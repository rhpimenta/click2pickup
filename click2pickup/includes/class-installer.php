<?php
/**
 * Click2Pickup - Instalador e Desinstalador Seguro
 * 
 * ✅ INSTALAÇÃO: Migra estoque WooCommerce → Local padrão
 * ⚠️ DESATIVAÇÃO: Apenas gera backup (NÃO deleta dados)
 * 
 * @package Click2Pickup
 * @since 1.0.0
 * @author rhpimenta
 * Last Update: 2025-01-09 00:16:30 UTC
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

final class Installer {

    private static $instance = null;

    // ✅ Constantes locais (não depende de class-constants.php)
    const TABLE_STOCK = 'c2p_stock';
    const TABLE_LEDGER = 'c2p_stock_ledger';
    const COL_STORE = 'store_id';
    const POST_TYPE_STORE = 'c2p_store';
    const META_STOCK_BY_IDS = 'c2p_stock_by_ids';
    const META_STOCK_BY_NAME = 'c2p_stock_by_name';

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_notices', [$this, 'activation_notice']);
        add_action('admin_notices', [$this, 'deactivation_notice']);
    }

    /* ====================================================================
     * MÉTODO ESTÁTICO DE ATIVAÇÃO
     * ================================================================== */

    public static function activate(): void {
        global $wpdb;

        self::create_tables();

        $existing_stores = get_posts([
            'post_type' => self::POST_TYPE_STORE,
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        if (empty($existing_stores)) {
            $default_store_id = self::create_default_store();
            $migrated_count = self::migrate_existing_stock_to_default($default_store_id);
            self::link_default_store_to_shipping($default_store_id);
            
            update_option('c2p_activation_data', [
                'default_store_id' => $default_store_id,
                'migrated_products' => $migrated_count,
                'timestamp' => current_time('mysql'),
                'user' => wp_get_current_user()->user_login,
            ], false);
        }

        set_transient('c2p_activation_notice', 'success', 60);
        flush_rewrite_rules();
    }

    /* ====================================================================
     * MÉTODO ESTÁTICO DE DESATIVAÇÃO
     * ================================================================== */

    public static function deactivate(): void {
        $report = self::generate_backup_report();
        update_option('c2p_deactivation_backup', $report, false);
        update_option('c2p_deactivation_timestamp', current_time('mysql'), false);
        
        set_transient('c2p_deactivation_notice', 'warning', 300);
        
        flush_rewrite_rules();
    }

    /* ====================================================================
     * MÉTODOS AUXILIARES (ESTÁTICOS)
     * ================================================================== */

    private static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_STOCK;
    }

    private static function get_ledger_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LEDGER;
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();
        $table_stock = self::get_table_name();
        $table_ledger = self::get_ledger_table_name();
        $col_store = self::COL_STORE;

        $sql_stock = "CREATE TABLE IF NOT EXISTS {$table_stock} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            {$col_store} BIGINT(20) UNSIGNED NOT NULL,
            qty INT(11) NOT NULL DEFAULT 0,
            low_stock_amount INT(11) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_location (product_id, {$col_store}),
            KEY idx_product (product_id),
            KEY idx_location ({$col_store})
        ) {$charset_collate};";

        $sql_ledger = "CREATE TABLE IF NOT EXISTS {$table_ledger} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            location_id BIGINT(20) UNSIGNED DEFAULT NULL,
            location_name_text VARCHAR(255) DEFAULT NULL,
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            delta INT(11) NOT NULL,
            qty_before INT(11) NOT NULL,
            qty_after INT(11) NOT NULL,
            source VARCHAR(100) NOT NULL,
            who VARCHAR(255) NOT NULL,
            meta LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product (product_id),
            KEY idx_location (location_id),
            KEY idx_order (order_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        dbDelta($sql_stock);
        dbDelta($sql_ledger);
    }

    private static function create_default_store(): int {
        $current_user = wp_get_current_user();
        $display_name = $current_user->display_name ?: 'Admin';
        
        $store_id = wp_insert_post([
            'post_type' => self::POST_TYPE_STORE,
            'post_title' => 'Estoque Padrão',
            'post_status' => 'publish',
            'post_content' => 'Local padrão criado automaticamente na ativação do plugin Click2Pickup em ' . wp_date('d/m/Y H:i:s') . ' por ' . $display_name . '.',
        ]);

        if (is_wp_error($store_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P] Erro ao criar local padrão: ' . $store_id->get_error_message());
            }
            return 0;
        }

        update_post_meta($store_id, 'c2p_type', 'cd');
        update_post_meta($store_id, 'c2p_is_default', 'yes');
        update_option('c2p_default_store_id', $store_id, false);

        return (int) $store_id;
    }

    private static function migrate_existing_stock_to_default(int $store_id): int {
        if ($store_id <= 0) return 0;

        global $wpdb;

        $products = $wpdb->get_results("
            SELECT p.ID, pm.meta_value AS stock, pm2.meta_value AS sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_stock')
            LEFT JOIN {$wpdb->postmeta} pm2 ON (p.ID = pm2.post_id AND pm2.meta_key = '_sku')
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'private')
              AND CAST(pm.meta_value AS SIGNED) > 0
        ", ARRAY_A);

        if (empty($products)) return 0;

        $table = self::get_table_name();
        $col_store = self::COL_STORE;
        $migrated = 0;

        foreach ($products as $prod) {
            $product_id = (int) $prod['ID'];
            $stock = max(0, (int) $prod['stock']);
            $sku = $prod['sku'] ?: '';

            if ($stock <= 0) continue;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND {$col_store} = %d LIMIT 1",
                $product_id,
                $store_id
            ));

            if ($exists) continue;

            $wpdb->insert($table, [
                'product_id' => $product_id,
                $col_store => $store_id,
                'qty' => $stock,
                'low_stock_amount' => null,
                'updated_at' => current_time('mysql', true),
            ], ['%d', '%d', '%d', '%s', '%s']);

            update_post_meta($product_id, 'c2p_initialized', 'yes');
            update_post_meta($product_id, self::META_STOCK_BY_IDS, [$store_id => $stock]);
            update_post_meta($product_id, self::META_STOCK_BY_NAME, ['Estoque Padrão' => $stock]);

            // Ledger (opcional - só se a classe existir)
            if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'record')) {
                \C2P\Stock_Ledger::record([
                    'product_id' => $product_id,
                    'location_id' => $store_id,
                    'order_id' => null,
                    'delta' => $stock,
                    'qty_before' => 0,
                    'qty_after' => $stock,
                    'source' => 'plugin_activation',
                    'who' => 'installer:migration',
                    'meta' => [
                        'event' => 'activation_migration',
                        'original_wc_stock' => $stock,
                        'migrated_to' => 'Estoque Padrão',
                        'store_id' => $store_id,
                        'sku' => $sku,
                        'timestamp' => current_time('mysql'),
                        'user' => wp_get_current_user()->user_login,
                    ],
                ]);
            }

            $migrated++;
        }

        return $migrated;
    }

    private static function link_default_store_to_shipping(int $store_id): void {
        if ($store_id <= 0 || !class_exists('WC_Shipping_Zones')) {
            return;
        }

        $zones = \WC_Shipping_Zones::get_zones();

        foreach ($zones as $zone_data) {
            if (!isset($zone_data['shipping_methods'])) continue;
            
            foreach ($zone_data['shipping_methods'] as $method) {
                if (!is_object($method)) continue;
                
                $instance_id = method_exists($method, 'get_instance_id') ? $method->get_instance_id() : 0;
                $method_id = method_exists($method, 'get_id') ? $method->get_id() : '';
                
                if (!$instance_id || !$method_id) continue;
                
                $option_key = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
                $settings = get_option($option_key, []);
                
                if (!isset($settings['c2p_enabled_stores'])) {
                    $settings['c2p_enabled_stores'] = [];
                }
                
                if (!is_array($settings['c2p_enabled_stores'])) {
                    $settings['c2p_enabled_stores'] = [];
                }
                
                if (!in_array($store_id, $settings['c2p_enabled_stores'], true)) {
                    $settings['c2p_enabled_stores'][] = $store_id;
                    update_option($option_key, $settings);
                }
            }
        }

        update_post_meta($store_id, 'c2p_auto_linked_shipping', 'yes');
    }

    private static function generate_backup_report(): array {
        global $wpdb;

        $table = self::get_table_name();
        $col_store = self::COL_STORE;

        $report = [
            'timestamp' => current_time('mysql'),
            'user' => wp_get_current_user()->user_login,
            'user_display_name' => wp_get_current_user()->display_name,
            'total_products' => 0,
            'total_stock' => 0,
            'locations' => [],
            'products' => [],
        ];

        $rows = $wpdb->get_results("
            SELECT s.product_id, s.{$col_store} AS location_id, s.qty,
                   p.post_title AS product_name,
                   l.post_title AS location_name,
                   pm.meta_value AS sku
            FROM {$table} s
            LEFT JOIN {$wpdb->posts} p ON (p.ID = s.product_id)
            LEFT JOIN {$wpdb->posts} l ON (l.ID = s.{$col_store})
            LEFT JOIN {$wpdb->postmeta} pm ON (pm.post_id = s.product_id AND pm.meta_key = '_sku')
            WHERE s.qty > 0
            ORDER BY s.product_id, s.{$col_store}
        ", ARRAY_A);

        if (empty($rows)) return $report;

        $products_summary = [];
        $locations_summary = [];

        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $lid = (int) $r['location_id'];
            $qty = (int) $r['qty'];
            $product_name = $r['product_name'] ?: '#' . $pid;
            $location_name = $r['location_name'] ?: '#' . $lid;
            $sku = $r['sku'] ?: 'N/A';

            if (!isset($products_summary[$pid])) {
                $products_summary[$pid] = [
                    'name' => $product_name,
                    'sku' => $sku,
                    'total_qty' => 0,
                    'locations' => [],
                ];
            }

            $products_summary[$pid]['total_qty'] += $qty;
            $products_summary[$pid]['locations'][$location_name] = $qty;

            if (!isset($locations_summary[$lid])) {
                $locations_summary[$lid] = [
                    'name' => $location_name,
                    'total_qty' => 0,
                    'products_count' => 0,
                ];
            }

            $locations_summary[$lid]['total_qty'] += $qty;
            $locations_summary[$lid]['products_count']++;

            $report['total_stock'] += $qty;
        }

        $report['total_products'] = count($products_summary);
        $report['locations'] = $locations_summary;
        $report['products'] = $products_summary;

        return $report;
    }

    /* ====================================================================
     * AVISOS NO ADMIN
     * ================================================================== */

    public function activation_notice(): void {
        if (!get_transient('c2p_activation_notice')) {
            return;
        }

        delete_transient('c2p_activation_notice');

        $activation_data = get_option('c2p_activation_data', []);
        $migrated = $activation_data['migrated_products'] ?? 0;
        $store_id = $activation_data['default_store_id'] ?? 0;

        if ($migrated > 0 && $store_id > 0) {
            $store_url = admin_url('post.php?post=' . $store_id . '&action=edit');
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<h3>✅ Click2Pickup ativado com sucesso!</h3>';
            echo '<p><strong>' . sprintf(esc_html__('%d produtos migrados', 'c2p'), $migrated) . '</strong> para o local padrão <a href="' . esc_url($store_url) . '" target="_blank"><strong>"Estoque Padrão"</strong></a>.</p>';
            echo '<p>🔹 Seu estoque foi preservado e as vendas continuarão normalmente.</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<h3>✅ Click2Pickup ativado!</h3>';
            echo '<p>Nenhum produto com estoque encontrado para migração.</p>';
            echo '</div>';
        }
    }

    public function deactivation_notice(): void {
        if (!get_transient('c2p_deactivation_notice')) {
            return;
        }

        delete_transient('c2p_deactivation_notice');

        $backup = get_option('c2p_deactivation_backup', []);
        $total_stock = $backup['total_stock'] ?? 0;

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<h3>⚠️ Click2Pickup Desativado</h3>';
        echo '<p><strong>Seus dados foram preservados!</strong></p>';
        echo '<p>🔹 Estoque total: <strong>' . esc_html($total_stock) . '</strong> unidades</p>';
        echo '<p>🔹 Para remover completamente o plugin, <strong>desinstale-o</strong> na lista de plugins.</p>';
        echo '<p>🔹 Se reativar, todos os dados estarão intactos.</p>';
        echo '</div>';
    }
}

// Bootstrap
add_action('plugins_loaded', function() {
    if (class_exists('\C2P\Installer')) {
        \C2P\Installer::instance();
    }
}, 5);