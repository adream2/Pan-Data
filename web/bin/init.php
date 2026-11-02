<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/auth.php';

$tables = schema_migrate();
fwrite(STDOUT, "建表完成：" . implode(', ', $tables) . PHP_EOL);

$defaults = [
    'site_name'   => (string) cfg('site_name'),
    'site_desc'   => (string) cfg('site_slogan'),
    'notice'      => '',
    'submit_open' => '1',
];
foreach ($defaults as $k => $v) {
    if (setting($k) === null) {
        set_setting($k, $v);
    }
}
fwrite(STDOUT, "默认设置写入完成" . PHP_EOL);

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? bin2hex(random_bytes(6));

if (admin_count() === 0) {
    admin_create($username, $password);
    fwrite(STDOUT, "已创建管理员：{$username} / {$password}" . PHP_EOL);
    fwrite(STDOUT, "请登录后台后立刻修改密码。" . PHP_EOL);
} else {
    fwrite(STDOUT, "管理员已存在，跳过创建（如需重置：php bin/reset-password.php <用户名> <新密码>）" . PHP_EOL);
}

fwrite(STDOUT, "数据库：" . cfg('db_path') . PHP_EOL);
