<?php
// (vazio intencionalmente no topo)
?>
<section id="cart-step-1" class="c2p-step">
  <div class="uk-margin uk-card uk-card-body uk-card-default uk-card-hover">

    <?php
    // --- Limpa da fila QUALQUER notice que contenha o texto genérico indesejado
    if ( function_exists('wc_get_notices') ) {
        $needles = [
            'Revise seus itens e a forma de entrega', // casa mesmo com variações/pontuação
        ];
        $queue   = wc_get_notices();
        $types   = ['error','success','notice'];
        $changed = false;

        // Normaliza texto para comparação
        $norm = static function($s){
            $s = wp_strip_all_tags( html_entity_decode( (string)$s ) );
            $s = preg_replace('/\s+/u',' ', trim($s) );
            return $s;
        };

        foreach ( $types as $type ) {
            if ( empty($queue[$type]) ) continue;

            foreach ( $queue[$type] as $i => $notice ) {
                $raw   = is_array($notice) && isset($notice['notice']) ? $notice['notice'] : (string)$notice;
                $plain = $norm($raw);

                foreach ( $needles as $needle ) {
                    if ($needle === '') continue;
                    // comparação case-insensitive por substring
                    if ( stripos($plain, $needle) !== false ) {
                        unset($queue[$type][$i]);
                        $changed = true;
                        break;
                    }
                }
            }
        }

        if ( $changed ) {
            wc_clear_notices();
            // reempilha o restante preservando tipos/ordem
            foreach ( $types as $type ) {
                if ( empty($queue[$type]) ) continue;
                foreach ( $queue[$type] as $notice ) {
                    $text = is_array($notice) && isset($notice['notice']) ? $notice['notice'] : (string)$notice;
                    wc_add_notice( $text, $type );
                }
            }
        }
    }
    ?>

    <!-- Notices do WooCommerce (aplicar cupom, erros, etc.) -->
    <div class="woocommerce-notices-wrapper">
      <?php wc_print_notices(); ?>
    </div>

    <!-- Form do carrinho -->
    <form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">

      <?php do_action( 'woocommerce_before_cart_table' ); ?>

      <table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents uk-table uk-table-divider uk-table-middle" style="width:100%;">
        <thead>
          <tr>
            <th class="product-name"><?php esc_html_e( 'Produto', 'woocommerce' ); ?></th>
            <th class="product-price uk-text-center"><?php esc_html_e( 'Preço', 'woocommerce' ); ?></th>
            <th class="product-quantity uk-text-center"><?php esc_html_e( 'Qtd', 'woocommerce' ); ?></th>
            <th class="product-subtotal uk-text-center"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php
        $valid_items = [];
        foreach ( WC()->cart->get_cart() as $k => $ci ) {
            $_p = isset($ci['data']) ? $ci['data'] : null;
            if ( $_p && $_p->exists() && $ci['quantity'] > 0 ) $valid_items[] = [$k, $ci, $_p];
        }

        foreach ( $valid_items as $triple ) :
            $cart_item_key = $triple[0];
            $cart_item     = $triple[1];
            $_product      = $triple[2];

            $product_permalink = $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '';
            $product_name      = $_product->get_name();
        ?>
          <tr class="woocommerce-cart-form__cart-item">
            <td class="product-name" data-title="<?php esc_attr_e('Produto','woocommerce'); ?>">
              <?php
                echo apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image([100,100]), $cart_item, $cart_item_key ) . ' ';
                if ( $product_permalink ) {
                    echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $product_name ), $cart_item, $cart_item_key ) );
                } else {
                    echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product_name, $cart_item, $cart_item_key ) );
                }
                echo wc_get_formatted_cart_item_data( $cart_item );
              ?>
            </td>

            <td class="product-price uk-text-center" data-title="<?php esc_attr_e('Preço','woocommerce'); ?>">
              <?php echo WC()->cart->get_product_price( $_product ); ?>
            </td>

            <td class="product-quantity uk-text-center" data-title="<?php esc_attr_e('Qtd','woocommerce'); ?>">
              <?php
                $sold_individually = $_product->is_sold_individually();
                $min  = $sold_individually ? 1 : 0;
                $max  = $sold_individually ? 1 : $_product->get_max_purchase_quantity(); // -1 => ilimitado
                $qty  = (int) $cart_item['quantity'];
              ?>
              <div class="c2p-qty uk-flex uk-flex-middle uk-flex-center uk-flex-nowrap">
                <button type="button" class="uk-button uk-button-link uk-button-small c2p-qty-minus"
                        data-c2p-delta="-1"
                        aria-label="<?php esc_attr_e('Diminuir','woocommerce'); ?>"
                        uk-icon="icon: minus; ratio:0.9"
                        style="margin-right:4px;"></button>

                <input type="text"
                       name="cart[<?php echo esc_attr($cart_item_key); ?>][qty]"
                       value="<?php echo esc_attr($qty); ?>"
                       inputmode="numeric" pattern="[0-9]*"
                       class="uk-input uk-form-blank qty"
                       data-min="<?php echo esc_attr($min); ?>"
                       data-step="1"
                       <?php if ( $max > 0 ) : ?>data-max="<?php echo esc_attr($max); ?>"<?php endif; ?>
                       style="width:5.2ch; text-align:center; -moz-appearance:textfield; appearance:textfield;"
                       aria-label="<?php esc_attr_e('Quantidade','woocommerce'); ?>" />

                <button type="button" class="uk-button uk-button-link uk-button-small c2p-qty-plus"
                        data-c2p-delta="+1"
                        aria-label="<?php esc_attr_e('Aumentar','woocommerce'); ?>"
                        uk-icon="icon: plus; ratio:0.9"
                        style="margin-left:4px;"></button>
              </div>
            </td>

            <td class="product-subtotal uk-text-center" data-title="<?php esc_attr_e('Subtotal','woocommerce'); ?>">
              <?php echo WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ); ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php do_action( 'woocommerce_after_cart_table' ); ?>

      <!-- Divisor com margens SMALL -->
      <hr class="uk-divider-icon uk-margin-small-top uk-margin-small-bottom">

      <!-- Cupom + CTA -->
      <div class="cart-actions uk-margin">

        <div class="uk-margin uk-card uk-card-body uk-card-secondary uk-card-hover uk-width-auto uk-accordion"
             uk-accordion="collapsible: true; multiple: false"
             style="width:300px;padding-top:10px;padding-bottom:10px;">
          <div class="uk-accordion-item">
            <a class="uk-accordion-title uk-text-muted" href="#" style="width:280px;padding-left:15px;">
              <?php esc_html_e('Possui cupom de desconto?', 'woocommerce'); ?>
            </a>
            <div class="uk-accordion-content">
              <div class="uk-grid-small uk-flex-middle" uk-grid>
                <div class="uk-width-1-3@s uk-width-1-2 uk-first-column" style="width:215px;">
                  <label class="uk-hidden" for="coupon_code">Cupom</label>
                  <input type="text" name="coupon_code" id="coupon_code"
                         class="uk-input uk-form-blank uk-form-small input-text"
                         placeholder="<?php echo esc_attr__('Código do cupom','woocommerce'); ?>">
                </div>
                <div class="uk-width-auto">
                  <button type="submit" name="apply_coupon" value="<?php echo esc_attr__('Aplicar','woocommerce'); ?>"
                          class="uk-button uk-button-link">
                    <?php esc_html_e('Aplicar','woocommerce'); ?>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php do_action( 'woocommerce_cart_actions' ); ?>

        <div class="uk-flex uk-flex-between uk-flex-middle uk-margin">
          <span></span>
          <?php echo wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce', true, false ); ?>
          <a href="#cart-step-2" data-c2p-next="2" class="uk-button uk-button-primary wc-forward">
            <?php esc_html_e('Ir para entrega','woocommerce'); ?>
          </a>
        </div>
      </div>

      <!-- Hidden update_cart para auto-update -->
      <input type="hidden" name="update_cart" value="<?php echo esc_attr__('Atualizar carrinho','woocommerce'); ?>">
    </form>

    <div class="cart-subtotal uk-margin">
      <strong><?php esc_html_e('Subtotal:', 'woocommerce'); ?></strong>
      <?php echo WC()->cart->get_cart_subtotal(); ?>
    </div>
  </div>
</section>
