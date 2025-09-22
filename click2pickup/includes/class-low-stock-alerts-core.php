<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Low_Stock_Alerts_Core {
    private static $inst;
    public static function instance(): self {
        return self::$inst ?? (self::$inst = new self());
    }

    private function __construct() {
        // Teste manual (aba E-mails)
        add_action( 'admin_post_c2p_lowstock_test', [ $this, 'handle_test' ] );

        // Watchers WooCommerce (global)
        add_action( 'woocommerce_low_stock', [ $this, 'on_wc_low_stock' ] );       // WC_Product
        add_action( 'woocommerce_no_stock',  [ $this, 'on_wc_low_stock' ] );       // WC_Product

        // Watcher do seu motor de multi-estoque (se aciona)
        add_action( 'c2p_multistock_changed', [ $this, 'on_multistock_changed' ], 10, 6 );
    }

    /* ============= Teste manual ============= */

    public function handle_test() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('forbidden');
        check_admin_referer('c2p_lowstock_test');

        $pid = isset($_POST['product_id'])  ? absint($_POST['product_id'])  : 0;
        $lid = isset($_POST['location_id']) ? absint($_POST['location_id']) : 0;
        $to  = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';

        $ok = false;
        if ( $pid && ( $product = wc_get_product($pid) ) && is_email($to) ) {
            $qty   = $this->get_qty_for_location( $product, $lid );
            $thr   = $this->get_threshold_for_location( $product, $lid );
            $lname = $this->get_location_name( $lid );

            $ok = $this->send_lowstock( $product, [
                'to'            => $to,
                'location_id'   => $lid,
                'location_name' => $lname,
                'new_qty'       => $qty,
                'threshold'     => $thr,
                'reason'        => 'manual_test',
            ] );
        }
        wp_safe_redirect( admin_url('admin.php?page=c2p-settings&tab=emails&lowstock_sent='.($ok?'1':'0')) );
        exit;
    }

    /* ============= Eventos reais ============= */

    public function on_wc_low_stock( $product ) {
        if ( ! $product instanceof \WC_Product ) return;
        $opts = Settings::get_options();
        if ( empty($opts['email_lowstock_enabled']) ) return;

        $qty = $product->get_stock_quantity();
        $thr = wc_get_low_stock_amount( $product );
        $this->send_lowstock( $product, [
            'to'            => get_option('admin_email'),
            'location_id'   => 0,
            'location_name' => '',
            'new_qty'       => is_numeric($qty) ? (int)$qty : null,
            'threshold'     => is_numeric($thr) ? (int)$thr : null,
            'reason'        => 'wc_low_stock',
        ] );
    }

    // payload: product_id, location_id, old_qty, new_qty, threshold_local, ctx
    public function on_multistock_changed( int $pid, int $lid, int $old_qty, int $new_qty, int $thr_local, string $ctx = '' ) {
        $opts = Settings::get_options();
        if ( empty($opts['email_lowstock_enabled']) ) return;

        // só dispara quando cruza >thr para <=thr
        if ( $old_qty > $thr_local && $new_qty <= $thr_local ) {
            $product = wc_get_product($pid);
            if ( ! $product ) return;

            $this->send_lowstock( $product, [
                'to'            => $this->get_store_email($lid) ?: get_option('admin_email'),
                'location_id'   => $lid,
                'location_name' => $this->get_location_name($lid),
                'new_qty'       => $new_qty,
                'threshold'     => $thr_local,
                'reason'        => $ctx ?: 'multistock_changed',
            ] );
        }
    }

    /* ============= Envio ============= */

    private function send_lowstock( \WC_Product $product, array $ctx ): bool {
        $o = Settings::get_options();
        if ( empty($o['email_lowstock_enabled']) ) return false;

        $subject_tpl = (string)($o['email_lowstock_subject']   ?? 'Alerta: Estoque baixo — {product_name}');
        $body_tpl    = (string)($o['email_lowstock_body_html'] ?? '<h2>Alerta de estoque baixo</h2><p><strong>Produto:</strong> {product_name} (SKU: {sku})</p><p><strong>Quantidade atual:</strong> {new_qty} — <strong>Limiar:</strong> {threshold}</p>');

        $repl = [
            '{product_name}'  => $product->get_name(),
            '{sku}'           => $product->get_sku() ?: '',
            '{product_id}'    => (string)$product->get_id(),
            '{variation_id}'  => method_exists($product,'get_parent_id') ? (string)$product->get_parent_id() : '',
            '{location_id}'   => isset($ctx['location_id'])   ? (string)$ctx['location_id']   : '',
            '{location_name}' => isset($ctx['location_name']) ? (string)$ctx['location_name'] : '',
            '{new_qty}'       => isset($ctx['new_qty'])       && $ctx['new_qty'] !== null ? (string)$ctx['new_qty'] : '—',
            '{threshold}'     => isset($ctx['threshold'])     && $ctx['threshold'] !== null ? (string)$ctx['threshold'] : '—',
            '{reason}'        => (string)($ctx['reason'] ?? ''),
            '{site_name}'     => get_bloginfo('name'),
        ];

        $subject = strtr($subject_tpl, $repl);
        $message = strtr($body_tpl,    $repl);

        $to = isset($ctx['to']) && is_email($ctx['to']) ? $ctx['to'] : get_option('admin_email');

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( !empty($o['email_lowstock_bcc']) ) {
            foreach ( preg_split('/[,;]+/', (string)$o['email_lowstock_bcc']) as $bcc ) {
                $bcc = trim($bcc);
                if ( is_email($bcc) ) $headers[] = 'Bcc: ' . $bcc;
            }
        }

        if ( function_exists('WC') && method_exists(\WC(),'mailer') ) { \WC()->mailer(); }
        return function_exists('wc_mail') ? wc_mail($to, $subject, $message, $headers) : wp_mail($to, $subject, $message, $headers);
    }

    /* ============= Utilitários (estoque/limiar por local) ============= */

    private function get_location_name( int $lid ): string {
        if ( $lid <= 0 ) return '';
        $p = get_post($lid);
        return ($p && $p->post_type === 'c2p_store') ? $p->post_title : '';
    }

    private function get_store_email( int $lid ): ?string {
        if ($lid <= 0) return null;
        foreach ( ['c2p_email','store_email','email','rpws_store_email','contact_email','c2p_store_email'] as $k ) {
            $v = trim((string)get_post_meta($lid,$k,true));
            if ($v && is_email($v)) return $v;
        }
        return null;
    }

    private function get_qty_for_location( \WC_Product $product, int $lid ): ?int {
        // Deixa o seu motor responder primeiro:
        $q = apply_filters( 'c2p_get_location_stock', null, $product->get_id(), $lid, $product );
        if ( $q !== null ) return (int)$q;

        // fallback global
        $g = method_exists($product,'get_stock_quantity') ? $product->get_stock_quantity() : null;
        return is_numeric($g) ? (int)$g : null;
    }

    private function get_threshold_for_location( \WC_Product $product, int $lid ): ?int {
        $t = apply_filters( 'c2p_low_stock_threshold_for_location', null, $product->get_id(), $lid, $product );
        if ( $t !== null ) return (int)$t;

        // fallback WC
        $g = wc_get_low_stock_amount( $product );
        return is_numeric($g) ? (int)$g : null;
    }
}
