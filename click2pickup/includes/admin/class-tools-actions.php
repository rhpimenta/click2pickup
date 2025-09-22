<?php
namespace C2P;
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Tools_Actions
 * - Handler robusto via admin-post.php para os botões da aba "Ferramentas".
 * - Funciona mesmo que o formulário NÃO poste para options.php.
 *
 * Como usar no formulário da aba Ferramentas:
 *   <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
 *     <input type="hidden" name="action" value="c2p_tools" />
 *     <?php wp_nonce_field('c2p_tools_actions','_c2p_tools_nonce'); ?>
 *     <input type="hidden" name="c2p_tools_action" value="init_scan" />
 *     <?php submit_button('Iniciar/Retomar'); ?>
 *   </form>
 */
class Tools_Actions {
    public static function bootstrap() {
        add_action( 'admin_post_c2p_tools', [ __CLASS__, 'handle' ] );
        add_action( 'admin_post_nopriv_c2p_tools', '__return_false' );
    }

    public static function handle() {
        if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Permissão insuficiente.', 'c2p') );
        }
        check_admin_referer( 'c2p_tools_actions', '_c2p_tools_nonce' );

        $action = isset($_POST['c2p_tools_action']) ? sanitize_text_field( wp_unslash($_POST['c2p_tools_action']) ) : '';

        if ( $action === 'init_scan' ) {
            if ( class_exists('\\C2P\\Stock_Ledger') && method_exists('\\C2P\\Stock_Ledger','enqueue_init_scan') ) {
                \C2P\Stock_Ledger::enqueue_init_scan('high');
            } else {
                update_option( 'c2p_init_scan_pending', time() );
            }
            add_settings_error( 'c2p_tools', 'c2p_tools_ok', __( 'Solicitação registrada. O reprocessamento será executado em breve.', 'c2p' ), 'updated' );
        } else {
            add_settings_error( 'c2p_tools', 'c2p_tools_unk', __( 'Ação desconhecida.', 'c2p' ) );
        }

        $redirect = wp_get_referer();
        if ( ! $redirect ) { $redirect = admin_url( 'admin.php?page=click2pickup&tab=tools' ); }
        wp_safe_redirect( $redirect );
        exit;
    }
}
add_action( 'plugins_loaded', [ '\\C2P\\Tools_Actions', 'bootstrap' ] );
