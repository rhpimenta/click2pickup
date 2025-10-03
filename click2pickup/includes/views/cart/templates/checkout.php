<?php
/**
 * Template do Checkout Customizado
 * Version: 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Garante que temos acesso ao carrinho
if (!WC()->cart) {
    return;
}

$cart       = WC()->cart;
$cart_items = $cart->get_cart();

// Verifica o CEP do cliente
$saved_postcode = WC()->customer->get_shipping_postcode();
$saved_postcode = preg_replace('/[^0-9]/', '', $saved_postcode);
$has_valid_cep  = (
    !empty($saved_postcode) &&
    $saved_postcode !== '00000000' &&
    strlen($saved_postcode) === 8
);

// Modo atual (garante UI consistente entre botões / seções)
$mode         = ( function_exists('WC') && WC()->session ) ? WC()->session->get('cwc_delivery_mode', 'home') : 'home';
$home_active  = $mode === 'home'  ? 'active' : '';
$store_active = $mode === 'store' ? 'active' : '';
?>
<!-- Container Global -->
<div class="cwc-global-container">
    <!-- Container do Checkout -->
    <div class="cwc-checkout-container">
        <!-- Coluna dos Produtos -->
        <div class="cwc-products-column">
            <div class="cwc-header">
                <div class="cwc-header-product">Produto</div>
                <div class="cwc-header-quantity">Quantidade</div>
                <div class="cwc-header-price">Preço Unitário</div>
                <div class="cwc-header-actions"></div>
            </div>
            
            <div class="cwc-products-list">
                <?php 
                foreach ($cart_items as $cart_item_key => $cart_item): 
                    $_product         = $cart_item['data'];
                    $product_id       = $cart_item['product_id'];
                    $product          = wc_get_product($product_id);
                    
                    if (!$_product || !$_product->exists() || $cart_item['quantity'] <= 0 || !apply_filters('woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key)) {
                        continue;
                    }
                    
                    $product_name      = $_product->get_name();
                    $product_permalink = $_product->get_permalink();
                    $product_price     = $_product->get_price();
                    $regular_price     = $_product->get_regular_price();
                    $product_quantity  = $cart_item['quantity'];
                    $product_thumbnail = $_product->get_image('thumbnail');
                ?>
                <div class="cwc-product-item" data-cart-key="<?php echo esc_attr($cart_item_key); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
                    <!-- Imagem do Produto -->
                    <div class="cwc-product-image">
                        <?php if ($product_permalink): ?>
                            <a href="<?php echo esc_url($product_permalink); ?>" target="_blank">
                                <?php echo $product_thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </a>
                        <?php else: ?>
                            <?php echo $product_thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Informações do Produto -->
                    <div class="cwc-product-info">
                        <h3 class="cwc-product-name">
                            <?php if ($product_permalink): ?>
                                <a href="<?php echo esc_url($product_permalink); ?>" target="_blank">
                                    <?php echo esc_html($product_name); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($product_name); ?>
                            <?php endif; ?>
                        </h3>
                        
                        <?php 
                        // Exibe variações selecionadas
                        if (!empty($cart_item['variation'])) {
                            echo '<div class="cwc-selected-variations">';
                            foreach ($cart_item['variation'] as $key => $value) {
                                $taxonomy    = str_replace('attribute_', '', $key);
                                $term        = get_term_by('slug', $value, $taxonomy);
                                $label       = wc_attribute_label($taxonomy);
                                $value_label = $term ? $term->name : $value;
                                echo '<span class="cwc-variation-item">' . esc_html($label . ': ' . $value_label) . '</span>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <!-- Controle de Quantidade -->
                    <div class="cwc-quantity-control">
                        <button class="cwc-qty-minus" data-action="minus">−</button>
                        <input type="number" 
                               class="cwc-qty-input" 
                               value="<?php echo esc_attr($product_quantity); ?>" 
                               min="1" 
                               max="<?php echo esc_attr($_product->get_stock_quantity() ?: 999); ?>">
                        <button class="cwc-qty-plus" data-action="plus">+</button>
                    </div>
                    
                    <!-- Preços -->
                    <div class="cwc-product-price">
                        <?php if ($regular_price > $product_price): ?>
                            <span class="cwc-original-price"><?php echo wc_price($regular_price); // phpcs:ignore ?></span>
                        <?php endif; ?>
                        <span class="cwc-sale-price"><?php echo wc_price($product_price); // phpcs:ignore ?></span>
                    </div>
                    
                    <!-- Botão Remover -->
                    <button class="cwc-remove-item" title="Remover do carrinho" aria-label="Remover do carrinho">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                            <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/>
                        </svg>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Coluna do Resumo -->
        <div class="cwc-summary-column">
            <h2 class="cwc-summary-title">Resumo do pedido</h2>
            
            <!-- Opção de Entrega -->
            <div class="cwc-delivery-options">
                <button class="cwc-delivery-btn cwc-pickup <?php echo esc_attr($home_active); ?>" data-delivery="home">
                    <span class="cwc-btn-icon">🏠</span>
                    <span>Receber em casa</span>
                </button>
                <button class="cwc-delivery-btn cwc-store <?php echo esc_attr($store_active); ?>" data-delivery="store">
                    <span class="cwc-btn-icon">🏪</span>
                    <span>Retirar na loja</span>
                </button>
            </div>
            
            <?php
            /**
             * Inclui a seção de frete, mas força a visibilidade conforme o modo atual,
             * removendo/adicionando o style="display:none" do #shipping-calculator se necessário.
             */
            ob_start();
            include CWC_PLUGIN_PATH . 'checkout-shipping-section.php';
            $shipping_html = ob_get_clean();

            // Normaliza o atributo style do shipping-calculator baseado no modo
            if ( $mode === 'home' ) {
                // remove display:none do shipping-calculator
                $shipping_html = preg_replace(
                    '#(<div[^>]*id=["\']shipping-calculator["\'][^>]*)(style=["\']?[^"\']*display\s*:\s*none;?[^"\']*["\']?)#i',
                    '$1',
                    $shipping_html
                );
            } else {
                // garante display:none quando em modo store
                if ( preg_match('#id=["\']shipping-calculator["\']#i', $shipping_html) ) {
                    if ( preg_match('#id=["\']shipping-calculator["\'][^>]*style=#i', $shipping_html) ) {
                        // já tem style -> substitui/força display:none
                        $shipping_html = preg_replace(
                            '#(id=["\']shipping-calculator["\'][^>]*style=["\'][^"\']*)["\']#i',
                            '$1;display:none;"',
                            $shipping_html
                        );
                        // remove possíveis duplicidades de display
                        $shipping_html = preg_replace('#display\s*:\s*[^;"]+;#i', 'display:none;', $shipping_html, 1);
                    } else {
                        // não tem style -> adiciona
                        $shipping_html = preg_replace(
                            '#(<div[^>]*id=["\']shipping-calculator["\'][^>]*)>#i',
                            '$1 style="display:none;">',
                            $shipping_html
                        );
                    }
                }
            }

            echo $shipping_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>

            <!-- Container para as opções de retirada (preenchido via AJAX com pickup_html) -->
            <div id="cwc-pickup-container" style="<?php echo $mode === 'store' ? '' : 'display:none;'; ?>"></div>

            <!-- Cupom -->
            <div class="cwc-coupon-section">
                <label for="coupon-input">Cupom de desconto</label>
                <div class="cwc-coupon-input-group">
                    <input type="text" 
                           class="cwc-coupon-input" 
                           id="coupon-input"
                           placeholder="Digite aqui">
                    <button class="cwc-coupon-btn" id="apply-coupon">Adicionar</button>
                </div>
                
                <!-- Cupons Aplicados -->
                <?php if ($cart->get_coupons()): ?>
                <div class="cwc-applied-coupons">
                    <?php foreach ($cart->get_coupons() as $code => $coupon): ?>
                    <div class="cwc-coupon-tag">
                        <span><?php echo esc_html($code); ?></span>
                        <button class="cwc-remove-coupon" data-coupon="<?php echo esc_attr($code); ?>">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Resumo de Valores -->
            <div class="cwc-price-summary">
                <div class="cwc-price-row">
                    <span>Subtotal</span>
                    <span class="cwc-subtotal"><?php echo wc_price($cart->get_subtotal()); // phpcs:ignore ?></span>
                </div>
                
                <?php if ($cart->get_discount_total() > 0): ?>
                <div class="cwc-price-row cwc-discount">
                    <span>Desconto</span>
                    <span class="cwc-discount-value">-<?php echo wc_price($cart->get_discount_total()); // phpcs:ignore ?></span>
                </div>
                <?php endif; ?>
                
                <div class="cwc-price-row">
                    <span>Frete</span>
                    <span class="cwc-shipping">
                        <?php 
                        if ($has_valid_cep) {
                            $shipping_total = $cart->get_shipping_total();
                            echo $shipping_total == 0 ? '<span class="cwc-free-shipping">Grátis</span>' : wc_price($shipping_total); // phpcs:ignore
                        } else {
                            echo '<span class="cwc-calculate-first">A calcular</span>';
                        }
                        ?>
                    </span>
                </div>
                
                <div class="cwc-price-row cwc-total">
                    <span>Total</span>
                    <span class="cwc-total-value"><?php echo wc_price($cart->get_total('')); // phpcs:ignore ?></span>
                </div>
            </div>
            
            <!-- Botão Finalizar -->
            <button class="cwc-checkout-btn" id="finalize-checkout">
                <span class="cwc-btn-icon">🛒</span>
                <span>Finalizar compra</span>
            </button>
            
            <a href="<?php echo esc_url( get_permalink(wc_get_page_id('shop')) ); ?>" class="cwc-continue-shopping">
                Continuar comprando
            </a>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="cwc-loading-overlay" id="cwc-loading" style="display: none;">
    <div class="cwc-spinner"></div>
</div>
