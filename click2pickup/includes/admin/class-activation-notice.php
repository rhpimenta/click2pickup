<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Exibe um admin_notice único com a saída capturada na ativação (c2p_activation_output),
 * limita o tamanho e limpa a opção após exibir.
 */
class Activation_Notice {
    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Mostra nas telas de admin (não em AJAX/CRON/front)
        add_action('admin_notices', [$this, 'maybe_show_notice'], 12);
        // Opcional: Multisite - também no Network Admin
        add_action('network_admin_notices', [$this, 'maybe_show_notice'], 12);
    }

    /**
     * Exibe aviso se houver conteúdo em c2p_activation_output e o usuário puder gerenciar.
     * Após exibir, apaga a opção para tornar o aviso "descartável".
     */
    public function maybe_show_notice(): void {
        if ( ! is_admin() || wp_doing_ajax() ) {
            return;
        }
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        $raw = get_option('c2p_activation_output', '');
        if ( ! is_string($raw) || $raw === '' ) {
            return;
        }

        // Limite de exibição para evitar telas gigantes
        $max = apply_filters('c2p_activation_output_maxlen', 2000);
        $truncated = mb_substr($raw, 0, $max);
        $is_truncated = (mb_strlen($raw) > $max);

        // Monta HTML do aviso (aviso amarelo, descartável)
        echo '<div class="notice notice-warning is-dismissible" style="white-space:normal; overflow:hidden;">';
        echo '<p><strong>Click2Pickup – Saída durante a ativação detectada</strong></p>';
        echo '<p>Encontramos texto impresso durante a ativação do plugin. Isso pode causar o aviso do WordPress sobre "saída inesperada". Abaixo está um trecho para diagnóstico (conteúdo salvo em <code>c2p_activation_output</code>):</p>';

        echo '<pre style="background:#fff; border:1px solid #ccd0d4; padding:8px; max-height:240px; overflow:auto; white-space:pre-wrap;">';
        echo esc_html($truncated);
        if ( $is_truncated ) {
            echo esc_html("\n… (truncado)");
        }
        echo '</pre>';

        if ( $is_truncated ) {
            echo '<p style="margin-top:6px;"><em>Dica:</em> Aumente o limite com o filtro <code>c2p_activation_output_maxlen</code>.</p>';
        }

        echo '</div>';

        // Limpa para não reaparecer
        delete_option('c2p_activation_output');
    }
}
