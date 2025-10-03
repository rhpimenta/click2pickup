<?php
namespace C2P\Settings_Tabs;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Aba "Ferramentas" (Tools) do Click2Pickup
 *
 * - Seção 1: Inicialização de Snapshots (Background) com botões Iniciar/Cancelar
 *            -> usa admin-ajax: c2p_scan_start / c2p_scan_status / c2p_scan_cancel
 * - Seção 2: Recálculo manual de _stock/_stock_status (lote por POST)
 */
trait Tab_Tools
{
    /**
     * Render principal da aba (inclui gatilho do recálculo manual)
     */
    public static function render_tab_tools( ?array $tools_report = null ) : void
    {
        // Se veio POST do recálculo manual, processa aqui (isolado nesta aba)
        if ( 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['c2p_tools_action']) && $_POST['c2p_tools_action'] === 'recalc' ) {
            \check_admin_referer( 'c2p_tools_recalc', 'c2p_tools_nonce' );
            $batch  = isset($_POST['c2p_batch_size']) ? max(1, min(1000, intval($_POST['c2p_batch_size']))) : 200;
            $offset = isset($_POST['c2p_offset']) ? max(0, intval($_POST['c2p_offset'])) : 0;
            $tools_report = self::tools_run_recalc_batch( $offset, $batch );
            echo '<div class="updated notice"><p>' . esc_html__( 'Lote executado.', 'c2p' ) . '</p></div>';
        }

        // Seção 1 — Scanner (Iniciar/Cancelar + Status)
        echo '<div class="c2p-tools-box">';
        echo '<h2>'.esc_html__('Inicialização de Snapshots (Background)', 'c2p').'</h2>';
        echo '<p class="c2p-help">'.esc_html__('Varre todos os produtos e variações, cria/regulariza os snapshots por local e marca como inicializados. Use isto antes de integrar via API.', 'c2p').'</p>';

        echo '<p>';
        echo '<label>'.esc_html__('Tamanho do lote:', 'c2p').' ';
        echo '<input id="c2p-scan-batch" type="number" min="50" max="1000" step="50" value="200" class="small-text" />';
        echo '</label> ';
        echo '<a href="#" class="button button-primary" id="c2p-scan-start">'.esc_html__('Iniciar/Retomar', 'c2p').'</a> ';
        echo '<a href="#" class="button" id="c2p-scan-cancel">'.esc_html__('Cancelar', 'c2p').'</a>';
        echo '</p>';

        echo '<p><strong>'.esc_html__('Em execução:', 'c2p').'</strong> <span id="c2p-scan-running">—</span></p>';
        echo '<p><progress id="c2p-scan-progress" value="0" max="100"></progress> <span id="c2p-scan-progress-val">0%</span></p>';
        echo '<p>'.esc_html__('Processados:', 'c2p').' <span id="c2p-scan-processed">0</span> / <span id="c2p-scan-total">0</span></p>';
        echo '</div>';

        // Seção 2 — Recálculo manual
        echo '<div class="c2p-tools-box">';
        echo '<h2>'.esc_html__('Recálculo de Estoque Total (_stock)', 'c2p').'</h2>';
        echo '<p class="c2p-help">'.esc_html__('Soma o multi-estoque por local de cada produto/variação e grava o total em _stock; atualiza _stock_status (se não houver backorders).', 'c2p').'</p>';

        echo '<form method="post" action="">';
        \wp_nonce_field( 'c2p_tools_recalc', 'c2p_tools_nonce' );
        echo '<input type="hidden" name="c2p_tools_action" value="recalc" />';

        echo '<p>';
        echo '<label>'.esc_html__('Lote:', 'c2p').' ';
        echo '<input type="number" name="c2p_batch_size" min="50" max="1000" step="50" value="200" class="small-text" />';
        echo '</label> ';
        echo '<label style="margin-left:8px">'.esc_html__('Offset:', 'c2p').' ';
        echo '<input type="number" name="c2p_offset" min="0" step="1" value="0" class="small-text" />';
        echo '</label> ';
        echo '<button type="submit" name="c2p_tools_recalc_btn" class="button button-primary">'.esc_html__('Rodar este lote agora', 'c2p').'</button>';
        echo '</p>';

        if ( $tools_report ) {
            echo '<div class="c2p-report">';
            echo '<p><strong>'.esc_html__('Resumo do lote:', 'c2p').'</strong></p>';
            echo '<ul>';
            echo '<li>'.esc_html__('Processados:', 'c2p').' '.intval($tools_report['processed']).'</li>';
            echo '<li>'.esc_html__('Próximo offset:', 'c2p').' '.intval($tools_report['next_offset']).'</li>';
            echo '<li>'.esc_html__('Total elegível (estimado):', 'c2p').' '.intval($tools_report['total_eligible']).'</li>';
            echo '</ul>';
            if ( ! empty( $tools_report['items'] ) ) {
                echo '<details><summary>'.esc_html__('Itens atualizados (amostra):', 'c2p').'</summary>';
                echo '<pre style="white-space:pre-wrap;margin:8px 0 0">'.esc_html( implode("\n", array_slice($tools_report['items'], 0, 50)) ).'</pre>';
                echo '</details>';
            }
            echo '</div>';
        }

        echo '</form>';
        echo '</div>';
    }

    /**
     * Lote de recálculo (_stock / _stock_status) usando a TABELA c2p_multi_stock (coluna qty)
     */
    private static function tools_run_recalc_batch( int $offset, int $limit ) : array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'c2p_multi_stock';

        // IDs pela nossa tabela
        $ids_from_table = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_id
               FROM {$table}
              GROUP BY product_id
              ORDER BY product_id ASC
              LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        // fallback por manage_stock, se necessário
        $ids_extra = [];
        if ( empty( $ids_from_table ) ) {
            $q = new \WP_Query([
                'post_type'      => [ 'product', 'product_variation' ],
                'post_status'    => 'any',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'meta_query'     => [
                    [ 'key' => '_manage_stock', 'value' => 'yes' ],
                ],
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            $ids_extra = $q->posts;
        }

        $ids = array_values( array_unique( array_map( 'intval',
            array_merge( $ids_from_table ?: [], $ids_extra ?: [] )
        ) ) );

        $total_eligible = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table}" );

        $processed = 0;
        $items     = [];

        foreach ( $ids as $pid ) {
            $sum = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(qty),0)
                   FROM {$table}
                  WHERE product_id = %d",
                $pid
            ) );

            \update_post_meta( $pid, '_stock', $sum );

            $product = \wc_get_product( $pid );
            if ( $product && ! $product->backorders_allowed() ) {
                $status = ( $sum > 0 ) ? 'instock' : 'outofstock';
                \update_post_meta( $pid, '_stock_status', $status );
            }

            if ( function_exists( 'wc_delete_product_transients' ) ) {
                \wc_delete_product_transients( $pid );
            }
            if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
                \wc_update_product_lookup_tables( $pid );
            }

            $processed++;
            $items[] = sprintf( '#%d → _stock=%d', $pid, $sum );
        }

        return [
            'processed'      => $processed,
            'items'          => $items,
            'next_offset'    => $offset + $limit,
            'total_eligible' => max( $total_eligible, $offset + $processed ),
        ];
    }
}

/* ============================================================
 * Infra da aba Ferramentas — handlers AJAX + assets
 * ============================================================ */

namespace C2P\Settings_Tabs;

if ( \is_admin() ) {

    /** ---------- Estado do scanner (helpers) ---------- */
    function c2p_scan_get_state() {
        $st = \get_option('c2p_scan_state', []);
        if ( ! is_array($st) ) { $st = []; }
        $defaults = ['running'=>false,'finished'=>false,'processed'=>0,'total'=>0,'last'=>0];
        return array_merge($defaults, $st);
    }
    function c2p_scan_set_state( $st ) {
        if ( ! is_array($st) ) return;
        $st['last'] = time();
        \update_option('c2p_scan_state', $st, false);
    }

    /** ---------- AJAX: START ---------- */
    \add_action('wp_ajax_c2p_scan_start', function () {
        if ( ! \current_user_can('manage_woocommerce') ) {
            \wp_send_json_error(['msg'=>'forbidden'], 403);
        }
        \check_ajax_referer('c2p_scan_nonce','nonce');

        $batch = isset($_POST['batch']) ? max(50, min(1000, intval($_POST['batch']))) : 200;

        // marca estado e já calcula 'total' para a UI não ficar 0/0
        $st = c2p_scan_get_state();

        // total estimado (DISTINCT products na tabela)
        global $wpdb;
        $table = $wpdb->prefix . 'c2p_multi_stock';
        $total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table}" );

        $st['running']   = true;
        $st['finished']  = false;
        $st['processed'] = isset($st['processed']) ? (int)$st['processed'] : 0;
        $st['total']     = $total;
        c2p_scan_set_state($st);

        // agenda ou roda
        if ( function_exists('as_enqueue_async_action') ) {
            \as_enqueue_async_action( 'c2p_init_full_scan', ['batch'=>$batch], 'c2p' );
        } else {
            if ( class_exists('\C2P\Init_Scan') ) {
                if ( method_exists('\C2P\Init_Scan','run_async') ) {
                    \C2P\Init_Scan::run_async( $batch );
                } elseif ( method_exists('\C2P\Init_Scan','run_full_scan') ) {
                    \C2P\Init_Scan::run_full_scan( $batch );
                }
            }
        }

        \wp_send_json_success(['ok'=>true,'batch'=>$batch,'total'=>$total]);
    });

    /** ---------- AJAX: STATUS ---------- */
    \add_action('wp_ajax_c2p_scan_status', function () {
        if ( ! \current_user_can('manage_woocommerce') ) {
            \wp_send_json_error(['msg'=>'forbidden'], 403);
        }

        $st = c2p_scan_get_state();

        // Se a classe expõe status, usa
        if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan','get_status') ) {
            try {
                $s = \C2P\Init_Scan::get_status();
                if ( is_array($s) ) {
                    $merge_keys = ['running'=>1,'processed'=>1,'total'=>1,'finished'=>1];
                    $st = array_merge($st, array_intersect_key($s, $merge_keys));
                }
            } catch (\Throwable $e) { /* noop */ }
        }

        // Se total ainda zero, tenta estimar
        if ( (int)($st['total'] ?? 0) === 0 ) {
            global $wpdb;
            $table = $wpdb->prefix . 'c2p_multi_stock';
            $st['total'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table}" );
        }

        // Checagem via Action Scheduler (dois ganchos possíveis)
        $has_running = false;
        if ( function_exists('as_get_scheduled_actions') ) {
            foreach ( ['c2p_init_full_scan','c2p_init_full_scan_worker'] as $hook_name ) {
                $pending = \as_get_scheduled_actions([
                    'hook'     => $hook_name,
                    'status'   => ['pending','running'], // 'in-progress' não existe
                    'group'    => 'c2p',
                    'per_page' => 1,
                ]);
                if ( ! empty($pending) ) { $has_running = true; break; }
            }
        } else {
            // Sem AS: se começamos há pouco, presume rodando
            if ( ! empty($st['running']) && ! empty($st['last']) && ( time() - (int)$st['last'] ) < 30 ) {
                $has_running = true;
            }
        }

        if ( $has_running ) {
            $st['running'] = true;
        } else {
            // Execução muito rápida: se havíamos marcado running, finalize a UI
            if ( ! empty($st['running']) ) {
                if ( (int)($st['processed'] ?? 0) < (int)($st['total'] ?? 0) ) {
                    $st['processed'] = (int) $st['total'];
                }
                $st['running']  = false;
                $st['finished'] = (int)($st['total'] ?? 0) > 0;
                c2p_scan_set_state($st);
            } else {
                $st['running'] = false;
            }
        }

        // Segurança extra: finished se processed >= total
        if ( $st['running'] === false
             && (int)($st['processed'] ?? 0) > 0
             && (int)($st['total'] ?? 0) > 0
             && (int)$st['processed'] >= (int)$st['total'] ) {
            $st['finished'] = true;
        }

        \wp_send_json_success($st);
    });

    /** ---------- AJAX: CANCEL ---------- */
    \add_action('wp_ajax_c2p_scan_cancel', function () {
        if ( ! \current_user_can('manage_woocommerce') ) {
            \wp_send_json_error(['msg'=>'forbidden'], 403);
        }
        \check_ajax_referer('c2p_scan_nonce','nonce');

        if ( function_exists('as_get_scheduled_actions') && function_exists('as_unschedule_action') ) {
            // Cancela os dois ganchos que podem existir
            foreach ( ['c2p_init_full_scan','c2p_init_full_scan_worker'] as $hook_name ) {
                $pend = \as_get_scheduled_actions([
                    'hook'     => $hook_name,
                    'status'   => ['pending','running'],
                    'group'    => 'c2p',
                    'per_page' => 50,
                ]);
                if ( is_array($pend) && ! empty($pend) ) {
                    try { \as_unschedule_action( $hook_name, [], 'c2p' ); } catch (\Throwable $e) { /* noop */ }
                }
            }
        }

        $st = c2p_scan_get_state();
        $st['running']  = false;
        $st['finished'] = false;
        c2p_scan_set_state($st);

        \wp_send_json_success(['ok'=>true]);
    });

    /** ---------- Assets (JS) da aba Ferramentas ---------- */
    \add_action( 'admin_enqueue_scripts', function( $hook ) {
        // Garante que carrega apenas na aba Ferramentas do C2P
        if ( ! isset($_GET['page']) || $_GET['page'] !== 'c2p-settings' || ! isset($_GET['tab']) || $_GET['tab'] !== 'tools' ) {
            return;
        }

        $nonce = \wp_create_nonce('c2p_scan_nonce');
        $ajax  = \admin_url('admin-ajax.php');

        // JS do painel da aba Ferramentas
        $js = "
(function(){
  function qs(s){return document.querySelector(s);}
  function fmt(n){try{return new Intl.NumberFormat().format(n||0);}catch(e){return String(n||0);}}

  // polling de status
  function refresh(){
    fetch('{$ajax}?action=c2p_scan_status',{credentials:'same-origin'})
      .then(r=>r.json()).then(function(j){
        if(!j||!j.success) return;
        var s=j.data||{};
        var elRun=qs('#c2p-scan-running'); if(!elRun) return;
        elRun.textContent = s.running ? 'Sim' : (s.finished ? 'Finalizado' : 'Não');
        var elProc=qs('#c2p-scan-processed'); if(elProc) elProc.textContent=fmt(s.processed);
        var elTot =qs('#c2p-scan-total');     if(elTot)  elTot.textContent =fmt(s.total);
        var p = s.total>0 ? Math.min(100, Math.round((s.processed/s.total)*100)) : (s.finished?100:0);
        var prog=qs('#c2p-scan-progress'); if(prog) prog.value=p;
        var pv  =qs('#c2p-scan-progress-val'); if(pv) pv.textContent=p+'%';
        if(s.running){ setTimeout(refresh, 2000); }
      }).catch(function(){});
  }

  document.addEventListener('click', function(e){
    if(e.target && e.target.id==='c2p-scan-start'){
      e.preventDefault();
      var b=qs('#c2p-scan-batch'); var batch=parseInt(b?b.value:'200',10)||200;
      var fd=new FormData();
      fd.append('action','c2p_scan_start');
      fd.append('nonce','{$nonce}');
      fd.append('batch',batch);
      fetch('{$ajax}',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(){ setTimeout(refresh, 300); })
        .catch(function(){ setTimeout(refresh, 800); });
    }
    if(e.target && e.target.id==='c2p-scan-cancel'){
      e.preventDefault();
      if(!confirm('Cancelar a varredura atual?')) return;
      var fd=new FormData();
      fd.append('action','c2p_scan_cancel');
      fd.append('nonce','{$nonce}');
      fetch('{$ajax}',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(){ setTimeout(refresh, 300); })
        .catch(function(){ setTimeout(refresh, 800); });
    }
  });

  document.addEventListener('DOMContentLoaded', refresh);
})();";
        \wp_add_inline_script( 'jquery-core', $js );

        // CSS leve
        $css = '
.c2p-tools-box{ background:#fff; border:1px solid #e2e2e2; padding:16px; border-radius:8px; max-width:880px; margin-top:10px }
.c2p-help{ color:#555; font-size:12px; margin:.3em 0 0 }
.c2p-report{ background:#f6f7f7; border:1px solid #e2e2e2; padding:12px; border-radius:6px; margin-top:12px }
progress{ width:100%; height:20px }
        ';
        \wp_add_inline_style( 'common', $css );
    });
}
