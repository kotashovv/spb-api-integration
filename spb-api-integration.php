<?php
/**
 * Plugin Name: SPB Integration
 * Description: Интеграция с API spbticket.com
 * Version: 1.0
 * Author: Ruslan
 */

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

global $wpdb;

// Название таблицы (включает префикс WordPress)
$table_name = $wpdb->prefix . 'tours';

// SQL-запрос для создания таблицы
$sql = "CREATE TABLE $table_name (
  id BIGINT(20) UNSIGNED NOT NULL,
  pagetitle VARCHAR(255) NOT NULL,
  introtext TEXT NOT NULL,
  images TEXT NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
dbDelta($sql);



add_action('add_meta_boxes', 'add_tour_selector_metabox');
function add_tour_selector_metabox()
{
    add_meta_box(
        'tour_selector',
        __('Select Tour', 'textdomain'),
        'render_tour_selector_metabox',
        'tours',
        'side',
        'default'
    );
}

add_action('admin_init', 'spb_check_and_create_tours_schedules_table');

function spb_check_and_create_tours_schedules_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'tours_schedules';

    // Проверяем, существует ли таблица
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tour_id BIGINT UNSIGNED NOT NULL,
			date DATE NOT NULL,
			time TIME NOT NULL,
			datetime DATETIME NOT NULL,
			schedule_id INT NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			places INT DEFAULT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			refers_id INT NOT NULL, -- Добавлено поле
			datetime_full DATETIME NOT NULL, -- Добавлено поле
			PRIMARY KEY (id),
			INDEX tour_id_idx (tour_id),
			INDEX datetime_idx (datetime),
			INDEX schedule_id_idx (schedule_id)
		) $charset_collate;";

        dbDelta($sql);
    }
}



function render_tour_selector_metabox($post)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'tours';
    $tours = $wpdb->get_results("SELECT id, pagetitle FROM $table_name", ARRAY_A);

    $selected_tour_id = get_post_meta($post->ID, '_selected_tour_id', true);

    echo '<select name="selected_tour_id" id="selected_tour_id">';
    echo '<option value="">' . esc_html__('Select a tour', 'textdomain') . '</option>';
    foreach ($tours as $tour) {
        $selected = ($tour['id'] == $selected_tour_id) ? 'selected="selected"' : '';
        echo '<option value="' . esc_attr($tour['id']) . '" ' . $selected . '>' . esc_html($tour['pagetitle']) . '</option>';
    }
    echo '</select>';
}

add_action('save_post', 'save_tour_selector_metabox_data');
function save_tour_selector_metabox_data($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (!isset($_POST['selected_tour_id']))
        return;
    if (!current_user_can('edit_post', $post_id))
        return;

    $selected_tour_id = sanitize_text_field($_POST['selected_tour_id']);
    update_post_meta($post_id, '_selected_tour_id', $selected_tour_id);
}

class Tour
{
    public $id;
    public $pagetitle;
    public $introtext;
    public $content;
    public $images;

    // Базовый URL для изображений
    private $image_base_url = 'https://spbticket.com/';

    public function __construct($data)
    {
        $this->id = $data['id'] ?? null;
        $this->pagetitle = $data['pagetitle'] ?? '';
        $this->introtext = $data['introtext'] ?? '';
        $this->content = $data['content'] ?? '';
        $this->images = $this->build_images($data['images'] ?? []);
    }

    // Метод для получения изображений с полным URL
    private function build_images($images)
    {
        $full_images = [];
        foreach ($images as $image_id => $image_path) {
            $full_images[$image_id] = $this->image_base_url . $image_path; // Формируем полный URL
        }
        return (object) $full_images; // Возвращаем как объект
    }

    // Метод для получения изображения по ID
    public function get_image($image_id)
    {
        return $this->images->$image_id ?? null;
    }
}



class SPB_Integration
{


    private static $api_base = 'https://dev.spbticket.com/api/v1';
    private $token;
    private $token_expiry;



    public static function request($endpoint, $method = 'GET', $data = [])
    {
        $url = self::$api_base . '/' . $endpoint;
        $args = [
            'method' => $method,
            'headers' => ['Accept' => 'application/json'],
        ];

        if ($method === 'POST') {
            $args['body'] = json_encode($data); // Преобразуем в JSON, если передаем данные
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true); // Декодируем JSON в массив

        return $data; // Возвращаем массив
    }





    public static function auth($email, $password)
    {
        $endpoint = "auth/login?field=$email&password=$password";
        $response = self::request($endpoint, 'POST');

        // Логируем полный ответ
        error_log(print_r($response, true));

        // Проверяем успешность авторизации
        if (is_array($response) && !empty($response['status']) && $response['status'] === 'success' && !empty($response['data']['accessToken'])) {
            update_option('spb_auth_token', $response['data']['accessToken']);
            update_option('spb_refresh_token', $response['data']['refreshToken']);
            update_option('spb_token_expire', $response['data']['accessTokenExpire']);
            // Логируем успешное сохранение токенов
            error_log('Токен успешно сохранен: ' . $response['data']['accessToken']);
        } else {
            // Логируем ошибку авторизации
            error_log('Ошибка авторизации: ' . (isset($response['message']) ? $response['message'] : 'Неизвестная ошибка'));
            return 'Ошибка авторизации: ' . (isset($response['message']) ? $response['message'] : 'Неизвестная ошибка');
        }

        return 'Авторизация прошла успешно';
    }




    public function __construct()
    {
        $this->token = get_option('spb_auth_token', '');
        $this->token_expiry = get_option('spb_token_expire', 0);
    }

    public function is_authenticated()
    {
        // Проверка на наличие токена и его истечение
        if (empty($this->token) || time() > $this->token_expiry) {
            return false;
        }
        return true;
    }

    public function refresh_token($email, $password)
    {
        // Попробуем выполнить повторную авторизацию
        return self::auth($email, $password);
    }

    public function save_tours_to_db($tours)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'tours';

        foreach ($tours as $tour) {
            $data = [
                'id' => $tour->id,
                'pagetitle' => $tour->pagetitle,
                'introtext' => $tour->introtext,
                'images' => maybe_serialize($tour->images)
            ];

            $wpdb->replace($table_name, $data);
        }
    }

    public function get_tours()
    {
        if (!$this->token) {
            return new WP_Error('no_token', 'Нет accessToken, выполните авторизацию.');
        }
        $url = self::$api_base . '/integration/tours'; // Исправленный путь
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->token
            ]
        ]);
        if (is_wp_error($response)) {
            return 'Ошибка запроса: ' . $response->get_error_message();
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['status']) || $data['status'] !== 'success') {
            return 'Ошибка API: Возможно, API вернул HTML главной страницы. Проверь токен!';
        }
        // Преобразуем туры в объекты Tour
        $tours = [];
        foreach ($data['data']['data'] as $tour_data) {
            $tours[] = new Tour($tour_data); // Создаем объект Tour
        }

        if (!is_wp_error($tours)) {
            $this->save_tours_to_db($tours);
        } else {
            echo $tours->get_error_message();
        }

        return $tours;
    }

    public function delete_order($order_id)
    {
        // URL для запроса
        $url = self::$api_base . '/integration/order/' . $order_id;

        // Заголовки запроса
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ];

        // Выполнение запроса DELETE
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => $headers
        ]);

        // Проверка на успешность запроса
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => 'Ошибка запроса: ' . $response->get_error_message()
            ];
        }

        // Получение тела ответа
        $body = wp_remote_retrieve_body($response);

        // Преобразование ответа в массив
        $data = json_decode($body, true);

        // Проверка на успешный ответ
        if ($data[0]['status'] === 'success') {
            return [
                'status' => 'success',
                'message' => 'Заказ удален: ' . $data[0]['data'][0]['data']
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Ошибка: ' . $data[0]['message']
            ];
        }
    }

    public function get_order($order_id)
    {
        // URL для запроса
        $url = self::$api_base . '/integration/order/' . $order_id;

        // Заголовки запроса
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ];

        // Выполнение запроса
        $response = wp_remote_get($url, [
            'method' => 'GET',
            'headers' => $headers
        ]);

        // Проверка на успешность запроса
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => 'Ошибка запроса: ' . $response->get_error_message(),
                'response' => $response // Добавляем сам ответ
            ];
        }

        // Получение тела ответа
        $body = wp_remote_retrieve_body($response);

        // Преобразование ответа в массив
        $data = json_decode($body, true);

        // Проверка на успешный ответ
        if ($data['status'] === 'success') {
            return [
                'status' => 'success',
                'data' => $data['data'],
                'response' => $data // Добавляем весь ответ
            ];
        } else {
            return [
                'status' => 'error',
                'message' => $data['message'],
                'response' => $data // Добавляем весь ответ
            ];
        }
    }



    public function create_order($order_data)
    {
        $token = $this->token;  // Получаем токен

        if (empty($token)) {
            return [
                'status' => 'error',
                'message' => 'Не удалось получить токен.',
                'code' => 400
            ];
        }

        // Преобразуем дату в нужный формат
        foreach ($order_data['data']['tickets'] as &$ticket) {
            $date = $ticket['date']; // Получаем дату
            $date_obj = DateTime::createFromFormat('Y-m-d', $date); // Преобразуем из формата Y-m-d
            if ($date_obj) {
                $ticket['date'] = $date_obj->format('d.m.Y'); // Форматируем в d.m.Y
            }
        }

        // Подготовка данных для запроса
        $request_data = json_encode($order_data);

        // Отправка запроса
        $response = wp_remote_post(self::$api_base . '/integration/order', [
            'method' => 'POST',
            'body' => $request_data,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => 'Ошибка при отправке запроса.',
                'code' => 500
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Дополнительная отладка
        echo '<pre>';
        echo "Response:\n";
        print_r($data);
        echo '</pre>';
        exit; // Остановить выполнение, чтобы увидеть ответ

        if (isset($data['status']) && $data['status'] === 'success') {

            $tour_id = $order_data['data']['tickets'][0]['tour_id'] ?? '';
            $excursion = 'Тур #' . $tour_id;
            $date_time = $order_data['data']['order']['date'] . ' ' . $order_data['data']['order']['time'];
            $tickets = json_encode($order_data['data']['tickets'], JSON_UNESCAPED_UNICODE);
            $payment = $order_data['data']['order']['payment_type'] ?? 'full'; // или 'prepayment'
            $order_id = $data['data']['order_id']; // от SPBTicket API

            // Создание записи заказа
            wp_insert_post([
                'post_type' => 'spb_tickets_order',
                'post_title' => 'Заказ #' . $order_id,
                'post_status' => 'publish',
                'meta_input' => [
                    '_spb_tickets_excursion' => $excursion,
                    '_spb_tickets_date_time' => $date_time,
                    '_spb_tickets' => $tickets,
                    '_spb_tickets_payment_status' => $payment,
                    '_spb_tickets_order_status' => 'not_confirmed',
                    '_spb_tickets_external_id' => $order_id // чтобы не потерять связь с API
                ]
            ]);

            return [
                'status' => 'success',
                'message' => $data['message'],
                'code' => 200,
                'order_id' => $data['data']['order_id']
            ];
        } else {
            return [
                'status' => 'error',
                'message' => $data['message'] ?? 'Неизвестная ошибка.',
                'code' => 500
            ];
        }
    }





    public function get_rates()
    {
        if (!$this->token) {
            return new WP_Error('no_token', 'Нет accessToken, выполните авторизацию.');
        }

        // URL для запроса тарифов
        $url = self::$api_base . '/integration/rates';

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->token
            ]
        ]);

        if (is_wp_error($response)) {
            return 'Ошибка запроса: ' . $response->get_error_message();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['status']) || $data['status'] !== 'success') {
            return 'Ошибка API: ' . ($data['message'] ?? 'Неизвестная ошибка');
        }

        // Если данных нет, возвращаем пустой массив
        return isset($data['data']['data']) ? $data['data']['data'] : [];
    }



    public function get_schedules($dateStart = null, $dateEnd = null)
    {
        if (!$this->token) {
            return new WP_Error('no_token', 'Нет accessToken, выполните авторизацию.');
        }

        $url = self::$api_base . '/integration/schedules';

        // Добавляем параметры, если они переданы
        $query_params = [];
        if ($dateStart) {
            $query_params['dateStart'] = $dateStart;
        }
        if ($dateEnd) {
            $query_params['dateEnd'] = $dateEnd;
        }

        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->token
            ]
        ]);

        if (is_wp_error($response)) {
            return 'Ошибка запроса: ' . $response->get_error_message();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['status']) || $data['status'] !== 'success') {
            return 'Ошибка API: ' . ($data['message'] ?? 'Неизвестная ошибка');
        }

        // Если данных нет, возвращаем пустой массив
        return isset($data['data']['data']) ? $data['data']['data'] : [];
    }



    public function render_rates_shortcode($email, $password)
    {
        // Получаем тарифы
        $rates = $this->get_rates($email, $password);

        // Проверяем на ошибку
        if (is_wp_error($rates)) {
            return '<p>Ошибка: ' . $rates->get_error_message() . '</p>';
        }

        // Проверяем, что тарифы не пусты и являются массивом
        if (empty($rates) || !is_array($rates)) {
            return '<p>Тарифы не найдены или произошла ошибка.</p>';
        }

        $output = '<ul>';
        foreach ($rates as $rate) {
            $output .= '<li><strong>' . esc_html($rate['title']) . '</strong> (' . esc_html($rate['short_title']) . ')';
            if (!empty($rate['description'])) {
                $output .= ': ' . esc_html($rate['description']);
            }
            $output .= '</li>';
        }
        $output .= '</ul>';

        return $output;
    }



    public function render_tours_shortcode($email, $password)
    {
        // Получаем туры
        $tours = $this->get_tours($email, $password);

        // Проверяем на ошибку
        if (is_wp_error($tours)) {
            return '<p>Ошибка: ' . $tours->get_error_message() . '</p>';
        }

        // Проверяем, что туры не пусты и являются объектами
        if (empty($tours)) {
            return '<p>Туры не найдены или произошла ошибка.</p>';
        }

        $output = '<ul>';
        foreach ($tours as $tour) {
            $output .= '<li><strong>' . esc_html($tour->pagetitle) . '</strong>: ' . esc_html($tour->introtext) . '</li>';
            // Выводим ссылки на изображения, если они есть
            if (!empty($tour->images)) {
                foreach ($tour->images as $image_id => $image_url) {
                    $output .= '<a href="' . esc_url($image_url) . '" target="_blank">Ссылка на изображение ' . esc_html($image_id) . '</a><br>';
                }
            }
        }
        $output .= '</ul>';


        return $output;
    }







}


function spbIntegration($action, $args = [])
{
    switch ($action) {
        case 'auth':
            return SPB_Integration::auth($args['email'], $args['password']);
        default:
            return ['error' => 'Неизвестный метод API'];
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/settings-page.php';


$spb = new SPB_Integration();
add_shortcode('spb_tours', [$spb, 'render_tours_shortcode']);
add_shortcode('spb_rates', [$spb, 'render_rates_shortcode']);


function import_schedules_into_db()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'tours_schedules';

    // Задаем дату начала и конца (сегодня и +3 месяца)
    $dateStart = date('Y-m-d');
    $dateEnd = date('Y-m-d', strtotime('+3 months'));

    // Инициализация API-класса
    $spb_api = new SPB_Integration();
    $response = $spb_api->get_schedules($dateStart, $dateEnd);

    // Проверяем, что данные получены успешно
    if (isset($response[0]) && !empty($response)) {
        $inserted = 0; // Счётчик добавленных записей
        $wpdb->query("TRUNCATE TABLE $table_name"); // Очищаем таблицу

        foreach ($response as $schedule) {
            $dateTime = $schedule['dateTime'];
            $date = substr($dateTime, 0, 10);   // Извлекаем дату (первые 10 символов)
            $time = substr($dateTime, 11, 5);   // Извлекаем время (с 11-го символа, 5 символов)

            // Идем по списку туров
            foreach ($schedule['tours'] as $tour_id) {
                $refers_id = intval($tour_id);
                $schedule_id = $schedule['schedules'][$tour_id] ?? null;

                // Проверяем, если schedule_id пустое, пропускаем
                if (!$schedule_id) {
                    echo '<p><strong>Пропущено расписание для тура с ID: ' . esc_html($tour_id) . '</strong></p>';
                    continue;
                }

                // Перебираем все цены для текущего тура
                foreach ($schedule['amounts'][$tour_id] as $key => $amount) {
                    // Если amount пустое, пропускаем
                    if (!$amount) {
                        echo '<p><strong>Пропущена цена для тура с ID: ' . esc_html($tour_id) . ' (вариант ' . esc_html($key) . ')</strong></p>';
                        continue;
                    }

                    // Проверяем перед вставкой
                    if (is_numeric($amount) && !empty($refers_id) && !empty($schedule_id) && !empty($dateTime)) {
                        $result = $wpdb->insert($table_name, [
                            'tour_id' => $refers_id,               // ID тура
                            'date' => $date,                       // Дата (из datetime_full)
                            'time' => $time,                       // Время (из datetime_full)
                            'schedule_id' => $schedule_id,         // ID расписания
                            'amount' => $amount,                   // Цена
                            'places' => null,                      // Места (если пусто)
                            'refers_id' => $refers_id,             // ID ссылки
                            'datetime_full' => $dateTime,          // Полная дата-время
                        ]);

                        // Проверка успешности вставки
                        if ($result !== false) {
                            $inserted++; // Увеличиваем счётчик добавленных записей
                            echo '<p><strong>Запись добавлена:</strong> Тур ID: ' . esc_html($refers_id) . ' | Дата: ' . esc_html($date) . ' | Время: ' . esc_html($time) . ' | Вариант: ' . esc_html($schedule_id) . ' | Цена: ' . esc_html($amount) . '</p>';
                        } else {
                            $error = $wpdb->last_error;
                            echo '<p><strong>Ошибка при вставке записи:</strong> Тур ID: ' . esc_html($refers_id) . ' | Ошибка: ' . esc_html($error) . '</p>';
                        }
                    } else {
                        echo '<p><strong>Пропущена запись (невалидные данные):</strong> Тур ID: ' . esc_html($tour_id) . ' | Цена: ' . esc_html($amount) . '</p>';
                    }
                }
            }
        }

        // Выводим итоговый результат
        if ($inserted > 0) {
            echo '<p><strong>Добавлено расписаний:</strong> ' . $inserted . '</p>';
        } else {
            echo '<p><strong>Новые данные не были добавлены.</strong></p>';
        }
    } else {
        // В случае ошибки API или пустого ответа
        echo '<p><strong>Ошибка загрузки расписаний или пустой ответ от API.</strong></p>';
    }

    exit;
}




// Для ручного запуска, например, по URL
add_action('admin_post_import_schedules', 'import_schedules_into_db');


function create_spb_tickets_orders()
{
    $args = array(
        'labels' => array(
            'name' => 'Заказы SPBTickets',
            'singular_name' => 'Заказ SPBTickets',
            'add_new' => 'Добавить заказ',
            'add_new_item' => 'Добавить новый заказ',
            'edit_item' => 'Редактировать заказ',
            'new_item' => 'Новый заказ',
            'view_item' => 'Просмотр заказа',
            'all_items' => 'Все заказы',
            'search_items' => 'Искать заказы',
            'not_found' => 'Заказы не найдены',
            'not_found_in_trash' => 'Заказы в корзине не найдены',
            'parent_item_colon' => '',
            'menu_name' => 'Заказы SPBTickets'
        ),
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_position' => 20,
        'supports' => array('title', 'editor', 'custom-fields'),
        'has_archive' => false,
        'rewrite' => array('slug' => 'spb-tickets-orders'),
    );
    register_post_type('spb_tickets_order', $args);
}
add_action('init', 'create_spb_tickets_orders');


function add_spb_tickets_order_meta_boxes()
{
    add_meta_box(
        'spb_tickets_order_details',
        'Детали заказа SPBTickets',
        'spb_tickets_order_meta_box_callback',
        'spb_tickets_order',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'add_spb_tickets_order_meta_boxes');

function spb_tickets_order_meta_box_callback($post)
{
    // Получаем сохраненные значения
    $excursion = get_post_meta($post->ID, '_spb_tickets_excursion', true);
    $date_time = get_post_meta($post->ID, '_spb_tickets_date_time', true);
    $tickets = get_post_meta($post->ID, '_spb_tickets', true);
    $payment_status = get_post_meta($post->ID, '_spb_tickets_payment_status', true);
    $order_status = get_post_meta($post->ID, '_spb_tickets_order_status', true);
    $order_id = get_post_meta($post->ID, '_spb_tickets_external_id', true);

    ?>
    <p>
        <label for="spb_tickets_excursion">Экскурсия:</label>
        <input type="text" id="spb_tickets_excursion" name="spb_tickets_excursion"
            value="<?php echo esc_attr($excursion); ?>" class="widefat" />
    </p>
    <p>
        <label for="spb_tickets_external_id">ID заказа SPBTickets:</label>
        <input type="text" id="spb_tickets_external_id" name="spb_tickets_external_id"
            value="<?php echo esc_attr($order_id); ?>" class="widefat" readonly />
    </p>
    <p>
        <label for="spb_tickets_date_time">Дата и время:</label>
        <input type="text" id="spb_tickets_date_time" name="spb_tickets_date_time"
            value="<?php echo esc_attr($date_time); ?>" class="widefat" />
    </p>
    <p>
        <label for="spb_tickets">Билеты:</label>
        <textarea id="spb_tickets" name="spb_tickets" class="widefat"><?php echo esc_textarea($tickets); ?></textarea>
    </p>
    <p>
        <label for="spb_tickets_payment_status">Оплата:</label>
        <select id="spb_tickets_payment_status" name="spb_tickets_payment_status" class="widefat">
            <option value="full" <?php selected($payment_status, 'full'); ?>>Полная</option>
            <option value="prepayment" <?php selected($payment_status, 'prepayment'); ?>>Предоплата</option>
        </select>
    </p>
    <p>
        <label for="spb_tickets_order_status">Статус заказа:</label>
        <select id="spb_tickets_order_status" name="spb_tickets_order_status" class="widefat">
            <option value="confirmed" <?php selected($order_status, 'confirmed'); ?>>Подтвержден</option>
            <option value="not_confirmed" <?php selected($order_status, 'not_confirmed'); ?>>Не подтвержден</option>
        </select>
    </p>
    <?php
}

// Сохраняем данные заказа
function save_spb_tickets_order_meta($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return $post_id;

    if (isset($_POST['spb_tickets_excursion'])) {
        update_post_meta($post_id, '_spb_tickets_excursion', sanitize_text_field($_POST['spb_tickets_excursion']));
    }
    if (isset($_POST['spb_tickets_date_time'])) {
        update_post_meta($post_id, '_spb_tickets_date_time', sanitize_text_field($_POST['spb_tickets_date_time']));
    }
    if (isset($_POST['spb_tickets'])) {
        update_post_meta($post_id, '_spb_tickets', sanitize_textarea_field($_POST['spb_tickets']));
    }
    if (isset($_POST['spb_tickets_payment_status'])) {
        update_post_meta($post_id, '_spb_tickets_payment_status', sanitize_text_field($_POST['spb_tickets_payment_status']));
    }
    if (isset($_POST['spb_tickets_order_status'])) {
        update_post_meta($post_id, '_spb_tickets_order_status', sanitize_text_field($_POST['spb_tickets_order_status']));
    }
    if (isset($_POST['spb_tickets_external_id'])) {
        update_post_meta($post_id, '_spb_tickets_external_id', sanitize_text_field($_POST['spb_tickets_external_id']));
    }
}
add_action('save_post', 'save_spb_tickets_order_meta');


function spb_tickets_orders_columns($columns)
{
    $columns['excursion'] = 'Экскурсия';
    $columns['date_time'] = 'Дата и время';
    $columns['payment_status'] = 'Оплата';
    $columns['order_status'] = 'Статус заказа';
    $columns['external_id'] = 'ID в SPBTickets';
    return $columns;
}
add_filter('manage_spb_tickets_order_posts_columns', 'spb_tickets_orders_columns');

function spb_tickets_orders_custom_column($column, $post_id)
{
    switch ($column) {
        case 'excursion':
            echo get_post_meta($post_id, '_spb_tickets_excursion', true);
            break;
        case 'date_time':
            echo get_post_meta($post_id, '_spb_tickets_date_time', true);
            break;
        case 'payment_status':
            echo get_post_meta($post_id, '_spb_tickets_payment_status', true);
            break;
        case 'order_status':
            echo get_post_meta($post_id, '_spb_tickets_order_status', true);
            break;
        case 'external_id':
            echo get_post_meta($post_id, '_spb_tickets_external_id', true);
            break;
    }
}
add_action('manage_spb_tickets_order_posts_custom_column', 'spb_tickets_orders_custom_column', 10, 2);

function spbtickets_tour_is_selected()
{
    if (is_singular('tours')) {
        return true;
    }

    if (isset($_GET['tour_id']) && is_numeric($_GET['tour_id'])) {
        return true;
    }

    return false;
}

add_action('wp_enqueue_scripts', 'spbtickets_enqueue_scripts', 999);

function spbtickets_enqueue_scripts()
{
    if (is_page() && spbtickets_tour_is_selected()) {
        wp_enqueue_script(
            'spbtickets-handler',
            plugin_dir_url(__FILE__) . 'assets/js/spbtickets-handler.js',
            array('jquery'),
            '1.0',
            true
        );

        global $post;

        wp_localize_script('spbtickets-handler', 'spbtickets_data', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'tour_id' => get_post_meta($post->ID, '_spbtickets_tour_id', true),
        ]);
    }
}



add_action('wp_ajax_spbtickets_create_order', 'spbtickets_handle_ajax_order');
add_action('wp_ajax_nopriv_spbtickets_create_order', 'spbtickets_handle_ajax_order');

function spbtickets_handle_ajax_order()
{
    // Получаем данные из запроса
    $order_data = json_decode(stripslashes($_POST['order']), true);

    if (!$order_data) {
        wp_send_json_error(['message' => 'Неверный формат данных']);
    }

    // Допустим, у тебя уже есть объект класса, например:
    $plugin = new SPB_Integration(); // или как у тебя называется основной класс
    $response = $plugin->create_order($order_data);

    wp_send_json($response);
}

// Функция для получения schedule_id на основе tour_id
function get_schedule_id_by_tour_id($tour_id) {
    global $wpdb;
    
    // Запрос к базе данных для получения schedule_id для данного tour_id
    $result = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT schedule_id FROM {$wpdb->prefix}tours_schedules WHERE tour_id = %d LIMIT 1",
            $tour_id
        )
    );
    
    return $result;
}

// Функция для добавления скрытого input на страницу, если выбрана экскурсия
function add_schedule_id_hidden_input($content) {
    // Получаем tour_id из мета-данных текущего поста
    $tour_id = get_post_meta(get_the_ID(), '_spb_tickets_excursion', true);
    
    if ($tour_id) {
        // Получаем schedule_id для данного tour_id
        $schedule_id = get_schedule_id_by_tour_id($tour_id);

        if ($schedule_id) {
            // Если найден schedule_id, добавляем скрытое поле на страницу
            $hidden_input = '<input type="hidden" name="schedule_id" value="' . esc_attr($schedule_id) . '" />';
            $content .= $hidden_input; // Добавляем скрытое поле в конец содержимого страницы
        }
    }

    return $content;
}
add_filter('the_content', 'add_schedule_id_hidden_input');