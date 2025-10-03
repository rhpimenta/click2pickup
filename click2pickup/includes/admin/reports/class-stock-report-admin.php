<?php
namespace C2P;

if ( ! defined('ABSPATH') ) exit;

/**
 * Stock_Report_Admin
 * - Lista o Ledger com paginação/filtros
 * - Exibe o nome do local mesmo que o CPT tenha sido removido
 *   (usa l.location_name_text ou meta->location_name como fallback)
 * - CSV export
 */
class Stock_Report_Admin {

    private static $instance;
    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [ $this, 'menu' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
        add_action('admin_notices', [ $this, 'maybe_bulk_notice' ]);
        add_action('admin_post_c2p_stock_report_bulk', [ $this, 'handle_bulk_action' ]);
    }

    public function menu() {
        add_submenu_page(
            'c2p-dashboard',
            __('Relatório de Estoque', 'c2p'),
            __('Relatório', 'c2p'),
            'manage_woocommerce',
            'c2p-stock-report',
            [ $this, 'render_page' ],
            30
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_c2p-dashboard' && $hook !== 'click2pickup_page_c2p-stock-report' ) return;

        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-datepicker-theme', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', [], '1.13.2' );
        wp_add_inline_style('common', '.c2p-stock-report-filter .small-text{width:8em;min-width:8em}');

        // "Selecionar todos" para a tabela (não usamos WP_List_Table aqui)
        $js = <<<JS
        (function($){
          $(document).on('change', '#c2p-check-all', function(){
            var checked = $(this).is(':checked');
            $('#c2p-bulk-form').find('input.c2p-ledger-id[type="checkbox"]').prop('checked', checked);
          });
        })(jQuery);
        JS;
        wp_add_inline_script( 'jquery-ui-datepicker', $js, 'after' );
    }

    public function maybe_bulk_notice() {
        if ( ! isset($_GET['page']) || $_GET['page'] !== 'c2p-stock-report' ) return;
        if ( isset($_GET['c2p_deleted']) ) {
            $n = max(0, (int) $_GET['c2p_deleted']);
            if ( $n > 0 ) {
                echo '<div class="notice notice-success is-dismissible"><p>'.
                    sprintf( esc_html__('%d registro(s) removido(s) com sucesso.', 'c2p'), $n ).
                '</p></div>';
            }
        }
        if ( isset($_GET['c2p_error']) ) {
            echo '<div class="notice notice-error is-dismissible"><p>'.
                esc_html( (string) $_GET['c2p_error'] ).
            '</p></div>';
        }
    }

    public function handle_bulk_action() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die( esc_html__('Permissão insuficiente.', 'c2p') );
        check_admin_referer('c2p_stock_report_bulk');

        global $wpdb;
        $table = class_exists('\\C2P\\Stock_Ledger') ? \C2P\Stock_Ledger::table_name() : $wpdb->prefix.'c2p_stock_ledger';

        $clicked_bottom = isset($_POST['apply2']);
        $action_raw = $clicked_bottom
            ? ($_POST['c2p_bulk_action_bottom'] ?? '')
            : ($_POST['c2p_bulk_action_top'] ?? '');
        $action = sanitize_text_field( (string) $action_raw );

        $ids = isset($_POST['ledger_ids']) && is_array($_POST['ledger_ids']) ? array_map('intval', $_POST['ledger_ids']) : [];
        $ids = array_values( array_filter($ids, fn($v)=>$v>0) );

        $back_url = admin_url( 'admin.php?page=c2p-stock-report' );
        foreach (['sku','product_id','from','to','paged'] as $keep) {
            if ( isset($_POST[$keep]) && $_POST[$keep] !== '' ) {
                $back_url = add_query_arg( [ $keep => sanitize_text_field((string)$_POST[$keep]) ], $back_url );
            }
        }

        if ( empty($action) || empty($ids) ) {
            wp_safe_redirect( add_query_arg(['c2p_error'=>rawurlencode(__('Selecione uma ação e pelo menos um item.','c2p'))], $back_url) );
            exit;
        }

        if ( $action === 'download' ) {
            $this->stream_csv_for_ids( $table, $ids );
        } elseif ( $action === 'delete' ) {
            $deleted = $this->delete_selected( $table, $ids );
            wp_safe_redirect( add_query_arg(['c2p_deleted'=>$deleted], $back_url) ); exit;
        } else {
            wp_safe_redirect( add_query_arg(['c2p_error'=>rawurlencode(__('Ação desconhecida.','c2p'))], $back_url) ); exit;
        }
    }

    public function render_page() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die( esc_html__('Permissão insuficiente.', 'c2p') );

        global $wpdb;
        $table = class_exists('\\C2P\\Stock_Ledger') ? \C2P\Stock_Ledger::table_name() : $wpdb->prefix.'c2p_stock_ledger';

        $paged  = max(1, (int) ($_GET['paged'] ?? 1));
        $ppp    = 50;
        $offset = ($paged - 1) * $ppp;

        $sku_query  = isset($_GET['sku']) ? trim((string) $_GET['sku']) : '';
        $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

        $from_raw = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
        $to_raw   = isset($_GET['to'])   ? trim((string) $_GET['to'])   : '';

        $from_dt = $this->parse_date_to_mysql($from_raw, false);
        $to_dt   = $this->parse_date_to_mysql($to_raw,   true );

        $where_parts = [ '1=1' ];
        $params = [];

        if ( $sku_query !== '' ) {
            $where_parts[] = "p.ID IN (
                SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
            )";
            $params[] = $sku_query;
        } elseif ( $product_id > 0 ) {
            $where_parts[] = "l.product_id = %d";
            $params[] = $product_id;
        }

        if ( $from_dt && $to_dt ) {
            $where_parts[] = "l.created_at BETWEEN %s AND %s";
            $params[] = $from_dt;
            $params[] = $to_dt;
        } elseif ( $from_dt ) {
            $where_parts[] = "l.created_at >= %s";
            $params[] = $from_dt;
        } elseif ( $to_dt ) {
            $where_parts[] = "l.created_at <= %s";
            $params[] = $to_dt;
        }

        $where_sql = implode(' AND ', $where_parts);

        // Importante: mantemos o nome do local mesmo se excluído
        // 1) tenta l.location_name_text (coluna dedicada no ledger)
        // 2) tenta meta->location_name (snapshoot salvo no JSON do ledger)
        // 3) cai no título do CPT (se ainda existir)
        $sql = "
            SELECT
                l.*,
                p.post_title   AS product_name,
                p.post_parent  AS parent_id,
                p.post_type    AS post_type,
                COALESCE(l.location_name_text, JSON_UNQUOTE(JSON_EXTRACT(l.meta,'$.location_name')), s.post_title) AS location_name_display,
                s.post_title   AS location_name,
                sku.meta_value   AS sku,
                sku_p.meta_value AS parent_sku
            FROM {$table} l
            LEFT JOIN {$wpdb->posts} p ON (p.ID = l.product_id)
            LEFT JOIN {$wpdb->posts} s ON (s.ID = l.location_id)
            LEFT JOIN {$wpdb->postmeta} sku
                   ON (sku.post_id = l.product_id AND sku.meta_key = '_sku')
            LEFT JOIN {$wpdb->postmeta} sku_p
                   ON (sku_p.post_id = p.post_parent AND sku_p.meta_key = '_sku')
            WHERE {$where_sql}
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT %d OFFSET %d
        ";
        $query_params = array_merge($params, [ $ppp, $offset ]);
        $prepared = $wpdb->prepare( $sql, $query_params );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        $db_error = $wpdb->last_error;

        $sql_count = "
            SELECT COUNT(1)
              FROM {$table} l
              LEFT JOIN {$wpdb->posts} p ON (p.ID = l.product_id)
             WHERE {$where_sql}
        ";
        $total = ! empty($params) ? (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) )
                                  : (int) $wpdb->get_var( $sql_count );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">'.esc_html__('Relatório de Estoque', 'c2p').'</h1>';

        // Filtros
        echo '<form method="get" style="margin:12px 0 16px" class="c2p-stock-report-filter">';
        echo '<input type="hidden" name="page" value="c2p-stock-report" />';

        echo '<label>'.esc_html__('SKU:', 'c2p').' ';
        echo '<input type="text" name="sku" value="'.esc_attr($sku_query).'" class="small-text" />';
        echo '</label> ';

        echo '<label style="margin-left:8px">'.esc_html__('ID do produto:', 'c2p').' ';
        echo '<input type="number" name="product_id" value="'.esc_attr($product_id ?: '').'" class="small-text" />';
        echo '</label> ';

        echo '<label style="margin-left:8px">'.esc_html__('De:', 'c2p').' ';
        echo '<input type="text" name="from" value="'.esc_attr($from_raw).'" class="small-text c2p-date" />';
        echo '</label> ';

        echo '<label style="margin-left:8px">'.esc_html__('Até:', 'c2p').' ';
        echo '<input type="text" name="to" value="'.esc_attr($to_raw).'" class="small-text c2p-date" />';
        echo '</label> ';

        submit_button( __('Filtrar','c2p'), 'secondary', '', false );
        echo '</form>';

        if ( $db_error ) {
            echo '<div class="notice notice-error"><p>'.
                 esc_html__( 'Erro ao consultar o banco de dados:', 'c2p' ) . ' ' . esc_html( $db_error ) .
                 '</p></div>';
        }

        if ( empty($rows) ) {
            echo '<p>'.esc_html__('Nenhum registro encontrado.', 'c2p').'</p>';
            echo '</div>';
            return;
        }

        // ===== Form de Ações em Massa =====
        echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'" id="c2p-bulk-form">';
        echo '<input type="hidden" name="action" value="c2p_stock_report_bulk" />';
        foreach (['sku'=>$sku_query,'product_id'=>$product_id?:'','from'=>$from_raw,'to'=>$to_raw,'paged'=>$paged] as $k=>$v){
            echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'" />';
        }
        wp_nonce_field('c2p_stock_report_bulk');

        echo '<div class="tablenav top" style="margin:8px 0 10px">';
        echo '<div class="alignleft actions bulkactions">';
        echo '<select name="c2p_bulk_action_top" id="c2p-bulk-action-top">';
        echo '<option value="">'.esc_html__('Ações em massa','c2p').'</option>';
        echo '<option value="download">'.esc_html__('Download','c2p').'</option>';
        echo '<option value="delete">'.esc_html__('Excluir permanentemente','c2p').'</option>';
        echo '</select> ';
        submit_button( __('Aplicar','c2p'), 'secondary', 'apply', false );
        echo '</div>';
        echo '</div>';

        // ===== Tabela =====
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th class="manage-column column-cb check-column"><input type="checkbox" id="c2p-check-all" /></th>';
        echo '<th>'.esc_html__('Data/Hora', 'c2p').'</th>';
        echo '<th>'.esc_html__('Produto', 'c2p').'</th>';
        echo '<th>'.esc_html__('SKU', 'c2p').'</th>';
        echo '<th>'.esc_html__('Local', 'c2p').'</th>';
        echo '<th style="text-align:right">'.esc_html__('Δ', 'c2p').'</th>';
        echo '<th style="text-align:right">'.esc_html__('Antes', 'c2p').'</th>';
        echo '<th style="text-align:right">'.esc_html__('Depois', 'c2p').'</th>';
        echo '<th>'.esc_html__('Origem', 'c2p').'</th>';
        echo '<th>'.esc_html__('Quem', 'c2p').'</th>';
        echo '<th>'.esc_html__('Pedido', 'c2p').'</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $id   = (int) $r['id'];
            $pid  = (int) $r['product_id'];
            $lid  = (int) $r['location_id'];
            $oid  = (int) $r['order_id'];
            $name = $r['product_name'] ?: ('#'.$pid);

            $loc  = $r['location_name_display'] ?: ('#'.$lid);
            $sku  = $r['sku'] ?: ($r['parent_sku'] ?: '');

            $edit_pid = ($r['post_type'] === 'product_variation' && (int)$r['parent_id'] > 0) ? (int)$r['parent_id'] : $pid;
            $product_url = admin_url('post.php?post='.$edit_pid.'&action=edit');

            echo '<tr>';
            // checkbox na primeira coluna
            echo '<th scope="row" class="check-column"><input type="checkbox" class="c2p-ledger-id" name="ledger_ids[]" value="'.esc_attr($id).'" /></th>';

            echo '<td>'.esc_html( $this->format_wp_datetime_gmt( $r['created_at'] ) ).'</td>';
            echo '<td><a target="_blank" rel="noopener" href="'.esc_url( $product_url ).'">'.esc_html($name).'</a></td>';
            echo '<td>'.esc_html($sku).'</td>';
            echo '<td>'.esc_html($loc).'</td>';
            echo '<td style="text-align:right">'.esc_html( (string) $r['delta'] ).'</td>';
            echo '<td style="text-align:right">'.esc_html( (string) $r['qty_before'] ).'</td>';
            echo '<td style="text-align:right">'.esc_html( (string) $r['qty_after'] ).'</td>';
            echo '<td>'.esc_html( (string) $r['source'] ).'</td>';
            echo '<td>'.esc_html( (string) $r['who'] ).'</td>';
            echo '<td>';
            if ( $oid > 0 ) {
                $url = admin_url( 'post.php?post=' . $oid . '&action=edit' );
                echo '<a target="_blank" rel="noopener" href="'.esc_url($url).'">#'.(int)$oid.'</a>';
            } else {
                echo '&mdash;';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div class="tablenav bottom" style="margin:10px 0 8px">';
        echo '<div class="alignleft actions bulkactions">';
        echo '<select name="c2p_bulk_action_bottom" id="c2p-bulk-action-bottom">';
        echo '<option value="">'.esc_html__('Ações em massa','c2p').'</option>';
        echo '<option value="download">'.esc_html__('Download','c2p').'</option>';
        echo '<option value="delete">'.esc_html__('Excluir permanentemente','c2p').'</option>';
        echo '</select> ';
        submit_button( __('Aplicar','c2p'), 'secondary', 'apply2', false );
        echo '</div>';
        echo '</div>';

        $total_pages = max(1, (int) ceil( $total / $ppp ));
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:8px">';
            for ( $i=1; $i <= $total_pages; $i++ ) {
                $url = add_query_arg( ['paged'=>$i], admin_url('admin.php?page=c2p-stock-report') );
                if ( $sku_query !== '' ) $url = add_query_arg( ['sku'=>$sku_query], $url );
                if ( $product_id > 0 )   $url = add_query_arg( ['product_id'=>$product_id], $url );
                if ( $from_raw !== '' )  $url = add_query_arg( ['from'=>$from_raw], $url );
                if ( $to_raw !== '' )    $url = add_query_arg( ['to'=>$to_raw], $url );
                if ( $i === $paged ) {
                    echo '<span class="tablenav-pages-navspan" style="margin-right:6px"><strong>'.$i.'</strong></span>';
                } else {
                    echo '<a class="tablenav-pages-navspan" style="margin-right:6px" href="'.esc_url($url).'">'.$i.'</a>';
                }
            }
            echo '</div></div>';
        }

        echo '</form>';
        echo '</div>';
    }

    private function stream_csv_for_ids( string $table, array $ids ): void {
        global $wpdb;
        if ( empty($ids) ) exit;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "
            SELECT
                l.*,
                p.post_title   AS product_name,
                p.post_parent  AS parent_id,
                p.post_type    AS post_type,
                COALESCE(l.location_name_text, JSON_UNQUOTE(JSON_EXTRACT(l.meta,'$.location_name')), s.post_title) AS location_name_display,
                s.post_title   AS location_name,
                sku.meta_value   AS sku,
                sku_p.meta_value AS parent_sku
            FROM {$table} l
            LEFT JOIN {$wpdb->posts} p ON (p.ID = l.product_id)
            LEFT JOIN {$wpdb->posts} s ON (s.ID = l.location_id)
            LEFT JOIN {$wpdb->postmeta} sku
                   ON (sku.post_id = l.product_id AND sku.meta_key = '_sku')
            LEFT JOIN {$wpdb->postmeta} sku_p
                   ON (sku_p.post_id = p.post_parent AND sku_p.meta_key = '_sku')
            WHERE l.id IN ($placeholders)
            ORDER BY l.created_at DESC, l.id DESC
        ";
        $prepared = $wpdb->prepare($sql, $ids);
        $rows = $wpdb->get_results( $prepared, ARRAY_A );

        while ( ob_get_level() > 0 ) { @ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="c2p-relatorio-estoque-selecionados.csv"');

        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');

        fputcsv($out, [ 'Gerado em:', date_i18n('d/m/Y H:i:s') ], ',', '"', "\0");
        fputcsv($out, [ 'Data/Hora','Produto','SKU','Local','Delta','Antes','Depois','Origem','Quem','Pedido' ], ',', '"', "\0");

        foreach ( (array)$rows as $r ) {
            $pid  = (int) $r['product_id'];
            $lid  = (int) $r['location_id'];
            $oid  = (int) $r['order_id'];
            $name = $r['product_name'] ?: ('#'.$pid);
            $loc  = $r['location_name_display'] ?: ('#'.$lid);
            $sku  = $r['sku'] ?: ($r['parent_sku'] ?: '');

            fputcsv($out, [
                $this->format_wp_datetime_gmt( $r['created_at'] ),
                $name, $sku, $loc,
                (string) $r['delta'],
                (string) $r['qty_before'],
                (string) $r['qty_after'],
                (string) $r['source'],
                (string) $r['who'],
                $oid > 0 ? ('#'.$oid) : ''
            ], ',', '"', "\0");
        }

        fclose($out);
        exit;
    }

    private function delete_selected( string $table, array $ids ): int {
        global $wpdb;
        if ( empty($ids) ) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "DELETE FROM {$table} WHERE id IN ($placeholders)";
        $prepared = $wpdb->prepare($sql, $ids);
        $wpdb->query($prepared);
        if ( $wpdb->last_error ) {
            error_log('[C2P][Stock_Report_Admin] Erro ao excluir: '.$wpdb->last_error);
        }
        return (int) $wpdb->rows_affected;
    }

    private function parse_date_to_mysql( string $in, bool $end_of_day ): ?string {
        $in = trim($in);
        if ( $in === '' ) return null;

        if ( preg_match('~^(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2}):(\d{2}))?$~', $in, $m) ) {
            $d=(int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3];
            $H = isset($m[4]) ? (int)$m[4] : ( $end_of_day ? 23 : 0 );
            $i = isset($m[5]) ? (int)$m[5] : ( $end_of_day ? 59 : 0 );
            $s = $end_of_day ? 59 : 0;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y,$mo,$d,$H,$i,$s);
        }

        if ( preg_match('~^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?$~', $in, $m) ) {
            $y=(int)$m[1]; $mo=(int)$m[2]; $d=(int)$m[3];
            $H = isset($m[4]) ? (int)$m[4] : ( $end_of_day ? 23 : 0 );
            $i = isset($m[5]) ? (int)$m[5] : ( $end_of_day ? 59 : 0 );
            $s = $end_of_day ? 59 : 0;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y,$mo,$d,$H,$i,$s);
        }

        $ts = strtotime($in);
        if ( $ts !== false ) {
            $has_time = (bool) preg_match('~\d{1,2}:\d{2}~', $in);
            if ( ! $has_time ) {
                $date = gmdate('Y-m-d', $ts);
                $ts = strtotime( $date . ' ' . ( $end_of_day ? '23:59:59' : '00:00:00' ) );
            }
            return gmdate('Y-m-d H:i:s', $ts);
        }

        return null;
    }

    private function format_wp_datetime_gmt( string $mysql_gmt ): string {
        $ts = strtotime( $mysql_gmt . ' UTC' );
        if ( false === $ts ) return $mysql_gmt;
        return wp_date( 'd/m/Y H:i:s', $ts );
    }
}

\C2P\Stock_Report_Admin::instance();
