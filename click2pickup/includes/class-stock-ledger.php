<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * C2P\Stock_Ledger
 *
 * - Tabela de auditoria de movimentações de estoque por local
 * - Grava delta, before/after, origem, quem, pedido, e snapshot do nome do local
 * - Expõe apply_delta() para ser chamada pelo REST/API ou fluxo de pedidos
 */
final class Stock_Ledger {

    /** Bump sempre que mudar o schema (dbDelta cuidará do ALTER) */
    const DB_VERSION = 3;
    const DB_VERSION_OPTION = 'c2p_stock_ledger_db_version';

    /** Nome da tabela do Ledger */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'c2p_stock_ledger';
    }

    /** Certifica a existência/estrutura da tabela */
    public static function maybe_install(): void {
        global $wpdb;

        $installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
        if ( $installed >= self::DB_VERSION ) {
            // Mesmo se a versão for >=, garantimos que a coluna location_name_text exista (ambientes antigos)
            $table = self::table_name();
            $col_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'location_name_text'", $table
            ) );
            if ( (int) $col_exists === 0 ) {
                self::run_dbdelta();
            }
            return;
        }

        self::run_dbdelta();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
    }

    private static function run_dbdelta(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // created_at armazena UTC (use current_time('mysql', true) ao inserir)
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            location_name_text VARCHAR(255) NULL,
            delta INT NOT NULL,
            qty_before INT NOT NULL DEFAULT 0,
            qty_after INT NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NULL DEFAULT NULL,
            source VARCHAR(60) NULL,
            who VARCHAR(120) NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY location_id (location_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Aplica um delta de estoque para {product_id, location_id} e registra no Ledger.
     *
     * @param int   $product_id
     * @param int   $location_id
     * @param int   $delta            (positivo repõe; negativo baixa)
     * @param array $opts             ['order_id'=>int,'source'=>string,'who'=>string,'meta'=>array]
     * @return bool                   true se conseguiu aplicar e registrar
     */
    public static function apply_delta( int $product_id, int $location_id, int $delta, array $opts = [] ): bool {
        if ( $product_id <= 0 || $location_id <= 0 || 0 === $delta ) return false;

        global $wpdb;

        $order_id = isset($opts['order_id']) ? (int) $opts['order_id'] : 0;
        $source   = isset($opts['source'])   ? substr( (string) $opts['source'], 0, 60 ) : 'system';
        $who      = isset($opts['who'])      ? substr( (string) $opts['who'],    0, 120) : '';
        $meta     = isset($opts['meta']) && is_array($opts['meta']) ? $opts['meta'] : [];

        // Snapshot do nome do local (para preservar mesmo que o CPT seja apagado)
        $loc_name = get_the_title( $location_id );
        if ( ! $loc_name ) $loc_name = 'Local #'.$location_id;
        $meta['location_name'] = $loc_name;

        // === Lê o estoque atual do local ===
        $inv_table = class_exists('\C2P\Inventory_DB') ? \C2P\Inventory_DB::table_name() : $wpdb->prefix.'c2p_multi_stock';
        $col_store = class_exists('\C2P\Inventory_DB') ? \C2P\Inventory_DB::store_column_name() : 'store_id';

        $qty_before = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM {$inv_table} WHERE product_id = %d AND {$col_store} = %d",
            $product_id, $location_id
        ) );
        if ( $qty_before < 0 ) $qty_before = 0;

        $qty_after = max( 0, $qty_before + (int) $delta );

        // === Aplica no canônico (UPSERT) ===
        $sql = "
            INSERT INTO {$inv_table} (product_id, {$col_store}, qty, updated_at)
            VALUES (%d, %d, %d, %s)
            ON DUPLICATE KEY UPDATE qty = %d, updated_at = VALUES(updated_at)
        ";
        $ok_upsert = $wpdb->query( $wpdb->prepare(
            $sql,
            $product_id,
            $location_id,
            $qty_after,
            current_time( 'mysql', true ), // UTC
            $qty_after
        ) );

        if ( false === $ok_upsert ) {
            error_log('[C2P][Stock_Ledger] Falha ao aplicar delta no canônico: '.$wpdb->last_error);
            return false;
        }

        // === Registra no Ledger ===
        self::record([
            'product_id'         => $product_id,
            'location_id'        => $location_id,
            'location_name_text' => $loc_name,
            'delta'              => (int) $delta,
            'qty_before'         => $qty_before,
            'qty_after'          => $qty_after,
            'order_id'           => $order_id,
            'source'             => $source,
            'who'                => $who,
            'meta'               => $meta,
            'created_at'         => current_time( 'mysql', true ), // UTC
        ]);

        // Não espelhamos o total aqui: quem chamou (ex.: REST_API) costuma recalcular/sincronizar o _stock / snapshots

        return true;
    }

    /**
     * Insere um registro bruto no ledger.
     * Campos esperados em $row:
     *  - product_id (int), location_id (int), location_name_text (string|nullable)
     *  - delta (int), qty_before (int), qty_after (int)
     *  - order_id (int|0), source (string), who (string), meta (array|string|null)
     *  - created_at (mysql datetime UTC)
     */
    public static function record( array $row ): void {
        global $wpdb;

        $table = self::table_name();

        $product_id  = (int)($row['product_id']  ?? 0);
        $location_id = (int)($row['location_id'] ?? 0);
        $loc_name    = isset($row['location_name_text']) ? (string) $row['location_name_text'] : null;

        $delta       = (int)($row['delta']       ?? 0);
        $qty_before  = max(0, (int)($row['qty_before'] ?? 0));
        $qty_after   = max(0, (int)($row['qty_after']  ?? 0));

        $order_id    = (int)($row['order_id'] ?? 0);
        $source      = substr( (string)($row['source'] ?? ''), 0, 60 );
        $who         = substr( (string)($row['who']    ?? ''), 0, 120 );
        $meta        = $row['meta'] ?? null;
        $created_at  = (string)($row['created_at'] ?? current_time('mysql', true));

        if ( is_array($meta) ) {
            $meta = wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        } elseif ( null !== $meta ) {
            $meta = (string) $meta;
        }

        $wpdb->insert(
            $table,
            [
                'product_id'         => $product_id,
                'location_id'        => $location_id,
                'location_name_text' => $loc_name,
                'delta'              => $delta,
                'qty_before'         => $qty_before,
                'qty_after'          => $qty_after,
                'order_id'           => $order_id ?: null,
                'source'             => $source ?: null,
                'who'                => $who ?: null,
                'meta'               => $meta,
                'created_at'         => $created_at,
            ],
            [
                '%d','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s'
            ]
        );

        if ( $wpdb->last_error ) {
            error_log('[C2P][Stock_Ledger] Insert erro: '.$wpdb->last_error);
        }
    }
}

// Garantir a instalação do schema cedo
add_action( 'plugins_loaded', [ '\C2P\Stock_Ledger', 'maybe_install' ], 0 );
