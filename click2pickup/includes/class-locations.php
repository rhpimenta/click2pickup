<?php
/**
 * Click2Pickup - Locais de Estoque (CPT c2p_store)
 * 
 * ✅ NOVO v1.8.0: Botão "Salvar" fixo em dias especiais
 * ✅ NOVO v1.8.0: Validação: limite + preparo <= fechamento
 * ✅ NOVO v1.8.0: Email obrigatório (validação PHP + JS)
 * ✅ NOVO v1.8.0: Feedback visual de erros (notices)
 * 
 * @package Click2Pickup
 * @since 1.8.0
 * @author rhpimenta
 * Last Update: 2025-01-07 23:15:00 UTC
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

class Locations {
    private static $instance;
    
    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        CPT_Store::instance();
        Store_Shipping_Link::instance();
    }
}

/* ================================================================
 * PARTE 1: CPT_Store (Registro + Metaboxes + UI)
 * ================================================================ */

class CPT_Store {
    private static $instance;

    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_cpt'], 0);
        add_action('add_meta_boxes', [$this, 'metaboxes']);
        add_action('save_post_c2p_store', [$this, 'save_meta']);

        add_action('admin_enqueue_scripts', [$this, 'inject_assets']);
        add_action('admin_notices', [$this, 'maybe_field_notices']);

        add_filter('parent_file', [$this, 'menu_highlight_parent']);
        add_filter('submenu_file', [$this, 'menu_highlight_sub']);

        add_filter('manage_c2p_store_posts_columns', [$this, 'columns']);
        add_action('manage_c2p_store_posts_custom_column', [$this, 'columns_content'], 10, 2);

        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('admin_post_c2p_duplicate_store', [$this, 'duplicate_store']);

        add_action('before_delete_post', [$this, 'on_before_delete_post']);
        add_action('transition_post_status', [$this, 'on_transition_status'], 10, 3);
        add_action('save_post_c2p_store', [$this, 'maybe_enqueue_scan_on_save'], 9999, 3);
    }

    public function register_cpt(): void {
        register_post_type(C2P::POST_TYPE_STORE, [
            'labels' => [
                'name' => __('Locais de Estoque', 'c2p'),
                'singular_name' => __('Local de Estoque', 'c2p'),
                'add_new_item' => __('Adicionar novo Local de Estoque', 'c2p'),
                'edit_item' => __('Editar Local de Estoque', 'c2p'),
                'menu_name' => __('Locais de Estoque', 'c2p'),
                'name_admin_bar' => __('Local de Estoque', 'c2p'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title'],
        ]);
    }

    /* ================================================================
     * ASSETS INLINE (CSS + JS) - COM VALIDAÇÕES
     * ================================================================ */

    public function inject_assets($hook): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== C2P::POST_TYPE_STORE) return;

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', [], '1.13.2');

        $countries = [];
        $states_by_country = [];
        if (function_exists('wc') && isset(wc()->countries)) {
            $countries = wc()->countries->get_countries();
            $states_by_country = wc()->countries->get_states();
        }

        $i18n = [
            'media_title' => __('Selecionar imagem', 'c2p'),
            'media_button' => __('Usar imagem', 'c2p'),
            'confirmUnlock' => __('Marque a confirmação antes de destravar.', 'c2p'),
            'invalidPhone' => __('Telefone inválido. Use (xx)xxxxx-xxxx ou (xx)xxxx-xxxx.', 'c2p'),
            'invalidEmail' => __('E-mail inválido. Corrija para salvar.', 'c2p'),
            'emailRequired' => __('E-mail é obrigatório.', 'c2p'),
            'timeValidationError' => __('ERRO: Horário limite + tempo de preparo ultrapassa o horário de fechamento.', 'c2p'),
        ];

        // ✅ INLINE CSS
        add_action('admin_head', function() {
            ?>
            <style>
                :root {
                    --c2p-white: #ffffff;
                    --c2p-gray-50: #fafafa;
                    --c2p-gray-100: #f5f5f5;
                    --c2p-gray-200: #e5e5e5;
                    --c2p-gray-300: #d4d4d4;
                    --c2p-gray-400: #a3a3a3;
                    --c2p-gray-500: #737373;
                    --c2p-gray-600: #525252;
                    --c2p-gray-700: #404040;
                    --c2p-gray-800: #262626;
                    --c2p-gray-900: #171717;
                    --c2p-blue: #2563eb;
                    --c2p-blue-hover: #1d4ed8;
                    --c2p-green: #16a34a;
                    --c2p-red: #dc2626;
                    --c2p-amber: #f59e0b;
                    --c2p-radius: 12px;
                    --c2p-radius-sm: 8px;
                    --c2p-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
                    --c2p-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
                    --c2p-shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
                }

                #c2p_store_main .inside,
                #c2p_store_hours .inside,
                #c2p_store_special .inside,
                #c2p_store_shipping .inside {
                    padding: 0 !important;
                    margin: 0 !important;
                }

                #c2p_store_main.postbox,
                #c2p_store_hours.postbox,
                #c2p_store_special.postbox,
                #c2p_store_shipping.postbox {
                    border: 1px solid var(--c2p-gray-200);
                    box-shadow: var(--c2p-shadow-sm);
                    border-radius: var(--c2p-radius);
                }

                .c2p-main-table {
                    width: 100%;
                    border-collapse: separate;
                    border-spacing: 0;
                    margin: 0 !important;
                }

                .c2p-main-table tr:first-child th,
                .c2p-main-table tr:first-child td {
                    padding-top: 24px !important;
                }

                .c2p-main-table tr:last-child th,
                .c2p-main-table tr:last-child td {
                    padding-bottom: 24px !important;
                    border-bottom: none !important;
                }

                .c2p-main-table th {
                    width: 140px;
                    padding: 20px 24px !important;
                    font-size: 13px;
                    font-weight: 600;
                    color: var(--c2p-gray-700);
                    text-align: left;
                    background: transparent;
                    border: none !important;
                }

                .c2p-main-table td {
                    padding: 20px 24px !important;
                    color: var(--c2p-gray-900);
                    border: none !important;
                }

                .c2p-main-table tr {
                    border-bottom: 1px solid var(--c2p-gray-100);
                }

                .c2p-hours-block {
                    padding: 32px;
                    background: var(--c2p-white);
                }

                .c2p-days-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 20px;
                    margin-bottom: 32px;
                    max-width: 1400px;
                    margin-left: auto;
                    margin-right: auto;
                }

                .c2p-day-card {
                    background: var(--c2p-white) !important;
                    border: 1px solid var(--c2p-gray-200) !important;
                    border-radius: var(--c2p-radius) !important;
                    padding: 20px !important;
                    transition: all 0.15s ease !important;
                    box-shadow: var(--c2p-shadow-sm) !important;
                }

                .c2p-day-card:hover {
                    border-color: var(--c2p-gray-300) !important;
                    box-shadow: var(--c2p-shadow) !important;
                }

                /* ✅ NOVO: Estado de erro */
                .c2p-day-card.has-error {
                    border-color: var(--c2p-red) !important;
                    background: #fef2f2 !important;
                }

                .c2p-day-title {
                    font-size: 15px;
                    font-weight: 600;
                    color: var(--c2p-gray-900);
                    margin: 0 0 16px 0;
                    letter-spacing: -0.01em;
                }

                .c2p-day-fields {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    margin-bottom: 12px;
                }

                .c2p-day-fields > div {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }

                .c2p-day-fields label {
                    font-size: 13px;
                    font-weight: 500;
                    color: var(--c2p-gray-600);
                    min-width: 130px;
                    flex-shrink: 0;
                }

                .c2p-time,
                .c2p-date,
                input[type="text"].regular-text,
                input[type="email"].regular-text,
                input[type="number"].small-text,
                select {
                    height: 38px;
                    padding: 0 12px !important;
                    font-size: 14px !important;
                    font-weight: 400;
                    line-height: 38px;
                    color: var(--c2p-gray-900) !important;
                    background: var(--c2p-white) !important;
                    border: 1px solid var(--c2p-gray-300) !important;
                    border-radius: var(--c2p-radius-sm) !important;
                    transition: all 0.15s ease !important;
                    box-shadow: var(--c2p-shadow-sm) !important;
                }

                .c2p-time:hover,
                .c2p-date:hover,
                input[type="text"].regular-text:hover,
                input[type="email"].regular-text:hover,
                input[type="number"].small-text:hover,
                select:hover {
                    border-color: var(--c2p-gray-400) !important;
                }

                .c2p-time:focus,
                .c2p-date:focus,
                input[type="text"].regular-text:focus,
                input[type="email"].regular-text:focus,
                input[type="number"].small-text:focus,
                select:focus {
                    outline: none !important;
                    border-color: var(--c2p-blue) !important;
                    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1) !important;
                }

                .c2p-time {
                    width: 90px;
                    text-align: center;
                    font-variant-numeric: tabular-nums;
                    font-family: ui-monospace, monospace;
                }

                input[type="number"].small-text {
                    width: 80px;
                    text-align: center;
                }

                .c2p-invalid {
                    border-color: var(--c2p-red) !important;
                    background: #fef2f2 !important;
                }

                /* ✅ NOVO: Mensagem de erro */
                .c2p-error-msg {
                    display: none;
                    padding: 8px 12px;
                    margin-top: 8px;
                    background: #fee2e2;
                    border: 1px solid var(--c2p-red);
                    border-radius: 6px;
                    color: #991b1b;
                    font-size: 12px;
                    font-weight: 600;
                }

                .c2p-day-card.has-error .c2p-error-msg {
                    display: block;
                }

                .c2p-switch {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 12px 0 0 0;
                    margin-top: 4px;
                    border-top: 1px solid var(--c2p-gray-100);
                }

                .c2p-switch input[type="checkbox"] {
                    width: 20px;
                    height: 20px;
                    margin: 0;
                    cursor: pointer;
                    accent-color: var(--c2p-blue);
                }

                .c2p-switch span {
                    font-size: 13px;
                    font-weight: 500;
                    color: var(--c2p-gray-700);
                }

                .button {
                    height: 38px;
                    padding: 0 16px !important;
                    font-size: 14px !important;
                    font-weight: 500 !important;
                    line-height: 38px !important;
                    color: var(--c2p-white) !important;
                    background: var(--c2p-blue) !important;
                    border: none !important;
                    border-radius: var(--c2p-radius-sm) !important;
                    cursor: pointer !important;
                    transition: all 0.15s ease !important;
                    box-shadow: var(--c2p-shadow-sm) !important;
                    text-shadow: none !important;
                }

                .button:hover {
                    background: var(--c2p-blue-hover) !important;
                    box-shadow: var(--c2p-shadow) !important;
                }

                .button:active {
                    transform: translateY(1px);
                }

                #c2p-copy-mon {
                    background: var(--c2p-green) !important;
                }

                #c2p-copy-mon:hover {
                    background: #15803d !important;
                }

                .c2p-remove-special,
                .c2p-media-remove {
                    background: transparent !important;
                    color: var(--c2p-red) !important;
                    border: 1px solid var(--c2p-gray-300) !important;
                }

                .c2p-remove-special:hover,
                .c2p-media-remove:hover {
                    background: #fef2f2 !important;
                    border-color: var(--c2p-red) !important;
                }

                .c2p-edit-special {
                    background: transparent !important;
                    color: var(--c2p-blue) !important;
                    border: 1px solid var(--c2p-gray-300) !important;
                }

                .c2p-edit-special:hover {
                    background: #eff6ff !important;
                    border-color: var(--c2p-blue) !important;
                }

                /* ✅ NOVO: Botão fixo de salvar */
                .c2p-sticky-save {
                    position: sticky;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background: linear-gradient(to top, rgba(255,255,255,1) 80%, rgba(255,255,255,0) 100%);
                    padding: 20px 32px;
                    margin: 0 -32px -32px;
                    text-align: center;
                    border-top: 1px solid var(--c2p-gray-200);
                    z-index: 10;
                }

                .c2p-sticky-save .button {
                    background: var(--c2p-green) !important;
                    font-size: 15px !important;
                    padding: 0 32px !important;
                    height: 44px !important;
                    line-height: 44px !important;
                }

                .c2p-sticky-save .button:hover {
                    background: #15803d !important;
                }

                .c2p-special-head {
                    display: grid;
                    grid-template-columns: 130px 80px 80px 80px 100px 1fr 70px 140px;
                    gap: 16px;
                    padding: 16px 20px;
                    margin-bottom: 16px;
                    font-size: 12px;
                    font-weight: 600;
                    color: var(--c2p-gray-600);
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    background: var(--c2p-gray-50);
                    border-radius: var(--c2p-radius-sm);
                }

                .c2p-special-item {
                    display: grid;
                    grid-template-columns: 130px 80px 80px 80px 100px 1fr 70px 140px;
                    gap: 16px;
                    padding: 16px 20px;
                    margin-bottom: 12px;
                    align-items: center;
                    background: var(--c2p-white);
                    border: 1px solid var(--c2p-gray-200);
                    border-radius: var(--c2p-radius-sm);
                    transition: all 0.15s ease;
                    box-shadow: var(--c2p-shadow-sm);
                }

                .c2p-special-item:hover {
                    border-color: var(--c2p-gray-300);
                    box-shadow: var(--c2p-shadow);
                }

                /* ✅ NOVO: Estado de erro em dias especiais */
                .c2p-special-item.has-error {
                    border-color: var(--c2p-red) !important;
                    background: #fef2f2 !important;
                }

                .c2p-ro[readonly],
                .c2p-ro[disabled] {
                    background: var(--c2p-gray-50) !important;
                    color: var(--c2p-gray-500) !important;
                    cursor: not-allowed !important;
                    opacity: 0.7;
                }

                .c2p-media {
                    display: flex;
                    align-items: flex-start;
                    gap: 20px;
                }

                .c2p-media img {
                    width: 120px;
                    height: 120px;
                    object-fit: cover;
                    border-radius: var(--c2p-radius);
                    border: 1px solid var(--c2p-gray-200);
                    box-shadow: var(--c2p-shadow);
                }

                .c2p-media > div {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }

                #c2p_store_shipping .inside {
                    padding: 32px !important;
                }

                #c2p_shipping_methods_wrap {
                    padding: 20px;
                    background: var(--c2p-gray-50);
                    border: 1px solid var(--c2p-gray-200);
                    border-radius: var(--c2p-radius-sm);
                }

                #c2p_shipping_methods_wrap.is-locked {
                    opacity: 0.5;
                    pointer-events: none;
                }

                #c2p_shipping_methods_wrap ul {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }

                #c2p_shipping_methods_wrap ul li {
                    padding: 12px 16px;
                    background: var(--c2p-white);
                    border: 1px solid var(--c2p-gray-200);
                    border-radius: var(--c2p-radius-sm);
                    transition: all 0.15s ease;
                }

                #c2p_shipping_methods_wrap ul li:hover {
                    background: var(--c2p-gray-50);
                    border-color: var(--c2p-gray-300);
                }

                #c2p_shipping_methods_wrap label {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                    color: var(--c2p-gray-900);
                }

                #c2p_shipping_methods_wrap input[type="checkbox"] {
                    width: 20px;
                    height: 20px;
                    margin: 0;
                    cursor: pointer;
                    accent-color: var(--c2p-blue);
                }

                .c2p-location-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 4px 10px;
                    font-size: 12px;
                    font-weight: 600;
                    border-radius: 6px;
                }

                .c2p-location-badge.type-loja {
                    background: #fce7f3;
                    color: #be185d;
                }

                .c2p-location-badge.type-cd {
                    background: #dbeafe;
                    color: #1e40af;
                }

                .c2p-status-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    padding: 4px 10px;
                    font-size: 12px;
                    font-weight: 600;
                    border-radius: 6px;
                }

                .c2p-status-badge.open {
                    background: #d1fae5;
                    color: #065f46;
                }

                .c2p-status-badge.closed {
                    background: #fee2e2;
                    color: #991b1b;
                }

                .c2p-col-info {
                    font-size: 13px;
                    line-height: 1.6;
                    color: var(--c2p-gray-700);
                }

                .c2p-col-info strong {
                    color: var(--c2p-gray-900);
                    font-weight: 600;
                }

                @media (max-width: 1400px) {
                    .c2p-days-grid {
                        grid-template-columns: repeat(3, 1fr);
                    }
                }

                @media (max-width: 1200px) {
                    .c2p-days-grid {
                        grid-template-columns: repeat(2, 1fr);
                    }
                    
                    .c2p-special-head,
                    .c2p-special-item {
                        grid-template-columns: 1fr;
                        gap: 12px;
                    }
                    
                    .c2p-day-fields label {
                        min-width: 100px;
                    }
                }

                @media (max-width: 900px) {
                    .c2p-days-grid {
                        grid-template-columns: 1fr;
                    }
                }

                @media (max-width: 782px) {
                    .c2p-main-table th,
                    .c2p-main-table td {
                        display: block;
                        width: 100% !important;
                        padding: 12px 20px !important;
                    }
                    
                    .c2p-hours-block {
                        padding: 20px;
                    }
                }
            </style>
            <?php
        });

        // ✅ INLINE JS COM VALIDAÇÕES
        add_action('admin_footer', function() use ($i18n, $countries, $states_by_country) {
            ?>
            <script>
            jQuery(function($){
                var I18N = <?php echo wp_json_encode($i18n); ?>;
                var COUNTRIES = <?php echo wp_json_encode($countries); ?>;
                var STATES = <?php echo wp_json_encode($states_by_country); ?>;

                // ====== Media selector ======
                $(document).on('click', '.c2p-media-select', function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: I18N.media_title,
                        button: {text: I18N.media_button},
                        multiple: false
                    });
                    var wrap = $(this).closest('.c2p-media');
                    frame.on('select', function() {
                        var att = frame.state().get('selection').first().toJSON();
                        wrap.find('input[type=hidden]').val(att.id);
                        wrap.find('img').attr('src', att.url).show();
                    });
                    frame.open();
                });

                $(document).on('click', '.c2p-media-remove', function(e) {
                    e.preventDefault();
                    var wrap = $(this).closest('.c2p-media');
                    wrap.find('input[type=hidden]').val('');
                    wrap.find('img').hide();
                });

                // ====== Horário 00:00 com clamp 23:59 ======
                function clampTime(d) {
                    d = String(d || '').replace(/[^0-9]/g, '').slice(0, 4);
                    if (d.length < 3) return d;
                    var H = Math.min(parseInt(d.slice(0, 2) || '0', 10), 23);
                    var M = Math.min(parseInt(d.slice(2, 4) || '0', 10), 59);
                    return ('0' + H).slice(-2) + ':' + ('0' + M).slice(-2);
                }

                $(document).on('input', '.c2p-time', function() {
                    var d = $(this).val().replace(/[^0-9]/g, '').slice(0, 4);
                    $(this).val(d.length >= 3 ? clampTime(d) : d);
                    validateTimeRules($(this).closest('.c2p-day-card, .c2p-special-item'));
                });

                $(document).on('blur', '.c2p-time', function() {
                    var v = $(this).val();
                    var m = /^(\d{1,2}):?(\d{2})$/.exec(v);
                    if (m) $(this).val(clampTime(m[1] + m[2]));
                    validateTimeRules($(this).closest('.c2p-day-card, .c2p-special-item'));
                });

                $(document).on('change', '.c2p-day-fields input[type="number"]', function() {
                    validateTimeRules($(this).closest('.c2p-day-card'));
                });

                // ====== ✅ VALIDAÇÃO: cutoff + prep <= close ======
                function validateTimeRules($container) {
                    if (!$container.length) return;

                    var $close = $container.find('[name*="[close]"]');
                    var $cutoff = $container.find('[name*="[cutoff]"]');
                    var $prep = $container.find('[name*="[prep_min]"]');
                    var $errorMsg = $container.find('.c2p-error-msg');

                    if (!$close.length || !$cutoff.length || !$prep.length) return;

                    var close = $close.val();
                    var cutoff = $cutoff.val();
                    var prep = parseInt($prep.val(), 10) || 0;

                    if (!close || !cutoff || prep <= 0) {
                        $container.removeClass('has-error');
                        return;
                    }

                    var closeMinutes = timeToMinutes(close);
                    var cutoffMinutes = timeToMinutes(cutoff);

                    if (closeMinutes === null || cutoffMinutes === null) return;

                    var deadline = cutoffMinutes + prep;

                    if (deadline > closeMinutes) {
                        $container.addClass('has-error');
                        if (!$errorMsg.length) {
                            $container.find('.c2p-day-fields').after('<div class="c2p-error-msg">⚠️ ' + I18N.timeValidationError + '</div>');
                        }
                    } else {
                        $container.removeClass('has-error');
                    }
                }

                function timeToMinutes(time) {
                    var parts = time.match(/^(\d{2}):(\d{2})$/);
                    if (!parts) return null;
                    return parseInt(parts[1], 10) * 60 + parseInt(parts[2], 10);
                }

                // ====== Telefone BR ======
                $(document).on('input', '.c2p-phone', function() {
                    var d = $(this).val().replace(/\D/g, '').substring(0, 11);
                    var p;
                    if (d.length <= 10) {
                        p = d.replace(/^(\d{0,2})(\d{0,4})(\d{0,4}).*/, function(m, a, b, c) {
                            var s = '';
                            if (a) s += '(' + a;
                            if (a.length === 2) s += ')';
                            if (b) {
                                s += b;
                                if (b.length === 4) s += '-';
                            }
                            if (c) s += c;
                            return s;
                        });
                    } else {
                        p = d.replace(/^(\d{0,2})(\d{0,5})(\d{0,4}).*/, function(m, a, b, c) {
                            var s = '';
                            if (a) s += '(' + a;
                            if (a.length === 2) s += ')';
                            if (b) {
                                s += b;
                                if (b.length === 5) s += '-';
                            }
                            if (c) s += c;
                            return s;
                        });
                    }
                    $(this).val(p);
                });

                $(document).on('blur', '.c2p-phone', function() {
                    var v = $(this).val().replace(/\s+/g, '');
                    var ok = /^\(\d{2}\)\d{4,5}\-\d{4}$/.test(v);
                    if (v !== '' && !ok) {
                        $(this).addClass('c2p-invalid');
                    } else {
                        $(this).removeClass('c2p-invalid');
                    }
                });

                // ====== ✅ E-mail OBRIGATÓRIO ======
                function isEmail(v) {
                    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
                }

                $(document).on('blur', '.c2p-email', function() {
                    var v = $(this).val().trim();
                    if (v === '') {
                        $(this).addClass('c2p-invalid');
                        $(this).next('.description').remove();
                        $(this).after('<p class="description" style="color:#dc2626;font-weight:600;">' + I18N.emailRequired + '</p>');
                    } else if (!isEmail(v)) {
                        $(this).addClass('c2p-invalid');
                        $(this).next('.description').remove();
                        $(this).after('<p class="description" style="color:#dc2626;font-weight:600;">' + I18N.invalidEmail + '</p>');
                    } else {
                        $(this).removeClass('c2p-invalid');
                        $(this).next('.description').remove();
                    }
                });

                // ====== ✅ VALIDAÇÃO NO SUBMIT ======
                $('form#post').on('submit', function(e) {
                    var $email = $('input[name="c2p_email"]');
                    var emailVal = $email.val().trim();

                    if (emailVal === '' || !isEmail(emailVal)) {
                        e.preventDefault();
                        alert(I18N.emailRequired);
                        $email.focus();
                        $email.addClass('c2p-invalid');
                        return false;
                    }

                    var hasError = $('.c2p-day-card.has-error, .c2p-special-item.has-error').length > 0;
                    if (hasError) {
                        e.preventDefault();
                        alert('Corrija os erros de horário antes de salvar.');
                        $('html, body').animate({scrollTop: $('.has-error:first').offset().top - 100}, 300);
                        return false;
                    }
                });

                // ====== Replicar horários ======
                $(document).on('click', '#c2p-copy-mon', function(e) {
                    e.preventDefault();
                    var open = $('input[name="c2p_hours_weekly[mon][open]"]').val();
                    var close = $('input[name="c2p_hours_weekly[mon][close]"]').val();
                    var cutoff = $('input[name="c2p_hours_weekly[mon][cutoff]"]').val();
                    var prep = $('input[name="c2p_hours_weekly[mon][prep_min]"]').val();
                    var closed = $('input[name="c2p_hours_weekly[mon][closed]"]').is(':checked');
                    
                    ['tue', 'wed', 'thu', 'fri', 'sat', 'sun'].forEach(function(d) {
                        $('input[name="c2p_hours_weekly[' + d + '][open]"]').val(open);
                        $('input[name="c2p_hours_weekly[' + d + '][close]"]').val(close);
                        $('input[name="c2p_hours_weekly[' + d + '][cutoff]"]').val(cutoff);
                        $('input[name="c2p_hours_weekly[' + d + '][prep_min]"]').val(prep);
                        $('input[name="c2p_hours_weekly[' + d + '][closed]"]').prop('checked', closed);
                        validateTimeRules($('.c2p-day-card').eq(['tue', 'wed', 'thu', 'fri', 'sat', 'sun'].indexOf(d) + 1));
                    });
                });

                // ====== Dias Especiais ======
                function nextIndex() {
                    var max = -1;
                    $('#c2p-special-days .c2p-special-item').each(function() {
                        var idx = parseInt($(this).attr('data-index') || '-1', 10);
                        if (!isNaN(idx) && idx > max) max = idx;
                    });
                    return max + 1;
                }

                $(document).on('click', '#c2p-add-special', function(e) {
                    e.preventDefault();
                    var i = nextIndex();
                    var html =
                        '<div class="c2p-special-item" data-index="' + i + '" data-readonly="0">' +
                        '<input type="text" name="c2p_hours_special[' + i + '][date_br]" value="" class="c2p-date" placeholder="dd/mm/aaaa" /> ' +
                        '<input type="text" name="c2p_hours_special[' + i + '][open]" value="" class="c2p-time" placeholder="00:00" /> ' +
                        '<input type="text" name="c2p_hours_special[' + i + '][close]" value="" class="c2p-time" placeholder="00:00" /> ' +
                        '<input type="text" name="c2p_hours_special[' + i + '][cutoff]" value="" class="c2p-time" placeholder="00:00" /> ' +
                        '<input type="number" min="0" step="1" name="c2p_hours_special[' + i + '][prep_min]" value="" class="small-text" placeholder="0" /> ' +
                        '<input type="text" name="c2p_hours_special[' + i + '][desc]" value="" class="regular-text" placeholder="Descrição" /> ' +
                        '<label><input type="checkbox" name="c2p_hours_special[' + i + '][annual]" value="1" /> Anual</label> ' +
                        '<button type="button" class="button c2p-remove-special">Remover</button>' +
                        '<div class="c2p-error-msg"></div>' +
                        '</div>';
                    $('#c2p-special-days').append(html);
                    if ($.fn.datepicker) {
                        $('#c2p-special-days .c2p-special-item:last .c2p-date').datepicker({dateFormat: 'dd/mm/yy'});
                    }
                });

                $(document).on('click', '.c2p-edit-special', function(e) {
                    e.preventDefault();
                    var wrap = $(this).closest('.c2p-special-item');
                    wrap.attr('data-readonly', '0');
                    wrap.find('input').prop('readonly', false).prop('disabled', false).removeClass('c2p-ro');
                    if ($.fn.datepicker) {
                        var $date = wrap.find('.c2p-date');
                        try {
                            $date.datepicker('destroy');
                        } catch (err) {}
                        $date.datepicker({dateFormat: 'dd/mm/yy'});
                    }
                    $(this).remove();
                });

                $(document).on('click', '.c2p-remove-special', function(e) {
                    e.preventDefault();
                    $(this).closest('.c2p-special-item').remove();
                });

                // ====== País/Estado dinâmico ======
                $(document).on('change', '#c2p_country', function() {
                    var country = $(this).val() || '';
                    var $wrap = $('#c2p_state_wrap').empty();
                    var states = STATES[country] || null;
                    
                    if (states && Object.keys(states).length) {
                        var $sel = $('<select name="c2p_state" id="c2p_state" class="c2p-state"></select>');
                        $sel.append('<option value="">Selecione o estado</option>');
                        Object.keys(states).forEach(function(code) {
                            $sel.append('<option value="' + code + '">' + states[code] + '</option>');
                        });
                        $wrap.append($sel);
                    } else {
                        $wrap.append('<input type="text" name="c2p_state" id="c2p_state" class="regular-text" placeholder="Estado" />');
                    }
                });

                // ====== Validação inicial ======
                $('.c2p-day-card').each(function() {
                    validateTimeRules($(this));
                });
            });
            </script>
            <?php
        });
    }

    /* ================================================================
     * METABOXES
     * ================================================================ */

    public function metaboxes(): void {
        add_meta_box('c2p_store_main', __('Dados do Local de Estoque', 'c2p'), [$this, 'box_main'], C2P::POST_TYPE_STORE, 'normal', 'high');
        add_meta_box('c2p_store_hours', '⏰ ' . __('Horários', 'c2p'), [$this, 'box_hours'], C2P::POST_TYPE_STORE, 'normal', 'default');
        add_meta_box('c2p_store_special', '⚠️ ' . __('Dias Especiais', 'c2p'), [$this, 'box_special'], C2P::POST_TYPE_STORE, 'normal', 'default');
    }

    public function box_main(\WP_Post $post): void {
        $m = $this->get_meta_all($post->ID);
        wp_nonce_field('c2p_store_save', 'c2p_store_nonce');

        $c2p_type = get_post_meta($post->ID, 'c2p_type', true);
        if ($c2p_type !== 'cd' && $c2p_type !== 'loja') {
            $c2p_type = 'loja';
        }

        echo '<table class="form-table c2p-main-table">';

        // Tipo do Local
        echo '<tr><th>' . esc_html__('Tipo do Local', 'c2p') . '</th><td>';
        wp_nonce_field('c2p_store_type_nonce', 'c2p_store_type_nonce');
        echo '<p style="margin:0 0 6px;">';
        echo '<label><input type="radio" name="c2p_type" value="loja" ' . checked($c2p_type, 'loja', false) . '/> 🏪 ' . esc_html__('Loja (retirada)', 'c2p') . '</label> &nbsp;&nbsp;&nbsp; ';
        echo '<label><input type="radio" name="c2p_type" value="cd" ' . checked($c2p_type, 'cd', false) . '/> 📦 ' . esc_html__('Centro de Distribuição (envio)', 'c2p') . '</label>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Define como o local será exibido no checkout.', 'c2p') . '</p>';
        echo '</td></tr>';

        // Endereço
        $countries = (function_exists('wc') && isset(wc()->countries)) ? wc()->countries->get_countries() : [];
        $states = (function_exists('wc') && isset(wc()->countries)) ? wc()->countries->get_states() : [];

        echo '<tr><th>' . esc_html__('Endereço', 'c2p') . '</th><td>';
        echo '<p><input type="text" name="c2p_address_1" value="' . esc_attr($m['address_1']) . '" class="regular-text" placeholder="' . esc_attr__('Endereço linha 1', 'c2p') . '" /></p>';
        echo '<p><input type="text" name="c2p_address_2" value="' . esc_attr($m['address_2']) . '" class="regular-text" placeholder="' . esc_attr__('Endereço linha 2', 'c2p') . '" /></p>';
        echo '<p><input type="text" name="c2p_city" value="' . esc_attr($m['city']) . '" class="regular-text" placeholder="' . esc_attr__('Cidade', 'c2p') . '" /></p>';

        $sel_country = get_post_meta($post->ID, 'c2p_country', true) ?: '';
        $sel_state = get_post_meta($post->ID, 'c2p_state', true) ?: '';
        
        echo '<p><label style="display:block;margin-bottom:4px;">' . esc_html__('País / estado', 'c2p') . '</label>';
        echo '<select name="c2p_country" id="c2p_country" class="c2p-country">';
        echo '<option value="">' . esc_html__('Selecione o país', 'c2p') . '</option>';
        foreach ((array)$countries as $code => $label) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($sel_country, $code, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        
        $has_states = !empty($sel_country) && !empty($states[$sel_country]);
        echo '<span id="c2p_state_wrap">';
        if ($has_states) {
            echo '<select name="c2p_state" id="c2p_state" class="c2p-state">';
            echo '<option value="">' . esc_html__('Selecione o estado', 'c2p') . '</option>';
            foreach ((array)$states[$sel_country] as $scode => $slabel) {
                echo '<option value="' . esc_attr($scode) . '" ' . selected($sel_state, $scode, false) . '>' . esc_html($slabel) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="text" name="c2p_state" id="c2p_state" class="regular-text" value="' . esc_attr($sel_state) . '" placeholder="' . esc_attr__('Estado', 'c2p') . '" />';
        }
        echo '</span></p>';

        echo '<p><input type="text" name="c2p_postcode" value="' . esc_attr($m['postcode']) . '" class="regular-text" placeholder="' . esc_attr__('CEP', 'c2p') . '" /></p>';
        echo '</td></tr>';

        // Contato
        echo '<tr><th>' . esc_html__('Contato', 'c2p') . '</th><td>';
        echo '<p><label style="font-weight:600;margin-bottom:4px;display:block;">📞 ' . esc_html__('Telefone', 'c2p') . '</label>';
        echo '<input type="text" name="c2p_phone" value="' . esc_attr($m['phone']) . '" class="regular-text c2p-phone" placeholder="(xx)xxxxx-xxxx" /></p>';
        
        // ✅ EMAIL OBRIGATÓRIO
        echo '<p><label style="font-weight:600;margin-bottom:4px;display:block;">📧 ' . esc_html__('E-mail', 'c2p') . ' <span style="color:#dc2626;">*</span></label>';
        echo '<input type="email" name="c2p_email" value="' . esc_attr($m['email']) . '" class="regular-text c2p-email" placeholder="email@dominio.com" required />';
        echo '<p class="description">' . esc_html__('Obrigatório: usado para notificações de pedidos.', 'c2p') . '</p>';
        echo '</p>';
        echo '</td></tr>';

        // Foto
        echo '<tr><th>' . esc_html__('Foto', 'c2p') . '</th><td>';
        $img = $m['photo_id'] ? wp_get_attachment_image($m['photo_id'], [120, 120]) : '<img src="" style="display:none;width:120px;height:120px;" />';
        echo '<div class="c2p-media">' . $img;
        echo '<div><input type="hidden" name="c2p_photo_id" value="' . esc_attr($m['photo_id']) . '" />';
        echo '<button type="button" class="button c2p-media-select">📸 ' . esc_html__('Selecionar imagem', 'c2p') . '</button><br><br>';
        echo '<button type="button" class="button c2p-media-remove">🗑️ ' . esc_html__('Remover', 'c2p') . '</button></div>';
        echo '</div></td></tr>';

        echo '</table>';
    }

    public function box_hours(\WP_Post $post): void {
        $m = $this->get_meta_all($post->ID);
        $days = [
            'mon' => __('Segunda', 'c2p'),
            'tue' => __('Terça', 'c2p'),
            'wed' => __('Quarta', 'c2p'),
            'thu' => __('Quinta', 'c2p'),
            'fri' => __('Sexta', 'c2p'),
            'sat' => __('Sábado', 'c2p'),
            'sun' => __('Domingo', 'c2p')
        ];

        echo '<div class="c2p-hours-block">';
        echo '<div class="c2p-days-grid">';
        
        foreach ($days as $k => $label) {
            $h = $m['hours_weekly'][$k] ?? ['open' => '', 'close' => '', 'cutoff' => '', 'prep_min' => 0, 'open_enabled' => true];
            $is_closed = empty($h['open_enabled']);

            echo '<div class="c2p-day-card">';
            echo '<div class="c2p-day-title">' . esc_html($label) . '</div>';
            
            echo '<div class="c2p-day-fields">';
            
            echo '<div>';
            echo '<label>Abre:</label>';
            echo '<input type="text" name="c2p_hours_weekly[' . $k . '][open]" value="' . esc_attr($h['open']) . '" class="c2p-time" placeholder="00:00" />';
            echo '</div>';
            
            echo '<div>';
            echo '<label>Fecha:</label>';
            echo '<input type="text" name="c2p_hours_weekly[' . $k . '][close]" value="' . esc_attr($h['close']) . '" class="c2p-time" placeholder="00:00" />';
            echo '</div>';

            echo '<div>';
            echo '<label>Horário limite:</label>';
            echo '<input type="text" name="c2p_hours_weekly[' . $k . '][cutoff]" value="' . esc_attr($h['cutoff']) . '" class="c2p-time" placeholder="00:00" />';
            echo '</div>';

            echo '<div>';
            echo '<label>Tempo de separação (min):</label>';
            echo '<input type="number" min="0" step="1" name="c2p_hours_weekly[' . $k . '][prep_min]" value="' . esc_attr($h['prep_min']) . '" class="small-text" style="width:80px;" />';
            echo '</div>';
            
            echo '</div>';

            echo '<label class="c2p-switch"><input type="checkbox" name="c2p_hours_weekly[' . $k . '][closed]" value="1" ' . ($is_closed ? 'checked' : '') . ' /> ';
            echo '<span>🔒 ' . esc_html__('Fechado', 'c2p') . '</span></label>';

            // ✅ MENSAGEM DE ERRO (inicialmente oculta)
            echo '<div class="c2p-error-msg">⚠️ ' . esc_html__('ERRO: Horário limite + tempo de preparo ultrapassa o horário de fechamento.', 'c2p') . '</div>';

            echo '</div>';
        }
        
        echo '</div>';
        echo '<div style="margin-top:20px;text-align:center;"><button type="button" class="button" id="c2p-copy-mon">🔄 ' . esc_html__('Replicar Segunda para Todos os Dias', 'c2p') . '</button></div>';
        echo '</div>';
    }

    public function box_special(\WP_Post $post): void {
        $m = $this->get_meta_all($post->ID);
        $specials = is_array($m['hours_special']) ? array_values($m['hours_special']) : [];

        echo '<div class="c2p-hours-block">';
        
        echo '<div class="c2p-special-head">';
        echo '<div>Data</div>';
        echo '<div>Abre</div>';
        echo '<div>Fecha</div>';
        echo '<div>Limite</div>';
        echo '<div>Separação (min)</div>';
        echo '<div>Descrição</div>';
        echo '<div>Anual</div>';
        echo '<div>Ações</div>';
        echo '</div>';

        echo '<div id="c2p-special-days">';
        foreach ($specials as $i => $sp) {
            echo '<div class="c2p-special-item" data-index="' . (int)$i . '" data-readonly="1">';
            echo '<input type="text" name="c2p_hours_special[' . $i . '][date_br]" value="' . esc_attr($sp['date_br'] ?? '') . '" class="c2p-date c2p-ro" placeholder="dd/mm/aaaa" readonly />';
            echo '<input type="text" name="c2p_hours_special[' . $i . '][open]" value="' . esc_attr($sp['open'] ?? '') . '" class="c2p-time c2p-ro" placeholder="00:00" readonly />';
            echo '<input type="text" name="c2p_hours_special[' . $i . '][close]" value="' . esc_attr($sp['close'] ?? '') . '" class="c2p-time c2p-ro" placeholder="00:00" readonly />';
            echo '<input type="text" name="c2p_hours_special[' . $i . '][cutoff]" value="' . esc_attr($sp['cutoff'] ?? '') . '" class="c2p-time c2p-ro" placeholder="00:00" readonly />';
            echo '<input type="number" min="0" step="1" name="c2p_hours_special[' . $i . '][prep_min]" value="' . esc_attr($sp['prep_min'] ?? 0) . '" class="small-text c2p-ro" readonly />';
            echo '<input type="text" name="c2p_hours_special[' . $i . '][desc]" value="' . esc_attr($sp['desc'] ?? '') . '" class="regular-text c2p-ro" readonly />';
            echo '<label class="c2p-ro"><input type="checkbox" name="c2p_hours_special[' . $i . '][annual]" value="1" ' . (!empty($sp['annual']) ? 'checked' : '') . ' disabled /> ' . esc_html__('Sim', 'c2p') . '</label>';
            echo '<span>';
            echo '<button type="button" class="button c2p-edit-special">✏️ ' . esc_html__('Editar', 'c2p') . '</button> ';
            echo '<button type="button" class="button c2p-remove-special">🗑️ ' . esc_html__('Remover', 'c2p') . '</button>';
            echo '</span>';
            echo '<div class="c2p-error-msg"></div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<p style="text-align:center;margin-top:20px;"><button type="button" class="button" id="c2p-add-special">➕ ' . esc_html__('Adicionar Dia Especial', 'c2p') . '</button></p>';
        
        // ✅ BOTÃO FIXO DE SALVAR
        echo '<div class="c2p-sticky-save">';
        echo '<button type="submit" class="button button-primary button-large">💾 ' . esc_html__('Salvar Alterações', 'c2p') . '</button>';
        echo '</div>';
        
        echo '</div>';
    }

    /* ================================================================
     * SALVAR
     * ================================================================ */

    public function save_meta(int $post_id): void {
        if (!isset($_POST['c2p_store_nonce']) || !wp_verify_nonce($_POST['c2p_store_nonce'], 'c2p_store_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('manage_woocommerce')) return;

        // Tipo
        if (isset($_POST['c2p_store_type_nonce']) && wp_verify_nonce($_POST['c2p_store_type_nonce'], 'c2p_store_type_nonce')) {
            $val = isset($_POST['c2p_type']) ? sanitize_text_field($_POST['c2p_type']) : '';
            if ($val === 'cd' || $val === 'loja') {
                update_post_meta($post_id, 'c2p_type', $val);
                update_post_meta($post_id, 'c2p_store_type', $val === 'cd' ? 'dc' : 'store');
                update_post_meta($post_id, 'c2p_is_cd', $val === 'cd' ? '1' : '0');
            }
        }

        // Endereço
        update_post_meta($post_id, 'c2p_address_1', sanitize_text_field($_POST['c2p_address_1'] ?? ''));
        update_post_meta($post_id, 'c2p_address_2', sanitize_text_field($_POST['c2p_address_2'] ?? ''));
        update_post_meta($post_id, 'c2p_city', sanitize_text_field($_POST['c2p_city'] ?? ''));
        update_post_meta($post_id, 'c2p_country', sanitize_text_field($_POST['c2p_country'] ?? ''));
        update_post_meta($post_id, 'c2p_state', sanitize_text_field($_POST['c2p_state'] ?? ''));
        update_post_meta($post_id, 'c2p_postcode', sanitize_text_field($_POST['c2p_postcode'] ?? ''));

        // Telefone
        $phone = sanitize_text_field($_POST['c2p_phone'] ?? '');
        $phone = preg_replace('/\s+/', '', $phone);
        $valid_phone = (bool)preg_match('/^\(\d{2}\)\d{4,5}\-\d{4}$/', $phone);
        if ($phone !== '' && !$valid_phone) {
            set_transient('c2p_phone_invalid_' . $post_id, __('Telefone inválido. Use (xx)xxxxx-xxxx ou (xx)xxxx-xxxx.', 'c2p'), 30);
        } else {
            update_post_meta($post_id, 'c2p_phone', $phone);
        }

        // ✅ E-MAIL OBRIGATÓRIO
        $email = sanitize_email($_POST['c2p_email'] ?? '');
        if (empty($email) || !is_email($email)) {
            set_transient('c2p_email_invalid_' . $post_id, __('E-mail é obrigatório e deve ser válido.', 'c2p'), 30);
            wp_die(__('E-mail é obrigatório. Volte e preencha o campo.', 'c2p'));
        } else {
            update_post_meta($post_id, 'c2p_email', $email);
        }

        // Mídia
        update_post_meta($post_id, 'c2p_photo_id', (int)($_POST['c2p_photo_id'] ?? 0));

        // ✅ SEMANA - COM VALIDAÇÃO
        $weekly = $_POST['c2p_hours_weekly'] ?? [];
        $validation_errors = [];
        
        foreach ($weekly as $k => $d) {
            foreach (['open', 'close', 'cutoff'] as $f) {
                if (!empty($d[$f])) {
                    $val = preg_replace('/[^0-9]/', '', $d[$f]);
                    if (strlen($val) >= 3) {
                        $d[$f] = substr($val, 0, 2) . ':' . substr($val, 2, 2);
                    }
                    $d[$f] = $this->clamp_time($d[$f]);
                } else {
                    $d[$f] = '';
                }
            }
            $is_closed = !empty($d['closed']);
            $prep_min = isset($d['prep_min']) ? max(0, intval($d['prep_min'])) : 0;
            
            // ✅ VALIDAÇÃO: cutoff + prep <= close
            if (!$is_closed && !empty($d['close']) && !empty($d['cutoff']) && $prep_min > 0) {
                $close_minutes = $this->time_to_minutes($d['close']);
                $cutoff_minutes = $this->time_to_minutes($d['cutoff']);
                
                if ($close_minutes !== null && $cutoff_minutes !== null) {
                    $deadline = $cutoff_minutes + $prep_min;
                    
                    if ($deadline > $close_minutes) {
                        $validation_errors[] = sprintf(
                            __('Erro em %s: Horário limite (%s) + tempo de preparo (%d min) ultrapassa o fechamento (%s)', 'c2p'),
                            $k,
                            $d['cutoff'],
                            $prep_min,
                            $d['close']
                        );
                    }
                }
            }
            
            $weekly[$k] = [
                'open' => $d['open'] ?? '',
                'close' => $d['close'] ?? '',
                'cutoff' => $d['cutoff'] ?? '',
                'prep_min' => $prep_min,
                'open_enabled' => !$is_closed,
            ];
        }
        
        if (!empty($validation_errors)) {
            set_transient('c2p_time_validation_errors_' . $post_id, $validation_errors, 60);
        }
        
        update_post_meta($post_id, 'c2p_hours_weekly', $weekly);

        // ✅ DIAS ESPECIAIS - COM VALIDAÇÃO
        $specials = $_POST['c2p_hours_special'] ?? [];
        foreach ($specials as $idx => &$sp) {
            if (!empty($sp['date_br'])) {
                $parts = explode('/', $sp['date_br']);
                if (count($parts) === 3) {
                    $sp['date_sql'] = sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
                }
            }
            foreach (['open', 'close', 'cutoff'] as $f) {
                if (!empty($sp[$f])) {
                    $val = preg_replace('/[^0-9]/', '', $sp[$f]);
                    if (strlen($val) >= 3) {
                        $sp[$f] = substr($val, 0, 2) . ':' . substr($val, 2, 2);
                    }
                    $sp[$f] = $this->clamp_time($sp[$f]);
                } else {
                    $sp[$f] = '';
                }
            }
            $sp['prep_min'] = isset($sp['prep_min']) ? max(0, intval($sp['prep_min'])) : 0;
            $sp['desc'] = sanitize_text_field($sp['desc'] ?? '');
            $sp['annual'] = !empty($sp['annual']);
            
            // ✅ VALIDAÇÃO
            if (!empty($sp['close']) && !empty($sp['cutoff']) && $sp['prep_min'] > 0) {
                $close_minutes = $this->time_to_minutes($sp['close']);
                $cutoff_minutes = $this->time_to_minutes($sp['cutoff']);
                
                if ($close_minutes !== null && $cutoff_minutes !== null) {
                    $deadline = $cutoff_minutes + $sp['prep_min'];
                    
                    if ($deadline > $close_minutes) {
                        $validation_errors[] = sprintf(
                            __('Erro no dia especial %s: Horário limite (%s) + tempo de preparo (%d min) ultrapassa o fechamento (%s)', 'c2p'),
                            $sp['date_br'] ?? $idx,
                            $sp['cutoff'],
                            $sp['prep_min'],
                            $sp['close']
                        );
                    }
                }
            }
        }
        unset($sp);
        
        update_post_meta($post_id, 'c2p_hours_special', $specials);
        
        if (class_exists('\C2P\Init_Scan')) \C2P\Init_Scan::clear_cache();
    }

    /* ================================================================
     * HELPERS
     * ================================================================ */

    private function get_meta_all(int $post_id): array {
        $c2p_type = get_post_meta($post_id, 'c2p_type', true);
        $type_norm = ($c2p_type === 'cd') ? 'dc' : 'store';

        return [
            'type' => $type_norm,
            'address_1' => get_post_meta($post_id, 'c2p_address_1', true),
            'address_2' => get_post_meta($post_id, 'c2p_address_2', true),
            'city' => get_post_meta($post_id, 'c2p_city', true),
            'country' => get_post_meta($post_id, 'c2p_country', true) ?: (function_exists('wc_get_base_location') ? (wc_get_base_location()['country'] ?? '') : ''),
            'state' => get_post_meta($post_id, 'c2p_state', true) ?: (function_exists('wc_get_base_location') ? (wc_get_base_location()['state'] ?? '') : ''),
            'postcode' => get_post_meta($post_id, 'c2p_postcode', true),
            'phone' => get_post_meta($post_id, 'c2p_phone', true),
            'email' => get_post_meta($post_id, 'c2p_email', true),
            'photo_id' => (int)get_post_meta($post_id, 'c2p_photo_id', true),
            'hours_weekly' => get_post_meta($post_id, 'c2p_hours_weekly', true),
            'hours_special' => get_post_meta($post_id, 'c2p_hours_special', true),
        ];
    }

    private function clamp_time(string $hhmm): string {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m)) return '';
        $H = min(max((int)$m[1], 0), 23);
        $M = min(max($m[2], 0), 59);
        return sprintf('%02d:%02d', $H, $M);
    }

    // ✅ NOVO: Converte HH:MM para minutos
    private function time_to_minutes(string $time): ?int {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) return null;
        return (int)$m[1] * 60 + (int)$m[2];
    }

    public function maybe_field_notices(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== C2P::POST_TYPE_STORE) return;

        $post_id = 0;
        if (isset($_GET['post'])) $post_id = (int)$_GET['post'];
        elseif (isset($_POST['post_ID'])) $post_id = (int)$_POST['post_ID'];
        if (!$post_id) return;

        $email_invalid = get_transient('c2p_email_invalid_' . $post_id);
        if ($email_invalid) {
            delete_transient('c2p_email_invalid_' . $post_id);
            echo '<div class="notice notice-error"><p>' . esc_html($email_invalid) . '</p></div>';
        }
        
        $phone_invalid = get_transient('c2p_phone_invalid_' . $post_id);
        if ($phone_invalid) {
            delete_transient('c2p_phone_invalid_' . $post_id);
            echo '<div class="notice notice-error"><p>' . esc_html($phone_invalid) . '</p></div>';
        }
        
        // ✅ NOVO: Erros de validação de horário
        $time_errors = get_transient('c2p_time_validation_errors_' . $post_id);
        if ($time_errors && is_array($time_errors)) {
            delete_transient('c2p_time_validation_errors_' . $post_id);
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Erros de validação de horário:', 'c2p') . '</strong></p><ul>';
            foreach ($time_errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    /* ================================================================
     * COLUNAS ADMIN (EXPANDIDAS)
     * ================================================================ */

    public function columns(array $columns): array {
        $new = [];
        if (isset($columns['cb'])) $new['cb'] = $columns['cb'];
        $new['title'] = __('Local de Estoque', 'c2p');
        $new['c2p_type'] = __('Tipo', 'c2p');
        $new['c2p_hours'] = __('Horário', 'c2p');
        $new['c2p_prep'] = __('Separação', 'c2p');
        $new['c2p_status'] = __('Status', 'c2p');
        $new['c2p_photo'] = __('Foto', 'c2p');
        return $new;
    }

    public function columns_content(string $column, int $post_id): void {
        if ($column === 'c2p_photo') {
            $photo_id = (int)get_post_meta($post_id, 'c2p_photo_id', true);
            if ($photo_id) {
                echo wp_get_attachment_image($photo_id, [80, 80], false, ['style' => 'width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--c2p-gray-200);box-shadow:var(--c2p-shadow-sm);']);
            } else {
                echo '<span style="display:inline-block;width:80px;height:80px;border-radius:8px;background:var(--c2p-gray-100);"></span>';
            }
        }
        
        if ($column === 'c2p_type') {
            $t = get_post_meta($post_id, 'c2p_type', true);
            if ($t === 'cd') {
                echo '<span class="c2p-location-badge type-cd">📦 CD</span>';
            } else {
                echo '<span class="c2p-location-badge type-loja">🏪 Loja</span>';
            }
        }
        
        if ($column === 'c2p_hours') {
            $m = $this->get_meta_all($post_id);
            $weekly = $m['hours_weekly'] ?: [];
            
            $mon = $weekly['mon'] ?? ['open' => '', 'close' => '', 'cutoff' => '', 'open_enabled' => false];
            
            if (!empty($mon['open_enabled']) && $mon['open'] && $mon['close']) {
                echo '<div class="c2p-col-info">';
                echo '<div><strong>Abre:</strong> ' . esc_html($mon['open']) . '</div>';
                echo '<div><strong>Fecha:</strong> ' . esc_html($mon['close']) . '</div>';
                if ($mon['cutoff']) {
                    echo '<div><strong>Limite:</strong> ' . esc_html($mon['cutoff']) . '</div>';
                }
                echo '</div>';
            } else {
                echo '<span style="color:var(--c2p-gray-500);font-style:italic;">Fechado</span>';
            }
        }
        
        if ($column === 'c2p_prep') {
            $m = $this->get_meta_all($post_id);
            $weekly = $m['hours_weekly'] ?: [];
            $mon = $weekly['mon'] ?? ['prep_min' => 0];
            
            $prep = (int)($mon['prep_min'] ?? 0);
            
            if ($prep > 0) {
                echo '<div class="c2p-col-info">';
                echo '<strong>' . esc_html($prep) . '</strong> min';
                echo '</div>';
            } else {
                echo '<span style="color:var(--c2p-gray-400);">—</span>';
            }
        }
        
        if ($column === 'c2p_status') {
            $m = $this->get_meta_all($post_id);
            $weekly = $m['hours_weekly'] ?: [];
            
            $now = new \DateTime('now', wp_timezone());
            $dow_num = (int)$now->format('w');
            $dow_map = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];
            $today_key = $dow_map[$dow_num] ?? 'mon';
            
            $today = $weekly[$today_key] ?? ['open' => '', 'close' => '', 'open_enabled' => false];
            
            $is_open = false;
            if (!empty($today['open_enabled']) && $today['open'] && $today['close']) {
                $current_time = $now->format('H:i');
                if ($current_time >= $today['open'] && $current_time < $today['close']) {
                    $is_open = true;
                }
            }
            
            if ($is_open) {
                echo '<span class="c2p-status-badge open">✅ Aberto</span>';
            } else {
                echo '<span class="c2p-status-badge closed">🔒 Fechado</span>';
            }
        }
    }

    /* ================================================================
     * ROW ACTIONS
     * ================================================================ */

    public function row_actions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== C2P::POST_TYPE_STORE) return $actions;
        
        foreach ($actions as $key => $html) {
            if (strpos($key, 'inline') !== false) unset($actions[$key]);
        }
        
        if (current_user_can('edit_post', $post->ID)) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=c2p_duplicate_store&post=' . $post->ID), 'c2p_duplicate_store_' . $post->ID);
            $actions['c2p_duplicate'] = '<a href="' . esc_url($url) . '">📋 ' . esc_html__('Duplicar', 'c2p') . '</a>';
        }
        
        return $actions;
    }

    public function duplicate_store(): void {
        if (!isset($_GET['post'])) wp_die(esc_html__('Local inválido.', 'c2p'));
        $post_id = (int)$_GET['post'];
        
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'c2p_duplicate_store_' . $post_id)) {
            wp_die(esc_html__('Ação não autorizada.', 'c2p'));
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Permissão insuficiente.', 'c2p'));
        }
        
        $post = get_post($post_id);
        if (!$post || $post->post_type !== C2P::POST_TYPE_STORE) {
            wp_die(esc_html__('Local inválido.', 'c2p'));
        }

        $new_post = [
            'post_type' => C2P::POST_TYPE_STORE,
            'post_status' => 'draft',
            'post_title' => $post->post_title . ' ' . __('(cópia)', 'c2p')
        ];
        
        $new_id = wp_insert_post($new_post, true);
        if (is_wp_error($new_id)) {
            wp_die(esc_html__('Erro ao duplicar o local.', 'c2p'));
        }

        $meta_keys = [
            'c2p_type', 'c2p_store_type', 'c2p_is_cd',
            'c2p_address_1', 'c2p_address_2', 'c2p_city', 'c2p_country', 'c2p_state', 'c2p_postcode',
            'c2p_phone', 'c2p_email',
            'c2p_photo_id', 'c2p_hours_weekly', 'c2p_hours_special', 'c2p_shipping_instance_ids'
        ];
        
        foreach ($meta_keys as $k) {
            $val = get_post_meta($post_id, $k, true);
            if ($val !== '' && $val !== null) {
                update_post_meta($new_id, $k, $val);
            }
        }
        
        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    /* ================================================================
     * MENU HIGHLIGHT
     * ================================================================ */

    public function menu_highlight_parent($parent_file) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type === C2P::POST_TYPE_STORE) {
            return 'c2p-dashboard';
        }
        return $parent_file;
    }

    public function menu_highlight_sub($submenu_file) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type === C2P::POST_TYPE_STORE) {
            return 'edit.php?post_type=' . C2P::POST_TYPE_STORE;
        }
        return $submenu_file;
    }

    /* ================================================================
     * LIFECYCLE
     * ================================================================ */

    public function on_transition_status(string $new_status, string $old_status, \WP_Post $post): void {
        try {
            if (!$post || $post->post_type !== C2P::POST_TYPE_STORE) return;
            if (class_exists('\C2P\Init_Scan')) \C2P\Init_Scan::clear_cache();

            
            if ($new_status === 'publish' && $old_status !== 'publish') {
                if (class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'run_async')) {
                    \C2P\Init_Scan::run_async(300);
                } elseif (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('c2p_init_full_scan', ['reason' => 'store_published'], 'c2p');
                }
            }
        } catch (\Throwable $e) {
            error_log('[C2P][CPT_Store][transition] ' . $e->getMessage());
        }
    }

    public function maybe_enqueue_scan_on_save(int $post_id, \WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post || $post->post_type !== C2P::POST_TYPE_STORE) return;
        if (in_array($post->post_status, ['trash', 'auto-draft'], true)) return;

        if (class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'run_async')) {
            \C2P\Init_Scan::run_async(300);
        } elseif (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('c2p_init_full_scan', ['reason' => 'store_saved'], 'c2p');
        }
    }

    public function on_before_delete_post(int $post_id): void {
        try {
            $post_id = (int)$post_id;
            if ($post_id <= 0) return;
            if (get_post_type($post_id) !== C2P::POST_TYPE_STORE) return;

            global $wpdb;

            $table = C2P::table();
            $col = C2P::col_store();

            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT product_id FROM {$table} WHERE {$col} = %d",
                $post_id
            ));

            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE {$col} = %d",
                $post_id
            ));

            if ($product_ids) {
                foreach ($product_ids as $pid) {
                    $this->rebuild_product_snapshots((int)$pid);
                }
            }
            if (class_exists('\C2P\Init_Scan')) \C2P\Init_Scan::clear_cache();

            if (class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'run_async')) {
                \C2P\Init_Scan::run_async(300);
            } elseif (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('c2p_init_full_scan', ['reason' => 'store_deleted'], 'c2p');
            }

        } catch (\Throwable $e) {
            error_log('[C2P][CPT_Store][delete] ' . $e->getMessage());
        }
    }

    private function rebuild_product_snapshots(int $product_id): void {
        if ($product_id <= 0) return;

        global $wpdb;
        $table = C2P::table();
        $col = C2P::col_store();

        $sum = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ms.qty),0)
               FROM {$table} ms
               JOIN {$wpdb->posts} p ON p.ID = ms.{$col}
                AND p.post_type = %s
                AND p.post_status = 'publish'
              WHERE ms.product_id = %d",
            C2P::POST_TYPE_STORE,
            $product_id
        ));

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product) {
            $product->set_stock_quantity($sum);
            if (!$product->backorders_allowed()) {
                $product->set_stock_status($sum > 0 ? 'instock' : 'outofstock');
            }
            $product->save();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ms.{$col} AS loc, ms.qty
               FROM {$table} ms
               JOIN {$wpdb->posts} p ON p.ID = ms.{$col}
                AND p.post_type = %s
                AND p.post_status = 'publish'
              WHERE ms.product_id = %d
              ORDER BY ms.{$col} ASC",
            C2P::POST_TYPE_STORE,
            $product_id
        ), ARRAY_A);

        $by_id = [];
        $by_name = [];
        
        if ($rows) {
            foreach ($rows as $r) {
                $loc_id = (int)$r['loc'];
                $qty = (int)$r['qty'];
                $by_id[$loc_id] = $qty;

                $title = get_the_title($loc_id);
                if (!$title) $title = 'Local #' . $loc_id;
                $by_name[$title] = $qty;
            }
        }

        update_post_meta($product_id, C2P::META_STOCK_BY_ID, $by_id);
        update_post_meta($product_id, C2P::META_STOCK_BY_NAME, $by_name);

        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($product_id);
        if (function_exists('wc_update_product_lookup_tables')) wc_update_product_lookup_tables($product_id);
        if (function_exists('clean_post_cache')) clean_post_cache($product_id);
    }
}

/* ================================================================
 * PARTE 2: Store_Shipping_Link (Vínculo com Métodos de Frete)
 * ================================================================ */

class Store_Shipping_Link {
    private static $instance;

    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', [$this, 'add_box']);
        add_action('save_post_c2p_store', [$this, 'save']);
        add_action('admin_footer-post.php', [$this, 'reposition_js']);
        add_action('admin_footer-post-new.php', [$this, 'reposition_js']);
        add_action('admin_notices', [$this, 'maybe_admin_notice']);
        add_filter('redirect_post_location', [$this, 'inject_notice_param'], 10, 2);
    }

    public function add_box(): void {
        add_meta_box(
            'c2p_store_shipping',
            '🚚 ' . __('Método de Frete Vinculado', 'c2p'),
            [$this, 'render'],
            C2P::POST_TYPE_STORE,
            'normal',
            'high'
        );
    }

    public function render(\WP_Post $post): void {
        wp_nonce_field('c2p_store_shipping', 'c2p_store_shipping_nonce');

        $has_orders = $this->store_has_orders($post->ID);
        $methods = self::get_all_shipping_instances();
        $current = (array)get_post_meta($post->ID, 'c2p_shipping_instance_ids', true);

        echo '<div style="padding:32px;">';
        echo '<p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:var(--c2p-gray-700);">' . esc_html__('Marque os métodos de frete que pertencem a este local.', 'c2p') . '</p>';

        if ($has_orders) {
            echo '<div class="notice notice-warning" style="margin:0 0 20px;padding:12px;border-left:4px solid var(--c2p-amber);">';
            echo '<p style="margin:0 0 12px;"><strong>⚠️ ' . esc_html__('Atenção:', 'c2p') . '</strong> ';
            echo esc_html__('Este local já possui pedidos. Para alterar, clique em "Destravar" e confirme.', 'c2p') . '</p>';
            echo '<p style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
            echo '<button type="button" class="button" id="c2p_unlock_shipping_link" style="background:var(--c2p-amber)!important;">🔓 ' . esc_html__('Destravar', 'c2p') . '</button>';
            echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="c2p_confirm_change_shipping_link" name="c2p_confirm_change_shipping_link" value="1" style="width:18px;height:18px;"> ';
            echo '<span>' . esc_html__('Confirmo que desejo alterar', 'c2p') . '</span></label>';
            echo '</p></div>';
        }

        $valid_ids = [];
        echo '<div id="c2p_shipping_methods_wrap" class="' . ($has_orders ? 'is-locked' : '') . '">';
        
        if (empty($methods)) {
            echo '<p style="color:var(--c2p-gray-500);margin:0;padding:20px;text-align:center;background:var(--c2p-gray-50);border-radius:var(--c2p-radius-sm);">❌ ' . esc_html__('Nenhum método de frete encontrado.', 'c2p') . '</p>';
        } else {
            echo '<ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px;">';
            foreach ($methods as $m) {
                $iid = (int)$m['instance_id'];
                $valid_ids[$iid] = true;
                $checked = in_array($iid, $current, true) ? ' checked="checked"' : '';
                $disabled = $has_orders ? ' disabled="disabled"' : '';
                $label = sprintf('%s — %s (ID %d)', $m['title'], $m['zone_name'], $iid);
                
                echo '<li>';
                echo '<label style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--c2p-white);border:1px solid var(--c2p-gray-200);border-radius:var(--c2p-radius-sm);cursor:pointer;transition:all 0.15s ease;">';
                echo '<input type="checkbox" class="c2p-ship-iid" name="c2p_shipping_instance_ids[]" value="' . esc_attr($iid) . '"' . $checked . $disabled . ' style="width:20px;height:20px;margin:0;cursor:pointer;accent-color:var(--c2p-blue);"> ';
                echo '<span style="font-weight:500;font-size:14px;color:var(--c2p-gray-900);">' . esc_html($label) . '</span>';
                echo '</label></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        echo '</div>';

        echo '<input type="hidden" name="c2p_shipping_valid_ids" value="' . esc_attr(wp_json_encode(array_keys($valid_ids))) . '">';
        echo '<input type="hidden" name="c2p_shipping_locked_snapshot" value="' . esc_attr(wp_json_encode(array_values($current))) . '">';

        ?>
        <script>
        (function(){
            var wrap = document.getElementById('c2p_shipping_methods_wrap');
            var btn = document.getElementById('c2p_unlock_shipping_link');
            var cb = document.getElementById('c2p_confirm_change_shipping_link');
            if (!wrap || !btn || !cb) return;
            
            function enableInputs(enable) {
                wrap.querySelectorAll('input.c2p-ship-iid').forEach(function(i) {
                    i.disabled = !enable;
                });
                wrap.classList.toggle('is-locked', !enable);
            }
            
            btn.addEventListener('click', function() {
                if (!cb.checked) {
                    alert('<?php echo esc_js(__('Marque a confirmação antes de destravar.', 'c2p')); ?>');
                    return;
                }
                enableInputs(true);
            });
        })();
        </script>
        <?php
    }

    public function save(int $post_id): void {
        if (!isset($_POST['c2p_store_shipping_nonce']) || !wp_verify_nonce($_POST['c2p_store_shipping_nonce'], 'c2p_store_shipping')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $has_orders = $this->store_has_orders($post_id);

        $valid = [];
        if (isset($_POST['c2p_shipping_valid_ids'])) {
            $decoded = json_decode(wp_unslash($_POST['c2p_shipping_valid_ids']), true);
            if (is_array($decoded)) {
                foreach ($decoded as $i) {
                    if (is_numeric($i)) $valid[(int)$i] = true;
                }
            }
        }
        
        if (empty($valid)) {
            foreach (self::get_all_shipping_instances() as $m) {
                $valid[(int)$m['instance_id']] = true;
            }
        }

        $submitted = isset($_POST['c2p_shipping_instance_ids']) && is_array($_POST['c2p_shipping_instance_ids'])
            ? array_values(array_unique(array_map('intval', $_POST['c2p_shipping_instance_ids'])))
            : [];

        if ($has_orders) {
            $snapshot = [];
            if (isset($_POST['c2p_shipping_locked_snapshot'])) {
                $snap = json_decode(wp_unslash($_POST['c2p_shipping_locked_snapshot']), true);
                if (is_array($snap)) $snapshot = array_values(array_unique(array_map('intval', $snap)));
            }
            
            $confirmed = !empty($_POST['c2p_confirm_change_shipping_link']);
            if (!$confirmed) {
                update_post_meta($post_id, 'c2p_shipping_instance_ids', $snapshot);
                $this->set_notice('error', __('Alteração NÃO aplicada: confirme e destrave.', 'c2p'));
                return;
            }
            
            $clean = [];
            foreach ($submitted as $iid) {
                if (isset($valid[$iid])) $clean[] = $iid;
            }
            update_post_meta($post_id, 'c2p_shipping_instance_ids', $clean);
            $this->set_notice('updated', __('Métodos atualizados com sucesso.', 'c2p'));
            return;
        }

        $clean = [];
        foreach ($submitted as $iid) {
            if (isset($valid[$iid])) $clean[] = $iid;
        }
        update_post_meta($post_id, 'c2p_shipping_instance_ids', $clean);
        $this->set_notice('updated', __('Métodos atualizados com sucesso.', 'c2p'));
    }

    public function reposition_js(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== C2P::POST_TYPE_STORE) return;
        ?>
        <script>
        (function(){
            function byTitle(pattern) {
                var h = Array.prototype.find.call(
                    document.querySelectorAll('#poststuff .postbox .hndle, #poststuff .postbox h2'),
                    function(el) { return pattern.test((el.textContent || '').trim()); }
                );
                return h ? h.closest('.postbox') : null;
            }
            
            function moveBox() {
                var ship = document.getElementById('c2p_store_shipping');
                if (!ship) return;
                
                var details = document.getElementById('c2p_store_main') || byTitle(/Dados do Local/i);
                var hours = document.getElementById('c2p_store_hours') || byTitle(/Horários|Hours/i);
                
                if (details && details.parentNode) {
                    details.parentNode.insertBefore(ship, hours ? hours : details.nextSibling);
                } else if (hours && hours.parentNode) {
                    hours.parentNode.insertBefore(ship, hours);
                }
                ship.style.display = '';
            }
            
            document.addEventListener('DOMContentLoaded', moveBox);
            window.addEventListener('load', moveBox);
        })();
        </script>
        <?php
    }

    private function set_notice(string $type, string $message): void {
        set_transient('c2p_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 30);
    }

    public function maybe_admin_notice(): void {
        $n = get_transient('c2p_notice_' . get_current_user_id());
        if (!$n || empty($n['message'])) return;
        delete_transient('c2p_notice_' . get_current_user_id());
        $class = $n['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . $class . '"><p>' . esc_html($n['message']) . '</p></div>';
    }

    public function inject_notice_param(string $location): string {
        if (get_transient('c2p_notice_' . get_current_user_id())) {
            $location = add_query_arg('c2p_notice', '1', $location);
        }
        return $location;
    }

    private function store_has_orders(int $store_id): bool {
        global $wpdb;
        
        $meta_keys = C2P::meta_location_keys();
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        
        $query = $wpdb->prepare(
            "SELECT COUNT(1)
               FROM {$wpdb->postmeta}
              WHERE meta_value = %d
                AND meta_key IN ($placeholders)",
            array_merge([$store_id], $meta_keys)
        );
        
        $count = (int)$wpdb->get_var($query);
        return $count > 0;
    }

    public static function get_all_shipping_instances(): array {
        if (!class_exists('\WC_Shipping_Zones')) return [];
        
        $out = [];
        $zones = \WC_Shipping_Zones::get_zones();
        $zones[0] = (new \WC_Shipping_Zone(0))->get_data();
        
        foreach ($zones as $z) {
            $zone = new \WC_Shipping_Zone($z['id'] ?? 0);
            foreach ($zone->get_shipping_methods(true) as $iid => $m) {
                if (method_exists($m, 'is_enabled') && !$m->is_enabled()) continue;
                
                $out[] = [
                    'instance_id' => (int)$iid,
                    'method_id' => $m->id,
                    'title' => $m->get_title(),
                    'zone_id' => (int)$zone->get_id(),
                    'zone_name' => $zone->get_zone_name(),
                ];
            }
        }
        
        return $out;
    }

    public static function get_store_shipping_instance_ids(int $store_id): array {
        $ids = (array)get_post_meta($store_id, 'c2p_shipping_instance_ids', true);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function get_instance_to_location_map(): array {
        static $cache = null;
        if (is_array($cache)) return $cache;

        $methods = [];
        foreach (self::get_all_shipping_instances() as $m) {
            $methods[(int)($m['instance_id'] ?? 0)] = (string)($m['method_id'] ?? '');
        }

        $q = new \WP_Query([
            'post_type' => C2P::POST_TYPE_STORE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'fields' => 'ids',
            'nopaging' => true,
        ]);

        $map = [];
        $pref = [];

        foreach ($q->posts as $store_id) {
            $store_id = (int)$store_id;
            $type = (string)get_post_meta($store_id, 'c2p_type', true);
            $iids = (array)self::get_store_shipping_instance_ids($store_id);

            foreach ($iids as $iid) {
                $iid = (int)$iid;
                if ($iid <= 0) continue;

                if (!isset($map[$iid])) {
                    $map[$iid] = $store_id;
                    $pref[$iid] = $type ?: '';
                    continue;
                }

                $method_id = $methods[$iid] ?? '';
                $cur_type = $pref[$iid] ?? '';

                if ($method_id === 'local_pickup') {
                    if ($type === 'loja' && $cur_type !== 'loja') {
                        $map[$iid] = $store_id;
                        $pref[$iid] = 'loja';
                    }
                } else {
                    if ($type === 'cd' && $cur_type !== 'cd') {
                        $map[$iid] = $store_id;
                        $pref[$iid] = 'cd';
                    }
                }
            }
        }

        $cache = $map;
        return $map;
    }

    public static function get_location_id_by_instance(int $instance_id): ?int {
        $map = self::get_instance_to_location_map();
        return isset($map[$instance_id]) ? (int)$map[$instance_id] : null;
    }

    public static function get_location_id_by_shipping_instance(int $instance_id): ?int {
        return self::get_location_id_by_instance($instance_id);
    }
}

// Bootstrap
Locations::instance();