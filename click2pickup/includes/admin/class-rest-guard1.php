<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST_Guard
 * - Centraliza pequenos "bloqueios" e proteções relativas à REST API.
 * - Garante o trait Tools antes de declarar a classe; se ausente, cria um no-op silencioso.
 */

// === Garante o trait Tools (carrega do caminho correto; fallback silencioso) ===
if ( ! trait_exists( __NAMESPACE__ . '\Tools' ) ) {
    // 1) Caminho relativo a este arquivo (estamos em includes/admin/)
    $tools_file_local = __DIR__ . '/settings-tabs/trait-tools.php';

    // 2) Caminho absoluto via constante do plugin (se existir)
    $tools_file_root  = ( defined('C2P_PATH') ? C2P_PATH : plugin_dir_path( dirname( __FILE__, 2 ) ) ) . 'includes/admin/settings-tabs/trait-tools.php';

    if ( file_exists( $tools_file_local ) ) {
        require_once $tools_file_local; // deve declarar \C2P\Tools
    } elseif ( file_exists( $tools_file_root ) ) {
        require_once $tools_file_root;  // fallback pelo root do plugin
    }

    // Se ainda não existir, define um trait vazio (sem logs)
    if ( ! trait_exists( __NAMESPACE__ . '\Tools' ) ) {
        trait Tools {}
    }
}

// ==============================================================================

final class REST_Guard {
    use Tools;

    /** @var self|null */
    private static $instance = null;

    /** Singleton */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Mantém permissivo por padrão; ponto único para colocar guards no futuro
        add_filter( 'rest_pre_dispatch', [ $this, 'maybe_guard' ], 9, 3 );
    }

    /**
     * Ponto central para bloqueios/validações em chamadas REST.
     * Mantém tudo liberado por padrão.
     *
     * @param mixed            $result
     * @param \WP_REST_Server  $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public function maybe_guard( $result, $server, $request ) {
        // Ex.: inspecionar $request->get_route() / permissões aqui, se precisar.
        return $result;
    }
}
