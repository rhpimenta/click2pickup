<?php
/**
 * Plugin Name: Click2Pickup
 * Plugin URI: https://github.com/rhpimenta/click2pickup
 * Description: Sistema de retirada em loja e entrega para WooCommerce com suporte HPOS
 * Version: 2.1.0
 * Author: RH Pimenta
 * Author URI: https://github.com/rhpimenta
 * Text Domain: click2pickup
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * 
 * @package Click2Pickup
 */

// Evitar acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('C2P_VERSION', '2.1.0');
define('C2P_PLUGIN_FILE', __FILE__);
define('C2P_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('C2P_PLUGIN_URL', plugin_dir_url(__FILE__));
define('C2P_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Declarar compatibilidade com HPOS antes da inicialização do WooCommerce
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        error_log('C2P: Compatibilidade HPOS declarada');
    }
});

/**
 * Declarar compatibilidade com Cart/Checkout Blocks
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            false // Por enquanto não temos suporte completo para blocks
        );
    }
});



// ADICIONAR TEMPORARIAMENTE no início do arquivo click2pickup.php, logo após as constantes

// Debug - verificar se está carregando
add_action('wp_footer', function() {
    if (is_cart()) {
        echo '<!-- C2P Debug: Plugin carregado -->';
        echo '<!-- C2P Version: ' . C2P_VERSION . ' -->';
        echo '<!-- Classes carregadas: -->';
        echo '<!-- Cart Handler: ' . (class_exists('C2P_Cart_Handler') ? 'SIM' : 'NAO') . ' -->';
        echo '<!-- WooCommerce: ' . (class_exists('WooCommerce') ? 'SIM' : 'NAO') . ' -->';
    }
});


// Carregar o Activator e Deactivator
require_once C2P_PLUGIN_DIR . 'includes/class-c2p-activator.php';
require_once C2P_PLUGIN_DIR . 'includes/class-c2p-deactivator.php';

// Hooks de ativação e desativação
register_activation_hook(C2P_PLUGIN_FILE, array('C2P_Activator', 'activate'));
register_deactivation_hook(C2P_PLUGIN_FILE, array('C2P_Deactivator', 'deactivate'));

/**
 * Classe principal do plugin
 */
final class Click2Pickup {

    /**
     * Instância singleton
     * @var Click2Pickup|null
     */
    private static $instance = null;

    /**
     * Arquivos faltantes detectados ao carregar classes
     * @var array
     */
    private $missing_files = array();

    /**
     * Obter instância
     */
    public static function get_instance(): Click2Pickup {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Construtor
     */
    private function __construct() {
        // Aguardar init para carregar traduções
        add_action('init', array($this, 'load_textdomain'), 1);
        
        // Aguardar plugins_loaded para garantir que WooCommerce esteja carregado
        add_action('plugins_loaded', array($this, 'init'), 20);
        
        // Links na página de plugins
        add_filter('plugin_action_links_' . C2P_PLUGIN_BASENAME, array($this, 'add_plugin_links'));
        
        // Notificações de arquivos faltantes
        add_action('admin_notices', array($this, 'maybe_render_missing_files_notice'));
        
        // Verificar atualizações de banco de dados
        add_action('admin_init', array($this, 'check_db_updates'));
    }

    /**
     * Carrega traduções
     */
    public function load_textdomain() {
        load_plugin_textdomain('click2pickup', false, dirname(C2P_PLUGIN_BASENAME) . '/languages/');
    }
    
    /**
     * Verifica atualizações de banco de dados
     */
    public function check_db_updates() {
        $current_db_version = get_option('c2p_db_version', '0');
        
        if (version_compare($current_db_version, C2P_VERSION, '<')) {
            // Reexecutar criação de tabelas para garantir estrutura atualizada
            C2P_Activator::activate();
        }
    }

    /**
     * Inicialização do plugin
     */
    public function init() {
        // Verificar WooCommerce primeiro
        if (!$this->check_woocommerce()) {
            return;
        }
        
        // Carregar classes
        $this->load_classes();

        // Inicializar componentes
        $this->init_components();
    }

    /**
     * Checa se WooCommerce está ativo
     */
    private function check_woocommerce(): bool {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                esc_html_e('Click2Pickup requer WooCommerce ativo. Por favor, instale e ative o WooCommerce.', 'click2pickup');
                echo '</p></div>';
            });
            return false;
        }
        return true;
    }

    /**
     * Requer um arquivo com segurança
     */
    private function safe_require(string $relative_path) {
        $path = C2P_PLUGIN_DIR . ltrim($relative_path, '/');
        if (file_exists($path)) {
            require_once $path;
        } else {
            $this->missing_files[] = $relative_path;
        }
    }

    /**
     * Carrega as classes do plugin
     */
    private function load_classes() {
        // Admin
        $this->safe_require('admin/class-c2p-admin.php');
        $this->safe_require('admin/class-c2p-locations-admin.php');
        $this->safe_require('admin/class-c2p-stock-admin.php');
        $this->safe_require('admin/class-c2p-settings-admin.php');
        $this->safe_require('admin/class-c2p-reports-admin.php');
        $this->safe_require('admin/class-c2p-product-tab.php');

        // Frontend / núcleo
        $this->safe_require('includes/class-c2p-cart-handler.php');
        $this->safe_require('includes/class-c2p-checkout.php');
        $this->safe_require('includes/class-c2p-stock-manager.php');
        $this->safe_require('includes/class-c2p-order-handler.php');

        // Integrações
        $this->safe_require('includes/class-c2p-woocommerce-integration.php');
        $this->safe_require('includes/class-c2p-rest-api.php');
    }

    /**
     * Exibe aviso no admin se houver arquivos faltantes
     */
    public function maybe_render_missing_files_notice() {
        if (empty($this->missing_files)) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>';
        esc_html_e('Click2Pickup: Arquivos ausentes detectados.', 'click2pickup');
        echo '</strong></p><p>';
        esc_html_e('Os seguintes arquivos não foram encontrados:', 'click2pickup');
        echo '</p><ul style="list-style:disc;margin-left:20px;">';
        foreach ($this->missing_files as $file) {
            echo '<li><code>' . esc_html($file) . '</code></li>';
        }
        echo '</ul></div>';
    }

    /**
     * Inicializa componentes do plugin
     */
    private function init_components() {
        if (!empty($this->missing_files)) {
            return;
        }

        // Admin
        if (is_admin()) {
            if (class_exists('C2P_Admin')) {
                new C2P_Admin();
            }
            if (class_exists('C2P_Locations_Admin')) {
                new C2P_Locations_Admin();
            }
            if (class_exists('C2P_Stock_Admin')) {
                new C2P_Stock_Admin();
            }
            if (class_exists('C2P_Settings_Admin')) {
                new C2P_Settings_Admin();
            }
            if (class_exists('C2P_Reports_Admin')) {
                new C2P_Reports_Admin();
            }
            if (class_exists('C2P_Product_Tab')) {
                new C2P_Product_Tab();
            }
        }

        // Frontend - inicializar diretamente aqui
        if (class_exists('C2P_Cart_Handler')) {
            new C2P_Cart_Handler();
        }
        if (class_exists('C2P_Checkout')) {
            new C2P_Checkout();
        }
        if (class_exists('C2P_Stock_Manager')) {
            C2P_Stock_Manager::get_instance();
        }
        if (class_exists('C2P_Order_Handler')) {
            new C2P_Order_Handler();
        }

        // Integrações
        if (class_exists('C2P_WooCommerce_Integration')) {
            new C2P_WooCommerce_Integration();
        }
        if (class_exists('C2P_REST_API')) {
            new C2P_REST_API();
        }
      
    }

    /**
     * Links de ação na lista de plugins
     */
    public function add_plugin_links($links) {
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=click2pickup')),
            esc_html__('Configurações', 'click2pickup')
        );
        return $links;
    }
}

// Bootstrap - Inicializar plugin apenas após WordPress estar pronto
add_action('plugins_loaded', function() {
    // Só inicializar se WooCommerce estiver disponível
    if (class_exists('WooCommerce')) {
        Click2Pickup::get_instance();
    } else {
        // Se WooCommerce não estiver disponível ainda, aguardar
        add_action('woocommerce_loaded', function() {
            Click2Pickup::get_instance();
        });
    }
}, 10);