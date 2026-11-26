<?php
/**
 * =======================================================
 * Сеть магазинов разливного пива «Пивляндия» (Симферополь)
 * Файл: admin.php — Конфигурация + функции + панель управления
 * =======================================================
 */

// ==================== CONFIG ====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'pass.php';

define('SITE_NAME', 'Пивляндия');
define('SITE_SLOGAN', 'Сеть магазинов крафтового и разливного пива в Симферополе');
define('SITE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

mysqli_report(MYSQLI_REPORT_OFF);

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
            die('Ошибка подключения к БД: ' . mysqli_connect_error());
        }
        mysqli_set_charset($conn, 'utf8mb4');

        // На всякий случай создаём таблицу settings (если её нет)
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` VARCHAR(50) PRIMARY KEY,
            `setting_value` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        mysqli_query($conn, "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('cart_enabled', '1')");

        // Миграция: колонка sort_order для категорий (для drag&drop)
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

// ==================== FUNCTIONS ====================
function get_setting($key, $default = null) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db, "SELECT setting_value FROM settings WHERE setting_key = ?");
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? $row['setting_value'] : $default;
}

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

function save_setting($key, $value) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db, "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    mysqli_stmt_bind_param($stmt, "ss", $key, $value);
    mysqli_stmt_execute($stmt);
}

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
    $password = trim($password);

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
            if ($user['role'] === 'admin' && !password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Неверный пароль администратора'];
            }
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'login' => $user['login'],
                'role' => $user['role'],
                'name' => $user['login']
            ];
            return ['success' => true, 'role' => $user['role'], 'user' => $_SESSION['user']];
        }
    }

    if ($login !== 'admin') {
        $_SESSION['user'] = [
            'id' => 0,
            'login' => $login,
            'role' => 'user',
            'name' => $login
        ];
        return ['success' => true, 'role' => 'user', 'user' => $_SESSION['user']];
    }

    return ['success' => false, 'message' => 'Пользователь не найден'];
}

function logout_user() {
    unset($_SESSION['user']);
    session_destroy();
}

function get_categories() {
    $db = get_db_connection();
    $result = mysqli_query($db, "SELECT * FROM categories ORDER BY sort_order ASC, id ASC");
    if (!$result) return [];
    $categories = [];
    while ($row = mysqli_fetch_assoc($result)) $categories[] = $row;
    return $categories;
}

function get_products($category_id = null) {
    $db = get_db_connection();
    if ($category_id && is_numeric($category_id)) {
        $stmt = mysqli_prepare($db, "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.category_id = ? ORDER BY p.id ASC");
        mysqli_stmt_bind_param($stmt, "i", $category_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($db, "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id ASC");
    }
    if (!$result) return [];
    $products = [];
    while ($row = mysqli_fetch_assoc($result)) $products[] = $row;
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

function get_users() {
    $db = get_db_connection();
    $result = mysqli_query($db, "SELECT id, login, role, created_at FROM users ORDER BY id ASC");
    if (!$result) {
        $result = mysqli_query($db, "SELECT id, login, role FROM users ORDER BY id ASC");
    }
    if (!$result) return [];
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) $users[] = $row;
    return $users;
}

function get_stats() {
    $db = get_db_connection();
    $stats = [];
    foreach (['products', 'categories', 'combos', 'shops'] as $table) {
        $res = mysqli_query($db, "SELECT COUNT(*) as c FROM `$table`");
        $stats[$table] = $res ? (int)mysqli_fetch_assoc($res)['c'] : 0;
    }
    return $stats;
}

// ==================== LOGIC ====================
require_once __DIR__ . '/attributes.php';
$message = '';
$error = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout_user();
    header('Location: index.php');
    exit;
}

if (isset($_POST['admin_login_submit'])) {
    $login = $_POST['login'] ?? '';
    $pass  = $_POST['password'] ?? '';
    $auth  = authenticate_user($login, $pass);
    if ($auth['success'] && $auth['role'] === 'admin') {
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Неверный логин или пароль администратора';
    }
}

if (!is_admin()) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход в панель управления — Пивляндия</title>
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preconnect" href="https://cdnjs.cloudflare.com">
        <link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800&display=swap"></noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
        <link rel="stylesheet" href="style.css?v=<?= @filemtime('style.css') ?: time() ?>">
    </head>
    <body class="setup-body">
        <div class="setup-container" style="max-width: 440px;">
            <div class="setup-card">
                <div class="setup-header">
                    <div class="neon-logo" style="justify-content: center; margin-bottom: 12px;">
                        <span class="logo-icon"><i class="fa-solid fa-beer-mug-empty"></i></span>
                        <span class="brand-name">ПИВЛЯНДИЯ</span>
                    </div>
                    <span class="neon-badge"><i class="fa-solid fa-shield-halved"></i> Панель администратора</span>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label" for="login">Логин</label>
                        <input type="text" name="login" id="login" class="form-control" placeholder="Введите логин" autocomplete="username" required />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="password">Пароль</label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Введите пароль" autocomplete="current-password" required />
                    </div>
                    <button type="submit" name="admin_login_submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 10px;">
                        <i class="fa-solid fa-right-to-bracket"></i> Войти в админку
                    </button>
                    <div style="text-align: center; margin-top: 18px;">
                        <a href="index.php" style="color: var(--text-secondary); font-size: 0.88rem;">
                            <i class="fa-solid fa-arrow-left"></i> Вернуться на витрину
                        </a>
                    </div>
                </form>
                <p style="text-align:center; margin-top:16px; font-size:0.75rem; color: var(--text-muted);">
                    По умолчанию: <code>admin / 123</code>. После входа смените пароль в «Настройках профиля».
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$db = get_db_connection();

// ==================== AJAX: СОХРАНЕНИЕ СОРТИРОВКИ ====================
if (isset($_GET['action']) && $_GET['action'] === 'update_order') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['order'])) {
        $stmt = mysqli_prepare($db, "UPDATE products SET sort_order = ? WHERE id = ?");
        foreach ($input['order'] as $index => $id) {
            $pos = $index + 1;
            $id = (int)$id;
            mysqli_stmt_bind_param($stmt, "ii", $pos, $id);
            mysqli_stmt_execute($stmt);
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'update_category_order') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['order'])) {
        $stmt = mysqli_prepare($db, "UPDATE categories SET sort_order = ? WHERE id = ?");
        foreach ($input['order'] as $index => $id) {
            $pos = $index + 1;
            $id = (int)$id;
            mysqli_stmt_bind_param($stmt, "ii", $pos, $id);
            mysqli_stmt_execute($stmt);
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ==================== AJAX: TOGGLE VISIBILITY ====================
if (isset($_GET['action']) && $_GET['action'] === 'toggle_visibility') {
    header('Content-Type: application/json; charset=utf-8');
    $tbl = $_POST['type'] ?? '';
    $rid = (int)($_POST['id'] ?? 0);
    if (!in_array($tbl, ['categories', 'attributes'], true) || $rid <= 0) { echo json_encode(['success'=>false]); exit; }
    $q = mysqli_prepare($db, "UPDATE `$tbl` SET is_visible = IF(is_visible = 1, 0, 1) WHERE id = ?");
    if (!$q) { echo json_encode(['success'=>false]); exit; }
    mysqli_stmt_bind_param($q, 'i', $rid);
    mysqli_stmt_execute($q);
    mysqli_stmt_close($q);
    $r = mysqli_query($db, "SELECT is_visible FROM `$tbl` WHERE id = " . $rid);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    echo json_encode(['success'=>true, 'is_visible'=>$row ? (int)$row['is_visible'] : 0]);
    exit;
}

// ==================== CRUD: ТОВАРЫ ====================
if (isset($_POST['save_product'])) {
    $id           = (int)($_POST['product_id'] ?? 0);
    $cat_id       = (int)$_POST['category_id'];
    $name         = trim($_POST['name']);
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $price        = (float)$_POST['price'];
    $volume       = trim($_POST['volume'] ?: '1.0 л');
    $image        = '';
    $desc         = trim($_POST['description']);

    if ($id > 0) {
        $stmt = mysqli_prepare($db, "UPDATE products SET category_id = ?, name = ?, manufacturer = ?, price = ?, volume = ?, image = ?, description = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "issdsssi", $cat_id, $name, $manufacturer, $price, $volume, $image, $desc, $id);
        mysqli_stmt_execute($stmt);
        $message = "Сорт «{$name}» успешно обновлен!";
    } else {
        $stmt = mysqli_prepare($db, "INSERT INTO products (category_id, name, manufacturer, price, volume, image, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "issdsss", $cat_id, $name, $manufacturer, $price, $volume, $image, $desc);
        mysqli_stmt_execute($stmt);
        $id = (int)mysqli_insert_id($db);
        $message = "Новый сорт «{$name}» успешно добавлен в каталог!";
    }

    if ($id > 0 && !empty($_FILES['product_image']['tmp_name']) && is_uploaded_file($_FILES['product_image']['tmp_name'])) {
    $tmp_file = $_FILES['product_image']['tmp_name'];
    $info = @getimagesize($tmp_file);
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    $images_dir = __DIR__ . '/images';
    if (!is_dir($images_dir)) @mkdir($images_dir, 0755, true);
    if ($info && in_array($info[2], $allowed, true) && function_exists('imagewebp')) {
        $src_img = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src_img = @imagecreatefromjpeg($tmp_file); break;
            case IMAGETYPE_PNG:  $src_img = @imagecreatefrompng($tmp_file);  break;
            case IMAGETYPE_GIF:  $src_img = @imagecreatefromgif($tmp_file);  break;
            case IMAGETYPE_WEBP: $src_img = @imagecreatefromwebp($tmp_file); break;
        }
        if ($src_img) {
            @imagewebp($src_img, $images_dir . '/image-' . $id . '.webp', 85);
            @imagedestroy($src_img);
            foreach (['jpg','jpeg','png','gif'] as $__ext) {
                $__old = $images_dir . '/image-' . $id . '.' . $__ext;
                if (file_exists($__old)) @unlink($__old);
            }
        }
    }
}

if ($id > 0 && !empty($_POST['attrs'])) {
        save_product_attributes($id, $_POST['attrs']);
    }
}

if (isset($_GET['delete_product'])) {
    $id = (int)$_GET['delete_product'];
    $stmt = mysqli_prepare($db, "DELETE FROM products WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $message = "Товар успешно удален!";
}

// ==================== CRUD: КАТЕГОРИИ ====================
if (isset($_POST['save_category'])) {
    $id   = (int)($_POST['category_id'] ?? 0);
    $name = trim($_POST['name']);
    $desc = trim($_POST['description']);
    $icon = trim($_POST['icon'] ?: 'fa-beer-mug-empty');
    $is_visible = isset($_POST['is_visible']) ? 1 : 0;

    if ($id > 0) {
        $stmt = mysqli_prepare($db, "UPDATE categories SET name = ?, description = ?, icon = ?, is_visible = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssii", $name, $desc, $icon, $is_visible, $id);
        mysqli_stmt_execute($stmt);
        $message = "Категория «{$name}» обновлена!";
    } else {
        $maxRow = mysqli_fetch_assoc(mysqli_query($db, "SELECT COALESCE(MAX(sort_order), 0) AS m FROM categories"));
		$newSort = (int)$maxRow['m'] + 1;
		$stmt = mysqli_prepare($db, "INSERT INTO categories (name, description, icon, sort_order, is_visible) VALUES (?, ?, ?, ?, ?)");
		mysqli_stmt_bind_param($stmt, "sssii", $name, $desc, $icon, $newSort, $is_visible);
        mysqli_stmt_execute($stmt);
        $message = "Категория «{$name}» создана!";
    }
}

if (isset($_GET['delete_category'])) {
    $id = (int)$_GET['delete_category'];
    $stmt = mysqli_prepare($db, "DELETE FROM categories WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $message = "Категория удалена!";
}

// ==================== CRUD: КОМБО ====================
if (isset($_POST['save_combo'])) {
    $id       = (int)($_POST['combo_id'] ?? 0);
    $name     = trim($_POST['name']);
    $desc     = trim($_POST['description']);
    $price    = (float)$_POST['price'];
    $old_pr   = (float)$_POST['old_price'];
    $disc     = (int)$_POST['discount_percent'];
    $image    = trim($_POST['image'] ?: 'images/combo-1.jpg');

    if ($id > 0) {
        $stmt = mysqli_prepare($db, "UPDATE combos SET name = ?, description = ?, price = ?, old_price = ?, discount_percent = ?, image = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssddisi", $name, $desc, $price, $old_pr, $disc, $image, $id);
        mysqli_stmt_execute($stmt);
        $message = "Комбо-набор «{$name}» обновлен!";
    } else {
        $stmt = mysqli_prepare($db, "INSERT INTO combos (name, description, price, old_price, discount_percent, image) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssddis", $name, $desc, $price, $old_pr, $disc, $image);
        mysqli_stmt_execute($stmt);
        $message = "Комбо-набор «{$name}» успешно создан!";
    }
}

if (isset($_GET['delete_combo'])) {
    $id = (int)$_GET['delete_combo'];
    $stmt = mysqli_prepare($db, "DELETE FROM combos WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $message = "Комбо-набор удален!";
}

// ==================== CRUD: АТРИБУТЫ ====================
if (isset($_POST['save_attribute'])) {
    $id    = (int)($_POST['attribute_id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $code  = trim($_POST['code'] ?? '');
    if ($code === '') $code = preg_replace('/[^a-z0-9_]/i', '_', strtolower($name));
    $cat_id = ($_POST['category_id'] ?? '') === '' ? null : (int)$_POST['category_id'];
    $type  = in_array($_POST['type'] ?? '', ['tag','select','multiselect','text','number'], true) ? $_POST['type'] : 'text';
	$unit  = trim($_POST['unit'] ?? '');
	$sort  = (int)($_POST['sort_order'] ?? 0);
	$is_visible = isset($_POST['is_visible']) ? 1 : 0;
	$options = null;
    if (in_array($type, ['tag','select','multiselect'], true) && !empty($_POST['options'])) {
        $opts = array_values(array_filter(array_map('trim', explode(',', $_POST['options']))));
        $options = json_encode($opts, JSON_UNESCAPED_UNICODE);
    }
	if ($id > 0) {
		$stmt = mysqli_prepare($db, "UPDATE attributes SET category_id=?, name=?, code=?, type=?, unit=?, options=?, sort_order=?, is_visible=? WHERE id=?");
		mysqli_stmt_bind_param($stmt, "issssssii", $cat_id, $name, $code, $type, $unit, $options, $sort, $is_visible, $id);
	} else {
		$stmt = mysqli_prepare($db, "INSERT INTO attributes (category_id,name,code,type,unit,options,sort_order,is_visible) VALUES (?,?,?,?,?,?,?,?)");
		mysqli_stmt_bind_param($stmt, "isssssii", $cat_id, $name, $code, $type, $unit, $options, $sort, $is_visible);
	}
    mysqli_stmt_execute($stmt);
    $message = "Атрибут «{$name}» сохранён!";
}
if (isset($_GET['delete_attribute'])) {
    $id = (int)$_GET['delete_attribute'];
    $stmt = mysqli_prepare($db, "DELETE FROM attributes WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $message = "Атрибут удалён!";
}

// ==================== CRUD: ПОЛЬЗОВАТЕЛИ ====================
if (isset($_POST['save_user'])) {
    $id       = (int)($_POST['user_id'] ?? 0);
    $login    = trim($_POST['login'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['admin','user'], true) ? $_POST['role'] : 'user';
    $password = $_POST['password'] ?? '';
    $self_id  = (int)($_SESSION['user']['id'] ?? 0);

    if ($login === '') {
        $error = 'Логин обязателен';
    } else {
        if ($id > 0) {
            $check = mysqli_prepare($db, "SELECT id FROM users WHERE login = ? AND id != ?");
            mysqli_stmt_bind_param($check, "si", $login, $id);
        } else {
            $check = mysqli_prepare($db, "SELECT id FROM users WHERE login = ?");
            mysqli_stmt_bind_param($check, "s", $login);
        }
        mysqli_stmt_execute($check);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

        if ($exists) {
            $error = 'Такой логин уже занят';
        } elseif ($id > 0 && $id === $self_id && $role !== 'admin') {
            $error = 'Нельзя снять с себя права администратора';
        } elseif ($id > 0) {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($db, "UPDATE users SET login = ?, password_hash = ?, role = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "sssi", $login, $hash, $role, $id);
            } else {
                $stmt = mysqli_prepare($db, "UPDATE users SET login = ?, role = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ssi", $login, $role, $id);
            }
            mysqli_stmt_execute($stmt);
            if ($id === $self_id) $_SESSION['user']['login'] = $login;
            $message = "Пользователь «{$login}» обновлён!";
        } else {
            if ($password === '') {
                $error = 'Пароль обязателен для нового пользователя';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($db, "INSERT INTO users (login, password_hash, role) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "sss", $login, $hash, $role);
                mysqli_stmt_execute($stmt);
                $message = "Пользователь «{$login}» успешно создан!";
            }
        }
    }
}

if (isset($_GET['delete_user'])) {
    $id = (int)$_GET['delete_user'];
    $self_id = (int)($_SESSION['user']['id'] ?? 0);
    if ($id === $self_id) {
        $error = 'Нельзя удалить собственный аккаунт';
    } else {
        $stmt = mysqli_prepare($db, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $message = "Пользователь удалён!";
    }
}

// ==================== СМЕНА СВОИХ УЧЁТНЫХ ДАННЫХ ====================
if (isset($_POST['change_credentials'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_login    = trim($_POST['new_login'] ?? '');
    $new_pass     = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $current_id   = (int)($_SESSION['user']['id'] ?? 0);

    $stmt = mysqli_prepare($db, "SELECT password_hash FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $current_id);
    mysqli_stmt_execute($stmt);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$current || !password_verify($current_pass, $current['password_hash'])) {
        $error = 'Текущий пароль неверен';
    } elseif ($new_login !== '' && $new_login !== $_SESSION['user']['login']) {
        $check = mysqli_prepare($db, "SELECT id FROM users WHERE login = ? AND id != ?");
        mysqli_stmt_bind_param($check, "si", $new_login, $current_id);
        mysqli_stmt_execute($check);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
            $error = 'Новый логин уже занят';
        }
    }

    if (!$error && $new_pass !== '' && $new_pass !== $confirm_pass) {
        $error = 'Новые пароли не совпадают';
    }

    if (!$error) {
        $final_login = ($new_login !== '') ? $new_login : $_SESSION['user']['login'];
        $updates = ['login = ?'];
        $types   = 's';
        $params  = [$final_login];

        if ($new_pass !== '') {
            $updates[] = 'password_hash = ?';
            $types    .= 's';
            $params[]  = password_hash($new_pass, PASSWORD_DEFAULT);
        }

        $types   .= 'i';
        $params[] = $current_id;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);

        $_SESSION['user']['login'] = $final_login;
        $_SESSION['user']['name']  = $final_login;
        $message = 'Учётные данные обновлены! Используйте новый логин при следующем входе.';
    }
}

// ==================== НАСТРОЙКИ САЙТА ====================
if (isset($_POST['save_site_settings'])) {
    save_setting('cart_enabled', isset($_POST['cart_enabled']) ? '1' : '0');
    save_setting('combos_enabled', isset($_POST['combos_enabled']) ? '1' : '0');
    save_setting('hide_no_photo', isset($_POST['hide_no_photo']) ? '1' : '0');
    save_setting('prices_enabled', isset($_POST['prices_enabled']) ? '1' : '0');
    save_setting('hide_pwa_install', isset($_POST['hide_pwa_install']) ? '1' : '0');
    $message = 'Настройки сайта сохранены!';
}

// ==================== ЗАГРУЗКА ДАННЫХ ДЛЯ РЕНДЕРА ====================
$active_tab = $_GET['tab'] ?? 'products';
$stats      = get_stats();

$categories = in_array($active_tab, ['products', 'categories', 'attributes'], true) ? get_categories() : [];
$products   = ($active_tab === 'products') ? get_products() : [];

// Подмешиваем значения атрибутов к товарам (для формы редактирования)
if ($active_tab === 'products') {
    foreach ($products as &$p) {
        $p['attr_values'] = get_product_attributes_by_id($p['id']);
    }
    unset($p);
}
$all_attributes = in_array($active_tab, ['products', 'attributes'], true) ? get_attributes() : [];
$combos     = ($active_tab === 'combos')   ? get_combos()   : [];
$shops      = ($active_tab === 'shops')    ? get_shops()    : [];
$users      = ($active_tab === 'users')    ? get_users()    : [];

$cart_enabled = get_setting('cart_enabled', '1') === '1';
$combos_enabled = get_setting('combos_enabled', '1') === '1';
$hide_no_photo = get_setting('hide_no_photo', '0') === '1';
$prices_enabled = get_setting('prices_enabled', '1') === '1';
$hide_pwa_install = get_setting('hide_pwa_install', '0') === '1';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель управления — Пивляндия Симферополь</title>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime('style.css') ?: time() ?>">
    <style>
	.visits-table td { white-space: nowrap; }
	.sortable-ghost { opacity: 0.4; background: rgba(245, 158, 11, 0.1); }
        .admin-layout { align-items: start; }
        .admin-sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
		.admin-nav-item { border: 1px solid transparent; }
        .switch { position: relative; display: inline-block; width: 50px; height: 26px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #2a3344; transition: .3s; border-radius: 26px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: #fff; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--gold-primary); }
        input:checked + .slider:before { transform: translateX(24px); }
        .modal-overlay.active { align-items: flex-start !important; overflow-y: auto; padding: 20px 0; }
        .modal-overlay.active .modal-box { margin: auto; max-height: calc(100vh - 40px); overflow-y: auto; }
    </style>
</head>
<body>

    <div class="admin-layout">
        <aside class="admin-sidebar">
            <div class="neon-logo">
                <span class="logo-icon"><i class="fa-solid fa-beer-mug-empty"></i></span>
                <div>
                    <span class="brand-name" style="font-size: 1.25rem;">ПИВЛЯНДИЯ</span>
                    <div style="font-size: 0.65rem; color: var(--gold-light);">ADMIN PANEL</div>
                </div>
            </div>

            <ul class="admin-nav">
                <li><a href="?tab=products" class="admin-nav-item <?= $active_tab === 'products' ? 'active' : '' ?>"><i class="fa-solid fa-beer-mug-empty"></i> Товары / Сорта</a></li>
                <li><a href="?tab=categories" class="admin-nav-item <?= $active_tab === 'categories' ? 'active' : '' ?>"><i class="fa-solid fa-layer-group"></i> Категории</a></li>
				<li><a href="?tab=attributes" class="admin-nav-item <?= $active_tab === 'attributes' ? 'active' : '' ?>"><i class="fa-solid fa-tags"></i> Атрибуты</a></li>
                <li><a href="?tab=combos" class="admin-nav-item <?= $active_tab === 'combos' ? 'active' : '' ?>"><i class="fa-solid fa-fire"></i> Комбо-наборы</a></li>
                <li><a href="?tab=shops" class="admin-nav-item <?= $active_tab === 'shops' ? 'active' : '' ?>"><i class="fa-solid fa-shop"></i> Магазины (7 точек)</a></li>
                <li><a href="?tab=users" class="admin-nav-item <?= $active_tab === 'users' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Пользователи</a></li>
				<li><a href="?tab=visits" class="admin-nav-item <?= $active_tab === 'visits' ? 'active' : '' ?>">
					<i class="fa-solid fa-chart-line"></i> Посещения
				</a></li>
                <li><a href="?tab=settings" class="admin-nav-item <?= $active_tab === 'settings' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> Настройки профиля</a></li>
                <li><a href="?tab=site_settings" class="admin-nav-item <?= $active_tab === 'site_settings' ? 'active' : '' ?>"><i class="fa-solid fa-sliders"></i> Настройки сайта</a></li>
                <li style="margin-top: auto;"><a href="index.php" class="admin-nav-item" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Открыть витрину</a></li>
                <li><a href="?action=logout" class="admin-nav-item" style="color: #ef4444;"><i class="fa-solid fa-arrow-right-from-bracket"></i> Выйти</a></li>
            </ul>
        </aside>

        <main class="admin-main">
            <div class="admin-header">
                <div>
                    <h2 style="font-size: 1.6rem; font-weight: 800;">Панель управления магазинами</h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Управление каталогом, категориями, наборами и пользователями</p>
                </div>
                <div><span class="neon-badge"><i class="fa-solid fa-user-shield"></i> <?= htmlspecialchars($_SESSION['user']['login']) ?></span></div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

			<div class="stats-grid">
				<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-beer-mug-empty"></i></div><div><div class="stat-value"><?= $stats['products'] ?></div><div class="stat-label">Сортов пива</div></div></div>
				<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div><div><div class="stat-value"><?= $stats['categories'] ?></div><div class="stat-label">Категорий</div></div></div>
				<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-fire"></i></div><div><div class="stat-value"><?= $stats['combos'] ?></div><div class="stat-label">Комбо-наборов</div></div></div>
				<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-location-dot"></i></div><div><div class="stat-value"><?= $stats['shops'] ?></div><div class="stat-label">Магазинов в Крыму</div></div></div>
			</div>

            <?php if ($active_tab === 'products'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Список сортов пива (<?= count($products) ?>)</h3>
                    <button class="btn btn-primary" onclick="openProductModal()"><i class="fa-solid fa-plus"></i> Добавить новый сорт</button>
                </div>
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead><tr><th style="width: 30px;"></th><th>Фото</th><th>Название</th><th>Категория</th><th>Объем</th><th>Цена</th><th>Действия</th></tr></thead>
						<tbody id="sortable-products">
							<?php foreach ($products as $p): ?>
								<tr data-id="<?= $p['id'] ?>">
									<td style="cursor: grab; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-grip-vertical"></i></td>
                                    <?php $p_fallback = get_no_photo_url($p['category_name'] ?? null); ?>
									<td><img src="<?= get_product_image($p['id'], $p['category_name'] ?? null) ?>" class="table-img" alt="" loading="lazy" onerror="this.src='<?= $p_fallback ?>'" /></td>
                                    <td>
                                        <strong><?= htmlspecialchars($p['name']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); max-width: 280px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;"><?= htmlspecialchars($p['description']) ?></div>
                                    </td>
                                    <td><span class="neon-badge"><?= htmlspecialchars($p['category_name'] ?? 'Сорт') ?></span></td>
                                    <td><?= htmlspecialchars($p['volume'] ?? '1.0 л') ?></td>
                                    <td><strong style="color: var(--gold-light);"><?= number_format($p['price'], 0) ?> ₽</strong></td>
                                    <td>
                                        <div class="table-actions">
											<a href="product.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Открыть на витрине"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
											<button class="btn btn-secondary btn-sm" onclick="editProduct(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)"><i class="fa-solid fa-pen"></i></button>
											<a href="?tab=products&delete_product=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить этот сорт?')"><i class="fa-solid fa-trash"></i></a>
										</div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'categories'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Категории напитков</h3>
                    <button class="btn btn-primary" onclick="openCategoryModal()"><i class="fa-solid fa-plus"></i> Добавить категорию</button>
                </div>
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead><tr><th style="width:30px;"></th><th>ID</th><th>Иконка</th><th>Название</th><th>Описание</th><th>Вид.</th><th>Действия</th></tr></thead>
						<tbody id="sortable-categories">
							<?php foreach ($categories as $c): ?>
								<tr data-id="<?= (int)$c['id'] ?>">
									<td style="cursor: grab; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-grip-vertical"></i></td>
									<td>#<?= $c['id'] ?></td>
									<td><i class="fa-solid <?= htmlspecialchars($c['icon']) ?>" style="font-size: 1.2rem; color: var(--gold-primary);"></i></td>
                                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                    <td style="color: var(--text-secondary);"><?= htmlspecialchars($c['description']) ?></td>
                                    <td style="text-align:center;">
                                        <?php if (!empty($c['is_visible'])): ?>
                                            <span class="neon-badge" style="border-color:#10b981;color:#10b981;"><i class="fa-solid fa-eye"></i></span>
                                        <?php else: ?>
                                            <span class="neon-badge" style="border-color:#ef4444;color:#fca5a5;"><i class="fa-solid fa-eye-slash"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-secondary btn-sm" onclick="editCategory(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)"><i class="fa-solid fa-pen"></i></button>
                                            <a href="?tab=categories&delete_category=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить категорию?')"><i class="fa-solid fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'combos'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Комбо-наборы со скидкой</h3>
                    <button class="btn btn-primary" onclick="openComboModal()"><i class="fa-solid fa-plus"></i> Добавить комбо-набор</button>
                </div>
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Фото</th><th>Название</th><th>Состав и описание</th><th>Цена со скидкой</th><th>Старая цена</th><th>Скидка</th><th>Действия</th></tr></thead>
                        <tbody>
                            <?php foreach ($combos as $cb): ?>
                                <tr>
                                    <td><img src="<?= htmlspecialchars($cb['image']) ?>" class="table-img" alt="" loading="lazy" onerror="this.src='images/combo-1.jpg'" /></td>
                                    <td><strong><?= htmlspecialchars($cb['name']) ?></strong></td>
                                    <td style="color: var(--text-secondary); max-width: 250px;"><?= htmlspecialchars($cb['description']) ?></td>
                                    <td><strong style="color: var(--gold-light);"><?= number_format($cb['price'], 0) ?> ₽</strong></td>
                                    <td><span style="text-decoration: line-through; color: var(--text-muted);"><?= number_format($cb['old_price'], 0) ?> ₽</span></td>
                                    <td><span class="neon-badge" style="background: rgba(239, 68, 68, 0.2); border-color: #ef4444; color: #fca5a5;">-<?= $cb['discount_percent'] ?>%</span></td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-secondary btn-sm" onclick="editCombo(<?= htmlspecialchars(json_encode($cb), ENT_QUOTES, 'UTF-8') ?>)"><i class="fa-solid fa-pen"></i></button>
                                            <a href="?tab=combos&delete_combo=<?= $cb['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить этот комбо-набор?')"><i class="fa-solid fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'shops'): ?>
                <div style="margin-bottom: 20px;">
                    <h3>7 точек розлива сети в Симферополе</h3>
                    <p style="color: var(--text-secondary); font-size: 0.88rem;">Координаты отображаются на интерактивной карте на главной странице</p>
                </div>
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>Название филиала</th><th>Адрес в Симферополе</th><th>Координаты (lat, lng)</th><th>Телефон</th><th>Режим работы</th></tr></thead>
                        <tbody>
                            <?php foreach ($shops as $s): ?>
                                <tr>
                                    <td>#<?= $s['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                                    <td><i class="fa-solid fa-location-dot" style="color: var(--gold-primary); margin-right: 6px;"></i> <?= htmlspecialchars($s['address']) ?></td>
                                    <td><code><?= $s['lat'] ?>, <?= $s['lng'] ?></code></td>
                                    <td><?= htmlspecialchars($s['phone']) ?></td>
                                    <td><?= htmlspecialchars($s['working_hours']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'attributes'): ?>
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
					<h3>Атрибуты товаров</h3>
					<button class="btn btn-primary" onclick="openAttributeModal()"><i class="fa-solid fa-plus"></i> Добавить атрибут</button>
				</div>
				<div class="data-table-wrapper">
					<table class="data-table">
						<thead><tr><th>ID</th><th>Название</th><th>Код</th><th>Тип</th><th>Категория</th><th>Опции</th><th>Вид.</th><th>Действия</th></tr></thead>
						<tbody>
							<?php foreach (get_attributes() as $a):
								$cat_label = 'Все категории';
								if ($a['category_id']) {
									foreach ($categories as $c) if ((int)$c['id'] === (int)$a['category_id']) $cat_label = $c['name'];
								}
							?>
							<tr>
								<td>#<?= (int)$a['id'] ?></td>
								<td><strong><?= htmlspecialchars($a['name']) ?></strong></td>
								<td><code><?= htmlspecialchars($a['code']) ?></code></td>
								<td><?= htmlspecialchars($a['type']) ?><?= $a['unit'] ? ' ('.$a['unit'].')' : '' ?></td>
								<td><?= htmlspecialchars($cat_label) ?></td>
								<td style="color:var(--text-secondary);font-size:.85rem;"><?= htmlspecialchars(implode(', ', $a['options_arr'])) ?: '—' ?></td>
								<td style="text-align:center;">
									<?php if (!empty($a['is_visible'])): ?>
										<span class="neon-badge" style="border-color:#10b981;color:#10b981;"><i class="fa-solid fa-eye"></i></span>
									<?php else: ?>
										<span class="neon-badge" style="border-color:#ef4444;color:#fca5a5;"><i class="fa-solid fa-eye-slash"></i></span>
									<?php endif; ?>
								</td>
								<td>
									<div class="table-actions">
										<button class="btn btn-secondary btn-sm" onclick='editAttribute(<?= json_encode($a, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen"></i></button>
										<a href="?tab=attributes&delete_attribute=<?= (int)$a['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить атрибут и все его значения у товаров?')"><i class="fa-solid fa-trash"></i></a>
									</div>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div id="attribute-modal" class="modal-overlay">
					<div class="modal-box">
						<button class="modal-close" onclick="closeModal('attribute-modal')"><i class="fa-solid fa-xmark"></i></button>
						<h3 id="attribute-modal-title" style="margin-bottom:20px;">Атрибут</h3>
						<form method="POST">
							<input type="hidden" name="attribute_id" id="attr-id" value="0" />
							<div class="form-group">
								<label class="form-label">Название</label>
								<input type="text" name="name" id="attr-name" class="form-control" required placeholder="Стиль / Способ обработки" />
							</div>
							<div class="form-group">
								<label class="form-label">Код <small style="color:var(--text-muted);">(уникальный, латиница)</small></label>
								<input type="text" name="code" id="attr-code" class="form-control" placeholder="style / processing" />
							</div>
							<div class="form-group">
								<label class="form-label">Категория</label>
								<select name="category_id" id="attr-cat" class="form-control">
									<option value="">Все категории (глобальный)</option>
									<?php foreach ($categories as $c): ?>
										<option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="form-group">
								<label class="form-label">Тип</label>
								<select name="type" id="attr-type" class="form-control">
									<option value="text">Текст</option>
									<option value="number">Число</option>
									<option value="select">Один вариант</option>
									<option value="multiselect">Несколько вариантов</option>
									<option value="tag">Тег (да/нет)</option>
								</select>
							</div>
							<div class="form-group">
								<label class="form-label">Единица измерения</label>
								<input type="text" name="unit" id="attr-unit" class="form-control" placeholder="% / IBU / г" />
							</div>
							<div class="form-group">
								<label class="form-label">Варианты <small style="color:var(--text-muted);">(через запятую, для select/multiselect/tag)</small></label>
								<input type="text" name="options" id="attr-options" class="form-control" placeholder="Лагер, Эль, IPA" />
							</div>
							<div class="form-group">
								<label class="form-label">Сортировка</label>
								<input type="number" name="sort_order" id="attr-sort" class="form-control" value="0" />
							</div>
							<div class="form-group" style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border-color);">
								<div>
									<label class="form-label" style="margin-bottom:4px;">Показывать пользователям</label>
									<p style="font-size:.8rem;color:var(--text-muted);margin:0;">Выключено — атрибут виден только администратору.</p>
								</div>
								<label class="switch">
									<input type="checkbox" name="is_visible" id="attr-visible" value="1" checked />
									<span class="slider"></span>
								</label>
							</div>
							<button type="submit" name="save_attribute" class="btn btn-primary btn-lg" style="width:100%;">
								<i class="fa-solid fa-floppy-disk"></i> Сохранить атрибут
							</button>
						</form>
					</div>
				</div>
				<script>
				function openAttributeModal() {
					document.getElementById('attr-id').value = '0';
					document.getElementById('attr-name').value = '';
					document.getElementById('attr-code').value = '';
					document.getElementById('attr-cat').value = '';
					document.getElementById('attr-type').value = 'text';
					document.getElementById('attr-unit').value = '';
					document.getElementById('attr-options').value = '';
					document.getElementById('attr-sort').value = '0';
					document.getElementById('attr-visible').checked = true;
					document.getElementById('attribute-modal-title').textContent = 'Новый атрибут';
					openModal('attribute-modal');
				}
				function editAttribute(a) {
					document.getElementById('attr-id').value = a.id;
					document.getElementById('attr-name').value = a.name;
					document.getElementById('attr-code').value = a.code;
					document.getElementById('attr-cat').value = a.category_id || '';
					document.getElementById('attr-type').value = a.type;
					document.getElementById('attr-unit').value = a.unit || '';
					document.getElementById('attr-options').value = (a.options_arr || []).join(', ');
					document.getElementById('attr-sort').value = a.sort_order;
					document.getElementById('attr-visible').checked = !!Number(a.is_visible);
					document.getElementById('attribute-modal-title').textContent = 'Редактировать #' + a.id;
					openModal('attribute-modal');
				}
				</script>
			<?php endif; ?>

			<?php if ($active_tab === 'users'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Пользователи системы (<?= count($users) ?>)</h3>
                    <button class="btn btn-primary" onclick="openUserModal()"><i class="fa-solid fa-plus"></i> Добавить пользователя</button>
                </div>
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>Логин</th><th>Роль</th><th>Создан</th><th>Действия</th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $u):
                                $is_self = ((int)$u['id'] === (int)$_SESSION['user']['id']);
                            ?>
                                <tr>
                                    <td>#<?= (int)$u['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($u['login']) ?></strong>
                                        <?php if ($is_self): ?><span class="neon-badge" style="margin-left:8px;">это вы</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['role'] === 'admin'): ?>
                                            <span class="neon-badge" style="border-color: var(--gold-primary); color: var(--gold-light);"><i class="fa-solid fa-shield-halved"></i> Администратор</span>
                                        <?php else: ?>
                                            <span class="neon-badge"><i class="fa-solid fa-user"></i> Покупатель</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: var(--text-secondary); font-size: 0.85rem;"><?= htmlspecialchars($u['created_at'] ?? '—') ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-secondary btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)"><i class="fa-solid fa-pen"></i></button>
                                            <?php if (!$is_self): ?>
                                                <a href="?tab=users&delete_user=<?= (int)$u['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить пользователя «<?= htmlspecialchars($u['login'], ENT_QUOTES) ?>»?')"><i class="fa-solid fa-trash"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'settings'): ?>
                <div style="max-width: 560px;">
                    <h3 style="margin-bottom: 8px;">Настройки профиля</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 24px;">
                        Текущий логин: <strong style="color: var(--gold-light);"><?= htmlspecialchars($_SESSION['user']['login']) ?></strong>.
                        Для подтверждения изменений введите текущий пароль.
                    </p>

                    <form method="POST" class="setup-card" style="padding: 24px;">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Текущий пароль <span style="color: var(--danger);">*</span></label>
                            <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password" />
                        </div>
                        <hr style="border: none; border-top: 1px solid var(--border-color); margin: 18px 0;" />
                        <div class="form-group">
                            <label class="form-label" for="new_login">Новый логин <small style="color: var(--text-muted);">(пусто = не менять)</small></label>
                            <input type="text" name="new_login" id="new_login" class="form-control" placeholder="Новый логин" autocomplete="username" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">Новый пароль <small style="color: var(--text-muted);">(пусто = не менять)</small></label>
                            <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Минимум 6 символов" autocomplete="new-password" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Подтвердите новый пароль</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Повторите пароль" autocomplete="new-password" />
                        </div>
                        <button type="submit" name="change_credentials" class="btn btn-primary btn-lg" style="width: 100%;">
                            <i class="fa-solid fa-floppy-disk"></i> Сохранить изменения
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'site_settings'): ?>
                <div style="max-width: 560px;">
                    <h3 style="margin-bottom: 8px;">Настройки сайта</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 24px;">
                        Глобальные параметры витрины магазина.
                    </p>

                    <form method="POST" class="setup-card" style="padding: 24px;">
                        <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0;">
                            <div>
                                <label class="form-label" style="margin-bottom: 4px;">Корзина и оформление заказа</label>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Если выключено, кнопки «В корзину» и иконка корзины скрыты.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="cart_enabled" value="1" <?= $cart_enabled ? 'checked' : '' ?> />
                                <span class="slider"></span>
                            </label>
                        </div>
						<div class="form-group" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-top: 1px solid var(--border-color); margin-top: 12px;">
                            <div>
                                <label class="form-label" style="margin-bottom: 4px;">Блок «Комбо-наборы»</label>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Если выключено, блок комбо скрывается на витрине.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="combos_enabled" value="1" <?= $combos_enabled ? 'checked' : '' ?> />
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-top: 1px solid var(--border-color);">
                            <div>
                                <label class="form-label" style="margin-bottom: 4px;">Скрывать товары без фото</label>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Скрывает товары, у которых нет картинки или стоит заглушка.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="hide_no_photo" value="1" <?= $hide_no_photo ? 'checked' : '' ?> />
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-top: 1px solid var(--border-color);">
                            <div>
                                <label class="form-label" style="margin-bottom: 4px;">Показывать цены на витрине</label>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Если выключено, цены и пометка «от» скрыты для покупателей. Блок наличия остаётся.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="prices_enabled" value="1" <?= $prices_enabled ? 'checked' : '' ?> />
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-top: 1px solid var(--border-color);">
                            <div>
                                <label class="form-label" style="margin-bottom: 4px;">Скрыть кнопку «Установить приложение» (PWA)</label>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Скрывает кнопку установки PWA на всех устройствах — и ПК, и мобильных.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="hide_pwa_install" value="1" <?= $hide_pwa_install ? 'checked' : '' ?> />
                                <span class="slider"></span>
                            </label>
                        </div>
                        <button type="submit" name="save_site_settings" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 12px;">
                            <i class="fa-solid fa-floppy-disk"></i> Сохранить настройки
                        </button>
                    </form>
                </div>
            <?php endif; ?>
			<?php if ($active_tab === 'visits'):
				$online = (int)mysqli_fetch_assoc(mysqli_query($db,
					"SELECT COUNT(DISTINCT session_id) c FROM visits WHERE last_seen_at > NOW() - INTERVAL 5 MINUTE"))['c'];
				$visits_list = [];
				$r = mysqli_query($db, "SELECT * FROM visits ORDER BY id DESC LIMIT 100");
				while ($row = mysqli_fetch_assoc($r)) $visits_list[] = $row;
			?>
				<div style="margin-bottom: 20px;">
					<h3>Посещения сайта</h3>
					<p style="color: var(--text-secondary); font-size: 0.9rem;">
						Сейчас онлайн: <strong style="color: var(--success);"><?= $online ?></strong>
						(активные за последние 5 минут) &nbsp;|&nbsp;
						Всего визитов: <strong><?= count($visits_list) ?></strong>
					</p>
				</div>
				<div class="data-table-wrapper">
					<table class="data-table">
						<thead><tr>
							<th>ID</th><th>IP</th><th>Страница входа</th><th>18+</th>
							<th>Длит.</th><th>Начало</th><th>Последняя активность</th><th>Закрыт</th>
						</tr></thead>
						<tbody>
						<?php foreach ($visits_list as $v): ?>
							<tr>
								<td>#<?= (int)$v['id'] ?></td>
								<td><code><?= htmlspecialchars($v['ip']) ?></code></td>
								<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
									<?= htmlspecialchars($v['landing_page'] ?: '—') ?>
								</td>
								<td><?= $v['age_confirmed']
									? '<span class="neon-badge" style="color:#10b981;border-color:#10b981;">Да</span>'
									: '<span class="neon-badge" style="color:#ef4444;border-color:#ef4444;">Нет</span>' ?></td>
								<td><?= gmdate('H:i:s', (int)$v['duration_seconds']) ?></td>
								<td style="font-size:0.85rem;"><?= htmlspecialchars($v['started_at']) ?></td>
								<td style="font-size:0.85rem;"><?= htmlspecialchars($v['last_seen_at']) ?></td>
								<td style="font-size:0.85rem;"><?= $v['closed_at'] ? htmlspecialchars($v['closed_at']) : '—' ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
        </main>
    </div>

    <!-- Модальное окно Товара -->
    <div id="product-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 540px;">
            <button class="modal-close" onclick="closeModal('product-modal')"><i class="fa-solid fa-xmark"></i></button>
            <h3 id="product-modal-title" style="margin-bottom: 20px;">Добавить сорт пива</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="product_id" id="prod-id" value="0" />
                <div class="form-group">
                    <label class="form-label">Название сорта</label>
                    <input type="text" name="name" id="prod-name" class="form-control" placeholder="Например: Крымская Ривьера Лагер" required />
                </div>
				<div class="form-group">
					<label class="form-label">Производитель / Пивоварня</label>
					<input type="text" name="manufacturer" id="prod-manufacturer" class="form-control" placeholder="Например: Крымская пивоварня" />
				</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Категория</label>
                        <select name="category_id" id="prod-cat" class="form-control" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Цена (руб.)</label>
                        <input type="number" name="price" id="prod-price" class="form-control" placeholder="180" required />
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Объем / Фасовка</label>
                        <input type="text" name="volume" id="prod-volume" class="form-control" value="1.0 л" />
                    </div>
                </div>
				<div class="form-group">
    <label class="form-label">Фото товара <small style="color:var(--text-muted);">(сохранится как images/image-{ID}.webp)</small></label>
    <input type="file" name="product_image" id="prod-image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewProductImage(event)" />
    <div id="prod-image-preview" style="margin-top:8px;"></div>
</div>
<div id="product-attributes-container">
					<?php foreach ($all_attributes as $attr): ?>
					<div class="form-group attr-group" data-category-id="<?= $attr['category_id'] ?? '' ?>">
						<label class="form-label">
							<?= htmlspecialchars($attr['name']) ?>
							<?php if (!empty($attr['unit'])): ?>(<?= htmlspecialchars($attr['unit']) ?>)<?php endif; ?>
						</label>
						<?php if ($attr['type'] === 'select'): ?>
							<select name="attrs[<?= (int)$attr['id'] ?>]" class="form-control">
								<option value="">—</option>
								<?php foreach ($attr['options_arr'] as $opt): ?>
									<option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ($attr['type'] === 'multiselect' || $attr['type'] === 'tag'): ?>
							<div class="multi-select-wrapper" style="display:flex;flex-wrap:wrap;gap:6px;">
								<?php foreach ($attr['options_arr'] as $opt): ?>
									<label style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid var(--border-color);border-radius:var(--radius-full);font-size:.85rem;cursor:pointer;">
										<input type="checkbox" name="attrs[<?= (int)$attr['id'] ?>][]" value="<?= htmlspecialchars($opt) ?>" />
										<?= htmlspecialchars($opt) ?>
									</label>
								<?php endforeach; ?>
							</div>
						<?php elseif ($attr['type'] === 'number'): ?>
							<input type="number" step="any" name="attrs[<?= (int)$attr['id'] ?>]" class="form-control" />
						<?php else: ?>
							<input type="text" name="attrs[<?= (int)$attr['id'] ?>]" class="form-control" />
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
                <div class="form-group">
                    <label class="form-label">Описание и вкусовые качества</label>
                    <textarea name="description" id="prod-desc" class="form-control" rows="3" placeholder="Плотность, хмель, нотки во вкусе..."></textarea>
                </div>
                <button type="submit" name="save_product" class="btn btn-primary btn-lg" style="width: 100%;"><i class="fa-solid fa-floppy-disk"></i> Сохранить товар</button>
            </form>
        </div>
    </div>

    <!-- Модальное окно Категории -->
    <div id="category-modal" class="modal-overlay">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('category-modal')"><i class="fa-solid fa-xmark"></i></button>
            <h3 id="category-modal-title" style="margin-bottom: 20px;">Категория</h3>
            <form method="POST">
                <input type="hidden" name="category_id" id="cat-id" value="0" />
                <div class="form-group">
                    <label class="form-label">Название категории</label>
                    <input type="text" name="name" id="cat-name" class="form-control" placeholder="Крафт, Светлое..." required />
                </div>
                <div class="form-group">
                    <label class="form-label">Иконка FontAwesome</label>
                    <input type="text" name="icon" id="cat-icon" class="form-control" placeholder="fa-beer-mug-empty" value="fa-beer-mug-empty" />
                </div>
                <div class="form-group">
                    <label class="form-label">Краткое описание</label>
                    <textarea name="description" id="cat-desc" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group" style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border-color);">
                    <div>
                        <label class="form-label" style="margin-bottom:4px;">Показывать пользователям</label>
                        <p style="font-size:.8rem;color:var(--text-muted);margin:0;">Выключено — категория и её товары скрыты на витрине.</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="is_visible" id="cat-visible" value="1" checked />
                        <span class="slider"></span>
                    </label>
                </div>
                <button type="submit" name="save_category" class="btn btn-primary btn-lg" style="width: 100%;"><i class="fa-solid fa-floppy-disk"></i> Сохранить категорию</button>
            </form>
        </div>
    </div>

    <!-- Модальное окно Комбо -->
    <div id="combo-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 520px;">
            <button class="modal-close" onclick="closeModal('combo-modal')"><i class="fa-solid fa-xmark"></i></button>
            <h3 id="combo-modal-title" style="margin-bottom: 20px;">Комбо-набор</h3>
            <form method="POST">
                <input type="hidden" name="combo_id" id="cb-id" value="0" />
                <div class="form-group">
                    <label class="form-label">Название сета</label>
                    <input type="text" name="name" id="cb-name" class="form-control" placeholder="Набор «Пятничный»" required />
                </div>
                <div class="form-group">
                    <label class="form-label">Состав (пиво + снеки)</label>
                    <textarea name="description" id="cb-desc" class="form-control" rows="2" placeholder="3л лагера + 500г воблы..." required></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label class="form-label">Цена (руб.)</label>
                        <input type="number" name="price" id="cb-price" class="form-control" placeholder="790" required />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Старая цена</label>
                        <input type="number" name="old_price" id="cb-old-price" class="form-control" placeholder="990" required />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Скидка %</label>
                        <input type="number" name="discount_percent" id="cb-discount" class="form-control" value="20" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">URL фото</label>
                    <input type="text" name="image" id="cb-image" class="form-control" placeholder="images/combo-1.jpg" />
                </div>
                <button type="submit" name="save_combo" class="btn btn-primary btn-lg" style="width: 100%;"><i class="fa-solid fa-floppy-disk"></i> Сохранить комбо-набор</button>
            </form>
        </div>
    </div>

    <!-- Модальное окно Пользователя -->
    <div id="user-modal" class="modal-overlay">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('user-modal')"><i class="fa-solid fa-xmark"></i></button>
            <h3 id="user-modal-title" style="margin-bottom: 20px;">Пользователь</h3>
            <form method="POST">
                <input type="hidden" name="user_id" id="user-id" value="0" />
                <div class="form-group">
                    <label class="form-label" for="user-login">Логин</label>
                    <input type="text" name="login" id="user-login" class="form-control" placeholder="Введите логин" required autocomplete="off" />
                </div>
                <div class="form-group">
                    <label class="form-label" for="user-password">Пароль <small style="color: var(--text-muted);">(пусто = не менять)</small></label>
                    <input type="password" name="password" id="user-password" class="form-control" placeholder="Оставьте пустым, чтобы не менять" autocomplete="new-password" />
                </div>
                <div class="form-group">
                    <label class="form-label" for="user-role">Роль</label>
                    <select name="role" id="user-role" class="form-control">
                        <option value="user">Покупатель</option>
                        <option value="admin">Администратор</option>
                    </select>
                </div>
                <button type="submit" name="save_user" class="btn btn-primary btn-lg" style="width: 100%;"><i class="fa-solid fa-floppy-disk"></i> Сохранить</button>
            </form>
        </div>
    </div>
	
    <script>
        function openModal(id) { document.getElementById(id)?.classList.add('active'); }
        function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

		function resetAttrFields() {
			document.querySelectorAll('.attr-group input, .attr-group select').forEach(el => {
				if (el.type === 'checkbox') el.checked = false;
				else el.value = '';
			});
			filterAttrGroups();
		}
		function filterAttrGroups() {
			const catId = document.getElementById('prod-cat')?.value || '';
			document.querySelectorAll('.attr-group').forEach(g => {
				const gCat = g.dataset.categoryId;
				g.style.display = (!gCat || gCat === '0' || gCat === 'null' || gCat === catId) ? '' : 'none';
			});
		}
		function previewProductImage(e) {
			var box = document.getElementById('prod-image-preview');
			if (!box) return;
			box.innerHTML = '';
			var f = e.target.files && e.target.files[0];
			if (!f) return;
			var im = document.createElement('img');
			im.src = URL.createObjectURL(f);
			im.style.cssText = 'max-width:160px;max-height:160px;border-radius:8px;border:1px solid var(--border-color);';
			box.appendChild(im);
		}
		function openProductModal() {
			document.getElementById('prod-id').value = '0';
			document.getElementById('prod-name').value = '';
			document.getElementById('prod-price').value = '';
			document.getElementById('prod-volume').value = '1.0 л';
			document.getElementById('prod-desc').value = '';
			document.getElementById('prod-manufacturer').value = '';
			var __pi = document.getElementById('prod-image'); if (__pi) __pi.value = '';
			var __pp = document.getElementById('prod-image-preview'); if (__pp) __pp.innerHTML = '';
			resetAttrFields();
			document.getElementById('product-modal-title').textContent = 'Добавить новый сорт пива';
			openModal('product-modal');
		}
		function editProduct(p) {
			document.getElementById('prod-id').value = p.id;
			document.getElementById('prod-name').value = p.name;
			document.getElementById('prod-cat').value = p.category_id;
			document.getElementById('prod-price').value = p.price;
			document.getElementById('prod-volume').value = p.volume || '1.0 л';
			document.getElementById('prod-desc').value = p.description || '';
			document.getElementById('prod-manufacturer').value = p.manufacturer || '';
			var __pi = document.getElementById('prod-image'); if (__pi) __pi.value = '';
			var __pp = document.getElementById('prod-image-preview');
			if (__pp) {
				__pp.innerHTML = '';
				var __im = document.createElement('img');
				__im.src = 'images/image-' + p.id + '.webp?t=' + Date.now();
				__im.style.cssText = 'max-width:160px;max-height:160px;border-radius:8px;border:1px solid var(--border-color);';
				__im.onerror = function(){ this.remove(); };
				__pp.appendChild(__im);
			}
			resetAttrFields();
			if (p.attr_values) {
				Object.entries(p.attr_values).forEach(([attrId, vals]) => {
					const sel = document.querySelector(`select[name="attrs[${attrId}]"]`);
					const inp = document.querySelector(`input[type="number"][name="attrs[${attrId}]"], input[type="text"][name="attrs[${attrId}]"]`);
					const chks = document.querySelectorAll(`input[type="checkbox"][name="attrs[${attrId}][]"]`);
					if (sel) sel.value = vals[0] || '';
					else if (inp) inp.value = vals[0] || '';
					else chks.forEach(cb => cb.checked = vals.includes(cb.value));
				});
			}
			filterAttrGroups();
			document.getElementById('product-modal-title').textContent = 'Редактировать сорт #' + p.id;
			openModal('product-modal');
		}
		// Слушатель смены категории в форме
		document.getElementById('prod-cat')?.addEventListener('change', filterAttrGroups);

        function openCategoryModal() {
            document.getElementById('cat-id').value = '0';
            document.getElementById('cat-name').value = '';
            document.getElementById('cat-icon').value = 'fa-beer-mug-empty';
            document.getElementById('cat-desc').value = '';
            document.getElementById('cat-visible').checked = true;
            document.getElementById('category-modal-title').textContent = 'Новая категория';
            openModal('category-modal');
        }
        function editCategory(c) {
            document.getElementById('cat-id').value = c.id;
            document.getElementById('cat-name').value = c.name;
            document.getElementById('cat-icon').value = c.icon || 'fa-beer-mug-empty';
            document.getElementById('cat-desc').value = c.description || '';
            document.getElementById('cat-visible').checked = !!Number(c.is_visible);
            document.getElementById('category-modal-title').textContent = 'Редактировать категорию #' + c.id;
            openModal('category-modal');
        }

        function openComboModal() {
            document.getElementById('cb-id').value = '0';
            document.getElementById('cb-name').value = '';
            document.getElementById('cb-desc').value = '';
            document.getElementById('cb-price').value = '';
            document.getElementById('cb-old-price').value = '';
            document.getElementById('cb-discount').value = '20';
            document.getElementById('cb-image').value = '';
            document.getElementById('combo-modal-title').textContent = 'Новый комбо-набор';
            openModal('combo-modal');
        }
        function editCombo(cb) {
            document.getElementById('cb-id').value = cb.id;
            document.getElementById('cb-name').value = cb.name;
            document.getElementById('cb-desc').value = cb.description;
            document.getElementById('cb-price').value = cb.price;
            document.getElementById('cb-old-price').value = cb.old_price;
            document.getElementById('cb-discount').value = cb.discount_percent;
            document.getElementById('cb-image').value = cb.image || '';
            document.getElementById('combo-modal-title').textContent = 'Редактировать комбо #' + cb.id;
            openModal('combo-modal');
        }

        function openUserModal() {
            document.getElementById('user-id').value = '0';
            document.getElementById('user-login').value = '';
            document.getElementById('user-password').value = '';
            document.getElementById('user-role').value = 'user';
            document.getElementById('user-modal-title').textContent = 'Новый пользователь';
            openModal('user-modal');
        }
        function editUser(u) {
            document.getElementById('user-id').value = u.id;
            document.getElementById('user-login').value = u.login;
            document.getElementById('user-password').value = '';
            document.getElementById('user-role').value = u.role;
            document.getElementById('user-modal-title').textContent = 'Редактировать пользователя #' + u.id;
            openModal('user-modal');
        }
    </script>
    <!-- Подключаем SortableJS для перетаскивания -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
(function(){
  var p = new URLSearchParams(location.search);
  var tab = p.get('tab');
  if (tab !== 'categories' && tab !== 'attributes') return;
  document.querySelectorAll('table.data-table tbody tr').forEach(function(tr){
    var icon = tr.querySelector('td .neon-badge .fa-eye, td .neon-badge .fa-eye-slash');
    if (!icon) return;
    var badge = icon.closest('.neon-badge');
    if (!badge) return;
    var id = tr.getAttribute('data-id');
    if (!id) {
      var t = tr.querySelector('td');
      if (t) id = parseInt(t.textContent.replace('#',''), 10);
    }
    if (!id || isNaN(id)) return;
    badge.style.cursor = 'pointer';
    badge.title = 'Показать / скрыть';
    badge.addEventListener('click', function(){
      if (badge.dataset.busy) return;
      badge.dataset.busy = '1';
      badge.style.opacity = '0.5';
      fetch('admin.php?action=toggle_visibility', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'type=' + encodeURIComponent(tab) + '&id=' + encodeURIComponent(id)
      }).then(function(r){ return r.json(); }).then(function(d){
        if (!d.success) throw new Error(d.error || 'fail');
        var v = Number(d.is_visible) === 1;
        if (v) { badge.style.borderColor = '#10b981'; badge.style.color = '#10b981'; icon.className = 'fa-solid fa-eye'; }
        else { badge.style.borderColor = '#ef4444'; badge.style.color = '#fca5a5'; icon.className = 'fa-solid fa-eye-slash'; }
      }).catch(function(e){ alert('Ошибка: ' + e.message); })
        .finally(function(){ badge.dataset.busy = ''; badge.style.opacity = ''; });
    });
  });
})();
</script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
			const tbody = document.getElementById('sortable-products');
			if (tbody) {
				new Sortable(tbody, {
					handle: '.fa-grip-vertical',
					animation: 150,
					ghostClass: 'sortable-ghost',
					onEnd: function() {
						const rows = tbody.querySelectorAll('tr');
						const orderIds = Array.from(rows).map(row => row.getAttribute('data-id'));
						fetch('admin.php?action=update_order', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({ order: orderIds })
						})
						.then(res => res.json())
						.then(data => { if (data.success) showOrderSavedToast(); });
					}
				});
			}

			const catTbody = document.getElementById('sortable-categories');
			if (catTbody) {
				new Sortable(catTbody, {
					handle: '.fa-grip-vertical',
					animation: 150,
					ghostClass: 'sortable-ghost',
					onEnd: function() {
						const rows = catTbody.querySelectorAll('tr');
						const orderIds = Array.from(rows).map(row => row.getAttribute('data-id'));
						fetch('admin.php?action=update_category_order', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({ order: orderIds })
						})
						.then(res => res.json())
						.then(data => { if (data.success) showOrderSavedToast(); });
					}
				});
			}
		});

		function showOrderSavedToast() {
			const msg = document.createElement('div');
			msg.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#10b981;color:#fff;padding:10px 20px;border-radius:8px;z-index:9999;font-weight:bold;';
			msg.textContent = 'Порядок сохранен';
			document.body.appendChild(msg);
			setTimeout(() => msg.remove(), 2000);
		}
    </script>
</body>
</html>