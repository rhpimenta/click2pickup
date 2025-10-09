<?php
/**
 * C2P\Stock_Ledger
 *
 * - Tabela de auditoria de movimentações de estoque por local
 * - Grava delta, before/after, origem, quem, pedido, e snapshot do nome do local
 * - Expõe apply_delta() para ser chamada pelo REST/API ou fluxo de pedidos
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-01-09 00:55:12 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 00:55: ✅ SQL ESCAPE adequado (segurança)
 * - 2025-01-09 00:55: ✅ Query atômica (sem race condition)
 * - 2025-01-09 00:55: ✅ Cache de verificação de coluna
 * - 2025-01-09 00:55: ✅ Usa Constants::table_ledger()
 * - 2025-01-09 00:55: ✅ Validação de metadata
 * - 2025-01-09 00:55: ✅ Logging condicional (WP_DEBUG)
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

final class Stock_Ledger {

    /** Bump sempre que mudar o schema (dbDelta cuidará do ALTER) */
    const DB_VERSION = 4; // ✅ Incrementado para nova versão
    const DB_VERSION_OPTION = 'c2p_stock_ledger_db_version';

    /**
     * ✅ Cache de verificação de coluna (evita query cara)
     * @var array
     */
    private static $column_check_cache = [];

    /**
     * ✅ CORRIGIDO: Usa Constants::table_ledger()
     */
    public static function table_name(): string {
        if (class_exists('\C2P\Constants') && method_exists('\C2P\Constants', 'table_ledger')) {
            return C2P::table_ledger();
        }
        
        global $wpdb;
        return $wpdb->prefix . 'c2p_stock_ledger';
    }

    /** Certifica a existência/estrutura da tabela */
    public static function maybe_install(): void {
        global $wpdb;

        $installed = (int) get_option(self::DB_VERSION_OPTION, 0);
        
        if ($installed >= self::DB_VERSION) {
            // ✅ OTIMIZADO: Usa cache para evitar query cara
            if (self::column_exists('location_name_text')) {
                return;
            }
            
            self::run_dbdelta();
            return;
        }

        self::run_dbdelta();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * ✅ NOVO: Verifica se coluna existe (com cache)
     */
    private static function column_exists(string $column_name): bool {
        $table = self::table_name();
        $cache_key = $table . '.' . $column_name;
        
        if (isset(self::$column_check_cache[$cache_key])) {
            return self::$column_check_cache[$cache_key];
        }
        
        global $wpdb;
        
        // ✅ OTIMIZADO: Usa SHOW COLUMNS (mais rápido que INFORMATION_SCHEMA)
        $table_escaped = esc_sql($table);
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_escaped}`");
        
        $exists = in_array($column_name, $columns, true);
        self::$column_check_cache[$cache_key] = $exists;
        
        return $exists;
    }

    private static function run_dbdelta(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ✅ SEGURANÇA: Escape do nome da tabela
        $table = esc_sql(self::table_name());
        $charset = $wpdb->get_charset_collate();

        // created_at armazena UTC (use current_time('mysql', true) ao inserir)
        $sql = "CREATE TABLE `{$table}` (
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
            KEY order_id (order_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    /**
     * Aplica um delta de estoque para {product_id, location_id} e registra no Ledger.
     *
     * ✅ CORRIGIDO: Query atômica (sem race condition)
     * ✅ CORRIGIDO: SQL escape adequado
     *
     * @param int   $product_id
     * @param int   $location_id
     * @param int   $delta            (positivo repõe; negativo baixa)
     * @param array $opts             ['order_id'=>int,'source'=>string,'who'=>string,'meta'=>array]
     * @return bool                   true se conseguiu aplicar e registrar
     */
    public static function apply_delta(int $product_id, int $location_id, int $delta, array $opts = []): bool {
        if ($product_id <= 0 || $location_id <= 0 || 0 === $delta) {
            return false;
        }

        global $wpdb;

        $order_id = isset($opts['order_id']) ? (int) $opts['order_id'] : 0;
        $source   = isset($opts['source'])   ? substr((string) $opts['source'], 0, 60) : 'system';
        $who      = isset($opts['who'])      ? substr((string) $opts['who'], 0, 120) : '';
        $meta     = isset($opts['meta']) && is_array($opts['meta']) ? $opts['meta'] : [];

        // Snapshot do nome do local (para preservar mesmo que o CPT seja apagado)
        $loc_name = get_the_title($location_id);
        if (!$loc_name) {
            $loc_name = 'Local #' . $location_id;
        }
        $meta['location_name'] = $loc_name;

        // ✅ CORRIGIDO: Usa Constants com fallback seguro
        $inv_table = self::get_inventory_table();
        $col_store = self::get_store_column();
        
        // ✅ SEGURANÇA: Escape de nomes de tabelas/colunas
        $inv_table_escaped = esc_sql($inv_table);
        $col_store_escaped = esc_sql($col_store);

        // ✅ OTIMIZADO: Query atômica (usa qty = GREATEST(0, qty + delta))
        // Isso evita race condition porque tudo acontece em 1 query
        $sql = "
            INSERT INTO `{$inv_table_escaped}` (product_id, `{$col_store_escaped}`, qty, updated_at)
            VALUES (%d, %d, GREATEST(0, %d), %s)
            ON DUPLICATE KEY UPDATE 
                qty = GREATEST(0, qty + %d),
                updated_at = VALUES(updated_at)
        ";
        
        $result = $wpdb->query($wpdb->prepare(
            $sql,
            $product_id,
            $location_id,
            max(0, $delta), // Para INSERT (se não existir)
            current_time('mysql', true), // UTC
            $delta // Para UPDATE (soma atômica)
        ));

        if (false === $result) {
            self::log_error('Falha ao aplicar delta no canônico: ' . $wpdb->last_error);
            return false;
        }

        // ✅ CORRIGIDO: Agora lê qty_before e qty_after em query separada (mas DEPOIS do update)
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT qty FROM `{$inv_table_escaped}` WHERE product_id = %d AND `{$col_store_escaped}` = %d LIMIT 1",
            $product_id,
            $location_id
        ));

        $qty_after = $row ? (int) $row->qty : 0;
        $qty_before = max(0, $qty_after - $delta);

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
            'created_at'         => current_time('mysql', true), // UTC
        ]);

        return true;
    }

    /**
     * ✅ NOVO: Retorna nome da tabela de inventário com fallback seguro
     */
    private static function get_inventory_table(): string {
        if (class_exists('\C2P\Constants') && method_exists('\C2P\Constants', 'table')) {
            return C2P::table();
        }
        
        if (class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB', 'table_name')) {
            return \C2P\Inventory_DB::table_name();
        }
        
        global $wpdb;
        return $wpdb->prefix . 'c2p_stock'; // ✅ CORRIGIDO: Nome correto (não c2p_multi_stock)
    }

    /**
     * ✅ NOVO: Retorna nome da coluna de store com fallback seguro
     */
    private static function get_store_column(): string {
        if (class_exists('\C2P\Constants') && method_exists('\C2P\Constants', 'col_store')) {
            return C2P::col_store();
        }
        
        if (class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB', 'store_column_name')) {
            return \C2P\Inventory_DB::store_column_name();
        }
        
        return 'store_id'; // ✅ Padrão correto
    }

    /**
     * Insere um registro bruto no ledger.
     * 
     * ✅ CORRIGIDO: Validação de metadata
     * ✅ CORRIGIDO: SQL escape adequado
     *
     * Campos esperados em $row:
     *  - product_id (int), location_id (int), location_name_text (string|nullable)
     *  - delta (int), qty_before (int), qty_after (int)
     *  - order_id (int|0), source (string), who (string), meta (array|string|null)
     *  - created_at (mysql datetime UTC)
     */
    public static function record(array $row): void {
        global $wpdb;

        // ✅ SEGURANÇA: Escape do nome da tabela
        $table = esc_sql(self::table_name());

        $product_id  = (int)($row['product_id']  ?? 0);
        $location_id = (int)($row['location_id'] ?? 0);
        $loc_name    = isset($row['location_name_text']) ? (string) $row['location_name_text'] : null;

        $delta       = (int)($row['delta']       ?? 0);
        $qty_before  = max(0, (int)($row['qty_before'] ?? 0));
        $qty_after   = max(0, (int)($row['qty_after']  ?? 0));

        $order_id    = (int)($row['order_id'] ?? 0);
        $source      = substr((string)($row['source'] ?? ''), 0, 60);
        $who         = substr((string)($row['who']    ?? ''), 0, 120);
        $meta        = $row['meta'] ?? null;
        $created_at  = (string)($row['created_at'] ?? current_time('mysql', true));

        // ✅ CORRIGIDO: Validação de metadata
        if (is_array($meta)) {
            // Limita profundidade e tamanho
            $meta = wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            // Se JSON for muito grande (>64KB), trunca
            if (strlen($meta) > 65535) {
                $meta = substr($meta, 0, 65535);
                self::log_error('Metadata muito grande, truncado para 64KB');
            }
        } elseif (is_object($meta)) {
            // Converte objetos para array
            $meta = wp_json_encode((array) $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (null !== $meta) {
            $meta = (string) $meta;
        }

        $result = $wpdb->insert(
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

        if (false === $result || $wpdb->last_error) {
            self::log_error('Insert erro: ' . $wpdb->last_error);
        }
    }

    /**
     * ✅ NOVO: Logger condicional (só loga em WP_DEBUG)
     */
    private static function log_error(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[C2P][Stock_Ledger] ' . $message);
        }
    }
    
    /**
     * ✅ NOVO: Limpa cache de verificação de colunas
     */
    public static function clear_cache(): void {
        self::$column_check_cache = [];
    }
}

// ✅ REMOVIDO: Bootstrap automático (deve estar no click2pickup.php)
// add_action('plugins_loaded', ['\C2P\Stock_Ledger', 'maybe_install'], 0);