<?php
// Простой HTTP-сервер: чистые URL (как в .htaccess) + статика
// Запуск: php server.php 8920

$port = (int)($argv[1] ?? 8920);
$root = __DIR__;

$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Cannot bind: $errstr\n");
    exit(1);
}
fwrite(STDERR, "Listening on 0.0.0.0:$port, root: $root\n");

while (true) {
    $conn = @stream_socket_accept($server, 0);
    if (!$conn) continue;
    
    $request = fread($conn, 8192);
    if (!$request) { fclose($conn); continue; }
    
    // Разбираем запрос
    $lines = explode("\r\n", $request);
    $requestLine = $lines[0];
    preg_match('#^(\S+) (\S+) #', $requestLine, $m);
    $method = $m[1] ?? 'GET';
    $uri = $m[2] ?? '/';
    
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    
    // Маршрутизация
    $file = null;
    $contentType = null;
    
    // 1. /admin/ → admin/admin.php
    if ($path === '/admin' || $path === '/admin/') {
        $file = $root . '/admin/admin.php';
    }
    // 2. /admin/... → admin/...
    elseif (strpos($path, '/admin/') === 0) {
        $rel = substr($path, 1);
        $file = $root . '/' . $rel;
        if (!is_file($file)) $file = $root . '/' . $rel . '.php';
    }
    // 3. /favicon.ico → /img/favicon.ico
    elseif ($path === '/favicon.ico') {
        $file = $root . '/img/favicon.ico';
    }
    // 4. Root: / → index.html
    elseif ($path === '/') {
        $file = $root . '/index.html';
        if (!is_file($file)) $file = null;
    }
    // 5. Чистые URL: /about → about.html
    elseif (preg_match('#^/([a-z0-9\-]+)$#', $path, $m2)) {
        $file = $root . '/' . $m2[1] . '.html';
        if (!is_file($file)) $file = null;
    }
    // 5. Всё остальное — прямой путь
    else {
        $file = $root . $path;
        if (!is_file($file)) $file = null;
    }
    
    if ($file && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = array(
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
        );
        $contentType = $types[$ext] ?? 'application/octet-stream';
        $content = file_get_contents($file);
        
        $headers = "HTTP/1.1 200 OK\r\n";
        $headers .= "Content-Type: $contentType\r\n";
        $headers .= "Content-Length: " . strlen($content) . "\r\n";
        $headers .= "Connection: close\r\n\r\n";
        fwrite($conn, $headers . $content);
    } else {
        // 404
        $errFile = $root . '/404.html';
        $errContent = is_file($errFile) ? file_get_contents($errFile) : '404 Not Found';
        $headers = "HTTP/1.1 404 Not Found\r\n";
        $headers .= "Content-Type: text/html; charset=utf-8\r\n";
        $headers .= "Content-Length: " . strlen($errContent) . "\r\n";
        $headers .= "Connection: close\r\n\r\n";
        fwrite($conn, $headers . $errContent);
    }
    
    fclose($conn);
}
