<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function admin_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
}

function admin_create(string $username, string $password): void
{
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    db()->prepare('INSERT INTO admins(username, pass_hash, created_at) VALUES(?,?,?)')
        ->execute([$username, $hash, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')]);
}

function admin_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if (!$admin || !password_verify($password, (string) $admin['pass_hash'])) {
        return false;
    }
    if (password_needs_rehash((string) $admin['pass_hash'], PASSWORD_ARGON2ID)) {
        db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), $admin['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = (string) $admin['username'];
    db()->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')
        ->execute([(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'), $admin['id']]);
    return true;
}

function admin_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_name']);
    session_regenerate_id(true);
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return ['id' => (int) $_SESSION['admin_id'], 'name' => (string) ($_SESSION['admin_name'] ?? 'admin')];
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect(url('admin.php', ['r' => 'login']));
    }
    return $admin;
}
