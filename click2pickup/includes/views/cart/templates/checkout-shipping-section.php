<?php
/**
 * Seção de Frete do Checkout
 * Version: 1.0.0
 */

// Pega o CEP salvo do cliente
$saved_postcode = WC()->customer->get_shipping_postcode();
$saved_postcode = preg_replace('/[^0-9]/', '', $saved_postcode); // Remove formatação

// Verifica se é um CEP válido (não vazio, não 00000000, tem 8 dígitos)
$has_valid_cep = (
    !empty($saved_postcode) && 
    $saved_postcode !== '00000000' && 
    $saved_postcode !== '00000-000' &&
    strlen($saved_postcode) === 8 &&
    $saved_postcode !== '00000'
);

// Pega métodos de envio disponíveis apenas se tiver CEP válido
$available_methods = array();
if ($has_valid_cep) {
    $packages = WC()->shipping()->get_packages();
    if (!empty($packages)) {
        foreach ($packages as $i => $package) {
            if (isset($package['rates']) && !empty($package['rates'])) {
                foreach ($package['rates'] as $rate_id => $rate) {
                    $cost = floatval($rate->get_cost());
                    $available_methods[] = array(
                        'id' => $rate->get_id(),
                        'label' => $rate->get_label(),
                        'cost' => $cost,
                        'cost_display' => $cost == 0 ? 'Grátis' : wc_price($cost),
                        'is_free' => ($cost == 0 || $rate->get_method_id() === 'free_shipping')
                    );
                }
            }
        }
    }
}
?>

<!-- CEP e Frete -->
<div class="cwc-shipping-calculator" id="shipping-calculator">
    <?php if (!$has_valid_cep): ?>
        <!-- Estado inicial - Solicita CEP -->
        <div class="cwc-cep-required-state">
            <div class="cwc-cep-icon">📍</div>
            <h3>Calcule o frete e prazo</h3>
            <p>Digite seu CEP para ver as opções de entrega disponíveis</p>
            
            <div class="cwc-cep-input-main">
                <input type="text" 
                       class="cwc-cep-input-large" 
                       id="cep-input-main" 
                       placeholder="00000-000"
                       maxlength="9"
                       autocomplete="postal-code">
                <button class="cwc-cep-calculate-btn" id="calculate-shipping-btn">
                    Calcular Frete
                </button>
            </div>
            
            <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" 
               target="_blank" 
               class="cwc-cep-link">
                Não sei meu CEP
            </a>
        </div>
    <?php else: ?>
        <!-- Estado com CEP - Mostra opções de frete -->
        <div class="cwc-shipping-header">
            <label>
                Receber 
                <span class="cwc-item-count"><?php echo WC()->cart->get_cart_contents_count(); ?> 
                <?php echo _n('item', 'itens', WC()->cart->get_cart_contents_count(), 'custom-wc-checkout'); ?></span>
                em 
                <span class="cwc-cep-display" id="current-cep">
                    <?php echo substr($saved_postcode, 0, 5) . '-' . substr($saved_postcode, 5, 3); ?>
                </span>
            </label>
            <button class="cwc-change-cep" id="change-cep">Alterar</button>
        </div>
        
        <!-- Input CEP (inicialmente oculto) -->
        <div class="cwc-cep-input-group" id="cep-input-group" style="display: none;">
            <input type="text" 
                   class="cwc-cep-input" 
                   id="cep-input" 
                   placeholder="00000-000"
                   maxlength="9">
            <button class="cwc-cep-apply" id="apply-cep">Aplicar</button>
        </div>
        
        <!-- Select de Métodos de Envio -->
        <div class="cwc-shipping-select-wrapper">
            <label for="shipping-method-select" class="cwc-shipping-label">
                Método de envio:
            </label>
            <select id="shipping-method-select" class="cwc-shipping-select">
                <?php if (!empty($available_methods)): ?>
                    <?php foreach ($available_methods as $index => $method): ?>
                    <option value="<?php echo esc_attr($method['id']); ?>" 
                            data-cost="<?php echo esc_attr($method['cost']); ?>"
                            <?php echo $index === 0 ? 'selected' : ''; ?>>
                        <?php 
                        echo esc_html($method['label']) . ' - ' . $method['cost_display'];
                        ?>
                    </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option value="">Nenhum método disponível</option>
                <?php endif; ?>
            </select>
            
            <div class="cwc-delivery-time-display" id="delivery-time-display" style="display: none;">
                <span class="cwc-delivery-icon">📦</span>
                <span class="cwc-delivery-text"></span>
            </div>
        </div>
    <?php endif; ?>
</div>