<?php
namespace C2P;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Consolida UI de Produto: Product_Admin (metabox/admin) + Frontend_Availability (exibição no front).
 * Mantém as classes originais no mesmo arquivo por compatibilidade.
 */
class Product_UI {
    private static $instance;
    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        if (class_exists('\\C2P\\Product_Admin') && method_exists('\\C2P\\Product_Admin','instance')) { \C2P\Product_Admin::instance(); }
        elseif (class_exists('\\C2P\\Product_Admin') && method_exists('\\C2P\\Product_Admin','init')) { \C2P\Product_Admin::init(); }
        if (class_exists('\\C2P\\Frontend_Availability') && method_exists('\\C2P\\Frontend_Availability','instance')) { \C2P\Frontend_Availability::instance(); }
        elseif (class_exists('\\C2P\\Frontend_Availability') && method_exists('\\C2P\\Frontend_Availability','init')) { \C2P\Frontend_Availability::init(); }
    }
}

// === Begin Product_Admin ===
/**
 * Admin de Produto — Estoque por local (lojas/CD) por produto e por variação.
 *
 * Regras:
 * - Admin decide "Gerenciar estoque?" (manage_stock). O plugin só atua quando habilitado.
 * - Não alteramos "Permitir encomendas?" (backorders) — seguimos Woo.
 * - Campo nativo de quantidade mostra a SOMA dos locais (quando manage_stock = true) e fica somente leitura.
 * - "Limiar de estoque baixo" é por local (na nossa tabela) quando manage_stock = true;
 *   o campo nativo de limiar é substituído por mensagem (mantendo label + help-tip).
 * - Lista do admin: <mark class="…"> padrão; “(X)” fora do <mark>; pai variável nunca mostra número.
 *
 * Tabela principal (canônica): {$wpdb->prefix}c2p_multi_stock
 * Colunas: product_id, location_id, qty, low_stock_amount, updated_at
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( '\C2P\Product_Admin' ) ) :

class Product_Admin {

    const TABLE = 'c2p_multi_stock';

    private static $instance = null;
    public static function instance(): Product_Admin {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_product_options_inventory_product_data', [ $this, 'render_simple_stock_box' ] );
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_stock_box' ], 10, 3 );

        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_simple_stock' ] );
        add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_stock' ], 10, 2 );

        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        add_filter( 'woocommerce_admin_stock_html', [ $this, 'filter_admin_stock_html' ], 10, 2 );
    }

    /* ================= UI — Produto simples ================= */

    public function render_simple_stock_box() {
        global $post;
        if ( ! $post ) return;

        $product = wc_get_product( $post->ID );
        if ( ! $product ) return;

        // ⚠️ Não renderizar no PAI de produto variável
        if ( $product->is_type( 'variable' ) ) {
            return;
        }

        $manage = (bool) $product->get_manage_stock( 'edit' );
        $stores = $this->get_locations();

        echo '<div class="options_group c2p-stock-box c2p-simple" style="padding-left:20px;'. ( $manage ? '' : 'display:none;' ) .'">';
        echo '<p><strong>' . esc_html__( 'Estoque por local', 'c2p' ) . '</strong></p>';

        if ( empty( $stores ) ) {
            echo '<p>' . esc_html__( 'Nenhum local cadastrado (c2p_store).', 'c2p' ) . '</p>';
            echo '</div>';
            return;
        }

        list( $stocks_map, $lows_map ) = $this->get_product_stocks_with_lows( (int) $post->ID );
        $global_low = get_option( 'woocommerce_notify_low_stock_amount', '' );
        $ph_low = $global_low !== '' ? sprintf( __( 'Limiar em toda a loja (%s)', 'c2p' ), (string) $global_low ) : '—';

        echo '<table class="widefat striped c2p-table">';
        echo '<thead><tr>';
        echo '<th class="c2p-col-local">' . esc_html__( 'Local', 'c2p' ) . '</th>';
        echo '<th class="c2p-col-qty">' . esc_html__( 'Quantidade', 'c2p' ) . '</th>';
        echo '<th class="c2p-col-low">' . esc_html__( 'Limiar de estoque baixo', 'c2p' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $stores as $store_id => $store_label ) {
            $qty = isset( $stocks_map[ $store_id ] ) ? (int) $stocks_map[ $store_id ] : 0;
            $low = array_key_exists( $store_id, $lows_map ) && $lows_map[ $store_id ] !== null ? (int) $lows_map[ $store_id ] : '';
            echo '<tr>';
            echo '<td class="c2p-td-local">' . esc_html( $store_label ) . '</td>';
            echo '<td class="c2p-td-qty"><input type="number" step="1" min="0" inputmode="numeric" class="short c2p-qty c2p-no-scroll" name="c2p_stock[' . esc_attr( $store_id ) . ']" value="' . esc_attr( $qty ) . '" /></td>';
            echo '<td class="c2p-td-low"><input type="number" step="1" min="0" inputmode="numeric" class="short c2p-low c2p-no-scroll" name="c2p_low[' . esc_attr( $store_id ) . ']" value="' . esc_attr( $low ) . '" placeholder="' . esc_attr( $ph_low ) . '" /></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // .c2p-stock-box
    }

    /* ================= UI — Variações ================= */

    public function render_variation_stock_box( $loop, $variation_data, $variation ) {
        $variation_id = (int) $variation->ID;
        $stores = $this->get_locations();
        if ( empty( $stores ) ) return;

        $v = wc_get_product( $variation_id );
        $manage = $v ? (bool) $v->get_manage_stock( 'edit' ) : false;

        echo '<div class="options_group c2p-stock-box c2p-var" style="padding-left:0;'. ( $manage ? '' : 'display:none;' ) .'">';
        echo '<p><strong>' . esc_html__( 'Estoque por local', 'c2p' ) . '</strong></p>';

        list( $stocks_map, $lows_map ) = $this->get_product_stocks_with_lows( $variation_id );
        $global_low = get_option( 'woocommerce_notify_low_stock_amount', '' );
        $ph_low = $global_low !== '' ? sprintf( __( 'Limiar em toda a loja (%s)', 'c2p' ), (string) $global_low ) : '—';

        echo '<table class="widefat striped c2p-table">';
        echo '<thead><tr>';
        echo '<th class="c2p-col-local">' . esc_html__( 'Local', 'c2p' ) . '</th>';
        echo '<th class="c2p-col-qty">' . esc_html__( 'Quantidade', 'c2p' ) . '</th>';
        echo '<th class="c2p-col-low">' . esc_html__( 'Limiar de estoque baixo', 'c2p' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $stores as $store_id => $store_label ) {
            $qty = isset( $stocks_map[ $store_id ] ) ? (int) $stocks_map[ $store_id ] : 0;
            $low = array_key_exists( $store_id, $lows_map ) && $lows_map[ $store_id ] !== null ? (int) $lows_map[ $store_id ] : '';
            echo '<tr>';
            echo '<td class="c2p-td-local">' . esc_html( $store_label ) . '</td>';
            echo '<td class="c2p-td-qty"><input type="number" step="1" min="0" inputmode="numeric" class="short c2p-qty c2p-no-scroll" name="c2p_stock_var[' . esc_attr( $variation_id ) . '][' . esc_attr( $store_id ) . ']" value="' . esc_attr( $qty ) . '" /></td>';
            echo '<td class="c2p-td-low"><input type="number" step="1" min="0" inputmode="numeric" class="short c2p-low c2p-no-scroll" name="c2p_low_var[' . esc_attr( $variation_id ) . '][' . esc_attr( $store_id ) . ']" value="' . esc_attr( $low ) . '" placeholder="' . esc_attr( $ph_low ) . '" /></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // .c2p-stock-box
    }

    /* ================= SAVE — Simples ================= */

    public function save_simple_stock( $product ) {
        if ( ! $product || ! $product->get_id() ) return;
        $product_id = (int) $product->get_id();

        $pairs_qty = [];
        if ( isset( $_POST['c2p_stock'] ) && is_array( $_POST['c2p_stock'] ) ) {
            foreach ( $_POST['c2p_stock'] as $store_id => $qty ) {
                if ( ! is_numeric( $store_id ) ) continue;
                $pairs_qty[ (int) $store_id ] = max( 0, (int) $qty );
            }
        }

        $pairs_low = [];
        if ( isset( $_POST['c2p_low'] ) && is_array( $_POST['c2p_low'] ) ) {
            foreach ( $_POST['c2p_low'] as $store_id => $low ) {
                if ( ! is_numeric( $store_id ) ) continue;
                $val = ( $low === '' || $low === null ) ? null : max( 0, (int) $low );
                $pairs_low[ (int) $store_id ] = $val;
            }
        }

        if ( $pairs_qty || $pairs_low ) {
            // Persiste e registra alterações no Ledger (contexto simples)
            $this->persist_stocks_with_lows( $product_id, $pairs_qty, $pairs_low, 'admin_product_save:simple' );
            // Reindexa total + snapshot para REST/meta
            $this->reindex_and_snapshot( $product_id );
        }
    }

    /* ================= SAVE — Variação ================= */

    public function save_variation_stock( $variation_id, $i ) {
        $variation_id = (int) $variation_id;
        if ( $variation_id <= 0 ) return;

        $pairs_qty = [];
        if ( isset( $_POST['c2p_stock_var'][ $variation_id ] ) && is_array( $_POST['c2p_stock_var'][ $variation_id ] ) ) {
            foreach ( $_POST['c2p_stock_var'][ $variation_id ] as $store_id => $qty ) {
                if ( ! is_numeric( $store_id ) ) continue;
                $pairs_qty[ (int) $store_id ] = max( 0, (int) $qty );
            }
        }

        $pairs_low = [];
        if ( isset( $_POST['c2p_low_var'][ $variation_id ] ) && is_array( $_POST['c2p_low_var'][ $variation_id ] ) ) {
            foreach ( $_POST['c2p_low_var'][ $variation_id ] as $store_id => $low ) {
                if ( ! is_numeric( $store_id ) ) continue;
                $val = ( $low === '' || $low === null ) ? null : max( 0, (int) $low );
                $pairs_low[ (int) $store_id ] = $val;
            }
        }

        if ( $pairs_qty || $pairs_low ) {
            // Persiste e registra alterações no Ledger (contexto variação)
            $this->persist_stocks_with_lows( $variation_id, $pairs_qty, $pairs_low, 'admin_product_save:variation' );
            // Reindexa total + snapshot para REST/meta
            $this->reindex_and_snapshot( $variation_id );
        }
    }

    /* ================= ASSETS — JS/CSS ================= */

    public function admin_assets( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'product' ) return;

        // Unifica layout da tabela e ajusta help-tip
        $css = '
            .c2p-table{ width:100%; max-width:100%; table-layout:auto }
            .c2p-col-local{ width:50% } .c2p-col-qty{ width:25% } .c2p-col-low{ width:25% }
            .c2p-td-qty input.short, .c2p-td-low input.short{ width:170px; max-width:100%; box-sizing:border-box }
            .woocommerce-help-tip{ margin-left:.35em; vertical-align:middle; display:inline-block }
            .woocommerce_variation .c2p-stock-box{ padding-left:0!important }
            .c2p-stock-box input[type=number]::-webkit-outer-spin-button,
            .c2p-stock-box input[type=number]::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0 }
            .c2p-stock-box input[type=number]{ -moz-appearance:textfield }
            input#_stock[readonly], input#_stock[disabled],
            #variable_product_options input[name^="variable_stock["][readonly],
            #variable_product_options input[name^="variable_stock["][disabled]{ background:#f6f7f7; opacity:.85; cursor:not-allowed }
            .c2p-msg-low-native{ margin:.4em 0 0; color:#666; font-size:12px }
        ';
        wp_add_inline_style( 'common', $css );

        $js = <<<'JS'
(function($){

  function sumValues($nodes){
    var s = 0;
    $nodes.each(function(){ var v = parseInt(this.value,10); if(!isNaN(v) && v>0) s += v; });
    return s;
  }

  // ======== PRODUTO SIMPLES ========
  function toggleSimpleBoxAndMirror(){
    var $ms = $('#_manage_stock');
    var manage = $ms.length ? $ms.is(':checked') : false;

    // show/hide tabela por local (só existe em produto simples)
    $('.c2p-stock-box.c2p-simple')[ manage ? 'show' : 'hide' ]();

    // espelho de quantidade
    var $stock = $('#_stock');
    if ($stock.length){
      if (manage){
        var total = sumValues( $('.c2p-stock-box.c2p-simple input[name^="c2p_stock["]') );
        $stock.val(String(total)).prop('readonly', true).prop('disabled', true);
      } else {
        $stock.prop('readonly', false).prop('disabled', false);
      }
    }

    // limiar nativo (mantém label + help-tip; só esconde o input e põe mensagem)
    var $low = $('#_low_stock_amount');
    var $lowWrap = $low.closest('.form-field');
    if ($low.length){
      $lowWrap.find('.c2p-msg-low-native').remove();
      if (manage){
        $low.hide();
        $lowWrap.append('<div class="c2p-msg-low-native">O limiar é definido por local na tabela “Estoque por local”.</div>');
      } else {
        $low.show();
      }
    }
  }

  // ======== VARIAÇÕES ========
  function toggleVariationBoxesAndMirror(){
    $('#variable_product_options .woocommerce_variation').each(function(){
      var $row = $(this);
      var $box = $row.find('.c2p-stock-box.c2p-var');

      var $ms  = $row.find('input[name^="variable_manage_stock["], input.variable_manage_stock');
      var manage = $ms.length ? $ms.is(':checked') : false;

      // show/hide tabela desta variação
      if ($box.length){ manage ? $box.show() : $box.hide(); }

      // quantidade nativa da variação
      var $locals = $row.find('input[name^="c2p_stock_var["]');
      var total = manage ? sumValues($locals) : null;
      var $nat = $row.find('input[name^="variable_stock["]');
      if ($nat.length){
        if (manage){ $nat.val(String(total||0)).prop('readonly', true).prop('disabled', true); }
        else { $nat.prop('readonly', false).prop('disabled', false); }
      }

      // limiar nativo da variação
      var $low = $row.find('input[name^="variable_low_stock_amount["]');
      var $lowWrap = $low.closest('.form-row, .form-field, p.form-field');
      $lowWrap.find('.c2p-msg-low-native').remove();
      if ($low.length){
        if (manage){
          $low.hide();
          $lowWrap.append('<div class="c2p-msg-low-native">O limiar desta variação é definido por local na tabela “Estoque por local”.</div>');
        } else {
          $low.show();
        }
      }
    });
  }

  // Triggers
  $(document).on('input change', '.c2p-stock-box.c2p-simple input[name^="c2p_stock["], #_manage_stock', toggleSimpleBoxAndMirror);
  $(document).on('input change', '#variable_product_options input[name^="c2p_stock_var["], #variable_product_options input[name^="variable_manage_stock["], #variable_product_options input.variable_manage_stock', toggleVariationBoxesAndMirror);

  function initAll(){
    toggleSimpleBoxAndMirror();
    toggleVariationBoxesAndMirror();
  }
  $(initAll);
  $(document).on('woocommerce_variations_loaded woocommerce_variations_added woocommerce_variations_removed', initAll);
  var mo = new MutationObserver(function(){ initAll(); });
  mo.observe(document.body, { childList:true, subtree:true });

})(jQuery);
JS;
        wp_add_inline_script( 'jquery-core', $js );
    }

    /* ========== Coluna de estoque (lista do admin) ========== */

    public function filter_admin_stock_html( $html, $product ) {
        try {
            if ( ! $product instanceof \WC_Product ) return $html;

            $status  = $product->get_stock_status();
            $manage  = $product->get_manage_stock();
            $qty     = (int) $product->get_stock_quantity();
            $is_var_parent = $product->is_type('variable');

            if ( $status === 'outofstock' ) {
                return '<mark class="outofstock">' . esc_html__( 'Fora de estoque', 'woocommerce' ) . '</mark>';
            }
            if ( $status === 'onbackorder' ) {
                return '<mark class="onbackorder">' . esc_html__( 'Em espera', 'woocommerce' ) . '</mark>';
            }

            if ( $is_var_parent ) {
                return '<mark class="instock">' . esc_html__( 'Em estoque', 'woocommerce' ) . '</mark>';
            }

            if ( $manage ) {
                if ( $qty > 0 ) {
                    return '<mark class="instock">' . esc_html__( 'Em estoque', 'woocommerce' ) . '</mark> (' . esc_html( (string) $qty ) . ')';
                }
                return '<mark class="instock">' . esc_html__( 'Em estoque', 'woocommerce' ) . '</mark>';
            }

            return '<mark class="instock">' . esc_html__( 'Em estoque', 'woocommerce' ) . '</mark>';

        } catch ( \Throwable $e ) {
            return $html;
        }
    }

    /* ================= Helpers ================= */

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
            // Tipo do local: 'loja' | 'cd' (normalizado em label)
            $type = get_post_meta( $pid, 'c2p_type', true );
            $label_type = $type === 'cd' ? esc_html__( 'CD', 'c2p' ) : esc_html__( 'Loja', 'c2p' );
            $out[ (int) $pid ] = get_the_title( $pid ) . ' (#' . (int) $pid . ') — ' . $label_type;
        }
        return $out;
    }

    /**
     * @return array [ [location_id => qty], [location_id => low|null] ]
     */
    private function get_product_stocks_with_lows( int $product_id ): array {
        global $wpdb;

        $table = \C2P\Inventory_DB::table_name();
        $col   = \C2P\Inventory_DB::store_column_name();

        // AS location_id => padroniza o nome no PHP, independente do nome no banco
        $sql = "SELECT {$col} AS location_id, qty, low_stock_amount
                  FROM {$table}
                 WHERE product_id = %d
              ORDER BY {$col} ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $product_id ), ARRAY_A );

        $stocks = [];
        $lows   = [];
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $sid = (int) $r['location_id'];
                $stocks[ $sid ] = (int) $r['qty'];
                $lows[ $sid ]   = ( $r['low_stock_amount'] === null || $r['low_stock_amount'] === '' ) ? null : (int) $r['low_stock_amount'];
            }
        }
        return [ $stocks, $lows ];
    }

    /**
     * Persiste as quantidades/limiares por local e registra mudanças no Ledger (se disponível).
     *
     * @param int   $product_id
     * @param array $pairs_qty  [location_id => qty]
     * @param array $pairs_low  [location_id => low|null]
     * @param string $ctx       contexto para auditoria (ex.: 'admin_product_save:simple' | ':variation')
     */
    private function persist_stocks_with_lows( int $product_id, array $pairs_qty, array $pairs_low, string $ctx = 'admin_product_save' ): void {
        global $wpdb;

        $table = \C2P\Inventory_DB::table_name();
        $col   = \C2P\Inventory_DB::store_column_name();

        list( $current_qty, $current_low ) = $this->get_product_stocks_with_lows( $product_id );
        $location_ids = array_unique( array_map( 'intval',
            array_merge( array_keys($current_qty), array_keys($current_low), array_keys($pairs_qty), array_keys($pairs_low) )
        ) );

        $changes = []; // para o ledger

        foreach ( $location_ids as $sid ) {
            if ( $sid <= 0 ) continue;

            $has_row = array_key_exists($sid, $current_qty) || array_key_exists($sid, $current_low);

            $old_qty = $has_row ? (int)($current_qty[$sid] ?? 0) : 0;
            $new_qty = array_key_exists($sid, $pairs_qty) ? max(0, (int)$pairs_qty[$sid]) : $old_qty;

            $new_low = array_key_exists($sid, $pairs_low)
                ? ( ($pairs_low[$sid] === null || $pairs_low[$sid] === '') ? null : max(0, (int)$pairs_low[$sid]) )
                : ( $has_row ? ( $current_low[$sid] ?? null ) : null );

            if ( $has_row ) {
                if ( is_null($new_low) ) {
                    // low_stock_amount = NULL
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table}
                            SET qty = %d,
                                low_stock_amount = NULL,
                                updated_at = %s
                          WHERE product_id = %d
                            AND {$col} = %d",
                        $new_qty, current_time('mysql', true), $product_id, $sid
                    ) );
                } else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table}
                            SET qty = %d,
                                low_stock_amount = %d,
                                updated_at = %s
                          WHERE product_id = %d
                            AND {$col} = %d",
                        $new_qty, $new_low, current_time('mysql', true), $product_id, $sid
                    ) );
                }
            } else {
                // INSERT ... ON DUPLICATE KEY UPDATE (PK: product_id, location_id)
                $low_sql = is_null($new_low) ? 'NULL' : '%d';
                $sql = "INSERT INTO {$table} (product_id, {$col}, qty, low_stock_amount, updated_at)
                        VALUES (%d, %d, %d, {$low_sql}, %s)
                        ON DUPLICATE KEY UPDATE
                            qty = VALUES(qty),
                            low_stock_amount = VALUES(low_stock_amount),
                            updated_at = VALUES(updated_at)";

                $params = [ $product_id, $sid, $new_qty ];
                if ( ! is_null($new_low) ) { $params[] = $new_low; }
                $params[] = current_time('mysql', true);

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( $wpdb->prepare( $sql, $params ) );
            }


            // Evento: mudança de estoque por local (para alertas/integrações)
            if ( $new_qty !== $old_qty ) {
                $thr = is_null($new_low) ? null : max(0, (int)$new_low);
                if ( is_null($thr) ) {
                    if ( function_exists('wc_get_product') ) {
                        $prod_obj = wc_get_product( $product_id );
                        if ( $prod_obj ) {
                            $thr = (int) wc_get_low_stock_amount( $prod_obj );
                        }
                    }
                }
                if ( is_null($thr) ) { $thr = 0; }
                /**
                 * Dispara quando o estoque por LOCAL é alterado.
                 * @param int    $product_id
                 * @param int    $location_id
                 * @param int    $old_qty
                 * @param int    $new_qty
                 * @param int    $threshold_local (0 se não definido)
                 * @param string $ctx
                 */
                do_action( 'c2p_multistock_changed', (int)$product_id, (int)$sid, (int)$old_qty, (int)$new_qty, (int)$thr, (string)$ctx );
            }
            // Coleta mudança para o Ledger
            if ( $new_qty !== $old_qty ) {
                $changes[] = [
                    'location_id' => (int)$sid,
                    'qty_before'  => (int)$old_qty,
                    'qty_after'   => (int)$new_qty,
                    'delta'       => (int)($new_qty - $old_qty),
                ];
            }
        }

        // Registra no Ledger (se disponível), sem impactar e-mails/colunas
        if ( ! empty($changes) && class_exists('\\C2P\\Stock_Ledger') && method_exists('\\C2P\\Stock_Ledger','record') ) {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $who     = $user_id > 0 ? 'user#'.$user_id : 'system';
            foreach ( $changes as $c ) {
                try {
                    \C2P\Stock_Ledger::record([
                        'product_id'  => $product_id,
                        'location_id' => $c['location_id'],
                        'order_id'    => null,
                        'delta'       => $c['delta'],
                        'qty_before'  => $c['qty_before'],
                        'qty_after'   => $c['qty_after'],
                        'source'      => 'manual_admin',
                        'who'         => $who,
                        'meta'        => [
                            'context' => $ctx,
                            'ip'      => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
                        ],
                    ]);
                } catch ( \Throwable $e ) {
                    // Nunca quebrar o fluxo de salvamento do produto por falha no log
                    // error_log('[C2P][Ledger] Falha ao registrar mudança: '.$e->getMessage());
                }
            }
        }
    }

    /* ================= Reindex + snapshot (REST/meta) ================= */

    /**
     * Recalcula soma por produto, espelha em _stock/_stock_status,
     * limpa caches/lookup tables e atualiza metas REST:
     * - c2p_stock_by_location_ids : [ location_id => qty ]
     * - c2p_stock_by_location     : [ Nome do Local => qty ]
     */
    private function reindex_and_snapshot( int $product_id ): void {
        $sum = $this->sum_multistock( $product_id );

        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_stock_quantity( $sum );
            if ( ! $product->backorders_allowed() ) {
                $product->set_stock_status( $sum > 0 ? 'instock' : 'outofstock' );
            }
            $product->save();
        }

        // Limpa transientes/lookup/cache para refletir imediatamente
        if ( function_exists('wc_delete_product_transients') ) {
            wc_delete_product_transients( $product_id );
        }
        if ( function_exists('wc_update_product_lookup_tables') ) {
            wc_update_product_lookup_tables( $product_id );
        }
        if ( function_exists('clean_post_cache') ) {
            clean_post_cache( $product_id );
        }

        // Atualiza snapshot em metadados para REST/JSON
        $this->update_product_meta_snapshot( $product_id );
    }

    /** Soma do multi-estoque para o produto */
    private function sum_multistock( int $product_id ): int {
        global $wpdb;
        $table = \C2P\Inventory_DB::table_name();
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(qty),0) FROM {$table} WHERE product_id = %d",
            $product_id
        ) );
        return is_numeric($val) ? (int)$val : 0;
    }

    /**
     * Gera metas:
     *  - c2p_stock_by_location_ids : [ location_id => qty ]
     *  - c2p_stock_by_location     : [ Nome do Local => qty ] (apenas conveniência p/ UI / integrações)
     */
    private function update_product_meta_snapshot( int $product_id ): void {
        list($by_id,) = $this->get_product_stocks_with_lows( $product_id );

        // by_name
        $by_name = [];
        foreach ( $by_id as $loc_id => $qty ) {
            $title = get_the_title( $loc_id );
            if ( $title === '' || $title === null ) {
                $title = 'Local #'.$loc_id;
            }
            $by_name[$title] = (int)$qty;
        }

        update_post_meta( $product_id, 'c2p_stock_by_location_ids', $by_id );
        update_post_meta( $product_id, 'c2p_stock_by_location',     $by_name );
    }
}

endif; // class_exists Product_Admin

// === Begin Frontend_Availability ===
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Disponibilidade no front/admin baseada na soma do multi-estoque.
 */
class Frontend_Availability {
    private static $instance;

    public static function instance(): Frontend_Availability {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_filter( 'woocommerce_variation_is_active', [ $this, 'variation_is_active' ], 10, 2 );
        add_filter( 'woocommerce_variation_is_in_stock', [ $this, 'variation_is_in_stock' ], 10, 2 );
        add_filter( 'woocommerce_product_is_in_stock', [ $this, 'product_is_in_stock' ], 10, 2 );
        add_filter( 'woocommerce_product_get_stock_status', [ $this, 'product_get_stock_status' ], 20, 2 );
        add_filter( 'woocommerce_get_availability', [ $this, 'filter_availability_html' ], 20, 2 );
    }

    // ⚠️ 3º parâmetro torna-se opcional para compatibilidade com o hook (que envia 2 args).
    public function variation_is_active( $active, $variation, $parent = null ) {
        $vid = (int) ( $variation ? $variation->get_id() : 0 );
        if ( $vid <= 0 ) return $active;
        return $active || $this->sum_stock_for_products( [ $vid ] ) > 0;
    }
    public function variation_is_in_stock( $in_stock, $variation ) {
        $vid = (int) ( $variation ? $variation->get_id() : 0 );
        if ( $vid <= 0 ) return $in_stock;
        return $this->sum_stock_for_products( [ $vid ] ) > 0;
    }
    public function product_is_in_stock( $in_stock, $product ) {
        if ( ! $product || ! is_a( $product, '\WC_Product' ) ) return $in_stock;
        if ( $product->is_type( 'variable' ) ) {
            $children = array_map( 'intval', (array) $product->get_children() );
            if ( empty( $children ) ) return $in_stock;
            return $this->sum_stock_for_products( $children ) > 0;
        }
        $pid = (int) $product->get_id(); if ( $pid <= 0 ) return $in_stock;
        return $this->sum_stock_for_products( [ $pid ] ) > 0;
    }
    public function product_get_stock_status( $status, $product ) {
        if ( ! $product || ! is_a( $product, '\WC_Product' ) ) return $status;
        if ( $product->is_type( 'variable' ) ) {
            $children = array_map( 'intval', (array) $product->get_children() );
            if ( empty( $children ) ) return $status;
            return ( $this->sum_stock_for_products( $children ) > 0 ) ? 'instock' : 'outofstock';
        }
        $pid = (int) $product->get_id(); if ( $pid <= 0 ) return $status;
        return ( $this->sum_stock_for_products( [ $pid ] ) > 0 ) ? 'instock' : 'outofstock';
    }
    public function filter_availability_html( $availability, $product ) {
        if ( ! $product || ! is_a( $product, '\WC_Product' ) ) return $availability;
        $status = $this->product_get_stock_status( $product->get_stock_status(), $product );
        if ( $status === 'instock' ) {
            $availability['class'] = 'in-stock';
            if ( empty( $availability['availability'] ) ) $availability['availability'] = __( 'Em estoque', 'woocommerce' );
        } elseif ( $status === 'outofstock' ) {
            $availability['class'] = 'out-of-stock';
            if ( empty( $availability['availability'] ) ) $availability['availability'] = __( 'Fora de estoque', 'woocommerce' );
        }
        return $availability;
    }

    private function sum_stock_for_products( array $product_ids ): int {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
        if ( empty( $ids ) ) return 0;

        $table = \C2P\Inventory_DB::table_name();
        $in    = implode( ',', $ids );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sum   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(qty),0) FROM {$table} WHERE product_id IN ({$in})" );
        return max(0,$sum);
    }
}
