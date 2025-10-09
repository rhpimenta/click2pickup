<?php
/**
 * Click2Pickup - Settings Tab: Advanced
 * 
 * ✅ v2.0: Type hints, escape adequado, validações
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-10-09 13:17:44 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 13:17: ✅ MELHORADO: Type hints na assinatura
 * - 2025-10-09 13:17: ✅ MELHORADO: Estrutura HTML organizada
 * - 2025-10-09 13:17: ✅ MELHORADO: Validação de valores
 * - 2025-10-09 13:17: ✅ MELHORADO: Escape adequado
 */

namespace C2P\Settings_Tabs;

if (!defined('ABSPATH')) exit;

trait Tab_Advanced {
    
    /**
     * Render Advanced Settings Tab
     * 
     * @param array $settings Current settings
     */
    private static function render_tab_advanced(array $settings): void {
        // ✅ Helper para valores com defaults
        $def = function(string $key, $default = 0) use ($settings) {
            return isset($settings[$key]) ? $settings[$key] : $default;
        };
        
        $is_on = function($value): bool {
            return !empty($value) && ($value === '1' || $value === 1 || $value === true);
        };
        
        ?>
        <h2 class="title"><?php esc_html_e('Configurações Avançadas', 'c2p'); ?></h2>
        
        <table class="form-table" role="presentation">
            <tbody>
                <!-- ========================================
                     LIMITES DE SEGURANÇA
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Limites de segurança', 'c2p'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="c2p_settings[hard_limits_enabled]" 
                                   value="1" 
                                   <?php checked($is_on($def('hard_limits_enabled', 0))); ?> />
                            <?php esc_html_e('Ativar limites máximos para evitar valores absurdos', 'c2p'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Bloqueia payloads com quantidades muito grandes (defina abaixo).', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- ========================================
                     LIMITE MÁXIMO DE QUANTIDADE
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Limite máximo de quantidade', 'c2p'); ?></th>
                    <td>
                        <input type="number" 
                               class="small-text" 
                               name="c2p_settings[hard_limit_max_qty]" 
                               value="<?php echo esc_attr(absint($def('hard_limit_max_qty', 100000))); ?>" 
                               min="0" 
                               max="999999" />
                        <p class="description">
                            <?php esc_html_e('Quantidade máxima permitida por produto (padrão: 100.000).', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- ========================================
                     LIMITE MÁXIMO DE DELTA
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Limite máximo de delta absoluto', 'c2p'); ?></th>
                    <td>
                        <input type="number" 
                               class="small-text" 
                               name="c2p_settings[hard_limit_max_delta]" 
                               value="<?php echo esc_attr(absint($def('hard_limit_max_delta', 50000))); ?>" 
                               min="0" 
                               max="999999" />
                        <p class="description">
                            <?php esc_html_e('Aplica-se quando a estratégia usa delta (ex.: delta_cd). Padrão: 50.000.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- ========================================
                     DRY-RUN PADRÃO
                     ======================================== -->
                <tr>
                    <th scope="row"><?php esc_html_e('Dry-run padrão', 'c2p'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="c2p_settings[dry_run_default]" 
                                   value="1" 
                                   <?php checked($is_on($def('dry_run_default', 0))); ?> />
                            <?php esc_html_e('Tratar como "simulação" por padrão quando o parâmetro não é informado', 'c2p'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Útil em homologação: as requisições globais só logam o que fariam, sem aplicar.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- ========================================
             INFORMAÇÕES ADICIONAIS
             ======================================== -->
        <div class="c2p-info-box" style="background:#f0f9ff; border-left:4px solid #0ea5e9; padding:16px; margin-top:20px; border-radius:6px; max-width:980px;">
            <h4 style="margin:0 0 10px; color:#0369a1;">
                💡 <?php esc_html_e('Sobre os limites de segurança', 'c2p'); ?>
            </h4>
            <ul style="margin:0; padding-left:20px; color:#374151;">
                <li>
                    <strong><?php esc_html_e('Limite de quantidade:', 'c2p'); ?></strong>
                    <?php esc_html_e('Bloqueia tentativas de atualizar estoque com valores muito altos (ex.: 1 milhão de unidades).', 'c2p'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Limite de delta:', 'c2p'); ?></strong>
                    <?php esc_html_e('Quando usando estratégia delta_cd, impede variações absurdas de estoque.', 'c2p'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Dry-run:', 'c2p'); ?></strong>
                    <?php esc_html_e('Todas as requisições via API serão simuladas, sem alterar dados reais. Ideal para testes.', 'c2p'); ?>
                </li>
            </ul>
        </div>
        
        <?php
    }
}