<?php
declare(strict_types=1);

/**
 * 重置（或创建）站长账号。
 *
 * 用法：
 *   php bin/reset-password.php                 # 重置 admin，随机生成新密码并打印
 *   php bin/reset-password.php admin '新密码'   # 指定用户名与密码
 *
 * 为什么单独一个脚本：bin/init.php 只在「一个管理员都没有」时创建账号，
 * 忘了密码再跑 init.php 会被跳过。这个脚本是幂等的：账号不存在就建，存在就改密码。
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/auth.php';

schema_migrate();

$username = trim((string) ($argv[1] ?? 'admin'));
$password = (string) ($argv[2] ?? '');

if ($username === '') {
    fwrite(STDERR, '用户名不能为空' . PHP_EOL);
    exit(1);
}
if ($password === '') {
    $password = bin2hex(random_bytes(6));
}
if (strlen($password) < 8) {
    fwrite(STDERR, '密码至少 8 位' . PHP_EOL);
    exit(1);
}

$stmt = db()->prepare('SELECT id FROM admins WHERE username = ?');
$stmt->execute([$username]);
$exists = $stmt->fetchColumn() !== false;

if ($exists) {
    db()->prepare('UPDATE admins SET pass_hash = ? WHERE username = ?')
        ->execute([password_hash($password, PASSWORD_ARGON2ID), $username]);
    fwrite(STDOUT, "已重置管理员密码：{$username}" . PHP_EOL);
} else {
    admin_create($username, $password);
    fwrite(STDOUT, "已创建管理员：{$username}" . PHP_EOL);
}

fwrite(STDOUT, "密码：{$password}" . PHP_EOL);
fwrite(STDOUT, '登录地址：/admin.php' . PHP_EOL);
