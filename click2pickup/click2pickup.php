<?php
/**
 * Plugin Name: Click2Pickup (Store Pickup & Multi-Estoque)
 * Description: Gerencia lojas/pontos de retirada, CDs e multi-estoque por local com espelhamento no WooCommerce.
 * Author: Você
 * Version: 1.1.0
 * Text Domain: c2p
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** =========================
 * Constantes básicas
 * ========================= */
if ( ! defined('C2P_VERSION') )      define('C2P_VERSION', '1.1.0');
if ( ! defined('C2P_TEXTDOMAIN') )   define('C2P_TEXTDOMAIN', 'c2p');
if ( ! defined('C2P_FILE') )         define('C2P_FILE', __FILE__);
if ( ! defined('C2P_PATH') )         define('C2P_PATH', plugin_dir_path(__FILE__));
if ( ! defined('C2P_URL') )          define('C2P_URL', plugin_dir_url(__FILE__));

/** =========================
 * I18N
 * ========================= */
add_action('plugins_loaded', function () {
    load_plugin_textdomain( C2P_TEXTDOMAIN, false, dirname( plugin_basename(__FILE__) ) . '/languages' );
}, 0);

/** =========================
 * Dependência obrigatória (Opção A)
 * - Verifica na ativação (desativa se faltar)
 * - Carrega cedo em runtime e alerta no admin se ausente
 * ========================= */
if ( ! defined('C2P_REQUIRED_LOWSTOCK') ) {
    define('C2P_REQUIRED_LOWSTOCK', C2P_PATH . 'includes/class-low-stock-alerts-core.php');
}

function c2p_check_required_files_on_activation() {
    if ( ! is_readable( C2P_REQUIRED_LOWSTOCK ) ) {
        deactivate_plugins( plugin_basename(__FILE__) );
        wp_die(
            '<p><strong>Click2Pickup:</strong> arquivo obrigatório ausente:</p>'.
            '<code>includes/class-low-stock-alerts-core.php</code>'.
            '<p>O plugin foi desativado para evitar erros. Restaure o arquivo e ative novamente.</p>',
            'Click2Pickup — dependência ausente',
            ['back_link' => true]
        );
    }
}
register_activation_hook(__FILE__, 'c2p_check_required_files_on_activation');

add_action('plugins_loaded', function() {
    if ( is_readable( C2P_REQUIRED_LOWSTOCK ) ) {
        require_once C2P_REQUIRED_LOWSTOCK;
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Click2Pickup:</strong> arquivo obrigatório ausente: <code>'.
                 esc_html( str_replace(ABSPATH, '', C2P_REQUIRED_LOWSTOCK) ) .
                 '</code>. Restaure este arquivo para que os alertas de estoque baixo funcionem.</p></div>';
        });
    }
}, 1);

/** =========================
 * Includes — somente se existir e suprimindo saída acidental
 * ========================= */
$__c2p_files = [
    // Núcleo / infra
    'includes/class-installer.php',                        // \C2P\Installer
    'includes/class-plugin.php',                           // \C2P\Plugin
    'includes/class-inventory-db.php',                     // \C2P\Inventory_DB
    'includes/class-stock-ledger.php',                     // \C2P\Stock_Ledger (ledger + relatório)

    // Admin / Locais / UI de produto / REST
    'includes/admin/locations/class-locations-admin.php',  // \C2P\Locations_Admin
    'includes/admin/class-product-ui.php',                 // \C2P\Product_UI
    'includes/admin/class-rest-api.php',                   // \C2P\REST_API
    // *** REMOVIDO DA LISTA: 'includes/class-low-stock-alerts-core.php',
    'includes/admin/orders/class-admin-save-lowstock-guard.php', // guarda para saves no admin

    // Relatórios/admin
    'includes/admin/class-inventory-report.php',           // \C2P\Inventory_Report
    'includes/admin/reports/class-stock-report-admin.php', // \C2P\Stock_Report_Admin

    // Guarda REST (opcional, com no-op interno se faltar Tools)
    'includes/admin/class-rest-guard.php',                 // \C2P\REST_Guard

    // Varredura/Inicialização
    'includes/admin/class-init-scan.php',                  // \C2P\Init_Scan

    // Checkout/carrinho (se existir)
    'includes/views/hooks-pickup-shipping.php',

    // Admin: pedido / e-mail retirada / coluna
    //'includes/admin/orders/class-order-admin-header.php',
    //'includes/admin/orders/class-order-list-column.php',
    'includes/admin/orders/class-order-metabox.php',
    //'includes/admin/orders/class-order-notes.php',

    'includes/admin/orders/class-order-email-pickup.php',
    'includes/admin/orders/class-order-admin-ui.php',

    // Sincronizador de estoque por local no ciclo do pedido
    'includes/admin/orders/class-order-stock-sync.php',

    // Debug de e-mail
    'includes/admin/class-email-debug.php',

    // Configurações (carrega traits e aba de e-mails internamente)
    'includes/class-settings.php',
    'includes/admin/settings-tabs/trait-tools.php',
];

foreach ( $__c2p_files as $__rel ) {
    $__abs = C2P_PATH . ltrim($__rel, '/');
    if ( is_readable($__abs) ) {
        ob_start();
        require_once $__abs;
        $__out = ob_get_clean();
        if ( $__out !== '' ) {
            // Evita “saída inesperada” na ativação
            error_log('[C2P][include output] ' . $__rel . ' -> ' . substr(trim(strip_tags($__out)), 0, 300));
        }
    }
}
unset($__c2p_files, $__rel, $__abs, $__out);

// Carrinho custom (layout preservado do plugin externo)
require_once C2P_PATH . 'includes/views/cart/class-custom-cart.php';
\C2P\Custom_Cart::boot( C2P_URL . 'assets/cart/', C2P_PATH . 'includes/views/cart/templates/', C2P_VERSION );


/** =========================
 * Migrações (nome/estrutura de tabela) — o mais cedo possível
 * ========================= */
add_action('plugins_loaded', function(){
    if ( class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB','maybe_migrate_table_name') ) {
        \C2P\Inventory_DB::maybe_migrate_table_name();
    }
    if ( class_exists('\C2P\Installer') && method_exists('\C2P\Installer','maybe_upgrade') ) {
        \C2P\Installer::maybe_upgrade();
    }
}, 0);

/** =========================
 * Ativação — roda Installer e captura saída
 * ========================= */
if ( class_exists('\C2P\Installer') ) {
    register_activation_hook( __FILE__, function() {
        ob_start();
        try {
            \C2P\Installer::activate();
        } finally {
            $out = ob_get_clean();
            if ( ! empty( $out ) ) {
                update_option( 'c2p_activation_output', substr( wp_strip_all_tags( (string) $out ), 0, 2000 ), false );
            }
        }
    });
}

/** =========================
 * BOOTSTRAP das classes
 * ========================= */
add_action( 'plugins_loaded', function() {

    // Núcleo de assets/TD
    if ( class_exists('\C2P\Plugin') )                 \C2P\Plugin::instance();

    // Admin de Locais (CPT + vínculo com fretes)
    if ( class_exists('\C2P\Locations_Admin') )        \C2P\Locations_Admin::instance();

    // UI de Produto (metabox/admin + disponibilidade) + snapshot por local
    if ( class_exists('\C2P\Product_UI') )             \C2P\Product_UI::instance();

    // REST API
    if ( class_exists('\C2P\REST_API') )               \C2P\REST_API::instance();

    // Carrinho (se existir)
    if ( class_exists('\C2P\Cart_Frontend') )          \C2P\Cart_Frontend::init();

    // Notas/elementos no admin de pedidos
    if ( class_exists('\C2P\Order_Notes') )            \C2P\Order_Notes::instance();
    if ( class_exists('\C2P\Order_Admin_Notes') )      \C2P\Order_Admin_Notes::instance();

    // Coluna da lista de pedidos
    if ( class_exists('\C2P\Order_List_Column') )      \C2P\Order_List_Column::boot();

    // Envio de e-mails Click2Pickup (retirada) — NOVO namespace
    if ( class_exists('\C2P\Admin\Orders\Order_Email_Pickup') ) {
        \C2P\Admin\Orders\Order_Email_Pickup::instance();
    } elseif ( class_exists('\C2P\Order_Email_Pickup') ) {
        // fallback, caso ainda exista a classe antiga em algum ambiente
        \C2P\Order_Email_Pickup::instance();
    }

    // Alerta de estoque baixo (real) — garantir bootstrap
    if ( class_exists('\C2P\Low_Stock_Alerts_Core') )  \C2P\Low_Stock_Alerts_Core::instance();

    // Sincronizador de estoque por local no ciclo do pedido
    if ( class_exists('\C2P\Order_Stock_Sync') )       \C2P\Order_Stock_Sync::instance();

    // Relatórios
    if ( class_exists('\C2P\Inventory_Report') )       \C2P\Inventory_Report::instance();
    if ( class_exists('\C2P\Stock_Report_Admin') )     \C2P\Stock_Report_Admin::instance();

    // Guardas/REST
    if ( class_exists('\C2P\REST_Guard') )             \C2P\REST_Guard::instance();

    // Varredura/Inicialização (scan manual / utilitários)
    if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'instance') ) {
        \C2P\Init_Scan::instance();
    }

}, 5);


/** =========================
 * SETTINGS — bootstrap CEDO (corrige erro do options.php)
 * ========================= */
if ( is_admin() ) {
    // Garante que o register_setting do Settings rode antes do options.php validar
    add_action('plugins_loaded', function () {
        if ( class_exists('\C2P\Settings') ) { \C2P\Settings::instance(); }
    }, 1);

    // Redundância segura
    add_action('admin_init', function () {
        if ( class_exists('\C2P\Settings') ) { \C2P\Settings::instance(); }
    }, 0);

    // Fallback: se por alguma razão o grupo ainda não estiver registrado,
    // adiciona explicitamente o grupo/opção na lista de permitidos do options.php
    add_filter('allowed_options', function ($allowed) {
        if ( ! isset($allowed['c2p_settings_group']) ) {
            $allowed['c2p_settings_group'] = [];
        }
        if ( ! in_array('c2p_settings', $allowed['c2p_settings_group'], true) ) {
            $allowed['c2p_settings_group'][] = 'c2p_settings';
        }
        return $allowed;
    });
}

/** =========================
 * Menu do Admin — Click2Pickup
 * ========================= */
add_action( 'admin_menu', function() {

    add_menu_page(
        __( 'Click2Pickup — Dashboard', 'c2p' ),
        __( 'Click2Pickup', 'c2p' ),
        'manage_woocommerce',
        'c2p-dashboard',
        function() {
            echo '<div class="wrap"><h1>Click2Pickup — Dashboard</h1>';
            echo '<p>'.esc_html__('Bem-vindo! Use o submenu para gerenciar Lojas e Configurações.', 'c2p').'</p>';
            echo '</div>';
        },
        'dashicons-store',
        56
    );

    add_submenu_page(
        'c2p-dashboard',
        __( 'Dashboard', 'c2p' ),
        __( 'Dashboard', 'c2p' ),
        'manage_woocommerce',
        'c2p-dashboard',
    );

    add_submenu_page(
        'c2p-dashboard',
        __( 'Locais de Estoque', 'c2p' ),
        __( 'Locais de Estoque', 'c2p' ),
        'manage_woocommerce',
        'edit.php?post_type=c2p_store'
    );

    add_submenu_page(
        'c2p-dashboard',
        __( 'Configurações', 'c2p' ),
        __( 'Configurações', 'c2p' ),
        'manage_woocommerce',
        'c2p-settings',
        function() {
            if ( class_exists('\C2P\Settings') ) {
                \C2P\Settings::instance(); // garante hooks
                \C2P\Settings::render_page();
            } else {
                echo '<div class="wrap"><h1>'.esc_html__('Configurações', 'c2p').'</h1>';
                echo '<p>'.esc_html__('(Módulo de configurações não encontrado.)', 'c2p').'</p>';
                echo '</div>';
            }
        }
    );
}, 9);

/** Realçar menu pai quando dentro do CPT de lojas */
add_filter( 'parent_file', function( $parent_file ) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'c2p_store' ) {
        $parent_file = 'c2p-dashboard';
    }
    return $parent_file;
}, 10, 1 );

/** Fixar submenu ativo quando dentro do CPT de lojas */
add_filter( 'submenu_file', function( $submenu_file ) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'c2p_store' ) {
        $submenu_file = 'edit.php?post_type=c2p_store';
    }
    return $submenu_file;
}, 10, 1 );

/** =========================
 * Varredura de inicialização (Action Scheduler)
 * ========================= */

/** Executor do job de inicialização (Action Scheduler) */
add_action( 'c2p_init_full_scan', function() {
    if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'run_async') ) {
        \C2P\Init_Scan::run_async( 1000 );
    } elseif ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'run_full_scan') ) {
        \C2P\Init_Scan::run_full_scan( 1000 );
    }
} );

/** Handler do botão (aba Ferramentas) — mantém fila via Action Scheduler */
add_action( 'admin_post_c2p_run_init_scan', function(){
    if ( ! current_user_can('manage_woocommerce') ) {
        wp_die( esc_html__('Permissão insuficiente.', 'c2p') );
    }
    check_admin_referer( 'c2p_run_init_scan', 'c2p_nonce' );

    if ( function_exists('as_enqueue_async_action') ) {
        as_enqueue_async_action( 'c2p_init_full_scan', [], 'c2p' );
        $msg = __('Varredura enfileirada com sucesso. Acompanhe no Action Scheduler.', 'c2p');
    } else {
        if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan','run_full_scan') ) {
            \C2P\Init_Scan::run_full_scan( 500 );
        }
        $msg = __('Varredura executada imediatamente (sem Action Scheduler).', 'c2p');
    }

    wp_safe_redirect( add_query_arg( 'c2p_notice', rawurlencode( $msg ), admin_url('admin.php?page=c2p-settings&tab=tools') ) );
    exit;
});
