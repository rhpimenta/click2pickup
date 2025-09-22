<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

class Email_Debug {
    private static $ring_option = 'c2p_email_debug_ring'; // guarda últimos N logs
    private static $ring_limit  = 50;
    private static $smtp_buffer = ''; // acumula debug da sessão atual
    private static $last_args   = null; // último pacote de args do wp_mail (pré-envio)

    public static function boot(): void {
        // Captura os dados antes do envio
        add_filter('wp_mail', [__CLASS__, 'capture_wp_mail'], 10, 1);
        // Captura debug do PHPMailer/SMTP quando debug estiver ligado
        add_action('phpmailer_init', [__CLASS__, 'hook_phpmailer']);
        // Sucesso e erro
        add_action('wp_mail_succeeded', [__CLASS__, 'on_success'], 10, 1);
        add_action('wp_mail_failed',    [__CLASS__, 'on_failed'],  10, 1);

        // Ação admin para limpar logs
        add_action('admin_post_c2p_email_debug_clear', [__CLASS__, 'handle_clear']);
    }

    /** Checa se o modo debug está habilitado nas configs da aba de e-mails */
    private static function enabled(): bool {
        $cfg = get_option('c2p_email_cfg', []);
        return !empty($cfg['debug_enable']);
    }

    /** Pré-envio: guarda subject/to/headers/message para enriquecer o log */
    public static function capture_wp_mail($args) {
        if ( self::enabled() ) {
            self::$last_args = $args; // ['to','subject','message','headers','attachments']
            self::$smtp_buffer = '';  // reseta o buffer para este envio
        }
        return $args;
    }

    /** Ajusta PHPMailer para despejar debug no nosso buffer quando habilitado */
    public static function hook_phpmailer(\PHPMailer\PHPMailer\PHPMailer $phpmailer) {
        if ( ! self::enabled() ) return;
        // Níveis: 0 = off, 1 = commands, 2 = data+headers, 3 = connection, 4 = low-level
        $phpmailer->SMTPDebug = 2;
        // Encaminha cada linha de debug para nosso buffer
        $phpmailer->Debugoutput = function($str, $level) {
            self::$smtp_buffer .= '['.date('H:i:s')." L$level] ".trim((string)$str)."\n";
        };
    }

    public static function on_success($mail_data) {
        $entry = self::build_entry('ok', null, $mail_data);
        self::push($entry);
    }

    public static function on_failed(\WP_Error $wp_error) {
        $data = [
            'error_code'    => $wp_error->get_error_code(),
            'error_message' => $wp_error->get_error_message(),
            'error_data'    => $wp_error->get_error_data(),
        ];
        $entry = self::build_entry('fail', $data, self::$last_args);
        self::push($entry);
    }

    private static function build_entry(string $status, ?array $error, ?array $mail_args): array {
        $to       = $mail_args['to']       ?? ( self::$last_args['to'] ?? '' );
        $subject  = $mail_args['subject']  ?? ( self::$last_args['subject'] ?? '' );
        $headers  = $mail_args['headers']  ?? ( self::$last_args['headers'] ?? '' );
        $message  = $mail_args['message']  ?? ( self::$last_args['message'] ?? '' );

        // Normaliza destinatários p/ string
        if ( is_array($to) ) $to = implode(', ', $to);
        if ( is_array($headers) ) $headers = implode("\n", $headers);

        return [
            'ts'         => current_time('timestamp'),
            'status'     => $status,                 // ok | fail
            'to'         => (string) $to,
            'subject'    => (string) $subject,
            'headers'    => (string) $headers,
            'snippet'    => wp_strip_all_tags( (string) $message ),
            'smtp_debug' => self::$smtp_buffer,
            'error'      => $error,                 // null ou array error_*
            'site'       => home_url(),
            'php_sapi'   => PHP_SAPI,
        ];
    }

    private static function push(array $entry): void {
        if ( ! self::enabled() ) return; // só loga quando habilitado
        $ring = get_option(self::$ring_option, []);
        if ( ! is_array($ring) ) $ring = [];
        $ring[] = $entry;
        // limita tamanho
        if ( count($ring) > self::$ring_limit ) {
            $ring = array_slice($ring, -self::$ring_limit);
        }
        update_option(self::$ring_option, $ring, false);
    }

    public static function get_logs(int $limit = 20): array {
        $ring = get_option(self::$ring_option, []);
        if ( ! is_array($ring) ) return [];
        $ring = array_reverse($ring); // mais recentes primeiro
        if ( $limit > 0 ) $ring = array_slice($ring, 0, $limit);
        return $ring;
    }

    public static function clear(): void {
        delete_option(self::$ring_option);
    }

    public static function handle_clear() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('forbidden');
        check_admin_referer('c2p_email_debug_clear');
        self::clear();
        wp_redirect( admin_url('admin.php?page=c2p-settings&tab=emails') );
        exit;
    }

    /** Renderiza um box HTML com os últimos logs (para usar na aba) */
    public static function render_admin_box(): string {
        $logs = self::get_logs(20);
        ob_start();
        ?>
        <div class="c2p-debug-box" style="background:#fff;border:1px solid #e2e2e2;padding:12px 14px;border-radius:8px;max-width:1100px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <strong><?php echo esc_html__('Logs de envio de e-mail (últimos 20)', 'c2p'); ?></strong>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="c2p_email_debug_clear">
                    <?php wp_nonce_field('c2p_email_debug_clear'); ?>
                    <button class="button button-secondary" type="submit"><?php echo esc_html__('Limpar logs', 'c2p'); ?></button>
                </form>
            </div>
            <?php if (empty($logs)) : ?>
                <p style="margin:8px 0 0;color:#666;"><?php echo esc_html__('Nenhum log ainda. Ative o modo debug e envie um e-mail de teste.', 'c2p'); ?></p>
            <?php else: ?>
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Data/Hora', 'c2p'); ?></th>
                        <th><?php echo esc_html__('Status', 'c2p'); ?></th>
                        <th><?php echo esc_html__('Para', 'c2p'); ?></th>
                        <th><?php echo esc_html__('Assunto', 'c2p'); ?></th>
                        <th><?php echo esc_html__('Detalhes', 'c2p'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $row): ?>
                    <tr>
                        <td><?php echo esc_html( date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$row['ts']) ); ?></td>
                        <td>
                            <?php if ($row['status']==='ok'): ?>
                                <span style="display:inline-block;padding:2px 6px;border-radius:12px;background:#e6f4ea;color:#137333;font-weight:600;">OK</span>
                            <?php else: ?>
                                <span style="display:inline-block;padding:2px 6px;border-radius:12px;background:#fde7e9;color:#a50e0e;font-weight:600;">FAIL</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( (string)($row['to'] ?? '') ); ?></td>
                        <td><?php echo esc_html( (string)($row['subject'] ?? '') ); ?></td>
                        <td>
                            <details>
                                <summary><?php echo esc_html__('ver', 'c2p'); ?></summary>
                                <div style="margin-top:6px;">
                                    <?php if (!empty($row['error'])): ?>
                                        <div style="background:#fff3cd;border:1px solid #ffe69c;padding:8px;border-radius:6px;margin-bottom:6px;">
                                            <strong>Erro:</strong>
                                            <div><code><?php echo esc_html( (string)($row['error']['error_code'] ?? '') ); ?></code> — <?php echo esc_html( (string)($row['error']['error_message'] ?? '') ); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['headers'])): ?>
                                        <div><strong>Headers:</strong><pre style="white-space:pre-wrap;max-height:140px;overflow:auto;"><?php echo esc_html( (string)$row['headers'] ); ?></pre></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['snippet'])): ?>
                                        <div><strong>Snippet do corpo:</strong><pre style="white-space:pre-wrap;max-height:140px;overflow:auto;"><?php echo esc_html( (string)$row['snippet'] ); ?></pre></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['smtp_debug'])): ?>
                                        <div><strong>SMTP/PHPMailer:</strong><pre style="white-space:pre-wrap;max-height:220px;overflow:auto;background:#f6f8fa;border:1px solid #e2e2e2;border-radius:6px;padding:8px;"><?php echo esc_html( (string)$row['smtp_debug'] ); ?></pre></div>
                                    <?php endif; ?>
                                    <div style="color:#666;font-size:12px;margin-top:4px;">SAPI: <?php echo esc_html((string)($row['php_sapi'] ?? '')); ?> • Site: <?php echo esc_html((string)($row['site'] ?? '')); ?></div>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
Email_Debug::boot();
