jQuery(document).ready(function($) {
    console.log('C2P Locations Admin JS Loaded - Version 5.0');
    
    // CORREÇÃO 1: Upload de Imagem WordPress Media
    var file_frame;
    
    $('#upload-location-image').on('click', function(e) {
        e.preventDefault();
        
        console.log('Upload button clicked');
        
        // Se o frame já existe, apenas abrir
        if (file_frame) {
            file_frame.open();
            return;
        }
        
        // Criar o media frame
        file_frame = wp.media.frames.file_frame = wp.media({
            title: 'Selecionar Imagem da Loja',
            button: {
                text: 'Usar esta imagem'
            },
            multiple: false
        });
        
        // Quando uma imagem é selecionada
        file_frame.on('select', function() {
            var attachment = file_frame.state().get('selection').first().toJSON();
            console.log('Image selected:', attachment);
            
            $('#location-image-id').val(attachment.id);
            $('#location-image-preview').html(
                '<img src="' + attachment.url + '" style="max-width: 300px; height: auto; display: block; margin-bottom: 10px;" />'
            );
            $('#remove-location-image').show();
        });
        
        // Abrir o modal
        file_frame.open();
    });
    
    $('#remove-location-image').on('click', function(e) {
        e.preventDefault();
        console.log('Remove image clicked');
        
        $('#location-image-id').val('');
        $('#location-image-preview').empty();
        $(this).hide();
    });
    
    // CORREÇÃO 2: Select2 com Ajax e interface melhorada
    function initializeSelect2() {
        console.log('Initializing Select2');
        
        // Destruir instâncias anteriores se existirem
        if ($('#shipping_zones').data('select2')) {
            $('#shipping_zones').select2('destroy');
        }
        if ($('#shipping_methods').data('select2')) {
            $('#shipping_methods').select2('destroy');
        }
        
        // Select2 para zonas
        $('#shipping_zones').select2({
            placeholder: 'Digite para pesquisar zonas de entrega...',
            allowClear: true,
            width: '100%',
            closeOnSelect: false,
            language: {
                noResults: function() {
                    return 'Nenhuma zona encontrada';
                },
                searching: function() {
                    return 'Pesquisando...';
                }
            }
        });
        
        // Select2 para métodos
        $('#shipping_methods').select2({
            placeholder: 'Digite para pesquisar métodos de entrega...',
            allowClear: true,
            width: '100%',
            closeOnSelect: false,
            language: {
                noResults: function() {
                    return 'Nenhum método encontrado';
                },
                searching: function() {
                    return 'Pesquisando...';
                }
            }
        });
        
        console.log('Select2 initialized');
    }
    
    // Inicializar Select2 quando a página carrega
    setTimeout(function() {
        initializeSelect2();
    }, 100);
    
    // CORREÇÃO 3: Adicionar dia especial com TODOS os campos
    window.specialDayIndex = $('#special-days-list tr').length;
    
    $('#add-special-day').on('click', function(e) {
        e.preventDefault();
        console.log('Add special day clicked, index:', window.specialDayIndex);
        
        var newRow = '<tr>' +
            '<td>' +
                '<input type="text" name="special_days[' + window.specialDayIndex + '][date]" ' +
                'class="datepicker" readonly placeholder="Selecione" style="width: 100%;">' +
            '</td>' +
            '<td>' +
                '<input type="text" name="special_days[' + window.specialDayIndex + '][description]" ' +
                'placeholder="Ex: Natal" style="width: 100%;">' +
            '</td>' +
            '<td>' +
                '<select name="special_days[' + window.specialDayIndex + '][status]" class="special-day-status">' +
                    '<option value="closed">Fechado</option>' +
                    '<option value="open">Aberto</option>' +
                '</select>' +
            '</td>' +
            '<td class="special-day-hours">' +
                '<input type="time" name="special_days[' + window.specialDayIndex + '][open]" style="width: 45%;"> - ' +
                '<input type="time" name="special_days[' + window.specialDayIndex + '][close]" style="width: 45%;">' +
            '</td>' +
            '<td class="special-day-prep">' +
                '<input type="number" name="special_days[' + window.specialDayIndex + '][prep_time]" ' +
                'value="60" min="0" max="1440" step="15" style="width: 60px;"> min' +
            '</td>' +
            '<td class="special-day-cutoff">' +
                '<input type="time" name="special_days[' + window.specialDayIndex + '][cutoff]" value="17:00">' +
            '</td>' +
            '<td style="text-align: center;">' +
                '<input type="checkbox" name="special_days[' + window.specialDayIndex + '][recurring]" value="1">' +
            '</td>' +
            '<td>' +
                '<button type="button" class="button button-small remove-special-day">Remover</button>' +
            '</td>' +
        '</tr>';
        
        $('#special-days-list').append(newRow);
        window.specialDayIndex++;
        
        // Inicializar datepicker no novo campo
        $('.datepicker').not('.hasDatepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            minDate: 0
        });
        
        // Aplicar estado inicial (fechado = desabilitar campos)
        $('#special-days-list tr:last-child .special-day-status').trigger('change');
    });
    
    // Toggle campos quando status mudar
    $(document).on('change', '.special-day-status', function() {
        var $row = $(this).closest('tr');
        var isClosed = $(this).val() === 'closed';
        
        $row.find('.special-day-hours input, .special-day-prep input, .special-day-cutoff input').each(function() {
            $(this).prop('disabled', isClosed);
            $(this).css('opacity', isClosed ? '0.5' : '1');
        });
    });
    
    // Remover dia especial
    $(document).on('click', '.remove-special-day', function(e) {
        e.preventDefault();
        if (confirm('Tem certeza que deseja remover este dia especial?')) {
            $(this).closest('tr').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });
    
    // Toggle dia fechado/aberto
    $(document).on('change', '.day-closed', function() {
        var $dayCard = $(this).closest('.c2p-day-card');
        var $dayHours = $dayCard.find('.c2p-day-hours');
        
        if ($(this).is(':checked')) {
            $dayHours.slideUp(300);
        } else {
            $dayHours.slideDown(300);
        }
    });
    
    // Copiar horários
    $('#copy-weekdays').on('click', function(e) {
        e.preventDefault();
        
        var mondayCard = $('.c2p-day-card[data-day="monday"]');
        if (!mondayCard.length) {
            alert('Dados da segunda-feira não encontrados!');
            return;
        }
        
        var mondayData = {
            closed: mondayCard.find('.day-closed').prop('checked'),
            open: mondayCard.find('input[name*="[open]"]').val(),
            close: mondayCard.find('input[name*="[close]"]').val(),
            prep_time: mondayCard.find('input[name*="[prep_time]"]').val(),
            cutoff: mondayCard.find('input[name*="[cutoff]"]').val()
        };
        
        ['tuesday', 'wednesday', 'thursday', 'friday'].forEach(function(day) {
            var $card = $('.c2p-day-card[data-day="' + day + '"]');
            if ($card.length) {
                $card.find('.day-closed').prop('checked', mondayData.closed).trigger('change');
                $card.find('input[name*="[open]"]').val(mondayData.open);
                $card.find('input[name*="[close]"]').val(mondayData.close);
                $card.find('input[name*="[prep_time]"]').val(mondayData.prep_time);
                $card.find('input[name*="[cutoff]"]').val(mondayData.cutoff);
            }
        });
        
        alert('Horários copiados com sucesso!');
    });
    
    $('#copy-all-days').on('click', function(e) {
        e.preventDefault();
        
        var mondayCard = $('.c2p-day-card[data-day="monday"]');
        if (!mondayCard.length) {
            alert('Dados da segunda-feira não encontrados!');
            return;
        }
        
        var mondayData = {
            closed: mondayCard.find('.day-closed').prop('checked'),
            open: mondayCard.find('input[name*="[open]"]').val(),
            close: mondayCard.find('input[name*="[close]"]').val(),
            prep_time: mondayCard.find('input[name*="[prep_time]"]').val(),
            cutoff: mondayCard.find('input[name*="[cutoff]"]').val()
        };
        
        $('.c2p-day-card').each(function() {
            if ($(this).data('day') !== 'monday') {
                $(this).find('.day-closed').prop('checked', mondayData.closed).trigger('change');
                $(this).find('input[name*="[open]"]').val(mondayData.open);
                $(this).find('input[name*="[close]"]').val(mondayData.close);
                $(this).find('input[name*="[prep_time]"]').val(mondayData.prep_time);
                $(this).find('input[name*="[cutoff]"]').val(mondayData.cutoff);
            }
        });
        
        alert('Horários copiados para todos os dias!');
    });
    
    // Datepicker
    $('.datepicker').datepicker({
        dateFormat: 'yy-mm-dd',
        changeMonth: true,
        changeYear: true,
        minDate: 0
    });
    
    // Filtrar métodos por zona
    $('#shipping_zones').on('change', function() {
        var selectedZones = $(this).val() || [];
        
        $('#shipping_methods optgroup').show();
        $('#shipping_methods option').show().prop('disabled', false);
        
        if (selectedZones.length > 0) {
            $('#shipping_methods option').each(function() {
                var zoneId = $(this).data('zone');
                if (zoneId !== undefined && selectedZones.indexOf(zoneId.toString()) === -1) {
                    $(this).hide().prop('disabled', true);
                }
            });
            
            // Esconder optgroups vazios
            $('#shipping_methods optgroup').each(function() {
                if ($(this).find('option:visible').length === 0) {
                    $(this).hide();
                }
            });
        }
        
        // Reinicializar Select2
        $('#shipping_methods').select2('destroy');
        $('#shipping_methods').select2({
            placeholder: 'Digite para pesquisar métodos de entrega...',
            allowClear: true,
            width: '100%',
            closeOnSelect: false
        });
    });
    
    // AJAX Save
    $('form#c2p-location-form').on('submit', function(e) {
        if ($(this).data('submitting')) return;
        
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"]');
        var originalText = $submitBtn.val();
        
        $form.data('submitting', true);
        $submitBtn.val('Salvando...').prop('disabled', true);
        
        $.post($form.attr('action'), $form.serialize() + '&ajax=1', function(response) {
            if (response && response.success) {
                // Mostrar mensagem de sucesso
                var $notice = $('<div class="notice notice-success is-dismissible"><p>Local salvo com sucesso!</p></div>');
                $('.wrap > h1').after($notice);
                
                // Se for novo, adicionar ID ao form
                if (response.data && response.data.location_id && !$form.find('input[name="location_id"]').length) {
                    $form.append('<input type="hidden" name="location_id" value="' + response.data.location_id + '">');
                    $submitBtn.val('Atualizar Local');
                }
                
                // Scroll para o topo
                $('html, body').animate({ scrollTop: 0 }, 300);
                
                // Remover notice após 5 segundos
                setTimeout(function() {
                    $notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, 5000);
            } else {
                // Em caso de erro, fazer submit normal
                $form.data('submitting', false);
                $form.off('submit').submit();
            }
        }).fail(function() {
            // Em caso de erro, fazer submit normal
            $form.data('submitting', false);
            $form.off('submit').submit();
        }).always(function() {
            $submitBtn.val(originalText).prop('disabled', false);
            $form.data('submitting', false);
        });
    });
    
    // Trigger inicial
    $('.special-day-status').trigger('change');
    
    // Debug
    console.log('Scripts loaded. Elements found:');
    console.log('- Upload button:', $('#upload-location-image').length);
    console.log('- Shipping zones select:', $('#shipping_zones').length);
    console.log('- Shipping methods select:', $('#shipping_methods').length);
    console.log('- Add special day button:', $('#add-special-day').length);
});