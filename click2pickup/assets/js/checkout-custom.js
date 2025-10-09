<?php
/**
 * Plugin Name: Click2Pickup (Store Pickup & Multi-Estoque)
 * Description: Gerencia lojas/pontos de retirada, CDs e multi-estoque por local com espelhamento no WooCommerce.
 * Author: rhpimenta
 * Version: 1.5.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 * Text Domain: c2p
 * Domain Path: /languages
 * 
 * @package Click2Pickup
 * @since 1.0.0
 * @author rhpimenta
 * @license GPL-2.0+
 * 
 * ✅ v1.5.2: CHECKOUT CUSTOMIZADO INTEGRADO
 * ✅ v1.5.1: Otimização de carregamento
 * Last Update: 2025-01-09 19:30:00 UTC
 * Updated by: rhpimenta
 */

namespace C2P;

// Security: Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ========================================
 * CONSTANTS
 * ========================================
 */
if (!defined('C2P_VERSION'))      define('C2P_VERSION', '1.5.2');
if (!defined('C2P_TEXTDOMAIN'))   define('C2P_TEXTDOMAIN', 'c2p');
if (!defined('C2P_FILE'))         define('C2P_FILE', __FILE__);
if (!defined('C2P_PATH'))         define('C2P_PATH', plugin_dir_path(__FILE__));
if (!defined('C2P_URL'))          define('C2P_URL', plugin_dir_url(__FILE__));
if (!defined('C2P_BASENAME'))     define('C2P_BASENAME', plugin_basename(__FILE__));

// Debug mode (only logs if WP_DEBUG is enabled)
if (!defined('C2P_DEBUG'))        define('C2P_DEBUG', defined('WP_DEBUG') && WP_DEBUG);

/**
 * ========================================
 * DECLARAÇÃO DE COMPATIBILIDADE COM HPOS
 * ========================================
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * ========================================
 * DEPENDENCY CHECKER
 * ========================================
 */
final class C2P_Dependency_Checker {
    
    public static function check_woocommerce() {
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true)) {
            if (is_multisite()) {
                $plugins = get_site_option('active_sitewide_plugins');
                if (!isset($plugins['woocommerce/woocommerce.php'])) {
                    return false;
                }
            } else {
                return false;
            }
        }

        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '<')) {
            return false;
        }

        return true;
    }

    public static function admin_notice_missing_woocommerce() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>' . esc_html__('Click2Pickup', 'c2p') . '</strong>: ';
        echo esc_html__('Este plugin requer WooCommerce 6.0 ou superior para funcionar. Por favor, instale e ative o WooCommerce.', 'c2p');
        echo '</p></div>';
    }

    public static function check_required_file($file_path) {
        return file_exists($file_path) && is_readable($file_path);
    }

    public static function deactivate_with_error($message) {
        deactivate_plugins(C2P_BASENAME);
        wp_die(
            wp_kses_post($message),
            esc_html__('Click2Pickup — Erro de Ativação', 'c2p'),
            ['back_link' => true]
        );
    }
}

/**
 * ========================================
 * ACTIVATION HOOK
 * ========================================
 */
register_activation_hook(__FILE__, function() {
    if (!C2P_Dependency_Checker::check_woocommerce()) {
        C2P_Dependency_Checker::deactivate_with_error(
            '<p><strong>Click2Pickup:</strong> ' . 
            esc_html__('Este plugin requer WooCommerce 6.0 ou superior.', 'c2p') . 
            '</p>'
        );
    }

    $required_files = [
        C2P_PATH . 'includes/class-core.php',
        C2P_PATH . 'includes/class-constants.php',
    ];

    foreach ($required_files as $file) {
        if (!C2P_Dependency_Checker::check_required_file($file)) {
            C2P_Dependency_Checker::deactivate_with_error(
                '<p><strong>Click2Pickup:</strong> ' . 
                esc_html__('Arquivo obrigatório ausente:', 'c2p') . 
                '</p><code>' . esc_html(str_replace(ABSPATH, '', $file)) . '</code>'
            );
        }
    }

    // Run installer activation
    if (file_exists(C2P_PATH . 'includes/class-installer.php')) {
        require_once C2P_PATH . 'includes/class-installer.php';
        if (class_exists('\C2P\Installer') && method_exists('\C2P\Installer', 'activate')) {
            \C2P\Installer::activate();
        }
    }

    flush_rewrite_rules();
});

/**
 * ========================================
 * DEACTIVATION HOOK
 * ========================================
 */
register_deactivation_hook(__FILE__, function() {
    if (file_exists(C2P_PATH . 'includes/class-installer.php')) {
        require_once C2P_PATH . 'includes/class-installer.php';
        if (class_exists('\C2P\Installer') && method_exists('\C2P\Installer', 'deactivate')) {
            \C2P\Installer::deactivate();
        }
    }
    
    flush_rewrite_rules();
});

/**
 * ========================================
 * EARLY EXIT - Check dependencies
 * ========================================
 */
add_action('plugins_loaded', function() {
    if (!C2P_Dependency_Checker::check_woocommerce()) {
        add_action('admin_notices', ['\C2P\C2P_Dependency_Checker', 'admin_notice_missing_woocommerce']);
        return;
    }

    c2p_load_plugin();
}, 1);

/**
 * ========================================
 * MAIN PLUGIN LOADER
 * ========================================
 */
function c2p_load_plugin() {
    
    load_plugin_textdomain(
        C2P_TEXTDOMAIN, 
        false, 
        dirname(C2P_BASENAME) . '/languages'
    );

    /**
     * CORE FILES - Always loaded
     */
    $core_files = [
        'includes/class-constants.php',
        'includes/class-installer.php',
        'includes/class-core.php',
        'includes/class-order.php',
        'includes/class-stock-ledger.php',
        'includes/class-locations.php',
        'includes/class-custom-cart.php',
        'includes/class-custom-checkout.php', // ✅ Checkout customizado
        'includes/class-rest-api.php',
        'includes/class-settings.php',
        'includes/class-product-ui.php',
        'includes/class-inventory-report.php',
        'includes/class-stock-report.php',
    ];

    if (file_exists(C2P_PATH . 'includes/class-low-stock-alerts-core.php')) {
        $core_files[] = 'includes/class-low-stock-alerts-core.php';
    }

    c2p_require_files($core_files);

    // ✅ Bootstrap custom cart
    if (class_exists('\C2P\Custom_Cart')) {
        \C2P\Custom_Cart::boot(
            C2P_URL . 'assets/', 
            C2P_PATH . 'includes/templates/', 
            C2P_VERSION
        );
    }

    // ✅ Bootstrap custom checkout
    if (class_exists('\C2P\Custom_Checkout')) {
        \C2P\Custom_Checkout::boot(
            C2P_URL . 'assets/', // ✅ CORRIGIDO (sem cart/)
            C2P_PATH . 'includes/templates/', 
            C2P_VERSION
        );
    }

    /**
     * ADMIN-ONLY FILES (excluding AJAX)
     */
    if (is_admin() && !wp_doing_ajax()) {
        $admin_only_files = [];
        
        if (file_exists(C2P_PATH . 'includes/settings-tabs/trait-tools.php')) {
            $admin_only_files[] = 'includes/settings-tabs/trait-tools.php';
        }

        c2p_require_files($admin_only_files);

        if (c2p_is_reports_page()) {
            if (file_exists(C2P_PATH . 'includes/admin/reports/class-stock-report-admin.php')) {
                c2p_require_files(['includes/admin/reports/class-stock-report-admin.php']);
            }
        }
    }

    /**
     * TEST FILE - Only if WP_DEBUG
     */
    if (C2P_DEBUG && file_exists(C2P_PATH . 'includes/test-prep-time.php')) {
        require_once C2P_PATH . 'includes/test-prep-time.php';
    }

    /**
     * ✅ Bootstrap Stock_Ledger cedo (antes de init)
     */
    if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'maybe_install')) {
        add_action('plugins_loaded', ['\C2P\Stock_Ledger', 'maybe_install'], 0);
    }

    /**
     * Bootstrap classes
     */
    add_action('init', 'C2P\c2p_bootstrap_classes', 20);

    /**
     * Admin menus
     */
    if (is_admin()) {
        add_action('admin_menu', 'C2P\c2p_register_admin_menu', 9);
        add_filter('parent_file', 'C2P\c2p_highlight_admin_menu');
        add_filter('submenu_file', 'C2P\c2p_highlight_admin_submenu');
    }

    /**
     * Settings
     */
    if (is_admin() && class_exists('\C2P\Settings')) {
        add_action('admin_init', function() {
            \C2P\Settings::instance();
        }, 5);

        add_filter('allowed_options', function($allowed) {
            if (!isset($allowed['c2p_settings_group'])) {
                $allowed['c2p_settings_group'] = [];
            }
            if (!in_array('c2p_settings', $allowed['c2p_settings_group'], true)) {
                $allowed['c2p_settings_group'][] = 'c2p_settings';
            }
            return $allowed;
        });
    }

    /**
     * Custom actions
     */
    add_action('c2p_init_full_scan', 'C2P\c2p_handle_init_scan');
    add_action('admin_post_c2p_run_init_scan', 'C2P\c2p_admin_run_init_scan');
}

/**
 * ========================================
 * HELPER FUNCTIONS
 * ========================================
 */

function c2p_require_files(array $files) {
    foreach ($files as $file) {
        $file_path = C2P_PATH . ltrim($file, '/');
        
        if (!file_exists($file_path)) {
            if (C2P_DEBUG) {
                error_log("[C2P] File not found: {$file}");
            }
            continue;
        }

        if (!is_readable($file_path)) {
            if (C2P_DEBUG) {
                error_log("[C2P] File not readable: {$file}");
            }
            continue;
        }

        require_once $file_path;
    }
}

function c2p_is_reports_page() {
    if (!function_exists('get_current_screen')) {
        return false;
    }
    
    $screen = get_current_screen();
    if (!$screen) {
        return false;
    }

    return strpos($screen->id, 'c2p') !== false && 
           strpos($screen->id, 'report') !== false;
}

/**
 * ✅ Bootstrap de classes (centralizado)
 */
function c2p_bootstrap_classes() {
    // Classes obrigatórias
    $classes = [
        '\C2P\Locations_Admin'       => 'instance',
        '\C2P\Product_UI'            => 'instance',
        '\C2P\REST_API'              => 'instance',
        '\C2P\Low_Stock_Alerts_Core' => 'instance',
        '\C2P\Inventory_Report'      => 'instance',
        '\C2P\Stock_Report'          => 'instance',
        '\C2P\Order'                 => 'instance',
    ];
    
    // Classes opcionais (podem não existir)
    $optional_classes = [
        '\C2P\Cart_Frontend'         => 'init',
        '\C2P\Order_Notes'           => 'instance',
        '\C2P\Order_Admin_Notes'     => 'instance',
        '\C2P\Order_List_Column'     => 'boot',
        '\C2P\Stock_Report_Admin'    => 'instance',
    ];

    // Bootstrap classes obrigatórias (silencioso se não existir)
    foreach ($classes as $class => $method) {
        if (class_exists($class) && method_exists($class, $method)) {
            call_user_func([$class, $method]);
        } elseif (C2P_DEBUG) {
            error_log("[C2P] Class not found or method missing: {$class}::{$method}");
        }
    }
    
    // Bootstrap classes opcionais (sem log de erro)
    foreach ($optional_classes as $class => $method) {
        if (class_exists($class) && method_exists($class, $method)) {
            call_user_func([$class, $method]);
        }
    }
}

function c2p_register_admin_menu() {
    add_menu_page(
        __('Click2Pickup — Dashboard', 'c2p'),
        __('Click2Pickup', 'c2p'),
        'manage_woocommerce',
        'c2p-dashboard',
        'C2P\c2p_render_dashboard_page',
        'dashicons-store',
        56
    );

    add_submenu_page(
        'c2p-dashboard',
        __('Dashboard', 'c2p'),
        __('Dashboard', 'c2p'),
        'manage_woocommerce',
        'c2p-dashboard',
        'C2P\c2p_render_dashboard_page'
    );

    add_submenu_page(
        'c2p-dashboard',
        __('Locais de Estoque', 'c2p'),
        __('Locais de Estoque', 'c2p'),
        'manage_woocommerce',
        'edit.php?post_type=c2p_store'
    );

    add_submenu_page(
        'c2p-dashboard',
        __('Configurações', 'c2p'),
        __('Configurações', 'c2p'),
        'manage_woocommerce',
        'c2p-settings',
        'C2P\c2p_render_settings_page'
    );
}

function c2p_render_dashboard_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Você não tem permissão para acessar esta página.', 'c2p'));
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Click2Pickup — Dashboard', 'c2p') . '</h1>';
    echo '<p>' . esc_html__('Bem-vindo! Use o submenu para gerenciar Lojas e Configurações.', 'c2p') . '</p>';
    
    echo '<div class="c2p-dashboard-widgets" style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-top:20px;">';
    
    echo '<h2 style="margin-top:0;">📊 ' . esc_html__('Informações do Sistema', 'c2p') . '</h2>';
    
    echo '<table class="widefat" style="margin-top:15px;">';
    echo '<tbody>';
    
    echo '<tr>';
    echo '<td style="padding:12px;"><strong>' . esc_html__('Versão do Plugin:', 'c2p') . '</strong></td>';
    echo '<td style="padding:12px;"><code>v' . esc_html(C2P_VERSION) . '</code></td>';
    echo '</tr>';
    
    echo '<tr>';
    echo '<td style="padding:12px;"><strong>' . esc_html__('WooCommerce:', 'c2p') . '</strong></td>';
    echo '<td style="padding:12px;"><code>v' . esc_html(defined('WC_VERSION') ? WC_VERSION : 'N/A') . '</code></td>';
    echo '</tr>';
    
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
        $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        echo '<tr>';
        echo '<td style="padding:12px;"><strong>' . esc_html__('HPOS (High-Performance Order Storage):', 'c2p') . '</strong></td>';
        echo '<td style="padding:12px;">';
        echo $hpos_enabled 
            ? '<span style="color:#16a34a;">✅ ' . esc_html__('Ativado', 'c2p') . '</span>' 
            : '<span style="color:#dc2626;">❌ ' . esc_html__('Desativado', 'c2p') . '</span>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '<tr>';
    echo '<td style="padding:12px;"><strong>' . esc_html__('PHP:', 'c2p') . '</strong></td>';
    echo '<td style="padding:12px;"><code>v' . esc_html(PHP_VERSION) . '</code></td>';
    echo '</tr>';
    
    echo '<tr>';
    echo '<td style="padding:12px;"><strong>' . esc_html__('WordPress:', 'c2p') . '</strong></td>';
    echo '<td style="padding:12px;"><code>v' . esc_html(get_bloginfo('version')) . '</code></td>';
    echo '</tr>';
    
    echo '</tbody>';
    echo '</table>';
    
    // ✅ Info de auditoria
    echo '<h2 style="margin-top:30px;">📝 ' . esc_html__('Sistema de Auditoria', 'c2p') . '</h2>';
    
    if (class_exists('\C2P\Stock_Ledger')) {
        global $wpdb;
        $ledger_table = class_exists('\C2P\Constants') ? \C2P\Constants::table_ledger() : $wpdb->prefix . 'c2p_stock_ledger';
        $ledger_table_escaped = esc_sql($ledger_table);
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$ledger_table_escaped}`");
        
        echo '<table class="widefat" style="margin-top:15px;">';
        echo '<tbody>';
        echo '<tr>';
        echo '<td style="padding:12px;"><strong>' . esc_html__('Registros de Auditoria:', 'c2p') . '</strong></td>';
        echo '<td style="padding:12px;"><code>' . esc_html(number_format_i18n((int)$count)) . '</code> ' . esc_html__('movimentações registradas', 'c2p') . '</td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p style="color:#dc2626;"><em>' . esc_html__('Stock Ledger não carregado.', 'c2p') . '</em></p>';
    }
    
    // ✅ Locais cadastrados
    echo '<h2 style="margin-top:30px;">📍 ' . esc_html__('Locais de Estoque', 'c2p') . '</h2>';
    
    $stores = get_posts([
        'post_type' => 'c2p_store',
        'post_status' => 'publish',
        'numberposts' => -1,
    ]);
    
    if ($stores) {
        echo '<p>' . sprintf(esc_html__('Total de locais ativos: %d', 'c2p'), count($stores)) . '</p>';
        echo '<ul style="list-style:disc;margin-left:20px;">';
        foreach ($stores as $store) {
            $type = get_post_meta($store->ID, 'c2p_type', true);
            $icon = $type === 'cd' ? '📦' : '🏪';
            echo '<li>' . $icon . ' <strong>' . esc_html($store->post_title) . '</strong> (#' . (int)$store->ID . ')</li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color:#dc2626;"><em>' . esc_html__('Nenhum local cadastrado.', 'c2p') . '</em></p>';
    }
    
    // ✅ Info do checkout customizado
    echo '<h2 style="margin-top:30px;">🛒 ' . esc_html__('Checkout Customizado', 'c2p') . '</h2>';
    
    echo '<table class="widefat" style="margin-top:15px;">';
    echo '<tbody>';
    echo '<tr>';
    echo '<td style="padding:12px;"><strong>' . esc_html__('Status:', 'c2p') . '</strong></td>';
    echo '<td style="padding:12px;">';
    if (class_exists('\C2P\Custom_Checkout')) {
        echo '<span style="color:#16a34a;">✅ ' . esc_html__('Ativo', 'c2p') . '</span>';
    } else {
        echo '<span style="color:#dc2626;">❌ ' . esc_html__('Inativo', 'c2p') . '</span>';
    }
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td style="padding:12px;"><strong>' . esc_html__('Shortcode:', 'c2p') . '</strong></td>';
    echo '<td style="padding:12px;"><code>[c2p_checkout]</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'[c2p_checkout]\');alert(\'Shortcode copiado!\')">📋 Copiar</button></td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
    
    echo '</div>';
    echo '</div>';
}

function c2p_render_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Você não tem permissão para acessar esta página.', 'c2p'));
    }

    if (class_exists('\C2P\Settings')) {
        \C2P\Settings::render_page();
    } else {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Configurações', 'c2p') . '</h1>';
        echo '<p>' . esc_html__('Módulo de configurações não encontrado.', 'c2p') . '</p>';
        echo '</div>';
    }
}

function c2p_highlight_admin_menu($parent_file) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->post_type === 'c2p_store') {
        return 'c2p-dashboard';
    }
    return $parent_file;
}

function c2p_highlight_admin_submenu($submenu_file) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->post_type === 'c2p_store') {
        return 'edit.php?post_type=c2p_store';
    }
    return $submenu_file;
}

function c2p_handle_init_scan() {
    if (class_exists('\C2P\Init_Scan')) {
        if (method_exists('\C2P\Init_Scan', 'run_async')) {
            \C2P\Init_Scan::run_async(1000);
        } elseif (method_exists('\C2P\Init_Scan', 'run_full_scan')) {
            \C2P\Init_Scan::run_full_scan(1000);
        }
    }
}

function c2p_admin_run_init_scan() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Permissão insuficiente.', 'c2p'), 403);
    }

    check_admin_referer('c2p_run_init_scan', 'c2p_nonce');

    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('c2p_init_full_scan', [], 'c2p');
        $message = __('Varredura enfileirada com sucesso. Acompanhe no Action Scheduler.', 'c2p');
    } else {
        if (class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'run_full_scan')) {
            \C2P\Init_Scan::run_full_scan(500);
        }
        $message = __('Varredura executada imediatamente (Action Scheduler não disponível).', 'c2p');
    }

    $redirect_url = add_query_arg(
        'c2p_notice',
        rawurlencode($message),
        admin_url('admin.php?page=c2p-settings&tab=tools')
    );

    wp_safe_redirect($redirect_url);
    exit;
}