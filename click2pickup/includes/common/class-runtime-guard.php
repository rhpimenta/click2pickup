<?php
namespace C2P;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Runtime_Guard
 * Descobre o contexto da requisição (REST, AJAX, ADMIN, FRONT) e
 * sinaliza quando o shortcode [c2p_cart] está ativo.
 */
class Runtime_Guard {
    private static $booted = false;
    private static $is_rest = false;
    private static $is_ajax = false;
    private static $shortcode_active = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;

        self::$is_ajax = ( defined('DOING_AJAX') && DOING_AJAX );

        self::$is_rest = ( defined('REST_REQUEST') && REST_REQUEST );
        if ( ! self::$is_rest && isset($_SERVER['REQUEST_URI']) ) {
            self::$is_rest = ( strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false );
        }

        add_filter('do_shortcode_tag', function($output, $tag){
            if ($tag === 'c2p_cart') {
                self::$shortcode_active = true;
            }
            return $output;
        }, 10, 2);
    }

    public static function is_rest() { return self::$is_rest; }
    public static function is_ajax() { return self::$is_ajax; }
    public static function is_admin() { return is_admin(); }

    public static function is_cart_context() {
        $is_cart     = function_exists('is_cart') ? is_cart() : false;
        $is_checkout = function_exists('is_checkout') ? is_checkout() : false;
        return $is_cart || $is_checkout || self::$shortcode_active;
    }

    public static function is_wc_products_route($request) {
        if ( ! $request || ! method_exists($request, 'get_route') ) return false;
        $route = (string) $request->get_route();
        return ( strpos($route, '/wc/') !== false && strpos($route, '/products') !== false );
    }
}
