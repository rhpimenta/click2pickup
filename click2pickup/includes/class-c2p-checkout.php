<?php
/**
 * Checkout handler para Click2Pickup
 *
 * Responsável por:
 * - Exigir um local selecionado antes do pagamento
 * - Validar estoque do carrinho contra o local selecionado (Etapa 2)
 * - Exibir um resumo do local selecionado no checkout
 * - (Futuro) Filtrar métodos de pagamento por local
 * - Gravar metadados do local e itens removidos no pedido
 *
 * @package Click2Pickup
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Checkout {

    public function __construct() {
        // Validar antes do pagamento (Etapa 2 -> Etapa 3)
        add_action('woocommerce_checkout_process', array($this, 'validate_before_payment'));

        // Mostrar resumo do local selecionado no checkout
        add_action('woocommerce_review_order_before_payment', array($this, 'render_location_summary'), 8);

        // (Futuro) Filtrar métodos de pagamento por local
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_gateways'));

        // Gravar metadados do local e itens removidos no pedido
        add_action('woocommerce_checkout_create_order', array($this, 'attach_meta_to_order'), 10, 2);
    }

    /**
     * Garante que a sessão PHP esteja iniciada
     */
    private function ensure_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
    }

    /**
     * Valida o checkout antes do pagamento
     * - Exige local selecionado
     * - Revalida estoque do carrinho contra o local
     */
    public function validate_before_payment() {
        $this->ensure_session();

        $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;

        if (!$selected || empty($selected['id'])) {
            wc_add_notice(__('Por favor, selecione um local de entrega ou retirada antes de finalizar.', 'click2pickup'), 'error');
            return;
        }

        $validation = $this->validate_cart_stock_for_location(intval($selected['id']));

        if ($validation['has_issues']) {
            // Montar mensagem com itens indisponíveis
            $msg_lines = array(__('Alguns itens não estão disponíveis no local selecionado:', 'click2pickup'));
            foreach ($validation['unavailable_items'] as $item) {
                $msg_lines[] = sprintf(
                    '• %s — %s: %d | %s: %d',
                    $item['name'],
                    __('Solicitado', 'click2pickup'),
                    $item['quantity_needed'],
                    __('Disponível', 'click2pickup'),
                    $item['quantity_available']
                );
            }

            wc_add_notice(implode('<br>', array_map('esc_html', $msg_lines)), 'error');
        }
    }

    /**
     * Exibe um resumo do local selecionado no checkout
     */
    public function render_location_summary() {
        $this->ensure_session();

        $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
        if (!$selected) {
            echo '<div class="woocommerce-info" style="margin-bottom:16px;">';
            echo esc_html__('Você ainda não selecionou um local. Volte ao carrinho para escolher CD ou Loja.', 'click2pickup') . ' ';
            echo '<a href="' . esc_url(wc_get_cart_url()) . '">' . esc_html__('Voltar ao carrinho', 'click2pickup') . '</a>';
            echo '</div>';
            return;
        }

        ?>
        <div class="c2p-selected-location-summary" style="background:#f8f9ff;border:1px solid #e5e7ff;border-radius:8px;padding:16px;margin-bottom:16px;">
            <h3 style="margin:0 0 8px 0;">📍 <?php esc_html_e('Local Selecionado', 'click2pickup'); ?></h3>
            <p style="margin:0;">
                <strong><?php echo esc_html($selected['name']); ?></strong><br>
                <?php
                $parts = array();
                if (!empty($selected['address'])) { $parts[] = $selected['address']; }
                if (!empty($selected['city'])) { $parts[] = $selected['city']; }
                if (!empty($selected['state'])) { $parts[] = $selected['state']; }
                echo esc_html(implode(' - ', $parts));
                ?>
            </p>
            <p style="margin:8px 0 0 0;">
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>">
                    ✏️ <?php esc_html_e('Trocar local (voltar ao carrinho)', 'click2pickup'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * (Futuro) Filtra métodos de pagamento por local
     * - Por ora, não altera nada, apenas mantém compatibilidade com a especificação 3.5
     * - Caso o local tenha "payment_methods" json com "disabled" => [ids], remove os gateways
     */
    public function filter_payment_gateways($gateways) {
        $this->ensure_session();

        $selected = isset($_SESSION['c2p_selected_location']) ? $_SESSION['c2p_selected_location'] : null;
        if (!$selected || empty($selected['id'])) {
            return $gateways;
        }

        // Buscar configuração do local (se houver métodos desabilitados)
        global $wpdb;
        $table_locations = $wpdb->prefix . 'c2p_locations';
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT payment_methods FROM $table_locations WHERE id = %d",
            intval($selected['id'])
        ));

        if (!$location || empty($location->payment_methods)) {
            return $gateways; // padrão do site
        }

        $cfg = json_decode($location->payment_methods, true);
        if (!is_array($cfg) || empty($cfg['disabled']) || !is_array($cfg['disabled'])) {
            return $gateways;
        }

        // Remover gateways desabilitados para este local
        foreach ($cfg['disabled'] as $disabled_id) {
            if (isset($gateways[$disabled_id])) {
                unset($gateways[$disabled_id]);
            }
        }

        return $gateways;
    }

    /**
     * Anexa metadados ao pedido
     * - Local selecionado
     * - Itens removidos (se existirem na sessão)
     */
    public function attach_meta_to_order($order, $data) {
        $this->ensure_session();

        if (isset($_SESSION['c2p_selected_location'])) {
            $loc = $_SESSION['c2p_selected_location'];
            $order->update_meta_data('_c2p_location_id', intval($loc['id']));
            $order->update_meta_data('_c2p_location_name', (string) $loc['name']);
            $order->update_meta_data('_c2p_location_type', (string) $loc['type']);
        }

        if (!empty($_SESSION['c2p_removed_items']) && is_array($_SESSION['c2p_removed_items'])) {
            $order->update_meta_data('_c2p_removed_items', $_SESSION['c2p_removed_items']);
        }
    }

    /**
     * Revalida o estoque do carrinho para um local específico
     */
    private function validate_cart_stock_for_location($location_id) {
        $cart = WC()->cart ? WC()->cart->get_cart() : array();
        $unavailable = array();

        if (empty($cart)) {
            return array(
                'has_issues' => false,
                'unavailable_items' => array()
            );
        }

        global $wpdb;
        $stock_table = $wpdb->prefix . 'c2p_stock';

        foreach ($cart as $cart_item_key => $cart_item) {
            $product_id = intval($cart_item['product_id']);
            $variation_id = intval($cart_item['variation_id']);
            $qty_needed = intval($cart_item['quantity']);

            $check_id = $variation_id > 0 ? $variation_id : $product_id;

            $stock = $wpdb->get_var($wpdb->prepare(
                "SELECT stock_quantity FROM $stock_table WHERE location_id = %d AND product_id = %d",
                $location_id,
                $check_id
            ));

            $available = is_null($stock) ? 0 : intval($stock);
            if ($available < $qty_needed) {
                $product = wc_get_product($check_id);
                $unavailable[] = array(
                    'key' => $cart_item_key,
                    'name' => $product ? $product->get_name() : ('#' . $check_id),
                    'quantity_needed' => $qty_needed,
                    'quantity_available' => $available
                );
            }
        }

        return array(
            'has_issues' => !empty($unavailable),
            'unavailable_items' => $unavailable
        );
    }
}