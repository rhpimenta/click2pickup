<?php
/**
 * Click2Pickup – Núcleo do plugin (classe principal)
 */

namespace C2P;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Helpers comuns */
$common_dir = __DIR__ . '/common';
if ( file_exists( $common_dir . '/class-runtime-guard.php' ) ) require_once $common_dir . '/class-runtime-guard.php';
if ( file_exists( $common_dir . '/class-profiler.php' ) )      require_once $common_dir . '/class-profiler.php';
if ( file_exists( $common_dir . '/class-setup-tweaks.php' ) )  require_once $common_dir . '/class-setup-tweaks.php';

/** Frontend assets (novo) */
$frontend_dir = __DIR__ . '/frontend';
if ( file_exists( $frontend_dir . '/class-assets.php' ) )      require_once $frontend_dir . '/class-assets.php';

class Plugin {

    private static $instance = null;

    /** Cache interno para localização de assets (sem typed property) */
    private static $asset_cache = array();

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        if ( class_exists(__NAMESPACE__ . '\\Runtime_Guard') ) Runtime_Guard::boot();
        if ( class_exists(__NAMESPACE__ . '\\Profiler') )      Profiler::boot();
        if ( class_exists(__NAMESPACE__ . '\\Setup_Tweaks') )  Setup_Tweaks::boot();
        if ( class_exists(__NAMESPACE__ . '\\Frontend_Assets') ) Frontend_Assets::boot();

        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
    }

    public function admin_assets( $hook_suffix ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        $is_c2p_store = ( $screen && ! empty($screen->post_type) && $screen->post_type === 'c2p_store' );

        $current_pages = array( 'c2p-dashboard', 'c2p-dashboard-home', 'c2p-settings' );
        $legacy_pages  = array( 'rpsp-dashboard', 'rpsp-dashboard-home', 'rpsp-settings' );

        $page = isset($_GET['page']) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        $is_plugin_page =
            in_array( $page, $current_pages, true )
            || in_array( $page, $legacy_pages, true )
            || ( $screen && in_array( (string) $screen->id, array(
                    'toplevel_page_c2p-dashboard',
                    'c2p_page_c2p-settings',
                    'c2p_page_c2p-dashboard-home',
                ), true ) );

        if ( ! $is_c2p_store && ! $is_plugin_page ) return;

        if ( $is_c2p_store ) {
            if ( function_exists('wp_enqueue_media') ) wp_enqueue_media();
            wp_enqueue_style( 'wp-components' );
        }

        $ver = defined('C2P_VERSION') ? C2P_VERSION : '1.1.0';

        $admin_css = $this->first_existing_cached( 'admin_css', array(
            'includes/admin/locations/assets/admin.min.css',
            'includes/admin/locations/assets/admin.css',
            'assets/admin.min.css',
            'assets/admin.css',
            'assets/css/admin.min.css',
            'assets/css/admin.css',
            'assets/css/c2p-admin.min.css',
            'assets/css/c2p-admin.css',
        ) );
        if ( $admin_css ) {
            wp_enqueue_style( 'c2p-admin', $this->url( $admin_css ), array(), $ver );
        }

        $admin_js = $this->first_existing_cached( 'admin_js', array(
            'assets/admin.min.js',
            'assets/admin.js',
            'assets/js/admin.min.js',
            'assets/js/admin.js',
            'assets/js/c2p-admin.min.js',
            'assets/js/c2p-admin.js',
        ) );
        if ( $admin_js ) {
            wp_enqueue_script( 'c2p-admin', $this->url( $admin_js ), array('jquery'), $ver, true );
        }
    }

    private function first_existing_cached( $key, $candidates ) {
        if ( isset(self::$asset_cache[$key]) ) {
            return self::$asset_cache[$key] ? self::$asset_cache[$key] : null;
        }
        $found = $this->first_existing( $candidates );
        self::$asset_cache[$key] = $found ? $found : '';
        return $found;
    }

    private function first_existing( $candidates ) {
        $base = $this->base_dir();
        foreach ( (array) $candidates as $rel ) {
            $rel = ltrim((string) $rel, '/\\');
            $abs = $base . '/' . $rel;
            if ( file_exists($abs) ) return $rel;
        }
        return null;
    }

    private function url( $relative ) {
        $relative = ltrim((string) $relative, '/\\');
        if ( defined('C2P_URL') ) return rtrim(C2P_URL, '/\\') . '/' . $relative;
        $root_url = $this->base_url();
        return rtrim($root_url, '/\\') . '/' . $relative;
    }

    private function base_dir() {
        if ( defined('C2P_PATH') ) return rtrim(C2P_PATH, '/\\');
        return rtrim( dirname(__DIR__), '/\\' );
    }

    private function base_url() {
        if ( defined('C2P_URL') ) return rtrim(C2P_URL, '/\\');
        $includes_url = plugins_url('', __FILE__);
        $root_url     = dirname( $includes_url );
        return trailingslashit( $root_url );
    }
}

add_action( 'plugins_loaded', function () {
    Plugin::instance();
} );
