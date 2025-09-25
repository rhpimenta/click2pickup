<?php
/**
 * Model para Estoque
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Stock {
    
    public $id;
    public $product_id;
    public $location_id;
    public $stock_quantity;
    public $reserved_quantity;
    public $min_stock_level;
    public $last_updated;
    
    /**
     * Construtor
     */
    public function __construct($data = null) {
        if ($data) {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
    
    /**
     * Obtém quantidade disponível
     */
    public function get_available_quantity() {
        return max(0, $this->stock_quantity - $this->reserved_quantity);
    }
    
    /**
     * Verifica se está abaixo do estoque mínimo
     */
    public function is_below_minimum() {
        return $this->stock_quantity < $this->min_stock_level;
    }
}