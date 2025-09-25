<?php
/**
 * Configurações do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Settings_Admin {
    
    /**
     * Construtor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Registra as configurações
     */
    public function register_settings() {
        register_setting('c2p_settings', 'c2p_general_settings');
        register_setting('c2p_settings', 'c2p_stock_settings');
        register_setting('c2p_settings', 'c2p_notification_settings');
    }
    
    /**
     * Exibe a página de configurações
     */
    public function display_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Configurações Click2Pickup', 'click2pickup'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('c2p_settings'); ?>
                
                <h2><?php esc_html_e('Configurações Gerais', 'click2pickup'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Modo de Operação', 'click2pickup'); ?></th>
                        <td>
                            <select name="c2p_general_settings[mode]">
                                <option value="multi_location"><?php esc_html_e('Multi-local', 'click2pickup'); ?></option>
                                <option value="single_location"><?php esc_html_e('Local único', 'click2pickup'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}