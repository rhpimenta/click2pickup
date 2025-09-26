<?php
/**
 * DEBUG COMPLETO - Click2Pickup Plugin
 * Checagem completa e dinâmica do sistema
 * 
 * @author RH Pimenta
 * @version 2.2 (fix: CEP seguro; reflection para métodos privados)
 */

// Carregar WordPress
require_once('../../../wp-load.php');

// Verificar se o usuário é admin
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

// === Helpers ================================================================

/** Badge de status */
function get_status_badge($is_ok) {
    return $is_ok ?
        '<span style="color: #28a745; font-weight: bold;">✅ OK</span>' :
        '<span style="color: #dc3545; font-weight: bold;">❌ ERRO</span>';
}

/** Formata arrays em string legível */
function format_array($array) {
    if (empty($array)) return 'Vazio';
    if (is_string($array)) {
        $decoded = json_decode($array, true);
        if (is_array($decoded)) return implode(', ', $decoded);
        return $array;
    }
    return implode(', ', (array) $array);
}

/** Normaliza ID do método (remove prefixo de zona '123:') */
function c2p_normalize_method_id($method_id) {
    if (!is_string($method_id)) return $method_id;
    return preg_replace('/^\d+:/', '', $method_id);
}

/** Obtém o primeiro campo existente/não-vazio do registro (objeto/array) */
function c2p_row_field($row, $keys, $default = '') {
    foreach ((array) $keys as $k) {
        if (is_object($row) && property_exists($row, $k) && $row->$k !== null && $row->$k !== '') {
            return $row->$k;
        }
        if (is_array($row) && array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

/** Fallback para método de pickup caso handler falhe/não exista */
function c2p_fallback_pickup_method($saved_methods, $pickup_methods) {
    $saved_methods = is_array($saved_methods) ? $saved_methods : [];
    foreach ($saved_methods as $m) {
        $norm = c2p_normalize_method_id($m);
        if (isset($pickup_methods[$norm])) return $norm;
    }
    if (!empty($pickup_methods)) {
        $keys = array_keys($pickup_methods);
        return $keys[0];
    }
    return '';
}

/** Chama get_pickup_method_for_location com segurança (public/privado) */
function c2p_safe_get_pickup_method_for_location($handler, $store_row, $pickup_methods) {
    $method_name = 'get_pickup_method_for_location';

    $saved_methods = [];
    if (!empty($store_row->shipping_methods)) {
        $decoded = json_decode($store_row->shipping_methods, true);
        if (is_array($decoded)) $saved_methods = $decoded;
    }

    if (!$handler) {
        return c2p_fallback_pickup_method($saved_methods, $pickup_methods);
    }

    if (is_callable([$handler, $method_name])) {
        try {
            return (string) $handler->{$method_name}($store_row);
        } catch (\Throwable $e) {
            // tenta Reflection abaixo
        }
    }

    if (method_exists($handler, $method_name)) {
        try {
            $ref = new \ReflectionObject($handler);
            if ($ref->hasMethod($method_name)) {
                $m = $ref->getMethod($method_name);
                if (!$m->isPublic()) $m->setAccessible(true);
                return (string) $m->invoke($handler, $store_row);
            }
        } catch (\Throwable $e) {
            // falhou tudo → fallback
        }
    }

    return c2p_fallback_pickup_method($saved_methods, $pickup_methods);
}

// === Coletas principais =====================================================

global $wpdb;
$locations_table = $wpdb->prefix . 'c2p_locations';

$all_locations = $wpdb->get_results("SELECT * FROM $locations_table ORDER BY type DESC, name ASC");

$stores = array_filter($all_locations ?: [], function($loc) { return isset($loc->type) && $loc->type === 'store'; });
$distribution_centers = array_filter($all_locations ?: [], function($loc) { return isset($loc->type) && $loc->type === 'distribution_center'; });

$all_shipping_methods = [];
$pickup_methods = [];
$delivery_methods = [];

if (class_exists('WC_Shipping_Zones')) {
    $zones = WC_Shipping_Zones::get_zones();
    $zones[] = array('zone_id' => 0); // zona padrão
    
    foreach ($zones as $zone_data) {
        $zone_id = isset($zone_data['zone_id']) ? $zone_data['zone_id'] : 0;
        $zone = new WC_Shipping_Zone($zone_id);
        $zone_name = $zone->get_zone_name();
        
        foreach ($zone->get_shipping_methods(true) as $method) {
            if (!$method->is_enabled()) continue;
            
            $method_id = $method->id . ':' . $method->get_instance_id();
            $method_title = $method->get_title();
            $method_type = $method->id;
            
            $method_info = array(
                'id'       => $method_id,
                'title'    => $method_title,
                'type'     => $method_type,
                'zone'     => $zone_name,
                'zone_id'  => $zone_id,
                'enabled'  => $method->is_enabled()
            );
            
            $all_shipping_methods[$method_id] = $method_info;
            
            if ($method_type === 'local_pickup' || stripos($method_title, 'retira') !== false || stripos($method_title, 'pickup') !== false) {
                $pickup_methods[$method_id] = $method_info;
            } else {
                $delivery_methods[$method_id] = $method_info;
            }
        }
    }
}

// Handler de carrinho
$handler_exists = class_exists('C2P_Cart_Handler');
$handler = $handler_exists ? new C2P_Cart_Handler() : null;

$method_exists = $handler ? method_exists($handler, 'get_pickup_method_for_location') : false;
$method_public = $handler ? is_callable([$handler, 'get_pickup_method_for_location']) : false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Completo - Click2Pickup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;padding:20px;}
        .container{max-width:1400px;margin:0 auto;background:white;border-radius:20px;padding:30px;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
        h1{color:#333;margin-bottom:30px;padding-bottom:20px;border-bottom:3px solid #667eea;display:flex;align-items:center;gap:10px;}
        h2{color:#667eea;margin:30px 0 20px;padding:15px;background:linear-gradient(90deg,#f8f9fa 0%,#e9ecef 100%);border-radius:10px;display:flex;align-items:center;gap:10px;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:30px;}
        .stat-card{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:20px;border-radius:15px;box-shadow:0 5px 15px rgba(102,126,234,.3);}
        .stat-card h3{font-size:14px;opacity:.9;margin-bottom:10px;}
        .stat-card .value{font-size:32px;font-weight:bold;}
        table{width:100%;border-collapse:collapse;margin:20px 0;box-shadow:0 2px 10px rgba(0,0,0,.1);border-radius:10px;overflow:hidden;}
        th{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:15px;text-align:left;font-weight:600;}
        td{padding:12px 15px;border-bottom:1px solid #e9ecef;}
        tr:hover{background:#f8f9fa;}
        .method-badge{display:inline-block;padding:5px 10px;border-radius:5px;font-size:12px;font-weight:600;margin:2px;}
        .method-pickup{background:#d4edda;color:#155724;}
        .method-delivery{background:#cce5ff;color:#004085;}
        .debug-output{background:#f8f9fa;border:1px solid #dee2e6;border-radius:10px;padding:15px;margin:10px 0;font-family:'Courier New',monospace;font-size:12px;max-height:300px;overflow-y:auto;}
        .warning-box{background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:15px;border-radius:10px;margin:20px 0;}
        .success-box{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:15px;border-radius:10px;margin:20px 0;}
        .error-box{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:15px;border-radius:10px;margin:20px 0;}
        .tabs{display:flex;gap:10px;margin:30px 0 20px;border-bottom:2px solid #e9ecef;}
        .tab{padding:10px 20px;background:#f8f9fa;border:none;border-radius:10px 10px 0 0;cursor:pointer;font-weight:600;transition:.3s;}
        .tab.active{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;}
        .tab-content{display:none;animation:fadeIn .5s;}
        .tab-content.active{display:block;}
        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        .method-test{background:#f8f9fa;border-radius:10px;padding:15px;margin:10px 0;}
        .test-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
        .test-details{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #dee2e6;}
        .detail-item{display:flex;flex-direction:column;}
        .detail-label{font-size:12px;color:#6c757d;margin-bottom:2px;}
        .detail-value{font-weight:600;color:#333;}
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug Completo - Click2Pickup Plugin v2.2</h1>
        
        <!-- Estatísticas Gerais -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total de Locais</h3>
                <div class="value"><?php echo count($all_locations ?: []); ?></div>
            </div>
            <div class="stat-card">
                <h3>Lojas Físicas</h3>
                <div class="value"><?php echo count($stores ?: []); ?></div>
            </div>
            <div class="stat-card">
                <h3>Centros de Distribuição</h3>
                <div class="value"><?php echo count($distribution_centers ?: []); ?></div>
            </div>
            <div class="stat-card">
                <h3>Métodos de Shipping</h3>
                <div class="value"><?php echo count($all_shipping_methods ?: []); ?></div>
            </div>
        </div>
        
        <!-- Verificações do Sistema -->
        <h2>⚙️ Verificações do Sistema</h2>
        <table>
            <thead>
                <tr>
                    <th>Componente</th>
                    <th>Status</th>
                    <th>Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>WooCommerce</td>
                    <td><?php echo get_status_badge(class_exists('WooCommerce')); ?></td>
                    <td><?php echo class_exists('WooCommerce') ? 'Versão ' . esc_html(WC()->version) : 'Não instalado'; ?></td>
                </tr>
                <tr>
                    <td>Classe C2P_Cart_Handler</td>
                    <td><?php echo get_status_badge($handler_exists); ?></td>
                    <td><?php echo $handler_exists ? 'Carregada corretamente' : 'Não encontrada'; ?></td>
                </tr>
                <tr>
                    <td>Método get_pickup_method_for_location</td>
                    <td><?php echo get_status_badge($method_exists); ?></td>
                    <td>
                        <?php
                        if (!$method_exists) {
                            echo 'Não encontrado';
                        } else {
                            echo $method_public ? 'Disponível (público)' : 'Disponível (privado) – usando Reflection';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Tabela de Locais</td>
                    <td><?php echo get_status_badge($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $locations_table)) == $locations_table); ?></td>
                    <td><?php echo esc_html($locations_table); ?></td>
                </tr>
                <tr>
                    <td>PHP Version</td>
                    <td><?php echo get_status_badge(version_compare(PHP_VERSION, '7.4', '>=')); ?></td>
                    <td><?php echo esc_html(PHP_VERSION); ?></td>
                </tr>
            </tbody>
        </table>
        
        <!-- Tabs de Navegação -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('pickup-tests', event)">🏪 Testes de Pickup</button>
            <button class="tab" onclick="showTab('delivery-tests', event)">🚚 Testes de Delivery</button>
            <button class="tab" onclick="showTab('all-methods', event)">📦 Todos os Métodos</button>
            <button class="tab" onclick="showTab('locations-data', event)">📍 Dados dos Locais</button>
        </div>
        
        <!-- Tab: Testes de Pickup -->
        <div id="pickup-tests" class="tab-content active">
            <h2>🏪 Teste de Seleção de Métodos de Pickup</h2>
            
            <?php if (empty($stores)): ?>
                <div class="warning-box">
                    <strong>⚠️ Nenhuma loja encontrada no sistema.</strong>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Loja</th>
                            <th>Cidade</th>
                            <th>Estado</th>
                            <th>Métodos Salvos</th>
                            <th>Método Retornado</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stores as $store): 
                            $saved_methods = json_decode($store->shipping_methods ?? '', true) ?: array();
                            $returned_method = c2p_safe_get_pickup_method_for_location($handler, $store, $pickup_methods);

                            $is_correct = false;
                            if (!empty($saved_methods)) {
                                $clean_saved = array_map('c2p_normalize_method_id', $saved_methods);
                                $is_correct = in_array($returned_method, $clean_saved, true) || isset($pickup_methods[$returned_method]);
                            } else {
                                $is_correct = $returned_method !== '' && isset($pickup_methods[$returned_method]);
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($store->name); ?></strong></td>
                            <td><?php echo esc_html(c2p_row_field($store, ['city'], '')); ?></td>
                            <td><?php echo esc_html(c2p_row_field($store, ['state','uf'], '')); ?></td>
                            <td>
                                <?php if (!empty($saved_methods)): ?>
                                    <span class="method-badge method-pickup">
                                        <?php echo esc_html(format_array(array_map('c2p_normalize_method_id', $saved_methods))); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">Nenhum</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($returned_method): ?>
                                    <span class="method-badge method-pickup">
                                        <?php echo esc_html($returned_method); ?>
                                    </span>
                                    <?php if (isset($pickup_methods[$returned_method])): ?>
                                        <br><small><?php echo esc_html($pickup_methods[$returned_method]['title']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #dc3545;">Não definido</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo get_status_badge($is_correct); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Testes Detalhados para TODAS as Lojas -->
                <h2>🔬 Testes Detalhados - Todas as Lojas</h2>
                <?php foreach ($stores as $store): 
                    $saved_methods = json_decode($store->shipping_methods ?? '', true) ?: array();
                    $returned_method = c2p_safe_get_pickup_method_for_location($handler, $store, $pickup_methods);

                    $is_correct = !empty($saved_methods) ? 
                        in_array($returned_method, array_map('c2p_normalize_method_id', $saved_methods), true) :
                        ($returned_method !== '' && isset($pickup_methods[$returned_method]));
                ?>
                <div class="method-test">
                    <div class="test-header">
                        <h3>📍 <?php echo esc_html($store->name); ?></h3>
                        <?php echo get_status_badge($is_correct); ?>
                    </div>
                    <div class="test-details">
                        <div class="detail-item">
                            <span class="detail-label">Estado</span>
                            <span class="detail-value"><?php echo esc_html(c2p_row_field($store, ['state','uf'], '')); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cidade</span>
                            <span class="detail-value"><?php echo esc_html(c2p_row_field($store, ['city'], '')); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Métodos Salvos</span>
                            <span class="detail-value"><?php echo esc_html(format_array(array_map('c2p_normalize_method_id', $saved_methods))); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Método Retornado</span>
                            <span class="detail-value"><?php echo esc_html($returned_method ?: '—'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Título do Método</span>
                            <span class="detail-value">
                                <?php echo isset($pickup_methods[$returned_method]) ? 
                                    esc_html($pickup_methods[$returned_method]['title']) : 'N/A'; ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Zona</span>
                            <span class="detail-value">
                                <?php echo isset($pickup_methods[$returned_method]) ? 
                                    esc_html($pickup_methods[$returned_method]['zone']) : 'N/A'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Tab: Testes de Delivery -->
        <div id="delivery-tests" class="tab-content">
            <h2>🚚 Centros de Distribuição e Métodos de Entrega</h2>
            
            <?php if (empty($distribution_centers)): ?>
                <div class="warning-box">
                    <strong>⚠️ Nenhum centro de distribuição encontrado.</strong>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Centro de Distribuição</th>
                            <th>Cidade</th>
                            <th>Estado</th>
                            <th>CEP</th>
                            <th>Delivery Habilitado</th>
                            <th>Métodos Disponíveis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distribution_centers as $dc): 
                            $saved_methods = json_decode($dc->shipping_methods ?? '', true) ?: array();
                            $dc_postcode = c2p_row_field($dc, ['postcode','zip_code','zipcode','postal_code','cep','zip'], '—'); // <<< FIX AQUI
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($dc->name); ?></strong></td>
                            <td><?php echo esc_html(c2p_row_field($dc, ['city'], '')); ?></td>
                            <td><?php echo esc_html(c2p_row_field($dc, ['state','uf'], '')); ?></td>
                            <td><?php echo esc_html($dc_postcode); ?></td>
                            <td><?php echo get_status_badge(!empty($dc->delivery_enabled)); ?></td>
                            <td>
                                <?php $available_delivery = count($delivery_methods); ?>
                                <span class="method-badge method-delivery">
                                    <?php echo esc_html($available_delivery); ?> métodos de entrega
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Métodos de Entrega Disponíveis -->
                <h3>📦 Métodos de Entrega Configurados</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID do Método</th>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Zona</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($delivery_methods as $method_id => $method): ?>
                        <tr>
                            <td><code><?php echo esc_html($method_id); ?></code></td>
                            <td><strong><?php echo esc_html($method['title']); ?></strong></td>
                            <td><span class="method-badge method-delivery"><?php echo esc_html($method['type']); ?></span></td>
                            <td><?php echo esc_html($method['zone']); ?></td>
                            <td><?php echo get_status_badge(!empty($method['enabled'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Tab: Todos os Métodos -->
        <div id="all-methods" class="tab-content">
            <h2>📦 Todos os Métodos de Shipping Disponíveis</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>ID do Método</th>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Categoria</th>
                        <th>Zona</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_shipping_methods as $method_id => $method): 
                        $is_pickup = isset($pickup_methods[$method_id]);
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($method_id); ?></code></td>
                        <td><strong><?php echo esc_html($method['title']); ?></strong></td>
                        <td><?php echo esc_html($method['type']); ?></td>
                        <td>
                            <?php if ($is_pickup): ?>
                                <span class="method-badge method-pickup">Retirada</span>
                            <?php else: ?>
                                <span class="method-badge method-delivery">Entrega</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($method['zone']); ?></td>
                        <td><?php echo get_status_badge(!empty($method['enabled'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Tab: Dados dos Locais -->
        <div id="locations-data" class="tab-content">
            <h2>📍 Dados Completos dos Locais</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Endereço</th>
                        <th>Cidade/Estado</th>
                        <th>Pickup</th>
                        <th>Delivery</th>
                        <th>Prioridade</th>
                        <th>Ativo</th>
                        <th>Métodos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_locations as $location): ?>
                    <tr>
                        <td><?php echo (int) $location->id; ?></td>
                        <td><strong><?php echo esc_html($location->name); ?></strong></td>
                        <td>
                            <?php if ($location->type === 'store'): ?>
                                <span class="method-badge method-pickup">Loja</span>
                            <?php else: ?>
                                <span class="method-badge method-delivery">CD</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(c2p_row_field($location, ['address','endereco','street'], '')); ?></td>
                        <td><?php echo esc_html(c2p_row_field($location, ['city'], '') . ', ' . c2p_row_field($location, ['state','uf'], '')); ?></td>
                        <td><?php echo get_status_badge(!empty($location->pickup_enabled)); ?></td>
                        <td><?php echo get_status_badge(!empty($location->delivery_enabled)); ?></td>
                        <td><?php echo (int) ($location->priority ?? 0); ?></td>
                        <td><?php echo get_status_badge(!empty($location->is_active)); ?></td>
                        <td>
                            <?php 
                            $methods = json_decode($location->shipping_methods ?? '', true) ?: array();
                            if (!empty($methods)): ?>
                                <small><?php echo esc_html(implode(', ', array_map('c2p_normalize_method_id', $methods))); ?></small>
                            <?php else: ?>
                                <span style="color: #6c757d;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Dados Raw do Banco -->
            <h3>💾 Verificação dos Dados Salvos no Banco</h3>
            <div class="debug-output">
                <?php 
                foreach ($all_locations as $location) {
                    echo "<strong>" . esc_html($location->name) . "</strong><br>";
                    echo "Campo shipping_methods: " . esc_html($location->shipping_methods) . "<br>";
                    $decoded = json_decode($location->shipping_methods ?? '', true);
                    echo "Decodificado: ";
                    if (is_array($decoded)) {
                        echo esc_html(implode(', ', array_map('c2p_normalize_method_id', $decoded)));
                    } else {
                        echo '—';
                    }
                    echo "<br><br>";
                }
                ?>
            </div>
        </div>
        
        <!-- Resumo -->
        <?php 
        $problems = array();
        $warnings = array();
        $success = array();
        
        if (!$handler_exists) $problems[] = "Classe C2P_Cart_Handler não encontrada";
        if (!$method_exists) $problems[] = "Método get_pickup_method_for_location não existe";
        if (empty($all_locations)) $problems[] = "Nenhum local cadastrado no sistema";
        if (empty($pickup_methods)) $warnings[] = "Nenhum método de pickup configurado no WooCommerce";
        if (empty($delivery_methods)) $warnings[] = "Nenhum método de entrega configurado no WooCommerce";
        
        if (!empty($stores)) $success[] = count($stores) . " loja(s) cadastrada(s)";
        if (!empty($distribution_centers)) $success[] = count($distribution_centers) . " centro(s) de distribuição cadastrado(s)";
        if (!empty($all_shipping_methods)) $success[] = count($all_shipping_methods) . " método(s) de shipping configurado(s)";
        ?>
        
        <?php if (!empty($problems)): ?>
        <div class="error-box">
            <h3>❌ Problemas Críticos Encontrados</h3>
            <ul>
                <?php foreach ($problems as $problem): ?>
                    <li><?php echo esc_html($problem); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($warnings)): ?>
        <div class="warning-box">
            <h3>⚠️ Avisos</h3>
            <ul>
                <?php foreach ($warnings as $warning): ?>
                    <li><?php echo esc_html($warning); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
        <div class="success-box">
            <h3>✅ Verificações OK</h3>
            <ul>
                <?php foreach ($success as $item): ?>
                    <li><?php echo esc_html($item); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Informações do Sistema -->
        <h2>ℹ️ Informações do Sistema</h2>
        <div class="debug-output">
            <strong>Data/Hora:</strong> <?php echo esc_html(current_time('Y-m-d H:i:s')); ?><br>
            <strong>PHP Version:</strong> <?php echo esc_html(PHP_VERSION); ?><br>
            <strong>WordPress Version:</strong> <?php echo esc_html(get_bloginfo('version')); ?><br>
            <strong>WooCommerce Version:</strong> <?php echo class_exists('WooCommerce') ? esc_html(WC()->version) : 'Não instalado'; ?><br>
            <strong>Plugin Path:</strong> <?php echo defined('C2P_PLUGIN_PATH') ? esc_html(C2P_PLUGIN_PATH) : 'Não definido'; ?><br>
            <strong>Plugin Version:</strong> <?php echo defined('C2P_VERSION') ? esc_html(C2P_VERSION) : 'Não definido'; ?><br>
        </div>
    </div>
    
    <script>
        function showTab(tabName, event) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            if (event && event.target) event.target.classList.add('active');
            const el = document.getElementById(tabName);
            if (el) el.classList.add('active');
        }
        // setTimeout(function(){ location.reload(); }, 30000); // opcional
    </script>
</body>
</html>
