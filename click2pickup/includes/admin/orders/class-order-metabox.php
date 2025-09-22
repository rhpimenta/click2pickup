<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metabox administrativo no pedido: mostra a Unidade (envio/retirada) de forma clara.
 */
class Order_Metabox {
    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register_box' ] );
    }

    public function register_box() {
        add_meta_box(
            'c2p_order_unit',
            'Click2Pickup • Unidade de Atendimento',
            [ $this, 'render_box' ],
            'shop_order',
            'side',
            'high'
        );
    }

    public function render_box( $post ) {
        $order = function_exists('wc_get_order') ? wc_get_order( $post ) : null;
        if ( ! $order ) {
            echo '<p>Pedido inválido.</p>';
            return;
        }

        // Ler metas gravadas
        $store_id = (int) ( $order->get_meta('_c2p_store_id', true) ?: $order->get_meta('c2p_store_id', true) ?: $order->get_meta('c2p_location_id', true) );
        $mode     = $order->get_meta('_c2p_mode', true) ?: $order->get_meta('c2p_mode', true);

        $mode_label = ($mode === 'pickup') ? 'RETIRADA NA LOJA' : ( ($mode === 'delivery' || $mode === 'RECEBER') ? 'ENVIO' : 'ATENDIMENTO' );
        $name = 'CD Global';
        $addr = '';
        if ( $store_id ) {
            $p = get_post( $store_id );
            if ( $p && 'publish' === $p->post_status ) {
                $name = $p->post_title;
                // Campos opcionais de endereço (ajuste os meta_keys se diferirem)
                $addr1 = get_post_meta( $store_id, 'address_line1', true );
                $city  = get_post_meta( $store_id, 'city', true );
                $uf    = get_post_meta( $store_id, 'state', true );
                $zip   = get_post_meta( $store_id, 'postcode', true );
                $addr_parts = array_filter([$addr1, $city, $uf, $zip]);
                if ( $addr_parts ) $addr = implode(' • ', $addr_parts);
            }
        }

        echo '<div style="padding:8px 4px;">';
        echo '<div style="font-weight:600;font-size:13px;margin-bottom:6px;">' . esc_html($mode_label) . '</div>';
        echo '<div style="font-size:14px;margin-bottom:4px;">' . esc_html($name) . '</div>';
        if ( $addr ) echo '<div style="color:#555;">' . esc_html($addr) . '</div>';
        echo '</div>';
        echo '<p style="margin-top:8px;"><em>As baixas por local são registradas nas notas do pedido (C2P).</em></p>';
    }
}
Order_Metabox::instance();
