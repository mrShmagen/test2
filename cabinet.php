<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include 'pass.php';
mysqli_report(MYSQLI_REPORT_OFF);

function get_db_connection() {
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_init();
        if (!$conn) { http_response_code(500); die('mysqli_init failed'); }
        mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 10);
        if (!@mysqli_real_connect($conn, DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306)) {
            http_response_code(500);
            die('Ошибка подключения к БД: ' . mysqli_connect_error());
        }
        mysqli_set_charset($conn, 'utf8mb4');
        $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
        if ($tbl && mysqli_num_rows($tbl) > 0) {
            $c1 = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'name'");
            if ($c1 && mysqli_num_rows($c1) === 0) {
                mysqli_query($conn, 'ALTER TABLE users ADD COLUMN name VARCHAR(100) NULL DEFAULT NULL');
            }
            $c2 = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'birth_date'");
            if ($c2 && mysqli_num_rows($c2) === 0) {
                mysqli_query($conn, 'ALTER TABLE users ADD COLUMN birth_date DATE NULL DEFAULT NULL');
            }
        }
    }
    return $conn;
}

function get_setting($key, $default = null) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db, "SELECT setting_value FROM settings WHERE setting_key = ?");
    if (!$stmt) return $default;
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? $row['setting_value'] : $default;
}

function logout_user() { unset($_SESSION['user']); session_destroy(); }
function is_logged_in() { return isset($_SESSION['user']); }

if (isset($_GET['action']) && $_GET['action'] === 'logout') { logout_user(); header('Location: index.php'); exit; }
if (!is_logged_in()) { header('Location: index.php'); exit; }

$db = get_db_connection();
$user_id = (int)($_SESSION['user']['id'] ?? 0);
$message = '';
$error = '';
$minAge = 18;
$active_tab = $_GET['tab'] ?? 'profile';
if (!in_array($active_tab, ['profile', 'security', 'favorites'], true)) $active_tab = 'profile';

$stmt = mysqli_prepare($db, 'SELECT id, login, role, name, birth_date, created_at FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$user) { logout_user(); header('Location: index.php'); exit; }

$is_admin_view = ($user['role'] ?? '') === 'admin';
$prices_enabled = $is_admin_view || get_setting('prices_enabled', '1') === '1';
$combos_enabled = $is_admin_view || get_setting('combos_enabled', '1') === '1';

$form_name  = $user['name'] ?? '';
$form_birth = $user['birth_date'] ?? '';
$form_new_login = '';

/* ---------- Изображения-заглушки по категории ---------- */
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

/* ---------- Избранное: таблица и данные ---------- */
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

$favorites_list = [];
ensure_favorites_table($db);
if ($user_id > 0) {
    $fstmt = mysqli_prepare($db, "SELECT p.id, p.name, p.price, p.volume, c.name AS category_name
                                  FROM favorites f
                                  JOIN products p ON p.id = f.product_id
                                  LEFT JOIN categories c ON p.category_id = c.id
                                  WHERE f.user_id = ?
                                  ORDER BY f.created_at DESC");
    if ($fstmt) {
        mysqli_stmt_bind_param($fstmt, 'i', $user_id);
        mysqli_stmt_execute($fstmt);
        $fres = mysqli_stmt_get_result($fstmt);
        while ($frow = mysqli_fetch_assoc($fres)) {
            $fav_img = null;
            foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
                $path = 'images/image-' . (int)$frow['id'] . '.' . $ext;
                if (file_exists(__DIR__ . '/' . $path)) { $fav_img = $path; break; }
            }
            $frow['fallback'] = get_no_photo_url($frow['category_name'] ?? null);
            $frow['image'] = $fav_img ?: $frow['fallback'];
            $favorites_list[] = $frow;
        }
    }
}

$favorites_stock_summary = [];
$favorites_product_ids = array_values(array_unique(array_map('intval', array_column($favorites_list, 'id'))));
if ($favorites_product_ids) {
    $favorites_ids_sql = implode(',', $favorites_product_ids);
    $stock_result = mysqli_query($db, "SELECT product_id, COUNT(*) AS cnt FROM shop_stock WHERE product_id IN ($favorites_ids_sql) AND quantity > 0 GROUP BY product_id");
    if ($stock_result) {
        while ($stock_row = mysqli_fetch_assoc($stock_result)) {
            $favorites_stock_summary[(int)$stock_row['product_id']] = ['cnt' => (int)$stock_row['cnt']];
        }
    }
}

if (isset($_POST['update_profile'])) {
    $active_tab = 'profile';
    $name  = trim($_POST['name'] ?? '');
    $birth = trim($_POST['birth_date'] ?? '');
    $form_name  = $name;
    $form_birth = $birth;

    if ($birth !== '') {
        $ts = strtotime($birth);
        if ($ts === false) {
            $error = 'Неверный формат даты';
        } else {
            $birthDate = new DateTime($birth);
            $today     = new DateTime('today');
            if ($birthDate > $today) {
                $error = 'Дата рождения не может быть в будущем';
            } else {
                $age = $birthDate->diff($today)->y;
                if ($age < $minAge) {
                    $error = 'Возраст должен быть не менее ' . $minAge . ' лет';
                }
            }
        }
    }

    if (!$error) {
        $bd = $birth === '' ? null : $birth;
        $stmt = mysqli_prepare($db, 'UPDATE users SET name = ?, birth_date = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ssi', $name, $bd, $user_id);
        mysqli_stmt_execute($stmt);
        $message = 'Профиль сохранён';
        $user['name'] = $name;
        $user['birth_date'] = $bd;
        $_SESSION['user']['name'] = $name !== '' ? $name : $user['login'];
    }
}

if (isset($_POST['update_credentials'])) {
    $active_tab = 'security';
    $current  = $_POST['current_password'] ?? '';
    $new_login = trim($_POST['new_login'] ?? '');
    $new_pass  = $_POST['new_password'] ?? '';
    $new_pass2 = $_POST['confirm_password'] ?? '';
    $form_new_login = $new_login;

    $stmt = mysqli_prepare($db, 'SELECT password_hash FROM users WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Текущий пароль неверен';
    } elseif ($new_login !== '' && $new_login !== $user['login']) {
        if (mb_strlen($new_login) < 3 || mb_strlen($new_login) > 50) {
            $error = 'Логин: 3-50 символов';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/u', $new_login)) {
            $error = 'Логин: только латиница, цифры, _ - .';
        } else {
            $chk = mysqli_prepare($db, 'SELECT id FROM users WHERE login = ? AND id != ?');
            mysqli_stmt_bind_param($chk, 'si', $new_login, $user_id);
            mysqli_stmt_execute($chk);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) { $error = 'Логин уже занят'; }
        }
    }
    if (!$error && $new_pass !== '' && $new_pass !== $new_pass2) { $error = 'Пароли не совпадают'; }
    if (!$error && $new_pass !== '' && strlen($new_pass) < 6) { $error = 'Пароль минимум 6 символов'; }

    if (!$error) {
        $fields = []; $types = ''; $params = [];
        if ($new_login !== '' && $new_login !== $user['login']) { $fields[] = 'login = ?'; $types .= 's'; $params[] = $new_login; }
        if ($new_pass !== '') { $fields[] = 'password_hash = ?'; $types .= 's'; $params[] = password_hash($new_pass, PASSWORD_DEFAULT); }
        if (!empty($fields)) {
            $types .= 'i'; $params[] = $user_id;
            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $stmt = mysqli_prepare($db, $sql);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            if ($new_login !== '') { $user['login'] = $new_login; $_SESSION['user']['login'] = $new_login; }
            $message = 'Учётные данные обновлены';
        } else {
            $message = 'Изменений нет';
        }
    }
}

$maxBirthDate = new DateTime('today');
$maxBirthDate->modify('-' . $minAge . ' years');
$maxBirthDateStr = $maxBirthDate->format('Y-m-d');

$birth_display = '';
if ($form_birth !== '') {
    $bts = strtotime($form_birth);
    if ($bts !== false) $birth_display = date('d.m.Y', $bts);
}

$display_name = $user['name'] ?: $user['login'];
$initial = mb_strtoupper(mb_substr($display_name !== '' ? $display_name : 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет — Пивляндия</title>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime('style.css') ?: time() ?>">
    <style>
        html { scrollbar-gutter: stable; }

        /* ---------- USER MENU ---------- */
        .user-menu-wrap { position: relative; display: inline-block; }
        .user-menu-wrap .user-menu-toggle { display: inline-flex; align-items: center; gap: 6px; }
        .user-menu-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 8px); min-width: 220px; background: #1a1f2e; border: 1px solid var(--border-color, rgba(255,255,255,.12)); border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,.45); padding: 6px; z-index: 1000; }
        .user-menu-wrap.open .user-menu-dropdown { display: block; }
        .user-menu-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; color: var(--text-primary, #fff); text-decoration: none; font-size: .9rem; }
        .user-menu-item:hover { background: rgba(245,158,11,.12); color: var(--gold-light, #fbbf24); }
        .user-menu-item i { width: 16px; text-align: center; }
        .user-menu-item.cabinet-logout { color: #ef4444; }
        .user-menu-item.cabinet-logout:hover { background: rgba(239,68,68,.1); color: #fca5a5; }
        .user-menu-divider { height: 1px; background: var(--border-color, rgba(255,255,255,.08)); margin: 6px 8px; }
        @media (max-width: 600px) { .user-menu-name { display: none; } }

        /* ---------- LAYOUT ---------- */
        .cabinet-layout {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 24px;
            max-width: 1240px;
            margin: 0 auto;
            padding: 32px 20px 60px;
            align-items: start;
            position: relative;
            z-index: 2;
        }

        /* ---------- SIDEBAR ---------- */
        .cabinet-sidebar {
            position: sticky;
            top: 92px;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border-color, rgba(255,255,255,.1));
            border-radius: 18px;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            box-sizing: border-box;
        }

        .cabinet-user-card {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 12px;
            border-radius: 14px;
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.2);
            min-width: 0;
        }
        .cabinet-avatar {
            width: 46px; height: 46px; border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b, #b45309);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem; color: #fff; font-weight: 800;
            flex-shrink: 0;
        }
        .cabinet-user-info { min-width: 0; flex: 1; }
        .cabinet-user-name {
            font-weight: 700; color: var(--text-primary, #fff);
            font-size: .95rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .cabinet-user-login {
            font-size: .78rem; color: var(--text-secondary, #b0b8c8);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .cabinet-nav { display: flex; flex-direction: column; gap: 4px; }
        .cabinet-nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            color: var(--text-secondary, #b0b8c8);
            text-decoration: none;
            font-size: .92rem; font-weight: 500;
            border: 1px solid transparent;
            transition: background .18s, color .18s, border-color .18s;
        }
        .cabinet-nav-item i { width: 18px; text-align: center; font-size: .95rem; }
        .cabinet-nav-item:hover { background: rgba(245,158,11,.08); color: var(--gold-light, #fbbf24); }
        .cabinet-nav-item.active {
            background: rgba(245,158,11,.15);
            color: var(--gold-light, #fbbf24);
            border-color: rgba(245,158,11,.35);
            font-weight: 600;
        }
        .cabinet-nav-item.cabinet-logout { color: #ef4444; }
        .cabinet-nav-item.cabinet-logout:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

        .cabinet-sidebar-footer {
            display: flex; flex-direction: column; gap: 4px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color, rgba(255,255,255,.08));
            margin-top: auto;
        }

        /* ---------- MAIN ---------- */
        .cabinet-main {
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border-color, rgba(255,255,255,.1));
            border-radius: 18px;
            padding: 32px;
            min-height: 500px;
            min-width: 0;
            box-sizing: border-box;
            width: 100%;
        }
        .cabinet-panel { display: none; width: 100%; }
        .cabinet-panel.active { display: block; }
        .cabinet-panel form { width: 100%; }
        
        .cabinet-panel-header { margin-bottom: 26px; }
        .cabinet-panel-header h2 {
            font-size: 1.55rem; font-weight: 800;
            margin: 0 0 6px; color: var(--text-primary, #fff);
        }
        .cabinet-panel-header p { margin: 0; color: var(--text-secondary, #b0b8c8); font-size: .9rem; }

        .cabinet-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; width: 100%; }
        .cabinet-form-actions {
            margin-top: 24px; padding-top: 20px;
            border-top: 1px solid var(--border-color, rgba(255,255,255,.08));
            display: flex; justify-content: flex-end;
        }

        /* ---------- DATEPICKER ---------- */
        .datepick { position: relative; }
        .datepick-input { cursor: pointer; padding-right: 42px; background: rgba(255,255,255,.05); }
        .datepick-icon {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--gold-primary, #f59e0b);
            pointer-events: none; font-size: 1.05rem;
        }
        .datepick-hint { font-size: .75rem; color: var(--text-muted, #6b7280); margin-top: 6px; }
        .datepick-popup {
            display: none; position: absolute;
            top: calc(100% + 8px); left: 0; z-index: 100;
            width: 320px;
            background: #1a1f2e;
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 16px 40px rgba(0,0,0,.55);
        }
        .datepick-popup.open { display: block; }
        .dp-header { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .dp-nav {
            background: rgba(255,255,255,.05);
            border: 1px solid var(--border-color, rgba(255,255,255,.1));
            border-radius: 8px;
            color: var(--text-primary, #fff);
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all .18s; flex-shrink: 0;
        }
        .dp-nav:hover { background: rgba(245,158,11,.15); color: var(--gold-light, #fbbf24); border-color: rgba(245,158,11,.3); }
        .dp-title { display: flex; gap: 6px; flex: 1; min-width: 0; }
        .dp-select {
            flex: 1; min-width: 0;
            background: rgba(255,255,255,.05);
            border: 1px solid var(--border-color, rgba(255,255,255,.1));
            border-radius: 8px;
            color: var(--text-primary, #fff);
            padding: 6px 8px;
            font-size: .88rem; font-family: inherit;
            cursor: pointer; outline: none;
        }
        .dp-select:focus { border-color: var(--gold-primary, #f59e0b); }
        .dp-select option { background: #1a1f2e; color: #fff; }
        .dp-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 6px; }
        .dp-weekdays span {
            text-align: center; font-size: .7rem;
            color: var(--text-muted, #6b7280);
            font-weight: 700; padding: 4px 0;
            text-transform: uppercase; letter-spacing: .5px;
        }
        .dp-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .dp-cell {
            background: transparent;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 8px 0;
            color: var(--text-primary, #fff);
            font-size: .88rem; font-family: inherit;
            cursor: pointer; transition: all .15s; text-align: center;
        }
        .dp-cell.dp-empty { cursor: default; }
        .dp-cell:not(.dp-empty):not(.dp-disabled):hover { background: rgba(245,158,11,.15); color: var(--gold-light, #fbbf24); }
        .dp-cell.dp-today { border-color: rgba(245,158,11,.5); color: var(--gold-light, #fbbf24); font-weight: 700; }
        .dp-cell.dp-selected {
            background: linear-gradient(135deg, #f59e0b, #b45309);
            color: #fff; font-weight: 700;
            box-shadow: 0 4px 12px rgba(245,158,11,.4);
            border-color: transparent;
        }
        .dp-cell.dp-disabled { color: var(--text-muted, #6b7280); opacity: .3; cursor: not-allowed; }
        .dp-footer {
            display: flex; gap: 8px; margin-top: 14px; padding-top: 12px;
            border-top: 1px solid var(--border-color, rgba(255,255,255,.08));
        }
        .dp-footer .btn { flex: 1; padding: 8px 12px; font-size: .82rem; }

        /* ---------- EMPTY STATE ---------- */
        .cabinet-empty { text-align: center; padding: 60px 20px; color: var(--text-secondary, #b0b8c8); width: 100%; }
        .cabinet-empty i { font-size: 3rem; color: rgba(245,158,11,.4); margin-bottom: 18px; }
        .cabinet-empty h3 { font-size: 1.1rem; color: var(--text-primary, #fff); margin: 0 0 8px; }
        .cabinet-empty p { margin: 0; font-size: .9rem; }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 900px) {
            .cabinet-layout { grid-template-columns: minmax(0, 1fr); padding: 16px 14px 40px; gap: 16px; }
            .cabinet-sidebar { position: static; padding: 16px; gap: 14px; }
            .cabinet-nav {
                flex-direction: row; overflow-x: auto; gap: 6px;
                padding-bottom: 4px; scrollbar-width: none;
            }
            .cabinet-nav::-webkit-scrollbar { display: none; }
            .cabinet-nav-item { flex-shrink: 0; padding: 9px 12px; font-size: .85rem; white-space: nowrap; }
            .cabinet-sidebar-footer {
                flex-direction: row; border-top: none;
                padding-top: 0; margin-top: 0; gap: 6px;
            }
            .cabinet-sidebar-footer .cabinet-nav-item { flex: 1; justify-content: center; padding: 9px 10px; }
            .cabinet-main { padding: 22px 18px; min-height: 400px; }
            .cabinet-panel-header h2 { font-size: 1.3rem; }
        }
        @media (max-width: 700px) {
            .cabinet-form-grid { grid-template-columns: 1fr; }
            .datepick-popup { width: calc(100vw - 40px); max-width: 320px; }
        }
        /* FIX: принудительная ширина — body flex/grid не должен сжимать .cabinet-layout */
        body { align-items: stretch !important; }
        body > .cabinet-layout,
        .cabinet-layout {
            width: 100% !important;
            max-width: 1240px !important;
            align-self: stretch !important;
            justify-self: stretch !important;
            margin-left: auto !important;
            margin-right: auto !important;
            flex: 1 1 auto !important;
            box-sizing: border-box;
        }
        .cabinet-main { width: 100% !important; min-width: 0 !important; flex: 1 1 auto !important; }
        .cabinet-panel.active { width: 100% !important; }
        .cabinet-panel form { width: 100% !important; }
    </style>
</head>
<body data-cabinet="<?= htmlspecialchars($active_tab) ?>">
    <ul class="bg-bubbles"></ul>

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
                        <li><a href="index.php#catalog-section" class="nav-link">Каталог сортов</a></li>
                        <?php if ($combos_enabled): ?>
                        <li><a href="index.php#combos-section" class="nav-link">Комбо-наборы</a></li>
                        <?php endif; ?>
                        <li><a href="index.php#shops-section" class="nav-link">Магазины на карте</a></li>
                    </ul>
                </nav>

                <div class="header-actions">
                    <a href="index.php" class="cart-btn" title="Корзина" style="text-decoration: none;">
                        <i class="fa-solid fa-basket-shopping" style="color: var(--gold-primary);"></i>
                    </a>
                    <div class="user-menu-wrap" id="user-menu">
                        <button type="button" class="btn btn-outline btn-sm user-menu-toggle">
                            <i class="fa-solid fa-user"></i>
                            <span class="user-menu-name"><?= htmlspecialchars($user['login']) ?></span>
                            <i class="fa-solid fa-chevron-down" style="font-size:.7rem;opacity:.7;"></i>
                        </button>
                        <div class="user-menu-dropdown">
                            <a href="cabinet.php?tab=profile" class="user-menu-item"><i class="fa-solid fa-id-card"></i> Профиль</a>
                            <a href="cabinet.php?tab=security" class="user-menu-item"><i class="fa-solid fa-key"></i> Безопасность</a>
                            <a href="cabinet.php?tab=favorites" class="user-menu-item"><i class="fa-solid fa-heart"></i> Избранное</a>
                            <div class="user-menu-divider"></div>
                            <a href="cabinet.php?action=logout" class="user-menu-item cabinet-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Выйти</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="cabinet-layout">
        <aside class="cabinet-sidebar">
            <div class="cabinet-user-card">
                <div class="cabinet-avatar"><?= htmlspecialchars($initial) ?></div>
                <div class="cabinet-user-info">
                    <div class="cabinet-user-name"><?= htmlspecialchars($display_name) ?></div>
                    <div class="cabinet-user-login">@<?= htmlspecialchars($user['login']) ?></div>
                </div>
            </div>

            <nav class="cabinet-nav">
                <a href="cabinet.php?tab=profile" class="cabinet-nav-item <?= $active_tab === 'profile' ? 'active' : '' ?>">
                    <i class="fa-solid fa-user"></i> <span>Профиль</span>
                </a>
                <a href="cabinet.php?tab=security" class="cabinet-nav-item <?= $active_tab === 'security' ? 'active' : '' ?>">
                    <i class="fa-solid fa-key"></i> <span>Безопасность</span>
                </a>
                <a href="cabinet.php?tab=favorites" class="cabinet-nav-item <?= $active_tab === 'favorites' ? 'active' : '' ?>">
                    <i class="fa-solid fa-heart"></i> <span>Избранное</span>
                </a>
            </nav>

            <div class="cabinet-sidebar-footer">
                <a href="index.php" class="cabinet-nav-item">
                    <i class="fa-solid fa-arrow-left"></i> <span>На витрину</span>
                </a>
                <a href="cabinet.php?action=logout" class="cabinet-nav-item cabinet-logout">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Выйти</span>
                </a>
            </div>
        </aside>

        <main class="cabinet-main">
            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="cabinet-panel <?= $active_tab === 'profile' ? 'active' : '' ?>">
                <div class="cabinet-panel-header">
                    <h2><i class="fa-solid fa-user" style="color: var(--gold-primary, #f59e0b); margin-right: 8px;"></i> Профиль</h2>
                    <p>Расскажите о себе — так мы сможем поздравлять вас с днём рождения и подбирать интересные сорта</p>
                </div>

                <form method="POST">
                    <div class="cabinet-form-grid">
                        <div class="form-group">
                            <label class="form-label" for="name">Имя</label>
                            <input type="text" name="name" id="name" class="form-control" value="<?= htmlspecialchars($form_name) ?>" placeholder="Как к вам обращаться" maxlength="100" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="birth-display">Дата рождения</label>
                            <div class="datepick" id="dp-wrap">
                                <input type="text" class="form-control datepick-input" id="birth-display" value="<?= htmlspecialchars($birth_display) ?>" placeholder="дд.мм.гггг" readonly autocomplete="off" />
                                <i class="fa-solid fa-calendar-days datepick-icon"></i>
                                <input type="hidden" name="birth_date" id="birth_date" value="<?= htmlspecialchars($form_birth) ?>" />
                                <div class="datepick-popup" id="dp-popup">
                                    <div class="dp-header">
                                        <button type="button" class="dp-nav" id="dp-prev"><i class="fa-solid fa-chevron-left"></i></button>
                                        <div class="dp-title">
                                            <select class="dp-select" id="dp-month"></select>
                                            <select class="dp-select" id="dp-year"></select>
                                        </div>
                                        <button type="button" class="dp-nav" id="dp-next"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>
                                    <div class="dp-weekdays">
                                        <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
                                    </div>
                                    <div class="dp-grid" id="dp-grid"></div>
                                    <div class="dp-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" id="dp-clear">Очистить</button>
                                        <button type="button" class="btn btn-primary btn-sm" id="dp-max">18 лет</button>
                                    </div>
                                </div>
                            </div>
                            <div class="datepick-hint"><i class="fa-solid fa-circle-info"></i> Сайт только для лиц старше 18 лет</div>
                        </div>
                    </div>
                    <div class="cabinet-form-actions">
                        <button type="submit" name="update_profile" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Сохранить</button>
                    </div>
                </form>
            </div>

            <div class="cabinet-panel <?= $active_tab === 'security' ? 'active' : '' ?>">
                <div class="cabinet-panel-header">
                    <h2><i class="fa-solid fa-key" style="color: var(--gold-primary, #f59e0b); margin-right: 8px;"></i> Логин и пароль</h2>
                    <p>Для изменения учётных данных подтвердите текущий пароль</p>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label" for="current_password">Текущий пароль <span style="color:#ef4444;">*</span></label>
                        <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password" placeholder="Введите текущий пароль" />
                    </div>

                    <hr style="border:none;border-top:1px solid var(--border-color, rgba(255,255,255,.1));margin:24px 0;" />

                    <div class="cabinet-form-grid">
                        <div class="form-group">
                            <label class="form-label" for="new_login">Новый логин <small style="color:var(--text-muted,#6b7280);">(пусто = не менять)</small></label>
                            <input type="text" name="new_login" id="new_login" class="form-control" value="<?= htmlspecialchars($form_new_login) ?>" placeholder="<?= htmlspecialchars($user['login']) ?>" autocomplete="username" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">Новый пароль <small style="color:var(--text-muted,#6b7280);">(пусто = не менять)</small></label>
                            <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Минимум 6 символов" autocomplete="new-password" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label" for="confirm_password">Подтвердите новый пароль</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Повторите новый пароль" autocomplete="new-password" />
                        </div>
                    </div>

                    <div class="cabinet-form-actions">
                        <button type="submit" name="update_credentials" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Обновить</button>
                    </div>
                </form>
            </div>

            <div class="cabinet-panel <?= $active_tab === 'favorites' ? 'active' : '' ?>">
                <div class="cabinet-panel-header">
                    <h2><i class="fa-solid fa-heart" style="color: var(--gold-primary, #f59e0b); margin-right: 8px;"></i> Избранное</h2>
                    <p>Ваши любимые сорта и наборы</p>
                </div>

                <?php if (!empty($favorites_list)): ?>
                <div class="favorites-grid">
                    <?php foreach ($favorites_list as $fav): ?>
                        <?php $fav_fallback = $fav['fallback'] ?? get_no_photo_url($fav['category_name'] ?? null); ?>
                        <div class="favorite-card" data-product-id="<?= (int)$fav['id'] ?>">
                            <a href="product.php?id=<?= (int)$fav['id'] ?>" class="favorite-card-img">
                                <img src="<?= htmlspecialchars($fav['image']) ?>" alt="<?= htmlspecialchars($fav['name']) ?>" loading="lazy" onerror="this.src='<?= $fav_fallback ?>'">
                            </a>
                            <button type="button" class="fav-btn active" data-product-id="<?= (int)$fav['id'] ?>" aria-label="Убрать из избранного" title="Убрать из избранного" onclick="toggleFavorite(<?= (int)$fav['id'] ?>, event)">
                                <i class="fa-solid fa-heart"></i>
                            </button>
                            <div class="favorite-card-body">
                                <a href="product.php?id=<?= (int)$fav['id'] ?>" class="favorite-card-title"><?= htmlspecialchars($fav['name']) ?></a>
                                <div class="favorite-card-meta"><?= htmlspecialchars($fav['category_name'] ?? '') ?></div>
                                <?php if ($prices_enabled): ?>
                                <div class="favorite-card-price"><?= number_format($fav['price'], 0, '', ' ') ?> ₽</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="cabinet-empty">
                    <i class="fa-solid fa-heart"></i>
                    <h3>Пока пусто</h3>
                    <p>Нажимайте на сердечко <i class="fa-regular fa-heart" style="color: var(--gold-primary);"></i> в карточках товаров, чтобы добавлять их в избранное.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

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
                        <li><a href="index.php#catalog-section">Каталог сортов</a></li>
                        <?php if ($combos_enabled): ?>
                        <li><a href="index.php#combos-section">Комбо-наборы</a></li>
                        <?php endif; ?>
                        <li><a href="index.php#shops-section">Магазины Симферополя</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Категории</h4>
                    <ul class="footer-links">
                        <li><a href="index.php#catalog-section">Всё пиво</a></li>
                        <li><a href="index.php#catalog-section">Светлые сорта</a></li>
                        <li><a href="index.php#catalog-section">Тёмные сорта</a></li>
                        <li><a href="index.php#catalog-section">Сидр и медовуха</a></li>
                        <li><a href="index.php#catalog-section">Рыба и закуски</a></li>
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

    <script>
    (function(){
        var wrap = document.getElementById('user-menu');
        if (wrap) {
            var toggle = wrap.querySelector('.user-menu-toggle');
            if (toggle) {
                toggle.addEventListener('click', function(e){
                    e.stopPropagation();
                    wrap.classList.toggle('open');
                });
            }
            document.addEventListener('click', function(){
                wrap.classList.remove('open');
            });
        }
    })();

    (function(){
        var wrapEl   = document.getElementById('dp-wrap');
        if (!wrapEl) return;
        var input    = document.getElementById('birth-display');
        var hidden   = document.getElementById('birth_date');
        var popup    = document.getElementById('dp-popup');
        var grid     = document.getElementById('dp-grid');
        var monthSel = document.getElementById('dp-month');
        var yearSel  = document.getElementById('dp-year');
        var prevBtn  = document.getElementById('dp-prev');
        var nextBtn  = document.getElementById('dp-next');
        var clearBtn = document.getElementById('dp-clear');
        var maxBtn   = document.getElementById('dp-max');
        var icon     = wrapEl.querySelector('.datepick-icon');

        var maxDate = new Date("<?= $maxBirthDateStr ?>T00:00:00");
        var minDate = new Date(1920, 0, 1);

        var selected = null;
        if (hidden.value) {
            var parts = hidden.value.split('-');
            if (parts.length === 3) {
                selected = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            }
        }

        var view = selected
            ? new Date(selected.getFullYear(), selected.getMonth(), 1)
            : new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);

        var months = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

        months.forEach(function(m, i){
            var o = document.createElement('option');
            o.value = i; o.textContent = m;
            monthSel.appendChild(o);
        });
        var minYear = 1920, maxYear = maxDate.getFullYear();
        for (var y = maxYear; y >= minYear; y--) {
            var o = document.createElement('option');
            o.value = y; o.textContent = y;
            yearSel.appendChild(o);
        }

        function pad(n){ return n < 10 ? '0' + n : '' + n; }
        function fmtDisplay(d){ return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear(); }
        function fmtValue(d){ return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
        function daysInMonth(y, m){ return new Date(y, m + 1, 0).getDate(); }

        function render() {
            monthSel.value = view.getMonth();
            yearSel.value  = view.getFullYear();
            grid.innerHTML = '';

            var first = new Date(view.getFullYear(), view.getMonth(), 1);
            var startDay = (first.getDay() + 6) % 7;
            var totalDays = daysInMonth(view.getFullYear(), view.getMonth());
            var today = new Date(); today.setHours(0,0,0,0);

            for (var i = 0; i < startDay; i++) {
                var empty = document.createElement('div');
                empty.className = 'dp-cell dp-empty';
                grid.appendChild(empty);
            }

            for (var d = 1; d <= totalDays; d++) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'dp-cell';
                cell.textContent = d;

                var cellDate = new Date(view.getFullYear(), view.getMonth(), d);
                var disabled = cellDate > maxDate || cellDate < minDate;

                if (disabled) cell.classList.add('dp-disabled');
                if (!disabled && cellDate.getTime() === today.getTime()) cell.classList.add('dp-today');
                if (selected && cellDate.getTime() === selected.getTime()) cell.classList.add('dp-selected');

                if (disabled) {
                    cell.disabled = true;
                } else {
                    (function(cd){
                        cell.addEventListener('click', function(){
                            selected = cd;
                            hidden.value = fmtValue(cd);
                            input.value  = fmtDisplay(cd);
                            closePopup();
                        });
                    })(cellDate);
                }
                grid.appendChild(cell);
            }
        }

        function openPopup(){ popup.classList.add('open'); render(); }
        function closePopup(){ popup.classList.remove('open'); }

        input.addEventListener('click', function(e){
            e.stopPropagation();
            if (popup.classList.contains('open')) closePopup(); else openPopup();
        });
        icon.addEventListener('click', function(e){
            e.stopPropagation();
            if (popup.classList.contains('open')) closePopup(); else openPopup();
        });
        document.addEventListener('click', function(e){
            if (!wrapEl.contains(e.target)) closePopup();
        });

        prevBtn.addEventListener('click', function(){ view.setMonth(view.getMonth() - 1); render(); });
        nextBtn.addEventListener('click', function(){ view.setMonth(view.getMonth() + 1); render(); });
        monthSel.addEventListener('change', function(){ view.setMonth(parseInt(monthSel.value, 10)); render(); });
        yearSel.addEventListener('change', function(){ view.setFullYear(parseInt(yearSel.value, 10)); render(); });

        clearBtn.addEventListener('click', function(){
            selected = null;
            hidden.value = '';
            input.value = '';
            closePopup();
        });
        maxBtn.addEventListener('click', function(){
            selected = new Date(maxDate.getTime());
            hidden.value = fmtValue(selected);
            input.value  = fmtDisplay(selected);
            view = new Date(selected.getFullYear(), selected.getMonth(), 1);
            closePopup();
        });
    })();
    </script>
    <script src="script.js?v=<?= @filemtime('script.js') ?: time() ?>"></script>
    <script>
        window.pricesEnabled = <?= $prices_enabled ? 'true' : 'false' ?>;
        window.stockSummary = <?= json_encode($favorites_stock_summary, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="stock-widget.js?v=<?= @filemtime('stock-widget.js') ?: time() ?>"></script>
</body>
</html>