<?php
/**
 * Classe helper para operações de pedidos compatível com HPOS
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Order_Helper {
    
    /**
     * Verifica se HPOS está ativado
     */
    public static function is_hpos_enabled() {
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
    }
    
    /**
     * Obtém um pedido de forma compatível com HPOS
     */
    public static function get_order($order_id) {
        if (self::is_hpos_enabled()) {
            return wc_get_order($order_id);
        } else {
            return wc_get_order($order_id);
        }
    }
    
    /**
     * Obtém meta data do pedido
     */
    public static function get_order_meta($order_id, $key, $single = true) {
        $order = self::get_order($order_id);
        if ($order) {
            return $order->get_meta($key, $single);
        }
        return false;
    }
    
    /**
     * Atualiza meta data do pedido
     */
    public static function update_order_meta($order_id, $key, $value) {
        $order = self::get_order($order_id);
        if ($order) {
            $order->update_meta_data($key, $value);
            $order->save();
            return true;
        }
        return false;
    }
    
    /**
     * Deleta meta data do pedido
     */
    public static function delete_order_meta($order_id, $key) {
        $order = self::get_order($order_id);
        if ($order) {
            $order->delete_meta_data($key);
            $order->save();
            return true;
        }
        return false;
    }
    
    /**
     * Adiciona nota ao pedido
     */
    public static function add_order_note($order_id, $note, $is_customer_note = false) {
        $order = self::get_order($order_id);
        if ($order) {
            $order->add_order_note($note, $is_customer_note);
            return true;
        }
        return false;
    }
    
    /**
     * Obtém itens do pedido
     */
    public static function get_order_items($order_id, $type = 'line_item') {
        $order = self::get_order($order_id);
        if ($order) {
            return $order->get_items($type);
        }
        return array();
    }
    
    /**
     * Obtém o local associado ao pedido
     */
    public static function get_order_location($order_id) {
        return self::get_order_meta($order_id, '_c2p_location_id', true);
    }
    
    /**
     * Define o local associado ao pedido
     */
    public static function set_order_location($order_id, $location_id) {
        return self::update_order_meta($order_id, '_c2p_location_id', $location_id);
    }
    
    /**
     * Obtém o tipo de entrega do pedido
     */
    public static function get_delivery_type($order_id) {
        return self::get_order_meta($order_id, '_c2p_delivery_type', true);
    }
    
    /**
     * Define o tipo de entrega do pedido
     */
    public static function set_delivery_type($order_id, $type) {
        return self::update_order_meta($order_id, '_c2p_delivery_type', $type);
    }
}