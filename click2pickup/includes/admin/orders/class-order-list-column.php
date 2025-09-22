<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adiciona a coluna "Click2Pickup" na lista de pedidos.
 * Compatível com:
 *  - WooCommerce HPOS (tabela nova): hooks `woocommerce_shop_order_list_table_columns` e `woocommerce_shop_order_list_table_custom_column`
 *  - Legado (lista de posts shop_order): `manage_edit-shop_order_columns` e `manage_shop_order_posts_custom_column`
 */
class Order_List_Column {
    private static $booted = false;

    public static function boot(): void {
        if ( self::$booted ) return;
        self::$booted = true;

        // === NOVA LISTA (HPOS)
        add_filter( 'woocommerce_shop_order_list_table_columns', [ __CLASS__, 'add_column_hpos' ], 20 );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ __CLASS__, 'render_column_hpos' ], 10, 2 );

        // === LEGADO (WP Posts)
        add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_column_legacy' ], 20 );
        add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column_legacy' ], 10, 2 );
    }

    /** ------------- Helpers ------------- */

    /** Obtém unidade + modo (pickup/delivery) para um pedido */
    private static function get_unit_info( $order ): array {
        $wc_order = $order instanceof \WC_Order ? $order : ( function_exists('wc_get_order') ? wc_get_order( (int)$order ) : null );
        if ( ! $wc_order ) return [ 'name' => '-', 'mode' => '-', 'id' => 0 ];

        $candidates = [ '_c2p_store_id','c2p_store_id','c2p_location_id','c2p_selected_store','rpws_store_id' ];
        $location_id = 0;
        foreach ( $candidates as $k ) {
            $v = $wc_order->get_meta( $k, true );
            if ( $v !== '' && $v !== null ) { $location_id = (int) $v; break; }
        }

        $mode = $wc_order->get_meta('_c2p_mode',true) ?: $wc_order->get_meta('c2p_mode',true);
        if ( $mode === 'RECEBER' ) $mode = 'delivery';
        if ( $mode === 'RETIRAR' ) $mode = 'pickup';
        if ( ! $mode ) {
            foreach ( $wc_order->get_shipping_methods() as $ship ) {
                $mid = $ship->get_method_id();
                if ( $mid && strpos( $mid, 'local_pickup' ) !== false ) { $mode = 'pickup'; break; }
            }
            if ( ! $mode ) $mode = 'delivery';
        }

        $name = 'CD Global';
        if ( $location_id ) {
            $p = get_post( $location_id );
            if ( $p && $p->post_status === 'publish' ) $name = $p->post_title;
        }
        return [ 'name' => $name, 'mode' => $mode, 'id' => $location_id ];
    }

    /** HTML curto da coluna */
    private static function render_badge( array $info ): string {
        $mode_label = ($info['mode'] === 'pickup') ? 'RETIRADA' : 'ENVIO';
        $mode_bg    = ($info['mode'] === 'pickup') ? '#e6f4ea' : '#e8f0fe';
        $mode_fg    = ($info['mode'] === 'pickup') ? '#137333' : '#174ea6';

        $name_html  = '<strong>'.esc_html($info['name']).'</strong>';
        $badge_html = '<span style="display:inline-block;margin-left:6px;padding:2px 6px;border-radius:10px;background:'.$mode_bg.';color:'.$mode_fg.';font-size:11px;font-weight:600;">'.$mode_label.'</span>';

        return $name_html . $badge_html;
    }

    /** ------------- HPOS ------------- */

    public static function add_column_hpos( array $cols ): array {
        // Insere a nossa coluna antes da coluna "date" se existir; caso contrário, adiciona ao final
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

    /**
     * @param string   $column Column ID
     * @param \WC_Order $order  Order object
     */
    public static function render_column_hpos( string $column, $order ): void {
        if ( $column !== 'c2p_click2pickup' ) return;
        $info = self::get_unit_info( $order );
        echo self::render_badge( $info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** ------------- Legado ------------- */

    public static function add_column_legacy( array $cols ): array {
        // Insere depois de 'order_total' se existir
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

    /**
     * @param string $column   Column ID
     * @param int    $post_id  Order post ID
     */
    public static function render_column_legacy( string $column, int $post_id ): void {
        if ( $column !== 'c2p_click2pickup' ) return;
        $info = self::get_unit_info( $post_id );
        echo self::render_badge( $info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
