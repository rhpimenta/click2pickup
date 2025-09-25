/**
 * Click2Pickup - Checkout JavaScript
 * Sistema interativo para seleção de local e agendamento
 */

jQuery(document).ready(function($) {
    
    // Variáveis globais
    let selectedLocation = null;
    let selectedDate = null;
    let selectedTime = null;
    let locationsData = [];
    
    // Inicialização
    init();
    
    function init() {
        // Listeners para mudança de método
        $('input[name="c2p_delivery_method"]').on('change', handleMethodChange);
        
        // Listeners para seleção de local
        $(document).on('change', 'input[name="c2p_pickup_location"]', handleLocationSelect);
        $(document).on('change', 'input[name="c2p_delivery_location"]', handleLocationSelect);
        
        // Busca de locais
        $('#c2p-location-search').on('input', handleLocationSearch);
        
        // Filtros
        $('.c2p-filter-btn').on('click', handleFilterClick);
        
        // Botão do mapa
        $(document).on('click', '.c2p-action-btn.map', handleMapClick);
        
        // Modal do mapa
        $('.c2p-modal-close').on('click', closeMapModal);
        $(window).on('click', function(e) {
            if ($(e.target).hasClass('c2p-modal')) {
                closeMapModal();
            }
        });
        
        // Carregar locais via AJAX
        loadLocations();
        
        // Gerar calendário
        generateDateSelector();
    }
    
    /**
     * Manipula mudança de método de entrega
     */
    function handleMethodChange() {
        const method = $(this).val();
        
        if (method === 'pickup') {
            $('#c2p-pickup-section').slideDown();
            $('#c2p-delivery-section').slideUp();
            $('#c2p-scheduling-title').text(c2p_checkout.strings.select_date);
            $('#c2p_delivery_type').val('pickup');
        } else {
            $('#c2p-pickup-section').slideUp();
            $('#c2p-delivery-section').slideDown();
            $('#c2p-scheduling-title').text('Quando deseja receber?');
            $('#c2p_delivery_type').val('delivery');
        }
        
        // Resetar seleções
        resetSelections();
    }
    
    /**
     * Manipula seleção de local
     */
    function handleLocationSelect() {
        const locationId = $(this).val();
        const locationName = $(this).closest('.c2p-location-card').data('location-name');
        
        selectedLocation = {
            id: locationId,
            name: locationName
        };
        
        // Salvar em campos hidden
        $('#c2p_selected_location').val(locationId);
        
        // Mostrar seção de agendamento
        $('#c2p-scheduling-section').slideDown();
        
        // Carregar horários disponíveis
        if (selectedDate) {
            loadTimeSlots(locationId, selectedDate);
        }
        
        // Atualizar resumo
        updateSummary();
        
        // Scroll suave para agendamento
        $('html, body').animate({
            scrollTop: $('#c2p-scheduling-section').offset().top - 100
        }, 500);
    }
    
    /**
     * Busca de locais
     */
    function handleLocationSearch() {
        const searchTerm = $(this).val().toLowerCase();
        
        $('.c2p-location-card').each(function() {
            const $card = $(this);
            const name = $card.data('location-name').toLowerCase();
            const $address = $card.find('.c2p-location-address').text().toLowerCase();
            
            if (name.includes(searchTerm) || $address.includes(searchTerm)) {
                $card.show();
            } else {
                $card.hide();
            }
        });
    }
    
    /**
     * Manipula clique nos filtros
     */
    function handleFilterClick() {
        const filter = $(this).data('filter');
        
        // Atualizar botão ativo
        $('.c2p-filter-btn').removeClass('active');
        $(this).addClass('active');
        
        // Aplicar filtro
        $('.c2p-location-card').each(function() {
            const $card = $(this);
            let show = true;
            
            switch(filter) {
                case 'open':
                    show = $card.hasClass('is-open');
                    break;
                case 'available':
                    show = $card.hasClass('has-stock');
                    break;
                case 'all':
                default:
                    show = true;
            }
            
            if (show) {
                $card.show();
            } else {
                $card.hide();
            }
        });
    }
    
    /**
     * Carrega locais via AJAX
     */
    function loadLocations() {
        $.ajax({
            url: c2p_checkout.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_get_locations',
                nonce: c2p_checkout.nonce
            },
            success: function(response) {
                if (response.success) {
                    locationsData = response.data;
                }
            }
        });
    }
    
    /**
     * Gera seletor de data
     */
    function generateDateSelector() {
        const $container = $('.c2p-date-selector');
        const today = new Date();
        const daysToShow = 14; // Mostrar próximos 14 dias
        
        for (let i = 0; i < daysToShow; i++) {
            const date = new Date(today);
            date.setDate(today.getDate() + i);
            
            const dayName = date.toLocaleDateString('pt-BR', { weekday: 'short' });
            const dayNumber = date.getDate();
            const monthName = date.toLocaleDateString('pt-BR', { month: 'short' });
            const dateValue = date.toISOString().split('T')[0];
            
            const $dateOption = $('<div>', {
                class: 'c2p-date-option',
                'data-date': dateValue
            });
            
            $dateOption.html(`
                <div class="day-name">${dayName}</div>
                <div class="day-number">${dayNumber}</div>
                <div class="month-name">${monthName}</div>
            `);
            
            // Marcar hoje
            if (i === 0) {
                $dateOption.append('<small>Hoje</small>');
            }
            
            $container.append($dateOption);
        }
        
        // Listener para seleção de data
        $(document).on('click', '.c2p-date-option', function() {
            $('.c2p-date-option').removeClass('selected');
            $(this).addClass('selected');
            
            selectedDate = $(this).data('date');
            $('#c2p_selected_date').val(selectedDate);
            
            // Carregar horários disponíveis
            if (selectedLocation) {
                loadTimeSlots(selectedLocation.id, selectedDate);
            }
            
            updateSummary();
        });
    }
    
    /**
     * Carrega slots de horário
     */
    function loadTimeSlots(locationId, date) {
        const $container = $('.c2p-time-slots');
        
        // Mostrar loading
        $container.html('<div class="c2p-loading">' + c2p_checkout.strings.loading + '</div>');
        
        $.ajax({
            url: c2p_checkout.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_get_time_slots',
                location_id: locationId,
                date: date,
                nonce: c2p_checkout.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.closed) {
                        $container.html('<div class="c2p-closed-message">' + c2p_checkout.strings.location_closed + '</div>');
                    } else {
                        displayTimeSlots(response.data.slots);
                    }
                }
            },
            error: function() {
                $container.html('<div class="c2p-error-message">Erro ao carregar horários</div>');
            }
        });
    }
    
    /**
     * Exibe slots de horário
     */
    function displayTimeSlots(slots) {
        const $container = $('.c2p-time-slots');
        $container.empty();
        
        if (slots.length === 0) {
            $container.html('<div class="c2p-no-slots">Nenhum horário disponível</div>');
            return;
        }
        
        slots.forEach(function(slot) {
            const $slot = $('<div>', {
                class: 'c2p-time-slot',
                'data-time': slot.value,
                text: slot.label
            });
            
            $container.append($slot);
        });
        
        // Listener para seleção de horário
        $(document).off('click', '.c2p-time-slot');
        $(document).on('click', '.c2p-time-slot', function() {
            $('.c2p-time-slot').removeClass('selected');
            $(this).addClass('selected');
            
            selectedTime = $(this).data('time');
            $('#c2p_selected_time').val(selectedTime);
            
            updateSummary();
        });
    }
    
    /**
     * Atualiza resumo
     */
    function updateSummary() {
        if (selectedLocation && selectedDate && selectedTime) {
            $('#c2p-summary-location').text(selectedLocation.name);
            
            const date = new Date(selectedDate);
            const formattedDate = date.toLocaleDateString('pt-BR', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            $('#c2p-summary-date').text(formattedDate);
            $('#c2p-summary-time').text(selectedTime);
            
            $('.c2p-schedule-summary').slideDown();
        }
    }
    
    /**
     * Reseta seleções
     */
    function resetSelections() {
        selectedLocation = null;
        selectedDate = null;
        selectedTime = null;
        
        $('input[name="c2p_pickup_location"]').prop('checked', false);
        $('input[name="c2p_delivery_location"]').prop('checked', false);
        $('.c2p-date-option').removeClass('selected');
        $('.c2p-time-slot').removeClass('selected');
        
        $('#c2p_selected_location').val('');
        $('#c2p_selected_date').val('');
        $('#c2p_selected_time').val('');
        
        $('#c2p-scheduling-section').slideUp();
        $('.c2p-schedule-summary').slideUp();
    }
    
    /**
     * Manipula clique no mapa
     */
    function handleMapClick(e) {
        e.preventDefault();
        
        const $button = $(this);
        const lat = $button.data('lat');
        const lng = $button.data('lng');
        const address = $button.data('address');
        
        // Abrir modal
        $('#c2p-map-modal').fadeIn();
        
        // Inicializar mapa
        setTimeout(function() {
            initMap(lat, lng, address);
        }, 100);
    }
    
    /**
     * Inicializa mapa Leaflet
     */
    function initMap(lat, lng, address) {
        // Usar coordenadas padrão se não fornecidas
        if (!lat || !lng) {
            // Tentar geocodificar o endereço
            geocodeAddress(address);
            return;
        }
        
        // Criar mapa
        const map = L.map('c2p-map-container').setView([lat, lng], 15);
        
        // Adicionar tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Adicionar marcador
        L.marker([lat, lng])
            .addTo(map)
            .bindPopup(address)
            .openPopup();
        
        // Salvar referência do mapa
        window.c2pMap = map;
        
        // Botão de direções
        $('#c2p-get-directions').off('click').on('click', function() {
            const url = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(address)}`;
            window.open(url, '_blank');
        });
    }
    
    /**
     * Geocodifica endereço
     */
    function geocodeAddress(address) {
        // Usar Nominatim (OpenStreetMap) para geocodificação
        $.ajax({
            url: 'https://nominatim.openstreetmap.org/search',
            data: {
                q: address,
                format: 'json',
                limit: 1
            },
            success: function(data) {
                if (data.length > 0) {
                    const result = data[0];
                    initMap(result.lat, result.lon, address);
                } else {
                    // Usar coordenadas padrão
                    initMap(
                        c2p_checkout.map_settings.default_lat,
                        c2p_checkout.map_settings.default_lng,
                        address
                    );
                }
            }
        });
    }
    
    /**
     * Fecha modal do mapa
     */
    function closeMapModal() {
        $('#c2p-map-modal').fadeOut();
        
        // Destruir mapa se existir
        if (window.c2pMap) {
            window.c2pMap.remove();
            window.c2pMap = null;
        }
    }
    
    /**
     * Verificação de disponibilidade em tempo real
     */
    function checkAvailability(locationId) {
        $.ajax({
            url: c2p_checkout.ajax_url,
            type: 'POST',
            data: {
                action: 'c2p_check_availability',
                location_id: locationId,
                nonce: c2p_checkout.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (!response.data.available) {
                        // Mostrar aviso sobre itens indisponíveis
                        if (confirm(c2p_checkout.strings.confirm_remove)) {
                            removeUnavailableItems(response.data.unavailable_items);
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Remove itens indisponíveis do carrinho
     */
    function removeUnavailableItems(items) {
        // Implementar remoção via AJAX
        console.log('Remover itens:', items);
    }
    
    /**
     * Animações
     */
    // Adicionar classes de animação quando elementos aparecem
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('c2p-animated');
            }
        });
    });
    
    $('.c2p-location-card').each(function() {
        observer.observe(this);
    });
});