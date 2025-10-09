<?php
/**
 * Click2Pickup - Custom Checkout
 * 
 * ✅ v1.0.3: DEBUG MODE + FORÇA CARREGAMENTO WC
 * ✅ v1.0.2: PATH CSS/JS CORRIGIDO
 * ✅ v1.0.1: Checkout customizado em steps
 * 
 * @package Click2Pickup
 * @since 1.0.3
 * @author rhpimenta
 * Last Update: 2025-01-09 20:06:00 UTC
 */

namespace C2P;

if (!defined('ABSPATH')) {
    exit;
}

class Custom_Checkout {
    
    private static $instance = null;
    protected static $assets_url = '';
    protected static $templates_path = '';
    protected static $version = '';
    
    /**
     * Singleton instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->register_hooks();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialize
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
    
    /**
     * Register hooks
     */
    private function register_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('c2p_checkout', [$this, 'render_checkout']);
        
        // AJAX handlers
        $this->register_ajax_handlers();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        $ajax_actions = [
            'get_delivery_mode',
            'set_delivery_mode',
            'check_customer',
            'calculate_shipping',
            'select_shipping_method',
            'apply_coupon',
            'remove_coupon',
            'place_order'
        ];
        
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_c2p_' . $action, [$this, $action]);
            add_action('wp_ajax_nopriv_c2p_' . $action, [$this, $action]);
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        global $post;
        
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        if (!has_shortcode($post->post_content, 'c2p_checkout')) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'c2p-checkout-custom',
            self::$assets_url . 'css/checkout-custom.css',
            [],
            self::$version
        );
        
        // JS
        wp_enqueue_script(
            'c2p-checkout-custom',
            self::$assets_url . 'js/checkout-custom.js',
            ['jquery'],
            self::$version,
            true
        );
        
        // Localização
        wp_localize_script('c2p-checkout-custom', 'c2p_checkout_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('c2p_checkout_nonce'),
            'checkout_url' => wc_get_checkout_url(),
            'cart_url' => wc_get_cart_url()
        ]);
    }
    
    /**
     * Render checkout shortcode
     */
    public function render_checkout($atts) {
        if (!function_exists('WC')) {
            return '<div class="c2p-error" style="padding:40px;text-align:center;background:#fff;border:2px solid #dc2626;border-radius:8px;margin:20px 0;">
                <h2 style="color:#dc2626;">❌ WooCommerce não está ativo</h2>
                <p>Por favor, ative o plugin WooCommerce para usar o checkout.</p>
            </div>';
        }
        
        // ✅ Força inicialização do WooCommerce
        if (!did_action('woocommerce_init')) {
            return '<div class="c2p-error" style="padding:40px;text-align:center;background:#fff;border:2px solid #f59e0b;border-radius:8px;margin:20px 0;">
                <h2 style="color:#f59e0b;">⚠️ WooCommerce ainda não inicializou</h2>
                <p>Tente recarregar a página. Se o problema persistir, entre em contato com o suporte.</p>
                <button onclick="location.reload()" style="padding:12px 24px;background:#003d82;color:#fff;border:none;border-radius:6px;cursor:pointer;margin-top:10px;">Recarregar Página</button>
            </div>';
        }
        
        // ✅ Verifica se cart existe
        if (!WC()->cart) {
            if (class_exists('\WC_Cart')) {
                WC()->cart = new \WC_Cart();
            } else {
                return '<div class="c2p-error" style="padding:40px;text-align:center;background:#fff;border:2px solid #dc2626;border-radius:8px;margin:20px 0;">
                    <h2 style="color:#dc2626;">❌ Erro ao inicializar carrinho</h2>
                    <p>A classe WC_Cart não existe. Verifique a instalação do WooCommerce.</p>
                </div>';
            }
        }
        
        // ✅ Verifica se session existe
        if (!WC()->session) {
            if (class_exists('\WC_Session_Handler')) {
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }
        }
        
        // ✅ Verifica se customer existe
        if (!WC()->customer) {
            if (class_exists('\WC_Customer')) {
                WC()->customer = new \WC_Customer(get_current_user_id(), true);
            }
        }
        
        ob_start();
        $template_file = self::$templates_path . 'checkout-custom.php';
        
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="c2p-error" style="padding:40px;text-align:center;background:#fff;border:2px solid #dc2626;border-radius:8px;margin:20px 0;">
                <h2 style="color:#dc2626;">❌ Template não encontrado</h2>
                <p>Arquivo esperado:</p>
                <code style="background:#f0f0f0;padding:8px;border-radius:4px;display:inline-block;margin:10px 0;">' . esc_html($template_file) . '</code>
                <p>Verifique se o arquivo existe no servidor.</p>
            </div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * ========================================
     * AJAX HANDLERS
     * ========================================
     */
    
    /**
     * Get delivery mode from session
     */
    public function get_delivery_mode() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        if (!WC()->session) {
            wp_send_json_error(['message' => 'Sessão não disponível']);
            return;
        }
        
        $mode = WC()->session->get('cwc_delivery_mode', 'home');
        $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
        
        // Busca métodos disponíveis
        $packages = WC()->shipping()->get_packages();
        $shipping_methods = [];
        $pickup_methods = [];
        
        if (!empty($packages) && isset($packages[0]['rates'])) {
            foreach ($packages[0]['rates'] as $rate) {
                $method_id = $rate->get_method_id();
                
                $method_data = [
                    'id' => $rate->get_id(),
                    'label' => $rate->get_label(),
                    'cost' => floatval($rate->get_cost()),
                    'cost_display' => wc_price($rate->get_cost()),
                    'method_id' => $method_id
                ];
                
                if ($method_id === 'local_pickup') {
                    $pickup_methods[] = $method_data;
                } else {
                    // Adiciona ETA se disponível
                    $meta = $rate->get_meta_data();
                    if (!empty($meta['delivery_forecast'])) {
                        $method_data['eta'] = $meta['delivery_forecast'] . ' dias úteis';
                    }
                    $shipping_methods[] = $method_data;
                }
            }
        }
        
        wp_send_json_success([
            'mode' => $mode,
            'shipping_methods' => $shipping_methods,
            'pickup_methods' => $pickup_methods,
            'chosen_method' => !empty($chosen_methods) ? $chosen_methods[0] : null
        ]);
    }
    
    /**
     * Set delivery mode
     */
    public function set_delivery_mode() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $mode = sanitize_text_field($_POST['mode'] ?? 'home');
        
        if (WC()->session) {
            WC()->session->set('cwc_delivery_mode', $mode);
        }
        
        wp_send_json_success(['mode' => $mode]);
    }
    
    /**
     * Check customer by email
     */
    public function check_customer() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $email = sanitize_email($_POST['email'] ?? '');
        
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Email inválido']);
            return;
        }
        
        // Busca usuário por email
        $user = get_user_by('email', $email);
        
        $customer_data = null;
        
        if ($user) {
            $customer = new \WC_Customer($user->ID);
            
            $customer_data = [
                'first_name' => $customer->get_billing_first_name(),
                'last_name' => $customer->get_billing_last_name(),
                'phone' => $customer->get_billing_phone(),
                'cpf' => get_user_meta($user->ID, 'billing_cpf', true),
                'postcode' => $customer->get_billing_postcode(),
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city' => $customer->get_billing_city(),
                'state' => $customer->get_billing_state()
            ];
        }
        
        wp_send_json_success([
            'customer' => $customer_data,
            'exists' => $user !== false
        ]);
    }
    
    /**
     * Calculate shipping
     */
    public function calculate_shipping() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $postcode = preg_replace('/\D/', '', sanitize_text_field($_POST['postcode'] ?? ''));
        
        if (strlen($postcode) !== 8) {
            wp_send_json_error(['message' => 'CEP inválido']);
            return;
        }
        
        // Define CEP no customer
        if (WC()->customer) {
            WC()->customer->set_shipping_postcode($postcode);
            WC()->customer->set_billing_postcode($postcode);
            WC()->customer->save();
        }
        
        // Recalcula frete
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();
        
        // Busca endereço via API
        $address = $this->get_address_by_postcode($postcode);
        
        // Retorna métodos
        $this->get_delivery_mode();
    }
    
    /**
     * Get address by postcode (ViaCEP)
     */
    private function get_address_by_postcode($postcode) {
        $response = wp_remote_get("https://viacep.com.br/ws/{$postcode}/json/");
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!empty($data) && !isset($data['erro'])) {
            return [
                'street' => $data['logradouro'] ?? '',
                'neighborhood' => $data['bairro'] ?? '',
                'city' => $data['localidade'] ?? '',
                'state' => $data['uf'] ?? ''
            ];
        }
        
        return null;
    }
    
    /**
     * Select shipping method
     */
    public function select_shipping_method() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $method_id = sanitize_text_field($_POST['method_id'] ?? '');
        
        if (WC()->session) {
            WC()->session->set('chosen_shipping_methods', [$method_id]);
        }
        
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();
        
        wp_send_json_success([
            'shipping' => wc_price(WC()->cart->get_shipping_total()),
            'total' => wc_price(WC()->cart->get_total(''))
        ]);
    }
    
    /**
     * Apply coupon
     */
    public function apply_coupon() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $coupon_code = wc_format_coupon_code(sanitize_text_field($_POST['coupon'] ?? ''));
        
        if (empty($coupon_code)) {
            wp_send_json_error(['message' => 'Digite um cupom válido']);
            return;
        }
        
        $result = WC()->cart->apply_coupon($coupon_code);
        
        WC()->cart->calculate_totals();
        
        if ($result) {
            wp_send_json_success();
        } else {
            $notices = wc_get_notices('error');
            $message = !empty($notices) ? wp_strip_all_tags($notices[0]['notice']) : 'Cupom inválido';
            wc_clear_notices();
            wp_send_json_error(['message' => $message]);
        }
    }
    
    /**
     * Remove coupon
     */
    public function remove_coupon() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $coupon_code = wc_format_coupon_code(sanitize_text_field($_POST['coupon'] ?? ''));
        
        WC()->cart->remove_coupon($coupon_code);
        WC()->cart->calculate_totals();
        
        wp_send_json_success();
    }
    
    /**
     * Place order
     */
    public function place_order() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        try {
            // Valida campos obrigatórios
            $required_fields = ['billing_email', 'billing_first_name', 'billing_last_name', 'billing_phone'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    wp_send_json_error(['message' => 'Preencha todos os campos obrigatórios']);
                    return;
                }
            }
            
            // Cria/atualiza customer
            $customer = WC()->customer;
            
            $customer->set_billing_email(sanitize_email($_POST['billing_email']));
            $customer->set_billing_first_name(sanitize_text_field($_POST['billing_first_name']));
            $customer->set_billing_last_name(sanitize_text_field($_POST['billing_last_name']));
            $customer->set_billing_phone(sanitize_text_field($_POST['billing_phone']));
            
            if (!empty($_POST['shipping_postcode'])) {
                $customer->set_shipping_postcode(sanitize_text_field($_POST['shipping_postcode']));
                $customer->set_shipping_address_1(sanitize_text_field($_POST['shipping_address_1'] ?? ''));
                $customer->set_shipping_address_2(sanitize_text_field($_POST['shipping_address_2'] ?? ''));
                $customer->set_shipping_city(sanitize_text_field($_POST['shipping_city'] ?? ''));
                $customer->set_shipping_state(sanitize_text_field($_POST['shipping_state'] ?? ''));
            }
            
            $customer->save();
            
            // Cria pedido
            $order = wc_create_order();
            
            // Adiciona produtos do carrinho
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $order->add_product($cart_item['data'], $cart_item['quantity']);
            }
            
            // Define endereços
            $order->set_address([
                'first_name' => sanitize_text_field($_POST['billing_first_name']),
                'last_name' => sanitize_text_field($_POST['billing_last_name']),
                'email' => sanitize_email($_POST['billing_email']),
                'phone' => sanitize_text_field($_POST['billing_phone'])
            ], 'billing');
            
            if (!empty($_POST['shipping_postcode'])) {
                $order->set_address([
                    'address_1' => sanitize_text_field($_POST['shipping_address_1'] ?? ''),
                    'address_2' => sanitize_text_field($_POST['shipping_address_2'] ?? ''),
                    'city' => sanitize_text_field($_POST['shipping_city'] ?? ''),
                    'state' => sanitize_text_field($_POST['shipping_state'] ?? ''),
                    'postcode' => sanitize_text_field($_POST['shipping_postcode'])
                ], 'shipping');
            }
            
            // Aplica cupons
            foreach (WC()->cart->get_coupons() as $code => $coupon) {
                $order->apply_coupon($code);
            }
            
            // Calcula totais
            $order->calculate_totals();
            
            // Salva CPF
            if (!empty($_POST['billing_cpf'])) {
                $order->update_meta_data('_billing_cpf', sanitize_text_field($_POST['billing_cpf']));
            }
            
            // Salva número do endereço
            if (!empty($_POST['shipping_number'])) {
                $order->update_meta_data('_shipping_number', sanitize_text_field($_POST['shipping_number']));
            }
            
            $order->save();
            
            // Limpa carrinho
            WC()->cart->empty_cart();
            
            // Redireciona para página de sucesso
            wp_send_json_success([
                'redirect' => $order->get_checkout_order_received_url()
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Bootstrap
     */
    public static function boot($assets_url, $templates_path, $version) {
        self::$assets_url = rtrim($assets_url, '/') . '/';
        self::$templates_path = rtrim($templates_path, '/') . '/';
        self::$version = $version;
        
        self::instance();
    }
}