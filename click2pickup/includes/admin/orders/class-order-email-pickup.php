<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

class Order_Email_Pickup {
    private static $instance;

    // Constantes
    private const STORE_ID_META_KEYS    = [ '_c2p_store_id','c2p_store_id','c2p_location_id','c2p_selected_store','rpws_store_id' ];
    private const STORE_EMAIL_META_KEYS = [ 'c2p_email','store_email','email','rpws_store_email','contact_email','c2p_store_email' ];
    private const ORDER_MODE_META_KEYS  = [ '_c2p_mode', 'c2p_mode' ];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Ações no pedido (admin)
        add_filter( 'woocommerce_order_actions', [ $this, 'register_action' ] );
        add_action( 'woocommerce_order_action_c2p_send_pickup_mail', [ $this, 'force_send' ] );

        // Teste (aba E-mails)
        add_action( 'admin_post_c2p_email_test', [ $this, 'handle_test' ] );

        // Envio AUTOMÁTICO após baixa de estoque (já com saldo pós-baixa)
        add_action( 'woocommerce_reduce_order_stock', [ $this, 'on_reduce_order_stock' ], 60, 1 );

        // Hooks antigos no-op
        add_action( 'woocommerce_new_order', [ $this, 'hook_new' ], 30, 1 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'hook_checkout' ], 30, 3 );
        add_action( 'woocommerce_payment_complete', [ $this, 'hook_new' ], 30, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'hook_new' ], 30, 1 );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'hook_new' ], 30, 1 );
    }

    /** === CFG === */
    private function cfg(): array {
        $opts = \C2P\Settings::get_options();
        return [
            'enable'            => !empty($opts['email_pickup_enabled']) ? 1 : 0,
            'notify_delivery'   => !empty($opts['email_pickup_notify_delivery']) ? 1 : 0,
            'from_name'         => (string)($opts['email_pickup_from_name'] ?? ''),
            'from_email'        => (string)($opts['email_pickup_from_email'] ?? ''),
            'to_mode'           => (string)($opts['email_pickup_to_mode'] ?? 'store'),
            'custom_to'         => (string)($opts['email_pickup_custom_to'] ?? ''),
            'bcc'               => (string)($opts['email_pickup_bcc'] ?? ''),
            'subject'           => (string)($opts['email_pickup_subject'] ?? 'Novo pedido #{order_number} - {unit_name}'),
            'body_html'         => (string)($opts['email_pickup_body_html'] ?? ''),
        ];
    }

    private function allowed_by_trigger( string $context ): bool {
        $cfg = $this->cfg();
        if ( empty($cfg['enable']) ) return false;
        return in_array($context, ['after_stock','manual','test'], true);
    }

    /** === Unidade/modo === */
    private function get_unit( \WC_Order $order ): array {
        $location_id = 0;
        foreach ( self::STORE_ID_META_KEYS as $k ) {
            $v = $order->get_meta($k, true);
            if ($v !== '' && $v !== null) { $location_id = (int)$v; break; }
        }

        $mode = null;
        foreach ( self::ORDER_MODE_META_KEYS as $k ) {
            $v = $order->get_meta($k, true);
            if ($v) { $mode = $v; break; }
        }
        if ($mode === 'RECEBER') $mode = 'delivery';
        if ($mode === 'RETIRAR') $mode = 'pickup';

        if (!$mode) {
            foreach ( $order->get_shipping_methods() as $ship ) {
                $mid = $ship->get_method_id();
                if ($mid && strpos($mid, 'local_pickup') !== false) { $mode = 'pickup'; break; }
            }
            if (!$mode) $mode = 'delivery';
        }

        $name = 'CD Global';
        if ($location_id) {
            $p = get_post($location_id);
            if ($p && $p->post_status === 'publish') $name = $p->post_title;
        }
        return ['id' => $location_id, 'name' => $name, 'mode' => $mode];
    }

    private function get_store_email( int $store_id ): ?string {
        if (!$store_id) return null;
        foreach ( self::STORE_EMAIL_META_KEYS as $k ) {
            $v = trim((string)get_post_meta($store_id, $k, true));
            if ($v && is_email($v)) return $v;
        }
        return null;
    }

    /** === Estoque por local === */
    private function get_location_qty( int $product_id, int $location_id ): ?int {
        global $wpdb;
        if ( $product_id <= 0 || $location_id <= 0 ) return null;

        if ( class_exists('\C2P\Inventory_DB') && method_exists('\C2P\Inventory_DB','table_name') ) {
            $table = \C2P\Inventory_DB::table_name();
            $col   = \C2P\Inventory_DB::store_column_name();
        } else {
            $table = $wpdb->prefix . 'c2p_multi_stock';
            $col   = 'location_id';
        }

        $qty = $wpdb->get_var( $wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id=%d AND {$col}=%d LIMIT 1",
            (int)$product_id, (int)$location_id
        ) );
        return ($qty === null) ? null : (int)$qty;
    }

    /** === Nome/SKU === */
    private function get_name_sku( int $product_id ): array {
        $name = 'Produto #'.$product_id; $sku = '';
        if ( function_exists('wc_get_product') ) {
            $p = wc_get_product($product_id);
            if ($p) { $name = $p->get_formatted_name(); $sku = $p->get_sku(); }
        }
        return [$name, $sku];
    }

    /** === Tabelas de itens (e-mail do pedido) === */
    private function items_table_from_summary_before( array $summary, int $location_id ): string {
        $list = $summary[$location_id] ?? [];
        if (!$list) return '<p>(Sem itens)</p>';
        $rows = [];
        foreach ($list as $pid => $row) {
            $name = (string)($row['name'] ?? ('Produto #'.$pid));
            $sku  = (string)($row['sku']  ?? '');
            if ($sku && !preg_match('/\(\s*'.preg_quote($sku, '/').'\s*\)$/', $name)) $name .= " ($sku)";
            $stock_display = is_int($row['before']) ? (string)$row['before'] : '—';
            $rows[] = sprintf(
                '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;">%d</td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;">%s</td></tr>',
                esc_html($name),
                (int)($row['sold'] ?? 0),
                $stock_display
            );
        }
        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;">'
             . '<thead><tr>'
             . '<th style="text-align:left;padding:8px;border-bottom:2px solid #333;">Produto</th>'
             . '<th style="text-align:center;padding:8px;border-bottom:2px solid #333;">Vendida</th>'
             . '<th style="text-align:center;padding:8px;border-bottom:2px solid #333;">Estoque (antes da venda)</th>'
             . '</tr></thead><tbody>'.implode('', $rows).'</tbody></table>';
    }

    private function items_table_basic( \WC_Order $order ): string {
        $rows=[];
        foreach ($order->get_items() as $item) {
            if (! $item instanceof \WC_Order_Item_Product) continue;
            $product = $item->get_product();
            $name = $product ? wp_strip_all_tags($product->get_name()) : $item->get_name();
            $sku  = $product ? $product->get_sku() : '';
            if ($sku && !preg_match('/\(\s*'.preg_quote($sku, '/').'\s*\)$/', $name)) $name .= " ($sku)";
            $rows[] = sprintf(
                '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;">%s</td></tr>',
                esc_html($name), esc_html((string)$item->get_quantity())
            );
        }
        if (!$rows) return '<p>(Sem itens)</p>';
        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;">'
             . '<thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #333;">Produto</th><th style="text-align:center;padding:8px;border-bottom:2px solid #333;">Qtd</th></tr></thead>'
             . '<tbody>'.implode('', $rows).'</tbody></table>';
    }

    private function fill( string $tpl, array $ctx ): string { return strtr($tpl, $ctx); }

    /* ===================== PRAZO DE PREPARO (mantido) ===================== */
    private function tz(): \DateTimeZone {
        if ( function_exists('wp_timezone') ) return wp_timezone();
        $tz = get_option('timezone_string') ?: 'UTC';
        return new \DateTimeZone($tz);
    }
    private function parse_hhmm( $s ): ?array {
        $s = trim((string)$s);
        if ( $s === '' ) return null;
        if ( preg_match('~^(\d{1,2}):?(\d{2})$~', $s, $m) ) {
            $h=(int)$m[1]; $i=(int)$m[2];
            if ($h>=0&&$h<24&&$i>=0&&$i<60) return [$h,$i];
        }
        return null;
    }
    private function slug_for_dow(int $w): string {
        return [0=>'sun',1=>'mon',2=>'tue',3=>'wed',4=>'thu',5=>'fri',6=>'sat'][$w] ?? 'mon';
    }
    private function get_store_calendar(int $store_id): array {
        $weekly   = get_post_meta($store_id, 'c2p_hours_weekly', true);
        $specials = get_post_meta($store_id, 'c2p_hours_special', true);
        return [
            'weekly'   => is_array($weekly)   ? $weekly   : [],
            'specials' => is_array($specials) ? $specials : [],
        ];
    }
    private function find_special_for_date(\DateTimeImmutable $date, array $specials): ?array {
        $ymd = $date->format('Y-m-d');
        $md  = $date->format('m-d');
        foreach ( (array)$specials as $sp ) {
            $date_sql = (string)($sp['date_sql'] ?? '');
            $date_br  = (string)($sp['date_br']  ?? '');
            $annual   = !empty($sp['annual']);
            if ( $date_sql ) {
                if ( $annual ) { if ( substr($date_sql,5,5) === $md ) return $sp; }
                else { if ( $date_sql === $ymd ) return $sp; }
                continue;
            }
            if ( $date_br && preg_match('~^(\d{2})/(\d{2})(?:/(\d{4}))?$~', $date_br, $m) ) {
                $cmp_md  = $m[2].'-'.$m[1];
                if ( !empty($m[3]) ) { if ( $ymd === ($m[3].'-'.$cmp_md) ) return $sp; }
                else { if ( $annual && $md === $cmp_md ) return $sp; }
            }
        }
        return null;
    }
    private function resolve_day_schedule(\DateTimeImmutable $date, int $store_id): array {
        $cal = $this->get_store_calendar($store_id);
        $sp  = $this->find_special_for_date($date, $cal['specials']);

        $row = null;
        if ( is_array($sp) ) {
            $row = [
                'open'   => (string)($sp['open']   ?? ''),
                'close'  => (string)($sp['close']  ?? ''),
                'cutoff' => (string)($sp['cutoff'] ?? ''),
                'prep'   => isset($sp['prep_min']) ? (int)$sp['prep_min'] : null,
                'enabled'=> true,
            ];
        } else {
            $slug = $this->slug_for_dow( (int)$date->format('w') );
            $wk = $cal['weekly'][$slug] ?? null;
            if ( is_array($wk) ) {
                $row = [
                    'open'    => (string)($wk['open']   ?? ''),
                    'close'   => (string)($wk['close']  ?? ''),
                    'cutoff'  => (string)($wk['cutoff'] ?? ''),
                    'prep'    => isset($wk['prep_min']) ? (int)$wk['prep_min'] : null,
                    'enabled' => !empty($wk['open_enabled']),
                ];
            }
        }

        if ( ! $row ) return ['status'=>'undefined'];
        if ( empty($row['enabled']) ) return ['status'=>'closed'];

        $open  = $this->parse_hhmm($row['open']);
        $close = $this->parse_hhmm($row['close']);
        $prep  = is_numeric($row['prep']) ? max(0,(int)$row['prep']) : null;
        $cut   = $this->parse_hhmm($row['cutoff'] ?? '');

        if ( ! $open || ! $close || $prep === null ) return ['status'=>'undefined'];

        $o = $date->setTime($open[0],  $open[1],  0);
        $c = $date->setTime($close[0], $close[1], 0);
        if ( $c <= $o ) return ['status'=>'undefined'];
        $cutDT = $cut ? $date->setTime($cut[0], $cut[1], 0) : null;

        return [
            'status'=>'open',
            'open'=>$o, 'close'=>$c,
            'prep_minutes'=>$prep,
            'cutoff'=>$cutDT
        ];
    }
    private function next_open_day(\DateTimeImmutable $from, int $store_id): ?array {
        $d = $from;
        for ($i=0;$i<14;$i++) {
            $d = $d->modify('+1 day')->setTime(0,0,0);
            $s = $this->resolve_day_schedule($d, $store_id);
            if ( ($s['status'] ?? '') === 'open' ) return ['date'=>$d, 'sched'=>$s];
        }
        return null;
    }
    private function compute_prep_deadline(\WC_Order $order, int $store_id): ?\DateTimeImmutable {
        if ($store_id <= 0) return null;

        $tz = $this->tz();
        $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
        $now = (new \DateTimeImmutable('@'.$ts))->setTimezone($tz);

        $today = $this->resolve_day_schedule($now, $store_id);
        if ( ($today['status'] ?? '') === 'undefined' ) return null;

        $start = null; $sched = null;

        if ( ($today['status'] ?? '') === 'open' ) {
            $outside     = ($now < $today['open'] || $now >= $today['close']);
            $after_cut   = ($today['cutoff'] instanceof \DateTimeImmutable) ? ($now > $today['cutoff']) : false;

            if ( $outside || $after_cut ) {
                $next = $this->next_open_day($now, $store_id);
                if ( ! $next ) return null;
                $sched = $next['sched'];
                $start = $sched['open'];
            } else {
                $sched = $today;
                $start = ($now > $today['open']) ? $now : $today['open'];
            }
        } elseif ( ($today['status'] ?? '') === 'closed' ) {
            $next = $this->next_open_day($now, $store_id);
            if ( ! $next ) return null;
            $sched = $next['sched'];
            $start = $sched['open'];
        } else {
            return null;
        }

        $prep = (int) $sched['prep_minutes'];
        $prov = $start->modify("+{$prep} minutes");
        if ( $prov <= $sched['close'] ) return $prov;

        $guard = 0; $d = $start;
        while ($guard++ < 14) {
            $next = $this->next_open_day($d, $store_id);
            if ( ! $next ) return null;
            $sched = $next['sched'];
            $start = $sched['open'];
            $prov  = $start->modify("+{$prep} minutes");
            if ( $prov <= $sched['close'] ) return $prov;
            $d = $start;
        }
        return null;
    }
    private function build_prep_block(\WC_Order $order, array $unit): array {
        $deadline = $this->compute_prep_deadline($order, (int)$unit['id']);
        if ( ! $deadline ) {
            $html = '<div style="padding:12px 16px; border:2px solid #f59e0b; background:#FFF8E6; border-radius:8px; margin:0 0 16px 0;">
                <div style="font-size:16px; line-height:1.4; font-weight:600; margin:0 0 4px 0;">🕒 ATENÇÃO: PRAZO PARA PREPARO</div>
                <div style="font-size:14px; line-height:1.5;"><strong>Sem definição</strong></div>
            </div>';
            return ['html'=>$html, 'time'=>'', 'date'=>''];
        }
        $time_str = wp_date('H:i', $deadline->getTimestamp(), $this->tz());
        $date_str = wp_date(get_option('date_format') ?: 'd/m/Y', $deadline->getTimestamp(), $this->tz());
        $msg = sprintf(
            'Prepare o pedido até <strong>%s</strong> do dia <strong>%s</strong>.',
            esc_html($time_str), esc_html($date_str)
        );
        $html = '<div style="padding:12px 16px; border:2px solid #f59e0b; background:#FFF8E6; border-radius:8px; margin:0 0 16px 0;">
            <div style="font-size:16px; line-height:1.4; font-weight:600; margin:0 0 4px 0;">🕒 ATENÇÃO: PRAZO PARA PREPARO</div>
            <div style="font-size:14px; line-height:1.5;">'.$msg.'</div>
        </div>';
        return ['html'=>$html, 'time'=>$time_str, 'date'=>$date_str];
    }

    /** === E-mail destino === */
    private function resolve_recipient_email( array $unit ): ?string {
        $cfg = $this->cfg();
        $to = null;
        if ( ($cfg['to_mode'] ?? 'store') === 'custom' ) $to = trim($cfg['custom_to']);
        else $to = $this->get_store_email( (int)$unit['id'] );
        if ( ! $to && ! empty($cfg['custom_to']) ) $to = trim($cfg['custom_to']);
        return ($to && is_email($to)) ? $to : null;
    }

    /* ===================== LOW STOCK ===================== */

    /** Resolve o limiar de “estoque baixo” — tenta várias fontes */
    private function get_low_stock_threshold( int $product_id, int $location_id ): int {
        // 1) Filtro
        $filtered = apply_filters( 'c2p_low_stock_threshold', null, $product_id, $location_id );
        if ( is_numeric($filtered) && (int)$filtered >= 0 ) return (int)$filtered;

        // 2) Produto/variação (por local e geral)
        $pid = $product_id;
        $v = get_post_meta( $pid, 'c2p_low_stock_threshold_' . (int)$location_id, true );
        if ( $v !== '' && $v !== null && is_numeric($v) && (int)$v >= 0 ) return (int)$v;

        $v = get_post_meta( $pid, 'c2p_low_stock_threshold', true );
        if ( $v !== '' && $v !== null && is_numeric($v) && (int)$v >= 0 ) return (int)$v;

        $v = get_post_meta( $pid, '_low_stock_amount', true );
        if ( $v !== '' && $v !== null && is_numeric($v) && (int)$v >= 0 ) return (int)$v;

        // 2b) Pai
        $parent_id = (int) get_post_meta( $pid, '_parent_id', true );
        if ( ! $parent_id && function_exists('wc_get_product') ) {
            $p = wc_get_product($pid);
            if ( $p && $p->get_parent_id() ) $parent_id = (int)$p->get_parent_id();
        }
        if ( $parent_id ) {
            $v = get_post_meta( $parent_id, 'c2p_low_stock_threshold_' . (int)$location_id, true );
            if ( $v !== '' && $v !== null && is_numeric($v) && (int)$v >= 0 ) return (int)$v;

            $v = get_post_meta( $parent_id, 'c2p_low_stock_threshold', true );
            if ( $v !== '' && $v !== null && is_numeric($v) && (int)$v >= 0 ) return (int)$v;

            $v = get_post_meta( $parent_id, '_low_stock_amount', true );
            if ( $v !== '' && $v !== null && is_numeric($v) && (int)$v >= 0 ) return (int)$v;
        }

        // 3) Local (CPT)
        $loc_keys = [ 'c2p_low_stock_threshold', 'low_stock_threshold', 'min_stock', 'min_qty', 'alert_threshold' ];
        foreach ( $loc_keys as $k ) {
            $vv = get_post_meta( (int)$location_id, $k, true );
            if ( $vv !== '' && $vv !== null && is_numeric($vv) && (int)$vv >= 0 ) return (int)$vv;
        }

        // 4) Global Woo
        $global = get_option( 'woocommerce_notify_low_stock_amount', '' );
        if ( $global !== '' && is_numeric($global) && (int)$global > 0 ) return (int)$global;

        return 0;
    }

    private function get_last_low_notified( int $product_id, int $location_id ): ?int {
        $map = get_post_meta( $product_id, 'c2p_low_stock_notified', true );
        if ( is_array($map) && array_key_exists($location_id, $map) ) {
            $q = $map[$location_id];
            return is_numeric($q) ? (int)$q : null;
        }
        return null;
    }
    private function set_last_low_notified( int $product_id, int $location_id, int $qty ): void {
        $map = get_post_meta( $product_id, 'c2p_low_stock_notified', true );
        if ( ! is_array($map) ) $map = [];
        $map[$location_id] = (int)$qty;
        update_post_meta( $product_id, 'c2p_low_stock_notified', $map );
    }
    private function clear_last_low_notified( int $product_id, int $location_id ): void {
        $map = get_post_meta( $product_id, 'c2p_low_stock_notified', true );
        if ( is_array($map) && array_key_exists($location_id, $map) ) {
            unset($map[$location_id]);
            update_post_meta( $product_id, 'c2p_low_stock_notified', $map );
        }
    }

    /** Designer novo do e-mail de Low Stock (sem debug) */
    private function send_low_stock_email( int $product_id, array $unit, int $location_id, int $after_qty, ?int $before_qty, int $threshold, \WC_Order $order ): bool {
        $to = $this->get_store_email( $location_id );
        if ( ! $to ) $to = get_option('admin_email'); // fallback
        if ( ! $to || ! is_email($to) ) return false;

        [$name, $sku] = $this->get_name_sku($product_id);
        if ($sku && !preg_match('/\(\s*'.preg_quote($sku,'/').'\s*\)$/',$name)) $name .= " ($sku)";

        $unit_name = $unit['name'];
        $subject   = sprintf('⚠️ Estoque baixo — %s (%s)', wp_strip_all_tags($name), $unit_name);

        $status_badge = ($after_qty <= 0)
            ? '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#dc2626;color:#fff;font-weight:600;font-size:12px;">Zerado</span>'
            : '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#f59e0b;color:#111827;font-weight:600;font-size:12px;">Crítico</span>';

        $before_tr = is_int($before_qty)
            ? sprintf('<tr><td style="padding:10px;border-bottom:1px solid #eee;color:#4b5563;">Qtd antes</td><td style="padding:10px;border-bottom:1px solid #eee;text-align:right;font-weight:600;">%d</td></tr>', (int)$before_qty)
            : '';

        $delta = max(0, $threshold - max(0,$after_qty));

        $primary_btn = sprintf(
            '<a href="%s" target="_blank" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;">Abrir pedido #%s</a>',
            esc_url( admin_url('post.php?post='.$order->get_id().'&action=edit') ),
            esc_html( $order->get_order_number() )
        );
        $secondary_btn = sprintf(
            '<a href="%s" target="_blank" style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d1d5db;color:#111827;text-decoration:none;font-weight:600;margin-left:8px;">Abrir produto</a>',
            esc_url( admin_url('post.php?post='.$product_id.'&action=edit') )
        );

        $body_inner = '
<div style="border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
  <div style="padding:14px 16px;background:linear-gradient(90deg,#fde68a,#fca5a5);border-bottom:1px solid #e5e7eb;">
    <div style="font-size:16px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;">
      <span>⚠️</span> Estoque baixo no local <span style="font-weight:800;">'.esc_html($unit_name).'</span>
      <span style="margin-left:auto;">'.$status_badge.'</span>
    </div>
  </div>

  <div style="padding:16px;background:#ffffff;">
    <div style="border:1px solid #f3f4f6;border-radius:12px;padding:12px;margin-bottom:12px;">
      <div style="font-size:15px;font-weight:700;color:#111827;margin-bottom:2px;">'.esc_html($name).'</div>
      '.($sku ? '<div style="font-size:12px;color:#6b7280;">SKU: '.esc_html($sku).'</div>' : '').'
    </div>

    <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:4px 0 10px 0;">
      <tbody>
        '.$before_tr.'
        <tr>
          <td style="padding:10px;border-bottom:1px solid #eee;color:#4b5563;">Qtd atual</td>
          <td style="padding:10px;border-bottom:1px solid #eee;text-align:right;font-weight:700;font-size:16px;">'.(int)$after_qty.'</td>
        </tr>
        <tr>
          <td style="padding:10px;border-bottom:1px solid #eee;color:#4b5563;">Limiar</td>
          <td style="padding:10px;border-bottom:1px solid #eee;text-align:right;">'.(int)$threshold.'</td>
        </tr>
        <tr>
          <td style="padding:10px;color:#4b5563;">Faltam para recomposição</td>
          <td style="padding:10px;text-align:right;">'.$delta.'</td>
        </tr>
      </tbody>
    </table>

    <div style="margin-top:8px;">'.$primary_btn.$secondary_btn.'</div>

    <p style="margin:14px 0 0 0;font-size:12px;color:#6b7280;">
      Este alerta considera <strong>estoque por local</strong> e é independente das notificações padrão do WooCommerce.
    </p>
  </div>
</div>';

        // Wrap no template do Woo
        $wrapped = $body_inner;
        if ( function_exists('WC') && method_exists(\WC(),'mailer') ) {
            $mailer = \WC()->mailer();
            $wrapped = $mailer->wrap_message( wp_strip_all_tags($subject), $body_inner );
        }

        // Headers
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $cfg = $this->cfg();
        if (!empty($cfg['from_name']) && !empty($cfg['from_email']) && is_email($cfg['from_email'])) {
            $headers[] = 'From: '.$cfg['from_name'].' <'.$cfg['from_email'].'>';
            $headers[] = 'Reply-To: '.$cfg['from_name'].' <'.$cfg['from_email'].'>';
        }
        if (!empty($cfg['bcc'])) {
            foreach ( array_filter(array_map('trim', preg_split('/[,;]+/',$cfg['bcc']))) as $bcc ) {
                if (is_email($bcc)) $headers[]='Bcc: '.$bcc;
            }
        }

        // Envelope Sender
        $set_sender_cb = null;
        $sender = '';
        if ( !empty($cfg['from_email']) && is_email($cfg['from_email']) ) $sender = $cfg['from_email'];
        else {
            $admin = get_option('admin_email');
            if ( is_email($admin) ) $sender = $admin;
        }
        if ( $sender ) {
            $set_sender_cb = function( $phpmailer ) use ( $sender ) { try { $phpmailer->Sender = $sender; } catch (\Throwable $e) {} };
            add_action( 'phpmailer_init', $set_sender_cb, 99 );
        }

        $sent = function_exists('wc_mail')
            ? wc_mail($to, $subject, $wrapped, $headers)
            : wp_mail($to, $subject, $wrapped, $headers);

        if ( $set_sender_cb ) remove_action( 'phpmailer_init', $set_sender_cb, 99 );

        if ( $sent ) {
            $order->add_order_note(sprintf(
                'C2P • Alerta de estoque baixo enviado para %s — Produto %d (loc %d), qty %d (limiar %d).',
                $to, (int)$product_id, (int)$location_id, (int)$after_qty, (int)$threshold
            ));
        }
        return (bool)$sent;
    }

    /** Regras: notificar em toda redução <= limiar (sem repetir na MESMA quantidade); limpar trava se voltar a > limiar */
    private function maybe_low_stock_alert( int $product_id, int $location_id, ?int $after_qty, ?int $before_qty, array $unit, \WC_Order $order ): void {
        if ( ! is_int($after_qty) ) return;

        $threshold = $this->get_low_stock_threshold( $product_id, $location_id );
        if ( $threshold <= 0 ) return;

        // Reset quando sobe acima do limiar
        if ( $after_qty > $threshold ) { $this->clear_last_low_notified($product_id, $location_id); return; }

        // Abaixo/igual ao limiar: avisa se ainda não avisou nesta MESMA quantidade
        $last = $this->get_last_low_notified( $product_id, $location_id );
        if ( !is_int($last) || $last !== $after_qty ) {
            if ( $this->send_low_stock_email( $product_id, $unit, $location_id, $after_qty, $before_qty, $threshold, $order ) ) {
                $this->set_last_low_notified( $product_id, $location_id, $after_qty );
            }
        }
    }

    /* ===================== Envio do e-mail de pedido (mantido com bloco Prazo) ===================== */
    private function deliver_using_cfg( \WC_Order $order, string $to, array $unit, string $context, bool $mark_sent, ?string $items_override = null ): bool {
        if ( ! $this->allowed_by_trigger($context) ) return false;

        $cfg = $this->cfg();
        $is_pickup = ($unit['mode'] === 'pickup');
        if (! $is_pickup && empty($cfg['notify_delivery'])) return false;

        $order_number = $order->get_order_number();
        $order_date   = $order->get_date_created()
            ? $order->get_date_created()->date_i18n( get_option('date_format').' '.get_option('time_format') )
            : date_i18n(get_option('date_format').' '.get_option('time_format'));

        $customer = trim(
            $order->get_formatted_billing_full_name()
            ?: ($order->get_formatted_shipping_full_name()
                ?: ($order->get_billing_first_name().' '.$order->get_billing_last_name()))
        );

        $prep = $this->build_prep_block($order, $unit);

        $ctx = [
            '{unit_name}'           => $unit['name'],
            '{order_number}'        => $order_number,
            '{order_date}'          => $order_date,
            '{customer_name}'       => $customer,
            '{customer_phone}'      => $order->get_billing_phone(),
            '{customer_email}'      => $order->get_billing_email(),
            '{admin_link}'          => admin_url('post.php?post='.$order->get_id().'&action=edit'),
            '{site_name}'           => wp_specialchars_decode(get_option('blogname'), ENT_QUOTES),
            '{items_table}'         => $items_override ?: $this->items_table_basic($order),
            '{prep_deadline_block}' => $prep['html'],
            '{prep_deadline_time}'  => $prep['time'],
            '{prep_deadline_date}'  => $prep['date'],
        ];

        $subject    = $this->fill($cfg['subject'], $ctx);
        $inner_html = $this->fill($cfg['body_html'], $ctx);
        if ( strpos($inner_html, '{prep_deadline_block}') === false ) {
            $inner_html = $prep['html'] . $inner_html;
        }

        $wrapped_html = $inner_html;
        if ( function_exists('WC') && method_exists(\WC(),'mailer') ) {
            $mailer = \WC()->mailer();
            $wrapped_html = $mailer->wrap_message( wp_strip_all_tags( $subject ), $inner_html );
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if (!empty($cfg['from_name']) && !empty($cfg['from_email']) && is_email($cfg['from_email'])) {
            $headers[] = 'From: '.$cfg['from_name'].' <'.$cfg['from_email'].'>';
            $headers[] = 'Reply-To: '.$cfg['from_name'].' <'.$cfg['from_email'].'>';
        }
        if (!empty($cfg['bcc'])) {
            foreach ( array_filter(array_map('trim', preg_split('/[,;]+/',$cfg['bcc']))) as $bcc ) {
                if (is_email($bcc)) $headers[]='Bcc: '.$bcc;
            }
        }

        $set_sender_cb = null;
        $sender = '';
        if ( !empty($cfg['from_email']) && is_email($cfg['from_email']) ) $sender = $cfg['from_email'];
        else {
            $admin = get_option('admin_email');
            if ( is_email($admin) ) $sender = $admin;
        }
        if ( $sender ) {
            $set_sender_cb = function( $phpmailer ) use ( $sender ) { try { $phpmailer->Sender = $sender; } catch (\Throwable $e) {} };
            add_action( 'phpmailer_init', $set_sender_cb, 99 );
        }

        $ok = function_exists('wc_mail')
            ? wc_mail($to, $subject, $wrapped_html, $headers)
            : wp_mail($to, $subject, $wrapped_html, $headers);

        if ( $set_sender_cb ) remove_action( 'phpmailer_init', $set_sender_cb, 99 );

        if ( $ok && $mark_sent ) {
            $order->update_meta_data('_c2p_pickup_mail_sent', time());
            $order->save();
            $order->add_order_note(sprintf('C2P • E-mail enviado para %s (%s). Contexto: %s', $unit['name'], $to, $context));
        } elseif (! $ok) {
            $order->add_order_note(sprintf('C2P • Falha no envio para %s (%s). Contexto: %s', $unit['name'], $to, $context));
        }
        return (bool) $ok;
    }

    /* ===================== Disparo pós-baixa ===================== */
    public function on_reduce_order_stock( $order ) {
        $order = ($order instanceof \WC_Order) ? $order : ( function_exists('wc_get_order') ? wc_get_order($order) : null );
        if ( ! $order ) return;

        $unit        = $this->get_unit($order);
        $location_id = (int) $unit['id'];

        $summary = [ $location_id => [] ];

        foreach ( $order->get_items('line_item') as $item ) {
            if (! $item instanceof \WC_Order_Item_Product) continue;

            $qty_sold = (int) $item->get_quantity();
            if ($qty_sold <= 0) continue;

            $product_id   = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $pid = $variation_id ?: $product_id;

            $after_qty  = $this->get_location_qty( $pid, $location_id ); // saldo já reduzido
            $before_qty = is_int($after_qty) ? max(0, $after_qty + $qty_sold) : null;

            // ALERTA DE ESTOQUE BAIXO (limiar até 0, sem duplicar na mesma quantidade)
            $this->maybe_low_stock_alert( $pid, $location_id, $after_qty, $before_qty, $unit, $order );

            // Monta para e-mail de pedido (se habilitado)
            if ( ! isset($summary[$location_id][$pid]) ) {
                [$name, $sku] = $this->get_name_sku($pid);
                $summary[$location_id][$pid] = [
                    'sold'   => 0,
                    'before' => $before_qty,
                    'name'   => $name,
                    'sku'    => $sku
                ];
            }
            $summary[$location_id][$pid]['sold'] += $qty_sold;
        }

        // envio do e-mail de pedido respeitando configs
        if ( $order->get_meta('_c2p_pickup_mail_sent', true) ) return;
        $cfg  = $this->cfg();
        if ( empty($cfg['enable']) ) return;

        $is_pickup = ($unit['mode'] === 'pickup');
        if (! $is_pickup && empty($cfg['notify_delivery'])) return;

        $to = $this->resolve_recipient_email($unit);
        if ( ! $to ) { $order->add_order_note('C2P • E-mail não enviado: destinatário não configurado (after_stock).'); return; }

        $items_html = $this->items_table_from_summary_before( $summary, $location_id );
        $this->deliver_using_cfg( $order, $to, $unit, 'after_stock', true, $items_html );
    }

    /** Hooks no-op */
    public function hook_new( $order_id ) {}
    public function hook_checkout( $order_id, $posted, $order ) {}

    /** Ações no pedido */
    public function register_action( array $actions ): array {
        $actions['c2p_send_pickup_mail'] = __( 'Enviar notificação Click2Pickup (retirada)', 'c2p' );
        return $actions;
    }
    public function force_send( \WC_Order $order ) {
        $order->delete_meta_data('_c2p_pickup_mail_sent');
        $order->save();

        $unit = $this->get_unit($order);
        $to = $this->resolve_recipient_email($unit);
        if (! $to) { $order->add_order_note('C2P • E-mail manual não enviado: destinatário não configurado.'); return; }

        $this->deliver_using_cfg( $order, $to, $unit, 'manual', true, null );
    }

    /** Teste (aba E-mails) */
    public function handle_test() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die( 'forbidden' );
        check_admin_referer( 'c2p_email_test' );

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $to       = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
        $redirect = admin_url('admin.php?page=c2p-settings&tab=emails');

        if ( ! $order_id || ! $to || ! is_email($to) ) {
            wp_redirect( $redirect . '&c2p_email_test=err&c2p_email_err=parametros%20invalidos' );
            exit;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ( ! $order ) {
            wp_redirect( $redirect . '&c2p_email_test=err&c2p_email_err=pedido%20nao%20encontrado' );
            exit;
        }

        $unit = $this->get_unit( $order );
        if ( empty($unit['name']) ) $unit['name'] = 'CD Global';

        $ok = $this->deliver_using_cfg( $order, $to, $unit, 'test', false, null );
        wp_redirect( $redirect . ( $ok ? '&c2p_email_test=ok' : '&c2p_email_test=err&c2p_email_err=wp_mail' ) );
        exit;
    }
}
Order_Email_Pickup::instance();
