<?php
namespace C2P;

if ( ! defined('ABSPATH') ) exit;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Sincroniza o estoque por LOJA/CD (tabela canônica {prefix}c2p_multi_stock) no ciclo do pedido.
 * - Baixa/restaura no local correto;
 * - Reespelha soma em _stock/_stock_status e snapshots por local;
 * - (NOVO) Dispara também 'c2p_multistock_changed' para o módulo de alerta de estoque baixo.
 */
class Order_Stock_Sync {

    private static $instance;

    /** Metas de controle e dados no pedido */
    const META_REDUCED   = '_c2p_multistock_reduced';
    const META_RESTORED  = '_c2p_multistock_restored';
    const META_LOCATION  = '_c2p_location_id';

    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_checkout_create_order', [$this, 'capture_location_on_checkout'], 10, 2);
        add_action('woocommerce_reduce_order_stock',  [$this, 'on_reduce_order_stock'],  1, 1);
        add_action('woocommerce_restore_order_stock', [$this, 'on_restore_order_stock'], 1, 1);
    }

    public function capture_location_on_checkout( WC_Order $order, array $data ) {
        $location_id = $this->detect_location_from_order($order, $data['shipping_method'] ?? null);
        if ( $location_id ) {
            $order->update_meta_data(self::META_LOCATION, (int) $location_id);
            $order->update_meta_data('c2p_location_id', (int) $location_id); // compat
        }
    }

    public function on_reduce_order_stock( $order ) {
        if ( ! ($order instanceof WC_Order) ) return;
        if ( 'yes' === $order->get_meta(self::META_REDUCED) ) return;

        $location_id = $this->get_order_location_id($order);
        if ( ! $location_id ) return;

        $log = $this->apply_delta_for_order_items($order, -1, (int)$location_id);

        if ( ! empty($log['changed_products']) ) {
            $this->reindex_products_totals($log['changed_products']);
            do_action('c2p_after_location_stock_change', $log['changed_products'], (int)$location_id, 'reduce', $order->get_id());
        }

        if ( ! empty($log['notes']) ) {
            $order->add_order_note( sprintf(
                'C2P • Estoque por local reduzido (%s):%s',
                $this->label_location($location_id),
                "\n" . implode("\n", $log['notes'])
            ) );
        }

        $order->update_meta_data(self::META_REDUCED, 'yes');
        $order->save();
    }

    public function on_restore_order_stock( $order ) {
        if ( ! ($order instanceof WC_Order) ) return;
        if ( 'yes' === $order->get_meta(self::META_RESTORED) ) return;

        $location_id = $this->get_order_location_id($order);
        if ( ! $location_id ) return;

        $log = $this->apply_delta_for_order_items($order, +1, (int)$location_id);

        if ( ! empty($log['changed_products']) ) {
            $this->reindex_products_totals($log['changed_products']);
            do_action('c2p_after_location_stock_change', $log['changed_products'], (int)$location_id, 'restore', $order->get_id());
        }

        if ( ! empty($log['notes']) ) {
            $order->add_order_note( sprintf(
                'C2P • Estoque por local restaurado (%s):%s',
                $this->label_location($location_id),
                "\n" . implode("\n", $log['notes'])
            ) );
        }

        $order->update_meta_data(self::META_RESTORED, 'yes');
        $order->save();
    }

    /* =========================
     * Internos
     * ========================= */

    private function get_table_and_col(): array {
        global $wpdb;
        $table = class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB', 'table_name')
            ? \C2P\Inventory_DB::table_name()
            : $wpdb->prefix . 'c2p_multi_stock';
        $col   = class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB', 'store_column_name')
            ? \C2P\Inventory_DB::store_column_name()
            : 'location_id';
        return [$table, $col];
    }

    private function get_order_location_id( WC_Order $order ): ?int {
        $meta_loc = (int) $order->get_meta(self::META_LOCATION);
        if ( $meta_loc > 0 ) return $meta_loc;
        $meta_compat = (int) $order->get_meta('c2p_location_id');
        if ( $meta_compat > 0 ) return $meta_compat;
        $infer = $this->detect_location_from_order($order, null);
        if ( $infer ) return (int) $infer;
        $alt = apply_filters('c2p_order_location_id', null, $order);
        if ( is_numeric($alt) && (int)$alt > 0 ) return (int) $alt;
        return null;
    }

    private function detect_location_from_order( WC_Order $order, $maybe_methods = null ): ?int {
        if ( is_array($maybe_methods) && ! empty($maybe_methods) ) {
            foreach ($maybe_methods as $mk) {
                if ( ! is_string($mk) ) continue;
                if ( preg_match('~^[a-z0-9_]+:(\d+)$~i', $mk, $m) ) {
                    $instance_id = (int) $m[1];
                    if ( $instance_id > 0 ) {
                        $loc = $this->map_instance_to_location($instance_id);
                        if ( $loc ) return (int) $loc;
                    }
                }
            }
        }
        foreach ( $order->get_shipping_methods() as $ship_item ) {
            $inst = method_exists($ship_item, 'get_instance_id') ? (int) $ship_item->get_instance_id() : 0;
            if ( $inst > 0 ) {
                $loc = $this->map_instance_to_location($inst);
                if ( $loc ) return (int) $loc;
            }
            $inst_meta = (int) $ship_item->get_meta('instance_id', true);
            if ( $inst_meta > 0 ) {
                $loc = $this->map_instance_to_location($inst_meta);
                if ( $loc ) return (int) $loc;
            }
        }
        return null;
    }

    private function get_qty_at_location( int $product_id, int $location_id ): int {
        global $wpdb;
        list($table, $col) = $this->get_table_and_col();
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id = %d AND {$col} = %d LIMIT 1",
            $product_id, $location_id
        ) );
        if ( $wpdb->last_error ) {
            error_log('[C2P][get_qty_at_location] '.$wpdb->last_error);
        }
        return is_numeric($row) ? (int)$row : 0;
    }

    /** Threshold efetivo: linha do local (low_stock_amount) → fallback WooCommerce */
    private function get_low_stock_threshold( int $product_id, int $location_id ): int {
        global $wpdb;
        list($table, $col) = $this->get_table_and_col();
        $thr = $wpdb->get_var( $wpdb->prepare(
            "SELECT low_stock_amount FROM {$table} WHERE product_id = %d AND {$col} = %d LIMIT 1",
            $product_id, $location_id
        ) );
        if ( is_numeric($thr) && (int)$thr > 0 ) return (int)$thr;

        if ( function_exists('wc_get_product') ) {
            $p = wc_get_product($product_id);
            if ($p) {
                $g = (int) wc_get_low_stock_amount($p);
                if ($g > 0) return $g;
            }
        }
        return 0;
    }

    private function apply_delta_for_order_items( WC_Order $order, int $direction, int $location_id ): array {
        $changed = [];
        $notes   = [];

        foreach ( $order->get_items('line_item') as $item ) {
            if ( ! ($item instanceof WC_Order_Item_Product) ) continue;
            $product = $item->get_product();
            if ( ! $product ) continue;

            $qty = (int) $item->get_quantity();
            if ( $qty <= 0 ) continue;

            $pid   = (int) $product->get_id();
            $delta = $qty * $direction;

            // BEFORE
            $before = $this->get_qty_at_location($pid, $location_id);

            // UPDATE
            $after  = $this->apply_delta_to_location($pid, $location_id, $delta);

            $changed[$pid] = true;

            $notes[] = sprintf('%s %s → saldo %d',
                $product->get_name(),
                $direction < 0 ? '−'.$qty : '+'.$qty,
                $after
            );

            // (NOVO) Dispara o hook clássico de mudança por local — alimenta o alerta automático
            $thr = $this->get_low_stock_threshold($pid, $location_id);
            /**
             * c2p_multistock_changed
             * @param int product_id
             * @param int location_id
             * @param int qty_before
             * @param int qty_after
             * @param int threshold_local
             * @param string contexto
             */
            do_action('c2p_multistock_changed', $pid, $location_id, (int)$before, (int)$after, (int)$thr, ($direction<0?'order_reduce':'order_restore') );
        }

        return [
            'changed_products' => array_map('intval', array_keys($changed)),
            'notes'            => $notes,
        ];
    }

    private function apply_delta_to_location( int $product_id, int $location_id, int $delta ): int {
        global $wpdb;
        list($table, $col) = $this->get_table_and_col();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id = %d AND {$col} = %d LIMIT 1",
            $product_id, $location_id
        ) );

        if ( $wpdb->last_error ) {
            error_log('[C2P][apply_delta_to_location][SELECT] '.$wpdb->last_error);
        }

        if ( $row ) {
            $new = max(0, (int)$row->qty + $delta);
            $wpdb->update(
                $table,
                [ 'qty' => $new, 'updated_at' => current_time('mysql', true) ],
                [ 'product_id' => $product_id, $col => $location_id ],
                [ '%d', '%s' ],
                [ '%d', '%d' ]
            );
            if ( $wpdb->last_error ) {
                error_log('[C2P][apply_delta_to_location][UPDATE] '.$wpdb->last_error);
            }
            return $new;
        } else {
            $new = max(0, 0 + $delta);
            $wpdb->insert(
                $table,
                [
                    'product_id'       => $product_id,
                    $col               => $location_id,
                    'qty'              => $new,
                    'low_stock_amount' => 0,
                    'updated_at'       => current_time('mysql', true),
                ],
                [ '%d', '%d', '%d', '%d', '%s' ]
            );
            if ( $wpdb->last_error ) {
                error_log('[C2P][apply_delta_to_location][INSERT] '.$wpdb->last_error);
            }
            return $new;
        }
    }

    private function reindex_products_totals( array $product_ids ): void {
        global $wpdb;
        if ( empty($product_ids) ) return;

        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        list($table, $col) = $this->get_table_and_col();
        $place = implode(',', array_fill(0, count($product_ids), '%d'));

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, SUM(qty) AS total
               FROM {$table}
              WHERE product_id IN ($place)
              GROUP BY product_id",
            $product_ids
        ), OBJECT_K );

        if ( $wpdb->last_error ) {
            error_log('[C2P][reindex_products_totals][SUM] '.$wpdb->last_error);
        }

        foreach ( $product_ids as $pid ) {
            $sum = isset($rows[$pid]) ? (int)$rows[$pid]->total : 0;

            $product = wc_get_product($pid);
            if ( $product ) {
                $product->set_stock_quantity($sum);
                if ( ! $product->backorders_allowed() ) {
                    $product->set_stock_status( $sum > 0 ? 'instock' : 'outofstock' );
                }
                $product->save();
            }

            if ( function_exists('wc_delete_product_transients') ) {
                wc_delete_product_transients( $pid );
            }
            if ( function_exists('wc_update_product_lookup_tables') ) {
                wc_update_product_lookup_tables( $pid );
            }
            if ( function_exists('clean_post_cache') ) {
                clean_post_cache( $pid );
            }

            $this->update_product_meta_snapshot( $pid, $table, $col );
        }
    }

    private function update_product_meta_snapshot( int $product_id, ?string $table = null, ?string $col = null ): void {
        global $wpdb;
        if ( ! $table || ! $col ) list($table, $col) = $this->get_table_and_col();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$col} AS loc, qty
               FROM {$table}
              WHERE product_id = %d
              ORDER BY {$col} ASC",
            $product_id
        ), ARRAY_A );

        if ( $wpdb->last_error ) {
            error_log('[C2P][update_product_meta_snapshot][SELECT] '.$wpdb->last_error);
        }

        $by_id   = [];
        $by_name = [];
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $loc_id = (int) $r['loc'];
                $qty    = (int) $r['qty'];
                $by_id[$loc_id] = $qty;

                $title = get_the_title( $loc_id );
                if ( $title === '' || $title === null ) $title = 'Local #'.$loc_id;
                $by_name[ $title ] = $qty;
            }
        }

        update_post_meta( $product_id, 'c2p_stock_by_location_ids', $by_id );
        update_post_meta( $product_id, 'c2p_stock_by_location',     $by_name );
    }

    private function map_instance_to_location( int $instance_id ): ?int {
        $via_filter = apply_filters('c2p_map_shipping_instance_to_location_id', null, $instance_id);
        if ( is_numeric($via_filter) && (int)$via_filter > 0 ) return (int) $via_filter;

        if ( class_exists(\C2P\Store_Shipping_Link::class) ) {
            foreach (['get_location_id_by_instance','get_location_id_by_shipping_instance'] as $m) {
                if ( method_exists(\C2P\Store_Shipping_Link::class, $m) ) {
                    $res = \C2P\Store_Shipping_Link::$m($instance_id);
                    if ( is_numeric($res) && (int)$res > 0 ) return (int) $res;
                }
            }
        }
        return null;
    }

    private function label_location( int $location_id ): string {
        return (string) apply_filters(
            'c2p_location_label',
            get_the_title($location_id) ?: ('location#'.$location_id),
            $location_id
        );
    }
}
