jQuery(function($){
  var I18N = (window.C2P_LOC && C2P_LOC.i18n) ? C2P_LOC.i18n : {};

  // ====== Media selector ======
  $(document).on('click','.c2p-media-select',function(e){
    e.preventDefault();
    var frame = wp.media({
      title: I18N.media_title || 'Selecionar imagem',
      button:{text: I18N.media_button || 'Usar imagem'},
      multiple:false
    });
    var wrap = $(this).closest('.c2p-media');
    frame.on('select', function(){
      var att = frame.state().get('selection').first().toJSON();
      wrap.find('input[type=hidden]').val(att.id);
      wrap.find('img').attr('src',att.url).show();
    });
    frame.open();
  });
  $(document).on('click','.c2p-media-remove',function(e){
    e.preventDefault();
    var wrap = $(this).closest('.c2p-media');
    wrap.find('input[type=hidden]').val('');
    wrap.find('img').hide();
  });

  // ====== Horário 00:00 com clamp 23:59 ======
  function clampTimeDigits(d){
    d = String(d||'').replace(/[^0-9]/g,'').slice(0,4);
    if(d.length < 3) return d;
    var H = parseInt(d.slice(0,2)||'0',10);
    var M = parseInt(d.slice(2,4)||'0',10);
    if(H > 23) H = 23;
    if(M > 59) M = 59;
    return ('0'+H).slice(-2)+':'+('0'+M).slice(-2);
  }
  $(document).on('input','.c2p-time',function(){
    var d = $(this).val().replace(/[^0-9]/g,'').slice(0,4);
    $(this).val( d.length>=3 ? clampTimeDigits(d) : d );
  });
  $(document).on('blur','.c2p-time',function(){
    var v = $(this).val();
    var m = /^(\d{1,2}):?(\d{2})$/.exec(v) || /^(\d{1,2})(\d{2})$/.exec(v);
    if(m){ $(this).val( clampTimeDigits(m[1]+m[2]) ); }
  });

  // ====== Telefone BR (xx)xxxxx-xxxx ou (xx)xxxx-xxxx ======
  $(document).on('input','.c2p-phone',function(){
    var d = $(this).val().replace(/\D/g,'').substring(0,11);
    var p;
    if(d.length <= 10){
      p = d.replace(/^(\d{0,2})(\d{0,4})(\d{0,4}).*/, function(m,a,b,c){
        var s=''; if(a) s+='('+a; if(a.length===2) s+=')'; if(b){ s+=''+b; if(b.length===4) s+='-'; } if(c) s+=c; return s;
      });
    } else {
      p = d.replace(/^(\d{0,2})(\d{0,5})(\d{0,4}).*/, function(m,a,b,c){
        var s=''; if(a) s+='('+a; if(a.length===2) s+=')'; if(b){ s+=''+b; if(b.length===5) s+='-'; } if(c) s+=c; return s;
      });
    }
    $(this).val(p);
  });
  $(document).on('blur','.c2p-phone',function(){
    var v = $(this).val().replace(/\s+/g,'');
    var ok = /^\(\d{2}\)\d{4,5}\-\d{4}$/.test(v);
    if(v!=='' && !ok){
      $(this).addClass('c2p-invalid');
      if($('.c2p-phone-error').length===0){
        $('<div class="notice notice-error c2p-phone-error"><p>'+(I18N.invalidPhone||'Telefone inválido. Use (xx)xxxxx-xxxx ou (xx)xxxx-xxxx.')+'</p></div>').insertBefore('.wrap h1:first');
      }
    } else {
      $(this).removeClass('c2p-invalid');
      $('.c2p-phone-error').remove();
    }
  });

  // ====== E-mail simples ======
  function isEmail(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
  $(document).on('blur','.c2p-email',function(){
    var v=$(this).val().trim();
    if(v!=='' && !isEmail(v)){
      $(this).addClass('c2p-invalid');
      if($('.c2p-email-error').length===0){
        $('<div class="notice notice-error c2p-email-error"><p>'+(I18N.invalidEmail||'E-mail inválido. Corrija para salvar.')+'</p></div>').insertBefore('.wrap h1:first');
      }
    } else {
      $(this).removeClass('c2p-invalid');
      $('.c2p-email-error').remove();
    }
  });

  // ====== Replicar horários (Segunda -> demais) ======
  $(document).on('click','#c2p-copy-mon', function(e){
    e.preventDefault();
    var open  = $('input[name="c2p_hours_weekly[mon][open]"]').val();
    var close = $('input[name="c2p_hours_weekly[mon][close]"]').val();
    var closed = $('input[name="c2p_hours_weekly[mon][closed]"]').is(':checked');
    ['tue','wed','thu','fri','sat','sun'].forEach(function(d){
      $('input[name="c2p_hours_weekly['+d+'][open]"]').val(open);
      $('input[name="c2p_hours_weekly['+d+'][close]"]').val(close);
      $('input[name="c2p_hours_weekly['+d+'][closed]"]').prop('checked', closed);
    });
  });

  // ====== Editar cards (Preparo / Cutoff) ======
  $(document).on('click','.c2p-edit-prep',function(e){
    e.preventDefault();
    var card = $(this).closest('.c2p-prep-card');
    card.attr('data-readonly','0');
    card.find('input').prop('readonly',false).removeClass('c2p-ro');
    $(this).remove();
  });
  $(document).on('click','.c2p-edit-cutoff',function(e){
    e.preventDefault();
    var card = $(this).closest('.c2p-cutoff-card');
    card.attr('data-readonly','0');
    card.find('input').prop('readonly',false).removeClass('c2p-ro');
    $(this).remove();
  });

  // ====== Dias Especiais: adicionar / editar / remover ======
  function nextSpecialIndex(){
    var max = -1;
    $('#c2p-special-days .c2p-special-item').each(function(){
      var idx = parseInt($(this).attr('data-index')||'-1',10);
      if(!isNaN(idx) && idx>max) max = idx;
    });
    return max + 1;
  }

  $(document).on('click','#c2p-add-special',function(e){
    e.preventDefault();
    var i = nextSpecialIndex();
    var html =
      '<div class="c2p-special-item" data-index="'+i+'">' +
      '<input type="text" name="c2p_hours_special['+i+'][date_br]" value="" class="c2p-date" placeholder="dd/mm/aaaa" /> '+
      '<input type="text" name="c2p_hours_special['+i+'][open]" value="" class="c2p-time" placeholder="00:00" /> '+
      '<input type="text" name="c2p_hours_special['+i+'][close]" value="" class="c2p-time" placeholder="00:00" /> '+
      '<input type="text" name="c2p_hours_special['+i+'][desc]" value="" class="regular-text" placeholder="Descrição (ex.: Feriado Municipal)" /> '+
      '<label><input type="checkbox" name="c2p_hours_special['+i+'][annual]" value="1" /> Repetir anualmente</label> '+
      '<button type="button" class="button c2p-edit-special" style="display:none">Editar</button> '+
      '<button type="button" class="button c2p-remove-special">Remover</button>'+
      '</div>';
    $('#c2p-special-days').append(html);
    // ativa datepicker
    if($.fn.datepicker){
      $('#c2p-special-days .c2p-special-item:last .c2p-date').datepicker({ dateFormat: 'dd/mm/yy' });
    }
  });

  $(document).on('click','.c2p-edit-special',function(e){
    e.preventDefault();
    var wrap = $(this).closest('.c2p-special-item');
    wrap.attr('data-readonly','0');
    wrap.find('input').prop('readonly',false).prop('disabled',false).removeClass('c2p-ro');
    if ($.fn.datepicker) {
      var $date = wrap.find('.c2p-date');
      try { $date.datepicker('destroy'); } catch(err){}
      $date.datepicker({ dateFormat: 'dd/mm/yy' });
    }
    $(this).remove();
  });

  $(document).on('click','.c2p-remove-special',function(e){
    e.preventDefault();
    $(this).closest('.c2p-special-item').remove();
  });

  // ====== País/Estado dinâmico ======
  $(document).on('change','#c2p_country', function(){
    var country = $(this).val() || '';
    var $wrap = $('#c2p_state_wrap').empty();
    var states = (window.C2P_LOC && C2P_LOC.states && C2P_LOC.states[country]) ? C2P_LOC.states[country] : null;
    if(states && Object.keys(states).length){
      var $sel = $('<select name="c2p_state" id="c2p_state" class="c2p-state"></select>');
      $sel.append('<option value="">'+(wp.i18n?wp.i18n.__('Selecione o estado','c2p'):'Selecione o estado')+'</option>');
      Object.keys(states).forEach(function(code){
        $sel.append('<option value="'+code+'">'+states[code]+'</option>');
      });
      $wrap.append($sel);
    } else {
      $wrap.append('<input type="text" name="c2p_state" id="c2p_state" class="regular-text" placeholder="Estado" />');
    }
  });

  // ====== Mostra avisos de validação de servidor (se houver) ======
  (function showServerFlags(){
    var $e = $('#c2p-email-error-flag');
    if($e.length){
      var msg = $e.data('msg') || (I18N.invalidEmail || 'E-mail inválido. Corrija para salvar.');
      if($('.c2p-email-error').length===0){
        $('<div class="notice notice-error c2p-email-error"><p>'+msg+'</p></div>').insertBefore('.wrap h1:first');
      }
    }
    var $p = $('#c2p-phone-error-flag');
    if($p.length){
      var msg2 = $p.data('msg') || (I18N.invalidPhone || 'Telefone inválido. Use (xx)xxxxx-xxxx ou (xx)xxxx-xxxx.');
      if($('.c2p-phone-error').length===0){
        $('<div class="notice notice-error c2p-phone-error"><p>'+msg2+'</p></div>').insertBefore('.wrap h1:first');
      }
    }
  })();
});
