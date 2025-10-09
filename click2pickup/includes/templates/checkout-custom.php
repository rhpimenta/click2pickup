<?php
/**
 * Click2Pickup - Template do Checkout Customizado
 * 
 * ✅ v1.0.1: DEBUG MODE + VALIDAÇÕES WC
 * ✅ v1.0.0: Checkout em steps progressivos
 * 
 * @package Click2Pickup
 * @since 1.0.1
 * @author rhpimenta
 * Last Update: 2025-01-09 20:19:00 UTC
 * 
 * Shortcode: [c2p_checkout]
 */

if (!defined('ABSPATH')) exit;

// ✅ DEBUG: Verifica estado do WooCommerce
$debug_info = [];
$debug_info['WC existe'] = function_exists('WC') ? 'SIM' : 'NÃO';
$debug_info['Cart existe'] = (function_exists('WC') && WC()->cart) ? 'SIM' : 'NÃO';
$debug_info['Cart itens'] = (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0;
$debug_info['Session existe'] = (function_exists('WC') && WC()->session) ? 'SIM' : 'NÃO';
$debug_info['Customer existe'] = (function_exists('WC') && WC()->customer) ? 'SIM' : 'NÃO';

// ✅ Mostra debug info no código-fonte
echo '<!-- DEBUG C2P CHECKOUT v1.0.1: ' . print_r($debug_info, true) . ' -->';

// ✅ Se WC não existe, para aqui
if (!function_exists('WC') || !WC()->cart) {
    echo '<div class="c2p-checkout-empty" style="padding:60px 20px;text-align:center;background:#fff;border:2px solid #dc2626;border-radius:8px;margin:20px;">';
    echo '<div class="c2p-empty-icon" style="font-size:80px;margin-bottom:20px;">⚠️</div>';
    echo '<h2 style="font-size:24px;margin-bottom:12px;color:#dc2626;">Erro de inicialização</h2>';
    echo '<p style="color:#6b7280;margin-bottom:24px;">WooCommerce não foi carregado corretamente.</p>';
    echo '<pre style="background:#f0f0f0;padding:15px;border-radius:6px;font-size:12px;text-align:left;max-width:500px;margin:0 auto 20px;overflow:auto;">';
    print_r($debug_info);
    echo '</pre>';
    echo '<a href="' . esc_url(get_permalink(wc_get_page_id('shop'))) . '" class="c2p-btn-primary" style="display:inline-block;padding:14px 32px;background:#003d82;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Voltar para a loja</a>';
    echo '</div>';
    return;
}

// ✅ Verifica se carrinho está vazio
if (WC()->cart->is_empty()) {
    echo '<div class="c2p-checkout-empty" style="padding:60px 20px;text-align:center;">';
    echo '<div class="c2p-empty-icon" style="font-size:80px;margin-bottom:20px;">🛒</div>';
    echo '<h2 style="font-size:24px;margin-bottom:12px;">Seu carrinho está vazio</h2>';
    echo '<p style="color:#6b7280;margin-bottom:24px;">Adicione produtos antes de finalizar a compra.</p>';
    echo '<a href="' . esc_url(get_permalink(wc_get_page_id('shop'))) . '" class="c2p-btn-primary" style="display:inline-block;padding:14px 32px;background:#003d82;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Ir para a loja</a>';
    echo '</div>';
    return;
}

// Verifica se o usuário escolheu método de entrega
$chosen_methods = WC()->session->get('chosen_shipping_methods');
$has_shipping_method = !empty($chosen_methods);

// Pega dados do customer
$customer = WC()->customer;
$user = wp_get_current_user();

// Pega modo de entrega do carrinho (home ou store)
$delivery_mode = WC()->session->get('cwc_delivery_mode', 'home');
?>

<div class="c2p-checkout-global">
    <div class="c2p-checkout-wrapper">
        
        <!-- COLUNA ESQUERDA: STEPS -->
        <div class="c2p-checkout-steps">
            
            <!-- STEP 1: EMAIL -->
            <div class="c2p-step c2p-step-active" id="step-email" data-step="1">
                <div class="c2p-step-header">
                    <div class="c2p-step-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                    </div>
                    <h2 class="c2p-step-title">Email</h2>
                    <button class="c2p-step-edit" style="display:none;">Alterar</button>
                </div>
                
                <div class="c2p-step-content">
                    <p class="c2p-step-description">Para finalizar sua compra, informe seu email.</p>
                    
                    <div class="c2p-form-group">
                        <label for="checkout-email">Email: <span class="required">*</span></label>
                        <div class="c2p-input-wrapper">
                            <input 
                                type="email" 
                                id="checkout-email" 
                                name="billing_email"
                                class="c2p-input" 
                                placeholder="seu@email.com"
                                value="<?php echo esc_attr($customer->get_billing_email() ?: $user->user_email); ?>"
                                required
                                autocomplete="email">
                            <span class="c2p-input-icon c2p-input-loading" style="display:none;">
                                <svg class="c2p-spinner-icon" width="20" height="20" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.25"/>
                                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" fill="none"/>
                                </svg>
                            </span>
                            <span class="c2p-input-icon c2p-input-success" style="display:none;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="3">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            </span>
                            <span class="c2p-input-icon c2p-input-error" style="display:none;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                            </span>
                        </div>
                        <small class="c2p-field-help">Usamos seu e-mail de forma 100% segura para:</small>
                        <ul class="c2p-email-benefits">
                            <li>✓ Identificar seu perfil</li>
                            <li>✓ Notificar sobre o andamento do seu pedido</li>
                            <li>✓ Gerenciar seu histórico de compras</li>
                            <li>✓ Acelerar o preenchimento de suas informações</li>
                        </ul>
                    </div>
                    
                    <button type="button" class="c2p-btn-continue" id="btn-continue-email" disabled>
                        Continuar
                    </button>
                </div>
            </div>
            
            <!-- STEP 2: DADOS PESSOAIS -->
            <div class="c2p-step c2p-step-locked" id="step-personal" data-step="2">
                <div class="c2p-step-header">
                    <div class="c2p-step-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                    <h2 class="c2p-step-title">Dados pessoais</h2>
                    <button class="c2p-step-edit" style="display:none;">Alterar</button>
                </div>
                
                <div class="c2p-step-content" style="display:none;">
                    <p class="c2p-step-description">Solicitamos apenas as informações essenciais para a realização da compra.</p>
                    
                    <!-- Email confirmado (readonly) -->
                    <div class="c2p-form-group">
                        <label>E-mail: <span class="required">*</span></label>
                        <div class="c2p-confirmed-field">
                            <span id="confirmed-email"></span>
                        </div>
                    </div>
                    
                    <div class="c2p-form-row">
                        <div class="c2p-form-group c2p-col-6">
                            <label for="billing_first_name">Primeiro nome: <span class="required">*</span></label>
                            <input 
                                type="text" 
                                id="billing_first_name" 
                                name="billing_first_name"
                                class="c2p-input" 
                                value="<?php echo esc_attr($customer->get_billing_first_name()); ?>"
                                required>
                        </div>
                        
                        <div class="c2p-form-group c2p-col-6">
                            <label for="billing_last_name">Último nome: <span class="required">*</span></label>
                            <input 
                                type="text" 
                                id="billing_last_name" 
                                name="billing_last_name"
                                class="c2p-input" 
                                value="<?php echo esc_attr($customer->get_billing_last_name()); ?>"
                                required>
                        </div>
                    </div>
                    
                    <div class="c2p-form-row">
                        <div class="c2p-form-group c2p-col-6">
                            <label for="billing_cpf">CPF: <span class="required">*</span></label>
                            <input 
                                type="text" 
                                id="billing_cpf" 
                                name="billing_cpf"
                                class="c2p-input c2p-mask-cpf" 
                                placeholder="000.000.000-00"
                                value="<?php echo esc_attr(get_user_meta($user->ID, 'billing_cpf', true)); ?>"
                                maxlength="14"
                                required>
                        </div>
                        
                        <div class="c2p-form-group c2p-col-6">
                            <label for="billing_phone">Celular: <span class="required">*</span></label>
                            <input 
                                type="tel" 
                                id="billing_phone" 
                                name="billing_phone"
                                class="c2p-input c2p-mask-phone" 
                                placeholder="(00) 00000-0000"
                                value="<?php echo esc_attr($customer->get_billing_phone()); ?>"
                                maxlength="15"
                                required>
                        </div>
                    </div>
                    
                    <div class="c2p-form-group">
                        <label class="c2p-checkbox">
                            <input type="checkbox" id="save-info" name="save_info">
                            <span>Salvar minhas informações para próximas compras.</span>
                        </label>
                    </div>
                    
                    <div class="c2p-form-group">
                        <label class="c2p-checkbox">
                            <input type="checkbox" id="accept-terms" name="accept_terms" required>
                            <span>Declaro que li e concordo com a 
                                <a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank">Política de Privacidade</a> 
                                e os 
                                <a href="#" target="_blank">Termos de Uso</a>.
                                <span class="required">*</span>
                            </span>
                        </label>
                    </div>
                    
                    <button type="button" class="c2p-btn-continue" id="btn-continue-personal" disabled>
                        Ir para a Entrega
                    </button>
                </div>
            </div>
            
            <!-- STEP 3: ENTREGA -->
            <div class="c2p-step c2p-step-locked" id="step-delivery" data-step="3">
                <div class="c2p-step-header">
                    <div class="c2p-step-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"></rect>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                            <circle cx="5.5" cy="18.5" r="2.5"></circle>
                            <circle cx="18.5" cy="18.5" r="2.5"></circle>
                        </svg>
                    </div>
                    <h2 class="c2p-step-title">Entrega</h2>
                    <button class="c2p-step-edit" style="display:none;">Alterar</button>
                </div>
                
                <div class="c2p-step-content" style="display:none;">
                    <?php if (!$has_shipping_method): ?>
                        <div class="c2p-alert c2p-alert-warning">
                            ⚠️ Você ainda não escolheu um método de entrega. Por favor, selecione uma opção abaixo.
                        </div>
                    <?php endif; ?>
                    
                    <!-- Botões: Receber em casa | Retirar na loja -->
                    <div class="c2p-delivery-options">
                        <button class="c2p-delivery-btn <?php echo $delivery_mode === 'home' ? 'active' : ''; ?>" data-delivery="home">
                            <span class="c2p-btn-icon">🏠</span>
                            <span>Receba em casa</span>
                        </button>
                        <button class="c2p-delivery-btn <?php echo $delivery_mode === 'store' ? 'active' : ''; ?>" data-delivery="store">
                            <span class="c2p-btn-icon">🏪</span>
                            <span>Retire na loja</span>
                        </button>
                    </div>
                    
                    <!-- Container de métodos de entrega (home) -->
                    <div id="home-delivery-section" style="<?php echo $delivery_mode === 'home' ? '' : 'display:none;'; ?>">
                        <div class="c2p-form-group">
                            <label>Calcule o frete:</label>
                            <div class="c2p-cep-wrapper">
                                <input 
                                    type="text" 
                                    id="shipping_postcode" 
                                    name="shipping_postcode"
                                    class="c2p-input c2p-mask-cep" 
                                    placeholder="00000-000"
                                    value="<?php echo esc_attr($customer->get_shipping_postcode()); ?>"
                                    maxlength="9">
                                <button type="button" id="calc-shipping" class="c2p-btn-secondary">Calcular</button>
                            </div>
                            <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" target="_blank" class="c2p-link-small">Não sei meu CEP</a>
                        </div>
                        
                        <div id="shipping-methods-container"></div>
                        
                        <div id="address-fields" style="display:none;">
                            <h3 class="c2p-section-title">Complete as informações do seu endereço:</h3>
                            
                            <div class="c2p-form-group">
                                <label>Endereço completo:</label>
                                <div class="c2p-confirmed-field" id="confirmed-address"></div>
                                <button type="button" class="c2p-btn-link" id="edit-address">Alterar</button>
                            </div>
                            
                            <div id="address-form" style="display:none;">
                                <div class="c2p-form-row">
                                    <div class="c2p-form-group c2p-col-8">
                                        <label for="shipping_address_1">Rua:</label>
                                        <input type="text" id="shipping_address_1" name="shipping_address_1" class="c2p-input">
                                    </div>
                                    
                                    <div class="c2p-form-group c2p-col-4">
                                        <label for="shipping_number">Número: <span class="required">*</span></label>
                                        <input type="text" id="shipping_number" name="shipping_number" class="c2p-input" required>
                                    </div>
                                </div>
                                
                                <div class="c2p-form-row">
                                    <div class="c2p-form-group c2p-col-6">
                                        <label for="shipping_address_2">Complemento:</label>
                                        <input type="text" id="shipping_address_2" name="shipping_address_2" class="c2p-input" placeholder="Apto, Bloco, etc.">
                                    </div>
                                    
                                    <div class="c2p-form-group c2p-col-6">
                                        <label for="shipping_neighborhood">Bairro:</label>
                                        <input type="text" id="shipping_neighborhood" name="shipping_neighborhood" class="c2p-input">
                                    </div>
                                </div>
                                
                                <div class="c2p-form-row">
                                    <div class="c2p-form-group c2p-col-8">
                                        <label for="shipping_city">Cidade:</label>
                                        <input type="text" id="shipping_city" name="shipping_city" class="c2p-input">
                                    </div>
                                    
                                    <div class="c2p-form-group c2p-col-4">
                                        <label for="shipping_state">Estado:</label>
                                        <input type="text" id="shipping_state" name="shipping_state" class="c2p-input" maxlength="2">
                                    </div>
                                </div>
                                
                                <div class="c2p-form-group">
                                    <label for="shipping_recipient">Destinatário:</label>
                                    <input type="text" id="shipping_recipient" name="shipping_recipient" class="c2p-input" placeholder="Ex: João Silva">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Container de retirada na loja -->
                    <div id="pickup-section" style="<?php echo $delivery_mode === 'store' ? '' : 'display:none;'; ?>">
                        <div id="pickup-methods-container"></div>
                    </div>
                    
                    <button type="button" class="c2p-btn-continue" id="btn-continue-delivery" disabled>
                        Ir para o Pagamento
                    </button>
                </div>
            </div>
            
            <!-- STEP 4: PAGAMENTO -->
            <div class="c2p-step c2p-step-locked" id="step-payment" data-step="4">
                <div class="c2p-step-header">
                    <div class="c2p-step-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                    </div>
                    <h2 class="c2p-step-title">Pagamento</h2>
                </div>
                
                <div class="c2p-step-content" style="display:none;">
                    <!-- Resumo dos dados (colapsado) -->
                    <div class="c2p-summary-block">
                        <div class="c2p-summary-item">
                            <strong>📧 Email:</strong>
                            <span id="summary-email"></span>
                            <button type="button" class="c2p-btn-link" data-edit-step="1">Alterar</button>
                        </div>
                        
                        <div class="c2p-summary-item">
                            <strong>👤 Dados pessoais:</strong>
                            <span id="summary-personal"></span>
                            <button type="button" class="c2p-btn-link" data-edit-step="2">Alterar</button>
                        </div>
                        
                        <div class="c2p-summary-item">
                            <strong>🚚 Entrega:</strong>
                            <span id="summary-delivery"></span>
                            <button type="button" class="c2p-btn-link" data-edit-step="3">Alterar</button>
                        </div>
                    </div>
                    
                    <!-- Métodos de pagamento do WooCommerce -->
                    <h3 class="c2p-section-title">Escolha a forma de pagamento:</h3>
                    
                    <div id="payment-methods">
                        <?php woocommerce_checkout_payment(); ?>
                    </div>
                    
                    <button type="submit" class="c2p-btn-finalize" id="place-order">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                        Finalizar compra
                    </button>
                </div>
            </div>
            
        </div>
        
        <!-- COLUNA DIREITA: RESUMO -->
        <div class="c2p-checkout-summary">
            <h2 class="c2p-summary-title">Resumo do pedido</h2>
            
            <!-- Lista de produtos -->
            <div class="c2p-summary-products">
                <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item):
                    $_product = $cart_item['data'];
                    if (!$_product || !$_product->exists()) continue;
                    
                    $product_name = $_product->get_name();
                    $product_image = $_product->get_image('thumbnail');
                    $product_quantity = $cart_item['quantity'];
                    $product_subtotal = WC()->cart->get_product_subtotal($_product, $product_quantity);
                ?>
                <div class="c2p-summary-product">
                    <div class="c2p-summary-product-image">
                        <?php echo $product_image; ?>
                    </div>
                    <div class="c2p-summary-product-info">
                        <h4><?php echo esc_html($product_name); ?></h4>
                        <p class="c2p-summary-product-qty">Quantidade: <?php echo $product_quantity; ?></p>
                    </div>
                    <div class="c2p-summary-product-price">
                        <?php echo $product_subtotal; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="c2p-link-back">Voltar à cesta</a>
            
            <!-- Cupom -->
            <div class="c2p-summary-coupon">
                <h3>Cupom</h3>
                <div class="c2p-coupon-input-group">
                    <input type="text" id="coupon-code" class="c2p-input" placeholder="digite aqui">
                    <button type="button" id="apply-coupon" class="c2p-btn-secondary">Aplicar</button>
                </div>
                
                <?php if (WC()->cart->get_coupons()): ?>
                <div class="c2p-applied-coupons">
                    <?php foreach (WC()->cart->get_coupons() as $code => $coupon): ?>
                    <div class="c2p-coupon-tag">
                        <span><?php echo esc_html($code); ?></span>
                        <button class="c2p-remove-coupon" data-coupon="<?php echo esc_attr($code); ?>">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Totais -->
            <div class="c2p-summary-totals">
                <div class="c2p-total-row">
                    <span>Subtotal</span>
                    <span class="c2p-subtotal"><?php echo WC()->cart->get_cart_subtotal(); ?></span>
                </div>
                
                <div class="c2p-total-row">
                    <span>Frete</span>
                    <span class="c2p-shipping">
                        <?php 
                        $shipping_total = WC()->cart->get_shipping_total();
                        if ($shipping_total == 0) {
                            echo '<span style="color:#16a34a;font-weight:600;">Grátis 🎉</span>';
                        } else {
                            echo wc_price($shipping_total);
                        }
                        ?>
                    </span>
                </div>
                
                <?php if (WC()->cart->get_discount_total() > 0): ?>
                <div class="c2p-total-row c2p-discount">
                    <span>Desconto</span>
                    <span class="c2p-discount-value">-<?php echo wc_price(WC()->cart->get_discount_total()); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="c2p-total-row c2p-total">
                    <span>Total</span>
                    <span class="c2p-total-value"><?php echo WC()->cart->get_total(); ?></span>
                </div>
            </div>
            
            <div class="c2p-summary-footer">
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="c2p-link-continue">
                    Continuar comprando
                </a>
            </div>
        </div>
        
    </div>
</div>

<!-- Loading overlay -->
<div class="c2p-loading-overlay" id="c2p-checkout-loading" style="display:none;">
    <div class="c2p-spinner"></div>
</div>