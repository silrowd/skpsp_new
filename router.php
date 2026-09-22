<?php
// ЛОКАЛЬНЫЙ роутер ТОЛЬКО для сервера разработки:  php -S localhost:8000 router.php
// На хостинге этот файл не используется — там работает .htaccess.
// Задаёт те же правила: /about → about.html, /admin → admin/admin.php.

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;

// Корень
if ($path === '/' || $path === '/index') {
    readfile($root . '/index.html');
    return true;
}

// Панель администратора
if ($path === '/admin' || $path === '/admin/') {
    require $root . '/admin/admin.php';
    return true;
}

// Фавиконка: /favicon.ico → img/favicon.ico
if ($path === '/favicon.ico') {
    header('Content-Type: image/x-icon');
    readfile($root . '/img/favicon.ico');
    return true;
}

// Личные данные (заявки с формы) — недоступны; на хостинге это делает data/private/.htaccess
if ($path === '/data/private' || strpos($path, '/data/private/') === 0) {
    error_404();
    return true;
}

$target = $root . $path;

// Реальный файл (css/js/img/старые .html) — отдаём как есть
if (is_file($target)) {
    return false;
}

// URL без расширения → файл .html
if (preg_match('/^[a-z0-9\-]+$/i', basename($path)) && is_file($target . '.html')) {
    readfile($target . '.html');
    return true;
}

// Не существует — явно 404 (встроенный сервер иначе отдаст index.html)
error_404();
return true;

function error_404() {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    $page = __DIR__ . '/404.html';
    if (is_file($page)) { readfile($page); return; }
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>404 — страница не найдена</title></head><body><h1>404</h1><p>Такой страницы нет. <a href="/">На главную</a></p></body></html>';
}
