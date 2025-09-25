/**
 * Click2Pickup Cart JavaScript
 * Version: 3.1.0 - Com auto-recálculo de frete
 */

(function($) {
    'use strict';
    
    // Variáveis globais
    var isLoadingShipping = false;
    var currentMode = 'delivery';
    var isProcessing = false;
    var hasCalculatedShipping = false; // NOVO: Flag para saber se já calculou frete
    var lastCalculatedPostcode = ''; // NOVO: Último CEP calculado
    var autoRecalcTimer = null; // NOVO: Timer para auto-recálculo
    window.c2pLocationSelected = false;
    
    /**
     * Função para limpar seleções anteriores do WooCommerce
     */
    function clearWooCommerceShipping() {
        // Esconder caixa padrão de shipping
        $('.woocommerce-shipping-totals.shipping').hide();
        $('.woocommerce-shipping-calculator').hide();
        $('.shipping-calculator-button').hide();
        
        // Remover seleção de método de envio padrão se não houver seleção C2P
        if (!window.c2pLocationSelected) {
            $('input[name^="shipping_method"]:checked').prop('checked', false);
        }
    }
    
    /**
     * Função para atualizar totais do carrinho
     */
    function updateCartTotals() {
        // Trigger update do WooCommerce
        $(document.body).trigger('update_cart');
        
        // Aguardar e recalcular
        setTimeout(function() {
            var $updateButton = $('[name="update_cart"]');
            if ($updateButton.length && !$updateButton.prop('disabled')) {
                $updateButton.trigger('click');
            }
        }, 1000);
    }
    
    /**
     * NOVO: Função para auto-recalcular frete
     */
    function autoRecalculateShipping() {
        // Só recalcular se já calculou antes e tem CEP
        if (!hasCalculatedShipping || !lastCalculatedPostcode) {
            console.log('⏭️ Pulando auto-recálculo - não tem cálculo anterior');
            return;
        }
        
        // Só recalcular se estiver em modo delivery
        if (currentMode !== 'delivery') {
            console.log('⏭️ Pulando auto-recálculo - não está em modo delivery');
            return;
        }
        
        // Se já está visível o container de métodos
        if ($('#c2p-shipping-methods').is(':visible')) {
            console.log('♻️ Auto-recalculando frete...');
            
            // Mostrar indicador de recálculo inline (não blocking)
            if (!$('.c2p-recalc-indicator').length) {
                $('#c2p-shipping-methods').prepend(
                    '<div class="c2p-recalc-indicator">' +
                    '🔄 Atualizando valores de frete...' +
                    '</div>'
                );
            }
            
            // Fazer a chamada silenciosa
            loadShippingMethods(true); // true = é auto-recálculo
        }
    }
    
    /**
     * NOVO: Configurar listeners para auto-recálculo
     */
    function setupAutoRecalcListeners() {
        console.log('🔧 Configurando auto-recálculo...');
        
        // Monitorar mudanças no total do carrinho (após update)
        $(document.body).on('updated_cart_totals', function() {
            console.log('📦 Cart totals atualizados');
            
            // Re-esconder caixa padrão
            clearWooCommerceShipping();
            
            // Auto-recalcular se necessário
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    console.log('⏰ Iniciando auto-recálculo após update do carrinho');
                    autoRecalculateShipping();
                }, 1500);
            }
        });
        
        // Monitorar aplicação/remoção de cupons
        $(document.body).on('applied_coupon removed_coupon', function(event, coupon_code) {
            console.log('🎟️ Cupom alterado:', coupon_code || 'removido');
            
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    console.log('⏰ Iniciando auto-recálculo após cupom');
                    autoRecalculateShipping();
                }, 1000);
            }
        });
        
        // Monitorar mudança de quantidade (mais específico)
        $(document).on('change', 'input.qty', function() {
            console.log('🔢 Quantidade alterada');
            
            // Se tem local selecionado, atualizar totais
            if (window.c2pLocationSelected) {
                setTimeout(updateCartTotals, 500);
            }
            
            // Se já calculou frete, programar recálculo
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    console.log('⏰ Iniciando auto-recálculo após mudança de quantidade');
                    autoRecalculateShipping();
                }, 2000); // Aguarda 2 segundos após última mudança
            }
        });
        
        // Monitorar clique no botão de atualizar carrinho
        $('[name="update_cart"]').on('click', function() {
            console.log('🔄 Botão update cart clicado');
            
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    autoRecalculateShipping();
                }, 2000);
            }
        });
        
        // Monitorar remoção de itens do carrinho
        $(document).on('click', '.remove', function() {
            console.log('🗑️ Item removido do carrinho');
            
            if (hasCalculatedShipping && currentMode === 'delivery') {
                clearTimeout(autoRecalcTimer);
                autoRecalcTimer = setTimeout(function() {
                    autoRecalculateShipping();
                }, 2500);
            }
        });
    }
    
    /**
     * Inicialização quando documento estiver pronto
     */
    $(document).ready(function() {
        console.log('🚀 Click2Pickup v3.1.0 iniciando...');
        
        // IMPORTANTE: Limpar qualquer modal existente ao carregar página
        $('.c2p-loading-overlay').remove();
        
        // Limpar seleções do WooCommerce padrão
        clearWooCommerceShipping();
        
        // Verificar se tem local selecionado
        if ($('.c2p-selected-location').length > 0) {
            window.c2pLocationSelected = true;
        }
        
        initializeLocationSelector();
        bindEvents();
        setupAutoRecalcListeners(); // NOVO: Configurar auto-recálculo
        
        // Verificar modo inicial
        currentMode = $('.c2p-switch-option.active').data('mode') || 'delivery';
        showModeContent(currentMode);
        
        // Se tem CEP, carregar métodos e marcar como já calculado
        var postcode = $('#c2p-postcode').val();
        if (postcode && postcode.replace(/\D/g, '').length === 8) {
            lastCalculatedPostcode = postcode; // NOVO: Salvar CEP
            hasCalculatedShipping = true; // NOVO: Marcar que já calculou
            setTimeout(function() {
                loadShippingMethods();
            }, 500);
        }
        
        // Monitorar mudanças no carrinho
        $(document.body).on('updated_cart_totals', function() {
            // Re-esconder caixa padrão após updates
            clearWooCommerceShipping();
            
            // Verificar se precisa recalcular frete
            if (window.c2pLocationSelected) {
                // Aguardar um pouco e forçar recalculo
                setTimeout(function() {
                    $(document.body).trigger('wc_update_cart');
                }, 500);
            }
        });
        
        // Monitorar mudanças nas quantidades
        $(document).on('change', '.qty', function() {
            if (window.c2pLocationSelected) {
                setTimeout(updateCartTotals, 500);
            }
        });
    });
    
    // Limpar modal quando usar botão voltar do navegador
    $(window).on('pageshow', function(event) {
        if (event.originalEvent.persisted) {
            $('.c2p-loading-overlay').remove();
            isProcessing = false;
        }
        
        // Re-verificar seleções
        clearWooCommerceShipping();
    });
    
    // Limpar modal ao sair da página
    $(window).on('beforeunload', function() {
        $('.c2p-loading-overlay').remove();
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
        
        // Campo de CEP - Formatação automática
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
        
        // Prevenir clique na caixa de shipping padrão
        $('.woocommerce-shipping-totals').on('click', function(e) {
            if (!window.c2pLocationSelected) {
                e.preventDefault();
                e.stopPropagation();
                
                // Scroll para o seletor C2P
                $('html, body').animate({
                    scrollTop: $('.c2p-location-selector').offset().top - 100
                }, 500);
                
                // Destacar o seletor
                $('.c2p-location-selector').css({
                    'animation': 'pulse 1s',
                    'box-shadow': '0 0 20px rgba(102, 126, 234, 0.6)'
                });
                
                setTimeout(function() {
                    $('.c2p-location-selector').css({
                        'animation': '',
                        'box-shadow': ''
                    });
                }, 1000);
            }
        });
        
        console.log('✅ Eventos vinculados com sucesso');
    }
    
    /**
     * Handle switch click entre delivery e pickup
     */
    function handleSwitchClick() {
        var $this = $(this);
        var mode = $this.data('mode');
        
        console.log('🔄 Mudando para modo:', mode);
        
        if ($this.hasClass('active')) {
            return;
        }
        
        // Atualizar visual do switch
        $('.c2p-switch-option').removeClass('active');
        $this.addClass('active');
        
        // Animar slider
        if (mode === 'pickup') {
            $('.c2p-switch-slider').addClass('right');
        } else {
            $('.c2p-switch-slider').removeClass('right');
        }
        
        currentMode = mode;
        showModeContent(mode);
    }
    
    /**
     * Mostrar conteúdo do modo selecionado
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
            // Destacar campo com erro
            $('#c2p-postcode').addClass('error').focus();
            
            // Mostrar mensagem de erro
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
        
        // NOVO: Salvar que calculou e o CEP
        hasCalculatedShipping = true;
        lastCalculatedPostcode = postcode;
        
        isProcessing = true;
        var $btn = $('#c2p-calculate-shipping');
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner"></span> Calculando...');
        
        // Atualizar CEP no WooCommerce
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_update_postcode',
                postcode: cleanPostcode,
                nonce: c2p_ajax.nonce
            },
            success: function(response) {
                console.log('✅ CEP atualizado:', response);
                if (response.success) {
                    // Limpar seleções antigas do WooCommerce
                    clearWooCommerceShipping();
                    // Carregar métodos
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
     * Carregar métodos de envio via AJAX
     * MODIFICADO: Adicionar suporte para auto-recálculo
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
            console.log('⏳ Já está carregando...');
            return;
        }
        
        isLoadingShipping = true;
        
        // Só mostrar loading completo se não for auto-recálculo
        if (!isAutoRecalc) {
            $('#c2p-shipping-loading').show();
            $('#c2p-shipping-methods').hide();
        }
        
        // Salvar método selecionado anteriormente (para manter seleção)
        var previousSelected = $('.c2p-shipping-card.selected').data('method');
        
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_get_shipping_methods',
                location_id: dcId,
                postcode: cleanPostcode,
                nonce: c2p_ajax.nonce
            },
            success: function(response) {
                console.log('✅ Métodos recebidos:', response);
                
                // Remover indicador de recálculo
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
                        
                        // Animar cards de entrada
                        $('.c2p-shipping-card').each(function(index) {
                            $(this).css('opacity', 0).delay(index * 100).animate({
                                opacity: 1
                            }, 300);
                        });
                    } else {
                        $('#c2p-shipping-methods').show();
                        
                        // Se é auto-recálculo, manter seleção anterior se possível
                        if (previousSelected) {
                            $('.c2p-shipping-card[data-method="' + previousSelected + '"]').addClass('selected');
                        }
                        
                        // Adicionar flash de atualização
                        $('#c2p-shipping-methods').css('opacity', 0.7).animate({opacity: 1}, 300);
                    }
                    
                    // Adicionar efeito hover
                    $('.c2p-shipping-card').hover(
                        function() {
                            $(this).addClass('hover');
                        },
                        function() {
                            $(this).removeClass('hover');
                        }
                    );
                    
                    // NOVO: Se tem frete grátis disponível após recálculo, destacar
                    if (isAutoRecalc) {
                        $('.c2p-shipping-price.free').each(function() {
                            var $card = $(this).closest('.c2p-shipping-card');
                            if (!$card.hasClass('free-highlighted')) {
                                $card.addClass('free-highlighted');
                                $card.css('animation', 'pulse 2s');
                                setTimeout(function() {
                                    $card.css('animation', '');
                                }, 2000);
                            }
                        });
                    }
                } else {
                    if (!isAutoRecalc) {
                        $('#c2p-shipping-methods').html(
                            '<p style="text-align: center; color: #666; padding: 20px;">' +
                            'Nenhum método de envio disponível para este CEP</p>'
                        ).show();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro ao carregar métodos:', error);
                
                // Remover indicador de recálculo
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
            console.log('⏳ Já processando...');
            return;
        }
        
        var $this = $(this);
        
        console.log('📦 Método de envio selecionado');
        
        // Visual feedback
        $('.c2p-shipping-card').removeClass('selected');
        $this.addClass('selected');
        
        // Animação de seleção
        $this.css('transform', 'scale(0.95)');
        setTimeout(function() {
            $this.css('transform', 'scale(1)');
        }, 200);
        
        // Pegar dados
        var locationId = $('#c2p-delivery-location').val() || 1;
        var shippingMethod = $this.data('method');
        
        // Marcar que tem seleção
        window.c2pLocationSelected = true;
        
        // Mostrar loading
        isProcessing = true;
        showLoadingOverlay('Processando sua seleção...');
        
        // Timeout de segurança - remover loading após 10 segundos
        var timeoutId = setTimeout(function() {
            if (isProcessing) {
                hideLoadingOverlay();
                isProcessing = false;
                alert('A operação demorou muito. Por favor, tente novamente.');
            }
        }, 10000);
        
        // Selecionar location
        selectLocation(locationId, 'delivery', shippingMethod, timeoutId);
    }
    
    /**
     * Handle click no botão de selecionar loja
     */
    function handleStoreSelectClick() {
        if (isProcessing) {
            console.log('⏳ Já processando...');
            return;
        }
        
        var $card = $(this).closest('.c2p-store-card');
        var locationId = $card.data('location-id');
        
        console.log('🏪 Loja selecionada:', locationId);
        
        // Visual feedback
        $('.c2p-store-card').removeClass('selected');
        $card.addClass('selected');
        
        // Animação de seleção
        $card.css('transform', 'scale(0.98)');
        setTimeout(function() {
            $card.css('transform', 'scale(1)');
        }, 200);
        
        // Marcar que tem seleção
        window.c2pLocationSelected = true;
        
        // Mostrar loading
        isProcessing = true;
        showLoadingOverlay('Selecionando loja...');
        
        // Timeout de segurança
        var timeoutId = setTimeout(function() {
            if (isProcessing) {
                hideLoadingOverlay();
                isProcessing = false;
                alert('A operação demorou muito. Por favor, tente novamente.');
            }
        }, 10000);
        
        // Selecionar location
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
        
        $.ajax({
            url: c2p_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_select_location',
                location_id: locationId,
                delivery_type: type,
                shipping_method: method,
                nonce: c2p_ajax.nonce
            },
            success: function(response) {
                console.log('✅ Seleção salva:', response);
                
                // Limpar timeout
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                
                if (response.success) {
                    // Atualizar totais antes de redirecionar
                    updateCartTotals();
                    
                    // Pequeno delay antes de redirecionar
                    setTimeout(function() {
                        window.location.href = response.data.redirect || c2p_ajax.checkout_url;
                    }, 500);
                } else {
                    hideLoadingOverlay();
                    isProcessing = false;
                    alert(response.data || 'Erro ao processar seleção');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro ao salvar seleção:', error);
                
                // Limpar timeout
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
        
        // Remover overlay existente
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
        
        // Animar barra de progresso
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
    
    // Adicionar animação pulse e estilos extras via CSS
    var style = '<style>' +
        '@keyframes pulse {' +
        '  0% { transform: scale(1); }' +
        '  50% { transform: scale(1.02); }' +
        '  100% { transform: scale(1); }' +
        '}' +
        '.c2p-error-message {' +
        '  color: #dc3545;' +
        '  font-size: 14px;' +
        '  margin-top: 5px;' +
        '  padding: 8px;' +
        '  background: #fee;' +
        '  border-radius: 4px;' +
        '  border: 1px solid #fcc;' +
        '}' +
        '#c2p-postcode.error {' +
        '  border-color: #dc3545 !important;' +
        '  animation: shake 0.5s;' +
        '}' +
        '@keyframes shake {' +
        '  0%, 100% { transform: translateX(0); }' +
        '  25% { transform: translateX(-5px); }' +
        '  75% { transform: translateX(5px); }' +
        '}' +
        '.spinner {' +
        '  display: inline-block;' +
        '  width: 16px;' +
        '  height: 16px;' +
        '  border: 2px solid #f3f3f3;' +
        '  border-top: 2px solid #667eea;' +
        '  border-radius: 50%;' +
        '  animation: spin 1s linear infinite;' +
        '  vertical-align: middle;' +
        '  margin-right: 5px;' +
        '}' +
        /* NOVO: Indicador de recálculo */
        '.c2p-recalc-indicator {' +
        '  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);' +
        '  color: white;' +
        '  padding: 8px 15px;' +
        '  border-radius: 20px;' +
        '  text-align: center;' +
        '  margin-bottom: 15px;' +
        '  font-size: 14px;' +
        '  animation: slideDown 0.3s ease;' +
        '}' +
        '@keyframes slideDown {' +
        '  from { opacity: 0; transform: translateY(-10px); }' +
        '  to { opacity: 1; transform: translateY(0); }' +
        '}' +
        /* NOVO: Destacar frete grátis */
        '.c2p-shipping-card.free-highlighted {' +
        '  border-color: #4caf50 !important;' +
        '  background: linear-gradient(135deg, #f1f8e9 0%, #e8f5e9 100%) !important;' +
        '}' +
        '</style>';
    
    $('head').append(style);
    
    // Expor funções globais necessárias
    window.c2pSelectStore = function(locationId) {
        if (isProcessing) return;
        
        console.log('🏪 Selecionando loja ID:', locationId);
        isProcessing = true;
        window.c2pLocationSelected = true;
        showLoadingOverlay('Selecionando loja...');
        
        // Timeout de segurança
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
        showLoadingOverlay('Alterando...');
        
        // Limpar flags
        window.c2pLocationSelected = false;
        hasCalculatedShipping = false;
        lastCalculatedPostcode = '';
        
        $.post(c2p_ajax.ajax_url, {
            action: 'c2p_select_location',
            location_id: 0,
            nonce: c2p_ajax.nonce
        }, function() {
            window.location.reload();
        });
    };
    
    // Mensagem de confirmação
    console.log('✅ Click2Pickup Cart JS v3.1.0 carregado com sucesso!');
    console.log('📋 Auto-recálculo de frete ATIVADO');
    
})(jQuery);