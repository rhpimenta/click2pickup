<?php
/**
 * Click2Pickup - Settings Tab: General
 * 
 * ✅ v2.0.0: Type hints, escape adequado, estrutura organizada
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-10-09 13:30:28 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 13:30: ✅ MELHORADO: Type hints na assinatura
 * - 2025-10-09 13:30: ✅ MELHORADO: Estrutura HTML organizada
 * - 2025-10-09 13:30: ✅ MELHORADO: Escape adequado
 * - 2025-10-09 13:30: ✅ MELHORADO: Info box com instruções
 */

namespace C2P\Settings_Tabs;

if (!defined('ABSPATH')) exit;

trait Tab_General {
    
    /**
     * Render General Settings Tab
     * 
     * @param array $settings Current settings
     */
    private static function render_tab_general(array $settings): void {
        // ✅ Helper para valores com defaults
        $def = function(string $key, string $default = '') use ($settings): string {
            return isset($settings[$key]) ? (string)$settings[$key] : $default;
        };
        
        ?>
        <h2 class="title"><?php esc_html_e('Configurações Gerais', 'c2p'); ?></h2>
        
        <table class="form-table" role="presentation">
            <tbody>
                <!-- ========================================
                     NOTAS INTERNAS
                     ======================================== -->
                <tr>
                    <th scope="row">
                        <label for="c2p_general_notes">
                            <?php esc_html_e('Notas internas', 'c2p'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea 
                            name="c2p_settings[general_notes]" 
                            id="c2p_general_notes"
                            rows="8" 
                            class="large-text code" 
                            placeholder="<?php esc_attr_e('Use este campo para anotações da equipe sobre a configuração do Click2Pickup...', 'c2p'); ?>"><?php echo esc_textarea($def('general_notes')); ?></textarea>
                        
                        <p class="description">
                            <?php esc_html_e('Este campo não altera comportamentos — serve apenas para documentação interna da equipe.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- ========================================
                     VERSÃO DO PLUGIN
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Versão do plugin', 'c2p'); ?></th>
                    <td>
                        <code style="font-size:14px;padding:4px 8px;background:#f0f0f0;border-radius:4px;">
                            <?php 
                            if (defined('C2P_VERSION')) {
                                echo esc_html(C2P_VERSION);
                            } else {
                                esc_html_e('Não detectada', 'c2p');
                            }
                            ?>
                        </code>
                        <p class="description">
                            <?php esc_html_e('Versão atual do Click2Pickup instalado.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- ========================================
                     AMBIENTE
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Ambiente', 'c2p'); ?></th>
                    <td>
                        <?php
                        $is_debug = defined('WP_DEBUG') && WP_DEBUG;
                        $is_dev = defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'development';
                        
                        if ($is_debug || $is_dev) {
                            echo '<span style="display:inline-block;padding:4px 12px;background:#fef3c7;color:#92400e;border-radius:4px;font-weight:600;font-size:13px;">';
                            echo '⚙️ ' . esc_html__('Desenvolvimento / Debug', 'c2p');
                            echo '</span>';
                        } else {
                            echo '<span style="display:inline-block;padding:4px 12px;background:#d1fae5;color:#065f46;border-radius:4px;font-weight:600;font-size:13px;">';
                            echo '✅ ' . esc_html__('Produção', 'c2p');
                            echo '</span>';
                        }
                        ?>
                        
                        <p class="description">
                            <?php 
                            if ($is_debug || $is_dev) {
                                esc_html_e('Modo de desenvolvimento ativo. Logs detalhados estão habilitados.', 'c2p'); 
                            } else {
                                esc_html_e('Modo de produção. Apenas erros críticos são registrados.', 'c2p'); 
                            }
                            ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- ========================================
             INFORMAÇÕES ADICIONAIS
             ======================================== -->
        <div style="background:#f0f9ff;border-left:4px solid #0ea5e9;padding:16px;margin-top:20px;border-radius:6px;max-width:980px;">
            <h4 style="margin:0 0 12px;color:#0369a1;">
                💡 <?php esc_html_e('Sobre as notas internas', 'c2p'); ?>
            </h4>
            <p style="margin:0;color:#374151;line-height:1.6;">
                <?php esc_html_e('Use o campo de notas para documentar:', 'c2p'); ?>
            </p>
            <ul style="margin:8px 0 0;padding-left:20px;color:#374151;line-height:1.8;">
                <li><?php esc_html_e('Configurações específicas da sua loja', 'c2p'); ?></li>
                <li><?php esc_html_e('Integrações com ERPs externos', 'c2p'); ?></li>
                <li><?php esc_html_e('Responsáveis por cada configuração', 'c2p'); ?></li>
                <li><?php esc_html_e('Histórico de mudanças importantes', 'c2p'); ?></li>
                <li><?php esc_html_e('Contatos de suporte técnico', 'c2p'); ?></li>
            </ul>
        </div>
        
        <?php
    }
}