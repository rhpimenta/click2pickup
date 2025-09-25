<?php
/**
 * Integrações com WooCommerce (placeholders e pequenos ajustes)
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_WooCommerce_Integration {

    public function __construct() {
        // Placeholder de integrações futuras.
        // Aqui podemos adicionar filtros de exibição, endpoints, ou compatibilidade com outros plugins.
        add_action('init', array($this, 'maybe_init'));
    }

    public function maybe_init() {
        // Mantido para futuras extensões
    }
}