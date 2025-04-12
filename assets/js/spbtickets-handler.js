jQuery(document).ready(function($) {
    $('#order_form-page').on('submit', function(e) {
        if (checkorderform() !== 0) return;

        e.preventDefault();

        const order = {
            data: {
                order: {
                    client_name: $('[name=name]').val(),
                    client_phone: $('[name=phone]').val(),
                    client_email: $('[name=mail]').val(),
                    external_order_id: Date.now().toString() // уникальный id, можно и счетчик
                },
                tickets: [{
                    date: $('.vc-date[data-vc-date-month="current"][aria-selected="true"]').data('vc-date'),
                    time: $('.tickets_addr__item.active').data('time'),
                    tour_id: spbtickets_data.tour_id,
                    schedule_id: 1, // заглушка
                    rates: []
                }]
            }
        };

        // Сбор тарифов (кол-во билетов)
        $('.form__input--number').each(function () {
            const count = parseInt($(this).val());
            const name = $(this).attr('name');
            if (count > 0) {
                order.data.tickets[0].rates.push({
                    count: count,
                    rate_id: name // временно сюда name (sold_adults и т.п.), заменим на id
                });
            }
        });

        $.post(spbtickets_data.ajaxurl, {
            action: 'spbtickets_create_order',
            order: JSON.stringify(order)
        }, function(response) {
            console.log('Ответ сервера:', response);
            if (response.status === 'success') {
                alert('Заказ зарегистрирован успешно!');
            } else {
                alert('Ошибка: ' + response.message);
            }
        });
    });
});
