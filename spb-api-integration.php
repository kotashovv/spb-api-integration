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
function add_tour_selector_metabox() {
    add_meta_box(
        'tour_selector',
        __('Select Tour', 'textdomain'),
        'render_tour_selector_metabox',
        'tours',
        'side',
        'default'
    );
}

function render_tour_selector_metabox($post) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tours';
    $tours = $wpdb->get_results("SELECT id, pagetitle FROM $table_name", ARRAY_A);

    $selected_tour_id = get_post_meta($post->ID, '_selected_tour_id', true);

    echo '<select name="selected_tour_id" id="selected_tour_id">';
    echo '<option value="">'. esc_html__('Select a tour', 'textdomain') .'</option>';
    foreach ($tours as $tour) {
        $selected = ($tour['id'] == $selected_tour_id) ? 'selected="selected"' : '';
        echo '<option value="'. esc_attr($tour['id']) .'" '. $selected .'>'. esc_html($tour['pagetitle']) .'</option>';
    }
    echo '</select>';
}

add_action('save_post', 'save_tour_selector_metabox_data');
function save_tour_selector_metabox_data($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['selected_tour_id'])) return;
    if (!current_user_can('edit_post', $post_id)) return;

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

    public function save_tours_to_db($tours) {
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

        // // Отладка: выводим запрос
        // echo '<pre>';
        // echo "URL: " . self::$api_base . '/integration/order' . "\n";
        // echo "Headers:\n";
        // print_r([
        //     'Content-Type' => 'application/json',
        //     'Authorization' => 'Bearer ' . $token,
        //     'Accept' => 'application/json'  // Добавляем Accept header
        // ]);
        // echo "\nRequest Body:\n";
        // echo htmlspecialchars($request_data);
        // echo '</pre>';
        // exit;  // Прекратить выполнение для отладки

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
