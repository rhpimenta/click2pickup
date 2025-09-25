<?php
/**
 * Sistema de Checkout Personalizado Click2Pickup
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Checkout {
    
    private $selected_location = null;
    private $selected_method = null;
    
    /**
     * Construtor
     */
    public function __construct() {
        // Adicionar campos customizados ao checkout
        add_action('woocommerce_before_order_notes', array($this, 'display_location_selector'), 10);
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields'));
        
        // AJAX handlers
        add_action('wp_ajax_c2p_get_locations', array($this, 'ajax_get_locations'));
        add_action('wp_ajax_nopriv_c2p_get_locations', array($this, 'ajax_get_locations'));
        add_action('wp_ajax_c2p_check_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_nopriv_c2p_check_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_c2p_get_time_slots', array($this, 'ajax_get_time_slots'));
        add_action('wp_ajax_nopriv_c2p_get_time_slots', array($this, 'ajax_get_time_slots'));
        
        // REMOVIDO: Linhas problemáticas de shipping
        // add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
        // add_filter('woocommerce_package_rates', array($this, 'modify_shipping_rates'), 10, 2);
        
        // Adicionar scripts e estilos
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Adicionar informações ao email do pedido
        add_action('woocommerce_email_order_meta', array($this, 'add_email_order_meta'), 10, 3);
        
        // Exibir informações na página de obrigado
        add_action('woocommerce_thankyou', array($this, 'display_pickup_info'), 10);
    }
    
    /**
     * Enfileira scripts e estilos
     */
    public function enqueue_scripts() {
        if (is_checkout()) {
            // CSS principal com design moderno mas adaptável
            wp_enqueue_style(
                'c2p-checkout',
                C2P_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                C2P_VERSION
            );
            
            // JavaScript para interatividade
            wp_enqueue_script(
                'c2p-checkout',
                C2P_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery', 'wp-util'),
                C2P_VERSION,
                true
            );
            
            // Leaflet para mapas (leve e open source)
            wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
            wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
            
            // Localização do script
            wp_localize_script('c2p-checkout', 'c2p_checkout', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('c2p_checkout_nonce'),
                'strings' => array(
                    'select_location' => __('Selecione um local', 'click2pickup'),
                    'loading' => __('Carregando...', 'click2pickup'),
                    'no_availability' => __('Sem disponibilidade', 'click2pickup'),
                    'select_date' => __('Selecione uma data', 'click2pickup'),
                    'select_time' => __('Selecione um horário', 'click2pickup'),
                    'location_closed' => __('Local fechado neste dia', 'click2pickup'),
                    'out_of_stock' => __('Alguns itens não estão disponíveis neste local', 'click2pickup'),
                    'confirm_remove' => __('Deseja remover os itens indisponíveis do carrinho?', 'click2pickup'),
                    'map_error' => __('Erro ao carregar o mapa', 'click2pickup')
                ),
                'map_settings' => array(
                    'default_lat' => -19.9167,
                    'default_lng' => -43.9345,
                    'default_zoom' => 12
                )
            ));
        }
    }
    
    /**
     * Exibe o seletor de locais no checkout
     */
    public function display_location_selector($checkout) {
        global $wpdb;
        
        // Obter locais ativos
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $locations = $wpdb->get_results("SELECT * FROM $locations_table WHERE is_active = 1 ORDER BY type DESC, name ASC");
        
        if (empty($locations)) {
            return;
        }
        ?>
        
        <div id="c2p-checkout-wrapper" class="c2p-checkout-section">
            <div class="c2p-header">
                <h3 class="c2p-title">
                    <span class="c2p-icon">📍</span>
                    <?php esc_html_e('Como deseja receber seu pedido?', 'click2pickup'); ?>
                </h3>
                <p class="c2p-subtitle">
                    <?php esc_html_e('Escolha entre retirar na loja ou receber em casa', 'click2pickup'); ?>
                </p>
            </div>
            
            <!-- Seleção do Método -->
            <div class="c2p-method-selector">
                <div class="c2p-method-cards">
                    <div class="c2p-method-card" data-method="pickup">
                        <input type="radio" name="c2p_delivery_method" id="c2p_method_pickup" value="pickup" checked>
                        <label for="c2p_method_pickup">
                            <div class="c2p-method-icon">🏪</div>
                            <div class="c2p-method-content">
                                <h4><?php esc_html_e('Retirar na Loja', 'click2pickup'); ?></h4>
                                <p><?php esc_html_e('Grátis - Retire quando quiser', 'click2pickup'); ?></p>
                                <span class="c2p-method-badge"><?php esc_html_e('Mais rápido', 'click2pickup'); ?></span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="c2p-method-card" data-method="delivery">
                        <input type="radio" name="c2p_delivery_method" id="c2p_method_delivery" value="delivery">
                        <label for="c2p_method_delivery">
                            <div class="c2p-method-icon">🚚</div>
                            <div class="c2p-method-content">
                                <h4><?php esc_html_e('Entrega em Casa', 'click2pickup'); ?></h4>
                                <p><?php esc_html_e('Receba no conforto do seu lar', 'click2pickup'); ?></p>
                                <span class="c2p-method-badge"><?php esc_html_e('Conveniente', 'click2pickup'); ?></span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Seleção de Local para Retirada -->
            <div id="c2p-pickup-section" class="c2p-location-section">
                <h4 class="c2p-section-title">
                    <?php esc_html_e('Escolha a loja para retirada', 'click2pickup'); ?>
                </h4>
                
                <!-- Busca e Filtros -->
                <div class="c2p-location-filters">
                    <div class="c2p-search-box">
                        <input type="text" 
                               id="c2p-location-search" 
                               placeholder="<?php esc_attr_e('Buscar por nome ou endereço...', 'click2pickup'); ?>">
                        <span class="c2p-search-icon">🔍</span>
                    </div>
                    
                    <div class="c2p-filter-buttons">
                        <button type="button" class="c2p-filter-btn active" data-filter="all">
                            <?php esc_html_e('Todas', 'click2pickup'); ?>
                        </button>
                        <button type="button" class="c2p-filter-btn" data-filter="open">
                            <span class="c2p-status-dot open"></span>
                            <?php esc_html_e('Abertas Agora', 'click2pickup'); ?>
                        </button>
                        <button type="button" class="c2p-filter-btn" data-filter="available">
                            <span class="c2p-status-dot available"></span>
                            <?php esc_html_e('Com Estoque', 'click2pickup'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Grade de Locais -->
                <div class="c2p-locations-grid">
                    <?php foreach ($locations as $location) : 
                        if ($location->type !== 'store') continue;
                        
                        $hours = json_decode($location->opening_hours, true);
                        $is_open = $this->is_location_open($hours);
                        $has_stock = $this->check_cart_availability($location->id);
                    ?>
                        <div class="c2p-location-card <?php echo $is_open ? 'is-open' : 'is-closed'; ?> <?php echo $has_stock['available'] ? 'has-stock' : 'no-stock'; ?>" 
                             data-location-id="<?php echo esc_attr($location->id); ?>"
                             data-location-name="<?php echo esc_attr($location->name); ?>">
                            
                            <input type="radio" 
                                   name="c2p_pickup_location" 
                                   id="c2p_location_<?php echo $location->id; ?>" 
                                   value="<?php echo $location->id; ?>"
                                   class="c2p-location-radio">
                            
                            <label for="c2p_location_<?php echo $location->id; ?>" class="c2p-location-label">
                                <!-- Status Badge -->
                                <div class="c2p-location-status">
                                    <?php if ($is_open) : ?>
                                        <span class="c2p-status-badge open">
                                            ✅ <?php esc_html_e('Aberta', 'click2pickup'); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="c2p-status-badge closed">
                                            🔒 <?php esc_html_e('Fechada', 'click2pickup'); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!$has_stock['available']) : ?>
                                        <span class="c2p-status-badge no-stock">
                                            ⚠️ <?php esc_html_e('Estoque Parcial', 'click2pickup'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Informações da Loja -->
                                <div class="c2p-location-header">
                                    <h4 class="c2p-location-name"><?php echo esc_html($location->name); ?></h4>
                                    <div class="c2p-location-address">
                                        📍 <?php echo esc_html($location->address); ?>
                                        <?php if ($location->city) : ?>
                                            <br><small><?php echo esc_html($location->city . ', ' . $location->state); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Horários -->
                                <div class="c2p-location-hours">
                                    <span class="c2p-hours-label">🕐 <?php esc_html_e('Horário:', 'click2pickup'); ?></span>
                                    <span class="c2p-hours-text">
                                        <?php echo $this->get_today_hours($hours); ?>
                                    </span>
                                </div>
                                
                                <!-- Disponibilidade -->
                                <?php if ($has_stock['available']) : ?>
                                    <div class="c2p-location-availability available">
                                        <span class="c2p-check">✓</span>
                                        <?php esc_html_e('Todos os itens disponíveis', 'click2pickup'); ?>
                                    </div>
                                <?php else : ?>
                                    <div class="c2p-location-availability partial">
                                        <span class="c2p-warning">⚠️</span>
                                        <?php 
                                        printf(
                                            esc_html__('%d de %d itens disponíveis', 'click2pickup'),
                                            $has_stock['available_count'],
                                            $has_stock['total_count']
                                        );
                                        ?>
                                        <?php if (!empty($has_stock['unavailable_items'])) : ?>
                                            <div class="c2p-unavailable-items">
                                                <small><?php esc_html_e('Indisponíveis:', 'click2pickup'); ?></small>
                                                <ul>
                                                    <?php foreach ($has_stock['unavailable_items'] as $item) : ?>
                                                        <li>• <?php echo esc_html($item); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Ações -->
                                <div class="c2p-location-actions">
                                    <?php if ($location->phone) : ?>
                                        <a href="tel:<?php echo esc_attr($location->phone); ?>" class="c2p-action-btn phone">
                                            📞 <?php esc_html_e('Ligar', 'click2pickup'); ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($location->address) : ?>
                                        <button type="button" class="c2p-action-btn map" 
                                                data-lat="<?php echo esc_attr($location->latitude ?? ''); ?>"
                                                data-lng="<?php echo esc_attr($location->longitude ?? ''); ?>"
                                                data-address="<?php echo esc_attr($location->address); ?>">
                                            🗺️ <?php esc_html_e('Ver Mapa', 'click2pickup'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mapa Modal -->
                <div id="c2p-map-modal" class="c2p-modal">
                    <div class="c2p-modal-content">
                        <span class="c2p-modal-close">&times;</span>
                        <div id="c2p-map-container"></div>
                        <div class="c2p-map-actions">
                            <button type="button" class="c2p-btn-primary" id="c2p-get-directions">
                                🚗 <?php esc_html_e('Como Chegar', 'click2pickup'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Seleção de CD para Entrega -->
            <div id="c2p-delivery-section" class="c2p-location-section" style="display: none;">
                <h4 class="c2p-section-title">
                    <?php esc_html_e('Centro de distribuição', 'click2pickup'); ?>
                </h4>
                
                <div class="c2p-delivery-info">
                    <p><?php esc_html_e('Seu pedido será enviado do centro de distribuição mais próximo com disponibilidade.', 'click2pickup'); ?></p>
                </div>
                
                <div class="c2p-locations-grid">
                    <?php 
                    $has_cd = false;
                    foreach ($locations as $location) : 
                        if ($location->type !== 'distribution_center') continue;
                        $has_cd = true;
                        $has_stock = $this->check_cart_availability($location->id);
                    ?>
                        <div class="c2p-location-card cd-card <?php echo $has_stock['available'] ? 'has-stock' : 'no-stock'; ?>" 
                             data-location-id="<?php echo esc_attr($location->id); ?>">
                            
                            <input type="radio" 
                                   name="c2p_delivery_location" 
                                   id="c2p_cd_<?php echo $location->id; ?>" 
                                   value="<?php echo $location->id; ?>"
                                   class="c2p-location-radio"
                                   <?php echo $has_stock['available'] ? 'checked' : 'disabled'; ?>>
                            
                            <label for="c2p_cd_<?php echo $location->id; ?>" class="c2p-location-label">
                                <div class="c2p-location-header">
                                    <h4 class="c2p-location-name">
                                        🏭 <?php echo esc_html($location->name); ?>
                                    </h4>
                                    <div class="c2p-location-address">
                                        <?php echo esc_html($location->city . ', ' . $location->state); ?>
                                    </div>
                                </div>
                                
                                <?php if ($has_stock['available']) : ?>
                                    <div class="c2p-location-availability available">
                                        <span class="c2p-check">✓</span>
                                        <?php esc_html_e('Disponível para envio', 'click2pickup'); ?>
                                    </div>
                                <?php else : ?>
                                    <div class="c2p-location-availability unavailable">
                                        <span class="c2p-cross">✗</span>
                                        <?php esc_html_e('Sem estoque disponível', 'click2pickup'); ?>
                                    </div>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (!$has_cd) : ?>
                        <div class="c2p-no-locations">
                            <p><?php esc_html_e('Nenhum centro de distribuição disponível no momento.', 'click2pickup'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Agendamento -->
            <div id="c2p-scheduling-section" class="c2p-scheduling" style="display: none;">
                <h4 class="c2p-section-title">
                    <span class="c2p-icon">📅</span>
                    <span id="c2p-scheduling-title">
                        <?php esc_html_e('Quando deseja retirar?', 'click2pickup'); ?>
                    </span>
                </h4>
                
                <div class="c2p-schedule-grid">
                    <!-- Seleção de Data -->
                    <div class="c2p-schedule-date">
                        <label><?php esc_html_e('Escolha o dia', 'click2pickup'); ?></label>
                        <div class="c2p-date-selector">
                            <!-- Preenchido via JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Seleção de Horário -->
                    <div class="c2p-schedule-time">
                        <label><?php esc_html_e('Escolha o horário', 'click2pickup'); ?></label>
                        <div class="c2p-time-slots">
                            <!-- Preenchido via AJAX -->
                        </div>
                    </div>
                </div>
                
                <!-- Resumo do Agendamento -->
                <div class="c2p-schedule-summary" style="display: none;">
                    <div class="c2p-summary-card">
                        <h5><?php esc_html_e('Resumo do seu pedido:', 'click2pickup'); ?></h5>
                        <div class="c2p-summary-content">
                            <div class="c2p-summary-row">
                                <span class="c2p-summary-label">📍 Local:</span>
                                <span class="c2p-summary-value" id="c2p-summary-location"></span>
                            </div>
                            <div class="c2p-summary-row">
                                <span class="c2p-summary-label">📅 Data:</span>
                                <span class="c2p-summary-value" id="c2p-summary-date"></span>
                            </div>
                            <div class="c2p-summary-row">
                                <span class="c2p-summary-label">🕐 Horário:</span>
                                <span class="c2p-summary-value" id="c2p-summary-time"></span>
                            </div>
                        </div>
                        <div class="c2p-summary-note">
                            <small><?php esc_html_e('💡 Você receberá um e-mail de confirmação com todos os detalhes', 'click2pickup'); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Campos Hidden para Salvar Dados -->
            <input type="hidden" name="c2p_selected_location" id="c2p_selected_location" value="">
            <input type="hidden" name="c2p_selected_date" id="c2p_selected_date" value="">
            <input type="hidden" name="c2p_selected_time" id="c2p_selected_time" value="">
            <input type="hidden" name="c2p_delivery_type" id="c2p_delivery_type" value="pickup">
        </div>
        
        <?php
    }
    
    /**
     * Verifica se a loja está aberta
     */
    private function is_location_open($hours) {
        if (!$hours) return false;
        
        $current_day = strtolower(date('l'));
        $current_time = date('H:i');
        
        if (!isset($hours[$current_day])) return false;
        
        $day_hours = $hours[$current_day];
        
        if (isset($day_hours['closed']) && $day_hours['closed']) {
            return false;
        }
        
        $open_time = $day_hours['open'] ?? '09:00';
        $close_time = $day_hours['close'] ?? '18:00';
        
        return ($current_time >= $open_time && $current_time <= $close_time);
    }
    
    /**
     * Obtém horário de hoje
     */
    private function get_today_hours($hours) {
        if (!$hours) return __('Horário não definido', 'click2pickup');
        
        $current_day = strtolower(date('l'));
        
        if (!isset($hours[$current_day])) {
            return __('Horário não definido', 'click2pickup');
        }
        
        $day_hours = $hours[$current_day];
        
        if (isset($day_hours['closed']) && $day_hours['closed']) {
            return __('Fechado hoje', 'click2pickup');
        }
        
        $open = $day_hours['open'] ?? '09:00';
        $close = $day_hours['close'] ?? '18:00';
        
        return sprintf('%s - %s', $open, $close);
    }
    
    /**
     * Verifica disponibilidade do carrinho em um local
     */
    private function check_cart_availability($location_id) {
        global $wpdb;
        
        $cart = WC()->cart;
        if (!$cart || !$cart->get_cart()) {
            return array(
                'available' => true,
                'available_count' => 0,
                'total_count' => 0,
                'unavailable_items' => array()
            );
        }
        
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $available_count = 0;
        $total_count = 0;
        $unavailable_items = array();
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];
            $quantity_needed = $cart_item['quantity'];
            $check_id = $variation_id > 0 ? $variation_id : $product_id;
            
            $total_count++;
            
            // Verificar estoque no local
            $stock = $wpdb->get_var($wpdb->prepare(
                "SELECT stock_quantity FROM $stock_table WHERE product_id = %d AND location_id = %d",
                $check_id,
                $location_id
            ));
            
            if ($stock !== null && $stock >= $quantity_needed) {
                $available_count++;
            } else {
                $product = wc_get_product($check_id);
                if ($product) {
                    $unavailable_items[] = $product->get_name();
                }
            }
        }
        
        return array(
            'available' => ($available_count === $total_count),
            'available_count' => $available_count,
            'total_count' => $total_count,
            'unavailable_items' => $unavailable_items
        );
    }
    
    /**
     * AJAX: Obter locais
     */
    public function ajax_get_locations() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        global $wpdb;
        $locations = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}c2p_locations WHERE is_active = 1 ORDER BY type DESC, name ASC"
        );
        
        wp_send_json_success($locations);
    }
    
    /**
     * AJAX: Verificar disponibilidade
     */
    public function ajax_check_availability() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $location_id = intval($_POST['location_id']);
        $availability = $this->check_cart_availability($location_id);
        
        wp_send_json_success($availability);
    }
    
    /**
     * AJAX: Obter horários disponíveis
     */
    public function ajax_get_time_slots() {
        check_ajax_referer('c2p_checkout_nonce', 'nonce');
        
        $location_id = intval($_POST['location_id']);
        $date = sanitize_text_field($_POST['date']);
        
        global $wpdb;
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}c2p_locations WHERE id = %d",
            $location_id
        ));
        
        if (!$location) {
            wp_send_json_error('Local não encontrado');
        }
        
        $hours = json_decode($location->opening_hours, true);
        $day_name = strtolower(date('l', strtotime($date)));
        
        if (!isset($hours[$day_name]) || $hours[$day_name]['closed']) {
            wp_send_json_success(array(
                'closed' => true,
                'message' => __('Local fechado neste dia', 'click2pickup')
            ));
        }
        
        $day_hours = $hours[$day_name];
        $slots = $this->generate_time_slots(
            $day_hours['open'],
            $day_hours['close'],
            $day_hours['prep_time'] ?? 60,
            $date
        );
        
        wp_send_json_success(array(
            'closed' => false,
            'slots' => $slots
        ));
    }
    
    /**
     * Gera slots de horário
     */
    private function generate_time_slots($open, $close, $prep_time, $date) {
        $slots = array();
        $current_time = strtotime($open);
        $close_time = strtotime($close);
        $now = time();
        
        // Adicionar tempo de preparação se for hoje
        if (date('Y-m-d') == $date) {
            $min_time = $now + ($prep_time * 60);
            if ($current_time < $min_time) {
                // Arredondar para próxima meia hora
                $current_time = ceil($min_time / 1800) * 1800;
            }
        }
        
        while ($current_time < $close_time) {
            $slots[] = array(
                'value' => date('H:i', $current_time),
                'label' => date('H:i', $current_time)
            );
            $current_time += 1800; // Intervalos de 30 minutos
        }
        
        return $slots;
    }
    
    /**
     * Valida campos do checkout
     */
    public function validate_checkout() {
        if (isset($_POST['c2p_delivery_method'])) {
            $method = sanitize_text_field($_POST['c2p_delivery_method']);
            
            if ($method == 'pickup') {
                if (empty($_POST['c2p_selected_location'])) {
                    wc_add_notice(__('Por favor, selecione uma loja para retirada.', 'click2pickup'), 'error');
                }
                
                if (empty($_POST['c2p_selected_date'])) {
                    wc_add_notice(__('Por favor, selecione uma data para retirada.', 'click2pickup'), 'error');
                }
                
                if (empty($_POST['c2p_selected_time'])) {
                    wc_add_notice(__('Por favor, selecione um horário para retirada.', 'click2pickup'), 'error');
                }
            }
        }
    }
    
    /**
     * Salva campos customizados do pedido
     */
    public function save_checkout_fields($order_id) {
        if (!empty($_POST['c2p_delivery_method'])) {
            update_post_meta($order_id, '_c2p_delivery_method', sanitize_text_field($_POST['c2p_delivery_method']));
        }
        
        if (!empty($_POST['c2p_selected_location'])) {
            update_post_meta($order_id, '_c2p_location_id', intval($_POST['c2p_selected_location']));
            
            // Salvar nome do local também
            global $wpdb;
            $location = $wpdb->get_row($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}c2p_locations WHERE id = %d",
                intval($_POST['c2p_selected_location'])
            ));
            
            if ($location) {
                update_post_meta($order_id, '_c2p_location_name', $location->name);
            }
        }
        
        if (!empty($_POST['c2p_selected_date'])) {
            update_post_meta($order_id, '_c2p_pickup_date', sanitize_text_field($_POST['c2p_selected_date']));
        }
        
        if (!empty($_POST['c2p_selected_time'])) {
            update_post_meta($order_id, '_c2p_pickup_time', sanitize_text_field($_POST['c2p_selected_time']));
        }
        
        // Processar estoque
        $this->process_order_stock($order_id);
    }
    
    /**
     * Processa o estoque do pedido
     */
    private function process_order_stock($order_id) {
        $location_id = get_post_meta($order_id, '_c2p_location_id', true);
        
        if (!$location_id) {
            return;
        }
        
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $stock_log_table = $wpdb->prefix . 'c2p_stock_log';
        
        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity = $item->get_quantity();
            $check_id = $variation_id > 0 ? $variation_id : $product_id;
            
            // Obter estoque atual
            $current_stock = $wpdb->get_var($wpdb->prepare(
                "SELECT stock_quantity FROM $stock_table WHERE product_id = %d AND location_id = %d",
                $check_id,
                $location_id
            ));
            
            if ($current_stock !== null) {
                $new_stock = max(0, $current_stock - $quantity);
                
                // Atualizar estoque
                $wpdb->update(
                    $stock_table,
                    array('stock_quantity' => $new_stock),
                    array('product_id' => $check_id, 'location_id' => $location_id)
                );
                
                // Registrar no log
                $wpdb->insert(
                    $stock_log_table,
                    array(
                        'product_id' => $check_id,
                        'location_id' => $location_id,
                        'order_id' => $order_id,
                        'quantity_change' => -$quantity,
                        'stock_before' => $current_stock,
                        'stock_after' => $new_stock,
                        'reason' => 'order_placed',
                        'user_id' => get_current_user_id(),
                        'notes' => sprintf('Pedido #%d', $order_id)
                    )
                );
            }
        }
    }
    
    /**
     * Adiciona informações ao email do pedido
     */
    public function add_email_order_meta($order, $sent_to_admin, $plain_text) {
        $method = get_post_meta($order->get_id(), '_c2p_delivery_method', true);
        
        if ($method == 'pickup') {
            $location_name = get_post_meta($order->get_id(), '_c2p_location_name', true);
            $date = get_post_meta($order->get_id(), '_c2p_pickup_date', true);
            $time = get_post_meta($order->get_id(), '_c2p_pickup_time', true);
            
            if ($plain_text) {
                echo "\n" . __('INFORMAÇÕES DE RETIRADA:', 'click2pickup') . "\n";
                echo __('Local:', 'click2pickup') . ' ' . $location_name . "\n";
                echo __('Data:', 'click2pickup') . ' ' . date_i18n('d/m/Y', strtotime($date)) . "\n";
                echo __('Horário:', 'click2pickup') . ' ' . $time . "\n\n";
            } else {
                ?>
                <div style="margin: 20px 0; padding: 15px; background: #f0f8ff; border-left: 4px solid #2271b1;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('📍 Informações de Retirada', 'click2pickup'); ?></h3>
                    <p><strong><?php esc_html_e('Local:', 'click2pickup'); ?></strong> <?php echo esc_html($location_name); ?></p>
                    <p><strong><?php esc_html_e('Data:', 'click2pickup'); ?></strong> <?php echo esc_html(date_i18n('d/m/Y', strtotime($date))); ?></p>
                    <p><strong><?php esc_html_e('Horário:', 'click2pickup'); ?></strong> <?php echo esc_html($time); ?></p>
                </div>
                <?php
            }
        }
    }
    
    /**
     * Exibe informações na página de obrigado
     */
    public function display_pickup_info($order_id) {
        $order = wc_get_order($order_id);
        $method = get_post_meta($order_id, '_c2p_delivery_method', true);
        
        if ($method == 'pickup') {
            $location_name = get_post_meta($order_id, '_c2p_location_name', true);
            $date = get_post_meta($order_id, '_c2p_pickup_date', true);
            $time = get_post_meta($order_id, '_c2p_pickup_time', true);
            
            global $wpdb;
            $location_id = get_post_meta($order_id, '_c2p_location_id', true);
            $location = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}c2p_locations WHERE id = %d",
                $location_id
            ));
            ?>
            
            <div class="c2p-order-confirmation">
                <div class="c2p-confirmation-card">
                    <div class="c2p-confirmation-header">
                        <div class="c2p-success-icon">✅</div>
                        <h2><?php esc_html_e('Pedido Confirmado!', 'click2pickup'); ?></h2>
                        <p><?php esc_html_e('Seu pedido está sendo preparado', 'click2pickup'); ?></p>
                    </div>
                    
                    <div class="c2p-confirmation-details">
                        <h3><?php esc_html_e('📍 Detalhes da Retirada', 'click2pickup'); ?></h3>
                        
                        <div class="c2p-detail-row">
                            <div class="c2p-detail-label"><?php esc_html_e('Local:', 'click2pickup'); ?></div>
                            <div class="c2p-detail-value">
                                <strong><?php echo esc_html($location_name); ?></strong>
                                <?php if ($location) : ?>
                                    <br><?php echo esc_html($location->address); ?>
                                    <?php if ($location->city) : ?>
                                        <br><?php echo esc_html($location->city . ', ' . $location->state); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="c2p-detail-row">
                            <div class="c2p-detail-label"><?php esc_html_e('Data:', 'click2pickup'); ?></div>
                            <div class="c2p-detail-value">
                                <strong><?php echo $date ? esc_html(date_i18n('l, d/m/Y', strtotime($date))) : ''; ?></strong>
                            </div>
                        </div>
                        
                        <div class="c2p-detail-row">
                            <div class="c2p-detail-label"><?php esc_html_e('Horário:', 'click2pickup'); ?></div>
                            <div class="c2p-detail-value">
                                <strong><?php echo esc_html($time); ?></strong>
                            </div>
                        </div>
                        
                        <?php if ($location && $location->phone) : ?>
                            <div class="c2p-detail-row">
                                <div class="c2p-detail-label"><?php esc_html_e('Telefone:', 'click2pickup'); ?></div>
                                <div class="c2p-detail-value">
                                    <a href="tel:<?php echo esc_attr($location->phone); ?>">
                                        <?php echo esc_html($location->phone); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="c2p-confirmation-actions">
                        <?php if ($location && $location->address) : ?>
                            <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($location->address . ', ' . $location->city . ', ' . $location->state); ?>" 
                               target="_blank" 
                               class="c2p-btn c2p-btn-primary">
                                🗺️ <?php esc_html_e('Ver no Mapa', 'click2pickup'); ?>
                            </a>
                        <?php endif; ?>
                        
                        <button onclick="window.print();" class="c2p-btn c2p-btn-secondary">
                            🖨️ <?php esc_html_e('Imprimir Comprovante', 'click2pickup'); ?>
                        </button>
                    </div>
                    
                    <div class="c2p-confirmation-tips">
                        <h4><?php esc_html_e('💡 Dicas para Retirada:', 'click2pickup'); ?></h4>
                        <ul>
                            <li><?php esc_html_e('Leve um documento com foto', 'click2pickup'); ?></li>
                            <li><?php esc_html_e('Informe o número do pedido', 'click2pickup'); ?>: <strong>#<?php echo $order_id; ?></strong></li>
                            <li><?php esc_html_e('Confira os produtos na hora da retirada', 'click2pickup'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <?php
        }
    }
}