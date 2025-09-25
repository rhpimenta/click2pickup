<?php
/**
 * Arquivo de teste para verificar carregamento de assets
 * Coloque este arquivo na raiz do plugin click2pickup
 */

// Verificar se WordPress está carregado
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

// Informações de debug
echo '<h1>🔍 Click2Pickup - Teste de Assets</h1>';
echo '<hr>';

// Verificar constantes
echo '<h2>1. Constantes do Plugin:</h2>';
echo '<pre>';
echo 'C2P_PLUGIN_URL: ' . (defined('C2P_PLUGIN_URL') ? C2P_PLUGIN_URL : 'NÃO DEFINIDA') . "\n";
echo 'C2P_VERSION: ' . (defined('C2P_VERSION') ? C2P_VERSION : 'NÃO DEFINIDA') . "\n";
echo '</pre>';

// Verificar arquivos
echo '<h2>2. Verificação de Arquivos:</h2>';
$plugin_dir = plugin_dir_path(__FILE__);
$files_to_check = [
    'assets/css/cart.css',
    'assets/js/cart.js',
    'includes/class-c2p-cart-handler.php'
];

echo '<ul>';
foreach ($files_to_check as $file) {
    $full_path = $plugin_dir . $file;
    if (file_exists($full_path)) {
        $size = filesize($full_path);
        $modified = date('Y-m-d H:i:s', filemtime($full_path));
        echo '<li>✅ ' . $file . ' (Tamanho: ' . $size . ' bytes, Modificado: ' . $modified . ')</li>';
    } else {
        echo '<li>❌ ' . $file . ' - NÃO ENCONTRADO</li>';
    }
}
echo '</ul>';

// Verificar se estamos na página do carrinho
echo '<h2>3. Página Atual:</h2>';
echo '<pre>';
echo 'É página do carrinho? ' . (is_cart() ? 'SIM' : 'NÃO') . "\n";
echo 'URL atual: ' . $_SERVER['REQUEST_URI'] . "\n";
echo '</pre>';

// Verificar enfileiramento
echo '<h2>4. Scripts e Estilos Enfileirados:</h2>';
global $wp_styles, $wp_scripts;

echo '<h3>Estilos:</h3>';
echo '<ul>';
foreach ($wp_styles->registered as $handle => $style) {
    if (strpos($handle, 'c2p') !== false) {
        echo '<li>' . $handle . ' => ' . $style->src . '</li>';
    }
}
echo '</ul>';

echo '<h3>Scripts:</h3>';
echo '<ul>';
foreach ($wp_scripts->registered as $handle => $script) {
    if (strpos($handle, 'c2p') !== false) {
        echo '<li>' . $handle . ' => ' . $script->src . '</li>';
    }
}
echo '</ul>';

// Link direto para os arquivos
echo '<h2>5. Links Diretos para Testar:</h2>';
$base_url = plugins_url('', __FILE__);
echo '<ul>';
echo '<li>CSS: <a href="' . $base_url . '/assets/css/cart.css" target="_blank">' . $base_url . '/assets/css/cart.css</a></li>';
echo '<li>JS: <a href="' . $base_url . '/assets/js/cart.js" target="_blank">' . $base_url . '/assets/js/cart.js</a></li>';
echo '</ul>';

// Botão para ir ao carrinho
echo '<hr>';
echo '<a href="' . wc_get_cart_url() . '" style="display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px;">Ir para o Carrinho</a>';
?>