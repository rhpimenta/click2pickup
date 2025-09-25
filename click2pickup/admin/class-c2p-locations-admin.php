<?php
/**
 * Gerenciamento de locais no admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Locations_Admin {
    
    /**
     * Construtor
     */
    public function __construct() {
        // IMPORTANTE: Registrar os hooks de salvamento IMEDIATAMENTE
        add_action('admin_post_c2p_save_location', array($this, 'save_location'));
        add_action('admin_post_nopriv_c2p_save_location', array($this, 'save_location'));
        add_action('admin_post_c2p_delete_location', array($this, 'delete_location'));
        
        // Hook para scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Ajax hooks
        add_action('wp_ajax_c2p_save_location_ajax', array($this, 'save_location_ajax'));
        add_action('wp_ajax_c2p_test_location', array($this, 'ajax_test_location'));
    }
    
    /**
     * Teste Ajax para debug
     */
    public function ajax_test_location() {
        wp_send_json_success(array('message' => 'Ajax funcionando'));
    }
    
    /**
     * Enqueue scripts específicos para locais
     */
    public function enqueue_scripts($hook) {
        // CORREÇÃO: Garantir que $hook seja sempre uma string
        $hook = (string) $hook;
        
        // Verificar se estamos na página de locais
        if (!empty($hook) && strpos($hook, 'c2p-locations') !== false) {
            $this->enqueue_location_scripts();
        }
        // Alternativamente, verificar via $_GET se $hook estiver vazio
        elseif (isset($_GET['page']) && $_GET['page'] === 'c2p-locations') {
            $this->enqueue_location_scripts();
        }
    }
    
    /**
     * Enfileira os scripts necessários
     */
    private function enqueue_location_scripts() {
        // Enqueue media para upload de imagem
        wp_enqueue_media();
        
        // jQuery UI para datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        // Select2 para melhor UX
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        
        // Script específico de locais
        wp_enqueue_script(
            'c2p-locations-admin',
            C2P_PLUGIN_URL . 'assets/js/locations-admin.js',
            array('jquery', 'jquery-ui-datepicker', 'select2', 'media-upload'),
            C2P_VERSION . '.2',
            true
        );
        
        // CSS específico para locais
        wp_add_inline_style('c2p-admin', $this->get_locations_inline_css());
        
        wp_localize_script('c2p-locations-admin', 'c2p_locations', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('c2p_locations_nonce'),
            'strings' => array(
                'select_zones' => __('Selecione as zonas de entrega', 'click2pickup'),
                'select_methods' => __('Selecione os métodos de entrega', 'click2pickup')
            )
        ));
    }
    
    /**
     * CSS inline específico para locais - VISUAL MODERNO RESTAURADO
     */
    private function get_locations_inline_css() {
        return '
        /* VISUAL MODERNO COM GRADIENTES */
        
        /* Header com gradiente roxo */
        .c2p-page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            margin: -20px -20px 30px -20px;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .c2p-page-header h1 {
            color: white !important;
            font-size: 32px;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Cards com sombras suaves */
        .c2p-form-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .c2p-form-section:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .c2p-form-section h2 {
            color: #2c3e50;
            font-size: 22px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            position: relative;
        }
        
        .c2p-form-section h2:before {
            content: "";
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        /* Badges coloridos para tipo de local */
        .c2p-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .c2p-type-badge.store {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .c2p-type-badge.cd {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        /* Status badges com gradientes */
        .c2p-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .c2p-status-badge.active {
            background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
            color: #1a5f3f;
        }
        
        .c2p-status-badge.inactive {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #8b3a1f;
        }
        
        /* Cards de dias da semana melhorados */
        .c2p-hours-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }
        
        .c2p-day-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border: none;
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .c2p-day-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.25);
        }
        
        .c2p-day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(255,255,255,0.5);
        }
        
        .c2p-day-header h4 {
            margin: 0;
            color: #2c3e50;
            font-size: 16px;
            font-weight: 600;
        }
        
        .c2p-hour-row {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            padding: 8px;
            background: rgba(255,255,255,0.7);
            border-radius: 8px;
            transition: background 0.2s;
        }
        
        .c2p-hour-row:hover {
            background: rgba(255,255,255,0.9);
        }
        
        .c2p-hour-row label {
            min-width: 100px;
            font-weight: 500;
            color: #34495e;
        }
        
        .c2p-hour-row input[type="time"],
        .c2p-hour-row input[type="number"] {
            margin-right: 5px;
            border-radius: 6px;
            border: 1px solid #ddd;
            padding: 5px 10px;
        }
        
        .c2p-unit {
            color: #7f8c8d;
            font-size: 13px;
            margin-left: 5px;
        }
        
        /* Switch personalizado melhorado */
        .c2p-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .c2p-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .c2p-switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
            transition: .4s;
            border-radius: 24px;
        }
        
        .c2p-switch-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .c2p-switch input:checked + .c2p-switch-slider {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .c2p-switch input:checked + .c2p-switch-slider:before {
            transform: translateX(26px);
        }
        
        .c2p-switch-label {
            margin-left: 55px;
            line-height: 24px;
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }
        
        /* Botões de ação de horários com gradientes */
        .c2p-hours-actions {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 2px dashed #e2e4e7;
        }
        
        .c2p-hours-actions button {
            margin-right: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .c2p-hours-actions button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        /* Tabela modernizada */
        .wp-list-table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .wp-list-table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 1px;
            padding: 15px 10px;
        }
        
        .wp-list-table tbody tr {
            transition: all 0.2s;
        }
        
        .wp-list-table tbody tr:hover {
            background: #f8f9ff;
            transform: scale(1.01);
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.1);
        }
        
        /* Tabela de dias especiais */
        #c2p-special-days table {
            margin-top: 15px;
            border-radius: 10px;
            overflow: hidden;
        }
        
        #c2p-special-days input[type="text"],
        #c2p-special-days input[type="time"] {
            width: auto;
            border-radius: 6px;
            border: 1px solid #ddd;
            padding: 5px 8px;
        }
        
        #c2p-special-days .datepicker {
            width: 120px;
        }
        
        /* Seção de Configurações de Entrega */
        .c2p-shipping-section {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        /* Select2 customizado */
        .select2-container {
            width: 100% !important;
            max-width: 600px;
        }
        
        .select2-container .select2-selection--multiple {
            min-height: 38px;
            border: 2px solid #e1e4e8;
            border-radius: 8px;
            background-color: #fff;
            transition: border-color 0.2s;
        }
        
        .select2-container .select2-selection--multiple:focus,
        .select2-container--focus .select2-selection--multiple {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__rendered {
            padding: 0 8px 8px 8px;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 15px;
            padding: 5px 10px;
            margin-right: 5px;
            margin-top: 8px;
            color: white;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            font-weight: bold;
            margin-right: 5px;
            font-size: 16px;
        }
        
        .select2-dropdown {
            border: 2px solid #e1e4e8;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .select2-search--dropdown .select2-search__field {
            border: 1px solid #e1e4e8;
            border-radius: 6px;
            padding: 8px 12px;
        }
        
        .c2p-field-wrapper {
            margin-bottom: 20px;
        }
        
        .c2p-field-description {
            display: block;
            margin-top: 8px;
            color: #646970;
            font-size: 13px;
            font-style: italic;
        }
        
        /* Botão principal com gradiente */
        .button-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            border: none !important;
            text-shadow: none !important;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3) !important;
            font-size: 14px !important;
            padding: 8px 20px !important;
            height: auto !important;
            border-radius: 8px !important;
            transition: all 0.3s !important;
        }
        
        .button-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4) !important;
        }
        
        /* Adicionar emojis nos títulos */
        .c2p-form-section h2:before {
            margin-right: 10px;
            font-size: 24px;
        }
        
        .c2p-form-section:nth-child(1) h2:before { content: "📍"; }
        .c2p-form-section:nth-child(2) h2:before { content: "🚚"; }
        .c2p-form-section:nth-child(3) h2:before { content: "📮"; }
        .c2p-form-section:nth-child(4) h2:before { content: "⏰"; }
        .c2p-form-section:nth-child(5) h2:before { content: "📅"; }
        ';
    }
    
    /**
     * Obter zonas de entrega do WooCommerce
     */
    private function get_shipping_zones() {
        if (!class_exists('WC_Shipping_Zones')) {
            return array();
        }
        
        $zones = array();
        $wc_zones = WC_Shipping_Zones::get_zones();
        
        // Adicionar zona "Resto do Mundo" (ID 0)
        $zones[0] = array(
            'id' => 0,
            'name' => __('Locais não cobertos por outras zonas', 'click2pickup'),
            'methods' => array()
        );
        
        // Obter métodos da zona 0
        $zone_0 = new WC_Shipping_Zone(0);
        foreach ($zone_0->get_shipping_methods() as $method) {
            $zones[0]['methods'][$method->id] = array(
                'id' => $method->id,
                'instance_id' => $method->get_instance_id(),
                'title' => $method->get_title(),
                'enabled' => $method->is_enabled()
            );
        }
        
        // Adicionar outras zonas
        foreach ($wc_zones as $zone_data) {
            $zone = new WC_Shipping_Zone($zone_data['zone_id']);
            $zones[$zone_data['zone_id']] = array(
                'id' => $zone_data['zone_id'],
                'name' => $zone->get_zone_name(),
                'methods' => array()
            );
            
            foreach ($zone->get_shipping_methods() as $method) {
                $zones[$zone_data['zone_id']]['methods'][$method->id . ':' . $method->get_instance_id()] = array(
                    'id' => $method->id,
                    'instance_id' => $method->get_instance_id(),
                    'title' => $method->get_title(),
                    'enabled' => $method->is_enabled()
                );
            }
        }
        
        return $zones;
    }

    /**
     * MÉTODO PRINCIPAL - Exibe a página de gerenciamento de locais
     */
    public function display_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        
        // Exibir mensagens
        if (isset($_GET['message'])) {
            $this->display_admin_notice($_GET['message']);
        }
        
        switch ($action) {
            case 'add':
            case 'edit':
                $this->display_form();
                break;
            case 'delete':
                $this->delete_location();
                break;
            default:
                $this->display_list();
                break;
        }
    }
    
    /**
     * Exibe mensagens administrativas
     */
    private function display_admin_notice($message) {
        $messages = array(
            'added' => __('Local adicionado com sucesso! 🎉', 'click2pickup'),
            'updated' => __('Local atualizado com sucesso! ✨', 'click2pickup'),
            'deleted' => __('Local excluído com sucesso! 🗑️', 'click2pickup'),
            'error' => __('❌ Erro ao processar a operação.', 'click2pickup')
        );
        
        $class = ($message == 'error') ? 'notice-error' : 'notice-success';
        $text = isset($messages[$message]) ? $messages[$message] : '';
        
        if ($text) {
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p style="font-size: 14px;">' . $text . '</p></div>';
        }
    }
    
    /**
     * Formata horários para exibição
     */
    private function format_hours_display($opening_hours) {
        if (!$opening_hours) {
            return '<em>' . esc_html__('Não configurado', 'click2pickup') . '</em>';
        }
        
        $hours = json_decode($opening_hours, true);
        if (!$hours) {
            return '<em>' . esc_html__('Não configurado', 'click2pickup') . '</em>';
        }
        
        $days_abbr = array(
            'monday' => 'Seg',
            'tuesday' => 'Ter',
            'wednesday' => 'Qua',
            'thursday' => 'Qui',
            'friday' => 'Sex',
            'saturday' => 'Sáb',
            'sunday' => 'Dom'
        );
        
        $grouped = array();
        $current_group = array();
        $last_hours = '';
        
        foreach ($days_abbr as $day => $abbr) {
            if (!isset($hours[$day])) continue;
            
            $day_data = $hours[$day];
            if (isset($day_data['closed']) && $day_data['closed']) {
                if (!empty($current_group)) {
                    $grouped[] = $current_group;
                    $current_group = array();
                }
                $grouped[] = array('days' => array($abbr), 'hours' => 'Fechado');
                $last_hours = 'closed';
            } else {
                $day_hours = (isset($day_data['open']) ? $day_data['open'] : '09:00') . '-' . (isset($day_data['close']) ? $day_data['close'] : '18:00');
                if ($day_hours == $last_hours) {
                    $current_group['days'][] = $abbr;
                } else {
                    if (!empty($current_group)) {
                        $grouped[] = $current_group;
                    }
                    $current_group = array('days' => array($abbr), 'hours' => $day_hours);
                    $last_hours = $day_hours;
                }
            }
        }
        
        if (!empty($current_group)) {
            $grouped[] = $current_group;
        }
        
        $display = array();
        foreach ($grouped as $group) {
            if (count($group['days']) > 1) {
                $display[] = $group['days'][0] . '-' . end($group['days']) . ': ' . $group['hours'];
            } else {
                $display[] = $group['days'][0] . ': ' . $group['hours'];
            }
        }
        
        return implode('<br>', $display);
    }
    
    /**
     * Exibe a lista de locais - VISUAL MODERNO
     */
    private function display_list() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'c2p_locations';
        $locations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY name ASC");
        ?>
        <div class="wrap">
            <div class="c2p-page-header">
                <h1>🏪 <?php esc_html_e('Gerenciar Locais', 'click2pickup'); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=c2p-locations&action=add')); ?>" 
                   class="page-title-action" style="background: white; color: #667eea; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; display: inline-block; margin-top: 10px;">
                    ➕ <?php esc_html_e('Adicionar Novo Local', 'click2pickup'); ?>
                </a>
            </div>
            
            <?php if (empty($locations)) : ?>
                <div style="text-align: center; padding: 60px; background: white; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.08);">
                    <span style="font-size: 60px;">📦</span>
                    <h2 style="color: #34495e; margin: 20px 0;"><?php esc_html_e('Nenhum local cadastrado ainda', 'click2pickup'); ?></h2>
                    <p style="color: #7f8c8d;"><?php esc_html_e('Comece adicionando seu primeiro local de entrega ou retirada.', 'click2pickup'); ?></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 20%">📍 <?php esc_html_e('Nome', 'click2pickup'); ?></th>
                            <th style="width: 10%">🏷️ <?php esc_html_e('Tipo', 'click2pickup'); ?></th>
                            <th style="width: 20%">📮 <?php esc_html_e('Endereço', 'click2pickup'); ?></th>
                            <th style="width: 15%">🚚 <?php esc_html_e('Zonas', 'click2pickup'); ?></th>
                            <th style="width: 15%">⏰ <?php esc_html_e('Horários', 'click2pickup'); ?></th>
                            <th style="width: 8%">✅ <?php esc_html_e('Status', 'click2pickup'); ?></th>
                            <th style="width: 12%">⚙️ <?php esc_html_e('Ações', 'click2pickup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locations as $location) : ?>
                            <tr>
                                <td><strong style="color: #2c3e50; font-size: 14px;"><?php echo esc_html($location->name); ?></strong></td>
                                <td>
                                    <?php 
                                    // Badges coloridos para tipo
                                    if ($location->type == 'distribution_center') {
                                        echo '<span class="c2p-type-badge cd"><span class="dashicons dashicons-building"></span> ' . esc_html__('CD', 'click2pickup') . '</span>';
                                    } else {
                                        echo '<span class="c2p-type-badge store"><span class="dashicons dashicons-store"></span> ' . esc_html__('Loja', 'click2pickup') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($location->address) : ?>
                                        <?php echo esc_html($location->address); ?><br>
                                        <?php 
                                        $city = (string) $location->city;
                                        $state = (string) $location->state;
                                        $zip = (string) $location->zip_code;
                                        
                                        $address_parts = array();
                                        if (!empty($city)) $address_parts[] = $city;
                                        if (!empty($state)) $address_parts[] = $state;
                                        if (!empty($zip)) $address_parts[] = $zip;
                                        
                                        if (!empty($address_parts)) : ?>
                                            <small style="color: #7f8c8d;"><?php echo esc_html(implode(', ', $address_parts)); ?></small>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <em style="color: #95a5a6;"><?php esc_html_e('Não informado', 'click2pickup'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $shipping_zones = isset($location->shipping_zones) ? json_decode($location->shipping_zones, true) : array();
                                    if (!empty($shipping_zones)) {
                                        $all_zones = $this->get_shipping_zones();
                                        $zone_names = array();
                                        foreach ($shipping_zones as $zone_id) {
                                            if (isset($all_zones[$zone_id])) {
                                                $zone_names[] = $all_zones[$zone_id]['name'];
                                            }
                                        }
                                        echo '<small style="color: #34495e;">' . esc_html(implode(', ', $zone_names)) . '</small>';
                                    } else {
                                        echo '<em style="color: #3498db;">🌍 ' . esc_html__('Todas as zonas', 'click2pickup') . '</em>';
                                    }
                                    ?>
                                </td>
                                <td class="c2p-hours-cell">
                                    <small><?php echo wp_kses_post($this->format_hours_display($location->opening_hours)); ?></small>
                                </td>
                                <td>
                                    <?php if ($location->is_active) : ?>
                                        <span class="c2p-status-badge active"><?php esc_html_e('Ativo', 'click2pickup'); ?></span>
                                    <?php else : ?>
                                        <span class="c2p-status-badge inactive"><?php esc_html_e('Inativo', 'click2pickup'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=c2p-locations&action=edit&id=' . intval($location->id))); ?>" 
                                       class="button button-small" style="margin-right: 5px;">
                                        ✏️ <?php esc_html_e('Editar', 'click2pickup'); ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=c2p-locations&action=delete&id=' . intval($location->id)), 'delete_location_' . $location->id); ?>" 
                                       class="button button-small"
                                       onclick="return confirm('<?php esc_attr_e('Tem certeza que deseja excluir este local?', 'click2pickup'); ?>')">
                                        🗑️ <?php esc_html_e('Excluir', 'click2pickup'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Exibe o formulário de adicionar/editar local - COM VISUAL MODERNO
     */
    private function display_form() {
        $location = null;
        $is_edit = false;
        
        if (isset($_GET['id'])) {
            global $wpdb;
            $location_id = intval($_GET['id']);
            $table_name = $wpdb->prefix . 'c2p_locations';
            $location = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $location_id));
            $is_edit = true;
        }
        
        // Decodificar horários se existirem
        $opening_hours = $location && $location->opening_hours ? json_decode($location->opening_hours, true) : array();
        
        // Decodificar configurações de entrega
        $shipping_zones = $location && isset($location->shipping_zones) ? json_decode($location->shipping_zones, true) : array();
        if (!is_array($shipping_zones)) $shipping_zones = array();
        
        $shipping_methods = $location && isset($location->shipping_methods) ? json_decode($location->shipping_methods, true) : array();
        if (!is_array($shipping_methods)) $shipping_methods = array();
        
        // Obter todas as zonas de entrega
        $all_zones = $this->get_shipping_zones();
        
        // Dias da semana
        $weekdays = array(
            'monday' => __('Segunda-feira', 'click2pickup'),
            'tuesday' => __('Terça-feira', 'click2pickup'),
            'wednesday' => __('Quarta-feira', 'click2pickup'),
            'thursday' => __('Quinta-feira', 'click2pickup'),
            'friday' => __('Sexta-feira', 'click2pickup'),
            'saturday' => __('Sábado', 'click2pickup'),
            'sunday' => __('Domingo', 'click2pickup')
        );
        ?>
        <div class="wrap">
            <div class="c2p-page-header">
                <h1><?php echo $is_edit ? '✏️ ' . esc_html__('Editar Local', 'click2pickup') : '➕ ' . esc_html__('Adicionar Novo Local', 'click2pickup'); ?></h1>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="c2p-location-form">
                <?php wp_nonce_field('c2p_save_location', 'c2p_location_nonce'); ?>
                <input type="hidden" name="action" value="c2p_save_location">
                <?php if ($is_edit && $location) : ?>
                    <input type="hidden" name="location_id" value="<?php echo esc_attr($location->id); ?>">
                <?php endif; ?>
                
                <!-- Informações Básicas -->
                <div class="c2p-form-section">
                    <h2><?php esc_html_e('Informações Básicas', 'click2pickup'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="name"><?php esc_html_e('Nome do Local', 'click2pickup'); ?> *</label></th>
                            <td>
                                <input type="text" name="name" id="name" class="regular-text" 
                                       value="<?php echo $location ? esc_attr((string) $location->name) : ''; ?>" required>
                                <p class="description"><?php esc_html_e('Nome que será exibido para os clientes', 'click2pickup'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="type"><?php esc_html_e('Tipo de Local', 'click2pickup'); ?> *</label></th>
                            <td>
                                <select name="type" id="type" required>
                                    <option value="store" <?php selected($location ? (string) $location->type : '', 'store'); ?>>
                                        🏪 <?php esc_html_e('Loja Física (Retirada)', 'click2pickup'); ?>
                                    </option>
                                    <option value="distribution_center" <?php selected($location ? (string) $location->type : '', 'distribution_center'); ?>>
                                        🏭 <?php esc_html_e('Centro de Distribuição (Envio)', 'click2pickup'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Lojas: clientes podem retirar produtos | CDs: produtos são enviados', 'click2pickup'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label><?php esc_html_e('Imagem da Loja', 'click2pickup'); ?></label></th>
                            <td>
                                <?php 
                                $image_id = $location && isset($location->image_id) ? intval($location->image_id) : 0;
                                $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
                                ?>
                                <input type="hidden" name="image_id" id="location-image-id" value="<?php echo esc_attr($image_id); ?>">
                                <div id="location-image-preview" style="margin-bottom: 10px;">
                                    <?php if ($image_url) : ?>
                                        <img src="<?php echo esc_url($image_url); ?>" style="max-width: 300px; height: auto; display: block; margin-bottom: 10px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);" />
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="button" id="upload-location-image">
                                    📷 <?php esc_html_e('Selecionar Imagem', 'click2pickup'); ?>
                                </button>
                                <button type="button" class="button" id="remove-location-image" 
                                        style="<?php echo $image_url ? '' : 'display:none;'; ?>">
                                    ❌ <?php esc_html_e('Remover Imagem', 'click2pickup'); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e('Esta imagem será exibida no carrinho quando o cliente escolher a loja.', 'click2pickup'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="phone"><?php esc_html_e('Telefone', 'click2pickup'); ?></label></th>
                            <td>
                                <input type="text" name="phone" id="phone" class="regular-text" 
                                       value="<?php echo $location && isset($location->phone) ? esc_attr((string) $location->phone) : ''; ?>"
                                       placeholder="(00) 0000-0000">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="email"><?php esc_html_e('E-mail', 'click2pickup'); ?></label></th>
                            <td>
                                <input type="email" name="email" id="email" class="regular-text" 
                                       value="<?php echo $location && isset($location->email) ? esc_attr((string) $location->email) : ''; ?>">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="is_active"><?php esc_html_e('Status', 'click2pickup'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" id="is_active" value="1" 
                                           <?php checked($location ? $location->is_active : true, 1); ?>>
                                    <?php esc_html_e('✅ Local ativo e disponível para clientes', 'click2pickup'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php
                // Incluir o restante do formulário
                $this->display_form_part2($location, $is_edit, $opening_hours, $weekdays, $all_zones, $shipping_zones, $shipping_methods);
                ?>
            </form>
        </div>
        <?php
    }

    private function display_form_part2($location, $is_edit, $opening_hours, $weekdays, $all_zones, $shipping_zones, $shipping_methods) {
        ?>
        <!-- Configurações de Entrega SIMPLIFICADA -->
        <div class="c2p-form-section">
            <h2><?php esc_html_e('Configurações de Entrega', 'click2pickup'); ?></h2>
            
            <div class="c2p-shipping-section">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="shipping_zones"><?php esc_html_e('Zonas de Entrega', 'click2pickup'); ?></label>
                        </th>
                        <td>
                            <div class="c2p-field-wrapper">
                                <select name="shipping_zones[]" 
                                        id="shipping_zones" 
                                        multiple="multiple">
                                    <?php foreach ($all_zones as $zone_id => $zone) : ?>
                                        <option value="<?php echo esc_attr($zone_id); ?>" 
                                                <?php selected(in_array($zone_id, $shipping_zones), true); ?>>
                                            <?php echo esc_html($zone['name']); ?>
                                            <?php if (!empty($zone['methods'])) : ?>
                                                (<?php echo count($zone['methods']) . ' ' . __('métodos', 'click2pickup'); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="c2p-field-description">
                                    <?php esc_html_e('Selecione as zonas que este local atende. Deixe vazio para atender todas.', 'click2pickup'); ?>
                                </span>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="shipping_methods"><?php esc_html_e('Métodos de Entrega', 'click2pickup'); ?></label>
                        </th>
                        <td>
                            <div class="c2p-field-wrapper">
                                <select name="shipping_methods[]" 
                                        id="shipping_methods" 
                                        multiple="multiple">
                                    <?php 
                                    foreach ($all_zones as $zone_id => $zone) :
                                        if (empty($zone['methods'])) continue;
                                        ?>
                                        <optgroup label="<?php echo esc_attr($zone['name']); ?>">
                                            <?php foreach ($zone['methods'] as $method_key => $method) : ?>
                                                <option value="<?php echo esc_attr($zone_id . ':' . $method_key); ?>"
                                                        data-zone="<?php echo esc_attr($zone_id); ?>"
                                                        <?php selected(in_array($zone_id . ':' . $method_key, $shipping_methods), true); ?>>
                                                    <?php echo esc_html($method['title']); ?>
                                                    <?php if (!$method['enabled']) echo ' (' . __('Desativado', 'click2pickup') . ')'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <span class="c2p-field-description">
                                    <?php esc_html_e('Selecione os métodos suportados. Os métodos são filtrados pelas zonas selecionadas.', 'click2pickup'); ?>
                                </span>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pickup_enabled"><?php esc_html_e('Permitir Retirada', 'click2pickup'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="pickup_enabled" id="pickup_enabled" value="1"
                                       <?php checked($location && isset($location->pickup_enabled) ? $location->pickup_enabled : true, 1); ?>>
                                <?php esc_html_e('Clientes podem retirar produtos neste local', 'click2pickup'); ?>
                            </label>
                            <span class="c2p-field-description">
                                <?php esc_html_e('Marque se este local aceita retirada de produtos pelos clientes.', 'click2pickup'); ?>
                            </span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="delivery_enabled"><?php esc_html_e('Permitir Entrega', 'click2pickup'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="delivery_enabled" id="delivery_enabled" value="1"
                                       <?php checked($location && isset($location->delivery_enabled) ? $location->delivery_enabled : true, 1); ?>>
                                <?php esc_html_e('Este local pode enviar produtos', 'click2pickup'); ?>
                            </label>
                            <span class="c2p-field-description">
                                <?php esc_html_e('Marque se este local pode enviar produtos para entrega.', 'click2pickup'); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Script inline para garantir que tudo funcione -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Forçar inicialização do Select2
            if ($.fn.select2) {
                $('#shipping_zones, #shipping_methods').each(function() {
                    if (!$(this).data('select2')) {
                        $(this).select2({
                            placeholder: 'Clique para selecionar...',
                            allowClear: true,
                            width: '100%',
                            closeOnSelect: false
                        });
                    }
                });
            }
            
            // CORREÇÃO: JavaScript para adicionar dia especial com TODOS os campos
            window.specialDayIndex = $('#special-days-list tr').length;
            
            $('#add-special-day').off('click').on('click', function(e) {
                e.preventDefault();
                console.log('Adicionando dia especial com índice:', window.specialDayIndex);
                
                var newRow = '<tr>' +
                    '<td>' +
                        '<input type="text" name="special_days[' + window.specialDayIndex + '][date]" ' +
                        'class="datepicker" readonly placeholder="Selecione" style="width: 100%;">' +
                    '</td>' +
                    '<td>' +
                        '<input type="text" name="special_days[' + window.specialDayIndex + '][description]" ' +
                        'placeholder="Ex: Natal" style="width: 100%;">' +
                    '</td>' +
                    '<td>' +
                        '<select name="special_days[' + window.specialDayIndex + '][status]" class="special-day-status">' +
                            '<option value="closed">Fechado</option>' +
                            '<option value="open">Aberto</option>' +
                        '</select>' +
                    '</td>' +
                    '<td class="special-day-hours">' +
                        '<input type="time" name="special_days[' + window.specialDayIndex + '][open]" style="width: 45%;"> - ' +
                        '<input type="time" name="special_days[' + window.specialDayIndex + '][close]" style="width: 45%;">' +
                    '</td>' +
                    '<td class="special-day-prep">' +
                        '<input type="number" name="special_days[' + window.specialDayIndex + '][prep_time]" ' +
                        'value="60" min="0" max="1440" step="15" style="width: 60px;"> min' +
                    '</td>' +
                    '<td class="special-day-cutoff">' +
                        '<input type="time" name="special_days[' + window.specialDayIndex + '][cutoff]" value="17:00">' +
                    '</td>' +
                    '<td style="text-align: center;">' +
                        '<input type="checkbox" name="special_days[' + window.specialDayIndex + '][recurring]" value="1">' +
                    '</td>' +
                    '<td>' +
                        '<button type="button" class="button button-small remove-special-day">Remover</button>' +
                    '</td>' +
                '</tr>';
                
                $('#special-days-list').append(newRow);
                window.specialDayIndex++;
                
                // Inicializar datepicker no novo campo
                $('.datepicker').not('.hasDatepicker').datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true,
                    minDate: 0
                });
                
                // Aplicar estado inicial (fechado = desabilitar campos)
                $('#special-days-list tr:last-child .special-day-status').trigger('change');
            });
            
            // Debug
            console.log('Form page loaded, Select2 available:', typeof $.fn.select2 !== 'undefined');
        });
        </script>
        
        <?php
        // Continuar com o resto do formulário...
        $this->display_form_part3($location, $is_edit, $opening_hours, $weekdays);
    }

    private function display_form_part3($location, $is_edit, $opening_hours, $weekdays) {
        ?>
        <!-- Endereço -->
        <div class="c2p-form-section">
            <h2><?php esc_html_e('Endereço', 'click2pickup'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="address"><?php esc_html_e('Endereço Completo', 'click2pickup'); ?></label></th>
                    <td>
                        <input type="text" name="address" id="address" class="large-text" 
                               value="<?php echo $location ? esc_attr((string) $location->address) : ''; ?>"
                               placeholder="<?php esc_attr_e('Ex: Rua das Flores, 123 - Centro', 'click2pickup'); ?>">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="city"><?php esc_html_e('Cidade', 'click2pickup'); ?></label></th>
                    <td>
                        <input type="text" name="city" id="city" class="regular-text" 
                               value="<?php echo $location ? esc_attr((string) $location->city) : ''; ?>">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="state"><?php esc_html_e('Estado', 'click2pickup'); ?></label></th>
                    <td>
                        <select name="state" id="state">
                            <option value="">— <?php esc_html_e('Selecione', 'click2pickup'); ?> —</option>
                            <?php
                            $states = array(
                                'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
                                'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
                                'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão',
                                'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
                                'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco',
                                'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
                                'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima',
                                'SC' => 'Santa Catarina', 'SP' => 'São Paulo', 'SE' => 'Sergipe',
                                'TO' => 'Tocantins'
                            );
                            
                            $current_state = $location ? (string) $location->state : '';
                            foreach ($states as $code => $name) {
                                ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_state, $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                                <?php
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="zip_code"><?php esc_html_e('CEP', 'click2pickup'); ?></label></th>
                    <td>
                        <input type="text" name="zip_code" id="zip_code" class="regular-text" 
                               value="<?php echo $location ? esc_attr((string) $location->zip_code) : ''; ?>"
                               placeholder="00000-000" maxlength="9">
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Horários de Funcionamento -->
        <div class="c2p-form-section">
            <h2><?php esc_html_e('Horários de Funcionamento', 'click2pickup'); ?></h2>
            
            <div class="c2p-hours-grid">
                <?php foreach ($weekdays as $day => $label) : 
                    $day_hours = isset($opening_hours[$day]) ? $opening_hours[$day] : array();
                    $is_closed = isset($day_hours['closed']) && $day_hours['closed'];
                ?>
                    <div class="c2p-day-card" data-day="<?php echo esc_attr($day); ?>">
                        <div class="c2p-day-header">
                            <h4><?php echo esc_html($label); ?></h4>
                            <label class="c2p-switch">
                                <input type="checkbox" 
                                       name="hours[<?php echo esc_attr($day); ?>][closed]" 
                                       class="day-closed" 
                                       value="1"
                                       <?php checked($is_closed, true); ?>>
                                <span class="c2p-switch-slider"></span>
                                <span class="c2p-switch-label"><?php esc_html_e('Fechado', 'click2pickup'); ?></span>
                            </label>
                        </div>
                        
                        <div class="c2p-day-hours" <?php echo $is_closed ? 'style="display:none;"' : ''; ?>>
                            <div class="c2p-hour-row">
                                <label><?php esc_html_e('Abertura', 'click2pickup'); ?>:</label>
                                <input type="time" 
                                       name="hours[<?php echo esc_attr($day); ?>][open]" 
                                       value="<?php echo isset($day_hours['open']) ? esc_attr($day_hours['open']) : '09:00'; ?>">
                            </div>
                            
                            <div class="c2p-hour-row">
                                <label><?php esc_html_e('Fechamento', 'click2pickup'); ?>:</label>
                                <input type="time" 
                                       name="hours[<?php echo esc_attr($day); ?>][close]" 
                                       value="<?php echo isset($day_hours['close']) ? esc_attr($day_hours['close']) : '18:00'; ?>">
                            </div>
                            
                            <div class="c2p-hour-row">
                                <label><?php esc_html_e('Tempo Preparo', 'click2pickup'); ?>:</label>
                                <input type="number" 
                                       name="hours[<?php echo esc_attr($day); ?>][prep_time]" 
                                       value="<?php echo isset($day_hours['prep_time']) ? esc_attr($day_hours['prep_time']) : '60'; ?>"
                                       min="0" max="1440" step="15">
                                <span class="c2p-unit"><?php esc_html_e('min', 'click2pickup'); ?></span>
                            </div>
                            
                            <div class="c2p-hour-row">
                                <label><?php esc_html_e('Horário Corte', 'click2pickup'); ?>:</label>
                                <input type="time" 
                                       name="hours[<?php echo esc_attr($day); ?>][cutoff]" 
                                       value="<?php echo isset($day_hours['cutoff']) ? esc_attr($day_hours['cutoff']) : '17:00'; ?>">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="c2p-hours-actions">
                <button type="button" id="copy-weekdays" class="button">
                    <?php esc_html_e('Copiar Segunda para dias úteis', 'click2pickup'); ?>
                </button>
                <button type="button" id="copy-all-days" class="button">
                    <?php esc_html_e('Copiar Segunda para todos', 'click2pickup'); ?>
                </button>
            </div>
        </div>
                <!-- Dias Especiais / Feriados COM TODOS OS CAMPOS -->
        <div class="c2p-form-section">
            <h2><?php esc_html_e('Dias Especiais / Feriados', 'click2pickup'); ?></h2>
            <p class="description">
                <?php esc_html_e('Dias especiais sobrescrevem os horários normais. Se marcado como fechado, a loja não funcionará neste dia.', 'click2pickup'); ?>
            </p>
            
            <div id="c2p-special-days" style="margin-top: 20px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 12%"><?php esc_html_e('Data', 'click2pickup'); ?></th>
                            <th style="width: 18%"><?php esc_html_e('Descrição', 'click2pickup'); ?></th>
                            <th style="width: 10%"><?php esc_html_e('Status', 'click2pickup'); ?></th>
                            <th style="width: 18%"><?php esc_html_e('Horário', 'click2pickup'); ?></th>
                            <th style="width: 15%"><?php esc_html_e('Tempo Preparo', 'click2pickup'); ?></th>
                            <th style="width: 12%"><?php esc_html_e('Horário Corte', 'click2pickup'); ?></th>
                            <th style="width: 8%"><?php esc_html_e('Anual', 'click2pickup'); ?></th>
                            <th style="width: 10%"><?php esc_html_e('Ações', 'click2pickup'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="special-days-list">
                        <?php
                        $special_days = isset($opening_hours['special_days']) ? $opening_hours['special_days'] : array();
                        if (!empty($special_days)) :
                            foreach ($special_days as $index => $special) :
                                $is_closed = isset($special['status']) && $special['status'] === 'closed';
                        ?>
                            <tr>
                                <td>
                                    <input type="text" name="special_days[<?php echo esc_attr($index); ?>][date]" 
                                           value="<?php echo isset($special['date']) ? esc_attr($special['date']) : ''; ?>" 
                                           class="datepicker" readonly placeholder="Selecione" style="width: 100%;">
                                </td>
                                <td>
                                    <input type="text" name="special_days[<?php echo esc_attr($index); ?>][description]" 
                                           value="<?php echo isset($special['description']) ? esc_attr($special['description']) : ''; ?>"
                                           placeholder="Ex: Natal" style="width: 100%;">
                                </td>
                                <td>
                                    <select name="special_days[<?php echo esc_attr($index); ?>][status]" class="special-day-status">
                                        <option value="closed" <?php selected($special['status'] ?? '', 'closed'); ?>>
                                            <?php esc_html_e('Fechado', 'click2pickup'); ?>
                                        </option>
                                        <option value="open" <?php selected($special['status'] ?? '', 'open'); ?>>
                                            <?php esc_html_e('Aberto', 'click2pickup'); ?>
                                        </option>
                                    </select>
                                </td>
                                <td class="special-day-hours">
                                    <input type="time" name="special_days[<?php echo esc_attr($index); ?>][open]" 
                                           value="<?php echo isset($special['open']) ? esc_attr($special['open']) : ''; ?>"
                                           <?php echo $is_closed ? 'disabled' : ''; ?> style="width: 45%;"> -
                                    <input type="time" name="special_days[<?php echo esc_attr($index); ?>][close]" 
                                           value="<?php echo isset($special['close']) ? esc_attr($special['close']) : ''; ?>"
                                           <?php echo $is_closed ? 'disabled' : ''; ?> style="width: 45%;">
                                </td>
                                <td class="special-day-prep">
                                    <input type="number" name="special_days[<?php echo esc_attr($index); ?>][prep_time]" 
                                           value="<?php echo isset($special['prep_time']) ? esc_attr($special['prep_time']) : '60'; ?>"
                                           min="0" max="1440" step="15" style="width: 60px;"
                                           <?php echo $is_closed ? 'disabled' : ''; ?>> min
                                </td>
                                <td class="special-day-cutoff">
                                    <input type="time" name="special_days[<?php echo esc_attr($index); ?>][cutoff]" 
                                           value="<?php echo isset($special['cutoff']) ? esc_attr($special['cutoff']) : ''; ?>"
                                           <?php echo $is_closed ? 'disabled' : ''; ?>>
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="special_days[<?php echo esc_attr($index); ?>][recurring]" 
                                           value="1" <?php checked($special['recurring'] ?? 0, 1); ?>>
                                </td>
                                <td>
                                    <button type="button" class="button button-small remove-special-day">
                                        <?php esc_html_e('Remover', 'click2pickup'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </tbody>
                </table>
                
                <p style="margin-top: 10px;">
                    <button type="button" id="add-special-day" class="button button-secondary">
                        <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Adicionar Dia Especial', 'click2pickup'); ?>
                    </button>
                </p>
            </div>
        </div>
        
        <?php submit_button($is_edit ? __('Atualizar Local', 'click2pickup') : __('Adicionar Local', 'click2pickup')); ?>
        <?php
    }
    
    /**
     * Salva o local - VERSÃO MELHORADA
     */
    public function save_location() {
        // Verificar se é requisição AJAX
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        // Verificar nonce
        if (!isset($_POST['c2p_location_nonce']) || !wp_verify_nonce($_POST['c2p_location_nonce'], 'c2p_save_location')) {
            if ($is_ajax) {
                wp_send_json_error(array('message' => __('Ação não autorizada', 'click2pickup')));
            }
            wp_die(__('Ação não autorizada', 'click2pickup'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'c2p_locations';
        
        // Processar horários
        $opening_hours = array();
        if (isset($_POST['hours'])) {
            foreach ($_POST['hours'] as $day => $hours) {
                $opening_hours[$day] = array(
                    'closed' => isset($hours['closed']) && $hours['closed'] == '1',
                    'open' => isset($hours['open']) ? sanitize_text_field($hours['open']) : '09:00',
                    'close' => isset($hours['close']) ? sanitize_text_field($hours['close']) : '18:00',
                    'prep_time' => isset($hours['prep_time']) ? intval($hours['prep_time']) : 60,
                    'cutoff' => isset($hours['cutoff']) ? sanitize_text_field($hours['cutoff']) : '17:00'
                );
            }
        }
        
        // Processar dias especiais COM NOVOS CAMPOS
        if (isset($_POST['special_days']) && is_array($_POST['special_days'])) {
            $opening_hours['special_days'] = array();
            foreach ($_POST['special_days'] as $special) {
                if (!empty($special['date'])) {
                    $opening_hours['special_days'][] = array(
                        'date' => sanitize_text_field($special['date']),
                        'description' => isset($special['description']) ? sanitize_text_field($special['description']) : '',
                        'status' => isset($special['status']) ? sanitize_text_field($special['status']) : 'closed',
                        'open' => isset($special['open']) ? sanitize_text_field($special['open']) : '',
                        'close' => isset($special['close']) ? sanitize_text_field($special['close']) : '',
                        'prep_time' => isset($special['prep_time']) ? intval($special['prep_time']) : 60,
                        'cutoff' => isset($special['cutoff']) ? sanitize_text_field($special['cutoff']) : '',
                        'recurring' => isset($special['recurring']) ? 1 : 0
                    );
                }
            }
        }
        
        // Processar zonas e métodos de entrega
        $shipping_zones = isset($_POST['shipping_zones']) ? array_map('intval', $_POST['shipping_zones']) : array();
        $shipping_methods = isset($_POST['shipping_methods']) ? array_map('sanitize_text_field', $_POST['shipping_methods']) : array();
        
        $data = array(
            'name' => sanitize_text_field((string) $_POST['name']),
            'type' => sanitize_text_field((string) $_POST['type']),
            'slug' => sanitize_title((string) $_POST['name']),
            'address' => isset($_POST['address']) ? sanitize_text_field((string) $_POST['address']) : '',
            'city' => isset($_POST['city']) ? sanitize_text_field((string) $_POST['city']) : '',
            'state' => isset($_POST['state']) ? sanitize_text_field((string) $_POST['state']) : '',
            'zip_code' => isset($_POST['zip_code']) ? sanitize_text_field((string) $_POST['zip_code']) : '',
            'phone' => isset($_POST['phone']) ? sanitize_text_field((string) $_POST['phone']) : '',
            'email' => isset($_POST['email']) ? sanitize_email((string) $_POST['email']) : '',
            'image_id' => isset($_POST['image_id']) ? intval($_POST['image_id']) : 0,
            'opening_hours' => json_encode($opening_hours),
            'shipping_zones' => json_encode($shipping_zones),
            'shipping_methods' => json_encode($shipping_methods),
            'pickup_enabled' => isset($_POST['pickup_enabled']) ? 1 : 0,
            'delivery_enabled' => isset($_POST['delivery_enabled']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        );
        
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        
        if ($location_id) {
            $result = $wpdb->update($table_name, $data, array('id' => $location_id));
            $message = 'updated';
        } else {
            $result = $wpdb->insert($table_name, $data);
            $location_id = $wpdb->insert_id;
            $message = 'added';
        }
        
        if ($is_ajax) {
            if ($result !== false) {
                wp_send_json_success(array(
                    'message' => __('Local salvo com sucesso!', 'click2pickup'),
                    'location_id' => $location_id
                ));
            } else {
                wp_send_json_error(array('message' => $wpdb->last_error));
            }
        } else {
            // Redirecionar para a página de edição ao invés da lista
            wp_redirect(admin_url('admin.php?page=c2p-locations&action=edit&id=' . $location_id . '&message=' . $message));
            exit;
        }
    }
    
    /**
     * Salva o local via AJAX
     */
    public function save_location_ajax() {
        // Verificar nonce
        if (!isset($_POST['c2p_location_nonce']) || !wp_verify_nonce($_POST['c2p_location_nonce'], 'c2p_save_location')) {
            wp_send_json_error(array('message' => __('Ação não autorizada', 'click2pickup')));
        }
        
        // Chamar o método save_location que já processa tudo
        $this->save_location();
    }
    
    /**
     * Exclui um local
     */
    public function delete_location() {
        if (!isset($_GET['id']) || !isset($_GET['_wpnonce'])) {
            wp_die(__('Ação não autorizada', 'click2pickup'));
        }
        
        $location_id = intval($_GET['id']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_location_' . $location_id)) {
            wp_die(__('Ação não autorizada', 'click2pickup'));
        }
        
        global $wpdb;
        
        // Verificar se há estoque associado
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $has_stock = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $stock_table WHERE location_id = %d AND stock_quantity > 0",
            $location_id
        ));
        
        if ($has_stock > 0) {
            wp_redirect(admin_url('admin.php?page=c2p-locations&message=error&error=has_stock'));
            exit;
        }
        
        // Deletar registros relacionados
        $wpdb->delete($stock_table, array('location_id' => $location_id));
        
        // Deletar o local
        $table_name = $wpdb->prefix . 'c2p_locations';
        $wpdb->delete($table_name, array('id' => $location_id));
        
        wp_redirect(admin_url('admin.php?page=c2p-locations&message=deleted'));
        exit;
    }
}