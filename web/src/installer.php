<?php
declare(strict_types=1);

/**
 * 安装向导的业务逻辑：环境自检、建库建号、分批导数。
 *
 * 目的很直接：部署这套站不该需要 SSH。
 * 上传解压 → 打开浏览器 → 点几下 → 站点可用。
 * 命令行（bin/init.php、bin/sync.php）保留，给无 web 权限或想写脚本的场景用。
 */

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/auth.php';

/** 已安装的判定：有管理员，或写完过 installed_at 标记 */
function installer_installed(): bool
{
    try {
        if (admin_count() > 0) {
            return true;
        }
        return (string) setting('installed_at') !== '';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 环境自检。level=error 的项不满足就不该继续。
 * @return array<int,array{name:string,ok:bool,value:string,need:string,level:string,note:string}>
 */
function installer_env_checks(): array
{
    $checks = [];

    $checks[] = [
        'name' => 'PHP 版本',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'value' => PHP_VERSION,
        'need' => '≥ 8.1',
        'level' => 'error',
        'note' => '',
    ];

    $exts = [
        'pdo_sqlite' => '数据库（SQLite）',
        'curl'       => '从 GitHub 拉数据',
        'mbstring'   => '中文标题处理',
        'json'       => 'JSON 解析',
    ];
    foreach ($exts as $ext => $label) {
        $has = extension_loaded($ext);
        $checks[] = [
            'name' => $label . ' · ' . $ext,
            'ok' => $has,
            'value' => $has ? '已安装' : '缺失',
            'need' => '必须',
            'level' => 'error',
            'note' => $has ? '' : '装 php-' . str_replace('_', '-', $ext) . ' 后重启 PHP-FPM',
        ];
    }

    // storage 必须可写：SQLite、限流文件、同步状态都放这里
    $dir = APP_ROOT . '/storage';
    @mkdir($dir, 0775, true);
    $probe = $dir . '/.install_probe';
    $writable = @file_put_contents($probe, 'ok') !== false;
    @unlink($probe);
    $checks[] = [
        'name' => 'storage 目录可写',
        'ok' => $writable,
        'value' => $writable ? '可写' : '不可写',
        'need' => '必须',
        'level' => 'error',
        'note' => $writable ? '' : 'chown -R www-data:www-data storage && chmod -R 775 storage',
    ];

    // FTS5 只影响搜索速度，没有也能跑（自动降级 LIKE），所以是 warn 不是 error
    $fts = false;
    try {
        $probeDb = new PDO('sqlite::memory:');
        $probeDb->exec('CREATE VIRTUAL TABLE t USING fts5(x)');
        $fts = true;
        $probeDb = null;
    } catch (Throwable $e) {
        $fts = false;
    }
    $checks[] = [
        'name' => 'SQLite FTS5 全文索引',
        'ok' => $fts,
        'value' => $fts ? '支持' : '不支持',
        'need' => '推荐',
        'level' => 'warn',
        'note' => $fts ? '' : '没有也能用，搜索会降级成 LIKE 模糊匹配（大库下变慢）',
    ];

    $css = APP_ROOT . '/public/assets/app.css';
    $checks[] = [
        'name' => '前端样式文件',
        'ok' => is_file($css),
        'value' => is_file($css) ? '存在' : '缺失',
        'need' => '必须',
        'level' => 'error',
        'note' => is_file($css) ? '' : '包不完整，重新解压',
    ];

    // 装完就该关掉安装入口，提醒一下
    if (installer_installed()) {
        $checks[] = [
            'name' => '重复安装保护',
            'ok' => false,
            'value' => '站点已安装',
            'need' => '—',
            'level' => 'warn',
            'note' => '要重装请先删除 storage/app.sqlite 或清空 admins 表',
        ];
    }

    return $checks;
}

/** 环境是否足够继续（只卡 error 级） */
function installer_env_ok(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['level'] === 'error' && !$c['ok']) {
            return false;
        }
    }
    return true;
}

/**
 * 建库 + 写设置 + 建管理员 + 自动登录。
 * @return array<int,string> 错误信息，空数组表示成功
 */
function installer_finish(string $siteName, string $siteUrl, string $username, string $password,
                          string $proxy = '', string $token = ''): array
{
    $errors = [];
    $siteName = trim($siteName) ?: '盘搜';
    $username = trim($username) ?: 'admin';
    $siteUrl = rtrim(trim($siteUrl), '/');

    if (mb_strlen($username) < 2) {
        $errors[] = '管理员用户名至少 2 个字符';
    }
    if (strlen($password) < 8) {
        $errors[] = '密码至少 8 位';
    }
    if ($siteUrl !== '' && !preg_match('#^https?://[^/]+#i', $siteUrl)) {
        $errors[] = '站点地址要带 http:// 或 https://，且不带结尾斜杠';
    }
    if ($proxy !== '' && !preg_match('#^(https?|socks5h?)://#i', $proxy)) {
        $errors[] = '代理地址要带协议，例如 http://127.0.0.1:7890';
    }
    if ($errors !== []) {
        return $errors;
    }

    schema_migrate();
    schema_upgrade();

    set_setting('site_name', mb_substr($siteName, 0, 40));
    // 境内服务器基本连不上 api.github.com，拉数据的代理/Token 必须在向导里就能填，
    // 否则装完第一步导入就卡死，用户还得 SSH 去改配置文件
    if ($proxy !== '') {
        set_setting('repo_proxy', mb_substr($proxy, 0, 120));
    }
    if ($token !== '') {
        set_setting('repo_token', mb_substr($token, 0, 200));
    }
    if ($siteUrl !== '') {
        set_setting('site_url', $siteUrl);
    }
    if (setting('site_desc') === null) {
        set_setting('site_desc', (string) cfg('site_slogan'));
    }
    if (setting('submit_open') === null) {
        set_setting('submit_open', '1');
    }

    $stmt = db()->prepare('SELECT id FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $existing = $stmt->fetchColumn();

    if ($existing !== false) {
        db()->prepare('UPDATE admins SET pass_hash = ? WHERE username = ?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), $username]);
        $adminId = (int) $existing;
    } else {
        admin_create($username, $password);
        $adminId = (int) db()->query('SELECT id FROM admins WHERE username = ' . db()->quote($username))->fetchColumn();
    }

    set_setting('installed_at', now_iso());

    // 装完直接登录，用户点「下一步」就能进后台导数据，不用再输一次密码
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_name'] = $username;

    return [];
}

/**
 * 导数计划：热文件 + 每个历史分片，各占一步。
 * 分成多步是为了让单个请求能在几秒到几十秒内返回，
 * 不然一次拉 24MB 必然撞上 PHP / Nginx 的超时。
 *
 * @return array<int,array{kind:string,id:string,label:string,count:int}>
 */
function installer_plan(bool $refresh = false): array
{
    $cacheFile = APP_ROOT . '/storage/install_plan.json';
    if (!$refresh && is_file($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['plan'] ?? []) !== []) {
            return $cached['plan'];
        }
    }

    $index = repo_index();
    if ($index === null) {
        throw new RuntimeException('连不上数据仓库：' . (repo_last_error() ?: '原因未知'));
    }

    $plan = [[
        'kind'  => 'hot',
        'id'    => '',
        'label' => '最近 90 天热数据',
        'count' => (int) ($index['hot']['resources'] ?? 0),
    ]];
    foreach ((array) ($index['shards'] ?? []) as $s) {
        $id = (string) ($s['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $plan[] = [
            'kind'  => 'shard',
            'id'    => $id,
            'label' => '历史分片 ' . $id,
            'count' => (int) ($s['resources'] ?? 0),
        ];
    }

    @file_put_contents($cacheFile, json_encode(['plan' => $plan], JSON_UNESCAPED_UNICODE));
    return $plan;
}

/**
 * 执行一步导入。幂等：重复调用同一步不会产生重复数据。
 *
 * @return array{ok:bool,step:int,total:int,label:string,insert:int,update:int,same:int,done:bool,error:string}
 */
function installer_import_step(int $step): array
{
    $out = ['ok' => false, 'step' => $step, 'total' => 0, 'label' => '',
        'insert' => 0, 'update' => 0, 'same' => 0, 'done' => false, 'error' => ''];

    // 一个分片最多九千多条，默认 30 秒的 max_execution_time 不够
    @set_time_limit(0);
    @ignore_user_abort(true);

    try {
        $plan = installer_plan();
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }

    $out['total'] = count($plan);
    if ($step < 0 || $step >= $out['total']) {
        $out['ok'] = true;
        $out['done'] = true;
        return $out;
    }

    $item = $plan[$step];
    $out['label'] = (string) $item['label'];

    try {
        $file = $item['kind'] === 'hot' ? 'resources.json' : 'shards/' . $item['id'] . '.json';
        $payload = repo_fetch_json($file);
        if ($payload === null) {
            throw new RuntimeException('下载失败（' . $file . '）：' . (repo_last_error() ?: '原因未知'));
        }
        // 快速通道：不比对、不写 FTS，全部导完再统一建索引
        $stat = repo_import_payload($payload, 'install', true);
        $out['insert'] = $stat['insert'];
        $out['update'] = $stat['update'];
        $out['same'] = $stat['same'];
        $out['ok'] = true;
        $out['done'] = ($step + 1) >= $out['total'];
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }

    if ($out['done']) {
        // 快速通道跳过了逐条 FTS 写入，这里一次建完（8000 条约 0.8 秒）
        $out['fts'] = repo_fts_rebuild();
        @unlink(APP_ROOT . '/storage/install_plan.json');
        set_setting('imported_at', now_iso());
    }
    return $out;
}
