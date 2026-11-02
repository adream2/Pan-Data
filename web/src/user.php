<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';

/** 用户中心：注册/登录/收藏/举报/API Key */

function user_current(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $user = false;
    if ($user === false) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([(int) $_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
        if ($user === null) {
            unset($_SESSION['uid']);
        }
    }
    return $user ?: null;
}

function user_register(string $username, string $password, string $email = ''): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $username)) {
        return [false, '用户名 3-20 位，仅限字母数字下划线或中文'];
    }
    if (strlen($password) < 6) {
        return [false, '密码至少 6 位'];
    }
    $exists = db()->prepare('SELECT id FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn() !== false) {
        return [false, '用户名已被占用'];
    }
    db()->prepare('INSERT INTO users(username, email, pass_hash, created_at) VALUES(?,?,?,?)')
        ->execute([$username, trim($email), password_hash($password, PASSWORD_DEFAULT), now_iso()]);
    $_SESSION['uid'] = (int) db()->lastInsertId();
    return [true, '注册成功'];
}

function user_login(string $username, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string) $user['pass_hash'])) {
        return [false, '用户名或密码错误'];
    }
    if ($user['status'] !== 'active') {
        return [false, '账号已被禁用'];
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_iso(), $user['id']]);
    return [true, '登录成功'];
}

function user_logout(): void
{
    unset($_SESSION['uid']);
    session_regenerate_id(true);
}

function user_favorite(int $userId, string $resourceId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND resource_id = ?');
    $stmt->execute([$userId, $resourceId]);
    if ($stmt->fetchColumn()) {
        db()->prepare('DELETE FROM favorites WHERE user_id = ? AND resource_id = ?')->execute([$userId, $resourceId]);
        return false;
    }
    db()->prepare('INSERT INTO favorites(user_id, resource_id, created_at) VALUES(?,?,?)')
        ->execute([$userId, $resourceId, now_iso()]);
    return true;
}

function user_is_favorite(int $userId, string $resourceId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND resource_id = ?');
    $stmt->execute([$userId, $resourceId]);
    return $stmt->fetchColumn() !== false;
}

function user_favorites(int $userId, int $limit = 50): array
{
    $stmt = db()->prepare('SELECT r.* FROM favorites f JOIN resources r ON r.id = f.resource_id
        WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT ' . (int) $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function report_create(string $resourceId, string $kind, string $note, ?int $userId): void
{
    db()->prepare('INSERT INTO reports(resource_id, kind, note, user_id, ip, created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$resourceId, $kind, mb_substr($note, 0, 200), $userId,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''), now_iso()]);
}

function api_key_for(int $userId): string
{
    $stmt = db()->prepare('SELECT api_key, api_expires_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    $key = (string) ($row['api_key'] ?? '');
    if ($key !== '' && !empty($row['api_expires_at']) && strtotime((string) $row['api_expires_at']) > time()) {
        return $key;
    }
    $key = 'pk_' . bin2hex(random_bytes(16));
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * 90);
    db()->prepare('UPDATE users SET api_key = ?, api_expires_at = ? WHERE id = ?')->execute([$key, $expires, $userId]);
    return $key;
}

/** 按 API Key 找到用户（校验有效期） */
function api_user_by_key(string $key): ?array
{
    if ($key === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE api_key = ? AND status = 'active'");
    $stmt->execute([$key]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    if (!empty($user['api_expires_at']) && strtotime((string) $user['api_expires_at']) < time()) {
        return null;
    }
    return $user;
}

function click_track(string $resourceId): void
{
    $day = gmdate('Y-m-d');
    db()->prepare('INSERT INTO clicks(resource_id, day, count) VALUES(?,?,1)
        ON CONFLICT(resource_id, day) DO UPDATE SET count = count + 1')->execute([$resourceId, $day]);
}

function click_count(string $resourceId): int
{
    $stmt = db()->prepare('SELECT SUM(count) FROM clicks WHERE resource_id = ?');
    $stmt->execute([$resourceId]);
    return (int) $stmt->fetchColumn();
}

function search_track(string $q): void
{
    $q = mb_substr(trim($q), 0, 40);
    if ($q === '') {
        return;
    }
    $day = gmdate('Y-m-d');
    db()->prepare('INSERT INTO search_logs(q, day, count) VALUES(?,?,1)
        ON CONFLICT(q, day) DO UPDATE SET count = count + 1')->execute([$q, $day]);
}

function top_searches(int $days = 7, int $limit = 10): array
{
    $since = gmdate('Y-m-d', time() - 86400 * $days);
    $stmt = db()->prepare('SELECT q, SUM(count) c FROM search_logs WHERE day >= ? GROUP BY q ORDER BY c DESC LIMIT ' . (int) $limit);
    $stmt->execute([$since]);
    return $stmt->fetchAll();
}

function top_resources(int $days = 7, int $limit = 10): array
{
    $since = gmdate('Y-m-d', time() - 86400 * $days);
    $stmt = db()->prepare('SELECT c.resource_id, SUM(c.count) hits, r.title
        FROM clicks c LEFT JOIN resources r ON r.id = c.resource_id
        WHERE c.day >= ? GROUP BY c.resource_id ORDER BY hits DESC LIMIT ' . (int) $limit);
    $stmt->execute([$since]);
    return $stmt->fetchAll();
}
