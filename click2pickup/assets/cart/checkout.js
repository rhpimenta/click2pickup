/**
 * click2pickup/assets/cart/checkout.js
 * Versão: 3.9.1
 *
 * Mudança pedida:
 * - VOLTAR o prazo (ETA) para o TÍTULO do card (entre parênteses).
 * - REMOVER qualquer "Entrega: sem prazo" (não mostrar nada quando não houver ETA).
 *
 * Mantém:
 * - Cards para envio e retirada.
 * - Alteração de quantidade funcionando.
 * - Sem monkey-patch em $.ajax global.
 * - Sem loops no /checkout/.
 */

(function($){
  'use strict';

  /* ====== GUARD: não roda no /checkout/ (previne loops) ====== */
  var IS_CHECKOUT = $('body').hasClass('woocommerce-checkout') || $('form.checkout').length>0;
  if (IS_CHECKOUT) {
    console.log('[C2P] checkout.js v3.9.1 — ignorado no /checkout/');
    return;
  }

  /* ====== Estado ====== */
  var state = {
    mode: (window.cwc_ajax && cwc_ajax.delivery_mode) || 'home', // 'home' | 'store'
    isUpdatingQty: false,
    isProcessing: false,
    lastShippingMethods: null,   // array vindo do backend
    pickupReady: false,          // já temos opções de retirada válidas?
    etaIndex: null               // índice de prazos
  };

  /* ====== Helpers UI ====== */
  var $spinner = $('#cwc-loading');
  function showLoading(){ $spinner.fadeIn(150); }
  function hideLoading(){ $spinner.fadeOut(150); }

  function notices(html){
    if(!html) return;
    var $wrap=$('.woocommerce-notices-wrapper');
    if($wrap.length) $wrap.html(html).show();
    else ($('.woocommerce').first().length?$('.woocommerce').first():$('body')).prepend(html);
  }

  function updateTotals(data){
    if(!data) return;
    if (data.subtotal) $('.cwc-subtotal').html(data.subtotal);
    if (data.shipping){
      $('.cwc-shipping, .cwc-shipping-value, .shipping-total').html(data.shipping);
    }
    if (data.discount) $('.cwc-discount-value').html('-'+data.discount);
    if (data.total){
      $('.cwc-total-value, .order-total').html(data.total);
    }
  }
  window.updatePrices = updateTotals; // compat

  function setModeUI(mode){
    state.mode = (mode==='store')?'store':'home';
    $('.cwc-delivery-btn.cwc-pickup').toggleClass('active', state.mode==='home');
    $('.cwc-delivery-btn.cwc-store').toggleClass('active',  state.mode==='store');

    var $ship = $('#shipping-calculator,.cwc-shipping-calculator');
    var $pick = $('#pickup-selector').length ? $('#pickup-selector') : $('#cwc-pickup-container');

    if ($ship.length) $ship.stop(true,true).css('display', state.mode==='home' ? '' : 'none');
    if ($pick.length) $pick.stop(true,true).css('display', state.mode==='store'? '' : 'none');
  }

  /* ====== CSS dos cards ====== */
  function ensureCardStyles(){
    if (document.getElementById('c2p-ship-card-styles')) return;
    var css = `
      .c2p-ship-hidden{display:none!important}
      .c2p-ship-cards-wrapper{margin-top:10px}
      .c2p-ship-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:10px 0 4px}
      .c2p-ship-card{display:flex;align-items:flex-start;gap:12px;padding:14px;border:1px solid #e5e7eb;border-radius:12px;cursor:pointer;background:#fff;box-shadow:0 1px 0 rgba(16,24,40,.02);transition:box-shadow .15s ease,border-color .15s ease}
      .c2p-ship-card:hover{border-color:#d1d5db;box-shadow:0 2px 6px rgba(16,24,40,.06)}
      .c2p-ship-card.is-selected{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
      .c2p-ship-card input.c2p-ship-radio{position:absolute;opacity:0;pointer-events:none}
      .c2p-ship-card__icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#f3f4f6;flex:0 0 36px}
      .c2p-ship-card__body{display:grid;gap:4px;min-width:0}
      .c2p-ship-card__badge{display:inline-block;font-size:11px;line-height:1;padding:6px 8px;border-radius:999px;background:#f1f5f9;color:#0f172a;font-weight:600;letter-spacing:.2px}
      .c2p-ship-card__title{font-weight:600;font-size:15px;color:#111827}
      .c2p-ship-placeholder{padding:14px;border:1px dashed #e5e7eb;border-radius:12px;background:#fafafa;color:#6b7280}
      .c2p-ship-loading{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:10px 0 4px}
      .c2p-ship-skeleton{padding:14px;border:1px solid #eee;border-radius:12px;background:linear-gradient(90deg,#f3f4f6 25%,#e5e7eb 37%,#f3f4f6 63%);background-size:400% 100%;animation:c2pShimmer 1.2s infinite}
      @keyframes c2pShimmer{0%{background-position:100% 0}100%{background-position:0 0}}
      @media (max-width:440px){.c2p-ship-cards{grid-template-columns:1fr}.c2p-ship-loading{grid-template-columns:1fr}}
    `.trim();
    $('<style id="c2p-ship-card-styles"/>').text(css).appendTo(document.head);
  }

  function iconTruck(){ return '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v7h2.5l3-4H18V7h3l2 3v7h-3a3 3 0 1 1-6 0H9a3 3 0 1 1-6 0H0V7h3zm3 10a1.5 1.5 0 1 0 0 .01V17zm12 0a1.5 1.5 0 1 0 0 .01V17z"/></svg>'; }
  function iconStore(){ return '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16l1 5H3l1-5zm-1 7h18v9H3v-9zm5 2v5h2v-5H8zm5 0v5h2v-5h-2z"/></svg>'; }

  /* ====== Selects / Snapshots ====== */
  var SNAP_KEY='_c2pSnapshotHtml';
  function saveSnapshot($sel){ if($sel&&$sel.length) $sel.data(SNAP_KEY,$sel.html()); }
  function restoreSnapshot($sel){ if(!$sel||!$sel.length) return; var h=$sel.data(SNAP_KEY); if(typeof h==='string') $sel.html(h); }
  function hasOptions($sel){ return $sel && $sel.length && $sel.find('option').length>0; }

  function shippingSelect(){ return $('#shipping-method-select'); }
  function pickupAnchor(){
    var $c=$('.cwc-pickup-selector'); if($c.length) return $c;
    $c=$('#cwc-pickup-options'); if($c.length) return $c;
    $c=$('#cwc-pickup-container'); if($c.length) return $c;
    return $('#pickup-selector');
  }
  function pickupSelect(){
    var $sel=$('#pickup-method-select'); if($sel.length) return $sel;
    var $a=pickupAnchor(); if($a.length){ var $inner=$a.find('select').first(); if($inner.length) return $inner; }
    return $sel;
  }

  /* ====== ETA ====== */
  var ETA_KEYS = ['prazo_correios','prazo_jadlog','prazo_jamef','_delivery_forecast','prazo','delivery_time','eta','lead_time'];

  function formatETA(raw){
    if(raw==null) return '';
    var s=String(raw).trim();
    if(!s) return '';
    if(/^\d+$/.test(s)){
      var n=parseInt(s,10);
      if(!isNaN(n) && n>0) return n+' '+(n===1?'dia útil':'dias úteis');
    }
    return s.replace(/uteis/ig,'úteis');
  }
  function normalizeLabel(s){
    if(!s) return '';
    s=s.toLowerCase();
    if(s.normalize) s=s.normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    return s.replace(/\s+/g,' ').trim();
  }
  function shortId(val){ val=String(val||''); var p=val.indexOf(':'); return p>0?val.slice(0,p):val; }
  function buildEtaIndex(methods){
    var idx={byId:{},byShortId:{},byMethodId:{},byLabel:{}};
    (methods||[]).forEach(function(m){
      var eta='';
      for(var i=0;i<ETA_KEYS.length;i++){ if(m[ETA_KEYS[i]]){ eta=String(m[ETA_KEYS[i]]).trim(); if(eta) break; } }
      if(!eta) return;
      var fmt=formatETA(eta);
      if(m.id) idx.byId[m.id]=fmt;
      var sid=shortId(m.id||''); if(sid) idx.byShortId[sid]=idx.byShortId[sid]||fmt;
      if(m.method_id) idx.byMethodId[m.method_id]=idx.byMethodId[m.method_id]||fmt;
      if(m.label) idx.byLabel[normalizeLabel(m.label)]=idx.byLabel[normalizeLabel(m.label)]||fmt;
    });
    state.etaIndex = idx;
  }
  function inferMethodIdFromOption($opt){
    var m=$opt.data('method-id'); if(m) return String(m);
    var val=String($opt.attr('value')||'').toLowerCase();
    if(val.indexOf('local_pickup')!==-1) return 'local_pickup';
    var p=val.indexOf(':'); if(p>0) return val.substring(0,p);
    var t=String($opt.text()||'').toLowerCase();
    if(/retirar|retirada|loja/.test(t)) return 'local_pickup';
    return 'flat_rate';
  }
  function isPickup(methodId){ return String(methodId||'').indexOf('local_pickup')===0; }
  function etaFromOption($opt){
    // 1) data-*
    for(var i=0;i<ETA_KEYS.length;i++){
      var k=ETA_KEYS[i], dk=k.replace(/_/g,'-');
      var v=$opt.data(k) || $opt.data(dk);
      if(v){ v=String(v).trim(); if(v) return formatETA(v); }
    }
    // 2) índices
    var val=String($opt.attr('value')||''),
        lbl=$.trim($opt.text()),
        methodId=inferMethodIdFromOption($opt),
        sid=shortId(val),
        key=normalizeLabel(lbl),
        idx=state.etaIndex||{};
    if(idx.byId && idx.byId[val]) return idx.byId[val];
    if(idx.byShortId && idx.byShortId[sid]) return idx.byShortId[sid];
    if(idx.byMethodId && idx.byMethodId[methodId]) return idx.byMethodId[methodId];
    if(idx.byLabel && idx.byLabel[key]) return idx.byLabel[key];

    // 3) regex "(...)" no fim
    var m=lbl.match(/\(([^()]+)\)\s*$/);
    if(m && m[1] && !/R\$\s?[\d.,]+/i.test(m[1]) && /(dia|dias|uteis|úteis|entrega|hora|horas|business|útil)/i.test(m[1])){
      return formatETA(m[1]);
    }
    return '';
  }
  function stripEtaSuffix(text){
    // remove apenas um sufixo (...), caso indique prazo/tempo
    var t=String(text||'');
    var m=t.match(/\(([^()]+)\)\s*$/);
    if(!m) return t;
    var inside=m[1];
    if(/R\$\s?[\d.,]+/i.test(inside)) return t; // preço, mantém
    if(/dia|dias|uteis|úteis|entrega|hora|horas|business|útil/i.test(inside)){
      return t.replace(/\s*\([^()]+\)\s*$/, '').trim();
    }
    return t;
  }

  /* ====== Cards ====== */
  function wrap($anchor,cls){
    var $w=$anchor.find('.'+cls);
    if(!$w.length){ $w=$('<div class="c2p-ship-cards-wrapper '+cls+'"></div>').appendTo($anchor); }
    return $w;
  }
  function loading($anchor,cls){
    ensureCardStyles();
    wrap($anchor,cls).html('<div class="c2p-ship-loading"><div class="c2p-ship-skeleton"></div><div class="c2p-ship-skeleton"></div></div>');
  }
  function placeholder($anchor,cls,msg){
    ensureCardStyles();
    wrap($anchor,cls).html('<div class="c2p-ship-placeholder">'+(msg||'Nenhuma opção disponível para este modo.')+'</div>');
  }
  function buildCard($opt, pkgIndex, selected, mode){
    var methodId=inferMethodIdFromOption($opt);
    var pickup=isPickup(methodId);
    var icon = pickup?iconStore():iconTruck();
    var badge= pickup?'Retirar na loja':'Receber em casa';

    var rawLabel = $.trim($opt.text());
    var baseLabel = stripEtaSuffix(rawLabel); // limpa ETA antigo do texto, se houver
    var title = baseLabel;

    // HOME: se houver ETA, coloca no TÍTULO entre parênteses; se não houver, não mostra nada
    if(!pickup && mode==='home'){
      var eta=etaFromOption($opt);
      if(eta){ title = baseLabel + ' (' + $('<div/>').text(eta).html() + ')'; }
    }

    var value=String($opt.attr('value')||'');
    var name='shipping_method['+pkgIndex+']';

    return ''+
      '<label class="c2p-ship-card'+(selected?' is-selected':'')+'" data-mode="'+mode+'" data-package-index="'+pkgIndex+'" data-value="'+$('<div/>').text(value).html()+'">'+
        '<input type="radio" class="c2p-ship-radio" name="'+name+'" value="'+$('<div/>').text(value).html()+'" '+(selected?'checked':'')+' />'+
        '<div class="c2p-ship-card__icon">'+icon+'</div>'+
        '<div class="c2p-ship-card__body">'+
          '<div class="c2p-ship-card__badge">'+badge+'</div>'+
          '<div class="c2p-ship-card__title">'+title+'</div>'+
        '</div>'+
      '</label>';
  }
  function renderFromSelect($select,$anchor,mode,cls,onlyPickup){
    ensureCardStyles();
    if(!$anchor||!$anchor.length){ return; }

    if(mode==='store' && !state.pickupReady){
      loading($anchor,cls);
      return;
    }
    if(!$select || !$select.length || !hasOptions($select)){
      if(mode==='store' && state.pickupReady){
        placeholder($anchor,cls,'Nenhuma opção disponível para retirada nesta loja.');
      } else {
        loading($anchor,cls);
      }
      return;
    }

    // esconder select de origem (sem perder dados)
    if(typeof $select.data(SNAP_KEY)==='undefined') saveSnapshot($select);
    $select.addClass('c2p-ship-hidden');

    var $wrap = wrap($anchor,cls);
    var $cards = $wrap.find('.c2p-ship-cards');
    if(!$cards.length){
      $cards = $('<div class="c2p-ship-cards" data-select-id="'+($select.attr('id')||'')+'" data-mode="'+mode+'"></div>').appendTo($wrap.empty());
    }else{
      $cards.attr('data-select-id',($select.attr('id')||'')).attr('data-mode',mode).empty();
    }

    var selectedVal = $select.val(), has=false;
    $select.find('option').each(function(){
      var $o=$(this);
      var mId=inferMethodIdFromOption($o);
      var isPick=isPickup(mId);
      if      (onlyPickup===true  && !isPick) return;
      else if (onlyPickup===false &&  isPick) return;
      var sel=(String($o.attr('value')||'')===String(selectedVal));
      $cards.append(buildCard($o,0,sel,mode));
      has=true;
    });

    if(!has){
      if(mode==='store') placeholder($anchor,cls,'Nenhuma opção disponível para retirada nesta loja.');
      else placeholder($anchor,cls,'Nenhuma opção disponível para este modo.');
    }
  }

  function renderDelivery(){
    var $sel = shippingSelect();
    var $anchor = $('.cwc-shipping-select-wrapper');
    if(!$anchor.length) $anchor = $sel.parent();
    renderFromSelect($sel,$anchor,'home','c2p-delivery-cards',false);
  }
  function renderPickup(){
    var $sel = pickupSelect();
    var $anchor = pickupAnchor();
    if(!$anchor.length) return;
    renderFromSelect($sel,$anchor,'store','c2p-pickup-cards',true);
  }

  /* ====== Parser do pickup_html (quando backend envia) ====== */
  function injectPickupHtmlAndSynthesize(pickup_html){
    var $anchor=pickupAnchor();
    if(!$anchor.length) return false;

    $('#pickup-selector').remove();
    $('.cwc-delivery-options').after(pickup_html);

    // Recoleta após injeção
    $anchor = pickupAnchor();

    // Já existe <select> com options?
    var $sel = $anchor.find('select').first();
    if($sel.length && $sel.find('option').length){
      if(!$sel.attr('id')) $sel.attr('id','pickup-method-select');
      state.pickupReady = true;
      return true;
    }

    // Procura radios padrão
    var $radios = $anchor.find('input[type=radio][name^="shipping_method"], input[type=radio][value*="local_pickup"]');
    if($radios.length){
      var html = '<select id="pickup-method-select" class="c2p-hidden-origin">';
      $radios.each(function(i,el){
        var $r=$(el);
        var val=$r.val() || ('local_pickup:'+i);
        var $lbl=$r.closest('li,label').find('label').first();
        var label=($.trim($lbl.text())||('Retirada #'+(i+1)));
        html+='<option value="'+val+'" data-method-id="local_pickup" '+($r.prop('checked')?'selected':'')+'>'+label+'</option>';
      });
      html+='</select>';
      $anchor.append(html);
      state.pickupReady = true;
      return true;
    }

    state.pickupReady = false;
    return false;
  }

  /* ====== Sincronização com backend ====== */
  function syncModeWithServer(targetMode, done){
    $.ajax({
      url:(window.cwc_ajax||{}).ajax_url,
      type:'POST',
      data:{ action:'cwc_change_delivery_method', nonce:(window.cwc_ajax||{}).nonce, method: targetMode },
      success:function(resp){
        try{
          if(resp && resp.success){
            updateTotals(resp.data);
            if (resp.data && resp.data.notices_html) notices(resp.data.notices_html);

            if (targetMode==='home'){
              if (Array.isArray(resp.data.shipping_methods)){
                state.lastShippingMethods = resp.data.shipping_methods.slice();
                buildEtaIndex(state.lastShippingMethods);

                var $sel = shippingSelect();
                if($sel.length){
                  var html=''; var selected=null;
                  $.each(state.lastShippingMethods, function(i,m){
                    html+='<option value="'+(m.id||'')+'" data-method-id="'+(m.method_id||'')+'" data-cost="'+(m.cost??'')+'"';
                    ETA_KEYS.forEach(function(k){
                      if(m[k]){
                        var attr=k.replace(/_/g,'-');
                        html+=' data-'+attr+'="'+$('<div/>').text(String(m[k])).html()+'"';
                      }
                    });
                    if(m.selected || (!selected && i===0)){ html+=' selected'; selected = m.id; }
                    html+='>';
                    html+=(m.label||m.id||('Método '+(i+1)));
                    if(m.cost==0 || m.is_free || m.method_id==='free_shipping') html+=' - Grátis';
                    else if(m.cost_display) html+=' - '+m.cost_display;
                    // NÃO adicionamos ETA aqui no texto da option; ETA vai no título do CARD
                    html+='</option>';
                  });
                  $sel.html(html);
                  saveSnapshot($sel);
                }
              }
              renderDelivery();
            } else {
              if (resp.data && resp.data.pickup_html){
                injectPickupHtmlAndSynthesize(resp.data.pickup_html);
              } else {
                var $ps=pickupSelect();
                state.pickupReady = ($ps.length && hasOptions($ps));
              }
              renderPickup();
            }
          }
        }catch(e){}
        if (typeof done==='function') done();
      },
      error:function(){ if (typeof done==='function') done(); }
    });
  }

  /* ====== Eventos: alternância dos botões HOME/STORE ====== */
  $(document).on('click','.cwc-delivery-btn',function(e){
    e.preventDefault();
    var target = $(this).data('delivery')==='store' ? 'store' : 'home';
    setModeUI(target);
    syncModeWithServer(target);
  });

  /* ====== Clique nos cards ====== */
  $(document).on('click','.c2p-ship-card',function(e){
    e.preventDefault();
    var $card=$(this);
    var mode=$card.data('mode');
    var value=String($card.data('value')||'');
    var $group=$card.closest('.c2p-ship-cards');
    var selectId=$group.data('select-id');
    var $sel = selectId ? $('#'+selectId) : (mode==='home'? shippingSelect() : pickupSelect());

    $group.find('.c2p-ship-card').removeClass('is-selected');
    $card.addClass('is-selected');

    if($sel && $sel.length){
      restoreSnapshot($sel);
      $sel.val(value).trigger('change');
    }
  });

  /* ====== Change: ENVIO ====== */
  $(document).on('change','#shipping-method-select',function(){
    var $sel=$(this);
    var selected=$sel.val();
    var $opt=$sel.find('option:selected');
    var cost=parseFloat($opt.data('cost'))||0;

    if(cost===0) $('.cwc-shipping').html('<span class="cwc-free-shipping">Grátis</span>');

    showLoading();
    $.ajax({
      url:(cwc_ajax||{}).ajax_url, type:'POST',
      data:{ action:'cwc_update_shipping_method', nonce:(cwc_ajax||{}).nonce, shipping_method:selected },
      success:function(resp){
        if(resp && resp.success){
          updateTotals(resp.data);
          if(resp.data && resp.data.notices_html) notices(resp.data.notices_html);

          // Se o backend devolveu shipping_methods atualizados, reindexa ETA
          if (Array.isArray(resp.data.shipping_methods)){
            state.lastShippingMethods = resp.data.shipping_methods.slice();
            buildEtaIndex(state.lastShippingMethods);
          }

          // Re-renderiza os CARDS para reavaliar títulos com ETA
          renderDelivery();
        }
      },
      complete:function(){ hideLoading(); }
    });
  });

  /* ====== Change: RETIRADA ====== */
  $(document).on('change','#pickup-method-select',function(){
    var selected=$(this).val();
    if(!selected) return;
    $.ajax({
      url:(cwc_ajax||{}).ajax_url, type:'POST',
      data:{ action:'cwc_update_pickup_method', nonce:(cwc_ajax||{}).nonce, pickup_method:selected },
      success:function(resp){
        if(resp && resp.success){
          updateTotals(resp.data);
          if(resp.data && resp.data.notices_html) notices(resp.data.notices_html);
        }
      }
    });
  });

  /* ====== Quantidade ====== */
  $(document).on('click','.cwc-qty-minus, .cwc-qty-plus',function(e){
    e.preventDefault();
    var $btn=$(this);
    var $input=$btn.siblings('.cwc-qty-input');
    if(!$input.length) $input=$btn.closest('.cwc-product-item').find('.cwc-qty-input');
    var cur=parseInt($input.val(),10)||1;
    var min=parseInt($input.attr('min'),10)||1;
    var max=parseInt($input.attr('max'),10)||999;
    var next = $btn.hasClass('cwc-qty-minus') ? Math.max(cur-1,min) : Math.min(cur+1,max);
    if(next!==cur) $input.val(next).trigger('change');
  });

  $(document).on('change','.cwc-qty-input',function(){
    var $input=$(this);
    var $item=$input.closest('.cwc-product-item');
    var key=$item.data('cart-key');
    var qty=parseInt($input.val(),10)||1;

    if(state.isUpdatingQty) return;
    state.isUpdatingQty=true;
    showLoading();

    $.ajax({
      url:(cwc_ajax||{}).ajax_url, type:'POST',
      data:{ action:'cwc_update_quantity', nonce:(cwc_ajax||{}).nonce, cart_item_key:key, quantity:qty },
      success:function(resp){
        if(resp && resp.success){
          if(resp.data && resp.data.line_total) $item.find('.cwc-product-total').html(resp.data.line_total);
          updateTotals(resp.data);

          // Atualiza métodos de envio (re-render cards) caso backend recalcule frete
          if(state.mode==='home' && Array.isArray(resp.data.shipping_methods)){
            state.lastShippingMethods = resp.data.shipping_methods.slice();
            buildEtaIndex(state.lastShippingMethods);

            var $sel = shippingSelect();
            if($sel.length){
              var html=''; var selected=null;
              $.each(state.lastShippingMethods,function(i,m){
                html+='<option value="'+(m.id||'')+'" data-method-id="'+(m.method_id||'')+'" data-cost="'+(m.cost??'')+'"';
                ETA_KEYS.forEach(function(k){
                  if(m[k]){ var a=k.replace(/_/g,'-'); html+=' data-'+a+'="'+$('<div/>').text(String(m[k])).html()+'"'; }
                });
                if(m.selected || (!selected && i===0)){ html+=' selected'; selected=m.id; }
                html+='>';
                html+=(m.label||m.id||('Método '+(i+1)));
                if(m.cost==0 || m.is_free || m.method_id==='free_shipping') html+=' - Grátis';
                else if(m.cost_display) html+=' - '+m.cost_display;
                html+='</option>';
              });
              $sel.html(html);
              renderDelivery(); // re-render cards (ETA no título)
            }
          }
        }
      },
      complete:function(){ hideLoading(); state.isUpdatingQty=false; }
    });
  });

  $(document).on('blur','.cwc-qty-input',function(){
    var $i=$(this);
    var v=parseInt($i.val(),10)||1;
    var min=parseInt($i.attr('min'),10)||1;
    var max=parseInt($i.attr('max'),10)||999;
    if(v<min){ $i.val(min).trigger('change'); }
    else if(v>max){ $i.val(max).trigger('change'); }
  });
  $(document).on('keypress','.cwc-qty-input',function(e){
    var c=e.which?e.which:e.keyCode;
    if(c>31 && (c<48||c>57)) return false;
    return true;
  });

  /* ====== CEP (área calculada) ====== */
  var CEP_PANEL_OPENING=false;
  $(document).on('click','#change-cep',function(e){
    e.preventDefault(); e.stopPropagation();
    var $grp=$('#cep-input-group'), $inp=$('#cep-input');
    if($grp.is(':visible')){ $grp.slideUp(150); $(this).text('Alterar'); }
    else {
      $grp.slideDown(150,function(){
        $inp.focus();
        var cur=$('#current-cep').text().trim();
        if(cur && cur!=='00000-000') $inp.val(cur);
      });
      $(this).text('Cancelar');
      CEP_PANEL_OPENING=true; setTimeout(function(){ CEP_PANEL_OPENING=false; }, 120);
    }
  });
  $(document).on('click',function(e){
    if(CEP_PANEL_OPENING) return;
    var inside = $(e.target).closest('.cwc-shipping-calculator, #shipping-calculator, .cwc-shipping-select-wrapper, #cep-input-group, #change-cep').length>0;
    if(!inside){
      var $grp=$('#cep-input-group');
      if($grp.is(':visible')){ $grp.slideUp(150); $('#change-cep').text('Alterar'); }
    }
  });
  $(document).on('click','#cep-input-group',function(e){ e.stopPropagation(); });

  $(document).on('click','#apply-cep',function(e){
    e.preventDefault(); e.stopPropagation();
    var cep=$('#cep-input').val().replace(/\D/g,'');
    if(cep.length!==8){ $('#cep-input').addClass('error').focus(); return; }
    state.isProcessing=true;
    $('#cep-input').removeClass('error');
    var $btn=$(this), txt=$btn.text();
    $btn.prop('disabled',true).text('Aplicando...');
    $.ajax({
      url:(cwc_ajax||{}).ajax_url, type:'POST',
      data:{ action:'cwc_update_shipping', nonce:(cwc_ajax||{}).nonce, postcode:cep, country:'BR' },
      success:function(resp){
        if(resp && resp.success){
          var f = resp.data.formatted_cep || (cep.substring(0,5)+'-'+cep.substring(5));
          $('#current-cep').text(f);
          $('#cep-input-group').slideUp(150); $('#change-cep').text('Alterar'); $('#cep-input').val('').removeClass('error');
          updateTotals(resp.data);
          if(resp.data && resp.data.notices_html) notices(resp.data.notices_html);

          if(Array.isArray(resp.data.shipping_methods)){
            state.lastShippingMethods = resp.data.shipping_methods.slice();
            buildEtaIndex(state.lastShippingMethods);

            var $sel=shippingSelect();
            if($sel.length){
              var html=''; var selected=null;
              $.each(state.lastShippingMethods,function(i,m){
                html+='<option value="'+(m.id||'')+'" data-method-id="'+(m.method_id||'')+'" data-cost="'+(m.cost??'')+'"';
                ETA_KEYS.forEach(function(k){
                  if(m[k]){ var a=k.replace(/_/g,'-'); html+=' data-'+a+'="'+$('<div/>').text(String(m[k])).html()+'"'; }
                });
                if(m.selected || (!selected && i===0)){ html+=' selected'; selected=m.id; }
                html+='>';
                html+=(m.label||m.id||('Método '+(i+1)));
                if(m.cost==0 || m.is_free || m.method_id==='free_shipping') html+=' - Grátis';
                else if(m.cost_display) html+=' - '+m.cost_display;
                html+='</option>';
              });
            }
            renderDelivery(); // cards recalculam títulos com ETA
          } else {
            syncModeWithServer('home');
          }
        } else {
          $('#cep-input').addClass('error').focus();
          if(resp && resp.data && resp.data.notices_html) notices(resp.data.notices_html);
        }
      },
      complete:function(){ $btn.prop('disabled',false).text(txt); setTimeout(function(){ state.isProcessing=false; }, 200); }
    });
  });

  // CEP inicial (quando o bloco "calcular frete" aparece)
  $(document).on('click','#calculate-shipping-btn',function(e){
    e.preventDefault();
    var cep=$('#cep-input-main').val().replace(/\D/g,'');
    if(cep.length!==8){ $('#cep-input-main').addClass('error').focus(); return; }
    state.isProcessing=true;
    $('.cwc-cep-required-state').addClass('cwc-cep-loading');
    var $btn=$(this), txt=$btn.text();
    $btn.prop('disabled',true).text('Calculando...');
    try{ localStorage.setItem('cwc_last_cep', cep); }catch(e){}
    $.ajax({
      url:(cwc_ajax||{}).ajax_url, type:'POST',
      data:{ action:'cwc_update_shipping', nonce:(cwc_ajax||{}).nonce, postcode:cep, country:'BR' },
      success:function(resp){
        if(resp && resp.success){ location.reload(); }
        else{
          $('#cep-input-main').addClass('error').focus();
          if(resp && resp.data && resp.data.notices_html) notices(resp.data.notices_html);
        }
      },
      error:function(){ $('#cep-input-main').addClass('error').focus(); },
      complete:function(){
        $('.cwc-cep-required-state').removeClass('cwc-cep-loading');
        $btn.prop('disabled',false).text(txt);
        setTimeout(function(){ state.isProcessing=false; },200);
      }
    });
  });

  $(document).on('keypress','#cep-input, #cep-input-main',function(e){
    if(e.which===13){
      e.preventDefault();
      if($(this).is('#cep-input')) $('#apply-cep').click();
      else $('#calculate-shipping-btn').click();
    }
  });
  $(document).on('input','#cep-input, #cep-input-main',function(){
    var v=$(this).val().replace(/\D/g,'');
    if(v.length>5) v=v.substring(0,5)+'-'+v.substring(5,8);
    $(this).val(v);
    if($(this).hasClass('error') && v.length>0) $(this).removeClass('error');
  });

  /* ====== Finalizar compra (marca opção e segue) ====== */
  $(document).on('click','#finalize-checkout',function(e){
    e.preventDefault();
    var checkoutUrl = (window.cwc_ajax && cwc_ajax.checkout_url) ||
                      ($('a.checkout-button[href]').attr('href')) || '/checkout/';

    var mode = state.mode;
    var $sel = mode==='home' ? shippingSelect() : pickupSelect();
    var selected = $sel.length ? $sel.val() : null;

    if(selected){
      var action = (mode==='home') ? 'cwc_update_shipping_method' : 'cwc_update_pickup_method';
      var payload = { action: action, nonce:(cwc_ajax||{}).nonce };
      if(mode==='home') payload.shipping_method = selected;
      else payload.pickup_method = selected;

      $.ajax({ url:(cwc_ajax||{}).ajax_url, type:'POST', data:payload, complete:function(){
        window.location.href = checkoutUrl;
      }});
    } else {
      window.location.href = checkoutUrl;
    }
  });

  /* ====== Inicialização ====== */
  (function init(){
    ensureCardStyles();
    setModeUI(state.mode);

    var $shipSel = shippingSelect();
    if ($shipSel.length && hasOptions($shipSel)) {
      // constrói índice ETA a partir do DOM (data-attrs) como fallback
      var domMethods=[];
      $shipSel.find('option').each(function(){
        var $o=$(this), obj={ id:$o.val(), method_id:($o.data('method-id')||''), label:$.trim($o.text()) };
        ETA_KEYS.forEach(function(k){ var dk=k.replace(/_/g,'-'); var v=$o.data(k)||$o.data(dk); if(v) obj[k]=v; });
        domMethods.push(obj);
      });
      buildEtaIndex(domMethods);
      renderDelivery();
    } else {
      // força backend a devolver shipping_methods
      syncModeWithServer('home');
    }

    if (state.mode==='store'){
      var $ps=pickupSelect();
      state.pickupReady = ($ps.length && hasOptions($ps));
      if(!state.pickupReady){
        syncModeWithServer('store'); // backend deve devolver pickup_html
      } else {
        renderPickup();
      }
    }

    var hasCEP = $('#current-cep').text()!=='00000-000' && $('#current-cep').text()!=='';
    if(!hasCEP && state.mode==='home'){ $('.cwc-cep-required-state').show(); $('.cwc-cep-calculated-state').hide(); }
    else { $('.cwc-cep-required-state').hide(); $('.cwc-cep-calculated-state').show(); }

    console.log('[C2P] checkout.js v3.9.1 — ETA no título, sem "Entrega: sem prazo" — mode:', state.mode);
  })();

})(jQuery);
