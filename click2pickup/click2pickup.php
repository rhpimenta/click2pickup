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

// Debug - verificar se está carregando
add_action('wp_footer', function() {
    if (is_cart()) {
        echo '<!-- C2P Debug: Plugin carregado -->';
        echo '<!-- C2P Version: ' . C2P_VERSION . ' -->';
        echo '<!-- C2P Plugin Dir: ' . C2P_PLUGIN_DIR . ' -->';
        echo '<!-- Classes carregadas: -->';
        echo '<!-- Cart Handler: ' . (class_exists('C2P_Cart_Handler') ? 'SIM' : 'NAO') . ' -->';
        echo '<!-- WooCommerce: ' . (class_exists('WooCommerce') ? 'SIM' : 'NAO') . ' -->';
        
        // Verificar se arquivo existe
        $cart_handler_path = C2P_PLUGIN_DIR . 'includes/class-c2p-cart-handler.php';
        echo '<!-- Cart Handler File Exists: ' . (file_exists($cart_handler_path) ? 'SIM' : 'NAO') . ' -->';
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
        // Log de debug
        error_log('C2P: Método init() chamado');
        
        // Verificar WooCommerce primeiro
        if (!$this->check_woocommerce()) {
            error_log('C2P: WooCommerce não encontrado');
            return;
        }
        
        error_log('C2P: WooCommerce encontrado, carregando classes...');
        
        // Carregar classes
        $this->load_classes();

        error_log('C2P: Classes carregadas, inicializando componentes...');
        
        // Inicializar componentes
        $this->init_components();
        
        error_log('C2P: Componentes inicializados');
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
        
        // Log de debug
        error_log('C2P: Tentando carregar arquivo: ' . $relative_path);
        error_log('C2P: Caminho completo: ' . $path);
        error_log('C2P: Arquivo existe? ' . (file_exists($path) ? 'SIM' : 'NÃO'));
        
        if (file_exists($path)) {
            require_once $path;
            error_log('C2P: Arquivo carregado com sucesso: ' . $relative_path);
        } else {
            $this->missing_files[] = $relative_path;
            error_log('C2P: AVISO - Arquivo não encontrado: ' . $relative_path);
        }
    }

    /**
     * Carrega as classes do plugin
     */
    private function load_classes() {
        error_log('C2P: Iniciando carregamento de classes...');
        
        // Admin
        $this->safe_require('admin/class-c2p-admin.php');
        $this->safe_require('admin/class-c2p-locations-admin.php');
        $this->safe_require('admin/class-c2p-stock-admin.php');
        $this->safe_require('admin/class-c2p-settings-admin.php');
        $this->safe_require('admin/class-c2p-reports-admin.php');
        $this->safe_require('admin/class-c2p-product-tab.php');
        
        // Frontend / núcleo
        $this->safe_require('includes/class-c2p-cart-handler.php');
        // REMOVIDO: class-c2p-checkout.php não existe e não é necessário
        // $this->safe_require('includes/class-c2p-checkout.php');
        $this->safe_require('includes/class-c2p-stock-manager.php');
        $this->safe_require('includes/class-c2p-order-handler.php');

        // Integrações
        $this->safe_require('includes/class-c2p-woocommerce-integration.php');
        $this->safe_require('includes/class-c2p-rest-api.php');
        
        // Verificar se Cart Handler foi carregado
        error_log('C2P: Classe C2P_Cart_Handler existe após carregamento? ' . (class_exists('C2P_Cart_Handler') ? 'SIM' : 'NÃO'));
        
        if (!empty($this->missing_files)) {
            error_log('C2P: Arquivos faltantes (não críticos): ' . implode(', ', $this->missing_files));
        }
    }

    /**
     * Exibe aviso no admin se houver arquivos faltantes CRÍTICOS
     */
    public function maybe_render_missing_files_notice() {
        // Definir arquivos críticos
        $critical_files = array(
            'includes/class-c2p-cart-handler.php',
            'includes/class-c2p-stock-manager.php',
            'includes/class-c2p-order-handler.php'
        );
        
        // Verificar apenas arquivos críticos
        $missing_critical = array_intersect($critical_files, $this->missing_files);
        
        if (empty($missing_critical)) {
            return; // Sem erros críticos
        }
        
        echo '<div class="notice notice-error"><p><strong>';
        esc_html_e('Click2Pickup: Arquivos CRÍTICOS ausentes detectados.', 'click2pickup');
        echo '</strong></p><p>';
        esc_html_e('Os seguintes arquivos essenciais não foram encontrados:', 'click2pickup');
        echo '</p><ul style="list-style:disc;margin-left:20px;">';
        foreach ($missing_critical as $file) {
            echo '<li><code>' . esc_html($file) . '</code></li>';
        }
        echo '</ul>';
        echo '<p>' . sprintf(
            esc_html__('Por favor, verifique se todos os arquivos foram enviados para o servidor. Você pode baixar a versão completa em %s', 'click2pickup'),
            '<a href="https://github.com/rhpimenta/click2pickup" target="_blank">GitHub</a>'
        ) . '</p>';
        echo '</div>';
    }

    /**
     * Inicializa componentes do plugin
     */
    private function init_components() {
        // MODIFICAÇÃO IMPORTANTE: Permitir inicialização mesmo com arquivos não-críticos faltando
        $critical_files = array(
            'includes/class-c2p-cart-handler.php',
            'includes/class-c2p-stock-manager.php', 
            'includes/class-c2p-order-handler.php'
        );
        
        // Verificar apenas arquivos críticos
        $missing_critical = array_intersect($critical_files, $this->missing_files);
        
        if (!empty($missing_critical)) {
            error_log('C2P: ERRO CRÍTICO - Arquivos essenciais faltando: ' . implode(', ', $missing_critical));
            return;
        }
        
        error_log('C2P: Arquivos críticos OK, inicializando componentes...');

        // Admin
        if (is_admin()) {
            error_log('C2P: Inicializando componentes Admin...');
            
            if (class_exists('C2P_Admin')) {
                new C2P_Admin();
                error_log('C2P: C2P_Admin inicializado');
            }
            if (class_exists('C2P_Locations_Admin')) {
                new C2P_Locations_Admin();
                error_log('C2P: C2P_Locations_Admin inicializado');
            }
            if (class_exists('C2P_Stock_Admin')) {
                new C2P_Stock_Admin();
                error_log('C2P: C2P_Stock_Admin inicializado');
            }
            if (class_exists('C2P_Settings_Admin')) {
                new C2P_Settings_Admin();
                error_log('C2P: C2P_Settings_Admin inicializado');
            }
            if (class_exists('C2P_Reports_Admin')) {
                new C2P_Reports_Admin();
                error_log('C2P: C2P_Reports_Admin inicializado');
            }
            if (class_exists('C2P_Product_Tab')) {
                new C2P_Product_Tab();
                error_log('C2P: C2P_Product_Tab inicializado');
            }
        }

        // Frontend - SEMPRE INICIALIZAR O QUE EXISTIR
        error_log('C2P: Inicializando componentes Frontend...');
        
        if (class_exists('C2P_Cart_Handler')) {
            new C2P_Cart_Handler();
            error_log('C2P: ✅ C2P_Cart_Handler inicializado com sucesso!');
        } else {
            error_log('C2P: ❌ ERRO - Classe C2P_Cart_Handler não encontrada!');
        }
        
        // Checkout - só inicializar se existir (OPCIONAL)
        if (class_exists('C2P_Checkout')) {
            new C2P_Checkout();
            error_log('C2P: C2P_Checkout inicializado');
        } else {
            error_log('C2P: C2P_Checkout não encontrado (não crítico - funcionalidade incorporada no Cart Handler)');
        }
        
        if (class_exists('C2P_Stock_Manager')) {
            C2P_Stock_Manager::get_instance();
            error_log('C2P: C2P_Stock_Manager inicializado');
        } else {
            error_log('C2P: ❌ ERRO - Classe C2P_Stock_Manager não encontrada!');
        }
        
        if (class_exists('C2P_Order_Handler')) {
            new C2P_Order_Handler();
            error_log('C2P: C2P_Order_Handler inicializado');
        } else {
            error_log('C2P: ❌ ERRO - Classe C2P_Order_Handler não encontrada!');
        }

        // Integrações
        error_log('C2P: Inicializando integrações...');
        
        if (class_exists('C2P_WooCommerce_Integration')) {
            new C2P_WooCommerce_Integration();
            error_log('C2P: C2P_WooCommerce_Integration inicializado');
        }
        
        if (class_exists('C2P_REST_API')) {
            new C2P_REST_API();
            error_log('C2P: C2P_REST_API inicializado');
        }
        
        error_log('C2P: ✅ Inicialização de componentes concluída!');
    }

    /**
     * Links de ação na lista de plugins
     */
    public function add_plugin_links($links) {
        // Adicionar link de configurações no início
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=click2pickup')),
            esc_html__('Configurações', 'click2pickup')
        );
        array_unshift($links, $settings_link);
        
        // Adicionar link para documentação
        $docs_link = sprintf(
            '<a href="%s" target="_blank" style="color: #0073aa;">%s</a>',
            'https://github.com/rhpimenta/click2pickup/wiki',
            esc_html__('Documentação', 'click2pickup')
        );
        $links[] = $docs_link;
        
        // Adicionar link para suporte
        $support_link = sprintf(
            '<a href="%s" target="_blank" style="color: #28a745;">%s</a>',
            'https://github.com/rhpimenta/click2pickup/issues',
            esc_html__('Suporte', 'click2pickup')
        );
        $links[] = $support_link;
        
        return $links;
    }
    
    /**
     * Obter lista de arquivos faltantes (para debug)
     */
    public function get_missing_files() {
        return $this->missing_files;
    }
}

// Bootstrap - Inicializar plugin apenas após WordPress estar pronto
add_action('plugins_loaded', function() {
    error_log('C2P: Hook plugins_loaded executado');
    
    // Só inicializar se WooCommerce estiver disponível
    if (class_exists('WooCommerce')) {
        error_log('C2P: WooCommerce detectado, inicializando Click2Pickup...');
        Click2Pickup::get_instance();
    } else {
        error_log('C2P: WooCommerce não detectado ainda, aguardando...');
        
        // Se WooCommerce não estiver disponível ainda, aguardar
        add_action('woocommerce_loaded', function() {
            error_log('C2P: WooCommerce carregado, inicializando Click2Pickup...');
            Click2Pickup::get_instance();
        });
    }
}, 10);

// Função global helper para obter instância do plugin
if (!function_exists('click2pickup')) {
    function click2pickup() {
        return Click2Pickup::get_instance();
    }
}

// Endpoint de debug para verificar status (apenas para admins)
add_action('wp_ajax_c2p_debug_status', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $instance = Click2Pickup::get_instance();
    
    $status = array(
        'version' => C2P_VERSION,
        'plugin_dir' => C2P_PLUGIN_DIR,
        'plugin_url' => C2P_PLUGIN_URL,
        'woocommerce_active' => class_exists('WooCommerce'),
        'classes' => array(
            'cart_handler' => class_exists('C2P_Cart_Handler'),
            'stock_manager' => class_exists('C2P_Stock_Manager'),
            'order_handler' => class_exists('C2P_Order_Handler'),
            'wc_integration' => class_exists('C2P_WooCommerce_Integration'),
            'rest_api' => class_exists('C2P_REST_API'),
        ),
        'files' => array(
            'cart_handler_exists' => file_exists(C2P_PLUGIN_DIR . 'includes/class-c2p-cart-handler.php'),
            'stock_manager_exists' => file_exists(C2P_PLUGIN_DIR . 'includes/class-c2p-stock-manager.php'),
            'order_handler_exists' => file_exists(C2P_PLUGIN_DIR . 'includes/class-c2p-order-handler.php'),
        ),
        'missing_files' => $instance ? $instance->get_missing_files() : array(),
        'environment' => array(
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        )
    );
    
    wp_send_json_success($status);
});

// Adicionar verificação de saúde no Site Health
add_filter('site_status_tests', function($tests) {
    $tests['direct']['click2pickup'] = array(
        'label' => __('Click2Pickup Status', 'click2pickup'),
        'test' => function() {
            $result = array(
                'label' => __('Click2Pickup está funcionando corretamente', 'click2pickup'),
                'status' => 'good',
                'badge' => array(
                    'label' => __('Performance', 'click2pickup'),
                    'color' => 'green',
                ),
                'description' => sprintf(
                    '<p>%s</p>',
                    __('Click2Pickup está instalado e funcionando corretamente.', 'click2pickup')
                ),
                'actions' => '',
                'test' => 'click2pickup_status',
            );
            
            // Verificar se classes principais existem
            if (!class_exists('C2P_Cart_Handler')) {
                $result['status'] = 'critical';
                $result['label'] = __('Click2Pickup tem problemas críticos', 'click2pickup');
                $result['badge']['color'] = 'red';
                $result['description'] = sprintf(
                    '<p>%s</p>',
                    __('O manipulador do carrinho não foi carregado. Verifique os logs de erro.', 'click2pickup')
                );
            }
            
            return $result;
        },
    );
    
    return $tests;
});