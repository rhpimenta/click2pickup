<?php
/**
 * Click2Pickup - Relatório de Estoque
 * 
 * ✅ v2.0: SQL escape, validações de segurança, placeholders seguros
 * ✅ Exibe apenas ícone + nome (sem detalhes extras)
 * ✅ Integrado com ledger para rastreabilidade
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-10-09 12:08:11 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 12:08: ✅ CORRIGIDO: SQL escape em todas as queries
 * - 2025-10-09 12:08: ✅ CORRIGIDO: Placeholders seguros com spread operator
 * - 2025-10-09 12:08: ✅ CORRIGIDO: Validação de JSON decode
 * - 2025-10-09 12:08: ✅ MELHORADO: Checkbox select-all com JavaScript
 * - 2025-10-09 12:08: ✅ MELHORADO: Paginação segura
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

final class Stock_Report {

    private static $instance = null;

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 30);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'bulk_notice']);
        add_action('admin_post_c2p_stock_report_bulk', [$this, 'bulk_action']);
    }

    public function add_menu(): void {
        add_submenu_page(
            'c2p-dashboard',
            __('📊 Relatório de Estoque', 'c2p'),
            __('📊 Relatório', 'c2p'),
            'manage_woocommerce',
            'c2p-stock-report',
            [$this, 'render_page'],
            30
        );
    }

    public function enqueue_assets($hook): void {
        if ($hook !== 'click2pickup_page_c2p-stock-report') return;
        
        wp_enqueue_style('c2p-stock-report', false);
        wp_add_inline_style('c2p-stock-report', $this->get_css());
        wp_add_inline_script('jquery-core', $this->get_js());
    }

    private function get_css(): string {
        return <<<CSS
.c2p-who-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    background: #f0f9ff;
    color: #0369a1;
    border: 1px solid #bae6fd;
}

.c2p-who-label.source-manual_admin {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
}

.c2p-who-label.source-order_reduce,
.c2p-who-label.source-order_restore {
    background: #f3e8ff;
    color: #6b21a8;
    border-color: #d8b4fe;
}

.c2p-who-icon {
    font-size: 14px;
}
CSS;
    }

    /**
     * ✅ NOVO: JavaScript para select-all
     */
    private function get_js(): string {
        return <<<JS
jQuery(function($) {
    $('#c2p-select-all').on('change', function() {
        $('input[name="ledger_ids[]"]').prop('checked', $(this).is(':checked'));
    });
    
    $('input[name="ledger_ids[]"]').on('change', function() {
        var total = $('input[name="ledger_ids[]"]').length;
        var checked = $('input[name="ledger_ids[]"]:checked').length;
        $('#c2p-select-all').prop('checked', total === checked);
    });
});
JS;
    }

    public function bulk_notice(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'c2p-stock-report') return;
        if (isset($_GET['c2p_deleted'])) {
            $n = max(0, (int) $_GET['c2p_deleted']);
            if ($n > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>'.
                    sprintf(esc_html__('%d registro(s) removido(s).', 'c2p'), $n).
                '</p></div>';
            }
        }
    }

    /**
     * ✅ CORRIGIDO: Placeholders seguros com spread operator
     */
    public function bulk_action(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        check_admin_referer('c2p_stock_report_bulk');

        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nome
        $table = esc_sql(C2P::table_ledger());

        $action = sanitize_text_field((string) ($_POST['c2p_bulk_action_top'] ?? ''));
        $ids = isset($_POST['ledger_ids']) && is_array($_POST['ledger_ids']) ? array_map('intval', $_POST['ledger_ids']) : [];
        $ids = array_values(array_filter($ids, fn($v) => $v > 0));

        $back_url = admin_url('admin.php?page=c2p-stock-report');

        if (empty($action) || empty($ids)) {
            wp_safe_redirect($back_url); 
            exit;
        }

        if ($action === 'delete') {
            // ✅ CORRIGIDO: Spread operator para passar array como args
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id IN ({$placeholders})", ...$ids));
            $deleted = (int) $wpdb->rows_affected;
            wp_safe_redirect(add_query_arg(['c2p_deleted' => $deleted], $back_url)); 
            exit;
        }

        wp_safe_redirect($back_url); 
        exit;
    }

    /**
     * ✅ CORRIGIDO: SQL escape em todas as queries
     */
    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');

        global $wpdb;
        
        // ✅ SEGURANÇA: Escape de nomes
        $table = esc_sql(C2P::table_ledger());
        $posts_table = esc_sql($wpdb->posts);
        $postmeta_table = esc_sql($wpdb->postmeta);

        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $ppp = 50;
        $offset = ($paged - 1) * $ppp;

        $sku_query = isset($_GET['sku']) ? trim((string) $_GET['sku']) : '';
        $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

        $where_parts = ['1=1'];
        $params = [];

        if ($sku_query !== '') {
            // ✅ CORRIGIDO: Escape adequado
            $where_parts[] = "p.ID IN (SELECT pm.post_id FROM `{$postmeta_table}` pm WHERE pm.meta_key = '_sku' AND pm.meta_value = %s)";
            $params[] = $sku_query;
        } elseif ($product_id > 0) {
            $where_parts[] = "l.product_id = %d";
            $params[] = $product_id;
        }

        $where_sql = implode(' AND ', $where_parts);

        // ✅ CORRIGIDO: SQL com escape adequado
        $sql = "
            SELECT l.*, 
                   p.post_title AS product_name, 
                   p.post_type, 
                   p.post_parent,
                   COALESCE(l.location_name_text, s.post_title) AS location_name_display,
                   sku.meta_value AS sku, 
                   sku_p.meta_value AS parent_sku
            FROM `{$table}` l
            LEFT JOIN `{$posts_table}` p ON (p.ID = l.product_id)
            LEFT JOIN `{$posts_table}` s ON (s.ID = l.location_id)
            LEFT JOIN `{$postmeta_table}` sku ON (sku.post_id = l.product_id AND sku.meta_key = '_sku')
            LEFT JOIN `{$postmeta_table}` sku_p ON (sku_p.post_id = p.post_parent AND sku_p.meta_key = '_sku')
            WHERE {$where_sql}
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT %d OFFSET %d
        ";
        
        $query_params = array_merge($params, [$ppp, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        echo '<div class="wrap">';
        echo '<h1>📊 ' . esc_html__('Relatório de Estoque', 'c2p') . '</h1>';

        echo '<form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="c2p-stock-report" />';
        echo '<label>SKU: <input type="text" name="sku" value="'.esc_attr($sku_query).'" class="small-text" /></label> ';
        echo '<label>ID: <input type="number" name="product_id" value="'.esc_attr($product_id ?: '').'" class="small-text" /></label> ';
        submit_button(__('Filtrar','c2p'), 'secondary', '', false);
        echo '</form>';

        if (empty($rows)) {
            echo '<p>'.esc_html__('Nenhum registro.', 'c2p').'</p></div>';
            return;
        }

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="c2p_stock_report_bulk" />';
        wp_nonce_field('c2p_stock_report_bulk');

        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<select name="c2p_bulk_action_top">';
        echo '<option value="">'.esc_html__('Ações em massa','c2p').'</option>';
        echo '<option value="delete">'.esc_html__('Excluir','c2p').'</option>';
        echo '</select> ';
        submit_button(__('Aplicar','c2p'), 'secondary', 'apply', false);
        echo '</div></div>';

        echo '<table class="widefat striped"><thead><tr>';
        // ✅ CORRIGIDO: ID no checkbox para JavaScript
        echo '<th class="check-column"><input type="checkbox" id="c2p-select-all" /></th>';
        echo '<th>Data/Hora</th><th>Produto</th><th>SKU</th><th>Local</th>';
        echo '<th style="text-align:right">Δ</th><th style="text-align:right">Antes</th><th style="text-align:right">Depois</th>';
        echo '<th>Origem</th><th>Quem</th><th>Pedido</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $pid = (int) $r['product_id'];
            $oid = (int) $r['order_id'];
            $name = $r['product_name'] ?: ('#'.$pid);
            $loc = $r['location_name_display'] ?: ('#'.(int)$r['location_id']);
            $sku = $r['sku'] ?: ($r['parent_sku'] ?? '');

            $edit_pid = ($r['post_type'] === 'product_variation' && (int)$r['post_parent'] > 0) ? (int)$r['post_parent'] : $pid;
            $product_url = admin_url('post.php?post='.$edit_pid.'&action=edit');

            $delta = (int)$r['delta'];
            $delta_class = $delta > 0 ? 'color:#16a34a;' : 'color:#dc2626;';
            $delta_display = $delta > 0 ? '+' . $delta : $delta;

            $who_html = $this->render_who_column($r);

            echo '<tr>';
            echo '<th class="check-column"><input type="checkbox" name="ledger_ids[]" value="'.esc_attr($id).'" /></th>';
            echo '<td>'.esc_html(wp_date('d/m/Y H:i:s', strtotime($r['created_at']))).'</td>';
            echo '<td><a href="'.esc_url($product_url).'" target="_blank">'.esc_html($name).'</a></td>';
            echo '<td>'.esc_html($sku).'</td>';
            echo '<td>'.esc_html($loc).'</td>';
            echo '<td style="text-align:right;font-weight:700;' . $delta_class . '">'.esc_html($delta_display).'</td>';
            echo '<td style="text-align:right">'.esc_html((string) $r['qty_before']).'</td>';
            echo '<td style="text-align:right">'.esc_html((string) $r['qty_after']).'</td>';
            echo '<td><code>'.esc_html($this->translate_source((string) $r['source'])).'</code></td>';
            echo '<td>' . $who_html . '</td>';
            echo '<td>';
            if ($oid > 0) {
                $url = admin_url('post.php?post=' . $oid . '&action=edit');
                echo '<a href="'.esc_url($url).'" target="_blank">#'.$oid.'</a>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></form></div>';
    }

    /**
     * ✅ CORRIGIDO: Validação de JSON decode
     */
    private function render_who_column(array $row): string {
        $who = (string)$row['who'];
        $source = (string)$row['source'];
        
        // ✅ CORRIGIDO: Validação de JSON
        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode($row['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        
        $oauth_description = $meta['oauth_description'] ?? '';
        
        $label = $this->get_who_label($who, $oauth_description);
        $icon = $this->get_who_icon($source);
        
        return sprintf(
            '<span class="c2p-who-label source-%s">
                <span class="c2p-who-icon">%s</span>
                <span>%s</span>
            </span>',
            esc_attr(sanitize_html_class($source)),
            $icon,
            esc_html($label)
        );
    }

    private function get_who_icon(string $source): string {
        if (strpos($source, 'oauth_') === 0) {
            return '🔑';
        }
        
        $icons = [
            'manual_admin' => '👤',
            'order_reduce' => '🛒',
            'order_restore' => '↩️',
            'api_stock_update' => '🔌',
        ];
        
        return $icons[$source] ?? '🔌';
    }

    private function translate_source(string $source): string {
        if (strpos($source, 'oauth_') === 0) {
            return 'API OAuth';
        }
        
        $map = [
            'manual_admin' => 'Manual (Admin)',
            'order_reduce' => 'Venda',
            'order_restore' => 'Estorno',
            'api_stock_update' => 'API Externa',
        ];
        
        return $map[$source] ?? $source;
    }

    private function get_who_label(string $who, ?string $oauth_description): string {
        if ($oauth_description) {
            return $oauth_description;
        }
        
        $map = [
            'external_api' => 'API Externa',
            'manual_admin' => 'Admin Manual',
            'guest' => 'Cliente (Visitante)',
        ];
        
        if (isset($map[$who])) return $map[$who];
        
        if (preg_match('/^customer#(\d+)/', $who, $m)) {
            $customer = get_userdata((int)$m[1]);
            return $customer ? $customer->display_name . ' (Cliente)' : 'Cliente #' . $m[1];
        }
        
        if (preg_match('/^user#(\d+)/', $who, $m)) {
            $user = get_userdata((int)$m[1]);
            return $user ? $user->display_name : 'Usuário #' . $m[1];
        }
        
        if (strpos($who, 'oauth:') === 0) {
            return substr($who, 6);
        }
        
        if (strpos($who, 'external_api@') === 0) {
            return 'API Externa (IP: ' . substr($who, 13) . ')';
        }
        
        return $who;
    }
}

add_action('plugins_loaded', function() {
    if (class_exists('\C2P\Stock_Report')) {
        \C2P\Stock_Report::instance();
    }
}, 15);