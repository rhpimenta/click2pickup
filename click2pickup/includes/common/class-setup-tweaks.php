<?php
namespace C2P;

if ( ! defined('ABSPATH') ) { exit; }

class Setup_Tweaks {
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;

        if ( ! is_admin() ) return;

        add_action('admin_init', array(__CLASS__, 'ensure_autoload_no'), 5);
        add_action('admin_init', array(__CLASS__, 'maybe_cleanup_transients'), 20);
    }

    public static function ensure_autoload_no() {
        global $wpdb;
        $row = $wpdb->get_row( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'c2p_settings' LIMIT 1" );
        if ( $row && isset($row->autoload) && $row->autoload !== 'no' ) {
            $wpdb->update( $wpdb->options, array('autoload' => 'no'), array('option_name' => 'c2p_settings') );
        }
    }

    public static function maybe_cleanup_transients() {
        $key  = 'c2p_last_transient_cleanup';
        $last = get_option($key);
        if ( $last && (time() - (int)$last) < DAY_IN_SECONDS ) return;

        global $wpdb;
        $like_local = $wpdb->esc_like('_transient_c2p_') . '%';
        $like_site  = $wpdb->esc_like('_site_transient_c2p_') . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like_local, $like_site
            )
        );

        update_option($key, time(), false);
    }
}
