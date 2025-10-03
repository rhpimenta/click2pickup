<?php
namespace C2P\Settings_Tabs;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Trait: Tab_Health — Aba "Saúde da API" integrada às Configurações.
 */
trait Tab_Health {

    /** Renderiza a aba */
    private static function render_tab_health( $o ) {
        $nonce    = \wp_create_nonce( 'c2p_rest_healthcheck' );
        $ajax_url = \admin_url( 'admin-ajax.php' );
        $last_id  = (int) \get_option( 'c2p_hc_last_product_id', 0 );

        // CSS inline (ajustado para exibir o painel dentro da aba)
        echo '<style>' . self::hc_inline_css() . '#c2p-hc-root{display:block!important}</style>';

        // Painel
        ?>
        <div id="c2p-hc-root" class="c2p-hc-wrap">
          <h2><?php echo esc_html__('Saúde da API', 'c2p'); ?></h2>

          <div class="c2p-hc-card">
            <p><?php
              echo wp_kses_post(
                sprintf(
                  /* translators: %s is the REST route */
                  __('Este teste executa um <strong>PUT</strong> em <code>%s</code> simulando um ERP que envia <code>qty</code>. Com <em>DRY-RUN</em>, nada é persistido; o interceptor registra o comportamento e você vê o log abaixo.', 'c2p'),
                  '/wc/v3/products/{id}'
                )
              );
            ?></p>

            <div class="c2p-hc-row">
              <div class="field">
                <label for="c2p-hc-product"><?php echo esc_html__('ID do Produto', 'c2p'); ?></label>
                <input type="number" id="c2p-hc-product" value="<?php echo esc_attr($last_id ?: 0); ?>" min="1" />
              </div>
              <div class="field">
                <label for="c2p-hc-qty"><?php echo esc_html__('Quantidade (qty)', 'c2p'); ?></label>
                <input type="number" id="c2p-hc-qty" value="10" step="1" />
              </div>
              <div class="field" style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <label><input type="checkbox" id="c2p-hc-dry" checked /> DRY-RUN (<?php echo esc_html__('não altera estoque', 'c2p'); ?>)</label>
              </div>
              <div class="field" style="flex:0 0 auto;">
                <button class="button button-primary c2p-hc-btn" id="c2p-hc-run"><?php echo esc_html__('Executar teste', 'c2p'); ?></button>
              </div>
            </div>

            <p><strong><?php echo esc_html__('Resultado', 'c2p'); ?>:</strong> <span id="c2p-hc-status"></span></p>
            <h3><?php echo esc_html__('Resposta', 'c2p'); ?></h3>
            <pre class="c2p-json" id="c2p-hc-response"></pre>
            <h3><?php echo esc_html__('Logs recentes (source: c2p-rest-global)', 'c2p'); ?></h3>
            <pre class="c2p-log" id="c2p-hc-logs"></pre>
          </div>
        </div>
        <script>
        <?php echo self::hc_inline_js( $nonce, $ajax_url ); ?>
        </script>
        <?php
    }

    /** AJAX handler (admin-ajax.php?action=c2p_rest_healthcheck) */
    public static function hc_ajax_run(): void {
        \check_ajax_referer( 'c2p_rest_healthcheck' );
        if ( ! \current_user_can('manage_woocommerce') && ! \current_user_can('manage_options') ) {
            \wp_send_json_error( ['message' => 'Sem permissão'], 403 );
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $qty        = isset($_POST['qty']) ? floatval($_POST['qty']) : null;
        $dry_run    = !empty($_POST['dry_run']);

        if ( $product_id <= 0 || $qty === null ) {
            \wp_send_json_error( ['message' => 'Parâmetros inválidos'], 400 );
        }

        \update_option( 'c2p_hc_last_product_id', $product_id, false );

        $route = '/wc/v3/products/' . $product_id;
        $req   = new \WP_REST_Request( 'PUT', $route );
        $req->add_header( 'Content-Type', 'application/json; charset=utf-8' );
        if ( $dry_run ) { $req->add_header( 'X-C2P-Dry-Run', '1' ); }
        $req->set_param( 'manage_stock', true );
        $req->set_param( 'qty', $qty );

        $response = \rest_do_request( $req );
        $status   = \method_exists( $response, 'get_status' ) ? $response->get_status() : 500;
        $data     = \method_exists( $response, 'get_data' )   ? $response->get_data()   : (string) $response;

        $log_tail = '';
        if ( \function_exists('wc_get_log_file_path') ) {
            $path = \wc_get_log_file_path('c2p-rest-global');
            if ( $path && \file_exists($path) ) {
                $lines = @\file($path, FILE_IGNORE_NEW_LINES);
                if ( \is_array($lines) ) {
                    $slice    = \array_slice($lines, -120);
                    $log_tail = \implode("\n", $slice);
                }
            }
        }

        \wp_send_json_success([
            'status'   => $status,
            'response' => $data,
            'log_tail' => $log_tail,
            'dry_run'  => (bool) $dry_run,
        ]);
    }

    /** CSS do painel */
    private static function hc_inline_css(): string {
        return "
        .c2p-hc-wrap{max-width:980px}
        .c2p-hc-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;margin:16px 0}
        .c2p-hc-row{display:flex;gap:16px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap}
        .c2p-hc-row .field{flex:1;min-width:220px}
        .c2p-hc-row label{display:block;font-weight:600;margin-bottom:6px}
        .c2p-hc-row input[type=number]{width:100%}
        .c2p-hc-btn{margin-top:8px}
        pre.c2p-log, pre.c2p-json{background:#0b1020;color:#cde5ff;border-radius:6px;border:1px solid #1c2757;padding:12px;white-space:pre-wrap;max-height:360px;overflow:auto}
        .c2p-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;margin-left:6px}
        .ok{background:#e5f7ee;color:#116b3d;border:1px solid #b7e3cd}
        .warn{background:#fff6e6;color:#8b5a00;border:1px solid #ffe1a3}
        .err{background:#fde8e8;color:#8a0e0e;border:1px solid #f7c6c6}
        ";
    }

    /** JS do painel (jQuery) */
    private static function hc_inline_js( string $nonce, string $ajax_url ): string {
        $action = 'c2p_rest_healthcheck';
        return "
        (function($){
          function show(x){ try{ return (typeof x==='string')? x : JSON.stringify(x, null, 2); }catch(e){ return String(x); } }
          $(document).on('click', '#c2p-hc-run', function(e){
            e.preventDefault();
            var pid = parseInt($('#c2p-hc-product').val(),10)||0;
            var qty = parseFloat($('#c2p-hc-qty').val());
            var dry = $('#c2p-hc-dry').is(':checked');
            $('#c2p-hc-status').html('Executando…');
            $('#c2p-hc-response').text('');
            $('#c2p-hc-logs').text('');
            $.post('".$ajax_url."', {
              action: '".$action."',
              _ajax_nonce: '".$nonce."',
              product_id: pid,
              qty: qty,
              dry_run: dry ? 1 : 0
            }).done(function(res){
              if(!res) return;
              if(res.success){
                var s = res.data.status || 0;
                var badge = '<span class=\"c2p-badge ok\">OK</span>';
                if(s>=400) badge = '<span class=\"c2p-badge err\">Erro</span>';
                else if(s>=300) badge = '<span class=\"c2p-badge warn\">Aviso</span>';
                $('#c2p-hc-status').html('HTTP '+s+' '+badge);
                $('#c2p-hc-response').text(show(res.data.response));
                $('#c2p-hc-logs').text(res.data.log_tail || '(sem linhas recentes)');
              } else {
                $('#c2p-hc-status').html('Falhou <span class=\"c2p-badge err\">Erro</span>');
                $('#c2p-hc-response').text(show(res.data||{}));
              }
            }).fail(function(xhr){
              $('#c2p-hc-status').html('Falha de rede <span class=\"c2p-badge err\">Erro</span>');
              $('#c2p-hc-response').text(xhr.responseText||'');
            });
          });
        })(jQuery);
        ";
    }
}
