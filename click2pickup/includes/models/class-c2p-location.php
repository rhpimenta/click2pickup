<?php
/**
 * Model para Local
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Location {
    
    public $id;
    public $name;
    public $type;
    public $slug;
    public $address;
    public $city;
    public $state;
    public $zip_code;
    public $country;
    public $phone;
    public $email;
    public $latitude;
    public $longitude;
    public $opening_hours;
    public $preparation_time;
    public $cutoff_time;
    public $is_active;
    public $min_stock_alert;
    public $manager_id;
    
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
     * Verifica se é centro de distribuição
     */
    public function is_distribution_center() {
        return $this->type === 'distribution_center';
    }
    
    /**
     * Verifica se é loja física
     */
    public function is_store() {
        return $this->type === 'store';
    }
}