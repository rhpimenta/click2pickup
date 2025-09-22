<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( __NAMESPACE__ . '\\Installer' ) ) :

/**
 * C2P\Installer
 *
 * - Cria/atualiza o schema necessário do plugin
 * - Padroniza coluna store_id + índices via Inventory_DB
 * - Mantém upgrade silencioso (admin_init)
 * - Agenda varredura de inicialização (Action Scheduler) na ativação
 * - Não produz nenhuma saída (sem echo/wp_die)
 */
final class Installer {

    /** Bump quando houver nova migração. */
    const DB_VERSION       = '1.1.0';
    const OPT_DB_VERSION   = 'c2p_db_version';

    /** Ativação */
    public static function activate() : void {
        self::migrate();
        self::schedule_init_scan();
    }

    /** Hooks de upgrade silencioso + bootstrap leve */
    public static function register_hooks() : void {
        add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ] );
        add_action( 'plugins_loaded', [ __CLASS__, 'soft_bootstrap' ], 2 );
    }

    /** Executa migração se versão atrasada */
    public static function maybe_upgrade() : void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $stored = get_option( self::OPT_DB_VERSION, '' );
        if ( version_compare( (string) $stored, self::DB_VERSION, '>=' ) ) return;
        self::migrate();
    }

    /** Tenta acionar Inventory_DB ao carregar */
    public static function soft_bootstrap() : void {
        if ( class_exists( __NAMESPACE__ . '\\Inventory_DB' ) ) {
            if ( method_exists( __NAMESPACE__ . '\\Inventory_DB', 'maybe_upgrade_on_load' ) ) {
                \C2P\Inventory_DB::maybe_upgrade_on_load();
            } elseif ( method_exists( __NAMESPACE__ . '\\Inventory_DB', 'install_schema' ) ) {
                \C2P\Inventory_DB::install_schema();
            }
        }
    }

    /** Migração principal */
    private static function migrate() : void {
        if ( class_exists( __NAMESPACE__ . '\\Inventory_DB' ) ) {
            if ( method_exists( __NAMESPACE__ . '\\Inventory_DB', 'install_schema' ) ) {
                \C2P\Inventory_DB::install_schema();
            } else {
                self::fallback_schema_migration();
            }
        } else {
            self::fallback_schema_migration();
        }
        update_option( self::OPT_DB_VERSION, self::DB_VERSION );
    }

    /**
     * Agenda a varredura de inicialização (full scan) para criar snapshots e marcar c2p_initialized.
     * Executa somente se Action Scheduler existir e não houver ação pendente/rodando igual.
     */
    private static function schedule_init_scan() : void {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) return;

        $already = false;
        if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler_Store' ) ) {
            $pending = as_get_scheduled_actions( [
                'hook'     => 'c2p_init_full_scan',
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ] );
            $running = as_get_scheduled_actions( [
                'hook'     => 'c2p_init_full_scan',
                'status'   => \ActionScheduler_Store::STATUS_RUNNING,
                'per_page' => 1,
            ] );
            $already = ! empty( $pending ) || ! empty( $running );
        }

        if ( ! $already ) {
            as_enqueue_async_action( 'c2p_init_full_scan', [], 'c2p' );
        }
    }

    /** Fallback mínimo: cria tabela e garante store_id (caso Inventory_DB não esteja atualizado) */
    private static function fallback_schema_migration() : void {
        global $wpdb;

        $table = $wpdb->prefix . 'c2p_multi_stock';
        if ( ! self::table_exists( $table ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table} (
                product_id     BIGINT(20) UNSIGNED NOT NULL,
                store_id       BIGINT(20) UNSIGNED NOT NULL,
                stock_quantity INT NOT NULL DEFAULT 0,
                low_stock_amount INT UNSIGNED NULL DEFAULT NULL,
                updated_at     DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (product_id, store_id),
                KEY idx_store   (store_id),
                KEY idx_product (product_id)
            ) {$charset};";
            dbDelta( $sql );
            return;
        }

        // Renomeia colunas antigas se existirem
        $hasStore = self::column_exists($table, 'store_id');
        $hasLoc   = self::column_exists($table, 'location_id');
        $hasC2p   = self::column_exists($table, 'c2p_store_id');

        if ( ! $hasStore && $hasLoc ) {
            $wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `location_id` `store_id` BIGINT(20) UNSIGNED NOT NULL" );
            $hasStore = true;
        }
        if ( ! $hasStore && $hasC2p ) {
            $wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `c2p_store_id` `store_id` BIGINT(20) UNSIGNED NOT NULL" );
            $hasStore = true;
        }
        if ( ! $hasStore ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `store_id` BIGINT(20) UNSIGNED NOT NULL AFTER `product_id`" );
        }

        if ( self::column_exists($table, 'stock_quantity') ) {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `stock_quantity` INT NOT NULL DEFAULT 0" );
        } else {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `stock_quantity` INT NOT NULL DEFAULT 0" );
        }

        if ( ! self::column_exists($table, 'low_stock_amount') ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `low_stock_amount` INT UNSIGNED NULL DEFAULT NULL AFTER `stock_quantity`" );
        }

        self::ensure_index($table, 'idx_store',   '(`store_id`)');
        self::ensure_index($table, 'idx_product', '(`product_id`)');
    }

    /** Helpers internos */
    private static function table_exists( string $table_name ) : bool {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table_name
        ) );
        return (int) $exists > 0;
    }

    private static function column_exists( string $table_name, string $column ) : bool {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table_name, $column
        ) );
        return (int) $exists > 0;
    }

    private static function ensure_index( string $table_name, string $index_name, string $definition_sql_part ) : void {
        global $wpdb;
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table_name}`", ARRAY_A );
        $names   = array_map( function($r){ return isset($r['Key_name']) ? $r['Key_name'] : ''; }, $indexes ?: [] );
        if ( ! in_array( $index_name, $names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `{$index_name}` {$definition_sql_part}" );
        }
    }
}

endif;

\C2P\Installer::register_hooks();
