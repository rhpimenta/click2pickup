<?php
/**
 * Classe principal do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Click2Pickup {
    
    private static $instance = null;
    private $admin;
    private $public;
    private $locations_admin; // Adicionar propriedade
    
    /**
     * Singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construtor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->define_hooks();
        
        // Verificar atualizações do banco
        add_action('admin_init', array('C2P_Activator', 'check_db_update'));
    }
    
    /**
     * Carrega dependências
     */
    private function load_dependencies() {
        // Activator
        require_once C2P_PLUGIN_DIR . 'includes/class-c2p-activator.php';
        
        // Admin
        require_once C2P_PLUGIN_DIR . 'admin/class-c2p-admin.php';
        
        // Locations Admin - IMPORTANTE: carregar sempre para registrar hooks
        require_once C2P_PLUGIN_DIR . 'admin/class-c2p-locations-admin.php';
        
        // Product tab
        if (file_exists(C2P_PLUGIN_DIR . 'admin/class-c2p-product-tab.php')) {
            require_once C2P_PLUGIN_DIR . 'admin/class-c2p-product-tab.php';
        }
        
        // Public
        require_once C2P_PLUGIN_DIR . 'public/class-c2p-public.php';
        require_once C2P_PLUGIN_DIR . 'public/class-c2p-checkout.php';
    }
    
    /**
     * Define hooks
     */
    private function define_hooks() {
        // IMPORTANTE: Instanciar Locations Admin para registrar hooks
        $this->locations_admin = new C2P_Locations_Admin();
        
        // Hooks do admin
        if (is_admin()) {
            $this->admin = new C2P_Admin();
            
            add_action('admin_menu', array($this->admin, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_styles'));
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_scripts'));
            
            // Product tab
            if (class_exists('C2P_Product_Tab')) {
                new C2P_Product_Tab();
            }
        }
        
        // Hooks públicos
        if (!is_admin()) {
            $this->public = new C2P_Public();
            
            // Checkout
            if (class_exists('WooCommerce')) {
                new C2P_Checkout();
            }
        }
    }
}