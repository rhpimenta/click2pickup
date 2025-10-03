<?php
namespace C2P\Settings_Tabs;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Trait: Tab_General
 * Conteúdo extraído de includes/class-settings.php (aba general).
 * Mantém a mesma assinatura para preservar comportamento.
 */
trait Tab_General {
    private static function render_tab_general($o){

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Notas internas', 'c2p' ) . '</th>';
        echo '<td>';
        echo '<textarea name="c2p_settings[general_notes]" rows="5" class="large-text" placeholder="' . esc_attr__( 'Use este campo para anotações da equipe sobre a configuração do Click2Pickup.', 'c2p' ) . '">'
             . esc_textarea( $o['general_notes'] ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Este campo não altera comportamentos — serve apenas para documentação interna.', 'c2p' ) . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    
    }
}
