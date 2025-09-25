<?php
/**
 * Classe base para operações de banco de dados
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class C2P_Database {
    
    /**
     * @var wpdb
     */
    protected $wpdb;
    
    /**
     * Nome da tabela
     */
    protected $table_name;
    
    /**
     * Construtor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * Inserir registro
     */
    public function insert($data) {
        return $this->wpdb->insert($this->table_name, $data);
    }
    
    /**
     * Atualizar registro
     */
    public function update($data, $where) {
        return $this->wpdb->update($this->table_name, $data, $where);
    }
    
    /**
     * Deletar registro
     */
    public function delete($where) {
        return $this->wpdb->delete($this->table_name, $where);
    }
    
    /**
     * Obter registro por ID
     */
    public function get($id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id)
        );
    }
    
    /**
     * Obter todos os registros
     */
    public function get_all($where = array()) {
        $sql = "SELECT * FROM {$this->table_name}";
        
        if (!empty($where)) {
            $conditions = array();
            foreach ($where as $key => $value) {
                $conditions[] = $this->wpdb->prepare("$key = %s", $value);
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        return $this->wpdb->get_results($sql);
    }
}