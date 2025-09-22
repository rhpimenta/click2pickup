<?php
namespace C2P;

if (!defined('ABSPATH')) exit;

/**
 * Auditoria central do Click2Pickup
 * - Loga POST de options.php (salvar settings)
 * - Loga pre_update/updated/added da option c2p_settings (com diff)
 * - Loga wp_mail (args) + sucesso/falha (com mensagem do servidor)
 * - Mantém ring buffer em option e arquivo persistente em uploads/c2p-logs/audit.log
 * - Renderiza painel na UI das configurações
 */
final class Audit {
    private static $booted = false;
    private static $ring_option = 'c2p_audit_ring';
    private static $ring_size   = 200;
    private static $log_dirname = 'c2p-logs';
    private static $log_filename= 'audit.log';

    public static function boot(): void {
        if (self::$booted) return;
        self::$booted = true;

        add_action('load-options.php', [__CLASS__, 'capture_options_submit'], 1);

        add_filter('option_c2p_settings', [__CLASS__, 'log_option_read']);
        add_filter('pre_update_option_c2p_settings', [__CLASS__, 'pre_update_c2p_settings'], 10, 2);
        add_action('updated_option', [__CLASS__, 'updated_option'], 10, 3);
        add_action('added_option',   [__CLASS__, 'added_option'],   10, 2);

        add_filter('wp_mail', [__CLASS__, 'mail_args_tap'], 10, 1);
        add_action('wp_mail_succeeded', [__CLASS__, 'mail_succeeded']);
        add_action('wp_mail_failed',     [__CLASS__, 'mail_failed']);
    }

    /* ===== infra de log ===== */
    private static function now(): string { return gmdate('Y-m-d H:i:s') . 'Z'; }

    private static function uploads_log_path(): string {
        $u = wp_get_upload_dir();
        $dir = trailingslashit($u['basedir']) . self::$log_dirname;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        return trailingslashit($dir) . self::$log_filename;
    }

    private static function redact($v) {
        if (is_string($v) && strlen($v) > 10000) return substr($v,0,10000) . '... [truncated]';
        return $v;
    }

    private static function context_to_string(array $ctx = []): string {
        if (!$ctx) return '';
        $safe = [];
        foreach ($ctx as $k => $v) {
            if (is_array($v) || is_object($v)) $v = json_decode(wp_json_encode($v), true);
            $safe[$k] = self::redact($v);
        }
        return wp_json_encode($safe, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public static function log(string $event, array $ctx = [], string $level = 'info'): void {
        $line = '[' . self::now() . "] [$level] $event";
        $ctxs = self::context_to_string($ctx);
        if ($ctxs !== '') $line .= ' ' . $ctxs;

        @file_put_contents(self::uploads_log_path(), $line . PHP_EOL, FILE_APPEND);

        $ring = get_option(self::$ring_option, []);
        if (!is_array($ring)) $ring = [];
        $ring[] = $line;
        if (count($ring) > self::$ring_size) $ring = array_slice($ring, -1 * self::$ring_size);
        update_option(self::$ring_option, $ring, false);
    }

    public static function get_recent(int $limit = 100): array {
        $ring = get_option(self::$ring_option, []);
        if (!is_array($ring)) return [];
        return array_slice($ring, -1 * max(1,$limit));
    }

    /* ===== options flow ===== */
    public static function capture_options_submit(): void {
        if (!is_admin() || 'options.php' !== $GLOBALS['pagenow']) return;
        if (empty($_POST['option_page']) || $_POST['option_page'] !== 'c2p_settings_group') return;
        self::log('options.php submit (raw)', [
            'option_page' => $_POST['option_page'] ?? null,
            'action'      => $_POST['action'] ?? null,
            'c2p_settings'=> $_POST['c2p_settings'] ?? null,
        ], 'debug');
    }

    public static function pre_update_c2p_settings($new, $old) {
        $diff = [];
        $keys = array_unique(array_merge(array_keys((array)$old), array_keys((array)$new)));
        foreach ($keys as $k) {
            $ov = $old[$k] ?? null;
            $nv = $new[$k] ?? null;
            if ($ov !== $nv) $diff[$k] = ['old'=>$ov,'new'=>$nv];
        }
        self::log('pre_update_option c2p_settings (diff)', ['changed'=>$diff], 'info');
        return $new;
    }

    public static function updated_option($option, $old, $value): void {
        if ($option !== 'c2p_settings') return;
        $changed = [];
        $keys = array_unique(array_merge(array_keys((array)$old), array_keys((array)$value)));
        foreach ($keys as $k) {
            $ov = $old[$k] ?? null;
            $nv = $value[$k] ?? null;
            if ($ov !== $nv) $changed[$k] = ['old'=>$ov,'new'=>$nv];
        }
        self::log('updated_option c2p_settings (diff)', ['changed'=>$changed], 'notice');
    }

    public static function added_option($option, $value): void {
        if ($option !== 'c2p_settings') return;
        self::log('added_option c2p_settings', ['value'=>$value], 'notice');
    }

    public static function log_option_read($val) {
        $is_plugin_settings = (is_admin() && isset($_GET['page']) && $_GET['page']==='c2p-settings');
        if ($is_plugin_settings) self::log('option read c2p_settings', ['keys'=>array_keys((array)$val)], 'debug');
        return $val;
    }

    /* ===== mail flow ===== */
    public static function mail_args_tap($args) {
        $tap = $args;
        if (isset($tap['message'])) { $tap['message_len'] = strlen((string)$tap['message']); unset($tap['message']); }
        self::log('wp_mail called', $tap, 'info');
        return $args;
    }
    public static function mail_succeeded($mail_data) {
        if (isset($mail_data['message'])) { $mail_data['message_len'] = strlen((string)$mail_data['message']); unset($mail_data['message']); }
        self::log('wp_mail_succeeded', $mail_data, 'success');
    }
    public static function mail_failed($wp_error) {
        $data = method_exists($wp_error,'get_error_data') ? $wp_error->get_error_data() : null;
        $msg  = method_exists($wp_error,'get_error_message') ? $wp_error->get_error_message() : (string)$wp_error;
        self::log('wp_mail_failed', ['message'=>$msg,'data'=>$data], 'error');
    }

    /* ===== painel ===== */
    public static function render_panel(): void {
        if (!current_user_can('manage_woocommerce')) return;

        $logs = self::get_recent(100);
        $u = wp_get_upload_dir();
        $url = trailingslashit($u['baseurl']) . self::$log_dirname . '/' . self::$log_filename;
        $file_exists = file_exists(self::uploads_log_path());

        echo '<div class="c2p-tools-box" style="margin-top:16px">';
        echo '<h2>Auditoria Click2Pickup</h2>';
        echo '<p>Últimas 100 linhas. Log completo em <code>wp-content/uploads/' . esc_html(self::$log_dirname . '/' . self::$log_filename) . '</code>.</p>';
        echo '<p>'.($file_exists ? ('Arquivo: <a href="'.esc_url($url).'" target="_blank" rel="noreferrer">baixar/ver</a>') : 'Arquivo ainda não criado.').'</p>';
        echo '<div class="c2p-report" style="max-height:420px; overflow:auto; font-family:monospace; font-size:12px; white-space:pre-wrap">';
        if ($logs) foreach ($logs as $line) echo esc_html($line) . "\n"; else echo 'Sem eventos ainda.';
        echo '</div>';
        echo '</div>';
    }
}
