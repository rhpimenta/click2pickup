<?php
/**
 * Click2Pickup - REST API + Interceptor OAuth
 * 
 * ✅ v2.6.1: CORRIGIDO ArgumentCountError em sanitize_callback
 * ✅ v2.6.0: SQL ESCAPE + Autenticação Segura + Validações
 * ✅ Rotas nativas (c2p/v1/*) e alias (wc/v3/c2p/*)
 * ✅ Interceptor REST para ERPs externos
 * ✅ Detecção automática de OAuth (SEGURA)
 * 
 * @package Click2Pickup
 * @since 2.6.1
 * @author rhpimenta
 * Last Update: 2025-10-09 11:51:50 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 11:51: ✅ CORRIGIDO: ArgumentCountError em intval() (wrapper functions)
 * - 2025-01-09 01:18: ✅ CORRIGIDO: SQL escape em todas as queries
 * - 2025-01-09 01:18: ✅ CORRIGIDO: OAuth com prepared statements seguros
 * - 2025-01-09 01:18: ✅ CORRIGIDO: Validação de IP com filter_var()
 * - 2025-01-09 01:18: ✅ CORRIGIDO: Permissões mais restritivas
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

final class REST_API {

    private static $instance = null;
    private static $processing = [];

    /**
     * ✅ Estratégias permitidas
     */
    const ALLOWED_STRATEGIES = ['cd_global', 'proportional', 'priority'];

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // === REST API ===
        add_action('rest_api_init', function () {
            $this->register_routes_core();
            $this->register_routes_alias();
        });
        
        add_action('woocommerce_rest_api_init', function() {
            $this->register_routes_alias();
        });

        add_filter('woocommerce_rest_prepare_product_object',
            [$this, 'on_prepare_product'], 99, 3);
        add_filter('woocommerce_rest_prepare_product_variation_object',
            [$this, 'on_prepare_variation'], 99, 3);

        add_filter('woocommerce_rest_prepare_product_object',
            [$this, 'inject_location_labels'], 100, 3);
        add_filter('woocommerce_rest_prepare_product_variation_object',
            [$this, 'inject_location_labels'], 100, 3);

        // === INTERCEPTOR REST ===
        add_action('rest_api_init', function() {
            $this->init_interceptor();
        }, 1);
    }

    /* ====================================================================
     * PARTE 1: ROTAS REST
     * ================================================================== */

    private function register_routes_core(): void {
        register_rest_route('c2p/v1', '/mirror', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_mirror'],
            'permission_callback' => [$this, 'permission_admin'],
        ]);

        register_rest_route('c2p/v1', '/stock', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_stock_delta'],
            'permission_callback' => [$this, 'permission_write'],
            'args'                => $this->args_stock(),
        ]);

        register_rest_route('c2p/v1', '/reindex', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_reindex'],
            'permission_callback' => [$this, 'permission_admin'],
        ]);

        register_rest_route('c2p/v1', '/products/init-state', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_product_init_state'],
            'permission_callback' => [$this, 'permission_read'],
            'args'                => [
                'product_id' => [
                    'required' => true, 
                    'type' => 'integer',
                    'sanitize_callback' => function($value) {
                        return absint($value);
                    },
                    'validate_callback' => function($value) {
                        return is_numeric($value) && (int)$value > 0;
                    }
                ],
            ],
        ]);
    }

    private function register_routes_alias(): void {
        register_rest_route('wc/v3', '/c2p/stock', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_stock_delta'],
            'permission_callback' => [$this, 'permission_write'],
            'args'                => $this->args_stock(),
        ]);
    }

    /**
     * ✅ CORRIGIDO v2.6.1: Wrapper functions para evitar ArgumentCountError
     */
    private function args_stock(): array {
        return [
            'product_id'  => [
                'required' => true, 
                'type' => 'integer',
                'sanitize_callback' => function($value) {
                    return absint($value);
                },
                'validate_callback' => function($value) {
                    return is_numeric($value) && (int)$value > 0;
                },
            ],
            'location_id' => [
                'required' => true, 
                'type' => 'integer',
                'sanitize_callback' => function($value) {
                    return absint($value);
                },
                'validate_callback' => function($value) {
                    return is_numeric($value) && (int)$value > 0;
                },
            ],
            'delta'       => [
                'required' => true, 
                'type' => 'integer',
                'sanitize_callback' => function($value) {
                    return (int) $value;
                },
            ],
            'source'      => [
                'required' => false, 
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'who'         => [
                'required' => false, 
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * ✅ Permissões mais restritivas
     */
    public function permission_admin(): bool {
        return current_user_can('manage_woocommerce');
    }

    public function permission_write(): bool {
        return current_user_can('edit_products') || current_user_can('manage_woocommerce');
    }

    public function permission_read(): bool {
        return current_user_can('edit_products') || current_user_can('manage_woocommerce');
    }

    public function route_mirror(\WP_REST_Request $request) {
        return new \WP_REST_Response([
            'ok' => true, 
            'timestamp' => current_time('mysql'),
            'message' => 'Click2Pickup REST API is working!'
        ], 200);
    }

    public function route_stock_delta(\WP_REST_Request $req) {
        global $wpdb;

        $product_id  = (int) $req->get_param('product_id');
        $location_id = (int) $req->get_param('location_id');
        $delta       = (int) $req->get_param('delta');
        
        $source_param = trim((string) $req->get_param('source'));
        $who_param    = trim((string) $req->get_param('who'));

        $identity = $this->identify_api_source($req);

        if (!empty($identity['oauth_description'])) {
            $source = 'oauth_' . sanitize_title($identity['oauth_description']);
            $who = 'oauth:' . $identity['oauth_description'];
        } else {
            $source = $source_param ?: 'api_stock_update';
            $who = $who_param ?: 'external_api';
        }

        if ($product_id <= 0 || $location_id <= 0) {
            return new \WP_Error('c2p_bad_args', __('Parâmetros inválidos.', 'c2p'), ['status' => 400]);
        }

        if ($source === '' && empty($identity['oauth_description'])) {
            return new \WP_Error('c2p_bad_args', __('source obrigatório (ou envie consumer_key via OAuth).', 'c2p'), ['status' => 400]);
        }

        if ($who === '' && empty($identity['oauth_description'])) {
            return new \WP_Error('c2p_bad_args', __('who obrigatório (ou envie consumer_key via OAuth).', 'c2p'), ['status' => 400]);
        }

        $post = get_post($product_id);
        if (!$post) {
            return new \WP_Error('c2p_product_not_found', __('Produto não encontrado.', 'c2p'), ['status' => 404]);
        }

        $loc = get_post($location_id);
        if (!$loc || $loc->post_type !== C2P::POST_TYPE_STORE || $loc->post_status !== 'publish') {
            return new \WP_Error('c2p_location_not_found', __('Local de estoque inexistente ou inativo.', 'c2p'), ['status' => 404]);
        }

        $flag = get_post_meta($product_id, 'c2p_initialized', true);
        if ($flag !== 'yes') {
            return new \WP_Error(
                'c2p_uninitialized',
                __('Produto não inicializado para multi-estoque.', 'c2p'),
                ['status' => 409, 'product_id' => $product_id]
            );
        }

        if (0 === $delta) {
            $state = $this->compute_state($product_id);
            $loc_after = $state['by_id'][$location_id] ?? 0;
            return new \WP_REST_Response([
                'ok' => true,
                'product_id' => $product_id,
                'location_id' => $location_id,
                'applied_delta' => 0,
                'location_qty_after' => (int) $loc_after,
                'total_stock_after' => (int) $state['total'],
                'snapshots' => [
                    'by_id' => $state['by_id'],
                    'by_name' => $state['by_name'],
                ],
                'notice' => 'Delta = 0 (nenhuma alteração aplicada).',
            ], 200);
        }

        if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
            try {
                $location_name = get_the_title($location_id) ?: 'Local #' . $location_id;
                
                \C2P\Stock_Ledger::apply_delta($product_id, $location_id, $delta, [
                    'source' => $source,
                    'who' => $who,
                    'meta' => [
                        'api_label' => $identity['label'] ?? null,
                        'user_agent' => $identity['user_agent'] ?? '',
                        'ip' => $identity['ip'] ?? '',
                        'oauth_key' => $identity['oauth_key'] ?? '',
                        'oauth_description' => $identity['oauth_description'] ?? '',
                        'location_name' => $location_name,
                    ],
                ]);
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[C2P][REST_API] Erro ao aplicar delta: ' . $e->getMessage());
                }
                return new \WP_Error('c2p_ledger_error', __('Erro ao registrar movimentação.', 'c2p'), ['status' => 500]);
            }
        }

        $state = $this->compute_state($product_id);
        $loc_after = $state['by_id'][$location_id] ?? 0;

        update_post_meta($product_id, C2P::META_STOCK_BY_IDS, $state['by_id']);
        update_post_meta($product_id, C2P::META_STOCK_BY_NAME, $state['by_name']);
        update_post_meta($product_id, '_stock', (int) $state['total']);

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $allow_backorder = $product ? (bool) $product->backorders_allowed() : false;
        if (!$allow_backorder) {
            update_post_meta($product_id, '_stock_status', ($state['total'] > 0 ? 'instock' : 'outofstock'));
        }

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables($product_id);
        }

        return new \WP_REST_Response([
            'ok' => true,
            'product_id' => $product_id,
            'location_id' => $location_id,
            'applied_delta' => (int) $delta,
            'location_qty_after' => (int) $loc_after,
            'total_stock_after' => (int) $state['total'],
            'snapshots' => [
                'by_id' => $state['by_id'],
                'by_name' => $state['by_name'],
            ],
        ], 200);
    }

    public function route_reindex() {
        return new \WP_REST_Response([
            'ok' => true, 
            'message' => 'Reindex endpoint (não implementado)'
        ], 200);
    }

    public function route_product_init_state(\WP_REST_Request $request) {
        $pid = (int) $request->get_param('product_id');
        if ($pid <= 0) {
            return new \WP_Error('c2p_bad_args', 'product_id inválido.', ['status' => 400]);
        }
        $flag = get_post_meta($pid, 'c2p_initialized', true);
        return new \WP_REST_Response([
            'product_id' => $pid,
            'state' => ($flag === 'yes' ? 'initialized' : 'uninitialized'),
        ], 200);
    }

    /* ====================================================================
     * PARTE 2: ENRIQUECIMENTO WOO REST
     * ================================================================== */

    public function on_prepare_product($response, $object, $request) {
        if (!($response instanceof \WP_REST_Response)) return $response;
        $product_id = (int) $object->get_id();
        return $this->add_map_to_response($response, $product_id);
    }

    public function on_prepare_variation($response, $object, $request) {
        if (!($response instanceof \WP_REST_Response)) return $response;
        $product_id = (int) $object->get_id();
        return $this->add_map_to_response($response, $product_id);
    }

    private function add_map_to_response(\WP_REST_Response $response, int $product_id): \WP_REST_Response {
        list($by_id, $by_nm) = $this->read_map($product_id);
        $state = (get_post_meta($product_id, 'c2p_initialized', true) === 'yes') ? 'initialized' : 'uninitialized';

        return $this->strip_and_prepend_meta($response, [
            C2P::META_STOCK_BY_IDS => $by_id,
            C2P::META_STOCK_BY_NAME => $by_nm,
            'c2p_init_state' => $state,
        ]);
    }

    public function inject_location_labels($response, $object, $request) {
        if (!($response instanceof \WP_REST_Response)) return $response;

        $product_id = 0;
        if (is_object($object) && method_exists($object, 'get_id')) {
            $product_id = (int) $object->get_id();
        } elseif (is_array($response->get_data())) {
            $data = $response->get_data();
            if (isset($data['id'])) $product_id = (int) $data['id'];
        }
        if ($product_id <= 0) return $response;

        list($by_id, $_) = $this->read_map($product_id);

        $labels = [];
        foreach ((array) $by_id as $sid => $qty) {
            $sid = (int) $sid;
            if ($sid <= 0) continue;
            $nm = get_the_title($sid);
            if (!$nm) $nm = 'Local #'.$sid;
            $labels[(string) $sid] = $nm;
        }

        if (!empty($labels)) {
            $response = $this->strip_and_prepend_meta($response, [
                'c2p_location_labels' => $labels,
            ]);
        }

        return $response;
    }

    /**
     * ✅ CORRIGIDO: SQL escape adequado
     */
    private function read_map(int $product_id): array {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$col}` AS loc, qty FROM `{$table}` WHERE product_id = %d ORDER BY `{$col}` ASC",
            $product_id
        ), ARRAY_A);

        $by_id = [];
        $by_nm = [];

        if ($rows) {
            foreach ($rows as $r) {
                $lid = (int) $r['loc'];
                $qty = (int) $r['qty'];
                $by_id[$lid] = $qty;

                $nm = get_the_title($lid);
                if (!$nm) $nm = 'Local #'.$lid;
                $by_nm[$nm] = ($by_nm[$nm] ?? 0) + $qty;
            }
        }

        ksort($by_id, SORT_NUMERIC);
        ksort($by_nm, SORT_NATURAL | SORT_FLAG_CASE);

        return [$by_id, $by_nm];
    }

    /**
     * ✅ CORRIGIDO: SQL escape adequado
     */
    private function compute_state(int $product_id): array {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col_store = esc_sql(C2P::col_store());

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$col_store}` AS store_id, qty FROM `{$table}` WHERE product_id = %d ORDER BY `{$col_store}` ASC",
            $product_id
        ), ARRAY_A);

        $by_id = [];
        $by_name = [];
        $total = 0;

        if (is_array($rows)) {
            foreach ($rows as $r) {
                $sid = (int) ($r['store_id'] ?? 0);
                $qty = max(0, (int) ($r['qty'] ?? 0));
                $by_id[$sid] = $qty;
                $total += $qty;

                $nm = get_the_title($sid);
                if (!$nm) { $nm = 'Local #' . $sid; }
                $by_name[$nm] = $qty;
            }
        }

        return [
            'by_id' => $by_id,
            'by_name' => $by_name,
            'total' => (int) $total,
        ];
    }

    private function strip_and_prepend_meta(\WP_REST_Response $response, array $pairs): \WP_REST_Response {
        $data = $response->get_data();
        $meta = isset($data['meta_data']) && is_array($data['meta_data']) ? $data['meta_data'] : [];

        $keys_to_replace = array_keys($pairs);
        $clean = [];

        foreach ($meta as $item) {
            $k = null;
            if (is_array($item) && isset($item['key'])) {
                $k = $item['key'];
            } elseif (is_object($item) && isset($item->key)) {
                $k = $item->key;
            }

            if ($k !== null && in_array($k, $keys_to_replace, true)) {
                continue;
            }

            $clean[] = $item;
        }

        $prepend = [];
        foreach ($pairs as $k => $v) {
            $prepend[] = ['id' => 0, 'key' => $k, 'value' => $v];
        }

        $data['meta_data'] = array_values(array_merge($prepend, $clean));
        $response->set_data($data);
        return $response;
    }

    /* ====================================================================
     * PARTE 3: INTERCEPTOR REST
     * ================================================================== */

    private function init_interceptor(): void {
        add_filter('rest_pre_dispatch', [$this, 'intercept_stock_update'], 1, 3);
        add_action('woocommerce_product_object_updated_props', [$this, 'on_product_updated'], 10, 2);
    }

    public function intercept_stock_update($result, $server, $request) {
        if ($result !== null) return $result;
        
        $route = $request->get_route();
        if (!preg_match('~^/wc/v[23]/products/(\d+)$~', $route, $matches)) {
            return $result;
        }
        
        $method = $request->get_method();
        if (!in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
            return $result;
        }
        
        $params = $request->get_json_params();
        if (!isset($params['stock_quantity'])) {
            return $result;
        }
        
        $product_id = (int)$matches[1];
        $new_qty = is_numeric($params['stock_quantity']) ? (int)$params['stock_quantity'] : 0;
        
        if (isset(self::$processing[$product_id])) {
            return $result;
        }
        
        self::$processing[$product_id] = true;
        
        $identity = $this->identify_api_source($request);
        
        $this->apply_stock_update($product_id, $new_qty, $identity);
        
        $params['stock_quantity'] = null;
        $request->set_body_params($params);
        
        // ✅ CORRIGIDO: Verifica se evento já existe antes de agendar
        if (!wp_next_scheduled('c2p_clear_processing_flag', [$product_id])) {
            wp_schedule_single_event(time() + 2, 'c2p_clear_processing_flag', [$product_id]);
        }
        
        return null;
    }

    public function on_product_updated($product, $updated_props) {
        if (!$product || !in_array('stock_quantity', $updated_props, true)) {
            return;
        }
        
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return;
        }
        
        $product_id = $product->get_id();
        
        if (isset(self::$processing[$product_id])) {
            return;
        }
        
        self::$processing[$product_id] = true;
        
        $new_qty = (int)$product->get_stock_quantity();
        $identity = $this->identify_api_source(null);
        
        $this->apply_stock_update($product_id, $new_qty, $identity);
        
        // ✅ CORRIGIDO: Verifica se evento já existe
        if (!wp_next_scheduled('c2p_clear_processing_flag', [$product_id])) {
            wp_schedule_single_event(time() + 2, 'c2p_clear_processing_flag', [$product_id]);
        }
    }

    /**
     * ✅ CORRIGIDO: Autenticação OAuth segura + Validação de IP
     */
    private function identify_api_source($request): array {
        $user_agent = '';
        $ip = '';
        $oauth_description = '';
        $consumer_key_truncated = '';
        $consumer_key = '';
        
        // User Agent
        if ($request) {
            $user_agent = $request->get_header('user_agent') ?: '';
        }
        if (!$user_agent && isset($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
        }
        
        // ✅ CORRIGIDO: Validação de IP
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            $ip = filter_var(trim($forwarded), FILTER_VALIDATE_IP);
        }
        
        if (!$ip && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
        }
        
        if ($ip === false) {
            $ip = ''; // IP inválido
        }
        
        // Consumer Key
        if (isset($_SERVER['PHP_AUTH_USER']) && !empty($_SERVER['PHP_AUTH_USER'])) {
            $consumer_key = sanitize_text_field($_SERVER['PHP_AUTH_USER']);
        }
        
        // ✅ CORRIGIDO: Autenticação Basic segura
        if (!$consumer_key && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
            
            if (stripos($auth_header, 'Basic ') === 0) {
                $encoded = substr($auth_header, 6);
                $decoded = base64_decode($encoded, true); // ✅ Strict mode
                
                if ($decoded !== false && strpos($decoded, ':') !== false) {
                    list($consumer_key, ) = explode(':', $decoded, 2);
                    $consumer_key = sanitize_text_field($consumer_key);
                }
            }
            elseif (stripos($auth_header, 'Bearer ') === 0) {
                $consumer_key = sanitize_text_field(substr($auth_header, 7));
            }
        }
        
        // Query params
        if (!$consumer_key) {
            if (isset($_GET['oauth_consumer_key'])) {
                $consumer_key = sanitize_text_field($_GET['oauth_consumer_key']);
            } elseif (isset($_GET['consumer_key'])) {
                $consumer_key = sanitize_text_field($_GET['consumer_key']);
            }
        }
        
        // Body params
        if (!$consumer_key && $request) {
            $body_params = $request->get_body_params();
            if (isset($body_params['oauth_consumer_key'])) {
                $consumer_key = sanitize_text_field($body_params['oauth_consumer_key']);
            } elseif (isset($body_params['consumer_key'])) {
                $consumer_key = sanitize_text_field($body_params['consumer_key']);
            }
        }
        
        // ✅ CORRIGIDO: OAuth lookup com prepared statement seguro
        if ($consumer_key) {
            global $wpdb;
            
            $oauth_row = $wpdb->get_row($wpdb->prepare(
                "SELECT description, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s LIMIT 1",
                $consumer_key
            ));
            
            // ✅ CORRIGIDO: Fallback seguro com prepared statement
            if (!$oauth_row && strlen($consumer_key) >= 7) {
                $last_7 = substr($consumer_key, -7);
                
                // ✅ CORRIGIDO: Escape do wildcard
                $oauth_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT description, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE truncated_key LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like($last_7)
                ));
            }
            
            if ($oauth_row && !empty($oauth_row->description)) {
                $oauth_description = $oauth_row->description;
                $consumer_key_truncated = $oauth_row->truncated_key ?? '';
            }
        }
        
        // Monta identificação
        if ($oauth_description) {
            $source = 'oauth_' . sanitize_title($oauth_description);
            $who = 'oauth:' . $oauth_description;
            $label = $oauth_description;
        } else {
            $source = 'api_stock_update';
            $who = $ip ? 'external_api@' . $ip : 'external_api';
            $label = $ip ? 'API Externa (IP: ' . $ip . ')' : 'API Externa';
        }
        
        return [
            'source' => $source,
            'who' => $who,
            'label' => $label,
            'user_agent' => $user_agent,
            'ip' => $ip,
            'oauth_key' => $consumer_key_truncated,
            'oauth_description' => $oauth_description,
        ];
    }

    /**
     * ✅ CORRIGIDO: Validação de estratégia
     */
    private function apply_stock_update(int $product_id, int $requested_qty, array $identity) {
        $opts = \C2P\Settings::get_options();
        
        if (empty($opts['accept_global_stock'])) {
            return;
        }
        
        $strategy = $opts['global_strategy'] ?? 'cd_global';
        
        // ✅ VALIDAÇÃO: Só aceita estratégias permitidas
        if (!in_array($strategy, self::ALLOWED_STRATEGIES, true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P][REST_API] Estratégia inválida: ' . $strategy);
            }
            return;
        }
        
        $cd_store_id = (int)($opts['cd_store_id'] ?? 0);
        
        if ($cd_store_id <= 0) {
            return;
        }
        
        switch ($strategy) {
            case 'cd_global':
                $current_cd_qty = $this->get_location_qty($product_id, $cd_store_id);
                $delta = $requested_qty - $current_cd_qty;
                
                if ($delta == 0) return;
                
                if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                    try {
                        $location_name = get_the_title($cd_store_id) ?: 'CD Global';
                        
                        \C2P\Stock_Ledger::apply_delta($product_id, $cd_store_id, $delta, [
                            'source' => $identity['source'],
                            'who' => $identity['who'],
                            'meta' => [
                                'requested_qty' => $requested_qty,
                                'previous_qty' => $current_cd_qty,
                                'delta' => $delta,
                                'strategy' => 'cd_global',
                                'api_label' => $identity['label'],
                                'user_agent' => $identity['user_agent'],
                                'ip' => $identity['ip'],
                                'oauth_key' => $identity['oauth_key'],
                                'oauth_description' => $identity['oauth_description'],
                                'location_name' => $location_name,
                            ],
                        ]);
                    } catch (\Throwable $e) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[C2P][REST_API] Erro ao aplicar delta interceptor: ' . $e->getMessage());
                        }
                    }
                }
                break;
            
            case 'proportional':
            case 'priority':
                // Implementação futura
                break;
        }
    }

    /**
     * ✅ CORRIGIDO: SQL escape adequado
     */
    private function get_location_qty(int $product_id, int $location_id): int {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        $qty = $wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM `{$table}` WHERE product_id = %d AND `{$col}` = %d LIMIT 1",
            $product_id,
            $location_id
        ));
        
        return is_numeric($qty) ? (int)$qty : 0;
    }
}

/**
 * ✅ CORRIGIDO: Bootstrap com error handling
 */
add_action('plugins_loaded', function() {
    if (class_exists('\C2P\REST_API')) {
        try {
            \C2P\REST_API::instance();
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P] Erro ao inicializar REST_API: ' . $e->getMessage());
            }
        }
    }
}, 12);

/**
 * ✅ CORRIGIDO: Limpeza de flag com try-catch
 */
add_action('c2p_clear_processing_flag', function($product_id) {
    if (!class_exists('\C2P\REST_API')) {
        return;
    }
    
    try {
        $reflection = new \ReflectionClass('\C2P\REST_API');
        
        if (!$reflection->hasProperty('processing')) {
            return;
        }
        
        $property = $reflection->getProperty('processing');
        $property->setAccessible(true);
        $processing = $property->getValue(null);
        
        if (isset($processing[$product_id])) {
            unset($processing[$product_id]);
            $property->setValue(null, $processing);
        }
    } catch (\ReflectionException $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[C2P] Erro ao limpar processing flag: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[C2P] Erro inesperado ao limpar flag: ' . $e->getMessage());
        }
    }
});