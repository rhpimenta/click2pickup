<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * C2P\REST_API
 *
 * - Rotas nativas (c2p/v1/*) e alias no namespace do Woo (wc/v3/c2p/*)
 * - Enriquecimento das respostas de produtos/variações do Woo com metadados
 * - Evita metadados c2p_* duplicados no REST (substitui e PREPENDE nossas chaves)
 */
final class REST_API {

    /** @var self */
    private static $instance = null;

    /** Singleton */
    public static function instance() : self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        // Rotas
        add_action( 'rest_api_init', function () {
            $this->register_routes_core();
            $this->register_routes_alias();
        } );
        add_action( 'woocommerce_rest_api_init', function() {
            $this->register_routes_alias();
        } );

        // Enriquecimento do REST — prioridade alta para sermos os últimos
        add_filter( 'woocommerce_rest_prepare_product_object',
            [ $this, 'on_prepare_product' ], 99, 3 );
        add_filter( 'woocommerce_rest_prepare_product_variation_object',
            [ $this, 'on_prepare_variation' ], 99, 3 );

        // Labels de locais — ainda mais tarde, para manter nossas chaves à frente
        add_filter( 'woocommerce_rest_prepare_product_object',
            [ $this, 'inject_location_labels' ], 100, 3 );
        add_filter( 'woocommerce_rest_prepare_product_variation_object',
            [ $this, 'inject_location_labels' ], 100, 3 );
    }

    /* ======================================================================
     * Rotas
     * ==================================================================== */

    private function register_routes_core() : void {
        register_rest_route( 'c2p/v1', '/mirror', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'route_mirror' ],
            'permission_callback' => [ $this, 'permission_admin' ],
        ] );

        register_rest_route( 'c2p/v1', '/stock', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'route_stock_delta' ],
            'permission_callback' => [ $this, 'permission_write' ],
            'args'                => $this->args_stock(),
        ] );

        register_rest_route( 'c2p/v1', '/reindex', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'route_reindex' ],
            'permission_callback' => [ $this, 'permission_admin' ],
        ] );

        register_rest_route( 'c2p/v1', '/resync_ledger', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'route_resync_ledger' ],
            'permission_callback' => [ $this, 'permission_admin' ],
        ] );

        register_rest_route( 'c2p/v1', '/products/init-state', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'route_product_init_state' ],
            'permission_callback' => [ $this, 'permission_read' ],
            'args'                => [
                'product_id' => [ 'required' => true, 'type' => 'integer' ],
            ],
        ] );
    }

    private function register_routes_alias() : void {
        register_rest_route( 'wc/v3', '/c2p/stock', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'route_stock_delta' ],
            'permission_callback' => [ $this, 'permission_write' ],
            'args'                => $this->args_stock(),
        ] );
    }

    private function args_stock() : array {
        return [
            'product_id'  => [ 'required' => true, 'type' => 'integer' ],
            'location_id' => [ 'required' => true, 'type' => 'integer' ],
            'delta'       => [ 'required' => true, 'type' => 'integer' ],
            'source'      => [ 'required' => false, 'type' => 'string'  ],
            'who'         => [ 'required' => false, 'type' => 'string'  ],
        ];
    }

    /* ======================================================================
     * Permissões
     * ==================================================================== */
    public function permission_admin() : bool {
        return current_user_can( 'manage_woocommerce' );
    }
    public function permission_write() : bool {
        return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
    }
    public function permission_read() : bool {
        return is_user_logged_in() || current_user_can( 'read' );
    }

    /* ======================================================================
     * Handlers
     * ==================================================================== */

    public function route_mirror( \WP_REST_Request $request ) {
        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function route_stock_delta( \WP_REST_Request $req ) {
        global $wpdb;

        $product_id  = (int) $req->get_param( 'product_id' );
        $location_id = (int) $req->get_param( 'location_id' );
        $delta       = (int) $req->get_param( 'delta' );
        $source      = trim( (string) ( $req->get_param( 'source' ) ?? 'REST' ) );
        $who         = trim( (string) ( $req->get_param( 'who' ) ?? 'api' ) );

        if ( $product_id <= 0 || $location_id <= 0 ) {
            return new \WP_Error( 'c2p_bad_args', __( 'Parâmetros inválidos.', 'c2p' ), [ 'status' => 400 ] );
        }
        $post = get_post( $product_id );
        if ( ! $post ) {
            return new \WP_Error( 'c2p_product_not_found', __( 'Produto não encontrado.', 'c2p' ), [ 'status' => 404 ] );
        }

        // Local publicado
        $loc = get_post( $location_id );
        if ( ! $loc || $loc->post_type !== 'c2p_store' || $loc->post_status !== 'publish' ) {
            return new \WP_Error( 'c2p_location_not_found', __( 'Local de estoque inexistente ou inativo.', 'c2p' ), [ 'status' => 404 ] );
        }

        // Produto inicializado?
        $flag = get_post_meta( $product_id, 'c2p_initialized', true );
        if ( $flag !== 'yes' ) {
            return new \WP_Error(
                'c2p_uninitialized',
                __( 'Produto não inicializado para multi-estoque. Inicialize em Configurações → Ferramentas.', 'c2p' ),
                [ 'status' => 409, 'product_id' => $product_id ]
            );
        }

        // No-op
        if ( 0 === $delta ) {
            $state    = $this->compute_state( $product_id );
            $loc_after= $state['by_id'][ $location_id ] ?? 0;
            return new \WP_REST_Response( [
                'ok'                 => true,
                'product_id'         => $product_id,
                'location_id'        => $location_id,
                'applied_delta'      => 0,
                'location_qty_after' => (int) $loc_after,
                'total_stock_after'  => (int) $state['total'],
                'snapshots'          => [
                    'by_id'   => $state['by_id'],
                    'by_name' => $state['by_name'],
                ],
                'notice' => 'Delta = 0 (nenhuma alteração aplicada).',
            ], 200 );
        }

        // 1) Ledger (preferencial)
        $applied = false;
        if ( class_exists( '\C2P\Stock_Ledger' ) && method_exists( '\C2P\Stock_Ledger', 'apply_delta' ) ) {
            $applied = \C2P\Stock_Ledger::apply_delta( $product_id, $location_id, $delta, [
                'source' => $source,
                'who'    => $who,
            ] );
        }

        // 2) Fallback: UPSERT direto
        if ( ! $applied ) {
            $table = ( class_exists( '\C2P\Inventory_DB' ) && method_exists( '\C2P\Inventory_DB', 'table_name' ) )
                ? \C2P\Inventory_DB::table_name()
                : $wpdb->prefix . 'c2p_multi_stock';
            $col_store = ( class_exists( '\C2P\Inventory_DB' ) && method_exists( '\C2P\Inventory_DB', 'store_column_name' ) )
                ? \C2P\Inventory_DB::store_column_name()
                : 'store_id';

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (product_id, {$col_store}, qty, updated_at)
                 VALUES (%d, %d, GREATEST(0, %d), NOW())
                 ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty + %d), updated_at = NOW()",
                $product_id, $location_id, $delta, $delta
            ) );
        }

        // Espelha estado
        $state     = $this->compute_state( $product_id );
        $loc_after = $state['by_id'][ $location_id ] ?? 0;

        update_post_meta( $product_id, 'c2p_stock_by_location_ids', $state['by_id'] );
        update_post_meta( $product_id, 'c2p_stock_by_location',     $state['by_name'] );
        update_post_meta( $product_id, '_stock',                    (int) $state['total'] );

        $product = function_exists('wc_get_product') ? wc_get_product( $product_id ) : null;
        $allow_backorder = $product ? (bool) $product->backorders_allowed() : false;
        if ( ! $allow_backorder ) {
            update_post_meta( $product_id, '_stock_status', ( $state['total'] > 0 ? 'instock' : 'outofstock' ) );
        }

        if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $product_id );
        if ( function_exists( 'wc_update_product_lookup_tables' ) ) wc_update_product_lookup_tables( $product_id );
        if ( function_exists( 'clean_post_cache' ) ) clean_post_cache( $product_id );

        return new \WP_REST_Response( [
            'ok'                 => true,
            'product_id'         => $product_id,
            'location_id'        => $location_id,
            'applied_delta'      => (int) $delta,
            'location_qty_after' => (int) $loc_after,
            'total_stock_after'  => (int) $state['total'],
            'snapshots'          => [
                'by_id'   => $state['by_id'],
                'by_name' => $state['by_name'],
            ],
        ], 200 );
    }

    public function route_reindex() {
        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function route_resync_ledger() {
        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function route_product_init_state( \WP_REST_Request $request ) {
        $pid = (int) $request->get_param( 'product_id' );
        if ( $pid <= 0 ) {
            return new \WP_Error( 'c2p_bad_args', 'product_id inválido.', [ 'status' => 400 ] );
        }
        $flag = get_post_meta( $pid, 'c2p_initialized', true );
        return new \WP_REST_Response( [
            'product_id' => $pid,
            'state'      => ( $flag === 'yes' ? 'initialized' : 'uninitialized' ),
        ], 200 );
    }

    /* ======================================================================
     * Enriquecimento Woo REST
     * ==================================================================== */

    public function on_prepare_product( $response, $object, $request ) {
        if ( ! ( $response instanceof \WP_REST_Response ) ) return $response;
        $product_id = (int) $object->get_id();
        return $this->add_map_to_response( $response, $product_id );
    }

    public function on_prepare_variation( $response, $object, $request ) {
        if ( ! ( $response instanceof \WP_REST_Response ) ) return $response;
        $product_id = (int) $object->get_id();
        return $this->add_map_to_response( $response, $product_id );
    }

    private function add_map_to_response( \WP_REST_Response $response, int $product_id ) : \WP_REST_Response {
        list( $by_id, $by_nm ) = $this->read_map( $product_id );
        $state = ( get_post_meta( $product_id, 'c2p_initialized', true ) === 'yes' ) ? 'initialized' : 'uninitialized';

        // Substitui e PREPENDE as chaves que nos interessam
        return $this->strip_and_prepend_meta( $response, [
            'c2p_stock_by_location_ids' => $by_id,
            'c2p_stock_by_location'     => $by_nm,
            'c2p_init_state'            => $state,
        ] );
    }

    public function inject_location_labels( $response, $object, $request ) {
        if ( ! ( $response instanceof \WP_REST_Response ) ) return $response;

        $product_id = 0;
        if ( is_object( $object ) && method_exists( $object, 'get_id' ) ) {
            $product_id = (int) $object->get_id();
        } elseif ( is_array( $response->get_data() ) ) {
            $data = $response->get_data();
            if ( isset( $data['id'] ) ) $product_id = (int) $data['id'];
        }
        if ( $product_id <= 0 ) return $response;

        list( $by_id, $_ ) = $this->read_map( $product_id );

        $labels = [];
        foreach ( (array) $by_id as $sid => $qty ) {
            $sid = (int) $sid;
            if ( $sid <= 0 ) continue;
            $nm = get_the_title( $sid );
            if ( ! $nm ) $nm = 'Local #'.$sid;
            $labels[ (string) $sid ] = $nm;
        }

        if ( ! empty( $labels ) ) {
            // Só substitui a chave labels; mantém as outras (já foram inseridas antes)
            $response = $this->strip_and_prepend_meta( $response, [
                'c2p_location_labels' => $labels,
            ] );
        }

        return $response;
    }

    /* ======================================================================
     * Leitura canônica (DB) com fallback
     * ==================================================================== */

    private function read_map( int $product_id ) : array {
        $by_id = [];
        $by_nm = [];

        // 1) Tabela canônica
        if ( class_exists( '\C2P\Inventory_DB' ) ) {
            global $wpdb;
            $table = \C2P\Inventory_DB::table_name();
            $col   = \C2P\Inventory_DB::store_column_name();

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT {$col} AS loc, qty
                   FROM {$table}
                  WHERE product_id = %d
               ORDER BY {$col} ASC",
                $product_id
            ), ARRAY_A );

            if ( $rows ) {
                foreach ( $rows as $r ) {
                    $lid = (int) $r['loc'];
                    $qty = (int) $r['qty'];
                    $by_id[ $lid ] = $qty;

                    $nm = get_the_title( $lid );
                    if ( ! $nm ) $nm = 'Local #'.$lid;
                    $by_nm[ $nm ] = ( $by_nm[ $nm ] ?? 0 ) + $qty;
                }
            }
        }

        // 2) Fallback snapshots do postmeta (caso DB ainda vazio)
        if ( empty( $by_id ) && empty( $by_nm ) ) {
            $meta_by_id = get_post_meta( $product_id, 'c2p_stock_by_location_ids', true );
            $meta_by_nm = get_post_meta( $product_id, 'c2p_stock_by_location',     true );
            if ( is_array( $meta_by_id ) ) {
                foreach ( $meta_by_id as $lid => $qty ) {
                    $by_id[ (int) $lid ] = (int) $qty;
                }
            }
            if ( is_array( $meta_by_nm ) ) {
                foreach ( $meta_by_nm as $nm => $qty ) {
                    $by_nm[ (string) $nm ] = (int) $qty;
                }
            }
        }

        ksort( $by_id, SORT_NUMERIC );
        ksort( $by_nm, SORT_NATURAL | SORT_FLAG_CASE );

        return [ $by_id, $by_nm ];
    }

    private function compute_state( int $product_id ) : array {
        global $wpdb;

        $table = ( class_exists( '\C2P\Inventory_DB' ) && method_exists( '\C2P\Inventory_DB', 'table_name' ) )
            ? \C2P\Inventory_DB::table_name()
            : $wpdb->prefix . 'c2p_multi_stock';
        $col_store = ( class_exists( '\C2P\Inventory_DB' ) && method_exists( '\C2P\Inventory_DB', 'store_column_name' ) )
            ? \C2P\Inventory_DB::store_column_name()
            : 'store_id';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$col_store} AS store_id, qty
               FROM {$table}
              WHERE product_id = %d
              ORDER BY {$col_store} ASC",
            $product_id
        ), ARRAY_A );

        $by_id   = [];
        $by_name = [];
        $total   = 0;

        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $sid = (int) ( $r['store_id'] ?? 0 );
                $qty = max( 0, (int) ( $r['qty'] ?? 0 ) );
                $by_id[ $sid ] = $qty;
                $total += $qty;

                $nm = get_the_title( $sid );
                if ( ! $nm ) { $nm = 'Local #' . $sid; }
                $by_name[ $nm ] = $qty;
            }
        }

        return [
            'by_id'   => $by_id,
            'by_name' => $by_name,
            'total'   => (int) $total,
        ];
    }

    /* ======================================================================
     * Helper: limpar e PREPENDER metadados
     * ==================================================================== */

    /**
     * Remove TODAS as ocorrências das chaves informadas e insere as novas
     * logo no início de meta_data (para o cliente ler sempre as corretas).
     *
     * @param \WP_REST_Response $response
     * @param array $pairs [ key => value, ... ]
     * @return \WP_REST_Response
     */
    private function strip_and_prepend_meta( \WP_REST_Response $response, array $pairs ) : \WP_REST_Response {
        $data = $response->get_data();
        $meta = isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ? $data['meta_data'] : [];

        $keys_to_replace = array_keys( $pairs );
        $clean = [];

        foreach ( $meta as $item ) {
            // Item pode vir como array OU stdClass
            $k = null;
            if ( is_array( $item ) && isset( $item['key'] ) ) {
                $k = $item['key'];
            } elseif ( is_object( $item ) && isset( $item->key ) ) {
                $k = $item->key;
            }

            if ( $k !== null && in_array( $k, $keys_to_replace, true ) ) {
                // descarta duplicata
                continue;
            }

            $clean[] = $item;
        }

        // Prepara nossas entradas (id=0) e PREPENDE
        $prepend = [];
        foreach ( $pairs as $k => $v ) {
            $prepend[] = [ 'id' => 0, 'key' => $k, 'value' => $v ];
        }

        $data['meta_data'] = array_values( array_merge( $prepend, $clean ) );
        $response->set_data( $data );
        return $response;
    }

} // class

// Bootstrap
add_action( 'plugins_loaded', function() {
    if ( class_exists( '\C2P\REST_API' ) ) {
        \C2P\REST_API::instance();
    }
}, 12 );
