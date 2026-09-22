<?php
/**
 * СК ПСП — админка (новости + объекты)
 * Управляет файлами data/news.json и data/objects.json (без базы данных).
 * Загрузка изображений: новости — img/news/, объекты — img_objects/Objects/.
 */

require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    // Безопасная session cookie: скрыта от JS (httponly), samesite Lax (CSRF).
    // secure=true включается автоматически, если сайт работает по HTTPS.
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => (bool)$isHttps,
        'samesite' => 'Lax',
    ));
}
session_start();

/* ---------- helpers ---------- */

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Запасная реализация mb_strimwidth на случай, если расширение mbstring не включено */
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($string, $start, $width, $trimmarker, $encoding = 'UTF-8') {
        $string = (string)$string;
        if (function_exists('mb_strlen')) {
            return mb_substr($string, $start, $width, $encoding) .
                   (mb_strlen($string, $encoding) > $start + $width ? $trimmarker : '');
        }
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        $total = count($chars);
        $slice = array_slice($chars, $start, $width);
        $out = implode('', $slice);
        if ($total > $start + $width) { $out .= $trimmarker; }
        return $out;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/* Одноразовый токен формы против двойной отправки (double-submit). */
function issue_form_nonce() {
    if (empty($_SESSION['form_nonce'])) {
        $_SESSION['form_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['form_nonce'];
}

function verify_login($user, $pass) {
    /* bcrypt: password_verify сам ведёт константное время сравнения.
       Логин сравниваем case-sensitive — он короткий и известен. */
    if ($user !== NEWS_ADMIN_USER) {
        // Запустим verify и на неверном логине, чтобы время ответа не выдавало,
        // угадан ли логин (timing-атаки).
        password_verify($pass, NEWS_ADMIN_HASH);
        return false;
    }
    return password_verify($pass, NEWS_ADMIN_HASH);
}

/* ---------- Rate-limit логина: не более 5 неудачных попыток за 15 минут с IP ----------
   Файл с картой sha1(ip) => [таймстампы], flock как в api/contact.php.
   Session-счётчика недостаточно: брутфорс-скрипт шлёт запросы без cookies. */
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW', 900); // 15 минут
define('LOGIN_RATE_FILE', dirname(__DIR__) . '/data/private/admin_rate.json');

function login_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return $ip !== '' ? $ip : 'unknown';
}

function login_attempts_blocked() {
    $ipKey = sha1(login_client_ip());
    $now = time();
    $map = array();
    if (is_file(LOGIN_RATE_FILE)) {
        $fp = @fopen(LOGIN_RATE_FILE, 'r');
        if ($fp) { flock($fp, LOCK_SH); $map = json_decode((string)stream_get_contents($fp), true); flock($fp, LOCK_UN); fclose($fp); }
    }
    if (!is_array($map)) $map = array();
    $a = isset($map[$ipKey]) ? array_values($map[$ipKey]) : array();
    $a = array_values(array_filter($a, function ($t) use ($now) {
        return is_int($t) && $t > $now - LOGIN_WINDOW;
    }));
    return count($a) >= LOGIN_MAX_ATTEMPTS;
}

function login_register_failure() {
    $ipKey = sha1(login_client_ip());
    $now = time();
    $dir = dirname(LOGIN_RATE_FILE);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fp = @fopen(LOGIN_RATE_FILE, 'c+');
    if ($fp === false) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $map = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($map)) $map = array();
    $a = isset($map[$ipKey]) ? array_values($map[$ipKey]) : array();
    $a = array_values(array_filter($a, function ($t) use ($now) {
        return is_int($t) && $t > $now - LOGIN_WINDOW;
    }));
    $a[] = $now;
    $map[$ipKey] = array_slice($a, -LOGIN_MAX_ATTEMPTS * 2);
    // Подчищаем старые IP, чтобы файл не рос
    foreach ($map as $k => $v) {
        $recent = array_filter((array)$v, function ($t) use ($now) {
            return is_int($t) && $t > $now - LOGIN_WINDOW;
        });
        if ($recent === array()) unset($map[$k]);
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($map));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function login_clear_failures() {
    $ipKey = sha1(login_client_ip());
    $fp = @fopen(LOGIN_RATE_FILE, 'c+');
    if ($fp === false) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $map = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($map)) $map = array();
    unset($map[$ipKey]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($map));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function load_news() {
    if (!is_file(NEWS_JSON_PATH)) return array();
    $raw = file_get_contents(NEWS_JSON_PATH);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

/* Атомарная запись JSON: tmp-файл в той же папке + rename() — на POSIX и
   NTFS rename атомарен, так что обрыв процесса не оставит обрезанный файл. */
function atomic_write_json($path, array $value) {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);
    $dir = dirname($path);
    $tmp = $dir . '/.' . basename($path) . '.' . getmypid() . '.tmp';
    $fh = fopen($tmp, 'w');
    if ($fh === false) return false;
    $ok = fwrite($fh, $json . "\n") !== false;
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    $renamed = rename($tmp, $path);
    if (!$renamed) { @unlink($tmp); }
    return $renamed;
}

function save_news($list) {
    usort($list, function ($a, $b) {
        $r = strcmp($b['date'], $a['date']);
        if ($r !== 0) return $r;
        return (int)$b['id'] - (int)$a['id'];
    });
    return atomic_write_json(NEWS_JSON_PATH, $list);
}

function load_objects() {
    if (!is_file(OBJECTS_JSON_PATH)) return array();
    $raw = file_get_contents(OBJECTS_JSON_PATH);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function save_objects($list) {
    return atomic_write_json(OBJECTS_JSON_PATH, $list);
}

function fmt_date($iso) {
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y', $ts) : $iso;
}

/* Загрузка изображения из формы. Возвращает относительный путь от корня сайта
   или пустую строку. При ошибке — пишет в $error. */
function handle_image_upload(array $f, $imgDir, $prefix, $exts, $maxSize, &$error) {
    if (empty($f['name'])) return '';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) {
        $error = 'Недопустимый формат изображения. Разрешены: ' . implode(', ', $exts) . '.';
        return '';
    }
    if ($f['size'] > $maxSize) {
        $error = 'Файл слишком большой (максимум ' . round($maxSize / 1048576) . ' МБ).';
        return '';
    }
    if (!is_file($f['tmp_name']) || !getimagesize($f['tmp_name'])) {
        $error = 'Файл не является корректным изображением.';
        return '';
    }
    if (!is_dir($imgDir) && !mkdir($imgDir, 0755, true)) {
        $error = 'Не удалось создать папку изображений. Проверьте права записи.';
        return '';
    }
    $fname = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $imgDir . '/' . $fname)) {
        $error = 'Не удалось сохранить изображение. Проверьте права записи.';
        return '';
    }
    return str_replace('\\', '/', $imgDir) . '/' . $fname;
}

/* ---------- state ---------- */

$flash = '';
$error = '';
$formGuardBlocked = false;
$section = isset($_GET['section']) && in_array($_GET['section'], array('news', 'objects'), true)
    ? $_GET['section'] : 'news';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($section === 'news') $action = in_array($action, array('list', 'new', 'edit', 'login'), true) ? $action : 'list';
else                     $action = in_array($action, array('list', 'new', 'edit', 'login'), true) ? $action : 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['do_login'])) {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (login_attempts_blocked()) {
            $error = 'Слишком много неудачных попыток входа. Попробуйте через 15 минут.';
            $action = 'login';
        } elseif (verify_login($user, $pass)) {
            session_regenerate_id(true);
            $_SESSION['authed'] = true;
            $_SESSION['csrf'] = '';
            login_clear_failures();
            $flash = 'Вы вошли в панель управления.';
            $action = 'list';
        } else {
            login_register_failure();
            $error = 'Неверный логин или пароль.';
            $action = 'login';
        }
    }
    elseif (isset($_POST['do_logout'])) {
        $_SESSION = array();
        session_destroy();
        header('Location: ./');
        exit;
    }
}

if ($action !== 'login' && empty($_SESSION['authed'])) {
    $action = 'login';
}

if ($action !== 'login' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['do_login'])) {
    if (!hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
        die('Ошибка безопасности: неверный CSRF-токен. Обновите страницу и попробуйте снова.');
    }

    /* Защита от двойной отправки формы: одноразовый токен form_nonce,
       который генерируется при отрисовке формы и одноразово тратится здесь. */
    if (isset($_POST['do_save']) || isset($_POST['do_save_obj'])) {
        if (!isset($_POST['form_nonce']) || !hash_equals($_SESSION['form_nonce'] ?? '', $_POST['form_nonce'])) {
            $flash = 'Двойная отправка формы отклонена. Обновите страницу и попробуйте снова.';
            $action = 'list';
            $formGuardBlocked = true;
        } else {
            unset($_SESSION['form_nonce']);
        }
    }

    /* ============================ НОВОСТИ ============================ */
    if ($section === 'news') {
        if (isset($_POST['do_delete'])) {
            $id = (int)($_POST['id'] ?? 0);
            $list = load_news();
            $new = array();
            $removed = null;
            foreach ($list as $n) {
                if ((int)$n['id'] === $id) { $removed = $n; }
                else { $new[] = $n; }
            }
            if ($removed !== null) {
                if (save_news($new)) {
                    if (!empty($removed['image'])) {
                        $p = NEWS_IMG_DIR . '/' . basename($removed['image']);
                        if (is_file($p)) @unlink($p);
                    }
                    $exList = isset($removed['images']) && is_array($removed['images']) ? $removed['images'] : array();
                    foreach ($exList as $exFp) {
                        if (is_string($exFp) && $exFp !== '') {
                            $p = NEWS_IMG_DIR . '/' . basename($exFp);
                            if (is_file($p)) @unlink($p);
                        }
                    }
                    $flash = 'Новость «' . $removed['title'] . '» удалена.';
                } else {
                    $error = 'Не удалось сохранить news.json. Проверьте права записи на файл data/news.json.';
                }
            }
            $action = 'list';
        }

        if (!$formGuardBlocked && isset($_POST['do_save'])) {
            $id      = (int)($_POST['id'] ?? 0);
            $date    = trim($_POST['date'] ?? '');
            $title   = trim($_POST['title'] ?? '');
            $text    = trim($_POST['text'] ?? '');
            $link    = trim($_POST['link'] ?? '');
            $linkTxt = trim($_POST['link_text'] ?? '');
            $image   = '';

            if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $error = 'Дата должна быть в формате ГГГГ-ММ-ДД.';
                $action = 'edit';
                $_GET['id'] = $id;
            }

            if ($error === '' && !empty($_FILES['image']['name'])) {
                $image = handle_image_upload($_FILES['image'], NEWS_IMG_DIR, 'news',
                    NEWS_IMG_EXT, NEWS_IMG_MAX_SIZE, $error);
                if ($image !== '') { $image = 'img/news/' . basename($image); }
            }

            /* Дополнительное фото (до 3): полная замена сета при загрузке. */
            $imagesExtra = array();
            if ($error === '' && isset($_FILES['images']) && is_array($_FILES['images'])
                && isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
                $nFiles = count($_FILES['images']['name']);
                $kept = array();
                for ($k = 0; $k < $nFiles; $k++) {
                    $one = array(
                        'name'     => $_FILES['images']['name'][$k],
                        'type'     => $_FILES['images']['type'][$k] ?? '',
                        'tmp_name' => $_FILES['images']['tmp_name'][$k],
                        'error'    => $_FILES['images']['error'][$k],
                        'size'     => $_FILES['images']['size'][$k],
                    );
                    if (empty($one['name'])) continue;
                    $res = handle_image_upload($one, NEWS_IMG_DIR, 'news',
                        NEWS_IMG_EXT, NEWS_IMG_MAX_SIZE, $error);
                    if ($res === '') { $kept = array(); break; }
                    $kept[] = 'img/news/' . basename($res);
                }
                if ($error === '') {
                    if (count($kept) > 3) {
                        $error = 'Максимум 3 дополнительных фото (загружено ' . count($kept) . ').';
                        foreach ($kept as $fp) { $p = NEWS_IMG_DIR . '/' . basename($fp); if (is_file($p)) @unlink($p); }
                        $kept = array();
                    } else {
                        $imagesExtra = $kept;
                    }
                }
            }

            if ($error === '' && ($title === '' || $date === '')) {
                $error = 'Заполните заголовок и дату.';
                $action = 'edit';
                $_GET['id'] = $id;
            }

            if ($error === '') {
                $list = load_news();
                $item = array(
                    'id'        => $id > 0 ? $id : (int)time(),
                    'date'      => $date,
                    'title'     => $title,
                    'text'      => $text,
                    'image'     => $image !== '' ? $image : (isset($_POST['image_keep']) ? $_POST['image_keep'] : ''),
                    'images'    => (!empty($imagesExtra)) ? $imagesExtra : (isset($_POST['images_keep']) ? (array)$_POST['images_keep'] : array()),
                    'link'      => $link,
                    'link_text' => $linkTxt !== '' ? $linkTxt : 'Читать полностью',
                );
                $replaced = false;
                foreach ($list as $i => $n) {
                    if ((int)$n['id'] === $item['id']) {
                        if ($image !== '' && !empty($n['image']) && $n['image'] !== $image) {
                            $p = NEWS_IMG_DIR . '/' . basename($n['image']);
                            if (is_file($p)) @unlink($p);
                        }
                        /* Удаление файлов, выпавших из набора доп. фото. */
                        $oldExtra = isset($n['images']) && is_array($n['images']) ? $n['images'] : array();
                        foreach ($oldExtra as $oldFp) {
                            if (!(is_string($oldFp) && in_array($oldFp, (array)$item['images'], true))) {
                                $p = NEWS_IMG_DIR . '/' . basename($oldFp);
                                if (is_file($p)) @unlink($p);
                            }
                        }
                        $list[$i] = $item;
                        $replaced = true;
                    }
                }
                if (!$replaced) { $list[] = $item; }
                if (save_news($list)) {
                    $flash = $replaced ? 'Новость обновлена.' : 'Новость добавлена.';
                    $action = 'list';
                } else {
                    $error = 'Не удалось сохранить news.json. Проверьте права записи.';
                    $action = 'edit';
                    $_GET['id'] = $id;
                }
            }
        }
    }

    /* ============================ ОБЪЕКТЫ ============================ */
    if ($section === 'objects') {
        if (isset($_POST['do_delete_obj'])) {
            $id = (int)($_POST['id'] ?? 0);
            $list = load_objects();
            $new = array();
            $removed = null;
            foreach ($list as $o) {
                if ((int)$o['id'] === $id) { $removed = $o; }
                else { $new[] = $o; }
            }
            if ($removed !== null) {
                if (save_objects($new)) {
                    if (!empty($removed['image'])) {
                        $p = OBJECTS_IMG_DIR . '/' . basename($removed['image']);
                        if (is_file($p)) @unlink($p);
                    }
                    $flash = 'Объект «' . $removed['title'] . '» удалён.';
                } else {
                    $error = 'Не удалось сохранить objects.json. Проверьте права записи.';
                }
            }
            $action = 'list';
        }

        if (!$formGuardBlocked && isset($_POST['do_save_obj'])) {
            $id      = (int)($_POST['id'] ?? 0);
            $title   = trim($_POST['title'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $text    = trim($_POST['text'] ?? '');
            $area    = trim($_POST['area'] ?? '');
            $term    = trim($_POST['term'] ?? '');
            $workType= trim($_POST['work_type'] ?? '');
            $alt     = trim($_POST['alt'] ?? '');
            $cats    = isset($_POST['categories']) && is_array($_POST['categories'])
                ? array_values(array_intersect(array_map('trim', $_POST['categories']), array_keys(OBJECTS_CATEGORIES)))
                : array();
            $image   = '';

            if ($error === '' && !empty($_FILES['image_obj']['name'])) {
                $image = handle_image_upload($_FILES['image_obj'], OBJECTS_IMG_DIR, 'obj',
                    OBJECTS_IMG_EXT, OBJECTS_IMG_MAX_SIZE, $error);
                if ($image !== '') { $image = 'img_objects/Objects/' . basename($image); }
            }

            if ($error === '') {
                if ($title === '') {
                    $error = 'Укажите название объекта.';
                } elseif (empty($cats)) {
                    $error = 'Выберите хотя бы одну категорию.';
                } else {
                    $list = load_objects();
                    $replaced = false;
                    $existingOrder = null;
                    foreach ($list as $o) {
                        if ((int)$o['id'] === $id && $id > 0) { $existingOrder = isset($o['order']) ? (int)$o['order'] : null; break; }
                    }
                    $item = array(
                        'id'        => $id > 0 ? $id : (int)(max(0, max(array_map(function ($x) { return (int)$x['id']; }, $list))) + 1),
                        'categories'=> $cats,
                        'title'     => $title,
                        'address'   => $address,
                        'text'      => $text,
                        'image'     => $image !== '' ? $image : (isset($_POST['image_keep']) ? $_POST['image_keep'] : ''),
                        'area'      => $area,
                        'term'      => $term,
                        'work_type' => $workType,
                        'alt'       => $alt !== '' ? $alt : $title,
                        'order'     => $existingOrder !== null ? $existingOrder : count($list),
                    );
                    foreach ($list as $i => $o) {
                        if ((int)$o['id'] === $item['id']) {
                            if ($image !== '' && !empty($o['image']) && $o['image'] !== $image) {
                                $p = OBJECTS_IMG_DIR . '/' . basename($o['image']);
                                if (is_file($p)) @unlink($p);
                            }
                            $list[$i] = $item;
                            $replaced = true;
                        }
                    }
                    if (!$replaced) { array_unshift($list, $item); }
                    if (save_objects($list)) {
                        $flash = $replaced ? 'Объект обновлён.' : 'Объект добавлён.';
                        $action = 'list';
                    } else {
                        $error = 'Не удалось сохранить objects.json. Проверьте права записи.';
                        $action = 'edit';
                        $_GET['id'] = $id;
                    }
                }
            }
            if ($error !== '') { $action = 'edit'; $_GET['id'] = $id; }
        }
    }
}

/* ---------- данные для форм ---------- */

$editNews = null;
if ($section === 'news' && ($action === 'edit' || $action === 'new')) {
    $eid = (int)($_GET['id'] ?? 0);
    if ($eid > 0) {
        foreach (load_news() as $n) {
            if ((int)$n['id'] === $eid) { $editNews = $n; break; }
        }
        if (!$editNews) { $error = 'Новость не найдена.'; $action = 'list'; }
    }
    /* Нормализация набора доп. фото (старые записи news.json без ключа). */
    if (is_array($editNews)) {
        $editNews['images'] = isset($editNews['images']) && is_array($editNews['images'])
            ? array_values(array_filter(array_map('strval', $editNews['images'])))
            : array();
    }
    if (!$editNews && $action !== 'list') {
        $editNews = array(
            'id'        => $eid,
            'date'      => !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d'),
            'title'     => $_POST['title'] ?? '',
            'text'      => $_POST['text'] ?? '',
            'image'     => $_POST['image_keep'] ?? '',
            'images'    => isset($_POST['images_keep']) && is_array($_POST['images_keep']) ? array_values(array_filter(array_map('strval', $_POST['images_keep']))) : array(),
            'link'      => $_POST['link'] ?? '',
            'link_text' => $_POST['link_text'] ?? '',
        );
    }
    if ($editNews !== null && isset($_POST['do_save']) && $error !== '') {
        $editNews['date']      = $_POST['date'] ?? $editNews['date'];
        $editNews['title']     = $_POST['title'] ?? $editNews['title'];
        $editNews['text']      = $_POST['text'] ?? $editNews['text'];
        $editNews['link']      = $_POST['link'] ?? $editNews['link'];
        $editNews['link_text'] = $_POST['link_text'] ?? $editNews['link_text'];
        $editNews['image']     = $_POST['image_keep'] ?? $editNews['image'];
        if (isset($_POST['images_keep']) && is_array($_POST['images_keep'])) {
            $editNews['images'] = array_values(array_filter(array_map('strval', $_POST['images_keep'])));
        }
    }
}

$editObj = null;
if ($section === 'objects' && ($action === 'edit' || $action === 'new')) {
    $eid = (int)($_GET['id'] ?? 0);
    if ($eid > 0) {
        foreach (load_objects() as $o) {
            if ((int)$o['id'] === $eid) { $editObj = $o; break; }
        }
        if (!$editObj) { $error = 'Объект не найден.'; $action = 'list'; }
    }
    if (!$editObj && $action !== 'list') {
        $editObj = array(
            'id'        => $eid,
            'categories'=> (isset($_POST['categories']) && is_array($_POST['categories']))
                            ? array_values(array_intersect(array_map('trim', $_POST['categories']), array_keys(OBJECTS_CATEGORIES)))
                            : array(),
            'title'     => $_POST['title'] ?? '',
            'address'   => $_POST['address'] ?? '',
            'text'      => $_POST['text'] ?? '',
            'image'     => $_POST['image_keep'] ?? '',
            'area'      => $_POST['area'] ?? '',
            'term'      => $_POST['term'] ?? '',
            'work_type' => $_POST['work_type'] ?? '',
            'alt'       => $_POST['alt'] ?? '',
        );
    }
}

$list = ($action === 'list')
    ? ($section === 'news' ? load_news() : load_objects())
    : array();

/* ---------- view ---------- */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>Панель управления — СК ПСП</title>
<link rel="icon" type="image/png" sizes="32x32" href="../img/favicon-32x32.png" />
<link rel="icon" type="image/x-icon" href="../img/favicon.ico" />
<style>
  :root { --ink:#1c2733; --muted:#5c6b7a; --border:#dde5ec; --blue:#1f6f9e; --red:#c0392b; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:'Roboto',Arial,sans-serif; color:var(--ink); background:#f4f7f9; }
  .wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px 60px; }
  header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
  header h1 { font-size:20px; margin:0; }
  header h1 small { color:var(--muted); font-weight:400; }
  .btn { display:inline-block; padding:10px 18px; border-radius:6px; border:1px solid var(--blue);
         background:var(--blue); color:#fff; font-weight:600; font-size:14px; cursor:pointer; text-decoration:none;
         line-height:1.3; }
  .btn:hover { background:#175a80; }
  .btn--ghost { background:#fff; color:var(--blue); }
  .btn--ghost:hover { background:#eef5fa; }
  .btn--danger { background:#fff; color:var(--red); border-color:var(--red); }
  .btn--danger:hover { background:#fbeeee; }
  .btn--on { background:#175a80; }
  .tabs { display:flex; gap:4px; margin-bottom:18px; border-bottom:2px solid var(--border); }
  .tabs a { display:inline-block; padding:10px 18px; margin-bottom:-2px; font-size:15px; font-weight:600;
            color:var(--muted); text-decoration:none; border-bottom:2px solid transparent; }
  .tabs a.active { color:var(--blue); border-bottom-color:var(--blue); }
  .card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:24px; margin-bottom:18px;
          box-shadow:0 1px 3px rgba(20,40,60,.06); }
  .flash { background:#e8f6ee; border:1px solid #bfe5cd; color:#1e6b3a; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .errbox { background:#fdecec; border:1px solid #f3c1c1; color:#8c2323; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
  th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
  tr:hover td { background:#f8fafc; }
  .thumb { width:84px; height:56px; object-fit:cover; border-radius:6px; border:1px solid var(--border); display:block; }
  .thumb--none { background:#eef2f6; display:grid; place-items:center; color:#a5b2bf; font-size:11px; width:84px; height:56px;
                 border-radius:6px; border:1px solid var(--border); }
  .noimg { color:var(--muted); font-size:12px; }
  .cat-tag { display:inline-block; background:#eef3f8; color:#33495f; border:1px solid #dbe5ee; border-radius:4px;
             padding:2px 8px; font-size:12px; margin:1px 4px 1px 0; }
  label { display:block; font-size:13px; font-weight:600; color:var(--muted); margin:14px 0 6px; }
  input[type=text], input[type=date], input[type=password], textarea, select {
    width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:6px; font-size:14px; font-family:inherit; }
  input:focus, textarea:focus, select:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(31,111,158,.12); }
  textarea { min-height:120px; resize:vertical; }
  .row { display:flex; gap:16px; flex-wrap:wrap; }
  .row > div { flex:1 1 220px; }
  .hint { font-size:12px; color:var(--muted); margin-top:4px; }
  .login { max-width:380px; margin:60px auto; }
  .actions { white-space:nowrap; }
  .actions form { display:inline; }
  .img-prev { max-width:180px; margin-top:8px; border-radius:6px; border:1px solid var(--border); display:block; }
  .img-prev--sm { max-width:120px; }
  .img-prev-grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
  .empty { color:var(--muted); padding:20px 0; text-align:center; }
  .cats { margin:10px 0 4px; }
  .cats label { display:inline-flex; align-items:center; gap:8px; font-weight:500; color:var(--ink);
                font-size:14px; margin:0 16px 8px 0; }
  .cats input { width:auto; }
  code { background:#eef2f6; padding:1px 5px; border-radius:4px; font-size:12px; }
</style>
<script>
/* Клиентская страховка от двойного нажатия «Сохранить».
   Надёжный бэкенд-guard — одноразовый form_nonce на сервере. */
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (f.tagName === 'FORM' && f.querySelector('input[name="do_save"], input[name="do_save_obj"]')) {
    var b = f.querySelector('button[type="submit"]');
    if (b) { b.disabled = true; b.style.opacity = '.6'; }
  }
}, true);
</script>
</head>
<body>
<div class="wrap">

<?php if ($action === 'login'): ?>
  <div class="card login">
    <h1 style="font-size:20px;margin:0 0 6px;">Панель управления</h1>
    <p style="color:var(--muted);font-size:14px;margin:0 0 16px;">Войдите, чтобы управлять новостями и объектами сайта.</p>
    <?php if ($error): ?><div class="errbox"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post" action="">
      <input type="hidden" name="do_login" value="1" />
      <label>Логин</label>
      <input type="text" name="username" required autofocus autocomplete="username" />
      <label>Пароль</label>
      <input type="password" name="password" required autocomplete="current-password" />
      <div style="margin-top:18px;">
        <button class="btn" type="submit">Войти</button>
      </div>
    </form>
  </div>

<?php else: ?>

  <header>
    <h1>Панель управления <small>— СК ПСП</small></h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <a class="btn btn--ghost" href="?section=<?php echo $section; ?>&action=new">
        + Добавить <?php echo $section === 'news' ? 'новость' : 'объект'; ?>
      </a>
      <form method="post" action="" style="display:inline;">
        <input type="hidden" name="do_logout" value="1" />
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
        <button class="btn btn--ghost" type="submit">Выйти</button>
      </form>
    </div>
  </header>

  <nav class="tabs">
    <a href="?section=news" class="<?php echo $section === 'news' ? 'active' : ''; ?>">Новости</a>
    <a href="?section=objects" class="<?php echo $section === 'objects' ? 'active' : ''; ?>">Объекты</a>
    <a href="../" target="_blank" style="margin-left:auto;">Сайт →</a>
  </nav>

  <?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="errbox"><?php echo e($error); ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<?php if ($section === 'news'): ?>
  <div class="card">
    <?php if (empty($list)): ?>
      <div class="empty">Пока нет новостей. <a href="?section=news&action=new">Добавить первую</a>.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th style="width:90px;">Фото</th><th style="width:110px;">Дата</th><th>Заголовок</th><th style="width:150px;">Действия</th></tr>
      </thead>
      <tbody>
      <?php foreach ($list as $n): ?>
        <tr>
          <td>
            <?php if (!empty($n['image'])): ?>
              <img class="thumb" src="../<?php echo e($n['image']); ?>" alt="" />
            <?php else: ?>
              <span class="thumb--none">нет фото</span>
            <?php endif; ?>
          </td>
          <td><?php echo e(fmt_date($n['date'])); ?></td>
          <td>
            <b><?php echo e($n['title']); ?></b>
            <?php if (!empty($n['text'])): ?>
              <div class="noimg"><?php echo e(mb_strimwidth($n['text'], 0, 120, '…', 'UTF-8')); ?></div>
            <?php endif; ?>
          </td>
          <td class="actions">
            <a class="btn btn--ghost" style="padding:6px 12px;font-size:13px;" href="?section=news&action=edit&id=<?php echo (int)$n['id']; ?>">Изменить</a>
            <form method="post" action="" onsubmit="return confirm('Удалить новость «<?php echo e(addslashes($n['title'])); ?>»? Картинка тоже будет удалена.');">
              <input type="hidden" name="do_delete" value="1" />
              <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>" />
              <button class="btn btn--danger" style="padding:6px 12px;font-size:13px;" type="submit">Удалить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

<?php else: /* objects list */ ?>
  <div class="card">
    <?php if (empty($list)): ?>
      <div class="empty">Пока нет объектов. <a href="?section=objects&action=new">Добавить первый</a>.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th style="width:90px;">Фото</th><th>Объект</th><th style="width:180px;">Категории</th><th style="width:150px;">Действия</th></tr>
      </thead>
      <tbody>
      <?php foreach ($list as $o): ?>
        <tr>
          <td>
            <?php if (!empty($o['image'])): ?>
              <img class="thumb" src="../<?php echo e($o['image']); ?>" alt="" />
            <?php else: ?>
              <span class="thumb--none">нет фото</span>
            <?php endif; ?>
          </td>
          <td>
            <b><?php echo e($o['title']); ?></b>
            <?php if (!empty($o['address'])): ?>
              <div class="noimg"><?php echo e($o['address']); ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php foreach ((array)($o['categories'] ?? array()) as $c): ?>
              <?php if (isset(OBJECTS_CATEGORIES[$c])): ?>
                <span class="cat-tag"><?php echo e(OBJECTS_CATEGORIES[$c]); ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>
          <td class="actions">
            <a class="btn btn--ghost" style="padding:6px 12px;font-size:13px;" href="?section=objects&action=edit&id=<?php echo (int)$o['id']; ?>">Изменить</a>
            <form method="post" action="" onsubmit="return confirm('Удалить объект «<?php echo e(addslashes($o['title'])); ?>»? Картинка тоже будет удалена.');">
              <input type="hidden" name="do_delete_obj" value="1" />
              <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
              <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>" />
              <button class="btn btn--danger" style="padding:6px 12px;font-size:13px;" type="submit">Удалить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php else: /* form: new / edit */ ?>

<?php if ($section === 'news' && $editNews !== null): ?>
  <div class="card">
    <h2 style="margin:0 0 4px;font-size:18px;"><?php echo $editNews['id'] ? 'Редактирование новости' : 'Новая новость'; ?></h2>
    <form method="post" action="" enctype="multipart/form-data">
      <input type="hidden" name="do_save" value="1" />
      <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
      <input type="hidden" name="form_nonce" value="<?php echo e(issue_form_nonce()); ?>" />
      <input type="hidden" name="id" value="<?php echo (int)$editNews['id']; ?>" />
      <?php if (!empty($editNews['image'])): ?>
        <input type="hidden" name="image_keep" value="<?php echo e($editNews['image']); ?>" />
      <?php endif; ?>

      <div class="row">
        <div>
          <label>Дата</label>
          <input type="date" name="date" value="<?php echo e($editNews['date']); ?>" required />
        </div>
        <div>
          <label>Ссылка «Читать полностью» (пусто = открыть полную статью новости)</label>
          <input type="text" name="link" value="<?php echo e($editNews['link']); ?>" placeholder="objects" />
          <div class="hint">Ссылка на страницу сайта: objects, about и т.п. (без расширения). Если оставить пустым — «Читать полностью» откроет полную статью этой новости.</div>
        </div>
      </div>

      <label>Заголовок</label>
      <input type="text" name="title" value="<?php echo e($editNews['title']); ?>" required placeholder="Сдан объект…" />

      <label>Текст</label>
      <textarea name="text" placeholder="Краткий текст новости…"><?php echo e($editNews['text']); ?></textarea>

      <label>Картинка (jpg, png, webp, gif — до 2 МБ)</label>
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif" />
      <?php if (!empty($editNews['image'])): ?>
        <img class="img-prev" src="../<?php echo e($editNews['image']); ?>" alt="Текущая картинка" />
        <div class="hint">Текущая картинка. Загрузите новую, чтобы заменить.</div>
      <?php endif; ?>

      <label>Дополнительные фото — до 3 (jpg, png, webp, gif, каждое до 2 МБ)</label>
      <input type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,.gif" multiple />
      <?php if (!empty($editNews['images'])): ?>
        <div class="img-prev-grid">
          <?php foreach ($editNews['images'] as $ex): ?>
            <img class="img-prev img-prev--sm" src="../<?php echo e($ex); ?>" alt="Дополнительное фото" />
            <input type="hidden" name="images_keep[]" value="<?php echo e($ex); ?>" />
          <?php endforeach; ?>
        </div>
        <div class="hint">Выберите новые файлы, чтобы заменить все доп. фото целиком.</div>
      <?php endif; ?>

      <label>Текст ссылки (необязательно)</label>
      <input type="text" name="link_text" value="<?php echo e($editNews['link_text']); ?>" placeholder="Читать полностью" />

      <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn" type="submit">Сохранить</button>
        <a class="btn btn--ghost" href="?section=news">Отмена</a>
      </div>
    </form>
  </div>

<?php else: /* objects form */ ?>
  <div class="card">
    <h2 style="margin:0 0 4px;font-size:18px;"><?php echo $editObj['id'] ? 'Редактирование объекта' : 'Новый объект'; ?></h2>
    <form method="post" action="" enctype="multipart/form-data">
      <input type="hidden" name="do_save_obj" value="1" />
      <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
      <input type="hidden" name="form_nonce" value="<?php echo e(issue_form_nonce()); ?>" />
      <input type="hidden" name="id" value="<?php echo (int)$editObj['id']; ?>" />
      <?php if (!empty($editObj['image'])): ?>
        <input type="hidden" name="image_keep" value="<?php echo e($editObj['image']); ?>" />
      <?php endif; ?>

      <label>Название объекта *</label>
      <input type="text" name="title" value="<?php echo e($editObj['title']); ?>" required placeholder="Например: Business-центр «Северное сияние»" />

      <label>Адрес</label>
      <input type="text" name="address" value="<?php echo e($editObj['address']); ?>" placeholder="Санкт-Петербург, ул. …" />

      <div class="cats">
        <span style="display:block;font-size:13px;font-weight:600;color:var(--muted);margin-bottom:8px;">Категории * <span style="font-weight:400;">(можно несколько)</span></span>
        <?php foreach (OBJECTS_CATEGORIES as $slug => $name): ?>
          <?php $checked = in_array($slug, (array)($editObj['categories'] ?? array()), true); ?>
          <label><input type="checkbox" name="categories[]" value="<?php echo e($slug); ?>" <?php echo $checked ? 'checked' : ''; ?> /> <?php echo e($name); ?></label>
        <?php endforeach; ?>
      </div>

      <label>Описание</label>
      <textarea name="text" placeholder="Краткое описание выполненного объёма работ…"><?php echo e($editObj['text']); ?></textarea>

      <div class="row">
        <div><label>Площадь</label><input type="text" name="area" value="<?php echo e($editObj['area']); ?>" placeholder="12 500 м²" /></div>
        <div><label>Сроки</label><input type="text" name="term" value="<?php echo e($editObj['term']); ?>" placeholder="2015–2017" /></div>
        <div><label>Тип работ</label><input type="text" name="work_type" value="<?php echo e($editObj['work_type']); ?>" placeholder="Новое строительство / Реконструкция" /></div>
      </div>

      <label>Картинка (jpg, png, webp, gif — до 4 МБ)</label>
      <input type="file" name="image_obj" accept=".jpg,.jpeg,.png,.webp,.gif" />
      <?php if (!empty($editObj['image'])): ?>
        <img class="img-prev" src="../<?php echo e($editObj['image']); ?>" alt="Текущая картинка" />
        <div class="hint">Текущая картинка. Загрузите новую, чтобы заменить.</div>
      <?php endif; ?>

      <label>Alt-текст картинки (необязательно)</label>
      <input type="text" name="alt" value="<?php echo e($editObj['alt']); ?>" placeholder="Описание для доступности" />

      <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn" type="submit">Сохранить</button>
        <a class="btn btn--ghost" href="?section=objects">Отмена</a>
      </div>
    </form>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
