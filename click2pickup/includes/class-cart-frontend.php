<?php
namespace C2P;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Cart_Frontend — Modo compatibilidade
 *
 * Objetivo: voltar ao carrinho padrão do WooCommerce.
 * - Shortcode [c2p_cart] passa a renderizar [woocommerce_cart]
 * - Não injeta JS/CSS, não cria passos, não intercepta calculadoras
 * - Seguro para manter no bootstrap do plugin
 */
class Cart_Frontend {

    /** @var self|null */
    private static $instance = null;

    /** Compat antigo */
    public static function init() { return self::instance(); }

    /** Singleton */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->setup_hooks();
        }
        return self::$instance;
    }

    private function __construct() {}

    /** Hooks mínimos (compat) */
    private function setup_hooks() {
        // Redireciona o shortcode do plugin para o carrinho nativo do Woo
        add_shortcode( 'c2p_cart', array( $this, 'render_shortcode' ) );
    }

    /**
     * Renderiza o carrinho padrão do WooCommerce
     * Mantém mensagens/avisos e template/theme atuais
     */
    public function render_shortcode( $atts = array(), $content = null ) {
        // Se o Woo estiver ativo, só delega para o shortcode oficial
        if ( shortcode_exists( 'woocommerce_cart' ) ) {
            return do_shortcode( '[woocommerce_cart]' );
        }

        // Fallback defensivo (Woo inativo)
        ob_start();
        echo '<div class="woocommerce">';
        if ( function_exists( 'woocommerce_output_all_notices' ) ) {
            woocommerce_output_all_notices();
        }
        echo '<p>' . esc_html__( 'WooCommerce não está disponível.', 'woocommerce' ) . '</p>';
        echo '</div>';
        return ob_get_clean();
    }
}

// Bootstrap (mantém compat com boots antigos via ::init())
add_action( 'plugins_loaded', function () {
    Cart_Frontend::instance();
}, 20 );
