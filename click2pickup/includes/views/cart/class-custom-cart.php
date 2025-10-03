<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Custom_Cart {
    
    private static $instance = null;

    /** Assets (mantidos aqui porque seu main chama ::boot) */
    protected static $assets_url = '';
    protected static $templates_path = '';
    protected static $version = '1.1.0';
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {

        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        add_shortcode('c2p_cart', array($this, 'render_checkout'));
        // (Se seu shortcode [custom_checkout] é registrado em outro lugar, deixo como estava)

        $this->register_ajax_handlers();
        
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        
        add_action('init', array($this, 'init_session_delivery_mode'));
        
        // FILTRO PRINCIPAL com prioridade MÁXIMA
        add_filter('woocommerce_package_rates', array($this, 'filter_shipping_methods_by_mode'), 9999, 2);
        
        // Otimização para Correios
        add_filter('http_request_timeout', array($this, 'reduce_api_timeout'));
    }
    
    public function activate() {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                'Este plugin requer o WooCommerce instalado e ativo.',
                'Plugin não pode ser ativado',
                array('back_link' => true)
            );
        }
        
        $this->create_checkout_page();
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function create_checkout_page() {
        $page = get_page_by_path('custom-checkout');
        
        if (!$page) {
            $page_data = array(
                'post_title'    => 'Checkout Personalizado',
                'post_name'     => 'custom-checkout',
                'post_content'  => '[custom_checkout]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => get_current_user_id(),
            );
            
            $page_id = wp_insert_post($page_data);
            
            if ($page_id) {
                update_option('cwc_checkout_page_id', $page_id);
            }
        }
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">' . 
                        'Configurações' . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    public function init() {
        // Ensure AJAX endpoints for both logged-in and guests
        add_action('wp_ajax_cwc_change_delivery_method', array($this, 'change_delivery_method'));
        add_action('wp_ajax_nopriv_cwc_change_delivery_method', array($this, 'change_delivery_method'));
        add_action('wp_ajax_cwc_update_pickup_method', array($this, 'update_pickup_method'));
        add_action('wp_ajax_nopriv_cwc_update_pickup_method', array($this, 'update_pickup_method'));
        add_action('wp_ajax_cwc_update_shipping_method', array($this, 'update_shipping_method'));
        add_action('wp_ajax_nopriv_cwc_update_shipping_method', array($this, 'update_shipping_method'));
        add_action('wp_ajax_cwc_process_checkout', array($this, 'process_checkout'));
        add_action('wp_ajax_nopriv_cwc_process_checkout', array($this, 'process_checkout'));

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        load_plugin_textdomain('c2p', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }
    
    public function init_session_delivery_mode() {
        if (!class_exists('WC')) return;
        
        if (WC()->session && !WC()->session->get('cwc_delivery_mode')) {
            WC()->session->set('cwc_delivery_mode', 'home');
        }
    }
    
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>Custom WooCommerce Checkout requer o WooCommerce instalado e ativo.</p>
        </div>
        <?php
    }
    
    public function reduce_api_timeout($timeout) {
        // Reduz timeout para APIs externas durante cálculo de frete
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action'])) {
            if (strpos($_REQUEST['action'], 'cwc_') === 0) {
                return 5; // 5 segundos máximo
            }
        }
        return $timeout;
    }
    
    public function enqueue_scripts() {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content,'custom_checkout') || has_shortcode($post->post_content,'c2p_cart')) {
            
            wp_enqueue_style(
                'cwc-styles', 
                self::$assets_url . 'checkout.css', 
                array(), 
                self::$version
            );
            
            wp_enqueue_script(
                'cwc-script', 
                self::$assets_url . 'checkout.js', 
                array('jquery'), 
                self::$version, 
                true
            );
            
            $cart_item_count = 0;
            $cart_subtotal = 0;
            $delivery_mode = 'home';
            
            if (WC()->cart) {
                $cart_item_count = WC()->cart->get_cart_contents_count();
                $cart_subtotal = WC()->cart->get_subtotal();
            }
            
            if (WC()->session) {
                $delivery_mode = WC()->session->get('cwc_delivery_mode', 'home');
            }
            
            wp_localize_script('cwc-script', 'cwc_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cwc_nonce'),
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'cart_url' => wc_get_cart_url(),
                'shop_url' => get_permalink(wc_get_page_id('shop')),
                'checkout_url' => wc_get_checkout_url(),
                'is_logged_in' => is_user_logged_in(),
                'item_count' => $cart_item_count,
                'cart_subtotal' => $cart_subtotal,
                'delivery_mode' => $delivery_mode
            ));
        }
    }
    
    private function register_ajax_handlers() {
        $ajax_actions = array(
            'update_quantity',
            'apply_coupon',
            'remove_coupon',
            'remove_item',
            'update_shipping',
            'update_shipping_method',
            'update_pickup_method',
            'change_delivery_method',
            'process_checkout'
        );
        
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_cwc_' . $action, array($this, $action));
            add_action('wp_ajax_nopriv_cwc_' . $action, array($this, $action));
        }
    }
    
    /**
     * FILTRO PRINCIPAL - Remove métodos baseado no modo de entrega
     */
    public function filter_shipping_methods_by_mode($rates, $package) {
        if (!WC()->session) return $rates;
        
        $delivery_mode = WC()->session->get('cwc_delivery_mode', 'home');
        
        $filtered = array();
        foreach ($rates as $rate_id => $rate) {
            $method_id = $rate->get_method_id();
            
            if ($delivery_mode === 'store') {
                if ($method_id === 'local_pickup') {
                    $filtered[$rate_id] = $rate;
                }
            } else {
                if ($method_id !== 'local_pickup') {
                    $filtered[$rate_id] = $rate;
                }
            }
        }
        
        return $filtered;
    }
    
    /**
     * Busca métodos de retirada configurados
     */
    private function get_pickup_methods() {
        $pickup_methods = array();
        
        // Busca em todas as zonas de envio
        $zones = \WC_Shipping_Zones::get_zones();
        $zones[] = array('id' => 0); // Zona "Resto do mundo"
        
        foreach ($zones as $zone_data) {
            $zone = \WC_Shipping_Zones::get_zone(is_array($zone_data) ? $zone_data['id'] : $zone_data);
            $methods = $zone->get_shipping_methods(true);
            
            foreach ($methods as $method) {
                if ($method->id === 'local_pickup' && $method->is_enabled()) {
                    $pickup_methods[] = array(
                        'id' => 'local_pickup:' . $method->get_instance_id(),
                        'label' => $method->get_title(),
                        'cost' => 0,
                        'cost_display' => 'Grátis',
                        'method_id' => 'local_pickup'
                    );
                }
            }
        }
        
        return $pickup_methods;
    }
    
    public function render_checkout($atts) {
        if (!function_exists('WC')) {
            return '<div class="cwc-error">WooCommerce não está ativo.</div>';
        }
        
        if (!WC()->session) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
        
        if (is_null(WC()->cart)) {
            wc_load_cart();
        }
        
        if (WC()->cart->is_empty()) {
            ob_start();
            ?>
            <div class="cwc-empty-cart">
                <div class="cwc-empty-icon">🛒</div>
                <h2>Seu carrinho está vazio</h2>
                <p>Adicione alguns produtos antes de fazer o checkout.</p>
                <a href="<?php echo esc_url( get_permalink(wc_get_page_id('shop')) ); ?>" class="cwc-btn-primary">
                    Continuar Comprando
                </a>
            </div>
            <?php
            return ob_get_clean();
        }
        
        WC()->cart->calculate_totals();
        
        ob_start();
        if (!defined('CWC_PLUGIN_PATH')) define('CWC_PLUGIN_PATH', self::$templates_path);
        if (!defined('CWC_PLUGIN_URL')) define('CWC_PLUGIN_URL', self::$assets_url);
        include self::$templates_path . 'checkout.php';
        return ob_get_clean();
    }
    
    /**
     * AJAX: Atualiza quantidade
     */
    public function update_quantity() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
        $quantity = intval($_POST['quantity']);
        
        if ($quantity > 0) {
            $success = WC()->cart->set_quantity($cart_item_key, $quantity);
        } else {
            $success = WC()->cart->remove_cart_item($cart_item_key);
        }
        
        if (!$success) {
            wp_send_json_error(array('message' => 'Erro ao atualizar quantidade.'));
        }
        
        // Desabilita temporariamente o cálculo dos Correios
        add_filter('woocommerce_correios_calculate_shipping', '__return_false');
        
        WC()->cart->calculate_totals();
        
        $packages = WC()->shipping()->get_packages();
        $shipping_methods = array();
        
        if (!empty($packages) && isset($packages[0]['rates'])) {
            foreach ($packages[0]['rates'] as $rate) {
                $shipping_methods[] = array(
                    'id' => $rate->get_id(),
                    'label' => $rate->get_label(),
                    'cost' => floatval($rate->get_cost()),
                    'cost_display' => $rate->get_cost() == 0 ? 'Grátis' : wc_price($rate->get_cost()),
                    'method_id' => $rate->get_method_id()
                );
            }
        }
        
        remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
        
        // Pega linha atualizada
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        $line_total = $cart_item ? wc_price($cart_item['line_total']) : wc_price(0);
        
        wp_send_json_success(array(
            'line_total' => $line_total,
            'subtotal' => wc_price(WC()->cart->get_subtotal()),
            'shipping' => wc_price(WC()->cart->get_shipping_total()),
            'total' => wc_price(WC()->cart->get_total('')),
            'discount' => wc_price(WC()->cart->get_discount_total()),
            'shipping_methods' => $shipping_methods,
            'item_count' => WC()->cart->get_cart_contents_count()
        ));
    }
    
    /**
     * AJAX: Atualiza CEP/Frete - OTIMIZADO COM UPDATE_DISPLAY
     */
    public function update_shipping() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $postcode = preg_replace('/[^0-9]/', '', sanitize_text_field($_POST['postcode']));
        
        if (strlen($postcode) !== 8) {
            wp_send_json_error(array('message' => 'CEP inválido. Digite 8 números.'));
            return;
        }
        
        // Desabilita temporariamente o cálculo automático dos Correios
        add_filter('woocommerce_correios_calculate_shipping', '__return_false');
        
        // Define timeout curto para APIs externas
        add_filter('http_request_timeout', function() { return 3; });
        
        // Atualiza customer
        WC()->customer->set_shipping_postcode($postcode);
        WC()->customer->set_billing_postcode($postcode);
        WC()->customer->set_shipping_country('BR');
        WC()->customer->set_billing_country('BR');
        WC()->customer->save();
        
        // Limpa rates anteriores
        WC()->session->set('shipping_for_package_0', null);
        
        // Força recálculo APENAS do shipping
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();
        
        // Pega packages com rates já calculados
        $packages = WC()->shipping()->get_packages();
        
        // Processa rates sem recalcular
        $shipping_methods = array();
        $delivery_mode = WC()->session->get('cwc_delivery_mode', 'home');
        
        if (!empty($packages) && isset($packages[0]['rates'])) {
            foreach ($packages[0]['rates'] as $rate) {
                $method_id = $rate->get_method_id();
                
                // Aplica filtro baseado no modo
                if ($delivery_mode === 'store' && $method_id !== 'local_pickup') continue;
                if ($delivery_mode === 'home' && $method_id === 'local_pickup') continue;
                
                $shipping_methods[] = array(
                    'id' => $rate->get_id(),
                    'label' => $rate->get_label(),
                    'cost' => floatval($rate->get_cost()),
                    'cost_display' => $rate->get_cost() == 0 ? 'Grátis' : wc_price($rate->get_cost()),
                    'method_id' => $method_id,
                    'is_free' => ($rate->get_cost() == 0)
                );
            }
            
            // Se tem métodos, seleciona o primeiro
            if (!empty($shipping_methods)) {
                WC()->session->set('chosen_shipping_methods', array($shipping_methods[0]['id']));
                WC()->cart->calculate_totals();
            }
        }
        
        // Remove filtros temporários
        remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
        remove_all_filters('http_request_timeout');
        
        // IMPORTANTE: Retorna valores atualizados para interface
        wp_send_json_success(array(
            'postcode' => $postcode,
            'shipping_methods' => $shipping_methods,
            'shipping' => wc_price(WC()->cart->get_shipping_total()),
            'total' => wc_price(WC()->cart->get_total('')),
            'subtotal' => wc_price(WC()->cart->get_subtotal()),
            'discount' => wc_price(WC()->cart->get_discount_total()),
            'delivery_mode' => $delivery_mode,
            'update_display' => true,
            'formatted_cep' => substr($postcode, 0, 5) . '-' . substr($postcode, 5)
        ));
    }
    
    /**
     * AJAX: Muda método de envio
     */
    public function update_shipping_method() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $shipping_method = sanitize_text_field($_POST['shipping_method']);
        
        WC()->session->set('chosen_shipping_methods', array($shipping_method));
        WC()->cart->calculate_totals();
        
        wp_send_json_success(array(
            'shipping' => wc_price(WC()->cart->get_shipping_total()),
            'total' => wc_price(WC()->cart->get_total('')),
            'subtotal' => wc_price(WC()->cart->get_subtotal()),
            'discount' => wc_price(WC()->cart->get_discount_total())
        ));
    }
    
    /**
     * AJAX: Atualiza método de retirada
     */
    public function update_pickup_method() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $pickup_method = sanitize_text_field($_POST['pickup_method']);
        
        WC()->session->set('chosen_shipping_methods', array($pickup_method));
        WC()->cart->calculate_totals();
        
        wp_send_json_success(array(
            'shipping' => wc_price(0),
            'total' => wc_price(WC()->cart->get_total('')),
            'subtotal' => wc_price(WC()->cart->get_subtotal()),
            'discount' => wc_price(WC()->cart->get_discount_total())
        ));
    }
    
    /**
     * AJAX: Alterna entre Home/Store - OTIMIZADO
     * (AQUI está a ÚNICA alteração no método:
     *  usamos o helper build_pickup_html() para gerar o HTML)
     */
    public function change_delivery_method() {
        try {

        check_ajax_referer('cwc_nonce', 'nonce');
        
        $method = sanitize_text_field($_POST['method']);
        
        // Salva o modo na sessão
        WC()->session->set('cwc_delivery_mode', $method);
        WC()->session->set('c2p_delivery_mode', $method);
        
        $shipping_methods = array();
        $shipping_total = 0;
        
        if ($method === 'store') {
            // Busca métodos de retirada configurados
            $shipping_methods = $this->get_pickup_methods();
            $shipping_total = 0;
            
            // Se tem métodos, seleciona o primeiro
            if (!empty($shipping_methods)) {
                WC()->session->set('chosen_shipping_methods', array($shipping_methods[0]['id']));
            }
        } else {
            // Modo home - pega métodos de envio existentes
            $has_postcode = WC()->customer->get_shipping_postcode();
            
            if ($has_postcode) {
                $packages = WC()->shipping()->get_packages();
                
                if (!empty($packages) && isset($packages[0]['rates'])) {
                    foreach ($packages[0]['rates'] as $rate) {
                        $method_id = $rate->get_method_id();
                        
                        if ($method_id !== 'local_pickup') {
                            $shipping_methods[] = array(
                                'id' => $rate->get_id(),
                                'label' => $rate->get_label(),
                                'cost' => floatval($rate->get_cost()),
                                'cost_display' => $rate->get_cost() == 0 ? 'Grátis' : wc_price($rate->get_cost()),
                                'method_id' => $method_id
                            );
                        }
                    }
                    
                    // Seleciona primeiro método
                    if (!empty($shipping_methods)) {
                        WC()->session->set('chosen_shipping_methods', array($shipping_methods[0]['id']));
                        $shipping_total = $shipping_methods[0]['cost'];
                    }
                }
            }
        }
        
        // HTML para retirada — **AGORA usa o helper**
        $pickup_html = '';
        if ($method === 'store') {
            $pickup_html = $this->build_pickup_html();
        }
        
        // Calcula total
        $cart_total = WC()->cart->get_subtotal() + $shipping_total - WC()->cart->get_discount_total() + WC()->cart->get_fee_total();
        
        wp_send_json_success(array(
            'shipping_methods' => $shipping_methods,
            'pickup_html' => $pickup_html,
            'shipping' => wc_price($shipping_total),
            'total' => wc_price($cart_total),
            'subtotal' => wc_price(WC()->cart->get_subtotal()),
            'discount' => wc_price(WC()->cart->get_discount_total()),
            'delivery_mode' => $method
        ));
    
        } catch (\Throwable $e) {
            if (function_exists('error_log')) { error_log('[C2P][change_delivery_method] ' . $e->getMessage()); }
            wp_send_json_success(array(
                'shipping_methods' => array(),
                'pickup_html' => '',
                'shipping' => wc_price(0),
                'total' => wc_price(WC()->cart ? WC()->cart->get_total('edit') : 0),
                'subtotal' => wc_price(WC()->cart ? WC()->cart->get_subtotal() : 0),
                'discount' => wc_price(WC()->cart ? WC()->cart->get_discount_total() : 0),
                'delivery_mode' => isset($_POST['method']) ? sanitize_text_field($_POST['method']) : 'store',
                'message' => 'fallback'
            ));
        }
    }
    
    /**
     * AJAX: Aplicar cupom
     */
    public function apply_coupon() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $coupon_code = wc_format_coupon_code(sanitize_text_field($_POST['coupon']));
        
        if (empty($coupon_code)) {
            wp_send_json_error(array('message' => 'Por favor, insira um código de cupom.'));
            return;
        }
        
        if (WC()->cart->has_discount($coupon_code)) {
            wp_send_json_error(array('message' => sprintf('O cupom "%s" já foi aplicado.', $coupon_code)));
            return;
        }
        
        wc_clear_notices();
        
        // Desabilita cálculo dos Correios temporariamente
        add_filter('woocommerce_correios_calculate_shipping', '__return_false');
        
        if (WC()->cart->apply_coupon($coupon_code)) {
            WC()->cart->calculate_totals();
            
            $packages = WC()->shipping()->get_packages();
            $shipping_methods = array();
            
            if (!empty($packages) && isset($packages[0]['rates'])) {
                foreach ($packages[0]['rates'] as $rate) {
                    $shipping_methods[] = array(
                        'id' => $rate->get_id(),
                        'label' => $rate->get_label(),
                        'cost' => floatval($rate->get_cost()),
                        'cost_display' => $rate->get_cost() == 0 ? 'Grátis' : wc_price($rate->get_cost()),
                        'method_id' => $rate->get_method_id()
                    );
                }
            }
            
            remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
            
            wp_send_json_success(array(
                'message' => sprintf('Cupom "%s" aplicado com sucesso!', $coupon_code),
                'subtotal' => wc_price(WC()->cart->get_subtotal()),
                'shipping' => wc_price(WC()->cart->get_shipping_total()),
                'total' => wc_price(WC()->cart->get_total('')),
                'discount' => wc_price(WC()->cart->get_discount_total()),
                'shipping_methods' => $shipping_methods
            ));
        } else {
            $error_message = 'Cupom inválido ou expirado.';
            $notices = wc_get_notices('error');
            if (!empty($notices)) {
                $error_message = strip_tags($notices[0]['notice']);
            }
            wc_clear_notices();
            
            remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
            
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    /**
     * AJAX: Remover cupom
     */
    public function remove_coupon() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $coupon_code = sanitize_text_field($_POST['coupon']);
        
        // Desabilita cálculo dos Correios temporariamente
        add_filter('woocommerce_correios_calculate_shipping', '__return_false');
        
        if (WC()->cart->remove_coupon($coupon_code)) {
            WC()->cart->calculate_totals();
            
            $packages = WC()->shipping()->get_packages();
            $shipping_methods = array();
            
            if (!empty($packages) && isset($packages[0]['rates'])) {
                foreach ($packages[0]['rates'] as $rate) {
                    $shipping_methods[] = array(
                        'id' => $rate->get_id(),
                        'label' => $rate->get_label(),
                        'cost' => floatval($rate->get_cost()),
                        'cost_display' => $rate->get_cost() == 0 ? 'Grátis' : wc_price($rate->get_cost()),
                        'method_id' => $rate->get_method_id()
                    );
                }
            }
            
            remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
            
            wp_send_json_success(array(
                'message' => sprintf('Cupom "%s" removido.', $coupon_code),
                'subtotal' => wc_price(WC()->cart->get_subtotal()),
                'shipping' => wc_price(WC()->cart->get_shipping_total()),
                'total' => wc_price(WC()->cart->get_total('')),
                'discount' => wc_price(WC()->cart->get_discount_total()),
                'shipping_methods' => $shipping_methods
            ));
        } else {
            remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
            wp_send_json_error(array('message' => 'Erro ao remover cupom.'));
        }
    }
    
    /**
     * AJAX: Remover item
     */
    public function remove_item() {
        check_ajax_referer('cwc_nonce', 'nonce');
        
        $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
        
        // Desabilita cálculo dos Correios temporariamente
        add_filter('woocommerce_correios_calculate_shipping', '__return_false');
        
        if (WC()->cart->remove_cart_item($cart_item_key)) {
            WC()->cart->calculate_totals();
            
            $packages = WC()->shipping()->get_packages();
            $shipping_methods = array();
            
            if (!empty($packages) && isset($packages[0]['rates'])) {
                foreach ($packages[0]['rates'] as $rate) {
                    $shipping_methods[] = array(
                        'id' => $rate->get_id(),
                        'label' => $rate->get_label(),
                        'cost' => floatval($rate->get_cost()),
                        'cost_display' => $rate->get_cost() == 0 ? 'Grátis' : wc_price($rate->get_cost()),
                        'method_id' => $rate->get_method_id()
                    );
                }
            }
            
            remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
            
            wp_send_json_success(array(
                'message' => 'Item removido do carrinho.',
                'cart_empty' => WC()->cart->is_empty(),
                'item_count' => WC()->cart->get_cart_contents_count(),
                'subtotal' => wc_price(WC()->cart->get_subtotal()),
                'shipping' => wc_price(WC()->cart->get_shipping_total()),
                'total' => wc_price(WC()->cart->get_total('')),
                'discount' => wc_price(WC()->cart->get_discount_total()),
                'shipping_methods' => $shipping_methods
            ));
        } else {
            remove_filter('woocommerce_correios_calculate_shipping', '__return_false');
            wp_send_json_error(array('message' => 'Erro ao remover item.'));
        }
    }
    
    /**
     * AJAX: Processar checkout
     */
    public function process_checkout() {
        $url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('checkout') : home_url('/checkout/'));
        wp_send_json_success(array('redirect' => $url));
    }

    /**
     * Boot usado pelo plugin principal (mantido)
     */
    public static function boot( $assets_url, $templates_path, $version = '1.1.0' ) {
        self::$assets_url = rtrim($assets_url, '/').'/' ;
        self::$templates_path = rtrim($templates_path, '/').'/' ;
        self::$version = $version;
        $instance = self::getInstance();
        // init já é chamado no __construct
    }

    // ==========================
    // 🔽 NOVO HELPER (única adição real)
    // ==========================
    /**
     * Constrói o HTML do seletor de retirada a partir dos métodos local_pickup.
     */
    protected function build_pickup_html() {
        if ( ! function_exists( 'WC' ) ) {
            return '';
        }

        $options = array();

        if ( class_exists( '\WC_Shipping_Zones' ) ) {
            $zones = \WC_Shipping_Zones::get_zones();
            foreach ( $zones as $zone ) {
                if ( empty( $zone['shipping_methods'] ) ) { continue; }
                foreach ( $zone['shipping_methods'] as $method ) {
                    $method_id = isset( $method->id ) ? $method->id : ( isset( $method->method_id ) ? $method->method_id : '' );
                    $instance  = isset( $method->instance_id ) ? (int) $method->instance_id : 0;
                    $enabled   = isset( $method->enabled ) ? $method->enabled : 'yes';
                    $title     = ! empty( $method->title ) ? $method->title : ( isset( $zone['zone_name'] ) ? $zone['zone_name'] : 'Retirada' );

                    if ( 'local_pickup' === $method_id && 'yes' === $enabled ) {
                        $options[] = array(
                            'value' => 'local_pickup:' . $instance,
                            'label' => $title,
                        );
                    }
                }
            }
        }

        // Fallback: sem zonas, lista métodos globais
        if ( empty( $options ) && isset( WC()->shipping ) ) {
            $shipping = WC()->shipping();
            if ( method_exists( $shipping, 'get_shipping_methods' ) ) {
                $methods = $shipping->get_shipping_methods();
                foreach ( $methods as $m ) {
                    $method_id = isset( $m->id ) ? $m->id : ( isset( $m->method_id ) ? $m->method_id : '' );
                    $enabled   = isset( $m->enabled ) ? $m->enabled : 'yes';
                    $title     = ! empty( $m->title ) ? $m->title : 'Retirada local';
                    if ( 'local_pickup' === $method_id && 'yes' === $enabled ) {
                        $instance  = isset( $m->instance_id ) ? (int) $m->instance_id : 0;
                        $options[] = array(
                            'value' => 'local_pickup:' . $instance,
                            'label' => $title,
                        );
                    }
                }
            }
        }

        // Gera HTML do seletor
        ob_start(); ?>
        <div class="cwc-pickup-selector" id="pickup-selector">
            <h3>🏪 Opções de Retirada</h3>
            <div class="cwc-pickup-options">
                <select id="pickup-method-select" class="cwc-select">
                    <?php if ( ! empty( $options ) ) : ?>
                        <?php foreach ( $options as $opt ) : ?>
                            <option value="<?php echo esc_attr( $opt['value'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value=""><?php esc_html_e( 'Nenhum ponto de retirada configurado', 'c2p' ); ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="cwc-pickup-info">
                <p>✅ Sem custos de entrega</p>
                <small>Retire seu pedido após confirmação do pagamento</small>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

// Inicializa o plugin
if (!function_exists('cwc_debug_log')) {
    function cwc_debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CWC Debug] ' . print_r($message, true));
        }
    }
}
