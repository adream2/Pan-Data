<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/user.php';
require_once __DIR__ . '/../src/seo.php';

session_name((string) cfg('security.session_name', 'pansou_sid'));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();
schema_upgrade();

$path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$route = (string) ($_GET['r'] ?? ($path === 'user' || $path === 'user.php' ? 'me' : 'login'));
$route = preg_replace('#[^a-z_]#', '', $route) ?: 'me';
$action = (string) ($_POST['action'] ?? '');
$user = user_current();

if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($action, ['login', 'register'], true)) {
        if (!rate_limit('user_' . $action, 10, 900)) {
            flash('操作太频繁，稍后再试', 'err');
        } else {
            csrf_check();
            [$ok, $msg] = $action === 'login'
                ? user_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))
                : user_register((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['email'] ?? ''));
            flash($msg, $ok ? 'ok' : 'err');
            if ($ok) {
                redirect('/user?r=me');
            }
        }
        redirect('/user?r=' . $action);
    }

    csrf_check();
    if ($user === null) {
        redirect('/user?r=login');
    }

    switch ($action) {
        case 'logout':
            user_logout();
            redirect('/');
        case 'api_key':
            api_key_for((int) $user['id']);
            flash('API Key 已重新生成（90 天有效）');
            redirect('/user?r=api');
        case 'favorite':
            $rid = (string) ($_POST['resource_id'] ?? '');
            if ($rid !== '') {
                $added = user_favorite((int) $user['id'], $rid);
                flash($added ? '已加入收藏' : '已取消收藏');
            }
            redirect('/detail?id=' . urlencode($rid));
        case 'report':
            $rid = (string) ($_POST['resource_id'] ?? '');
            if ($rid !== '') {
                report_create($rid, (string) ($_POST['kind'] ?? 'invalid'), (string) ($_POST['note'] ?? ''), (int) $user['id']);
                flash('举报已提交，感谢反馈');
            }
            redirect('/detail?id=' . urlencode($rid));
    }
}

if ($route === 'logout') {
    user_logout();
    redirect('/');
}

$title = '用户中心 - ' . setting('site_name', (string) cfg('site_name'));

if ($user === null) {
    $f = flash();
    $flashHtml = $f ? '<div class="mb-4 rounded-lg px-3.5 py-2.5 text-sm ' . ($f['type'] === 'err'
        ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-blue-50 text-blue-700 ring-1 ring-blue-100') . '">' . e($f['msg']) . '</div>' : '';

    $isLogin = $route !== 'register';
    $body = '<div class="mx-auto max-w-sm pt-16">'
        . '<div class="rounded-2xl bg-white p-7 ring-1 ring-neutral-200/80">'
        . '<h1 class="text-base font-semibold">' . ($isLogin ? '登录' : '注册账号') . '</h1>'
        . '<p class="mt-1 text-xs text-neutral-400">' . ($isLogin ? '登录后可以投稿、收藏、申请 API' : '注册后可以投稿、收藏、申请 API') . '</p>'
        . '<div class="mt-5">' . $flashHtml . '</div>'
        . '<form method="post" action="/user?r=' . ($isLogin ? 'login' : 'register') . '" class="space-y-4">'
        . '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="action" value="' . ($isLogin ? 'login' : 'register') . '">'
        . '<div><label class="mb-1.5 block text-xs font-medium text-neutral-500">用户名</label>'
        . '<input class="input" name="username" required autofocus autocomplete="username"></div>'
        . '<div><label class="mb-1.5 block text-xs font-medium text-neutral-500">密码</label>'
        . '<input class="input" name="password" type="password" required minlength="6" autocomplete="current-password"></div>'
        . ($isLogin ? '' : '<div><label class="mb-1.5 block text-xs font-medium text-neutral-500">邮箱（可选）</label>'
            . '<input class="input" name="email" type="email" placeholder="用于找回，可不填"></div>')
        . '<button class="btn w-full" type="submit">' . ($isLogin ? '登录' : '注册') . '</button>'
        . '</form>'
        . '<p class="mt-4 text-center text-xs text-neutral-400">'
        . ($isLogin
            ? '还没有账号？<a class="text-blue-600 hover:underline" href="/user?r=register">注册</a>'
            : '已有账号？<a class="text-blue-600 hover:underline" href="/user?r=login">登录</a>')
        . '</p></div></div>';
    view('layout', ['content' => $body, 'title' => $title,
        'robots' => 'noindex, nofollow', 'description' => '用户中心', 'canonical' => seo_abs('/user')]);
    exit;
}

$tab = in_array($route, ['me', 'subs', 'favs', 'api', 'reports'], true) ? $route : 'me';
$uid = (int) $user['id'];

$counts = [
    'favs'    => (int) db()->query("SELECT COUNT(*) FROM favorites WHERE user_id = {$uid}")->fetchColumn(),
    'reports' => (int) db()->query("SELECT COUNT(*) FROM reports WHERE user_id = {$uid}")->fetchColumn(),
];
$subStmt = db()->prepare('SELECT COUNT(*) FROM submissions WHERE contact = ?');
$subStmt->execute([(string) $user['username']]);
$counts['subs'] = (int) $subStmt->fetchColumn();

$tabs = '';
foreach (['me' => '概览', 'subs' => '我的投稿', 'favs' => '我的收藏', 'reports' => '我的举报', 'api' => 'API 密钥'] as $k => $label) {
    $active = $tab === $k;
    $tabs .= '<a href="/user?r=' . $k . '" class="rounded-lg px-3 py-1.5 text-xs font-medium transition '
        . ($active ? 'bg-slate-800 text-white' : 'text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900') . '">' . $label . '</a>';
}

$panel = '';
if ($tab === 'me') {
    $panel = '<div class="grid gap-3 sm:grid-cols-3">'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $counts['subs'] . '</div><div class="mt-0.5 text-xs text-neutral-400">投稿</div></div>'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $counts['favs'] . '</div><div class="mt-0.5 text-xs text-neutral-400">收藏</div></div>'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $counts['reports'] . '</div><div class="mt-0.5 text-xs text-neutral-400">举报</div></div>'
        . '</div>'
        . '<div class="mt-5 kpi">'
        . '<h2 class="text-sm font-semibold">账号信息</h2>'
        . '<dl class="mt-3 space-y-2 text-sm">'
        . '<div class="flex justify-between"><dt class="text-neutral-500">用户名</dt><dd>' . e((string) $user['username']) . '</dd></div>'
        . '<div class="flex justify-between"><dt class="text-neutral-500">注册时间</dt><dd class="font-mono text-xs text-neutral-500">' . e(substr((string) $user['created_at'], 0, 10)) . '</dd></div>'
        . '<div class="flex justify-between"><dt class="text-neutral-500">API 到期</dt><dd class="font-mono text-xs text-neutral-500">'
        . ($user['api_expires_at'] ? e(substr((string) $user['api_expires_at'], 0, 10)) : '未申请') . '</dd></div>'
        . '</dl></div>';
} elseif ($tab === 'subs') {
    $stmt = db()->prepare('SELECT * FROM submissions WHERE contact = ? ORDER BY id DESC LIMIT 50');
    $stmt->execute([(string) $user['username']]);
    $rows = '';
    foreach ($stmt->fetchAll() as $s) {
        $cls = match ($s['status']) {
            'approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'rejected' => 'bg-rose-50 text-rose-700 ring-rose-200',
            default => 'bg-amber-50 text-amber-700 ring-amber-200',
        };
        $rows .= '<tr class="border-b border-neutral-100 last:border-0">'
            . '<td class="px-4 py-3"><div class="text-[13px] font-medium">' . e((string) $s['title']) . '</div>'
            . '<div class="mt-0.5 break-all font-mono text-[11px] text-neutral-400">' . e((string) $s['share_url']) . '</div></td>'
            . '<td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] ring-1 ' . $cls . '">' . e((string) $s['status']) . '</span></td>'
            . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e(substr((string) $s['created_at'], 0, 10)) . '</td></tr>';
    }
    $panel = '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
        . '<thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-400"><tr>'
        . '<th class="px-4 py-2.5 font-medium">资源</th><th class="px-4 py-2.5 font-medium">状态</th><th class="px-4 py-2.5 font-medium">提交</th>'
        . '</tr></thead><tbody>' . ($rows ?: '<tr><td colspan="3" class="px-4 py-10 text-center text-sm text-neutral-400">还没有投稿</td></tr>')
        . '</tbody></table></div>'
        . '<p class="mt-3 text-xs text-neutral-400">提示：投稿时「联系方式」填你的用户名，才会出现在这里。</p>';
} elseif ($tab === 'favs') {
    $rows = '';
    foreach (user_favorites($uid) as $r) {
        $rows .= '<a href="/detail?id=' . e((string) $r['id']) . '" class="flex items-center justify-between gap-3 rounded-xl bg-white p-4 ring-1 ring-neutral-200/80 transition hover:ring-neutral-300">'
            . '<span class="min-w-0"><span class="block truncate text-[13px] font-medium">' . e((string) $r['title']) . '</span>'
            . '<span class="mt-0.5 block text-[11px] text-neutral-400">' . e((string) $r['provider']) . ' · ' . e(substr((string) ($r['posted_at'] ?? ''), 0, 10)) . '</span></span>'
            . '<span class="shrink-0 text-xs text-neutral-400">查看</span></a>';
    }
    $panel = $rows !== ''
        ? '<div class="space-y-2">' . $rows . '</div>'
        : '<div class="rounded-xl bg-white px-6 py-14 text-center text-sm text-neutral-400 ring-1 ring-neutral-200/80">还没有收藏，去搜索页点「收藏」试试</div>';
} elseif ($tab === 'reports') {
    $stmt = db()->prepare('SELECT r.*, s.title FROM reports r LEFT JOIN resources s ON s.id = r.resource_id
        WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 50');
    $stmt->execute([$uid]);
    $rows = '';
    foreach ($stmt->fetchAll() as $r) {
        $rows .= '<tr class="border-b border-neutral-100 last:border-0">'
            . '<td class="px-4 py-3 text-[13px]">' . e((string) ($r['title'] ?? $r['resource_id'])) . '</td>'
            . '<td class="px-4 py-3 text-xs text-neutral-500">' . e((string) $r['kind']) . '</td>'
            . '<td class="px-4 py-3 text-[11px] text-neutral-400">' . e((string) ($r['note'] ?? '')) . '</td>'
            . '<td class="px-4 py-3 text-xs">' . e((string) $r['status']) . '</td></tr>';
    }
    $panel = '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
        . '<thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-400"><tr>'
        . '<th class="px-4 py-2.5 font-medium">资源</th><th class="px-4 py-2.5 font-medium">类型</th>'
        . '<th class="px-4 py-2.5 font-medium">备注</th><th class="px-4 py-2.5 font-medium">状态</th></tr></thead><tbody>'
        . ($rows ?: '<tr><td colspan="4" class="px-4 py-10 text-center text-sm text-neutral-400">还没有提交举报</td></tr>')
        . '</tbody></table></div>';
} else {
    $key = (string) ($user['api_key'] ?? '');
    $expires = (string) ($user['api_expires_at'] ?? '');
    $panel = '<div class="rounded-lg bg-white p-6 shadow-sm">'
        . '<h2 class="text-sm font-semibold">API 密钥</h2>'
        . '<p class="mt-1 text-xs text-neutral-500">90 天有效，过期后重新生成即可。请勿泄露。</p>'
        . ($key !== '' && strtotime($expires) > time()
            ? '<div class="mt-4 flex flex-wrap items-center gap-2"><code class="break-all rounded-lg bg-neutral-100 px-3 py-2 font-mono text-xs">' . e($key) . '</code>'
              . '<span class="text-[11px] text-neutral-400">到期 ' . e(substr($expires, 0, 10)) . '</span></div>'
            : '<p class="mt-4 text-sm text-neutral-500">还没有密钥</p>')
        . '<form method="post" class="mt-5"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="action" value="api_key">'
        . '<button class="btn-soft" type="submit">' . ($key !== '' ? '重新生成' : '生成密钥') . '</button></form>'
        . '<h3 class="mt-6 text-[11px] uppercase tracking-wide text-neutral-400">调用示例</h3>'
        . '<pre class="mt-2 overflow-x-auto rounded-lg bg-neutral-900 p-4 text-[11px] leading-relaxed text-neutral-100">curl -H "X-API-Key: ' . e($key !== '' ? $key : 'YOUR_KEY') . '" \\' . "\n"
        . '  "https://your-domain/api.php?q=课程&amp;limit=20"</pre>'
        . '<p class="mt-2 text-[11px] text-neutral-400">返回 JSON：id / title / provider / share_url / pwd / status / posted_at</p>'
        . '</div>';
}

$content = '<div class="mx-auto max-w-3xl pt-8">'
    . '<div class="mb-5 flex flex-wrap items-center justify-between gap-3">'
    . '<div><h1 class="text-lg font-semibold tracking-tight">' . e((string) $user['username']) . '</h1>'
    . '<p class="mt-0.5 text-xs text-neutral-400">用户中心</p></div>'
    . '<form method="post"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
    . '<input type="hidden" name="action" value="logout">'
    . '<button class="btn-soft" type="submit">退出登录</button></form></div>'
    . '<div class="mb-5 flex flex-wrap gap-1">' . $tabs . '</div>'
    . $panel . '</div>';

view('layout', ['content' => $content, 'title' => $title,
        'robots' => 'noindex, nofollow', 'description' => '用户中心', 'canonical' => seo_abs('/user')]);
