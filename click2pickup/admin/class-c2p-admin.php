<?php
/**
 * Classe Admin Principal do Click2Pickup
 *
 * @package Click2Pickup
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Admin {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Menu admin
        add_action('admin_menu', array($this, 'add_admin_menu'), 9);
        
        // Estilos e scripts admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // Debug: verificar se a classe está sendo carregada
        error_log('C2P_Admin class loaded at ' . current_time('mysql'));
    }
    
    /**
     * Adiciona o menu admin
     */
    public function add_admin_menu() {
        // Menu principal
        add_menu_page(
            __('Click2Pickup', 'click2pickup'),
            __('Click2Pickup', 'click2pickup'),
            'manage_woocommerce',
            'click2pickup',
            array($this, 'display_dashboard_page'),
            'dashicons-location',
            56
        );
        
        // Dashboard (duplica o item principal)
        add_submenu_page(
            'click2pickup',
            __('Dashboard', 'click2pickup'),
            __('Dashboard', 'click2pickup'),
            'manage_woocommerce',
            'click2pickup',
            array($this, 'display_dashboard_page')
        );
        
        // Locais
        add_submenu_page(
            'click2pickup',
            __('Locais', 'click2pickup'),
            __('Locais', 'click2pickup'),
            'manage_woocommerce',
            'c2p-locations',
            array($this, 'display_locations_page')
        );
        
        // Estoque
        add_submenu_page(
            'click2pickup',
            __('Estoque', 'click2pickup'),
            __('Estoque', 'click2pickup'),
            'manage_woocommerce',
            'c2p-stock',
            array($this, 'display_stock_page')
        );
        
        // Relatórios
        add_submenu_page(
            'click2pickup',
            __('Relatórios', 'click2pickup'),
            __('Relatórios', 'click2pickup'),
            'manage_woocommerce',
            'c2p-reports',
            array($this, 'display_reports_page')
        );
        
        // Configurações
        add_submenu_page(
            'click2pickup',
            __('Configurações', 'click2pickup'),
            __('Configurações', 'click2pickup'),
            'manage_woocommerce',
            'c2p-settings',
            array($this, 'display_settings_page')
        );
    }
    
    /**
     * Enqueue assets admin
     */
    public function enqueue_admin_assets($hook) {
        // Apenas nas páginas do plugin
        if (strpos($hook, 'click2pickup') === false && strpos($hook, 'c2p-') === false) {
            return;
        }
        
        // CSS Admin
        wp_enqueue_style(
            'c2p-admin',
            C2P_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            C2P_VERSION
        );
        
        // JS Admin (se existir)
        if (file_exists(C2P_PLUGIN_DIR . 'assets/js/admin.js')) {
            wp_enqueue_script(
                'c2p-admin',
                C2P_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                C2P_VERSION,
                true
            );
        }
    }
    
    /**
     * Página Dashboard
     */
    public function display_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-location" style="font-size: 30px; margin-right: 10px;"></span>
                <?php echo esc_html(get_admin_page_title()); ?>
            </h1>
            
            <div class="c2p-welcome-panel" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px; margin: 20px 0;">
                <h2 style="color: white; margin-top: 0;">
                    <?php esc_html_e('Bem-vindo ao Click2Pickup! 🎉', 'click2pickup'); ?>
                </h2>
                <p style="font-size: 16px;">
                    <?php esc_html_e('Sistema de gestão de estoque multi-local para WooCommerce', 'click2pickup'); ?>
                </p>
                <div style="margin-top: 20px;">
                    <a href="<?php echo admin_url('admin.php?page=c2p-locations'); ?>" class="button button-primary button-hero">
                        <?php esc_html_e('Gerenciar Locais', 'click2pickup'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=c2p-stock'); ?>" class="button button-secondary button-hero" style="margin-left: 10px;">
                        <?php esc_html_e('Gerenciar Estoque', 'click2pickup'); ?>
                    </a>
                </div>
            </div>
            
            <?php
            // Estatísticas rápidas
            $this->display_dashboard_stats();
            ?>
        </div>
        <?php
    }
    
    /**
     * Exibe estatísticas no dashboard
     */
    private function display_dashboard_stats() {
        global $wpdb;
        
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        // Verificar se as tabelas existem
        $tables_exist = $wpdb->get_var("SHOW TABLES LIKE '$locations_table'") === $locations_table;
        
        if (!$tables_exist) {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('As tabelas do banco de dados não foram criadas. Por favor, desative e reative o plugin.', 'click2pickup'); ?></p>
            </div>
            <?php
            return;
        }
        
        $total_locations = $wpdb->get_var("SELECT COUNT(*) FROM $locations_table WHERE is_active = 1");
        $total_products = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM $stock_table");
        $total_stock = $wpdb->get_var("SELECT SUM(stock_quantity) FROM $stock_table");
        
        ?>
        <div class="c2p-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 30px;">
            <div class="c2p-stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3><?php esc_html_e('Locais Ativos', 'click2pickup'); ?></h3>
                <p style="font-size: 32px; font-weight: bold; color: #667eea; margin: 10px 0;">
                    <?php echo intval($total_locations); ?>
                </p>
            </div>
            
            <div class="c2p-stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3><?php esc_html_e('Produtos Gerenciados', 'click2pickup'); ?></h3>
                <p style="font-size: 32px; font-weight: bold; color: #4caf50; margin: 10px 0;">
                    <?php echo intval($total_products); ?>
                </p>
            </div>
            
            <div class="c2p-stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3><?php esc_html_e('Estoque Total', 'click2pickup'); ?></h3>
                <p style="font-size: 32px; font-weight: bold; color: #ff9800; margin: 10px 0;">
                    <?php echo number_format(intval($total_stock)); ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Página de Locais
     */
    public function display_locations_page() {
        // Delegar para a classe específica
        if (class_exists('C2P_Locations_Admin')) {
            $locations_admin = new C2P_Locations_Admin();
            $locations_admin->display_page();
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Locais', 'click2pickup') . '</h1>';
            echo '<p>' . esc_html__('Módulo de locais não encontrado.', 'click2pickup') . '</p></div>';
        }
    }
    
    /**
     * Página de Estoque
     */
    public function display_stock_page() {
        if (class_exists('C2P_Stock_Admin')) {
            $stock_admin = new C2P_Stock_Admin();
            $stock_admin->display_page();
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Estoque', 'click2pickup') . '</h1>';
            echo '<p>' . esc_html__('Módulo de estoque não encontrado.', 'click2pickup') . '</p></div>';
        }
    }
    
    /**
     * Página de Relatórios
     */
    public function display_reports_page() {
        if (class_exists('C2P_Reports_Admin')) {
            $reports_admin = new C2P_Reports_Admin();
            $reports_admin->display_page();
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Relatórios', 'click2pickup') . '</h1>';
            echo '<p>' . esc_html__('Módulo de relatórios não encontrado.', 'click2pickup') . '</p></div>';
        }
    }
    
    /**
     * Página de Configurações
     */
    public function display_settings_page() {
        if (class_exists('C2P_Settings_Admin')) {
            $settings_admin = new C2P_Settings_Admin();
            $settings_admin->display_page();
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Configurações', 'click2pickup') . '</h1>';
            echo '<p>' . esc_html__('Módulo de configurações não encontrado.', 'click2pickup') . '</p></div>';
        }
    }
    
    /**
     * Widget do Dashboard WordPress
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'c2p_dashboard_widget',
            __('Click2Pickup - Status', 'click2pickup'),
            array($this, 'display_dashboard_widget')
        );
    }
    
    /**
     * Conteúdo do widget
     */
    public function display_dashboard_widget() {
        global $wpdb;
        
        $locations_table = $wpdb->prefix . 'c2p_locations';
        $stock_table = $wpdb->prefix . 'c2p_stock';
        
        $active_locations = $wpdb->get_var("SELECT COUNT(*) FROM $locations_table WHERE is_active = 1");
        $low_stock = $wpdb->get_var("SELECT COUNT(*) FROM $stock_table WHERE stock_quantity <= low_stock_threshold");
        
        ?>
        <p>
            <strong><?php esc_html_e('Locais Ativos:', 'click2pickup'); ?></strong> 
            <?php echo intval($active_locations); ?>
        </p>
        <p>
            <strong><?php esc_html_e('Produtos com Estoque Baixo:', 'click2pickup'); ?></strong> 
            <span style="color: <?php echo $low_stock > 0 ? '#e91e63' : '#4caf50'; ?>;">
                <?php echo intval($low_stock); ?>
            </span>
        </p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=click2pickup'); ?>" class="button button-primary">
                <?php esc_html_e('Acessar Click2Pickup', 'click2pickup'); ?>
            </a>
        </p>
        <?php
    }
    
    /**
     * Avisos admin
     */
    public function display_admin_notices() {
        // Verificar se é primeira instalação
        if (!get_option('c2p_welcome_dismissed') && current_user_can('manage_woocommerce')) {
            ?>
            <div class="notice notice-info is-dismissible" data-notice="c2p_welcome">
                <p>
                    <strong><?php esc_html_e('Click2Pickup está ativo!', 'click2pickup'); ?></strong>
                    <?php esc_html_e('Configure seus locais e comece a gerenciar estoque multi-local.', 'click2pickup'); ?>
                    <a href="<?php echo admin_url('admin.php?page=c2p-locations&action=new'); ?>">
                        <?php esc_html_e('Adicionar primeiro local', 'click2pickup'); ?>
                    </a>
                </p>
            </div>
            <script>
            jQuery(document).on('click', '.notice[data-notice="c2p_welcome"] .notice-dismiss', function() {
                jQuery.post(ajaxurl, {
                    action: 'c2p_dismiss_notice',
                    notice: 'c2p_welcome',
                    _wpnonce: '<?php echo wp_create_nonce('c2p_dismiss_notice'); ?>'
                });
            });
            </script>
            <?php
        }
    }
}