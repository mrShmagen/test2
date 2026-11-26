<?php
/**
 * Открывается по адресу: product.php?id=<ID товара>
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
            die('Ошибка подключения к базе данных: ' . mysqli_connect_error());
        }
        mysqli_set_charset($conn, 'utf8mb4');

        // Миграция: is_visible для атрибутов
        $tblA = mysqli_query($conn, "SHOW TABLES LIKE 'attributes'");
        if ($tblA && mysqli_num_rows($tblA) > 0) {
            $colA = mysqli_query($conn, "SHOW COLUMNS FROM attributes LIKE 'is_visible'");
            if ($colA && mysqli_num_rows($colA) === 0) {
                mysqli_query($conn, "ALTER TABLE attributes ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1");
            }
        }
    }
    return $conn;
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

function get_setting($key, $default = null) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db, "SELECT setting_value FROM settings WHERE setting_key = ?");
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? $row['setting_value'] : $default;
}

function safe_image_url($url, $category_name = null) {
    $no_photo = get_no_photo_url($category_name);
    $url = trim($url ?? '');
    if ($url === '' || strpos($url, 'http') === 0) return $no_photo;
    $webpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $url);
    $webpPath = __DIR__ . '/' . ltrim($webpUrl, '/');
    if (file_exists($webpPath)) return $webpUrl;
    $origPath = __DIR__ . '/' . ltrim($url, '/');
    if (!file_exists($origPath)) return $no_photo;
    return $url;
}

$db = get_db_connection();
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = mysqli_prepare($db, "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
mysqli_stmt_bind_param($stmt, "i", $product_id);
mysqli_stmt_execute($stmt);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($product) {
    $product['image'] = get_product_image($product['id'], $product['category_name'] ?? null);
    $product['no_photo'] = get_no_photo_url($product['category_name'] ?? null);
}

if (!$product) {
    http_response_code(404);
    header('Location: index.php');
    exit;
}

$related = [];
if (!empty($product['category_id'])) {
    $stmt = mysqli_prepare($db, "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.category_id = ? AND p.id != ? ORDER BY p.sort_order ASC, p.id ASC LIMIT 8");
    mysqli_stmt_bind_param($stmt, "ii", $product['category_id'], $product['id']);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
	while ($row = mysqli_fetch_assoc($r)) {
		$row['image'] = get_product_image($row['id'], $row['category_name'] ?? null);
		$row['no_photo'] = get_no_photo_url($row['category_name'] ?? null);
		$related[] = $row;
	}
}

$stock_product_ids = array_values(array_unique(array_map('intval', array_merge([(int)$product['id']], array_column($related, 'id')))));
$stock_summary = [];
if ($stock_product_ids) {
    $stock_ids_sql = implode(',', $stock_product_ids);
    $stock_result = mysqli_query($db, "SELECT product_id, COUNT(*) AS cnt FROM shop_stock WHERE product_id IN ($stock_ids_sql) AND quantity > 0 GROUP BY product_id");
    if ($stock_result) {
        while ($stock_row = mysqli_fetch_assoc($stock_result)) {
            $stock_summary[(int)$stock_row['product_id']] = ['cnt' => (int)$stock_row['cnt']];
        }
    }
}

require_once __DIR__ . '/attributes.php';
$product_attrs = get_product_attributes_by_code((int)$product['id']);
$is_admin_view = isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
$cart_enabled  = $is_admin_view || get_setting('cart_enabled', '1') === '1';
$prices_enabled = $is_admin_view || get_setting('prices_enabled', '1') === '1';

/* Избранное */
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
$is_favorite = false;
$favorites_ids = [];
$favorite_drink_discount = ['product_id'=>0,'discount_percent'=>0];
if (isset($_SESSION['user'])) {
    $fav_uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($fav_uid > 0) {
        ensure_favorites_table($db);
        $fstmt = mysqli_prepare($db, "SELECT product_id FROM favorites WHERE user_id = ?");
        if ($fstmt) {
            mysqli_stmt_bind_param($fstmt, "i", $fav_uid);
            mysqli_stmt_execute($fstmt);
            $fres = mysqli_stmt_get_result($fstmt);
            while ($frow = mysqli_fetch_assoc($fres)) $favorites_ids[] = (int)$frow['product_id'];
            $is_favorite = in_array((int)$product['id'], $favorites_ids, true);
        }
    }
    $fdTableCheck = mysqli_query($db, "SHOW TABLES LIKE 'favorite_drinks'");
    if ($fav_uid > 0 && $fdTableCheck && mysqli_num_rows($fdTableCheck) > 0) {
        $fdq = mysqli_prepare($db, "SELECT product_id,discount_percent FROM favorite_drinks WHERE user_id=? AND product_id IS NOT NULL");
        mysqli_stmt_bind_param($fdq, 'i', $fav_uid);
        mysqli_stmt_execute($fdq);
        $fdrow = mysqli_fetch_assoc(mysqli_stmt_get_result($fdq));
        if ($fdrow) $favorite_drink_discount = ['product_id'=>(int)$fdrow['product_id'],'discount_percent'=>(float)$fdrow['discount_percent']];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> — Пивляндия</title>
    <meta name="description" content="<?= htmlspecialchars(mb_substr($product['description'] ?? '', 0, 160)) ?>">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap"></noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime('style.css') ?: time() ?>">
    <style>
        .product-page { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start; }
        .product-page-image {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: var(--radius-lg); overflow: hidden; position: relative;
            aspect-ratio: 1 / 1;
        }
        .product-page-image img { width: 100%; height: 100%; object-fit: contain; background: #11141c; }
        .product-page-info { display: flex; flex-direction: column; gap: 18px; }
        .product-page-title { font-size: 2rem; font-weight: 800; line-height: 1.2; }
        .product-page-badges { display: flex; flex-wrap: wrap; gap: 8px; }
        .product-page-desc { color: var(--text-secondary); font-size: 1rem; line-height: 1.6; }
        .product-page-specs {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
            padding: 16px; background: rgba(255,255,255,0.03);
            border: 1px dashed var(--border-color); border-radius: var(--radius-md);
        }
        .product-page-price-row {
            display: flex; align-items: center; justify-content: space-between; gap: 20px;
            padding-top: 20px; border-top: 1px solid var(--border-color); margin-top: 10px;
        }
        .product-page-price { font-size: 2rem; font-weight: 800; color: var(--gold-light); }
        .product-page-price-label { font-size: 0.85rem; color: var(--text-muted); }
        .product-page-back { color: var(--text-secondary); display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; }
        .product-page-back:hover { color: var(--gold-light); }
        .related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
        .related-card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: var(--radius-md); overflow: hidden;
            transition: var(--transition-smooth); display: flex; flex-direction: column;
        }
        .related-card:hover { transform: translateY(-5px); border-color: var(--gold-primary); box-shadow: 0 12px 25px rgba(0,0,0,0.6); }
        .related-card img { width: 100%; height: 160px; object-fit: cover; background: #11141c; }
        .related-card-body { padding: 14px; display: flex; flex-direction: column; gap: 6px; flex-grow: 1; }
        .related-card-title { font-weight: 700; font-size: 1rem; color: var(--text-primary); }
        .related-card-price { color: var(--gold-light); font-weight: 800; font-size: 1.15rem; margin-top: auto; }
        @media (max-width: 768px) {
            .product-page { grid-template-columns: 1fr; gap: 20px; }
            .product-page-title { font-size: 1.4rem; }
            .product-page-price { font-size: 1.5rem; }
            .product-page-price-row { flex-direction: column; align-items: stretch; }
            .related-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .related-card img { height: 120px; }
            .related-card-body { padding: 10px; }
            .related-card-title { font-size: 0.85rem; }
            .related-card-price { font-size: 1rem; }
        }
    </style>
</head>
<body data-prices-enabled="<?= $prices_enabled ? '1' : '0' ?>">
    <ul class="bg-bubbles"></ul>

    <div id="age-modal" class="modal-overlay">
        <div class="modal-box" style="text-align: center;">
            <div style="font-size: 3.5rem; color: var(--gold-light); margin-bottom: 16px;">
                <i class="fa-solid fa-beer-mug-empty"></i>
            </div>
            <h2 class="neon-title" style="font-size: 1.8rem; margin-bottom: 12px;">ПИВЛЯНДИЯ</h2>
            <h3 style="font-size: 1.2rem; margin-bottom: 14px;">Вам уже исполнилось 18 лет?</h3>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 24px;">
                Сайт содержит информацию об алкогольной продукции и предназначен только для совершеннолетних.
            </p>
            <div class="age-buttons">
                <button class="btn btn-primary btn-lg" onclick="confirmAge(true)"><i class="fa-solid fa-check"></i> Да, мне есть 18</button>
                <button class="btn btn-secondary btn-lg" onclick="confirmAge(false)">Нет, мне меньше</button>
            </div>
        </div>
    </div>

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
                <div class="header-actions">
                    <a href="index.php#catalog-section" class="btn btn-secondary btn-sm">
                        <i class="fa-solid fa-arrow-left"></i> В каталог
                    </a>
                    <?php if ($cart_enabled): ?>
                    <button class="cart-btn" onclick="toggleCartDrawer(true)">
                        <i class="fa-solid fa-basket-shopping" style="color: var(--gold-primary);"></i>
                        <span class="cart-count">0</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <section class="section">
        <div class="container">
            <div style="margin-bottom: 20px;">
                <a href="index.php#catalog-section" class="product-page-back">
                    <i class="fa-solid fa-arrow-left"></i> Назад в каталог
                </a>
            </div>
            <div class="product-page">
                <div class="product-page-image">
					<?php $main_fallback = get_no_photo_url($product['category_name'] ?? null); ?>
					<img src="<?= htmlspecialchars(safe_image_url($product['image'], $product['category_name'] ?? null)) ?>"
						 alt="<?= htmlspecialchars($product['name']) ?>"
						 onerror="this.src='<?= $main_fallback ?>'">
                    <button type="button" class="fav-btn fav-btn-page<?= $is_favorite ? ' active' : '' ?>" data-product-id="<?= (int)$product['id'] ?>" aria-label="В избранное" title="В избранное" onclick="toggleFavorite(<?= (int)$product['id'] ?>, event)">
                        <i class="fa-<?= $is_favorite ? 'solid' : 'regular' ?> fa-heart"></i>
                    </button>
                </div>
                <div class="product-page-info" data-product-id="<?= (int)$product['id'] ?>">
                    <div class="product-page-badges">
                        <?php if (!empty($product['category_name'])): ?>
                            <span class="neon-badge"><?= htmlspecialchars($product['category_name']) ?></span>
                        <?php endif; ?>
                        <span class="neon-badge"><?= htmlspecialchars($product['volume'] ?? '1.0 л') ?></span>
                    </div>
                    <h1 class="product-page-title"><?= htmlspecialchars($product['name']) ?></h1>
                    <?php if (!empty($product['manufacturer'])): ?>
                        <div style="color: var(--gold-light); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">
                            <?= htmlspecialchars($product['manufacturer']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($product['description'])): ?>
                        <p class="product-page-desc"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                    <?php endif; ?>
					<?php
					$visible_attrs = [];
					foreach ($product_attrs as $code => $rows) {
						$first = $rows[0];
						if (empty($first['is_visible']) && !$is_admin_view) continue;
						$visible_attrs[$code] = $rows;
					}
					if (!empty($visible_attrs)): ?>
					<div class="product-page-specs">
						<?php foreach ($visible_attrs as $code => $rows):
							$first = $rows[0];
							$val = implode(', ', array_column($rows, 'value'));
						?>
							<div class="spec-item">
								<span><?= htmlspecialchars($first['name']) ?></span>
								<strong><?= htmlspecialchars($val) ?><?= $first['unit'] ? ' ' . htmlspecialchars($first['unit']) : '' ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
                    <div class="product-page-price-row">
                        <?php if ($prices_enabled): ?>
                        <div>
                            <?php
							$pp_label = strpos($product['volume'] ?? '', 'г') !== false
								? 'Цена за ' . htmlspecialchars($product['volume'])
								: 'Цена за 1 л';
                                $is_favorite_drink = (int)$favorite_drink_discount['product_id'] === (int)$product['id'];
                                $favorite_price = $is_favorite_drink ? round((float)$product['price'] * (1 - (float)$favorite_drink_discount['discount_percent']/100), 2) : (float)$product['price'];
							?>
							<div class="product-page-price-label"><?= $pp_label ?></div>
                                <?php if ($is_favorite_drink): ?><div style="color:var(--gold-light);font-size:.85rem;">Любимый напиток · скидка <?= number_format($favorite_drink_discount['discount_percent'],2,',',' ') ?>%</div><div style="text-decoration:line-through;color:var(--text-muted);"><?= number_format($product['price'],0,'',' ') ?> ₽</div><?php endif; ?>
							<div class="product-page-price"><?= number_format($favorite_price, 0, '', ' ') ?> ₽</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($cart_enabled): ?>
                        <button class="btn btn-primary btn-lg" onclick="addToCart(<?= (int)$product['id'] ?>, 'product')">
                            <i class="fa-solid fa-cart-plus"></i> В корзину
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($related)): ?>
    <section class="section" style="padding-top: 20px;">
        <div class="container">
            <div class="section-header">
                <span class="section-tag"><i class="fa-solid fa-beer-mug-empty"></i> Ещё из этой категории</span>
                <h2 class="section-title">Похожие сорта</h2>
            </div>
            <div class="related-grid">
                <?php foreach ($related as $rp): ?>
                    <a href="product.php?id=<?= (int)$rp['id'] ?>" class="related-card" data-product-id="<?= (int)$rp['id'] ?>">
						<?php $rp_fallback = get_no_photo_url($rp['category_name'] ?? null); ?>
						<img src="<?= htmlspecialchars(safe_image_url($rp['image'], $rp['category_name'] ?? null)) ?>"
							 alt="<?= htmlspecialchars($rp['name']) ?>" loading="lazy"
							 onerror="this.src='<?= $rp_fallback ?>'">
                        <div class="related-card-body">
                            <div class="related-card-title"><?= htmlspecialchars($rp['name']) ?></div>
                            <?php if ($prices_enabled): ?>
                            <div class="related-card-price"><?= number_format($rp['price'], 0, '', ' ') ?> ₽</div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="fav-btn<?= in_array((int)$rp['id'], $favorites_ids) ? ' active' : '' ?>" data-product-id="<?= (int)$rp['id'] ?>" aria-label="В избранное" title="В избранное" onclick="toggleFavorite(<?= (int)$rp['id'] ?>, event)">
                            <i class="fa-<?= in_array((int)$rp['id'], $favorites_ids) ? 'solid' : 'regular' ?> fa-heart"></i>
                        </button>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <script>
        window.initialFavorites = <?= json_encode($favorites_ids) ?>;
        window.pricesEnabled = <?= $prices_enabled ? 'true' : 'false' ?>;
        window.stockSummary = <?= json_encode($stock_summary, JSON_UNESCAPED_UNICODE) ?>;
    </script>

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
            <a href="index.php#catalog-section" class="btn btn-primary btn-lg" style="width: 100%;">
                <i class="fa-solid fa-arrow-left"></i> Перейти к оформлению
            </a>
        </div>
    </div>
    <?php endif; ?>

    <footer class="site-footer">
        <div class="container">
            <div class="legal-warning-banner">
                <i class="fa-solid fa-triangle-exclamation"></i> ЧРЕЗМЕРНОЕ УПОТРЕБЛЕНИЕ АЛКОГОЛЯ ВРЕДИТ ВАШЕМУ ЗДОРОВЬЮ. ПРОДАЖА ЛИЦАМ ДО 18 ЛЕТ ЗАПРЕЩЕНА.
            </div>
            <div class="footer-bottom">
                &copy; <?= date('Y') ?> Сеть магазинов разливного пива «Пивляндия» (г. Симферополь). Все права защищены.
            </div>
        </div>
    </footer>

    <?php
        $client_products = array_merge([$product], $related);
        if (!$prices_enabled) {
            foreach ($client_products as &$client_product) unset($client_product['price']);
            unset($client_product);
        }
    ?>
    <script>
        window.initialProducts   = <?= json_encode($client_products, JSON_UNESCAPED_UNICODE) ?>;
        window.initialSnacks     = [];
        window.initialCombos     = [];
        window.initialCategories = [];
        window.cartEnabled       = <?= $cart_enabled ? 'true' : 'false' ?>;
        window.pricesEnabled     = <?= $prices_enabled ? 'true' : 'false' ?>;
        window.stockSummary      = <?= json_encode($stock_summary, JSON_UNESCAPED_UNICODE) ?>;
        window.favoriteDrink = <?= json_encode(['productId'=>(int)$favorite_drink_discount['product_id'],'discountPercent'=>(float)$favorite_drink_discount['discount_percent']]) ?>;
        window.combosEnabled     = false;
    </script>
    <script src="script.js?v=<?= @filemtime('script.js') ?: time() ?>"></script>
    <script src="stock-widget.js?v=<?= @filemtime('stock-widget.js') ?: time() ?>"></script>
</body>
</html>