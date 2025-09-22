<?php
/**
 * C2P — Hooks de frete DESATIVADOS (modo WooCommerce puro)
 *
 * Este arquivo existe apenas para manter compatibilidade de funções utilitárias
 * sem adicionar filtros/ações que alterem o comportamento nativo do WooCommerce.
 *
 * ✅ Nenhum filtro em: woocommerce_package_rates, woocommerce_shipping_chosen_method,
 *    woocommerce_cart_shipping_packages, woocommerce_check_cart_items, etc.
 * ✅ Nenhuma rota AJAX adicionada.
 * ✅ Nenhum meta extra salvo no pedido.
 *
 * Se algum template/parte do plugin chamar helpers como c2p_get_mode(),
 * eles retornam valores neutros, sem impactar o fluxo.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Sessão (stub, não usada aqui) */
if ( ! function_exists('c2p_session') ) {
    function c2p_session() {
        return ( function_exists('WC') && WC()->session ) ? WC()->session : null;
    }
}

/** Modo atual (delivery/pickup) — aqui sempre “não definido” */
if ( ! function_exists('c2p_get_mode') ) {
    function c2p_get_mode() : string {
        return '';
    }
}

/** Define modo — aqui não faz nada */
if ( ! function_exists('c2p_set_mode') ) {
    function c2p_set_mode( string $mode ) : void {
        // noop — mantemos WooCommerce puro
    }
}

/** Loja selecionada para retirada — aqui sempre 0 (nenhuma) */
if ( ! function_exists('c2p_get_store') ) {
    function c2p_get_store() : int {
        return 0;
    }
}

/** Define loja — aqui não faz nada */
if ( ! function_exists('c2p_set_store') ) {
    function c2p_set_store( int $store_id ) : void {
        // noop — mantemos WooCommerce puro
    }
}

/** Nome da loja — string vazia para não poluir UI */
if ( ! function_exists('c2p_store_name') ) {
    function c2p_store_name() : string {
        return '';
    }
}

/**
 * Observação: se em algum momento você quiser reativar comportamentos do Click2Pickup,
 * este é o ponto para recolocar add_action/add_filter específicos em um novo arquivo
 * (ou reverter para a versão anterior deste).
 */
