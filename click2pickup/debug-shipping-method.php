<?php
/**
 * Forçar Método de Envio - Click2Pickup
 * 
 * @package Click2Pickup
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Shipping_Force {
    
    private static $instance = null;
    private $selected_method = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hooks com prioridade máxima
        add_filter('woocommerce_package_rates', array($this, 'force_shipping_method'), 9999, 2);
        add_filter('woocommerce_shipping_chosen_method', array($this, 'set_chosen_method'), 9999, 3);
        add_action('woocommerce_before_calculate_totals', array($this, 'sync_shipping_method'), 9999);
        add_action('woocommerce_review_order_before_shipping', array($this, 'force_refresh_shipping'), 9999);
        
        // Hook para JavaScript forçar no frontend
        add_action('wp_footer', array($this, 'force_shipping_js'), 9999);
    }
    
    /**
     * Sincronizar método de envio
     */
    public function sync_shipping_method() {
        // Obter seleção
        $selected = $this->get_selected_location();
        
        if ($selected && isset($selected['shipping_method'])) {
            $this->selected_method = $selected['shipping_method'];
            
            // Forçar no WooCommerce
            WC()->session->set('chosen_shipping_methods', array($this->selected_method));
        }
    }
    
    /**
     * Obter local selecionado de qualquer sessão
     */
    private function get_selected_location() {
        // Primeiro tentar WC Session
        $selected = WC()->session->get('c2p_selected_location');
        
        if (!$selected) {
            // Tentar sessão PHP
            if (!session_id() && !headers_sent()) {
                @session_start();
            }
            $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
            
            // Se encontrou, salvar no WC
            if ($selected) {
                WC()->session->set('c2p_selected_location', $selected);
            }
        }
        
        return $selected;
    }
    
    /**
     * Forçar método de envio nos rates
     */
    public function force_shipping_method($rates, $package) {
        $selected = $this->get_selected_location();
        
        if (!$selected || !isset($selected['shipping_method'])) {
            return $rates;
        }
        
        $selected_method = $selected['shipping_method'];
        
        // Log
        error_log('C2P Force: Tentando forçar método: ' . $selected_method);
        error_log('C2P Force: Rates disponíveis: ' . print_r(array_keys($rates), true));
        
        // Se é pickup
        if (isset($selected['delivery_type']) && $selected['delivery_type'] === 'pickup') {
            // Procurar qualquer método local_pickup
            foreach ($rates as $rate_id => $rate) {
                if (strpos($rate_id, 'local_pickup') !== false) {
                    error_log('C2P Force: Forçando pickup: ' . $rate_id);
                    return array($rate_id => $rate);
                }
            }
        }
        
        // Tentar encontrar o método exato
        if (isset($rates[$selected_method])) {
            error_log('C2P Force: Método exato encontrado: ' . $selected_method);
            return array($selected_method => $rates[$selected_method]);
        }
        
        // Tentar match parcial - mais agressivo
        $method_parts = explode(':', $selected_method);
        $method_base = $method_parts[0];
        
        foreach ($rates as $rate_id => $rate) {
            // Verificar se contém a base do método
            if (strpos($rate_id, $method_base) !== false) {
                error_log('C2P Force: Match parcial encontrado: ' . $rate_id);
                
                // Atualizar a sessão com o ID correto
                WC()->session->set('c2p_selected_location', array_merge(
                    $selected,
                    array('shipping_method' => $rate_id)
                ));
                WC()->session->set('chosen_shipping_methods', array($rate_id));
                
                return array($rate_id => $rate);
            }
        }
        
        // Se não encontrou mas tem método selecionado, criar rate customizado
        if ($selected_method && !empty($rates)) {
            // Pegar o primeiro rate como base
            $base_rate = reset($rates);
            $custom_rate = clone $base_rate;
            
            // Ajustar propriedades baseado no tipo selecionado
            if (strpos($selected_method, 'local_pickup') !== false) {
                $custom_rate->label = 'Retirada na Loja';
                $custom_rate->cost = 0;
            } elseif (strpos($selected_method, 'free_shipping') !== false) {
                $custom_rate->label = 'Frete Grátis';
                $custom_rate->cost = 0;
            }
            
            error_log('C2P Force: Criando rate customizado');
            return array($selected_method => $custom_rate);
        }
        
        return $rates;
    }
    
    /**
     * Definir método escolhido
     */
    public function set_chosen_method($method, $rates, $package) {
        $selected = $this->get_selected_location();
        
        if (!$selected || !isset($selected['shipping_method'])) {
            return $method;
        }
        
        $selected_method = $selected['shipping_method'];
        
        // Se o método existe nos rates, usar ele
        if (isset($rates[$selected_method])) {
            return $selected_method;
        }
        
        // Tentar match parcial
        $method_base = explode(':', $selected_method)[0];
        foreach ($rates as $rate_id => $rate) {
            if (strpos($rate_id, $method_base) !== false) {
                return $rate_id;
            }
        }
        
        return $method;
    }
    
    /**
     * Forçar refresh do shipping
     */
    public function force_refresh_shipping() {
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            // Trigger update quando a página carrega
            setTimeout(function() {
                $(document.body).trigger('update_checkout');
            }, 100);
        });
        </script>
        <?php
    }
    
    /**
     * JavaScript para forçar seleção no frontend
     */
    public function force_shipping_js() {
        if (!is_checkout()) {
            return;
        }
        
        $selected = $this->get_selected_location();
        if (!$selected || !isset($selected['shipping_method'])) {
            return;
        }
        
        $selected_method = $selected['shipping_method'];
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            console.log('🚀 C2P: Forçando método de envio: <?php echo esc_js($selected_method); ?>');
            
            // Função para forçar seleção
            function forceShippingMethod() {
                var targetMethod = '<?php echo esc_js($selected_method); ?>';
                var methodBase = targetMethod.split(':')[0];
                
                // Procurar input de shipping
                var $shippingInputs = $('input[name^="shipping_method"]');
                
                $shippingInputs.each(function() {
                    var $input = $(this);
                    var value = $input.val();
                    
                    // Se encontrou o método exato
                    if (value === targetMethod) {
                        if (!$input.is(':checked')) {
                            $input.prop('checked', true).trigger('change');
                            console.log('✅ Método exato selecionado:', value);
                        }
                        return false;
                    }
                    
                    // Se encontrou match parcial
                    if (value.indexOf(methodBase) !== -1) {
                        if (!$input.is(':checked')) {
                            $input.prop('checked', true).trigger('change');
                            console.log('✅ Match parcial selecionado:', value);
                        }
                        return false;
                    }
                });
                
                // Esconder outros métodos se configurado
                <?php if (isset($selected['delivery_type'])) : ?>
                var deliveryType = '<?php echo esc_js($selected['delivery_type']); ?>';
                
                if (deliveryType === 'pickup') {
                    // Esconder métodos que não são pickup
                    $shippingInputs.each(function() {
                        var $input = $(this);
                        var $li = $input.closest('li');
                        
                        if ($input.val().indexOf('local_pickup') === -1) {
                            $li.hide();
                        }
                    });
                } else if (deliveryType === 'delivery') {
                    // Esconder pickup se é delivery
                    $shippingInputs.each(function() {
                        var $input = $(this);
                        var $li = $input.closest('li');
                        
                        if ($input.val().indexOf('local_pickup') !== -1) {
                            $li.hide();
                        }
                    });
                }
                <?php endif; ?>
            }
            
            // Executar na carga
            forceShippingMethod();
            
            // Executar após updates do checkout
            $(document.body).on('updated_checkout', function() {
                setTimeout(forceShippingMethod, 100);
            });
            
            // Prevenir mudança manual
            $(document).on('change', 'input[name^="shipping_method"]', function(e) {
                var selectedVal = $(this).val();
                var targetMethod = '<?php echo esc_js($selected_method); ?>';
                var methodBase = targetMethod.split(':')[0];
                
                // Se tentou mudar para outro método, reverter
                if (selectedVal.indexOf(methodBase) === -1) {
                    e.preventDefault();
                    console.log('⚠️ Mudança bloqueada. Volte ao carrinho para alterar.');
                    forceShippingMethod();
                    
                    // Mostrar aviso
                    if (!$('.c2p-shipping-notice').length) {
                        $('.woocommerce-shipping-methods').before(
                            '<div class="c2p-shipping-notice" style="background: #fff3cd; padding: 10px; margin-bottom: 10px; border-left: 4px solid #ffc107;">' +
                            '⚠️ Para alterar o método de entrega, <a href="<?php echo wc_get_cart_url(); ?>">volte ao carrinho</a>.' +
                            '</div>'
                        );
                    }
                }
            });
        });
        </script>
        <?php
    }
}

// Inicializar
C2P_Shipping_Force::instance();