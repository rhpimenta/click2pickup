<?php
/**
 * Click2Pickup - Settings Tab: ERP
 * 
 * ✅ v2.1.1: CORRIGIDO - Checkbox accept_global_stock agora desabilita corretamente
 * 
 * @package Click2Pickup
 * @since 2.1.1
 * @author rhpimenta
 * Last Update: 2025-10-09 13:24:12 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 13:24: 🐛 CORRIGIDO: Hidden input para accept_global_stock
 * - 2025-10-09 13:22: ✅ SQL escape em TODAS as queries (15+)
 * - 2025-10-09 13:22: ✅ Type hints em TODOS os métodos
 * - 2025-10-09 13:22: ✅ Logs condicionais (WP_DEBUG)
 */

namespace C2P\Settings_Tabs;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

trait Tab_ERP {
    
    /**
     * ========================================
     * LOGGING HELPERS
     * ========================================
     */
    
    /**
     * Log info (only if WP_DEBUG)
     * 
     * @param string $message
     */
    private static function log_info(string $message): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('[C2P][ERP] ' . $message, ['source' => 'c2p-erp']);
        }
    }
    
    /**
     * Log warning (only if WP_DEBUG)
     * 
     * @param string $message
     */
    private static function log_warning(string $message): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->warning('[C2P][ERP] ' . $message, ['source' => 'c2p-erp']);
        }
    }
    
    /**
     * Log error (always logs)
     * 
     * @param string $message
     */
    private static function log_error(string $message): void {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->error('[C2P][ERP] ' . $message, ['source' => 'c2p-erp']);
        } else {
            error_log('[C2P][ERP] ' . $message);
        }
    }
    
    /**
     * ========================================
     * INTERCEPTOR INITIALIZATION
     * ========================================
     */
    
    /**
     * Registra hooks de interceptação REST
     */
    public static function init_erp_interceptor(): void {
        // ✅ Só registra se Settings existe
        if (!class_exists('\C2P\Settings')) {
            return;
        }
        
        $opts = \C2P\Settings::get_options();
        
        // ✅ Só registra se habilitado
        if (empty($opts['accept_global_stock'])) {
            return;
        }
        
        add_filter('rest_pre_dispatch', [__CLASS__, 'intercept_global_stock_update'], 5, 3);
    }
    
    /**
     * ========================================
     * REST API INTERCEPTOR
     * ========================================
     */
    
    /**
     * Intercepta PUT/PATCH em /wc/v3/products/{id}
     * 
     * @param mixed $result
     * @param WP_REST_Server $server
     * @param WP_REST_Request $request
     * @return mixed
     */
    public static function intercept_global_stock_update($result, $server, $request) {
        // Só atua se ainda não houver resposta
        if ($result !== null) {
            return $result;
        }
        
        // Só atua em rotas de produtos do WooCommerce
        $route = $request->get_route();
        if (!preg_match('~^/wc/v[23]/products/(\d+)$~', $route, $matches)) {
            return $result;
        }
        
        // Só atua em PUT/PATCH
        $method = $request->get_method();
        if (!in_array($method, ['PUT', 'PATCH'], true)) {
            return $result;
        }
        
        // Verifica se tem stock_quantity no payload
        $params = $request->get_json_params();
        if (!isset($params['stock_quantity'])) {
            return $result;
        }
        
        // Lê configurações
        $opts = \C2P\Settings::get_options();
        
        // ✅ VERIFICA SE ESTÁ HABILITADO
        if (empty($opts['accept_global_stock'])) {
            $behavior = $opts['on_global_disabled'] ?? 'ignore_ok';
            
            if ($behavior === 'error_422') {
                return new \WP_Error(
                    'c2p_global_stock_disabled',
                    __('Atualização global de estoque está desabilitada. Use endpoints por local.', 'c2p'),
                    ['status' => 422]
                );
            }
            
            // ignore_ok: apenas ignora e deixa o WooCommerce processar
            return $result;
        }
        
        // ✅ OBTÉM CONFIGURAÇÕES
        $strategy = sanitize_key($opts['global_strategy'] ?? 'cd_global');
        $cd_store_id = absint($opts['cd_store_id'] ?? 0);
        
        if ($cd_store_id <= 0) {
            self::log_warning('CD Global não configurado. stock_quantity ignorado.');
            return $result;
        }
        
        // ✅ PROCESSA SEGUNDO A ESTRATÉGIA
        $product_id = absint($matches[1]);
        $requested_qty = absint($params['stock_quantity']);
        
        self::log_info(sprintf(
            'stock_quantity=%d recebido para produto #%d. Estratégia: %s',
            $requested_qty,
            $product_id,
            $strategy
        ));
        
        // Aplica estratégia
        switch ($strategy) {
            case 'cd_global':
                self::apply_strategy_cd_global($product_id, $requested_qty, $cd_store_id, $opts);
                break;
                
            case 'delta_cd':
                self::apply_strategy_delta_cd($product_id, $requested_qty, $cd_store_id, $opts);
                break;
                
            case 'proportional':
                self::apply_strategy_proportional($product_id, $requested_qty, $opts);
                break;
                
            case 'overwrite_all':
                self::apply_strategy_overwrite_all($product_id, $requested_qty, $cd_store_id, $opts);
                break;
                
            default:
                self::apply_strategy_cd_global($product_id, $requested_qty, $cd_store_id, $opts);
        }
        
        // Remove stock_quantity do payload para evitar conflito com WooCommerce
        $request->set_param('stock_quantity', null);
        
        // Retorna null para deixar o WooCommerce processar o resto da requisição
        return null;
    }
    
    /**
     * ========================================
     * ESTRATÉGIAS DE DISTRIBUIÇÃO
     * ========================================
     */
    
    /**
     * ESTRATÉGIA 1: CD Global (sobrescrever ou delta)
     * 
     * @param int $product_id
     * @param int $requested_qty
     * @param int $cd_id
     * @param array $opts
     */
    private static function apply_strategy_cd_global(int $product_id, int $requested_qty, int $cd_id, array $opts): void {
        $current_cd_qty = self::get_location_qty($product_id, $cd_id);
        $use_delta = !empty($opts['cd_apply_delta']);
        
        $delta = $requested_qty - $current_cd_qty;
        
        if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
            \C2P\Stock_Ledger::apply_delta($product_id, $cd_id, $delta, [
                'source' => $use_delta ? 'erp_cd_global_delta' : 'erp_cd_global_overwrite',
                'who'    => 'external_api',
                'meta'   => [
                    'requested_qty' => $requested_qty,
                    'previous_qty' => $current_cd_qty,
                    'delta' => $delta,
                ],
            ]);
        }
        
        self::reindex_product_total($product_id);
    }
    
    /**
     * ESTRATÉGIA 2: Delta no CD (aplicar diferença total)
     * 
     * @param int $product_id
     * @param int $requested_qty
     * @param int $cd_id
     * @param array $opts
     */
    private static function apply_strategy_delta_cd(int $product_id, int $requested_qty, int $cd_id, array $opts): void {
        $current_total = self::get_total_stock($product_id);
        $delta = $requested_qty - $current_total;
        
        if ($delta < 0) {
            // Redução
            $cd_qty = self::get_location_qty($product_id, $cd_id);
            
            if (abs($delta) > $cd_qty) {
                // CD não tem saldo suficiente
                if (!empty($opts['delta_negative_fallback'])) {
                    // Fallback proporcional
                    self::apply_negative_delta_proportional($product_id, abs($delta), $cd_id);
                } else {
                    // Falha
                    self::log_error(sprintf(
                        'Delta negativo %d maior que saldo CD (%d). Operação abortada.',
                        $delta,
                        $cd_qty
                    ));
                    return;
                }
            } else {
                // CD tem saldo suficiente
                if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                    \C2P\Stock_Ledger::apply_delta($product_id, $cd_id, $delta, [
                        'source' => 'erp_delta_cd',
                        'who'    => 'external_api',
                        'meta'   => ['delta' => $delta],
                    ]);
                }
            }
        } else {
            // Adição: sempre no CD
            if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                \C2P\Stock_Ledger::apply_delta($product_id, $cd_id, $delta, [
                    'source' => 'erp_delta_cd',
                    'who'    => 'external_api',
                    'meta'   => ['delta' => $delta],
                ]);
            }
        }
        
        self::reindex_product_total($product_id);
    }
    
    /**
     * ESTRATÉGIA 3: Distribuição proporcional
     * 
     * @param int $product_id
     * @param int $requested_qty
     * @param array $opts
     */
    private static function apply_strategy_proportional(int $product_id, int $requested_qty, array $opts): void {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        // Obtém distribuição atual
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$col}` AS loc_id, qty FROM `{$table}` WHERE product_id = %d",
            $product_id
        ), ARRAY_A);
        
        if (empty($rows)) {
            // Não há distribuição: vai tudo para CD principal
            $cd_id = absint($opts['cd_store_id'] ?? 0);
            if ($cd_id > 0) {
                self::apply_strategy_cd_global($product_id, $requested_qty, $cd_id, $opts);
            }
            return;
        }
        
        $current_total = array_sum(array_column($rows, 'qty'));
        
        if ($current_total == 0) {
            // Divisão igual
            $per_location = floor($requested_qty / count($rows));
            $remainder = $requested_qty % count($rows);
            
            foreach ($rows as $i => $row) {
                $loc_id = absint($row['loc_id']);
                $new_qty = $per_location + ($i === 0 ? $remainder : 0);
                $delta = $new_qty;
                
                if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                    \C2P\Stock_Ledger::apply_delta($product_id, $loc_id, $delta, [
                        'source' => 'erp_proportional_equal',
                        'who'    => 'external_api',
                        'meta'   => ['new_qty' => $new_qty],
                    ]);
                }
            }
        } else {
            // Distribuição proporcional
            foreach ($rows as $row) {
                $loc_id = absint($row['loc_id']);
                $current_qty = absint($row['qty']);
                $proportion = $current_qty / $current_total;
                $new_qty = (int)round($requested_qty * $proportion);
                $delta = $new_qty - $current_qty;
                
                if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                    \C2P\Stock_Ledger::apply_delta($product_id, $loc_id, $delta, [
                        'source' => 'erp_proportional',
                        'who'    => 'external_api',
                        'meta'   => [
                            'proportion' => $proportion,
                            'new_qty' => $new_qty,
                        ],
                    ]);
                }
            }
        }
        
        self::reindex_product_total($product_id);
    }
    
    /**
     * ESTRATÉGIA 4: Sobrescrever tudo (zera outros locais)
     * 
     * @param int $product_id
     * @param int $requested_qty
     * @param int $cd_id
     * @param array $opts
     */
    private static function apply_strategy_overwrite_all(int $product_id, int $requested_qty, int $cd_id, array $opts): void {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        // Zera todos os locais exceto CD principal
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET qty = 0 WHERE product_id = %d AND `{$col}` != %d",
            $product_id,
            $cd_id
        ));
        
        // Define tudo no CD principal
        $current_cd_qty = self::get_location_qty($product_id, $cd_id);
        $delta = $requested_qty - $current_cd_qty;
        
        if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
            \C2P\Stock_Ledger::apply_delta($product_id, $cd_id, $delta, [
                'source' => 'erp_overwrite_all',
                'who'    => 'external_api',
                'meta'   => ['requested_qty' => $requested_qty],
            ]);
        }
        
        self::reindex_product_total($product_id);
    }
    
    /**
     * ========================================
     * HELPER METHODS
     * ========================================
     */
    
    /**
     * Distribuir delta negativo proporcionalmente
     * 
     * @param int $product_id
     * @param int $reduction
     * @param int $cd_id
     */
    private static function apply_negative_delta_proportional(int $product_id, int $reduction, int $cd_id): void {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$col}` AS loc_id, qty FROM `{$table}` WHERE product_id = %d AND qty > 0 ORDER BY qty DESC",
            $product_id
        ), ARRAY_A);
        
        if (empty($rows)) {
            return;
        }
        
        $total = array_sum(array_column($rows, 'qty'));
        $remaining = $reduction;
        
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            
            $loc_id = absint($row['loc_id']);
            $qty = absint($row['qty']);
            $proportion = $qty / $total;
            $to_reduce = min((int)ceil($remaining * $proportion), $qty);
            
            if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                \C2P\Stock_Ledger::apply_delta($product_id, $loc_id, -$to_reduce, [
                    'source' => 'erp_negative_fallback',
                    'who'    => 'external_api',
                    'meta'   => ['reduction' => $to_reduce],
                ]);
            }
            
            $remaining -= $to_reduce;
        }
    }
    
    /**
     * Obtém quantidade em um local
     * 
     * @param int $product_id
     * @param int $location_id
     * @return int
     */
    private static function get_location_qty(int $product_id, int $location_id): int {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        $qty = $wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM `{$table}` WHERE product_id = %d AND `{$col}` = %d LIMIT 1",
            $product_id,
            $location_id
        ));
        
        return is_numeric($qty) ? absint($qty) : 0;
    }
    
    /**
     * Obtém total de estoque do produto
     * 
     * @param int $product_id
     * @return int
     */
    private static function get_total_stock(int $product_id): int {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0) FROM `{$table}` WHERE product_id = %d",
            $product_id
        ));
        
        return is_numeric($total) ? absint($total) : 0;
    }
    
    /**
     * Reindexar total do produto
     * 
     * @param int $product_id
     */
    private static function reindex_product_total(int $product_id): void {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        $sum = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0) FROM `{$table}` WHERE product_id = %d",
            $product_id
        )));
        
        $product = wc_get_product($product_id);
        if ($product) {
            $product->set_stock_quantity($sum);
            if (!$product->backorders_allowed()) {
                $product->set_stock_status($sum > 0 ? 'instock' : 'outofstock');
            }
            $product->save();
        }
        
        // Atualiza snapshots
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$col}` AS loc, qty FROM `{$table}` WHERE product_id = %d ORDER BY `{$col}` ASC",
            $product_id
        ), ARRAY_A);
        
        $by_id = [];
        $by_name = [];
        
        if ($rows) {
            foreach ($rows as $r) {
                $loc_id = absint($r['loc']);
                $qty = absint($r['qty']);
                $by_id[$loc_id] = $qty;
                
                $title = get_the_title($loc_id) ?: ('Local #' . $loc_id);
                $by_name[$title] = $qty;
            }
        }
        
        update_post_meta($product_id, C2P::META_STOCK_BY_ID, $by_id);
        update_post_meta($product_id, C2P::META_STOCK_BY_NAME, $by_name);
        
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables($product_id);
        }
    }
    
    /**
     * ========================================
     * UI RENDERING
     * ========================================
     */
    
    /**
     * Obtém lista de lojas
     * 
     * @return array
     */
    private static function get_stores_list(): array {
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
            $pid = absint($pid);
            $type = get_post_meta($pid, 'c2p_type', true);
            $type_label = ($type === 'cd') ? __('CD', 'c2p') : __('Loja', 'c2p');
            $out[$pid] = get_the_title($pid) . ' (#' . $pid . ') — ' . $type_label;
        }
        
        return $out;
    }
    
    /**
     * Renderiza aba ERP
     * 
     * @param array $settings
     */
    private static function render_tab_erp(array $settings): void {
        $stores = self::get_stores_list();
        
        ?>
        <h2 class="title"><?php esc_html_e('Integração com ERP (Estoque Global)', 'c2p'); ?></h2>
        
        <table class="form-table" role="presentation">
            <tbody>
                <!-- ========================================
                     ACEITAR ATUALIZAÇÃO GLOBAL
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Aceitar atualização global via stock_quantity', 'c2p'); ?></th>
                    <td>
                        <!-- ✅ CORRIGIDO v2.1.1: Hidden input para permitir desabilitar -->
                        <input type="hidden" name="c2p_settings[accept_global_stock]" value="0" />
                        
                        <label>
                            <input type="checkbox" 
                                   name="c2p_settings[accept_global_stock]" 
                                   value="1" 
                                   <?php checked(1, $settings['accept_global_stock'] ?? 0); ?> />
                            <?php esc_html_e('Permitir que a API aceite stock_quantity (estoque global) de ERPs externos.', 'c2p'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Quando desativado: a API ignora stock_quantity (ou retorna erro, conforme opção abaixo).', 'c2p'); ?>
                        </p>
                    </td>
                </tr>

                <!-- ========================================
                     COMPORTAMENTO QUANDO DESATIVADO
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Comportamento quando desativado', 'c2p'); ?></th>
                    <td>
                        <label>
                            <input type="radio" 
                                   name="c2p_settings[on_global_disabled]" 
                                   value="ignore_ok" 
                                   <?php checked('ignore_ok', $settings['on_global_disabled'] ?? 'ignore_ok'); ?> />
                            <?php esc_html_e('Ignorar silenciosamente (200 OK)', 'c2p'); ?>
                        </label>
                        <br />
                        <label>
                            <input type="radio" 
                                   name="c2p_settings[on_global_disabled]" 
                                   value="error_422" 
                                   <?php checked('error_422', $settings['on_global_disabled'] ?? 'ignore_ok'); ?> />
                            <?php esc_html_e('Retornar erro 422 (Unprocessable Entity)', 'c2p'); ?>
                        </label>
                    </td>
                </tr>

                <!-- ========================================
                     ESTRATÉGIA PRINCIPAL
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Estratégia principal', 'c2p'); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:12px;">
                            <input type="radio" 
                                   name="c2p_settings[global_strategy]" 
                                   value="cd_global" 
                                   <?php checked('cd_global', $settings['global_strategy'] ?? 'cd_global'); ?> />
                            <strong><?php esc_html_e('Depósito Padrão (CD Global)', 'c2p'); ?></strong>
                        </label>
                        <p class="description" style="margin-left:24px;margin-bottom:16px;">
                            <?php esc_html_e('Toda atualização global é aplicada em um local único (CD Global).', 'c2p'); ?>
                        </p>

                        <label style="display:block;margin-bottom:12px;">
                            <input type="radio" 
                                   name="c2p_settings[global_strategy]" 
                                   value="proportional" 
                                   <?php checked('proportional', $settings['global_strategy'] ?? 'cd_global'); ?> />
                            <strong><?php esc_html_e('Distribuição proporcional', 'c2p'); ?></strong>
                        </label>
                        <p class="description" style="margin-left:24px;margin-bottom:16px;">
                            <?php esc_html_e('Reparte o valor global entre os locais na mesma proporção dos estoques atuais.', 'c2p'); ?>
                        </p>

                        <label style="display:block;margin-bottom:12px;">
                            <input type="radio" 
                                   name="c2p_settings[global_strategy]" 
                                   value="delta_cd" 
                                   <?php checked('delta_cd', $settings['global_strategy'] ?? 'cd_global'); ?> />
                            <strong><?php esc_html_e('Aplicar delta no CD Global', 'c2p'); ?></strong>
                        </label>
                        <p class="description" style="margin-left:24px;margin-bottom:16px;">
                            <?php esc_html_e('Aplica apenas a diferença (novo total – total atual) no CD Global.', 'c2p'); ?>
                        </p>

                        <label style="display:block;margin-bottom:12px;">
                            <input type="radio" 
                                   name="c2p_settings[global_strategy]" 
                                   value="overwrite_all" 
                                   <?php checked('overwrite_all', $settings['global_strategy'] ?? 'cd_global'); ?> />
                            <strong><?php esc_html_e('Sobrescrever tudo', 'c2p'); ?></strong>
                        </label>
                        <p class="description" style="margin-left:24px;margin-bottom:8px;">
                            <?php esc_html_e('Zera todos os locais e define o total somente no CD Global.', 'c2p'); ?>
                        </p>
                        <div style="margin-left:24px;padding:12px;background:#fee2e2;border-left:4px solid#dc2626;border-radius:6px;">
                            <strong style="color:#991b1b;">
                                ⚠️ <?php esc_html_e('AVISO: alto risco para retirada por loja. Use apenas se o ERP for a fonte de verdade.', 'c2p'); ?>
                            </strong>
                        </div>
                    </td>
                </tr>

                <!-- ========================================
                     CD GLOBAL (LOCAL)
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('CD Global (local)', 'c2p'); ?></th>
                    <td>
                        <?php if (empty($stores)): ?>
                            <p style="color:#dc2626;font-weight:600;margin-bottom:12px;">
                                ⚠️ <?php esc_html_e('Nenhum local cadastrado. Crie um local do tipo "CD" primeiro.', 'c2p'); ?>
                            </p>
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . C2P::POST_TYPE_STORE)); ?>" 
                               class="button">
                                ➕ <?php esc_html_e('Criar Local de Estoque', 'c2p'); ?>
                            </a>
                        <?php else: ?>
                            <select name="c2p_settings[cd_store_id]" style="min-width:300px;">
                                <option value="0"><?php esc_html_e('— Selecione um local —', 'c2p'); ?></option>
                                <?php foreach ($stores as $sid => $label): ?>
                                    <option value="<?php echo absint($sid); ?>" 
                                            <?php selected($sid, absint($settings['cd_store_id'] ?? 0)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Local que receberá atualizações globais (quando aplicável).', 'c2p'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- ========================================
                     OPÇÕES COMBINÁVEIS
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Opções combináveis', 'c2p'); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" 
                                   name="c2p_settings[cd_apply_delta]" 
                                   value="1" 
                                   <?php checked(1, $settings['cd_apply_delta'] ?? 0); ?> />
                            <?php esc_html_e('CD Global: usar delta em vez de sobrescrever', 'c2p'); ?>
                        </label>
                        
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" 
                                   name="c2p_settings[delta_negative_fallback]" 
                                   value="1" 
                                   <?php checked(1, $settings['delta_negative_fallback'] ?? 0); ?> />
                            <?php esc_html_e('Delta negativo: fallback proporcional', 'c2p'); ?>
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- ========================================
             INFORMAÇÕES ADICIONAIS
             ======================================== -->
        <div style="background:#f0f9ff;border-left:4px solid #0ea5e9;padding:16px;margin-top:20px;border-radius:6px;max-width:980px;">
            <h4 style="margin:0 0 12px;color:#0369a1;">
                💡 <?php esc_html_e('Como funciona a interceptação', 'c2p'); ?>
            </h4>
            <ul style="margin:0;padding-left:20px;color:#374151;line-height:1.8;">
                <li>
                    <strong><?php esc_html_e('Endpoint:', 'c2p'); ?></strong>
                    <code>PUT/PATCH /wc/v3/products/{id}</code>
                </li>
                <li>
                    <strong><?php esc_html_e('Parâmetro:', 'c2p'); ?></strong>
                    <code>stock_quantity</code> <?php esc_html_e('(estoque global)', 'c2p'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Ação:', 'c2p'); ?></strong>
                    <?php esc_html_e('O plugin intercepta a requisição e distribui o estoque conforme a estratégia configurada.', 'c2p'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Logs:', 'c2p'); ?></strong>
                    <?php esc_html_e('Operações são registradas em', 'c2p'); ?>
                    <code>WooCommerce > Status > Logs > c2p-erp</code>
                    <?php esc_html_e('(apenas se WP_DEBUG estiver ativo)', 'c2p'); ?>
                </li>
            </ul>
        </div>
        
        <?php
    }
}

/**
 * ========================================
 * BOOTSTRAP
 * ========================================
 */

// ✅ Registra os hooks APENAS se as classes existirem
add_action('rest_api_init', function() {
    if (!class_exists('\C2P\Settings')) {
        return;
    }
    
    if (!trait_exists('\C2P\Settings_Tabs\Tab_ERP')) {
        return;
    }
    
    \C2P\Settings_Tabs\Tab_ERP::init_erp_interceptor();
}, 1);