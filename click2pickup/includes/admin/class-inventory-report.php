<?php
namespace C2P;

if ( ! defined('ABSPATH') ) exit;

/**
 * Inventory_Report
 *
 * Submenu "Estoque" dentro de Click2Pickup (pai: c2p-dashboard).
 * Lista produtos/variações com colunas dinâmicas para cada Local (c2p_store) + Total,
 * filtros por categoria e busca (SKU/nome), e exportação CSV paginada.
 *
 * Integração com a tabela canônica do plugin:
 * - Tabela: \C2P\Inventory_DB::table_name()
 * - Coluna de local dinâmica: \C2P\Inventory_DB::store_column_name()
 */
final class Inventory_Report {

    private static $instance = null;

    const PAGE_SLUG         = 'c2p-estoque';
    const AJAX_ACTION       = 'c2p_inventory_export_batch';
    const NONCE_ACTION      = 'c2p_inventory_nonce';
    const DL_NONCE_ACTION   = 'c2p_download_nonce';
    const TRANSIENT_PREFIX  = 'c2p_report_data_';
    const PER_PAGE_SCREEN   = 20;  // itens na tabela da tela
    const PER_PAGE_EXPORT   = 100; // itens por lote no export

    /** Singleton */
    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                    [ $this, 'register_menu' ], 9 );
        add_action( 'admin_enqueue_scripts',         [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION,  [ $this, 'ajax_export_batch' ] );
        add_action( 'admin_init',                    [ $this, 'handle_download_csv' ] );
    }

    /* ============================ MENU ============================ */

    /** Submenu: Click2Pickup → Estoque */
    public function register_menu(): void {
        add_submenu_page(
            'c2p-dashboard',
            __('Estoque', 'c2p'),
            __('Estoque', 'c2p'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            20
        );
    }

    /* =========================== ASSETS =========================== */

    public function enqueue_assets( $hook ): void {
        $is_page = isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG;
        if ( ! $is_page ) return;

        // Select2
        wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
        wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);

        // Estilos
$css = '
  #c2p-inventory-wrap { background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); border-radius:4px; padding:12px 24px 24px; margin-right:20px; }
  #c2p-inventory-wrap h1 { margin-bottom:18px }
  #c2p-card-header { display:flex; justify-content:space-between; gap:16px; align-items:center; padding-bottom:16px }
  #c2p-card-header .search-box { margin:0; display:flex; flex-wrap:wrap; align-items:center; gap:8px }
  #c2p-card-actions .button .dashicons { line-height:1; font-size:1.2em; vertical-align:middle; margin-right:4px; position:relative; top:-1px }
  #c2p-inventory-wrap .table-scroller{ overflow:auto; border:1px solid #ccd0d4; border-radius:4px }
  #c2p-inventory-wrap table{ min-width:900px }
  #c2p-inventory-wrap table th{ background:#f6f7f7; white-space:nowrap }
  #c2p-inventory-wrap table td, #c2p-inventory-wrap table th{ padding:10px 12px; vertical-align:middle }
  #c2p-inventory-wrap table td a{ text-decoration:none; font-weight:600 }
  .c2p-col-sku{ width:140px }
  .c2p-col-stock-total{ width:110px; text-align:center }
  .c2p-col-loc{ text-align:center }
  .tablenav-pages .page-numbers{ display:inline-block; padding:6px 12px; margin:0 2px; text-decoration:none; border:1px solid #ccd0d4; background:#f6f7f7; border-radius:3px; font-size:14px; line-height:1.5; color:#2271b1 }
  .tablenav-pages .page-numbers.current{ background:#2271b1; border-color:#2271b1; color:#fff; font-weight:700 }
';

        wp_add_inline_style('common', $css);

        // Script da página (select2 + export)
        $nonce        = wp_create_nonce(self::NONCE_ACTION);
        $dl_nonce     = wp_create_nonce(self::DL_NONCE_ACTION);
        $ajax_action  = self::AJAX_ACTION;
        $page_slug    = self::PAGE_SLUG;

        $js = <<<JS
(function($){
  $(function(){
    // select2
    $('#c2p-product-category-filter').select2({
      placeholder: 'Filtrar por categoria...',
      allowClear: true,
      closeOnSelect: false
    });

    // Exportar
    $('#c2p-gerar-relatorio').on('click', function(){
      var btn = $(this);
      btn.prop('disabled', true).html('<span class="dashicons dashicons-cloud-saved"></span> <strong>Gerando...</strong>');

      var searchTerm = $('input[name="s"]').val() || '';
      var cats = $('#c2p-product-category-filter').val() || [];
      var reportId = 'c2p_' + Date.now();

      var progressHTML = '<div style="padding:12px; border-left:4px solid #45a0e3; background:#f0f6fc; border-radius:4px;">' +
                         '<p><strong>Exportando... Não feche esta página.</strong></p>' +
                         '<progress id="c2p-progress" value="0" max="100" style="width:100%; height:18px;"></progress>' +
                         '<p id="c2p-progress-text">Iniciando...</p>' +
                         '</div>';
      $('#c2p-relatorio-progresso').html(progressHTML).show();
      $('#c2p-relatorio-resultado').hide();

      exportBatch(1, reportId, searchTerm, cats);

      function exportBatch(page, report_id, s, categories){
        $.ajax({
          url: ajaxurl, type:'POST',
          data: {
            action: '{$ajax_action}',
            nonce: '{$nonce}',
            page: page,
            report_id: report_id,
            search_term: s,
            categories: categories
          },
          success: function(resp){
            if (!resp || !resp.success){ onError(resp && resp.data ? resp.data : 'Erro desconhecido'); return; }

            var d = resp.data || {};
            if (d.totalProducts > 0) {
              var pct = (d.page / d.totalPages) * 100;
              $('#c2p-progress').val(pct);
              var processed = Math.min(d.page * d.batchSize, d.totalProducts);
              $('#c2p-progress-text').text('Exportando... ' + processed + ' de ' + d.totalProducts + ' itens.');
            } else {
              $('#c2p-progress').val(100);
              $('#c2p-progress-text').text('Nenhum item para exportar.');
            }

            if (d.page < d.totalPages){
              exportBatch(d.page + 1, d.report_id, s, categories);
            } else {
              var downloadUrl = 'admin.php?page={$page_slug}&action=download_csv&report_id='+encodeURIComponent(d.report_id)+'&_wpnonce={$dl_nonce}';
              $('#c2p-relatorio-progresso').hide();
              var html;
              if (d.totalProducts > 0) {
                html = '<div style="padding:12px; border-left:4px solid #7ad03a; background:#f0f6fc; border-radius:4px;">' +
                       '<h3>Exportação Concluída!</h3>' +
                       '<p>Seu relatório está pronto. O download deve iniciar automaticamente.</p>' +
                       '<p>Se não iniciar, <a href="'+downloadUrl+'">clique aqui para baixar</a>.</p>' +
                       '</div>';
                window.location.href = downloadUrl;
              } else {
                html = '<div style="padding:12px; border-left:4px solid #ffb900; background:#fff8e5; border-radius:4px;">' +
                       '<h3>Nenhum produto encontrado</h3>' +
                       '<p>A exportação foi concluída, mas nenhum item corresponde aos filtros.</p>' +
                       '</div>';
              }
              $('#c2p-relatorio-resultado').html(html).show();
              btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud-saved"></span> <strong>Gerar relatório</strong>');
            }
          },
          error: function(){ onError('Erro de comunicação com o servidor.'); }
        });
      }

      function onError(msg){
        $('#c2p-progress-text').css('color','red').text('Erro: '+msg);
        $('#c2p-gerar-relatorio').prop('disabled', false).html('<span class="dashicons dashicons-cloud-saved"></span> <strong>Tentar Novamente</strong>');
      }
    });
  });
})(jQuery);
JS;
        wp_add_inline_script('jquery-core', $js);
    }

    /* ===================== BUSCA / CONSULTA ===================== */

    /** Query base (equivalente ao seu get_visualizador_estoque_query_args) */
    private function build_query_args( string $search_term = '', array $selected_cats = [] ): array {
        global $wpdb;

        $args = [
            'post_type'      => [ 'product', 'product_variation' ],
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'tax_query'      => [
                'relation' => 'AND',
                [ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'variable', 'operator' => 'NOT IN' ], // exclui PAI variável
            ]
        ];

        // Filtro por categoria (expande pai variável para filhos)
        if ( ! empty( $selected_cats ) ) {
            $parent_and_simple_ids = get_posts([
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                'tax_query'      => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $selected_cats ]],
            ]);
            $ids_to_display = [];
            if ( ! empty( $parent_and_simple_ids ) ) {
                foreach ( $parent_and_simple_ids as $pid ) {
                    $p = wc_get_product( $pid );
                    if ( ! $p ) continue;
                    if ( $p->is_type('variable') ) {
                        $ids_to_display = array_merge( $ids_to_display, $p->get_children() );
                    } else {
                        $ids_to_display[] = $pid;
                    }
                }
            }
            $args['post__in'] = empty( $ids_to_display ) ? [0] : array_unique( $ids_to_display );
        }

        // Busca SKU/nome
        if ( $search_term !== '' ) {
            $found = [];

            $sku_query = $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like( $search_term ) . '%'
            );
            $ids_by_sku = (array) $wpdb->get_col( $sku_query );
            if ( $ids_by_sku ) $found = array_merge( $found, $ids_by_sku );

            $name_ids = get_posts([
                'post_type'      => [ 'product', 'product_variation' ],
                'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                's'              => $search_term,
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ]);
            if ( $name_ids ) $found = array_merge( $found, $name_ids );

            $uniq = array_unique( array_map('intval', $found) );
            if ( $uniq ) {
                $args['post__in'] = empty( $args['post__in'] ) ? $uniq : array_intersect( $args['post__in'], $uniq );
                if ( empty( $args['post__in'] ) ) $args['post__in'] = [0];
            } else {
                $args['post__in'] = [0];
            }
        }

        return $args;
    }

    /** Lista de locais (id => nome curto) */
    private function get_locations(): array {
        $q = new \WP_Query([
            'post_type'      => 'c2p_store',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $out = [];
        foreach ( $q->posts as $pid ) {
            // Exibe "Nome (#id)"
            $out[ (int) $pid ] = get_the_title( $pid ) . " (#{$pid})";
        }
        return $out;
    }

    /** Mapa stock por local para um produto/variação */
    private function get_multistock_map( int $product_id ): array {
        global $wpdb;
        $table = \C2P\Inventory_DB::table_name();
        $col   = \C2P\Inventory_DB::store_column_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT {$col} AS store_id, qty FROM {$table} WHERE product_id = %d", $product_id),
            ARRAY_A
        );
        $map = [];
        foreach ( (array) $rows as $r ) {
            $map[ (int) $r['store_id'] ] = (int) $r['qty'];
        }
        return $map;
    }

    /* ============================ VIEW ============================ */

    public function render_page(): void {
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_die( __('Você não tem permissão para ver esta página.', 'c2p') );
        }

        $search_term   = isset($_GET['s']) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $selected_cats = ( isset($_GET['product_cats']) && is_array($_GET['product_cats']) ) ? array_map('intval', $_GET['product_cats']) : [];

        // Ordenação
        $sortable = [ 'sku', 'title' /*, 'stock_total'*/ ];
        $orderby  = ( isset($_GET['orderby']) && in_array($_GET['orderby'], $sortable, true) ) ? $_GET['orderby'] : 'title';
        $order    = ( isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ) ? 'desc' : 'asc';

        $args = $this->build_query_args( $search_term, $selected_cats );
        $args['posts_per_page'] = self::PER_PAGE_SCREEN;
        $args['paged'] = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        switch ( $orderby ) {
            case 'sku':   $args['orderby'] = 'meta_value'; $args['meta_key'] = '_sku'; break;
            case 'title': $args['orderby'] = 'title'; break;
            default:      $args['orderby'] = 'title';
        }
        $args['order'] = strtoupper($order);

        $query  = new \WP_Query( $args );
        $stores = $this->get_locations(); // colunas dinâmicas
        ?>
        <div class="wrap" id="c2p-inventory-wrap">
          <h1><?php esc_html_e('Estoque', 'c2p'); ?></h1>

          <div id="c2p-relatorio-progresso" style="display:none; margin-bottom:16px;"></div>
          <div id="c2p-relatorio-resultado" style="display:none; margin-bottom:16px;"></div>

          <div id="c2p-card-header">
            <form method="get" class="search-box">
              <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
              <p class="search-box">
                <?php
                $all_cats = get_terms([ 'taxonomy'=>'product_cat', 'hide_empty'=>false ]);
                if ( ! is_wp_error($all_cats) && ! empty($all_cats) ): ?>
                  <select name="product_cats[]" id="c2p-product-category-filter" multiple="multiple" style="width:260px;">
                    <?php foreach ( $all_cats as $cat ): ?>
                      <option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( (int)$cat->term_id, $selected_cats, true ) ); ?>>
                        <?php echo esc_html( $cat->name ); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>

                <input type="search" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="<?php esc_attr_e('Buscar por SKU ou Nome...', 'c2p'); ?>">
                <input type="submit" class="button" value="<?php esc_attr_e('Filtrar', 'c2p'); ?>">

                <?php if ( $search_term || $selected_cats ): ?>
                  <a class="button button-secondary" href="<?php echo esc_url( admin_url('admin.php?page=' . self::PAGE_SLUG) ); ?>">
                    <?php esc_html_e('Limpar Filtros','c2p'); ?>
                  </a>
                <?php endif; ?>
              </p>
            </form>

            <div id="c2p-card-actions">
              <button id="c2p-gerar-relatorio" class="button button-primary">
                <span class="dashicons dashicons-cloud-saved"></span> <strong><?php esc_html_e('Gerar relatório','c2p'); ?></strong>
              </button>
            </div>
          </div>

          <div class="table-scroller">
            <table class="wp-list-table widefat fixed striped">
              <thead>
                <tr>
                  <?php
                  // Colunas fixas
                  $cols = [
                    'sku'    => __('SKU','c2p'),
                    'title'  => __('Produto/Variação','c2p'),
                    'cat'    => __('Categoria','c2p'),
                  ];
                  foreach ($cols as $slug=>$label){
                      $sort = in_array($slug, ['sku','title'], true);
                      $width = $slug==='sku' ? ' class="c2p-col-sku"' : '';
                      if ($sort){
                          $link = add_query_arg([
                              'orderby' => $slug,
                              'order'   => ( $slug === $orderby && $order === 'asc' ) ? 'desc' : 'asc',
                          ]);
                          $indicator = ($slug === $orderby) ? ( $order === 'asc' ? '<span class="dashicons dashicons-arrow-up"></span>' : '<span class="dashicons dashicons-arrow-down"></span>' ) : '';
                          echo '<th'.$width.'><a href="'.esc_url($link).'"><strong>'.esc_html($label).'</strong>'.$indicator.'</a></th>';
                      } else {
                          echo '<th'.$width.'><strong>'.esc_html($label).'</strong></th>';
                      }
                  }

                  // Colunas dinâmicas por Local
                  foreach ( $stores as $sid => $sname ) {
                      echo '<th class="c2p-col-loc"><strong>'.esc_html( $sname ).'</strong></th>';
                  }
                  // Total
                  echo '<th class="c2p-col-stock-total"><strong>'.esc_html__('Total','c2p').'</strong></th>';
                  ?>
                </tr>
              </thead>
              <tbody>
                <?php
                if ( $query->have_posts() ) {
                    while ( $query->have_posts() ) {
                        $query->the_post();
                        $product = wc_get_product( get_the_ID() );
                        if ( ! $product ) continue;

                        // SKU / Nome / Categoria (parent p/ variação)
                        $sku = $product->get_sku() ?: 'N/A';
                        if ( $product->is_type('variation') ) {
                            $parent = wc_get_product( $product->get_parent_id() );
                            $pname  = $parent ? $parent->get_name() : '';
                            $attrs  = wc_get_formatted_variation( $product, true, false, false );
                            $name   = $pname . ' - ' . $attrs;
                            $prod_id_for_terms = $parent ? $parent->get_id() : $product->get_id();
                        } else {
                            $name = $product->get_name();
                            $prod_id_for_terms = $product->get_id();
                        }

                        $st = get_post_status( $product->get_id() );
                        if ( $st !== 'publish' ) {
                            $obj = get_post_status_object( $st );
                            $name .= ' (' . ( $obj ? $obj->label : ucfirst($st) ) . ')';
                        }

                        $cat_terms = get_the_terms( $prod_id_for_terms, 'product_cat' );
                        $cats_html = '—';
                        if ( $cat_terms && ! is_wp_error($cat_terms) ) {
                            $links = [];
                            foreach ( $cat_terms as $term ) {
                                $links[] = '<a href="'.esc_url( admin_url( 'admin.php?page='.self::PAGE_SLUG.'&product_cats[]='.$term->term_id ) ).'">'.esc_html($term->name).'</a>';
                            }
                            $cats_html = implode(', ', $links);
                        }

                        // Estoque por local (multi-estoque)
                        $map = $this->get_multistock_map( (int) $product->get_id() );
                        $total = 0;

                        echo '<tr>';
                        echo '<td>'.esc_html($sku).'</td>';

                        $edit_link = get_edit_post_link( $product->is_type('variation') ? $product->get_parent_id() : $product->get_id() );
                        echo '<td><a href="'.esc_url($edit_link).'" target="_blank">'.esc_html($name).'</a></td>';
                        echo '<td>'.$cats_html.'</td>';

                        foreach ( $stores as $sid => $sname ) {
                            $q = isset($map[$sid]) ? (int) $map[$sid] : 0;
                            $total += $q;
                            echo '<td class="c2p-col-loc">'.esc_html( (string) $q ).'</td>';
                        }

                        echo '<td class="c2p-col-stock-total"><strong>'.esc_html( (string) $total ).'</strong></td>';
                        echo '</tr>';
                    }
                } else {
                    $colspan = 3 + count($stores) + 1;
                    echo '<tr><td colspan="'.(int)$colspan.'">'.esc_html__('Nenhum produto encontrado.','c2p').'</td></tr>';
                }
                wp_reset_postdata();
                ?>
              </tbody>
            </table>
          </div>

          <div class="tablenav bottom">
            <div class="tablenav-pages">
              <?php
                $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
                echo '<span class="displaying-num">'. (int) $query->found_posts .' '. esc_html__('itens','c2p') .'</span>';
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => max(1,(int)$query->max_num_pages),
                    'prev_text' => '‹',
                    'next_text' => '›',
                    'type'      => 'plain',
                ]);
              ?>
            </div>
          </div>
        </div>
        <?php
    }

    /* ====================== EXPORTAÇÃO (AJAX) ====================== */

    public function ajax_export_batch(): void {
        if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error('forbidden', 403);
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ACTION ) ) {
            wp_send_json_error('nonce', 403);
        }

        $page       = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $report_id  = isset($_POST['report_id']) ? sanitize_key($_POST['report_id']) : '';
        $search     = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        $cats       = ( isset($_POST['categories']) && is_array($_POST['categories']) ) ? array_map('intval', $_POST['categories']) : [];

        if ( $report_id === '' ) wp_send_json_error('report_id', 400);

        $args = $this->build_query_args( $search, $cats );
        $args['paged']          = $page;
        $args['posts_per_page'] = self::PER_PAGE_EXPORT;
        $args['fields']         = 'ids';
        $args['orderby']        = 'ID';
        $args['order']          = 'ASC';

        $q   = new \WP_Query( $args );
        $ids = (array) $q->get_posts();

        // Cabeçalho dinâmico depende dos locais atuais
        $stores = $this->get_locations();

        $lote = [];
        foreach ( $ids as $pid ) {
            $p = wc_get_product( $pid );
            if ( ! $p ) continue;

            // Nome amigável
            if ( $p->is_type('variation') ) {
                $parent = wc_get_product( $p->get_parent_id() );
                $pname  = $parent ? $parent->get_name() : '';
                $attrs  = wc_get_formatted_variation( $p, true, false, false );
                $name   = $pname . ' - ' . $attrs;
            } else {
                $name = $p->get_name();
            }

            // Quantidades por local
            $map   = $this->get_multistock_map( (int) $p->get_id() );
            $total = 0;
            $row   = [
                'sku'  => $p->get_sku() ?: 'N/A',
                'name' => wp_strip_all_tags( $name ),
            ];
            foreach ( $stores as $sid => $_label ) {
                $qtd = isset($map[$sid]) ? (int) $map[$sid] : 0;
                $row[ 'loc_' . (int) $sid ] = $qtd;
                $total += $qtd;
            }
            $row['total'] = $total;

            $lote[] = $row;
        }

        // Persiste no transient (acumula por lotes)
        $key  = self::TRANSIENT_PREFIX . $report_id;
        $prev = get_transient( $key );
        if ( ! is_array($prev) ) $prev = [];
        set_transient( $key, array_merge( $prev, $lote ), HOUR_IN_SECONDS );

        // Totais para progresso
        $total_args = $args;
        $total_args['posts_per_page'] = -1;
        $total_args['paged'] = 1;
        $qt = new \WP_Query( $total_args );
        $total_products = (int) $qt->found_posts;
        $total_pages    = $total_products > 0 ? (int) ceil( $total_products / self::PER_PAGE_EXPORT ) : 0;

        wp_send_json_success([
            'page'          => $page,
            'totalPages'    => $total_pages,
            'totalProducts' => $total_products,
            'batchSize'     => self::PER_PAGE_EXPORT,
            'report_id'     => $report_id,
        ]);
    }

    /* ======= Helper para CSV compatível com PHP 7.x → 8.3+ ======= */

    /**
     * Escreve uma linha CSV definindo explicitamente separador, aspas e escape,
     * evitando avisos "Deprecated" no PHP 8.1+.
     */
    private function csv_putline($handle, array $fields, string $sep = ',', string $enc = '"', string $esc = '\\', string $eol = "\n"): void {
        if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
            // PHP 8.1+: aceita $eol como 6º parâmetro
            fputcsv($handle, $fields, $sep, $enc, $esc, $eol);
        } else {
            // PHP < 8.1: sem $eol; adiciona manualmente a quebra de linha
            fputcsv($handle, $fields, $sep, $enc, $esc);
            fwrite($handle, $eol);
        }
    }

    /** Download CSV (saída limpa e compatível com PHP 8.1+) */
    public function handle_download_csv(): void {
        if ( ! isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG ) return;
        if ( ! isset($_GET['action']) || $_GET['action'] !== 'download_csv' ) return;

        if ( ! current_user_can('manage_woocommerce') ) wp_die('forbidden');
        if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce( $_GET['_wpnonce'], self::DL_NONCE_ACTION ) ) {
            wp_die('nonce');
        }

        $report_id = isset($_GET['report_id']) ? sanitize_key($_GET['report_id']) : '';
        if ( $report_id === '' ) wp_die('ID do relatório não encontrado.');

        $key  = self::TRANSIENT_PREFIX . $report_id;
        $data = get_transient( $key );
        if ( empty( $data ) || ! is_array( $data ) ) wp_die('Relatório não encontrado ou expirado.');

        $stores = $this->get_locations();

        // Garante que não há buffers/avisos interferindo no arquivo
        if ( function_exists('ob_get_level') ) {
            while ( ob_get_level() > 0 ) { @ob_end_clean(); }
        }

        nocache_headers();
        $filename = "relatorio-estoque-". wp_date('Y-m-d_H-i-s') .".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}");

        $out = fopen('php://output', 'w');

        // BOM UTF-8
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // Cabeçalho
        $this->csv_putline($out, [ 'Relatório gerado em: ' . wp_date('d/m/Y H:i:s') ]);
        // Linha em branco (evita fputcsv([]) em versões antigas)
        fwrite($out, "\n");

        // Header dinâmico: SKU, Nome, [Locais...], Total
        $header = [ 'SKU', 'Nome do Produto' ];
        foreach ( $stores as $sid => $label ) {
            $header[] = $label;
        }
        $header[] = 'Total';
        $this->csv_putline($out, $header);

        // Linhas
        foreach ( $data as $row ) {
            $line = [ $row['sku'] ?? '', $row['name'] ?? '' ];
            foreach ( $stores as $sid => $_label ) {
                $line[] = isset($row['loc_'.$sid]) ? (int)$row['loc_'.$sid] : 0;
            }
            $line[] = isset($row['total']) ? (int)$row['total'] : 0;
            $this->csv_putline($out, $line);
        }

        fclose($out);
        // Não apagamos o transient; expira em 1h.
        exit;
    }
}
