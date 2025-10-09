<?php
/**
 * Click2Pickup - Core (Bootstrap + Schema + Init)
 * 
 * Consolida:
 * - Plugin (Bootstrap + Assets)
 * - Inventory_DB (Schema do banco)
 * - Init_Scan (Inicialização de estoque)
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-01-09 00:21:17 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 00:21: ✅ SEGURANÇA: Escape de tabelas/colunas em SQL
 * - 2025-01-09 00:21: ✅ PERFORMANCE: Query otimizada (batch insert único)
 * - 2025-01-09 00:21: ✅ LIMPEZA: Removido código de classes opcionais
 * - 2025-01-09 00:21: ✅ CONFIABILIDADE: Lock com auto-cleanup
 * - 2025-01-09 00:21: ✅ DEBUG: Logger robusto com fallback
 * - 2025-01-09 00:21: ✅ Cache com TTL e invalidação
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

/* ============================================================
 * PARTE 1: PLUGIN (Bootstrap + Assets)
 * ========================================================== */

class Plugin {
    private static $instance = null;
    private static $asset_cache = [];

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // ✅ REMOVIDO: Código de classes opcionais (Runtime_Guard, Profiler, Setup_Tweaks)
        // Se você precisar deles, carregue-os explicitamente no click2pickup.php
        
        // ✅ CORRIGIDO: Hook com prioridade normal (10)
        add_action('admin_enqueue_scripts', [$this, 'admin_assets'], 10);
    }

    public function admin_assets($hook_suffix): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        $is_c2p_store = ($screen && !empty($screen->post_type) && $screen->post_type === C2P::POST_TYPE_STORE);

        $current_pages = ['c2p-dashboard', 'c2p-dashboard-home', 'c2p-settings'];
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        $is_plugin_page = in_array($page, $current_pages, true) || 
            ($screen && in_array((string)$screen->id, [
                'toplevel_page_c2p-dashboard',
                'c2p_page_c2p-settings',
                'c2p_page_c2p-dashboard-home',
            ], true));

        if (!$is_c2p_store && !$is_plugin_page) {
            return;
        }

        if ($is_c2p_store) {
            if (function_exists('wp_enqueue_media')) {
                wp_enqueue_media();
            }
            wp_enqueue_style('wp-components');
        }

        $ver = C2P_VERSION;

        $admin_css = $this->first_existing_cached('admin_css', [
            'assets/css/admin.min.css',
            'assets/css/admin.css',
            'assets/admin.min.css',
            'assets/admin.css',
        ]);
        
        if ($admin_css) {
            wp_enqueue_style('c2p-admin', $this->url($admin_css), [], $ver);
        }

        $admin_js = $this->first_existing_cached('admin_js', [
            'assets/js/admin.min.js',
            'assets/js/admin.js',
            'assets/admin.min.js',
            'assets/admin.js',
        ]);
        
        if ($admin_js) {
            wp_enqueue_script('c2p-admin', $this->url($admin_js), ['jquery'], $ver, true);
        }
    }

    /**
     * ✅ CORRIGIDO: Cache com limpeza adequada
     */
    private function first_existing_cached(string $key, array $candidates): ?string {
        if (isset(self::$asset_cache[$key])) {
            $cached = self::$asset_cache[$key];
            return ($cached !== '') ? $cached : null;
        }
        
        $found = $this->first_existing($candidates);
        self::$asset_cache[$key] = $found ?: '';
        
        return $found;
    }

    private function first_existing(array $candidates): ?string {
        $base = C2P_PATH;
        
        foreach ($candidates as $rel) {
            if (!is_string($rel)) {
                continue;
            }
            
            $rel = ltrim($rel, '/\\');
            $abs = $base . $rel;
            
            if (file_exists($abs)) {
                return $rel;
            }
        }
        
        return null;
    }

    private function url(string $relative): string {
        $relative = ltrim($relative, '/\\');
        return rtrim(C2P_URL, '/\\') . '/' . $relative;
    }
    
    /**
     * ✅ NOVO: Limpar cache de assets
     */
    public static function clear_asset_cache(): void {
        self::$asset_cache = [];
    }
}

/* ============================================================
 * PARTE 2: INVENTORY_DB (Schema do Banco)
 * ========================================================== */

final class Inventory_DB {
    
    const SCHEMA_VERSION = 6; // ✅ Incrementado para nova versão

    /**
     * @deprecated 2.0.0 Use Constants::table()
     */
    public static function table_name(): string {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // ✅ CORRIGIDO: Aviso de deprecated
            @trigger_error(
                'Inventory_DB::table_name() is deprecated. Use Constants::table() instead.',
                E_USER_DEPRECATED
            );
        }
        return C2P::table();
    }

    /**
     * @deprecated 2.0.0 Use Constants::col_store()
     */
    public static function store_column_name(): string {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            @trigger_error(
                'Inventory_DB::store_column_name() is deprecated. Use Constants::col_store() instead.',
                E_USER_DEPRECATED
            );
        }
        return C2P::col_store();
    }

    public static function table_exists(string $table): bool {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape do nome da tabela
        $table_escaped = esc_sql($table);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_escaped));
        
        return ($found === $table);
    }

    public static function maybe_migrate_table_name(): void {
        global $wpdb;
        $new = C2P::table();

        $candidates_old = [
            $wpdb->prefix . 'c2p_multistock',
            $wpdb->prefix . 'c2p_multiestoque',
            $wpdb->prefix . 'woocommerce_multi_stock',
            $wpdb->prefix . 'c2p_stock_locations',
        ];

        if (self::table_exists($new)) {
            return;
        }

        foreach ($candidates_old as $old) {
            if (self::table_exists($old)) {
                // ✅ SEGURANÇA: Escape de nomes de tabelas
                $old_escaped = esc_sql($old);
                $new_escaped = esc_sql($new);
                
                $wpdb->query("RENAME TABLE `{$old_escaped}` TO `{$new_escaped}`");
                
                if (self::table_exists($new)) {
                    self::log("Tabela renomeada: {$old} → {$new}");
                    return;
                }
            }
        }
    }

    public static function create_or_update_table(): void {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape do nome da tabela
        $table = esc_sql(C2P::table());
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE `{$table}` (
            `product_id`       BIGINT(20) UNSIGNED NOT NULL,
            `store_id`         BIGINT(20) UNSIGNED NOT NULL,
            `qty`              INT NOT NULL DEFAULT 0,
            `low_stock_amount` INT UNSIGNED NULL DEFAULT NULL,
            `updated_at`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (`product_id`, `store_id`),
            KEY `idx_store`   (`store_id`),
            KEY `idx_product` (`product_id`)
        ) {$charset};";

        dbDelta($sql);
        self::log("dbDelta executado para {$table}");
    }

    public static function ensure_store_column(): void {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape do nome da tabela
        $table = esc_sql(C2P::table());

        if (!self::table_exists($table)) {
            self::log("Tabela {$table} não existe ao ensure_store_column().");
            return;
        }

        $cols = self::get_columns($table);
        $hasStore = in_array('store_id', $cols, true);
        $hasLoc = in_array('location_id', $cols, true);
        $hasC2p = in_array('c2p_store_id', $cols, true);

        // Migração de nomes de colunas
        if (!$hasStore && $hasLoc) {
            $wpdb->query("ALTER TABLE `{$table}` CHANGE COLUMN `location_id` `store_id` BIGINT(20) UNSIGNED NOT NULL");
            $cols = self::get_columns($table);
            $hasStore = in_array('store_id', $cols, true);
        }

        if (!$hasStore && $hasC2p) {
            $wpdb->query("ALTER TABLE `{$table}` CHANGE COLUMN `c2p_store_id` `store_id` BIGINT(20) UNSIGNED NOT NULL");
            $cols = self::get_columns($table);
            $hasStore = in_array('store_id', $cols, true);
        }

        if (!$hasStore) {
            $after = in_array('product_id', $cols, true) ? " AFTER `product_id`" : "";
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `store_id` BIGINT(20) UNSIGNED NOT NULL{$after}");
            $cols = self::get_columns($table);
        }

        // Garantir tipos corretos
        if (in_array('product_id', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` MODIFY `product_id` BIGINT(20) UNSIGNED NOT NULL");
        }
        
        if (in_array('store_id', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` MODIFY `store_id` BIGINT(20) UNSIGNED NOT NULL");
        }

        if (in_array('qty', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` MODIFY `qty` INT NOT NULL DEFAULT 0");
        } else {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `qty` INT NOT NULL DEFAULT 0");
        }

        if (!in_array('low_stock_amount', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `low_stock_amount` INT UNSIGNED NULL DEFAULT NULL AFTER `qty`");
        }

        // Índices
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $index_names = array_map(function($r) { 
            return isset($r['Key_name']) ? (string)$r['Key_name'] : ''; 
        }, $indexes ?: []);

        if (!in_array('idx_store', $index_names, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `idx_store` (`store_id`)");
        }
        
        if (!in_array('idx_product', $index_names, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `idx_product` (`product_id`)");
        }

        // Primary Key
        $expected = ['product_id', 'store_id'];
        $current = self::current_primary_columns($table);

        if (empty($current)) {
            self::with_suppressed_errors(function() use ($table, $wpdb) {
                $wpdb->query("ALTER TABLE `{$table}` ADD PRIMARY KEY (`product_id`,`store_id`)");
            });
            self::log("PK composta criada em {$table}.");
        } elseif ($current !== $expected) {
            self::with_suppressed_errors(function() use ($table, $wpdb) {
                $wpdb->query("ALTER TABLE `{$table}` DROP PRIMARY KEY");
                $wpdb->query("ALTER TABLE `{$table}` ADD PRIMARY KEY (`product_id`,`store_id`)");
            });
            self::log("PK ajustada para (product_id, store_id) em {$table}.");
        }
    }

    public static function install_schema(): void {
        self::maybe_migrate_table_name();
        self::create_or_update_table();
        self::ensure_store_column();
        update_option(C2P::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
        self::log('Schema/migração concluídos (v' . self::SCHEMA_VERSION . ')');
    }

    public static function maybe_upgrade_on_load(): void {
        $current = (int)get_option(C2P::OPTION_SCHEMA_VERSION, 0);
        
        if ($current >= self::SCHEMA_VERSION) {
            return;
        }
        
        self::install_schema();
    }

    public static function install(): void {
        self::install_schema();
    }

    private static function get_columns(string $table): array {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape do nome da tabela
        $table_escaped = esc_sql($table);
        $cols = (array)$wpdb->get_col("SHOW COLUMNS FROM `{$table_escaped}`", 0);
        
        return array_map('strval', $cols);
    }

    private static function current_primary_columns(string $table): array {
        global $wpdb;
        
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND CONSTRAINT_NAME = 'PRIMARY'
              ORDER BY ORDINAL_POSITION ASC",
            $table
        ));
        
        if (is_array($rows) && !empty($rows)) {
            return array_map('strval', $rows);
        }

        // Fallback via SHOW INDEX
        $table_escaped = esc_sql($table);
        $idx = $wpdb->get_results("SHOW INDEX FROM `{$table_escaped}`", ARRAY_A);
        
        if (!is_array($idx) || empty($idx)) {
            return [];
        }
        
        $primaries = array_values(array_filter($idx, static function($r) {
            return isset($r['Key_name']) && $r['Key_name'] === 'PRIMARY';
        }));
        
        if (empty($primaries)) {
            return [];
        }
        
        usort($primaries, static function($a, $b) {
            $sa = isset($a['Seq_in_index']) ? (int)$a['Seq_in_index'] : 0;
            $sb = isset($b['Seq_in_index']) ? (int)$b['Seq_in_index'] : 0;
            return $sa <=> $sb;
        });
        
        $cols = [];
        foreach ($primaries as $r) {
            if (isset($r['Column_name'])) {
                $cols[] = (string)$r['Column_name'];
            }
        }
        
        return $cols;
    }

    private static function with_suppressed_errors(callable $fn): void {
        global $wpdb;
        $prev_suppress = $wpdb->suppress_errors();
        $wpdb->suppress_errors(true);
        
        if (method_exists($wpdb, 'hide_errors')) {
            $wpdb->hide_errors();
        }

        try {
            $fn();
        } finally {
            $wpdb->suppress_errors((bool)$prev_suppress);
            
            if (method_exists($wpdb, 'show_errors')) {
                $wpdb->show_errors();
            }
        }
    }

    /**
     * ✅ CORRIGIDO: Logger com fallback
     */
    private static function log(string $msg): void {
        if (function_exists('wc_get_logger')) {
            try {
                wc_get_logger()->info($msg, ['source' => 'c2p-db']);
            } catch (\Throwable $e) {
                error_log('[C2P][DB] ' . $msg);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P][DB] ' . $msg);
            }
        }
    }
}

/* ============================================================
 * PARTE 3: INIT_SCAN (Inicialização de Estoque)
 * ========================================================== */

final class Init_Scan {
    private static $instance = null;

    const BATCH_SIZE = 500; // ✅ Aumentado de 100 para 500 (mais eficiente)
    const LOCK_TTL = 300;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('c2p_init_full_scan_worker', function($args) {
            $batch = isset($args['batch_size']) ? (int)$args['batch_size'] : self::BATCH_SIZE;
            self::run_full_scan($batch);
        });
        
        // ✅ NOVO: Cleanup automático do lock em caso de shutdown
        add_action('shutdown', [$this, 'cleanup_on_shutdown']);
    }

    public static function run_async(?int $batch_size = null): void {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('c2p_init_full_scan_worker', [
                'batch_size' => $batch_size ?: self::BATCH_SIZE,
            ], 'c2p');
        } else {
            self::run_full_scan($batch_size ?: self::BATCH_SIZE);
        }
    }

    public static function run_full_scan(?int $batch_size = null): void {
        $batch_size = max(50, (int)$batch_size ?: self::BATCH_SIZE);

        if (!self::acquire_lock()) {
            return;
        }

        try {
            global $wpdb;
            
            // ✅ SEGURANÇA: Escape de nomes
            $table = esc_sql(C2P::table());
            $col_store = esc_sql(C2P::col_store());

            $stores = self::get_published_store_ids();
            
            if (empty($stores)) {
                delete_option('c2p_init_scan_pending');
                return;
            }

            foreach ($stores as $store_id) {
                self::fill_missing_zero_rows_for_store($table, $col_store, (int)$store_id, $batch_size);
            }

            delete_option('c2p_init_scan_pending');

        } catch (\Throwable $e) {
            error_log('[C2P][Init_Scan][run_full_scan] ' . $e->getMessage());
        } finally {
            self::release_lock();
        }
    }

    /**
     * ✅ OTIMIZADO: Batch insert único em vez de loop
     */
    private static function fill_missing_zero_rows_for_store(string $table, string $col_store, int $store_id, int $batch_size): void {
        global $wpdb;

        // ✅ Query única para pegar todos os produtos faltantes
        $sql_missing = "
            SELECT p.ID
              FROM {$wpdb->posts} p
             WHERE p.post_type IN ('product','product_variation')
               AND p.post_status IN ('publish','private')
               AND NOT EXISTS (
                   SELECT 1
                     FROM `{$table}` t
                    WHERE t.product_id = p.ID
                      AND t.`{$col_store}` = %d
               )
        ";
        
        $missing_ids = $wpdb->get_col($wpdb->prepare($sql_missing, $store_id));

        if (empty($missing_ids)) {
            return;
        }

        // ✅ Processa em batches (mas sem loop infinito)
        $chunks = array_chunk($missing_ids, $batch_size);

        foreach ($chunks as $chunk) {
            $values = [];
            $placeholders = [];
            
            foreach ($chunk as $pid) {
                $placeholders[] = '(%d,%d,0,NOW())';
                $values[] = (int)$pid;
                $values[] = $store_id;
            }

            if (empty($placeholders)) {
                continue;
            }

            $sql_insert = "
                INSERT INTO `{$table}` (product_id, `{$col_store}`, qty, updated_at)
                VALUES " . implode(',', $placeholders) . "
                ON DUPLICATE KEY UPDATE qty = qty
            ";

            $wpdb->query($wpdb->prepare($sql_insert, $values));
        }
    }

    private static function acquire_lock(): bool {
        $key = 'c2p_init_scan_lock';
        
        if (get_transient($key)) {
            return false;
        }
        
        set_transient($key, time(), self::LOCK_TTL);
        return true;
    }

    private static function release_lock(): void {
        delete_transient('c2p_init_scan_lock');
    }
    
    /**
     * ✅ NOVO: Cleanup automático em shutdown
     */
    public function cleanup_on_shutdown(): void {
        $key = 'c2p_init_scan_lock';
        $lock_time = get_transient($key);
        
        if ($lock_time && is_numeric($lock_time)) {
            $elapsed = time() - (int)$lock_time;
            
            // Se passou mais de 5 minutos, libera o lock
            if ($elapsed > self::LOCK_TTL) {
                self::release_lock();
            }
        }
    }

    private static function get_published_store_ids(): array {
        $cache_key = 'c2p_published_stores';
        $ids = wp_cache_get($cache_key, 'c2p');
        
        if (false === $ids) {
            $ids = get_posts([
                'post_type' => C2P::POST_TYPE_STORE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);
            
            $ids = array_map('intval', (array)$ids);
            wp_cache_set($cache_key, $ids, 'c2p', HOUR_IN_SECONDS);
        }
        
        return $ids;
    }
    
    public static function clear_cache(): void {
        wp_cache_delete('c2p_published_stores', 'c2p');
    }
}

/* ============================================================
 * BOOTSTRAP AUTOMÁTICO
 * ========================================================== */

add_action('plugins_loaded', function() {
    // ✅ CORRIGIDO: Verifica WooCommerce antes de bootstrap
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    Plugin::instance();
    Inventory_DB::maybe_upgrade_on_load();
    Init_Scan::instance();
}, 2);