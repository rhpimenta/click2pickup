<?php
/**
 * Click2Pickup - Ghost Stock Diagnostic Tool
 * 
 * ✅ v2.3.0: Adicionado botão "Corrigir WooCommerce"
 * ✅ Atualiza _stock para o valor correto da soma C2P
 * 
 * @package Click2Pickup
 * @since 2.3.0
 * @author rhpimenta
 * Last Update: 2025-10-07 23:51:00 UTC
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

class Ghost_Stock_Diagnostic {
    private static $instance;
    
    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_c2p_ghost_stock_export', [$this, 'export_csv']);
        add_action('admin_post_c2p_investigate_product', [$this, 'investigate_product']);
        add_action('admin_post_c2p_sync_product_stock', [$this, 'sync_product_stock']);
        add_action('admin_post_c2p_fix_woo_stock', [$this, 'fix_woo_stock']); // ✅ NOVO
        add_action('admin_post_c2p_delete_orphan_record', [$this, 'delete_orphan_record']);
    }
    
    public function add_menu(): void {
        add_submenu_page(
            'c2p-dashboard',
            __('Diagnóstico de Estoque Fantasma', 'c2p'),
            __('🔍 Estoque Fantasma', 'c2p'),
            'manage_woocommerce',
            'c2p-ghost-stock',
            [$this, 'render_page'],
            999
        );
    }
    
    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permissão insuficiente.', 'c2p'));
        }
        
        global $wpdb;
        $table = C2P::table();
        
        echo '<div class="wrap">';
        echo '<h1>🔍 ' . esc_html__('Diagnóstico de Estoque Fantasma', 'c2p') . '</h1>';
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>' . esc_html__('O que é estoque fantasma?', 'c2p') . '</strong><br>';
        echo esc_html__('Produtos que mostram estoque no WooCommerce (_stock > 0), mas NÃO têm registros no Click2Pickup (c2p_multi_stock).', 'c2p');
        echo '</p></div>';
        
        $sql_ghost = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                p.post_type,
                p.post_parent,
                stock_meta.meta_value AS woo_stock,
                sku_meta.meta_value AS sku,
                0 AS c2p_total
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} stock_meta 
                ON p.ID = stock_meta.post_id 
                AND stock_meta.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} sku_meta
                ON p.ID = sku_meta.post_id
                AND sku_meta.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'draft', 'pending', 'private')
              AND CAST(stock_meta.meta_value AS DECIMAL(10,2)) > 0
              AND p.ID NOT IN (SELECT DISTINCT product_id FROM {$table})
            ORDER BY CAST(stock_meta.meta_value AS DECIMAL(10,2)) DESC, p.ID ASC
        ";
        
        $ghosts = $wpdb->get_results($sql_ghost, ARRAY_A);
        
        $sql_mismatch = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                p.post_type,
                p.post_parent,
                stock_meta.meta_value AS woo_stock,
                sku_meta.meta_value AS sku,
                COALESCE(SUM(c2p.qty), 0) AS c2p_total
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} stock_meta 
                ON p.ID = stock_meta.post_id 
                AND stock_meta.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} sku_meta
                ON p.ID = sku_meta.post_id
                AND sku_meta.meta_key = '_sku'
            LEFT JOIN {$table} c2p
                ON p.ID = c2p.product_id
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'draft', 'pending', 'private')
            GROUP BY p.ID
            HAVING CAST(stock_meta.meta_value AS DECIMAL(10,2)) != COALESCE(SUM(c2p.qty), 0)
            ORDER BY ABS(CAST(stock_meta.meta_value AS DECIMAL(10,2)) - COALESCE(SUM(c2p.qty), 0)) DESC
        ";
        
        $mismatches = $wpdb->get_results($sql_mismatch, ARRAY_A);
        
        echo '<h2>⚠️ ' . esc_html__('Produtos com estoque fantasma', 'c2p') . ' <span class="count">(' . count($ghosts) . ')</span></h2>';
        
        if (empty($ghosts)) {
            echo '<p style="color:#16a34a;font-weight:600;">✅ ' . esc_html__('Nenhum estoque fantasma detectado!', 'c2p') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('ID', 'c2p') . '</th>';
            echo '<th>' . esc_html__('Produto', 'c2p') . '</th>';
            echo '<th>' . esc_html__('SKU', 'c2p') . '</th>';
            echo '<th>' . esc_html__('Tipo', 'c2p') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Estoque WooCommerce', 'c2p') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Estoque C2P', 'c2p') . '</th>';
            echo '<th>' . esc_html__('Ações', 'c2p') . '</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($ghosts as $row) {
                $edit_link = admin_url('post.php?post=' . $row['product_id'] . '&action=edit');
                $investigate_link = admin_url('admin-post.php?action=c2p_investigate_product&product_id=' . $row['product_id']);
                $type_label = $row['post_type'] === 'product_variation' ? __('Variação', 'c2p') : __('Simples', 'c2p');
                
                echo '<tr>';
                echo '<td>' . esc_html($row['product_id']) . '</td>';
                echo '<td><a href="' . esc_url($edit_link) . '" target="_blank">' . esc_html($row['product_name']) . '</a></td>';
                echo '<td>' . esc_html($row['sku'] ?: '—') . '</td>';
                echo '<td>' . esc_html($type_label) . '</td>';
                echo '<td style="text-align:right;font-weight:700;color:#dc2626;">' . esc_html($row['woo_stock']) . '</td>';
                echo '<td style="text-align:right;color:#9ca3af;">0</td>';
                echo '<td>';
                echo '<a href="' . esc_url($investigate_link) . '" class="button button-small button-primary">🔬 Investigar</a> ';
                echo '<a href="' . esc_url($edit_link) . '" target="_blank" class="button button-small">Editar</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '<hr style="margin:40px 0;">';
        
        echo '<h2>⚠️ ' . esc_html__('Produtos com descompasso (WooCommerce ≠ Click2Pickup)', 'c2p') . ' <span class="count">(' . count($mismatches) . ')</span></h2>';
        
        if (empty($mismatches)) {
            echo '<p style="color:#16a34a;font-weight:600;">✅ ' . esc_html__('Todos os estoques estão sincronizados!', 'c2p') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('ID', 'c2p') . '</th>';
            echo '<th>' . esc_html__('Produto', 'c2p') . '</th>';
            echo '<th>' . esc_html__('SKU', 'c2p') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Estoque WooCommerce', 'c2p') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Estoque C2P (soma)', 'c2p') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Diferença', 'c2p') . '</th>';
            echo '<th>' . esc_html__('Ações', 'c2p') . '</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($mismatches as $row) {
                $edit_link = admin_url('post.php?post=' . $row['product_id'] . '&action=edit');
                $investigate_link = admin_url('admin-post.php?action=c2p_investigate_product&product_id=' . $row['product_id']);
                $diff = (int)$row['woo_stock'] - (int)$row['c2p_total'];
                $diff_class = $diff > 0 ? 'color:#dc2626;' : 'color:#16a34a;';
                
                echo '<tr>';
                echo '<td>' . esc_html($row['product_id']) . '</td>';
                echo '<td><a href="' . esc_url($edit_link) . '" target="_blank">' . esc_html($row['product_name']) . '</a></td>';
                echo '<td>' . esc_html($row['sku'] ?: '—') . '</td>';
                echo '<td style="text-align:right;font-weight:700;">' . esc_html($row['woo_stock']) . '</td>';
                echo '<td style="text-align:right;font-weight:700;">' . esc_html($row['c2p_total']) . '</td>';
                echo '<td style="text-align:right;font-weight:700;' . $diff_class . '">' . ($diff > 0 ? '+' : '') . esc_html($diff) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($investigate_link) . '" class="button button-small button-primary">🔬 Investigar</a> ';
                echo '<a href="' . esc_url($edit_link) . '" target="_blank" class="button button-small">Editar</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        if (!empty($ghosts) || !empty($mismatches)) {
            echo '<p style="margin-top:20px;">';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=c2p_ghost_stock_export'), 'c2p_ghost_export')) . '" class="button button-primary button-large">';
            echo '📥 ' . esc_html__('Exportar relatório completo (CSV)', 'c2p');
            echo '</a>';
            echo '</p>';
        }
        
        echo '</div>';
    }
    
    /**
     * ✅ INVESTIGAÇÃO PROFUNDA
     */
    public function investigate_product(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        
        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        
        if (!$product_id) {
            wp_redirect(admin_url('admin.php?page=c2p-ghost-stock'));
            exit;
        }
        
        global $wpdb;
        $table = C2P::table();
        $col = C2P::col_store();
        
        echo '<div class="wrap">';
        echo '<h1>🔬 ' . sprintf(__('Investigação Profunda: Produto #%d', 'c2p'), $product_id) . '</h1>';
        
        $product = wc_get_product($product_id);
        if (!$product) {
            echo '<div class="notice notice-error"><p>' . __('Produto não encontrado.', 'c2p') . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=c2p-ghost-stock')) . '" class="button">&larr; ' . __('Voltar', 'c2p') . '</a></p>';
            echo '</div>';
            return;
        }
        
        // ✅ Notificação de sucesso
        if (isset($_GET['fixed'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Estoque corrigido com sucesso!</strong> O WooCommerce agora está sincronizado com o Click2Pickup.</p></div>';
        }
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>' . esc_html($product->get_name()) . '</strong><br>';
        echo 'SKU: ' . esc_html($product->get_sku() ?: '—') . '<br>';
        echo 'Tipo: ' . esc_html($product->get_type());
        echo '</p></div>';
        
        echo '<h2>📊 1. Estoque WooCommerce</h2>';
        $woo_stock = get_post_meta($product_id, '_stock', true);
        $manage_stock = get_post_meta($product_id, '_manage_stock', true);
        $stock_status = get_post_meta($product_id, '_stock_status', true);
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<tr><th>Meta Key</th><th>Valor</th></tr>';
        echo '<tr><td><code>_stock</code></td><td><strong style="font-size:18px;color:#dc2626;">' . esc_html($woo_stock) . '</strong></td></tr>';
        echo '<tr><td><code>_manage_stock</code></td><td>' . esc_html($manage_stock) . '</td></tr>';
        echo '<tr><td><code>_stock_status</code></td><td>' . esc_html($stock_status) . '</td></tr>';
        echo '</table>';
        
        echo '<h2>📦 2. Estoque Click2Pickup</h2>';
        
        $c2p_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, {$col} AS location_id, qty, low_stock_amount, updated_at 
             FROM {$table} WHERE product_id = %d ORDER BY {$col} ASC",
            $product_id
        ), ARRAY_A);
        
        $c2p_total = 0;
        
        if (empty($c2p_rows)) {
            echo '<p style="color:#dc2626;font-weight:600;">⚠️ Nenhum registro na tabela!</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Local</th><th>Qty</th><th>Atualizado</th></tr></thead><tbody>';
            foreach ($c2p_rows as $row) {
                $loc_name = get_the_title($row['location_id']) ?: 'Local #' . $row['location_id'];
                $c2p_total += (int)$row['qty'];
                echo '<tr><td>' . esc_html($loc_name) . '</td><td><strong>' . esc_html($row['qty']) . '</strong></td><td>' . esc_html($row['updated_at']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        
        $diff = (int)$woo_stock - $c2p_total;
        
        echo '<h2>⚖️ 3. Comparação</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<tr><td>WooCommerce (<code>_stock</code>)</td><td><strong style="font-size:18px;color:#dc2626;">' . esc_html($woo_stock) . '</strong></td></tr>';
        echo '<tr><td>Click2Pickup (soma tabela)</td><td><strong style="font-size:18px;color:#16a34a;">' . esc_html($c2p_total) . '</strong></td></tr>';
        echo '<tr style="background:#fef3c7;"><td><strong>DIFERENÇA (Fantasma)</strong></td><td><strong style="font-size:20px;color:#991b1b;">' . ($diff > 0 ? '+' : '') . esc_html($diff) . '</strong></td></tr>';
        echo '</table>';
        
        if ($diff > 0) {
            echo '<div class="notice notice-warning" style="margin-top:20px;"><p>';
            echo '<strong>⚠️ ESTOQUE FANTASMA DETECTADO!</strong><br><br>';
            echo sprintf(__('O WooCommerce mostra <strong>%d unidades</strong>, mas o Click2Pickup tem apenas <strong>%d unidades</strong> distribuídas.', 'c2p'), (int)$woo_stock, $c2p_total);
            echo '<br><br><strong>CAUSA:</strong> O campo <code>_stock</code> do WooCommerce está desatualizado.';
            echo '<br><strong>SOLUÇÃO:</strong> Clique no botão abaixo para corrigir automaticamente.';
            echo '</p></div>';
        }
        
        echo '<h2>🗂️ 4. Snapshot (post_meta)</h2>';
        $snapshot = get_post_meta($product_id, C2P::META_STOCK_BY_ID, true);
        if (empty($snapshot)) {
            echo '<p style="color:#9ca3af;">Vazio</p>';
        } else {
            echo '<pre style="background:#f5f5f5;padding:12px;border:1px solid #d4d4d4;border-radius:6px;">';
            print_r($snapshot);
            echo '</pre>';
        }
        
        echo '<hr><h2>🔧 Ações Corretivas</h2>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
        
        if ($diff != 0) {
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=c2p_fix_woo_stock&product_id=' . $product_id), 'c2p_fix_woo')) . '" class="button button-primary button-large" style="background:#16a34a!important;border-color:#15803d!important;">';
            echo '✅ Corrigir WooCommerce (' . esc_html($woo_stock) . ' → ' . esc_html($c2p_total) . ')';
            echo '</a>';
        }
        
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=c2p_sync_product_stock&product_id=' . $product_id), 'c2p_sync_stock')) . '" class="button button-large">🔄 Sincronizar (Snapshot → Tabela)</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=c2p-ghost-stock')) . '" class="button button-large">&larr; Voltar</a>';
        echo '</div>';
        
        echo '</div>';
        exit;
    }
    
    /**
     * ✅ NOVO: Corrigir estoque do WooCommerce
     */
    public function fix_woo_stock(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        
        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        
        if (!$product_id) {
            wp_redirect(admin_url('admin.php?page=c2p-ghost-stock'));
            exit;
        }
        
        check_admin_referer('c2p_fix_woo');
        
        global $wpdb;
        $table = C2P::table();
        
        // Calcula o total correto
        $c2p_total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0) FROM {$table} WHERE product_id = %d",
            $product_id
        ));
        
        // Atualiza o produto
        $product = wc_get_product($product_id);
        if ($product) {
            $old_stock = (int)$product->get_stock_quantity();
            
            $product->set_stock_quantity($c2p_total);
            
            if (!$product->backorders_allowed()) {
                $product->set_stock_status($c2p_total > 0 ? 'instock' : 'outofstock');
            }
            
            $product->save();
            
            // Log
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->info(sprintf(
                    '[Ghost Stock Fix] Produto #%d: _stock corrigido de %d para %d (fonte: Click2Pickup)',
                    $product_id,
                    $old_stock,
                    $c2p_total
                ), ['source' => 'c2p-ghost-diagnostic']);
            }
        }
        
        // Redireciona de volta
        wp_redirect(admin_url('admin-post.php?action=c2p_investigate_product&product_id=' . $product_id . '&fixed=1'));
        exit;
    }
    
    /**
     * ✅ Sincronizar (Snapshot → Tabela)
     */
    public function sync_product_stock(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        
        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        
        if (!$product_id) {
            wp_redirect(admin_url('admin.php?page=c2p-ghost-stock'));
            exit;
        }
        
        check_admin_referer('c2p_sync_stock');
        
        global $wpdb;
        $table = C2P::table();
        $col = C2P::col_store();
        
        $snapshot = get_post_meta($product_id, C2P::META_STOCK_BY_ID, true);
        
        if (empty($snapshot) || !is_array($snapshot)) {
            wp_redirect(admin_url('admin-post.php?action=c2p_investigate_product&product_id=' . $product_id . '&error=no_snapshot'));
            exit;
        }
        
        foreach ($snapshot as $loc_id => $qty) {
            $loc_id = (int)$loc_id;
            $qty = (int)$qty;
            
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND {$col} = %d",
                $product_id,
                $loc_id
            ));
            
            if ($exists) {
                $wpdb->update(
                    $table,
                    ['qty' => $qty, 'updated_at' => current_time('mysql', true)],
                    ['product_id' => $product_id, $col => $loc_id],
                    ['%d', '%s'],
                    ['%d', '%d']
                );
            } else {
                $wpdb->insert(
                    $table,
                    [
                        'product_id' => $product_id,
                        $col => $loc_id,
                        'qty' => $qty,
                        'low_stock_amount' => 0,
                        'updated_at' => current_time('mysql', true),
                    ],
                    ['%d', '%d', '%d', '%d', '%s']
                );
            }
        }
        
        wp_redirect(admin_url('admin-post.php?action=c2p_investigate_product&product_id=' . $product_id . '&synced=1'));
        exit;
    }
    
    /**
     * ✅ Deletar órfão
     */
    public function delete_orphan_record(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        
        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
        
        if (!$product_id || !$location_id) {
            wp_redirect(admin_url('admin.php?page=c2p-ghost-stock'));
            exit;
        }
        
        check_admin_referer('c2p_delete_orphan');
        
        global $wpdb;
        $table = C2P::table();
        $col = C2P::col_store();
        
        $wpdb->delete(
            $table,
            ['product_id' => $product_id, $col => $location_id],
            ['%d', '%d']
        );
        
        wp_redirect(admin_url('admin-post.php?action=c2p_investigate_product&product_id=' . $product_id . '&deleted=1'));
        exit;
    }
    
    /**
     * ✅ Exportar CSV
     */
    public function export_csv(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        check_admin_referer('c2p_ghost_export');
        
        global $wpdb;
        $table = C2P::table();
        
        $sql_ghost = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                p.post_type,
                stock_meta.meta_value AS woo_stock,
                sku_meta.meta_value AS sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} stock_meta 
                ON p.ID = stock_meta.post_id 
                AND stock_meta.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} sku_meta
                ON p.ID = sku_meta.post_id
                AND sku_meta.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'draft', 'pending', 'private')
              AND CAST(stock_meta.meta_value AS DECIMAL(10,2)) > 0
              AND p.ID NOT IN (SELECT DISTINCT product_id FROM {$table})
            ORDER BY p.ID ASC
        ";
        
        $ghosts = $wpdb->get_results($sql_ghost, ARRAY_A);
        
        $sql_mismatch = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                p.post_type,
                stock_meta.meta_value AS woo_stock,
                sku_meta.meta_value AS sku,
                COALESCE(SUM(c2p.qty), 0) AS c2p_total
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} stock_meta 
                ON p.ID = stock_meta.post_id 
                AND stock_meta.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} sku_meta
                ON p.ID = sku_meta.post_id
                AND sku_meta.meta_key = '_sku'
            LEFT JOIN {$table} c2p
                ON p.ID = c2p.product_id
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'draft', 'pending', 'private')
            GROUP BY p.ID
            HAVING CAST(stock_meta.meta_value AS DECIMAL(10,2)) != COALESCE(SUM(c2p.qty), 0)
            ORDER BY p.ID ASC
        ";
        
        $mismatches = $wpdb->get_results($sql_mismatch, ARRAY_A);
        
        while (ob_get_level() > 0) { @ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="c2p-estoque-fantasma-' . date('Y-m-d') . '.csv"');
        
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        
        fputcsv($out, ['Relatório gerado em:', wp_date('d/m/Y H:i:s')], ',', '"');
        fputcsv($out, [], ',', '"');
        
        fputcsv($out, ['ESTOQUE FANTASMA (WooCommerce > 0, Click2Pickup = 0)'], ',', '"');
        fputcsv($out, ['ID', 'Nome', 'SKU', 'Tipo', 'Estoque WooCommerce', 'Estoque C2P'], ',', '"');
        
        foreach ($ghosts as $row) {
            fputcsv($out, [
                $row['product_id'],
                $row['product_name'],
                $row['sku'] ?: '',
                $row['post_type'],
                $row['woo_stock'],
                0
            ], ',', '"');
        }
        
        fputcsv($out, [], ',', '"');
        fputcsv($out, ['DESCOMPASSO (WooCommerce ≠ Click2Pickup)'], ',', '"');
        fputcsv($out, ['ID', 'Nome', 'SKU', 'Tipo', 'Estoque WooCommerce', 'Estoque C2P', 'Diferença'], ',', '"');
        
        foreach ($mismatches as $row) {
            $diff = (int)$row['woo_stock'] - (int)$row['c2p_total'];
            fputcsv($out, [
                $row['product_id'],
                $row['product_name'],
                $row['sku'] ?: '',
                $row['post_type'],
                $row['woo_stock'],
                $row['c2p_total'],
                $diff
            ], ',', '"');
        }
        
        fclose($out);
        exit;
    }
}

Ghost_Stock_Diagnostic::instance();