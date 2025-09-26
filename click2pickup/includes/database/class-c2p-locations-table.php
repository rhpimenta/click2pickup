<?php
/**
 * Classe para operações da tabela de locais
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Locations_Table extends C2P_Database {
    
    /**
     * Construtor
     */
    public function __construct() {
        parent::__construct();
        $this->table_name = $this->wpdb->prefix . 'c2p_locations';
    }
    
    /**
     * Obter locais ativos
     */
    public function get_active_locations($type = null) {
        $sql = "SELECT * FROM {$this->table_name} WHERE is_active = 1";
        
        if ($type) {
            $sql .= $this->wpdb->prepare(" AND type = %s", $type);
        }
        
        $sql .= " ORDER BY name ASC";
        
        return $this->wpdb->get_results($sql);
    }
    
    /**
     * Obter local por slug
     */
    public function get_by_slug($slug) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table_name} WHERE slug = %s", $slug)
        );
    }
}