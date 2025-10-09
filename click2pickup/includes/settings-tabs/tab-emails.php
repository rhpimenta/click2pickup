<?php
/**
 * Click2Pickup - Settings Tab: Emails
 * 
 * ✅ v2.0: Type hints, constantes, validações
 * 
 * @package Click2Pickup
 * @since 2.0.0
 * @author rhpimenta
 * Last Update: 2025-10-09 13:14:36 UTC
 * 
 * CHANGELOG:
 * - 2025-10-09 13:14: ✅ MELHORADO: Type hints em closures
 * - 2025-10-09 13:14: ✅ MELHORADO: Constantes para defaults
 * - 2025-10-09 13:14: ✅ CORRIGIDO: Escape sem redundância
 * - 2025-10-09 13:14: ✅ REMOVIDO: Método não usado
 */

namespace C2P\Settings_Tabs;

if (!defined('ABSPATH')) exit;

trait Tab_Emails {
    
    /**
     * Default email templates
     */
    const DEFAULT_PICKUP_SUBJECT = 'Novo pedido #{order_number} — {unit_name}';
    
    const DEFAULT_PICKUP_BODY = "{prep_deadline_block}\n" .
        "<h2>Novo pedido #{order_number}</h2>\n" .
        "<p><strong>Unidade:</strong> {unit_name}</p>\n" .
        "<p><strong>Cliente:</strong> {customer_name} — {customer_phone} — {customer_email}</p>\n" .
        "<h3>Itens</h3>\n" .
        "{items_table}\n" .
        "<p><a href=\"{admin_link}\">Abrir no painel</a> • {site_name}</p>";
    
    const DEFAULT_LOWSTOCK_SUBJECT = '⚠️ Estoque baixo — {product_name} ({sku}) ({location_name})';
    
    const DEFAULT_LOWSTOCK_BODY = "<h2>⚠️ Alerta de estoque baixo</h2>\n" .
        "<p><strong>Produto:</strong> {product_name} (SKU: {sku})</p>\n" .
        "<p><strong>Local:</strong> {location_name} (ID {location_id})</p>\n" .
        "<p><strong>Quantidade atual:</strong> {new_qty} — <strong>Limiar:</strong> {threshold}</p>\n" .
        "<p><strong>Motivo:</strong> {reason}</p>\n" .
        "<hr />\n" .
        "<p>{site_name}</p>";
    
    /**
     * Render emails tab
     * 
     * @param array $settings
     */
    public static function render_tab_emails(array $settings): void {
        // ✅ MELHORADO: Type hints adequados
        $def = function(string $k, $d = '') use ($settings) {
            return array_key_exists($k, $settings) ? $settings[$k] : $d;
        };
        
        $is_on = function($v): bool {
            return !empty($v) && ($v === '1' || $v === 1 || $v === true || $v === 'on');
        };
        
        $mode = (string) $def('email_pickup_to_mode', 'store');
        $mode_store_checked = ($mode === 'store') ? 'checked' : '';
        $mode_custom_checked = ($mode === 'custom') ? 'checked' : '';
        ?>
        
        <h2 class="title"><?php esc_html_e('E-mails — Click2Pickup', 'c2p'); ?></h2>
        
        <!-- ========================================
             NOVA VENDA (RETIRADA/ENVIO)
             ======================================== -->
        <h3><?php esc_html_e('Nova venda (retirada / envio)', 'c2p'); ?></h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Ativar envio para novas vendas', 'c2p'); ?></th>
                    <td>
                        <input type="hidden" name="c2p_settings[email_pickup_enabled]" value="0" />
                        <label>
                            <input type="checkbox" 
                                   name="c2p_settings[email_pickup_enabled]" 
                                   value="1" 
                                   <?php checked($is_on($def('email_pickup_enabled', '0'))); ?> />
                            <?php esc_html_e('Ativo', 'c2p'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Envia e-mail para a unidade responsável após a baixa de estoque do pedido.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Também enviar para pedidos de ENTREGA (CD)', 'c2p'); ?></th>
                    <td>
                        <input type="hidden" name="c2p_settings[email_pickup_notify_delivery]" value="0" />
                        <label>
                            <input type="checkbox" 
                                   name="c2p_settings[email_pickup_notify_delivery]" 
                                   value="1" 
                                   <?php checked($is_on($def('email_pickup_notify_delivery', '0'))); ?> />
                            <?php esc_html_e('Notificar Centro de Distribuição (CD) em pedidos de envio', 'c2p'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Remetente', 'c2p'); ?></th>
                    <td>
                        <input type="text"
                               style="width:260px"
                               placeholder="<?php esc_attr_e('Nome (opcional)', 'c2p'); ?>"
                               name="c2p_settings[email_pickup_from_name]"
                               value="<?php echo esc_attr($def('email_pickup_from_name', '')); ?>" />
                        &nbsp;
                        <input type="email"
                               style="width:260px"
                               placeholder="<?php esc_attr_e('email@seudominio.com (opcional)', 'c2p'); ?>"
                               name="c2p_settings[email_pickup_from_email]"
                               value="<?php echo esc_attr($def('email_pickup_from_email', '')); ?>" />
                        <p class="description">
                            <?php esc_html_e('Se vazio, usa as configurações padrão do WordPress/WooCommerce.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Destinatários', 'c2p'); ?></th>
                    <td>
                        <label>
                            <input type="radio" 
                                   name="c2p_settings[email_pickup_to_mode]" 
                                   value="store" 
                                   <?php echo $mode_store_checked; ?> />
                            <?php esc_html_e('Usar e-mail da Unidade (CPT c2p_store)', 'c2p'); ?>
                        </label>
                        <br />
                        <label>
                            <input type="radio" 
                                   name="c2p_settings[email_pickup_to_mode]" 
                                   value="custom" 
                                   <?php echo $mode_custom_checked; ?> />
                            <?php esc_html_e('Lista personalizada (separar por vírgula)', 'c2p'); ?>
                        </label>
                        <br />
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_pickup_custom_to]"
                               value="<?php echo esc_attr($def('email_pickup_custom_to', '')); ?>"
                               placeholder="<?php esc_attr_e('ex.: loja@exemplo.com, gerente@exemplo.com', 'c2p'); ?>" />
                        <p class="description">
                            <?php esc_html_e('Se escolher lista personalizada, o e-mail será enviado para estes endereços em todas as vendas.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('BCC (cópia oculta)', 'c2p'); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_pickup_bcc]"
                               value="<?php echo esc_attr($def('email_pickup_bcc', '')); ?>"
                               placeholder="<?php esc_attr_e('ex.: auditoria@exemplo.com', 'c2p'); ?>" />
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Assunto', 'c2p'); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_pickup_subject]"
                               value="<?php echo esc_attr($def('email_pickup_subject', self::DEFAULT_PICKUP_SUBJECT)); ?>" />
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Corpo (WYSIWYG)', 'c2p'); ?></th>
                    <td>
                        <?php
                        $pickup_body_value = (string) $def('email_pickup_body_html', self::DEFAULT_PICKUP_BODY);
                        
                        wp_editor(
                            $pickup_body_value,
                            'c2p_email_pickup_body_html',
                            [
                                'textarea_name' => 'c2p_settings[email_pickup_body_html]',
                                'textarea_rows' => 12,
                                'media_buttons' => false,
                                'teeny'         => true,
                                'quicktags'     => true,
                            ]
                        );
                        ?>
                        <p class="description">
                            <?php esc_html_e('Placeholders:', 'c2p'); ?>
                            <code>{unit_name}</code>
                            <code>{order_number}</code>
                            <code>{order_date}</code>
                            <code>{customer_name}</code>
                            <code>{customer_phone}</code>
                            <code>{customer_email}</code>
                            <code>{admin_link}</code>
                            <code>{site_name}</code>
                            <code>{items_table}</code>
                            <code>{prep_deadline_block}</code>
                            <code>{prep_deadline_time}</code>
                            <code>{prep_deadline_date}</code>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Dica: mantenha {prep_deadline_block} no topo para destacar o prazo de preparo calculado automaticamente.', 'c2p'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <hr />
        
        <!-- ========================================
             ALERTA DE ESTOQUE BAIXO
             ======================================== -->
        <h3><?php esc_html_e('Alerta de estoque baixo', 'c2p'); ?></h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Ativar alerta de estoque baixo', 'c2p'); ?></th>
                    <td>
                        <input type="hidden" name="c2p_settings[email_lowstock_enabled]" value="0" />
                        <label>
                            <input type="checkbox" 
                                   name="c2p_settings[email_lowstock_enabled]" 
                                   value="1" 
                                   <?php checked($is_on($def('email_lowstock_enabled', '0'))); ?> />
                            <?php esc_html_e('Ativo', 'c2p'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Assunto', 'c2p'); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_lowstock_subject]"
                               value="<?php echo esc_attr($def('email_lowstock_subject', self::DEFAULT_LOWSTOCK_SUBJECT)); ?>" />
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Corpo (WYSIWYG)', 'c2p'); ?></th>
                    <td>
                        <?php
                        $low_body_value = (string) $def('email_lowstock_body_html', self::DEFAULT_LOWSTOCK_BODY);
                        
                        wp_editor(
                            $low_body_value,
                            'c2p_email_lowstock_body_html',
                            [
                                'textarea_name' => 'c2p_settings[email_lowstock_body_html]',
                                'textarea_rows' => 10,
                                'media_buttons' => false,
                                'teeny'         => true,
                                'quicktags'     => true,
                            ]
                        );
                        ?>
                        <p class="description">
                            <?php esc_html_e('Placeholders:', 'c2p'); ?>
                            <code>{product_name}</code>
                            <code>{sku}</code>
                            <code>{product_id}</code>
                            <code>{variation_id}</code>
                            <code>{location_id}</code>
                            <code>{location_name}</code>
                            <code>{new_qty}</code>
                            <code>{threshold}</code>
                            <code>{reason}</code>
                            <code>{site_name}</code>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('BCC (cópia oculta)', 'c2p'); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_lowstock_bcc]"
                               value="<?php echo esc_attr($def('email_lowstock_bcc', '')); ?>"
                               placeholder="<?php esc_attr_e('ex.: auditoria@exemplo.com', 'c2p'); ?>" />
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- ========================================
             TESTE RÁPIDO
             ======================================== -->
        <hr />
        
        <div class="c2p-tools-box" style="margin-top:16px; background:#fff; border:1px solid #e2e2e2; padding:16px; border-radius:8px; max-width:980px;">
            <h3><?php esc_html_e('Teste rápido (envio manual de nova venda)', 'c2p'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('c2p_email_test'); ?>
                <input type="hidden" name="action" value="c2p_email_test" />
                
                <label>
                    <?php esc_html_e('Pedido #', 'c2p'); ?>
                    <input type="number" 
                           name="order_id" 
                           style="width:120px" 
                           required 
                           min="1" />
                </label>
                &nbsp;
                <label>
                    <?php esc_html_e('Enviar para', 'c2p'); ?>
                    <input type="email" 
                           name="to" 
                           style="width:260px" 
                           placeholder="email@exemplo.com" 
                           required />
                </label>
                &nbsp;
                <button type="submit" class="button button-secondary">
                    <?php esc_html_e('Enviar teste', 'c2p'); ?>
                </button>
            </form>
        </div>
        
        <?php
    }
}