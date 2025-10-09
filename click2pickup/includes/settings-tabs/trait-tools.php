<?php
/**
 * Click2Pickup - Settings Tab: Tools
 * 
 * ✅ v2.0.1: CORRIGIDO - Array keys validados, error handling
 * 
 * @package Click2Pickup
 * @since 2.0.1
 * @author rhpimenta
 * Last Update: 2025-01-09 15:14:33 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 15:14: 🐛 CORRIGIDO: Validação de array keys
 * - 2025-01-09 15:14: 🐛 CORRIGIDO: Error handling robusto
 * - 2025-01-09 15:11: ✅ Type hints, SQL escape, nonce validado
 */

namespace C2P\Settings_Tabs;

if (!defined('ABSPATH')) exit;

use C2P\Constants as C2P;

trait Tab_Tools {
    
    /**
     * Render principal da aba
     * 
     * @param array|null $tools_report
     */
    public static function render_tab_tools(?array $tools_report = null): void {
        // ✅ CORRIGIDO v2.0.1: Validação robusta de POST
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && 
            isset($_POST['c2p_tools_action']) && 
            $_POST['c2p_tools_action'] === 'recalc' &&
            isset($_POST['c2p_tools_nonce'])) {
            
            // ✅ Verifica nonce
            if (!wp_verify_nonce($_POST['c2p_tools_nonce'], 'c2p_tools_recalc')) {
                echo '<div class="error notice"><p>' . 
                     esc_html__('Nonce inválido. Recarregue a página e tente novamente.', 'c2p') . 
                     '</p></div>';
            } else {
                $batch = isset($_POST['c2p_batch_size']) ? 
                         max(1, min(1000, absint($_POST['c2p_batch_size']))) : 200;
                $offset = isset($_POST['c2p_offset']) ? 
                          max(0, absint($_POST['c2p_offset'])) : 0;
                
                // ✅ Executa recálculo
                try {
                    $tools_report = self::tools_run_recalc_batch($offset, $batch);
                    
                    if (!is_array($tools_report) || empty($tools_report)) {
                        throw new \Exception('Resultado vazio do recálculo.');
                    }
                    
                    echo '<div class="updated notice"><p>' . 
                         esc_html__('Lote executado com sucesso.', 'c2p') . 
                         '</p></div>';
                } catch (\Throwable $e) {
                    echo '<div class="error notice"><p>' . 
                         esc_html__('Erro ao executar lote:', 'c2p') . ' ' . 
                         esc_html($e->getMessage()) . 
                         '</p></div>';
                    $tools_report = null;
                }
            }
        }
        
        ?>
        <h2 class="title"><?php esc_html_e('Ferramentas', 'c2p'); ?></h2>
        
        <!-- ========================================
             SEÇÃO 1: INICIALIZAÇÃO DE SNAPSHOTS
             ======================================== -->
        <div class="c2p-tools-box">
            <h3><?php esc_html_e('Inicialização de Snapshots (Background)', 'c2p'); ?></h3>
            <p class="c2p-help">
                <?php esc_html_e('Varre todos os produtos e variações, cria/regulariza os snapshots por local e marca como inicializados. Use isto antes de integrar via API.', 'c2p'); ?>
            </p>
            
            <p>
                <label>
                    <?php esc_html_e('Tamanho do lote:', 'c2p'); ?>
                    <input id="c2p-scan-batch" 
                           type="number" 
                           min="50" 
                           max="1000" 
                           step="50" 
                           value="200" 
                           class="small-text" />
                </label>
                
                <a href="#" class="button button-primary" id="c2p-scan-start">
                    <?php esc_html_e('Iniciar/Retomar', 'c2p'); ?>
                </a>
                
                <a href="#" class="button" id="c2p-scan-cancel">
                    <?php esc_html_e('Cancelar', 'c2p'); ?>
                </a>
            </p>
            
            <p>
                <strong><?php esc_html_e('Em execução:', 'c2p'); ?></strong> 
                <span id="c2p-scan-running">—</span>
            </p>
            
            <p>
                <progress id="c2p-scan-progress" value="0" max="100"></progress> 
                <span id="c2p-scan-progress-val">0%</span>
            </p>
            
            <p>
                <?php esc_html_e('Processados:', 'c2p'); ?> 
                <span id="c2p-scan-processed">0</span> / 
                <span id="c2p-scan-total">0</span>
            </p>
        </div>
        
        <!-- ========================================
             SEÇÃO 2: RECÁLCULO DE ESTOQUE TOTAL
             ======================================== -->
        <div class="c2p-tools-box">
            <h3><?php esc_html_e('Recálculo de Estoque Total (_stock)', 'c2p'); ?></h3>
            <p class="c2p-help">
                <?php esc_html_e('Soma o multi-estoque por local de cada produto/variação e grava o total em _stock; atualiza _stock_status (se não houver backorders).', 'c2p'); ?>
            </p>
            
            <form method="post" action="">
                <?php wp_nonce_field('c2p_tools_recalc', 'c2p_tools_nonce'); ?>
                <input type="hidden" name="c2p_tools_action" value="recalc" />
                
                <p>
                    <label>
                        <?php esc_html_e('Lote:', 'c2p'); ?>
                        <input type="number" 
                               name="c2p_batch_size" 
                               min="50" 
                               max="1000" 
                               step="50" 
                               value="200" 
                               class="small-text" />
                    </label>
                    
                    <label style="margin-left:8px">
                        <?php esc_html_e('Offset:', 'c2p'); ?>
                        <input type="number" 
                               name="c2p_offset" 
                               min="0" 
                               step="1" 
                               value="<?php echo absint($tools_report['next_offset'] ?? 0); ?>" 
                               class="small-text" />
                    </label>
                    
                    <button type="submit" 
                            name="c2p_tools_recalc_btn" 
                            class="button button-primary">
                        <?php esc_html_e('Rodar este lote agora', 'c2p'); ?>
                    </button>
                </p>
                
                <?php if ($tools_report && is_array($tools_report)): ?>
                    <div class="c2p-report">
                        <p><strong><?php esc_html_e('Resumo do lote:', 'c2p'); ?></strong></p>
                        <ul>
                            <li>
                                <?php esc_html_e('Processados:', 'c2p'); ?> 
                                <strong><?php echo absint($tools_report['processed'] ?? 0); ?></strong>
                            </li>
                            <li>
                                <?php esc_html_e('Próximo offset:', 'c2p'); ?> 
                                <strong><?php echo absint($tools_report['next_offset'] ?? 0); ?></strong>
                            </li>
                            <li>
                                <?php esc_html_e('Total elegível (estimado):', 'c2p'); ?> 
                                <strong><?php echo absint($tools_report['total_eligible'] ?? 0); ?></strong>
                            </li>
                        </ul>
                        
                        <?php if (!empty($tools_report['items']) && is_array($tools_report['items'])): ?>
                            <details>
                                <summary><?php esc_html_e('Itens atualizados (amostra):', 'c2p'); ?></summary>
                                <pre style="white-space:pre-wrap;margin:8px 0 0;max-height:300px;overflow:auto;background:#f5f5f5;padding:10px;border-radius:4px;"><?php 
                                    echo esc_html(implode("\n", array_slice($tools_report['items'], 0, 100))); 
                                ?></pre>
                            </details>
                        <?php endif; ?>
                        
                        <?php if (absint($tools_report['processed'] ?? 0) > 0 && 
                                  absint($tools_report['next_offset'] ?? 0) < absint($tools_report['total_eligible'] ?? 0)): ?>
                            <p style="margin-top:12px;padding:10px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;">
                                <strong>💡 <?php esc_html_e('Ainda há itens para processar!', 'c2p'); ?></strong><br>
                                <?php 
                                printf(
                                    esc_html__('Próximo offset: %d de %d', 'c2p'),
                                    absint($tools_report['next_offset'] ?? 0),
                                    absint($tools_report['total_eligible'] ?? 0)
                                );
                                ?>
                            </p>
                        <?php elseif (absint($tools_report['processed'] ?? 0) > 0): ?>
                            <p style="margin-top:12px;padding:10px;background:#d1fae5;border-left:4px solid #10b981;border-radius:4px;">
                                <strong>✅ <?php esc_html_e('Recálculo completo!', 'c2p'); ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * ========================================
     * RECÁLCULO DE ESTOQUE
     * ========================================
     */
    
    /**
     * Lote de recálculo (_stock / _stock_status)
     * 
     * @param int $offset
     * @param int $limit
     * @return array
     */
    private static function tools_run_recalc_batch(int $offset, int $limit): array {
        global $wpdb;
        
        // ✅ SQL ESCAPE
        $table = esc_sql(C2P::table());
        
        // ✅ CORRIGIDO v2.0.1: Verifica se tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            throw new \Exception(__('Tabela de estoque não encontrada.', 'c2p'));
        }
        
        // ✅ Obtém IDs da tabela multi-stock
        $ids_from_table = $wpdb->get_col($wpdb->prepare(
            "SELECT product_id
             FROM `{$table}`
             GROUP BY product_id
             ORDER BY product_id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        
        // ✅ Converte para array vazio se NULL
        if (!is_array($ids_from_table)) {
            $ids_from_table = [];
        }
        
        $ids_extra = [];
        
        // ✅ Fallback: busca produtos com manage_stock=yes
        if (empty($ids_from_table)) {
            $q = new \WP_Query([
                'post_type'      => ['product', 'product_variation'],
                'post_status'    => 'any',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'meta_query'     => [
                    ['key' => '_manage_stock', 'value' => 'yes'],
                ],
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            $ids_extra = $q->posts;
        }
        
        // ✅ Merge e sanitização
        $ids = array_values(array_unique(array_map('absint',
            array_merge($ids_from_table, $ids_extra)
        )));
        
        // ✅ Total elegível
        $total_eligible = absint($wpdb->get_var(
            "SELECT COUNT(DISTINCT product_id) FROM `{$table}`"
        ));
        
        // ✅ Se não há total na tabela, usa WP_Query
        if ($total_eligible === 0) {
            $count_query = new \WP_Query([
                'post_type'      => ['product', 'product_variation'],
                'post_status'    => 'any',
                'meta_query'     => [
                    ['key' => '_manage_stock', 'value' => 'yes'],
                ],
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ]);
            $total_eligible = $count_query->found_posts;
        }
        
        $processed = 0;
        $items = [];
        
        // ✅ Processa cada produto
        foreach ($ids as $pid) {
            // ✅ Soma total do estoque
            $sum = absint($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(qty), 0)
                 FROM `{$table}`
                 WHERE product_id = %d",
                $pid
            )));
            
            // ✅ Atualiza _stock
            update_post_meta($pid, '_stock', $sum);
            
            // ✅ Atualiza _stock_status (se não permitir backorders)
            $product = wc_get_product($pid);
            if ($product && !$product->backorders_allowed()) {
                $status = ($sum > 0) ? 'instock' : 'outofstock';
                update_post_meta($pid, '_stock_status', $status);
            }
            
            // ✅ Limpa caches
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables($pid);
            }
            
            $processed++;
            $items[] = sprintf('#%d → _stock=%d', $pid, $sum);
        }
        
        // ✅ CORRIGIDO v2.0.1: Garante que TODAS as keys existem
        return [
            'processed'      => $processed,
            'items'          => $items,
            'next_offset'    => $offset + $limit,
            'total_eligible' => max($total_eligible, $offset + $processed),
        ];
    }
}

/**
 * ========================================
 * AJAX HANDLERS & ASSETS
 * ========================================
 */

namespace C2P\Settings_Tabs;

if (is_admin()) {
    
    /**
     * ========================================
     * HELPERS DE ESTADO
     * ========================================
     */
    
    /**
     * Obtém estado do scanner
     * 
     * @return array
     */
    function c2p_scan_get_state(): array {
        $st = get_option('c2p_scan_state', []);
        if (!is_array($st)) {
            $st = [];
        }
        
        $defaults = [
            'running' => false,
            'finished' => false,
            'processed' => 0,
            'total' => 0,
            'last' => 0
        ];
        
        return array_merge($defaults, $st);
    }
    
    /**
     * Define estado do scanner
     * 
     * @param array $st
     */
    function c2p_scan_set_state(array $st): void {
        if (!is_array($st)) {
            return;
        }
        
        $st['last'] = time();
        update_option('c2p_scan_state', $st, false);
    }
    
    /**
     * ========================================
     * AJAX: START
     * ========================================
     */
    add_action('wp_ajax_c2p_scan_start', function() {
        // ✅ Verifica capacidades
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['msg' => __('Sem permissão.', 'c2p')], 403);
            return;
        }
        
        // ✅ Verifica nonce
        check_ajax_referer('c2p_scan_nonce', 'nonce');
        
        $batch = isset($_POST['batch']) ? 
                 max(50, min(1000, absint($_POST['batch']))) : 200;
        
        $st = c2p_scan_get_state();
        
        global $wpdb;
        $table = esc_sql(\C2P\Constants::table());
        $total = absint($wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM `{$table}`"));
        
        $st['running'] = true;
        $st['finished'] = false;
        $st['processed'] = isset($st['processed']) ? absint($st['processed']) : 0;
        $st['total'] = $total;
        
        c2p_scan_set_state($st);
        
        // ✅ Enfileira ação Action Scheduler (se disponível)
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('c2p_init_full_scan', ['batch' => $batch], 'c2p');
        } else {
            // ✅ Fallback: executa diretamente
            if (class_exists('\C2P\Init_Scan')) {
                if (method_exists('\C2P\Init_Scan', 'run_async')) {
                    \C2P\Init_Scan::run_async($batch);
                } elseif (method_exists('\C2P\Init_Scan', 'run_full_scan')) {
                    \C2P\Init_Scan::run_full_scan($batch);
                }
            }
        }
        
        wp_send_json_success(['ok' => true, 'batch' => $batch, 'total' => $total]);
    });
    
    /**
     * ========================================
     * AJAX: STATUS
     * ========================================
     */
    add_action('wp_ajax_c2p_scan_status', function() {
        // ✅ Verifica capacidades
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['msg' => __('Sem permissão.', 'c2p')], 403);
            return;
        }
        
        $st = c2p_scan_get_state();
        
        // ✅ Tenta obter status do Init_Scan
        if (class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan', 'get_status')) {
            try {
                $s = \C2P\Init_Scan::get_status();
                if (is_array($s)) {
                    $merge_keys = ['running' => 1, 'processed' => 1, 'total' => 1, 'finished' => 1];
                    $st = array_merge($st, array_intersect_key($s, $merge_keys));
                }
            } catch (\Throwable $e) {
                // Silently fail
            }
        }
        
        // ✅ Atualiza total se zero
        if (absint($st['total'] ?? 0) === 0) {
            global $wpdb;
            $table = esc_sql(\C2P\Constants::table());
            $st['total'] = absint($wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM `{$table}`"));
        }
        
        // ✅ Verifica se há ações pendentes
        $has_running = false;
        if (function_exists('as_get_scheduled_actions')) {
            foreach (['c2p_init_full_scan', 'c2p_init_full_scan_worker'] as $hook_name) {
                $pending = as_get_scheduled_actions([
                    'hook' => $hook_name,
                    'status' => ['pending', 'running'],
                    'group' => 'c2p',
                    'per_page' => 1,
                ]);
                if (!empty($pending)) {
                    $has_running = true;
                    break;
                }
            }
        } else {
            // ✅ Fallback: verifica timestamp
            if (!empty($st['running']) && !empty($st['last']) && (time() - absint($st['last'])) < 30) {
                $has_running = true;
            }
        }
        
        // ✅ Atualiza estado
        if ($has_running) {
            $st['running'] = true;
        } else {
            if (!empty($st['running'])) {
                if (absint($st['processed'] ?? 0) < absint($st['total'] ?? 0)) {
                    $st['processed'] = absint($st['total']);
                }
                $st['running'] = false;
                $st['finished'] = absint($st['total'] ?? 0) > 0;
                c2p_scan_set_state($st);
            } else {
                $st['running'] = false;
            }
        }
        
        // ✅ Marca como finalizado se processou tudo
        if ($st['running'] === false &&
            absint($st['processed'] ?? 0) > 0 &&
            absint($st['total'] ?? 0) > 0 &&
            absint($st['processed']) >= absint($st['total'])) {
            $st['finished'] = true;
        }
        
        wp_send_json_success($st);
    });
    
    /**
     * ========================================
     * AJAX: CANCEL
     * ========================================
     */
    add_action('wp_ajax_c2p_scan_cancel', function() {
        // ✅ Verifica capacidades
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['msg' => __('Sem permissão.', 'c2p')], 403);
            return;
        }
        
        // ✅ Verifica nonce
        check_ajax_referer('c2p_scan_nonce', 'nonce');
        
        // ✅ Cancela ações agendadas
        if (function_exists('as_get_scheduled_actions') && function_exists('as_unschedule_action')) {
            foreach (['c2p_init_full_scan', 'c2p_init_full_scan_worker'] as $hook_name) {
                $pend = as_get_scheduled_actions([
                    'hook' => $hook_name,
                    'status' => ['pending', 'running'],
                    'group' => 'c2p',
                    'per_page' => 50,
                ]);
                if (is_array($pend) && !empty($pend)) {
                    try {
                        as_unschedule_action($hook_name, [], 'c2p');
                    } catch (\Throwable $e) {
                        // Silently fail
                    }
                }
            }
        }
        
        // ✅ Reseta estado
        $st = c2p_scan_get_state();
        $st['running'] = false;
        $st['finished'] = false;
        c2p_scan_set_state($st);
        
        wp_send_json_success(['ok' => true]);
    });
    
    /**
     * ========================================
     * ASSETS (JS & CSS)
     * ========================================
     */
    add_action('admin_enqueue_scripts', function($hook) {
        // ✅ Só carrega na aba Tools
        if (!isset($_GET['page']) || sanitize_key($_GET['page']) !== 'c2p-settings' || 
            !isset($_GET['tab']) || sanitize_key($_GET['tab']) !== 'tools') {
            return;
        }
        
        $nonce = wp_create_nonce('c2p_scan_nonce');
        $ajax = esc_url(admin_url('admin-ajax.php'));
        
        // ✅ Escape de nonce para JS
        $nonce_esc = esc_js($nonce);
        $ajax_esc = esc_js($ajax);
        
        $js = <<<JS
(function(){
  function qs(s){return document.querySelector(s);}
  function fmt(n){try{return new Intl.NumberFormat().format(n||0);}catch(e){return String(n||0);}}

  function refresh(){
    fetch('{$ajax_esc}?action=c2p_scan_status',{credentials:'same-origin'})
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
      fd.append('nonce','{$nonce_esc}');
      fd.append('batch',batch);
      fetch('{$ajax_esc}',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(){ setTimeout(refresh, 300); })
        .catch(function(){ setTimeout(refresh, 800); });
    }
    if(e.target && e.target.id==='c2p-scan-cancel'){
      e.preventDefault();
      if(!confirm('Cancelar a varredura atual?')) return;
      var fd=new FormData();
      fd.append('action','c2p_scan_cancel');
      fd.append('nonce','{$nonce_esc}');
      fetch('{$ajax_esc}',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(){ setTimeout(refresh, 300); })
        .catch(function(){ setTimeout(refresh, 800); });
    }
  });

  document.addEventListener('DOMContentLoaded', refresh);
})();
JS;
        
        wp_add_inline_script('jquery-core', $js);
        
        $css = <<<CSS
.c2p-tools-box {
    background: #fff;
    border: 1px solid #e2e2e2;
    padding: 16px;
    border-radius: 8px;
    max-width: 880px;
    margin-top: 10px;
}

.c2p-help {
    color: #555;
    font-size: 12px;
    margin: .3em 0 0;
}

.c2p-report {
    background: #f6f7f7;
    border: 1px solid #e2e2e2;
    padding: 12px;
    border-radius: 6px;
    margin-top: 12px;
}

progress {
    width: 100%;
    height: 20px;
}
CSS;
        
        wp_add_inline_style('common', $css);
    });
}