/**
 * Click2Pickup Cart JavaScript
 * Version: 4.0.0 - Correção definitiva para usuários não logados
 * @author RH Pimenta
 */

(function($) {
    'use strict';
    
    // Variáveis globais
    var isLoadingShipping = false;
    var currentMode = 'delivery';
    var isProcessing = false;
    var hasCalculatedShipping = false;
    var lastCalculatedPostcode = '';
    var autoRecalcTimer = null;
    window.c2pLocationSelected = false;
    
    /**
     * Função para verificar se tem seleção salva
     */
    function checkSavedSelection(callback) {
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_check_session'
            },
            success: function(response) {
                console.log('📋 Status da sessão:', response.data);
                if (response.success && response.data.has_selection) {
                    window.c2pLocationSelected = true;
                    console.log('✅ Seleção encontrada:', response.data.selection);
                }
                if (callback) callback(response.data);
            },
            error: function() {
                console.log('❌ Erro ao verificar sessão');
                if (callback) callback(null);
            }
        });
    }
    
    /**
     * Função para limpar seleções do WooCommerce
     */
    function clearWooCommerceShipping() {
        $('.woocommerce-shipping-totals.shipping').hide();
        $('.woocommerce-shipping-calculator').hide();
        $('.shipping-calculator-button').hide();
        
        if (!window.c2pLocationSelected) {
            $('input[name^="shipping_method"]:checked').prop('checked', false);
        }
    }
    
    /**
     * Função para atualizar totais do carrinho
     */
    function updateCartTotals() {
        $(document.body).trigger('update_cart');
        
        setTimeout(function() {
            var $updateButton = $('[name="update_cart"]');
            if ($updateButton.length && !$updateButton.prop('disabled')) {
                $updateButton.trigger('click');
            }
        }, 1000);
    }
    
    /**
     * Função para auto-recalcular frete
     */
    function autoRecalculateShipping() {
        if (!hasCalculatedShipping || !lastCalculatedPostcode) {
            return;
        }
        
        if (currentMode !== 'delivery') {
            return;
        }
        
        if ($('#c2p-shipping-methods').is(':visible')) {
            console.log('♻️ Auto-recalculando frete...');
            
            if (!$('.c2p-recalc-indicator').length) {
                $('#c2p-shipping-methods').prepend(
                    '<div class="c2p-recalc-indicator">' +
                    '🔄 Atualizando valores de frete...' +
                    '</div>'
                );
            }
            
            loadShippingMethods(true);
        }
    }
    
    /**
     * Configurar listeners para auto-recálculo
     */
    function setupAutoRecalcListeners() {
        console.log('🔧 Configurando auto-recálculo...');
        
        $(document.body).on('updated_cart_totals', function() {
            console.log('📦 Cart totals atualizados');
            
            clearWooCommerceShipping();
            
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    autoRecalculateShipping();
                }, 1500);
            }
        });
        
        $(document.body).on('applied_coupon removed_coupon', function(event, coupon_code) {
            console.log('🎟️ Cupom alterado:', coupon_code || 'removido');
            
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    autoRecalculateShipping();
                }, 1000);
            }
        });
        
        $(document).on('change', 'input.qty', function() {
            console.log('🔢 Quantidade alterada');
            
            if (window.c2pLocationSelected) {
                setTimeout(updateCartTotals, 500);
            }
            
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    autoRecalculateShipping();
                }, 2000);
            }
        });
    }
    
    /**
     * Inicialização quando documento estiver pronto
     */
    $(document).ready(function() {
        console.log('🚀 Click2Pickup v4.0.0 iniciando...');
        console.log('👤 Usuário logado:', c2p_ajax.is_logged_in ? 'Sim' : 'Não');
        
        // Limpar modal existente
        $('.c2p-loading-overlay').remove();
        
        // Verificar se tem seleção salva
        checkSavedSelection(function(sessionData) {
            if (sessionData && sessionData.has_selection) {
                console.log('✅ Seleção prévia detectada');
                window.c2pLocationSelected = true;
            }
        });
        
        // Limpar seleções do WooCommerce padrão
        clearWooCommerceShipping();
        
        // Verificar se tem local selecionado visível
        if ($('.c2p-selected-location').length > 0) {
            window.c2pLocationSelected = true;
        }
        
        initializeLocationSelector();
        bindEvents();
        setupAutoRecalcListeners();
        
        // Verificar modo inicial
        currentMode = $('.c2p-switch-option.active').data('mode') || 'delivery';
        showModeContent(currentMode);
        
        // Se tem CEP, carregar métodos
        var postcode = $('#c2p-postcode').val();
        if (postcode && postcode.replace(/\D/g, '').length === 8) {
            lastCalculatedPostcode = postcode;
            hasCalculatedShipping = true;
            setTimeout(function() {
                loadShippingMethods();
            }, 500);
        }
    });
    
    /**
     * Inicializa o seletor de local
     */
    function initializeLocationSelector() {
        if ($('.c2p-location-selector').length === 0) {
            console.error('❌ Seletor não encontrado');
            return;
        }
        
        $('.c2p-location-selector').addClass('initialized');
        console.log('✅ Seletor inicializado');
    }
    
    /**
     * Vincula eventos aos elementos
     */
    function bindEvents() {
        console.log('🔗 Vinculando eventos...');
        
        // Switch entre Delivery e Pickup
        $('.c2p-switch-option').off('click').on('click', function(e) {
            e.preventDefault();
            if (!isProcessing) {
                handleSwitchClick.call(this);
            }
        });
        
        // Campo de CEP - Formatação
        $('#c2p-postcode').off('input').on('input', function() {
            var value = $(this).val();
            value = value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0, 5) + '-' + value.substring(5, 8);
            }
            $(this).val(value);
        });
        
        // Enter no campo CEP
        $('#c2p-postcode').off('keypress').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#c2p-calculate-shipping').click();
            }
        });
        
        // Botão Calcular Frete
        $('#c2p-calculate-shipping').off('click').on('click', function(e) {
            e.preventDefault();
            if (!isProcessing) {
                handleCalculateShipping();
            }
        });
        
        // Selecionar método de envio
        $(document).off('click', '.c2p-shipping-card').on('click', '.c2p-shipping-card', function(e) {
            e.preventDefault();
            if (!isProcessing) {
                handleShippingCardClick.call(this);
            }
        });
        
        // Selecionar loja
        $(document).off('click', '.c2p-store-select-btn').on('click', '.c2p-store-select-btn', function(e) {
            e.preventDefault();
            if (!isProcessing) {
                handleStoreSelectClick.call(this);
            }
        });
    }
    
    /**
     * Handle switch click
     */
    function handleSwitchClick() {
        var $this = $(this);
        var mode = $this.data('mode');
        
        console.log('🔄 Mudando para modo:', mode);
        
        if ($this.hasClass('active')) {
            return;
        }
        
        $('.c2p-switch-option').removeClass('active');
        $this.addClass('active');
        
        if (mode === 'pickup') {
            $('.c2p-switch-slider').addClass('right');
        } else {
            $('.c2p-switch-slider').removeClass('right');
        }
        
        currentMode = mode;
        showModeContent(mode);
    }
    
    /**
     * Mostrar conteúdo do modo
     */
    function showModeContent(mode) {
        console.log('📋 Exibindo conteúdo:', mode);
        
        $('.c2p-mode-content').hide();
        $('#c2p-' + mode + '-content').fadeIn(300);
        
        if (mode === 'delivery') {
            var postcode = $('#c2p-postcode').val();
            if (!postcode) {
                $('#c2p-postcode').focus();
            } else if (postcode.replace(/\D/g, '').length === 8) {
                loadShippingMethods();
            }
        }
    }
    
    /**
     * Handle calcular frete
     */
    function handleCalculateShipping() {
        console.log('📮 Calculando frete...');
        
        var postcode = $('#c2p-postcode').val();
        var cleanPostcode = postcode.replace(/\D/g, '');
        
        if (cleanPostcode.length !== 8) {
            $('#c2p-postcode').addClass('error').focus();
            
            var errorMsg = $('<div class="c2p-error-message">Por favor, digite um CEP válido com 8 dígitos</div>');
            $('.c2p-postcode-input-group').after(errorMsg);
            
            setTimeout(function() {
                $('#c2p-postcode').removeClass('error');
                $('.c2p-error-message').fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
            
            return;
        }
        
        hasCalculatedShipping = true;
        lastCalculatedPostcode = postcode;
        
        isProcessing = true;
        var $btn = $('#c2p-calculate-shipping');
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner"></span> Calculando...');
        
        // NÃO enviar nonce para usuários não logados
        var ajaxData = {
            action: 'c2p_update_postcode',
            postcode: cleanPostcode
        };
        
        // Só adicionar nonce se estiver logado
        if (c2p_ajax.is_logged_in) {
            ajaxData.nonce = c2p_ajax.nonce;
        }
        
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('✅ CEP atualizado:', response);
                if (response.success) {
                    clearWooCommerceShipping();
                    loadShippingMethods();
                } else {
                    alert(response.data || 'Erro ao processar CEP');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro ao atualizar CEP:', error);
                alert('Erro ao processar CEP. Tente novamente.');
            },
            complete: function() {
                $btn.prop('disabled', false).html(originalText);
                isProcessing = false;
            }
        });
    }
    
    /**
     * Carregar métodos de envio
     */
    function loadShippingMethods(isAutoRecalc) {
        var dcId = $('#c2p-delivery-location').val() || 1;
        var postcode = $('#c2p-postcode').val();
        var cleanPostcode = postcode.replace(/\D/g, '');
        
        console.log('📦 ' + (isAutoRecalc ? 'Auto-recalculando' : 'Carregando') + ' métodos para CEP:', postcode);
        
        if (cleanPostcode.length !== 8) {
            if (!isAutoRecalc) {
                $('#c2p-shipping-methods').html(
                    '<div class="c2p-postcode-empty-message">' +
                    '⚠️ Digite seu CEP acima para ver as opções de entrega' +
                    '</div>'
                ).show();
            }
            return;
        }
        
        if (isLoadingShipping && !isAutoRecalc) {
            return;
        }
        
        isLoadingShipping = true;
        
        if (!isAutoRecalc) {
            $('#c2p-shipping-loading').show();
            $('#c2p-shipping-methods').hide();
        }
        
        var previousSelected = $('.c2p-shipping-card.selected').data('method');
        
        // NÃO enviar nonce para usuários não logados
        var ajaxData = {
            action: 'c2p_get_shipping_methods',
            location_id: dcId,
            postcode: cleanPostcode
        };
        
        if (c2p_ajax.is_logged_in) {
            ajaxData.nonce = c2p_ajax.nonce;
        }
        
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('✅ Métodos recebidos:', response);
                
                $('.c2p-recalc-indicator').fadeOut(function() {
                    $(this).remove();
                });
                
                if (!isAutoRecalc) {
                    $('#c2p-shipping-loading').hide();
                }
                
                if (response.success && response.data && response.data.html) {
                    $('#c2p-shipping-methods').html(response.data.html);
                    
                    if (!isAutoRecalc) {
                        $('#c2p-shipping-methods').fadeIn(300);
                        
                        $('.c2p-shipping-card').each(function(index) {
                            $(this).css('opacity', 0).delay(index * 100).animate({
                                opacity: 1
                            }, 300);
                        });
                    } else {
                        $('#c2p-shipping-methods').show();
                        
                        if (previousSelected) {
                            $('.c2p-shipping-card[data-method="' + previousSelected + '"]').addClass('selected');
                        }
                        
                        $('#c2p-shipping-methods').css('opacity', 0.7).animate({opacity: 1}, 300);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro ao carregar métodos:', error);
                
                $('.c2p-recalc-indicator').remove();
                
                if (!isAutoRecalc) {
                    $('#c2p-shipping-loading').hide();
                    $('#c2p-shipping-methods').html(
                        '<p style="text-align: center; color: red; padding: 20px;">' +
                        'Erro ao calcular frete. Tente novamente.</p>'
                    ).show();
                }
            },
            complete: function() {
                isLoadingShipping = false;
            }
        });
    }
    
    /**
     * Handle click no card de método de envio
     */
    function handleShippingCardClick() {
        if (isProcessing) {
            return;
        }
        
        var $this = $(this);
        
        console.log('📦 Método de envio selecionado');
        
        $('.c2p-shipping-card').removeClass('selected');
        $this.addClass('selected');
        
        $this.css('transform', 'scale(0.95)');
        setTimeout(function() {
            $this.css('transform', 'scale(1)');
        }, 200);
        
        var locationId = $('#c2p-delivery-location').val() || 1;
        var shippingMethod = $this.data('method');
        
        window.c2pLocationSelected = true;
        
        isProcessing = true;
        showLoadingOverlay('Processando sua seleção...');
        
        var timeoutId = setTimeout(function() {
            if (isProcessing) {
                hideLoadingOverlay();
                isProcessing = false;
                alert('A operação demorou muito. Por favor, tente novamente.');
            }
        }, 10000);
        
        selectLocation(locationId, 'delivery', shippingMethod, timeoutId);
    }
    
    /**
     * Handle click no botão de selecionar loja
     */
    function handleStoreSelectClick() {
        if (isProcessing) {
            return;
        }
        
        var $card = $(this).closest('.c2p-store-card');
        var locationId = $card.data('location-id');
        
        console.log('🏪 Loja selecionada:', locationId);
        
        $('.c2p-store-card').removeClass('selected');
        $card.addClass('selected');
        
        $card.css('transform', 'scale(0.98)');
        setTimeout(function() {
            $card.css('transform', 'scale(1)');
        }, 200);
        
        window.c2pLocationSelected = true;
        
        isProcessing = true;
        showLoadingOverlay('Selecionando loja para retirada...');
        
        var timeoutId = setTimeout(function() {
            if (isProcessing) {
                hideLoadingOverlay();
                isProcessing = false;
                alert('A operação demorou muito. Por favor, tente novamente.');
            }
        }, 10000);
        
        selectLocation(locationId, 'pickup', 'local_pickup', timeoutId);
    }
    
    /**
     * Selecionar local e salvar via AJAX
     */
    function selectLocation(locationId, type, method, timeoutId) {
        console.log('📍 Salvando seleção:', {
            locationId: locationId,
            type: type,
            method: method
        });
        
        if (type === 'pickup') {
            method = 'local_pickup';
        }
        
        // Salvar no sessionStorage como backup
        try {
            var selectionData = {
                id: locationId,
                type: type,
                delivery_type: type,
                shipping_method: method,
                timestamp: Date.now()
            };
            sessionStorage.setItem('c2p_selected_location', JSON.stringify(selectionData));
        } catch(e) {}
        
        // NÃO enviar nonce para usuários não logados
        var ajaxData = {
            action: 'c2p_select_location',
            location_id: locationId,
            delivery_type: type,
            shipping_method: method,
            is_pickup: (type === 'pickup' ? '1' : '0'),
            force_method: method
        };
        
        if (c2p_ajax.is_logged_in) {
            ajaxData.nonce = c2p_ajax.nonce;
        }
        
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('✅ Resposta do servidor:', response);
                
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                
                if (response.success) {
                    try {
                        sessionStorage.setItem('c2p_selection_confirmed', 'true');
                    } catch(e) {}
                    
                    updateCartTotals();
                    
                    var successMsg = type === 'pickup' 
                        ? 'Loja selecionada para retirada!' 
                        : 'Método de entrega selecionado!';
                    
                    $('.c2p-loading-overlay-text').html(
                        '<span style="color: #4caf50;">✓</span> ' + successMsg + 
                        '<br><small>Redirecionando para checkout...</small>'
                    );
                    
                    setTimeout(function() {
                        window.location.href = response.data.redirect || c2p_ajax.checkout_url;
                    }, 800);
                } else {
                    hideLoadingOverlay();
                    isProcessing = false;
                    alert(response.data || 'Erro ao processar seleção');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro AJAX:', error);
                
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                
                hideLoadingOverlay();
                isProcessing = false;
                alert('Erro na comunicação. Tente novamente.');
            }
        });
    }
    
    /**
     * Mostrar loading overlay
     */
    function showLoadingOverlay(message) {
        message = message || 'Processando...';
        
        $('.c2p-loading-overlay').remove();
        
        var html = '<div class="c2p-loading-overlay">' +
            '<div class="c2p-loading-overlay-content">' +
            '<div class="c2p-loading-overlay-spinner"></div>' +
            '<div class="c2p-loading-overlay-text">' + message + '</div>' +
            '<div class="c2p-loading-progress">' +
            '<div class="c2p-loading-progress-bar"></div>' +
            '</div>' +
            '</div>' +
            '</div>';
        
        $('body').append(html);
        $('.c2p-loading-overlay').fadeIn(200);
        
        setTimeout(function() {
            $('.c2p-loading-progress-bar').css('width', '80%');
        }, 100);
    }
    
    /**
     * Esconder loading overlay
     */
    function hideLoadingOverlay() {
        $('.c2p-loading-overlay').fadeOut(200, function() {
            $(this).remove();
        });
        isProcessing = false;
    }
    
    /**
     * Funções globais
     */
    window.c2pSelectStore = function(locationId) {
        if (isProcessing) return;
        
        console.log('🏪 Selecionando loja ID:', locationId);
        isProcessing = true;
        window.c2pLocationSelected = true;
        showLoadingOverlay('Selecionando loja para retirada...');
        
        var timeoutId = setTimeout(function() {
            if (isProcessing) {
                hideLoadingOverlay();
                isProcessing = false;
            }
        }, 10000);
        
        selectLocation(locationId, 'pickup', 'local_pickup', timeoutId);
    };
    
    window.c2pChangeLocation = function() {
        console.log('🔄 Alterando seleção...');
        showLoadingOverlay('Removendo seleção anterior...');
        
        window.c2pLocationSelected = false;
        hasCalculatedShipping = false;
        lastCalculatedPostcode = '';
        
        try {
            sessionStorage.clear();
        } catch(e) {}
        
        var ajaxData = {
            action: 'c2p_select_location',
            location_id: 0
        };
        
        if (c2p_ajax.is_logged_in) {
            ajaxData.nonce = c2p_ajax.nonce;
        }
        
        $.post(c2p_ajax.ajax_url, ajaxData, function() {
            window.location.reload();
        });
    };
    
    console.log('✅ Click2Pickup v4.0.0 carregado!');
    console.log('🛡️ Proteção para usuários não logados: ATIVA');
    
})(jQuery);