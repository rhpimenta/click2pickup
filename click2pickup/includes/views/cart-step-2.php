<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passo 2 — Entrega x Retirada (UI)
 * - Alterna modo via AJAX (sem reload) e limpa estado anterior
 * - ENTREGAR: só transportadoras (local_pickup oculto)
 * - RETIRAR: só local_pickup + lista de LOJAS (nunca CDs)
 * - Painel de indisponibilidade com botões "Remover X faltantes" e "Remover tudo que falta"
 * - Impede avançar enquanto houver faltas ou sem modo/método válido
 */

// Helpers expostos pelos hooks (caso não existam ainda em early load)
if ( ! function_exists('c2p_get_mode') )  { function c2p_get_mode(){ return ''; } }
if ( ! function_exists('c2p_get_store') ) { function c2p_get_store(){ return 0; } }

$mode     = c2p_get_mode();
$selected = c2p_get_store();

/* Buscar SOMENTE lojas (não CDs) para a aba Retirada */
$stores_q = new WP_Query([
    'post_type'      => 'c2p_store',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'fields'         => 'ids',
    'meta_query'     => [
        [
            'key'   => 'c2p_type',
            'value' => 'loja', // lojistas apenas
        ],
    ],
]);
$stores   = $stores_q->posts ?: [];

/* Utilitário para endereço bonitinho */
function c2p__format_store_address( int $post_id ): string {
    $addr1 = get_post_meta($post_id,'c2p_address_1',true);
    $addr2 = get_post_meta($post_id,'c2p_address_2',true);
    $city  = get_post_meta($post_id,'c2p_city',true);
    $state = get_post_meta($post_id,'c2p_state',true);
    $zip   = get_post_meta($post_id,'c2p_postcode',true);
    $bits  = [];
    if ($addr1) $bits[] = esc_html($addr1);
    if ($addr2) $bits[] = esc_html($addr2);
    $cs = trim(($city?:'') . ' / ' . ($state?:''), ' /');
    if ($cs)   $bits[] = esc_html($cs);
    if ($zip)  $bits[] = esc_html($zip);
    return implode(' • ', $bits);
}

/** Existe algum método local_pickup habilitado? (checagem macro para mensagem de indisponibilidade) */
function c2p__any_local_pickup_enabled(): bool {
    if ( ! class_exists('WC_Shipping_Zones') ) return false;
    $zones = \WC_Shipping_Zones::get_zones();
    foreach ( $zones as $z ) {
        foreach ( ($z['shipping_methods'] ?? []) as $m ) {
            if ( $m->id === 'local_pickup' && $m->is_enabled() ) return true;
        }
    }
    $rest = \WC_Shipping_Zones::get_zone(0);
    if ( $rest ) {
        foreach ( $rest->get_shipping_methods() as $m ) {
            if ( $m->id === 'local_pickup' && $m->is_enabled() ) return true;
        }
    }
    return false;
}

$pickup_possible = !empty($stores) && c2p__any_local_pickup_enabled();

?>
<section id="cart-step-2" class="c2p-section">

  <!-- Tabs (layout seguindo seu stepper/UIkit) -->
  <div class="c2p-tabs uk-margin">
    <ul class="wc-tabs nav nav-tabs uk-tab">
      <li class="tab-delivery <?php echo ($mode==='pickup')?'':'active'; ?>"><a href="#c2p-tab-entrega"><?php esc_html_e('Entrega','woocommerce'); ?></a></li>
      <li class="tab-pickup   <?php echo ($mode==='pickup')?'active':''; ?>"><a href="#c2p-tab-retirada"><?php esc_html_e('Retirada na Unidade','woocommerce'); ?></a></li>
    </ul>

    <!-- Painel de indisponibilidade (populado por JS via AJAX) -->
    <div id="c2p-shortage-panel" class="uk-alert-warning uk-alert" style="display:none; border:1px solid #ffe08a; background:#fff7d6; padding:10px 12px; border-radius:6px; margin-bottom:12px;"></div>

    <!-- ENTREGA -->
    <div id="c2p-tab-entrega" class="wc-tab panel" style="<?php echo ($mode==='pickup')?'display:none':''; ?>">
      <?php
        do_action('c2p_before_delivery_calculator');
        // A calculadora padrão pode fazer POST; com o JS corrigido, os métodos voltam a aparecer normalmente.
        woocommerce_shipping_calculator();
        do_action('c2p_after_delivery_calculator');
      ?>
      <div class="uk-margin-top">
        <a href="#cart-step-1" class="uk-button uk-button-default" data-c2p-prev><?php esc_html_e('Voltar','woocommerce'); ?></a>
        <a href="#cart-step-3" class="uk-button uk-button-primary" data-c2p-next id="c2p-next-delivery"><?php esc_html_e('Prosseguir','woocommerce'); ?></a>
      </div>
    </div>

    <!-- RETIRADA -->
    <div id="c2p-tab-retirada" class="wc-tab panel" style="<?php echo ($mode==='pickup')?'':'display:none'; ?>">

      <?php if ( ! $pickup_possible ) : ?>
        <div class="uk-alert-warning uk-alert" style="border:1px solid #ffe08a; background:#fff7d6; padding:10px 12px; border-radius:6px;">
          <strong><?php esc_html_e('Retirada indisponível para estes itens.','woocommerce'); ?></strong>
          <div class="uk-text-meta"><?php esc_html_e('Você pode prosseguir com Entrega em endereço.','woocommerce'); ?></div>
          <div class="uk-margin-small-top">
            <a href="#c2p-tab-entrega" class="uk-button uk-button-primary" id="c2p-goto-delivery"><?php esc_html_e('Receber em casa','woocommerce'); ?></a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ( $stores ) : ?>
        <div class="uk-grid-medium" uk-grid>
          <?php foreach ( $stores as $sid ) :
            $title = get_the_title($sid);
            $addr  = c2p__format_store_address($sid);
            $isSel = ((int)$selected === (int)$sid);
          ?>
          <div class="uk-width-1-2@m">
            <div class="uk-card uk-card-default <?php echo $isSel?'uk-card-primary':''; ?>">
              <div class="uk-card-header">
                <h3 class="uk-card-title uk-margin-remove"><?php echo esc_html($title); ?></h3>
                <?php if ($addr): ?><div class="uk-text-meta"><?php echo esc_html($addr); ?></div><?php endif; ?>
              </div>
              <div class="uk-card-body">
                <span class="uk-label <?php echo $isSel?'uk-label-success':''; ?>">
                  <?php echo $isSel ? esc_html__('Selecionada','woocommerce') : esc_html__('Disponível','woocommerce'); ?>
                </span>
              </div>
              <div class="uk-card-footer">
                <button class="uk-button uk-button-<?php echo $isSel?'default':'primary'; ?> c2p-pick-store"
                        data-store-id="<?php echo (int)$sid; ?>" type="button">
                  <?php echo $isSel ? esc_html__('Selecionada','woocommerce') : esc_html__('Selecionar','woocommerce'); ?>
                </button>
                <a href="#cart-step-3" class="uk-button uk-button-primary" data-c2p-next id="c2p-next-pickup"><?php esc_html_e('Prosseguir','woocommerce'); ?></a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="uk-text-meta"><?php esc_html_e('Nenhuma loja de retirada cadastrada no momento.','woocommerce'); ?></p>
      <?php endif; ?>

      <div class="uk-margin-top">
        <a href="#cart-step-1" class="uk-button uk-button-default" data-c2p-prev><?php esc_html_e('Voltar','woocommerce'); ?></a>
      </div>
    </div>
  </div>
</section>

<script>
(function($){
  var ajaxURL = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
  var nonce   = <?php echo wp_json_encode( wp_create_nonce('c2p_cart_nonce') ); ?>;

  // Função simples para escape em HTML
  function esc(s){
    return String(s===undefined||s===null?'':s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  // Não bloquear a navegação "Voltar" (#cart-step-1)
  // Nem o submit da calculadora (form padrão do Woo).
  // Interceptamos apenas:
  // - troca de abas (para setar o modo)
  // - tentar avançar (#cart-step-3) para validar antes
  $(document).on('click', '#cart-step-2 a[href^="#"]', function(e){
    var href = $(this).attr('href') || '';
    if (href.indexOf('#c2p-tab-entrega') === 0 || href.indexOf('#c2p-tab-retirada') === 0) {
      e.preventDefault();
    } else if (href.indexOf('#cart-step-3') === 0) {
      e.preventDefault();
    }
  });

  function setModeUI(mode){
    var $tabDel = $('#c2p-tab-entrega');
    var $tabPic = $('#c2p-tab-retirada');

    $('.wc-tabs li').removeClass('active');
    if(mode==='pickup'){
      $('.wc-tabs .tab-pickup').addClass('active');
      $tabDel.hide(); $tabPic.show();
    }else{
      $('.wc-tabs .tab-delivery').addClass('active');
      $tabPic.hide(); $tabDel.show();
    }
  }

  function refreshFragments(){
    if (typeof wc_cart_fragments_params !== 'undefined') {
      $(document.body).trigger('wc_fragment_refresh');
    } else {
      $(document.body).trigger('updated_wc_div');
    }
  }

  /** Painel de faltas / mensagens **/
  function renderShortagesBox(status){
    var $box = $('#c2p-shortage-panel');
    $box.empty().hide();

    // Sem modo => pedir para escolher
    if (!status.mode) {
      $box.html(
        '<strong><?php echo esc_js(__('Escolha uma opção em "Entrega / Retirada" antes de prosseguir.','woocommerce')); ?></strong>'
      ).show();
      return;
    }

    // Método inválido para o modo
    if (!status.has_valid_method) {
      $box.html(
        '<strong><?php echo esc_js(__('Selecione um método compatível com a opção escolhida.','woocommerce')); ?></strong>'
      ).show();
      return;
    }

    // Faltas
    if (status.shortages && status.shortages.length){
      var html  = '<strong><?php echo esc_js(__('Itens indisponíveis no local fornecedor:','woocommerce')); ?></strong>';
      html += '<div class="uk-text-meta" style="margin-top:4px"><?php echo esc_js(__('Remova apenas a quantidade faltante para continuar.','woocommerce')); ?></div>';
      html += '<div class="uk-margin-small"><table class="shop_table" style="width:100%">';
      html += '<thead><tr><th><?php echo esc_js(__('Produto','woocommerce')); ?></th><th style="text-align:center"><?php echo esc_js(__('Solicitado','woocommerce')); ?></th><th style="text-align:center"><?php echo esc_js(__('Disponível','woocommerce')); ?></th><th style="text-align:center"><?php echo esc_js(__('Falta','woocommerce')); ?></th><th></th></tr></thead><tbody>';

      status.shortages.forEach(function(row){
        html += '<tr>'+
                  '<td>'+ esc(row.name) +'</td>'+
                  '<td style="text-align:center">'+ esc(row.requested) +'</td>'+
                  '<td style="text-align:center">'+ esc(row.available) +'</td>'+
                  '<td style="text-align:center"><strong>'+ esc(row.missing) +'</strong></td>'+
                  '<td style="text-align:right">'+
                    '<button class="uk-button uk-button-small uk-button-danger c2p-fix-one" data-line="'+ esc(row.line_key) +'" data-missing="'+ esc(row.missing) +'"><?php echo esc_js(__('Remover faltantes','woocommerce')); ?></button>'+
                  '</td>'+
                '</tr>';
      });

      html += '</tbody></table></div>';
      html += '<div class="uk-margin-small"><button class="uk-button uk-button-primary c2p-fix-all"><?php echo esc_js(__('Remover tudo que falta','woocommerce')); ?></button></div>';

      $box.html(html).show();
      return;
    }

    // Sem faltas, tudo ok: painel oculto
    $box.hide().empty();
  }

  // Validação geral
  function validateAndRender(cb){
    $.post(ajaxURL, { action:'c2p_validate_cart', nonce:nonce }, function(resp){
      if(!resp || !resp.success || !resp.data) return;
      renderShortagesBox(resp.data);
      if (typeof cb === 'function') cb(resp.data);
    });
  }

  function switchMode(mode){
    $.post(ajaxURL, { action:'c2p_set_mode', mode:mode, nonce:nonce }, function(resp){
      if (resp && resp.success && resp.data) {
        setModeUI(mode);
        renderShortagesBox(resp.data);
        refreshFragments();
      }
    });
  }

  // Tabs clicáveis definem o modo
  $('.tab-delivery a').on('click', function(){ switchMode('delivery'); });
  $('.tab-pickup a').on('click',   function(){ switchMode('pickup');   });

  // CTA quando retirada indisponível
  $('#c2p-goto-delivery').on('click', function(){ switchMode('delivery'); });

  // Selecionar loja (define store + força pickup)
  $(document).on('click', '.c2p-pick-store', function(){
    var sid = parseInt($(this).data('store-id'),10)||0;
    if(!sid) return;
    $('.c2p-pick-store').removeClass('uk-button-default uk-button-primary').addClass('uk-button-primary').text('<?php echo esc_js(__('Selecionar','woocommerce')); ?>');
    $(this).removeClass('uk-button-primary').addClass('uk-button-default').text('<?php echo esc_js(__('Selecionada','woocommerce')); ?>');
    $.post(ajaxURL, { action:'c2p_set_store', store_id:sid, nonce:nonce }, function(resp){
      if (resp && resp.success && resp.data) {
        setModeUI('pickup');
        renderShortagesBox(resp.data);
        refreshFragments();
      }
    });
  });

  // Corrigir faltas — um item (linha)
  $(document).on('click', '.c2p-fix-one', function(){
    var line = $(this).data('line') || '';
    if (!line) return;
    $(this).prop('disabled', true);
    $.post(ajaxURL, { action:'c2p_fix_shortage', line_key:line, nonce:nonce }, function(resp){
      validateAndRender(function(){ refreshFragments(); });
    });
  });

  // Corrigir faltas — tudo
  $(document).on('click', '.c2p-fix-all', function(){
    $(this).prop('disabled', true);
    $.post(ajaxURL, { action:'c2p_fix_shortage', all:1, nonce:nonce }, function(resp){
      validateAndRender(function(){ refreshFragments(); });
    });
  });

  // Tentar avançar: primeiro valida
  function tryProceed(){
    validateAndRender(function(status){
      if (status && status.ok) {
        // Pode avançar
        window.location.hash = '#cart-step-3';
        $(window).trigger('hashchange');
      } else {
        // Mantém no passo 2 e mostra mensagens
        window.location.hash = '#cart-step-2';
      }
    });
  }
  $('#c2p-next-delivery, #c2p-next-pickup').on('click', function(e){
    e.preventDefault();
    tryProceed();
  });

  // Valida ao carregar o passo 2
  $(function(){ validateAndRender(); });

})(jQuery);
</script>
