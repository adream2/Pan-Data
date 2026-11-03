<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}
date_default_timezone_set((string) ($config['timezone'] ?? 'Asia/Shanghai'));

function cfg(string $key, mixed $default = null): mixed
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = (string) cfg('db_path');
    @mkdir(dirname($path), 0775, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function e(mixed $s): string
{
    // 放宽到 mixed：模板里常直接塞 int / null（如 id、(int) 计数），
    // 严格 string 会让整页 500，得不偿失
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '', array $query = []): string
{
    $u = '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals((string) ($_SESSION['_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('CSRF 校验失败，请刷新后重试');
    }
}

function setting(string $key, ?string $default = null): ?string
{
    // 用全局而非函数内 static：set_setting 需要能让缓存失效
    if (!isset($GLOBALS['__settings_cache']) || !is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];
        foreach (db()->query('SELECT k, v FROM settings') as $row) {
            $GLOBALS['__settings_cache'][$row['k']] = $row['v'];
        }
    }
    return $GLOBALS['__settings_cache'][$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(k, v) VALUES(:k, :v)
        ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute([':k' => $key, ':v' => $value]);
    // 保存后立即生效：否则同一进程内后续读取还是旧值
    $GLOBALS['__settings_cache'][$key] = $value;
}

function render_view(string $name, array $data = []): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/src/views/' . $name . '.php';
    return (string) ob_get_clean();
}

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require APP_ROOT . '/src/views/' . $name . '.php';
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('content-type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function http_fetch(string $url, int $timeout = 30, string $proxy = ''): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
    ]);
    if ($proxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || $code >= 400) {
        return null;
    }
    return $body;
}

function http_post_json(string $url, array $payload, string $token, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'accept: application/vnd.github+json',
            'authorization: Bearer ' . $token,
            'content-type: application/json',
            'user-agent: pansou-php',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

function rate_limit(string $bucket, int $max, int $seconds): bool
{
    $dir = APP_ROOT . '/storage/ratelimit';
    @mkdir($dir, 0775, true);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    $file = $dir . '/' . $bucket . '_' . md5($ip);
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $raw = json_decode((string) file_get_contents($file), true);
        $hits = array_values(array_filter((array) $raw, fn($t) => is_int($t) && $t > $now - $seconds));
    }
    if (count($hits) >= $max) {
        return false;
    }
    $hits[] = $now;
    file_put_contents($file, json_encode($hits));
    return true;
}

function owner_token(): string
{
    if (empty($_COOKIE['po_owner'])) {
        $token = bin2hex(random_bytes(8));
        setcookie('po_owner', $token, ['expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['po_owner'] = $token;
    }
    return (string) $_COOKIE['po_owner'];
}
