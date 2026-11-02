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
            $count = 0;
            $sum = 0;
            foreach ($channels as $ch) {
                if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $ch)) {
                    continue;
                }
                $stat = crawler_ingest($ch, (int) ($_POST['pages'] ?? 1));
                $sum += $stat['insert'];
                $count++;
            }
            flash("采集完成：{$count} 个频道，新增 {$sum} 条");
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

/** ---------------- 登录页 ---------------- */
if ($route === 'login') {
    if (current_admin() !== null) {
        redirect('/admin.php');
    }
    $f = flash();
    $flashHtml = $f
        ? '<div class="mb-4 rounded-lg bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700 ring-1 ring-rose-200">' . e($f['msg']) . '</div>'
        : '';
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>后台登录</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="grid min-h-screen place-items-center bg-neutral-50 px-4">
<div class="w-full max-w-sm rounded-2xl bg-white p-7 ring-1 ring-neutral-200/80">
  <div class="mb-6 flex items-center gap-2">
    <span class="grid size-9 place-items-center rounded-lg bg-neutral-900 text-sm font-semibold text-white">盘</span>
    <div><div class="text-sm font-semibold">后台登录</div><div class="text-[11px] text-neutral-400">盘搜管理控制台</div></div>
  </div>
  {$flashHtml}
  <form method="post" action="/admin.php?r=login" class="space-y-4">
    <input type="hidden" name="action" value="login">
    <div><label class="mb-1.5 block text-xs font-medium text-neutral-500">用户名</label>
      <input class="input" name="username" required autofocus autocomplete="username"></div>
    <div><label class="mb-1.5 block text-xs font-medium text-neutral-500">密码</label>
      <input class="input" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn w-full" type="submit">登录</button>
  </form>
  <p class="mt-5 text-[11px] leading-relaxed text-neutral-400">首次部署运行 <code class="rounded bg-neutral-100 px-1 py-0.5">php bin/init.php</code> 创建管理员</p>
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
$pending = (int) db()->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();

$nav = '';
foreach ($navItems as $k => $label) {
    $active = $route === $k;
    $cls = $active
        ? 'flex items-center justify-between rounded-lg bg-neutral-900 px-3 py-2 text-sm font-medium text-white'
        : 'flex items-center justify-between rounded-lg px-3 py-2 text-sm text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900';
    $badge = ($k === 'subs' && $pending > 0)
        ? '<span class="rounded-full bg-rose-500 px-1.5 text-[10px] font-medium leading-4 text-white">' . $pending . '</span>'
        : '';
    $nav .= '<a class="' . $cls . '" href="/admin.php?r=' . $k . '"><span>' . $label . '</span>' . $badge . '</a>';
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
            $active = $status === $k;
            $tabs .= '<a href="/admin.php?r=subs&status=' . $k . '" class="rounded-full px-3.5 py-1.5 text-xs font-medium transition '
                . ($active ? 'bg-neutral-900 text-white' : 'bg-white text-neutral-500 ring-1 ring-neutral-200 hover:text-neutral-900') . '">'
                . $v . '</a>';
        }

        $tr = '';
        foreach ($rows as $s) {
            $mod = mod_check((string) $s['title'], (string) ($s['note'] ?? ''));
            $riskCls = match ($mod['level']) {
                'low' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                'medium' => 'bg-amber-50 text-amber-700 ring-amber-200',
                default => 'bg-rose-50 text-rose-700 ring-rose-200',
            };
            $ops = $s['status'] === 'pending'
                ? '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                  . '<input type="hidden" name="action" value="review"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
                  . '<input type="hidden" name="decision" value="approve">'
                  . '<button class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100">通过</button></form> '
                  . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                  . '<input type="hidden" name="action" value="review"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
                  . '<input type="hidden" name="decision" value="reject">'
                  . '<button class="rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100">拒绝</button></form>'
                : '<span class="text-xs text-neutral-400">' . e((string) ($s['reject_reason'] ?? '')) . '</span>';

            $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-4 py-3 align-top"><div class="text-[13px] font-medium text-neutral-900">' . e((string) $s['title']) . '</div>'
                . '<div class="mt-1 break-all font-mono text-[11px] text-neutral-400">' . e((string) $s['share_url']) . '</div>'
                . '<div class="mt-1 text-[11px] text-neutral-400">' . e((string) ($s['note'] ?? '')) . ' ' . e((string) ($s['contact'] ?? '')) . '</div></td>'
                . '<td class="px-4 py-3 align-top"><span class="rounded-full px-2 py-0.5 text-[11px] ring-1 ' . $riskCls . '">' . e($mod['level']) . '</span></td>'
                . '<td class="px-4 py-3 align-top font-mono text-[11px] text-neutral-400">' . e(substr((string) $s['created_at'], 0, 16)) . '</td>'
                . '<td class="px-4 py-3 align-top">' . $ops . '</td></tr>';
        }
        $body = '<div class="mb-4 flex gap-2">' . $tabs . '</div>'
            . '<div class="overflow-hidden rounded-xl bg-white ring-1 ring-neutral-200/80"><table class="w-full text-left">'
            . '<thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-400">'
            . '<tr><th class="px-4 py-2.5 font-medium">内容</th><th class="px-4 py-2.5 font-medium">风控</th>'
            . '<th class="px-4 py-2.5 font-medium">时间</th><th class="px-4 py-2.5 font-medium">操作</th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="4" class="px-4 py-10 text-center text-sm text-neutral-400">暂无投稿</td></tr>')
            . '</tbody></table></div>';
        break;

    case 'res':
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = repo_search(['q' => $q], $page, 20);
        $tr = '';
        foreach ($res['items'] as $r) {
            $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-4 py-3"><div class="text-[13px] font-medium text-neutral-900">' . e((string) $r['title']) . '</div>'
                . '<div class="mt-1 break-all font-mono text-[11px] text-neutral-400">' . e((string) $r['share_url']) . '</div></td>'
                . '<td class="px-4 py-3 text-xs text-neutral-500">' . e((string) $r['provider']) . ' · ' . e((string) $r['type']) . '</td>'
                . '<td class="px-4 py-3 text-xs text-neutral-500">' . e((string) $r['status']) . '</td>'
                . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e(substr((string) ($r['posted_at'] ?? ''), 0, 10)) . '</td>'
                . '<td class="px-4 py-3"><form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="hidden">'
                . '<input type="hidden" name="id" value="' . e((string) $r['id']) . '"><input type="hidden" name="q" value="' . e($q) . '">'
                . '<button class="rounded-md bg-white px-2.5 py-1 text-xs text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">下架</button></form></td></tr>';
        }
        $body = '<form class="mb-4 flex max-w-md items-center gap-2" method="get" action="/admin.php">'
            . '<input type="hidden" name="r" value="res">'
            . '<input class="input" name="q" value="' . e($q) . '" placeholder="按关键词搜索资源">'
            . '<button class="btn shrink-0" type="submit">搜索</button></form>'
            . '<div class="overflow-hidden rounded-xl bg-white ring-1 ring-neutral-200/80"><table class="w-full text-left">'
            . '<thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-400">'
            . '<tr><th class="px-4 py-2.5 font-medium">资源</th><th class="px-4 py-2.5 font-medium">平台/类型</th>'
            . '<th class="px-4 py-2.5 font-medium">状态</th><th class="px-4 py-2.5 font-medium">发布</th><th class="px-4 py-2.5 font-medium"></th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">没有数据，先去「同步与采集」导入</td></tr>')
            . '</tbody></table></div>'
            . '<p class="mt-3 text-xs text-neutral-400">共 ' . number_format($res['total']) . ' 条 · 第 ' . $res['page'] . '/' . $res['pages'] . ' 页</p>';
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
            $shards .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-3 py-2 font-mono text-xs">' . e((string) $s['id']) . '</td>'
                . '<td class="px-3 py-2 text-xs tabular-nums text-neutral-500">' . number_format((int) $s['resources']) . '</td>'
                . '<td class="px-3 py-2 text-[11px] text-neutral-400">' . e((string) $s['from']) . ' ~ ' . e((string) $s['to']) . '</td>'
                . '<td class="px-3 py-2 text-right"><form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="sync_shard"><input type="hidden" name="shard" value="' . e((string) $s['id']) . '">'
                . '<button class="rounded-md bg-white px-2 py-0.5 text-[11px] text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">导入</button></form></td></tr>';
        }
        $logs = db()->query('SELECT * FROM logs ORDER BY id DESC LIMIT 10')->fetchAll();
        $logRows = '';
        foreach ($logs as $l) {
            $logRows .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-3 py-2 text-xs text-neutral-600">' . e((string) $l['kind']) . '</td>'
                . '<td class="px-3 py-2 text-xs ' . ($l['ok'] ? 'text-emerald-600' : 'text-rose-600') . '">' . ($l['ok'] ? '成功' : '失败') . '</td>'
                . '<td class="px-3 py-2 text-[11px] text-neutral-400">' . e((string) $l['message']) . '</td>'
                . '<td class="px-3 py-2 font-mono text-[11px] text-neutral-400">' . e(substr((string) $l['created_at'], 0, 16)) . '</td></tr>';
        }
        $ghConfigured = (string) cfg('github.token', '') !== '';

        $body = '<div class="grid gap-5 lg:grid-cols-2">'
            . '<section class="rounded-xl bg-white p-5 ring-1 ring-neutral-200/80">'
            . '<h2 class="text-sm font-semibold">仓库导入</h2>'
            . '<p class="mt-1 text-xs text-neutral-500">从数据仓库拉热文件，覆盖最近 90 天。'
            . ($indexErr !== '' ? '<span class="ml-1 text-rose-600">' . e($indexErr) . '</span>' : '') . '</p>'
            . '<form method="post" class="mt-3"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="sync_hot">'
            . '<button class="btn" type="submit">同步热文件</button></form>'
            . '<h3 class="mb-2 mt-5 text-[11px] uppercase tracking-wide text-neutral-400">历史分片</h3>'
            . '<div class="max-h-64 overflow-auto rounded-lg ring-1 ring-neutral-100"><table class="w-full text-left">'
            . ($shards ?: '<tr><td class="px-3 py-6 text-center text-xs text-neutral-400">没有拿到分片清单</td></tr>')
            . '</table></div></section>'
            . '<section class="rounded-xl bg-white p-5 ring-1 ring-neutral-200/80">'
            . '<h2 class="text-sm font-semibold">本地采集</h2>'
            . '<p class="mt-1 text-xs text-neutral-500">直接抓 t.me/s 网页预览，兜底用。</p>'
            . '<form method="post" class="mt-3 space-y-3"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="crawl">'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">频道（逗号分隔）</label>'
            . '<input class="input" name="channels" value="' . e(implode(',', (array) cfg('crawler.channels', []))) . '"></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">每频道翻页</label>'
            . '<input class="input w-24" name="pages" type="number" value="1" min="1" max="10"></div>'
            . '<button class="btn" type="submit">开始采集</button></form>'
            . '<h3 class="mb-2 mt-5 text-[11px] uppercase tracking-wide text-neutral-400">触发云端</h3>'
            . ($ghConfigured
                ? '<form method="post" class="flex items-center gap-2"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                  . '<input type="hidden" name="action" value="trigger">'
                  . '<select class="input" name="workflow"><option value="collect">collect 增量</option>'
                  . '<option value="backfill">backfill 深挖</option><option value="mtproto">mtproto 追新</option></select>'
                  . '<button class="btn shrink-0" type="submit">触发</button></form>'
                : '<p class="text-xs text-neutral-400">未配置 github.token，无法从后台触发</p>')
            . '</section></div>'
            . '<section class="mt-5 rounded-xl bg-white p-5 ring-1 ring-neutral-200/80">'
            . '<h2 class="mb-3 text-sm font-semibold">最近日志</h2><table class="w-full text-left">'
            . ($logRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无</td></tr>') . '</table></section>';
        break;

    case 'settings':
        $body = '<div class="grid gap-5 lg:grid-cols-2">'
            . '<section class="rounded-xl bg-white p-5 ring-1 ring-neutral-200/80">'
            . '<h2 class="text-sm font-semibold">站点</h2>'
            . '<form method="post" class="mt-4 space-y-4"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="settings">'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">站点名称</label>'
            . '<input class="input" name="site_name" value="' . e(setting('site_name', (string) cfg('site_name'))) . '"></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">副标题</label>'
            . '<input class="input" name="site_desc" value="' . e(setting('site_desc', (string) cfg('site_slogan'))) . '"></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">公告（留空不显示）</label>'
            . '<textarea class="input h-auto py-2" name="notice" rows="2">' . e(setting('notice', '')) . '</textarea></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">开放投稿</label>'
            . '<select class="input" name="submit_open"><option value="1"' . (setting('submit_open', '1') === '1' ? ' selected' : '') . '>开启</option>'
            . '<option value="0"' . (setting('submit_open', '1') === '0' ? ' selected' : '') . '>关闭</option></select></div>'
            . '<button class="btn" type="submit">保存</button></form></section>'
            . '<section class="rounded-xl bg-white p-5 ring-1 ring-neutral-200/80">'
            . '<h2 class="text-sm font-semibold">改密码</h2>'
            . '<form method="post" class="mt-4 space-y-4"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="password">'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">新密码（≥8 位）</label>'
            . '<input class="input" name="new_password" type="password" minlength="8" required></div>'
            . '<button class="btn" type="submit">更新密码</button></form>'
            . '<h3 class="mb-2 mt-6 text-[11px] uppercase tracking-wide text-neutral-400">数据源配置</h3>'
            . '<p class="text-xs leading-relaxed text-neutral-500">仓库地址、代理、GitHub token 在 '
            . '<code class="rounded bg-neutral-100 px-1 py-0.5">src/config.local.php</code> 里配，保存即生效。</p>'
            . '</section></div>';
        break;

    default:
        $s = repo_stats();
        $cards = '';
        foreach ([['资源总数', $s['total']], ['今日新增', $s['today']], ['待审投稿', $s['pending']], ['已下架', $s['hidden']]] as [$label, $value]) {
            $cards .= '<div class="rounded-xl bg-white p-4 ring-1 ring-neutral-200/80">'
                . '<div class="text-2xl font-semibold tabular-nums">' . number_format((int) $value) . '</div>'
                . '<div class="mt-0.5 text-xs text-neutral-400">' . $label . '</div></div>';
        }
        $typeRows = '';
        foreach ($s['by_type'] as $t) {
            $typeRows .= '<tr class="border-b border-neutral-100 last:border-0"><td class="py-2 text-xs text-neutral-600">'
                . e((string) $t['type']) . '</td><td class="py-2 text-right text-xs tabular-nums text-neutral-500">'
                . number_format((int) $t['c']) . '</td></tr>';
        }
        $provRows = '';
        foreach ($s['by_provider'] as $p) {
            $provRows .= '<tr class="border-b border-neutral-100 last:border-0"><td class="py-2 text-xs text-neutral-600">'
                . e((string) $p['provider']) . '</td><td class="py-2 text-right text-xs tabular-nums text-neutral-500">'
                . number_format((int) $p['c']) . '</td></tr>';
        }
        $body = '<div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">' . $cards . '</div>'
            . '<div class="grid gap-5 lg:grid-cols-2">'
            . '<section class="rounded-xl bg-white p-5 ring-1 ring-neutral-200/80"><h2 class="mb-2 text-sm font-semibold">按类型</h2>'
            . '<table class="w-full">' . ($typeRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无</td></tr>') . '</table></section>'
            . '<section class="rounded-xl bg-white p-5 ring-1 ring-neutral-200/80"><h2 class="mb-2 text-sm font-semibold">按平台</h2>'
            . '<table class="w-full">' . ($provRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无</td></tr>') . '</table></section>'
            . '</div>';
        break;
}

$flashHtml = $flash
    ? '<div class="mb-5 rounded-lg px-4 py-3 text-sm ' . ($flash['type'] === 'err'
        ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
        : 'bg-brand-50 text-brand-700 ring-1 ring-brand-100') . '">' . e($flash['msg']) . '</div>'
    : '';
$adminName = e((string) $admin['name']);
$csrfField = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
$routeTitle = $navItems[$route] ?? '概览';

echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$routeTitle} · 盘搜后台</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="min-h-screen bg-neutral-50">
<div class="flex min-h-screen">
  <aside class="hidden w-56 shrink-0 flex-col border-r border-neutral-200/80 bg-white px-3 py-5 sm:flex">
    <div class="mb-6 flex items-center gap-2 px-2">
      <span class="grid size-8 place-items-center rounded-lg bg-neutral-900 text-sm font-semibold text-white">盘</span>
      <div><div class="text-[13px] font-semibold leading-4">盘搜</div><div class="text-[10px] text-neutral-400">管理后台</div></div>
    </div>
    <nav class="flex flex-col gap-0.5">{$nav}</nav>
    <div class="mt-auto flex items-center justify-between px-2 pt-4 text-xs text-neutral-400">
      <span>{$adminName}</span>
      <form method="post">{$csrfField}<input type="hidden" name="action" value="logout">
        <button class="transition hover:text-neutral-700" type="submit">退出</button></form>
    </div>
  </aside>
  <main class="min-w-0 flex-1 px-4 py-6 sm:px-8">
    <div class="mb-4 flex gap-1 overflow-x-auto sm:hidden">{$nav}</div>
    <h1 class="mb-5 text-lg font-semibold tracking-tight">{$routeTitle}</h1>
    {$flashHtml}{$body}
  </main>
</div>
</body></html>
HTML;
