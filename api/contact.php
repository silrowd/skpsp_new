<?php
/**
 * СК ПСП — обработчик формы обратной связи (api/contact.php)
 *
 * Заменяет сторонний сервис FormSubmit.co: заявки обрабатываются локально
 * и не передаются за пределы сервера (152-ФЗ, нет трансграничной передачи).
 *
 * Принимает JSON POST:
 *   { "name": "...", "phone": "...", "message": "...", "consent": true,
 *     "website": "" (honeypot), "page": "/contacts" }
 *
 * Порядок действий:
 *   1. honeypot заполнен — молча «принимаем» (бот не поймёт, что его поймали);
 *   2. серверная валидация (имя, телефон, длина сообщения, согласие);
 *   3. rate-limit: не более 3 заявок за 10 минут с одного IP;
 *   4. заявка сохраняется в data/private/requests.json (ДО письма —
 *      чтобы не потерять данные при сбое почтового сервера);
 *   5. письмо отправляется Оператору через mail().
 *
 * Ответ — JSON:
 *   успех:  {"success": true}
 *   ошибка: {"success": false, "field": "name|phone|message|consent|rate", "message": "..."}
 *
 * Настройка под хостинг: если письмо не уходит, смените CONTACT_FROM_EMAIL
 * на адрес с домена сайта (многие хостеры блокируют «чужие» From-адреса).
 */

/* ---------- Настройки ---------- */

define('CONTACT_TO', 'oplavnik@yandex.ru');
// Отправитель — ящик на этом же хостинге (sweb): SPF/DKIM проходят, письмо не в спам.
define('CONTACT_FROM_EMAIL', 'info@sk-psp.ru');
define('CONTACT_FROM_NAME', 'Сайт СК ПСП');
define('CONTACT_SUBJECT', 'Новая заявка с сайта СК ПСП');

// Лимит: не более CONTACT_RATE_MAX заявок за CONTACT_RATE_WINDOW секунд с одного IP
define('CONTACT_RATE_MAX', 3);
define('CONTACT_RATE_WINDOW', 600); // 10 минут

// Лимиты полей (символы)
define('CONTACT_NAME_MAX', 100);
define('CONTACT_PHONE_MAX', 20);
define('CONTACT_MESSAGE_MAX', 2000);

// Сколько заявок хранить в requests.json (старые удаляются)
define('CONTACT_STORE_MAX', 500);

define('CONTACT_ROOT', dirname(__DIR__));
define('CONTACT_PRIVATE_DIR', CONTACT_ROOT . '/data/private');
define('CONTACT_STORE_FILE', CONTACT_PRIVATE_DIR . '/requests.json');
define('CONTACT_RATE_DIR', CONTACT_PRIVATE_DIR . '/rate');
define('CONTACT_MAIL_LOG', CONTACT_PRIVATE_DIR . '/mail_errors.log');

/* ---------- Утилиты ---------- */

function contact_json_response($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function contact_fail($field, $message, $status = 400)
{
    contact_json_response(array('success' => false, 'field' => $field, 'message' => $message), $status);
}

function contact_ok()
{
    contact_json_response(array('success' => true));
}

// Длина строки в символах (важно для кириллицы)
function contact_strlen($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

// MIME-кодирование заголовков (Subject/From) — чтобы кириллица не ломалась
function contact_mime_header($value)
{
    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($value, 'UTF-8', 'B', ' =')
        : $value;
}

// IP клиента: REMOTE_ADDR. X-Forwarded-For учитываем ТОЛЬКО если запрос
// пришёл от доверенного прокси (иначе любой клиент подделает заголовок
// и сбросит rate-limit). Список правого прокси — добавить при деплое
// за reverse-proxy/CDN (например IP вашего CDN в доверенном списке).
$TRUSTED_PROXY_NETS = array(
    // '192.0.2.0/24',  // пример: публичный IP вашего CDN/прокси
);

function contact_client_ip($trustedNets = array())
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    if ($ip !== '' && $trustedNets !== array()) {
        foreach ($trustedNets as $cidr) {
            if (ip_in_cidr($ip, $cidr)) {
                // Запрос с доверенного прокси — берём самый левый клиентский IP
                if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] !== '') {
                    $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                        $ip = $first;
                    }
                }
                break;
            }
        }
    }
    return $ip !== '' ? $ip : 'unknown';
}

// Совпадение IP с точечным адресом (a.b.c.d) или /CIDR сегментом.
function ip_in_cidr($ip, $spec)
{
    // Точное совпадение
    if (strpos($spec, '/') === false) {
        return $ip === $spec;
    }
    // CIDR: a.b.c.d/n
    $parts = explode('/', $spec, 2);
    $network = $parts[0];
    $bits = (int)$parts[1];
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    $ipLong = ip2long($ip);
    $netLong = ip2long($network);
    if ($ipLong === false || $netLong === false) {
        return false;
    }
    $mask = -1 ^ ((1 << (32 - $bits)) - 1);
    return ($ipLong & $mask) === ($netLong & $mask);
}

/* ---------- 1. Только POST, разумный размер тела ---------- */

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    contact_fail('method', 'Метод не поддерживается.', 405);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) {
    contact_fail('data', 'Некорректные данные.', 400);
}

/* ---------- 2. Читаем JSON ---------- */

$data = json_decode($raw, true);
if (!is_array($data)) {
    contact_fail('data', 'Некорректные данные.', 400);
}

/* ---------- 3. Honeypot: бот заполнил скрытое поле — молча «принимаем» ---------- */

if (isset($data['website']) && trim((string)$data['website']) !== '') {
    contact_ok();
}

/* ---------- 4. Серверная валидация ---------- */

$name = isset($data['name']) ? trim((string)$data['name']) : '';
$name = preg_replace('/[\r\n\t]+/', ' ', $name); // защита от CRLF-инъекции в Subject письма
$phone = isset($data['phone']) ? trim((string)$data['phone']) : '';
$message = isset($data['message']) ? trim((string)$data['message']) : '';
$page = isset($data['page']) ? substr(trim((string)$data['page']), 0, 200) : '';
$consent = isset($data['consent']) && $data['consent'] === true;

if (contact_strlen($name) < 2 || contact_strlen($name) > CONTACT_NAME_MAX) {
    contact_fail('name', 'Укажите имя (минимум 2 символа).');
}

$phoneDigits = preg_replace('/\D/', '', $phone);
if (!is_string($phoneDigits) || strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15 || strlen($phone) > CONTACT_PHONE_MAX) {
    contact_fail('phone', 'Укажите корректный номер телефона.');
}

if (contact_strlen($message) > CONTACT_MESSAGE_MAX) {
    contact_fail('message', 'Сообщение слишком длинное (максимум 2000 символов).');
}

if (!$consent) {
    contact_fail('consent', 'Необходимо согласие на обработку персональных данных.');
}

/* ---------- 5. Rate-limit: не более N заявок за M минут с одного IP ---------- */

$ip = contact_client_ip($TRUSTED_PROXY_NETS);

if (!is_dir(CONTACT_PRIVATE_DIR) && !@mkdir(CONTACT_PRIVATE_DIR, 0775, true)) {
    contact_fail('server', 'Внутренняя ошибка. Попробуйте позже.', 500);
}

$rateLimited = false;
if (is_dir(CONTACT_RATE_DIR) || @mkdir(CONTACT_RATE_DIR, 0775, true)) {
    $rateFile = CONTACT_RATE_DIR . '/' . sha1($ip) . '.json';
    $now = time();
    $fp = @fopen($rateFile, 'c+');
    if ($fp !== false) {
        flock($fp, LOCK_EX);
        $hits = json_decode((string)stream_get_contents($fp), true);
        $hits = is_array($hits)
            ? array_values(array_filter($hits, function ($t) use ($now) {
                return is_int($t) && $t > $now - CONTACT_RATE_WINDOW;
            }))
            : array();
        if (count($hits) >= CONTACT_RATE_MAX) {
            flock($fp, LOCK_UN);
            fclose($fp);
            $rateLimited = true;
        } else {
            $hits[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($hits));
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        // Редкая уборка старых rate-файлов, чтобы папка не росла бесконечно
        if (!$rateLimited) {
            $rateFiles = glob(CONTACT_RATE_DIR . '/*.json');
            if (is_array($rateFiles) && count($rateFiles) > 1000) {
                usort($rateFiles, function ($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                foreach (array_slice($rateFiles, 0, count($rateFiles) - 500) as $oldFile) {
                    @unlink($oldFile);
                }
            }
        }
    }
}

if ($rateLimited) {
    contact_fail('rate', 'Слишком много заявок. Попробуйте ещё раз через 10 минут.', 429);
}

/* ---------- 6. Сохраняем заявку (до письма — не потеряем при сбое почты) ---------- */

$record = array(
    'id' => date('Ymd-His') . '-' . bin2hex(random_bytes(4)),
    'created_at' => date('c'),
    'name' => $name,
    'phone' => $phone,
    'message' => $message,
    'page' => $page,
    'ip' => $ip,
    'user_agent' => substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '', 0, 300),
);

$fp = @fopen(CONTACT_STORE_FILE, 'c+');
if ($fp === false) {
    contact_fail('server', 'Внутренняя ошибка. Попробуйте позже.', 500);
}
flock($fp, LOCK_EX);
$list = json_decode((string)stream_get_contents($fp), true);
$list = is_array($list) ? array_values($list) : array();
$list[] = $record;
if (count($list) > CONTACT_STORE_MAX) {
    $list = array_slice($list, -CONTACT_STORE_MAX);
}
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
flock($fp, LOCK_UN);
fclose($fp);

/* ---------- 7. Письмо Оператору ---------- */

$subject = CONTACT_SUBJECT . ' — ' . $name;
$lines = array(
    CONTACT_SUBJECT,
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'Сообщение: ' . ($message !== '' ? $message : '—'),
    '',
    'Дата: ' . date('d.m.Y H:i'),
    'Страница: ' . ($page !== '' ? $page : '—'),
    'IP: ' . $ip,
);
$body = base64_encode(implode("\r\n", $lines));

$headers  = 'From: ' . contact_mime_header(CONTACT_FROM_NAME) . ' <' . CONTACT_FROM_EMAIL . ">\r\n";
$headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
$headers .= 'Content-Transfer-Encoding: base64' . "\r\n";

$okMail = @mail(CONTACT_TO, contact_mime_header($subject), $body, $headers);

if (!$okMail) {
    // Письмо не ушло — заявка уже сохранена в requests.json, фиксируем сбой для проверки
    @file_put_contents(
        CONTACT_MAIL_LOG,
        '[' . date('d.m.Y H:i:s') . '] mail() не сработал: «' . $subject . '» (IP ' . $ip . ")\r\n",
        FILE_APPEND
    );
}

contact_ok();