<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passo 3 — Checkout
 * Mantém a escolha do método de envio (feita no Passo 2) via:
 * - sessão (quando possível) e
 * - fallback por localStorage + trigger de update_checkout.
 */
?>
<section id="cart-step-3" class="c2p-step" style="display:none">
  <div class="uk-margin uk-card uk-card-body uk-card-default uk-card-hover">
    <div class="woocommerce-notices-wrapper">
      <?php if ( function_exists('wc_print_notices') ) { wc_print_notices(); } ?>
    </div>

    <?php echo do_shortcode('[woocommerce_checkout]'); ?>
  </div>
</section>

<script>
(function($){
  function restoreChosenFromStorage(){
    var arr = [];
    try{ arr = JSON.parse(localStorage.getItem('c2p_chosen_shipping_methods') || '[]'); }catch(e){ arr = []; }
    if (!arr || !arr.length) return;

    function apply(){
      var changed = false;
      for (var i=0;i<arr.length;i++){
        var val = arr[i];
        var $radio = $('input[name^="shipping_method["][value="'+val+'"]');
        if ($radio.length && !$radio.is(':checked')) {
          $radio.prop('checked', true).trigger('change');
          changed = true;
        }
      }
      if (changed) {
        $(document.body).trigger('update_checkout');
      }
    }

    // Aguarda renderização inicial das taxas
    setTimeout(apply, 120);
  }

  $(restoreChosenFromStorage);
  $(document.body).on('updated_checkout', restoreChosenFromStorage);
})(jQuery);
</script>
