<?php
/**
 * Click2Pickup - Product UI
 * 
 * Consolida UI de Produto: Product_Admin (metabox/admin) + Frontend_Availability (exibição no front).
 * 
 * ✅ v2.4.0: SQL escape adequado, cache com cleanup, placeholders seguros
 * ✅ v2.3.1: 3 CAMADAS DE PROTEÇÃO DE ESTOQUE
 * ✅ v2.3.0: Tracking manual de alterações no ledger
 * 
 * @package Click2Pickup
 * @since 2.4.0
 * @author rhpimenta
 * Last Update: 2025-01-09 01:08:32 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 01:08: ✅ CORRIGIDO: SQL escape em todas as queries
 * - 2025-01-09 01:08: ✅ CORRIGIDO: Placeholders seguros com spread operator
 * - 2025-01-09 01:08: ✅ CORRIGIDO: Cache com cleanup automático
 * - 2025-01-09 01:08: ✅ CORRIGIDO: UPSERT seguro (sem SQL dinâmico)
 * - 2025-01-09 01:08: ✅ MELHORADO: Flag para evitar recursão em save
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

class Product_UI {
    private static $instance;
    
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        if (class_exists('\\C2P\\Product_Admin') && method_exists('\\C2P\\Product_Admin','instance')) { 
            \C2P\Product_Admin::instance(); 
        } elseif (class_exists('\\C2P\\Product_Admin') && method_exists('\\C2P\\Product_Admin','init')) { 
            \C2P\Product_Admin::init(); 
        }
        
        if (class_exists('\\C2P\\Frontend_Availability') && method_exists('\\C2P\\Frontend_Availability','instance')) { 
            \C2P\Frontend_Availability::instance(); 
        } elseif (class_exists('\\C2P\\Frontend_Availability') && method_exists('\\C2P\\Frontend_Availability','init')) { 
            \C2P\Frontend_Availability::init(); 
        }
        
        // ✅ NOVO: Cleanup de cache no shutdown
        add_action('shutdown', [$this, 'cleanup_caches']);
    }
    
    /**
     * ✅ NOVO: Limpa todos os caches
     */
    public function cleanup_caches(): void {
        if (class_exists('\\C2P\\Product_Admin')) {
            \C2P\Product_Admin::clear_all_caches();
        }
        
        if (class_exists('\\C2P\\Frontend_Availability')) {
            \C2P\Frontend_Availability::clear_all_caches();
        }
    }
}

// === Product_Admin ===
if (!class_exists('\C2P\Product_Admin')):

class Product_Admin {

    private static $instance = null;
    private static $locations_cache = null;
    private static $stocks_cache = [];
    private static $assets_loaded = false;
    
    /**
     * ✅ NOVO: Flag para evitar recursão
     */
    private static $is_saving = false;
    
    public static function instance(): Product_Admin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'render_simple_stock_box']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_stock_box'], 10, 3);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_simple_stock']);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_stock'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets'], 999);
        add_filter('woocommerce_admin_stock_html', [$this, 'filter_admin_stock_html'], 10, 2);
    }

    /* ================= UI — Produto simples ================= */

    public function render_simple_stock_box() {
        global $post;
        if (!$post) return;

        $product = wc_get_product($post->ID);
        if (!$product || $product->is_type('variable')) return;

        $manage = (bool) $product->get_manage_stock('edit');
        $stores = $this->get_locations();

        echo '<div class="options_group c2p-stock-box c2p-simple" style="'. ($manage ? '' : 'display:none;') .'">';

        if (empty($stores)) {
            echo '<p>' . esc_html__('Nenhum local cadastrado (c2p_store).', 'c2p') . '</p></div>';
            return;
        }

        // ✅ CAMADA 1: MIGRAÇÃO AUTOMÁTICA AO RENDERIZAR
        $this->auto_migrate_if_needed((int) $post->ID);

        // ✅ CAMADA 2: AVISO VISUAL CRÍTICO
        $this->render_migration_warning((int) $post->ID);

        $this->render_stock_table((int) $post->ID, $stores);
        echo '</div>';
    }

    /* ================= UI — Variações ================= */

    public function render_variation_stock_box($loop, $variation_data, $variation) {
        $variation_id = (int) $variation->ID;
        $stores = $this->get_locations();
        if (empty($stores)) return;

        $v = wc_get_product($variation_id);
        $manage = $v ? (bool) $v->get_manage_stock('edit') : false;

        echo '<div class="options_group c2p-stock-box c2p-var" style="'. ($manage ? '' : 'display:none;') .'">';
        
        // ✅ CAMADA 1: MIGRAÇÃO AUTOMÁTICA AO RENDERIZAR
        $this->auto_migrate_if_needed($variation_id);

        // ✅ CAMADA 2: AVISO VISUAL CRÍTICO
        $this->render_migration_warning($variation_id);

        $this->render_stock_table($variation_id, $stores, 'variation');
        echo '</div>';
    }

    /* ================= ✅ CAMADA 1: Migração Automática ao Renderizar ================= */

    private function auto_migrate_if_needed(int $product_id): void {
        $wc_stock = (int) get_post_meta($product_id, '_stock', true);
        $c2p_initialized = get_post_meta($product_id, 'c2p_initialized', true);

        // Se produto tem estoque WooCommerce mas NÃO foi inicializado
        if ($wc_stock > 0 && $c2p_initialized !== 'yes') {
            $default_store_id = (int) get_option('c2p_default_store_id', 0);
            
            if ($default_store_id <= 0) {
                // Tenta buscar qualquer CD ativo
                $stores = get_posts([
                    'post_type' => C2P::POST_TYPE_STORE,
                    'post_status' => 'publish',
                    'meta_query' => [
                        ['key' => 'c2p_type', 'value' => 'cd', 'compare' => '=']
                    ],
                    'numberposts' => 1,
                    'fields' => 'ids',
                ]);
                
                $default_store_id = !empty($stores) ? (int) $stores[0] : 0;
            }

            if ($default_store_id > 0) {
                $this->migrate_product_to_default($product_id, $default_store_id, $wc_stock);
            }
        }
    }

    private function migrate_product_to_default(int $product_id, int $store_id, int $qty): void {
        global $wpdb;

        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col_store = esc_sql(C2P::col_store());

        // Verifica se já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE product_id = %d AND `{$col_store}` = %d LIMIT 1",
            $product_id,
            $store_id
        ));

        if ($exists) {
            return; // Já migrado
        }

        // Insere na tabela
        $wpdb->insert($table, [
            'product_id' => $product_id,
            $col_store => $store_id,
            'qty' => $qty,
            'low_stock_amount' => null,
            'updated_at' => current_time('mysql', true),
        ], ['%d', '%d', '%d', '%s', '%s']);

        // Marca como inicializado
        update_post_meta($product_id, 'c2p_initialized', 'yes');
        update_post_meta($product_id, C2P::META_STOCK_BY_IDS, [$store_id => $qty]);

        $store_name = get_the_title($store_id) ?: 'Estoque Padrão';
        update_post_meta($product_id, C2P::META_STOCK_BY_NAME, [$store_name => $qty]);

        // Registra no ledger
        if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'record')) {
            \C2P\Stock_Ledger::record([
                'product_id' => $product_id,
                'location_id' => $store_id,
                'location_name_text' => $store_name,
                'order_id' => null,
                'delta' => $qty,
                'qty_before' => 0,
                'qty_after' => $qty,
                'source' => 'auto_migration',
                'who' => 'product_ui:auto_migrate',
                'meta' => [
                    'event' => 'auto_migration_on_edit',
                    'original_wc_stock' => $qty,
                    'migrated_to' => $store_name,
                    'store_id' => $store_id,
                    'timestamp' => current_time('mysql'),
                    'user' => wp_get_current_user()->user_login,
                ],
                'created_at' => current_time('mysql', true),
            ]);
        }
    }

    /* ================= ✅ CAMADA 2: Aviso Visual Crítico ================= */

    private function render_migration_warning(int $product_id): void {
        $wc_stock = (int) get_post_meta($product_id, '_stock', true);
        $c2p_initialized = get_post_meta($product_id, 'c2p_initialized', true);

        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        
        $total_c2p = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(qty) FROM `{$table}` WHERE product_id = %d",
            $product_id
        ));

        // Se WooCommerce tem estoque mas c2p está zerado
        if ($wc_stock > 0 && $total_c2p === 0 && $c2p_initialized !== 'yes') {
            echo '<div class="notice notice-warning inline" style="margin:0 0 15px 0;padding:12px;border-left:4px solid #f0ad4e;">';
            echo '<p style="margin:0 0 8px 0;"><strong>⚠️ ' . esc_html__('ATENÇÃO: Estoque não migrado!', 'c2p') . '</strong></p>';
            echo '<p style="margin:0 0 8px 0;">' . 
                 sprintf(
                     esc_html__('Este produto tem %d unidades no WooCommerce mas não foi distribuído nos locais.', 'c2p'),
                     $wc_stock
                 ) . 
                 '</p>';
            echo '<p style="margin:0;">' . 
                 esc_html__('Ao salvar, o estoque será migrado automaticamente para o local padrão.', 'c2p') . 
                 '</p>';
            echo '</div>';
        }

        // Se houver divergência entre WooCommerce e C2P
        if ($c2p_initialized === 'yes' && $wc_stock !== $total_c2p) {
            echo '<div class="notice notice-info inline" style="margin:0 0 15px 0;padding:12px;">';
            echo '<p style="margin:0;"><strong>ℹ️ ' . esc_html__('Sincronização:', 'c2p') . '</strong> ';
            echo sprintf(
                esc_html__('WooCommerce: %d | Multi-Local: %d', 'c2p'),
                $wc_stock,
                $total_c2p
            );
            echo ' — ' . esc_html__('Ao salvar, o WooCommerce será atualizado com a soma dos locais.', 'c2p');
            echo '</p>';
            echo '</div>';
        }
    }

    /* ================= RENDERIZAÇÃO: Tabela Moderna com Cores ================= */

    private function render_stock_table(int $product_id, array $stores, string $type = 'simple') {
        list($stocks_map, $lows_map) = $this->get_product_stocks_with_lows($product_id);
        $global_low = get_option('woocommerce_notify_low_stock_amount', '');
        $ph_low = $global_low !== '' 
            ? sprintf(__('Padrão WooCommerce: %s', 'c2p'), (string) $global_low) 
            : __('Padrão loja', 'c2p');

        $total = array_sum($stocks_map);
        echo '<p class="c2p-header-title">';
        echo '<strong>' . esc_html__('Estoque por local', 'c2p') . '</strong>';
        echo '<span class="c2p-total-badge">' . sprintf(__('Total: %d unidades', 'c2p'), $total) . '</span>';
        echo '</p>';

        echo '<table class="widefat striped c2p-table" data-global-low="' . esc_attr($global_low) . '">';
        echo '<thead><tr>';
        echo '<th class="c2p-col-local">' . esc_html__('Local', 'c2p') . '</th>';
        echo '<th class="c2p-col-qty">' . esc_html__('Quantidade', 'c2p') . '</th>';
        echo '<th class="c2p-col-low">' . esc_html__('Estoque mínimo', 'c2p') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($stores as $store_id => $store_label) {
            $qty = isset($stocks_map[$store_id]) ? (int) $stocks_map[$store_id] : 0;
            $low = array_key_exists($store_id, $lows_map) && $lows_map[$store_id] !== null ? (int) $lows_map[$store_id] : '';
            
            $name_prefix = $type === 'variation' ? 'c2p_stock_var[' . $product_id . ']' : 'c2p_stock';
            $low_prefix = $type === 'variation' ? 'c2p_low_var[' . $product_id . ']' : 'c2p_low';
            
            preg_match('/—\s*(.+)$/', $store_label, $matches);
            $location_type = $matches[1] ?? '';
            $type_badge = $location_type === 'CD' ? '<span class="c2p-badge c2p-badge-cd">📦 CD</span>' : '<span class="c2p-badge c2p-badge-loja">🏪 Loja</span>';
            
            $clean_name = preg_replace('/\s*—\s*.+$/', '', $store_label);
            
            $row_class = $this->get_stock_row_class($qty, $low, $global_low);
            
            echo '<tr class="' . esc_attr($row_class) . '">';
            echo '<td class="c2p-td-local">' . $type_badge . ' ' . esc_html($clean_name) . '</td>';
            echo '<td class="c2p-td-qty"><input type="number" step="1" min="0" inputmode="numeric" class="short c2p-qty c2p-no-scroll" name="' . esc_attr($name_prefix) . '[' . esc_attr($store_id) . ']" value="' . esc_attr((string) $qty) . '" /></td>';
            echo '<td class="c2p-td-low"><input type="number" step="1" min="0" inputmode="numeric" class="short c2p-low c2p-no-scroll" name="' . esc_attr($low_prefix) . '[' . esc_attr($store_id) . ']" value="' . esc_attr((string) $low) . '" placeholder="' . esc_attr($ph_low) . '" /></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function get_stock_row_class(int $qty, $low, string $global_low): string {
        if ($qty <= 0) {
            return 'c2p-row-critical';
        }
        
        $threshold = null;
        
        if ($low !== '' && $low !== null) {
            $threshold = (int) $low;
        } elseif ($global_low !== '' && is_numeric($global_low)) {
            $threshold = (int) $global_low;
        }
        
        if ($threshold === null || $threshold <= 0) {
            return 'c2p-row-ok';
        }
        
        if ($qty > 0 && $qty <= $threshold) {
            return 'c2p-row-warning';
        }
        
        return 'c2p-row-ok';
    }

    /* ================= SAVE ================= */

    public function save_simple_stock($product) {
        if (!$product || !$product->get_id()) return;
        
        // ✅ PROTEÇÃO: Evita recursão
        if (self::$is_saving) return;
        self::$is_saving = true;
        
        $product_id = (int) $product->get_id();
        $pairs_qty = $this->extract_post_data('c2p_stock');
        $pairs_low = $this->extract_post_data('c2p_low', true);

        if ($pairs_qty || $pairs_low) {
            self::clear_stock_cache($product_id);
            $this->persist_stocks_with_lows($product_id, $pairs_qty, $pairs_low, 'admin_product_save:simple');
            
            // ✅ CAMADA 3: Proteção no Save (Anti-Zero)
            $this->reindex_and_snapshot_safe($product_id);
        }
        
        self::$is_saving = false;
    }

    public function save_variation_stock($variation_id, $i) {
        // ✅ PROTEÇÃO: Evita recursão
        if (self::$is_saving) return;
        self::$is_saving = true;
        
        $variation_id = (int) $variation_id;
        if ($variation_id <= 0) {
            self::$is_saving = false;
            return;
        }

        $pairs_qty = isset($_POST['c2p_stock_var'][$variation_id]) 
            ? $this->sanitize_stock_array($_POST['c2p_stock_var'][$variation_id]) 
            : [];
        
        $pairs_low = isset($_POST['c2p_low_var'][$variation_id]) 
            ? $this->sanitize_stock_array($_POST['c2p_low_var'][$variation_id], true) 
            : [];

        if ($pairs_qty || $pairs_low) {
            self::clear_stock_cache($variation_id);
            $this->persist_stocks_with_lows($variation_id, $pairs_qty, $pairs_low, 'admin_product_save:variation');
            
            // ✅ CAMADA 3: Proteção no Save (Anti-Zero)
            $this->reindex_and_snapshot_safe($variation_id);
        }
        
        self::$is_saving = false;
    }

    private function extract_post_data(string $key, bool $allow_null = false): array {
        if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
            return [];
        }
        return $this->sanitize_stock_array($_POST[$key], $allow_null);
    }

    private function sanitize_stock_array(array $data, bool $allow_null = false): array {
        $result = [];
        foreach ($data as $store_id => $value) {
            if (!is_numeric($store_id)) continue;
            
            if ($allow_null && ($value === '' || $value === null)) {
                $result[(int) $store_id] = null;
            } else {
                $result[(int) $store_id] = max(0, (int) $value);
            }
        }
        return $result;
    }
      /* ================= ✅ CAMADA 3: Proteção no Save (Anti-Zero) ================= */

    private function reindex_and_snapshot_safe(int $product_id): void {
        $sum = $this->sum_multistock($product_id);
        $current_wc_stock = (int) get_post_meta($product_id, '_stock', true);

        // 🔒 PROTEÇÃO: Se soma = 0 mas WooCommerce tem estoque, NÃO sobrescreve
        if ($sum === 0 && $current_wc_stock > 0) {
            $c2p_initialized = get_post_meta($product_id, 'c2p_initialized', true);
            
            // Só bloqueia se produto NÃO foi inicializado
            if ($c2p_initialized !== 'yes') {
                // Produto não migrado, mantém estoque original
                return;
            }
        }

        // Continua com o save normal
        $this->reindex_and_snapshot($product_id);
    }

    /* ================= PERSIST STOCKS ================= */

    /**
     * ✅ CORRIGIDO: Queries com escape adequado
     */
    private function persist_stocks_with_lows(int $product_id, array $pairs_qty, array $pairs_low, string $ctx = 'admin_product_save'): void {
        global $wpdb;

        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col_store = esc_sql(C2P::col_store());
        
        list($current_qty, $current_low) = $this->get_product_stocks_with_lows($product_id);
        
        $location_ids = array_unique(array_map('intval',
            array_merge(array_keys($current_qty), array_keys($current_low), array_keys($pairs_qty), array_keys($pairs_low))
        ));

        $changes = [];

        foreach ($location_ids as $sid) {
            if ($sid <= 0) continue;

            $has_row = array_key_exists($sid, $current_qty) || array_key_exists($sid, $current_low);
            $old_qty = $has_row ? (int)($current_qty[$sid] ?? 0) : 0;
            $new_qty = array_key_exists($sid, $pairs_qty) ? max(0, (int)$pairs_qty[$sid]) : $old_qty;
            $new_low = array_key_exists($sid, $pairs_low)
                ? (($pairs_low[$sid] === null || $pairs_low[$sid] === '') ? null : max(0, (int)$pairs_low[$sid]))
                : ($has_row ? ($current_low[$sid] ?? null) : null);

            $this->upsert_stock_row($product_id, $sid, $new_qty, $new_low);

            if ($new_qty !== $old_qty) {
                $thr = $this->get_threshold($product_id, $new_low);
                do_action('c2p_multistock_changed', (int)$product_id, (int)$sid, (int)$old_qty, (int)$new_qty, (int)$thr, (string)$ctx);
                
                $changes[] = [
                    'location_id' => (int)$sid,
                    'qty_before'  => (int)$old_qty,
                    'qty_after'   => (int)$new_qty,
                    'delta'       => (int)($new_qty - $old_qty),
                ];
            }
        }

        if (!empty($changes)) {
            $this->record_ledger_changes($product_id, $changes, $ctx);
        }
    }

    /**
     * ✅ CORRIGIDO: UPSERT seguro (sem SQL dinâmico)
     */
    private function upsert_stock_row(int $product_id, int $sid, int $qty, $low): void {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col_store = esc_sql(C2P::col_store());
        
        // Verifica se row existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE product_id = %d AND `{$col_store}` = %d LIMIT 1",
            $product_id,
            $sid
        ));

        $data = [
            'qty' => $qty,
            'low_stock_amount' => $low,
            'updated_at' => current_time('mysql', true),
        ];

        $format = ['%d', '%s', '%s'];

        if ($exists) {
            // UPDATE
            $wpdb->update(
                $table,
                $data,
                ['product_id' => $product_id, $col_store => $sid],
                $format,
                ['%d', '%d']
            );
        } else {
            // INSERT
            $data['product_id'] = $product_id;
            $data[$col_store] = $sid;
            
            array_unshift($format, '%d', '%d');
            
            $wpdb->insert($table, $data, $format);
        }
    }

    /**
     * ✅ MELHORADO: Validação de Stock_Ledger antes de chamar
     */
    private function record_ledger_changes(int $product_id, array $changes, string $ctx): void {
        if (!class_exists('\\C2P\\Stock_Ledger') || !method_exists('\\C2P\\Stock_Ledger','record')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P][Product_UI] Stock_Ledger não disponível para registro');
            }
            return;
        }
        
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $who = $user_id > 0 ? 'user#'.$user_id : 'admin:unknown';
        
        $user = $user_id > 0 ? get_userdata($user_id) : null;
        
        // ✅ SEGURANÇA: Validação de IP
        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
            if ($ip === false) {
                $ip = '';
            }
        }
        
        foreach ($changes as $c) {
            try {
                $location_name = get_the_title($c['location_id']) ?: 'Local #' . $c['location_id'];
                
                \C2P\Stock_Ledger::record([
                    'product_id'  => $product_id,
                    'location_id' => $c['location_id'],
                    'location_name_text' => $location_name,
                    'order_id'    => null,
                    'delta'       => $c['delta'],
                    'qty_before'  => $c['qty_before'],
                    'qty_after'   => $c['qty_after'],
                    'source'      => 'manual_admin',
                    'who'         => $who,
                    'meta'        => [
                        'context' => $ctx,
                        'user_id' => $user_id,
                        'user_login' => $user ? $user->user_login : null,
                        'user_display_name' => $user ? $user->display_name : null,
                        'screen' => 'product_edit',
                        'ip' => $ip,
                        'timestamp' => current_time('mysql'),
                    ],
                    'created_at' => current_time('mysql', true),
                ]);
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[C2P][Product_UI] Erro ao registrar ledger: ' . $e->getMessage());
                }
            }
        }
    }

    private function get_threshold(int $product_id, $low): int {
        if (!is_null($low)) return max(0, (int)$low);
        
        if (function_exists('wc_get_product')) {
            $prod_obj = wc_get_product($product_id);
            if ($prod_obj) {
                return (int) wc_get_low_stock_amount($prod_obj);
            }
        }
        return 0;
    }

    /**
     * ✅ CORRIGIDO: Usa flag ao invés de remove/add action
     */
    private function reindex_and_snapshot(int $product_id): void {
        $sum = $this->sum_multistock($product_id);

        $product = wc_get_product($product_id);
        if ($product) {
            // ✅ Flag já protege contra recursão
            $product->set_stock_quantity($sum);
            if (!$product->backorders_allowed()) {
                $product->set_stock_status($sum > 0 ? 'instock' : 'outofstock');
            }
            $product->save();
        }

        $this->update_product_meta_snapshot($product_id);

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables($product_id);
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($product_id);
        }
    }

    /**
     * ✅ CORRIGIDO: SQL escape adequado
     */
    private function sum_multistock(int $product_id): int {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty),0) FROM `{$table}` WHERE product_id = %d",
            $product_id
        ));
        return is_numeric($val) ? (int)$val : 0;
    }

    private function update_product_meta_snapshot(int $product_id): void {
        list($by_id,) = $this->get_product_stocks_with_lows($product_id);

        $by_name = [];
        foreach ($by_id as $loc_id => $qty) {
            $title = get_the_title($loc_id) ?: 'Local #'.$loc_id;
            $by_name[$title] = (int)$qty;
        }

        update_post_meta($product_id, C2P::META_STOCK_BY_IDS,  $by_id);
        update_post_meta($product_id, C2P::META_STOCK_BY_NAME, $by_name);
    }

    /* ================= HELPERS ================= */

    private function get_locations(): array {
        if (self::$locations_cache !== null) {
            return self::$locations_cache;
        }
        
        $q = new \WP_Query([
            'post_type'      => C2P::POST_TYPE_STORE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $out = [];
        foreach ($q->posts as $pid) {
            $type = get_post_meta($pid, 'c2p_type', true);
            $label_type = $type === 'cd' ? esc_html__('CD', 'c2p') : esc_html__('Loja', 'c2p');
            $out[(int) $pid] = get_the_title($pid) . ' (#' . (int) $pid . ') — ' . $label_type;
        }
        
        self::$locations_cache = $out;
        
        return $out;
    }

    /**
     * ✅ CORRIGIDO: SQL escape adequado
     */
    private function get_product_stocks_with_lows(int $product_id): array {
        global $wpdb;
        
        if (isset(self::$stocks_cache[$product_id])) {
            return self::$stocks_cache[$product_id];
        }

        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        $col_store = esc_sql(C2P::col_store());
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$col_store}` AS location_id, qty, low_stock_amount
               FROM `{$table}`
              WHERE product_id = %d
           ORDER BY `{$col_store}` ASC",
            $product_id
        ), ARRAY_A);

        $stocks = [];
        $lows   = [];
        
        if ($rows) {
            foreach ($rows as $r) {
                $sid = (int) $r['location_id'];
                $stocks[$sid] = (int) $r['qty'];
                $lows[$sid]   = ($r['low_stock_amount'] === null || $r['low_stock_amount'] === '') 
                    ? null 
                    : (int) $r['low_stock_amount'];
            }
        }
        
        $result = [$stocks, $lows];
        self::$stocks_cache[$product_id] = $result;
        
        return $result;
    }
    
    private static function clear_stock_cache(int $product_id): void {
        unset(self::$stocks_cache[$product_id]);
    }
    
    /**
     * ✅ NOVO: Limpa todos os caches
     */
    public static function clear_all_caches(): void {
        self::$stocks_cache = [];
        self::$locations_cache = null;
        
        // Limita cache se estiver muito grande (proteção)
        if (count(self::$stocks_cache) > 500) {
            self::$stocks_cache = [];
        }
    }

    /* ================= ASSETS - CSS E JS ================= */

    public function admin_assets($hook) {
        if (self::$assets_loaded) return;
        
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product') return;

        self::$assets_loaded = true;

        $css = '
            .c2p-stock-box { background: #f9fafb; padding: 20px !important; border-radius: 10px; border: 1px solid #e5e7eb; }
            .c2p-header-title { display: flex; align-items: center; justify-content: space-between; margin: 0 0 16px !important; padding-bottom: 12px; border-bottom: 2px solid #e5e7eb; }
            .c2p-header-title strong { display: flex; align-items: center; gap: 8px; font-size: 15px; color: #111827; }
            .c2p-header-title strong::before { content: "📦"; font-size: 18px; }
            .c2p-total-badge { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; padding: 6px 14px; border-radius: 999px; font-size: 12px; font-weight: 700; }
            .c2p-table { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); }
            .c2p-table thead { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); }
            .c2p-table thead th { padding: 14px 16px !important; font-size: 12px !important; font-weight: 700 !important; color: #475569 !important; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0 !important; }
            .c2p-table tbody tr { transition: background-color 0.15s ease; }
            .c2p-table tbody tr:hover { background: #f8fafc; }
            .c2p-table tbody tr.c2p-row-ok { background: #f0fdf4; }
            .c2p-table tbody tr.c2p-row-ok:hover { background: #dcfce7; }
            .c2p-table tbody tr.c2p-row-warning { background: #fffbeb; }
            .c2p-table tbody tr.c2p-row-warning:hover { background: #fef3c7; }
            .c2p-table tbody tr.c2p-row-critical { background: #fef2f2; }
            .c2p-table tbody tr.c2p-row-critical:hover { background: #fee2e2; }
            .c2p-table tbody td { padding: 14px 16px !important; vertical-align: middle !important; }
            .c2p-td-local { font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; }
            .c2p-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
            .c2p-badge-cd { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; }
            .c2p-badge-loja { background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%); color: #be185d; }
            .c2p-table input { width: 100% !important; padding: 10px 12px !important; border: 1.5px solid #e5e7eb !important; border-radius: 6px !important; font-size: 14px !important; font-weight: 600 !important; transition: all 0.2s ease !important; }
            .c2p-table input:focus { outline: none !important; border-color: #6366f1 !important; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1) !important; }
            .c2p-table input::placeholder { color: #9ca3af; font-weight: 400; font-size: 12px; }
            .c2p-no-scroll::-webkit-outer-spin-button, .c2p-no-scroll::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
            .c2p-no-scroll { -moz-appearance: textfield; }
            .c2p-col-local { width: 50%; }
            .c2p-col-qty { width: 25%; }
            .c2p-col-low { width: 25%; }
            .woocommerce_variation .c2p-stock-box { padding-left: 0 !important; }
            input#_stock[readonly], input#_stock[disabled], #variable_product_options input[name^="variable_stock["][readonly], #variable_product_options input[name^="variable_stock["][disabled] { background: #f6f7f7; opacity: .85; cursor: not-allowed; }
            .c2p-msg-low-native { margin: .4em 0 0; color: #666; font-size: 12px; }
        ';
        wp_add_inline_style('woocommerce_admin_styles', $css);

        $js = <<<'JS'
(function($) {
    'use strict';
    if (window.C2P_ProductAdmin_Loaded) return;
    window.C2P_ProductAdmin_Loaded = true;

    function sum($nodes) {
        var s = 0;
        $nodes.each(function() { 
            var v = parseInt(this.value, 10); 
            if (v > 0) s += v; 
        });
        return s;
    }

    function updateRowColors() {
        $('.c2p-table tbody tr').each(function() {
            var $row = $(this);
            var $table = $row.closest('table');
            var $qtyInput = $row.find('.c2p-qty');
            var $lowInput = $row.find('.c2p-low');
            
            if (!$qtyInput.length) return;
            
            var qty = parseInt($qtyInput.val(), 10) || 0;
            var low = parseInt($lowInput.val(), 10);
            var globalLow = parseInt($table.data('global-low'), 10);
            
            var threshold = null;
            if (!isNaN(low) && low > 0) {
                threshold = low;
            } else if (!isNaN(globalLow) && globalLow > 0) {
                threshold = globalLow;
            }
            
            $row.removeClass('c2p-row-ok c2p-row-warning c2p-row-critical');
            
            if (qty <= 0) {
                $row.addClass('c2p-row-critical');
            } else if (threshold !== null && qty > 0 && qty <= threshold) {
                $row.addClass('c2p-row-warning');
            } else {
                $row.addClass('c2p-row-ok');
            }
        });
    }

    function update(ctx) {
        var $ms, $box, $stock, $low, $wrap;
        
        if (ctx === 'simple') {
            $ms = $('#_manage_stock');
            if (!$ms.length) return;
            $box = $('.c2p-stock-box.c2p-simple');
            $stock = $('#_stock');
            $low = $('#_low_stock_amount');
        } else {
            var $var = $(this).closest('.woocommerce_variation');
            $ms = $var.find('input[name^="variable_manage_stock["]').first();
            if (!$ms.length) return;
            $box = $var.find('.c2p-stock-box.c2p-var');
            $stock = $var.find('input[name^="variable_stock["]').first();
            $low = $var.find('input[name^="variable_low_stock_amount["]').first();
        }
        
        var managed = $ms.is(':checked');
        
        if ($box.length) $box.toggle(managed);
        
        if ($stock.length) {
            if (managed) {
                var total = sum($box.find('input[name^="c2p_stock"]'));
                $stock.val(total).prop({readonly: true, disabled: true});
            } else {
                $stock.prop({readonly: false, disabled: false});
            }
        }
        
        if ($low.length) {
            $wrap = $low.closest('.form-field, .form-row, p');
            $wrap.find('.c2p-msg-low-native').remove();
            if (managed) {
                $low.hide();
                $wrap.append('<div class="c2p-msg-low-native">Limiar definido por local na tabela "Estoque por local".</div>');
            } else {
                $low.show();
            }
        }
        
        updateRowColors();
    }

    function init() {
        update('simple');
        $('.woocommerce_variation').each(function() { 
            update.call(this, 'variation'); 
        });
    }

    $(document)
        .on('change.c2p', '#_manage_stock', function() { update('simple'); })
        .on('input.c2p', '.c2p-simple input[name^="c2p_stock"]', function() { update('simple'); })
        .on('input.c2p change.c2p', '.c2p-qty, .c2p-low', updateRowColors)
        .on('change.c2p', '#variable_product_options input[name^="variable_manage_stock["]', function() { update.call(this, 'variation'); })
        .on('input.c2p', '#variable_product_options input[name^="c2p_stock_var"]', function() { update.call(this, 'variation'); })
        .on('woocommerce_variations_loaded woocommerce_variations_added woocommerce_variations_removed', init);

    $(document).ready(init);
})(jQuery);
JS;
        wp_add_inline_script('woocommerce_admin', $js);
    }

    public function filter_admin_stock_html($html, $product) {
        if (!$product instanceof \WC_Product) return $html;

        $status = $product->get_stock_status();
        $qty = (int) $product->get_stock_quantity();

        $labels = [
            'outofstock'  => '<mark class="outofstock">' . esc_html__('Fora de estoque', 'woocommerce') . '</mark>',
            'onbackorder' => '<mark class="onbackorder">' . esc_html__('Em espera', 'woocommerce') . '</mark>',
            'instock'     => '<mark class="instock">' . esc_html__('Em estoque', 'woocommerce') . '</mark>',
        ];

        $base = $labels[$status] ?? $html;
        
        return $qty > 0 && !$product->is_type('variable') ? $base . ' (' . esc_html($qty) . ')' : $base;
    }
}

endif;

// === Frontend_Availability ===
if (!class_exists('\C2P\Frontend_Availability')):

class Frontend_Availability {
    private static $instance;
    private static $sum_cache = [];

    public static function instance(): Frontend_Availability {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('woocommerce_variation_is_active', [$this, 'variation_is_active'], 10, 2);
        add_filter('woocommerce_variation_is_in_stock', [$this, 'variation_is_in_stock'], 10, 2);
        add_filter('woocommerce_product_is_in_stock', [$this, 'product_is_in_stock'], 10, 2);
        add_filter('woocommerce_product_get_stock_status', [$this, 'product_get_stock_status'], 20, 2);
        add_filter('woocommerce_get_availability', [$this, 'filter_availability_html'], 20, 2);
    }

    public function variation_is_active($active, $variation) {
        $vid = (int) ($variation ? $variation->get_id() : 0);
        if ($vid <= 0) return $active;
        return $active || $this->sum_stock_for_products([$vid]) > 0;
    }
    
    public function variation_is_in_stock($in_stock, $variation) {
        $vid = (int) ($variation ? $variation->get_id() : 0);
        if ($vid <= 0) return $in_stock;
        return $this->sum_stock_for_products([$vid]) > 0;
    }
    
    public function product_is_in_stock($in_stock, $product) {
        if (!$product || !is_a($product, '\WC_Product')) return $in_stock;
        
        if ($product->is_type('variable')) {
            $children = array_map('intval', (array) $product->get_children());
            if (empty($children)) return $in_stock;
            return $this->sum_stock_for_products($children) > 0;
        }
        
        $pid = (int) $product->get_id();
        if ($pid <= 0) return $in_stock;
        return $this->sum_stock_for_products([$pid]) > 0;
    }
    
    public function product_get_stock_status($status, $product) {
        if (!$product || !is_a($product, '\WC_Product')) return $status;
        
        if ($product->is_type('variable')) {
            $children = array_map('intval', (array) $product->get_children());
            if (empty($children)) return $status;
            return ($this->sum_stock_for_products($children) > 0) ? 'instock' : 'outofstock';
        }
        
        $pid = (int) $product->get_id();
        if ($pid <= 0) return $status;
        return ($this->sum_stock_for_products([$pid]) > 0) ? 'instock' : 'outofstock';
    }
    
    public function filter_availability_html($availability, $product) {
        if (!$product || !is_a($product, '\WC_Product')) return $availability;
        
        $status = $this->product_get_stock_status($product->get_stock_status(), $product);
        
        if ($status === 'instock') {
            $availability['class'] = 'in-stock';
            if (empty($availability['availability'])) {
                $availability['availability'] = __('Em estoque', 'woocommerce');
            }
        } elseif ($status === 'outofstock') {
            $availability['class'] = 'out-of-stock';
            if (empty($availability['availability'])) {
                $availability['availability'] = __('Fora de estoque', 'woocommerce');
            }
        }
        
        return $availability;
    }

    /**
     * ✅ CORRIGIDO: Placeholders seguros com spread operator
     */
    private function sum_stock_for_products(array $product_ids): int {
        global $wpdb;
        
        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if (empty($ids)) return 0;
        
        $cache_key = implode('-', $ids);
        
        if (isset(self::$sum_cache[$cache_key])) {
            return self::$sum_cache[$cache_key];
        }

        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table());
        
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        // ✅ CORRIGIDO: Spread operator para passar array como argumentos individuais
        $sum = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty),0) FROM `{$table}` WHERE product_id IN ({$placeholders})",
            ...$ids  // ← CORRIGIDO: Spread operator
        ));
        
        $result = max(0, $sum);
        self::$sum_cache[$cache_key] = $result;
        
        return $result;
    }
    
    /**
     * ✅ NOVO: Limpa cache
     */
    public static function clear_all_caches(): void {
        self::$sum_cache = [];
        
        // Proteção: Limita tamanho do cache
        if (count(self::$sum_cache) > 1000) {
            self::$sum_cache = [];
        }
    }
}

endif;