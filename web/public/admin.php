<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/moderation.php';
require_once __DIR__ . '/../src/user.php';
require_once __DIR__ . '/../src/admin_extra.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/promo.php';
require_once __DIR__ . '/../src/health.php';

session_name((string) cfg('security.session_name', 'pansou_sid'));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

schema_upgrade();

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
    admin_extra_actions($action);

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
            $token = (string) repo_conf('github_token', '');
            $repo = (string) repo_conf('github_repo', '');
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
            $saved = cfg_save($_POST);
            flash($saved ? '已保存 ' . count($saved) . ' 项设置，即时生效' : '没有有效改动', $saved ? 'ok' : 'err');
            redirect('/admin.php?r=settings');

        case 'promo_create':
            if (cfg_get('promo_open') !== '1') {
                flash('拉新总开关未开启，先去设置里打开', 'err');
            } else {
                $rid = (string) ($_POST['resource_id'] ?? '');
                $row = $rid !== '' ? promo_resource($rid) : null;
                if ($row === null) {
                    flash('资源不存在', 'err');
                } else {
                    $id = promo_task_create($row, (string) ($row['status'] ?? ''));
                    if ($id > 0 && cfg_get_bool('promo_auto_approve')) {
                        flash(promo_run_one($id), 'ok');
                    } else {
                        flash($id > 0 ? '拉新任务已创建 #' . $id . '，去「拉新任务」执行' : '创建失败');
                    }
                }
            }
            redirect('/admin.php?r=promo');

        case 'promo_retry':
            promo_task_retry((int) ($_POST['id'] ?? 0));
            flash('已重置为待执行');
            redirect('/admin.php?r=promo');

        case 'promo_cancel':
            promo_task_cancel((int) ($_POST['id'] ?? 0));
            flash('任务已取消');
            redirect('/admin.php?r=promo');

        case 'promo_run':
            $msg = promo_run_one((int) ($_POST['id'] ?? 0));
            flash($msg, str_starts_with($msg, '转存成功') ? 'ok' : 'err');
            redirect('/admin.php?r=promo');

        case 'promo_run_all':
            [$ok, $fail, $note] = promo_run_pending();
            flash("批量执行：成功 {$ok}，失败 {$fail}（{$note}）", $fail > 0 ? 'err' : 'ok');
            redirect('/admin.php?r=promo');

        case 'promo_account':
            $err = promo_account_save($_POST);
            flash($err === '' ? '账号已保存' : $err, $err === '' ? 'ok' : 'err');
            redirect('/admin.php?r=promo_accounts');

        case 'promo_account_toggle':
            promo_account_toggle((int) ($_POST['id'] ?? 0));
            flash('账号状态已切换');
            redirect('/admin.php?r=promo_accounts');

        case 'review_bulk':
            $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
            $decision = (string) ($_POST['decision'] ?? '');
            $n = 0;
            if ($ids !== [] && in_array($decision, ['approve', 'reject'], true)) {
                $place = implode(',', array_fill(0, count($ids), '?'));
                $stmtB = db()->prepare('SELECT * FROM submissions WHERE id IN (' . $place . ") AND status = 'pending'");
                $stmtB->execute(array_values($ids));
                foreach ($stmtB->fetchAll() as $sub) {
                    if ($decision === 'approve') {
                        $provider = mod_provider_of((string) $sub['share_url']) ?? 'unknown';
                        $rid = 's' . substr(sha1((string) $sub['share_url']), 0, 15);
                        repo_upsert([
                            'id' => $rid, 'title' => (string) $sub['title'], 'core_title' => (string) $sub['title'],
                            'type' => mod_type_guess((string) $sub['title']), 'provider' => $provider,
                            'share_url' => (string) $sub['share_url'], 'pwd' => $sub['pwd'],
                            'status' => 'unchecked', 'channel' => 'submission',
                            'desc' => (string) ($sub['note'] ?? ''), 'posted_at' => now_iso(),
                        ], 'submit');
                        db()->prepare("UPDATE submissions SET status = 'approved', reviewed_at = ?, resource_id = ? WHERE id = ?")
                            ->execute([now_iso(), $rid, $sub['id']]);
                    } else {
                        db()->prepare("UPDATE submissions SET status = 'rejected', reviewed_at = ? WHERE id = ?")
                            ->execute([now_iso(), $sub['id']]);
                    }
                    $n++;
                }
            }
            flash($n > 0 ? "已批量处理 {$n} 条" : '没有选中待审投稿');
            redirect('/admin.php?r=subs');


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
    'users'     => '用户管理',
    'reports'   => '举报处理',
    'bulk'      => '批量录入',
    'promo'     => '拉新任务',
    'promo_accounts' => '网盘账号',
    'settings'  => '设置',
];
$pending = (int) db()->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();

$nav = '';
foreach ($navItems as $k => $label) {
    $active = $route === $k;
    $cls = $active
        ? 'admin-nav-link admin-nav-active justify-between'
        : 'admin-nav-link justify-between';
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

            $box = $s['status'] === 'pending'
                ? '<input type="checkbox" name="ids[]" value="' . (int) $s['id'] . '" class="size-3.5 accent-blue-600">'
                : '';
            $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-3 py-3 align-top">' . $box . '</td>'
                . '<td class="px-4 py-3 align-top"><div class="text-[13px] font-medium text-neutral-900">' . e((string) $s['title']) . '</div>'
                . '<div class="mt-1 break-all font-mono text-[11px] text-neutral-400">' . e((string) $s['share_url']) . '</div>'
                . '<div class="mt-1 text-[11px] text-neutral-400">' . e((string) ($s['note'] ?? '')) . ' ' . e((string) ($s['contact'] ?? '')) . '</div></td>'
                . '<td class="px-4 py-3 align-top"><span class="rounded-full px-2 py-0.5 text-[11px] ring-1 ' . $riskCls . '">' . e($mod['level']) . '</span></td>'
                . '<td class="px-4 py-3 align-top font-mono text-[11px] text-neutral-400">' . e(substr((string) $s['created_at'], 0, 16)) . '</td>'
                . '<td class="px-4 py-3 align-top">' . $ops . '</td></tr>';
        }
        $modeHint = match (cfg_get('review_mode')) {
            'none' => '当前为「免审」：新投稿直接入库，本列表只留历史。',
            'low_auto' => '当前为「低风险自动通过」：只有命中敏感词的才会进这里。',
            default => '当前为「全部人工审核」：每条投稿都需要在这里处理。',
        };
        $bulkBar = $status === 'pending'
            ? '<div class="mt-3 flex flex-wrap items-center gap-2 rounded-lg bg-white p-3 shadow-sm">'
              . '<span class="text-xs text-neutral-500">勾选后批量处理：</span>'
              . '<button class="rounded-md bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100"'
              . ' type="submit" name="decision" value="approve">通过所选</button>'
              . '<button class="rounded-md bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100"'
              . ' type="submit" name="decision" value="reject">拒绝所选</button>'
              . '<span class="ml-auto text-[11px] text-neutral-400">' . e($modeHint) . '</span></div>'
            : '<p class="mt-3 text-[11px] text-neutral-400">' . e($modeHint) . '</p>';

        $body = '<div class="mb-4 flex gap-2">' . $tabs . '</div>'
            . '<form method="post"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="review_bulk">'
            . '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
            . '<thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white">'
            . '<tr><th class="w-10 px-3 py-2.5"></th><th class="px-4 py-2.5 font-medium">内容</th>'
            . '<th class="px-4 py-2.5 font-medium">风控</th>'
            . '<th class="px-4 py-2.5 font-medium">时间</th><th class="px-4 py-2.5 font-medium">操作</th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">暂无投稿</td></tr>')
            . '</tbody></table></div></form>'
            . $bulkBar;
        break;

    case 'res':
        $promoOn = cfg_get('promo_open') === '1';
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = repo_search(['q' => $q], $page, 20);
        $tr = '';
        foreach ($res['items'] as $r) {
            $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-4 py-3"><div class="text-[13px] font-medium text-neutral-900">' . e((string) $r['title']) . '</div>'
                . '<div class="mt-1 break-all font-mono text-[11px] text-neutral-400">' . e((string) $r['share_url']) . '</div></td>'
                . '<td class="px-4 py-3 whitespace-nowrap text-xs text-neutral-500">'
                . '<span class="inline-flex items-center gap-1"><i class="dot" style="background:' . e(ui_provider_color((string) $r['provider'])) . '"></i>'
                . e(ui_provider_name((string) $r['provider'])) . '</span>'
                . '<div class="mt-0.5 text-[11px] text-neutral-400">' . e(ui_type_name((string) $r['type'])) . '</div></td>'
                . '<td class="px-4 py-3 text-xs">' . ui_status((string) $r['status']) . '</td>'
                . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e(substr((string) ($r['posted_at'] ?? ''), 0, 10)) . '</td>'
                . '<td class="px-4 py-3 whitespace-nowrap">'
                . ($promoOn && $r['provider'] === 'quark'
                    ? '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                      . '<input type="hidden" name="action" value="promo_create"><input type="hidden" name="resource_id" value="' . e((string) $r['id']) . '">'
                      . '<button class="rounded-md bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-200 hover:bg-blue-100">拉新</button></form> '
                    : '')
                . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="hidden">'
                . '<input type="hidden" name="id" value="' . e((string) $r['id']) . '"><input type="hidden" name="q" value="' . e($q) . '">'
                . '<button class="rounded-md bg-white px-2.5 py-1 text-xs text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">下架</button></form></td></tr>';
        }
        $body = '<form class="mb-4 flex max-w-md items-center gap-2" method="get" action="/admin.php">'
            . '<input type="hidden" name="r" value="res">'
            . '<input class="input" name="q" value="' . e($q) . '" placeholder="按关键词搜索资源">'
            . '<button class="btn shrink-0" type="submit">搜索</button></form>'
            . '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
            . '<thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white">'
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
        $ghConfigured = (string) repo_conf('github_token', '') !== '';

        $body = '<div class="grid gap-5 lg:grid-cols-2">'
            . '<section class="rounded-lg bg-white p-5 shadow-sm">'
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
            . '<section class="rounded-lg bg-white p-5 shadow-sm">'
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
            . '<section class="mt-5 rounded-lg bg-white p-5 shadow-sm">'
            . '<h2 class="mb-3 text-sm font-semibold">最近日志</h2><table class="w-full text-left">'
            . ($logRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无</td></tr>') . '</table></section>';
        break;

    case 'promo':
        $counts = promo_task_counts();
        $quotaLeft = promo_quota_left();
        $quotaLimit = cfg_get_int('promo_daily_limit');
        $open = cfg_get('promo_open') === '1';
        $accs = promo_accounts(true);
        $qkAccs = array_values(array_filter($accs, fn($a) => $a['provider'] === 'quark'));

        $notice = '<div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">'
            . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . ($open ? '开' : '关') . '</div>'
            . '<div class="mt-0.5 text-xs text-neutral-400">拉新总开关</div></div>'
            . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . (int) ($counts['pending'] ?? 0) . '</div>'
            . '<div class="mt-0.5 text-xs text-neutral-400">待执行</div></div>'
            . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . (int) ($counts['done'] ?? 0) . '</div>'
            . '<div class="mt-0.5 text-xs text-neutral-400">已完成</div></div>'
            . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . (int) ($counts['failed'] ?? 0) . '</div>'
            . '<div class="mt-0.5 text-xs text-neutral-400">失败</div></div>'
            . '</div>';

        $warn = '';
        if (!$open) {
            $warn = '<div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">'
                . '拉新总开关是关的，任务可以创建但不会执行。去 <a class="underline" href="/admin.php?r=settings">设置 → 拉新</a> 打开。</div>';
        } elseif ($qkAccs === []) {
            $warn = '<div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">'
                . '还没有启用的夸克账号，转存会失败。去 <a class="underline" href="/admin.php?r=promo_accounts">网盘账号</a> 添加 Cookie。</div>';
        }

        $statMap = [
            'pending'    => ['待执行', 'bg-neutral-100 text-neutral-600'],
            'executing'  => ['执行中', 'bg-blue-50 text-blue-700'],
            'done'       => ['已完成', 'bg-emerald-50 text-emerald-700'],
            'failed'     => ['失败', 'bg-rose-50 text-rose-700'],
            'canceled'   => ['已取消', 'bg-neutral-100 text-neutral-500'],
        ];
        $rows = '';
        foreach (promo_task_list('', 100) as $t) {
            [$label, $cls] = $statMap[$t['status']] ?? [$t['status'], 'bg-neutral-100 text-neutral-600'];
            $ops = '';
            if (in_array($t['status'], ['pending', 'failed'], true)) {
                $ops = '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="promo_run"><input type="hidden" name="id" value="' . (int) $t['id'] . '">'
                    . '<button class="rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 ring-1 ring-blue-200 hover:bg-blue-100">执行</button></form>';
            }
            if ($t['status'] !== 'executing' && $t['status'] !== 'done') {
                $ops .= ' <form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="promo_cancel"><input type="hidden" name="id" value="' . (int) $t['id'] . '">'
                    . '<button class="rounded-md bg-white px-2 py-0.5 text-[11px] text-neutral-500 ring-1 ring-neutral-200 hover:bg-neutral-50">取消</button></form>';
            }
            if ($t['status'] === 'failed') {
                $ops .= ' <form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="promo_retry"><input type="hidden" name="id" value="' . (int) $t['id'] . '">'
                    . '<button class="rounded-md bg-white px-2 py-0.5 text-[11px] text-neutral-500 ring-1 ring-neutral-200 hover:bg-neutral-50">重排</button></form>';
            }
            $target = $t['target_url'] !== ''
                ? '<a class="break-all text-blue-600 hover:underline" href="' . e((string) $t['target_url']) . '" target="_blank" rel="noopener">' . e((string) $t['target_url']) . '</a>'
                    . ($t['target_pwd'] !== '' ? ' <span class="tag-pwd">' . e((string) $t['target_pwd']) . '</span>' : '')
                : '<span class="text-neutral-300">—</span>';
            $err = $t['error'] !== '' ? '<div class="mt-1 text-[11px] text-rose-600">' . e((string) $t['error']) . '</div>' : '';
            $rows .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-4 py-3"><div class="text-[13px] font-medium text-neutral-900">' . e(mb_substr((string) $t['title'], 0, 40)) . '</div>'
                . '<div class="mt-0.5 break-all font-mono text-[11px] text-neutral-400">' . e((string) $t['source_url']) . '</div>' . $err . '</td>'
                . '<td class="px-4 py-3 whitespace-nowrap"><span class="rounded-full px-2 py-0.5 text-[11px] ' . $cls . '">' . $label . '</span></td>'
                . '<td class="px-4 py-3">' . $target . '</td>'
                . '<td class="px-4 py-3 whitespace-nowrap font-mono text-[11px] text-neutral-400">' . e(substr((string) $t['created_at'], 0, 16)) . '</td>'
                . '<td class="px-4 py-3 whitespace-nowrap">' . $ops . '</td></tr>';
        }

        $body = $notice . $warn
            . '<div class="mb-4 flex flex-wrap items-center gap-2">'
            . '<form method="post"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="promo_run_all">'
            . '<button class="btn" type="submit"' . ($open ? '' : ' disabled') . '>批量执行待处理（最多 5 条）</button></form>'
            . '<span class="text-xs text-neutral-400">今日配额 ' . ($quotaLimit > 0 ? max(0, $quotaLeft) . ' / ' . $quotaLimit : '不限')
            . ' · 间隔 ' . cfg_get_int('promo_min_interval') . ' 秒 · 启用账号 ' . count($accs) . ' 个</span>'
            . '</div>'
            . '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
            . '<thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white">'
            . '<tr><th class="px-4 py-2.5 font-medium">资源</th><th class="px-4 py-2.5 font-medium">状态</th>'
            . '<th class="px-4 py-2.5 font-medium">新分享链接</th><th class="px-4 py-2.5 font-medium">创建</th>'
            . '<th class="px-4 py-2.5 font-medium">操作</th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="5" class="px-4 py-12 text-center text-sm text-neutral-400">还没有拉新任务。<br>去 <a class="text-blue-600 underline" href="/admin.php?r=res">资源管理</a> 点「拉新」创建。</td></tr>')
            . '</tbody></table></div>';
        break;

    case 'promo_accounts':
        $accRows = '';
        foreach (promo_accounts() as $a) {
            $accRows .= '<tr class="border-b border-neutral-100 last:border-0">'
                . '<td class="px-4 py-3 text-[13px] font-medium text-neutral-900">' . e((string) $a['label'] ?: '未命名') . '</td>'
                . '<td class="px-4 py-3"><span class="tag-gray">' . e(ui_provider_name((string) $a['provider'])) . '</span></td>'
                . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e(promo_mask_cookie((string) $a['cookie'])) . '</td>'
                . '<td class="px-4 py-3 text-xs">'
                . ((int) $a['enabled'] === 1
                    ? '<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700">启用</span>'
                    : '<span class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-500">停用</span>')
                . '</td>'
                . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e(substr((string) $a['last_used'], 0, 16) ?: '—') . '</td>'
                . '<td class="px-4 py-3 whitespace-nowrap">'
                . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="promo_account_toggle"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
                . '<button class="rounded-md bg-white px-2 py-0.5 text-[11px] text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">'
                . ((int) $a['enabled'] === 1 ? '停用' : '启用') . '</button></form></td></tr>';
        }

        $provOpts = '';
        foreach (UI_PROVIDERS as $pk => $pv) {
            $provOpts .= '<option value="' . e($pk) . '">' . e($pv[0]) . '</option>';
        }

        $body = '<div class="grid items-start gap-5 lg:grid-cols-[380px_1fr]">'
            . '<section class="rounded-lg bg-white p-5 shadow-sm">'
            . '<h2 class="text-sm font-semibold">添加 / 更新账号</h2>'
            . '<form method="post" class="mt-4 space-y-3"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="promo_account">'
            . '<input type="hidden" name="id" value="0">'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">平台</label>'
            . '<select class="input" name="provider">' . $provOpts . '</select></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">备注名</label>'
            . '<input class="input" name="label" placeholder="例如 夸克主号"></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">Cookie</label>'
            . '<textarea class="input h-auto py-2 font-mono text-[11px]" name="cookie" rows="4" required placeholder="浏览器登录后复制整条 Cookie"></textarea>'
            . '<p class="mt-1 text-[11px] text-neutral-400">等同账号凭证，不要在公共环境粘贴。当前存 SQLite 明文，务必收紧库文件权限。</p></div>'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">状态</label>'
            . '<select class="input" name="enabled"><option value="1">启用</option><option value="0">停用</option></select></div>'
            . '<button class="btn w-full" type="submit">保存账号</button></form></section>'
            . '<section class="overflow-hidden rounded-lg bg-white shadow-sm">'
            . '<table class="w-full text-left"><thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white">'
            . '<tr><th class="px-4 py-2.5 font-medium">备注</th><th class="px-4 py-2.5 font-medium">平台</th>'
            . '<th class="px-4 py-2.5 font-medium">Cookie</th><th class="px-4 py-2.5 font-medium">状态</th>'
            . '<th class="px-4 py-2.5 font-medium">最近使用</th><th class="px-4 py-2.5 font-medium"></th></tr></thead><tbody>'
            . ($accRows ?: '<tr><td colspan="6" class="px-4 py-12 text-center text-sm text-neutral-400">还没有账号。拉新转存必须先有夸克 Cookie。</td></tr>')
            . '</tbody></table></section></div>';
        break;

    case 'settings':
        $spec = cfg_spec();
        $body = '<div class="grid items-start gap-5 xl:grid-cols-[1fr_320px]">'
            . '<div>' . cfg_render_form() . '</div>'
            . '<aside class="space-y-5">'
            . '<section class="rounded-lg bg-white p-5 shadow-sm">'
            . '<h2 class="text-sm font-semibold">改管理员密码</h2>'
            . '<form method="post" class="mt-4 space-y-4"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="password">'
            . '<div><label class="mb-1 block text-[11px] text-neutral-500">新密码（≥8 位）</label>'
            . '<input class="input" name="new_password" type="password" minlength="8" required></div>'
            . '<button class="btn" type="submit">更新密码</button></form></section>'
            . '<section class="rounded-lg bg-white p-5 shadow-sm">'
            . '<h2 class="text-sm font-semibold">当前生效</h2>'
            . '<dl class="mt-3 space-y-1.5 text-[11px] text-neutral-500">'
            . '<div class="flex justify-between gap-2"><dt>审核模式</dt><dd class="font-medium text-neutral-700">'
            . e($spec['review_mode']['options'][cfg_get('review_mode')] ?? '-') . '</dd></div>'
            . '<div class="flex justify-between gap-2"><dt>开放投稿</dt><dd class="font-medium text-neutral-700">'
            . e($spec['submit_open']['options'][cfg_get('submit_open')] ?? '-') . '</dd></div>'
            . '<div class="flex justify-between gap-2"><dt>拉新</dt><dd class="font-medium text-neutral-700">'
            . e($spec['promo_open']['options'][cfg_get('promo_open')] ?? '-') . '</dd></div>'
            . '<div class="flex justify-between gap-2"><dt>每页条数</dt><dd class="font-medium text-neutral-700">'
            . e(cfg_get('search_page_size')) . '</dd></div>'
            . '</dl>'
            . '<h3 class="mb-2 mt-5 text-[11px] uppercase tracking-wide text-neutral-400">数据源</h3>'
            . '<p class="text-[11px] leading-relaxed text-neutral-500">仓库地址、代理、GitHub token 在 '
            . '<code class="rounded bg-neutral-100 px-1">src/config.local.php</code>，改完刷新即生效。</p>'
            . '</section></aside></div>';
        break;

    default:
        $s = repo_stats();
        $cards = '';
        foreach ([['资源总数', $s['total']], ['今日新增', $s['today']], ['待审投稿', $s['pending']], ['已下架', $s['hidden']]] as [$label, $value]) {
            $cards .= '<div class="kpi">'
                . '<div class="text-2xl font-semibold tabular-nums">' . number_format((int) $value) . '</div>'
                . '<div class="mt-0.5 text-xs text-neutral-400">' . $label . '</div></div>';
        }
        $typeRows = '';
        foreach ($s['by_type'] as $t) {
            $typeRows .= '<tr class="border-b border-gray-100 last:border-0"><td class="py-2 text-xs text-gray-600">'
                . e(ui_type_name((string) $t['type'])) . '</td><td class="py-2 text-right text-xs tabular-nums text-gray-500">'
                . number_format((int) $t['c']) . '</td></tr>';
        }
        $provRows = '';
        foreach ($s['by_provider'] as $p) {
            $provRows .= '<tr class="border-b border-gray-100 last:border-0"><td class="py-2 text-xs text-gray-600">'
                . '<span class="inline-flex items-center gap-1.5"><i class="dot" style="background:' . e(ui_provider_color((string) $p['provider'])) . '"></i>'
                . e(ui_provider_name((string) $p['provider'])) . '</span></td>'
                . '<td class="py-2 text-right text-xs tabular-nums text-gray-500">' . number_format((int) $p['c']) . '</td></tr>';
        }
        $body = '<div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">' . $cards . '</div>'
            . '<div class="grid items-start gap-5 lg:grid-cols-2">'
            . '<section class="rounded-lg bg-white p-5 shadow-sm"><h2 class="mb-2 text-sm font-semibold">按类型</h2>'
            . '<table class="w-full">' . ($typeRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无</td></tr>') . '</table></section>'
            . '<section class="rounded-lg bg-white p-5 shadow-sm"><h2 class="mb-2 text-sm font-semibold">按平台</h2>'
            . '<table class="w-full">' . ($provRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无</td></tr>') . '</table></section>'
            . '</div>';
        break;
}

$extraBody = admin_extra_body($route);
if ($extraBody !== '') {
    $body = $extraBody;
}
if ($route === 'dashboard') {
    $body = admin_dashboard_extra() . '<div class="mb-2 text-xs uppercase tracking-wide text-neutral-400">库存概览</div>' . $body;
}

$flashHtml = $flash
    ? '<div class="mb-5 rounded-lg px-4 py-3 text-sm ' . ($flash['type'] === 'err'
        ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
        : 'bg-blue-50 text-blue-700 ring-1 ring-blue-100') . '">' . e($flash['msg']) . '</div>'
    : '';
$adminName = e((string) $admin['name']);
$csrfField = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
$routeTitle = $navItems[$route] ?? '概览';

echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$routeTitle} · 盘搜后台</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="min-h-screen bg-gray-50">
<div class="flex min-h-screen">
  <aside class="hidden w-56 shrink-0 flex-col bg-slate-800 px-3 py-5 sm:flex">
    <a href="/" class="mb-6 flex items-center gap-2 px-2" title="返回前台">
      <span class="grid size-8 place-items-center rounded-lg bg-blue-600 text-sm font-semibold text-white">盘</span>
      <span><span class="block text-[13px] font-semibold leading-4 text-white">盘搜</span>
      <span class="block text-[10px] text-slate-400">管理后台</span></span>
    </a>
    <nav class="flex flex-col gap-0.5">{$nav}</nav>
    <div class="mt-auto flex items-center justify-between border-t border-white/10 px-2 pt-3 text-xs text-slate-400">
      <span>{$adminName}</span>
      <form method="post">{$csrfField}<input type="hidden" name="action" value="logout">
        <button class="transition hover:text-white" type="submit">退出</button></form>
    </div>
  </aside>
  <main class="min-w-0 flex-1 px-4 py-6 sm:px-8">
    <div class="mb-4 flex gap-1 overflow-x-auto rounded-lg bg-slate-800 p-1.5 sm:hidden">{$nav}</div>
    <div class="mb-5 flex items-center justify-between">
      <h1 class="text-lg font-semibold tracking-tight text-gray-900">{$routeTitle}</h1>
      <a href="/" class="text-xs text-gray-400 transition hover:text-blue-600">查看前台 →</a>
    </div>
    {$flashHtml}{$body}
  </main>
</div>
</body></html>
HTML;
