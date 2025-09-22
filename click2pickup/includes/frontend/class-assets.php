<?php
namespace C2P;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Frontend_Assets – enfileira CSS/JS apenas quando necessário
 * (carrinho/checkout ou quando o shortcode [c2p_cart] é renderizado).
 */
class Frontend_Assets {
    private static $did_enqueue = false;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;

        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue'), 20);
    }

    public static function maybe_enqueue() {
        if ( is_admin() || ( defined('REST_REQUEST') && REST_REQUEST ) || ( defined('DOING_AJAX') && DOING_AJAX ) ) {
            return;
        }

        $in_cart_context = false;

        if ( class_exists(__NAMESPACE__ . '\\Runtime_Guard') ) {
            $in_cart_context = Runtime_Guard::is_cart_context();
        } else {
            $is_cart     = function_exists('is_cart') ? is_cart() : false;
            $is_checkout = function_exists('is_checkout') ? is_checkout() : false;
            $in_cart_context = $is_cart || $is_checkout;
        }

        if ( $in_cart_context ) {
            self::enqueue();
        }
    }

    public static function enqueue() {
        if ( self::$did_enqueue ) return;
        self::$did_enqueue = true;

        $ver = defined('C2P_VERSION') ? C2P_VERSION : '1.1.0';

        $css = self::first_existing(array(
            'assets/css/frontend.min.css',
            'assets/css/frontend.css',
            'assets/frontend.min.css',
            'assets/frontend.css',
        ));
        if ( $css ) {
            wp_enqueue_style( 'c2p-frontend', self::url($css), array(), $ver );
        }

        $js = self::first_existing(array(
            'assets/js/frontend.min.js',
            'assets/js/frontend.js',
            'assets/frontend.min.js',
            'assets/frontend.js',
        ));
        if ( $js ) {
            wp_enqueue_script( 'c2p-frontend', self::url($js), array('jquery'), $ver, true );
            wp_localize_script( 'c2p-frontend', 'C2P_FRONTEND', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('c2p_frontend'),
            ));
        }
    }

    private static function base_dir() {
        if ( defined('C2P_PATH') ) return rtrim(C2P_PATH, '/\\');
        return rtrim( dirname( dirname(__DIR__) ), '/\\' );
    }

    private static function base_url() {
        if ( defined('C2P_URL') ) return rtrim(C2P_URL, '/\\');
        $includes_frontend_url = plugins_url('', __FILE__);
        return dirname( dirname( $includes_frontend_url ) );
    }

    private static function url($relative) {
        $relative = ltrim((string) $relative, '/\\');
        return self::base_url() . '/' . $relative;
    }

    private static function first_existing($candidates) {
        $base = self::base_dir();
        foreach ( (array) $candidates as $rel ) {
            $rel = ltrim((string) $rel, '/\\');
            $abs = $base . '/' . $rel;
            if ( file_exists($abs) ) return $rel;
        }
        return null;
    }
}
