<?php
/**
 * СК ПСП — настройки админки новостей
 *
 * ВАЖНО: смените логин и пароль на свои, чтобы никто посторонний
 * не смог управлять новостями сайта.
 *
 * Этот файл подключается админкой (admin.php) и не предназначен
 * для прямого обращения из браузера.
 */

// Защита от прямого доступа: если файл открыт напрямую — показываем 403.
if (!defined('NEWS_CONFIG_GUARD') && (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Доступ запрещён.';
    exit;
}
define('NEWS_CONFIG_GUARD', true);

define('NEWS_ADMIN_USER', 'admin');

/* Пароль хранится как hash из password_hash() (bcrypt). Сброс пароля:
   php -r 'echo password_hash("НОВЫЙ_ПАРОЛЬ", PASSWORD_DEFAULT);'
   и подставьте результат сюда. SHA256+соль устарела (быстрая проверка — брутфорс). */
define('NEWS_ADMIN_HASH', '$2y$10$6U92EqbldZ1dSaFxZGeadeGhSVv9XL/aslh4/8u0Z0ZVwiWI13rgS');

// Путь к файлу с новостями (относительно корня сайта)
define('NEWS_JSON_PATH', dirname(__DIR__) . '/data/news.json');

// Папка для изображений новостей (относительно корня сайта)
define('NEWS_IMG_DIR', dirname(__DIR__) . '/img/news');

// Максимальный размер загружаемого изображения, байт (2 МБ)
define('NEWS_IMG_MAX_SIZE', 2097152);

// Разрешённые расширения изображений
define('NEWS_IMG_EXT', array('jpg', 'jpeg', 'png', 'webp', 'gif'));

/* ---------- Объекты (data/objects.json) ---------- */

// Путь к файлу с объектами
define('OBJECTS_JSON_PATH', dirname(__DIR__) . '/data/objects.json');

// Папка для изображений объектов (те же, что на страницах категорий)
define('OBJECTS_IMG_DIR', dirname(__DIR__) . '/img_objects/Objects');

// Максимальный размер, байт (4 МБ — фото объектов крупнее, чем обложки новостей)
define('OBJECTS_IMG_MAX_SIZE', 4194304);

// Расширения изображений объектов
define('OBJECTS_IMG_EXT', array('jpg', 'jpeg', 'png', 'webp', 'gif'));

// Категории объектов (slug => название). Соответствуют страницам сайта.
define('OBJECTS_CATEGORIES', array(
    'business-centers' => 'Бизнес-центры',
    'public-buildings' => 'Общественные здания',
    'shopping-malls'   => 'Торговые комплексы',
    'industrial'       => 'Промышленные объекты',
    'residential'      => 'Жилые дома',
    'reconstruction'   => 'Реконструкция',
));