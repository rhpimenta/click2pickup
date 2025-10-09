<?php
/**
 * Click2Pickup - Uninstall (Desinstalação Completa)
 * 
 * ⚠️ ATENÇÃO: Este arquivo só é executado quando o plugin é DESINSTALADO
 * (não quando desativado!)
 * 
 * Executa:
 * 1. Migra estoque multi-local → WooCommerce
 * 2. Deleta tabelas do plugin
 * 3. Remove posts do tipo c2p_store
 * 4. Limpa options e postmeta
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-01-09 00:12:22 UTC
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// ✅ Define constantes necessárias
if (!defined('C2P_TABLE')) {
    define('C2P_TABLE', $wpdb->prefix . 'c2p_stock');
}
if (!defined('C2P_TABLE_LEDGER')) {
    define('C2P_TABLE_LEDGER', $wpdb->prefix . 'c2p_stock_ledger');
}
if (!defined('C2P_COL_STORE')) {
    define('C2P_COL_STORE', 'store_id');
}
if (!defined('C2P_POST_TYPE_STORE')) {
    define('C2P_POST_TYPE_STORE', 'c2p_store');
}

/* ====================================================================
 * 1️⃣ MIGRA ESTOQUE MULTI-LOCAL → WOOCOMMERCE
 * ================================================================== */

function c2p_uninstall_migrate_stock_to_woocommerce() {
    global $wpdb;

    $table = C2P_TABLE;

    // Agrupa por produto e soma total
    $totals = $wpdb->get_results("
        SELECT product_id, SUM(qty) AS total_qty
        FROM {$table}
        GROUP BY product_id
        HAVING total_qty > 0
    ", ARRAY_A);

    if (empty($totals)) {
        return;
    }

    foreach ($totals as $row) {
        $product_id = (int) $row['product_id'];
        $total_qty = max(0, (int) $row['total_qty']);

        // Atualiza _stock do WooCommerce
        update_post_meta($product_id, '_stock', $total_qty);

        // Atualiza status
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product && !$product->backorders_allowed()) {
                update_post_meta($product_id, '_stock_status', $total_qty > 0 ? 'instock' : 'outofstock');
            }
        }

        // Limpa cache
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
    }
}

c2p_uninstall_migrate_stock_to_woocommerce();

/* ====================================================================
 * 2️⃣ REMOVE TABELAS DO PLUGIN
 * ================================================================== */

$wpdb->query("DROP TABLE IF EXISTS " . C2P_TABLE_LEDGER);
$wpdb->query("DROP TABLE IF EXISTS " . C2P_TABLE);

/* ====================================================================
 * 3️⃣ REMOVE POSTS DO TIPO c2p_store
 * ================================================================== */

$stores = get_posts([
    'post_type' => C2P_POST_TYPE_STORE,
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
]);

foreach ($stores as $store_id) {
    wp_delete_post($store_id, true);
}

/* ====================================================================
 * 4️⃣ LIMPA OPTIONS
 * ================================================================== */

delete_option('c2p_db_version');
delete_option('c2p_default_store_id');
delete_option('c2p_activation_data');
delete_option('c2p_deactivation_backup');
delete_option('c2p_deactivation_timestamp');

// Remove TODAS as options que começam com 'c2p_'
$wpdb->query("
    DELETE FROM {$wpdb->options} 
    WHERE option_name LIKE 'c2p\\_%'
");

/* ====================================================================
 * 5️⃣ LIMPA POSTMETA
 * ================================================================== */

$wpdb->query("
    DELETE FROM {$wpdb->postmeta}
    WHERE meta_key IN (
        'c2p_initialized',
        'c2p_stock_by_ids',
        'c2p_stock_by_name',
        'c2p_stock_by_location_ids',
        'c2p_stock_by_location',
        'c2p_type',
        'c2p_is_default',
        'c2p_auto_linked_shipping',
        'c2p_enabled_stores'
    )
");

/* ====================================================================
 * 6️⃣ LIMPA USERMETA
 * ================================================================== */

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta}
     WHERE meta_key LIKE %s OR meta_key LIKE %s",
    $wpdb->esc_like('c2p_email_invalid_') . '%',
    $wpdb->esc_like('c2p_phone_invalid_') . '%'
));

/* ====================================================================
 * 7️⃣ LIMPA TRANSIENTS
 * ================================================================== */

delete_transient('c2p_init_scan_lock');
delete_transient('c2p_activation_notice');
delete_transient('c2p_deactivation_notice');

/* ====================================================================
 * 8️⃣ LIMPA CACHE
 * ================================================================== */

wp_cache_flush();