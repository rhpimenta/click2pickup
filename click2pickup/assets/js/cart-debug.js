/**
 * Click2Pickup Cart JavaScript - VERSÃO DEBUG
 * Version: 2.1.0
 */

(function($) {
    'use strict';
    
    console.log('🚀 Click2Pickup: Script carregado');
    console.log('📍 Ajax URL:', typeof c2p_ajax !== 'undefined' ? c2p_ajax.ajax_url : 'NÃO DEFINIDO');
    
    // Variáveis globais
    var isLoadingShipping = false;
    
    /**
     * Inicialização
     */
    $(document).ready(function() {
        console.log('📦 Click2Pickup: Document ready');
        console.log('🔍 Seletor encontrado:', $('.c2p-location-selector').length);
        console.log('🔄 Switch options:', $('.c2p-switch-option').length);
        
        initializeLocationSelector();
        bindEvents();
        
        // Debug: verificar modo ativo
        var activeMode = $('.c2p-switch-option.active').data('mode');
        console.log('✅ Modo ativo:', activeMode);
        
        // Carregar métodos se for delivery
        if (activeMode === 'delivery') {
            console.log('🚚 Carregando métodos de envio...');
            loadShippingMethods();
        }
    });
    
    /**
     * Inicializa o seletor
     */
    function initializeLocationSelector() {
        console.log('🎯 Inicializando seletor...');
        
        if ($('.c2p-location-selector').length === 0) {
            console.error('❌ Seletor não encontrado!');
            return;
        }
        
        $('.c2p-location-selector').addClass('initialized');
    }
    
    /**
     * Vincula eventos
     */
    function bindEvents() {
        console.log('🔗 Vinculando eventos...');
        
        // Switch entre Delivery e Pickup
        $('.c2p-switch-option').off('click').on('click', function() {
            var mode = $(this).data('mode');
            console.log('👆 Click no switch:', mode);
            handleSwitchClick.call(this);
        });
        
        // Selecionar método de envio
        $(document).on('click', '.c2p-shipping-card', function() {
            console.log('📦 Click em método de envio');
            handleShippingCardClick.call(this);
        });
        
        // Debug: verificar se eventos foram vinculados
        console.log('✅ Eventos vinculados');
    }
    
    /**
     * Handle switch click
     */
    function handleSwitchClick() {
        var $this = $(this);
        var mode = $this.data('mode');
        
        console.log('🔄 Mudando para modo:', mode);
        
        if ($this.hasClass('active')) {
            console.log('⚠️ Modo já ativo, ignorando...');
            return;
        }
        
        // Atualizar visual do switch
        $('.c2p-switch-option').removeClass('active');
        $this.addClass('active');
        
        // Mover slider
        if (mode === 'pickup') {
            $('.c2p-switch-slider').addClass('right');
        } else {
            $('.c2p-switch-slider').removeClass('right');
        }
        
        // Debug: verificar conteúdo
        console.log('🎯 Escondendo conteúdo atual...');
        console.log('📍 Conteúdo delivery:', $('#c2p-delivery-content').length);
        console.log('📍 Conteúdo pickup:', $('#c2p-pickup-content').length);
        
        // Trocar conteúdo
        $('.c2p-mode-content').hide();
        $('#c2p-' + mode + '-content').show();
        
        // Se for delivery, carregar métodos
        if (mode === 'delivery') {
            console.log('🚚 Iniciando carregamento de métodos...');
            loadShippingMethods();
        }
    }
    
    /**
     * Carregar métodos de envio
     */
    function loadShippingMethods() {
        var dcId = $('#c2p-delivery-location').val();
        
        console.log('📍 Location ID:', dcId);
        console.log('🔄 Já carregando?:', isLoadingShipping);
        
        if (!dcId) {
            console.error('❌ Nenhum CD selecionado!');
            return;
        }
        
        if (isLoadingShipping) {
            console.log('⚠️ Já está carregando, aguarde...');
            return;
        }
        
        isLoadingShipping = true;
        
        $('#c2p-shipping-loading').show();
        $('#c2p-shipping-methods').hide();
        
        console.log('📡 Fazendo requisição AJAX...');
        console.log('URL:', c2p_ajax.ajax_url);
        console.log('Dados:', {
            action: 'c2p_get_shipping_methods',
            location_id: dcId,
            nonce: c2p_ajax.nonce
        });
        
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_get_shipping_methods',
                location_id: dcId,
                nonce: c2p_ajax.nonce
            },
            success: function(response) {
                console.log('✅ Resposta recebida:', response);
                
                if (response.success) {
                    $('#c2p-shipping-methods').html(response.data.html).show();
                    $('#c2p-shipping-loading').hide();
                    console.log('✅ Métodos carregados com sucesso');
                } else {
                    console.error('❌ Erro na resposta:', response);
                    showError(response.data || 'Erro ao carregar métodos');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro AJAX:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                
                $('#c2p-shipping-loading').hide();
                $('#c2p-shipping-methods').html(
                    '<div style="text-align: center; padding: 20px; color: #dc3545;">' +
                    '<p>Erro: ' + error + '</p>' +
                    '<small>Status: ' + status + '</small>' +
                    '</div>'
                ).show();
            },
            complete: function() {
                isLoadingShipping = false;
                console.log('🏁 Requisição completa');
            }
        });
    }
    
    /**
     * Mostrar erro
     */
    function showError(message) {
        console.error('🔴 Erro:', message);
        $('#c2p-shipping-loading').hide();
        $('#c2p-shipping-methods').html(
            '<div style="text-align: center; color: #dc3545; padding: 20px;">' +
            '<p>' + message + '</p>' +
            '</div>'
        ).show();
    }
    
    // Expor funções globais
    window.c2pSelectStore = function(locationId) {
        console.log('🏪 Selecionando loja:', locationId);
        selectLocation(locationId, 'pickup', 'local_pickup');
    };
    
    window.c2pChangeLocation = function() {
        console.log('🔄 Alterando local...');
        $.post(c2p_ajax.ajax_url, {
            action: 'c2p_select_location',
            location_id: 0,
            nonce: c2p_ajax.nonce
        }, function() {
            location.reload();
        });
    };
    
    function selectLocation(locationId, type, method) {
        console.log('📍 Selecionando local:', {
            id: locationId,
            type: type,
            method: method
        });
        
        $.post(c2p_ajax.ajax_url, {
            action: 'c2p_select_location',
            location_id: locationId,
            delivery_type: type,
            shipping_method: method,
            nonce: c2p_ajax.nonce
        }, function(response) {
            console.log('✅ Resposta da seleção:', response);
            if (response.success) {
                location.reload();
            } else {
                alert('Erro: ' + (response.data || 'Erro desconhecido'));
            }
        }).fail(function(xhr, status, error) {
            console.error('❌ Erro na seleção:', error);
            alert('Erro na comunicação: ' + error);
        });
    }
    
    // Auto debug
    console.log('🔍 DEBUG MODE ATIVO');
    console.log('================================');
    console.log('Elementos encontrados:');
    console.log('- Seletor principal:', $('.c2p-location-selector').length);
    console.log('- Switch container:', $('.c2p-delivery-switch').length);
    console.log('- Opções do switch:', $('.c2p-switch-option').length);
    console.log('- Content wrapper:', $('.c2p-content-wrapper').length);
    console.log('- Delivery content:', $('#c2p-delivery-content').length);
    console.log('- Pickup content:', $('#c2p-pickup-content').length);
    console.log('================================');
    
})(jQuery);