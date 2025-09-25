<?php
/**
 * Gerenciamento de estoque no admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Stock_Admin {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Hooks para a página de estoque
        add_action('admin_menu', array($this, 'add_menu_page'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Ajax handlers
        add_action('wp_ajax_c2p_update_stock', array($this, 'ajax_update_stock'));
        add_action('wp_ajax_c2p_bulk_update_stock', array($this, 'ajax_bulk_update_stock'));
        add_action('wp_ajax_c2p_import_stock', array($this, 'ajax_import_stock'));
    }
    
    /**
     * Adiciona página no menu (já adicionada pelo admin principal)
     */
    public function add_menu_page() {
        // A página já é adicionada pelo C2P_Admin
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'c2p-stock') === false) {
            return;
        }
        
        wp_enqueue_script('c2p-stock-admin', C2P_PLUGIN_URL . 'assets/js/stock-admin.js', array('jquery'), C2P_VERSION, true);
        wp_localize_script('c2p-stock-admin', 'c2p_stock', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('c2p_stock_nonce')
        ));
    }
    
    /**
     * Exibe a página de gerenciamento de estoque
     */
    public function display_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gerenciar Estoque', 'click2pickup'); ?></h1>
            
            <?php
            $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
            $this->display_tabs($tab);
            
            switch ($tab) {
                case 'by_location':
                    $this->display_by_location();
                    break;
                case 'by_product':
                    $this->display_by_product();
                    break;
                case 'movements':
                    $this->display_movements();
                    break;
                case 'import':
                    $this->display_import();
                    break;
                default:
                    $this->display_overview();
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Exibe as abas
     */
    private function display_tabs($current) {
        $tabs = array(
            'overview' => __('Visão Geral', 'click2pickup'),
            'by_location' => __('Por Local', 'click2pickup'),
            'by_product' => __('Por Produto', 'click2pickup'),
            'movements' => __('Movimentações', 'click2pickup'),
            'import' => __('Importar/Exportar', 'click2pickup')
        );
        
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $name) {
            $class = ($tab == $current) ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . $class . '" href="?page=c2p-stock&tab=' . $tab . '">' . $name . '</a>';
        }
        echo '</h2>';
    }
    
    /**
     * Visão geral do estoque
     */
    private function display_overview() {
        global $wpdb;
        
        $stock_table = $wpdb->prefix . 'c2p_stock';
        $locations_table = $wpdb->prefix . 'c2p_locations';
        
        // Estatísticas gerais
        $total_products = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM $stock_table");
        $total_stock = $wpdb->get_var("SELECT SUM(stock_quantity) FROM $stock_table");
        $low_stock_count = $wpdb->get_var("SELECT COUNT(*) FROM $stock_table WHERE stock_quantity <= low_stock_threshold");
        
        ?>
        <div class="c2p-dashboard-widgets">
            <div class="c2p-widget">
                <h3><?php esc_html_e('Total de Produtos', 'click2pickup'); ?></h3>
                <p class="c2p-big-number"><?php echo number_format($total_products); ?></p>
            </div>
            
            <div class="c2p-widget">
                <h3><?php esc_html_e('Estoque Total', 'click2pickup'); ?></h3>
                <p class="c2p-big-number"><?php echo number_format($total_stock); ?></p>
            </div>
            
            <div class="c2p-widget">
                <h3><?php esc_html_e('Produtos com Estoque Baixo', 'click2pickup'); ?></h3>
                <p class="c2p-big-number" style="color: #e91e63;"><?php echo number_format($low_stock_count); ?></p>
            </div>
        </div>
        
        <h2><?php esc_html_e('Produtos com Estoque Baixo', 'click2pickup'); ?></h2>
        <?php
        
        $low_stock_items = $wpdb->get_results("
            SELECT s.*, l.name as location_name 
            FROM $stock_table s
            JOIN $locations_table l ON s.location_id = l.id
            WHERE s.stock_quantity <= s.low_stock_threshold
            ORDER BY s.stock_quantity ASC
            LIMIT 20
        ");
        
        if ($low_stock_items) {
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Produto', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Local', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Estoque Atual', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Limite Mínimo', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Ações', 'click2pickup'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($low_stock_items as $item) : 
                        $product = wc_get_product($item->product_id);
                        if (!$product) continue;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                                <br><small>SKU: <?php echo esc_html($product->get_sku()); ?></small>
                            </td>
                            <td><?php echo esc_html($item->location_name); ?></td>
                            <td style="color: <?php echo $item->stock_quantity <= 0 ? 'red' : 'orange'; ?>">
                                <?php echo esc_html($item->stock_quantity); ?>
                            </td>
                            <td><?php echo esc_html($item->low_stock_threshold); ?></td>
                            <td>
                                <button class="button button-small c2p-quick-stock-update" 
                                        data-location="<?php echo esc_attr($item->location_id); ?>"
                                        data-product="<?php echo esc_attr($item->product_id); ?>">
                                    <?php esc_html_e('Atualizar', 'click2pickup'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>' . esc_html__('Nenhum produto com estoque baixo.', 'click2pickup') . '</p>';
        }
    }
    
    /**
     * Exibir estoque por local
     */
    private function display_by_location() {
        global $wpdb;
        
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $locations = $wpdb->get_results("SELECT * FROM $locations_table WHERE is_active = 1 ORDER BY name");
        
        $selected_location = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        ?>
        
        <form method="get" action="">
            <input type="hidden" name="page" value="c2p-stock">
            <input type="hidden" name="tab" value="by_location">
            
            <select name="location_id" onchange="this.form.submit()">
                <option value=""><?php esc_html_e('Selecione um local', 'click2pickup'); ?></option>
                <?php foreach ($locations as $location) : ?>
                    <option value="<?php echo esc_attr($location->id); ?>" <?php selected($selected_location, $location->id); ?>>
                        <?php echo esc_html($location->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        
        <?php
        if ($selected_location) {
            $this->display_location_stock($selected_location);
        }
    }
    
    /**
     * Exibe estoque de um local específico
     */
    private function display_location_stock($location_id) {
        global $wpdb;
        
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        $total_items = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $stock_table WHERE location_id = %d",
            $location_id
        ));
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $stock_table 
             WHERE location_id = %d 
             ORDER BY stock_quantity ASC 
             LIMIT %d OFFSET %d",
            $location_id, $per_page, $offset
        ));
        
        if ($items) {
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Produto', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('SKU', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Estoque', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Reservado', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Disponível', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Ações', 'click2pickup'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) : 
                        $product = wc_get_product($item->product_id);
                        if (!$product) continue;
                        $available = $item->stock_quantity - $item->reserved_quantity;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                            </td>
                            <td><?php echo esc_html($product->get_sku()); ?></td>
                            <td>
                                <input type="number" 
                                       class="c2p-stock-input" 
                                       data-location="<?php echo esc_attr($location_id); ?>"
                                       data-product="<?php echo esc_attr($item->product_id); ?>"
                                       value="<?php echo esc_attr($item->stock_quantity); ?>"
                                       style="width: 80px;">
                            </td>
                            <td><?php echo esc_html($item->reserved_quantity); ?></td>
                            <td style="color: <?php echo $available <= 0 ? 'red' : 'green'; ?>">
                                <?php echo esc_html($available); ?>
                            </td>
                            <td>
                                <button class="button button-small c2p-save-stock">
                                    <?php esc_html_e('Salvar', 'click2pickup'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php
            // Paginação
            $total_pages = ceil($total_items / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $page
                ));
                echo '</div></div>';
            }
        } else {
            echo '<p>' . esc_html__('Nenhum produto cadastrado para este local.', 'click2pickup') . '</p>';
        }
    }
    
    /**
     * Exibir estoque por produto
     */
    private function display_by_product() {
        ?>
        <div class="c2p-product-search">
            <input type="text" id="c2p-product-search" placeholder="<?php esc_attr_e('Buscar produto por nome ou SKU...', 'click2pickup'); ?>">
        </div>
        
        <div id="c2p-product-stock-results"></div>
        <?php
    }
    
    /**
     * Exibir movimentações
     */
    private function display_movements() {
        global $wpdb;
        
        $movements_table = $wpdb->prefix . 'c2p_stock_movements';
        $locations_table = $wpdb->prefix . 'c2p_locations';
        
        $movements = $wpdb->get_results("
            SELECT m.*, l.name as location_name 
            FROM $movements_table m
            JOIN $locations_table l ON m.location_id = l.id
            ORDER BY m.created_at DESC
            LIMIT 100
        ");
        
        if ($movements) {
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Data/Hora', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Local', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Produto', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Tipo', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Quantidade', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Pedido', 'click2pickup'); ?></th>
                        <th><?php esc_html_e('Notas', 'click2pickup'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $movement) : 
                        $product = wc_get_product($movement->product_id);
                    ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($movement->created_at))); ?></td>
                            <td><?php echo esc_html($movement->location_name); ?></td>
                            <td><?php echo $product ? esc_html($product->get_name()) : 'N/A'; ?></td>
                            <td><?php echo esc_html($this->get_movement_type_label($movement->type)); ?></td>
                            <td style="color: <?php echo $movement->quantity < 0 ? 'red' : 'green'; ?>">
                                <?php echo $movement->quantity > 0 ? '+' : ''; ?><?php echo esc_html($movement->quantity); ?>
                            </td>
                            <td>
                                <?php if ($movement->order_id) : ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $movement->order_id . '&action=edit'); ?>">
                                        #<?php echo esc_html($movement->order_id); ?>
                                    </a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($movement->notes); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>' . esc_html__('Nenhuma movimentação registrada.', 'click2pickup') . '</p>';
        }
    }
    
    /**
     * Página de importação/exportação
     */
    private function display_import() {
        ?>
        <div class="c2p-import-section">
            <h2><?php esc_html_e('Importar Estoque', 'click2pickup'); ?></h2>
            <p><?php esc_html_e('Faça upload de um arquivo CSV com os dados do estoque.', 'click2pickup'); ?></p>
            
            <form method="post" enctype="multipart/form-data" id="c2p-import-form">
                <?php wp_nonce_field('c2p_import_stock', 'c2p_import_nonce'); ?>
                
                <input type="file" name="stock_file" accept=".csv" required>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Importar', 'click2pickup'); ?>
                </button>
            </form>
            
            <h3><?php esc_html_e('Formato do CSV', 'click2pickup'); ?></h3>
            <p><?php esc_html_e('O arquivo deve conter as seguintes colunas:', 'click2pickup'); ?></p>
            <code>SKU,Location_ID,Stock_Quantity,Low_Stock_Threshold</code>
        </div>
        
        <div class="c2p-export-section">
            <h2><?php esc_html_e('Exportar Estoque', 'click2pickup'); ?></h2>
            <p><?php esc_html_e('Baixe um arquivo CSV com todo o estoque atual.', 'click2pickup'); ?></p>
            
            <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=c2p_export_stock'), 'c2p_export_stock'); ?>" 
               class="button button-primary">
                <?php esc_html_e('Exportar Estoque', 'click2pickup'); ?>
            </a>
        </div>
        <?php
    }
    
    /**
     * Retorna label do tipo de movimentação
     */
    private function get_movement_type_label($type) {
        $types = array(
            'sale' => __('Venda', 'click2pickup'),
            'return' => __('Devolução', 'click2pickup'),
            'adjustment' => __('Ajuste', 'click2pickup'),
            'transfer_in' => __('Transferência (Entrada)', 'click2pickup'),
            'transfer_out' => __('Transferência (Saída)', 'click2pickup'),
            'import' => __('Importação', 'click2pickup')
        );
        
        return isset($types[$type]) ? $types[$type] : $type;
    }
    
    /**
     * Ajax - Atualizar estoque
     */
    public function ajax_update_stock() {
        check_ajax_referer('c2p_stock_nonce', 'nonce');
        
        $location_id = intval($_POST['location_id']);
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        
        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        $result = $wpdb->update(
            $stock_table,
            array('stock_quantity' => $quantity),
            array('location_id' => $location_id, 'product_id' => $product_id)
        );
        
        if ($result !== false) {
            // Registrar movimentação
            $movements_table = $wpdb->prefix . 'c2p_stock_movements';
            $wpdb->insert($movements_table, array(
                'location_id' => $location_id,
                'product_id' => $product_id,
                'type' => 'adjustment',
                'quantity' => $quantity,
                'user_id' => get_current_user_id(),
                'notes' => 'Ajuste manual via admin'
            ));
            
            wp_send_json_success(array('message' => __('Estoque atualizado com sucesso', 'click2pickup')));
        } else {
            wp_send_json_error(array('message' => __('Erro ao atualizar estoque', 'click2pickup')));
        }
    }
}