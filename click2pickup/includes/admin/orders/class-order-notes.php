<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Notas de pedido (baixa por local) + carimbo de unidade (envio/retirada).
 *
 * - Escuta a redução de estoque nativa do WooCommerce (por item) e
 *   registra uma nota "C2P: Estoque por local reduzido".
 * - Adiciona uma nota separada informando a Unidade (Loja/CD) responsável.
 * - Tenta compor "ANTES→DEPOIS" lendo a tabela {prefix}c2p_multi_stock (somente leitura).
 * - Acrescenta um resumo consolidado do WooCommerce no formato [Woo: estoque_atual - vendido].
 * - NÃO altera estoque (apenas registra notas).
 */
class Order_Notes {
    private static $instance;
    /** @var array<int,array{lines:array<int,string>}> */
    private $buffer = [];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Nota por item reduzido (desde WC 7.6: passa $item, $change, $order)
        add_action( 'woocommerce_reduce_order_item_stock', [ $this, 'on_reduce_item_stock' ], 20, 3 );
        // Ao final da redução do pedido, escrever nota consolidada
        add_action( 'woocommerce_reduce_order_stock', [ $this, 'flush_order_notes' ], 10, 1 );
        // Carimbar a unidade escolhida no momento da criação do pedido (se ainda não houver)
        add_action( 'woocommerce_checkout_create_order', [ $this, 'stamp_fulfillment_unit_on_create' ], 20, 2 );
    }

    /** === Helpers centrais ================================================= */

    /** Nome da tabela multi-estoque */
    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'c2p_multi_stock';
    }

    /**
     * Lê o saldo (qty) por product/variation/location — somente leitura.
     * @return int|null null se não encontrado.
     */
    private function get_location_qty( int $product_id, ?int $variation_id, int $location_id ): ?int {
        global $wpdb;
        $table = $this->table_name();
        // Colunas canônicas esperadas: product_id, variation_id, location_id, qty
        $var_id = $variation_id ? $variation_id : 0;
        $sql = $wpdb->prepare(
            "SELECT qty FROM {$table} WHERE product_id=%d AND variation_id=%d AND location_id=%d LIMIT 1",
            $product_id, $var_id, $location_id
        );
        $val = $wpdb->get_var( $sql );
        return ( null !== $val ) ? (int) $val : null;
    }

    /**
     * Obtém a "unidade de atendimento" do pedido.
     * Retorna array: ['mode' => 'pickup'|'delivery'|null, 'location_id' => int|null, 'location_name' => string]
     */
    private function get_order_fulfillment_unit( \WC_Order $order ): array {
        $candidates = ['_c2p_store_id', '_c2p_location_id', 'c2p_store_id', 'c2p_location_id', 'c2p_selected_store', 'rpws_store_id'];
        $location_id = null;
        foreach ( $candidates as $key ) {
            $v = $order->get_meta( $key, true );
            if ( '' !== $v && null !== $v ) { $location_id = (int) $v; break; }
        }
        $mode = $order->get_meta( '_c2p_mode', true ) ?: $order->get_meta( 'c2p_mode', true );
        if ( $mode === 'RECEBER' ) $mode = 'delivery';
        if ( $mode === 'RETIRAR' ) $mode = 'pickup';

        $location_name = 'CD Global';
        if ( $location_id ) {
            $post = get_post( $location_id );
            if ( $post && 'publish' === $post->post_status ) {
                $location_name = $post->post_title;
            }
        }
        return ['mode'=>$mode ?: null, 'location_id'=>$location_id, 'location_name'=>$location_name];
    }

    /** === Hooks ============================================================ */

    /**
     * Disparado por item quando o WooCommerce reduz estoque.
     * @param \WC_Order_Item_Product $item
     * @param array $change ['product'=>WC_Product,'from'=>int,'to'=>int]
     * @param \WC_Order $order
     */
    public function on_reduce_item_stock( $item, $change, $order ) {
        if ( ! $order instanceof \WC_Order ) return;

        $order_id = (int) $order->get_id();
        if ( ! isset( $this->buffer[ $order_id ] ) ) {
            $this->buffer[ $order_id ] = [ 'lines' => [] ];
        }

        $product   = $change['product'];
        $from      = isset($change['from']) ? (int) $change['from'] : 0;
        $to        = isset($change['to'])   ? (int) $change['to']   : 0;
        $qty_delta = absint( $from - $to );

        // Dados do produto
        $sku  = $product ? $product->get_sku() : '';
        $name = $product ? wp_strip_all_tags( $product->get_name() ) : $item->get_name(); // <- evita SKU duplicado
        // Se o nome já contém o SKU entre parênteses, não repetir
        $tag_sku = '';
        if ( $sku ) {
            $sku_in_name = (bool) preg_match( '/\(\s*'.preg_quote($sku,'/').'\s*\)$/', $name );
            if ( ! $sku_in_name ) {
                $tag_sku = " ({$sku})";
            }
        }

        // IDs p/ lookup de estoque por local
        $pid = $product ? (int)$product->get_id() : (int)$item->get_product_id();
        $vid = $product && $product->is_type('variation') ? (int)$product->get_id() : (int)$item->get_variation_id();
        if ( $product && $product->is_type('variation') ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) $pid = (int) $parent_id;
        }

        // Unidade do pedido
        $unit = $this->get_order_fulfillment_unit( $order );
        $loc_id   = $unit['location_id'] ?: 0;
        $loc_name = $unit['location_name'];

        // Tentar compor "ANTES→DEPOIS" por local (somente leitura da tabela)
        $local_display = '-' . $qty_delta; // fallback
        if ( $loc_id ) {
            $before = $this->get_location_qty( $pid, $vid ?: null, $loc_id );
            if ( null !== $before ) {
                $after        = max( 0, $before - $qty_delta );
                $local_display = sprintf( '%d→%d', $before, $after );
            }
        }

        // Consolidado WooCommerce: estoque_atual (to) - vendido (delta)
        $woo_summary = sprintf( '%d - %d', max(0,$to), $qty_delta );

        // Linha da nota (sem SKU duplicado)
        $line = sprintf(
            '%s — %s%s %s [Woo: %s]',
            $loc_name,
            $name,
            $tag_sku,
            $local_display,
            $woo_summary
        );

        $this->buffer[ $order_id ]['lines'][] = $line;
    }

    /**
     * Ao final da redução do pedido, escreve:
     * - Nota de Unidade (uma vez por pedido)
     * - Nota consolidada de baixa por local
     */
    public function flush_order_notes( $order ) {
        if ( ! $order instanceof \WC_Order ) return;

        $order_id = (int) $order->get_id();
        $buf = $this->buffer[ $order_id ] ?? null;

        // Nota de Unidade (se ainda não escrita)
        $already = $order->get_meta( '_c2p_unit_note_added', true );
        if ( ! $already ) {
            $unit = $this->get_order_fulfillment_unit( $order );
            $label_mode = $unit['mode'] === 'pickup' ? 'RETIRADA NA LOJA' : ( $unit['mode'] === 'delivery' ? 'ENVIO' : 'ATENDIMENTO' );
            $note_unit = sprintf( 'C2P • Unidade responsável: %s — %s', $unit['location_name'], $label_mode );
            $order->add_order_note( $note_unit );
            $order->update_meta_data( '_c2p_unit_note_added', 1 );
            $order->save();
        }

        // Nota consolidada de baixa por local
        if ( $buf && ! empty( $buf['lines'] ) ) {
            $note = 'C2P • Estoque por local reduzido: ' . implode( ', ', $buf['lines'] );
            $order->add_order_note( $note );
        }

        unset( $this->buffer[ $order_id ] );
    }

    /**
     * No checkout, grava metas de unidade se ainda não existirem (compat com sua etapa 2).
     * NÃO sobrescreve caso já estejam gravadas por outra parte do plugin.
     */
    public function stamp_fulfillment_unit_on_create( \WC_Order $order, $data ) {
        if ( $order->get_meta( '_c2p_store_id', true ) || $order->get_meta( 'c2p_store_id', true ) || $order->get_meta( 'c2p_location_id', true ) ) {
            return;
        }
        $sess = function_exists('WC') && WC()->session ? WC()->session : null;
        $maybe_store = $sess ? ( $sess->get('c2p_store_id') ?: $sess->get('c2p_location_id') ?: $sess->get('c2p_selected_store') ) : null;
        $maybe_mode  = $sess ? ( $sess->get('c2p_mode') ) : null;

        if ( $maybe_store ) $order->update_meta_data( '_c2p_store_id', (int)$maybe_store );
        if ( $maybe_mode )  $order->update_meta_data( '_c2p_mode', $maybe_mode );

        if ( $maybe_store || $maybe_mode ) {
            $order->save();
        }
    }
}
Order_Notes::instance();
