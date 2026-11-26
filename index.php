<?php
/**
 * =======================================================
 * Файл: index.php — Конфигурация + функции + витрина
 * =======================================================
 *
 * СОДЕРЖАНИЕ
 *   1.  Конфигурация
 *   2.  Подключение к БД
 *   3.  Настройки (settings)
 *   4.  Изображения
 *   5.  JSON-ответ
 *   6.  Авторизация / сессия
 *   7.  Запросы к БД: категории, товары, закуски, комбо, магазины
 *   8.  Обработчик API
 *   9.  API-роутер
 *  10.  Загрузка данных для витрины
 *  11.  HTML: <head>
 *  12.  HTML: фон и 18+ модалка
 *  13.  HTML: шапка
 *  14.  HTML: hero-секция
 *  15.  HTML: каталог сортов
 *  16.  HTML: закуски
 *  17.  HTML: комбо-наборы
 *  18.  HTML: магазины и карта
 *  19.  HTML: корзина (drawer)
 *  20.  HTML: авторизация / регистрация
 *  21.  HTML: оформление заказа
 *  22.  HTML: модалка товара
 *  23.  HTML: модалка телефона
 *  24.  HTML: футер
 *  25.  Данные для JS + подключение script.js
 *  26.  Inline-JS: модалки, авторизация, карта, статус магазинов
 *  27.  Кнопка «наверх»
 */

/* =========================================================
   1. КОНФИГУРАЦИЯ
   ========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'pass.php';

define('SITE_NAME', 'Пивляндия');
define('SITE_SLOGAN', 'Сеть магазинов крафтового и разливного пива в Симферополе');
define('SITE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']));

mysqli_report(MYSQLI_REPORT_OFF);

/* =========================================================
   2. ПОДКЛЮЧЕНИЕ К БД
   ========================================================= */
function get_db_connection() {
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_init();
        if (!$conn) {
            http_response_code(500);
            die('mysqli_init failed');
        }
        mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 10);
        if (!@mysqli_real_connect($conn, DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306)) {
            http_response_code(500);
            die('Ошибка подключения к базе данных: ' . mysqli_connect_error());
        }
        mysqli_set_charset($conn, 'utf8mb4');

        // Служебная таблица настроек создаётся при первом подключении
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` VARCHAR(50) PRIMARY KEY,
            `setting_value` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        mysqli_query($conn, "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('cart_enabled', '1')");

        // Миграция: sort_order для категорий
        $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'categories'");
        if ($tbl && mysqli_num_rows($tbl) > 0) {
            $col = mysqli_query($conn, "SHOW COLUMNS FROM categories LIKE 'sort_order'");
            if ($col && mysqli_num_rows($col) === 0) {
                mysqli_query($conn, "ALTER TABLE categories ADD COLUMN sort_order INT DEFAULT 0");
                mysqli_query($conn, "UPDATE categories SET sort_order = id");
            }
            // Миграция: is_visible для категорий
            $colC = mysqli_query($conn, "SHOW COLUMNS FROM categories LIKE 'is_visible'");
            if ($colC && mysqli_num_rows($colC) === 0) {
                mysqli_query($conn, "ALTER TABLE categories ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1");
            }
        }
    }
    return $conn;
}

/* =========================================================
   3. НАСТРОЙКИ
   ========================================================= */
function get_setting($key, $default = null) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db, "SELECT setting_value FROM settings WHERE setting_key = ?");
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? $row['setting_value'] : $default;
}

/* =========================================================
   4. ИЗОБРАЖЕНИЯ
   ========================================================= */
function get_no_photo_url($category_name = null) {
    if ($category_name) {
        // Сначала ищем заглушку с названием конкретной категории.
        $translit = strtr(mb_strtolower(trim($category_name)), [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts',
            'ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
        ]);
        $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', $translit), '-');
        if ($slug !== '') {
            foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
                $candidate = 'images/no-photo-' . $slug . '.' . $ext;
                if (file_exists(__DIR__ . '/' . $candidate)) return $candidate;
            }
        }

        // Совместимость с уже используемой заглушкой для рыбной категории.
        if (mb_stripos($category_name, 'рыб') !== false) {
            $fish = 'images/no-photo-fish.webp';
            if (file_exists(__DIR__ . '/' . $fish)) return $fish;
        }
    }
    return 'images/no-photo.webp';
}

function safe_image_url($url, $category_name = null) {
    $no_photo = get_no_photo_url($category_name);
    $url = trim($url ?? '');
    // Пусто или внешняя ссылка — заглушка
    if ($url === '' || strpos($url, 'http') === 0) {
        return $no_photo;
    }

    // Если рядом есть .webp-версия — отдаём её
    $webpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $url);
    $webpPath = __DIR__ . '/' . ltrim($webpUrl, '/');

    if (file_exists($webpPath)) {
        return $webpUrl;
    }

    // Проверяем, что оригинальный файл физически существует
    $origPath = __DIR__ . '/' . ltrim($url, '/');
    if (!file_exists($origPath)) {
        return $no_photo;
    }

    return $url;
}

/* =========================================================
   5. JSON-ОТВЕТ
   ========================================================= */
function send_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* =========================================================
   6. АВТОРИЗАЦИЯ / СЕССИЯ
   ========================================================= */
function is_admin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}

function is_logged_in() {
    return isset($_SESSION['user']);
}

function get_current_user_data() {
    return $_SESSION['user'] ?? null;
}

function authenticate_user($login, $password) {
    $login = trim($login);
    $password = (string)$password;

    if (empty($login)) {
        return ['success' => false, 'message' => 'Введите логин'];
    }

    $db = get_db_connection();
    $stmt = mysqli_prepare($db, "SELECT id, login, password_hash, role FROM users WHERE login = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $login);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($user) {
            if (!password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Неверный логин или пароль'];
            }
            session_regenerate_id(true);
            $_SESSION['verified_login'] = true;
            $_SESSION['user'] = [
                'id'    => (int)$user['id'],
                'login' => $user['login'],
                'role'  => $user['role'],
                'name'  => $user['login']
            ];
            return ['success' => true, 'role' => $user['role'], 'user' => $_SESSION['user']];
        }
    }

    return ['success' => false, 'message' => 'Неверный логин или пароль'];
}

function logout_user() {
    unset($_SESSION['user']);
    session_destroy();
}

/* =========================================================
   7. ЗАПРОСЫ К БД
   ========================================================= */
function get_categories($only_visible = false) {
    $db = get_db_connection();
    $sql = "SELECT * FROM categories";
    if ($only_visible) $sql .= " WHERE is_visible = 1";
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $result = mysqli_query($db, $sql);
    if (!$result) return [];
    $categories = [];
    while ($row = mysqli_fetch_assoc($result)) $categories[] = $row;
    return $categories;
}

function get_products($category_id = null, $exclude_hidden = false) {
    $db = get_db_connection();
    $vis_filter = $exclude_hidden ? " AND (c.is_visible = 1 OR c.id IS NULL)" : "";
    if ($category_id && is_numeric($category_id)) {
        $stmt = mysqli_prepare($db, "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.category_id = ? $vis_filter ORDER BY p.sort_order ASC, p.id ASC");
        mysqli_stmt_bind_param($stmt, "i", $category_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($db, "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE 1=1 $vis_filter ORDER BY p.sort_order ASC, p.id ASC");
    }
    if (!$result) return [];
    $products = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Подменяем картинку из БД на поиск по ID
        $row['image'] = get_product_image($row['id'], $row['category_name'] ?? null);
        $row['no_photo'] = get_no_photo_url($row['category_name'] ?? null);
        $products[] = $row;
    }
    return $products;
}

function get_combos() {
    $db = get_db_connection();
    $result = mysqli_query($db, "SELECT * FROM combos ORDER BY id ASC");
    if (!$result) return [];
    $combos = [];
    while ($row = mysqli_fetch_assoc($result)) $combos[] = $row;
    return $combos;
}

function get_shops() {
    $db = get_db_connection();
    $result = mysqli_query($db, "SELECT * FROM shops ORDER BY id ASC");
    if (!$result) return [];
    $shops = [];
    while ($row = mysqli_fetch_assoc($result)) $shops[] = $row;
    return $shops;
}

function get_product_image($id, $category_name = null) {
    $extensions = ['webp', 'jpg', 'jpeg', 'png'];
    foreach ($extensions as $ext) {
        $path = "images/image-{$id}.{$ext}";
        if (file_exists(__DIR__ . '/' . $path)) {
            return $path;
        }
    }
    return get_no_photo_url($category_name);
}

function get_products_stock_summary(array $product_ids) {
    if (empty($product_ids)) return [];
    $db = get_db_connection();
    $ids = implode(',', array_map('intval', $product_ids));
    $r = mysqli_query($db, "SELECT product_id, COUNT(*) AS cnt, MIN(NULLIF(price, 0)) AS min_price FROM shop_stock WHERE product_id IN ($ids) AND quantity > 0 GROUP BY product_id");
    $out = [];
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $out[(int)$row['product_id']] = [
                'cnt' => (int)$row['cnt'],
                'min_price' => (float)$row['min_price']
            ];
        }
    }
    return $out;
}

/* =========================================================
   8. ИЗБРАННОЕ
   ========================================================= */
function ensure_favorites_table($db) {
    static $done = false;
    if ($done) return;
    mysqli_query($db, "CREATE TABLE IF NOT EXISTS favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fav (user_id, product_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function get_user_favorites() {
    if (!is_logged_in()) return [];
    $db = get_db_connection();
    ensure_favorites_table($db);
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) return [];
    $stmt = mysqli_prepare($db, "SELECT product_id FROM favorites WHERE user_id = ?");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ids = [];
    while ($row = mysqli_fetch_assoc($res)) $ids[] = (int)$row['product_id'];
    return $ids;
}

function toggle_favorite_product($product_id) {
    if (!is_logged_in()) {
        send_json_response(['success' => false, 'need_auth' => true, 'message' => 'Войдите, чтобы добавлять товары в избранное'], 401);
    }
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        send_json_response(['success' => false, 'need_auth' => true, 'message' => 'Войдите, чтобы добавлять товары в избранное'], 401);
    }
    $db = get_db_connection();
    ensure_favorites_table($db);

    $chk = mysqli_prepare($db, "SELECT id FROM favorites WHERE user_id = ? AND product_id = ?");
    mysqli_stmt_bind_param($chk, "ii", $uid, $product_id);
    mysqli_stmt_execute($chk);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);

    if ($existing) {
        $del = mysqli_prepare($db, "DELETE FROM favorites WHERE id = ?");
        mysqli_stmt_bind_param($del, "i", $existing['id']);
        mysqli_stmt_execute($del);
        send_json_response(['success' => true, 'favorited' => false, 'message' => 'Товар удалён из избранного']);
    }

    $ins = mysqli_prepare($db, "INSERT INTO favorites (user_id, product_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($ins, "ii", $uid, $product_id);
    if (mysqli_stmt_execute($ins)) {
        send_json_response(['success' => true, 'favorited' => true, 'message' => 'Товар добавлен в избранное']);
    }
    send_json_response(['success' => false, 'message' => 'Не удалось изменить избранное'], 500);
}

/* =========================================================
   9. ОБРАБОТЧИК API
   ========================================================= */
function handle_api_requests() {
    $action = $_GET['action'] ?? $_GET['api'] ?? '';
    if (empty($action)) return;

    switch ($action) {

        /* ---------- Данные ---------- */
        case 'categories':
            send_json_response(['success' => true, 'data' => get_categories(!is_admin())]);
            break;

        case 'products':
            $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
            $data = get_products($category_id, !is_admin());
            if (!is_admin() && get_setting('prices_enabled', '1') !== '1') {
                foreach ($data as &$item) unset($item['price']);
                unset($item);
            }
            send_json_response(['success' => true, 'data' => $data]);
            break;
			
        case 'combos':
            $data = get_combos();
            if (!is_admin() && get_setting('prices_enabled', '1') !== '1') {
                foreach ($data as &$item) { unset($item['price'], $item['old_price']); }
                unset($item);
            }
            send_json_response(['success' => true, 'data' => $data]);
            break;

        case 'shops':
            send_json_response(['success' => true, 'data' => get_shops()]);
            break;

        case 'product_stock':
            $pid = (int)($_GET['product_id'] ?? 0);
            if ($pid <= 0) { send_json_response(['success' => false, 'message' => 'bad id'], 400); }
            $db = get_db_connection();
            $stmt = mysqli_prepare($db, "SELECT s.id, s.name, s.address, s.phone, s.working_hours, ss.price, ss.quantity FROM shops s LEFT JOIN shop_stock ss ON ss.shop_id = s.id AND ss.product_id = ? ORDER BY s.id ASC");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $out = [];
            $show_prices = is_admin() || get_setting('prices_enabled', '1') === '1';
            while ($row = mysqli_fetch_assoc($r)) {
                $row['has_price'] = (float)($row['price'] ?? 0) > 0;
                if (!$show_prices) unset($row['price']);
                $out[] = $row;
            }
            send_json_response(['success' => true, 'data' => $out]);
            break;

        /* ---------- Авторизация ---------- */
        case 'auth':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?? $_POST;
                $login    = $input['login'] ?? '';
                $password = $input['password'] ?? '';
                $result   = authenticate_user($login, $password);
                send_json_response($result, $result['success'] ? 200 : 401);
            }
            break;

        case 'register':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') break;

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?? $_POST;
            $login    = trim($input['login'] ?? '');
            $password = $input['password'] ?? '';
            $confirm  = $input['confirm'] ?? '';

            /* Валидация */
            if ($login === '' || $password === '') {
                send_json_response(['success' => false, 'message' => 'Заполните все поля'], 400);
            }
            if (mb_strlen($login) < 3) {
                send_json_response(['success' => false, 'message' => 'Логин должен быть не короче 3 символов'], 400);
            }
            if (mb_strlen($login) > 50) {
                send_json_response(['success' => false, 'message' => 'Логин слишком длинный (макс. 50)'], 400);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/u', $login)) {
                send_json_response(['success' => false, 'message' => 'Логин: только латиница, цифры, _ - .'], 400);
            }
            if (strlen($password) < 6) {
                send_json_response(['success' => false, 'message' => 'Пароль должен быть не короче 6 символов'], 400);
            }
            if ($password !== $confirm) {
                send_json_response(['success' => false, 'message' => 'Пароли не совпадают'], 400);
            }
            if (strtolower($login) === 'admin') {
                send_json_response(['success' => false, 'message' => 'Логин «admin» зарезервирован'], 409);
            }

            /* Проверка уникальности и создание */
            $db = get_db_connection();
            $stmt = mysqli_prepare($db, "SELECT id FROM users WHERE login = ?");
            mysqli_stmt_bind_param($stmt, "s", $login);
            mysqli_stmt_execute($stmt);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                send_json_response(['success' => false, 'message' => 'Пользователь с таким логином уже существует'], 409);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($db, "INSERT INTO users (login, password_hash, role) VALUES (?, ?, 'user')");
            mysqli_stmt_bind_param($stmt, "ss", $login, $hash);
            if (mysqli_stmt_execute($stmt)) {
                session_regenerate_id(true);
                $_SESSION['verified_login'] = true;
                $_SESSION['user'] = [
                    'id'    => (int)mysqli_insert_id($db),
                    'login' => $login,
                    'role'  => 'user',
                    'name'  => $login
                ];
                send_json_response([
                    'success' => true,
                    'user'    => $_SESSION['user'],
                    'message' => 'Регистрация успешна'
                ]);
            } else {
                send_json_response(['success' => false, 'message' => 'Ошибка при создании пользователя'], 500);
            }
            break;

        case 'current_user':
            send_json_response([
                'success'   => true,
                'logged_in' => is_logged_in(),
                'user'      => get_current_user_data()
            ]);
            break;

        case 'logout':
            logout_user();
            send_json_response(['success' => true]);
            break;

        /* ---------- Избранное ---------- */
        case 'favorites':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?? $_POST;
                $pid = (int)($input['product_id'] ?? 0);
                if ($pid <= 0) {
                    send_json_response(['success' => false, 'message' => 'Некорректный ID товара'], 400);
                }
                toggle_favorite_product($pid);
            } else {
                send_json_response(['success' => true, 'favorites' => get_user_favorites()]);
            }
            break;

        /* ---------- Заказ ---------- */
        case 'order':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $raw = file_get_contents('php://input');
                $order = json_decode($raw, true) ?? $_POST;
                send_json_response([
                    'success'  => true,
                    'order_id' => rand(1000, 9999),
                    'message'  => 'Заказ успешно оформлен! Продавец в магазине уже собирает ваш заказ к вашему визиту.'
                ]);
            }
            break;

        default:
            send_json_response(['success' => false, 'message' => 'Неизвестный API эндпоинт'], 404);
            break;
    }
}

/* =========================================================
   9. API-РОУТЕР
   ========================================================= */
if (isset($_GET['api']) || isset($_GET['action'])) {
    handle_api_requests();
    exit;
}

/* =========================================================
   10. ЗАГРУЗКА ДАННЫХ ДЛЯ ВИТРИНЫ
   ========================================================= */
require_once __DIR__ . '/attributes.php';

$categories = get_categories(!is_admin());
$products   = get_products(null, !is_admin());
$product_attrs = get_products_attributes_bulk(array_column($products, 'id'));
$combos     = get_combos();
$shops      = get_shops();

$is_admin_view  = is_admin();
$cart_enabled   = $is_admin_view || get_setting('cart_enabled', '1') === '1';
$combos_enabled = $is_admin_view || get_setting('combos_enabled', '1') === '1';
$hide_no_photo  = !$is_admin_view && get_setting('hide_no_photo', '0') === '1';
$prices_enabled = $is_admin_view || get_setting('prices_enabled', '1') === '1';
$hide_pwa_install = get_setting('hide_pwa_install', '0') === '1';
$favorites_ids    = get_user_favorites();

$favorite_drink_discount = ['product_id' => 0, 'discount_percent' => 0];
$fdTableCheck = mysqli_query(get_db_connection(), "SHOW TABLES LIKE 'favorite_drinks'");
$fdUserId = (int)($_SESSION['user']['id'] ?? 0);
if ($fdUserId > 0 && $fdTableCheck && mysqli_num_rows($fdTableCheck) > 0) {
    $fdq = mysqli_prepare(get_db_connection(), "SELECT product_id,discount_percent FROM favorite_drinks WHERE user_id=? AND product_id IS NOT NULL");
    mysqli_stmt_bind_param($fdq, 'i', $fdUserId);
    mysqli_stmt_execute($fdq);
    $fdrow = mysqli_fetch_assoc(mysqli_stmt_get_result($fdq));
    if ($fdrow) $favorite_drink_discount = ['product_id'=>(int)$fdrow['product_id'], 'discount_percent'=>(float)$fdrow['discount_percent']];
}

// Атрибуты, по которым можно фильтровать витрину
$type_attr = null;
$proc_attr = null;
foreach (get_attributes() as $a) {
    if ($a['code'] === 'beer_type')  $type_attr = $a;
    if ($a['code'] === 'processing') $proc_attr = $a;
}

// ФИЛЬТРАЦИЯ ТОВАРОВ БЕЗ ФОТО
if ($hide_no_photo) {
    $products = array_filter($products, function ($p) {
        return safe_image_url($p['image'], $p['category_name'] ?? null) !== get_no_photo_url($p['category_name'] ?? null);
    });
    $products = array_values($products);
}

// Сводка по наличию в магазинах (кол-во магазинов, где quantity>0)
$stock_summary = get_products_stock_summary(array_column($products, 'id'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <!-- =========================================================
         11. HTML: <head>
         ========================================================= -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пивляндия — Сеть магазинов разливного и крафтового пива в Симферополе</title>
    <meta name="description" content="Свежее разливное пиво, крафтовые сорта, сидры и вкуснейшие закуски в 7 магазинах «Пивляндия» по всему Симферополю. Выгодные комбо-наборы!">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#f59e0b">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Пивляндия">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime('style.css') ?: time() ?>">
</head>
<body data-prices-enabled="<?= $prices_enabled ? '1' : '0' ?>" data-hide-pwa="<?= $hide_pwa_install ? '1' : '0' ?>">
<script>
(function(){
    function hidePrices() {
        if (document.body.getAttribute('data-prices-enabled') === '1') return;
        document.querySelectorAll('[data-price], [data-price-per-liter], .price, .product-price, .product-price-label, .from-price, .card-price, .item-price, .combo-price, .combo-old-price, .current-price, .old-price, .price-per-liter, .per-liter, .liter-price, .price-liter, .price-1l, .card-price-per-liter').forEach(function(el){ el.style.display = 'none'; });
        var w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null), n, hits = [];
        while (n = w.nextNode()) {
            var t = (n.nodeValue || '').trim();
            if (/^от\s*\d/i.test(t) || /^\d+\s*₽$/i.test(t) || /^null\s*₽$/i.test(t) || /^цена\s+за/i.test(t)) hits.push(n.parentNode);
        }
        hits.forEach(function(el){ if (el && el.style) el.style.display = 'none'; });
    }
    if (document.body) hidePrices();
    document.addEventListener('DOMContentLoaded', hidePrices);
    try { new MutationObserver(hidePrices).observe(document.body, { childList: true, subtree: true }); } catch(e) {}
})();
</script>
<script>
(function(){
    function pmEsc(s){ return String(s||'').replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function pmLogout(){ fetch('index.php?api=logout', {method:'POST', credentials:'same-origin'}).then(function(){ location.reload(); }).catch(function(){ location.reload(); }); }
    window.pmLogout = pmLogout;

    window.pmRenderUserMenu = function(user){
        var oldWrap = document.querySelector('.header-actions .user-menu-wrap');
        var btn = document.getElementById('auth-btn');
        if (!user) {
            if (oldWrap) {
                var loginBtn = document.createElement('button');
                loginBtn.id = 'auth-btn';
                loginBtn.className = 'btn btn-outline btn-sm';
                loginBtn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> <span class="auth-btn-text">Войти</span>';
                loginBtn.addEventListener('click', function(){ if (typeof openModal === 'function') openModal('auth-modal'); });
                oldWrap.parentNode.replaceChild(loginBtn, oldWrap);
            }
            return;
        }
        if (oldWrap) {
            var existingName = oldWrap.querySelector('.user-menu-name');
            if (existingName) existingName.textContent = user.login || user.name || 'Профиль';
            return;
        }
        btn = document.getElementById('auth-btn');
        if (!btn) return;
        var wrap = document.createElement('div');
        wrap.className = 'user-menu-wrap';
        wrap.innerHTML = '<button type="button" class="btn btn-outline btn-sm user-menu-toggle"><i class="fa-solid fa-user"></i> <span class="user-menu-name">' + pmEsc(user.login || user.name || 'Профиль') + '</span> <i class="fa-solid fa-chevron-down" style="font-size:.7rem;opacity:.7;"></i></button>'
            + '<div class="user-menu-dropdown">'
            + '<a href="cabinet.php" class="user-menu-item"><i class="fa-solid fa-id-card"></i> Личный кабинет</a>'
            + '<a href="javascript:void(0)" class="user-menu-item" onclick="pmLogout();return false;"><i class="fa-solid fa-arrow-right-from-bracket"></i> Выйти</a>'
            + '</div>';
        btn.parentNode.replaceChild(wrap, btn);
        var toggle = wrap.querySelector('.user-menu-toggle');
        toggle.addEventListener('click', function(e){ e.stopPropagation(); wrap.classList.toggle('open'); });
        if (!window.pmMenuOutsideClickBound) {
            document.addEventListener('click', function(e){
                document.querySelectorAll('.user-menu-wrap.open').forEach(function(el){ if(!el.contains(e.target)) el.classList.remove('open'); });
            });
            window.pmMenuOutsideClickBound = true;
        }
    };

    document.addEventListener('DOMContentLoaded', function(){
        fetch('index.php?api=current_user', {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){ if(d && d.success && d.logged_in) window.pmRenderUserMenu(d.user || {}); })
        .catch(function(){});
    });
})();
</script>
<style>
.user-menu-wrap { position: relative; display: inline-block; }
.user-menu-wrap .user-menu-toggle { display: inline-flex; align-items: center; gap: 6px; }
.user-menu-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 8px); min-width: 200px; background: #1a1f2e; border: 1px solid var(--border-color, rgba(255,255,255,.12)); border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,.45); padding: 6px; z-index: 1000; }
.user-menu-wrap.open .user-menu-dropdown { display: block; }
.user-menu-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; color: var(--text-primary, #fff); text-decoration: none; font-size: .9rem; }
.user-menu-item:hover { background: rgba(245,158,11,.12); color: var(--gold-light, #fbbf24); }
.user-menu-item i { width: 16px; text-align: center; }
@media (max-width: 600px) {
    .user-menu-name { max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
}
</style>

    <!-- =========================================================
         12. HTML: фон и 18+ модалка
         ========================================================= -->
    <ul class="bg-bubbles"></ul>

    <div id="age-modal" class="modal-overlay">
        <div class="modal-box" style="text-align: center;">
            <div style="font-size: 3.5rem; color: var(--gold-light); margin-bottom: 16px;">
                <i class="fa-solid fa-beer-mug-empty"></i>
            </div>
            <h2 class="neon-title" style="font-size: 1.8rem; margin-bottom: 12px;">ПИВЛЯНДИЯ</h2>
            <h3 style="font-size: 1.2rem; margin-bottom: 14px;">Вам уже исполнилось 18 лет?</h3>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 24px;">
                Сайт сети магазинов «Пивляндия» содержит информацию об алкогольной продукции и предназначен только для совершеннолетних посетителей.
            </p>
            <div class="age-buttons">
                <button class="btn btn-primary btn-lg" onclick="confirmAge(true)">
                    <i class="fa-solid fa-check"></i> Да, мне есть 18
                </button>
                <button class="btn btn-secondary btn-lg" onclick="confirmAge(false)">
                    Нет, мне меньше
                </button>
            </div>
        </div>
    </div>

    <!-- =========================================================
         13. HTML: шапка
         ========================================================= -->
    <header class="site-header">
        <div class="container">
            <div class="header-inner">
                <a href="index.php" class="neon-logo">
                    <span class="logo-icon"><i class="fa-solid fa-beer-mug-empty"></i></span>
                    <div>
                        <span class="brand-name">ПИВЛЯНДИЯ</span>
                        <div style="font-size: 0.7rem; color: var(--gold-light); letter-spacing: 1px;">СИМФЕРОПОЛЬ • 7 ТОЧЕК</div>
                    </div>
                </a>

                <nav>
                    <ul class="nav-links">
                        <li><a href="#catalog-section" class="nav-link">Каталог сортов</a></li>

                        <?php if ($combos_enabled): ?>
                        <li><a href="#combos-section" class="nav-link">Комбо-наборы</a></li>
                        <?php endif; ?>

                        <li><a href="#shops-section" class="nav-link">Магазины на карте</a></li>
                    </ul>
                </nav>

                <div class="header-actions">
                    <a href="javascript:void(0)" class="header-phone" onclick="openModal('phone-info-modal')">
                        <i class="fa-solid fa-phone-volume"></i>
                        <span></span>
                    </a>
                    <?php if ($cart_enabled): ?>
                    <button class="cart-btn" onclick="toggleCartDrawer(true)">
                        <i class="fa-solid fa-basket-shopping" style="color: var(--gold-primary);"></i>
                        <span class="cart-count">0</span>
                    </button>
                    <?php endif; ?>
                    <button id="auth-btn" class="btn btn-outline btn-sm" onclick="openModal('auth-modal')">
                        <i class="fa-solid fa-right-to-bracket"></i> <span class="auth-btn-text">Войти</span>
                    </button>
                    <button class="burger-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-bars"></i></button>
                </div>
            </div>
        </div>
    </header>
    <div id="mobile-menu-overlay" class="mobile-menu-overlay" onclick="toggleMobileMenu()"></div>

    <!-- =========================================================
         14. HTML: hero-секция
         ========================================================= -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-grid">
                <div class="hero-content">
                    <span class="neon-badge"><i class="fa-solid fa-award"></i> Крымская сеть свежего розлива</span>
                    <h1 style="margin-top: 14px;" class="hero-animated-title">
                        Вкусное разливное пиво <br />
                        <span class="gradient-text">в Симферополе</span>
                    </h1>
                    
                    <!-- Блок преимуществ -->
                    <div class="hero-badges">
                        <!-- 1. Более 60 сортов (Кружка) -->
                        <div class="hero-badge-item" onclick="showBadgeTooltip(this)" data-tooltip="Более 60 сортов отборного пенного: от классических золотистых лагеров до крафтовых IPA и фруктовых сидров. Всегда свежее, холодное и с лучшими закусками!">
                            <svg class="badge-svg" viewBox="0 0 24 24">
                                <path class="base-path" pathLength="100" d="M4 7h12v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z M16 9h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2 M8 11h4" />
                                <path class="trace-path" pathLength="100" d="M4 7h12v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z M16 9h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2 M8 11h4" />
                            </svg>
                            <svg class="orbit-svg" viewBox="0 0 100 100"><circle class="orbit-path" cx="50" cy="50" r="50" pathLength="100" /></svg>
                            <div class="orbit-dot"></div>
                            <span class="badge-text">Более 60 сортов</span>
                        </div>

                        <!-- 2. Холодильные камеры (Термометр) -->
                        <div class="hero-badge-item" onclick="showBadgeTooltip(this)" data-tooltip="Холодильные камеры +4°C">
                            <svg class="badge-svg" viewBox="0 0 24 24">
                                <path class="base-path" pathLength="100" d="M10 13.5V5a2 2 0 0 1 4 0v8.5a5 5 0 1 1-4 0z M12 8v6" />
                                <path class="trace-path" pathLength="100" d="M10 13.5V5a2 2 0 0 1 4 0v8.5a5 5 0 1 1-4 0z M12 8v6" />
                            </svg>
                            <svg class="orbit-svg" viewBox="0 0 100 100"><circle class="orbit-path" cx="50" cy="50" r="50" pathLength="100" /></svg>
                            <div class="orbit-dot"></div>
                            <span class="badge-text">Холодильные камеры +4°C</span>
                        </div>

                        <!-- 3. Промывка кранов (Часы) -->
                        <div class="hero-badge-item" onclick="showBadgeTooltip(this)" data-tooltip="Ежедневная промывка кранов">
                            <svg class="badge-svg" viewBox="0 0 24 24">
                                <path class="base-path" pathLength="100" d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z M12 6v6l4 2" />
                                <path class="trace-path" pathLength="100" d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z M12 6v6l4 2" />
                            </svg>
                            <svg class="orbit-svg" viewBox="0 0 100 100"><circle class="orbit-path" cx="50" cy="50" r="50" pathLength="100" /></svg>
                            <div class="orbit-dot"></div>
                            <span class="badge-text">Ежедневная промывка кранов</span>
                        </div>

                        <!-- 4. Дисконт (Карта) -->
                        <div class="hero-badge-item" onclick="showBadgeTooltip(this)" data-tooltip="Дисконтная система">
                            <svg class="badge-svg" viewBox="0 0 24 24">
                                <path class="base-path" pathLength="100" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                <path class="trace-path" pathLength="100" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                            <svg class="orbit-svg" viewBox="0 0 100 100"><circle class="orbit-path" cx="50" cy="50" r="50" pathLength="100" /></svg>
                            <div class="orbit-dot"></div>
                            <span class="badge-text">Дисконтная система</span>
                        </div>

                        <!-- 5. Акции (Проценты) -->
                        <div class="hero-badge-item" onclick="showBadgeTooltip(this)" data-tooltip="Акции и скидки">
                            <svg class="badge-svg" viewBox="0 0 24 24">
                                <path class="base-path" pathLength="100" d="M19 5L5 19M6.5 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM17.5 20a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" />
                                <path class="trace-path" pathLength="100" d="M19 5L5 19M6.5 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM17.5 20a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" />
                            </svg>
                            <svg class="orbit-svg" viewBox="0 0 100 100"><circle class="orbit-path" cx="50" cy="50" r="50" pathLength="100" /></svg>
                            <div class="orbit-dot"></div>
                            <span class="badge-text">Акции и скидки</span>
                        </div>
                    </div>

                    <!-- Этот текст останется только на ПК -->
                    <p class="hero-description">
                        Более 60 сортов отборного пенного: от классических золотистых лагеров до крафтовых IPA и фруктовых сидров. Всегда свежее, холодное и с лучшими закусками!
                    </p>
                    <div class="hero-actions">
                        <a href="#catalog-section" class="btn btn-primary btn-lg">
                            <i class="fa-solid fa-beer-mug-empty"></i> Выбрать сорт
                        </a>
                        <a href="#shops-section" class="btn btn-secondary btn-lg">
                            <i class="fa-solid fa-map-location-dot"></i> Найти магазин
                        </a>
                    </div>
                </div>
                <div class="hero-visual">
                    <div class="hero-card-glow">
                        <?php
                        $slideDir = __DIR__ . '/images/slide';
                        $slideImages = [];
                        if (is_dir($slideDir)) {
                            $files = glob($slideDir . '/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG,WEBP,GIF}', GLOB_BRACE);
                            if ($files) {
                                sort($files);
                                foreach ($files as $f) {
                                    $slideImages[] = 'images/slide/' . basename($f);
                                }
                            }
                        }
                        ?>
                        <div class="hero-slideshow" id="hero-slideshow">
                            <?php if (empty($slideImages)): ?>
                                <img src="images/no-photo.webp" alt="Пивляндия" class="active" />
                            <?php else: ?>
                                <?php
                                // Случайный стартовый кадр, чтобы при каждой загрузке было разное фото
                                $firstIdx = array_rand($slideImages);
                                foreach ($slideImages as $i => $img): ?>
                                    <img src="<?= htmlspecialchars($img) ?>"
                                         alt="Пивляндия крафтовое пиво"
                                         <?= $i === $firstIdx ? 'class="active"' : '' ?>
                                         loading="eager"
                                         onerror="this.style.display='none'" />
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="hero-card-floating">
                            <div>
                                <strong id="store-status-title" style="color: var(--gold-light); display: block; transition: color 0.3s;">7 магазинов открыты сегодня</strong>
                                <span id="store-status-subtitle" style="font-size: 0.85rem; color: var(--text-secondary);">Работаем до 23:00 без выходных</span>
                            </div>
                            <div class="status-actions-wrapper">
                                <span id="store-status-badge" class="neon-badge" style="display: none; border-color: #10b981; color: #10b981; transition: all 0.3s; align-items: center;">
                                    <span class="status-dot" id="status-dot-indicator"></span> <span id="status-text">Открыто</span>
                                </span>
                                <a href="#shops-section" class="btn btn-primary btn-sm mobile-find-shop-btn">
                                    <i class="fa-solid fa-location-dot"></i> Найти магазин
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         15. HTML: каталог сортов
         ========================================================= -->
    <section id="catalog-section" class="section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag"><i class="fa-solid fa-beer-mug-empty"></i> Свежий розлив</span>
                <h2 class="section-title">Каталог сортов</h2>
                <p class="section-subtitle">Выберите интересующую категорию для фильтрации нашего ассортимента</p>
            </div>

            <?php
			// Собираем кнопки фильтра: категории + значения атрибутов
			$filter_groups = [];

			// 1. Категории (Пиво, Рыба и т.п.)
			foreach ($categories as $cat) {
				$cnt = 0;
				foreach ($products as $p) {
					if ((int)$p['category_id'] === (int)$cat['id']) $cnt++;
				}
				if ($cnt > 0) {
					$filter_groups[] = [
						'label' => $cat['name'],
						'value' => $cat['name'],
						'icon'  => $cat['icon'] ?: 'fa-tag',
						'count' => $cnt,
						'type'  => 'category',
						'cat_id' => $cat['id']
					];
				}
			}

			// 2. Значения атрибутов «Тип» и «Способ обработки»
				foreach ([$type_attr, $proc_attr] as $attr) {
					if (!$attr) continue;
					if (empty($attr['is_visible']) && !is_admin()) continue;
					foreach ($attr['options_arr'] as $opt) {
					$cnt = 0;
					foreach ($products as $p) {
						$vals = isset($product_attrs[(int)$p['id']][$attr['code']])
							? array_column($product_attrs[(int)$p['id']][$attr['code']], 'value')
							: [];
						if (in_array($opt, $vals, true)) $cnt++;
					}
					if ($cnt > 0) {
						$filter_groups[] = [
							'label' => $opt,
							'value' => $opt,
							'icon'  => 'fa-circle',
							'count' => $cnt,
							'type'  => 'attribute',
							'cat_id' => $attr['category_id'] ?? 0
						];
					}
				}
			}
			?>
			<div id="category-filter-container" class="category-filter">
				<button class="category-tab active" data-category="" data-type="all" data-cat-id="0" onclick="selectCategory(null)">
					<i class="fa-solid fa-layer-group"></i> Все товары (<?= count($products) ?>)
				</button>
				<?php foreach ($filter_groups as $fg): ?>
					<button class="category-tab"
							data-category="<?= htmlspecialchars($fg['value']) ?>"
							data-type="<?= $fg['type'] ?>"
							data-cat-id="<?= $fg['cat_id'] ?>"
							onclick="selectCategory(<?= htmlspecialchars(json_encode($fg['value']), ENT_QUOTES) ?>)">
						<i class="fa-solid <?= htmlspecialchars($fg['icon']) ?>"></i>
						<?= htmlspecialchars($fg['label']) ?> (<?= $fg['count'] ?>)
					</button>
				<?php endforeach; ?>
			</div>

            <div id="products-grid" class="products-grid">
				<?php foreach ($products as $prod): ?>
					<?php
					$card_fallback = get_no_photo_url($prod['category_name'] ?? null);
					$p_tags = [];
					if (!empty($prod['category_name'])) $p_tags[] = $prod['category_name'];
					foreach (['beer_type', 'processing'] as $code) {
						if (!empty($product_attrs[(int)$prod['id']][$code])) {
							foreach ($product_attrs[(int)$prod['id']][$code] as $av) {
								$p_tags[] = $av['value'];
							}
						}
					}
					$p_tags = array_values(array_unique(array_filter($p_tags)));
					?>
					<div class="product-card" data-category="<?= htmlspecialchars(implode(',', $p_tags)) ?>" data-product-id="<?= (int)$prod['id'] ?>">
                        <div class="product-image-box">
                            <img src="<?= safe_image_url($prod['image'], $prod['category_name'] ?? null) ?>" alt="<?= htmlspecialchars($prod['name']) ?>" loading="lazy" onerror="this.src='<?= $card_fallback ?>'" />
                            <?php if (!empty($prod['manufacturer'])): ?>
                                <div class="product-image-title">
                                    <span><?= htmlspecialchars($prod['manufacturer']) ?></span>
                                </div>
                            <?php endif; ?>
                            <span class="product-badge"><?= htmlspecialchars($prod['category_name'] ?? 'Разливное') ?></span>
                            <span class="product-volume-tag"><?= htmlspecialchars($prod['volume'] ?? '1.0 л') ?></span>
                            <button type="button" class="fav-btn<?= in_array((int)$prod['id'], $favorites_ids) ? ' active' : '' ?>" data-product-id="<?= (int)$prod['id'] ?>" aria-label="В избранное" title="В избранное" onclick="toggleFavorite(<?= (int)$prod['id'] ?>, event)">
                                <i class="fa-<?= in_array((int)$prod['id'], $favorites_ids) ? 'solid' : 'regular' ?> fa-heart"></i>
                            </button>
                        </div>
                        <div class="product-content">
                            <h3 class="product-title"><?= htmlspecialchars($prod['name']) ?></h3>
                            <p class="product-desc"><?= htmlspecialchars($prod['description']) ?></p>
                            <button type="button" class="desc-toggle" onclick="toggleDescription(this)">
                                <span class="desc-toggle-text">развернуть</span>
                                <i class="fa-solid fa-chevron-down"></i>
                            </button>

							<?php
							$prod_attrs = $product_attrs[(int)$prod['id']] ?? [];
							$spec_attrs = [];
							foreach ($prod_attrs as $code => $rows) {
								$first = $rows[0];
								if ($first['type'] !== 'number' && $first['type'] !== 'text') continue;
								if (empty($first['is_visible']) && !is_admin()) continue;
								$spec_attrs[] = $first;
							}
							?>
							<?php if (!empty($spec_attrs)): ?>
							<div class="product-specs">
								<?php foreach (array_slice($spec_attrs, 0, 4) as $spec):
									$val = implode(', ', array_column($prod_attrs[$spec['code']], 'value'));
								?>
									<div class="spec-item">
										<span><?= htmlspecialchars($spec['name']) ?></span>
										<strong><?= htmlspecialchars($val) ?><?= $spec['unit'] ? ' ' . htmlspecialchars($spec['unit']) : '' ?></strong>
									</div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>

                            <div class="product-footer">
                                <?php
								$is_weight = strpos($prod['volume'] ?? '', 'г') !== false;
								$price_lbl = $is_weight ? 'Цена за ' . htmlspecialchars($prod['volume']) : 'Цена за 1 л';
                                    $is_favorite_drink = (int)$favorite_drink_discount['product_id'] === (int)$prod['id'];
                                    $favorite_price = $is_favorite_drink ? round((float)$prod['price'] * (1 - (float)$favorite_drink_discount['discount_percent'] / 100), 2) : (float)$prod['price'];
								?>
                                <?php if ($prices_enabled): ?>
								<div class="product-price-box">
									<span class="product-price-label"><?= $price_lbl ?></span>
                                    <?php if ($is_favorite_drink): ?><span style="font-size:.78rem;color:var(--gold-light);">Любимый напиток · −<?= number_format($favorite_drink_discount['discount_percent'],2,',',' ') ?>%</span><span style="font-size:.82rem;text-decoration:line-through;color:var(--text-muted);"><?= number_format($prod['price'], 0, '', ' ') ?> ₽</span><?php endif; ?>
									<span class="product-price"><?= number_format($favorite_price, 0, '', ' ') ?> ₽</span>
								</div>
                                <?php endif; ?>
                                <?php if ($cart_enabled): ?>
                                <button class="btn btn-primary btn-sm" onclick="addToCart(<?= $prod['id'] ?>, 'product')">
                                    <i class="fa-solid fa-cart-plus"></i> В корзину
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="load-more-container" style="text-align: center; margin-top: 30px;">
                <button id="load-more-btn" class="btn btn-primary btn-lg" onclick="loadMoreProducts()">
                    <i class="fa-solid fa-chevron-down"></i> Показать еще
                </button>
            </div>
        </div>
    </section>

    <!-- =========================================================
         17. HTML: комбо-наборы
         ========================================================= -->
    <?php if ($combos_enabled): ?>
    <section id="combos-section" class="section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag"><i class="fa-solid fa-fire"></i> Выгодное предложение</span>
                <h2 class="section-title">Готовые комбо-наборы</h2>
                <p class="section-subtitle">Сеты из пива и фирменных снеков со скидкой до 25%. Идеально для компании!</p>
            </div>
            <div id="combos-grid" class="combos-grid">
                <?php foreach ($combos as $cb): ?>
                    <div class="combo-card">
                        <span class="combo-discount-badge">-<?= $cb['discount_percent'] ?>% СКИДКА</span>
                        <div class="combo-img-box">
                            <img src="<?= safe_image_url($cb['image']) ?>" alt="<?= htmlspecialchars($cb['name']) ?>" loading="lazy" onerror="this.src='images/no-photo.webp'" />
                        </div>
                        <div class="combo-body">
                            <h3 class="combo-title"><?= htmlspecialchars($cb['name']) ?></h3>
                            <p class="combo-desc"><?= htmlspecialchars($cb['description']) ?></p>
                            <div class="combo-price-row">
                                <?php if ($prices_enabled): ?>
                                <div class="price-group">
                                    <span class="current-price"><?= number_format($cb['price'], 0, '', ' ') ?> ₽</span>
                                    <span class="old-price"><?= number_format($cb['old_price'], 0, '', ' ') ?> ₽</span>
                                </div>
                                <?php endif; ?>
                                <?php if ($cart_enabled): ?>
                                <button class="btn btn-primary" onclick="addToCart(<?= $cb['id'] ?>, 'combo')">
                                    <i class="fa-solid fa-plus"></i> Заказать комбо
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- =========================================================
         18. HTML: магазины и карта
         ========================================================= -->
    <section id="shops-section" class="section shops-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag"><i class="fa-solid fa-map-pin"></i> Симферополь</span>
                <h2 class="section-title">7 магазинов «Пивляндия» на карте</h2>
                <p class="section-subtitle">Найдите ближайший к вам магазин</p>
            </div>
            <div class="shops-layout">
                <div id="shops-list-container" class="shops-list-wrapper">
                    <?php foreach ($shops as $idx => $s): ?>
                        <div class="shop-item <?= $idx === 0 ? 'active' : '' ?>" onclick="focusShopOnMap(<?= $s['id'] ?>, this)">
                            <h4 class="shop-item-title"><i class="fa-solid fa-location-dot" style="color: var(--gold-primary); margin-right: 6px;"></i> <?= htmlspecialchars($s['name']) ?></h4>
                            <div class="shop-item-address"><?= htmlspecialchars($s['address']) ?></div>
                            <div class="shop-item-meta">
                                <span><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($s['working_hours']) ?></span>
                                <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($s['phone']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="map-container">
                    <div id="yandex-map"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         19. HTML: корзина (drawer)
         ========================================================= -->
    <?php if ($cart_enabled): ?>
    <div id="cart-drawer-overlay" class="cart-drawer-overlay" onclick="toggleCartDrawer(false)"></div>
    <div id="cart-drawer" class="cart-drawer">
        <div class="cart-drawer-header">
            <h3 style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-basket-shopping" style="color: var(--gold-primary);"></i>
                Ваша корзина
            </h3>
            <button class="modal-close" onclick="toggleCartDrawer(false)"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="cart-items-container" class="cart-drawer-body"></div>
        <div class="cart-drawer-footer">
            <?php if ($prices_enabled): ?>
            <div class="cart-total-row">
                <span>Итого к оплате:</span>
                <span id="cart-total-price" style="color: var(--gold-light);">0 ₽</span>
            </div>
            <?php endif; ?>
            <button id="cart-order-btn" class="btn btn-primary btn-lg" style="width: 100%;" onclick="showCheckoutModal()">
                <i class="fa-solid fa-bag-shopping"></i> Перейти к оформлению
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================================================
         20. HTML: авторизация / регистрация
         ========================================================= -->
    <div id="auth-modal" class="modal-overlay">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('auth-modal')"><i class="fa-solid fa-xmark"></i></button>
            <div style="text-align: center; margin-bottom: 20px;">
                <span class="neon-badge">Вход в систему</span>
                <h3 id="auth-title" style="font-size: 1.4rem; margin-top: 10px;">Авторизация</h3>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 20px;">
                <button type="button" id="tab-login-btn" class="btn btn-primary" style="flex: 1;" onclick="showAuthTab('login')">
                    <i class="fa-solid fa-right-to-bracket"></i> Вход
                </button>
                <button type="button" id="tab-register-btn" class="btn btn-secondary" style="flex: 1;" onclick="showAuthTab('register')">
                    <i class="fa-solid fa-user-plus"></i> Регистрация
                </button>
            </div>

            <div id="auth-error" class="alert alert-danger" style="display: none;"></div>

            <form id="login-form" onsubmit="handleAuthSubmit(event)">
                <div class="form-group">
                    <label class="form-label" for="auth-login">Логин</label>
                    <input type="text" id="auth-login" class="form-control" placeholder="Введите логин" required autocomplete="username" />
                </div>
                <div class="form-group">
                    <label class="form-label" for="auth-password">Пароль</label>
                    <input type="password" id="auth-password" class="form-control" placeholder="Введите пароль" required autocomplete="current-password" />
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 10px;">
                    <i class="fa-solid fa-right-to-bracket"></i> Войти
                </button>
            </form>

            <form id="register-form" style="display: none;" onsubmit="handleRegisterSubmit(event)">
                <div class="form-group">
                    <label class="form-label" for="reg-login">Логин <small style="color: var(--text-muted);">(минимум 3 символа)</small></label>
                    <input type="text" id="reg-login" class="form-control" placeholder="Придумайте логин" required minlength="3" maxlength="50" autocomplete="username" />
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-password">Пароль <small style="color: var(--text-muted);">(минимум 6 символов)</small></label>
                    <input type="password" id="reg-password" class="form-control" placeholder="Придумайте пароль" required minlength="6" autocomplete="new-password" />
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-confirm">Подтвердите пароль</label>
                    <input type="password" id="reg-confirm" class="form-control" placeholder="Повторите пароль" required minlength="6" autocomplete="new-password" />
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 10px;">
                    <i class="fa-solid fa-user-plus"></i> Зарегистрироваться
                </button>
            </form>
        </div>
    </div>

    <!-- =========================================================
         21. HTML: оформление заказа
         ========================================================= -->
    <?php if ($cart_enabled): ?>
    <div id="checkout-modal" class="modal-overlay">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('checkout-modal')"><i class="fa-solid fa-xmark"></i></button>
            <div style="text-align: center; margin-bottom: 20px;">
                <h3 style="font-size: 1.35rem;">Оформление самовывоза</h3>
                <p style="font-size: 0.88rem; color: var(--text-secondary);">Заказ будет собран к вашему визиту в магазин</p>
            </div>
            <form onsubmit="submitOrder(event)">
                <div class="form-group">
                    <label class="form-label" for="order-name">Ваше имя</label>
                    <input type="text" id="order-name" class="form-control" placeholder="Иван" required />
                </div>
                <div class="form-group">
                    <label class="form-label" for="order-phone">Номер телефона</label>
                    <input type="tel" id="order-phone" class="form-control" placeholder="+7 (978) 000-00-00" required />
                </div>
                <div class="form-group">
                    <label class="form-label" for="order-shop">Выберите магазин для получения</label>
                    <select id="order-shop" class="form-control"></select>
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 12px;">
                    <i class="fa-solid fa-check"></i> Подтвердить заказ
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================================================
         22. HTML: модалка товара
         ========================================================= -->
    <div id="product-detail-modal" class="modal-overlay">
        <div class="modal-box product-detail-modal-box">
            <button class="modal-close pd-close" type="button" aria-label="Закрыть" onclick="closeModal('product-detail-modal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div id="product-detail-content"></div>
        </div>
    </div>

    <!-- =========================================================
         23. HTML: модалка телефона
         ========================================================= -->
    <div id="phone-info-modal" class="modal-overlay">
        <div class="modal-box" style="text-align: center; max-width: 440px;">
            <button class="modal-close" onclick="closeModal('phone-info-modal')"><i class="fa-solid fa-xmark"></i></button>

            <div style="font-size: 3rem; color: var(--gold-light); margin-bottom: 16px;">
                <i class="fa-solid fa-phone-volume"></i>
            </div>

            <h3 style="font-size: 1.4rem; margin-bottom: 10px; color: #fff;">Телефон офиса</h3>
            <p style="font-size: 1.2rem; font-weight: 800; color: var(--gold-light); margin-bottom: 20px;">
                +7 (918) 064-28-17
            </p>

            <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2); padding: 18px; border-radius: var(--radius-md); text-align: left; margin-bottom: 24px;">
                <p style="margin-bottom: 12px; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-regular fa-clock" style="color: var(--gold-primary); font-size: 1.2rem;"></i>
                    <span><strong>Режим работы:</strong> Пн-Пт с 11:00 до 18:00</span>
                </p>
                <div style="border-top: 1px solid rgba(255,255,255,0.1); margin: 12px 0;"></div>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin: 0; line-height: 1.5; display: flex; align-items: flex-start; gap: 10px;">
                    <i class="fa-solid fa-circle-exclamation" style="color: var(--gold-primary); font-size: 1.1rem; margin-top: 2px;"></i>
                    <span>Обратите внимание: этот номер <strong>не предназначен для оформления предзаказов</strong>. Он работает для <strong>жалоб, предложений</strong> и общих вопросов по работе сети.</span>
                </p>
            </div>

            <div style="display: flex; gap: 12px;">
                <a href="tel:+79180642817" class="btn btn-lg" style="flex: 1; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 700; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);">
                    <i class="fa-solid fa-phone"></i> Позвонить
                </a>
                <button class="btn btn-secondary btn-lg" style="flex: 1;" onclick="closeModal('phone-info-modal')">
                    Закрыть
                </button>
            </div>
        </div>
    </div>

    <!-- =========================================================
         24. HTML: футер
         ========================================================= -->
    <footer class="site-footer">
        <div class="container">
            <div class="legal-warning-banner">
                <i class="fa-solid fa-triangle-exclamation"></i> ЧРЕЗМЕРНОЕ УПОТРЕБЛЕНИЕ АЛКОГОЛЯ ВРЕДИТ ВАШЕМУ ЗДОРОВЬЮ. ПРОДАЖА ЛИЦАМ ДО 18 ЛЕТ ЗАПРЕЩЕНА.
            </div>
            <div class="footer-grid">
                <div class="footer-col">
                    <div class="neon-logo" style="margin-bottom: 14px;">
                        <span class="logo-icon"><i class="fa-solid fa-beer-mug-empty"></i></span>
                        <span class="brand-name">ПИВЛЯНДИЯ</span>
                    </div>
                    <p style="color: var(--text-secondary); font-size: 0.88rem; line-height: 1.6;">
                        Сеть специализированных магазинов разливного и крафтового пива в Симферополе. Только свежие поставки с лучших пивоварен России.
                    </p>
                </div>
                <div class="footer-col">
                    <h4>Навигация</h4>
                    <ul class="footer-links">
                        <li><a href="#catalog-section">Каталог сортов</a></li>
                        <?php if ($combos_enabled): ?>
                        <li><a href="#combos-section">Комбо-наборы</a></li>
                        <?php endif; ?>
                        <li><a href="#shops-section">Магазины Симферополя</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Категории</h4>
					<ul class="footer-links">
						<li><a href="javascript:void(0)" onclick="selectCategory('Пиво')">Всё пиво</a></li>
						<li><a href="javascript:void(0)" onclick="selectCategory('Светлое')">Светлые сорта</a></li>
						<li><a href="javascript:void(0)" onclick="selectCategory('Тёмное')">Тёмные сорта</a></li>
						<li><a href="javascript:void(0)" onclick="selectCategory('Сидр и Медовуха')">Сидр и медовуха</a></li>
						<li><a href="javascript:void(0)" onclick="selectCategory('Рыба')">Рыба и закуски</a></li>
					</ul>
                </div>
                <div class="footer-col">
                    <h4>Контакты сети</h4>
                    <div style="font-size: 0.9rem; color: var(--text-secondary); display: flex; flex-direction: column; gap: 8px;">
                        <div><i class="fa-solid fa-phone" style="color: var(--gold-primary); width: 20px;"></i> +7 (918) 064-28-17</div>
                        <div><a href="https://t.me/pivland" target="_blank" style="color: inherit; text-decoration: none;"><i class="fa-brands fa-telegram" style="color: var(--gold-primary); width: 20px;"></i> Telegram</a></div>
                        <div><a href="https://max.ru/pivland" target="_blank" style="color: inherit; text-decoration: none;"><i class="fa-solid fa-comment-dots" style="color: var(--gold-primary); width: 20px;"></i> MAX</a></div>
                        <div><i class="fa-solid fa-location-dot" style="color: var(--gold-primary); width: 20px;"></i> г. Симферополь, 7 филиалов</div>
                        <div><i class="fa-regular fa-clock" style="color: var(--gold-primary); width: 20px;"></i> Ежедневно с 10:00 до 23:00</div>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                &copy; <?= date('Y') ?> Сеть магазинов разливного пива «Пивляндия» (г. Симферополь). Все права защищены.
            </div>
        </div>
    </footer>

    <!-- =========================================================
         25. Данные для JS + подключение script.js
         ========================================================= -->
    <?php
        $client_products = $products;
        $client_combos = $combos;
        if (!$prices_enabled) {
            foreach ($client_products as &$client_product) unset($client_product['price']);
            unset($client_product);
            foreach ($client_combos as &$client_combo) { unset($client_combo['price'], $client_combo['old_price']); }
            unset($client_combo);
        }
        $client_stock_summary = $stock_summary;
        if (!$prices_enabled) {
            foreach ($client_stock_summary as &$stock_item) unset($stock_item['min_price']);
            unset($stock_item);
        }
    ?>
    <script>
        window.initialProducts   = <?= json_encode($client_products, JSON_UNESCAPED_UNICODE) ?>;
		window.initialSnacks     = [];
        window.initialCombos     = <?= json_encode($client_combos, JSON_UNESCAPED_UNICODE) ?>;
        window.initialCategories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
        window.stockSummary      = <?= json_encode($client_stock_summary ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
        window.initialFavorites  = <?= json_encode(get_user_favorites()) ?>;
        window.favoriteDrink = <?= json_encode(['productId'=>(int)$favorite_drink_discount['product_id'], 'discountPercent'=>(float)$favorite_drink_discount['discount_percent']]) ?>;
        window.cartEnabled       = <?= $cart_enabled ? 'true' : 'false' ?>;
        window.pricesEnabled     = <?= $prices_enabled ? 'true' : 'false' ?>;
        window.combosEnabled     = <?= $combos_enabled ? 'true' : 'false' ?>;
        window.hidePwaInstall    = <?= $hide_pwa_install ? 'true' : 'false' ?>;
    </script>
    
    <script>
        /* =========================================================
           26. Inline-JS
           ========================================================= */

        /* ---------- 26.1. Модалки НЕ закрываются по клику на фон ---------- */
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) e.stopImmediatePropagation();
            }, true);
        });

        /* ---------- 26.2. Сброс на вкладку «Вход» при открытии модалки ---------- */
        const authBtnEl = document.getElementById('auth-btn');
        if (authBtnEl) {
            authBtnEl.addEventListener('click', () => {
                const regForm = document.getElementById('register-form');
                if (regForm && regForm.style.display !== 'none') {
                    showAuthTab('login');
                }
            });
        }

        /* ---------- 26.3. Авторизация / регистрация ---------- */
        function showAuthTab(tab) {
            const isLogin = tab === 'login';
            const loginForm = document.getElementById('login-form');
            const regForm = document.getElementById('register-form');
            const loginBtn = document.getElementById('tab-login-btn');
            const regBtn = document.getElementById('tab-register-btn');
            const title = document.getElementById('auth-title');
            const errEl = document.getElementById('auth-error');

            if (loginForm) loginForm.style.display = isLogin ? 'block' : 'none';
            if (regForm) regForm.style.display = isLogin ? 'none' : 'block';
            if (loginBtn) loginBtn.className = 'btn ' + (isLogin ? 'btn-primary' : 'btn-secondary');
            if (regBtn) regBtn.className = 'btn ' + (isLogin ? 'btn-secondary' : 'btn-primary');
            if (title) title.textContent = isLogin ? 'Авторизация' : 'Регистрация';
            if (errEl) errEl.style.display = 'none';
        }

        async function handleRegisterSubmit(e) {
            e.preventDefault();
            const login = document.getElementById('reg-login').value.trim();
            const password = document.getElementById('reg-password').value;
            const confirm = document.getElementById('reg-confirm').value;
            const errorEl = document.getElementById('auth-error');

            try {
                const res = await fetch('index.php?api=register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ login, password, confirm })
                });
                const data = await res.json();
                if (data.success) {
                    state.user = data.user;
                    closeModal('auth-modal');
                    if (typeof updateUserUI === 'function') updateUserUI();
                    if (typeof showToast === 'function') {
                        showToast(`Добро пожаловать, ${login}!`);
                    } else {
                        alert(`Добро пожаловать, ${login}!`);
                    }
                } else {
                    if (errorEl) {
                        errorEl.textContent = data.message || 'Ошибка регистрации';
                        errorEl.style.display = 'block';
                    }
                }
            } catch (err) {
                if (errorEl) {
                    errorEl.textContent = 'Ошибка соединения с сервером';
                    errorEl.style.display = 'block';
                }
            }
        }

        /* ---------- 26.4. Карта: центрирование на магазине ---------- */
        window.focusShopOnMap = function (shopId, el) {
            const shop = state.shops.find(s => Number(s.id) === Number(shopId));
            if (!shop) return;

            document.querySelectorAll('.shop-item').forEach(item => item.classList.remove('active'));
            if (el) el.classList.add('active');

            if (state.yandexMap) {
                // 17 — уровень «дом с адресом». 15 недостаточно заметно.
                const TARGET_ZOOM = 17;

                state.yandexMap.setCenter([Number(shop.lat), Number(shop.lng)], TARGET_ZOOM, {
                    checkZoomRange: true,
                    duration: 700,
                    timingFunction: 'ease-in-out'
                });

                // Балун открываем после того, как карта доедет — иначе он «дёргается»
                setTimeout(() => {
                    const markObj = state.mapPlacemarks.find(m => Number(m.id) === Number(shopId));
                    if (markObj) markObj.placemark.balloon.open();
                }, 750);
            }

            // На мобильных/планшетах карта под списком — подскроллим к ней
            if (window.matchMedia('(max-width: 1024px)').matches) {
                const mapEl = document.getElementById('yandex-map');
                if (mapEl) {
                    mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        };

        /* ---------- 26.5. Статус магазинов (открыто/закрыто) ---------- */
        function updateStoreStatus() {
            const moscowTimeStr = new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' });
            const moscowDate = new Date(moscowTimeStr);
            const currentHour = moscowDate.getHours();
            const isOpen = (currentHour >= 10 && currentHour < 23);

            const badge = document.getElementById('store-status-badge');
            const title = document.getElementById('store-status-title');
            const subtitle = document.getElementById('store-status-subtitle');
            const dot = document.getElementById('status-dot-indicator');
            const statusText = document.getElementById('status-text');

            if (!badge || !title || !subtitle || !dot) return;

            if (isOpen) {
                statusText.textContent = 'Открыто';
                badge.style.borderColor = '#10b981';
                badge.style.color = '#10b981';
                badge.style.background = 'rgba(16, 185, 129, 0.15)';
                dot.classList.remove('closed');

                title.textContent = '7 магазинов открыты сегодня';
                title.style.color = 'var(--gold-light)';
                subtitle.textContent = 'Работаем до 23:00 без выходных';
            } else {
                statusText.textContent = 'Закрыто';
                badge.style.borderColor = '#ef4444';
                badge.style.color = '#fca5a5';
                badge.style.background = 'rgba(239, 68, 68, 0.15)';
                dot.classList.add('closed');

                title.textContent = 'Магазины закрыты';
                title.style.color = '#ef4444';
                subtitle.textContent = 'Откроемся завтра в 10:00';
            }
            badge.style.display = 'flex';
        }

        // Проверяем статус при загрузке и обновляем раз в минуту
        updateStoreStatus();
        setInterval(updateStoreStatus, 60000);
    </script>

    <!-- =========================================================
         27. Кнопка «наверх»
         ========================================================= -->
    <button id="scroll-top-btn" class="scroll-top-btn" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" title="Наверх">
        <i class="fa-solid fa-arrow-up"></i>
    </button>
	<script src="script.js?v=<?= @filemtime('script.js') ?: time() ?>"></script>
    <script src="pwa-init.js?v=<?= @filemtime('pwa-init.js') ?: time() ?>"></script>
<style>
body[data-hide-pwa="1"] .pwa-install,
body[data-hide-pwa="1"] .pwa-install-btn,
body[data-hide-pwa="1"] #pwa-install,
body[data-hide-pwa="1"] #pwa-install-btn,
body[data-hide-pwa="1"] #install-app,
body[data-hide-pwa="1"] #installAppBtn,
body[data-hide-pwa="1"] .install-app-btn,
body[data-hide-pwa="1"] .install-pwa-btn,
body[data-hide-pwa="1"] [data-pwa-install],
body[data-hide-pwa="1"] .add-to-home { display: none !important; }
</style>
<script>
(function(){
  if (document.body.getAttribute('data-hide-pwa') !== '1') return;
  function hideInstallButtons(){
    document.querySelectorAll('button, a, div[role="button"], [role="button"]').forEach(function(el){
      if (el.dataset.__pwaHidden === '1') return;
      var t = (el.textContent || '').trim().toLowerCase();
      if (!t || t.length > 80) return;
      if (t === '\u0443\u0441\u0442\u0430\u043d\u043e\u0432\u0438\u0442\u044c \u043f\u0440\u0438\u043b\u043e\u0436\u0435\u043d\u0438\u0435' || t === 'install app' || t === '\u0443\u0441\u0442\u0430\u043d\u043e\u0432\u0438\u0442\u044c' || t === 'install' || t.indexOf('\u0443\u0441\u0442\u0430\u043d\u043e\u0432\u0438\u0442\u044c \u043d\u0430') === 0 || t.indexOf('\u0434\u043e\u0431\u0430\u0432\u0438\u0442\u044c \u043d\u0430 \u0433\u043b\u0430\u0432\u043d\u044b\u0439') !== -1 || t.indexOf('add to home') !== -1) {
        el.style.setProperty('display', 'none', 'important');
        el.dataset.__pwaHidden = '1';
      }
    });
    document.querySelectorAll('[class*="pwa-install"],[class*="install-pwa"],[class*="install-app"],[id*="pwa-install"],[id*="install-app"]').forEach(function(el){
      el.style.setProperty('display', 'none', 'important');
    });
  }
  hideInstallButtons();
  try { new MutationObserver(hideInstallButtons).observe(document.body, { childList: true, subtree: true }); } catch(e) {}
  setTimeout(hideInstallButtons, 800);
  setTimeout(hideInstallButtons, 2000);
  setTimeout(hideInstallButtons, 4000);
})();
</script>
<script src="stock-widget.js?v=<?= @filemtime('stock-widget.js') ?: time() ?>"></script>
</body>
</html>