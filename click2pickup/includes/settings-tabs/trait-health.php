<?php
/**
 * Click2Pickup - Settings Tab: Health (API Healthcheck)
 * 
 * ✅ v2.0.3: CORRIGIDO - Nonce usando wp_verify_nonce() direto
 * 
 * @package Click2Pickup
 * @since 2.0.3
 * @author rhpimenta
 * Last Update: 2025-01-09 15:08:44 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 15:08: 🐛 CORRIGIDO: Removido check_ajax_referer(), usando wp_verify_nonce()
 * - 2025-01-09 15:02: 🐛 Verificação manual de nonce (fallback)
 */

namespace C2P\Settings_Tabs;

if (!defined('ABSPATH')) exit;

trait Tab_Health {
    
    /**
     * Renderiza a aba Health (Saúde da API)
     * 
     * @param array $settings Current settings
     */
    private static function render_tab_health(array $settings): void {
        // ✅ Nonce específico para este form
        $nonce = wp_create_nonce('c2p_hc_test');
        $ajax_url = admin_url('admin-ajax.php');
        $last_id = absint(get_option('c2p_hc_last_product_id', 0));
        
        // CSS inline
        echo '<style>' . self::hc_inline_css() . '</style>';
        
        ?>
        <div id="c2p-hc-root" class="c2p-hc-wrap">
            <h2 class="title"><?php esc_html_e('Saúde da API (Healthcheck)', 'c2p'); ?></h2>
            
            <div class="c2p-hc-card">
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s is the REST route */
                        __('Este teste executa um <strong>PUT</strong> em <code>%s</code> simulando um ERP que envia <code>qty</code>. Com <em>DRY-RUN</em>, nada é persistido; o interceptor registra o comportamento e você vê o log abaixo.', 'c2p'),
                        '/wc/v3/products/{id}'
                    ));
                    ?>
                </p>
                
                <!-- ========================================
                     FORM DE TESTE
                     ======================================== -->
                <div class="c2p-hc-row">
                    <div class="field">
                        <label for="c2p-hc-product">
                            <?php esc_html_e('ID do Produto', 'c2p'); ?>
                        </label>
                        <input type="number" 
                               id="c2p-hc-product" 
                               value="<?php echo esc_attr($last_id > 0 ? $last_id : ''); ?>" 
                               min="1" 
                               placeholder="<?php esc_attr_e('Ex: 123', 'c2p'); ?>" />
                    </div>
                    
                    <div class="field">
                        <label for="c2p-hc-qty">
                            <?php esc_html_e('Quantidade (qty)', 'c2p'); ?>
                        </label>
                        <input type="number" 
                               id="c2p-hc-qty" 
                               value="10" 
                               step="1" 
                               min="0" />
                    </div>
                    
                    <div class="field" style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <label>
                            <input type="checkbox" id="c2p-hc-dry" checked /> 
                            <?php esc_html_e('DRY-RUN (não altera estoque)', 'c2p'); ?>
                        </label>
                    </div>
                    
                    <div class="field" style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <button class="button button-primary c2p-hc-btn" id="c2p-hc-run">
                            <?php esc_html_e('▶ Executar teste', 'c2p'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- ========================================
                     RESULTADO
                     ======================================== -->
                <p>
                    <strong><?php esc_html_e('Resultado:', 'c2p'); ?></strong> 
                    <span id="c2p-hc-status"><?php esc_html_e('Aguardando execução...', 'c2p'); ?></span>
                </p>
                
                <h3><?php esc_html_e('Resposta da API', 'c2p'); ?></h3>
                <pre class="c2p-json" id="c2p-hc-response"><?php esc_html_e('Nenhum teste executado ainda.', 'c2p'); ?></pre>
                
                <h3><?php esc_html_e('Logs recentes (source: c2p-rest-global)', 'c2p'); ?></h3>
                <pre class="c2p-log" id="c2p-hc-logs"><?php esc_html_e('Logs aparecerão aqui após o teste.', 'c2p'); ?></pre>
            </div>
            
            <!-- ========================================
                 INFORMAÇÕES ADICIONAIS
                 ======================================== -->
            <div style="background:#f0f9ff;border-left:4px solid #0ea5e9;padding:16px;margin-top:20px;border-radius:6px;">
                <h4 style="margin:0 0 12px;color:#0369a1;">
                    💡 <?php esc_html_e('Como usar este teste', 'c2p'); ?>
                </h4>
                <ul style="margin:0;padding-left:20px;color:#374151;line-height:1.8;">
                    <li>
                        <strong><?php esc_html_e('DRY-RUN ativado:', 'c2p'); ?></strong>
                        <?php esc_html_e('Simula a operação sem alterar o banco de dados. Ideal para testes.', 'c2p'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('DRY-RUN desativado:', 'c2p'); ?></strong>
                        <?php esc_html_e('Altera o estoque REALMENTE. Use com cuidado em produção!', 'c2p'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Logs:', 'c2p'); ?></strong>
                        <?php esc_html_e('Visualize as últimas 120 linhas do log c2p-rest-global para debug.', 'c2p'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Status HTTP:', 'c2p'); ?></strong>
                        <code>200-299</code> = OK • 
                        <code>300-399</code> = Aviso • 
                        <code>400+</code> = Erro
                    </li>
                </ul>
            </div>
        </div>
        
        <script>
        <?php echo self::hc_inline_js($nonce, $ajax_url); ?>
        </script>
        <?php
    }
    
    /**
     * ========================================
     * AJAX HANDLER
     * ========================================
     */
    
    /**
     * AJAX handler para healthcheck
     * 
     * Action: admin-ajax.php?action=c2p_rest_healthcheck
     */
    public static function hc_ajax_run(): void {
        // ✅ CORRIGIDO v2.0.3: Verificação DIRETA de nonce (sem check_ajax_referer)
        $nonce = isset($_POST['c2p_hc_nonce']) ? sanitize_text_field($_POST['c2p_hc_nonce']) : '';
        
        if (empty($nonce) || !wp_verify_nonce($nonce, 'c2p_hc_test')) {
            wp_send_json_error([
                'message' => __('Nonce inválido ou expirado. Recarregue a página e tente novamente.', 'c2p'),
                'debug' => 'Nonce validation failed'
            ], 403);
            return;
        }
        
        // ✅ Verifica capacidades
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sem permissão para executar este teste.', 'c2p')], 403);
            return;
        }
        
        // ✅ Validação e sanitização de inputs
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $qty = isset($_POST['qty']) ? (float)sanitize_text_field($_POST['qty']) : null;
        $dry_run = !empty($_POST['dry_run']);
        
        if ($product_id <= 0) {
            wp_send_json_error([
                'message' => __('ID do produto inválido. Digite um número maior que zero.', 'c2p')
            ], 400);
            return;
        }
        
        if ($qty === null || $qty < 0) {
            wp_send_json_error([
                'message' => __('Quantidade inválida. Digite um número válido.', 'c2p')
            ], 400);
            return;
        }
        
        // ✅ Salva último ID testado
        update_option('c2p_hc_last_product_id', $product_id, false);
        
        // ✅ Cria request REST
        $route = '/wc/v3/products/' . $product_id;
        $req = new \WP_REST_Request('PUT', $route);
        $req->add_header('Content-Type', 'application/json; charset=utf-8');
        
        if ($dry_run) {
            $req->add_header('X-C2P-Dry-Run', '1');
        }
        
        $req->set_param('manage_stock', true);
        $req->set_param('qty', $qty);
        
        // ✅ Executa request
        $response = rest_do_request($req);
        
        // ✅ Extrai status e data
        $status = method_exists($response, 'get_status') ? $response->get_status() : 500;
        $data = method_exists($response, 'get_data') ? $response->get_data() : (string)$response;
        
        // ✅ Lê logs com verificação de existência
        $log_tail = self::get_recent_logs('c2p-rest-global', 120);
        
        wp_send_json_success([
            'status' => $status,
            'response' => $data,
            'log_tail' => $log_tail,
            'dry_run' => $dry_run,
            'product_id' => $product_id,
            'qty' => $qty,
        ]);
    }
    
    /**
     * ========================================
     * HELPER METHODS
     * ========================================
     */
    
    /**
     * Obtém logs recentes de um arquivo de log
     * 
     * @param string $log_name Nome do log (ex: 'c2p-rest-global')
     * @param int $lines Número de linhas a retornar
     * @return string
     */
    private static function get_recent_logs(string $log_name, int $lines = 120): string {
        if (!function_exists('wc_get_log_file_path')) {
            return __('(WooCommerce Logs não disponível)', 'c2p');
        }
        
        $path = wc_get_log_file_path($log_name);
        
        // ✅ Verifica se arquivo existe E é legível
        if (!$path || !file_exists($path) || !is_readable($path)) {
            return __('(Nenhum log encontrado ainda)', 'c2p');
        }
        
        // ✅ Trata erros de leitura
        $file_lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if (!is_array($file_lines) || empty($file_lines)) {
            return __('(Log vazio)', 'c2p');
        }
        
        $slice = array_slice($file_lines, -$lines);
        
        return implode("\n", $slice);
    }
    
    /**
     * ========================================
     * CSS & JS
     * ========================================
     */
    
    /**
     * CSS do painel
     * 
     * @return string
     */
    private static function hc_inline_css(): string {
        return <<<CSS
        .c2p-hc-wrap {
            max-width: 980px;
        }
        
        .c2p-hc-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .c2p-hc-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .c2p-hc-row .field {
            flex: 1;
            min-width: 220px;
        }
        
        .c2p-hc-row label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #1d2327;
        }
        
        .c2p-hc-row input[type=number] {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
        }
        
        .c2p-hc-row input[type=number]:focus {
            border-color: #2271b1;
            outline: none;
            box-shadow: 0 0 0 1px #2271b1;
        }
        
        .c2p-hc-btn {
            margin-top: 8px;
        }
        
        pre.c2p-log,
        pre.c2p-json {
            background: #0b1020;
            color: #cde5ff;
            border-radius: 6px;
            border: 1px solid #1c2757;
            padding: 14px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 400px;
            overflow: auto;
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
            line-height: 1.6;
            margin: 10px 0;
        }
        
        .c2p-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .c2p-badge.ok {
            background: #e5f7ee;
            color: #116b3d;
            border: 1px solid #b7e3cd;
        }
        
        .c2p-badge.warn {
            background: #fff6e6;
            color: #8b5a00;
            border: 1px solid #ffe1a3;
        }
        
        .c2p-badge.err {
            background: #fde8e8;
            color: #8a0e0e;
            border: 1px solid #f7c6c6;
        }
        
        #c2p-hc-status {
            font-weight: 600;
        }
CSS;
    }
    
    /**
     * JavaScript do painel
     * 
     * @param string $nonce
     * @param string $ajax_url
     * @return string
     */
    private static function hc_inline_js(string $nonce, string $ajax_url): string {
        $action = 'c2p_rest_healthcheck';
        
        // ✅ Escape de strings JS
        $nonce_esc = esc_js($nonce);
        $ajax_url_esc = esc_url($ajax_url);
        $action_esc = esc_js($action);
        
        return <<<JS
        (function($) {
            'use strict';
            
            function show(x) {
                try {
                    return (typeof x === 'string') ? x : JSON.stringify(x, null, 2);
                } catch (e) {
                    return String(x);
                }
            }
            
            $(document).on('click', '#c2p-hc-run', function(e) {
                e.preventDefault();
                
                var pid = parseInt($('#c2p-hc-product').val(), 10) || 0;
                var qty = parseFloat($('#c2p-hc-qty').val());
                var dry = $('#c2p-hc-dry').is(':checked');
                
                if (pid <= 0) {
                    alert('Digite um ID de produto válido.');
                    return;
                }
                
                if (isNaN(qty) || qty < 0) {
                    alert('Digite uma quantidade válida.');
                    return;
                }
                
                $('#c2p-hc-status').html('⏳ Executando teste...');
                $('#c2p-hc-response').text('Aguardando resposta...');
                $('#c2p-hc-logs').text('Carregando logs...');
                
                $.post('{$ajax_url_esc}', {
                    action: '{$action_esc}',
                    c2p_hc_nonce: '{$nonce_esc}',
                    product_id: pid,
                    qty: qty,
                    dry_run: dry ? 1 : 0
                }).done(function(res) {
                    if (!res) {
                        $('#c2p-hc-status').html('❌ Resposta vazia <span class="c2p-badge err">Erro</span>');
                        return;
                    }
                    
                    if (res.success) {
                        var s = res.data.status || 0;
                        var badge = '<span class="c2p-badge ok">✓ OK</span>';
                        
                        if (s >= 400) {
                            badge = '<span class="c2p-badge err">✗ Erro</span>';
                        } else if (s >= 300) {
                            badge = '<span class="c2p-badge warn">⚠ Aviso</span>';
                        }
                        
                        $('#c2p-hc-status').html('HTTP ' + s + ' ' + badge);
                        $('#c2p-hc-response').text(show(res.data.response));
                        $('#c2p-hc-logs').text(res.data.log_tail || '(sem linhas recentes)');
                    } else {
                        $('#c2p-hc-status').html('❌ Falhou <span class="c2p-badge err">Erro</span>');
                        $('#c2p-hc-response').text(show(res.data || {}));
                        $('#c2p-hc-logs').text('(erro ao obter logs)');
                    }
                }).fail(function(xhr) {
                    $('#c2p-hc-status').html('❌ Falha de rede <span class="c2p-badge err">Erro</span>');
                    $('#c2p-hc-response').text(xhr.responseText || 'Erro desconhecido');
                    $('#c2p-hc-logs').text('(não foi possível obter logs)');
                });
            });
        })(jQuery);
JS;
    }
}