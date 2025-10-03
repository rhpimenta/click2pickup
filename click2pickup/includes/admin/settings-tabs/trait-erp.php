<?php
namespace C2P\Settings_Tabs;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Trait: Tab_ERP
 * Conteúdo extraído de includes/class-settings.php (aba erp).
 * Mantém a mesma assinatura para preservar comportamento.
 */
trait Tab_ERP {
    private static function render_tab_erp($o){

        $stores = self::get_stores_list();
        echo '<table class="form-table" role="presentation"><tbody>';

        // chave
        echo '<tr><th scope="row">' . esc_html__( 'Aceitar atualização global via stock_quantity', 'c2p' ) . '</th><td>';
        echo '<label><input type="checkbox" name="c2p_settings[accept_global_stock]" value="1" ' . checked( 1, $o['accept_global_stock'], false ) . ' /> ';
        echo esc_html__( 'Permitir que a API aceite stock_quantity (estoque global) de ERPs fechados.', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Quando desativado: a API ignora stock_quantity (ou retorna erro, conforme opção abaixo).', 'c2p' ) . '</p>';
        echo '</td></tr>';

        // comportamento quando desligado
        echo '<tr><th scope="row">' . esc_html__( 'Comportamento quando desativado', 'c2p' ) . '</th><td>';
        echo '<label><input type="radio" name="c2p_settings[on_global_disabled]" value="ignore_ok" ' . checked( 'ignore_ok', $o['on_global_disabled'], false ) . ' /> ' . esc_html__( 'Ignorar silenciosamente (200 OK)', 'c2p' ) . '</label><br />';
        echo '<label><input type="radio" name="c2p_settings[on_global_disabled]" value="error_422" ' . checked( 'error_422', $o['on_global_disabled'], false ) . ' /> ' . esc_html__( 'Retornar erro 422 (Unprocessable Entity)', 'c2p' ) . '</label>';
        echo '</td></tr>';

        // estratégia principal
        echo '<tr><th scope="row">' . esc_html__( 'Estratégia principal', 'c2p' ) . '</th><td>';

        echo '<label><input type="radio" name="c2p_settings[global_strategy]" value="cd_global" ' . checked( 'cd_global', $o['global_strategy'], false ) . ' /> <strong>' . esc_html__( 'Depósito Padrão (CD Global)', 'c2p' ) . '</strong></label>';
        echo '<p class="c2p-help">' . esc_html__( 'Toda atualização global é aplicada em um local único (CD Global). O total do site = soma (CD Global + demais locais).', 'c2p' ) . '</p>';
        echo '<div class="c2p-example">Ex.: total atual 60 → ERP manda 100 → CD Global vira 100 (ou +40, conforme abaixo).</div><br/>';

        echo '<label><input type="radio" name="c2p_settings[global_strategy]" value="proportional" ' . checked( 'proportional', $o['global_strategy'], false ) . ' /> <strong>' . esc_html__( 'Distribuição proporcional', 'c2p' ) . '</strong></label>';
        echo '<p class="c2p-help">' . esc_html__( 'Reparte o valor global entre os locais na mesma proporção dos estoques atuais (ou por pesos).', 'c2p' ) . '</p>';
        echo '<div class="c2p-example">Ex.: A=20, B=40 (total 60) → ERP manda 90 → A=30, B=60.</div><br/>';

        echo '<label><input type="radio" name="c2p_settings[global_strategy]" value="delta_cd" ' . checked( 'delta_cd', $o['global_strategy'], false ) . ' /> <strong>' . esc_html__( 'Aplicar delta no CD Global', 'c2p' ) . '</strong></label>';
        echo '<p class="c2p-help">' . esc_html__( 'Aplica apenas a diferença (novo total – total atual) no CD Global; demais locais permanecem.', 'c2p' ) . '</p>';
        echo '<div class="c2p-example">Ex.: total 60 → ERP 100 → +40 no CD. Se ERP 50 → –10 no CD (vide fallback abaixo).</div><br/>';

        echo '<label><input type="radio" name="c2p_settings[global_strategy]" value="overwrite_all" ' . checked( 'overwrite_all', $o['global_strategy'], false ) . ' /> <strong>' . esc_html__( 'Sobrescrever tudo', 'c2p' ) . '</strong></label>';
        echo '<p class="c2p-help">' . esc_html__( 'Zera todos os locais e define o total somente no CD Global (ou redistribui igualmente).', 'c2p' ) . '</p>';
        echo '<div class="c2p-warning">' . esc_html__( 'AVISO: alto risco para retirada por loja. Use apenas se o ERP for a fonte de verdade e não houver pickup.', 'c2p' ) . '</div>';

        echo '</td></tr>';

        // CD Global + seleção
        echo '<tr><th scope="row">' . esc_html__( 'CD Global (local)', 'c2p' ) . '</th><td>';
        $stores = self::get_stores_list();
        echo '<select name="c2p_settings[cd_store_id]">';
        echo '<option value="0">' . esc_html__( '— Selecione um local —', 'c2p' ) . '</option>';
        foreach ( $stores as $sid => $label ) {
            echo '<option value="' . (int) $sid . '" ' . selected( $sid, (int) $o['cd_store_id'], false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="c2p-help">' . esc_html__( 'Local que receberá atualizações globais (quando aplicável).', 'c2p' ) . '</p>';
        echo '</td></tr>';

        // Combinações
        echo '<tr><th scope="row">' . esc_html__( 'Opções combináveis', 'c2p' ) . '</th><td>';
        echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="c2p_settings[cd_apply_delta]" value="1" ' . checked( 1, $o['cd_apply_delta'], false ) . ' /> ' . esc_html__( 'CD Global: usar delta em vez de sobrescrever', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Para “CD Global”, ao invés de substituir o valor, aplica-se apenas a diferença no CD.', 'c2p' ) . '</p>';

        echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="c2p_settings[delta_negative_fallback]" value="1" ' . checked( 1, $o['delta_negative_fallback'], false ) . ' /> ' . esc_html__( 'Delta negativo: fallback proporcional', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Se o delta for negativo e o CD não tiver saldo suficiente, retirar o restante proporcionalmente das outras lojas. Se desmarcado, a operação falha.', 'c2p' ) . '</p>';

        echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="c2p_settings[proportional_use_weights]" value="1" ' . checked( 1, $o['proportional_use_weights'], false ) . ' /> ' . esc_html__( 'Distribuição proporcional com pesos personalizados', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Defina pesos por local (JSON). Ex.: {"3391":2,"3397":1}', 'c2p' ) . '</p>';
        echo '<textarea name="c2p_settings[proportional_weights_json]" rows="4" class="large-text" placeholder="{&quot;3391&quot;:2,&quot;3397&quot;:1}">' . esc_textarea( $o['proportional_weights_json'] ) . '</textarea>';

        echo '<label style="display:block;margin:10px 0"><input type="checkbox" name="c2p_settings[protect_pickup_only]" value="1" ' . checked( 1, $o['protect_pickup_only'], false ) . ' /> ' . esc_html__( 'Não alterar lojas marcadas como “somente retirada”', 'c2p' ) . '</label>';

        echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="c2p_settings[allow_low_stock_global]" value="1" ' . checked( 1, $o['allow_low_stock_global'], false ) . ' /> ' . esc_html__( 'Permitir sobrescrever limiar global (CD Global)', 'c2p' ) . '</label>';
        echo '<p class="c2p-help">' . esc_html__( 'Aceitar campo low_stock_global no payload para definir limiar no CD Global. Não altera limiares por loja.', 'c2p' ) . '</p>';

        echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="c2p_settings[log_detailed]" value="1" ' . checked( 1, $o['log_detailed'], false ) . ' /> ' . esc_html__( 'Log detalhado das aplicações globais', 'c2p' ) . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
    
    }
}
