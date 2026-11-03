<?php
declare(strict_types=1);

/**
 * 后台扩展模块：用户管理 / 网盘账号 / 举报处理 / 批量录入 / 概览增强
 * 独立成文件，避免 admin.php 越写越长。
 */

function admin_extra_actions(string $action): bool
{
    switch ($action) {
        case 'user_toggle':
            db()->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'banned' ELSE 'active' END WHERE id = ?")
                ->execute([(int) ($_POST['id'] ?? 0)]);
            flash('用户状态已更新');
            redirect('/admin.php?r=users');
        case 'user_apikey':
            api_key_for((int) ($_POST['id'] ?? 0));
            flash('已生成新 API Key（90 天有效）');
            redirect('/admin.php?r=users');
        case 'user_reset':
            $pw = (string) ($_POST['password'] ?? '');
            if (strlen($pw) < 6) {
                flash('密码至少 6 位', 'err');
            } else {
                db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                    ->execute([password_hash($pw, PASSWORD_DEFAULT), (int) ($_POST['id'] ?? 0)]);
                flash('密码已重置');
            }
            redirect('/admin.php?r=users');
        case 'account_save':
            $provider = (string) ($_POST['provider'] ?? 'quark');
            $label = trim((string) ($_POST['label'] ?? ''));
            $cookie = trim((string) ($_POST['cookie'] ?? ''));
            $id = (int) ($_POST['id'] ?? 0);
            if ($cookie === '') {
                flash('Cookie 不能为空', 'err');
            } elseif ($id > 0) {
                db()->prepare('UPDATE pan_accounts SET provider = ?, label = ?, cookie = ? WHERE id = ?')
                    ->execute([$provider, $label, $cookie, $id]);
                flash('账号已更新');
            } else {
                db()->prepare('INSERT INTO pan_accounts(provider, label, cookie, created_at) VALUES(?,?,?,?)')
                    ->execute([$provider, $label, $cookie, now_iso()]);
                flash('账号已添加');
            }
            redirect('/admin.php?r=accounts');
        case 'account_toggle':
            db()->prepare('UPDATE pan_accounts SET enabled = 1 - enabled WHERE id = ?')
                ->execute([(int) ($_POST['id'] ?? 0)]);
            flash('状态已切换');
            redirect('/admin.php?r=accounts');
        case 'account_delete':
            db()->prepare('DELETE FROM pan_accounts WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            flash('账号已删除');
            redirect('/admin.php?r=accounts');
        case 'report_handle':
            $status = (string) ($_POST['status'] ?? 'rejected') === 'resolved' ? 'resolved' : 'rejected';
            db()->prepare('UPDATE reports SET status = ? WHERE id = ?')
                ->execute([$status, (int) ($_POST['id'] ?? 0)]);
            $rid = (string) ($_POST['resource_id'] ?? '');
            if ($status === 'resolved' && $rid !== '') {
                db()->prepare("UPDATE resources SET status = 'invalid', updated_at = ? WHERE id = ?")
                    ->execute([now_iso(), $rid]);
            }
            flash($status === 'resolved' ? '已标记失效并处理' : '已忽略该举报');
            redirect('/admin.php?r=reports');
        case 'bulk_import':
            [$ok, $skip, $bad] = bulk_import_lines((string) ($_POST['lines'] ?? ''));
            repo_log('bulk_import', true, "批量录入 成功{$ok} 跳过{$skip} 失败{$bad}",
                ['ok' => $ok, 'skip' => $skip, 'bad' => $bad]);
            flash("批量录入：成功 {$ok}，重复跳过 {$skip}，格式错误 {$bad}");
            redirect('/admin.php?r=bulk');
    }
    return false;
}

/** @return array{0:int,1:int,2:int} [成功, 跳过, 失败] */
function bulk_import_lines(string $raw): array
{
    $ok = $skip = $bad = 0;
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('#(https?://\S+)#', $line, $m)) {
            $bad++;
            continue;
        }
        $url = rtrim($m[1], ')|,');
        $provider = mod_provider_of($url);
        if ($provider === null) {
            $bad++;
            continue;
        }
        $title = trim(str_replace($m[1], '', $line), " \t|,，　");
        $title = $title !== '' ? $title : $url;
        $pwd = preg_match('/[?&]pwd=([0-9a-zA-Z]{2,8})/', $url, $pm) ? $pm[1] : null;

        $dup = db()->prepare('SELECT id FROM resources WHERE share_url = ?');
        $dup->execute([$url]);
        if ($dup->fetchColumn() !== false) {
            $skip++;
            continue;
        }
        repo_upsert([
            'id'         => 'b' . substr(sha1($url), 0, 15),
            'title'      => mb_substr($title, 0, 120),
            'core_title' => mb_substr($title, 0, 60),
            'type'       => 'other',
            'provider'   => $provider,
            'share_url'  => $url,
            'pwd'        => $pwd,
            'status'     => 'unchecked',
            'channel'    => 'bulk',
            'posted_at'  => now_iso(),
        ], 'bulk');
        $ok++;
    }
    return [$ok, $skip, $bad];
}

function admin_extra_body(string $route): string
{
    switch ($route) {
        case 'users':
            $rows = db()->query('SELECT u.*,
                (SELECT COUNT(*) FROM submissions s WHERE s.contact = u.username) subs,
                (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) favs
                FROM users u ORDER BY u.id DESC LIMIT 200')->fetchAll();
            $tr = '';
            foreach ($rows as $u) {
                $active = $u['status'] === 'active';
                $badge = $active
                    ? '<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700 ring-1 ring-emerald-200">正常</span>'
                    : '<span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] text-rose-700 ring-1 ring-rose-200">封禁</span>';
                $api = $u['api_expires_at'] ? substr((string) $u['api_expires_at'], 0, 10) : '—';
                $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                    . '<td class="px-4 py-3"><div class="text-[13px] font-medium">' . e((string) $u['username']) . '</div>'
                    . '<div class="font-mono text-[11px] text-neutral-400">#' . (int) $u['id'] . '</div></td>'
                    . '<td class="px-4 py-3">' . $badge . '</td>'
                    . '<td class="px-4 py-3 text-xs tabular-nums text-neutral-500">' . (int) $u['subs'] . '</td>'
                    . '<td class="px-4 py-3 text-xs tabular-nums text-neutral-500">' . (int) $u['favs'] . '</td>'
                    . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e($api) . '</td>'
                    . '<td class="px-4 py-3"><div class="flex flex-wrap gap-1">'
                    . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="user_toggle"><input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                    . '<button class="rounded-md bg-white px-2 py-1 text-[11px] text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">'
                    . ($active ? '封禁' : '解封') . '</button></form>'
                    . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="user_apikey"><input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                    . '<button class="rounded-md bg-white px-2 py-1 text-[11px] text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">发 Key</button></form>'
                    . '</div></td></tr>';
            }
            return '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
                . '<thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white"><tr>'
                . '<th class="px-4 py-2.5 font-medium">用户</th><th class="px-4 py-2.5 font-medium">状态</th>'
                . '<th class="px-4 py-2.5 font-medium">投稿</th><th class="px-4 py-2.5 font-medium">收藏</th>'
                . '<th class="px-4 py-2.5 font-medium">API 到期</th><th class="px-4 py-2.5 font-medium">操作</th>'
                . '</tr></thead><tbody>'
                . ($tr ?: '<tr><td colspan="6" class="px-4 py-10 text-center text-sm text-neutral-400">还没有用户</td></tr>')
                . '</tbody></table></div>';

        case 'accounts':
            $rows = db()->query('SELECT * FROM pan_accounts ORDER BY id DESC')->fetchAll();
            $tr = '';
            foreach ($rows as $a) {
                $masked = mb_substr((string) $a['cookie'], 0, 18) . '…（' . mb_strlen((string) $a['cookie']) . ' 字符）';
                $enabled = (int) $a['enabled'] === 1;
                $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                    . '<td class="px-4 py-3"><span class="rounded-md bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium">' . e((string) $a['provider']) . '</span></td>'
                    . '<td class="px-4 py-3 text-[13px]">' . e((string) $a['label']) . '</td>'
                    . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e($masked) . '</td>'
                    . '<td class="px-4 py-3 text-xs">' . ($enabled
                        ? '<span class="text-emerald-600">启用</span>' : '<span class="text-neutral-400">停用</span>') . '</td>'
                    . '<td class="px-4 py-3"><div class="flex gap-1">'
                    . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="account_toggle"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
                    . '<button class="rounded-md bg-white px-2 py-1 text-[11px] ring-1 ring-neutral-200 hover:bg-neutral-50">' . ($enabled ? '停用' : '启用') . '</button></form>'
                    . '<form method="post" class="inline" onsubmit="return confirm(\'确认删除该账号？\')"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="account_delete"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
                    . '<button class="rounded-md bg-rose-50 px-2 py-1 text-[11px] text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100">删除</button></form>'
                    . '</div></td></tr>';
            }
            return '<div class="grid gap-5 lg:grid-cols-2">'
                . '<section class="rounded-lg bg-white p-5 shadow-sm">'
                . '<h2 class="text-sm font-semibold">添加网盘账号</h2>'
                . '<p class="mt-1 text-xs text-neutral-500">Cookie 用于自动转存/分享，明文存库，建议只在可信内网使用。</p>'
                . '<form method="post" class="mt-3 space-y-3"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="account_save">'
                . '<div class="grid grid-cols-2 gap-3">'
                . '<div><label class="mb-1 block text-[11px] text-neutral-500">平台</label>'
                . '<select class="input" name="provider"><option value="quark">夸克</option><option value="baidu">百度</option>'
                . '<option value="aliyun">阿里云盘</option><option value="xunlei">迅雷</option><option value="uc">UC</option></select></div>'
                . '<div><label class="mb-1 block text-[11px] text-neutral-500">备注名</label>'
                . '<input class="input" name="label" placeholder="主号 / 备用"></div></div>'
                . '<div><label class="mb-1 block text-[11px] text-neutral-500">Cookie</label>'
                . '<textarea class="input h-24 py-2 font-mono text-[11px]" name="cookie" placeholder="粘贴浏览器整条 Cookie"></textarea></div>'
                . '<button class="btn" type="submit">保存账号</button></form></section>'
                . '<section class="rounded-lg bg-white p-5 shadow-sm">'
                . '<h2 class="mb-3 text-sm font-semibold">已配置账号</h2>'
                . '<table class="w-full text-left">' . ($tr ?: '<tr><td class="py-8 text-center text-xs text-neutral-400">还没有账号</td></tr>')
                . '</table></section></div>';

        case 'reports':
            $rows = db()->query("SELECT r.*, s.title FROM reports r LEFT JOIN resources s ON s.id = r.resource_id
                WHERE r.status = 'open' ORDER BY r.id DESC LIMIT 100")->fetchAll();
            $tr = '';
            foreach ($rows as $r) {
                $tr .= '<tr class="border-b border-neutral-100 last:border-0">'
                    . '<td class="px-4 py-3"><div class="text-[13px] font-medium">' . e((string) ($r['title'] ?? $r['resource_id'])) . '</div>'
                    . '<div class="font-mono text-[11px] text-neutral-400">' . e((string) $r['resource_id']) . '</div></td>'
                    . '<td class="px-4 py-3 text-xs text-neutral-500">' . e((string) $r['kind']) . '</td>'
                    . '<td class="px-4 py-3 text-[11px] text-neutral-400">' . e((string) ($r['note'] ?? '')) . '</td>'
                    . '<td class="px-4 py-3 font-mono text-[11px] text-neutral-400">' . e(substr((string) $r['created_at'], 0, 16)) . '</td>'
                    . '<td class="px-4 py-3"><div class="flex gap-1">'
                    . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="report_handle"><input type="hidden" name="status" value="resolved">'
                    . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="resource_id" value="' . e((string) $r['resource_id']) . '">'
                    . '<button class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100">失效并下架</button></form>'
                    . '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="report_handle"><input type="hidden" name="status" value="rejected">'
                    . '<input type="hidden" name="id" value="' . (int) $r['id'] . '">'
                    . '<button class="rounded-md bg-white px-2 py-1 text-[11px] text-neutral-600 ring-1 ring-neutral-200 hover:bg-neutral-50">忽略</button></form>'
                    . '</div></td></tr>';
            }
            $open = count($rows);
            return '<div class="mb-4 flex items-center gap-2 text-xs text-neutral-500">'
                . '<span class="rounded-full bg-amber-50 px-2.5 py-1 text-amber-700 ring-1 ring-amber-200">待处理 ' . $open . '</span>'
                . '<span>处理「失效」会自动把资源标记为 invalid</span></div>'
                . '<div class="overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left">'
                . '<thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white"><tr>'
                . '<th class="px-4 py-2.5 font-medium">资源</th><th class="px-4 py-2.5 font-medium">类型</th>'
                . '<th class="px-4 py-2.5 font-medium">备注</th><th class="px-4 py-2.5 font-medium">时间</th>'
                . '<th class="px-4 py-2.5 font-medium">处理</th></tr></thead><tbody>'
                . ($tr ?: '<tr><td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">没有待处理举报</td></tr>')
                . '</tbody></table></div>';

        case 'bulk':
            return '<div class="grid gap-5 lg:grid-cols-3">'
                . '<section class="rounded-lg bg-white p-5 shadow-sm lg:col-span-2">'
                . '<h2 class="text-sm font-semibold">批量录入</h2>'
                . '<p class="mt-1 text-xs text-neutral-500">每行一条：<code class="rounded bg-neutral-100 px-1">标题 链接</code> 或只贴链接。重复链接自动跳过。</p>'
                . '<form method="post" class="mt-3"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="bulk_import">'
                . '<textarea class="input h-72 py-3 font-mono text-[12px]" name="lines" placeholder="Python 全栈课 https://pan.quark.cn/s/xxxx&#10;https://pan.baidu.com/s/yyyy pwd=abcd"></textarea>'
                . '<button class="btn mt-3" type="submit">开始导入</button></form></section>'
                . '<aside class="rounded-xl bg-white p-5 text-xs leading-relaxed text-neutral-500 ring-1 ring-neutral-200/80">'
                . '<h3 class="mb-2 text-[11px] uppercase tracking-wide text-neutral-400">说明</h3>'
                . '<p>· 自动识别平台，未知平台记为格式错误</p>'
                . '<p class="mt-1.5">· 支持 <code class="rounded bg-neutral-100 px-1">?pwd=</code> 提取码</p>'
                . '<p class="mt-1.5">· 与已有链接重复的直接跳过</p>'
                . '<p class="mt-1.5">· 录入后状态为「未巡检」，建议跑一次巡检</p>'
                . '</aside></div>';
    }
    return '';
}

/** 概览页增强：今日/昨日环比 + 热搜 + 热门资源 */
function admin_dashboard_extra(): string
{
    $today = gmdate('Y-m-d');
    $yesterday = gmdate('Y-m-d', time() - 86400);
    $todayNew = (int) db()->query("SELECT COUNT(*) FROM resources WHERE substr(created_at,1,10) = '{$today}'")->fetchColumn();
    $yNew = (int) db()->query("SELECT COUNT(*) FROM resources WHERE substr(created_at,1,10) = '{$yesterday}'")->fetchColumn();
    $todaySearch = (int) db()->query("SELECT COALESCE(SUM(count),0) FROM search_logs WHERE day = '{$today}'")->fetchColumn();
    $todayClick = (int) db()->query("SELECT COALESCE(SUM(count),0) FROM clicks WHERE day = '{$today}'")->fetchColumn();
    $openReports = (int) db()->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();

    // 环比在导入当天必然是 -100%，纯噪音；改成"近 7 日"更有信息量
    $weekNew = (int) db()->query("SELECT COUNT(*) FROM resources WHERE date(created_at) >= date('now','-6 days')")->fetchColumn();
    $weekHint = ' · 近 7 日 ' . number_format($weekNew);

    $kpi = '<div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $todayNew . '</div>'
        . '<div class="mt-0.5 text-xs text-neutral-400">今日入库' . $weekHint . '</div></div>'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $todaySearch . '</div>'
        . '<div class="mt-0.5 text-xs text-neutral-400">今日搜索</div></div>'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $todayClick . '</div>'
        . '<div class="mt-0.5 text-xs text-neutral-400">今日详情点击</div></div>'
        . '<div class="kpi"><div class="text-2xl font-semibold tabular-nums">' . $openReports . '</div>'
        . '<div class="mt-0.5 text-xs text-neutral-400">待处理举报</div></div></div>';

    $wordRows = '';
    foreach (top_searches(7, 8) as $w) {
        $wordRows .= '<tr class="border-b border-neutral-100 last:border-0"><td class="py-2 text-xs">' . e((string) $w['q'])
            . '</td><td class="py-2 text-right text-xs tabular-nums text-neutral-500">' . (int) $w['c'] . '</td></tr>';
    }
    $resRows = '';
    foreach (top_resources(7, 8) as $r) {
        $resRows .= '<tr class="border-b border-neutral-100 last:border-0"><td class="py-2 text-xs">'
            . e(mb_substr((string) ($r['title'] ?? $r['resource_id']), 0, 24)) . '</td>'
            . '<td class="py-2 text-right text-xs tabular-nums text-neutral-500">' . (int) $r['hits'] . '</td></tr>';
    }

    return $kpi . '<div class="mt-5 grid items-start gap-5 lg:grid-cols-2">'
        . '<section class="rounded-lg bg-white p-5 shadow-sm"><h2 class="mb-2 text-sm font-semibold">近 7 天热搜</h2>'
        . '<table class="w-full">' . ($wordRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无数据</td></tr>') . '</table></section>'
        . '<section class="rounded-lg bg-white p-5 shadow-sm"><h2 class="mb-2 text-sm font-semibold">近 7 天热门资源</h2>'
        . '<table class="w-full">' . ($resRows ?: '<tr><td class="py-6 text-center text-xs text-neutral-400">暂无数据</td></tr>') . '</table></section>'
        . '</div>';
}
