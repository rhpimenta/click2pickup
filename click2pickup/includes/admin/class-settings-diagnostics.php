<?php
namespace C2P;
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Settings Diagnostics
 * - Loga POST do options.php (por aba), resultado dos hooks de update_option,
 *   e confirma execução do handler de Ferramentas.
 * - Usa WooCommerce logger (Status > Logs) com source "c2p-settings".
 *   O arquivo do dia fica como "c2p-settings-YYYY-MM-DD-...".
 */
if ( ! class_exists('\\C2P\\Settings_Diagnostics') ):
class Settings_Diagnostics {

    public static function bootstrap() : void {
        if ( is_admin() ) {
            add_action( 'load-options.php', [ __CLASS__, 'on_load_options' ] );
            add_filter( 'pre_update_option', [ __CLASS__, 'on_pre_update_option' ], 10, 3 );
            add_action( 'updated_option', [ __CLASS__, 'on_updated_option' ], 10, 3 );
            add_action( 'admin_post_c2p_tools', [ __CLASS__, 'on_admin_post_tools' ], 5 );
            add_action( 'admin_print_footer_scripts', [ __CLASS__, 'inject_console_helper' ], 99 );
        }
    }

    protected static function log( string $msg, array $ctx = [] ) : void {
        if ( function_exists('\\wc_get_logger') ) {
            \wc_get_logger()->info( $msg, array_merge( ['source' => 'c2p-settings'], $ctx ) );
        } else {
            error_log('[C2P-SETTINGS] ' . $msg . ( $ctx ? ' ' . wp_json_encode($ctx) : '' ));
        }
    }

    /** Captura o POST no options.php (Settings API) */
    public static function on_load_options() : void {
        $payload = [
            'option_page' => isset($_POST['option_page']) ? sanitize_text_field( (string) $_POST['option_page'] ) : '',
            'action'      => isset($_POST['action']) ? sanitize_text_field( (string) $_POST['action'] ) : '',
            'referer'     => isset($_POST['_wp_http_referer']) ? sanitize_text_field( (string) $_POST['_wp_http_referer'] ) : '',
            // Chaves que começam com c2p_
            'posted_keys' => array_values( array_filter( array_map( 'strval', array_keys( $_POST ?? [] ) ), function($k){
                return strpos($k, 'c2p_') === 0;
            } ) ),
        ];
        self::log('OPTIONS.PHP POST recebido', [ 'payload' => $payload ]);
    }

    /** Antes de atualizar QUALQUER option (observa opções do plugin) */
    public static function on_pre_update_option( $new_value, $option, $old_value ) {
        if ( strpos( (string) $option, 'c2p_' ) === 0 ) {
            $ctx = [
                'option'    => (string) $option,
                'old_type'  => gettype($old_value),
                'new_type'  => gettype($new_value),
                'old_size'  => is_array($old_value) ? count($old_value) : ( is_string($old_value) ? strlen($old_value) : null ),
                'new_size'  => is_array($new_value) ? count($new_value) : ( is_string($new_value) ? strlen($new_value) : null ),
                'new_keys'  => is_array($new_value) ? array_keys($new_value) : null,
            ];
            self::log('pre_update_option', $ctx );
        }
        return $new_value; // não altera nada — é só diagnóstico
    }

    /** Após atualizar qualquer option */
    public static function on_updated_option( $option, $old_value, $value ) {
        if ( strpos( (string) $option, 'c2p_' ) === 0 ) {
            $ctx = [
                'option'   => (string) $option,
                'type'     => gettype($value),
                'size'     => is_array($value) ? count($value) : ( is_string($value) ? strlen($value) : null ),
                'keys'     => is_array($value) ? array_keys($value) : null,
            ];
            self::log('updated_option', $ctx );
        }
    }

    /** Confirma que o admin-post da Ferramentas disparou */
    public static function on_admin_post_tools() : void {
        $ok = ( ! empty($_POST['_c2p_tools_nonce']) && \wp_verify_nonce( $_POST['_c2p_tools_nonce'], 'c2p_tools_actions' ) );
        $ctx = [
            'nonce_ok' => $ok ? 1 : 0,
            'action'   => isset($_POST['c2p_tools_action']) ? sanitize_text_field( (string) $_POST['c2p_tools_action'] ) : '',
        ];
        self::log('admin_post_c2p_tools recebido', $ctx );
        // Não intercepta — deixa o handler oficial continuar
    }

    /** Ajuda no console do navegador: loga envio e conta checkboxes */
    public static function inject_console_helper() : void {
        ?>
<script>
(function(){
  try{
    // console helper: loga submits de options.php
    var forms = document.querySelectorAll('form[action$="options.php"]');
    forms.forEach(function(f){
      if (f.__c2p_diag_bound) return;
      f.__c2p_diag_bound = true;
      f.addEventListener('submit', function(){
        try{
          var cbs = f.querySelectorAll('input[type="checkbox"][name^="c2p_"], input[type="checkbox"][name*="c2p_settings"]');
          var names = [];
          cbs.forEach(function(cb){ names.push(cb.name + '=' + (cb.checked?'1':'0')); });
          console.log('%c[C2P] Enviando options.php','color:#0a0', {checkboxes:names.slice(0,40), total:names.length});
        }catch(e){}
      }, true);
    });
  }catch(e){}
})();
</script>
        <?php
    }
}
\add_action( 'plugins_loaded', ['C2P\\Settings_Diagnostics','bootstrap'] );
endif;
