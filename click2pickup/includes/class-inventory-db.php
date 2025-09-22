<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Inventory_DB — schema e migrações da tabela de multi-estoque
 *
 * - Tabela canônica: {$wpdb->prefix}c2p_multi_stock
 * - Padroniza a coluna do local como "location_id" (renomeia de "location_id" ou "c2p_store_id" se necessário)
 * - Garante colunas mínimas, índices e PK composta (product_id, store_id) de forma idempotente
 * - Exponibiliza store_column_name() para SELECTs/UPDATEs compatíveis
 */
if ( ! class_exists( __NAMESPACE__ . '\\Inventory_DB' ) ) :

final class Inventory_DB {

    /** Incrementar sempre que mudar o schema/migração */
    const SCHEMA_VERSION = 4; // bump para reexecutar migração
    const SCHEMA_OPTION  = 'c2p_schema_version';

    /** Nome atual da tabela */
    public static function table_name() : string {
        global $wpdb;
        return $wpdb->prefix . 'c2p_multi_stock';
    }

    /** Descobre dinamicamente qual é a coluna de loja no banco (store_id | location_id | c2p_store_id) */
    public static function store_column_name() : string {
        static $col = null;
        if ($col !== null) return $col;

        global $wpdb;
        $table = self::table_name();

        $exists = $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $table) );
        if ($exists !== $table) { $col = 'store_id'; return $col; }

        $cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
        foreach (['location_id','store_id','c2p_store_id'] as $candidate) {
            if (in_array($candidate, $cols, true)) { $col = $candidate; return $col; }
        }
        $col = 'store_id';
        return $col;
    }

    /** Retorna true se a tabela existe */
    public static function table_exists( $table ) : bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return ( $found === $table );
    }

    /** Colunas atuais da tabela (array de strings) */
    private static function get_columns( $table ) : array {
        global $wpdb;
        $cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
        return array_map( 'strval', $cols );
    }

    /**
     * PK atual (nomes das colunas na ordem) — usa INFORMATION_SCHEMA (robusto e compatível)
     */
    private static function current_primary_columns( string $table ) : array {
        global $wpdb;
        // Primeiro tenta via INFORMATION_SCHEMA (preferível)
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND CONSTRAINT_NAME = 'PRIMARY'
              ORDER BY ORDINAL_POSITION ASC",
            $table
        ) );
        if ( is_array($rows) && !empty($rows) ) {
            return array_map('strval', $rows);
        }

        // Fallback: SHOW INDEX e filtragem em PHP (sem WHERE/ORDER BY para compatibilidade)
        $idx = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
        if (!is_array($idx) || empty($idx)) return [];
        $primaries = array_values(array_filter($idx, static function($r){
            return isset($r['Key_name']) && $r['Key_name'] === 'PRIMARY';
        }));
        if (empty($primaries)) return [];
        usort($primaries, static function($a,$b){
            $sa = isset($a['Seq_in_index']) ? (int)$a['Seq_in_index'] : 0;
            $sb = isset($b['Seq_in_index']) ? (int)$b['Seq_in_index'] : 0;
            return $sa <=> $sb;
        });
        $cols = [];
        foreach ($primaries as $r) {
            if (isset($r['Column_name'])) $cols[] = (string) $r['Column_name'];
        }
        return $cols;
    }

    /** Executa um trecho com supressão temporária de erros do wpdb e hide_errors */
    private static function with_suppressed_errors( callable $fn ) : void {
        global $wpdb;
        // guarda estado anterior
        $prev_suppress = $wpdb->suppress_errors();
        // liga supressão + esconde exibição
        $wpdb->suppress_errors( true );
        if ( method_exists( $wpdb, 'hide_errors' ) ) {
            $wpdb->hide_errors();
        }

        try { $fn(); }
        finally {
            // restaura estado
            $wpdb->suppress_errors( (bool) $prev_suppress );
            if ( method_exists( $wpdb, 'show_errors' ) ) {
                $wpdb->show_errors(); // volta a configuração padrão
            }
        }
    }

    /** Logger (silencioso se WC não estiver disponível) */
    private static function log( string $msg ) : void {
        if ( function_exists('wc_get_logger') ) {
            wc_get_logger()->info( $msg, ['source'=>'c2p-db'] );
        }
    }

    /* =========================================================
     * MIGRAÇÕES
     * =======================================================*/

    /** Renomeia tabela antiga para a atual, se necessário */
    public static function maybe_migrate_table_name() : void {
        global $wpdb;
        $new = self::table_name();

        $candidates_old = [
            $wpdb->prefix . 'c2p_multistock',
            $wpdb->prefix . 'c2p_multiestoque',
            $wpdb->prefix . 'woocommerce_multi_stock',
            $wpdb->prefix . 'c2p_stock_locations',
        ];

        if ( self::table_exists( $new ) ) { return; }

        foreach ( $candidates_old as $old ) {
            if ( self::table_exists( $old ) ) {
                $sql = "RENAME TABLE `{$old}` TO `{$new}`";
                $wpdb->query( $sql );
                if ( self::table_exists( $new ) ) {
                    self::log("Tabela renomeada: {$old} -> {$new}");
                    return;
                }
            }
        }
    }

    /** Cria/atualiza a tabela base (idempotente via dbDelta) */
    public static function create_or_update_table() : void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE `{$table}` (
            `product_id`       BIGINT(20) UNSIGNED NOT NULL,
            `store_id`         BIGINT(20) UNSIGNED NOT NULL,
            `qty`   INT NOT NULL DEFAULT 0,
            `low_stock_amount` INT UNSIGNED NULL DEFAULT NULL,
            `updated_at`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (`product_id`, `store_id`),
            KEY `idx_store`   (`store_id`),
            KEY `idx_product` (`product_id`)
        ) {$charset};";

        dbDelta( $sql );
        self::log("dbDelta executado para {$table}");
    }

    /** Renomeia/garante a coluna store_id e normaliza tipos mínimos/índices/PK */
    public static function ensure_store_column() : void {
        global $wpdb;
        $table = self::table_name();

        if ( ! self::table_exists( $table ) ) {
            self::log("Tabela {$table} não existe ao ensure_store_column().");
            return;
        }

        $cols      = self::get_columns( $table );
        $hasStore  = in_array( 'store_id', $cols, true );
        $hasLoc    = in_array( 'location_id', $cols, true );
        $hasC2p    = in_array( 'c2p_store_id', $cols, true );

        // Renomeia coluna de loja para store_id, caso necessário
        if ( ! $hasStore && $hasLoc ) {
            $wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `location_id` `store_id` BIGINT(20) UNSIGNED NOT NULL" );
            $cols     = self::get_columns( $table );
            $hasStore = in_array( 'store_id', $cols, true );
        }
        if ( ! $hasStore && $hasC2p ) {
            $wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `c2p_store_id` `store_id` BIGINT(20) UNSIGNED NOT NULL" );
            $cols     = self::get_columns( $table );
            $hasStore = in_array( 'store_id', $cols, true );
        }
        if ( ! $hasStore ) {
            $after = in_array('product_id', $cols, true) ? " AFTER `product_id`" : "";
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `store_id` BIGINT(20) UNSIGNED NOT NULL{$after}" );
            $cols     = self::get_columns( $table );
            $hasStore = in_array( 'store_id', $cols, true );
        }

        // Tipos/NULLability exigidos pela PK
        if ( in_array('product_id', $cols, true) ) {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY `product_id` BIGINT(20) UNSIGNED NOT NULL" );
        }
        if ( in_array('store_id', $cols, true) ) {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY `store_id`   BIGINT(20) UNSIGNED NOT NULL" );
        }

        // qty
        if ( in_array('qty', $cols, true) ) {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY `qty` INT NOT NULL DEFAULT 0" );
        } else {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `qty` INT NOT NULL DEFAULT 0" );
        }

        // low_stock_amount (mantida para UI de alerta)
        if ( ! in_array('low_stock_amount', $cols, true) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `low_stock_amount` INT UNSIGNED NULL DEFAULT NULL AFTER `qty`" );
        }

        // Índices secundários
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
        $index_names = array_map( function($r){ return isset($r['Key_name']) ? (string) $r['Key_name'] : ''; }, $indexes ?: [] );

        if ( ! in_array('idx_store', $index_names, true) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `idx_store` (`store_id`)" );
        }
        if ( ! in_array('idx_product', $index_names, true) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `idx_product` (`product_id`)" );
        }

        // PRIMARY KEY composta — lógica idempotente usando INFORMATION_SCHEMA
        $expected = ['product_id','store_id'];
        $current  = self::current_primary_columns($table);

        if (empty($current)) {
            // Não há PK → adiciona
            self::with_suppressed_errors(function() use ($table, $wpdb) {
                $wpdb->query("ALTER TABLE `{$table}` ADD PRIMARY KEY (`product_id`,`store_id`)");
            });
            self::log("PK composta criada em {$table}.");
        } elseif ($current !== $expected) {
            // Há PK, mas diferente da esperada → troca (drop + add)
            self::with_suppressed_errors(function() use ($table, $wpdb) {
                $wpdb->query("ALTER TABLE `{$table}` DROP PRIMARY KEY");
                $wpdb->query("ALTER TABLE `{$table}` ADD PRIMARY KEY (`product_id`,`store_id`)");
            });
            self::log("PK ajustada para (product_id, store_id) em {$table}.");
        } else {
            // Já correto
        }
    }

    /** Charset/collate coerente (log apenas) */
    public static function ensure_charset_collate() : void {
        global $wpdb;
        $table = self::table_name();
        if ( ! self::table_exists( $table ) ) { return; }
        $charset = $wpdb->get_charset_collate();
        self::log("Schema verificado; charset/collate esperado: {$charset}");
    }

    /** Executa tudo (ativação) */
    public static function install_schema() : void {
        self::maybe_migrate_table_name();
        self::create_or_update_table();
        self::ensure_store_column();
        self::ensure_charset_collate();
        update_option( self::SCHEMA_OPTION, (int) self::SCHEMA_VERSION, false );
        self::log('Schema/migração concluídos.');
    }

    /** Verifica versão de schema ao carregar o plugin e migra se necessário */
    public static function maybe_upgrade_on_load() : void {
        $current = (int) get_option( self::SCHEMA_OPTION, 0 );
        if ( $current >= (int) self::SCHEMA_VERSION ) {
            return;
        }
        self::install_schema();
    }

    /** Compat: método legado */
    public static function install() {
        self::install_schema();
    }
}

endif;

add_action( 'plugins_loaded', function(){
    if ( class_exists( '\\C2P\\Inventory_DB' ) ) {
        \C2P\Inventory_DB::maybe_upgrade_on_load();
    }
}, 2 );
