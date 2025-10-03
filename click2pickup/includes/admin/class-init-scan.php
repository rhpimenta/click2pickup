<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Init_Scan
 *
 * Garante que a tabela canônica de estoque por local possua uma linha
 * (product_id, store_id, qty) para TODO produto (incl. variações) em
 * TODO Local publicado. Linhas ausentes são preenchidas com qty=0.
 *
 * - Idempotente
 * - Em lotes para não estourar timeouts
 * - Não altera manage_stock nem força estoque em produtos externos
 */
final class Init_Scan {

    /** @var self */
    private static $instance = null;

    /** Tamanho do lote de produtos por iteração */
    private const BATCH_SIZE = 800;

    /** TTL do lock para evitar concorrência (segundos) */
    private const LOCK_TTL = 300;

    /** Singleton */
    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Nada obrigatório aqui; a orquestração é feita pelo plugin principal.
    }

    /**
     * Aciona de forma assíncrona (Action Scheduler). Pode ser chamada pelo hook 'c2p_init_full_scan'.
     */
    public static function run_async( int $batch_size = null ): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'c2p_init_full_scan_worker', [
                'batch_size' => $batch_size ?: self::BATCH_SIZE,
            ], 'c2p' );
        } else {
            // Sem AS: roda agora, em modo best-effort
            self::run_full_scan( $batch_size ?: self::BATCH_SIZE );
        }
    }

    /**
     * Worker do Action Scheduler — registre este action uma única vez (ex.: no bootstrap do plugin).
     * Se seu plugin principal já registrou, não há problema de chamar add_action duas vezes com a mesma callback.
     */
    public static function bootstrap_worker_hook(): void {
        add_action( 'c2p_init_full_scan_worker', function( $args ) {
            $batch = isset( $args['batch_size'] ) ? (int) $args['batch_size'] : self::BATCH_SIZE;
            self::run_full_scan( $batch );
        } );
    }

    /**
     * Executa o scan completo (preenche zeros para pares faltantes).
     * Seguro para rodar múltiplas vezes.
     */
    public static function run_full_scan( int $batch_size = null ): void {
        $batch_size = max( 50, (int) $batch_size ?: self::BATCH_SIZE );

        // Lock simples para evitar concorrência
        if ( ! self::acquire_lock() ) {
            return;
        }

        try {
            global $wpdb;

            if ( ! class_exists( '\C2P\Inventory_DB' ) ) {
                error_log('[C2P][Init_Scan] Inventory_DB não encontrado. Abortando.');
                return;
            }

            $table     = \C2P\Inventory_DB::table_name();
            $col_store = \C2P\Inventory_DB::store_column_name();

            // 1) Lista de Locais publicados
            $stores = self::get_published_store_ids();
            if ( empty( $stores ) ) {
                // Nada a fazer, mas limpe flag/option se existir
                delete_option( 'c2p_init_scan_pending' );
                return;
            }

            // 2) Inserir linhas zero faltantes por Local publicado
            foreach ( $stores as $store_id ) {
                self::fill_missing_zero_rows_for_store( $table, $col_store, (int) $store_id, $batch_size );
            }

            // 3) (Opcional) Recalcular snapshots por produto? NÃO necessário para exibição na API,
            // pois zeros não alteram totais. Mantemos leve aqui.

            // 4) limpeza de flags
            delete_option( 'c2p_init_scan_pending' );

        } catch ( \Throwable $e ) {
            error_log('[C2P][Init_Scan][run_full_scan] '.$e->getMessage());
        } finally {
            self::release_lock();
        }
    }

    /* ============================================================
     * Núcleo de preenchimento (por Local)
     * ========================================================== */

    /**
     * Insere, em lotes, as linhas faltantes (qty=0) para um Local específico.
     * Loteia a seleção de produtos para evitar estouro de memória.
     */
    private static function fill_missing_zero_rows_for_store( string $table, string $col_store, int $store_id, int $batch_size ): void {
        global $wpdb;

        // Loop até não existirem mais produtos faltantes para este Local
        while ( true ) {
            // Seleciona até N produtos (product, product_variation) que NÃO têm linha para este Local
            $sql_missing = "
                SELECT p.ID
                  FROM {$wpdb->posts} p
             LEFT JOIN {$table} t
                    ON t.product_id = p.ID
                   AND t.{$col_store} = %d
                 WHERE p.post_type IN ('product','product_variation')
                   AND p.post_status IN ('publish','private')
                   AND t.product_id IS NULL
                 LIMIT %d
            ";
            $missing_ids = $wpdb->get_col( $wpdb->prepare( $sql_missing, $store_id, $batch_size ) );

            if ( empty( $missing_ids ) ) {
                // Nada mais a fazer para este Local
                break;
            }

            // Monta INSERT em massa com ON DUPLICATE (idempotente)
            $values   = [];
            $holders  = [];
            foreach ( $missing_ids as $pid ) {
                $holders[] = '(%d,%d,0,NOW())';
                $values[]  = (int) $pid;
                $values[]  = $store_id;
            }

            $sql_insert = "
                INSERT INTO {$table} (product_id, {$col_store}, qty, updated_at)
                VALUES " . implode(',', $holders ) . "
                ON DUPLICATE KEY UPDATE qty = qty
            ";

            $wpdb->query( $wpdb->prepare( $sql_insert, $values ) );

            // Pequena pausa cooperativa se necessário (ambiente host compartilhado)
            if ( defined('DOING_CRON') && DOING_CRON ) {
                // nada
            }
        }
    }

    /* ============================================================
     * Utilitários
     * ========================================================== */

    /** Lock simples via transient */
    private static function acquire_lock(): bool {
        $key = 'c2p_init_scan_lock';
        if ( get_transient( $key ) ) return false;
        set_transient( $key, 1, self::LOCK_TTL );
        return true;
    }
    private static function release_lock(): void {
        delete_transient( 'c2p_init_scan_lock' );
    }

    /** IDs de Locais publicados */
    private static function get_published_store_ids(): array {
        $ids = get_posts([
            'post_type'      => 'c2p_store',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        return array_map('intval', (array) $ids);
    }

    /**
     * (Opcional) Utilitário para saber se um product_id é externo.
     * Não usamos neste scanner, pois não atualizamos estoque do WC aqui.
     */
    private static function is_external_product( int $product_id ): bool {
        if ( ! function_exists('wc_get_product') ) return false;
        $p = wc_get_product( $product_id );
        return $p && $p->is_type('external');
    }
}

// Bootstrap do worker do AS (se ainda não existir)
\C2P\Init_Scan::bootstrap_worker_hook();
\C2P\Init_Scan::instance();
