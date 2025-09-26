<?php
/**
 * Classe para operações da tabela de estoque
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Stock_Table extends C2P_Database {
    
    /**
     * Construtor
     */
    public function __construct() {
        parent::__construct();
        $this->table_name = $this->wpdb->prefix . 'c2p_stock';
    }
    
    /**
     * Obter estoque de um produto em um local
     */
    public function get_stock($product_id, $location_id) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT stock_quantity FROM {$this->table_name} WHERE product_id = %d AND location_id = %d",
                $product_id,
                $location_id
            )
        );
    }
    
    /**
     * Atualizar estoque
     */
    public function update_stock($product_id, $location_id, $quantity) {
        return $this->wpdb->replace(
            $this->table_name,
            array(
                'product_id' => $product_id,
                'location_id' => $location_id,
                'stock_quantity' => $quantity
            ),
            array('%d', '%d', '%f')
        );
    }
    
    /**
     * Obter estoque total de um produto
     */
    public function get_total_stock($product_id) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(stock_quantity) FROM {$this->table_name} WHERE product_id = %d",
                $product_id
            )
        );
    }
}