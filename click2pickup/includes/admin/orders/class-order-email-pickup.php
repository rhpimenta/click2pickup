<?php
namespace C2P\Admin\Orders;

use C2P\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Order_Email_Pickup {
    private static $inst;
    public static function instance(): self {
        return self::$inst ?? (self::$inst = new self());
    }

    private function __construct() {
        // Gatilhos (mesmos do seu arquivo antigo, porque funcionavam bem)
        add_action( 'woocommerce_new_order',               [ $this, 'hook_new' ], 30, 1 );
        add_action( 'woocommerce_checkout_order_processed',[ $this, 'hook_checkout' ], 30, 3 );
        add_action( 'woocommerce_payment_complete',        [ $this, 'hook_new' ], 30, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'hook_new' ], 30, 1 );
        add_action( 'woocommerce_order_status_on-hold',    [ $this, 'hook_new' ], 30, 1 );

        // Ação manual no pedido
        add_filter( 'woocommerce_order_actions', [ $this, 'register_action' ] );
        add_action( 'woocommerce_order_action_c2p_send_pickup_mail', [ $this, 'force_send' ] );

        // Teste via admin-post — agora com o MESMO nonce do formulário da aba
        add_action( 'admin_post_c2p_email_test', [ $this, 'handle_test' ] );
    }

    /* ============= Helpers de configuração/opts ============= */

    private function opts(): array {
        // Usa as opções centrais (aba E-mails)
        $o = Settings::get_options();
        return [
            'enabled'            => ! empty($o['email_pickup_enabled']),
            'notify_delivery'    => ! empty($o['email_pickup_notify_delivery']),
            'from_name'          => (string)($o['email_pickup_from_name']  ?? ''),
            'from_email'         => (string)($o['email_pickup_from_email'] ?? ''),
            'to_mode'            => (string)($o['email_pickup_to_mode']     ?? 'store'), // store|custom
            'to_custom'          => (string)($o['email_pickup_custom_to']   ?? ''),
            'bcc'                => (string)($o['email_pickup_bcc']         ?? ''),
            'subject'            => (string)($o['email_pickup_subject']     ?? 'Novo pedido #{order_number} - {unit_name}'),
            'body'               => (string)($o['email_pickup_body_html']   ?? ''),
        ];
    }

    private function resolve_unit( \WC_Order $order ): array {
        // tenta várias metas comuns do C2P / addons
        $cands = [ '_c2p_store_id','c2p_store_id','c2p_location_id','c2p_selected_store','rpws_store_id' ];
        $unit_id = 0;
        foreach ($cands as $k) {
            $v = (int) $order->get_meta($k, true);
            if ($v > 0) { $unit_id = $v; break; }
        }
        $mode = $order->get_meta('_c2p_mode', true) ?: $order->get_meta('c2p_mode', true);
        if (!$mode) {
            // tenta deduzir por método de envio
            foreach ($order->get_shipping_methods() as $ship) {
                $mid = $ship->get_method_id();
                if ($mid && strpos($mid, 'local_pickup') !== false) { $mode = 'pickup'; break; }
            }
            if (!$mode) $mode = 'delivery';
        }
        $unit_name = $unit_id ? get_the_title($unit_id) : 'CD Global';
        return [ $unit_id, $unit_name, $mode ];
    }

    private function resolve_store_email( int $store_id ): ?string {
        if (!$store_id) return null;
        $cands = [ 'c2p_email','store_email','email','rpws_store_email','contact_email','c2p_store_email' ];
        foreach ($cands as $k) {
            $v = trim( (string) get_post_meta($store_id, $k, true) );
            if ($v && is_email($v)) return $v;
        }
        return null;
    }

    private function build_placeholders( \WC_Order $order, string $unit_name ): array {
        $order_date = $order->get_date_created()
            ? $order->get_date_created()->date_i18n( get_option('date_format').' '.get_option('time_format') )
            : date_i18n( get_option('date_format').' '.get_option('time_format') );

        $customer = trim(
            $order->get_formatted_billing_full_name()
            ?: ( $order->get_formatted_shipping_full_name()
                ?: ( $order->get_billing_first_name().' '.$order->get_billing_last_name() ) )
        );

        return [
            '{unit_name}'      => $unit_name,
            '{order_number}'   => $order->get_order_number(),
            '{order_date}'     => $order_date,
            '{customer_name}'  => $customer,
            '{customer_phone}' => $order->get_billing_phone(),
            '{customer_email}' => $order->get_billing_email(),
            '{admin_link}'     => admin_url('post.php?post='.$order->get_id().'&action=edit'),
            '{site_name}'      => wp_specialchars_decode( get_option('blogname'), ENT_QUOTES ),
        ];
    }

    private function build_items_table( \WC_Order $order, string $unit_name ): string {
        $rows = '';
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) continue;
            $product = $item->get_product();
            $name    = $product ? wp_strip_all_tags( $product->get_name() ) : $item->get_name();
            $sku     = $product ? $product->get_sku() : '';
            $qty     = (float) $item->get_quantity();

            // Estoque "após a venda" — tenta perguntar ao multiestoque; senão cai no WC
            $stock_after = apply_filters( 'c2p_stock_after_sale', null, $product, $qty, $order, $unit_name );
            if ( $stock_after === null && $product && method_exists($product,'get_stock_quantity') ) {
                $stock_after = $product->get_stock_quantity();
            }

            $name_show = $name . ( $sku ? " ($sku)" : '' );
            $rows .= sprintf(
                '<tr>
                    <td style="padding:6px 8px;border-bottom:1px solid #eee">%s</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">%s</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">%s</td>
                 </tr>',
                esc_html($name_show),
                esc_html( wc_format_decimal($qty, 2) ),
                esc_html( is_numeric($stock_after) ? wc_format_decimal($stock_after, 2) : '—' )
            );
        }

        if ( $rows === '' ) $rows = '<tr><td colspan="3" style="padding:6px 8px;border-bottom:1px solid #eee">—</td></tr>';

        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0">'
             . '<thead><tr>'
             . '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #333">Produto</th>'
             . '<th style="text-align:right;padding:6px 8px;border-bottom:2px solid #333">Qtd vendida</th>'
             . '<th style="text-align:right;padding:6px 8px;border-bottom:2px solid #333">Estoque após venda</th>'
             . '</tr></thead>'
             . '<tbody>'.$rows.'</tbody></table>';
    }

    private function fill( string $tpl, array $ctx ): string { return strtr($tpl, $ctx); }

    /* ============= Envio ============= */

    private function send_core( \WC_Order $order, string $context, string $force_to = '' ): bool {
        $o = $this->opts();
        if ( empty($o['enabled']) ) return false;

        // evita duplicidade (exceto teste/manual)
        if ( $context !== 'test' && $context !== 'manual' && $order->get_meta('_c2p_pickup_mail_sent') ) return false;

        list($unit_id, $unit_name, $mode) = $this->resolve_unit( $order );
        $is_pickup = ($mode === 'pickup');
        if ( ! $is_pickup && empty($o['notify_delivery']) ) return false;

        // Destinatário
        $to = '';
        if ( $force_to && is_email($force_to) ) {
            $to = $force_to;
        } elseif ( ($o['to_mode'] ?? 'store') === 'custom' ) {
            $to = $o['to_custom'];
        } else {
            $to = $this->resolve_store_email( $unit_id ) ?: '';
        }
        if ( ! $to ) $to = get_option('admin_email');

        // Cabeçalhos
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( !empty($o['from_email']) && is_email($o['from_email']) ) {
            $from = $o['from_name'] ? sprintf('%s <%s>', $o['from_name'], $o['from_email']) : $o['from_email'];
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from;
        }
        if ( !empty($o['bcc']) ) {
            foreach ( preg_split('/[,;]+/', $o['bcc']) as $bcc ) {
                $bcc = trim($bcc);
                if ( is_email($bcc) ) $headers[] = 'Bcc: ' . $bcc;
            }
        }

        // Subject + Body
        $ph   = $this->build_placeholders( $order, $unit_name );
        $ph['{items_table}'] = $this->build_items_table( $order, $unit_name );
        $subj = $this->fill( $o['subject'], $ph );
        $body = $this->fill( $o['body'] ?: '<h2>Novo pedido #{order_number}</h2><h3>Itens</h3>{items_table}<p><a href="{admin_link}">Abrir no painel</a></p>', $ph );

        // Disparo
        if ( function_exists('WC') && method_exists(\WC(),'mailer') ) { \WC()->mailer(); }
        $ok = function_exists('wc_mail') ? wc_mail($to, $subj, $body, $headers) : wp_mail($to, $subj, $body, $headers);

        if ( $ok && $context !== 'test' ) {
            $order->update_meta_data('_c2p_pickup_mail_sent', time());
            $order->add_order_note('C2P • e-mail de nova venda enviado para '.$to.' ('.$context.').');
            $order->save();
        } elseif ( ! $ok ) {
            $order->add_order_note('C2P • falha ao enviar e-mail de nova venda para '.$to.' ('.$context.').');
        }

        return (bool) $ok;
    }

    /* ============= Hooks (auto/manual/teste) ============= */

    public function hook_new( $order_id ) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ($order) $this->send_core($order, 'new/status');
    }

    public function hook_checkout( $order_id, $posted, $order ) {
        if ( ! $order instanceof \WC_Order ) $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ($order) $this->send_core($order, 'checkout_processed');
    }

    public function register_action( array $actions ): array {
        $actions['c2p_send_pickup_mail'] = __( 'Enviar notificação Click2Pickup (retirada)', 'c2p' );
        return $actions;
    }

    public function force_send( \WC_Order $order ) {
        $order->delete_meta_data('_c2p_pickup_mail_sent');
        $order->save();
        $this->send_core($order, 'manual');
    }

    public function handle_test() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('forbidden');
        // O formulário usa wp_nonce_field('c2p_email_test'), então validamos assim:
        check_admin_referer( 'c2p_email_test' );

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $to       = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
        $redir    = admin_url('admin.php?page=c2p-settings&tab=emails');

        if ( ! $order_id || ! $to || ! is_email($to) ) {
            wp_safe_redirect( $redir . '&sent=0' ); exit;
        }
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ( ! $order ) {
            wp_safe_redirect( $redir . '&sent=0' ); exit;
        }
        $ok = $this->send_core( $order, 'test', $to );
        wp_safe_redirect( $redir . '&sent=' . ( $ok ? '1' : '0' ) ); exit;
    }
}
