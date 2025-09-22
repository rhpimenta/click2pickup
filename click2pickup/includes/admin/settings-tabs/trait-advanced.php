<?php
namespace C2P\Settings_Tabs;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Trait: Tab_Advanced
 * Conteúdo extraído de includes/class-settings.php (aba advanced).
 * Mantém a mesma assinatura para preservar comportamento.
 */
trait Tab_Advanced {
    private static function render_tab_advanced($o){

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__( 'Limites de segurança', 'c2p' ) . '</th><td>';
        echo '<label><input type="checkbox" name="c2p_settings[hard_limits_enabled]" value="1" ' . checked( 1, $o['hard_limits_enabled'], false ) . ' /> ' . esc_html__( 'Ativar limites máximos para evitar valores absurdos', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Bloqueia payloads com quantidades muito grandes (defina abaixo).', 'c2p' ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__( 'Limite máximo de quantidade', 'c2p' ) . '</th><td>';
        echo '<input type="number" class="small-text" min="0" name="c2p_settings[hard_limit_max_qty]" value="' . esc_attr( (int) $o['hard_limit_max_qty'] ) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__( 'Limite máximo de delta absoluto', 'c2p' ) . '</th><td>';
        echo '<input type="number" class="small-text" min="0" name="c2p_settings[hard_limit_max_delta]" value="' . esc_attr( (int) $o['hard_limit_max_delta'] ) . '" />';
        echo '<p class="c2p-help">' . esc_html__( 'Aplica-se quando a estratégia usa delta (ex.: delta_cd).', 'c2p' ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__( 'Dry-run padrão', 'c2p' ) . '</th><td>';
        echo '<label><input type="checkbox" name="c2p_settings[dry_run_default]" value="1" ' . checked( 1, $o['dry_run_default'], false ) . ' /> ' . esc_html__( 'Tratar como “simulação” por padrão quando o parâmetro não é informado', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Útil em homologação: as requisições globais só logam o que fariam, sem aplicar.', 'c2p' ) . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    
    }
}
