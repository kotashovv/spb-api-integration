<?php
// Добавляем страницу настроек
function spb_integration_menu()
{
    add_options_page('SPB Integration', 'SPB Integration', 'manage_options', 'spb-integration', 'spb_integration_settings_page');
}
add_action('admin_menu', 'spb_integration_menu');

function spb_integration_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        update_option('spb_email', sanitize_email($_POST['spb_email']));
        update_option('spb_password', sanitize_text_field($_POST['spb_password']));
        echo '<div class="updated"><p>Настройки сохранены.</p></div>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_token'])) {
        $email = get_option('spb_email', '');
        $password = get_option('spb_password', '');

        if (!empty($email) && !empty($password)) {
            $auth_result = spbIntegration('auth', ['email' => $email, 'password' => $password]);
            echo '<div class="updated"><p>Результат авторизации: ' . esc_html($auth_result) . '</p></div>';
        } else {
            echo '<div class="error"><p>Введите логин и пароль перед получением токена.</p></div>';
        }
    }

    $email = get_option('spb_email', '');
    $password = get_option('spb_password', '');
    $token = get_option('spb_auth_token', 'Токен отсутствует');
    ?>
    <div class="wrap">
        <h1>Настройки SPB Integration</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="spb_email">Логин (Email)</label></th>
                    <td><input type="email" id="spb_email" name="spb_email" value="<?php echo esc_attr($email); ?>"
                            class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="spb_password">Пароль</label></th>
                    <td><input type="password" id="spb_password" name="spb_password"
                            value="<?php echo esc_attr($password); ?>" class="regular-text" required></td>
                </tr>
            </table>
            <p><input type="submit" name="save_settings" class="button-primary" value="Сохранить настройки"></p>
        </form>

        <h2>Авторизация</h2>
        <form method="post">
            <p><input type="submit" name="get_token" class="button-secondary" value="Получить Token"></p>
        </form>

        <h3>Текущий токен:</h3>
        <pre><?php echo esc_html($token); ?></pre>

        <h2>Расписание туров</h2>

        <?php
        // Инициализация объекта интеграции
        $spb_api = new SPB_Integration();
        $schedules = $spb_api->get_schedules(); // Получение расписания
    
        // Проверка на ошибку
        if (is_string($schedules)) {
            echo '<p>' . esc_html($schedules) . '</p>';
        } elseif (!empty($schedules)) {
            // Вывод таблицы
            echo '<table border="1" cellpadding="5" cellspacing="0">';
            echo '<tr>
            <th>Дата</th>
            <th>Время</th>
            <th>Туры</th>
            <th>Цвет</th>
            <th>Места</th>
            <th>Цены</th>
          </tr>';

            // Перебор расписания
            foreach ($schedules as $schedule) {
                echo '<tr>';
                echo '<td>' . esc_html($schedule['date']) . '</td>';
                echo '<td>' . esc_html($schedule['time']) . '</td>';
                echo '<td>' . esc_html(implode(', ', $schedule['tours'])) . '</td>';
                echo '<td>';

                // Выводим цвета туров
                foreach ($schedule['tours'] as $tour_id) {
                    echo '<span style="color:' . esc_html($schedule['colors'][$tour_id]) . '">' . esc_html($tour_id) . '</span> ';
                }

                echo '</td>';
                echo '<td>';

                // Выводим количество мест по каждому туру
                foreach ($schedule['tours'] as $tour_id) {
                    echo 'Тур ' . esc_html($tour_id) . ': ' . esc_html($schedule['places'][$tour_id]) . ' мест <br>';
                }

                echo '</td>';
                echo '<td>';

                // Выводим цены по каждому туру
                foreach ($schedule['tours'] as $tour_id) {
                    echo 'Тур ' . esc_html($tour_id) . ': <br>';
                    foreach ($schedule['amounts'][$tour_id] as $group => $price) {
                        echo 'Цена для группы ' . esc_html($group) . ' : ' . esc_html($price) . ' руб.<br>';
                    }
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</table>';
        } else {
            echo '<p>Нет доступных расписаний.</p>';
        }
        ?>

        <form method="POST" action="">
            <h2>Оформить заявку</h2>

            <label for="client_name">Имя клиента</label>
            <input type="text" id="client_name" name="client_name" required>

            <label for="client_phone">Телефон</label>
            <input type="tel" id="client_phone" name="client_phone" required>

            <label for="client_email">Email</label>
            <input type="email" id="client_email" name="client_email" required>

            <label for="external_order_id">ID заказа</label>
            <input type="text" id="external_order_id" name="external_order_id" required>

            <h3>Туры</h3>
            <label for="tour_id">Выберите тур</label>
            <!-- <select name="tour_id" id="tour_id">
                <option value="1000">Тур 1</option>
                <option value="1009">Тур 2</option>
            </select> -->
            <input name="tour_id" type="text" placeholder="id тура">

            <label for="tour_date">Дата тура</label>
            <input type="date" name="date" id="tour_date" required> <!-- Убедитесь, что это поле называется "date" -->

            <label for="tour_time">Время тура</label>
            <input type="time" name="time" id="tour_time" required> <!-- Убедитесь, что это поле называется "time" -->

            <h4>Тарифы</h4>
            <label for="rate_1">Тариф 1 (взрослый)</label>
            <input type="number" name="rates[1]" id="rate_1" value="0" min="0">
            

            <input type="submit" name="submit_order" value="Оформить заявку">
        </form>

        <h2>
            Полчение заказа по ID
        </h2>
        <div class="container">
            <h1>Получить заказ по ID</h1>
            <form id="orderForm" method="POST" onsubmit="return false;">
                <div class="form-group">
                    <label for="order_id">Введите ID заказа:</label>
                    <input type="text" id="order_id" name="order_id" placeholder="ID заказа" required>
                </div>
                <button type="submit">Получить заказ</button>
            </form>

            <div id="response" class="response" style="display: none;">
                <p id="responseMessage"></p>
            </div>
        </div>
        <script>
    document.getElementById('orderForm').addEventListener('submit', function (event) {
        event.preventDefault();

        var order_id = document.getElementById('order_id').value;

        // Параметры запроса
        var data = {
            order_id: order_id
        };

        // Отправка запроса через fetch
        fetch('./', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
            .then(response => {
                // Проверка на правильный формат ответа (JSON)
                const contentType = response.headers.get('Content-Type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json(); // Если JSON, парсим его
                } else {
                    throw new Error('Ответ от сервера не в формате JSON');
                }
            })
            .then(responseData => {
                var responseMessage = document.getElementById('responseMessage');
                var responseDiv = document.getElementById('response');

                // Обработка успешного ответа
                if (responseData.status === 'success') {
                    responseMessage.innerHTML = '<strong>Заказ найден:</strong><pre>' + JSON.stringify(responseData.data, null, 2) + '</pre>';
                    responseDiv.style.display = 'block';
                    responseDiv.classList.add('success');
                } else {
                    responseMessage.innerHTML = 'Ошибка: ' + responseData.message;
                    responseDiv.style.display = 'block';
                    responseDiv.classList.add('error');

                    // Добавляем полный ответ от сервера для отладки
                    var responseDetails = responseData.response ? JSON.stringify(responseData.response, null, 2) : 'Детали ответа отсутствуют';
                    responseMessage.innerHTML += '<br><strong>Полный ответ от API:</strong><pre>' + responseDetails + '</pre>';
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
                var responseMessage = document.getElementById('responseMessage');
                var responseDiv = document.getElementById('response');
                responseMessage.innerHTML = 'Произошла ошибка при получении данных. (похоже ответ не в json формате)' + '<br>' + error;
                responseDiv.style.display = 'block';
                responseDiv.classList.add('error');
            });
    });
</script>



    </div>
    <?php
}
?>




<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $order_id = $_POST['order_id'];

    // Создаем объект для API
    $spb_api = new SPB_Integration();

    // Получаем ответ от API
    $response = $spb_api->get_order($order_id);

    // Возвращаем результат в формате JSON
    header('Content-Type: application/json');

    // Если произошла ошибка, возвращаем статус ошибки и сам ответ
    if ($response['status'] === 'error') {
        echo json_encode([
            'status' => 'error',
            'message' => $response['message'],
            'response' => $response['response'] // возвращаем сам ответ
        ]);
    } else {
        // Если запрос успешен, возвращаем данные заказа
        echo json_encode([
            'status' => 'success',
            'data' => $response['data'],
            'response' => $response['response'] // возвращаем сам ответ
        ]);
    }

    exit; // Останавливаем дальнейшую обработку, чтобы не выводить лишний HTML
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    // Проверяем, что все необходимые поля присутствуют
    if (isset($_POST['date']) && isset($_POST['time'])) {
        // Получаем данные из формы
        $order_data = [
            'data' => [
                'order' => [
                    'comment' => '', // Можно добавить комментарий, если нужно
                    'client_name' => $_POST['client_name'],
                    'client_phone' => $_POST['client_phone'],
                    'client_email' => $_POST['client_email'],
                    'external_order_id' => $_POST['external_order_id']
                ],
                'tickets' => [
                    [
                        'date' => $_POST['date'], // Получаем дату из формы
                        'rates' => [],
                        'schedule_id' => 1, // ID расписания (можно передать соответствующее значение)
                        'time' => $_POST['time'], // Время тура
                        'tour_id' => $_POST['tour_id'] // ID тура
                    ]
                ]
            ]
        ];

        // Собираем тарифы
        foreach ($_POST['rates'] as $rate_id => $count) {
            $order_data['data']['tickets'][0]['rates'][] = [
                'count' => $count,
                'rate_id' => $rate_id
            ];
        }




        // Создаем заказ
        $spb_api = new SPB_Integration();
        $response = $spb_api->create_order($order_data);

        // Выводим полный ответ от API
        echo '<pre>';
        print_r($response); // Выводим весь ответ
        echo '</pre>';

        // Выводим результат
        if ($response['status'] === 'success') {
            echo 'Заказ успешно создан! ID заказа: ' . $response['data']['order_id'];
        } else {
            echo 'Ошибка при создании заказа: ' . $response['message'];
        }
    } else {
        echo "Пожалуйста, выберите дату и время тура.";
    }
}

?>