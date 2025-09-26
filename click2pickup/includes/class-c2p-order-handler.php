<?php
/**
 * Manipula eventos de pedido para ajustar estoque por local
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Order_Handler {

    public function __construct() {
        // Deduzir estoque quando o pedido entra em processamento/completo
        add_action('woocommerce_order_status_processing', array($this, 'deduct_order_stock'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'deduct_order_stock'), 10, 1);

        // Repor estoque quando o pedido é cancelado/reembolsado
        add_action('woocommerce_order_status_cancelled', array($this, 'restore_order_stock'), 10, 1);
        add_action('woocommerce_order_refunded', array($this, 'restore_order_stock'), 10, 1);
    }

    /**
     * Deduz estoque do local selecionado
     */
    public function deduct_order_stock($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $location_id = intval($order->get_meta('_c2p_location_id'));
        if ($location_id <= 0) return;

        // Evitar deduzir mais de uma vez
        if ($order->get_meta('_c2p_stock_deducted')) {
            return;
        }

        $sm = C2P_Stock_Manager::get_instance();

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            $qty = intval($item->get_quantity());
            if ($product_id && $qty > 0) {
                // Primeiro libera qualquer reserva remanescente
                $sm->unreserve_stock($location_id, $product_id, $qty);
                // Deduz
                $sm->adjust_stock($location_id, $product_id, -$qty, 'sale', $order_id, 'Order fulfillment');
            }
        }

        $order->update_meta_data('_c2p_stock_deducted', 1);
        $order->save();
    }

    /**
     * Restaura estoque do local selecionado
     */
    public function restore_order_stock($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $location_id = intval($order->get_meta('_c2p_location_id'));
        if ($location_id <= 0) return;

        // Só repõe se já tiver deduzido
        if (!$order->get_meta('_c2p_stock_deducted')) {
            return;
        }

        $sm = C2P_Stock_Manager::get_instance();

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            $qty = intval($item->get_quantity());
            if ($product_id && $qty > 0) {
                $sm->adjust_stock($location_id, $product_id, +$qty, 'return', $order_id, 'Order cancelled/refunded');
            }
        }

        $order->update_meta_data('_c2p_stock_deducted', 0);
        $order->save();
    }
}