<?php
/**
 * Classe responsável pela desinstalação do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Uninstaller {
    
    /**
     * Método executado na desinstalação do plugin
     */
    public static function uninstall() {
        // Verificar se o usuário optou por remover dados
        $remove_data = get_option('c2p_remove_data_on_uninstall', false);
        
        if ($remove_data) {
            self::remove_database_tables();
            self::remove_options();
            self::remove_capabilities();
        }
    }
    
    /**
     * Remove tabelas do banco de dados
     */
    private static function remove_database_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'c2p_reservations',
            $wpdb->prefix . 'c2p_stock_log',
            $wpdb->prefix . 'c2p_stock',
            $wpdb->prefix . 'c2p_locations'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
    
    /**
     * Remove opções do plugin
     */
    private static function remove_options() {
        global $wpdb;
        
        // Remover todas as opções do plugin
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE 'c2p_%'"
        );
        
        // Remover meta de produtos
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE '_c2p_%'"
        );
    }
    
    /**
     * Remove capacidades dos roles
     */
    private static function remove_capabilities() {
        $capabilities = array(
            'manage_c2p_locations',
            'edit_c2p_locations',
            'view_c2p_reports',
            'manage_c2p_stock',
            'transfer_c2p_stock'
        );
        
        // Remover capacidades do administrador
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach ($capabilities as $cap) {
                $admin_role->remove_cap($cap);
            }
        }
        
        // Remover capacidades do shop manager
        $shop_manager = get_role('shop_manager');
        if ($shop_manager) {
            foreach ($capabilities as $cap) {
                $shop_manager->remove_cap($cap);
            }
        }
    }
}