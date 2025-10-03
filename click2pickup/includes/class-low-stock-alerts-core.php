<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alerta de estoque baixo por LOCAL, usando template padrão do Woo nos e-mails.
 *
 * - Ouve o evento detalhado:  do_action('c2p_multistock_changed', $product_id, $location_id, $old_qty, $new_qty, $threshold_local, $ctx)
 *   → Envia somente quando cruza de >limiar para <=limiar.
 *
 * - Fallback: ouve do_action('c2p_after_location_stock_change', $product_ids, $location_id, $op, $order_id)
 *   → Recalcula o limiar e o saldo atual; só envia se atual <= limiar e o estado anterior "não era baixo"
 *     (usa um meta por produto/local para evitar e-mails repetidos).
 */
class Low_Stock_Alerts_Core {
    private static $instance;

    // Meta para debouncing por local: _c2p_low_alert_{location_id}
    private function flag_meta_key( int $location_id ): string {
        return '_c2p_low_alert_' . $location_id;
    }

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Evento detalhado (preferencial)
        add_action( 'c2p_multistock_changed', [ $this, 'on_change' ], 10, 6 );

        // Fallback a partir do sincronizador do pedido
        add_action( 'c2p_after_location_stock_change', [ $this, 'on_after_location_stock_change' ], 10, 4 );
    }

    /** Lê config dos alertas */
    private function cfg(): array {
        $o = \C2P\Settings::get_options();
        return [
            'enabled'   => ! empty($o['email_lowstock_enabled']),
            'subject'   => (string)($o['email_lowstock_subject'] ?? 'Alerta: Estoque baixo — {product_name}'),
            'body_html' => (string)($o['email_lowstock_body_html'] ?? '<h2>Alerta de estoque baixo</h2> ...'),
            'bcc'       => (string)($o['email_lowstock_bcc'] ?? ''),
        ];
    }

    /** Nome da tabela/coluna p/ quantidade e limiar por local */
    private function table_col(): array {
        global $wpdb;
        if ( class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB','table_name') ) {
            return [ \C2P\Inventory_DB::table_name(), \C2P\Inventory_DB::store_column_name() ];
        }
        return [ $wpdb->prefix.'c2p_multi_stock', 'location_id' ];
    }

    /** Quantidade atual no local */
    private function get_qty( int $product_id, int $location_id ): int {
        global $wpdb;
        list($table,$col) = $this->table_col();
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id=%d AND {$col}=%d LIMIT 1",
            $product_id, $location_id
        ) );
        return is_numeric($v) ? (int)$v : 0;
    }

    /** Limiar efetivo (por local → coluna low_stock_amount; se 0, usa WooCommerce do produto) */
    private function get_threshold( int $product_id, int $location_id ): int {
        global $wpdb;
        list($table,$col) = $this->table_col();
        $thr = $wpdb->get_var( $wpdb->prepare(
            "SELECT low_stock_amount FROM {$table} WHERE product_id=%d AND {$col}=%d LIMIT 1",
            $product_id, $location_id
        ) );
        $thr = is_numeric($thr) ? (int)$thr : 0;

        if ( $thr <= 0 && function_exists('wc_get_product') ) {
            $p = wc_get_product( $product_id );
            if ( $p ) {
                $woo_thr = (int) wc_get_low_stock_amount( $p );
                if ( $woo_thr > 0 ) $thr = $woo_thr;
            }
        }
        return max(0, $thr);
    }

    /** E-mail do local */
    private function get_store_email( int $store_id ): ?string {
        foreach ( [ 'c2p_email','store_email','email','rpws_store_email','contact_email','c2p_store_email' ] as $k ) {
            $v = trim((string)get_post_meta($store_id, $k, true));
            if ($v && is_email($v)) return $v;
        }
        return null;
    }

    /** Nome do produto + SKU */
    private function product_name_sku( int $product_id ): array {
        $name = 'Produto #'.$product_id; $sku = '';
        if ( function_exists('wc_get_product') ) {
            $p = wc_get_product($product_id);
            if ( $p ) { $name = $p->get_formatted_name(); $sku = $p->get_sku(); }
        }
        return [$name,$sku];
    }

    /** Nome do local */
    private function location_name( int $location_id ): string {
        $post = get_post( $location_id );
        return ($post && $post->post_status==='publish') ? $post->post_title : ('Local #'.$location_id);
    }

    /** Envia alerta (com template padrão do Woo) */
    private function send_alert( int $product_id, int $location_id, int $new_qty, int $thr, string $reason = '' ): bool {
        $cfg = $this->cfg();
        if ( ! $cfg['enabled'] ) return false;

        $to = $this->get_store_email( $location_id );
        if ( ! $to ) return false;

        [$pname,$sku] = $this->product_name_sku( $product_id );
        $lname        = $this->location_name( $location_id );

        $place = [
            '{product_id}'   => (string)$product_id,
            '{product_name}' => $pname,
            '{sku}'          => $sku ?: '',
            '{location_id}'  => (string)$location_id,
            '{location_name}' => $lname,
            '{new_qty}'      => (string)$new_qty,
            '{threshold}'    => (string)$thr,
            '{reason}'       => $reason,
            '{site_name}'    => wp_specialchars_decode( get_option('blogname'), ENT_QUOTES ),
        ];

        $subject = strtr( $cfg['subject'], $place );

        // Corpo configurável + wrap no template do Woo
        $inner = strtr( $cfg['body_html'], $place );
        $wrapped = $inner;
        if ( function_exists('WC') && method_exists(\WC(),'mailer') ) {
            $wrapped = \WC()->mailer()->wrap_message( __('Alerta de estoque baixo','c2p'), $inner );
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if (!empty($cfg['bcc'])) {
            foreach ( array_filter(array_map('trim', preg_split('/[,;]+/', $cfg['bcc']))) as $bcc ) {
                if (is_email($bcc)) $headers[] = 'Bcc: '.$bcc;
            }
        }

        return function_exists('wc_mail') ? wc_mail($to, $subject, $wrapped, $headers)
                                          : wp_mail($to, $subject, $wrapped, $headers);
    }

    /** Handler preferencial: temos old_qty e new_qty */
    public function on_change( int $product_id, int $location_id, int $old_qty, int $new_qty, int $threshold_local, string $ctx = '' ): void {
        try {
            $thr = max( 0, (int)$threshold_local );
            if ( $thr <= 0 ) $thr = $this->get_threshold( $product_id, $location_id );

            // Envia somente se cruzou de >thr para <=thr
            if ( $old_qty > $thr && $new_qty <= $thr ) {
                $ok = $this->send_alert( $product_id, $location_id, $new_qty, $thr, $ctx ?: 'threshold_cross' );
                $this->set_flag( $product_id, $location_id, $new_qty, $thr, $ok ? time() : 0 );
            } elseif ( $new_qty > $thr ) {
                // Saiu da zona de alerta → limpa flag
                $this->clear_flag( $product_id, $location_id );
            }
        } catch ( \Throwable $e ) {
            // não quebrar fluxo
        }
    }

    /** Fallback: sem old_qty exato; evita duplicações usando meta por local */
    public function on_after_location_stock_change( array $product_ids, int $location_id, string $op, $order_id ): void {
        try {
            foreach ( $product_ids as $pid ) {
                $pid = (int)$pid;
                $thr = $this->get_threshold( $pid, $location_id );
                if ( $thr <= 0 ) continue;

                $qty = $this->get_qty( $pid, $location_id );
                $flag = $this->get_flag( $pid, $location_id );

                if ( $qty <= $thr ) {
                    // Só alerta se "antes" não estava em alerta (flag vazio ou anterior > thr)
                    if ( empty($flag) || (isset($flag['last_qty']) && (int)$flag['last_qty'] > $thr) ) {
                        $ok = $this->send_alert( $pid, $location_id, $qty, $thr, 'order_'.$op );
                        $this->set_flag( $pid, $location_id, $qty, $thr, $ok ? time() : 0 );
                    }
                } else {
                    // Saiu da zona de alerta → limpa flag
                    $this->clear_flag( $pid, $location_id );
                }
            }
        } catch ( \Throwable $e ) {
            // silencioso
        }
    }

    /** === Debounce helpers (flag por produto/local) === */
    private function get_flag( int $product_id, int $location_id ): array {
        $k   = $this->flag_meta_key($location_id);
        $raw = get_post_meta( $product_id, $k, true );
        return is_array($raw) ? $raw : [];
    }
    private function set_flag( int $product_id, int $location_id, int $last_qty, int $thr, int $ts ): void {
        $k = $this->flag_meta_key($location_id);
        update_post_meta( $product_id, $k, [
            'last_qty' => (int)$last_qty,
            'thr'      => (int)$thr,
            'ts'       => (int)$ts,
        ] );
    }
    private function clear_flag( int $product_id, int $location_id ): void {
        delete_post_meta( $product_id, $this->flag_meta_key($location_id) );
    }
}

// bootstrap
Low_Stock_Alerts_Core::instance();
