/**
 * click2pickup/assets/cart/checkout.js
 * 
 * ✅ v8.8.0: RECARREGA CARDS DE PRODUTOS + DEBUG MODE
 * ✅ v8.8.0: Performance metrics no console
 * ✅ v8.7.2: TIMEOUT 15s + FIX savedData undefined
 * ✅ v8.7.1: BOTÃO LIXEIRA FUNCIONA
 * 
 * @package Click2Pickup
 * @since 8.8.0
 * @author rhpimenta
 * Last Update: 2025-01-09 18:45:00 UTC
 */

(function($){
  'use strict';

  var IS_CHECKOUT = $('body').hasClass('woocommerce-checkout') || $('form.checkout').length > 0;
  if (IS_CHECKOUT) {
    if (typeof console !== 'undefined' && console.log) {
      console.log('[C2P] checkout.js v8.8.0 — ignorado no /checkout/');
    }
    return;
  }

  var state = {
    mode: localStorage.getItem('c2p_last_mode') || 'home',
    shippingMethods: [],
    pickupMethods: [],
    chosenMethod: null,
    isLoading: false,
    quantityTimeout: null,
    stockCache: {},
    prepTimeCache: {},
    storeHoursCache: {},
    loadingStartTime: null,
    stockLoadingCount: 0,
    debugMode: window.location.search.indexOf('c2p_debug=1') > -1 // ✅ NOVO: Debug mode
  };

  var $spinner = $('#cwc-loading');
  var MINIMUM_LOADING_TIME = 300;

  // ✅ NOVO v8.8.0: Logger de performance
  function debugLog(message, data) {
    if (!state.debugMode) return;
    
    if (typeof console !== 'undefined' && console.log) {
      if (data) {
        console.log('[C2P DEBUG] ' + message, data);
      } else {
        console.log('[C2P DEBUG] ' + message);
      }
    }
  }

  function showLoading(){ 
    state.isLoading = true;
    state.loadingStartTime = Date.now();
    $spinner.fadeIn(150);
    debugLog('Loading iniciado');
  }
  
  function hideLoading(){ 
    state.isLoading = false;
    
    if (state.loadingStartTime) {
      var elapsed = Date.now() - state.loadingStartTime;
      debugLog('Loading finalizado em ' + elapsed + 'ms');
    }
    
    if (!state.loadingStartTime) {
      $spinner.fadeOut(150);
      return;
    }
    
    var elapsed = Date.now() - state.loadingStartTime;
    
    if (elapsed < MINIMUM_LOADING_TIME) {
      $spinner.hide();
    } else {
      $spinner.fadeOut(150);
    }
    
    state.loadingStartTime = null;
  }

  function notices(html, type){
    if (!html) return;
    
    $('.cwc-notices-wrapper').remove();
    
    var $wrapper = $('<div class="cwc-notices-wrapper"></div>');
    if (type === 'error') $wrapper.addClass('cwc-notices-error');
    else if (type === 'success') $wrapper.addClass('cwc-notices-success');
    
    $wrapper.html(html);
    
    var $checkoutContainer = $('.cwc-checkout-container');
    if ($checkoutContainer.length) {
      $checkoutContainer.before($wrapper);
    } else {
      $('body').prepend($wrapper);
    }
    
    if (type === 'success') {
      setTimeout(function(){
        $wrapper.fadeOut(500, function(){ $(this).remove(); });
      }, 5000);
    }
    
    $('html, body').animate({ scrollTop: $wrapper.offset().top - 100 }, 300);
  }

  // ✅ OTIMIZADO v8.8.0: Recarrega HTML dos produtos
  function updateTotals(data){
    if (!data) return;
    
    debugLog('updateTotals chamado', {
      subtotal: data.subtotal,
      item_count: data.item_count,
      has_cart_items: !!(data.cart_items && data.cart_items.length)
    });
    
    if (data.subtotal) $('.cwc-subtotal').html(data.subtotal);
    
    if (data.shipping_raw !== undefined) {
      var shippingValue = parseFloat(data.shipping_raw);
      if (shippingValue === 0) {
        $('.cwc-shipping, .cwc-shipping-value, .shipping-total, .cwc-price-row .cwc-shipping').html(
          '<span style="color:#16a34a;font-weight:600;">Grátis 🎉</span>'
        );
      } else {
        $('.cwc-shipping, .cwc-shipping-value, .shipping-total').html(data.shipping);
      }
    } else if (data.shipping) {
      $('.cwc-shipping, .cwc-shipping-value, .shipping-total').html(data.shipping);
    }
    
    if (data.discount) $('.cwc-discount-value').html('-' + data.discount);
    if (data.total) $('.cwc-total-value, .order-total').html(data.total);
    if (data.item_count !== undefined) $('.cwc-item-count').text(data.item_count);
    
    // ✅ NOVO v8.8.0: RECARREGA CARDS DE PRODUTOS
    if (data.cart_items && Array.isArray(data.cart_items) && data.cart_items.length > 0) {
      renderProductCards(data.cart_items);
    } else if (data.cart_empty) {
      $('.cwc-products-list').html('<div class="cwc-empty-cart">Seu carrinho está vazio.</div>');
    }
    
    // ✅ FORÇA RECÁLCULO DE ESTOQUE
    if (data.item_count !== undefined) {
      state.stockCache = {};
      $('.c2p-ship-card__stock[data-loaded="true"]').attr('data-loaded', 'false').attr('data-loading', 'false');
      
      setTimeout(function() {
        loadStockInfo();
      }, 200);
    }
  }

  // ✅ NOVO v8.8.0: Renderiza cards completos dos produtos
  function renderProductCards(cartItems) {
    debugLog('renderProductCards chamado', { total_items: cartItems.length });
    
    if (!cartItems || cartItems.length === 0) {
      $('.cwc-products-list').html('<div class="cwc-empty-cart">Seu carrinho está vazio.</div>');
      return;
    }

    var html = '';
    
    cartItems.forEach(function(item) {
      var imageHtml = item.image 
        ? '<img src="' + item.image + '" alt="' + item.name + '">' 
        : '<div style="width:80px;height:80px;background:#f0f0f0;"></div>';
      
      var skuHtml = item.sku 
        ? '<div class="cwc-product-sku">SKU: <span>' + item.sku + '</span></div>' 
        : '';
      
      html += `
        <div class="cwc-product-item" 
             data-cart-key="${item.key}" 
             data-product-id="${item.product_id}">
          
          <div class="cwc-product-image">
            ${imageHtml}
          </div>
          
          <div class="cwc-product-info">
            <h3 class="cwc-product-name">${item.name}</h3>
            ${skuHtml}
          </div>
          
          <div class="cwc-quantity-control">
            <button class="cwc-qty-minus" data-action="minus" aria-label="Diminuir quantidade">−</button>
            <input type="number" 
                   class="cwc-qty-input" 
                   value="${item.quantity}" 
                   min="1" 
                   max="999"
                   aria-label="Quantidade do produto ${item.name}">
            <button class="cwc-qty-plus" data-action="plus" aria-label="Aumentar quantidade">+</button>
          </div>
          
          <div class="cwc-product-price">
            <span class="cwc-sale-price">${item.subtotal}</span>
          </div>
          
          <button class="cwc-remove-item" 
                  title="Remover ${item.name} do carrinho" 
                  aria-label="Remover ${item.name} do carrinho">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/>
            </svg>
          </button>
        </div>
      `;
    });
    
    $('.cwc-products-list').html(html);
    debugLog('Cards de produtos renderizados');
  }

  function saveCardStockData() {
    var savedData = {};
    
    $('.c2p-ship-card').each(function() {
      var $card = $(this);
      var methodId = $card.data('method-id');
      var stockData = $card.data('stock-data');
      var stockPercentage = $card.data('stock-percentage');
      var isDisabled = $card.hasClass('is-disabled') || $card.attr('data-disabled') === 'true';
      
      if (methodId) {
        var $stockBadge = $card.find('.c2p-ship-card__stock');
        var isLoaded = $stockBadge.attr('data-loaded') === 'true';
        
        if (isLoaded) {
          savedData[methodId] = {
            stockData: stockData,
            stockPercentage: stockPercentage,
            isDisabled: isDisabled
          };
        }
      }
    });
    
    return savedData;
  }

  function restoreCardStockData(savedData) {
    if (!savedData || Object.keys(savedData).length === 0) return;
    
    $('.c2p-ship-card').each(function() {
      var $card = $(this);
      var methodId = $card.data('method-id');
      
      if (methodId && savedData[methodId]) {
        if (savedData[methodId].stockData) {
          $card.data('stock-data', savedData[methodId].stockData);
        }
        if (savedData[methodId].stockPercentage !== undefined) {
          $card.data('stock-percentage', savedData[methodId].stockPercentage);
        }
        if (savedData[methodId].isDisabled) {
          $card.addClass('is-disabled');
          $card.attr('data-disabled', 'true');
          $card.css('pointer-events', 'none');
          $card.find('.c2p-ship-card__price').remove();
          $card.find('.c2p-ship-card__prep').remove();
          $card.find('.c2p-ship-card__eta').remove();
        }
      }
    });
  }

  function restoreMethodsStockData(methods, savedData) {
    if (!methods || !savedData || Object.keys(savedData).length === 0) {
      return methods;
    }

    return methods.map(function(method) {
      var saved = savedData[method.id];
      
      if (saved) {
        method.stock_percentage = saved.stockPercentage;
        method.stock_data = saved.stockData;
        
        if (saved.isDisabled) {
          method.has_stock_location = false;
          method.stock_percentage = 0;
        }
      }
      
      return method;
    });
  }

  function processServerResponse(data) {
    if (!data || typeof data !== 'object') {
      if (typeof console !== 'undefined' && console.error) {
        console.error('[C2P] Dados inválidos recebidos do servidor:', data);
      }
      return;
    }
    
    debugLog('processServerResponse', {
      has_shipping: !!data.shipping_methods,
      has_pickup: !!data.pickup_methods,
      item_count: data.item_count
    });

    var savedStockData = saveCardStockData();

    state.shippingMethods = data.shipping_methods || [];
    state.pickupMethods = data.pickup_methods || [];
    state.chosenMethod = data.chosen_method;

    updateTotals(data);

    if (state.mode === 'home' && savedStockData) {
      state.shippingMethods = restoreMethodsStockData(state.shippingMethods, savedStockData);
    } else if (savedStockData) {
      state.pickupMethods = restoreMethodsStockData(state.pickupMethods, savedStockData);
    }

    if (state.mode === 'home') {
      renderShippingCards(state.shippingMethods);
    } else {
      renderPickupCards(state.pickupMethods);
    }

    setTimeout(function() {
      restoreCardStockData(savedStockData);
    }, 50);

    if (data.formatted_cep) {
      if ($('.cwc-cep-required-state').is(':visible')) {
        window.location.reload();
      } else if ($('#current-cep').length > 0) {
        $('#current-cep').text(data.formatted_cep);
      }
    }
  }

  // ✅ OTIMIZADO v8.8.0: Métricas de performance
  function performAjaxAction(action, data) {
    if (state.isLoading) {
      debugLog('AJAX bloqueado - já está carregando');
      return;
    }
    
    var requestStart = Date.now();
    debugLog('AJAX iniciado: ' + action, data);
    
    showLoading();
    
    $.ajax({
      type: 'POST',
      url: cwc_ajax.ajax_url,
      data: $.extend({ action: 'cwc_' + action, nonce: cwc_ajax.nonce }, data),
      timeout: 15000,
      success: function(response) {
        var elapsed = Date.now() - requestStart;
        debugLog('AJAX sucesso em ' + elapsed + 'ms: ' + action, response);
        
        if (response && response.success) {
          processServerResponse(response.data);
        } else {
          var msg = (response && response.data && response.data.message) ? response.data.message : 'Ocorreu um erro.';
          notices('<div class="woocommerce-error">' + msg + '</div>', 'error');
        }
      },
      error: function(xhr, status, error) {
        var elapsed = Date.now() - requestStart;
        debugLog('AJAX erro em ' + elapsed + 'ms: ' + action, { status: xhr.status, error: error });
        
        var msg = 'Erro de comunicação com o servidor.';
        
        if (status === 'timeout') {
          msg = '⏱️ O servidor demorou ' + (elapsed/1000).toFixed(1) + 's para responder.\n\n' +
                '• Servidor E2C pode estar sobrecarregado\n' +
                '• Tente recarregar a página (F5)\n\n' +
                'Se persistir, entre em contato com o suporte.';
        } else if (xhr.status === 403) {
          msg = 'Sessão expirada. Recarregue a página.';
        } else if (xhr.status === 404) {
          msg = 'Ação não encontrada. Verifique se o plugin está ativo.';
        } else if (xhr.status >= 500) {
          msg = 'Erro no servidor. Tente novamente em alguns segundos.';
        }
        
        notices('<div class="woocommerce-error">' + msg + '</div>', 'error');
        
        if (typeof console !== 'undefined' && console.error) {
          console.error('[C2P AJAX Error]', {
            action: action,
            status: xhr.status,
            statusText: xhr.statusText,
            error: error,
            timeout: status === 'timeout',
            elapsed_ms: elapsed
          });
        }
      },
      complete: function() {
        hideLoading();
      }
    });
  }

  function iconTruck(){ 
    return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>';
  }
  
  function iconStore(){ 
    return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>';
  }
  
  function iconBox(){
    return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>';
  }

  function iconClock(){
    return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
  }

  function loadPrepTimeAndHours(storeId, $prepElement) {
    if (state.prepTimeCache[storeId] && state.storeHoursCache[storeId]) {
      renderPrepTimeWithTooltip($prepElement, state.prepTimeCache[storeId], state.storeHoursCache[storeId]);
      return;
    }

    var urlParams = new URLSearchParams(window.location.search);
    var testTime = urlParams.get('test_time') || '';

    $.ajax({
      url: cwc_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'cwc_get_prep_time',
        nonce: cwc_ajax.nonce,
        store_id: storeId,
        test_time: testTime
      },
      success: function(resp){
        if (resp && resp.success && resp.data){
          state.prepTimeCache[storeId] = resp.data;
          
          if (state.storeHoursCache[storeId]){
            renderPrepTimeWithTooltip($prepElement, resp.data, state.storeHoursCache[storeId]);
          }
        }
      }
    });

    $.ajax({
      url: cwc_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'cwc_get_store_hours',
        nonce: cwc_ajax.nonce,
        store_id: storeId
      },
      success: function(resp){
        if (resp && resp.success && resp.data && resp.data.hours){
          state.storeHoursCache[storeId] = resp.data.hours;
          
          if (state.prepTimeCache[storeId]){
            renderPrepTimeWithTooltip($prepElement, state.prepTimeCache[storeId], resp.data.hours);
          }
        }
      }
    });
  }

  function renderPrepTimeWithTooltip($element, prepData, storeHours) {
    if (!prepData || !prepData.success) {
      $element.remove();
      return;
    }

    var tooltipHtml = '';
    if (storeHours) {
      tooltipHtml = '<span class="c2p-tooltip-text">📅 Horários de funcionamento:\n\n' + 
                    storeHours.replace(/\n/g, '\n') + 
                    '</span>';
    }

    var html = iconClock() + ' <span>' + prepData.message + '</span>' + tooltipHtml;
    
    $element.html(html).show();
  }

  function createStockModal() {
    if (document.getElementById('c2p-stock-modal')) return;
    
    var html = `
      <div id="c2p-stock-modal-overlay" class="c2p-modal-overlay"></div>
      <div id="c2p-stock-modal" class="c2p-modal">
        <div class="c2p-modal-header">
          <h3>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="28" height="28">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <span id="c2p-modal-title">Ops! Estoque insuficiente</span>
          </h3>
          <button class="c2p-modal-close">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="c2p-modal-body">
          <div class="c2p-modal-message"></div>
          <div class="c2p-modal-items"></div>
        </div>
        <div class="c2p-modal-footer">
          <button class="c2p-modal-btn c2p-modal-btn-secondary" data-action="cancel">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Voltar ao Carrinho
          </button>
          <button class="c2p-modal-btn c2p-modal-btn-primary" data-action="adjust">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            Ajustar Automaticamente
          </button>
        </div>
      </div>
    `;
    
    $('body').append(html);
    
    $('#c2p-stock-modal-overlay, .c2p-modal-close, [data-action="cancel"]').on('click', closeStockModal);
    $('[data-action="adjust"]').on('click', adjustQuantities);
  }

  function showStockModal(stockData, methodLabel) {
    createStockModal();
    
    var $modal = $('#c2p-stock-modal');
    var $overlay = $('#c2p-stock-modal-overlay');
    
    if ($modal.length === 0 || $overlay.length === 0) return;
    
    var $message = $('.c2p-modal-message');
    var $items = $('.c2p-modal-items');
    var $title = $('#c2p-modal-title');
    var $footer = $('.c2p-modal-footer');
    
    var percentage = stockData.percentage || 0;
    var totalMissing = 0;
    
    if (stockData.items && stockData.items.length > 0) {
      stockData.items.forEach(function(item) {
        if (item.missing > 0) {
          totalMissing += item.missing;
        }
      });
    }
    
    var statusText = '';
    var emoji = '';
    var instruction = '';
    
    if (percentage >= 90) {
      emoji = '😊';
      $title.text('Quase lá! Falta pouco para finalizar');
      statusText = 'Boa notícia! <strong>' + methodLabel + '</strong> tem quase tudo que você precisa.';
      instruction = '<strong>Para continuar nesta loja:</strong> reduza a quantidade dos itens abaixo ou clique em "Ajustar Automaticamente".';
    } else if (percentage >= 70) {
      emoji = '🤔';
      $title.text('Ops! Alguns itens não estão disponíveis');
      statusText = '<strong>' + methodLabel + '</strong> não tem estoque suficiente de alguns produtos.';
      instruction = '<strong>O que fazer?</strong> Reduza as quantidades abaixo para seguir com esta loja, ou escolha outra opção de retirada.';
    } else if (percentage >= 50) {
      emoji = '😕';
      $title.text('Atenção! Estoque limitado nesta loja');
      statusText = '<strong>' + methodLabel + '</strong> tem apenas <strong>metade</strong> dos produtos do seu pedido.';
      instruction = '<strong>Você precisa decidir:</strong> Ajuste as quantidades para continuar aqui, ou selecione outra loja com mais estoque disponível.';
    } else {
      emoji = '😢';
      $title.text('Esta loja não consegue atender seu pedido');
      statusText = '<strong>' + methodLabel + '</strong> tem <strong>muito pouco estoque</strong> dos itens que você quer.';
      instruction = '<strong>Recomendação:</strong> Escolha outra loja na lista acima, ou ajuste drasticamente as quantidades.';
    }
    
    $message.html(`
      <div style="margin-bottom: 16px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 6px;">
        ${emoji} ${statusText}
      </div>
      <p style="margin: 12px 0; font-size: 14px; color: #374151; line-height: 1.6;">
        ${instruction}
      </p>
      <div style="margin: 16px 0 12px; padding: 10px; background: #f0f9ff; border-radius: 6px; border: 1px solid #bfdbfe;">
        <strong style="color: #1e40af; font-size: 13px;">📦 Itens com estoque insuficiente:</strong>
      </div>
    `);
    
    $items.empty();
    
    if (stockData.items && stockData.items.length > 0) {
      stockData.items.forEach(function(item) {
        if (item.missing > 0) {
          var $productDiv = $('.cwc-product-item').filter(function() {
            return $(this).find('.cwc-product-name').text().trim() === item.name;
          });
          
          var productImage = '';
          if ($productDiv.length) {
            var $img = $productDiv.find('.cwc-product-image img');
            if ($img.length) {
              productImage = '<img src="' + $img.attr('src') + '" alt="' + item.name + '">';
            }
          }
          
          var actionText = '';
          if (item.available === 0) {
            actionText = '<span style="color: #dc2626; font-weight: 600;">❌ Remova este item</span>';
          } else {
            actionText = '<span style="color: #ea580c; font-weight: 600;">⚠️ Reduza para ' + item.available + ' unidade' + (item.available > 1 ? 's' : '') + '</span>';
          }
          
          var $item = $(`
            <div class="c2p-stock-item">
              ${productImage ? '<div class="c2p-stock-item-image">' + productImage + '</div>' : ''}
              <div class="c2p-stock-item-info">
                <span class="c2p-stock-item-name">${item.name}</span>
                <span class="c2p-stock-item-qty">
                  Você pediu: <strong>${item.requested}</strong> • Disponível: <strong>${item.available}</strong>
                </span>
                <span style="font-size: 12px; margin-top: 4px; display: block;">
                  ${actionText}
                </span>
              </div>
            </div>
          `);
          $items.append($item);
        }
      });
    }
    
    var btnText = '';
    if (percentage >= 70) {
      btnText = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Ajustar e Continuar';
    } else {
      btnText = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Ajustar Quantidades';
    }
    
    $footer.find('[data-action="adjust"]').html(btnText);
    
    $modal.data('stockData', stockData);
    
    $overlay.fadeIn(200);
    $modal.fadeIn(200);
  }

  function closeStockModal() {
    $('#c2p-stock-modal-overlay').fadeOut(200);
    $('#c2p-stock-modal').fadeOut(200);
  }

  function adjustQuantities() {
    var $btn = $('[data-action="adjust"]');
    var stockData = $('#c2p-stock-modal').data('stockData');
    
    if (!stockData || !stockData.items || stockData.items.length === 0) {
      closeStockModal();
      return;
    }
    
    $btn.prop('disabled', true).html(`
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M12 6v6l4 2"/>
      </svg>
      Ajustando...
    `);
    
    var adjustmentsMap = {};
    
    stockData.items.forEach(function(item) {
      if (item.missing > 0 && item.available >= 0) {
        adjustmentsMap[item.product_id] = item.available;
      }
    });
    
    if (Object.keys(adjustmentsMap).length === 0) {
      closeStockModal();
      return;
    }
    
    var totalAdjustments = Object.keys(adjustmentsMap).length;
    var completed = 0;
    
    $('.cwc-product-item').each(function() {
      var $item = $(this);
      var productId = $item.data('product-id');
      var cartKey = $item.data('cart-key');
      
      if (adjustmentsMap.hasOwnProperty(productId)) {
        var newQty = adjustmentsMap[productId];
        
        $.ajax({
          url: cwc_ajax.ajax_url,
          type: 'POST',
          data: {
            action: 'cwc_update_quantity',
            nonce: cwc_ajax.nonce,
            cart_item_key: cartKey,
            quantity: newQty
          },
          complete: function() {
            completed++;
            
            if (completed === totalAdjustments) {
              state.stockCache = {};
              closeStockModal();
              
              setTimeout(function() {
                location.reload();
              }, 300);
            }
          }
        });
      }
    });
    
    if (completed === 0) {
      state.stockCache = {};
      closeStockModal();
      location.reload();
    }
  }

  function sortMethodsArrayByScore(methods) {
    if (!methods || methods.length === 0) return methods;
    
    return methods.sort(function(a, b) {
      var aStock = a.stock_percentage !== undefined ? a.stock_percentage : 100;
      var bStock = b.stock_percentage !== undefined ? b.stock_percentage : 100;
      var aFree = (a.cost === 0 || a.is_free);
      var bFree = (b.cost === 0 || b.is_free);
      var aNoLocation = (a.has_stock_location === false);
      var bNoLocation = (b.has_stock_location === false);
      
      var scoreA = 0;
      var scoreB = 0;
      
      if (aNoLocation) {
        scoreA = -1000;
      } else if (aStock === 0) {
        scoreA = -999;
      } else if (aStock === 100 && aFree) {
        scoreA = 1000;
      } else if (aStock === 100 && !aFree) {
        scoreA = 900 - (a.cost || 0);
      } else if (aStock > 0 && aFree) {
        scoreA = 800 + aStock;
      } else {
        scoreA = 700 + aStock - (a.cost || 0) * 0.1;
      }
      
      if (bNoLocation) {
        scoreB = -1000;
      } else if (bStock === 0) {
        scoreB = -999;
      } else if (bStock === 100 && bFree) {
        scoreB = 1000;
      } else if (bStock === 100 && !bFree) {
        scoreB = 900 - (b.cost || 0);
      } else if (bStock > 0 && bFree) {
        scoreB = 800 + bStock;
      } else {
        scoreB = 700 + bStock - (b.cost || 0) * 0.1;
      }
      
      return scoreB - scoreA;
    });
  }

  function sortCardsByScore() {
    var $container = state.mode === 'home' ? 
      $('#cwc-shipping-cards-container .c2p-ship-cards') : 
      $('#cwc-pickup-container .c2p-ship-cards');
    
    if (!$container.length) return;
    
    var $cards = $container.find('.c2p-ship-card').detach();
    
    var cardsArray = $cards.toArray().map(function(card) {
      var $card = $(card);
      var stock = $card.data('stock-percentage');
      var cost = parseFloat($card.data('cost')) || 0;
      var isFree = ($card.find('.c2p-ship-card__price.is-free').length > 0);
      
      var hasStockLocation = !($card.attr('data-disabled') === 'true' && $card.find('.c2p-ship-card__stock.stock-none[data-loaded="true"]').length > 0);
      
      if (!hasStockLocation) {
        return {
          element: card,
          score: -1000,
          stock: 0,
          cost: cost,
          isFree: isFree,
          noLocation: true
        };
      }
      
      if (stock === undefined) stock = 100;
      
      var score = 0;
      
      if (stock === 0) {
        score = -999;
      } else if (stock === 100 && isFree) {
        score = 1000;
      } else if (stock === 100 && !isFree) {
        score = 900 - cost;
      } else if (stock > 0 && isFree) {
        score = 800 + stock;
      } else {
        score = 700 + stock - cost * 0.1;
      }
      
      return {
        element: card,
        score: score,
        stock: stock,
        cost: cost,
        isFree: isFree,
        noLocation: false
      };
    });
    
    cardsArray.sort(function(a, b) {
      return b.score - a.score;
    });
    
    cardsArray.forEach(function(item) {
      $container.append(item.element);
    });
    
    debugLog('Cards reordenados', cardsArray.map(c => ({
      score: c.score.toFixed(2),
      stock: c.stock + '%',
      free: c.isFree
    })));
  }

  function loadLocationStock(locationId, locationType, $element) {
    var cacheKey = locationId + '_' + locationType;
    
    $element.attr('data-loading', 'true');
    
    if (state.stockCache[cacheKey]) {
      renderStockBadge($element, state.stockCache[cacheKey]);
      return;
    }
    
    state.stockLoadingCount++;
    
    $.ajax({
      url: cwc_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'cwc_check_location_stock',
        nonce: cwc_ajax.nonce,
        location_id: locationId,
        location_type: locationType
      },
      success: function(resp){
        if (resp && resp.success && resp.data){
          state.stockCache[cacheKey] = resp.data;
          renderStockBadge($element, resp.data);
          
          var $card = $element.closest('.c2p-ship-card');
          if ($card.length) {
            $card.data('stock-percentage', resp.data.percentage);
            $card.data('stock-data', resp.data);
            
            var cost = parseFloat($card.find('.c2p-ship-card__price:not(.is-free)').text().replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            $card.data('cost', cost);
            
            if (resp.data.percentage === 0) {
              $card.addClass('is-disabled');
              $card.attr('data-disabled', 'true');
              $card.css('pointer-events', 'none');
              $card.find('.c2p-ship-card__price').remove();
              $card.find('.c2p-ship-card__prep').remove();
              $card.find('.c2p-ship-card__eta').remove();
            }
          }
        } else {
          $element.attr('data-loading', 'false').hide();
        }
      },
      error: function(){
        $element.attr('data-loading', 'false').hide();
      },
      complete: function() {
        state.stockLoadingCount--;
        
        if (state.stockLoadingCount === 0) {
          setTimeout(sortCardsByScore, 100);
        }
      }
    });
  }

  function renderStockBadge($element, data) {
    $element.attr('data-loading', 'false');
    
    if (!data) {
      $element.hide();
      return;
    }
    
    var html = iconBox() + ' ';
    var className = '';
    
    if (data.percentage === 100) {
      html += '<span>Estoque completo</span>';
      className = 'stock-full';
    } else if (data.percentage > 0) {
      html += '<span>' + data.percentage + '% disponível</span>';
      className = 'stock-partial';
    } else {
      html += '<span>Sem estoque</span>';
      className = 'stock-none';
    }
    
    $element.html(html)
      .removeClass('stock-full stock-partial stock-none')
      .addClass(className)
      .attr('data-loaded', 'true')
      .show();
  }

  function loadStockInfo(){
    $('.c2p-ship-card[data-type="delivery"]:not([data-disabled="true"])').each(function(){
      var $card = $(this);
      var $stockDiv = $card.find('.c2p-ship-card__stock');
      
      if ($stockDiv.attr('data-loaded') === 'true' || $stockDiv.attr('data-loading') === 'true') return;
      
      loadLocationStock(0, 'cd', $stockDiv);
    });
    
    $('.c2p-ship-card[data-type="pickup"][data-store-id]:not([data-disabled="true"])').each(function(){
      var $card = $(this);
      var storeId = $card.data('store-id');
      var $stockDiv = $card.find('.c2p-ship-card__stock');
      
      if (!storeId || $stockDiv.attr('data-loaded') === 'true' || $stockDiv.attr('data-loading') === 'true') return;
      
      loadLocationStock(storeId, 'store', $stockDiv);
    });
  }

  function loadPrepTimeInfo(){
    $('.c2p-ship-card[data-type="pickup"][data-store-id]').each(function(){
      var $card = $(this);
      var storeId = $card.data('store-id');
      var $prepDiv = $card.find('.c2p-ship-card__prep');
      
      if (!storeId || !$prepDiv.length || $prepDiv.attr('data-loaded')) return;
      
      $prepDiv.attr('data-loaded', 'true');
      loadPrepTimeAndHours(storeId, $prepDiv);
    });
  }

  function buildCard(method, isPickup) {
    var isSelected = (state.chosenMethod === method.id);
    var icon = isPickup ? iconStore() : iconTruck();
    var iconClass = isPickup ? 'store-icon' : '';
    var badge = isPickup ? 'Retirar na loja' : 'Receber em casa';

    var hasNoStock = (method.stock_percentage === 0 || method.has_stock_location === false);

    var priceHtml = '';
    if (!hasNoStock) {
      priceHtml = (method.cost === 0 || method.is_free) ?
        '<div class="c2p-ship-card__price is-free">Grátis 🎉</div>' :
        '<div class="c2p-ship-card__price">' + method.cost_display + '</div>';
    }

    var prepHtml = '';
    if (isPickup && !hasNoStock) {
      prepHtml = '<div class="c2p-ship-card__prep" style="display:none;"></div>';
    }

    var etaHtml = '';
    if (!isPickup && !hasNoStock && method.eta) {
      etaHtml = '<div class="c2p-ship-card__eta">' + iconClock() + ' <span>' + method.eta + '</span></div>';
    }

    var stockHtml = '';
    if (method.has_stock_location === false) {
      stockHtml = '<div class="c2p-ship-card__stock stock-none" data-loaded="true">' + iconBox() + ' <span>Sem estoque</span></div>';
    } else if (method.stock_percentage !== undefined) {
      if (method.stock_percentage === 100) {
        stockHtml = '<div class="c2p-ship-card__stock stock-full" data-loaded="true">' + iconBox() + ' <span>Estoque completo</span></div>';
      } else if (method.stock_percentage > 0) {
        stockHtml = '<div class="c2p-ship-card__stock stock-partial" data-loaded="true">' + iconBox() + ' <span>' + method.stock_percentage + '% disponível</span></div>';
      } else {
        stockHtml = '<div class="c2p-ship-card__stock stock-none" data-loaded="true">' + iconBox() + ' <span>Sem estoque</span></div>';
      }
    } else {
      stockHtml = '<div class="c2p-ship-card__stock" data-loaded="false" data-loading="false"><span>Verificando...</span></div>';
    }

    return `
      <label class="c2p-ship-card ${isSelected ? 'is-selected' : ''} ${method.has_stock_location === false || method.stock_percentage === 0 ? 'is-disabled' : ''}" 
        data-method-id="${method.id}" 
        data-type="${isPickup ? 'pickup' : 'delivery'}" 
        data-cost="${method.cost || 0}"
        data-stock-percentage="${method.stock_percentage !== undefined ? method.stock_percentage : ''}"
        ${method.store_id ? `data-store-id="${method.store_id}"` : ''}
        ${method.has_stock_location === false || method.stock_percentage === 0 ? 'data-disabled="true" style="pointer-events:none;"' : ''}>
          <div class="c2p-ship-card__icon ${iconClass}">${icon}</div>
          <div class="c2p-ship-card__body">
              <div class="c2p-ship-card__badge">${badge}</div>
              <div class="c2p-ship-card__title">${method.label}</div>
              ${priceHtml}
              ${prepHtml}
              ${etaHtml}
              ${stockHtml}
          </div>
      </label>`;
  }

  function renderShippingCards(methods) {
    var $container = $('#cwc-shipping-cards-container');
    if (!$container.length) $container = $('.cwc-shipping-select-wrapper');
    if (!$container.length) return;

    if (!methods || methods.length === 0) {
      $container.html('<div class="c2p-ship-placeholder">Informe o CEP para ver opções de entrega</div>');
      return;
    }

    methods = sortMethodsArrayByScore(methods);

    var cardsHtml = methods.map(m => buildCard(m, false)).join('');
    $container.html('<div class="c2p-ship-cards">' + cardsHtml + '</div>');
    
    state.stockLoadingCount = 0;
    setTimeout(loadStockInfo, 100);
  }

  function renderPickupCards(methods) {
    var $container = $('#cwc-pickup-container');
    if (!$container.length) return;

    if (!methods || methods.length === 0) {
      $container.html('<div class="c2p-ship-placeholder">Nenhuma loja disponível para retirada</div>');
      return;
    }

    methods = sortMethodsArrayByScore(methods);

    var cardsHtml = methods.map(m => buildCard(m, true)).join('');
    $container.html('<div class="c2p-ship-cards">' + cardsHtml + '</div>');
    
    state.stockLoadingCount = 0;
    
    setTimeout(function(){
      loadStockInfo();
      loadPrepTimeInfo();
    }, 100);
  }

  $(document).on('click', '.cwc-delivery-btn', function(e) {
    e.preventDefault();
    var target = $(this).data('delivery') === 'store' ? 'store' : 'home';

    if (state.mode === target) return;

    state.mode = target;
    localStorage.setItem('c2p_last_mode', target);

    $('.cwc-delivery-btn').removeClass('active');
    $(this).addClass('active');

    $('#shipping-calculator, .cwc-shipping-calculator').toggle(state.mode === 'home');
    $('#cwc-pickup-container').toggle(state.mode === 'store');

    if (state.mode === 'home') {
      renderShippingCards(state.shippingMethods);
    } else {
      renderPickupCards(state.pickupMethods);
    }
  });

  $(document).on('click', '.c2p-ship-card', function(e){
    e.preventDefault();
    
    var $card = $(this);
    
    if ($card.attr('data-disabled') === 'true' || $card.hasClass('is-disabled')){
      return false;
    }
    
    var methodId = $card.data('method-id');
    var type = $card.data('type');
    var storeId = $card.data('store-id');
    
    var stockData = $card.data('stock-data');
    var stockPercentage = $card.data('stock-percentage');
    
    if (stockData && stockPercentage < 100 && stockPercentage > 0) {
      var methodLabel = $card.find('.c2p-ship-card__title').text();
      showStockModal(stockData, methodLabel);
      return false;
    }
    
    if (!stockData && stockPercentage < 100 && stockPercentage > 0) {
      var locationId = storeId || 0;
      var locationType = type === 'pickup' ? 'store' : 'cd';
      
      $.ajax({
        url: cwc_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'cwc_check_location_stock',
          nonce: cwc_ajax.nonce,
          location_id: locationId,
          location_type: locationType
        },
        success: function(resp){
          if (resp && resp.success && resp.data) {
            $card.data('stock-data', resp.data);
            var methodLabel = $card.find('.c2p-ship-card__title').text();
            showStockModal(resp.data, methodLabel);
          }
        }
      });
      
      return false;
    }
    
    $card.siblings('.c2p-ship-card').removeClass('is-selected');
    $card.addClass('is-selected');
    
    state.chosenMethod = methodId;
    
    var action = (type === 'pickup') ? 'update_pickup_method' : 'update_shipping_method';
    var data = (type === 'pickup') ? { pickup_method: methodId } : { shipping_method: methodId };
    
    performAjaxAction(action, data);
  });

  $(document).on('click', '.cwc-qty-minus, .cwc-qty-plus', function(e){
    e.preventDefault();
    var $btn = $(this);
    var $input = $btn.siblings('.cwc-qty-input');
    if (!$input.length) $input = $btn.closest('.cwc-product-item').find('.cwc-qty-input');
    
    var cur = parseInt($input.val(), 10) || 1;
    var min = parseInt($input.attr('min'), 10) || 1;
    var max = parseInt($input.attr('max'), 10) || 999;
    var next = $btn.hasClass('cwc-qty-minus') ? Math.max(cur - 1, min) : Math.min(cur + 1, max);
    
    if (next !== cur) {
      $input.val(next);
      
      var $item = $input.closest('.cwc-product-item');
      var key = $item.data('cart-key');
      
      if (state.quantityTimeout) clearTimeout(state.quantityTimeout);
      
      state.stockCache = {};
      
      state.quantityTimeout = setTimeout(function(){
        debugLog('Atualizando quantidade para ' + next);
        performAjaxAction('update_quantity', {
          cart_item_key: key,
          quantity: next
        });
      }, 800);
    }
  });

  $(document).on('change keyup', '.cwc-qty-input', function(e){
    if (e.type === 'change' || (e.type === 'keyup' && e.keyCode === 13)) {
      var $input = $(this);
      var newVal = parseInt($input.val(), 10);
      var min = parseInt($input.attr('min'), 10) || 1;
      var max = parseInt($input.attr('max'), 10) || 999;
      
      if (isNaN(newVal) || newVal < min) {
        newVal = min;
        $input.val(newVal);
      } else if (newVal > max) {
        newVal = max;
        $input.val(newVal);
      }
      
      var $item = $input.closest('.cwc-product-item');
      var key = $item.data('cart-key');
      
      if (state.quantityTimeout) clearTimeout(state.quantityTimeout);
      
      state.stockCache = {};
      
      if (e.keyCode === 13) {
        performAjaxAction('update_quantity', {
          cart_item_key: key,
          quantity: newVal
        });
      } else {
        state.quantityTimeout = setTimeout(function(){
          performAjaxAction('update_quantity', {
            cart_item_key: key,
            quantity: newVal
          });
        }, 1000);
      }
    }
  });

  $(document).on('click', '.cwc-remove-item', function(e){
    e.preventDefault();
    e.stopPropagation();
    
    var $btn = $(this);
    var $item = $btn.closest('.cwc-product-item');
    var key = $item.data('cart-key');
    var productName = $item.find('.cwc-product-name').text().trim();
    
    if (!key) {
      notices('<div class="woocommerce-error">Erro ao remover item.</div>', 'error');
      return;
    }
    
    if (!confirm('Remover "' + productName + '" do carrinho?')) {
      return;
    }
    
    $item.css('opacity', '0.5');
    $btn.prop('disabled', true);
    
    state.stockCache = {};
    
    performAjaxAction('remove_item', {
      cart_item_key: key
    });
  });

  $(document).on('click', '#apply-coupon, .cwc-apply-coupon', function(e) {
    e.preventDefault();
    
    var $input = $('#coupon-code, .cwc-coupon-input');
    var couponCode = $input.val().trim();
    
    if (!couponCode) {
      notices('<div class="woocommerce-error">Digite um cupom válido.</div>', 'error');
      return;
    }
    
    performAjaxAction('apply_coupon', { coupon: couponCode });
  });

  $(document).on('click', '.cwc-remove-coupon', function(e) {
    e.preventDefault();
    var couponCode = $(this).data('coupon');
    performAjaxAction('remove_coupon', { coupon: couponCode });
  });

  $(document).on('click', '#calculate-shipping-btn, #apply-cep', function(e) {
    e.preventDefault();
    var isMainButton = $(this).is('#calculate-shipping-btn');
    var cepInputSelector = isMainButton ? '#cep-input-main' : '#cep-input';
    var cep = $(cepInputSelector).val();
    performAjaxAction('update_shipping', { postcode: cep });
  });

  $(document).on('click', '#change-cep', function(e) {
    e.preventDefault();
    $('#cep-input-group').slideDown(200);
    $('#cep-input').focus();
  });

  $(document).on('click', '#finalize-checkout', function(e){
    e.preventDefault();
    window.location.href = cwc_ajax.checkout_url;
  });

  (function init(){
    if (typeof console !== 'undefined' && console.log) {
      console.log('[C2P] 🚀 Iniciando v8.8.0...');
      
      if (state.debugMode) {
        console.log('[C2P DEBUG] Modo debug ATIVO - adicione ?c2p_debug=1 na URL');
      }
    }
    
    $('.cwc-delivery-btn').removeClass('active');
    $('.cwc-delivery-btn[data-delivery="' + state.mode + '"]').addClass('active');
    
    if (state.mode === 'home') {
      $('#shipping-calculator, .cwc-shipping-calculator').show();
      $('#cwc-pickup-container').hide();
    } else {
      $('#shipping-calculator, .cwc-shipping-calculator').hide();
      $('#cwc-pickup-container').show();
    }
    
    performAjaxAction('update_shipping', { postcode: '' });
    
    if (typeof console !== 'undefined' && console.log) {
      console.log('[C2P] ✅ v8.8.0 - Recarrega cards + Debug mode!');
    }
  })();

})(jQuery);