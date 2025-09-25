/**
 * Scripts para a página de edição de produto
 */

jQuery(document).ready(function($) {
    // Prevenir scroll do mouse nos campos numéricos
    $(document).on('wheel mousewheel', '.c2p-stock-input, .c2p-min-stock-input', function(e) {
        e.preventDefault();
        return false;
    });
    
    // Atualizar total em tempo real
    $(document).on('input change', '.c2p-stock-input', function() {
        var $table = $(this).closest('.c2p-stock-table');
        var total = 0;
        
        $table.find('.c2p-stock-input').each(function() {
            var val = parseInt($(this).val()) || 0;
            total += val;
        });
        
        $table.find('.c2p-total-stock').text(total);
        
        // Atualizar status
        var $row = $(this).closest('tr');
        var quantity = parseInt($(this).val()) || 0;
        var minStock = parseInt($row.find('.c2p-min-stock-input').val()) || c2p_product.global_min_stock;
        
        updateStockStatus($row, quantity, minStock);
    });
    
    // Atualizar status quando o estoque mínimo mudar
    $(document).on('input change', '.c2p-min-stock-input', function() {
        var $row = $(this).closest('tr');
        var quantity = parseInt($row.find('.c2p-stock-input').val()) || 0;
        var minStock = parseInt($(this).val()) || c2p_product.global_min_stock;
        
        updateStockStatus($row, quantity, minStock);
    });
    
    // Limpar campo de estoque mínimo quando vazio
    $(document).on('blur', '.c2p-min-stock-input', function() {
        if ($(this).val() === '0' || $(this).val() === '') {
            $(this).val('');
        }
    });
    
    // Função para atualizar status visual
    function updateStockStatus($row, quantity, minStock) {
        var $statusBadge = $row.find('.status-badge');
        
        // Remover classes antigas
        $row.removeClass('out-of-stock low-stock in-stock');
        $statusBadge.removeClass('out-of-stock low-stock in-stock');
        
        if (quantity === 0) {
            $row.addClass('out-of-stock');
            $statusBadge.addClass('out-of-stock').text('Sem Estoque');
        } else if (quantity <= minStock) {
            $row.addClass('low-stock');
            $statusBadge.addClass('low-stock').text('Estoque Baixo');
        } else {
            $row.addClass('in-stock');
            $statusBadge.addClass('in-stock').text('Em Estoque');
        }
    }
    
    // Validar apenas números inteiros
    $(document).on('keypress', '.c2p-stock-input, .c2p-min-stock-input', function(e) {
        var charCode = (e.which) ? e.which : e.keyCode;
        if (charCode > 31 && (charCode < 48 || charCode > 57)) {
            return false;
        }
        return true;
    });
    
    // Remover decimais ao colar
    $(document).on('paste', '.c2p-stock-input, .c2p-min-stock-input', function(e) {
        var $input = $(this);
        setTimeout(function() {
            var value = parseInt($input.val()) || 0;
            $input.val(value);
        }, 10);
    });
    
    // Atualizar totais quando variações são carregadas
    $(document).on('woocommerce_variations_loaded', function() {
        $('.c2p-stock-table').each(function() {
            var $table = $(this);
            var total = 0;
            
            $table.find('.c2p-stock-input').each(function() {
                var val = parseInt($(this).val()) || 0;
                total += val;
            });
            
            $table.find('.c2p-total-stock').text(total);
        });
    });
});