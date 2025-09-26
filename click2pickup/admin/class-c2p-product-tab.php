<?php
/**
 * Adiciona aba de estoque por local na página do produto
 * Versão 12 - Completa e corrigida
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Product_Tab {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Adicionar aba personalizada APENAS para produtos simples
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_tab_content'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_tab_data'));
        
        // Para produtos variáveis - adicionar campos nas variações
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_stock_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_stock_fields'), 10, 2);
        
        // Adicionar coluna na listagem de produtos
        add_filter('manage_product_posts_columns', array($this, 'add_stock_column'));
        add_action('manage_product_posts_custom_column', array($this, 'display_stock_column'), 10, 2);
        
        // Scripts e estilos
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_head', array($this, 'add_inline_styles'));
        add_action('admin_footer', array($this, 'add_inline_scripts'));
    }
    
    /**
     * Adiciona scripts
     */
    public function enqueue_scripts($hook) {
        if (!$this->is_product_page($hook)) {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    /**
     * Adiciona estilos inline
     */
    public function add_inline_styles() {
        if (!$this->is_product_page()) {
            return;
        }
        ?>
        <style type="text/css">
        /* Click2Pickup - Enhanced Product Tab Styles */
        #c2p_stock_data {
            padding: 0 !important;
            background: #f8f9ff !important;
        }
        
        #c2p_stock_data .c2p-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px;
            margin: 0;
            color: white;
            border-radius: 0;
        }
        
        #c2p_stock_data .c2p-header h3 {
            color: white !important;
            margin: 0 0 8px 0 !important;
            font-size: 18px !important;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        #c2p_stock_data .c2p-header p {
            color: rgba(255,255,255,0.9);
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
        }
        
        #c2p_stock_data .c2p-actions-bar {
            background: white;
            padding: 15px 20px;
            margin: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e5e7ff;
        }
        
        #c2p_stock_data .c2p-bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        #c2p_stock_data .c2p-bulk-actions strong {
            color: #2c3e50;
            font-size: 14px;
        }
        
        #c2p_stock_data .c2p-bulk-actions input[type="number"] {
            width: 120px;
            padding: 8px 12px;
            border: 2px solid #e5e7ff;
            border-radius: 6px;
            font-size: 14px;
        }
        
        #c2p_stock_data .c2p-bulk-actions input[type="number"]:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
            outline: none;
        }
        
        #c2p_stock_data .c2p-bulk-actions .button {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        #c2p_stock_data .c2p-bulk-actions .button:hover {
            background: #5568d3;
            transform: translateY(-1px);
        }
        
        #c2p_stock_data .c2p-main-content {
            padding: 20px;
        }
        
        #c2p_stock_data .c2p-locations-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }
        
        #c2p_stock_data .c2p-locations-table thead {
            background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        #c2p_stock_data .c2p-locations-table th {
            padding: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #dee2e6;
            text-align: left;
        }
        
        #c2p_stock_data .c2p-locations-table tbody tr {
            transition: all 0.2s;
            border-bottom: 1px solid #f1f3f5;
        }
        
        #c2p_stock_data .c2p-locations-table tbody tr:hover {
            background: #f8f9ff;
        }
        
        #c2p_stock_data .c2p-locations-table tbody tr:last-child {
            border-bottom: none;
        }
        
        #c2p_stock_data .c2p-locations-table td {
            padding: 15px;
            vertical-align: middle;
            color: #495057;
        }
        
        #c2p_stock_data .c2p-location-icon {
            font-size: 20px;
            display: inline-block;
        }
        
        #c2p_stock_data .c2p-location-name strong {
            font-size: 14px;
            color: #2c3e50;
            display: block;
        }
        
        #c2p_stock_data .c2p-location-address {
            font-size: 12px;
            color: #6c757d;
            margin-top: 3px;
        }
        
        #c2p_stock_data .c2p-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        #c2p_stock_data .c2p-badge-cd {
            background: linear-gradient(135deg, #e91e63, #c2185b);
            color: white;
        }
        
        #c2p_stock_data .c2p-badge-store {
            background: linear-gradient(135deg, #4caf50, #388e3c);
            color: white;
        }
        
        #c2p_stock_data .c2p-stock-input {
            width: 80px;
            text-align: center;
            padding: 8px;
            border: 2px solid #e5e7ff;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        #c2p_stock_data .c2p-stock-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
            outline: none;
        }
        
        #c2p_stock_data .c2p-threshold-input {
            width: 60px;
            text-align: center;
            padding: 6px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        #c2p_stock_data .c2p-threshold-input:focus {
            border-color: #667eea;
            outline: none;
        }
        
        #c2p_stock_data .c2p-stock-available {
            font-weight: 700;
            font-size: 14px;
        }
        
        #c2p_stock_data .c2p-stock-available.in-stock {
            color: #4caf50;
        }
        
        #c2p_stock_data .c2p-stock-available.low-stock {
            color: #ff9800;
        }
        
        #c2p_stock_data .c2p-stock-available.out-stock {
            color: #f44336;
        }
        
        #c2p_stock_data .c2p-total-row {
            background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 600;
        }
        
        #c2p_stock_data .c2p-total-row td {
            padding: 18px 15px;
            border-top: 3px solid #667eea;
            font-size: 15px;
            color: #2c3e50;
        }
        
        #c2p_stock_data .c2p-total-stock {
            font-size: 24px;
            color: #667eea;
            font-weight: 700;
        }
        
        #c2p_stock_data .c2p-global-stock {
            color: #667eea;
            font-weight: 700;
            font-size: 20px;
            margin: 0 5px;
        }
        
        #c2p_stock_data .c2p-info-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px 25px;
            border-radius: 10px;
            margin-top: 30px;
            color: white;
            box-shadow: 0 4px 12px rgba(102,126,234,0.3);
        }
        
        #c2p_stock_data .c2p-info-box h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: white;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        #c2p_stock_data .c2p-info-box ul {
            margin: 10px 0 0 0;
            padding-left: 0;
            list-style: none;
        }
        
        #c2p_stock_data .c2p-info-box li {
            margin: 10px 0;
            padding-left: 25px;
            position: relative;
            line-height: 1.6;
            color: rgba(255,255,255,0.95);
        }
        
        #c2p_stock_data .c2p-info-box li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #84fab0;
            font-weight: bold;
            font-size: 16px;
        }
        
        #c2p_stock_data .c2p-no-locations {
            text-align: center;
            padding: 60px 20px;
            background: linear-gradient(135deg, #ffeaa7 0%, #fdcb6e 100%);
            border-radius: 10px;
            margin: 20px;
        }
        
        #c2p_stock_data .c2p-no-locations h3 {
            color: #2d3436;
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        #c2p_stock_data .c2p-no-locations p {
            color: #636e72;
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        #c2p_stock_data .c2p-no-locations .button-primary {
            background: #2d3436;
            border: none;
            padding: 12px 30px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .c2p-variation-wrapper {
            margin: 20px 0;
            border: 2px solid #667eea;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .c2p-variation-wrapper h4 {
            margin: 0 !important;
            padding: 12px 15px !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            color: white !important;
            font-size: 14px;
        }
        
        #c2p-stock-notice {
            background: linear-gradient(135deg, #ffeaa7 0%, #fdcb6e 100%);
            color: #2d3436;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 13px;
            font-weight: 500;
        }
        
        /* Centralizar valores nas colunas específicas */
        #c2p_stock_data .c2p-locations-table td.text-center {
            text-align: center;
        }
        
        #c2p_stock_data .c2p-locations-table th.text-center {
            text-align: center;
        }
        </style>
        <?php
    }
    
    /**
     * Adiciona aba ao produto - APENAS para produtos simples
     */
    public function add_product_tab($tabs) {
        $tabs['c2p_stock'] = array(
            'label' => __('📍 Estoque por Local', 'click2pickup'),
            'target' => 'c2p_stock_data',
            'class' => array('show_if_simple'), // APENAS produtos simples
            'priority' => 21
        );
        
        return $tabs;
    }
    
    /**
     * Conteúdo da aba - APENAS para produtos simples
     */
    public function add_product_tab_content() {
        global $post, $wpdb;
        
        $product_id = $post->ID;
        $product = wc_get_product($product_id);
        
        // Se não for produto simples, não mostrar nada
        if (!$product || !$product->is_type('simple')) {
            return;
        }
        
        // Buscar locais
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $locations = $wpdb->get_results(
            "SELECT * FROM $locations_table WHERE is_active = 1 ORDER BY type DESC, name ASC"
        );
        
        ?>
        <div id="c2p_stock_data" class="panel woocommerce_options_panel">
            <div class="c2p-header">
                <h3>
                    <span class="dashicons dashicons-location" style="font-size: 24px;"></span>
                    <?php esc_html_e('Gerenciamento de Estoque Multi-Local', 'click2pickup'); ?>
                </h3>
                <p><?php esc_html_e('Configure o estoque deste produto em cada local (CD ou Loja). O sistema calculará automaticamente o estoque total.', 'click2pickup'); ?></p>
            </div>
            
            <?php if (empty($locations)) : ?>
                <div class="c2p-no-locations">
                    <h3>🏪 <?php esc_html_e('Nenhum local cadastrado', 'click2pickup'); ?></h3>
                    <p><?php esc_html_e('Você precisa cadastrar pelo menos um local antes de gerenciar o estoque.', 'click2pickup'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=c2p-locations&action=new'); ?>" class="button button-primary">
                        <?php esc_html_e('Cadastrar Primeiro Local', 'click2pickup'); ?>
                    </a>
                </div>
            <?php else : ?>
                
                <!-- Barra de ações rápidas -->
                <div class="c2p-actions-bar">
                    <div class="c2p-bulk-actions">
                        <strong><?php esc_html_e('Ações Rápidas:', 'click2pickup'); ?></strong>
                        <input type="number" id="c2p_bulk_stock_value" placeholder="<?php esc_attr_e('Quantidade', 'click2pickup'); ?>" min="0">
                        <button type="button" class="button" onclick="c2pSetAllStock()">
                            <?php esc_html_e('Aplicar a Todos', 'click2pickup'); ?>
                        </button>
                        <button type="button" class="button" onclick="c2pClearAllStock()">
                            <?php esc_html_e('Zerar Todos', 'click2pickup'); ?>
                        </button>
                    </div>
                    <?php 
                    // Calcular estoque total atual
                    $stock_table = $wpdb->prefix . 'c2p_stock';
                    $global_stock = $wpdb->get_var($wpdb->prepare(
                        "SELECT SUM(stock_quantity) FROM $stock_table WHERE product_id = %d",
                        $product_id
                    ));
                    ?>
                    <div>
                        <strong><?php esc_html_e('Estoque Global:', 'click2pickup'); ?></strong>
                        <span class="c2p-global-stock"><?php echo intval($global_stock); ?></span>
                        <?php esc_html_e('unidades', 'click2pickup'); ?>
                    </div>
                </div>
                
                <div class="c2p-main-content">
                    <?php $this->render_locations_table($product_id, $locations); ?>
                    <?php $this->render_info_box(); ?>
                </div>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Renderiza tabela de locais
     */
    private function render_locations_table($product_id, $locations) {
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        ?>
        <table class="c2p-locations-table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th><?php esc_html_e('Local', 'click2pickup'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Tipo', 'click2pickup'); ?></th>
                    <th class="text-center" style="width: 120px;"><?php esc_html_e('Estoque', 'click2pickup'); ?></th>
                    <th class="text-center" style="width: 100px;"><?php esc_html_e('Reservado', 'click2pickup'); ?></th>
                    <th class="text-center" style="width: 100px;"><?php esc_html_e('Disponível', 'click2pickup'); ?></th>
                    <th class="text-center" style="width: 100px;"><?php esc_html_e('Mínimo', 'click2pickup'); ?></th>
                    <th class="text-center" style="width: 120px;"><?php esc_html_e('Backorder', 'click2pickup'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_stock = 0;
                $total_reserved = 0;
                
                foreach ($locations as $location) : 
                    $stock = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $stock_table WHERE product_id = %d AND location_id = %d",
                        $product_id,
                        $location->id
                    ));
                    
                    $stock_qty = $stock ? intval($stock->stock_quantity) : 0;
                    $reserved = $stock ? intval($stock->reserved_quantity) : 0;
                    $available = $stock_qty - $reserved;
                    $low_threshold = $stock ? intval($stock->low_stock_threshold) : 5;
                    // Verificar se a propriedade existe antes de acessar
                    $allow_backorder = ($stock && isset($stock->allow_backorder)) ? intval($stock->allow_backorder) : 0;
                    
                    $total_stock += $stock_qty;
                    $total_reserved += $reserved;
                    
                    // Determinar classe de status
                    $status_class = 'in-stock';
                    if ($available <= 0) {
                        $status_class = 'out-stock';
                    } elseif ($available <= $low_threshold) {
                        $status_class = 'low-stock';
                    }
                ?>
                    <tr>
                        <td style="text-align: center;">
                            <?php if ($location->type == 'distribution_center') : ?>
                                <span class="c2p-location-icon" style="color: #e91e63;">🏭</span>
                            <?php else : ?>
                                <span class="c2p-location-icon" style="color: #4caf50;">🏪</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="c2p-location-name">
                                <strong><?php echo esc_html($location->name); ?></strong>
                                <?php if ($location->city) : ?>
                                    <div class="c2p-location-address">
                                        <?php echo esc_html($location->city . ', ' . $location->state); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($location->type == 'distribution_center') : ?>
                                <span class="c2p-badge c2p-badge-cd">CD</span>
                            <?php else : ?>
                                <span class="c2p-badge c2p-badge-store">LOJA</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <input type="number" 
                                   name="c2p_stock[<?php echo $location->id; ?>][quantity]" 
                                   class="c2p-stock-input"
                                   value="<?php echo $stock_qty; ?>" 
                                   min="0"
                                   data-location="<?php echo $location->id; ?>">
                        </td>
                        <td class="text-center">
                            <span style="color: #ff9800; font-weight: 600;">
                                <?php echo $reserved; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="c2p-stock-available <?php echo $status_class; ?>">
                                <?php echo $available; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <input type="number" 
                                   name="c2p_stock[<?php echo $location->id; ?>][low_threshold]" 
                                   class="c2p-threshold-input"
                                   value="<?php echo $low_threshold; ?>" 
                                   min="0">
                        </td>
                        <td class="text-center">
                            <input type="checkbox" 
                                   name="c2p_stock[<?php echo $location->id; ?>][allow_backorder]" 
                                   class="c2p-backorder"
                                   value="1"
                                   <?php checked($allow_backorder, 1); ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="c2p-total-row">
                    <td colspan="3">
                        <strong>📊 <?php esc_html_e('Total Geral:', 'click2pickup'); ?></strong>
                    </td>
                    <td class="text-center">
                        <span class="c2p-total-stock"><?php echo $total_stock; ?></span>
                    </td>
                    <td class="text-center">
                        <span style="color: #ff9800; font-weight: 600;"><?php echo $total_reserved; ?></span>
                    </td>
                    <td class="text-center">
                        <span style="color: #667eea; font-weight: 700; font-size: 18px;">
                            <?php echo ($total_stock - $total_reserved); ?>
                        </span>
                    </td>
                    <td colspan="2" style="text-align: right;">
                        <em>🔄 <?php esc_html_e('Sincronizado com WooCommerce', 'click2pickup'); ?></em>
                    </td>
                </tr>
            </tfoot>
        </table>
        <?php
    }
    
    /**
     * Renderiza caixa de informações
     */
    private function render_info_box() {
        ?>
        <div class="c2p-info-box">
            <h4>
                <span style="font-size: 20px;">💡</span>
                <?php esc_html_e('Informações Importantes', 'click2pickup'); ?>
            </h4>
            <ul>
                <li><?php esc_html_e('O estoque total é calculado automaticamente somando todos os locais ativos', 'click2pickup'); ?></li>
                <li><?php esc_html_e('Produtos reservados são automaticamente deduzidos quando pedidos são processados', 'click2pickup'); ?></li>
                <li><?php esc_html_e('Ative "Backorder" para permitir vendas mesmo sem estoque disponível', 'click2pickup'); ?></li>
                <li><?php esc_html_e('O estoque mínimo dispara alertas automáticos quando atingido', 'click2pickup'); ?></li>
                <li><?php esc_html_e('Todas as alterações são registradas para auditoria completa', 'click2pickup'); ?></li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Adiciona campos para variações
     */
    public function add_variation_stock_fields($loop, $variation_data, $variation) {
        global $wpdb;
        
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $locations = $wpdb->get_results(
            "SELECT * FROM $locations_table WHERE is_active = 1 ORDER BY type DESC, name ASC"
        );
        
        if (empty($locations)) {
            return;
        }
        
        ?>
        <div class="c2p-variation-wrapper">
            <h4>📍 <?php esc_html_e('Estoque por Local (Click2Pickup)', 'click2pickup'); ?></h4>
            <div style="padding: 15px; background: #f8f9ff;">
                <?php $this->render_variation_stock_table($variation->ID, $locations); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderiza tabela de estoque para variação
     */
    private function render_variation_stock_table($variation_id, $locations) {
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        ?>
        <table class="widefat" style="margin: 0;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Local', 'click2pickup'); ?></th>
                    <th><?php esc_html_e('Estoque', 'click2pickup'); ?></th>
                    <th><?php esc_html_e('Mínimo', 'click2pickup'); ?></th>
                    <th><?php esc_html_e('Backorder', 'click2pickup'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $location) : 
                    $stock = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $stock_table WHERE product_id = %d AND location_id = %d",
                        $variation_id,
                        $location->id
                    ));
                    
                    $stock_qty = $stock ? intval($stock->stock_quantity) : 0;
                    $low_threshold = $stock ? intval($stock->low_stock_threshold) : 5;
                    $allow_backorder = ($stock && isset($stock->allow_backorder)) ? intval($stock->allow_backorder) : 0;
                ?>
                    <tr>
                        <td>
                            <?php echo esc_html($location->name); ?>
                            <?php if ($location->type == 'distribution_center') : ?>
                                <span style="color: #e91e63;">(CD)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="number" 
                                   name="variable_c2p_stock[<?php echo $variation_id; ?>][<?php echo $location->id; ?>][quantity]" 
                                   value="<?php echo $stock_qty; ?>" 
                                   min="0"
                                   style="width: 60px;">
                        </td>
                        <td>
                            <input type="number" 
                                   name="variable_c2p_stock[<?php echo $variation_id; ?>][<?php echo $location->id; ?>][low_threshold]" 
                                   value="<?php echo $low_threshold; ?>" 
                                   min="0"
                                   style="width: 60px;">
                        </td>
                        <td>
                            <input type="checkbox" 
                                   name="variable_c2p_stock[<?php echo $variation_id; ?>][<?php echo $location->id; ?>][allow_backorder]" 
                                   value="1"
                                   <?php checked($allow_backorder, 1); ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Salva dados da variação
     */
    public function save_variation_stock_fields($variation_id, $i) {
        if (isset($_POST['variable_c2p_stock'][$variation_id])) {
            $this->save_stock_data($variation_id, $_POST['variable_c2p_stock'][$variation_id]);
        }
    }
    
    /**
     * Salva dados do produto simples
     */
    public function save_product_tab_data($post_id) {
        // Verificar se é produto simples
        $product = wc_get_product($post_id);
        if (!$product || !$product->is_type('simple')) {
            return;
        }
        
        if (isset($_POST['c2p_stock'])) {
            $this->save_stock_data($post_id, $_POST['c2p_stock']);
        }
    }
    
    /**
     * Método genérico para salvar estoque
     */
    private function save_stock_data($product_id, $stock_data) {
        global $wpdb;
        
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $movements_table = $wpdb->prefix . 'c2p_stock_movements';
        
        // Verificar se a tabela de movimentos existe
        $movements_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$movements_table'") === $movements_table;
        
        $total_stock = 0;
        
        foreach ($stock_data as $location_id => $data) {
            $location_id = intval($location_id);
            $quantity = isset($data['quantity']) ? intval($data['quantity']) : 0;
            $low_threshold = isset($data['low_threshold']) ? intval($data['low_threshold']) : 5;
            $allow_backorder = isset($data['allow_backorder']) ? 1 : 0;
            
            // Verificar estoque anterior
            $old_stock = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $stock_table WHERE product_id = %d AND location_id = %d",
                $product_id,
                $location_id
            ));
            
            // Preparar dados para salvar
            $stock_data_to_save = array(
                'stock_quantity' => $quantity,
                'low_stock_threshold' => $low_threshold
            );
            
            // Só adicionar allow_backorder se a coluna existir
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $stock_table");
            if (in_array('allow_backorder', $columns)) {
                $stock_data_to_save['allow_backorder'] = $allow_backorder;
            }
            
            // Inserir ou atualizar
            if ($old_stock) {
                $old_qty = intval($old_stock->stock_quantity);
                
                $wpdb->update(
                    $stock_table,
                    $stock_data_to_save,
                    array(
                        'product_id' => $product_id,
                        'location_id' => $location_id
                    )
                );
                
                // Registrar movimentação se mudou e a tabela existir
                if ($movements_table_exists && $old_qty != $quantity) {
                    $wpdb->insert($movements_table, array(
                        'location_id' => $location_id,
                        'product_id' => $product_id,
                        'type' => 'adjustment',
                        'quantity' => $quantity - $old_qty,
                        'user_id' => get_current_user_id(),
                        'notes' => sprintf(__('Ajuste manual: de %d para %d', 'click2pickup'), $old_qty, $quantity)
                    ));
                }
            } else {
                // Adicionar campos padrão para insert
                $stock_data_to_save['location_id'] = $location_id;
                $stock_data_to_save['product_id'] = $product_id;
                $stock_data_to_save['manage_stock'] = 1;
                
                $wpdb->insert($stock_table, $stock_data_to_save);
                
                // Registrar movimento inicial se a tabela existir
                if ($movements_table_exists && $quantity > 0) {
                    $wpdb->insert($movements_table, array(
                        'location_id' => $location_id,
                        'product_id' => $product_id,
                        'type' => 'adjustment',
                        'quantity' => $quantity,
                        'user_id' => get_current_user_id(),
                        'notes' => __('Estoque inicial', 'click2pickup')
                    ));
                }
            }
            
            $total_stock += $quantity;
        }
        
        // Atualizar meta do WooCommerce
        update_post_meta($product_id, '_stock', $total_stock);
        update_post_meta($product_id, '_manage_stock', 'yes');
        update_post_meta($product_id, '_stock_status', $total_stock > 0 ? 'instock' : 'outofstock');
    }
    
    /**
     * Adiciona coluna na listagem
     */
    public function add_stock_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'sku') {
                $new_columns['c2p_stock'] = __('📍 Estoque C2P', 'click2pickup');
            }
        }
        return $new_columns;
    }
    
    /**
     * Exibe conteúdo da coluna
     */
    public function display_stock_column($column, $post_id) {
        if ($column === 'c2p_stock') {
            global $wpdb;
            $stock_table = $wpdb->prefix . 'c2p_stock';
            
            $product = wc_get_product($post_id);
            
            // Se for produto variável, somar todas as variações
            if ($product && $product->is_type('variable')) {
                $variations = $product->get_children();
                $total = 0;
                $low_stock = 0;
                
                foreach ($variations as $variation_id) {
                    $var_total = $wpdb->get_var($wpdb->prepare(
                        "SELECT SUM(stock_quantity) FROM $stock_table WHERE product_id = %d",
                        $variation_id
                    ));
                    $total += intval($var_total);
                    
                    $var_low = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $stock_table 
                         WHERE product_id = %d AND stock_quantity <= low_stock_threshold AND stock_quantity > 0",
                        $variation_id
                    ));
                    $low_stock += intval($var_low);
                }
                
                echo '<strong style="color: ' . ($total > 0 ? '#4caf50' : '#f44336') . ';">';
                echo intval($total);
                echo '</strong>';
                echo '<br><small style="color: #666;">(' . count($variations) . ' variações)</small>';
                
                if ($low_stock > 0) {
                    echo '<br><span style="color: #ff9800; font-size: 11px;">⚠ ' . 
                         sprintf(__('%d local(is) baixo', 'click2pickup'), $low_stock) . 
                         '</span>';
                }
            } else {
                // Produto simples
                $total = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(stock_quantity) FROM $stock_table WHERE product_id = %d",
                    $post_id
                ));
                
                $low_stock = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $stock_table 
                     WHERE product_id = %d AND stock_quantity <= low_stock_threshold AND stock_quantity > 0",
                    $post_id
                ));
                
                echo '<strong style="color: ' . ($total > 0 ? '#4caf50' : '#f44336') . ';">';
                echo intval($total);
                echo '</strong>';
                
                if ($low_stock > 0) {
                    echo '<br><span style="color: #ff9800; font-size: 11px;">⚠ ' . 
                         sprintf(__('%d local(is) baixo', 'click2pickup'), $low_stock) . 
                         '</span>';
                }
            }
        }
    }
    
    /**
     * Scripts inline
     */
    public function add_inline_scripts() {
        if (!$this->is_product_page()) {
            return;
        }
        ?>
        <script type="text/javascript">
        function c2pSetAllStock() {
            var value = document.getElementById('c2p_bulk_stock_value').value;
            if (value) {
                document.querySelectorAll('.c2p-stock-input').forEach(function(input) {
                    input.value = value;
                });
                c2pUpdateTotalDisplay();
            }
        }
        
        function c2pClearAllStock() {
            if (confirm('<?php esc_html_e('Tem certeza que deseja zerar o estoque em todos os locais?', 'click2pickup'); ?>')) {
                document.querySelectorAll('.c2p-stock-input').forEach(function(input) {
                    input.value = 0;
                });
                c2pUpdateTotalDisplay();
            }
        }
        
        function c2pUpdateTotalDisplay() {
            var total = 0;
            document.querySelectorAll('.c2p-stock-input').forEach(function(input) {
                total += parseInt(input.value) || 0;
            });
            document.querySelectorAll('.c2p-total-stock').forEach(function(elem) {
                elem.textContent = total;
            });
            document.querySelectorAll('.c2p-global-stock').forEach(function(elem) {
                elem.textContent = total;
            });
        }
        
        jQuery(document).ready(function($) {
            // Atualizar total ao mudar valores
            $(document).on('input', '.c2p-stock-input', c2pUpdateTotalDisplay);
            
            // Desabilitar campo nativo de estoque
            $('#_stock').prop('readonly', true).css({
                'background-color': '#f0f0f0',
                'cursor': 'not-allowed'
            });
            
            if (!$('#_stock').next('#c2p-stock-notice').length) {
                $('#_stock').after('<p id="c2p-stock-notice">📦 <?php esc_html_e("Este campo é gerenciado pelo Click2Pickup. Use a aba Estoque por Local.", "click2pickup"); ?></p>');
            }
            
            // Para variações - desabilitar campos nativos
            $(document).on('woocommerce_variations_loaded', function() {
                $('input[id*="variable_stock"]').each(function() {
                    $(this).prop('readonly', true).css({
                        'background-color': '#f0f0f0',
                        'cursor': 'not-allowed'
                    });
                    
                    if (!$(this).next('.c2p-variation-notice').length) {
                        $(this).after('<small class="c2p-variation-notice" style="color: #667eea; display: block; margin-top: 5px;">📦 <?php esc_html_e("Gerenciado pelo Click2Pickup - Configure abaixo", "click2pickup"); ?></small>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Verifica se é página de produto
     */
    private function is_product_page($hook = null) {
        if (!$hook) {
            global $pagenow;
            $hook = $pagenow;
        }
        
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return false;
        }
        
        global $post;
        if ($post && isset($post->post_type)) {
            return $post->post_type === 'product';
        }
        
        if (isset($_GET['post'])) {
            return get_post_type($_GET['post']) === 'product';
        }
        
        return isset($_GET['post_type']) && $_GET['post_type'] === 'product';
    }
}