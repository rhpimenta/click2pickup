<?php
/**
 * Gerenciador de Estoque Global (Multi-local)
 *
 * - Calcula estoque global como soma dos estoques por local (tabela c2p_stock)
 * - Fornece helpers para ajustar estoque e registrar movimentações
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Stock_Manager {

    private static $instance = null;
    private $hooks_initialized = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if ($this->hooks_initialized) {
            return;
        }
        $this->hooks_initialized = true;

        // Usar estoque global (soma por locais) nas telas da loja
        add_filter('woocommerce_product_get_stock_quantity', array($this, 'filter_stock_quantity'), 10, 2);
        add_filter('woocommerce_variation_get_stock_quantity', array($this, 'filter_stock_quantity'), 10, 2);
    }

    /**
     * Substitui a quantidade de estoque por soma em c2p_stock
     */
    public function filter_stock_quantity($qty, $product) {
        $product_id = $product ? intval($product->get_id()) : 0;
        if ($product_id <= 0) {
            return $qty;
        }

        $sum = $this->get_global_stock($product_id);
        // Caso a tabela não exista ainda ou retorne null, mantém valor original
        if ($sum === null) {
            return $qty;
        }
        return max(0, intval($sum));
    }

    /**
     * Retorna a soma do estoque do produto em todos os locais
     * Retorna null se a tabela não existir
     */
    public function get_global_stock($product_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'c2p_stock';
        // Verifica se a tabela existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));
        if (!$exists) {
            return null;
        }

        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(stock_quantity - reserved_quantity) FROM $table WHERE product_id = %d",
            $product_id
        ));
        if ($sum === null) {
            return 0;
        }
        return intval($sum);
    }

    /**
     * Ajusta estoque em um local. $qty_change pode ser negativo (venda) ou positivo (devolução).
     * Registra movimentação em c2p_stock_movements.
     */
    public function adjust_stock($location_id, $product_id, $qty_change, $type = 'adjustment', $order_id = null, $notes = '') {
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $mov_table = $wpdb->prefix . 'c2p_stock_movements';

        // Upsert
        $row_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $stock_table WHERE location_id = %d AND product_id = %d",
            $location_id, $product_id
        ));

        if ($row_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $stock_table SET stock_quantity = stock_quantity + %d WHERE id = %d",
                $qty_change, $row_id
            ));
        } else {
            $wpdb->insert($stock_table, array(
                'location_id' => $location_id,
                'product_id' => $product_id,
                'stock_quantity' => max(0, intval($qty_change)), // se negativo, zera
                'reserved_quantity' => 0,
                'low_stock_threshold' => 5,
                'manage_stock' => 1,
                'allow_backorder' => 0
            ));
        }

        // Registrar movimentação
        $wpdb->insert($mov_table, array(
            'location_id' => $location_id,
            'product_id' => $product_id,
            'type' => $type,
            'quantity' => $qty_change,
            'order_id' => $order_id,
            'user_id' => get_current_user_id(),
            'notes' => $notes
        ));
    }

    /**
     * Reserva quantidade (aumenta reserved_quantity)
     */
    public function reserve_stock($location_id, $product_id, $qty) {
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $wpdb->query($wpdb->prepare(
            "UPDATE $stock_table SET reserved_quantity = GREATEST(0, reserved_quantity + %d) WHERE location_id = %d AND product_id = %d",
            $qty, $location_id, $product_id
        ));
    }

    /**
     * Libera reserva (diminui reserved_quantity)
     */
    public function unreserve_stock($location_id, $product_id, $qty) {
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $wpdb->query($wpdb->prepare(
            "UPDATE $stock_table SET reserved_quantity = GREATEST(0, reserved_quantity - %d) WHERE location_id = %d AND product_id = %d",
            $qty, $location_id, $product_id
        ));
    }
}