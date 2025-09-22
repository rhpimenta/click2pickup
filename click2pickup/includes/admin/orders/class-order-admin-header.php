<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mostra "Click2Pickup: <Loja/CD> — <ENVIO/RETIRADA>"
 * no cabeçalho do pedido (faixa amarela), dentro de .order_data_header_column.
 *
 * Estratégia:
 *  - Emite um placeholder oculto (PHP).
 *  - No rodapé do admin, JS move e injeta o conteúdo negritado na coluna do cabeçalho.
 */
class Order_Admin_Header {
    private static $instance;
    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'print_placeholder' ], 99, 1 );
        add_action( 'admin_print_footer_scripts', [ $this, 'inject_script' ], 99 );
    }

    /** Detecta a tela de edição do pedido no wc-orders (HPOS). */
    private function is_wc_orders_edit_screen(): bool {
        if ( ! function_exists('get_current_screen') ) return false;
        $s = get_current_screen();
        if ( ! $s ) return false;
        // Em wc-orders, o id costuma ser 'woocommerce_page_wc-orders'
        return ( $s->id === 'woocommerce_page_wc-orders' );
    }

    /** Lê a unidade/mode gravados no pedido, com fallbacks. */
    private function get_fulfillment_unit( \WC_Order $order ): array {
        $candidates  = [ '_c2p_store_id', 'c2p_store_id', 'c2p_location_id', 'c2p_selected_store', 'rpws_store_id' ];
        $location_id = null;
        foreach ( $candidates as $key ) {
            $v = $order->get_meta( $key, true );
            if ( $v !== '' && $v !== null ) { $location_id = (int) $v; break; }
        }

        $mode = $order->get_meta( '_c2p_mode', true ) ?: $order->get_meta( 'c2p_mode', true );
        if ( $mode === 'RECEBER' ) $mode = 'delivery';
        if ( $mode === 'RETIRAR' ) $mode = 'pickup';
        if ( ! $mode ) {
            foreach ( $order->get_shipping_methods() as $ship ) {
                $mid = $ship->get_method_id();
                if ( $mid && strpos( $mid, 'local_pickup' ) !== false ) { $mode = 'pickup'; break; }
            }
            if ( ! $mode ) $mode = 'delivery';
        }

        $location_name = 'CD Global';
        if ( $location_id ) {
            $post = get_post( $location_id );
            if ( $post && 'publish' === $post->post_status ) {
                $location_name = $post->post_title;
            }
        }

        return [
            'location_id'   => $location_id,
            'location_name' => $location_name,
            'mode'          => $mode,
            'mode_label'    => ($mode === 'pickup') ? __('RETIRADA NA LOJA','c2p')
                               : (($mode === 'delivery') ? __('ENVIO','c2p') : __('ATENDIMENTO','c2p')),
        ];
    }

    /** Imprime placeholder oculto com o texto final (PHP). */
    public function print_placeholder( $order ) {
        if ( ! $order instanceof \WC_Order ) return;
        if ( ! $this->is_wc_orders_edit_screen() ) return;

        $u = $this->get_fulfillment_unit( $order );
        // Texto base (sem HTML). O HTML (negrito) é aplicado no JS via <strong>.
        $text = sprintf(
            /* translators: 1: unit name, 2: mode label */
            __('Click2Pickup: %1$s — %2$s', 'c2p'),
            $u['location_name'],
            $u['mode_label']
        );

        echo '<span id="c2p-order-unit__ph" data-c2p-text="' . esc_attr( $text ) . '" style="display:none"></span>';
    }

    /** JS que move o texto para a faixa amarela (coluna do cabeçalho) e aplica <strong>. */
    public function inject_script() {
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
                    // Negrito preservando escape de texto
                    $('<strong/>').text(text).appendTo($p);
                    $p.appendTo($col);
                }
                $ph.remove();
            });
        })(jQuery);
        </script>
        <?php
    }
}
Order_Admin_Header::instance();
