<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gerenciador Administrativo de Pedidos para o Click2Pickup.
 *
 * Esta classe centraliza todas as funcionalidades relacionadas à interface e automação
 * de pedidos no painel do WooCommerce.
 *
 * RESPONSABILIDADES:
 * 1. UI: Adiciona a coluna "Click2Pickup" na lista de pedidos e exibe informações no cabeçalho de edição do pedido.
 * 2. NOTAS: Cria notas de pedido detalhadas sobre a redução de estoque por local e identifica a unidade responsável.
 * 3. METADADOS: Garante que os dados da unidade (loja/modo) sejam salvos no pedido durante sua criação.
 */
class C2P_Order_Admin {
    private static $instance;

    /** @var array Buffer temporário para consolidar notas de estoque por pedido. */
    private $buffer = [];

    /** @var string[] Chaves de metadados para buscar o ID da loja/local. */
    private const STORE_ID_META_KEYS = [ '_c2p_store_id', 'c2p_store_id', 'c2p_location_id', 'c2p_selected_store', 'rpws_store_id' ];
    
    /** @var string[] Chaves de metadados para buscar o modo de entrega/retirada. */
    private const ORDER_MODE_META_KEYS = [ '_c2p_mode', 'c2p_mode' ];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // --- Hooks de UI (Coluna e Cabeçalho) ---
        add_filter( 'woocommerce_shop_order_list_table_columns', [ $this, 'add_column_hpos' ], 20 );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_column_hpos' ], 10, 2 );
        add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column_legacy' ], 20 );
        add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_column_legacy' ], 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'print_header_placeholder' ], 99, 1 );
        add_action( 'admin_print_footer_scripts', [ $this, 'inject_header_script' ], 99 );

        // --- Hooks de Notas de Pedido e Metadados ---
        add_action( 'woocommerce_reduce_order_item_stock', [ $this, 'on_reduce_item_stock' ], 20, 3 );
        add_action( 'woocommerce_reduce_order_stock', [ $this, 'flush_order_notes' ], 10, 1 );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'stamp_fulfillment_unit_on_create' ], 20, 2 );
    }

    // ===================================================================
    // HELPERS CENTRALIZADOS
    // ===================================================================

    /**
     * Lê a unidade de atendimento e o modo do pedido. Usado por todas as funcionalidades.
     * @param \WC_Order|int $order Objeto do pedido ou ID do pedido.
     * @return array Dados da unidade de atendimento.
     */
    private function get_order_unit_info( $order ): array {
        $wc_order = $order instanceof \WC_Order ? $order : ( function_exists('wc_get_order') ? wc_get_order( (int)$order ) : null );
        if ( ! $wc_order ) {
            return [ 'location_id' => null, 'location_name' => '-', 'mode' => null, 'mode_label' => '-' ];
        }

        $location_id = null;
        foreach ( self::STORE_ID_META_KEYS as $key ) {
            $v = $wc_order->get_meta( $key, true );
            if ( $v !== '' && $v !== null ) { $location_id = (int) $v; break; }
        }

        $mode = null;
        foreach ( self::ORDER_MODE_META_KEYS as $key ) {
            $v = $wc_order->get_meta( $key, true );
            if ($v) { $mode = $v; break; }
        }
        
        if ( $mode === 'RECEBER' ) $mode = 'delivery';
        if ( $mode === 'RETIRAR' ) $mode = 'pickup';

        if ( ! $mode ) {
            foreach ( $wc_order->get_shipping_methods() as $ship ) {
                if ( strpos( $ship->get_method_id(), 'local_pickup' ) !== false ) { $mode = 'pickup'; break; }
            }
            if ( ! $mode ) $mode = 'delivery';
        }

        $location_name = 'CD Global';
        if ( $location_id ) {
            $post = get_post( $location_id );
            if ( $post && 'publish' === $post->post_status ) $location_name = $post->post_title;
        }

        return [
            'location_id'   => $location_id,
            'location_name' => $location_name,
            'mode'          => $mode,
            'mode_label'    => ($mode === 'pickup') ? __('RETIRADA NA LOJA','c2p') : __('ENVIO','c2p'),
        ];
    }

    /**
     * Lê o saldo (qty) de um produto em um local específico (considerando variações).
     * @return int|null null se não encontrado.
     */
    private function get_location_qty( int $product_id, ?int $variation_id, int $location_id ): ?int {
        global $wpdb;

        // Lógica para obter a tabela e coluna corretas, caso use a classe Inventory_DB
        if ( class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB','table_name') ) {
            $table = \C2P\Inventory_DB::table_name();
            $col_location = \C2P\Inventory_DB::store_column_name();
            $col_product = 'product_id'; // Assumindo que a coluna do produto é padrão
        } else {
            $table = $wpdb->prefix . 'c2p_multi_stock';
            $col_location = 'location_id';
            $col_product = 'product_id';
        }

        $pid_to_check = $variation_id ?: $product_id;

        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM {$table} WHERE {$col_product}=%d AND {$col_location}=%d LIMIT 1",
            $pid_to_check, $location_id
        ));

        return ( null !== $val ) ? (int) $val : null;
    }


    // ===================================================================
    // FUNCIONALIDADE: UI (COLUNA E CABEÇALHO)
    // ===================================================================

    private function render_column_badge( array $info ): string {
        $mode_label = ($info['mode'] === 'pickup') ? 'RETIRADA' : 'ENVIO';
        $mode_bg    = ($info['mode'] === 'pickup') ? '#e6f4ea' : '#e8f0fe';
        $mode_fg    = ($info['mode'] === 'pickup') ? '#137333' : '#174ea6';

        $name_html  = '<strong>'.esc_html($info['location_name']).'</strong>';
        $badge_html = '<span style="display:inline-block;margin-left:6px;padding:2px 6px;border-radius:10px;background:'.$mode_bg.';color:'.$mode_fg.';font-size:11px;font-weight:600;">'.$mode_label.'</span>';

        return $name_html . $badge_html;
    }

    public function add_column_hpos( array $cols ): array {
        $new = [];
        $inserted = false;
        foreach ( $cols as $key => $label ) {
            if ( $key === 'date' && ! $inserted ) {
                $new['c2p_click2pickup'] = __( 'Click2Pickup', 'c2p' );
                $inserted = true;
            }
            $new[ $key ] = $label;
        }
        if ( ! $inserted ) $new['c2p_click2pickup'] = __( 'Click2Pickup', 'c2p' );
        return $new;
    }

    public function render_column_hpos( string $column, \WC_Order $order ): void {
        if ( $column !== 'c2p_click2pickup' ) return;
        $info = $this->get_order_unit_info( $order );
        echo $this->render_column_badge( $info );
    }

    public function add_column_legacy( array $cols ): array {
        $new = [];
        $inserted = false;
        foreach ( $cols as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_total' && ! $inserted ) {
                $new['c2p_click2pickup'] = __( 'Click2Pickup', 'c2p' );
                $inserted = true;
            }
        }
        if ( ! $inserted ) $new['c2p_click2pickup'] = __( 'Click2Pickup', 'c2p' );
        return $new;
    }

    public function render_column_legacy( string $column, int $post_id ): void {
        if ( $column !== 'c2p_click2pickup' ) return;
        $info = $this->get_order_unit_info( $post_id );
        echo $this->render_column_badge( $info );
    }

    private function is_wc_orders_edit_screen(): bool {
        if ( ! function_exists('get_current_screen') ) return false;
        $s = get_current_screen();
        return $s && $s->id === 'woocommerce_page_wc-orders';
    }

    public function print_header_placeholder( \WC_Order $order ): void {
        if ( ! $this->is_wc_orders_edit_screen() ) return;
        $u = $this->get_order_unit_info( $order );
        $text = sprintf(
            __('Click2Pickup: %1$s — %2$s', 'c2p'),
            $u['location_name'],
            $u['mode_label']
        );
        echo '<span id="c2p-order-unit__ph" data-c2p-text="' . esc_attr( $text ) . '" style="display:none"></span>';
    }

    public function inject_header_script(): void {
        if ( ! $this->is_wc_orders_edit_screen() ) return;
        ?>
        <script>
        (function($){
            $(function(){
                var $ph = $('#c2p-order-unit__ph');
                if (!$ph.length) return;
                var text = $ph.data('c2p-text');
                var $col = $('.woocommerce-order-data .order_data_header .order_data_header_column').first();
                if ($col.length && text) {
                    $col.find('.c2p-order-unit-injected').remove();
                    var $p = $('<p/>', {'class': 'woocommerce-order-data__meta order_number c2p-order-unit-injected'});
                    $('<strong/>').text(text).appendTo($p);
                    $p.appendTo($col);
                }
                $ph.remove();
            });
        })(jQuery);
        </script>
        <?php
    }

    // ===================================================================
    // FUNCIONALIDADE: NOTAS DE PEDIDO AUTOMÁTICAS
    // ===================================================================

    public function on_reduce_item_stock( \WC_Order_Item_Product $item, array $change, \WC_Order $order ): void {
        $order_id = $order->get_id();
        if ( ! isset( $this->buffer[ $order_id ] ) ) {
            $this->buffer[ $order_id ] = [ 'lines' => [] ];
        }

        $product   = $change['product'];
        $from      = $change['from'] ?? 0;
        $to        = $change['to'] ?? 0;
        $qty_delta = absint( $from - $to );
        $name      = $product ? wp_strip_all_tags( $product->get_formatted_name() ) : $item->get_name();
        
        $pid = $item->get_product_id();
        $vid = $item->get_variation_id();
        
        $unit     = $this->get_order_unit_info( $order );
        $loc_id   = $unit['location_id'];
        $loc_name = $unit['location_name'];

        $local_display = '(-' . $qty_delta . ')'; // Fallback
        if ( $loc_id ) {
            $before = $this->get_location_qty( $pid, $vid, $loc_id );
            if ( null !== $before ) {
                $local_display = sprintf( '%d→%d', $before, max( 0, $before - $qty_delta ) );
            }
        }

        $this->buffer[ $order_id ]['lines'][] = sprintf(
            '%s: %s (%s)', $loc_name, $name, $local_display
        );
    }

    public function flush_order_notes( \WC_Order $order ): void {
        $order_id = $order->get_id();

        if ( ! $order->get_meta( '_c2p_unit_note_added', true ) ) {
            $unit = $this->get_order_unit_info( $order );
            $order->add_order_note( sprintf(
                'C2P • Unidade responsável: %s — %s',
                $unit['location_name'],
                $unit['mode_label']
            ));
            $order->update_meta_data( '_c2p_unit_note_added', 1 );
        }

        if ( ! empty( $this->buffer[ $order_id ]['lines'] ) ) {
            $note = 'C2P • Estoque por local reduzido: ' . implode( '; ', $this->buffer[ $order_id ]['lines'] );
            $order->add_order_note( $note );
        }

        if ( metadata_exists( 'post', $order_id, '_c2p_unit_note_added' ) ) {
             $order->save();
        }
        unset( $this->buffer[ $order_id ] );
    }

    public function stamp_fulfillment_unit_on_create( \WC_Order $order, array $data ): void {
        if ( $this->get_order_unit_info( $order )['location_id'] ) {
            return;
        }

        $sess = WC()->session ?? null;
        if ( ! $sess || ! $sess->has_session() ) return;
        
        $session_data = $sess->get('c2p_selected_location');
        if ( ! is_array($session_data) || empty($session_data['id']) ) return;
        
        $order->update_meta_data( '_c2p_store_id', (int) $session_data['id'] );
        if ( ! empty( $session_data['delivery_type'] ) ) {
            $mode = ($session_data['delivery_type'] === 'pickup') ? 'RETIRAR' : 'RECEBER';
            $order->update_meta_data( '_c2p_mode', $mode );
        }
    }
}

// Inicializa a classe unificada.
C2P_Order_Admin::instance();