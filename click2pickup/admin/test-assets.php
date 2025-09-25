<?php
/**
 * Página de teste para verificar carregamento de assets
 */

// Esta é uma página de teste temporária
?>
<!DOCTYPE html>
<html>
<head>
    <title>Teste de Assets - Click2Pickup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
        }
        .test-item {
            margin: 10px 0;
            padding: 10px;
            background: #f5f5f5;
            border-left: 4px solid #ccc;
        }
        .success {
            border-left-color: green;
            background: #e8f5e9;
        }
        .error {
            border-left-color: red;
            background: #ffebee;
        }
    </style>
</head>
<body>
    <h1>Teste de Assets - Click2Pickup</h1>
    
    <div class="test-item <?php echo defined('C2P_PLUGIN_DIR') ? 'success' : 'error'; ?>">
        <strong>Plugin Constants:</strong><br>
        C2P_PLUGIN_DIR: <?php echo defined('C2P_PLUGIN_DIR') ? C2P_PLUGIN_DIR : 'NOT DEFINED'; ?><br>
        C2P_PLUGIN_URL: <?php echo defined('C2P_PLUGIN_URL') ? C2P_PLUGIN_URL : 'NOT DEFINED'; ?>
    </div>
    
    <div class="test-item <?php echo file_exists(C2P_PLUGIN_DIR . 'assets/css/admin.css') ? 'success' : 'error'; ?>">
        <strong>CSS File:</strong><br>
        Path: <?php echo C2P_PLUGIN_DIR . 'assets/css/admin.css'; ?><br>
        Exists: <?php echo file_exists(C2P_PLUGIN_DIR . 'assets/css/admin.css') ? 'YES' : 'NO'; ?><br>
        URL: <a href="<?php echo C2P_PLUGIN_URL . 'assets/css/admin.css'; ?>" target="_blank">
            <?php echo C2P_PLUGIN_URL . 'assets/css/admin.css'; ?>
        </a>
    </div>
    
    <div class="test-item <?php echo file_exists(C2P_PLUGIN_DIR . 'assets/js/admin.js') ? 'success' : 'error'; ?>">
        <strong>JS File:</strong><br>
        Path: <?php echo C2P_PLUGIN_DIR . 'assets/js/admin.js'; ?><br>
        Exists: <?php echo file_exists(C2P_PLUGIN_DIR . 'assets/js/admin.js') ? 'YES' : 'NO'; ?><br>
        URL: <a href="<?php echo C2P_PLUGIN_URL . 'assets/js/admin.js'; ?>" target="_blank">
            <?php echo C2P_PLUGIN_URL . 'assets/js/admin.js'; ?>
        </a>
    </div>
    
    <div class="test-item">
        <strong>Directory Structure:</strong><br>
        <pre><?php
        if (function_exists('scandir')) {
            $dirs = array(
                'Root' => C2P_PLUGIN_DIR,
                'Assets' => C2P_PLUGIN_DIR . 'assets/',
                'CSS' => C2P_PLUGIN_DIR . 'assets/css/',
                'JS' => C2P_PLUGIN_DIR . 'assets/js/'
            );
            
            foreach ($dirs as $label => $dir) {
                echo "\n$label: ";
                if (is_dir($dir)) {
                    $files = scandir($dir);
                    echo implode(', ', array_filter($files, function($f) {
                        return $f != '.' && $f != '..';
                    }));
                } else {
                    echo "DIRECTORY NOT FOUND";
                }
            }
        }
        ?></pre>
    </div>
    
    <div class="test-item">
        <strong>Test CSS Loading:</strong><br>
        <div class="c2p-form-section" style="margin-top: 10px;">
            <h2>Se este card tiver borda azul no topo, o CSS está funcionando!</h2>
            <p>Este é um teste de carregamento de CSS.</p>
        </div>
    </div>
    
    <script>
        console.log('Assets Test Page Loaded');
        if (typeof jQuery !== 'undefined') {
            console.log('jQuery Version:', jQuery.fn.jquery);
        } else {
            console.log('jQuery not loaded');
        }
    </script>
</body>
</html>