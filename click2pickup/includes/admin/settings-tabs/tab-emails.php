<?php
namespace C2P\Settings_Tabs;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Aba "E-mails" — alinhada ao envio atual.
 * - Recebe $settings prontos (sem get_option() aqui).
 * - Usa funções globais prefixadas com "\".
 * - Values passam por \wp_unslash() + \esc_attr().
 * - Checkboxes com hidden 0 (permite desmarcar).
 * - Editores de corpo em WYSIWYG (wp_editor).
 */
trait Tab_Emails {

    public static function render_tab_emails( array $settings ): void {
        $def = function(string $k, $d = '') use ($settings) {
            return array_key_exists($k, $settings) ? $settings[$k] : $d;
        };
        $is_on = function($v) {
            return !empty($v) && ( $v === '1' || $v === 1 || $v === true || $v === 'on' );
        };

        $mode   = (string) $def('email_pickup_to_mode', 'store');
        $mode_store_checked  = ($mode === 'store')  ? 'checked' : '';
        $mode_custom_checked = ($mode === 'custom') ? 'checked' : '';

        // Defaults (alinhados ao comportamento atual)
        $default_pickup_subject   = 'Novo pedido #{order_number} — {unit_name}';
        $default_pickup_body_html =
            "{prep_deadline_block}\n".
            "<h2>Novo pedido #{order_number}</h2>\n".
            "<p><strong>Unidade:</strong> {unit_name}</p>\n".
            "<p><strong>Cliente:</strong> {customer_name} — {customer_phone} — {customer_email}</p>\n".
            "<h3>Itens</h3>\n".
            "{items_table}\n".
            "<p><a href=\"{admin_link}\">Abrir no painel</a> • {site_name}</p>";

        // Padrão do alerta de low-stock no estilo aprovado
        $default_low_subject      = '⚠️ Estoque baixo — {product_name} ({sku}) ({location_name})';
        $default_low_body_html =
            "<h2>⚠️ Alerta de estoque baixo</h2>\n".
            "<p><strong>Produto:</strong> {product_name} (SKU: {sku})</p>\n".
            "<p><strong>Local:</strong> {location_name} (ID {location_id})</p>\n".
            "<p><strong>Quantidade atual:</strong> {new_qty} — <strong>Limiar:</strong> {threshold}</p>\n".
            "<p><strong>Motivo:</strong> {reason}</p>\n".
            "<hr />\n".
            "<p>{site_name}</p>";

        ?>
        <h2 class="title"><?php echo \esc_html__( 'E-mails — Click2Pickup', 'c2p' ); ?></h2>

        <h3><?php echo \esc_html__( 'Nova venda (retirada / envio)', 'c2p' ); ?></h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Ativar envio para novas vendas', 'c2p' ); ?></th>
                    <td>
                        <input type="hidden" name="c2p_settings[email_pickup_enabled]" value="0" />
                        <label>
                            <input type="checkbox" name="c2p_settings[email_pickup_enabled]" value="1" <?php echo $is_on($def('email_pickup_enabled','0')) ? 'checked' : ''; ?> />
                            <?php echo \esc_html__( 'Ativo', 'c2p' ); ?>
                        </label>
                        <p class="description"><?php
                            echo \esc_html__( 'Envia e-mail para a unidade responsável após a baixa de estoque do pedido.', 'c2p' );
                        ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Também enviar para pedidos de ENTREGA (CD)', 'c2p' ); ?></th>
                    <td>
                        <input type="hidden" name="c2p_settings[email_pickup_notify_delivery]" value="0" />
                        <label>
                            <input type="checkbox" name="c2p_settings[email_pickup_notify_delivery]" value="1" <?php echo $is_on($def('email_pickup_notify_delivery','0')) ? 'checked' : ''; ?> />
                            <?php echo \esc_html__( 'Notificar Centro de Distribuição (CD) em pedidos de envio', 'c2p' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Remetente', 'c2p' ); ?></th>
                    <td>
                        <input type="text"
                               style="width:260px"
                               placeholder="Nome (opcional)"
                               name="c2p_settings[email_pickup_from_name]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_pickup_from_name','') ) ); ?>" />
                        &nbsp;
                        <input type="email"
                               style="width:260px"
                               placeholder="email@seudominio.com (opcional)"
                               name="c2p_settings[email_pickup_from_email]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_pickup_from_email','') ) ); ?>" />
                        <p class="description"><?php echo \esc_html__( 'Se vazio, usa as configurações padrão do WordPress/WooCommerce.', 'c2p' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Destinatários', 'c2p' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="c2p_settings[email_pickup_to_mode]" value="store" <?php echo $mode_store_checked; ?> />
                            <?php echo \esc_html__( 'Usar e-mail da Unidade (CPT c2p_store)', 'c2p' ); ?>
                        </label>
                        <br />
                        <label>
                            <input type="radio" name="c2p_settings[email_pickup_to_mode]" value="custom" <?php echo $mode_custom_checked; ?> />
                            <?php echo \esc_html__( 'Lista personalizada (separar por vírgula)', 'c2p' ); ?>
                        </label>
                        <br />
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_pickup_custom_to]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_pickup_custom_to','') ) ); ?>"
                               placeholder="ex.: loja@exemplo.com, gerente@exemplo.com" />
                        <p class="description"><?php echo \esc_html__( 'Se escolher lista personalizada, o e-mail será enviado para estes endereços em todas as vendas.', 'c2p' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo \esc_html__( 'BCC (cópia oculta)', 'c2p' ); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_pickup_bcc]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_pickup_bcc','') ) ); ?>"
                               placeholder="ex.: auditoria@exemplo.com" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Assunto', 'c2p' ); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_pickup_subject]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_pickup_subject',$default_pickup_subject) ) ); ?>" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Corpo (WYSIWYG)', 'c2p' ); ?></th>
                    <td>
                        <?php
                        $pickup_body_value = (string) $def('email_pickup_body_html', $default_pickup_body_html);
                        \wp_editor(
                            \wp_unslash($pickup_body_value),
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
                            <?php echo \esc_html__( 'Placeholders:', 'c2p' ); ?>
                            <code>{unit_name}</code> <code>{order_number}</code> <code>{order_date}</code>
                            <code>{customer_name}</code> <code>{customer_phone}</code> <code>{customer_email}</code>
                            <code>{admin_link}</code> <code>{site_name}</code> <code>{items_table}</code>
                            <code>{prep_deadline_block}</code> <code>{prep_deadline_time}</code> <code>{prep_deadline_date}</code>
                        </p>
                        <p class="description">
                            <?php echo \esc_html__( 'Dica: mantenha {prep_deadline_block} no topo para destacar o prazo de preparo calculado automaticamente.', 'c2p' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <hr />

        <h3><?php echo \esc_html__( 'Alerta de estoque baixo', 'c2p' ); ?></h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Ativar alerta de estoque baixo', 'c2p' ); ?></th>
                    <td>
                        <input type="hidden" name="c2p_settings[email_lowstock_enabled]" value="0" />
                        <label>
                            <input type="checkbox" name="c2p_settings[email_lowstock_enabled]" value="1" <?php echo $is_on($def('email_lowstock_enabled','0')) ? 'checked' : ''; ?> />
                            <?php echo \esc_html__( 'Ativo', 'c2p' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Assunto', 'c2p' ); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_lowstock_subject]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_lowstock_subject',$default_low_subject) ) ); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo \esc_html__( 'Corpo (WYSIWYG)', 'c2p' ); ?></th>
                    <td>
                        <?php
                        $low_body_value = (string) $def('email_lowstock_body_html', $default_low_body_html);
                        \wp_editor(
                            \wp_unslash($low_body_value),
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
                            <?php echo \esc_html__( 'Placeholders:', 'c2p' ); ?>
                            <code>{product_name}</code> <code>{sku}</code> <code>{product_id}</code> <code>{variation_id}</code>
                            <code>{location_id}</code> <code>{location_name}</code> <code>{new_qty}</code> <code>{threshold}</code>
                            <code>{reason}</code> <code>{site_name}</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo \esc_html__( 'BCC (cópia oculta)', 'c2p' ); ?></th>
                    <td>
                        <input type="text"
                               style="width:520px"
                               name="c2p_settings[email_lowstock_bcc]"
                               value="<?php echo \esc_attr( \wp_unslash( (string) $def('email_lowstock_bcc','') ) ); ?>"
                               placeholder="ex.: auditoria@exemplo.com" />
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Blocos de teste (fora do form principal).
     * Mantivemos apenas o teste de nova venda manual, sem snapshots/auditoria.
     */
    public static function render_tab_emails_test_blocks( array $settings ): void {
        ?>
        <div class="c2p-tools-box" style="margin-top:16px">
            <h3><?php echo \esc_html__( 'Teste rápido (envio manual de nova venda)', 'c2p' ); ?></h3>
            <form method="post" action="<?php echo \esc_url( \admin_url('admin-post.php') ); ?>">
                <?php \wp_nonce_field('c2p_email_test'); ?>
                <input type="hidden" name="action" value="c2p_email_test" />
                <label><?php echo \esc_html__( 'Pedido #', 'c2p' ); ?> <input type="number" name="order_id" style="width:120px" required /></label>
                &nbsp;
                <label><?php echo \esc_html__( 'Enviar para', 'c2p' ); ?> <input type="email" name="to" style="width:260px" placeholder="email@exemplo.com" required /></label>
                &nbsp;
                <button type="submit" class="button button-secondary"><?php echo \esc_html__( 'Enviar teste', 'c2p' ); ?></button>
            </form>
        </div>
        <?php
    }
}
