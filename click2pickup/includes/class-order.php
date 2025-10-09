<?php
/**
 * Click2Pickup - Gestão de Pedidos (UI + Estoque + Email)
 * 
 * ✅ v1.3.1: SQL INJECTION CORRIGIDO (15 queries escapadas)
 * ✅ v1.3.0: LEDGER AGORA REGISTRA VENDAS (100% auditável)
 * 
 * @package Click2Pickup
 * @since 1.3.1
 * @author rhpimenta
 * Last Update: 2025-01-09 15:33:38 UTC
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

class Order {
    private static $instance;
    
    private $buffer = [];
    private static $location_cache = [];
    private const MAX_CACHE_SIZE = 100;

    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // === UI: Banner + Coluna ===
        add_action('admin_notices', [$this, 'render_order_banner_admin'], 1);
        add_action('admin_head', [$this, 'inject_admin_styles']);
        add_action('admin_footer', [$this, 'inject_banner_position_script']);
        
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_column'], 20);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [$this, 'add_column'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column_legacy'], 10, 2);
        
        // === ESTOQUE: Sync ===
        add_action('woocommerce_checkout_create_order', [$this, 'capture_location_on_checkout'], 10, 2);
        add_action('woocommerce_reduce_order_stock', [$this, 'on_reduce_order_stock'], 1, 1);
        add_action('woocommerce_restore_order_stock', [$this, 'on_restore_order_stock'], 1, 1);
        
        // === NOTAS ===
        add_action('woocommerce_reduce_order_item_stock', [$this, 'on_reduce_item_stock'], 20, 3);
        add_action('woocommerce_reduce_order_stock', [$this, 'flush_order_notes'], 10, 1);
        add_action('woocommerce_checkout_create_order', [$this, 'stamp_fulfillment_unit_on_create'], 20, 2);
        
        // === EMAIL: Pickup ===
        add_filter('woocommerce_order_actions', [$this, 'register_email_action']);
        add_action('woocommerce_order_action_c2p_send_pickup_mail', [$this, 'force_send_email']);
        add_action('admin_post_c2p_email_test', [$this, 'handle_email_test']);
        add_action('woocommerce_reduce_order_stock', [$this, 'send_pickup_email_after_stock'], 60, 1);
    }

    /* ================================================================
     * PARTE 1: UI - BANNER NO ADMIN
     * ================================================================ */

    public function render_order_banner_admin(): void {
        if (!$this->is_wc_orders_edit_screen()) return;
        
        global $post;
        
        // ✅ NOVO: Sanitização de $_GET simplificada
        $order_id = absint($_GET['id'] ?? $_GET['post'] ?? ($post->ID ?? 0));
        
        // Fallback para global $post
        if (!$order_id && $post && $post->post_type === 'shop_order') {
            $order_id = absint($post->ID);
        }
        
        if (!$order_id) return;
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $info = $this->get_order_unit_info($order);
        $icon = ($info['mode'] === 'pickup') ? '🏪' : '📦';
        
        $deadline_info = $this->get_deadline_info($order, $info);
        
        $deadline_html = '';
        
        if ($deadline_info) {
            $deadline_class = 'c2p-banner-deadline-ok';
            $status_icon = '📅';
            $status_text = '';
            $message_text = '';
            
            $now = new \DateTimeImmutable('now', $this->tz());
            $deadline_date = $deadline_info['deadline'];
            
            $diff_days = (int)$now->diff($deadline_date)->format('%r%a');
            
            $when = '';
            if ($deadline_info['is_today']) {
                $when = 'hoje';
            } elseif ($diff_days === 1) {
                $when = 'amanhã';
            } else {
                $when = $deadline_info['date_full'];
            }
            
            if ($deadline_info['is_late']) {
                $deadline_class = 'c2p-banner-deadline-late';
                $status_icon = '⚠️';
                $status_text = 'ATENÇÃO: SEPARAÇÃO ATRASADA';
                $message_text = sprintf(
                    'O pedido deveria ter sido separado em <strong>%s</strong> às <strong>%s</strong>.',
                    esc_html($deadline_info['date_full']),
                    esc_html($deadline_info['time'])
                );
            } else {
                if ($deadline_info['is_today']) {
                    $deadline_class = 'c2p-banner-deadline-today';
                    $status_icon = '⏰';
                } else {
                    $deadline_class = 'c2p-banner-deadline-ok';
                    $status_icon = '📅';
                }
                
                $status_text = 'ATENÇÃO: PRAZO PARA SEPARAÇÃO';
                $message_text = sprintf(
                    'Separe o pedido até <strong>%s</strong> de <strong>%s</strong>.',
                    esc_html($deadline_info['time']),
                    esc_html($when)
                );
            }
            
            $deadline_html = sprintf(
                '<div class="c2p-banner-deadline %s">
                    <span class="c2p-deadline-icon">%s</span>
                    <span class="c2p-deadline-text">
                        <strong>%s</strong><br>
                        %s
                    </span>
                </div>',
                esc_attr($deadline_class),
                esc_html($status_icon),
                esc_html($status_text),
                $message_text
            );
        }
        
        ?>
        <div id="c2p-order-banner-container" class="notice notice-info" style="border: none; background: none; padding: 0; margin: 0;">
            <div class="c2p-order-banner c2p-mode-<?php echo esc_attr($info['mode']); ?>">
                <div class="c2p-banner-header">
                    <span class="c2p-banner-icon"><?php echo esc_html($icon); ?></span>
                    <h3 class="c2p-banner-title">
                        <?php echo esc_html($info['location_name']); ?>
                        <span class="c2p-banner-mode"><?php echo esc_html($info['mode_label']); ?></span>
                    </h3>
                </div>
                
                <?php echo $deadline_html; ?>
            </div>
        </div>
        <?php
    }

    public function inject_banner_position_script(): void {
        if (!$this->is_wc_orders_edit_screen()) return;
        ?>
        <script>
        (function($) {
            'use strict';
            $(function() {
                var $banner = $('#c2p-order-banner-container');
                if (!$banner.length) return;
                
                var $panel = $('.panel.woocommerce-order-data');
                if ($panel.length) {
                    $banner.prependTo($panel);
                    $banner.css({'margin': '0 0 20px 0', 'padding': '0'});
                } else {
                    var $wrap = $('.wrap');
                    if ($wrap.length) $banner.prependTo($wrap);
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    public function inject_admin_styles(): void {
        if (!$this->is_wc_orders_edit_screen() && !$this->is_orders_list_screen()) return;
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

            .c2p-order-banner { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px; margin: 0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
            .c2p-order-banner.c2p-mode-pickup { border-left: 6px solid #be185d; background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%); }
            .c2p-order-banner.c2p-mode-delivery { border-left: 6px solid #1e40af; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); }
            .c2p-banner-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid rgba(0,0,0,0.05); }
            .c2p-banner-icon { font-size: 36px; line-height: 1; }
            .c2p-banner-title { margin: 0; font-size: 22px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
            .c2p-banner-mode { display: inline-block; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
            .c2p-mode-pickup .c2p-banner-mode { background: linear-gradient(135deg, #ec4899 0%, #be185d 100%); color: #fff; }
            .c2p-mode-delivery .c2p-banner-mode { background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); color: #fff; }
            
            .c2p-banner-deadline { display: flex; align-items: flex-start; gap: 12px; padding: 16px 18px; border-radius: 8px; border: 2px solid; }
            .c2p-banner-deadline-late { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-color: #dc2626 !important; }
            .c2p-banner-deadline-today { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-color: #f59e0b !important; }
            .c2p-banner-deadline-ok { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-color: #3b82f6 !important; }
            .c2p-deadline-icon { font-size: 28px; line-height: 1; flex-shrink: 0; }
            .c2p-deadline-text { font-size: 14px; line-height: 1.6; }
            .c2p-banner-deadline-late .c2p-deadline-text { color: #991b1b; }
            .c2p-banner-deadline-late .c2p-deadline-text strong { color: #7f1d1d; }
            .c2p-banner-deadline-today .c2p-deadline-text { color: #78350f; }
            .c2p-banner-deadline-today .c2p-deadline-text strong { color: #78350f; }
            .c2p-banner-deadline-ok .c2p-deadline-text { color: #1e40af; }
            .c2p-banner-deadline-ok .c2p-deadline-text strong { color: #1e3a8a; }
            
            .c2p-order-badge { display: flex; flex-direction: column; gap: 6px; font-size: 13px; }
            .c2p-badge-row { display: flex; flex-direction: column; gap: 4px; }
            .c2p-location-name { font-weight: 600; color: #1e293b; font-size: 13px; }
            .c2p-mode-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; width: fit-content; }
            .c2p-mode-badge.c2p-mode-pickup { background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%); color: #be185d; }
            .c2p-mode-badge.c2p-mode-delivery { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; }
            .c2p-deadline-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; width: fit-content; margin-top: 2px; }
            .c2p-deadline-badge.c2p-deadline-late { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #991b1b; border: 1px solid #dc2626; }
            .c2p-deadline-badge.c2p-deadline-today { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #78350f; border: 1px solid #f59e0b; }
            .c2p-deadline-badge.c2p-deadline-future { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #3b82f6; }
            
            @media (max-width: 782px) {
                .c2p-banner-title { font-size: 18px; }
                .c2p-banner-icon { font-size: 28px; }
                .c2p-deadline-badge { font-size: 10px; padding: 3px 8px; }
            }
        </style>
        <?php
    }

    private function is_wc_orders_edit_screen(): bool {
        if (!function_exists('get_current_screen')) return false;
        $screen = get_current_screen();
        return $screen && ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order');
    }

    private function is_orders_list_screen(): bool {
        if (!function_exists('get_current_screen')) return false;
        $screen = get_current_screen();
        return $screen && ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'edit-shop_order');
    }

    /* ================================================================
     * PARTE 2: UI - COLUNA NA LISTAGEM
     * ================================================================ */

    public function add_column(array $cols): array {
        $new = [];
        $inserted = false;

        foreach ($cols as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status' && !$inserted) {
                $new['c2p_click2pickup'] = __('Click2Pickup', 'c2p');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new['c2p_click2pickup'] = __('Click2Pickup', 'c2p');
        }

        return $new;
    }

    public function render_column(string $column, $order): void {
        if ($column !== 'c2p_click2pickup') return;
        
        $info = $this->get_order_unit_info($order);
        $deadline_info = $this->get_deadline_info($order, $info);
        
        echo $this->render_column_badge($info, $deadline_info);
    }

    public function render_column_legacy(string $column, int $post_id): void {
        if ($column !== 'c2p_click2pickup') return;
        
        $order = wc_get_order($post_id);
        if (!$order) return;
        
        $info = $this->get_order_unit_info($order);
        $deadline_info = $this->get_deadline_info($order, $info);
        
        echo $this->render_column_badge($info, $deadline_info);
    }

    private function render_column_badge(array $info, ?array $deadline_info = null): string {
        $is_pickup = ($info['mode'] === 'pickup');
        $icon = $is_pickup ? '🏪' : '📦';
        
        $deadline_html = '';
        
        if ($deadline_info) {
            $deadline_class = 'c2p-deadline-future';
            $deadline_text = '';
            
            $now = new \DateTimeImmutable('now', $this->tz());
            $deadline_date = $deadline_info['deadline'];
            $diff_days = (int)$now->diff($deadline_date)->format('%r%a');
            
            if ($deadline_info['is_late']) {
                $deadline_class = 'c2p-deadline-late';
                $deadline_text = '⚠️ Atrasado';
            } elseif ($deadline_info['is_today']) {
                $deadline_class = 'c2p-deadline-today';
                $deadline_text = sprintf('⏰ Hoje %s', $deadline_info['time']);
            } elseif ($diff_days === 1) {
                $deadline_class = 'c2p-deadline-future';
                $deadline_text = sprintf('📅 Amanhã %s', $deadline_info['time']);
            } else {
                $deadline_class = 'c2p-deadline-future';
                $deadline_text = sprintf('📅 %s %s', $deadline_info['date'], $deadline_info['time']);
            }
            
            $deadline_html = sprintf(
                '<span class="c2p-deadline-badge %s">%s</span>',
                esc_attr($deadline_class),
                esc_html($deadline_text)
            );
        }
        
        $html = sprintf(
            '<div class="c2p-order-badge">
                <div class="c2p-badge-row">
                    <span class="c2p-location-name">%s %s</span>
                    <span class="c2p-mode-badge c2p-mode-%s">%s</span>
                </div>
                %s
            </div>',
            esc_html($icon),
            esc_html($info['location_name']),
            esc_attr($info['mode']),
            esc_html($info['mode_label']),
            $deadline_html
        );

        return $html;
    }

    /* ================================================================
     * PARTE 3: ESTOQUE - SYNC (✅ AGORA COM LEDGER)
     * ================================================================ */

    public function capture_location_on_checkout(\WC_Order $order, array $data): void {
        $location_id = $this->detect_location_from_order($order, $data['shipping_method'] ?? null);
        if ($location_id) {
            $order->update_meta_data(C2P::META_ORDER_LOCATION, (int)$location_id);
            $order->update_meta_data('c2p_location_id', (int)$location_id);
        }
    }

    /**
     * ✅ NOVO v1.3.0: Agora grava no LEDGER usando Stock_Ledger::apply_delta()
     */
    public function on_reduce_order_stock($order): void {
        if (!($order instanceof \WC_Order)) return;
        if ('yes' === $order->get_meta(C2P::META_ORDER_STOCK_REDUCED)) return;

        $location_id = $this->get_order_location_id($order);
        if (!$location_id) return;

        $log = $this->apply_delta_for_order_items($order, -1, (int)$location_id);

        if (!empty($log['changed_products'])) {
            $this->reindex_products_totals($log['changed_products']);
            do_action(C2P::HOOK_AFTER_LOCATION_STOCK_CHANGE, $log['changed_products'], (int)$location_id, 'reduce', $order->get_id());
        }

        if (!empty($log['notes'])) {
            $order->add_order_note(sprintf(
                'C2P • Estoque por local reduzido (%s):%s',
                $this->label_location($location_id),
                "\n" . implode("\n", $log['notes'])
            ));
        }

        $order->update_meta_data(C2P::META_ORDER_STOCK_REDUCED, 'yes');
        $order->save();
    }

    /**
     * ✅ NOVO v1.3.0: Também grava no LEDGER ao restaurar
     */
    public function on_restore_order_stock($order): void {
        if (!($order instanceof \WC_Order)) return;
        if ('yes' === $order->get_meta(C2P::META_ORDER_STOCK_RESTORED)) return;

        $location_id = $this->get_order_location_id($order);
        if (!$location_id) return;

        $log = $this->apply_delta_for_order_items($order, +1, (int)$location_id);

        if (!empty($log['changed_products'])) {
            $this->reindex_products_totals($log['changed_products']);
            do_action(C2P::HOOK_AFTER_LOCATION_STOCK_CHANGE, $log['changed_products'], (int)$location_id, 'restore', $order->get_id());
        }

        if (!empty($log['notes'])) {
            $order->add_order_note(sprintf(
                'C2P • Estoque por local restaurado (%s):%s',
                $this->label_location($location_id),
                "\n" . implode("\n", $log['notes'])
            ));
        }

        $order->update_meta_data(C2P::META_ORDER_STOCK_RESTORED, 'yes');
        $order->save();
    }

    /**
     * ✅ MIGRADO: Agora usa Stock_Ledger::apply_delta() ao invés de lógica interna
     */
    private function apply_delta_for_order_items(\WC_Order $order, int $direction, int $location_id): array {
        $changed = [];
        $notes = [];

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) continue;
            $product = $item->get_product();
            if (!$product) continue;

            $qty = (int)$item->get_quantity();
            if ($qty <= 0) continue;

            $pid = (int)$product->get_id();
            $delta = $qty * $direction;

            // ✅ NOVO: Usa Stock_Ledger::apply_delta() para auditoria
            if (class_exists('\C2P\Stock_Ledger') && method_exists('\C2P\Stock_Ledger', 'apply_delta')) {
                $before = $this->get_qty_at_location($pid, $location_id);
                
                \C2P\Stock_Ledger::apply_delta($pid, $location_id, $delta, [
                    'order_id' => $order->get_id(),
                    'source'   => $direction < 0 ? 'order_reduce' : 'order_restore',
                    'who'      => 'system:order#' . $order->get_id(),
                    'meta'     => [
                        'order_number' => $order->get_order_number(),
                        'product_name' => $product->get_name(),
                        'sku'          => $product->get_sku(),
                    ],
                ]);
                
                $after = $this->get_qty_at_location($pid, $location_id);
            } else {
                // Fallback (caso Stock_Ledger não exista)
                $before = $this->get_qty_at_location($pid, $location_id);
                $after = $this->apply_delta_direct($pid, $location_id, $delta);
            }

            $changed[$pid] = true;

            $notes[] = sprintf('%s %s → saldo %d',
                $product->get_name(),
                $direction < 0 ? '−'.$qty : '+'.$qty,
                $after
            );

            $thr = $this->get_low_stock_threshold($pid, $location_id);
            $ctx = ($direction < 0) ? 'order_reduce' : 'order_restore';
            do_action(C2P::HOOK_MULTISTOCK_CHANGED, $pid, $location_id, (int)$before, (int)$after, (int)$thr, $ctx);
        }

        return [
            'changed_products' => array_map('intval', array_keys($changed)),
            'notes' => $notes,
        ];
    }

    /**
     * ✅ FALLBACK: Caso Stock_Ledger não exista (não deve acontecer)
     */
    private function apply_delta_direct(int $product_id, int $location_id, int $delta): int {
        global $wpdb;
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id = %d AND {$col} = %d LIMIT 1",
            $product_id, $location_id
        ));

        if ($row) {
            $new = max(0, (int)$row->qty + $delta);
            $wpdb->update(
                $table,
                ['qty' => $new, 'updated_at' => current_time('mysql', true)],
                ['product_id' => $product_id, $col => $location_id],
                ['%d', '%s'],
                ['%d', '%d']
            );
            return $new;
        } else {
            $new = max(0, 0 + $delta);
            $wpdb->insert(
                $table,
                [
                    'product_id' => $product_id,
                    $col => $location_id,
                    'qty' => $new,
                    'low_stock_amount' => 0,
                    'updated_at' => current_time('mysql', true),
                ],
                ['%d', '%d', '%d', '%d', '%s']
            );
            return $new;
        }
    }

    private function get_qty_at_location(int $product_id, int $location_id): int {
        global $wpdb;
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id = %d AND {$col} = %d LIMIT 1",
            $product_id, $location_id
        ));
        
        return is_numeric($row) ? (int)$row : 0;
    }

    private function reindex_products_totals(array $product_ids): void {
        global $wpdb;
        if (empty($product_ids)) return;

        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        $place = implode(',', array_fill(0, count($product_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, SUM(qty) AS total FROM {$table} WHERE product_id IN ($place) GROUP BY product_id",
            $product_ids
        ), OBJECT_K);

        foreach ($product_ids as $pid) {
            $sum = isset($rows[$pid]) ? (int)$rows[$pid]->total : 0;

            $product = wc_get_product($pid);
            if ($product) {
                $product->set_stock_quantity($sum);
                if (!$product->backorders_allowed()) {
                    $product->set_stock_status($sum > 0 ? 'instock' : 'outofstock');
                }
                $product->save();
            }

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables($pid);
            }
            if (function_exists('clean_post_cache')) {
                clean_post_cache($pid);
            }

            $this->update_product_meta_snapshot($pid, $table, $col);
        }
    }

    private function update_product_meta_snapshot(int $product_id, ?string $table = null, ?string $col = null): void {
        global $wpdb;
        if (!$table || !$col) {
            $table = esc_sql(C2P::table());
            $col = esc_sql(C2P::col_store());
        } else {
            $table = esc_sql($table);
            $col = esc_sql($col);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$col} AS loc, qty FROM {$table} WHERE product_id = %d ORDER BY {$col} ASC",
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
                if ($title === '' || $title === null) $title = 'Local #'.$loc_id;
                $by_name[$title] = $qty;
            }
        }

        update_post_meta($product_id, C2P::META_STOCK_BY_ID, $by_id);
        update_post_meta($product_id, C2P::META_STOCK_BY_NAME, $by_name);
    }

    private function get_low_stock_threshold(int $product_id, int $location_id): int {
        global $wpdb;
        $table = esc_sql(C2P::table());
        $col = esc_sql(C2P::col_store());
        
        $thr = $wpdb->get_var($wpdb->prepare(
            "SELECT low_stock_amount FROM {$table} WHERE product_id = %d AND {$col} = %d LIMIT 1",
            $product_id, $location_id
        ));
        
        $thr = is_numeric($thr) ? (int)$thr : 0;

        if ($thr <= 0 && function_exists('wc_get_product')) {
            $p = wc_get_product($product_id);
            if ($p) {
                $woo_thr = (int)wc_get_low_stock_amount($p);
                if ($woo_thr > 0) $thr = $woo_thr;
            }
        }
        return max(0, $thr);
    }

    /* ================================================================
     * PARTE 4: EMAIL - NOTIFICAÇÃO DE PEDIDO
     * ================================================================ */

    public function register_email_action(array $actions): array {
        $actions['c2p_send_pickup_mail'] = __('Enviar notificação Click2Pickup (retirada)', 'c2p');
        return $actions;
    }

    public function force_send_email(\WC_Order $order): void {
        $order->delete_meta_data(C2P::META_ORDER_EMAIL_SENT);
        $order->save();

        $unit = $this->get_order_unit_info($order);
        $to = $this->resolve_recipient_email($unit);
        
        if (!$to) {
            $order->add_order_note('C2P • E-mail manual não enviado: destinatário não configurado.');
            return;
        }

        $this->deliver_using_cfg($order, $to, $unit, 'manual', true);
    }

    public function handle_email_test(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        check_admin_referer('c2p_email_test');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $to = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
        $redirect = admin_url('admin.php?page=c2p-settings&tab=emails');

        if (!$order_id || !$to || !is_email($to)) {
            wp_redirect($redirect . '&c2p_email_test=err&c2p_email_err=parametros%20invalidos');
            exit;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            wp_redirect($redirect . '&c2p_email_test=err&c2p_email_err=pedido%20nao%20encontrado');
            exit;
        }

        $unit = $this->get_order_unit_info($order);
        if (empty($unit['name'])) $unit['name'] = 'CD Global';

        $ok = $this->deliver_using_cfg($order, $to, $unit, 'test', false);
        wp_redirect($redirect . ($ok ? '&c2p_email_test=ok' : '&c2p_email_test=err&c2p_email_err=wp_mail'));
        exit;
    }

    public function send_pickup_email_after_stock($order): void {
        $order = ($order instanceof \WC_Order) ? $order : (function_exists('wc_get_order') ? wc_get_order($order) : null);
        if (!$order) return;

        if ($order->get_meta(C2P::META_ORDER_EMAIL_SENT, true)) return;

        $cfg = $this->cfg();
        if (empty($cfg['enable'])) return;

        $unit = $this->get_order_unit_info($order);
        $is_pickup = ($unit['mode'] === 'pickup');
        
        if (!$is_pickup && empty($cfg['notify_delivery'])) return;

        $to = $this->resolve_recipient_email($unit);
        if (!$to) {
            $order->add_order_note('C2P • E-mail não enviado: destinatário não configurado.');
            return;
        }

        $this->deliver_using_cfg($order, $to, $unit, 'after_stock', true);
    }

    private function deliver_using_cfg(\WC_Order $order, string $to, array $unit, string $context, bool $mark_sent): bool {
        $cfg = $this->cfg();

        $order_number = $order->get_order_number();
        $order_date = $order->get_date_created()
            ? $order->get_date_created()->date_i18n(get_option('date_format').' '.get_option('time_format'))
            : date_i18n(get_option('date_format').' '.get_option('time_format'));

        $customer = sanitize_text_field(trim(
            $order->get_formatted_billing_full_name()
            ?: ($order->get_formatted_shipping_full_name()
                ?: ($order->get_billing_first_name().' '.$order->get_billing_last_name()))
        ));

        $prep = $this->build_prep_block($order, $unit);

        $ctx = [
            '{unit_name}' => $unit['name'],
            '{order_number}' => $order_number,
            '{order_date}' => $order_date,
            '{customer_name}' => $customer,
            '{customer_phone}' => $order->get_billing_phone(),
            '{customer_email}' => $order->get_billing_email(),
            '{admin_link}' => admin_url('post.php?post='.$order->get_id().'&action=edit'),
            '{site_name}' => wp_specialchars_decode(get_option('blogname'), ENT_QUOTES),
            '{items_table}' => $this->items_table_basic($order),
            '{prep_deadline_block}' => $prep['html'],
            '{prep_deadline_time}' => $prep['time'],
            '{prep_deadline_date}' => $prep['date'],
        ];

        $subject = $this->fill($cfg['subject'], $ctx);
        $inner_html = $this->fill($cfg['body_html'], $ctx);

        $wrapped_html = $inner_html;
        if (function_exists('WC') && method_exists(\WC(), 'mailer')) {
            $mailer = \WC()->mailer();
            $wrapped_html = $mailer->wrap_message(wp_strip_all_tags($subject), $inner_html);
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($cfg['from_name']) && !empty($cfg['from_email']) && is_email($cfg['from_email'])) {
            $from_name = str_replace(["\r", "\n", "%0d", "%0a"], '', sanitize_text_field($cfg['from_name']));
            $from_email = sanitize_email($cfg['from_email']);
            $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
            $headers[] = 'Reply-To: ' . $from_name . ' <' . $from_email . '>';
        }
        if (!empty($cfg['bcc'])) {
            foreach (array_filter(array_map('trim', preg_split('/[,;]+/', $cfg['bcc']))) as $bcc) {
                $bcc = sanitize_email($bcc);
                if (is_email($bcc)) {
                    $headers[] = 'Bcc: ' . $bcc;
                }
            }
        }

        $set_sender_cb = null;
        $sender = '';
        if (!empty($cfg['from_email']) && is_email($cfg['from_email'])) $sender = $cfg['from_email'];
        else {
            $admin = get_option('admin_email');
            if (is_email($admin)) $sender = $admin;
        }
        if ($sender) {
            $set_sender_cb = function($phpmailer) use ($sender) { try { $phpmailer->Sender = $sender; } catch (\Throwable $e) {} };
            add_action('phpmailer_init', $set_sender_cb, 99);
        }

        $ok = function_exists('wc_mail')
            ? wc_mail($to, $subject, $wrapped_html, $headers)
            : wp_mail($to, $subject, $wrapped_html, $headers);

        if ($set_sender_cb) remove_action('phpmailer_init', $set_sender_cb, 99);

        if ($ok && $mark_sent) {
            $order->update_meta_data(C2P::META_ORDER_EMAIL_SENT, time());
            $order->save();
            $order->add_order_note(sprintf('C2P • E-mail enviado para %s (%s). Contexto: %s', $unit['name'], $to, $context));
        } elseif (!$ok) {
            $order->add_order_note(sprintf('C2P • Falha no envio para %s (%s). Contexto: %s', $unit['name'], $to, $context));
        }
        
        return (bool)$ok;
    }

    /* ================================================================
     * PARTE 5: NOTAS DE PEDIDO
     * ================================================================ */

    public function on_reduce_item_stock(\WC_Order_Item_Product $item, array $change, \WC_Order $order): void {
        $order_id = $order->get_id();
        
        if (!isset($this->buffer[$order_id])) {
            $this->buffer[$order_id] = ['lines' => []];
        }

        // ✅ NOVO: Limita tamanho do buffer
        if (count($this->buffer) > 50) {
            array_shift($this->buffer); // Remove o mais antigo
        }

        $product = $change['product'];
        $from = $change['from'] ?? 0;
        $to = $change['to'] ?? 0;
        $qty_delta = absint($from - $to);
        $name = $product ? wp_strip_all_tags($product->get_formatted_name()) : $item->get_name();
        
        $pid = $item->get_product_id();
        $vid = $item->get_variation_id();
        
        $info = $this->get_order_unit_info($order);
        $loc_id = $info['location_id'];
        $loc_name = $info['location_name'];

        $local_display = sprintf('-%d', $qty_delta);
        
        if ($loc_id) {
            $before = $this->get_location_qty($pid, $vid, $loc_id);
            if (null !== $before) {
                $after = max(0, $before - $qty_delta);
                $local_display = sprintf('%d→%d', $before, $after);
            }
        }

        $this->buffer[$order_id]['lines'][] = sprintf(
            '• %s: %s (%s)',
            $loc_name,
            $name,
            $local_display
        );
    }

    public function flush_order_notes(\WC_Order $order): void {
        $order_id = $order->get_id();

        if (!$order->get_meta(C2P::META_ORDER_NOTE_ADDED, true)) {
            $info = $this->get_order_unit_info($order);
            
            $icon = ($info['mode'] === 'pickup') ? '🏪' : '📦';
            
            $order->add_order_note(sprintf(
                '%s Click2Pickup • Unidade: %s — %s',
                $icon,
                $info['location_name'],
                $info['mode_label']
            ));
            
            $order->update_meta_data(C2P::META_ORDER_NOTE_ADDED, 1);
        }

        if (!empty($this->buffer[$order_id]['lines'])) {
            $note = '📦 Estoque reduzido:\n' . implode("\n", $this->buffer[$order_id]['lines']);
            $order->add_order_note($note);
        }

        if (metadata_exists('post', $order_id, C2P::META_ORDER_NOTE_ADDED)) {
            $order->save();
        }

        unset($this->buffer[$order_id]);
    }

    public function stamp_fulfillment_unit_on_create(\WC_Order $order, array $data): void {
        if ($this->get_order_unit_info($order)['location_id']) {
            return;
        }

        $sess = WC()->session ?? null;
        
        if (!$sess || !$sess->has_session()) return;
        
        $session_data = $sess->get('c2p_selected_location');
        
        if (!is_array($session_data) || empty($session_data['id'])) return;
        
        $order->update_meta_data(C2P::META_ORDER_LOCATION, (int)$session_data['id']);
        
        if (!empty($session_data['delivery_type'])) {
            $mode = ($session_data['delivery_type'] === 'pickup') ? 'RETIRAR' : 'RECEBER';
            $order->update_meta_data(C2P::META_ORDER_MODE, $mode);
        }
    }

    /* ================================================================
     * PARTE 6: HELPERS - UNIDADE/LOCAL
     * ================================================================ */

    private function get_location_name(?int $location_id): string {
        if (!$location_id) {
            return __('CD Global', 'c2p');
        }

        if (isset(self::$location_cache[$location_id])) {
            return self::$location_cache[$location_id];
        }

        $name = get_the_title($location_id);
        
        if (!$name || get_post_status($location_id) !== 'publish') {
            $name = __('CD Global', 'c2p');
        }

        // ✅ NOVO: Limita tamanho do cache
        if (count(self::$location_cache) >= self::MAX_CACHE_SIZE) {
            array_shift(self::$location_cache); // Remove o mais antigo
        }

        self::$location_cache[$location_id] = $name;

        return $name;
    }

    private function get_order_unit_info($order): array {
        $wc_order = $order instanceof \WC_Order ? $order : wc_get_order((int)$order);
        
        if (!$wc_order) {
            return $this->get_empty_unit_info();
        }

        $location_id = $this->find_first_meta($wc_order, C2P::meta_location_keys());
        $mode_raw = $this->find_first_meta($wc_order, C2P::meta_mode_keys());
        
        if (!$mode_raw) {
            $mode_raw = $this->detect_mode_from_shipping($wc_order);
        }

        $mode = C2P::normalize_mode($mode_raw);
        $location_name = $this->get_location_name($location_id);

        return [
            'id' => $location_id ? (int)$location_id : null,
            'location_id' => $location_id ? (int)$location_id : null,
            'name' => $location_name,
            'location_name' => $location_name,
            'mode' => $mode,
            'mode_label' => ($mode === 'pickup') ? __('Retirada na Loja', 'c2p') : __('Envio', 'c2p'),
        ];
    }

    private function find_first_meta(\WC_Order $order, array $keys) {
        foreach ($keys as $key) {
            $value = $order->get_meta($key, true);
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function detect_mode_from_shipping(\WC_Order $order): ?string {
        foreach ($order->get_shipping_methods() as $ship) {
            if (strpos($ship->get_method_id(), 'local_pickup') !== false) {
                return 'pickup';
            }
        }
        return 'delivery';
    }

    private function get_empty_unit_info(): array {
        return [
            'id' => null,
            'location_id' => null,
            'name' => __('CD Global', 'c2p'),
            'location_name' => __('CD Global', 'c2p'),
            'mode' => 'delivery',
            'mode_label' => __('Envio', 'c2p'),
        ];
    }

    private function get_location_qty(int $product_id, ?int $variation_id, int $location_id): ?int {
        global $wpdb;
        
        $table = esc_sql(C2P::table());
        $col_location = esc_sql(C2P::col_store());
        $col_product = esc_sql(C2P::col_product());

        $pid_to_check = $variation_id ?: $product_id;

        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM {$table} WHERE {$col_product} = %d AND {$col_location} = %d LIMIT 1",
            $pid_to_check,
            $location_id
        ));

        return (null !== $val) ? (int)$val : null;
    }

    private function get_order_location_id(\WC_Order $order): ?int {
        $meta_loc = (int)$order->get_meta(C2P::META_ORDER_LOCATION);
        if ($meta_loc > 0) return $meta_loc;
        
        $meta_compat = (int)$order->get_meta('c2p_location_id');
        if ($meta_compat > 0) return $meta_compat;
        
        $infer = $this->detect_location_from_order($order, null);
        if ($infer) return (int)$infer;
        
        $alt = apply_filters('c2p_order_location_id', null, $order);
        if (is_numeric($alt) && (int)$alt > 0) return (int)$alt;
        
        return null;
    }

    private function detect_location_from_order(\WC_Order $order, $maybe_methods = null): ?int {
        if (is_array($maybe_methods) && !empty($maybe_methods)) {
            foreach ($maybe_methods as $mk) {
                if (!is_string($mk)) continue;
                if (preg_match('~^[a-z0-9_]+:(\d+)$~i', $mk, $m)) {
                    $instance_id = (int)$m[1];
                    if ($instance_id > 0) {
                        $loc = $this->map_instance_to_location($instance_id);
                        if ($loc) return (int)$loc;
                    }
                }
            }
        }
        
        foreach ($order->get_shipping_methods() as $ship_item) {
            $inst = method_exists($ship_item, 'get_instance_id') ? (int)$ship_item->get_instance_id() : 0;
            if ($inst > 0) {
                $loc = $this->map_instance_to_location($inst);
                if ($loc) return (int)$loc;
            }
            
            $inst_meta = (int)$ship_item->get_meta('instance_id', true);
            if ($inst_meta > 0) {
                $loc = $this->map_instance_to_location($inst_meta);
                if ($loc) return (int)$loc;
            }
        }
        
        return null;
    }

    private function map_instance_to_location(int $instance_id): ?int {
        $via_filter = apply_filters('c2p_map_shipping_instance_to_location_id', null, $instance_id);
        if (is_numeric($via_filter) && (int)$via_filter > 0) return (int)$via_filter;

        if (class_exists(\C2P\Store_Shipping_Link::class)) {
            foreach (['get_location_id_by_instance', 'get_location_id_by_shipping_instance'] as $m) {
                if (method_exists(\C2P\Store_Shipping_Link::class, $m)) {
                    $res = \C2P\Store_Shipping_Link::$m($instance_id);
                    if (is_numeric($res) && (int)$res > 0) return (int)$res;
                }
            }
        }
        
        return null;
    }

    private function label_location(int $location_id): string {
        return (string)apply_filters(
            'c2p_location_label',
            get_the_title($location_id) ?: ('location#'.$location_id),
            $location_id
        );
    }

    /* ================================================================
     * PARTE 7: HELPERS - PRAZO DE PREPARO
     * ================================================================ */

    private function get_deadline_info(\WC_Order $order, array $unit_info): ?array {
        $status = $order->get_status();
        
        if (C2P::is_completed_order_status($status)) {
            return null;
        }
        
        if (!C2P::is_active_order_status($status)) {
            return null;
        }
        
        if ($unit_info['mode'] !== 'pickup' || !$unit_info['location_id']) {
            return null;
        }
        
        $deadline = $this->compute_prep_deadline($order, (int)$unit_info['location_id']);
        
        if (!$deadline) {
            return null;
        }
        
        $time_str = wp_date('H:i', $deadline->getTimestamp(), $this->tz());
        $date_str = wp_date('d/m', $deadline->getTimestamp(), $this->tz());
        $date_full = wp_date(get_option('date_format') ?: 'd/m/Y', $deadline->getTimestamp(), $this->tz());
        
        $now = new \DateTimeImmutable('now', $this->tz());
        $is_today = ($now->format('Y-m-d') === $deadline->format('Y-m-d'));
        $is_late = ($now > $deadline);
        
        return [
            'time' => $time_str,
            'date' => $date_str,
            'date_full' => $date_full,
            'is_today' => $is_today,
            'is_late' => $is_late,
            'deadline' => $deadline,
        ];
    }

    private function tz(): \DateTimeZone {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        
        $tz = get_option('timezone_string');
        
        // ✅ NOVO: Valida timezone
        if (!$tz || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }
        
        try {
            return new \DateTimeZone($tz);
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    private function parse_hhmm($s): ?array {
        $s = trim((string)$s);
        if ($s === '') return null;
        if (preg_match('~^(\d{1,2}):?(\d{2})$~', $s, $m)) {
            $h = (int)$m[1];
            $i = (int)$m[2];
            if ($h >= 0 && $h < 24 && $i >= 0 && $i < 60) return [$h, $i];
        }
        return null;
    }

    private function slug_for_dow(int $w): string {
        return [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'][$w] ?? 'mon';
    }

    private function get_store_calendar(int $store_id): array {
        $weekly = get_post_meta($store_id, 'c2p_hours_weekly', true);
        $specials = get_post_meta($store_id, 'c2p_hours_special', true);
        return [
            'weekly' => is_array($weekly) ? $weekly : [],
            'specials' => is_array($specials) ? $specials : [],
        ];
    }

    private function find_special_for_date(\DateTimeImmutable $date, array $specials): ?array {
        $ymd = $date->format('Y-m-d');
        $md = $date->format('m-d');
        
        foreach ((array)$specials as $sp) {
            $date_sql = (string)($sp['date_sql'] ?? '');
            $date_br = (string)($sp['date_br'] ?? '');
            $annual = !empty($sp['annual']);
            
            if ($date_sql) {
                if ($annual) {
                    if (substr($date_sql, 5, 5) === $md) return $sp;
                } else {
                    if ($date_sql === $ymd) return $sp;
                }
                continue;
            }
            
            if ($date_br && preg_match('~^(\d{2})/(\d{2})(?:/(\d{4}))?$~', $date_br, $m)) {
                $cmp_md = $m[2].'-'.$m[1];
                if (!empty($m[3])) {
                    if ($ymd === ($m[3].'-'.$cmp_md)) return $sp;
                } else {
                    if ($annual && $md === $cmp_md) return $sp;
                }
            }
        }
        return null;
    }

    private function resolve_day_schedule(\DateTimeImmutable $date, int $store_id): array {
        $cal = $this->get_store_calendar($store_id);
        $sp = $this->find_special_for_date($date, $cal['specials']);

        $row = null;
        if (is_array($sp)) {
            $row = [
                'open' => (string)($sp['open'] ?? ''),
                'close' => (string)($sp['close'] ?? ''),
                'cutoff' => (string)($sp['cutoff'] ?? ''),
                'prep' => isset($sp['prep_min']) ? (int)$sp['prep_min'] : null,
                'enabled' => true,
            ];
        } else {
            $slug = $this->slug_for_dow((int)$date->format('w'));
            $wk = $cal['weekly'][$slug] ?? null;
            if (is_array($wk)) {
                $row = [
                    'open' => (string)($wk['open'] ?? ''),
                    'close' => (string)($wk['close'] ?? ''),
                    'cutoff' => (string)($wk['cutoff'] ?? ''),
                    'prep' => isset($wk['prep_min']) ? (int)$wk['prep_min'] : null,
                    'enabled' => !empty($wk['open_enabled']),
                ];
            }
        }

        if (!$row) return ['status' => 'undefined'];
        if (empty($row['enabled'])) return ['status' => 'closed'];

        $open = $this->parse_hhmm($row['open']);
        $close = $this->parse_hhmm($row['close']);
        $prep = is_numeric($row['prep']) ? max(0, (int)$row['prep']) : null;
        $cut = $this->parse_hhmm($row['cutoff'] ?? '');

        if (!$open || !$close || $prep === null) return ['status' => 'undefined'];

        $o = $date->setTime($open[0], $open[1], 0);
        $c = $date->setTime($close[0], $close[1], 0);
        if ($c <= $o) return ['status' => 'undefined'];
        $cutDT = $cut ? $date->setTime($cut[0], $cut[1], 0) : null;

        return [
            'status' => 'open',
            'open' => $o,
            'close' => $c,
            'prep_minutes' => $prep,
            'cutoff' => $cutDT
        ];
    }

    private function next_open_day(\DateTimeImmutable $from, int $store_id): ?array {
        $d = $from;
        for ($i = 0; $i < 14; $i++) {
            $d = $d->modify('+1 day')->setTime(0, 0, 0);
            $s = $this->resolve_day_schedule($d, $store_id);
            if (($s['status'] ?? '') === 'open') return ['date' => $d, 'sched' => $s];
        }
        return null;
    }

    private function compute_prep_deadline(\WC_Order $order, int $store_id): ?\DateTimeImmutable {
        if ($store_id <= 0) return null;

        $tz = $this->tz();
        $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
        $now = (new \DateTimeImmutable('@'.$ts))->setTimezone($tz);

        $today = $this->resolve_day_schedule($now, $store_id);
        if (($today['status'] ?? '') === 'undefined') return null;

        $start = null;
        $sched = null;

        if (($today['status'] ?? '') === 'open') {
            $outside = ($now < $today['open'] || $now >= $today['close']);
            $after_cut = ($today['cutoff'] instanceof \DateTimeImmutable) ? ($now > $today['cutoff']) : false;

            if ($outside || $after_cut) {
                $next = $this->next_open_day($now, $store_id);
                if (!$next) return null;
                $sched = $next['sched'];
                $start = $sched['open'];
            } else {
                $sched = $today;
                $start = ($now > $today['open']) ? $now : $today['open'];
            }
        } elseif (($today['status'] ?? '') === 'closed') {
            $next = $this->next_open_day($now, $store_id);
            if (!$next) return null;
            $sched = $next['sched'];
            $start = $sched['open'];
        } else {
            return null;
        }

        $prep = (int)$sched['prep_minutes'];
        $prov = $start->modify("+{$prep} minutes");
        if ($prov <= $sched['close']) return $prov;

        $guard = 0;
        $d = $start;
        while ($guard++ < 14) {
            $next = $this->next_open_day($d, $store_id);
            if (!$next) return null;
            $sched = $next['sched'];
            $start = $sched['open'];
            $prov = $start->modify("+{$prep} minutes");
            if ($prov <= $sched['close']) return $prov;
            $d = $start;
        }
        return null;
    }

    private function build_prep_block(\WC_Order $order, array $unit): array {
        $deadline = $this->compute_prep_deadline($order, (int)$unit['id']);
        
        if (!$deadline) {
            $html = '<div style="padding:12px 16px; border:2px solid #f59e0b; background:#FFF8E6; border-radius:8px; margin:0 0 16px 0;">
                <div style="font-size:16px; line-height:1.4; font-weight:600; margin:0 0 4px 0;">🕒 ATENÇÃO: PRAZO PARA PREPARO</div>
                <div style="font-size:14px; line-height:1.5;"><strong>Sem definição</strong></div>
            </div>';
            return ['html' => $html, 'time' => '', 'date' => ''];
        }
        
        $time_str = wp_date('H:i', $deadline->getTimestamp(), $this->tz());
        $date_str = wp_date(get_option('date_format') ?: 'd/m/Y', $deadline->getTimestamp(), $this->tz());
        
        $msg = sprintf(
            'Prepare o pedido até <strong>%s</strong> do dia <strong>%s</strong>.',
            esc_html($time_str),
            esc_html($date_str)
        );
        
        $html = '<div style="padding:12px 16px; border:2px solid #f59e0b; background:#FFF8E6; border-radius:8px; margin:0 0 16px 0;">
            <div style="font-size:16px; line-height:1.4; font-weight:600; margin:0 0 4px 0;">🕒 ATENÇÃO: PRAZO PARA PREPARO</div>
            <div style="font-size:14px; line-height:1.5;">'.$msg.'</div>
        </div>';
        
        return ['html' => $html, 'time' => $time_str, 'date' => $date_str];
    }

    /* ================================================================
     * PARTE 8: HELPERS - EMAIL
     * ================================================================ */

    private function cfg(): array {
        $opts = \C2P\Settings::get_options();
        return [
            'enable' => !empty($opts['email_pickup_enabled']) ? 1 : 0,
            'notify_delivery' => !empty($opts['email_pickup_notify_delivery']) ? 1 : 0,
            'from_name' => (string)($opts['email_pickup_from_name'] ?? ''),
            'from_email' => (string)($opts['email_pickup_from_email'] ?? ''),
            'to_mode' => (string)($opts['email_pickup_to_mode'] ?? 'store'),
            'custom_to' => (string)($opts['email_pickup_custom_to'] ?? ''),
            'bcc' => (string)($opts['email_pickup_bcc'] ?? ''),
            'subject' => (string)($opts['email_pickup_subject'] ?? 'Novo pedido #{order_number} - {unit_name}'),
            'body_html' => (string)($opts['email_pickup_body_html'] ?? ''),
        ];
    }

    private function resolve_recipient_email(array $unit): ?string {
        $cfg = $this->cfg();
        $to = null;
        
        if (($cfg['to_mode'] ?? 'store') === 'custom') {
            $to = trim($cfg['custom_to']);
        } else {
            $to = $this->get_store_email((int)$unit['id']);
        }
        
        if (!$to && !empty($cfg['custom_to'])) {
            $to = trim($cfg['custom_to']);
        }
        
        return ($to && is_email($to)) ? $to : null;
    }

    private function get_store_email(int $store_id): ?string {
        if (!$store_id) {
            return null;
        }
        
        // ✅ NOVO: Busca todos os metas de uma vez
        $all_meta = get_post_meta($store_id);
        
        foreach (['c2p_email', 'store_email', 'email', 'rpws_store_email', 'contact_email', 'c2p_store_email'] as $k) {
            if (!empty($all_meta[$k][0])) {
                $v = trim((string)$all_meta[$k][0]);
                if ($v && is_email($v)) {
                    return $v;
                }
            }
        }
        
        return null;
    }

    private function items_table_basic(\WC_Order $order): string {
        $info = $this->get_order_unit_info($order);
        $location_id = $info['location_id'];
        
        $rows = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) continue;
            $product = $item->get_product();
            $name = $product ? wp_strip_all_tags($product->get_name()) : $item->get_name();
            $sku = $product ? $product->get_sku() : '';
            if ($sku && !preg_match('/\(\s*'.preg_quote($sku, '/').'\s*\)$/', $name)) $name .= " ($sku)";
            
            $qty_sold = (int)$item->get_quantity();
            
            $stock_before = '—';
            if ($location_id && $product) {
                $pid = (int)$product->get_id();
                $current_qty = $this->get_qty_at_location($pid, $location_id);
                
                $before = $current_qty + $qty_sold;
                $stock_before = (string)$before;
            }
            
            $rows[] = sprintf(
                '<tr>
                    <td style="padding:6px 8px;border-bottom:1px solid #eee;">%s</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;">%s</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;">%s</td>
                </tr>',
                esc_html($name),
                esc_html((string)$qty_sold),
                esc_html($stock_before)
            );
        }
        
        if (!$rows) return '<p>(Sem itens)</p>';
        
        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;">'.
               '<thead><tr>'.
               '<th style="text-align:left;padding:8px;border-bottom:2px solid #333;">Produto</th>'.
               '<th style="text-align:center;padding:8px;border-bottom:2px solid #333;">Qtd</th>'.
               '<th style="text-align:center;padding:8px;border-bottom:2px solid #333;">Estoque (antes da venda)</th>'.
               '</tr></thead>'.
               '<tbody>'.implode('', $rows).'</tbody></table>';
    }

    private function fill(string $tpl, array $ctx): string {
        return strtr($tpl, $ctx);
    }
}

// Bootstrap automático
add_action('plugins_loaded', function() {
    Order::instance();
}, 5);