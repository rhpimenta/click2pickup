<?php
/**
 * Gerenciador de Sessões - Click2Pickup
 * 
 * @package Click2Pickup
 * @author RH Pimenta
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Session_Manager {
    
    /**
     * Configurar sessão para usuários não logados
     */
    public function setup_guest_session() {
        // Sempre iniciar sessão PHP
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // Para usuários não logados, garantir cookie de sessão
        if (!is_user_logged_in()) {
            if (!isset($_COOKIE['c2p_guest_id'])) {
                $guest_id = 'guest_' . md5(uniqid('', true));
                setcookie('c2p_guest_id', $guest_id, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                $_COOKIE['c2p_guest_id'] = $guest_id;
            }
        }
    }
    
    /**
     * Forçar sessão do WooCommerce
     */
    public function force_wc_session() {
        if (!is_admin() && !defined('DOING_AJAX')) {
            if (function_exists('WC') && WC()->session === null) {
                WC()->initialize_session();
            }
            
            if (WC()->session && !WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
        }
    }
    
    /**
     * Obter ID único do visitante
     */
    public function get_guest_id() {
        if (isset($_COOKIE['c2p_guest_id'])) {
            return $_COOKIE['c2p_guest_id'];
        }
        
        if (session_id()) {
            return 'sess_' . session_id();
        }
        
        if (WC()->session && WC()->session->get_customer_id()) {
            return 'wc_' . WC()->session->get_customer_id();
        }
        
        $guest_id = 'guest_' . md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'] . time());
        setcookie('c2p_guest_id', $guest_id, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        return $guest_id;
    }
    
    /**
     * Restaurar seleção no checkout
     */
    public function restore_selection_on_checkout() {
        $selected = null;
        
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (isset($_SESSION['c2p_selected_location'])) {
            $selected = $_SESSION['c2p_selected_location'];
        } elseif (isset($_COOKIE['c2p_location_data'])) {
            $selected = json_decode(stripslashes($_COOKIE['c2p_location_data']), true);
        } else {
            $guest_id = $this->get_guest_id();
            if ($guest_id) {
                $selected = get_transient('c2p_guest_' . $guest_id);
            }
        }
        
        if (!$selected && WC()->session) {
            $selected = WC()->session->get('c2p_selected_location');
        }
        
        if ($selected) {
            $_SESSION['c2p_selected_location'] = $selected;
            
            if (WC()->session) {
                WC()->session->set('c2p_selected_location', $selected);
                if (isset($selected['shipping_method'])) {
                    WC()->session->set('chosen_shipping_methods', array($selected['shipping_method']));
                }
            }
        }
    }
    
    /**
     * Verificar se tem seleção
     */
    public function has_selection() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        return isset($_SESSION['c2p_selected_location']) || 
               (WC()->session && WC()->session->get('c2p_selected_location'));
    }
    
    /**
     * Obter seleção atual
     */
    public function get_selection() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (isset($_SESSION['c2p_selected_location'])) {
            return $_SESSION['c2p_selected_location'];
        }
        
        if (WC()->session) {
            return WC()->session->get('c2p_selected_location');
        }
        
        return null;
    }
    
    /**
     * Salvar seleção em múltiplos locais
     */
    public function save_selection($selection_data) {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // 1. Sessão PHP
        $_SESSION['c2p_selected_location'] = $selection_data;
        
        // 2. Cookie
        $cookie_data = json_encode($selection_data);
        setcookie('c2p_location_data', $cookie_data, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        
        // 3. Transient
        $guest_id = $this->get_guest_id();
        set_transient('c2p_guest_' . $guest_id, $selection_data, 3600);
        
        // 4. Sessão WooCommerce
        if (WC()->session) {
            WC()->session->set('c2p_selected_location', $selection_data);
            if (isset($selection_data['shipping_method'])) {
                WC()->session->set('chosen_shipping_methods', array($selection_data['shipping_method']));
            }
            WC()->session->save_data();
        }
        
        // 5. User Meta (se logado)
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'c2p_last_selection', $selection_data);
        }
    }
    
    /**
     * Limpar seleção
     */
    public function clear_selection() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        unset($_SESSION['c2p_selected_location']);
        
        // Limpar cookie
        setcookie('c2p_location_data', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        
        // Limpar transient
        $guest_id = $this->get_guest_id();
        if ($guest_id) {
            delete_transient('c2p_guest_' . $guest_id);
        }
        
        // Limpar sessão WC
        if (WC()->session) {
            WC()->session->set('c2p_selected_location', null);
            WC()->session->set('chosen_shipping_methods', array());
        }
    }
    
    /**
     * Garantir sessão no checkout
     */
    public function ensure_checkout_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        
        $this->restore_selection_on_checkout();
    }
    
    /**
     * Sincronizar sessões
     */
    public function sync_sessions() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (isset($_SESSION['c2p_selected_location']) && !empty($_SESSION['c2p_selected_location'])) {
            if (WC()->session) {
                WC()->session->set('c2p_selected_location', $_SESSION['c2p_selected_location']);
                
                if (isset($_SESSION['c2p_selected_location']['shipping_method'])) {
                    $method = $_SESSION['c2p_selected_location']['shipping_method'];
                    WC()->session->set('chosen_shipping_methods', array($method));
                }
            }
        }
        elseif (WC()->session && WC()->session->get('c2p_selected_location')) {
            $_SESSION['c2p_selected_location'] = WC()->session->get('c2p_selected_location');
        }
    }
    
    /**
     * Sincronizar sessões no init
     */
    public function sync_sessions_on_init() {
        if (!is_admin() && (is_checkout() || is_cart())) {
            $this->sync_sessions();
        }
    }
    
    /**
     * Sincronizar sessões no checkout
     */
    public function sync_sessions_to_checkout() {
        $this->sync_sessions();
    }
    
    /**
     * Sincronizar shipping no checkout
     */
    public function sync_shipping_on_checkout() {
        $this->sync_sessions();
        
        if (WC()->session) {
            $selected = WC()->session->get('c2p_selected_location');
            if ($selected && isset($selected['shipping_method'])) {
                WC()->session->set('chosen_shipping_methods', array($selected['shipping_method']));
            }
        }
    }
    
    /**
     * Update checkout session
     */
    public function update_checkout_session($posted_data) {
        $this->sync_sessions();
        return $posted_data;
    }
    
}