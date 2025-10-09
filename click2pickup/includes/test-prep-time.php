<?php
/**
 * Teste de Cálculo de Tempo de Preparo e Estoque
 * Shortcode: [c2p_test_prep_time]
 * Versão: 1.1.1
 * 
 * ✅ CORRIGIDO v1.1.1: Mensagem de prazo agora compara DATAS, não horas
 * ✅ CORRIGIDO v1.1.0: Mensagem de prazo mostra dia da semana correto
 * 
 * Este arquivo testa o cálculo do prazo de preparo e disponibilidade de estoque
 * para todas as lojas e centros de distribuição cadastrados
 * 
 * @author rhpimenta
 * Last Update: 2025-01-07 15:33:47 UTC
 */

namespace C2P;

if (!defined('ABSPATH')) exit;

class Test_Prep_Time {
    
    public function __construct() {
        add_shortcode('c2p_test_prep_time', [$this, 'render_test']);
    }
    
    /**
     * Renderiza o teste de tempo de preparo e estoque
     */
    public function render_test() {
        ob_start();
        
        // Verifica se tem carrinho
        if (!WC()->cart || WC()->cart->is_empty()) {
            echo '<div style="padding:20px; background:#f5f5f5; border-radius:8px; border:1px solid #ccc;">';
            echo '<h3 style="color:#333;">⚠️ Carrinho vazio</h3>';
            echo '<p style="color:#333;">Adicione produtos ao carrinho para testar o cálculo de tempo de preparo e verificar estoque.</p>';
            echo '</div>';
            return ob_get_clean();
        }
        
        // Pega TODOS os locais cadastrados (lojas e CDs)
        $all_locations = $this->get_all_locations();
        
        if (empty($all_locations)) {
            echo '<div style="padding:20px; background:#fff3cd; border-radius:8px; border:1px solid #ffc107;">';
            echo '<h3 style="color:#333;">⚠️ Nenhum local cadastrado</h3>';
            echo '<p style="color:#333;">Configure lojas ou centros de distribuição no sistema.</p>';
            echo '</div>';
            return ob_get_clean();
        }
        
        // Pega itens do carrinho para verificação de estoque
        $cart_items = $this->get_cart_items_for_stock();
        
        // Pré-carrega todos os estoques de uma vez só (OTIMIZAÇÃO DE PERFORMANCE)
        $all_stocks = $this->preload_all_stocks($all_locations, $cart_items);
        
        // Hora atual para simulação
        if (isset($_GET['test_time'])) {
            try {
                $now = new \DateTimeImmutable($_GET['test_time'], $this->get_timezone());
            } catch (\Exception $e) {
                $now = new \DateTimeImmutable('now', $this->get_timezone());
            }
        } else {
            $now = new \DateTimeImmutable('now', $this->get_timezone());
        }
        
        ?>
        <div style="padding:20px; background:#f8f9fa; border-radius:12px; border:2px solid #333;">
            <h2 style="margin-top:0; color:#000;">🧪 Teste de Cálculo de Tempo de Preparo e Estoque</h2>
            
            <div style="background:#e7f3ff; padding:10px; border-radius:6px; margin-bottom:20px; border:1px solid #0066cc;">
                <strong style="color:#000;">Hora atual (simulação):</strong> 
                <span style="color:#000;"><?php echo $now->format('d/m/Y H:i'); ?> 
                (<?php echo $this->get_day_name($now->format('w')); ?>)</span>
            </div>
            
            <!-- RESUMO DO CARRINHO -->
            <div style="background:#fff; padding:15px; margin-bottom:20px; border-radius:8px; border:2px solid #666;">
                <h3 style="margin-top:0; color:#000;">🛒 Itens no Carrinho</h3>
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#e0e0e0;">
                            <th style="padding:8px; text-align:left; color:#000; border:1px solid #999;">Produto</th>
                            <th style="padding:8px; text-align:center; color:#000; border:1px solid #999;">SKU</th>
                            <th style="padding:8px; text-align:center; color:#000; border:1px solid #999;">Quantidade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart_items as $item): ?>
                        <tr>
                            <td style="padding:8px; border:1px solid #999; color:#333;">
                                <?php echo esc_html($item['name']); ?>
                                <?php if ($item['variation']): ?>
                                    <br><small style="color:#666;"><?php echo esc_html($item['variation']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px; text-align:center; border:1px solid #999; color:#333;">
                                <?php echo $item['sku'] ?: '-'; ?>
                            </td>
                            <td style="padding:8px; text-align:center; border:1px solid #999; color:#333; font-weight:bold;">
                                <?php echo $item['quantity']; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- SUMÁRIO GERAL DE DISPONIBILIDADE -->
            <div style="background:#fff; padding:15px; margin-bottom:20px; border-radius:8px; border:2px solid #0066cc;">
                <h3 style="margin-top:0; color:#000;">📊 Resumo de Disponibilidade por Local</h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:10px;">
                    <?php 
                    foreach ($all_locations as $location):
                        $stock_check = $this->check_store_stock_from_cache($location['id'], $cart_items, $all_stocks);
                        
                        // CORREÇÃO: Calcular disponibilidade baseada em QUANTIDADES
                        $total_requested = 0;
                        $total_available_to_fulfill = 0;
                        
                        foreach ($stock_check['items'] as $item_data) {
                            $total_requested += $item_data['requested'];
                            // Conta o mínimo entre disponível e solicitado (o que pode ser atendido)
                            $total_available_to_fulfill += min($item_data['available'], $item_data['requested']);
                        }
                        
                        // Determina status e cores baseado na QUANTIDADE que pode ser atendida
                        $can_fulfill_all = ($total_available_to_fulfill >= $total_requested);
                        $fulfillment_percentage = $total_requested > 0 ? round(($total_available_to_fulfill / $total_requested) * 100) : 0;
                        
                        if ($can_fulfill_all) {
                            $bg_color = '#d4edda';
                            $border_color = '#28a745';
                            $icon = '✅';
                            $status_text = '100% disponível';
                        } elseif ($total_available_to_fulfill > 0) {
                            $bg_color = '#fff3cd';
                            $border_color = '#ffc107';
                            $icon = '⚠️';
                            $status_text = $total_available_to_fulfill . '/' . $total_requested . ' itens';
                        } else {
                            $bg_color = '#f8d7da';
                            $border_color = '#dc3545';
                            $icon = '❌';
                            $status_text = 'Sem estoque';
                        }
                        
                        $type_icon = $location['type'] === 'cd' ? '📦' : '🏪';
                    ?>
                    <div style="padding:10px; background:<?php echo $bg_color; ?>; border:2px solid <?php echo $border_color; ?>; border-radius:6px;">
                        <strong style="color:#000; font-size:14px;">
                            <?php echo $type_icon; ?> <?php echo esc_html($location['title']); ?>
                        </strong>
                        <div style="font-size:12px; color:#333; margin-top:5px;">
                            <?php echo $icon; ?> 
                            <span style="color:<?php echo $can_fulfill_all ? '#155724' : ($total_available_to_fulfill > 0 ? '#856404' : '#721c24'); ?>; font-weight:bold;">
                                <?php echo $status_text; ?>
                                <?php if (!$can_fulfill_all && $total_available_to_fulfill > 0): ?>
                                    (<?php echo $fulfillment_percentage; ?>%)
                                <?php endif; ?>
                            </span>
                            <?php if ($location['type'] === 'cd'): ?>
                                <br><small style="color:#666;">📍 Centro de Distribuição</small>
                            <?php else: ?>
                                <br><small style="color:#666;">📍 Loja para retirada</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- DETALHES POR LOCAL -->
            <?php foreach ($all_locations as $location): ?>
                <?php 
                $store_id = $location['id'];
                $store_title = $location['title'];
                $store_type = $location['type'];
                $schedule = $this->get_store_schedule($store_id);
                $prep_result = $this->calculate_prep_deadline($store_id, $now);
                $stock_check = $this->check_store_stock_from_cache($store_id, $cart_items, $all_stocks);
                
                // Recalcula para determinar cor do card
                $total_requested = 0;
                $total_available_to_fulfill = 0;
                foreach ($stock_check['items'] as $item_data) {
                    $total_requested += $item_data['requested'];
                    $total_available_to_fulfill += min($item_data['available'], $item_data['requested']);
                }
                $can_fulfill_all = ($total_available_to_fulfill >= $total_requested);
                
                $card_border = $can_fulfill_all ? '#28a745' : ($total_available_to_fulfill > 0 ? '#ffc107' : '#dc3545');
                ?>
                
                <div style="background:#fff; padding:15px; margin-bottom:15px; border-radius:8px; border:2px solid <?php echo $card_border; ?>;">
                    <h3 style="margin-top:0; color:#0066cc;">
                        <?php echo $store_type === 'cd' ? '📦' : '🏪'; ?> <?php echo esc_html($store_title); ?>
                        <span style="font-size:12px; color:#666;">
                            (<?php echo $store_type === 'cd' ? 'Centro de Distribuição' : 'Loja Física'; ?> - ID: <?php echo $store_id; ?>)
                        </span>
                    </h3>
                    
                    <!-- Info do local -->
                    <div style="background:#f0f0f0; padding:10px; border-radius:4px; margin-bottom:10px; border:1px solid #999;">
                        <strong style="color:#000;">Tipo:</strong> 
                        <span style="color:#333;">
                            <?php 
                            if ($store_type === 'cd') {
                                echo '📦 Centro de Distribuição (envio para casa)';
                            } else {
                                echo '🏪 Loja Física (retirada no local)';
                            }
                            ?>
                        </span>
                        <?php 
                        // Se for loja, verifica se tem método de retirada configurado
                        if ($store_type !== 'cd'):
                            $pickup_method = $this->get_pickup_method_for_store($store_id);
                            if ($pickup_method):
                        ?>
                        <br><strong style="color:#000;">Método de retirada:</strong> 
                        <span style="color:#333;"><?php echo esc_html($pickup_method['title']); ?> (Zona: <?php echo esc_html($pickup_method['zone_name']); ?>)</span>
                        <?php 
                            endif;
                        endif;
                        ?>
                    </div>
                    
                    <!-- VERIFICAÇÃO DE ESTOQUE -->
                    <details <?php echo !$can_fulfill_all ? 'open' : ''; ?> style="margin-bottom:10px;">
                        <summary style="cursor:pointer; padding:12px; border:2px solid <?php echo $can_fulfill_all ? '#28a745' : ($total_available_to_fulfill > 0 ? '#ffc107' : '#dc3545'); ?>; 
                                        border-radius:6px; background:<?php echo $can_fulfill_all ? '#d4edda' : ($total_available_to_fulfill > 0 ? '#fff3cd' : '#f8d7da'); ?>; 
                                        color:#000; font-weight:bold;">
                            📦 Disponibilidade de Estoque: 
                            <?php if ($can_fulfill_all): ?>
                                <span style="color:#155724;">✅ 100% Disponível (<?php echo $total_available_to_fulfill; ?>/<?php echo $total_requested; ?> unidades)</span>
                            <?php elseif ($total_available_to_fulfill > 0): ?>
                                <span style="color:#856404;">⚠️ Parcialmente disponível (<?php echo $total_available_to_fulfill; ?>/<?php echo $total_requested; ?> unidades)</span>
                            <?php else: ?>
                                <span style="color:#721c24;">❌ Sem estoque (0/<?php echo $total_requested; ?> unidades)</span>
                            <?php endif; ?>
                        </summary>
                        
                        <div style="padding:10px; background:#fff; margin-top:5px; border-radius:4px; border:1px solid #999;">
                            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                                <thead>
                                    <tr style="background:#e0e0e0;">
                                        <th style="padding:5px; text-align:left; color:#000; border:1px solid #999;">Produto</th>
                                        <th style="padding:5px; text-align:center; color:#000; border:1px solid #999;">Pedido</th>
                                        <th style="padding:5px; text-align:center; color:#000; border:1px solid #999;">Disponível</th>
                                        <th style="padding:5px; text-align:center; color:#000; border:1px solid #999;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stock_check['items'] as $item_data): ?>
                                    <tr>
                                        <td style="padding:5px; border:1px solid #999; color:#333; font-size:12px;">
                                            <?php echo esc_html($item_data['name']); ?>
                                        </td>
                                        <td style="padding:5px; text-align:center; border:1px solid #999; color:#333; font-weight:bold;">
                                            <?php echo $item_data['requested']; ?>
                                        </td>
                                        <td style="padding:5px; text-align:center; border:1px solid #999; 
                                                   color:<?php echo $item_data['available'] >= $item_data['requested'] ? '#28a745' : '#dc3545'; ?>; 
                                                   font-weight:bold;">
                                            <?php echo $item_data['available']; ?>
                                        </td>
                                        <td style="padding:5px; text-align:center; border:1px solid #999;">
                                            <?php if ($item_data['available'] >= $item_data['requested']): ?>
                                                <span style="color:#28a745;">✓ OK</span>
                                            <?php elseif ($item_data['available'] > 0): ?>
                                                <span style="color:#ffc107;">⚠ Parcial</span>
                                            <?php else: ?>
                                                <span style="color:#dc3545;">✗ Sem estoque</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <div style="margin-top:10px; padding:8px; background:#f0f0f0; border-radius:4px;">
                                <strong style="color:#000;">Análise Detalhada:</strong><br>
                                <span style="color:#333;">
                                    • Total de unidades pedidas: <strong><?php echo $total_requested; ?></strong><br>
                                    • Total de unidades disponíveis para atender: <strong><?php echo $total_available_to_fulfill; ?></strong><br>
                                    • Percentual de atendimento: <strong><?php echo $total_requested > 0 ? round(($total_available_to_fulfill / $total_requested) * 100) : 0; ?>%</strong><br>
                                    <?php if ($total_requested > $total_available_to_fulfill): ?>
                                    • Faltam: <strong style="color:#dc3545;"><?php echo ($total_requested - $total_available_to_fulfill); ?> unidades</strong>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </details>
                    
                    <!-- HORÁRIOS E TEMPO DE PREPARO (só para lojas físicas) -->
                    <?php if ($store_type !== 'cd'): ?>
                        
                        <!-- Horários da semana -->
                        <details style="margin-bottom:10px;">
                            <summary style="cursor:pointer; padding:8px; background:#e0e0e0; border-radius:4px; color:#000; font-weight:bold;">
                                📅 Horários de funcionamento
                            </summary>
                            <div style="padding:10px; background:#fafafa; margin-top:5px; border-radius:4px; border:1px solid #ccc;">
                                <?php $this->render_weekly_schedule($schedule['weekly']); ?>
                            </div>
                        </details>
                        
                        <!-- Dias especiais -->
                        <?php if (!empty($schedule['specials'])): ?>
                        <details style="margin-bottom:10px;">
                            <summary style="cursor:pointer; padding:8px; background:#e0e0e0; border-radius:4px; color:#000; font-weight:bold;">
                                📆 Dias especiais
                            </summary>
                            <div style="padding:10px; background:#fafafa; margin-top:5px; border-radius:4px; border:1px solid #ccc;">
                                <?php $this->render_special_days($schedule['specials']); ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        
                        <!-- Resultado do cálculo de tempo -->
                        <div style="padding:12px; border:2px solid <?php echo $prep_result['success'] ? '#28a745' : '#dc3545'; ?>; 
                                    border-radius:6px; background:<?php echo $prep_result['success'] ? '#d4edda' : '#f8d7da'; ?>;">
                            <h4 style="margin-top:0; color:#000;">🕒 Tempo de Preparo para Retirada:</h4>
                            
                            <?php if ($prep_result['success']): ?>
                                <div style="font-size:16px; font-weight:bold; color:#155724;">
                                    ✅ <?php echo $prep_result['message']; ?>
                                </div>
                                
                                <details style="margin-top:10px;">
                                    <summary style="cursor:pointer; padding:8px; background:rgba(255,255,255,0.5); border-radius:4px; color:#000;">
                                        Ver detalhes do cálculo
                                    </summary>
                                    <div style="margin-top:5px; padding:10px; background:#fff; border-radius:4px; border:1px solid #999;">
                                        <span style="color:#333; font-size:13px;">
                                        • Data da compra: <?php echo $prep_result['debug']['purchase_date']; ?><br>
                                        • Status no dia: <?php echo $prep_result['debug']['today_status']; ?><br>
                                        • Dia do preparo: <?php echo $prep_result['debug']['effective_day']; ?><br>
                                        • Início do preparo: <?php echo $prep_result['debug']['start_point']; ?><br>
                                        • Tempo de preparo: <?php echo $prep_result['debug']['prep_minutes']; ?> minutos<br>
                                        • Prazo final: <?php echo $prep_result['debug']['deadline']; ?><br>
                                        <?php if (isset($prep_result['debug']['moved_reason'])): ?>
                                        • Observação: <?php echo $prep_result['debug']['moved_reason']; ?>
                                        <?php endif; ?>
                                        </span>
                                    </div>
                                </details>
                            <?php else: ?>
                                <div style="font-size:16px; font-weight:bold; color:#721c24;">
                                    ❌ <?php echo $prep_result['message']; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                    <?php else: ?>
                        <!-- Para CDs, mostrar informação de envio -->
                        <div style="padding:12px; border:2px solid #0066cc; border-radius:6px; background:#e7f3ff;">
                            <h4 style="margin-top:0; color:#000;">📦 Centro de Distribuição:</h4>
                            <p style="color:#333; margin:5px 0;">
                                Este local é um centro de distribuição para envio.<br>
                                O prazo de entrega dependerá do método de envio escolhido e da localização do cliente.
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- RESUMO FINAL DO LOCAL -->
                    <?php if ($can_fulfill_all && ($store_type === 'cd' || $prep_result['success'])): ?>
                    <div style="margin-top:15px; padding:12px; background:linear-gradient(135deg, #d4edda, #c3e6cb); 
                                border-radius:6px; border:2px solid #28a745;">
                        <h4 style="margin:0; color:#155724;">
                            🎉 <?php echo $store_type === 'cd' ? 'Este CD' : 'Esta loja'; ?> pode atender seu pedido completo!
                        </h4>
                        <?php if ($store_type !== 'cd'): ?>
                        <p style="margin:5px 0; color:#155724;">
                            Retirada disponível: <strong style="font-size:18px;"><?php echo $prep_result['message']; ?></strong>
                        </p>
                        <?php else: ?>
                        <p style="margin:5px 0; color:#155724;">
                            Todos os <?php echo $total_requested; ?> itens disponíveis para envio imediato.
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($total_available_to_fulfill > 0): ?>
                    <div style="margin-top:15px; padding:12px; background:linear-gradient(135deg, #fff3cd, #ffeeba); 
                                border-radius:6px; border:2px solid #ffc107;">
                        <h4 style="margin:0; color:#856404;">
                            ⚠️ <?php echo $store_type === 'cd' ? 'Este CD' : 'Esta loja'; ?> pode atender parcialmente seu pedido
                        </h4>
                        <p style="margin:5px 0; color:#856404;">
                            Disponível: <?php echo $total_available_to_fulfill; ?> de <?php echo $total_requested; ?> unidades 
                            (faltam <?php echo ($total_requested - $total_available_to_fulfill); ?> unidades).
                        </p>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:15px; padding:12px; background:linear-gradient(135deg, #f8d7da, #f5c6cb); 
                                border-radius:6px; border:2px solid #dc3545;">
                        <h4 style="margin:0; color:#721c24;">
                            ❌ <?php echo $store_type === 'cd' ? 'Este CD' : 'Esta loja'; ?> não tem estoque para seu pedido
                        </h4>
                        <p style="margin:5px 0; color:#721c24;">
                            Nenhuma das <?php echo $total_requested; ?> unidades solicitadas está disponível.
                        </p>
                    </div>
                    <?php endif; ?>
                    
                </div>
            <?php endforeach; ?>
            
            <!-- Simulador de horário -->
            <div style="background:#fff; padding:15px; margin-top:20px; border-radius:8px; border:2px solid #0066cc;">
                <h3 style="margin-top:0; color:#000;">🔧 Simulador de Horário</h3>
                <p style="color:#333;">Adicione <code style="background:#f0f0f0; padding:2px 6px; color:#000;">?test_time=YYYY-MM-DD HH:MM</code> na URL para simular outro horário.</p>
                <p style="color:#333;">Exemplo: <code style="background:#f0f0f0; padding:2px 6px; color:#000;">?test_time=2025-10-11 22:00</code></p>
                <?php 
                if (isset($_GET['test_time'])) {
                    $test_time = sanitize_text_field($_GET['test_time']);
                    echo '<div style="padding:10px; background:#e7f3ff; border-radius:4px; border:1px solid #0066cc;">';
                    echo '<strong style="color:#000;">Simulando:</strong> <span style="color:#333;">' . esc_html($test_time) . '</span>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * OTIMIZAÇÃO: Pré-carrega todos os estoques em UMA query
     */
    private function preload_all_stocks($locations, $cart_items) {
        global $wpdb;
        
        $store_ids = array_map(function($loc) { return $loc['id']; }, $locations);
        $product_ids = array_map(function($item) { return $item['product_id']; }, $cart_items);
        
        if (empty($store_ids) || empty($product_ids)) {
            return [];
        }
        
        $store_placeholders = implode(',', array_fill(0, count($store_ids), '%d'));
        $product_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        
        $table = $wpdb->prefix . 'c2p_multi_stock';
        $query = $wpdb->prepare(
            "SELECT store_id, product_id, qty 
             FROM {$table} 
             WHERE store_id IN ($store_placeholders) 
             AND product_id IN ($product_placeholders)",
            array_merge($store_ids, $product_ids)
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $stocks = [];
        foreach ($results as $row) {
            $stocks[$row['store_id']][$row['product_id']] = intval($row['qty']);
        }
        
        return $stocks;
    }
    
    /**
     * Verifica estoque usando cache pré-carregado
     */
    private function check_store_stock_from_cache($store_id, $cart_items, $cached_stocks) {
        $result = [
            'all_available' => true,
            'items' => [],
            'total_items' => count($cart_items),
            'available_items' => 0,
            'partial_items' => 0,
            'unavailable_items' => 0
        ];
        
        foreach ($cart_items as $item) {
            $available = isset($cached_stocks[$store_id][$item['product_id']]) 
                        ? $cached_stocks[$store_id][$item['product_id']] 
                        : 0;
            
            $requested = $item['quantity'];
            
            $item_data = [
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'requested' => $requested,
                'available' => $available,
                'sufficient' => ($available >= $requested)
            ];
            
            if ($available >= $requested) {
                $result['available_items']++;
            } elseif ($available > 0) {
                $result['partial_items']++;
                $result['all_available'] = false;
            } else {
                $result['unavailable_items']++;
                $result['all_available'] = false;
            }
            
            $result['items'][] = $item_data;
        }
        
        return $result;
    }
    
    /**
     * Pega TODOS os locais cadastrados
     */
    private function get_all_locations() {
        $locations = [];
        
        $stores = get_posts([
            'post_type' => 'c2p_store',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        foreach ($stores as $store) {
            $store_type = get_post_meta($store->ID, 'c2p_type', true) ?: 'loja';
            
            $locations[] = [
                'id' => $store->ID,
                'title' => $store->post_title,
                'type' => $store_type
            ];
        }
        
        return $locations;
    }
    
    /**
     * Verifica se uma loja tem método de retirada configurado
     */
    private function get_pickup_method_for_store($store_id) {
        if (!class_exists('\WC_Shipping_Zones')) return null;
        
        $linked_instances = get_post_meta($store_id, 'c2p_shipping_instance_ids', true);
        if (!is_array($linked_instances) || empty($linked_instances)) return null;
        
        $zones = \WC_Shipping_Zones::get_zones();
        $zones[0] = (new \WC_Shipping_Zone(0))->get_data();
        
        foreach ($zones as $zone_data) {
            $zone = new \WC_Shipping_Zone($zone_data['id'] ?? 0);
            foreach ($zone->get_shipping_methods(true) as $instance_id => $method) {
                if ($method->id === 'local_pickup' && 
                    $method->is_enabled() && 
                    in_array($instance_id, $linked_instances)) {
                    return [
                        'instance_id' => (int)$instance_id,
                        'title' => $method->get_title(),
                        'zone_name' => $zone->get_zone_name(),
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Pega itens do carrinho
     */
    private function get_cart_items_for_stock() {
        $items = [];
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['variation_id'] ?: $cart_item['product_id'];
            
            $variation_text = '';
            if ($cart_item['variation_id'] && !empty($cart_item['variation'])) {
                $variations = [];
                foreach ($cart_item['variation'] as $key => $value) {
                    $taxonomy = str_replace('attribute_', '', $key);
                    $term = get_term_by('slug', $value, $taxonomy);
                    $variations[] = $term ? $term->name : $value;
                }
                $variation_text = implode(', ', $variations);
            }
            
            $items[] = [
                'product_id' => $product_id,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'quantity' => $cart_item['quantity'],
                'variation' => $variation_text,
                'cart_key' => $cart_item_key
            ];
        }
        
        return $items;
    }
    
    private function get_timezone(): \DateTimeZone {
        if (function_exists('wp_timezone')) return wp_timezone();
        $tz = get_option('timezone_string') ?: 'UTC';
        return new \DateTimeZone($tz);
    }
    
    private function get_day_name($dow) {
        $days = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        return $days[$dow] ?? '';
    }
    
    private function get_store_schedule($store_id) {
        return [
            'weekly' => get_post_meta($store_id, 'c2p_hours_weekly', true) ?: [],
            'specials' => get_post_meta($store_id, 'c2p_hours_special', true) ?: []
        ];
    }
    
    private function render_weekly_schedule($weekly) {
        $days = [
            'mon' => 'Segunda',
            'tue' => 'Terça', 
            'wed' => 'Quarta',
            'thu' => 'Quinta',
            'fri' => 'Sexta',
            'sat' => 'Sábado',
            'sun' => 'Domingo'
        ];
        
        echo '<table style="width:100%; border-collapse:collapse;">';
        echo '<tr style="background:#ddd;"><th style="padding:5px; color:#000; border:1px solid #999;">Dia</th><th style="padding:5px; color:#000; border:1px solid #999;">Horário</th><th style="padding:5px; color:#000; border:1px solid #999;">Limite</th><th style="padding:5px; color:#000; border:1px solid #999;">Preparo</th></tr>';
        
        foreach ($days as $key => $label) {
            $day = $weekly[$key] ?? [];
            $is_open = !empty($day['open_enabled']);
            
            echo '<tr>';
            echo '<td style="padding:5px; border:1px solid #999; color:#000;"><strong>' . $label . '</strong></td>';
            
            if ($is_open && !empty($day['open']) && !empty($day['close'])) {
                echo '<td style="padding:5px; border:1px solid #999; color:#333;">' . $day['open'] . ' - ' . $day['close'] . '</td>';
                
                $cutoff_value = isset($day['cutoff']) ? $day['cutoff'] : '';
                echo '<td style="padding:5px; border:1px solid #999; color:#333;">' . ($cutoff_value ?: '-') . '</td>';
                
                echo '<td style="padding:5px; border:1px solid #999; color:#333;">' . ($day['prep_min'] ?? 0) . ' min</td>';
            } else {
                echo '<td colspan="3" style="padding:5px; border:1px solid #999; color:#dc3545; font-weight:bold;">Fechado</td>';
            }
            echo '</tr>';
        }
        
        echo '</table>';
    }
    
    private function render_special_days($specials) {
        if (empty($specials)) {
            echo '<p style="color:#333;">Nenhum dia especial configurado.</p>';
            return;
        }
        
        echo '<ul style="margin:0; padding-left:20px; color:#333;">';
        foreach ($specials as $special) {
            $date = $special['date_br'] ?? '';
            $desc = $special['desc'] ?? '';
            $is_annual = !empty($special['annual']);
            
            echo '<li style="color:#000;">';
            echo '<strong>' . esc_html($date) . '</strong>';
            if ($desc) echo ' - <span style="color:#333;">' . esc_html($desc) . '</span>';
            if ($is_annual) echo ' <span style="color:#28a745; font-weight:bold;">(Anual)</span>';
            
            if (!empty($special['open']) && !empty($special['close'])) {
                echo '<br><small style="color:#666;">Horário: ' . $special['open'] . ' - ' . $special['close'];
                
                if (isset($special['cutoff']) && $special['cutoff']) {
                    echo ' | Limite: ' . $special['cutoff'];
                }
                
                if (isset($special['prep_min']) && $special['prep_min']) {
                    echo ' | Preparo: ' . $special['prep_min'] . ' min';
                }
                
                echo '</small>';
            } else {
                echo ' <span style="color:#dc3545; font-weight:bold;">(Fechado)</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    
    /**
     * CÁLCULO PRINCIPAL
     */
    private function calculate_prep_deadline($store_id, $now = null) {
        if (!$now) {
            if (isset($_GET['test_time'])) {
                try {
                    $now = new \DateTimeImmutable($_GET['test_time'], $this->get_timezone());
                } catch (\Exception $e) {
                    $now = new \DateTimeImmutable('now', $this->get_timezone());
                }
            } else {
                $now = new \DateTimeImmutable('now', $this->get_timezone());
            }
        }
        
        $schedule = $this->get_store_schedule($store_id);
        $today_schedule = $this->resolve_day_schedule($now, $schedule);
        
        $debug = [
            'purchase_date' => $now->format('d/m/Y H:i') . ' (' . $this->get_day_name($now->format('w')) . ')',
            'today_status' => $today_schedule['status'] ?? 'undefined'
        ];
        
        if (isset($today_schedule['special_day'])) {
            $debug['special_day'] = $today_schedule['special_day'];
        }
        
        if (($today_schedule['status'] ?? '') === 'undefined') {
            return [
                'success' => false,
                'message' => 'Horários não definidos para esta loja',
                'debug' => $debug
            ];
        }
        
        $start = null;
        $effective_schedule = null;
        $effective_date = null;
        
        if (($today_schedule['status'] ?? '') === 'open') {
            $before_open = ($now < $today_schedule['open']);
            $after_close = ($now >= $today_schedule['close']);
            $after_cutoff = (isset($today_schedule['cutoff']) && $today_schedule['cutoff'] instanceof \DateTimeImmutable) ? 
                            ($now > $today_schedule['cutoff']) : false;
            
            if ($before_open) {
                $effective_date = $now;
                $effective_schedule = $today_schedule;
                $start = $today_schedule['open'];
                $debug['moved_reason'] = 'Compra antes da abertura - preparo iniciará quando abrir';
            } elseif ($after_close) {
                $next = $this->next_open_day($now, $schedule);
                if (!$next) {
                    return [
                        'success' => false,
                        'message' => 'Não há dias úteis disponíveis nos próximos 14 dias',
                        'debug' => $debug
                    ];
                }
                $effective_date = $next['date'];
                $effective_schedule = $next['schedule'];
                $start = $effective_schedule['open'];
                $debug['moved_reason'] = 'Compra após fechamento - preparo no próximo dia útil';
            } elseif ($after_cutoff) {
                $next = $this->next_open_day($now, $schedule);
                if (!$next) {
                    return [
                        'success' => false,
                        'message' => 'Não há dias úteis disponíveis nos próximos 14 dias',
                        'debug' => $debug
                    ];
                }
                $effective_date = $next['date'];
                $effective_schedule = $next['schedule'];
                $start = $effective_schedule['open'];
                $debug['moved_reason'] = 'Compra após horário limite do dia';
            } else {
                $effective_date = $now;
                $effective_schedule = $today_schedule;
                $start = $now;
                $debug['moved_reason'] = 'Compra dentro do horário útil';
            }
        } else {
            $next = $this->next_open_day($now, $schedule);
            if (!$next) {
                return [
                    'success' => false,
                    'message' => 'Não há dias úteis disponíveis nos próximos 14 dias',
                    'debug' => $debug
                ];
            }
            $effective_date = $next['date'];
            $effective_schedule = $next['schedule'];
            $start = $effective_schedule['open'];
            $debug['moved_reason'] = 'Loja fechada no dia da compra';
        }
        
        $debug['effective_day'] = $effective_date->format('d/m/Y') . ' (' . 
                                  $this->get_day_name($effective_date->format('w')) . ')';
        
        $prep_minutes = (int)($effective_schedule['prep_minutes'] ?? 60);
        $provisional = $start->modify("+{$prep_minutes} minutes");
        
        $debug['start_point'] = $start->format('d/m/Y H:i');
        $debug['prep_minutes'] = $prep_minutes;
        
        if ($provisional <= $effective_schedule['close']) {
            $deadline = $provisional;
        } else {
            $next = $this->next_open_day($effective_date, $schedule);
            if (!$next) {
                return [
                    'success' => false,
                    'message' => 'Tempo de preparo excede horários disponíveis',
                    'debug' => $debug
                ];
            }
            
            $new_schedule = $next['schedule'];
            $new_prep = (int)($new_schedule['prep_minutes'] ?? 60);
            $deadline = $new_schedule['open']->modify("+{$new_prep} minutes");
            
            $debug['effective_day'] = $next['date']->format('d/m/Y') . ' (' . 
                                      $this->get_day_name($next['date']->format('w')) . ')';
            $debug['start_point'] = $new_schedule['open']->format('d/m/Y H:i');
            $debug['moved_reason'] = ($debug['moved_reason'] ?? '') . 
                                   ' + Tempo de preparo não cabe no dia, movido para próximo dia útil';
        }
        
        $debug['deadline'] = $deadline->format('d/m/Y H:i');
        
        $message = $this->format_prep_message($now, $deadline);
        
        return [
            'success' => true,
            'deadline' => $deadline,
            'message' => $message,
            'debug' => $debug
        ];
    }
    
    private function get_dow_key($dow_number) {
        $map = [
            0 => 'sun',
            1 => 'mon',
            2 => 'tue',
            3 => 'wed',
            4 => 'thu',
            5 => 'fri',
            6 => 'sat'
        ];
        return $map[$dow_number] ?? 'mon';
    }
    
    private function resolve_day_schedule($date, $schedule) {
        $special = $this->find_special_day($date, $schedule['specials'] ?? []);
        
        if ($special) {
            if (empty($special['open']) || empty($special['close'])) {
                return [
                    'status' => 'closed',
                    'special_day' => $special['desc'] ?? 'Dia especial (fechado)'
                ];
            }
            
            $row = [
                'open' => $special['open'],
                'close' => $special['close'],
                'cutoff' => isset($special['cutoff']) ? $special['cutoff'] : '',
                'prep_min' => isset($special['prep_min']) ? (int)$special['prep_min'] : 60,
                'enabled' => true
            ];
            
            $result = $this->process_day_schedule($date, $row);
            if ($result) {
                $result['special_day'] = $special['desc'] ?? 'Dia especial';
            }
            return $result ?: ['status' => 'undefined', 'special_day' => $special['desc'] ?? 'Dia especial'];
        }
        
        $dow_number = (int)$date->format('w');
        $key = $this->get_dow_key($dow_number);
        
        $week_day = $schedule['weekly'][$key] ?? null;
        if (!$week_day) {
            return ['status' => 'undefined'];
        }
        
        if (empty($week_day['open_enabled'])) {
            return ['status' => 'closed'];
        }
        
        $row = [
            'open' => $week_day['open'] ?? '',
            'close' => $week_day['close'] ?? '',
            'cutoff' => isset($week_day['cutoff']) ? $week_day['cutoff'] : '',
            'prep_min' => isset($week_day['prep_min']) ? (int)$week_day['prep_min'] : 60,
            'enabled' => true
        ];
        
        return $this->process_day_schedule($date, $row) ?: ['status' => 'undefined'];
    }
    
    private function process_day_schedule($date, $row) {
        if (!$row['enabled']) {
            return ['status' => 'closed'];
        }
        
        $open = $this->parse_time($row['open']);
        $close = $this->parse_time($row['close']);
        $prep = $row['prep_min'];
        
        if (!$open || !$close || $prep === null) {
            return ['status' => 'undefined'];
        }
        
        $open_dt = $date->setTime($open[0], $open[1], 0);
        $close_dt = $date->setTime($close[0], $close[1], 0);
        
        if ($close_dt <= $open_dt) {
            return ['status' => 'undefined'];
        }
        
        $cutoff_dt = null;
        if ($row['cutoff']) {
            $cut = $this->parse_time($row['cutoff']);
            if ($cut) {
                $cutoff_dt = $date->setTime($cut[0], $cut[1], 0);
            }
        }
        
        return [
            'status' => 'open',
            'open' => $open_dt,
            'close' => $close_dt,
            'prep_minutes' => $prep,
            'cutoff' => $cutoff_dt
        ];
    }
    
    private function find_special_day($date, $specials) {
        if (empty($specials) || !is_array($specials)) {
            return null;
        }
        
        $ymd = $date->format('Y-m-d');
        $md = $date->format('m-d');
        $dmy = $date->format('d/m/Y');
        $dm = $date->format('d/m');
        
        foreach ($specials as $special) {
            $date_sql = $special['date_sql'] ?? '';
            if ($date_sql) {
                $is_annual = !empty($special['annual']);
                
                if ($is_annual) {
                    if (substr($date_sql, 5) === $md) {
                        return $special;
                    }
                } else {
                    if ($date_sql === $ymd) {
                        return $special;
                    }
                }
            }
            
            $date_br = $special['date_br'] ?? '';
            if ($date_br) {
                $is_annual = !empty($special['annual']);
                
                if ($is_annual) {
                    $date_br_parts = explode('/', $date_br);
                    if (count($date_br_parts) >= 2) {
                        $compare_dm = $date_br_parts[0] . '/' . $date_br_parts[1];
                        if ($compare_dm === $dm) {
                            return $special;
                        }
                    }
                } else {
                    if ($date_br === $dmy) {
                        return $special;
                    }
                }
            }
        }
        
        return null;
    }
    
    private function next_open_day($from, $schedule) {
        $date = clone $from;
        
        for ($i = 1; $i <= 14; $i++) {
            $date = $date->modify('+1 day')->setTime(0, 0, 0);
            $day_schedule = $this->resolve_day_schedule($date, $schedule);
            
            if (($day_schedule['status'] ?? '') === 'open') {
                return [
                    'date' => $date,
                    'schedule' => $day_schedule
                ];
            }
        }
        return null;
    }
    
    private function parse_time($time_str) {
        $time_str = trim((string)$time_str);
        if ($time_str === '') return null;
        
        if (preg_match('~^(\d{1,2}):?(\d{2})$~', $time_str, $m)) {
            $h = (int)$m[1];
            $i = (int)$m[2];
            if ($h >= 0 && $h < 24 && $i >= 0 && $i < 60) {
                return [$h, $i];
            }
        }
        return null;
    }
    
    /**
     * ✅ CORRIGIDO v1.1.1: Formata mensagem comparando DATAS, não horas
     */
    private function format_prep_message($now, $deadline) {
        $now_date = $now->format('Y-m-d');
        $deadline_date = $deadline->format('Y-m-d');
        
        // ✅ CORREÇÃO: Cria objetos DateTime APENAS com a data (sem hora)
        $now_day = new \DateTime($now_date);
        $deadline_day = new \DateTime($deadline_date);
        
        // Diferença em DIAS CALENDÁRIO (não em horas)
        $interval = $now_day->diff($deadline_day);
        $days = (int)$interval->format('%a');
        
        if ($deadline_date === $now_date) {
            // HOJE
            $minutes = (int)(($deadline->getTimestamp() - $now->getTimestamp()) / 60);
            if ($minutes <= 120) {
                return "Pronto em {$minutes} minutos";
            } else {
                return "Pronto hoje às " . $deadline->format('H:i');
            }
        } elseif ($days === 1) {
            // AMANHÃ (diferença de exatamente 1 DIA CALENDÁRIO)
            return "Pronto amanhã às " . $deadline->format('H:i');
        } else {
            // OUTROS DIAS
            $day_names = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            $dow = $day_names[$deadline->format('w')];
            
            // Se for 2-7 dias, mostra o dia da semana
            if ($days >= 2 && $days <= 7) {
                return "Pronto {$dow} às " . $deadline->format('H:i');
            } else {
                // Mais de 7 dias, mostra data completa
                return "Pronto em " . $deadline->format('d/m') . 
                       " ({$dow}) às " . $deadline->format('H:i');
            }
        }
    }
}

// Inicializa
new Test_Prep_Time();