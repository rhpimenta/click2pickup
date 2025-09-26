<?php
/**
 * Correção de Sessão para Guias Anônimas - Click2Pickup
 * 
 * @package Click2Pickup
 * @author RH Pimenta
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe para corrigir problemas de sessão em guias anônimas
 */
class C2P_Session_Fix {
    
    /**
     * Inicializar correções
     */
    public static function init() {
        // Prioridade ALTA para executar ANTES do WooCommerce
        add_action('init', array(__CLASS__, 'force_session_for_guests'), 1);
        add_action('woocommerce_init', array(__CLASS__, 'ensure_wc_session'), 5);
        add_filter('woocommerce_checkout_update_order_review_expired', '__return_false');
        add_filter('nonce_user_logged_out', array(__CLASS__, 'fix_nonce_uid'), 10, 2);
        
        // Corrigir AJAX para usuários não logados
        add_action('wp_ajax_nopriv_woocommerce_update_order_review', array(__CLASS__, 'fix_update_order_review'), 1);
        add_filter('woocommerce_ship_to_different_address_checked', array(__CLASS__, 'fix_shipping_address'));
        
        // Headers para prevenir cache
        add_action('send_headers', array(__CLASS__, 'prevent_cache_headers'));
    }
    
    /**
     * Forçar criação de sessão para visitantes
     */
    public static function force_session_for_guests() {
        if (!is_admin() && !defined('DOING_AJAX')) {
            // Iniciar sessão PHP se necessário
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            
            // Forçar cookie de sessão do WooCommerce
            if (!is_user_logged_in() && class_exists('WC_Session_Handler')) {
                if (is_null(WC()->session) || !WC()->session->has_session()) {
                    WC()->session = new WC_Session_Handler();
                    WC()->session->init();
                    WC()->session->set_customer_session_cookie(true);
                }
            }
        }
    }
    
    /**
     * Garantir que a sessão do WC está iniciada
     */
    public static function ensure_wc_session() {
        if (!is_admin() && WC()->session) {
            // Se não tem sessão, criar uma
            if (!WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
            
            // Adicionar um valor dummy para manter a sessão ativa
            if (!WC()->session->get('c2p_session_active')) {
                WC()->session->set('c2p_session_active', true);
            }
        }
    }
    
    /**
     * Corrigir UID do nonce para usuários não logados
     */
    public static function fix_nonce_uid($uid, $action) {
        if (!is_user_logged_in() && strpos($action, 'woocommerce') !== false) {
            // Usar um UID consistente baseado na sessão
            if (WC()->session && WC()->session->get_customer_id()) {
                return WC()->session->get_customer_id();
            }
            // Fallback para IP + User Agent
            return md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
        }
        return $uid;
    }
    
    /**
     * Corrigir AJAX update_order_review para não logados
     */
    public static function fix_update_order_review() {
        // Verificar se é uma requisição válida
        if (!isset($_POST['security'])) {
            // Criar um nonce válido se não existir
            $_POST['security'] = wp_create_nonce('update-order-review');
        }
        
        // Garantir sessão
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }
    
    /**
     * Headers para prevenir cache em páginas críticas
     */
    public static function prevent_cache_headers() {
        if (is_checkout() || is_cart()) {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
    
    /**
     * Corrigir checkbox de endereço diferente
     */
    public static function fix_shipping_address($checked) {
        if (!is_user_logged_out()) {
            return $checked;
        }
        
        // Para usuários não logados, sempre retornar false para simplificar
        return false;
    }
}

// Inicializar as correções
C2P_Session_Fix::init();