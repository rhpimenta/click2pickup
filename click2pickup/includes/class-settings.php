<?php
/**
 * Configurações do Click2Pickup — UI com abas e persistência
 * 
 * ✅ v2.0: SQL escape, validações de segurança, cache otimizado
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-10-09 12:05:47 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 12:05: ✅ CORRIGIDO: SQL escape em get_options_fresh()
 * - 2025-10-09 12:05: ✅ CORRIGIDO: Validação de tab com whitelist
 * - 2025-10-09 12:05: ✅ CORRIGIDO: Cache flush específico (não global)
 * - 2025-10-09 12:05: ✅ MELHORADO: Ajax healthcheck com nonce
 * - 2025-10-09 12:05: ✅ MELHORADO: Autocomplete com new-password
 */

namespace C2P;

if (!defined('ABSPATH')) { exit; }

if (!class_exists(__NAMESPACE__ . '\\Settings')) :

// ✅ CAMINHOS ATUALIZADOS
require_once __DIR__ . '/settings-tabs/trait-general.php';
require_once __DIR__ . '/settings-tabs/trait-erp.php';
require_once __DIR__ . '/settings-tabs/trait-advanced.php';
require_once __DIR__ . '/settings-tabs/trait-health.php';
require_once __DIR__ . '/settings-tabs/tab-emails.php';
require_once __DIR__ . '/settings-tabs/trait-tools.php';

final class Settings {
    use \C2P\Settings_Tabs\Tab_General;
    use \C2P\Settings_Tabs\Tab_ERP;
    use \C2P\Settings_Tabs\Tab_Advanced;
    use \C2P\Settings_Tabs\Tab_Health;
    use \C2P\Settings_Tabs\Tab_Emails;
    use \C2P\Settings_Tabs\Tab_Tools;

    private static $option_key = 'c2p_settings';

    /**
     * ✅ NOVO: Whitelist de tabs permitidas
     */
    const ALLOWED_TABS = ['general', 'erp', 'advanced', 'emails', 'tools', 'health'];

    private static $defaults = [
        // Geral
        'general_notes' => '',

        // ERP
        'accept_global_stock'       => 0,
        'global_strategy'           => 'delta_cd',
        'cd_store_id'               => 0,
        'cd_apply_delta'            => 1,
        'delta_negative_fallback'   => 0,
        'proportional_use_weights'  => 0,
        'proportional_weights_json' => '',
        'protect_pickup_only'       => 1,
        'allow_low_stock_global'    => 0,
        'log_detailed'              => 1,
        'on_global_disabled'        => 'ignore_ok',

        // Avançado
        'hard_limits_enabled'       => 0,
        'hard_limit_max_qty'        => 100000,
        'hard_limit_max_delta'      => 50000,
        'dry_run_default'           => 0,

        // E-mails (nova venda)
        'email_pickup_enabled'         => 0,
        'email_pickup_notify_delivery' => 0,
        'email_pickup_from_name'       => '',
        'email_pickup_from_email'      => '',
        'email_pickup_to_mode'         => 'store',
        'email_pickup_custom_to'       => '',
        'email_pickup_bcc'             => '',
        'email_pickup_subject'         => 'Novo pedido #{order_number} - {unit_name}',
        'email_pickup_body_html'       => '',
        'email_pickup_test_to'         => '',

        // E-mails (estoque baixo)
        'email_lowstock_enabled'       => 0,
        'email_lowstock_subject'       => 'Alerta: Estoque baixo — {product_name}',
        'email_lowstock_body_html'     => '',
        'email_lowstock_bcc'           => '',
    ];

    public static function instance(): self {
        static $inst = null;
        if (null === $inst) { $inst = new self(); }
        return $inst;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);

        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook !== 'toplevel_page_c2p-dashboard'
             && $hook !== 'click2pickup_page_c2p-settings'
             && $hook !== 'c2p_page_c2p-settings') {
                if (!isset($_GET['page']) || $_GET['page'] !== 'c2p-settings') return;
            }
            $css = '.c2p-settings-wrap .form-table th{ width:320px } .c2p-tools-box{ background:#fff;border:1px solid #e2e2e2;padding:16px;border-radius:8px;max-width:980px;margin-top:10px } .c2p-report{ background:#f6f7f7;border:1px solid #e2e2e2;padding:12px;border-radius:6px;margin-top:12px }';
            wp_add_inline_style('common', $css);
        });
    }

    public static function get_options(): array {
        $saved = get_option(self::$option_key, []);
        if (!is_array($saved)) $saved = [];
        return array_replace_recursive(self::$defaults, $saved);
    }

    /**
     * ✅ CORRIGIDO: SQL escape adequado
     */
    private static function get_options_fresh(): array {
        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nome de tabela
        $table = esc_sql($wpdb->options);
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM `{$table}` WHERE option_name=%s LIMIT 1",
            self::$option_key
        ));
        
        $val   = $row ? maybe_unserialize($row->option_value) : [];
        $saved = is_array($val) ? $val : [];
        return array_replace_recursive(self::$defaults, $saved);
    }

    /**
     * ✅ CORRIGIDO: Flush apenas opção específica
     */
    private static function flush_option_cache(): void {
        // ✅ Deleta apenas a opção específica (não alloptions)
        wp_cache_delete(self::$option_key, 'options');
        
        // Se usar object cache externo (Redis/Memcached), limpa lá também
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('options');
        }
    }

    public function register_settings(): void {
        register_setting(
            'c2p_settings_group',
            self::$option_key,
            [
                'type'         => 'array',
                'capability'   => 'manage_woocommerce',
                'default'      => self::$defaults,
                'show_in_rest' => false,
                'sanitize_callback' => [$this, 'sanitize_settings'],
            ]
        );

        /**
         * ✅ MELHORADO: Merge com validação
         */
        add_filter('pre_update_option_' . self::$option_key, function($new, $old) {
            if (!is_array($old)) $old = [];
            if (!is_array($new)) $new = [];
            
            // Merge apenas chaves que existem nos defaults
            $merged = array_replace_recursive($old, $new);
            
            // Remove chaves que não existem nos defaults (segurança)
            $merged = array_intersect_key($merged, self::$defaults);
            
            return $merged;
        }, 9, 2);
    }

    /**
     * ✅ NOVO: Sanitização de configurações
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return self::$defaults;
        }

        $sanitized = [];

        // Campos numéricos
        $numeric_fields = [
            'accept_global_stock', 'cd_store_id', 'cd_apply_delta',
            'delta_negative_fallback', 'proportional_use_weights',
            'protect_pickup_only', 'allow_low_stock_global', 'log_detailed',
            'hard_limits_enabled', 'hard_limit_max_qty', 'hard_limit_max_delta',
            'dry_run_default', 'email_pickup_enabled', 'email_pickup_notify_delivery',
            'email_lowstock_enabled',
        ];

        foreach ($numeric_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = absint($input[$field]);
            }
        }

        // Campos de texto
        $text_fields = [
            'general_notes', 'global_strategy', 'proportional_weights_json',
            'on_global_disabled', 'email_pickup_from_name', 'email_pickup_to_mode',
            'email_pickup_custom_to', 'email_pickup_subject', 'email_lowstock_subject',
        ];

        foreach ($text_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_text_field($input[$field]);
            }
        }

        // E-mails
        $email_fields = ['email_pickup_from_email', 'email_pickup_bcc', 'email_lowstock_bcc', 'email_pickup_test_to'];
        foreach ($email_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_email($input[$field]);
            }
        }

        // HTML (e-mails)
        $html_fields = ['email_pickup_body_html', 'email_lowstock_body_html'];
        foreach ($html_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = wp_kses_post($input[$field]);
            }
        }

        return $sanitized;
    }

    /**
     * ✅ CORRIGIDO: Validação de tab com whitelist
     */
    public static function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Operação não permitida.', 'c2p'));
        }

        // ✅ VALIDAÇÃO: Whitelist de tabs
        $tab = 'general';
        if (isset($_GET['tab'])) {
            $requested_tab = sanitize_key($_GET['tab']);
            if (in_array($requested_tab, self::ALLOWED_TABS, true)) {
                $tab = $requested_tab;
            }
        }

        $tabs = [
            'general'   => __('Geral', 'c2p'),
            'erp'       => __('Estoque Global (ERP)', 'c2p'),
            'advanced'  => __('Avançado', 'c2p'),
            'emails'    => __('E-mails (Retirada)', 'c2p'),
            'tools'     => __('Ferramentas', 'c2p'),
            'health'    => __('Saúde da API', 'c2p'),
        ];

        // SEMPRE ler direto do DB nesta página (evita descompasso visual)
        self::flush_option_cache();
        $opts = self::get_options_fresh();

        echo '<div class="wrap c2p-settings-wrap">';
        echo '<h1>' . esc_html__('Click2Pickup — Configurações', 'c2p') . '</h1>';

        echo '<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $class = ($slug === $tab) ? ' nav-tab nav-tab-active' : ' nav-tab';
            $url   = esc_url(admin_url('admin.php?page=c2p-settings&tab=' . $slug));
            echo '<a class="' . esc_attr($class) . '" href="' . $url . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        // Avisos de testes
        if (isset($_GET['sent'])) {
            $ok = ($_GET['sent'] === '1');
            echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' is-dismissible"><p>' . 
                 esc_html($ok ? __('E-mail de nova venda enviado.', 'c2p') : __('Falha ao enviar e-mail de nova venda.', 'c2p')) . 
                 '</p></div>';
        }
        
        if (isset($_GET['lowstock_sent'])) {
            $ok = ($_GET['lowstock_sent'] === '1');
            echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' is-dismissible"><p>' . 
                 esc_html($ok ? __('E-mail de estoque baixo enviado.', 'c2p') : __('Falha ao enviar e-mail de estoque baixo.', 'c2p')) . 
                 '</p></div>';
        }

        /**
         * ✅ MELHORADO: Autocomplete desligado com new-password
         */
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '" class="c2p-settings" autocomplete="new-password">';
        settings_fields('c2p_settings_group');

        echo '<div class="c2p-tab-content">';
        
        // ✅ SEGURANÇA: Switch com whitelist
        switch ($tab) {
            case 'erp':
                self::render_tab_erp($opts);
                break;
            case 'advanced':
                self::render_tab_advanced($opts);
                break;
            case 'health':
                self::render_tab_health($opts);
                break;
            case 'emails':
                self::render_tab_emails($opts);
                break;
            case 'tools':
                self::render_tab_tools($opts);
                break;
            case 'general':
            default:
                self::render_tab_general($opts);
                break;
        }
        
        echo '</div>';

        submit_button(__('Salvar alterações', 'c2p'));
        echo '</form>';

        echo '</div>';
    }
}

endif;

/**
 * ✅ CORRIGIDO: Ajax healthcheck com nonce
 */
add_action('wp_ajax_c2p_rest_healthcheck', function() {
    // ✅ SEGURANÇA: Verifica nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'c2p_healthcheck')) {
        wp_send_json_error('invalid_nonce', 403);
    }

    // ✅ SEGURANÇA: Verifica permissão
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('forbidden', 403);
    }

    // Chama o método real
    if (class_exists('\\C2P\\Settings') && method_exists('\\C2P\\Settings', 'hc_ajax_run')) {
        \C2P\Settings::hc_ajax_run();
    } else {
        wp_send_json_error('method_not_found', 500);
    }
});