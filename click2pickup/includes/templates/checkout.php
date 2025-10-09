<?php
/**
 * Click2Pickup - Template do Checkout Customizado
 * 
 * ✅ v3.4.0: SKU exibido abaixo do nome do produto
 * ✅ v3.4.0: Otimizações de performance e acessibilidade
 * ✅ v3.3.1: Input CEP oculto após aplicar
 * 
 * @package Click2Pickup
 * @since 3.4.0
 * @author rhpimenta
 * Last Update: 2025-01-09 16:35:00 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 16:35: ✅ NOVO: SKU exibido abaixo do nome do produto
 * - 2025-01-09 16:35: ✅ OTIMIZADO: Escape de dados e acessibilidade
 * - 2025-01-09 12:45: ✅ CORRIGIDO: Input CEP permanece visível após aplicar
 */

if (!defined('ABSPATH')) exit;

if (!WC()->cart) {
    echo '<div class="cwc-error">Carrinho não disponível.</div>';
    return;
}

$cart = WC()->cart;
$cart_items = $cart->get_cart();

$saved_postcode = '';
if (WC()->customer) {
    $raw_postcode = WC()->customer->get_shipping_postcode();
    $saved_postcode = preg_replace('/\D/', '', sanitize_text_field($raw_postcode));
}

$has_valid_cep = (
    !empty($saved_postcode) &&
    $saved_postcode !== '00000000' &&
    strlen($saved_postcode) === 8
);

$formatted_cep = $has_valid_cep 
    ? substr($saved_postcode, 0, 5) . '-' . substr($saved_postcode, 5) 
    : '';

$mode = 'home';
if (function_exists('WC') && WC()->session) {
    try {
        $session_mode = WC()->session->get('cwc_delivery_mode', 'home');
        $mode = in_array($session_mode, ['home', 'store'], true) ? $session_mode : 'home';
    } catch (\Throwable $e) {
        $mode = 'home';
    }
}

$home_active = $mode === 'home' ? 'active' : '';
$store_active = $mode === 'store' ? 'active' : '';
?>

<div class="cwc-global-container">
    <div class="cwc-checkout-container">
        
        <!-- COLUNA DOS PRODUTOS -->
        <div class="cwc-products-column">
            <div class="cwc-header">
                <div class="cwc-header-product">Produto</div>
                <div class="cwc-header-quantity">Quantidade</div>
                <div class="cwc-header-price">Preço Unitário</div>
                <div class="cwc-header-actions"></div>
            </div>
            
            <div class="cwc-products-list">
                <?php 
                if (empty($cart_items)) {
                    echo '<div class="cwc-empty-cart">Seu carrinho está vazio.</div>';
                } else {
                    foreach ($cart_items as $cart_item_key => $cart_item): 
                        $_product = $cart_item['data'];
                        $product_id = absint($cart_item['product_id']);
                        
                        if (!$_product || !$_product->exists() || $cart_item['quantity'] <= 0) {
                            continue;
                        }
                        
                        $product_name = $_product->get_name();
                        $product_sku = $_product->get_sku(); // ✅ NOVO: Busca SKU
                        $product_permalink = $_product->get_permalink();
                        $product_price = $_product->get_price();
                        $regular_price = $_product->get_regular_price();
                        $product_quantity = max(1, absint($cart_item['quantity']));
                        $product_thumbnail = $_product->get_image('thumbnail');
                        
                        $stock_qty = $_product->get_stock_quantity();
                        $max_qty = is_numeric($stock_qty) && $stock_qty > 0 ? absint($stock_qty) : 999;
                ?>
                <div class="cwc-product-item" 
                     data-cart-key="<?php echo esc_attr(sanitize_key($cart_item_key)); ?>" 
                     data-product-id="<?php echo esc_attr($product_id); ?>">
                    
                    <div class="cwc-product-image">
                        <?php if ($product_permalink): ?>
                            <a href="<?php echo esc_url($product_permalink); ?>" target="_blank" rel="noopener">
                                <?php echo wp_kses_post($product_thumbnail); ?>
                            </a>
                        <?php else: ?>
                            <?php echo wp_kses_post($product_thumbnail); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="cwc-product-info">
                        <h3 class="cwc-product-name">
                            <?php if ($product_permalink): ?>
                                <a href="<?php echo esc_url($product_permalink); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html($product_name); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($product_name); ?>
                            <?php endif; ?>
                        </h3>
                        
                        <?php 
                        // ✅ NOVO: Exibe SKU se existir
                        if (!empty($product_sku)): 
                        ?>
                        <div class="cwc-product-sku">
                            SKU: <span><?php echo esc_html($product_sku); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php 
                        if (!empty($cart_item['variation']) && is_array($cart_item['variation'])) {
                            echo '<div class="cwc-selected-variations">';
                            foreach ($cart_item['variation'] as $key => $value) {
                                $taxonomy = str_replace('attribute_', '', sanitize_key($key));
                                $term = get_term_by('slug', $value, $taxonomy);
                                
                                $label = wc_attribute_label($taxonomy);
                                $value_label = $term && !is_wp_error($term) ? $term->name : $value;
                                
                                echo '<span class="cwc-variation-item">' . 
                                     esc_html($label . ': ' . $value_label) . 
                                     '</span>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <div class="cwc-quantity-control">
                        <button class="cwc-qty-minus" data-action="minus" aria-label="Diminuir quantidade">−</button>
                        <input type="number" 
                               class="cwc-qty-input" 
                               value="<?php echo esc_attr($product_quantity); ?>" 
                               min="1" 
                               max="<?php echo esc_attr($max_qty); ?>"
                               aria-label="Quantidade do produto <?php echo esc_attr($product_name); ?>">
                        <button class="cwc-qty-plus" data-action="plus" aria-label="Aumentar quantidade">+</button>
                    </div>
                    
                    <div class="cwc-product-price">
                        <?php if ($regular_price > $product_price): ?>
                            <span class="cwc-original-price" aria-label="Preço original">
                                <?php echo wp_kses_post(wc_price($regular_price)); ?>
                            </span>
                        <?php endif; ?>
                        <span class="cwc-sale-price" aria-label="Preço atual">
                            <?php echo wp_kses_post(wc_price($product_price)); ?>
                        </span>
                    </div>
                    
                    <button class="cwc-remove-item" 
                            title="Remover <?php echo esc_attr($product_name); ?> do carrinho" 
                            aria-label="Remover <?php echo esc_attr($product_name); ?> do carrinho">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/>
                        </svg>
                    </button>
                </div>
                <?php 
                    endforeach;
                }
                ?>
            </div>
        </div>
        
        <!-- COLUNA DO RESUMO -->
        <div class="cwc-summary-column">
            <h2 class="cwc-summary-title">Resumo do pedido</h2>
            
            <?php if ($has_valid_cep): ?>
                <div class="cwc-delivery-options">
                    <button class="cwc-delivery-btn cwc-pickup <?php echo esc_attr($home_active); ?>" data-delivery="home">
                        <span class="cwc-btn-icon" aria-hidden="true">🏠</span>
                        <span>Receber em casa</span>
                    </button>
                    <button class="cwc-delivery-btn cwc-store <?php echo esc_attr($store_active); ?>" data-delivery="store">
                        <span class="cwc-btn-icon" aria-hidden="true">🏪</span>
                        <span>Retirar na loja</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- SEÇÃO DE FRETE -->
            <div class="cwc-shipping-calculator" id="shipping-calculator">
                <?php if (!$has_valid_cep): ?>
                    <div class="cwc-cep-required-state">
                        <div class="cwc-cep-icon" aria-hidden="true">📍</div>
                        <h3>Calcule o frete e prazo</h3>
                        <p>Digite seu CEP para ver as opções de entrega disponíveis</p>
                        
                        <div class="cwc-cep-input-main">
                            <input type="text" 
                                   class="cwc-cep-input-large" 
                                   id="cep-input-main" 
                                   placeholder="00000-000"
                                   maxlength="9"
                                   autocomplete="postal-code"
                                   aria-label="Digite seu CEP">
                            <button class="cwc-cep-calculate-btn" id="calculate-shipping-btn">
                                Calcular Frete
                            </button>
                        </div>
                        
                        <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" 
                           target="_blank" 
                           rel="noopener noreferrer"
                           class="cwc-cep-link">
                            Não sei meu CEP
                        </a>
                    </div>
                <?php else: ?>
                    <div class="cwc-shipping-header" id="cwc-shipping-header-persistent">
                        <label>
                            Receber 
                            <span class="cwc-item-count"><?php echo absint(WC()->cart->get_cart_contents_count()); ?> 
                            <?php echo _n('item', 'itens', WC()->cart->get_cart_contents_count(), 'c2p'); ?></span>
                            em 
                            <span class="cwc-cep-display" id="current-cep">
                                <?php echo esc_html($formatted_cep); ?>
                            </span>
                        </label>
                        <button class="cwc-change-cep" id="change-cep">Alterar</button>
                    </div>
                    
                    <div class="cwc-cep-input-group" id="cep-input-group" style="display: none;">
                        <input type="text" 
                               class="cwc-cep-input" 
                               id="cep-input" 
                               placeholder="00000-000"
                               maxlength="9"
                               autocomplete="postal-code"
                               aria-label="Digite novo CEP">
                        <button class="cwc-cep-apply" id="apply-cep">Aplicar</button>
                    </div>
                    
                    <div class="cwc-shipping-select-wrapper" id="cwc-shipping-cards-container">
                        <!-- JS renderiza os cards aqui -->
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($has_valid_cep): ?>
                <div id="cwc-pickup-container" style="<?php echo $mode === 'store' ? '' : 'display:none;'; ?>"></div>
            <?php endif; ?>

            <!-- CUPOM -->
            <div class="cwc-coupon-section">
                <label for="coupon-input">Cupom de desconto</label>
                <div class="cwc-coupon-input-group">
                    <input type="text" 
                           class="cwc-coupon-input" 
                           id="coupon-input"
                           placeholder="Digite aqui"
                           autocomplete="off"
                           aria-label="Digite o cupom de desconto">
                    <button class="cwc-coupon-btn" id="apply-coupon">Adicionar</button>
                </div>
                
                <?php if ($cart->get_coupons()): ?>
                <div class="cwc-applied-coupons">
                    <?php foreach ($cart->get_coupons() as $code => $coupon): ?>
                    <div class="cwc-coupon-tag">
                        <span><?php echo esc_html($code); ?></span>
                        <button class="cwc-remove-coupon" 
                                data-coupon="<?php echo esc_attr($code); ?>"
                                aria-label="Remover cupom <?php echo esc_attr($code); ?>">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- RESUMO DE VALORES -->
            <div class="cwc-price-summary">
                <div class="cwc-price-row">
                    <span>Subtotal</span>
                    <span class="cwc-subtotal"><?php echo wp_kses_post(wc_price($cart->get_subtotal())); ?></span>
                </div>
                
                <?php if ($cart->get_discount_total() > 0): ?>
                <div class="cwc-price-row cwc-discount">
                    <span>Desconto</span>
                    <span class="cwc-discount-value">-<?php echo wp_kses_post(wc_price($cart->get_discount_total())); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="cwc-price-row">
                    <span>Frete</span>
                    <span class="cwc-shipping">
                        <?php 
                        if ($has_valid_cep) {
                            $shipping_total = $cart->get_shipping_total();
                            echo $shipping_total == 0 
                                ? '<span class="cwc-free-shipping">Grátis</span>' 
                                : wp_kses_post(wc_price($shipping_total));
                        } else {
                            echo '<span class="cwc-calculate-first">A calcular</span>';
                        }
                        ?>
                    </span>
                </div>
                
                <div class="cwc-price-row cwc-total">
                    <span>Total</span>
                    <span class="cwc-total-value"><?php echo wp_kses_post(wc_price($cart->get_total(''))); ?></span>
                </div>
            </div>
            
            <!-- BOTÃO FINALIZAR -->
            <button class="cwc-checkout-btn" id="finalize-checkout">
                <span class="cwc-btn-icon" aria-hidden="true">🛒</span>
                <span>Finalizar compra</span>
            </button>
            
            <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="cwc-continue-shopping">
                Continuar comprando
            </a>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="cwc-loading-overlay" id="cwc-loading" style="display: none;" role="status" aria-live="polite">
    <div class="cwc-spinner" aria-label="Carregando"></div>
    <span class="sr-only">Carregando...</span>
</div>

<style>
/* ✅ NOVO: Estilo do SKU */
.cwc-product-sku {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
    font-weight: 400;
}

.cwc-product-sku span {
    font-weight: 500;
    color: #374151;
}

/* ✅ FIX: Controla visibilidade do input CEP */
#cep-input-group {
    display: none !important;
}

#cep-input-group.active {
    display: flex !important;
}

/* ✅ ACESSIBILIDADE: Screen reader only */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}
</style>

<script>
(function($) {
    'use strict';
    
    // ✅ Mostra input ao clicar em "Alterar"
    $(document).on('click', '#change-cep', function(e) {
        e.preventDefault();
        $('#cwc-shipping-header-persistent').hide();
        $('#cep-input-group').addClass('active').show();
        $('#cep-input').focus();
    });
    
    // ✅ Esconde input após aplicar CEP
    $(document).on('click', '#apply-cep', function(e) {
        var cep = $('#cep-input').val().replace(/\D/g, '');
        
        if (cep.length === 8) {
            $('#cep-input-group').removeClass('active').hide();
            $('#cwc-shipping-header-persistent').show();
        }
    });
    
    // ✅ Garante que input está oculto ao carregar
    $(document).ready(function() {
        if ($('#current-cep').length) {
            $('#cep-input-group').removeClass('active').hide();
            $('#cwc-shipping-header-persistent').show();
        }
    });
    
})(jQuery);
</script>