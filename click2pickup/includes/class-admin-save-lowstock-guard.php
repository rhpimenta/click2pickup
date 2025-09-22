<?php
namespace C2P;
if ( ! defined('ABSPATH') ) { exit; }

class Admin_Save_LowStock_Guard {
    public static function bootstrap() {
        add_action('save_post_product', [__CLASS__,'on_product_save'], 10, 3);
        add_filter('pre_wp_mail', [__CLASS__,'maybe_block_mail'], 10, 2);
        add_filter('woocommerce_email_enabled_low_stock',  [__CLASS__,'disable_wc_email_if_admin'], 10, 2);
        add_filter('woocommerce_email_enabled_no_stock',   [__CLASS__,'disable_wc_email_if_admin'], 10, 2);
        add_filter('woocommerce_email_enabled_backorder',  [__CLASS__,'disable_wc_email_if_admin'], 10, 2);
    }
    protected static function log( $msg, $ctx=array() ) {
        if ( function_exists('wc_get_logger') ) {
            \wc_get_logger()->info('[C2P Core Guard] ' . $msg, array_merge(['source'=>'c2p-core-guard'], $ctx));
        }
    }
    public static function on_product_save( $post_id, $post, $update ) {
        // Só marca flag se realmente for em ADMIN UI (evita checkout/front disparando bloqueio)
        if ( ! \is_admin() || \wp_doing_cron() ) { return; }
        // Ignora chamadas ajax/admin-ajax (ex: bulk, metaboxes) — queremos bloquear só no post.php / post-new.php
        $req = $_SERVER['REQUEST_URI'] ?? '';
        if ( stripos($req, '/wp-admin/post.php') === false && stripos($req, '/wp-admin/post-new.php') === false ) {
            return;
        }
        \set_transient('c2p_core_guard_admin_save_flag', time(), 30); // 30s
        self::log('Flag admin_save (UI) setado', array('post_id'=>$post_id, 'update'=>$update ? 1 : 0, 'uri'=>$req));
    }
    protected static function is_admin_save_active_now() {
        // Bloqueia apenas se: (a) estamos em ADMIN request agora, e (b) houve save recente
        if ( ! \is_admin() || \wp_doing_cron() ) { return false; }
        $t = \get_transient('c2p_core_guard_admin_save_flag');
        return $t ? true : false;
    }
    public static function maybe_block_mail( $short_circuit, $atts ) {
        if ( ! self::is_admin_save_active_now() ) { return $short_circuit; }
        $subject = isset($atts['subject']) ? $atts['subject'] : '';
        $is_low = ( stripos($subject, 'estoque baixo') !== false ) || ( stripos($subject, 'low stock') !== false );
        if ( $is_low ) {
            self::log('Bloqueado via pre_wp_mail (admin save UI)', array('subject'=>$subject));
            return false;
        }
        return $short_circuit;
    }
    public static function disable_wc_email_if_admin( $enabled, $product ) {
        if ( self::is_admin_save_active_now() ) {
            self::log('Desabilitado email Woo durante admin save (UI).', array('product_id'=> is_object($product)? $product->get_id() : null ));
            return false;
        }
        return $enabled;
    }
}
\add_action( 'plugins_loaded', ['C2P\\Admin_Save_LowStock_Guard', 'bootstrap'] );
