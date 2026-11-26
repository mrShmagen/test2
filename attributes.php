<?php
/**
 * attributes.php — гибкие атрибуты товаров (EAV-паттерн)
 * Требует, чтобы get_db_connection() уже была определена выше.
 */

function get_attributes($category_id = null) {
    $db = get_db_connection();
    // Миграция: колонка is_visible (по умолчанию — видимый)
    $col = mysqli_query($db, "SHOW COLUMNS FROM attributes LIKE 'is_visible'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($db, "ALTER TABLE attributes ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1");
    }
    if ($category_id) {
        $stmt = mysqli_prepare($db,
            "SELECT * FROM attributes WHERE category_id IS NULL OR category_id = 0 OR category_id = ?
             ORDER BY sort_order, id");
        mysqli_stmt_bind_param($stmt, "i", $category_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
    } else {
        $r = mysqli_query($db, "SELECT * FROM attributes ORDER BY sort_order, id");
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $row['options_arr'] = $row['options'] ? json_decode($row['options'], true) : [];
        $out[] = $row;
    }
    return $out;
}

/** Атрибуты одного товара с группировкой по ID атрибута (для формы). */
function get_product_attributes_by_id($product_id) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db,
        "SELECT pa.attribute_id, pa.value
         FROM product_attributes pa
         JOIN attributes a ON pa.attribute_id = a.id
         WHERE pa.product_id = ? ORDER BY a.sort_order, a.id");
    mysqli_stmt_bind_param($stmt, "i", $product_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $out[(int)$row['attribute_id']][] = $row['value'];
    }
    return $out;
}

/** Атрибуты одного товара с группировкой по коду (для витрины). */
function get_product_attributes_by_code($product_id) {
    $db = get_db_connection();
    $stmt = mysqli_prepare($db,
		"SELECT pa.value, a.code, a.name, a.type, a.unit, a.is_visible
		 FROM product_attributes pa
		 JOIN attributes a ON pa.attribute_id = a.id
		 WHERE pa.product_id = ? ORDER BY a.sort_order, a.id");
    mysqli_stmt_bind_param($stmt, "i", $product_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $out[$row['code']][] = $row;
    }
    return $out;
}

/** Пакетная загрузка атрибутов для массива товаров (витрина). */
function get_products_attributes_bulk(array $product_ids) {
    if (empty($product_ids)) return [];
    $db = get_db_connection();
    $ids = implode(',', array_map('intval', $product_ids));
    $r = mysqli_query($db,
		"SELECT pa.product_id, pa.value, a.code, a.name, a.type, a.unit, a.is_visible
		 FROM product_attributes pa
		 JOIN attributes a ON pa.attribute_id = a.id
		 WHERE pa.product_id IN ($ids) ORDER BY a.sort_order, a.id");
    $out = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $out[(int)$row['product_id']][$row['code']][] = $row;
    }
    return $out;
}

/** Сохраняет атрибуты товара. $data = [attribute_id => value|[...]]. */
function save_product_attributes($product_id, array $data) {
    $db = get_db_connection();

    // Удаляем прежние значения
    $stmt = mysqli_prepare($db, "DELETE FROM product_attributes WHERE product_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $product_id);
    mysqli_stmt_execute($stmt);
    if (empty($data)) return;

    // Разрешаем только атрибуты, допустимые для категории товара
    $stmt = mysqli_prepare($db, "SELECT category_id FROM products WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $product_id);
    mysqli_stmt_execute($stmt);
    $prod = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $cat_id = $prod ? (int)$prod['category_id'] : 0;

    $allowed = array_map('intval', array_column(get_attributes($cat_id), 'id'));

    $ins = mysqli_prepare($db,
        "INSERT IGNORE INTO product_attributes (product_id, attribute_id, value) VALUES (?, ?, ?)");
    foreach ($data as $attr_id => $values) {
        $attr_id = (int)$attr_id;
        if (!in_array($attr_id, $allowed, true)) continue;
        if (!is_array($values)) $values = [$values];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            mysqli_stmt_bind_param($ins, "iis", $product_id, $attr_id, $v);
            mysqli_stmt_execute($ins);
        }
    }
}