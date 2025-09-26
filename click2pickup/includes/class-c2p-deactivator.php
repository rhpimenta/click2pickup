<?php
/**
 * Desativador do Plugin Click2Pickup
 * 
 * @package Click2Pickup
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Deactivator {
    
    /**
     * Método principal de desativação
     */
    public static function deactivate() {
        // Limpar eventos agendados
        self::clear_scheduled_events();
        
        // Limpar cache
        self::clear_cache();
        
        // Log de desativação
        self::log_deactivation();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Limpar eventos cron agendados
     */
    private static function clear_scheduled_events() {
        wp_clear_scheduled_hook('c2p_daily_stock_check');
        wp_clear_scheduled_hook('c2p_hourly_reservation_cleanup');
        wp_clear_scheduled_hook('c2p_weekly_report');
    }
    
    /**
     * Limpar cache
     */
    private static function clear_cache() {
        // Limpar transients do plugin
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_c2p_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_c2p_%'");
    }
    
    /**
     * Log de desativação
     */
    private static function log_deactivation() {
        $log_data = array(
            'deactivated_at' => current_time('mysql'),
            'deactivated_by' => get_current_user_id()
        );
        
        update_option('c2p_last_deactivation', $log_data);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Click2Pickup deactivated: ' . json_encode($log_data));
        }
    }
}