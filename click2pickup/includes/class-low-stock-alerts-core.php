<?php
/**
 * Click2Pickup - Low Stock Alerts Core
 * 
 * ✅ v2.0: SQL escape, validações de segurança, email sanitizado
 * 
 * Alerta de estoque baixo por LOCAL, usando template padrão do Woo nos e-mails.
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-10-09 12:12:31 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 12:12: ✅ CORRIGIDO: SQL escape em todas as queries
 * - 2025-10-09 12:12: ✅ CORRIGIDO: Validação de email antes de enviar
 * - 2025-10-09 12:12: ✅ CORRIGIDO: Threshold sempre >= 0
 * - 2025-10-09 12:12: ✅ MELHORADO: Error handling com try-catch
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

class Low_Stock_Alerts_Core {
    private static $instance;

    /**
     * Meta para debouncing por local: _c2p_low_alert_{location_id}
     */
    private function flag_meta_key(int $location_id): string {
        return '_c2p_low_alert_' . absint($location_id);
    }

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Evento detalhado (preferencial)
        add_action('c2p_multistock_changed', [$this, 'on_change'], 10, 6);

        // Fallback a partir do sincronizador do pedido
        add_action('c2p_after_location_stock_change', [$this, 'on_after_location_stock_change'], 10, 4);
    }

    /**
     * Lê config dos alertas
     */
    private function cfg(): array {
        $o = \C2P\Settings::get_options();
        return [
            'enabled'   => !empty($o['email_lowstock_enabled']),
            'subject'   => (string)($o['email_lowstock_subject'] ?? 'Alerta: Estoque baixo — {product_name}'),
            'body_html' => (string)($o['email_lowstock_body_html'] ?? '<h2>Alerta de estoque baixo</h2><p>O produto abaixo atingiu o estoque mínimo configurado neste local.</p><ul><li><strong>Produto:</strong> {product_name} (SKU: {sku})</li><li><strong>Local:</strong> {location_name}</li><li><strong>Quantidade atual:</strong> {new_qty}</li><li><strong>Estoque mínimo:</strong> {threshold}</li></ul>'),
            'bcc'       => (string)($o['email_lowstock_bcc'] ?? ''),
        ];
    }

    /**
     * ✅ CORRIGIDO: Retorna valores COM escape
     */
    private function table_col(): array {
        global $wpdb;
        
        $table = C2P::table();
        $col = C2P::col_store();
        
        // ✅ SEGURANÇA: Escape de nomes
        return [esc_sql($table), esc_sql($col)];
    }

    /**
     * ✅ CORRIGIDO: SQL com escape adequado
     */
    private function get_qty(int $product_id, int $location_id): int {
        global $wpdb;
        
        list($table, $col) = $this->table_col();
        
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM `{$table}` WHERE product_id=%d AND `{$col}`=%d LIMIT 1",
            $product_id, 
            $location_id
        ));
        
        return is_numeric($v) ? max(0, (int)$v) : 0;
    }

    /**
     * ✅ CORRIGIDO: SQL com escape + validação de threshold
     */
    private function get_threshold(int $product_id, int $location_id): int {
        global $wpdb;
        
        list($table, $col) = $this->table_col();
        
        $thr = $wpdb->get_var($wpdb->prepare(
            "SELECT low_stock_amount FROM `{$table}` WHERE product_id=%d AND `{$col}`=%d LIMIT 1",
            $product_id, 
            $location_id
        ));
        
        // ✅ VALIDAÇÃO: Garante que threshold seja >= 0
        $thr = is_numeric($thr) ? max(0, (int)$thr) : 0;

        // Fallback para threshold do WooCommerce
        if ($thr <= 0 && function_exists('wc_get_product')) {
            $p = wc_get_product($product_id);
            if ($p) {
                $woo_thr = (int) wc_get_low_stock_amount($p);
                if ($woo_thr > 0) {
                    $thr = $woo_thr;
                }
            }
        }
        
        return max(0, $thr);
    }

    /**
     * ✅ CORRIGIDO: Validação rigorosa de email
     */
    private function get_store_email(int $store_id): ?string {
        $meta_keys = [
            'c2p_email',
            'store_email',
            'email',
            'rpws_store_email',
            'contact_email',
            'c2p_store_email'
        ];
        
        foreach ($meta_keys as $k) {
            $v = trim((string)get_post_meta($store_id, $k, true));
            
            // ✅ VALIDAÇÃO: Sanitiza e valida email
            $v = sanitize_email($v);
            
            if ($v && is_email($v)) {
                return $v;
            }
        }
        
        return null;
    }

    /**
     * Nome do produto + SKU
     */
    private function product_name_sku(int $product_id): array {
        $name = 'Produto #' . $product_id;
        $sku = '';
        
        if (function_exists('wc_get_product')) {
            $p = wc_get_product($product_id);
            if ($p) {
                $name = $p->get_formatted_name();
                $sku = $p->get_sku();
            }
        }
        
        return [$name, $sku];
    }

    /**
     * Nome do local
     */
    private function location_name(int $location_id): string {
        $post = get_post($location_id);
        return ($post && $post->post_status === 'publish') 
            ? $post->post_title 
            : ('Local #' . $location_id);
    }

    /**
     * ✅ CORRIGIDO: Validação de email antes de enviar
     */
    private function send_alert(int $product_id, int $location_id, int $new_qty, int $thr, string $reason = ''): bool {
        $cfg = $this->cfg();
        if (!$cfg['enabled']) {
            return false;
        }

        $to = $this->get_store_email($location_id);
        
        // ✅ VALIDAÇÃO: Verifica novamente se email é válido
        if (!$to || !is_email($to)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[C2P][Low_Stock_Alerts] Email inválido para location #{$location_id}");
            }
            return false;
        }

        [$pname, $sku] = $this->product_name_sku($product_id);
        $lname = $this->location_name($location_id);

        $place = [
            '{product_id}'   => (string)$product_id,
            '{product_name}' => $pname,
            '{sku}'          => $sku ?: '',
            '{location_id}'  => (string)$location_id,
            '{location_name}' => $lname,
            '{new_qty}'      => (string)$new_qty,
            '{threshold}'    => (string)$thr,
            '{reason}'       => $reason,
            '{site_name}'    => wp_specialchars_decode(get_option('blogname'), ENT_QUOTES),
        ];

        $subject = strtr($cfg['subject'], $place);

        // Corpo configurável + wrap no template do Woo
        $inner = strtr($cfg['body_html'], $place);
        $wrapped = $inner;
        
        if (function_exists('WC') && method_exists(\WC(), 'mailer')) {
            $wrapped = \WC()->mailer()->wrap_message(__('Alerta de estoque baixo', 'c2p'), $inner);
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        if (!empty($cfg['bcc'])) {
            foreach (array_filter(array_map('trim', preg_split('/[,;]+/', $cfg['bcc']))) as $bcc) {
                // ✅ VALIDAÇÃO: Sanitiza BCC
                $bcc = sanitize_email($bcc);
                if (is_email($bcc)) {
                    $headers[] = 'Bcc: ' . $bcc;
                }
            }
        }

        try {
            return function_exists('wc_mail') 
                ? wc_mail($to, $subject, $wrapped, $headers)
                : wp_mail($to, $subject, $wrapped, $headers);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P][Low_Stock_Alerts] Erro ao enviar email: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * ✅ Handler preferencial com FILTRO por contexto
     * 
     * REGRAS:
     * 1. Só envia se contexto for VENDA (order_reduce/order_restore)
     * 2. Envia SEMPRE que qty <= estoque mínimo (não só ao cruzar)
     * 3. Evita duplicatas na MESMA quantidade
     */
    public function on_change(int $product_id, int $location_id, int $old_qty, int $new_qty, int $threshold_local, string $ctx = ''): void {
        try {
            // ✅ FILTRO: Só vendas
            if (!in_array($ctx, ['order_reduce', 'order_restore'], true)) {
                return;
            }

            $thr = max(0, (int)$threshold_local);
            if ($thr <= 0) {
                $thr = $this->get_threshold($product_id, $location_id);
            }

            // ✅ Envia SEMPRE que estoque <= estoque mínimo
            if ($new_qty <= $thr && $old_qty !== $new_qty) {
                $flag = $this->get_flag($product_id, $location_id);
                
                // Evita duplicata na MESMA quantidade exata
                $last_notified_qty = isset($flag['last_qty']) ? (int)$flag['last_qty'] : -1;
                
                if ($last_notified_qty !== $new_qty) {
                    $ok = $this->send_alert($product_id, $location_id, $new_qty, $thr, $ctx);
                    $this->set_flag($product_id, $location_id, $new_qty, $thr, $ok ? time() : 0);
                }
            } elseif ($new_qty > $thr) {
                // Saiu da zona de alerta → limpa flag
                $this->clear_flag($product_id, $location_id);
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P][Low_Stock_Alerts][on_change] ' . $e->getMessage());
            }
        }
    }

    /**
     * Fallback: sem old_qty exato; evita duplicações usando meta por local
     */
    public function on_after_location_stock_change(array $product_ids, int $location_id, string $op, $order_id): void {
        try {
            foreach ($product_ids as $pid) {
                $pid = (int)$pid;
                $thr = $this->get_threshold($pid, $location_id);
                if ($thr <= 0) continue;

                $qty = $this->get_qty($pid, $location_id);
                $flag = $this->get_flag($pid, $location_id);

                if ($qty <= $thr) {
                    // Só alerta se "antes" não estava em alerta
                    if (empty($flag) || (isset($flag['last_qty']) && (int)$flag['last_qty'] > $thr)) {
                        $ok = $this->send_alert($pid, $location_id, $qty, $thr, 'order_' . $op);
                        $this->set_flag($pid, $location_id, $qty, $thr, $ok ? time() : 0);
                    }
                } else {
                    // Saiu da zona de alerta → limpa flag
                    $this->clear_flag($pid, $location_id);
                }
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[C2P][Low_Stock_Alerts][fallback] ' . $e->getMessage());
            }
        }
    }

    /**
     * === Debounce helpers (flag por produto/local) ===
     */
    private function get_flag(int $product_id, int $location_id): array {
        $k = $this->flag_meta_key($location_id);
        $raw = get_post_meta($product_id, $k, true);
        return is_array($raw) ? $raw : [];
    }

    private function set_flag(int $product_id, int $location_id, int $last_qty, int $thr, int $ts): void {
        $k = $this->flag_meta_key($location_id);
        update_post_meta($product_id, $k, [
            'last_qty' => max(0, (int)$last_qty),
            'thr'      => max(0, (int)$thr),
            'ts'       => max(0, (int)$ts),
        ]);
    }

    private function clear_flag(int $product_id, int $location_id): void {
        delete_post_meta($product_id, $this->flag_meta_key($location_id));
    }
}

// Bootstrap
Low_Stock_Alerts_Core::instance();