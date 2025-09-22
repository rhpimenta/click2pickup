<?php
namespace C2P;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Profiler leve – escreve tempos quando C2P_PROFILE === true.
 * Usa error_log ou arquivo dedicado (se C2P_PROFILE_LOG definido).
 */
class Profiler {
    private static $booted = false;
    private static $t0 = 0.0;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;

        if ( ! defined('C2P_PROFILE') || C2P_PROFILE !== true ) {
            return;
        }

        self::$t0 = microtime(true);

        add_action('shutdown', function(){
            $elapsed = number_format( (microtime(true) - self::$t0) * 1000, 2 );
            self::log('[C2P][PROFILE] total_request_ms=' . $elapsed);
        });

        add_filter('rest_request_before_callbacks', function($response, $handler, $request){
            if ( class_exists(__NAMESPACE__ . '\\Runtime_Guard') && Runtime_Guard::is_wc_products_route($request) ) {
                self::log('[C2P][PROFILE] hitting WC products route: ' . $request->get_route());
            }
            return $response;
        }, 10, 3);
    }

    private static function log($line) {
        $msg = (string) $line;
        if ( defined('C2P_PROFILE_LOG') && C2P_PROFILE_LOG ) {
            $ok = @error_log($msg . PHP_EOL, 3, C2P_PROFILE_LOG);
            if ( $ok ) return;
        }
        error_log($msg);
    }
}
