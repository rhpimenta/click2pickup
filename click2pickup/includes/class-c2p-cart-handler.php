<?php
/**
 * Manipulador do Carrinho - Click2Pickup
 * Versão 18.2 - CORREÇÃO AJAX PARA GUESTS
 * 
 * @package Click2Pickup
 * @author RH Pimenta
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Cart_Handler {
    
    /**
     * Construtor
     */
    public function __construct() {
        // ADICIONAR: Sessões para guests
        add_action('init', array($this, 'setup_guest_session'), 1);
        add_action('wp_loaded', array($this, 'force_wc_session'), 1);
        
        // Garantir sessão no checkout
        add_action('woocommerce_checkout_init', array($this, 'ensure_checkout_session'), 1);
        
        // Limpar seleções ao chegar no carrinho
        add_action('woocommerce_before_cart', array($this, 'clear_selections_on_cart'), 1);
        
        // Adicionar seletor de local antes do carrinho
        add_action('woocommerce_before_cart', array($this, 'display_location_selector'), 5);
        
        // Adicionar aviso de itens removidos
        add_action('woocommerce_before_cart', array($this, 'display_removed_items_notice'), 10);
        
        // Esconder caixa padrão e adicionar CSS
        add_action('wp_head', array($this, 'hide_default_shipping_box'));
        
        // Adicionar linha de frete customizada
        add_action('woocommerce_after_cart_table', array($this, 'add_custom_shipping_row'));
        
        // Hook para adicionar frete ao total
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_shipping_to_cart_total'));
        
        // Processar seleção de local
        add_action('template_redirect', array($this, 'handle_location_selection'));
        
        // Adicionar estilos
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        
        // AJAX handlers - COM SUPORTE A GUESTS
        $ajax_actions = array(
            'c2p_select_location',
            'c2p_get_shipping_methods',
            'c2p_update_postcode',
            'c2p_update_shipping_method',
            'c2p_check_session'
        );
        
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_' . $action, array($this, 'ajax_' . str_replace('c2p_', '', $action)));
            add_action('wp_ajax_nopriv_' . $action, array($this, 'ajax_' . str_replace('c2p_', '', $action)));
        }
        
        // Hook para selecionar método correto no checkout
        add_filter('woocommerce_package_rates', array($this, 'select_correct_shipping_method'), 100, 2);
        add_filter('woocommerce_checkout_update_order_review', array($this, 'update_checkout_session'), 10);
        
        // Sincronizar sessões no checkout
        add_action('woocommerce_checkout_init', array($this, 'sync_sessions_to_checkout'));
        add_action('woocommerce_init', array($this, 'sync_sessions_on_init'));
        
        // Escolher método de envio correto
        add_filter('woocommerce_shipping_chosen_method', array($this, 'set_chosen_shipping_method'), 999, 3);
        
        // Inicializar seleção de shipping
        $this->init_shipping_selection();
    }
    
    /**
     * NOVO: Configurar sessão para guests
     */
    public function setup_guest_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // Criar ID único para guest se não existir
        if (!is_user_logged_in()) {
            if (!isset($_COOKIE['c2p_guest_id'])) {
                $guest_id = 'guest_' . md5(uniqid('', true));
                setcookie('c2p_guest_id', $guest_id, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                $_COOKIE['c2p_guest_id'] = $guest_id;
            }
        }
    }
    
    /**
     * NOVO: Forçar sessão WC para guests
     */
    public function force_wc_session() {
        if (is_admin() || defined('DOING_AJAX')) {
            return;
        }
        
        if (function_exists('WC') && WC()->session === null) {
            WC()->initialize_session();
        }
        
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }
    
    /**
     * NOVO: Obter ID do guest
     */
    private function get_guest_id() {
        if (isset($_COOKIE['c2p_guest_id'])) {
            return $_COOKIE['c2p_guest_id'];
        }
        
        if (session_id()) {
            return 'sess_' . session_id();
        }
        
        if (WC()->session && WC()->session->get_customer_id()) {
            return 'wc_' . WC()->session->get_customer_id();
        }
        
        // Fallback
        $guest_id = 'guest_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '') . time());
        setcookie('c2p_guest_id', $guest_id, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        return $guest_id;
    }
    
    /**
     * Garantir sessão no checkout
     */
    public function ensure_checkout_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // Garantir WC Session
        if (function_exists('WC') && WC()->session === null) {
            WC()->initialize_session();
        }
        
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        
        $has_data = false;
        
        if (isset($_SESSION['c2p_selected_location'])) {
            $data = $_SESSION['c2p_selected_location'];
            $has_data = true;
        } elseif (isset($_COOKIE['c2p_location_data'])) {
            $data = json_decode(stripslashes($_COOKIE['c2p_location_data']), true);
            if (is_array($data)) {
                $has_data = true;
                $_SESSION['c2p_selected_location'] = $data;
            }
        } else {
            $guest_id = $this->get_guest_id();
            $data = get_transient('c2p_guest_' . $guest_id);
            if ($data) {
                $has_data = true;
                $_SESSION['c2p_selected_location'] = $data;
            }
        }
        
        if ($has_data && WC()->session) {
            WC()->session->set('c2p_selected_location', $data);
            if (isset($data['shipping_method'])) {
                WC()->session->set('chosen_shipping_methods', array($data['shipping_method']));
            }
        }
    }
    
    /**
     * Limpar seleções ao chegar no carrinho
     */
    public function clear_selections_on_cart() {
        if (!is_cart()) {
            return;
        }
        
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (!isset($_SESSION['c2p_selected_location'])) {
            if (WC()->session) {
                WC()->session->set('chosen_shipping_methods', array());
                WC()->session->set('c2p_selected_location', null);
            }
        }
    }
    
    /**
     * Esconder caixa de shipping padrão e adicionar CSS customizado
     */
    public function hide_default_shipping_box() {
        if (!is_cart()) {
            return;
        }
        ?>
        <style>
            /* Esconder caixa padrão de shipping do WooCommerce */
            .woocommerce-shipping-totals.shipping {
                display: none !important;
            }
            
            /* Esconder calculadora de frete padrão */
            .woocommerce-shipping-calculator {
                display: none !important;
            }
            
            /* Esconder o título "Entrega" se não houver seleção */
            .cart-subtotal + tr.woocommerce-shipping-totals {
                display: none !important;
            }
            
            /* Esconder botão calcular frete padrão */
            .shipping-calculator-button {
                display: none !important;
            }
            
            /* Estilizar linha do frete quando selecionado via C2P */
            .c2p-shipping-total {
                border-top: 1px solid #e0e0e0;
            }
            
            .c2p-shipping-total th {
                font-weight: normal;
                padding: 10px;
            }
            
            .c2p-shipping-total td {
                text-align: right;
                padding: 10px;
            }
            
            .c2p-shipping-method {
                color: #667eea;
                font-weight: 600;
            }
            
            .c2p-shipping-amount {
                margin-left: 10px;
            }
            
            .c2p-shipping-amount.free {
                color: #4caf50;
                font-weight: 700;
            }
            
            /* Estilos para mensagens de erro */
            .c2p-no-locations-notice,
            .c2p-no-shipping-methods,
            .c2p-no-stores-message {
                background: linear-gradient(135deg, #ffeaa7 0%, #fdcb6e 100%);
                padding: 30px;
                border-radius: 15px;
                text-align: center;
                color: #2d3436;
                margin: 20px 0;
            }
            
            .c2p-no-locations-notice h3,
            .c2p-no-shipping-methods h3 {
                color: #2d3436;
                margin: 0 0 10px 0;
                font-size: 24px;
            }
            
            .c2p-no-locations-notice p,
            .c2p-no-shipping-methods p {
                margin: 10px 0;
                font-size: 16px;
            }
            
            .c2p-no-stores-message {
                padding: 20px;
                font-size: 16px;
            }
        </style>
        <?php
    }
    
    /**
     * Adicionar linha de frete customizada na tabela do carrinho
     */
    public function add_custom_shipping_row() {
        if (!is_cart()) {
            return;
        }
        
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
        
        if (!$selected) {
            return;
        }
              // Obter custo do frete dinamicamente
        $shipping_cost = 0;
        $shipping_label = '';
        
        if ($selected['delivery_type'] === 'pickup') {
            $shipping_label = '🏪 ' . esc_html__('Retirada na Loja', 'click2pickup') . ' - ' . esc_html($selected['name']);
            $shipping_cost = 0; // Grátis
        } else {
            // Obter o método de envio escolhido DINAMICAMENTE
            $packages = WC()->shipping()->get_packages();
            if (!empty($packages)) {
                $package = reset($packages);
                if (isset($package['rates'])) {
                    $available_methods = $package['rates'];
                    $selected_method = isset($selected['shipping_method']) ? $selected['shipping_method'] : '';
                    
                    // Buscar o método selecionado DINAMICAMENTE
                    foreach ($available_methods as $method_id => $method) {
                        if ($method_id === $selected_method) {
                            $shipping_label = '🚚 ' . $method->label;
                            $shipping_cost = floatval($method->cost);
                            break;
                        }
                    }
                }
            }
        }
        
        if ($shipping_label) {
            ?>
            <script type="text/javascript">
            jQuery(function($) {
                // Aguardar a tabela do carrinho carregar
                setTimeout(function() {
                    // Verificar se já não foi adicionado
                    if ($('.c2p-shipping-total').length === 0) {
                        var shippingRow = '<tr class="c2p-shipping-total">';
                        shippingRow += '<th><?php echo esc_js(esc_html__('Entrega', 'click2pickup')); ?></th>';
                        shippingRow += '<td data-title="<?php echo esc_attr(esc_html__('Entrega', 'click2pickup')); ?>">';
                        shippingRow += '<span class="c2p-shipping-method"><?php echo esc_js($shipping_label); ?></span>';
                        shippingRow += '<span class="c2p-shipping-amount';
                        <?php if ($shipping_cost == 0) : ?>
                        shippingRow += ' free">GRÁTIS';
                        <?php else : ?>
                        shippingRow += '"> <?php echo esc_js(wc_price($shipping_cost)); ?>';
                        <?php endif; ?>
                        shippingRow += '</span>';
                        shippingRow += '</td>';
                        shippingRow += '</tr>';
                        
                        // Inserir após o subtotal
                        $('.cart-subtotal').after(shippingRow);
                    }
                }, 500);
            });
            </script>
            <?php
        }
    }
    
    /**
     * Adicionar frete ao total do carrinho
     */
    public function add_shipping_to_cart_total() {
        if (!is_cart()) {
            return;
        }
        
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
        
        if (!$selected || $selected['delivery_type'] === 'pickup') {
            return; // Sem custo para pickup
        }
        
        // Obter custo do método selecionado DINAMICAMENTE
        $shipping_cost = 0;
        $selected_method = isset($selected['shipping_method']) ? $selected['shipping_method'] : '';
        
        if ($selected_method) {
            // Buscar o custo real do método DINAMICAMENTE
            $packages = WC()->shipping()->get_packages();
            
            if (!empty($packages)) {
                foreach ($packages as $package) {
                    if (isset($package['rates']) && isset($package['rates'][$selected_method])) {
                        $shipping_cost = floatval($package['rates'][$selected_method]->cost);
                        break;
                    }
                }
            }
            
            // Se encontrou custo, adicionar como taxa (isso soma ao total)
            if ($shipping_cost > 0) {
                WC()->cart->add_fee(esc_html__('Frete', 'click2pickup'), $shipping_cost);
            }
        }
    }
    
    /**
     * NOVO: AJAX - Verificar sessão
     */
    public function ajax_check_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        $has_selection = false;
        $selection_data = null;
        
        if (isset($_SESSION['c2p_selected_location'])) {
            $has_selection = true;
            $selection_data = $_SESSION['c2p_selected_location'];
        } elseif (isset($_COOKIE['c2p_location_data'])) {
            $data = json_decode(stripslashes($_COOKIE['c2p_location_data']), true);
            if (is_array($data)) {
                $has_selection = true;
                $selection_data = $data;
            }
        } else {
            $guest_id = $this->get_guest_id();
            $data = get_transient('c2p_guest_' . $guest_id);
            if ($data) {
                $has_selection = true;
                $selection_data = $data;
            }
        }
        
        wp_send_json_success(array(
            'has_selection' => $has_selection,
            'selection' => $selection_data,
            'guest_id' => $this->get_guest_id(),
            'session_id' => session_id(),
            'is_logged_in' => is_user_logged_in()
        ));
    }
    
    /**
     * AJAX - Seleção de local CORRIGIDO PARA GUESTS
     */
    public function ajax_select_location() {
        error_log('=== C2P DEBUG: ajax_select_location INICIADO ===');
        
        // CORREÇÃO: Verificar nonce apenas para usuários logados
        if (is_user_logged_in()) {
            if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'c2p_cart_nonce')) {
                wp_send_json_error('Nonce inválido para usuário logado');
            }
        }
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $delivery_type = isset($_POST['delivery_type']) ? sanitize_text_field($_POST['delivery_type']) : 'pickup';
        $shipping_method = isset($_POST['shipping_method']) ? sanitize_text_field($_POST['shipping_method']) : '';
        
        error_log('C2P DEBUG - Location ID: ' . $location_id);
        error_log('C2P DEBUG - Delivery Type: ' . $delivery_type);
        error_log('C2P DEBUG - Is Logged In: ' . (is_user_logged_in() ? 'YES' : 'NO'));
        
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // Limpar seleção se location_id = 0
        if ($location_id == 0) {
            unset($_SESSION['c2p_selected_location']);
            
            // Limpar cookie
            setcookie('c2p_location_data', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            
            // Limpar transient
            $guest_id = $this->get_guest_id();
            if ($guest_id) {
                delete_transient('c2p_guest_' . $guest_id);
            }
            
            if (WC()->session) {
                WC()->session->set('c2p_selected_location', null);
                WC()->session->set('chosen_shipping_methods', array());
            }
            
            wp_send_json_success(array(
                'redirect' => wc_get_cart_url()
            ));
        }
        
        global $wpdb;
        $locations_table = $wpdb->prefix . 'c2p_locations';
        
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $locations_table WHERE id = %d AND is_active = 1",
            $location_id
        ));
        
        if (!$location) {
            wp_send_json_error('Local não encontrado');
        }
        
        // Para pickup, buscar o método correto
        if ($delivery_type === 'pickup') {
            $shipping_method = $this->get_pickup_method_for_location($location);
            error_log('C2P DEBUG - Método de pickup obtido: ' . $shipping_method);
        }
        
        $selection_data = array(
            'id' => (int) $location->id,
            'name' => (string) $location->name,
            'type' => (string) $location->type,
            'address' => (string) $location->address,
            'city' => (string) $location->city,
            'state' => (string) $location->state,
            'postcode' => (string) $location->postcode,
            'delivery_type' => (string) $delivery_type,
            'shipping_method' => (string) $shipping_method,
            'selected_at' => current_time('mysql'),
            'guest_id' => $this->get_guest_id()
        );
        
        // 1. Salvar na sessão PHP
        $_SESSION['c2p_selected_location'] = $selection_data;
        
        // 2. Salvar em cookie (para guests)
        setcookie('c2p_location_data', 
            wp_json_encode($selection_data), 
            time() + 3600, 
            COOKIEPATH, 
            COOKIE_DOMAIN, 
            is_ssl(), 
            true
        );
        
        // 3. Salvar em transient (backup)
        $guest_id = $this->get_guest_id();
        if ($guest_id) {
            set_transient('c2p_guest_' . $guest_id, $selection_data, 3600);
        }
        
        // 4. Salvar na sessão WC
        if (WC()->session) {
            WC()->session->set('c2p_selected_location', $selection_data);
            WC()->session->set('chosen_shipping_methods', array($shipping_method));
            WC()->session->save_data();
        }
        
        // 5. Se logado, salvar em user meta
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'c2p_last_selection', $selection_data);
        }
        
        error_log('=== C2P DEBUG: Seleção salva com sucesso ===');
        
        $validation_result = $this->validate_cart_for_location($location_id);
        
        wp_send_json_success(array(
            'message' => 'Local selecionado com sucesso',
            'redirect' => wc_get_checkout_url(),
            'selection' => $selection_data,
            'validation' => $validation_result,
            'guest_id' => $guest_id
        ));
    }
    
    /**
     * MÉTODO CORRIGIDO: Busca o método de pickup correto para a loja NO BANCO
     * COM PROCESSAMENTO DO PREFIXO DE ZONA
     */
    private function get_pickup_method_for_location($location) {
        error_log('C2P DEBUG - Buscando método para loja: ' . $location->name);
        error_log('C2P DEBUG - Cidade: ' . $location->city);
        error_log('C2P DEBUG - Estado: ' . $location->state);
              // 1. Primeiro, buscar métodos configurados para esta loja no banco
        $configured_methods = array();
        if (!empty($location->shipping_methods)) {
            $configured_methods = json_decode($location->shipping_methods, true);
            error_log('C2P DEBUG - Métodos configurados no banco: ' . print_r($configured_methods, true));
        }
        
        // 2. Buscar todos os métodos de pickup disponíveis no WooCommerce
        $available_pickup_methods = array();
        
        if (class_exists('WC_Shipping_Zones')) {
            $zones = WC_Shipping_Zones::get_zones();
            // Adicionar zona padrão
            $zones[] = array('zone_id' => 0);
            
            foreach ($zones as $zone_data) {
                $zone_id = isset($zone_data['zone_id']) ? $zone_data['zone_id'] : (isset($zone_data['id']) ? $zone_data['id'] : 0);
                $zone = new WC_Shipping_Zone($zone_id);
                
                foreach ($zone->get_shipping_methods(true) as $method) {
                    if (!$method->is_enabled()) continue;
                    
                    $method_id = $method->id . ':' . $method->get_instance_id();
                    $method_title = $method->get_title();
                    
                    // Verificar se é método de pickup
                    if ($method->id === 'local_pickup' ||
                        strpos(strtolower($method_title), 'retira') !== false ||
                        strpos(strtolower($method_title), 'pickup') !== false ||
                        strpos(strtolower($method_title), 'retirar') !== false) {
                        
                        $available_pickup_methods[$method_id] = array(
                            'id' => $method_id,
                            'title' => $method_title,
                            'zone_id' => $zone_id
                        );
                        
                        error_log('C2P DEBUG - Método pickup encontrado: ' . $method_id . ' (' . $method_title . ')');
                    }
                }
            }
        }
        
        // 3. CORREÇÃO PRINCIPAL: Processar métodos configurados removendo prefixo de zona
        if (!empty($configured_methods)) {
            foreach ($configured_methods as $configured_method) {
                error_log('C2P DEBUG - Processando método configurado: ' . $configured_method);
                
                // Remover prefixo de zona se existir (formato: "1:local_pickup:5" → "local_pickup:5")
                $clean_method = $configured_method;
                if (preg_match('/^\d+:(.+)$/', $configured_method, $matches)) {
                    $clean_method = $matches[1];
                    error_log('C2P DEBUG - Método limpo (sem prefixo de zona): ' . $clean_method);
                }
                
                // Verificar se o método limpo é de pickup
                if (isset($available_pickup_methods[$clean_method])) {
                    error_log('C2P DEBUG - Match EXATO encontrado: ' . $clean_method);
                    return $clean_method;
                }
                
                // Tentar match parcial com o método limpo
                foreach ($available_pickup_methods as $pickup_id => $pickup_data) {
                    // Comparar apenas a parte do instance_id
                    $configured_instance = explode(':', $clean_method);
                    $pickup_instance = explode(':', $pickup_id);
                    
                    if (count($configured_instance) > 1 && count($pickup_instance) > 1) {
                        if ($configured_instance[1] === $pickup_instance[1]) {
                            error_log('C2P DEBUG - Match por instance_id: ' . $pickup_id);
                            return $pickup_id;
                        }
                    }
                }
            }
        }
        
        // 4. FALLBACK: Se não achou pelos métodos configurados, tentar pela lógica de estado/cidade
        $state = strtoupper($location->state);
        $city_lower = strtolower($location->city);
        
        error_log('C2P DEBUG - Nenhum match no banco, tentando por estado: ' . $state);
        
        // Mapeamento DIRETO de estados para IDs específicos
        $state_to_pickup_id = array(
            'SP' => 'local_pickup:4', // Retira SP
            'MG' => 'local_pickup:3', // Retira BH  
            'RJ' => 'local_pickup:5', // Retira RJ
            'ES' => 'local_pickup:5'  // ES usa RJ por proximidade
        );
        
        // Buscar método baseado no estado DIRETO
        if (isset($state_to_pickup_id[$state])) {
            $preferred_method = $state_to_pickup_id[$state];
            
            // Verificar se o método preferido existe
            if (isset($available_pickup_methods[$preferred_method])) {
                error_log('C2P DEBUG - Match por estado (mapeamento direto): ' . $preferred_method);
                return $preferred_method;
            }
        }
        
        // 5. Buscar por palavras-chave no título do método
        $state_mapping = array(
            'SP' => array('sp', 'paulo', 'são paulo'),
            'MG' => array('bh', 'horizonte', 'belo', 'minas'),
            'RJ' => array('rj', 'rio', 'janeiro'),
            'ES' => array('rj', 'rio', 'janeiro') // ES usa keywords do RJ
        );
        
        if (isset($state_mapping[$state])) {
            $keywords = $state_mapping[$state];
            
            foreach ($available_pickup_methods as $method_id => $method_data) {
                $title_lower = strtolower($method_data['title']);
                
                foreach ($keywords as $keyword) {
                    if (strpos($title_lower, $keyword) !== false) {
                        error_log('C2P DEBUG - Match por keyword no título: ' . $method_id . ' (keyword: ' . $keyword . ')');
                        return $method_id;
                    }
                }
            }
        }
        
        // 6. Se ainda não encontrou, buscar por cidade
        foreach ($available_pickup_methods as $method_id => $method_data) {
            $title_lower = strtolower($method_data['title']);
            
            if (!empty($city_lower)) {
                $city_parts = explode(' ', $city_lower);
                foreach ($city_parts as $part) {
                    if (strlen($part) > 2 && strpos($title_lower, $part) !== false) {
                        error_log('C2P DEBUG - Match por cidade: ' . $method_id . ' (parte: ' . $part . ')');
                        return $method_id;
                    }
                }
            }
        }
        
        // 7. Se não encontrou específico, retornar o primeiro método de pickup disponível
        if (!empty($available_pickup_methods)) {
            $first_method = array_keys($available_pickup_methods)[0];
            error_log('C2P DEBUG - Usando primeiro método disponível: ' . $first_method);
            return $first_method;
        }
        
        // 8. Fallback final
        error_log('C2P DEBUG - Usando fallback padrão: local_pickup');
        return 'local_pickup';
    }
      
    /**
     * Escolher método de envio correto baseado na seleção
     */
    public function set_chosen_shipping_method($method, $rates, $package) {
        if (!WC()->session) {
            return $method;
        }
        
        $selected = WC()->session->get('c2p_selected_location');
        
        if (!$selected || !isset($selected['delivery_type'])) {
            return $method;
        }
        
        // Usar o método EXATO que foi selecionado/configurado
        if (isset($selected['shipping_method']) && !empty($selected['shipping_method'])) {
            $selected_method = $selected['shipping_method'];
            
            // Verificar se o método existe nas rates disponíveis
            if (isset($rates[$selected_method])) {
                error_log('C2P DEBUG - Método escolhido: ' . $selected_method);
                return $selected_method;
            }
            
            // Tentar match parcial se o método exato não foi encontrado
            foreach ($rates as $rate_id => $rate) {
                if (strpos($rate_id, explode(':', $selected_method)[0]) !== false) {
                    error_log('C2P DEBUG - Método escolhido (match parcial): ' . $rate_id);
                    return $rate_id;
                }
            }
        }
        
        return $method;
    }
    
    /**
     * Selecionar método correto no filtro de rates
     */
    public function select_correct_shipping_method($rates, $package) {
        if (!WC()->session) {
            return $rates;
        }
        
        $selected = WC()->session->get('c2p_selected_location');
        
        if (!$selected) {
            if (!session_id() && !headers_sent()) {
                @session_start();
            }
            $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
            if ($selected && WC()->session) {
                WC()->session->set('c2p_selected_location', $selected);
            }
        }
        
        if (!$selected || !isset($selected['delivery_type'])) {
            return $rates;
        }
        
        // Usar o método EXATO configurado
        if (isset($selected['shipping_method']) && !empty($selected['shipping_method'])) {
            $selected_method = $selected['shipping_method'];
            
            if (isset($rates[$selected_method])) {
                if (WC()->session) {
                    WC()->session->set('chosen_shipping_methods', array($selected_method));
                    error_log('C2P DEBUG - Método definido na sessão: ' . $selected_method);
                }
            }
        }
        
        return $rates; // Sempre retornar TODOS os rates
    }
    
    /**
     * JavaScript para seleção no checkout
     */
    public function shipping_selection_javascript() {
        if (!is_checkout()) {
            return;
        }
        
        if (!WC()->session) {
            return;
        }
        
        $selected = WC()->session->get('c2p_selected_location');
        if (!$selected) {
            if (!session_id() && !headers_sent()) {
                @session_start();
            }
            $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
        }
        
        if (!$selected || !isset($selected['delivery_type'])) {
            return;
        }
        
        $delivery_type = isset($selected['delivery_type']) ? $selected['delivery_type'] : '';
        $selected_method = isset($selected['shipping_method']) ? $selected['shipping_method'] : '';
        $store_name = isset($selected['name']) ? $selected['name'] : '';
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            console.log('🚀 C2P: Configuração de shipping', {
                type: '<?php echo esc_js($delivery_type); ?>',
                method: '<?php echo esc_js($selected_method); ?>',
                store: '<?php echo esc_js($store_name); ?>'
            });
                      function selectC2PShipping() {
                var selectedMethod = '<?php echo esc_js($selected_method); ?>';
                
                if (!selectedMethod) {
                    console.log('⚠️ Nenhum método selecionado');
                    return;
                }
                
                var $inputs = $('input[name^="shipping_method"]');
                
                console.log('📋 Procurando método:', selectedMethod);
                console.log('📋 Métodos disponíveis:', $inputs.map(function() { return $(this).val(); }).get());
                
                // Buscar método exato
                var found = false;
                $inputs.each(function() {
                    var $input = $(this);
                    var value = $input.val();
                    
                    if (value === selectedMethod) {
                        if (!$input.is(':checked')) {
                            $input.prop('checked', true).trigger('change');
                            console.log('✅ Método selecionado:', value);
                        }
                        found = true;
                        return false;
                    }
                });
                
                if (!found) {
                    console.log('⚠️ Método exato não encontrado, tentando match parcial...');
                    
                    // Tentar match parcial
                    $inputs.each(function() {
                        var $input = $(this);
                        var value = $input.val();
                        
                        // Verificar se o ID do método contém a parte relevante
                        var methodParts = selectedMethod.split(':');
                        var valueParts = value.split(':');
                        
                        if (methodParts.length > 1 && valueParts.length > 1) {
                            // Comparar instance_id
                            if (methodParts[1] === valueParts[1]) {
                                if (!$input.is(':checked')) {
                                    $input.prop('checked', true).trigger('change');
                                    console.log('✅ Método selecionado (match por instance):', value);
                                }
                                found = true;
                                return false;
                            }
                        }
                    });
                }
                
                if (!found) {
                    console.log('❌ Método não encontrado:', selectedMethod);
                }
            }
            
            // Executar imediatamente
            selectC2PShipping();
            
            // Re-executar após updates do checkout
            $(document.body).on('updated_checkout update_checkout', function() {
                console.log('🔄 Checkout atualizado, re-selecionando...');
                setTimeout(selectC2PShipping, 500);
            });
            
            // Forçar atualização a cada 2 segundos nos primeiros 10 segundos
            var updateCount = 0;
            var forceUpdateInterval = setInterval(function() {
                updateCount++;
                selectC2PShipping();
                if (updateCount >= 5) {
                    clearInterval(forceUpdateInterval);
                }
            }, 2000);
            
            // Adicionar informação visual
            if (!$('.c2p-shipping-info').length) {
                var infoHtml = '<div class="c2p-shipping-info" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; margin: 20px 0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px rgba(102, 126, 234, 0.2);">';
                infoHtml += '<div>';
                infoHtml += '<strong style="font-size: 16px;">📍 <?php echo esc_js($store_name); ?></strong><br>';
                infoHtml += '<span style="font-size: 14px;"><?php echo $delivery_type === 'pickup' ? '🏪 Retirada na Loja' : '🚚 Entrega em Domicílio'; ?></span>';
                
                <?php if (!empty($selected['address'])) : ?>
                infoHtml += '<br><small style="opacity: 0.9;"><?php echo esc_js($selected['address']); ?></small>';
                <?php endif; ?>
                
                infoHtml += '</div>';
                infoHtml += '<a href="<?php echo wc_get_cart_url(); ?>" style="background: white; color: #667eea; padding: 8px 20px; border-radius: 5px; text-decoration: none; font-weight: 600; transition: all 0.3s;">Alterar</a>';
                infoHtml += '</div>';
                
                $('.woocommerce-checkout-review-order').before(infoHtml);
            }
        });
        </script>
        <?php
    }
    
    /**
     * Inicializar sistema de seleção
     */
    private function init_shipping_selection() {
        add_action('woocommerce_checkout_init', array($this, 'sync_shipping_on_checkout'), 9999);
        add_action('wp_footer', array($this, 'shipping_selection_javascript'), 9999);
    }
    
    /**
     * Sincronizar sessões
     */
    public function sync_sessions_on_init() {
        if (!is_admin() && (is_checkout() || is_cart())) {
            $this->sync_sessions();
        }
    }
    
    public function sync_sessions_to_checkout() {
        $this->sync_sessions();
    }
    
    private function sync_sessions() {
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
        } elseif (WC()->session && WC()->session->get('c2p_selected_location')) {
            $_SESSION['c2p_selected_location'] = WC()->session->get('c2p_selected_location');
        }
    }
    
    public function sync_shipping_on_checkout() {
        $this->sync_sessions();
        
        if (WC()->session) {
            $selected = WC()->session->get('c2p_selected_location');
            if ($selected && isset($selected['shipping_method'])) {
                WC()->session->set('chosen_shipping_methods', array($selected['shipping_method']));
            }
        }
    }
    
    public function update_checkout_session($posted_data) {
        $this->sync_sessions();
        return $posted_data;
    }
    
    public function handle_location_selection() {
        return;
    }
    
    /**
     * AJAX handler para atualizar método de shipping CORRIGIDO PARA GUESTS
     */
    public function ajax_update_shipping_method() {
        // CORREÇÃO: Verificar nonce apenas para usuários logados
        if (is_user_logged_in()) {
            if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'c2p_update_method')) {
                wp_send_json_error('Nonce inválido');
            }
        }
        
        $method = sanitize_text_field($_POST['method'] ?? '');
        
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // Atualizar na sessão PHP
        if (isset($_SESSION['c2p_selected_location'])) {
            $_SESSION['c2p_selected_location']['shipping_method'] = $method;
        }
        
        // Atualizar no cookie
        if (isset($_COOKIE['c2p_location_data'])) {
            $data = json_decode(stripslashes($_COOKIE['c2p_location_data']), true);
            if (is_array($data)) {
                $data['shipping_method'] = $method;
                setcookie('c2p_location_data', 
                    wp_json_encode($data), 
                    time() + 3600, 
                    COOKIEPATH, 
                    COOKIE_DOMAIN, 
                    is_ssl(), 
                    true
                );
            }
        }
        
        // Atualizar no transient
        $guest_id = $this->get_guest_id();
        if ($guest_id) {
            $data = get_transient('c2p_guest_' . $guest_id);
            if (is_array($data)) {
                $data['shipping_method'] = $method;
                set_transient('c2p_guest_' . $guest_id, $data, 3600);
            }
        }
        
        // Atualizar na sessão WC
        if (WC()->session) {
            $selected = WC()->session->get('c2p_selected_location');
            if ($selected) {
                $selected['shipping_method'] = $method;
                WC()->session->set('c2p_selected_location', $selected);
                WC()->session->set('chosen_shipping_methods', array($method));
            }
        }
        
        wp_send_json_success();
    }
    
    /**
     * AJAX handler para atualizar CEP CORRIGIDO PARA GUESTS
     */
    public function ajax_update_postcode() {
        // CORREÇÃO: Verificar nonce apenas para usuários logados
        if (is_user_logged_in()) {
            if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'c2p_cart_nonce')) {
                wp_send_json_error('Nonce inválido');
            }
        }
        
        $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';
        $postcode = preg_replace('/[^0-9]/', '', $postcode);
        
        // Validar CEP
        if (strlen($postcode) !== 8) {
            wp_send_json_error(esc_html__('CEP inválido', 'click2pickup'));
        }
        
        // Atualizar CEP do cliente no WooCommerce
        if (WC()->customer) {
            WC()->customer->set_shipping_postcode($postcode);
            WC()->customer->set_billing_postcode($postcode);
            WC()->customer->save();
        }
        
        // Atualizar sessão
        if (WC()->session) {
            $customer = WC()->session->get('customer', array());
            $customer['postcode'] = $postcode;
            $customer['shipping_postcode'] = $postcode;
            $customer['billing_postcode'] = $postcode;
            WC()->session->set('customer', $customer);
        }
        
        // Forçar recálculo de shipping
        WC()->shipping()->reset_shipping();
        
        wp_send_json_success(array(
            'postcode' => $postcode,
            'message' => esc_html__('CEP atualizado com sucesso', 'click2pickup')
        ));
    }
      /**
     * AJAX - Obter métodos de envio DINAMICAMENTE do WooCommerce CORRIGIDO PARA GUESTS
     */
    public function ajax_get_shipping_methods() {
        // CORREÇÃO: Verificar nonce apenas para usuários logados
        if (is_user_logged_in()) {
            if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'c2p_cart_nonce')) {
                wp_send_json_error('Nonce inválido');
            }
        }
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        
        // Buscar dados do local (CD)
        global $wpdb;
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $locations_table WHERE id = %d AND is_active = 1",
            $location_id
        ));
        
        if (!$location) {
            wp_send_json_error('Local não encontrado');
        }
        
        // Arrays para armazenar métodos
        $available_methods = array();
        
        // Obter CEP do cliente
        $customer_postcode = '';
        if (WC()->customer) {
            $customer_postcode = WC()->customer->get_shipping_postcode();
        }
        
        if (empty($customer_postcode)) {
            wp_send_json_error(esc_html__('Por favor, digite seu CEP primeiro', 'click2pickup'));
        }
        
        // Obter total do carrinho para verificar frete grátis
        $cart_total = WC()->cart->get_displayed_subtotal();
        
        // Criar pacote para cálculo REAL de shipping
        $package = array(
            'destination' => array(
                'country' => 'BR',
                'state' => '',
                'postcode' => $customer_postcode,
                'city' => '',
                'address' => '',
                'address_2' => ''
            ),
            'contents' => WC()->cart->get_cart(),
            'contents_cost' => WC()->cart->get_cart_contents_total(),
            'applied_coupons' => WC()->cart->get_applied_coupons(),
            'user' => array('ID' => get_current_user_id())
        );
        
        // Forçar recálculo de shipping
        WC()->shipping()->reset_shipping();
        $packages = WC()->cart->get_shipping_packages();
        $packages[0] = $package;
        
        // Calcular shipping para o pacote
        $calculated_packages = WC()->shipping()->calculate_shipping($packages);
        
        // Obter rates calculados
        if (!empty($calculated_packages) && isset($calculated_packages[0]['rates'])) {
            foreach ($calculated_packages[0]['rates'] as $rate_id => $rate) {
                // Pular métodos de retirada
                if (strpos($rate_id, 'local_pickup') !== false ||
                    strpos(strtolower($rate->label), 'retira') !== false ||
                    strpos(strtolower($rate->label), 'pickup') !== false) {
                    continue;
                }
                
                // Verificação especial para frete grátis
                if ($rate->method_id === 'free_shipping') {
                    // Verificar se realmente está disponível
                    $instance_id = str_replace('free_shipping:', '', $rate_id);
                    $free_shipping_settings = get_option('woocommerce_free_shipping_' . $instance_id . '_settings');
                    
                    if ($free_shipping_settings) {
                        $requires = isset($free_shipping_settings['requires']) ? $free_shipping_settings['requires'] : '';
                        $min_amount = isset($free_shipping_settings['min_amount']) ? floatval($free_shipping_settings['min_amount']) : 0;
                        
                        // Verificar condições
                        $is_eligible = false;
                        
                        if ($requires === 'min_amount' || $requires === 'either') {
                            if ($cart_total >= $min_amount) {
                                $is_eligible = true;
                            }
                        } elseif ($requires === 'coupon' || $requires === 'both') {
                            $has_free_shipping_coupon = false;
                            foreach (WC()->cart->get_applied_coupons() as $coupon_code) {
                                $coupon = new WC_Coupon($coupon_code);
                                if ($coupon->get_free_shipping()) {
                                    $has_free_shipping_coupon = true;
                                    break;
                                }
                            }
                            if ($has_free_shipping_coupon) {
                                $is_eligible = true;
                            }
                        } elseif (empty($requires) || $requires === '') {
                            $is_eligible = true;
                        }
                        
                        if (!$is_eligible) {
                            continue;
                        }
                    }
                }
                
                // Adicionar método disponível
                $available_methods[] = array(
                    'id' => $rate->id,
                    'title' => $rate->label,
                    'cost' => floatval($rate->cost),
                    'description' => $rate->method_id,
                    'delivery_time' => $this->get_dynamic_delivery_time($rate),
                    'is_free' => (floatval($rate->cost) == 0)
                );
            }
        }
        
        // Se não encontrou métodos
        if (empty($available_methods)) {
            ob_start();
            ?>
            <div class="c2p-no-shipping-methods">
                <span style="font-size: 48px; display: block; margin-bottom: 15px;">😔</span>
                <h3><?php echo esc_html__('Desculpe!', 'click2pickup'); ?></h3>
                <p><?php echo esc_html__('Não há métodos de entrega disponíveis para o CEP informado.', 'click2pickup'); ?></p>
                <p><small><?php echo esc_html__('Verifique se o CEP está correto ou entre em contato conosco.', 'click2pickup'); ?></small></p>
            </div>
            <?php
            $html = ob_get_clean();
            
            wp_send_json_success(array(
                'html' => $html,
                'no_methods' => true
            ));
            return;
        }
        
        // Renderizar HTML dos métodos encontrados
        ob_start();
        foreach ($available_methods as $method) {
            $this->render_shipping_method_card($method);
        }
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'methods_count' => count($available_methods)
        ));
    }
    
    /**
     * Obtém tempo de entrega DINÂMICO para rate
     */
    private function get_dynamic_delivery_time($rate) {
        // Verificar meta data do rate primeiro
        if (isset($rate->meta_data['delivery_time'])) {
            return $rate->meta_data['delivery_time'];
        }
        
        // Verificar por padrões conhecidos no nome/ID
        $method_string = strtolower($rate->id . ' ' . $rate->label);
        
        // Padrões comuns de métodos
        $patterns = array(
            'sedex' => '1-2 dias úteis',
            'expresso' => '1-2 dias úteis',
            'express' => '1-2 dias úteis',
            'pac' => '5-8 dias úteis',
            'econom' => '7-12 dias úteis',
            'normal' => '3-5 dias úteis',
            'padrão' => '3-5 dias úteis',
            'standard' => '3-5 dias úteis',
            'free' => '5-10 dias úteis',
            'grátis' => '5-10 dias úteis',
            'gratuito' => '5-10 dias úteis'
        );
        
        foreach ($patterns as $pattern => $time) {
            if (strpos($method_string, $pattern) !== false) {
                return $time;
            }
        }
        
        // Padrão genérico
        return '3-7 dias úteis';
    }
    
    /**
     * Adiciona estilos e scripts
     */
    public function enqueue_styles() {
        if (!is_cart()) {
            return;
        }
        
        // Registrar e enfileirar CSS
        wp_register_style(
            'c2p-cart',
            C2P_PLUGIN_URL . 'assets/css/cart.css',
            array(),
            C2P_VERSION
        );
        wp_enqueue_style('c2p-cart');
        
        // Registrar e enfileirar JavaScript
        wp_register_script(
            'c2p-cart',
            C2P_PLUGIN_URL . 'assets/js/cart.js',
            array('jquery'),
            C2P_VERSION,
            true
        );
        
        // Localizar script com dados necessários
        wp_localize_script('c2p-cart', 'c2p_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('c2p_cart_nonce'),
            'plugin_url' => C2P_PLUGIN_URL,
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'checkout_url' => wc_get_checkout_url(),
            'is_logged_in' => is_user_logged_in(),
            'guest_id' => $this->get_guest_id(),
            'strings' => array(
                'loading' => esc_html__('Carregando...', 'click2pickup'),
                'error' => esc_html__('Erro ao processar solicitação', 'click2pickup'),
                'select_location' => esc_html__('Por favor, selecione um local', 'click2pickup'),
                'free' => esc_html__('GRÁTIS', 'click2pickup'),
                'days' => esc_html__('dias úteis', 'click2pickup'),
                'enter_postcode' => esc_html__('Digite seu CEP', 'click2pickup'),
                'calculating' => esc_html__('Calculando frete...', 'click2pickup'),
                'no_methods' => esc_html__('Nenhum método de entrega disponível', 'click2pickup'),
                'no_pickup' => esc_html__('Nenhuma loja disponível para retirada', 'click2pickup')
            )
        ));
        
        wp_enqueue_script('c2p-cart');
    }
      
    /**
     * Exibe o seletor de local
     */
    public function display_location_selector() {
        global $wpdb;
        
        // Garantir sessão
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        $selected_location_id = isset($_SESSION['c2p_selected_location']['id']) ? $_SESSION['c2p_selected_location']['id'] : null;
        
        // Se já tem local selecionado, mostrar resumo
        if ($selected_location_id) {
            $this->display_selected_location_summary();
            return;
        }
        
        // Buscar locais ativos
        $cache_key = 'c2p_active_locations';
        $locations = wp_cache_get($cache_key);
        
        if (false === $locations) {
            $locations_table = $wpdb->prefix . 'c2p_locations';
            $locations = $wpdb->get_results(
                "SELECT * FROM $locations_table WHERE is_active = 1 ORDER BY priority DESC, type DESC, name ASC"
            );
            wp_cache_set($cache_key, $locations, '', 300);
        }
        
        if (empty($locations)) {
            $this->display_no_locations_message();
            return;
        }
        
        // Separar CDs de Lojas
        $distribution_centers = array();
        $stores = array();
        
        foreach ($locations as $location) {
            if ($location->type === 'distribution_center' && $location->delivery_enabled) {
                $distribution_centers[] = $location;
            } elseif ($location->type === 'store' && $location->pickup_enabled) {
                $stores[] = $location;
            }
        }
        
        // Se não há CDs com delivery ou lojas com pickup, mostrar mensagem
        if (empty($distribution_centers) && empty($stores)) {
            $this->display_no_locations_message();
            return;
        }
              // Obter CEP do cliente
        $customer_postcode = '';
        if (WC()->customer) {
            $customer_postcode = WC()->customer->get_shipping_postcode();
            if (empty($customer_postcode)) {
                $customer_postcode = WC()->customer->get_billing_postcode();
            }
        }
        
        ?>
        <div class="c2p-location-selector">
            <h2>📍 <?php echo esc_html__('Como você prefere receber seu pedido?', 'click2pickup'); ?></h2>
            <p><?php echo esc_html__('Escolha entre receber em casa ou retirar em uma de nossas lojas', 'click2pickup'); ?></p>
            
            <!-- Switch de Delivery/Pickup -->
            <?php if (!empty($distribution_centers) || !empty($stores)) : ?>
            <div class="c2p-delivery-switch">
                <div class="c2p-switch-slider"></div>
                <?php if (!empty($distribution_centers)) : ?>
                    <div class="c2p-switch-option active" data-mode="delivery">
                        <span class="icon">🚚</span>
                        <span><?php echo esc_html__('Receber em Casa', 'click2pickup'); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($stores)) : ?>
                    <div class="c2p-switch-option <?php echo empty($distribution_centers) ? 'active' : ''; ?>" data-mode="pickup">
                        <span class="icon">🏪</span>
                        <span><?php echo esc_html__('Retirar na Loja', 'click2pickup'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Container de conteúdo -->
            <div class="c2p-content-wrapper">
                <?php if (!empty($distribution_centers)) : ?>
                    <!-- Conteúdo de Delivery -->
                    <div id="c2p-delivery-content" class="c2p-mode-content">
                        <?php 
                        // Usar o primeiro CD como padrão para delivery
                        $default_dc = reset($distribution_centers);
                        ?>
                        
                        <!-- Campo de CEP -->
                        <div class="c2p-postcode-section">
                            <div class="c2p-postcode-wrapper">
                                <label for="c2p-postcode">
                                    <span>📮</span>
                                    <?php echo esc_html__('Digite seu CEP para calcular o frete:', 'click2pickup'); ?>
                                </label>
                                <div class="c2p-postcode-input-group">
                                    <input type="text" 
                                           id="c2p-postcode" 
                                           class="c2p-postcode-input" 
                                           placeholder="00000-000"
                                           maxlength="9"
                                           value="<?php echo esc_attr($customer_postcode); ?>">
                                    <button type="button" id="c2p-calculate-shipping" class="c2p-btn-calculate">
                                        <?php echo esc_html__('Calcular', 'click2pickup'); ?>
                                    </button>
                                </div>
                                <small class="c2p-postcode-help">
                                    <a href="https://buscacepinter.correios.com.br/" target="_blank">
                                        <?php echo esc_html__('Não sei meu CEP', 'click2pickup'); ?>
                                    </a>
                                </small>
                            </div>
                        </div>
                        
                        <div class="c2p-loading-container" id="c2p-shipping-loading" style="display: none;">
                            <div class="c2p-loading-spinner"></div>
                            <p><?php echo esc_html__('Calculando opções de entrega...', 'click2pickup'); ?></p>
                        </div>
                        
                        <div class="c2p-shipping-methods" id="c2p-shipping-methods" style="display: none;">
                            <!-- Métodos de envio serão carregados via AJAX -->
                        </div>
                        
                        <input type="hidden" id="c2p-delivery-location" value="<?php echo esc_attr($default_dc->id); ?>">
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($stores)) : ?>
                    <!-- Conteúdo de Pickup -->
                    <div id="c2p-pickup-content" class="c2p-mode-content" style="<?php echo !empty($distribution_centers) ? 'display: none;' : ''; ?>">
                        <div class="c2p-stores-grid">
                            <?php foreach ($stores as $store) : ?>
                                <?php $this->render_store_card($store); ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($stores)) : ?>
                        <div class="c2p-no-stores-message">
                            <span>⚠️</span>
                            <p><?php echo esc_html__('Nenhuma loja disponível para retirada no momento.', 'click2pickup'); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Exibe mensagem quando não há locais disponíveis
     */
    private function display_no_locations_message() {
        ?>
        <div class="c2p-no-locations-notice">
            <h3>⚠️ <?php echo esc_html__('Atenção', 'click2pickup'); ?></h3>
            <p><?php echo esc_html__('No momento não há métodos de entrega ou retirada disponíveis.', 'click2pickup'); ?></p>
            <p><?php echo esc_html__('Por favor, entre em contato conosco para mais informações.', 'click2pickup'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Renderiza card de loja
     */
    private function render_store_card($store) {
        $opening_hours = json_decode($store->opening_hours, true);
        $today = strtolower(date('l'));
        $today_hours = isset($opening_hours[$today]) ? $opening_hours[$today] : null;
        $is_open_now = false;
        
        if ($today_hours && !empty($today_hours['open']) && !empty($today_hours['close']) && !$today_hours['closed']) {
            $current_time = current_time('H:i');
            $is_open_now = ($current_time >= $today_hours['open'] && $current_time <= $today_hours['close']);
        }
        
        ?>
        <div class="c2p-store-card" data-location-id="<?php echo esc_attr($store->id); ?>">
            <div class="c2p-store-header">
                <span class="c2p-store-type">
                    <?php echo esc_html__('LOJA FÍSICA', 'click2pickup'); ?>
                </span>
                <span class="c2p-store-icon">🏪</span>
                <h3 class="c2p-store-name"><?php echo esc_html($store->name); ?></h3>
            </div>
            
            <div class="c2p-store-body">
                <?php if ($store->address) : ?>
                    <div class="c2p-store-address">
                        <span>📍</span>
                        <div>
                            <?php echo esc_html($store->address); ?>
                            <?php if ($store->city && $store->state) : ?>
                                <br><?php echo esc_html($store->city . ', ' . $store->state); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($today_hours) : ?>
                    <div class="c2p-store-hours">
                        <div class="c2p-store-hours-title">
                            <?php echo esc_html__('Horário de Hoje', 'click2pickup'); ?>
                        </div>
                        <?php if (!$today_hours['closed'] && !empty($today_hours['open']) && !empty($today_hours['close'])) : ?>
                            <div class="c2p-store-hours-time <?php echo $is_open_now ? 'open' : ''; ?>">
                                <?php if ($is_open_now) : ?>
                                    <span style="color: #4caf50;">● <?php echo esc_html__('Aberto agora', 'click2pickup'); ?></span><br>
                                <?php else : ?>
                                    <span style="color: #dc3545;">● <?php echo esc_html__('Fechado agora', 'click2pickup'); ?></span><br>
                                <?php endif; ?>
                                <?php echo esc_html($today_hours['open'] . ' às ' . $today_hours['close']); ?>
                            </div>
                        <?php else : ?>
                            <div class="c2p-store-hours-time closed">
                                <?php echo esc_html__('Fechado hoje', 'click2pickup'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="c2p-store-features">
                    <?php if ($store->phone) : ?>
                        <div class="c2p-store-feature">
                            <span>📞</span>
                            <span><?php echo esc_html($store->phone); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="c2p-store-feature">
                        <span>✅</span>
                        <span><?php echo esc_html__('Retirada Grátis', 'click2pickup'); ?></span>
                    </div>
                </div>
                
                <button type="button" 
                        class="c2p-store-select-btn" 
                        onclick="c2pSelectStore(<?php echo esc_attr($store->id); ?>)">
                    <?php echo esc_html__('Selecionar esta Loja', 'click2pickup'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderiza card de método de envio DINÂMICO
     */
    private function render_shipping_method_card($method) {
        $cost = isset($method['cost']) ? floatval($method['cost']) : 0;
        $is_free = isset($method['is_free']) ? $method['is_free'] : ($cost == 0);
        
        // Determinar ícone DINAMICAMENTE baseado no título/tipo
        $icon = '📦';
        $title_lower = strtolower($method['title']);
        
        // Padrões de ícones
        $icon_patterns = array(
            'express' => '🚀',
            'expresso' => '🚀',
            'sedex' => '🚀',
            'rápido' => '🚀',
            'pac' => '💰',
            'econom' => '💰',
            'normal' => '📦',
            'padrão' => '📦',
            'standard' => '📦',
            'grátis' => '🎁',
            'free' => '🎁',
            'gratuito' => '🎁'
        );
        
        foreach ($icon_patterns as $pattern => $pattern_icon) {
            if (strpos($title_lower, $pattern) !== false) {
                $icon = $pattern_icon;
                break;
            }
        }
        
        if ($is_free) {
            $icon = '🎁';
        }
        
        ?>
        <div class="c2p-shipping-card" data-method="<?php echo esc_attr($method['id']); ?>">
            <span class="c2p-shipping-icon"><?php echo $icon; ?></span>
            
            <?php 
            // Badge de desconto DINÂMICO
            if ($cost > 0 && $cost < 30 && strpos($title_lower, 'promo') !== false) : 
            ?>
                <div class="c2p-discount-badge">PROMO</div>
            <?php endif; ?>
            
            <div class="c2p-shipping-name"><?php echo esc_html($method['title']); ?></div>
            
            <?php if (!empty($method['delivery_time'])) : ?>
            <div class="c2p-shipping-time">
                <span>🕐</span>
                <span><?php echo esc_html($method['delivery_time']); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="c2p-shipping-price <?php echo $is_free ? 'free' : ''; ?>">
                <?php 
                if ($is_free) {
                    echo esc_html__('GRÁTIS', 'click2pickup');
                } else {
                    echo wc_price($cost);
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Exibe resumo do local selecionado
     */
    private function display_selected_location_summary() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
        if (!$selected) {
            return;
        }
        
        $delivery_type = isset($selected['delivery_type']) ? $selected['delivery_type'] : 'pickup';
        $shipping_method = isset($selected['shipping_method']) ? $selected['shipping_method'] : '';
        
        // Obter nome do método de envio DINAMICAMENTE
        $method_title = '';
        if ($shipping_method) {
            // Buscar o título real do método
            $packages = WC()->shipping()->get_packages();
            if (!empty($packages)) {
                foreach ($packages as $package) {
                    if (isset($package['rates'][$shipping_method])) {
                        $method_title = $package['rates'][$shipping_method]->label;
                        break;
                    }
                }
            }
            
            // Se não encontrou, usar título genérico
            if (empty($method_title)) {
                if (strpos($shipping_method, 'local_pickup') !== false) {
                    $method_title = 'Retirada na Loja';
                } else {
                    $method_title = 'Entrega';
                }
            }
        }
        
        ?>
        <div class="c2p-selected-location">
            <div class="c2p-selected-info">
                <h3>
                    <?php if ($delivery_type === 'delivery') : ?>
                        🚚 <?php echo esc_html__('Entrega em Domicílio', 'click2pickup'); ?>
                    <?php else : ?>
                        🏪 <?php echo esc_html__('Retirada na Loja', 'click2pickup'); ?>
                    <?php endif; ?>
                </h3>
                <div class="c2p-selected-details">
                    <div class="c2p-selected-badge">
                        <?php echo esc_html($selected['name']); ?>
                    </div>
                    <?php if (!empty($selected['address'])) : ?>
                        <span class="c2p-selected-badge">📍 <?php echo esc_html($selected['address']); ?></span>
                    <?php endif; ?>
                    <?php if ($method_title && $delivery_type === 'delivery') : ?>
                        <span class="c2p-selected-badge">📦 <?php echo esc_html($method_title); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="c2p-btn-change" onclick="c2pChangeLocation()">
                <?php echo esc_html__('Alterar', 'click2pickup'); ?>
            </button>
        </div>
        
        <script>
        function c2pChangeLocation() {
            jQuery.post(c2p_ajax.ajax_url, {
                action: 'c2p_select_location',
                location_id: 0,
                nonce: c2p_ajax.nonce
            }, function(response) {
                location.reload();
            });
        }
        </script>
        <?php
    }
    
    /**
     * Valida carrinho para o local selecionado
     */
    private function validate_cart_for_location($location_id) {
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        // Verificar se a tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$stock_table'") !== $stock_table) {
            // Se a tabela não existe, retornar sem problemas
            return array('has_issues' => false);
        }
        
        $cart = WC()->cart;
        if (!$cart) {
            return array('has_issues' => false);
        }
        
        $removed_items = array();
        $unavailable_items = array();
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['variation_id'] ?: $cart_item['product_id'];
            $quantity_needed = $cart_item['quantity'];
            
            // Verificar estoque no local
            $stock = $wpdb->get_var($wpdb->prepare(
                "SELECT (stock_quantity - reserved_quantity) as available 
                 FROM $stock_table 
                 WHERE location_id = %d AND product_id = %d",
                $location_id,
                $product_id
            ));
            
            $available = $stock ?: 0;
            
            if ($available < $quantity_needed) {
                $product = wc_get_product($product_id);
                
                if ($available > 0) {
                    // Ajustar quantidade
                    $cart->set_quantity($cart_item_key, $available);
                    $removed_items[] = array(
                        'name' => $product->get_name(),
                        'original_qty' => $quantity_needed,
                        'adjusted_qty' => $available
                    );
                } else {
                    // Adicionar à lista de não disponíveis
                    $unavailable_items[] = array(
                        'key' => $cart_item_key,
                        'name' => $product->get_name(),
                        'quantity_needed' => $quantity_needed,
                        'quantity_available' => 0
                    );
                }
            }
        }
        
        if (!empty($removed_items)) {
            $_SESSION['c2p_removed_items'] = $removed_items;
        }
        
        return array(
            'has_issues' => !empty($unavailable_items),
            'unavailable_items' => $unavailable_items
        );
    }
    
    /**
     * Exibe aviso de itens removidos
     */
    public function display_removed_items_notice() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (empty($_SESSION['c2p_removed_items'])) {
            return;
        }
        
        $removed_items = $_SESSION['c2p_removed_items'];
        ?>
        <div class="c2p-removed-items-notice">
            <h3>⚠️ <?php echo esc_html__('Atenção: Alguns itens foram ajustados', 'click2pickup'); ?></h3>
            <p><?php echo esc_html__('As quantidades foram ajustadas baseadas no estoque disponível no local selecionado:', 'click2pickup'); ?></p>
            <ul>
                <?php foreach ($removed_items as $item) : ?>
                    <li>
                        <strong><?php echo esc_html($item['name']); ?></strong> - 
                        <?php printf(
                            esc_html__('Quantidade ajustada de %d para %d', 'click2pickup'),
                            $item['original_qty'],
                            $item['adjusted_qty']
                        ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <style>
            .c2p-removed-items-notice {
                background: linear-gradient(135deg, #fab1a0 0%, #fd79a8 100%);
                border: 1px solid #e17055;
                border-radius: 10px;
                padding: 20px;
                margin: 20px 0;
                color: #2d3436;
            }
            
            .c2p-removed-items-notice h3 {
                margin-top: 0;
                color: #d63031;
            }
            
            .c2p-removed-items-notice ul {
                background: rgba(255, 255, 255, 0.9);
                border-radius: 5px;
                padding: 15px 15px 15px 35px;
                margin-top: 10px;
            }
            
            .c2p-removed-items-notice li {
                margin: 5px 0;
            }
        </style>
        <?php
        
        // Limpar após exibir
        unset($_SESSION['c2p_removed_items']);
    }
    
} // FIM DA CLASSE C2P_Cart_Handler