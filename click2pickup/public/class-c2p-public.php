<?php
/**
 * Classe pública do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Public {
    
    /**
     * Enqueue styles
     */
    public function enqueue_styles() {
        // Apenas carregar se necessário
        if (is_checkout() || is_product() || is_shop()) {
            wp_enqueue_style(
                'c2p-public',
                C2P_PLUGIN_URL . 'assets/css/public.css',
                array(),
                C2P_VERSION
            );
        }
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        // Apenas carregar se necessário
        if (is_checkout() || is_product() || is_shop()) {
            wp_enqueue_script(
                'c2p-public',
                C2P_PLUGIN_URL . 'assets/js/public.js',
                array('jquery'),
                C2P_VERSION,
                true
            );
            
            wp_localize_script('c2p-public', 'c2p_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('c2p_public_nonce')
            ));
        }
    }
}