jQuery(document).ready(function($){
  // Atualização rápida de estoque na listagem por local
  $(document).on('click', '.c2p-save-stock', function(e){
    e.preventDefault();
    var $row = $(this).closest('tr');
    var $input = $row.find('.c2p-stock-input');

    var locationId = parseInt($input.data('location'), 10);
    var productId = parseInt($input.data('product'), 10);
    var quantity = parseInt($input.val(), 10);

    var $btn = $(this);
    $btn.prop('disabled', true).text('Salvando...');

    $.post(ajaxurl, {
      action: 'c2p_update_stock',
      nonce: c2p_stock.nonce,
      location_id: locationId,
      product_id: productId,
      quantity: quantity
    }).done(function(resp){
      if (resp && resp.success) {
        $btn.text('Salvo!');
        setTimeout(function(){ $btn.prop('disabled', false).text('Salvar'); }, 800);
      } else {
        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Erro ao salvar.');
        $btn.prop('disabled', false).text('Salvar');
      }
    }).fail(function(){
      alert('Erro de comunicação.');
      $btn.prop('disabled', false).text('Salvar');
    });
  });
});