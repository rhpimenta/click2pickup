<?php
/**
 * Ativador do Plugin Click2Pickup
 * 
 * Gerencia a ativação do plugin, criação e atualização de tabelas
 * 
 * @package Click2Pickup
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Activator {
    
    /**
     * Método principal de ativação
     */
    public static function activate() {
        // Desabilitar output durante ativação para evitar erros
        ob_start();
        
        try {
            // Criar/atualizar tabelas
            self::create_tables();
            
            // Adicionar capacidades
            self::add_capabilities();
            
            // Agendar tarefas cron
            self::schedule_cron_events();
            
            // Definir opções padrão
            self::set_default_options();
            
            // Limpar cache de rewrite rules
            flush_rewrite_rules();
            
            // Marcar versão do banco
            update_option('c2p_db_version', C2P_VERSION);
            
            // Log de ativação
            self::log_activation();
            
        } catch (Exception $e) {
            error_log('Click2Pickup Activation Error: ' . $e->getMessage());
        }
        
        // Limpar qualquer output
        ob_end_clean();
    }
    
    /**
     * Criar ou atualizar tabelas do banco de dados
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Desabilitar erros de exibição temporariamente
        $wpdb->hide_errors();
        
        // Tabela de locais
        $table_locations = $wpdb->prefix . 'c2p_locations';
        
        // Dropar e recriar se necessário para garantir estrutura correta
        $sql_drop_locations = "DROP TABLE IF EXISTS $table_locations";
        $wpdb->query($sql_drop_locations);
        
        $sql_locations = "CREATE TABLE $table_locations (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            type ENUM('store', 'distribution_center') DEFAULT 'store',
            address TEXT,
            city VARCHAR(100),
            state VARCHAR(50),
            zip_code VARCHAR(20),
            country VARCHAR(2) DEFAULT 'BR',
            phone VARCHAR(20),
            email VARCHAR(100),
            image_id INT DEFAULT 0,
            opening_hours LONGTEXT,
            shipping_zones LONGTEXT,
            shipping_methods LONGTEXT,
            payment_methods LONGTEXT,
            pickup_enabled TINYINT(1) DEFAULT 1,
            delivery_enabled TINYINT(1) DEFAULT 1,
            priority INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY type_active (type, is_active)
        ) $charset_collate;";
        
        $wpdb->query($sql_locations);
        
        // Tabela de estoque
        $table_stock = $wpdb->prefix . 'c2p_stock';
        
        // Dropar e recriar
        $sql_drop_stock = "DROP TABLE IF EXISTS $table_stock";
        $wpdb->query($sql_drop_stock);
        
        $sql_stock = "CREATE TABLE $table_stock (
            id BIGINT NOT NULL AUTO_INCREMENT,
            location_id INT NOT NULL,
            product_id BIGINT NOT NULL,
            stock_quantity INT DEFAULT 0,
            reserved_quantity INT DEFAULT 0,
            low_stock_threshold INT DEFAULT 5,
            min_stock_level INT DEFAULT 0,
            manage_stock TINYINT(1) DEFAULT 1,
            allow_backorder TINYINT(1) DEFAULT 0,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY location_product (location_id, product_id),
            KEY product_id (product_id),
            KEY location_stock (location_id, stock_quantity)
        ) $charset_collate;";
        
        $wpdb->query($sql_stock);
        
        // Tabela de log de estoque
        $table_stock_log = $wpdb->prefix . 'c2p_stock_log';
        $sql_stock_log = "CREATE TABLE IF NOT EXISTS $table_stock_log (
            id BIGINT NOT NULL AUTO_INCREMENT,
            product_id BIGINT NOT NULL,
            location_id INT NOT NULL,
            quantity_change INT NOT NULL,
            stock_before INT DEFAULT 0,
            stock_after INT DEFAULT 0,
            reason VARCHAR(50),
            order_id BIGINT DEFAULT NULL,
            user_id BIGINT DEFAULT NULL,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_location (product_id, location_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        $wpdb->query($sql_stock_log);
        
        // Tabela de movimentações
        $table_movements = $wpdb->prefix . 'c2p_stock_movements';
        $sql_movements = "CREATE TABLE IF NOT EXISTS $table_movements (
            id BIGINT NOT NULL AUTO_INCREMENT,
            location_id INT NOT NULL,
            product_id BIGINT NOT NULL,
            type ENUM('sale', 'return', 'adjustment', 'transfer_in', 'transfer_out', 'import', 'reservation', 'cancellation') NOT NULL,
            quantity INT NOT NULL,
            reference_type VARCHAR(50),
            reference_id BIGINT DEFAULT NULL,
            order_id BIGINT DEFAULT NULL,
            user_id BIGINT DEFAULT NULL,
            notes TEXT,
            metadata LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY location_product (location_id, product_id),
            KEY type_date (type, created_at),
            KEY order_id (order_id)
        ) $charset_collate;";
        
        $wpdb->query($sql_movements);
        
        // Reativar exibição de erros
        $wpdb->show_errors();
    }
    
    /**
     * Adicionar capacidades aos roles
     */
    private static function add_capabilities() {
        $capabilities = array(
            'manage_c2p_locations',
            'edit_c2p_locations',
            'delete_c2p_locations',
            'manage_c2p_stock',
            'edit_c2p_stock',
            'view_c2p_reports',
            'manage_c2p_settings'
        );
        
        // Admin tem todas as capacidades
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach ($capabilities as $cap) {
                $admin_role->add_cap($cap);
            }
        }
        
        // Shop manager tem quase todas
        $manager_role = get_role('shop_manager');
        if ($manager_role) {
            foreach ($capabilities as $cap) {
                if ($cap !== 'manage_c2p_settings') {
                    $manager_role->add_cap($cap);
                }
            }
        }
    }
    
    /**
     * Agendar eventos cron
     */
    private static function schedule_cron_events() {
        // Verificação diária de estoque baixo
        if (!wp_next_scheduled('c2p_daily_stock_check')) {
            wp_schedule_event(time(), 'daily', 'c2p_daily_stock_check');
        }
        
        // Limpeza de reservas expiradas (a cada hora)
        if (!wp_next_scheduled('c2p_hourly_reservation_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'c2p_hourly_reservation_cleanup');
        }
    }
    
    /**
     * Definir opções padrão
     */
    private static function set_default_options() {
        // Opções gerais
        add_option('c2p_enable_multi_location', 'yes');
        add_option('c2p_force_location_selection', 'yes');
        add_option('c2p_show_stock_in_frontend', 'yes');
        add_option('c2p_low_stock_threshold', 5);
        add_option('c2p_enable_reservations', 'yes');
        add_option('c2p_reservation_duration', 60); // minutos
        
        // Opções de notificação
        add_option('c2p_enable_low_stock_alerts', 'yes');
        add_option('c2p_alert_email', get_option('admin_email'));
        
        // Primeira instalação
        add_option('c2p_first_install', current_time('mysql'));
        add_option('c2p_version', C2P_VERSION);
    }
    
    /**
     * Log de ativação
     */
    private static function log_activation() {
        $log_data = array(
            'version' => C2P_VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'not installed',
            'activated_at' => current_time('mysql'),
            'activated_by' => get_current_user_id()
        );
        
        update_option('c2p_activation_log', $log_data);
    }
}