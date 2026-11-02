<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/moderation.php';

session_name((string) cfg('security.session_name', 'pansou_sid'));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

if (!schema_ready()) {
    schema_migrate();
    if (admin_count() === 0) {
        admin_create('admin', bin2hex(random_bytes(4)));
    }
}

$route = (string) ($_GET['r'] ?? 'dashboard');
$action = (string) ($_POST['action'] ?? '');

/** ---------------- 动作 ---------------- */
if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($route === 'login') {
        if (!rate_limit('login', (int) cfg('security.login_max_try', 8), 900)) {
            flash('尝试次数过多，15 分钟后再试', 'err');
        } elseif (admin_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
            redirect('/admin.php');
        } else {
            flash('用户名或密码错误', 'err');
        }
        redirect('/admin.php?r=login');
    }

    csrf_check();
    require_admin();

    switch ($action) {
        case 'logout':
            admin_logout();
            redirect('/admin.php?r=login');

        case 'sync_hot':
            try {
                $stat = repo_import_hot();
                flash("同步完成：新增 {$stat['insert']}，更新 {$stat['update']}，跳过 {$stat['skip']}");
            } catch (Throwable $e) {
                flash('同步失败：' . $e->getMessage(), 'err');
            }
            redirect('/admin.php?r=sync');

        case 'sync_shard':
            try {
                $stat = repo_import_shard(trim((string) ($_POST['shard'] ?? '')));
                flash("分片导入完成：新增 {$stat['insert']}，更新 {$stat['update']}");
            } catch (Throwable $e) {
                flash('分片导入失败：' . $e->getMessage(), 'err');
            }
            redirect('/admin.php?r=sync');

        case 'crawl':
            $channels = array_filter(array_map('trim', explode(',', (string) ($_POST['channels'] ?? ''))));
            @set_time_limit(300);
            $i = 0;
            $sum = 0;
            foreach ($channels as $ch) {
                if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $ch)) {
                    continue;
                }
                $stat = crawler_ingest($ch, (int) ($_POST['pages'] ?? 1));
                $sum += $stat['insert'];
                $i++;
            }
            flash("采集完成：{$i} 个频道，新增 {$sum} 条");
            redirect('/admin.php?r=sync');

        case 'trigger':
            $wf = (string) ($_POST['workflow'] ?? '');
            $token = (string) cfg('github.token', '');
            $repo = (string) cfg('github.repo', '');
            $file = (array) cfg('github.workflows', [])[$wf] ?? null;
            if ($token === '' || $repo === '' || $file === null) {
                flash('未配置 GitHub token / 仓库', 'err');
            } else {
                $resp = http_post_json(
                    "https://api.github.com/repos/{$repo}/actions/workflows/{$file}/dispatches",
                    ['ref' => 'main'],
                    $token
                );
                flash($resp['code'] === 204 ? "已触发 {$wf}，几分钟后数据会更新" : '触发失败：HTTP ' . $resp['code'], $resp['code'] === 204 ? 'ok' : 'err');
            }
            redirect('/admin.php?r=sync');

        case 'review':
            $id = (int) ($_POST['id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? 'reject');
            $stmt = db()->prepare('SELECT * FROM submissions WHERE id = ? AND status = ?');
            $stmt->execute([$id, 'pending']);
            $sub = $stmt->fetch();
            if (!$sub) {
                flash('投稿不存在或已处理', 'err');
                redirect('/admin.php?r=subs');
            }
            if ($decision === 'approve') {
                $provider = mod_provider_of((string) $sub['share_url']) ?? 'unknown';
                $rid = 's' . substr(sha1((string) $sub['share_url']), 0, 15);
                repo_upsert([
                    'id'         => $rid,
                    'title'      => (string) $sub['title'],
                    'core_title' => (string) $sub['title'],
                    'type'       => 'other',
                    'provider'   => $provider,
                    'share_url'  => (string) $sub['share_url'],
                    'pwd'        => $sub['pwd'],
                    'status'     => 'unchecked',
                    'channel'    => 'submission',
                    'desc'       => (string) ($sub['note'] ?? ''),
                    'posted_at'  => now_iso(),
                ], 'submit');
                db()->prepare('UPDATE submissions SET status = ?, reviewed_at = ?, resource_id = ? WHERE id = ?')
                    ->execute(['approved', now_iso(), $rid, $id]);
                flash('已通过并入库');
            } else {
                db()->prepare('UPDATE submissions SET status = ?, reviewed_at = ?, reject_reason = ? WHERE id = ?')
                    ->execute(['rejected', now_iso(), trim((string) ($_POST['reason'] ?? '')), $id]);
                flash('已拒绝');
            }
            redirect('/admin.php?r=subs');

        case 'toggle':
            $id = (string) ($_POST['id'] ?? '');
            $field = (string) ($_POST['field'] ?? '');
            if (in_array($field, ['hidden', 'featured'], true) && $id !== '') {
                db()->prepare("UPDATE resources SET {$field} = 1 - {$field}, updated_at = ? WHERE id = ?")
                    ->execute([now_iso(), $id]);
                flash('已更新');
            }
            redirect('/admin.php?r=res&q=' . urlencode((string) ($_POST['q'] ?? '')));

        case 'settings':
            foreach (['site_name', 'site_desc', 'notice', 'submit_open'] as $k) {
                if (isset($_POST[$k])) {
                    set_setting($k, trim((string) $_POST[$k]));
                }
            }
            flash('设置已保存');
            redirect('/admin.php?r=settings');

        case 'password':
            $new = (string) ($_POST['new_password'] ?? '');
            if (strlen($new) < 8) {
                flash('新密码至少 8 位', 'err');
            } else {
                $admin = require_admin();
                db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_ARGON2ID), $admin['id']]);
                flash('密码已更新');
            }
            redirect('/admin.php?r=settings');
    }
}

/** ---------------- 页面 ---------------- */
if ($route === 'login') {
    if (current_admin() !== null) {
        redirect('/admin.php');
    }
    $f = flash();
    $loginFlash = $f ? '<div class="flash ' . e($f['type']) . '">' . e($f['msg']) . '</div>' : '';
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>后台登录</title><link rel="stylesheet" href="/assets/app.css"></head><body class="login-body">
<div class="login-box">
  <h1>后台登录</h1>
  {$loginFlash}
  <form method="post" action="/admin.php?r=login" class="form">
    <input type="hidden" name="action" value="login">
    <label>用户名<input name="username" required autofocus autocomplete="username"></label>
    <label>密码<input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">登录</button>
  </form>
  <p class="muted">首次运行用 <code>php bin/init.php</code> 创建管理员</p>
</div></body></html>
HTML;
    exit;
}

$admin = require_admin();
$flash = flash();

$navItems = [
    'dashboard' => '概览',
    'subs'      => '投稿审核',
    'res'       => '资源管理',
    'sync'      => '同步与采集',
    'settings'  => '设置',
];
$nav = '';
$pending = (int) db()->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();
foreach ($navItems as $k => $label) {
    $active = $route === $k ? ' active' : '';
    $badge = ($k === 'subs' && $pending > 0) ? ' <em>' . $pending . '</em>' : '';
    $nav .= '<a class="' . trim($active) . '" href="/admin.php?r=' . $k . '">' . $label . $badge . '</a>';
}

$body = '';
switch ($route) {
    case 'subs':
        $status = (string) ($_GET['status'] ?? 'pending');
        $stmt = db()->prepare('SELECT * FROM submissions WHERE status = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$status]);
        $rows = $stmt->fetchAll();
        $tabs = '';
        foreach (['pending' => '待审', 'approved' => '通过', 'rejected' => '拒绝'] as $k => $v) {
            $tabs .= '<a class="tab' . ($status === $k ? ' active' : '') . '" href="/admin.php?r=subs&status=' . $k . '">' . $v . '</a>';
        }
        $tr = '';
        foreach ($rows as $s) {
            $mod = mod_check((string) $s['title'], (string) ($s['note'] ?? ''));
            $risk = '<span class="risk ' . e($mod['level']) . '">' . e($mod['level']) . '</span>';
            $ops = $s['status'] === 'pending'
                ? '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">
                   <input type="hidden" name="action" value="review"><input type="hidden" name="id" value="' . (int) $s['id'] . '">
                   <input type="hidden" name="decision" value="approve"><button class="mini ok">通过</button></form>
                   <form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">
                   <input type="hidden" name="action" value="review"><input type="hidden" name="id" value="' . (int) $s['id'] . '">
                   <input type="hidden" name="decision" value="reject"><button class="mini bad">拒绝</button></form>'
                : '<span class="muted">' . e((string) ($s['reject_reason'] ?? '')) . '</span>';
            $tr .= '<tr><td><div class="t">' . e((string) $s['title']) . '</div>'
                . '<div class="m">' . e((string) $s['share_url']) . ' ' . ($s['pwd'] ? '码 ' . e((string) $s['pwd']) : '') . '</div>'
                . '<div class="m muted">' . e((string) ($s['note'] ?? '')) . ' ' . e((string) ($s['contact'] ?? '')) . '</div></td>'
                . '<td>' . $risk . '</td><td class="muted">' . e(substr((string) $s['created_at'], 0, 16)) . '</td><td>' . $ops . '</td></tr>';
        }
        $body = '<div class="tabs">' . $tabs . '</div><table class="tbl"><thead><tr><th>内容</th><th>风控</th><th>时间</th><th>操作</th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="4" class="muted">暂无</td></tr>') . '</tbody></table>';
        break;

    case 'res':
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = repo_search(['q' => $q], $page, 20);
        // 管理端需要看到隐藏项，这里额外查总数
        $tr = '';
        foreach ($res['items'] as $r) {
            $tr .= '<tr><td><div class="t">' . e((string) $r['title']) . '</div>'
                . '<div class="m muted">' . e((string) $r['share_url']) . '</div></td>'
                . '<td>' . e((string) $r['provider']) . ' / ' . e((string) $r['type']) . '</td>'
                . '<td>' . e((string) $r['status']) . '</td>'
                . '<td class="muted">' . e(substr((string) ($r['posted_at'] ?? ''), 0, 10)) . '</td>'
                . '<td><form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="hidden">'
                . '<input type="hidden" name="id" value="' . e((string) $r['id']) . '"><input type="hidden" name="q" value="' . e($q) . '">'
                . '<button class="mini bad">下架</button></form></td></tr>';
        }
        $body = '<form class="form row" method="get" action="/admin.php"><input type="hidden" name="r" value="res">'
            . '<input name="q" value="' . e($q) . '" placeholder="按关键词搜索资源"><button class="btn">搜索</button></form>'
            . '<table class="tbl"><thead><tr><th>资源</th><th>平台/类型</th><th>状态</th><th>发布</th><th>操作</th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="5" class="muted">没有数据，先去「同步与采集」导入</td></tr>') . '</tbody></table>'
            . '<p class="muted">共 ' . number_format($res['total']) . ' 条，第 ' . $res['page'] . '/' . $res['pages'] . ' 页</p>';
        break;

    case 'sync':
        $index = null;
        $indexErr = '';
        try {
            $index = repo_index();
            if ($index === null) {
                $indexErr = '拉取 index.json 失败（检查网络或 repo.proxy 配置）';
            }
        } catch (Throwable $e) {
            $indexErr = $e->getMessage();
        }
        $shards = '';
        foreach (($index['shards'] ?? []) as $s) {
            $shards .= '<tr><td>' . e((string) $s['id']) . '</td><td>' . number_format((int) $s['resources']) . '</td>'
                . '<td class="muted">' . e((string) $s['from']) . ' ~ ' . e((string) $s['to']) . '</td>'
                . '<td><form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="sync_shard"><input type="hidden" name="shard" value="' . e((string) $s['id']) . '">'
                . '<button class="mini">导入</button></form></td></tr>';
        }
        $logs = db()->query('SELECT * FROM logs ORDER BY id DESC LIMIT 12')->fetchAll();
        $logRows = '';
        foreach ($logs as $l) {
            $logRows .= '<tr><td>' . e((string) $l['kind']) . '</td><td>' . ($l['ok'] ? '成功' : '失败') . '</td>'
                . '<td class="muted">' . e((string) $l['message']) . ' ' . e((string) $l['stats_json']) . '</td>'
                . '<td class="muted">' . e(substr((string) $l['created_at'], 0, 16)) . '</td></tr>';
        }
        $ghConfigured = (string) cfg('github.token', '') !== '';
        $body = '<div class="cols">'
            . '<div class="col"><h2>仓库导入（推荐）</h2>'
            . '<p class="muted">从数据仓库拉取热文件，覆盖最近 90 天。' . ($indexErr ? '<span class="risk blocked">' . e($indexErr) . '</span>' : '') . '</p>'
            . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="sync_hot"><button class="btn">同步热文件</button></form>'
            . '<h3>历史分片</h3><table class="tbl small"><tbody>' . ($shards ?: '<tr><td class="muted">没有拿到分片清单</td></tr>') . '</tbody></table>'
            . '</div>'
            . '<div class="col"><h2>本地采集</h2><p class="muted">直接抓 t.me/s 网页预览，兜底用。</p>'
            . '<form method="post" class="form"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="crawl">'
            . '<label>频道（逗号分隔）<input name="channels" value="' . e(implode(',', (array) cfg('crawler.channels', []))) . '"></label>'
            . '<label>每个频道翻页<input name="pages" type="number" value="1" min="1" max="10"></label>'
            . '<button class="btn" type="submit">开始采集</button></form>'
            . '<h3>触发云端跑一轮</h3>'
            . ($ghConfigured
                ? '<form method="post" class="row"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                  . '<input type="hidden" name="action" value="trigger">'
                  . '<select name="workflow"><option value="collect">collect 增量</option><option value="backfill">backfill 深挖</option><option value="mtproto">mtproto 追新</option></select>'
                  . '<button class="btn" type="submit">触发</button></form>'
                : '<p class="muted">未配置 github.token，无法从后台触发</p>')
            . '</div></div>'
            . '<h2>最近日志</h2><table class="tbl small"><tbody>' . ($logRows ?: '<tr><td class="muted">暂无</td></tr>') . '</tbody></table>';
        break;

    case 'settings':
        $body = '<div class="cols"><div class="col"><h2>站点</h2>'
            . '<form method="post" class="form"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="settings">'
            . '<label>站点名称<input name="site_name" value="' . e(setting('site_name', (string) cfg('site_name'))) . '"></label>'
            . '<label>副标题<input name="site_desc" value="' . e(setting('site_desc', (string) cfg('site_slogan'))) . '"></label>'
            . '<label>公告（留空不显示）<textarea name="notice" rows="2">' . e(setting('notice', '')) . '</textarea></label>'
            . '<label>开放投稿<select name="submit_open"><option value="1"' . (setting('submit_open', '1') === '1' ? ' selected' : '') . '>开启</option>'
            . '<option value="0"' . (setting('submit_open', '1') === '0' ? ' selected' : '') . '>关闭</option></select></label>'
            . '<button class="btn" type="submit">保存</button></form></div>'
            . '<div class="col"><h2>改密码</h2><form method="post" class="form">'
            . '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="password">'
            . '<label>新密码（≥8 位）<input name="new_password" type="password" minlength="8" required></label>'
            . '<button class="btn" type="submit">更新密码</button></form>'
            . '<h3>数据源配置</h3><p class="muted">仓库地址、代理、GitHub token 在 <code>src/config.local.php</code> 里配，改完即生效。</p>'
            . '</div></div>';
        break;

    default:
        $s = repo_stats();
        $cards = '';
        foreach ([
            ['资源总数', $s['total']], ['今日新增', $s['today']], ['待审投稿', $s['pending']], ['已下架', $s['hidden']],
        ] as [$label, $value]) {
            $cards .= '<div class="kpi"><b>' . number_format((int) $value) . '</b><span>' . $label . '</span></div>';
        }
        $typeRows = '';
        foreach ($s['by_type'] as $t) {
            $typeRows .= '<tr><td>' . e((string) $t['type']) . '</td><td>' . number_format((int) $t['c']) . '</td></tr>';
        }
        $provRows = '';
        foreach ($s['by_provider'] as $p) {
            $provRows .= '<tr><td>' . e((string) $p['provider']) . '</td><td>' . number_format((int) $p['c']) . '</td></tr>';
        }
        $body = '<div class="kpis">' . $cards . '</div><div class="cols">'
            . '<div class="col"><h2>按类型</h2><table class="tbl small"><tbody>' . ($typeRows ?: '<tr><td class="muted">暂无</td></tr>') . '</tbody></table></div>'
            . '<div class="col"><h2>按平台</h2><table class="tbl small"><tbody>' . ($provRows ?: '<tr><td class="muted">暂无</td></tr>') . '</tbody></table></div>'
            . '</div>';
        break;
}

$adminName = e($admin['name']);
$csrfField = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
$routeTitle = $navItems[$route] ?? '概览';
$flashHtml = $flash ? '<div class="flash ' . e($flash['type']) . '">' . e($flash['msg']) . '</div>' : '';
echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>后台 - 盘搜</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin">
<aside class="side">
  <div class="side-brand">盘搜 · 后台</div>
  <nav>{$nav}</nav>
  <div class="side-foot">
    <span>{$adminName}</span>
    <form method="post">{$csrfField}<input type="hidden" name="action" value="logout"><button class="link">退出</button></form>
  </div>
</aside>
<main class="admin-main">{$flashHtml}<h1>{$routeTitle}</h1>{$body}</main>
</body></html>
HTML;
