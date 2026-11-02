<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/moderation.php';
require_once __DIR__ . '/../src/user.php';

session_name((string) cfg('security.session_name', 'pansou_sid'));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

if (!schema_ready()) {
    schema_migrate();
}
schema_upgrade();
$currentUser = user_current();

// 详情页的举报是直接 POST 回详情页的，在这里处理掉
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'report') {
    csrf_check();
    $rid = (string) ($_POST['resource_id'] ?? '');
    if ($rid !== '' && rate_limit('report', 10, 3600)) {
        report_create($rid, (string) ($_POST['kind'] ?? 'invalid'), (string) ($_POST['note'] ?? ''),
            $currentUser !== null ? (int) $currentUser['id'] : null);
        flash('举报已提交，感谢反馈');
    } else {
        flash('举报太频繁，稍后再试', 'err');
    }
    redirect('/detail?id=' . urlencode($rid));
}

// 路由：支持 /search 伪静态，也支持 ?r=search
$path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$path = preg_replace('#^index\.php$#', '', $path) ?? '';
$route = (string) ($_GET['r'] ?? ($path === '' ? 'home' : $path));
$route = preg_replace('#[^a-z_]#', '', $route) ?: 'home';

$siteName = setting('site_name', (string) cfg('site_name'));

function status_pill(?string $status): string
{
    [$text, $dot, $cls] = match ($status) {
        'valid'   => ['有效', 'bg-emerald-500', 'text-emerald-700'],
        'invalid' => ['失效', 'bg-rose-500', 'text-rose-600'],
        default   => ['未巡检', 'bg-neutral-300', 'text-neutral-400'],
    };
    return '<span class="inline-flex items-center gap-1 text-xs ' . $cls . '">'
        . '<i class="size-1.5 rounded-full ' . $dot . '"></i>' . $text . '</span>';
}

function render_card(array $row): string
{
    $meta = [];
    if ($row['year']) {
        $meta[] = (int) $row['year'] . '年';
    }
    if ($row['season']) {
        $meta[] = '第' . (int) $row['season'] . '季';
    }
    if ($row['episode']) {
        $meta[] = (!empty($row['complete']) ? '全' : '更至') . (int) $row['episode'] . '集';
    }
    if ((int) $row['sources'] > 1) {
        $meta[] = (int) $row['sources'] . ' 个来源';
    }
    $typeLabel = ['movie' => '影视', 'course' => '课程', 'ebook' => '电子书', 'software' => '软件', 'music' => '音乐'][$row['type']] ?? '其它';
    $pwd = $row['pwd']
        ? '<span class="rounded bg-amber-50 px-1.5 py-0.5 font-mono text-[11px] text-amber-700">码 ' . e($row['pwd']) . '</span>'
        : '<span></span>';
    $date = $row['posted_at'] ? e(substr((string) $row['posted_at'], 0, 10)) : '';

    return '<a href="/detail?id=' . e($row['id']) . '"'
        . ' class="group flex flex-col gap-2 rounded-xl bg-white p-4 ring-1 ring-neutral-200/80 transition hover:ring-neutral-300 hover:shadow-[0_4px_16px_-8px_rgba(0,0,0,.14)]">'
        . '<div class="flex items-center justify-between">'
        . '<span class="flex items-center gap-1.5">'
        . '<span class="rounded-md bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium text-neutral-600">' . e($row['provider']) . '</span>'
        . '<span class="text-[11px] text-neutral-400">' . e($typeLabel) . '</span>'
        . '</span>' . status_pill($row['status']) . '</div>'
        . '<div class="clamp-2 text-[13.5px] font-medium leading-snug text-neutral-900">' . e($row['title']) . '</div>'
        . '<div class="text-[11px] text-neutral-400">' . e(implode(' · ', $meta)) . '</div>'
        . '<div class="mt-auto flex items-center justify-between pt-1">' . $pwd
        . '<span class="font-mono text-[11px] text-neutral-300">' . $date . '</span></div>'
        . '</a>';
}

function pager(int $page, int $pages, array $query): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = function (int $p, string $label, bool $active = false) use ($query): string {
        $query['page'] = $p;
        $cls = $active
            ? 'bg-neutral-900 text-white ring-neutral-900'
            : 'bg-white text-neutral-600 ring-neutral-200 hover:bg-neutral-50 hover:text-neutral-900';
        return '<a href="/search?' . e(http_build_query($query)) . '"'
            . ' class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-xs font-medium ring-1 transition ' . $cls . '">'
            . $label . '</a>';
    };
    $out = '<div class="mt-8 flex flex-wrap items-center justify-center gap-1.5">';
    if ($page > 1) {
        $out .= $link($page - 1, '上一页');
    }
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $out .= $link($i, (string) $i, $i === $page);
    }
    if ($page < $pages) {
        $out .= $link($page + 1, '下一页');
    }
    return $out . '</div>';
}

switch ($route) {
    case 'search':
        $q = trim((string) ($_GET['q'] ?? ''));
        $filters = [
            'q'        => $q,
            'type'     => (string) ($_GET['type'] ?? ''),
            'provider' => (string) ($_GET['provider'] ?? ''),
        ];
        $res = repo_search($filters, max(1, (int) ($_GET['page'] ?? 1)), (int) cfg('page_size', 20));
        $facets = repo_facets();
        if ($q !== '') {
            search_track($q);
        }

        $chip = function (string $key, string $value, string $label, int $count, string $current) use ($q): string {
            $active = $current === $value;
            $qs = array_filter(['q' => $q, $key => $value], fn($v) => $v !== '');
            return '<a href="/search?' . e(http_build_query($qs)) . '" class="chip ' . ($active ? 'chip-active' : '') . '">'
                . e($label) . ' <span class="' . ($active ? 'text-white/60' : 'text-neutral-400') . '">' . $count . '</span></a>';
        };

        $typeChips = $chip('type', '', '全部', array_sum(array_column($facets['types'], 'c')), $filters['type']);
        foreach ($facets['types'] as $t) {
            $typeChips .= $chip('type', (string) $t['type'], (string) $t['type'], (int) $t['c'], $filters['type']);
        }
        $provChips = $chip('provider', '', '全部平台', array_sum(array_column($facets['providers'], 'c')), $filters['provider']);
        foreach (array_slice($facets['providers'], 0, 8) as $p) {
            $provChips .= $chip('provider', (string) $p['provider'], (string) $p['provider'], (int) $p['c'], $filters['provider']);
        }

        $cards = '';
        foreach ($res['items'] as $row) {
            $cards .= render_card($row);
        }
        if ($cards === '') {
            $cards = '<div class="col-span-full rounded-xl bg-white px-6 py-16 text-center ring-1 ring-neutral-200/80">'
                . '<p class="text-sm text-neutral-500">没有找到匹配的资源</p>'
                . '<p class="mt-2 text-xs text-neutral-400">换个关键词，或者 <a class="text-brand-600 hover:underline" href="/submit">投稿</a> 一条</p></div>';
        }

        $content = '<div class="pt-6">'
            . '<div class="rounded-xl bg-white p-4 ring-1 ring-neutral-200/80">'
            . '<div class="flex flex-wrap items-center gap-2"><span class="w-10 shrink-0 text-xs text-neutral-400">类型</span>' . $typeChips . '</div>'
            . '<div class="mt-3 flex flex-wrap items-center gap-2"><span class="w-10 shrink-0 text-xs text-neutral-400">平台</span>' . $provChips . '</div>'
            . '</div>'
            . '<div class="mt-6 flex items-baseline justify-between">'
            . '<h1 class="text-[15px] font-medium text-neutral-900">' . ($q !== '' ? '「' . e($q) . '」' : '全部资源') . '</h1>'
            . '<span class="text-xs tabular-nums text-neutral-400">共 ' . number_format($res['total']) . ' 条</span></div>'
            . '<div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">' . $cards . '</div>'
            . pager($res['page'], $res['pages'], array_filter($filters, fn($v) => $v !== ''))
            . '</div>';
        $title = $q !== '' ? $q . ' - ' . $siteName : '搜索 - ' . $siteName;
        break;

    case 'detail':
        $row = repo_get((string) ($_GET['id'] ?? ''));
        if ($row === null) {
            http_response_code(404);
            $content = '<div class="py-24 text-center"><p class="text-sm text-neutral-500">资源不存在或已下架</p>'
                . '<a class="mt-4 inline-block text-sm text-brand-600 hover:underline" href="/">回首页</a></div>';
            $title = '404';
            break;
        }
        click_track((string) $row['id']);
        $isFav = $currentUser !== null && user_is_favorite((int) $currentUser['id'], (string) $row['id']);
        $metaBits = [];
        if ($row['year']) {
            $metaBits[] = (int) $row['year'] . '年';
        }
        if ($row['season']) {
            $metaBits[] = '第' . (int) $row['season'] . '季';
        }
        if ($row['episode']) {
            $metaBits[] = (!empty($row['complete']) ? '全集 ' : '更新至 ') . (int) $row['episode'] . '集';
        }
        $metaBits[] = (string) $row['provider'];
        if ($row['channel']) {
            $metaBits[] = '来源 ' . $row['channel'];
        }

        // 来源可能有几十个，详情页只展示前 6 个，其余给出计数（避免页面被撑爆）
        $allMirrors = $row['mirrors'] ?? [];
        $mirrorRows = '';
        foreach (array_slice($allMirrors, 0, 6) as $m) {
            $mirrorRows .= '<li class="flex flex-wrap items-center gap-2 text-sm">'
                . '<span class="rounded-md bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium text-neutral-600">' . e((string) ($m['provider'] ?? '')) . '</span>'
                . '<a class="break-all text-brand-600 hover:underline" href="' . e((string) ($m['share_url'] ?? '#')) . '" target="_blank" rel="nofollow noopener">' . e((string) ($m['share_url'] ?? '')) . '</a>'
                . (!empty($m['pwd']) ? '<span class="rounded bg-amber-50 px-1.5 py-0.5 font-mono text-[11px] text-amber-700">码 ' . e((string) $m['pwd']) . '</span>' : '')
                . '</li>';
        }
        if (count($allMirrors) > 6) {
            $mirrorRows .= '<li class="text-[11px] text-neutral-400">还有 ' . (count($allMirrors) - 6) . ' 个来源未展开</li>';
        }

        $related = repo_search(['q' => mb_substr((string) $row['core_title'], 0, 12)], 1, 5)['items'];
        $relCards = '';
        foreach ($related as $rr) {
            if ($rr['id'] !== $row['id']) {
                $relCards .= render_card($rr);
            }
        }

        $content = '<div class="mx-auto max-w-3xl pt-8">'
            . '<a href="javascript:history.back()" class="inline-flex items-center gap-1 text-xs text-neutral-400 hover:text-neutral-600">'
            . '<svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l-5 5 5 5"/></svg>返回</a>'
            . '<h1 class="mt-4 text-xl font-semibold leading-snug tracking-tight text-neutral-900">' . e($row['title']) . '</h1>'
            . '<div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-neutral-400">'
            . e(implode(' · ', $metaBits)) . ' · ' . status_pill($row['status']) . '</div>'
            . '<div class="mt-6 rounded-xl bg-white p-5 ring-1 ring-neutral-200/80">'
            . '<div class="flex flex-wrap items-center gap-3">'
            . '<a class="btn" href="' . e($row['share_url']) . '" target="_blank" rel="nofollow noopener">打开网盘链接'
            . '<svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 13 13 7M8 7h5v5"/></svg></a>'
            . ($row['pwd'] ? '<span class="rounded-lg bg-amber-50 px-3 py-2 font-mono text-sm text-amber-700 ring-1 ring-amber-200">提取码 ' . e($row['pwd']) . '</span>' : '')
            . '</div>'
            . '<p class="mt-3 break-all font-mono text-[11px] text-neutral-400">' . e($row['share_url']) . '</p>'
            . '<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-4">'
            . ($currentUser !== null
                ? '<form method="post" action="/user?r=favs"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                  . '<input type="hidden" name="action" value="favorite"><input type="hidden" name="resource_id" value="' . e((string) $row['id']) . '">'
                  . '<button class="btn-soft" type="submit">' . ($isFav ? '已收藏' : '收藏') . '</button></form>'
                : '<a class="btn-soft" href="/user?r=login">登录后收藏</a>')
            . '<span class="text-[11px] text-neutral-400">浏览 ' . number_format(click_count((string) $row['id'])) . ' 次</span>'
            . '<form method="post" action="/detail?id=' . e((string) $row['id']) . '" class="ml-auto flex items-center gap-2">'
            . '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="report">'
            . '<input type="hidden" name="resource_id" value="' . e((string) $row['id']) . '">'
            . '<select name="kind" class="h-8 rounded-md bg-white px-2 text-xs ring-1 ring-neutral-200 focus:outline-none">'
            . '<option value="invalid">链接失效</option><option value="wrong">内容不符</option>'
            . '<option value="illegal">违规内容</option><option value="other">其它</option></select>'
            . '<input name="note" maxlength="120" placeholder="补充说明（可选）" class="h-8 w-36 rounded-md bg-white px-2 text-xs ring-1 ring-neutral-200 placeholder:text-neutral-400 focus:outline-none">'
            . '<button class="btn-soft" type="submit">举报</button></form>'
            . '</div></div>'
            . ($mirrorRows !== '' ? '<h2 class="mb-3 mt-8 text-xs font-medium uppercase tracking-wide text-neutral-400">备用链接 ' . count($row['mirrors']) . '</h2>'
                . '<ul class="space-y-2 rounded-xl bg-white p-4 ring-1 ring-neutral-200/80">' . $mirrorRows . '</ul>' : '')
            . ($row['desc'] !== '' ? '<h2 class="mb-2 mt-8 text-xs font-medium uppercase tracking-wide text-neutral-400">简介</h2>'
                . '<p class="text-sm leading-relaxed text-neutral-600">' . nl2br(e($row['desc'])) . '</p>' : '')
            . ($row['post_url'] ? '<p class="mt-6 text-xs text-neutral-400">原帖 <a class="hover:underline" href="' . e($row['post_url']) . '" target="_blank" rel="nofollow noopener">' . e($row['post_url']) . '</a></p>' : '')
            . ($relCards !== '' ? '<h2 class="mb-3 mt-10 text-xs font-medium uppercase tracking-wide text-neutral-400">相关资源</h2>'
                . '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">' . $relCards . '</div>' : '')
            . '</div>';
        $title = $row['title'] . ' - ' . $siteName;
        break;

    case 'submit':
        $done = false;
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            if (setting('submit_open', '1') !== '1') {
                $error = '投稿暂时关闭';
            } elseif (!rate_limit('submit', (int) cfg('security.submit_per_hour', 6), 3600)) {
                $error = '投稿太频繁，休息一下再来';
            } else {
                $title = trim((string) ($_POST['title'] ?? ''));
                $shareUrl = trim((string) ($_POST['share_url'] ?? ''));
                $pwd = mod_clean_pwd($_POST['pwd'] ?? null);
                $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300);
                $contact = mb_substr(trim((string) ($_POST['contact'] ?? '')), 0, 80);
                $provider = mod_provider_of($shareUrl);

                if (mb_strlen($title) < 2) {
                    $error = '标题至少 2 个字';
                } elseif ($provider === null) {
                    $error = '只支持主流网盘分享链接（夸克 / 百度 / 阿里 / 迅雷 / UC / 123 等）';
                } else {
                    $mod = mod_check($title, $note);
                    $dup = db()->prepare('SELECT id FROM resources WHERE share_url = ?');
                    $dup->execute([$shareUrl]);
                    if ($dup->fetchColumn() !== false) {
                        $error = '这条链接已经在库里了';
                    } elseif ($mod['level'] === 'blocked') {
                        $error = '内容不符合收录要求：' . implode('；', $mod['reasons']);
                    } else {
                        db()->prepare('INSERT INTO submissions(title, share_url, pwd, note, contact, ip, status, created_at)
                            VALUES(?,?,?,?,?,?,?,?)')
                            ->execute([$title, $shareUrl, $pwd, $note, $contact,
                                (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'pending', now_iso()]);
                        $done = true;
                    }
                }
            }
        }

        $field = 'mt-1.5 block h-10 w-full rounded-lg border-0 bg-white px-3.5 text-sm ring-1 ring-neutral-200 placeholder:text-neutral-400 focus:ring-2 focus:ring-brand-500 focus:outline-none';
        $label = 'block text-xs font-medium text-neutral-500';

        $form = '<form method="post" class="mt-6 space-y-5 rounded-xl bg-white p-6 ring-1 ring-neutral-200/80">'
            . '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . ($error !== '' ? '<div class="rounded-lg bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700 ring-1 ring-rose-200">' . e($error) . '</div>' : '')
            . '<div><label class="' . $label . '">标题</label><input class="' . $field . '" name="title" required maxlength="120" placeholder="例如：Python 从入门到实战 全 40 讲"></div>'
            . '<div><label class="' . $label . '">分享链接</label><input class="' . $field . '" name="share_url" required maxlength="500" placeholder="https://pan.quark.cn/s/xxxx"></div>'
            . '<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">'
            . '<div><label class="' . $label . '">提取码（可选）</label><input class="' . $field . '" name="pwd" maxlength="8" placeholder="4 位码"></div>'
            . '<div><label class="' . $label . '">联系方式（可选）</label><input class="' . $field . '" name="contact" maxlength="80" placeholder="TG / 邮箱"></div></div>'
            . '<div><label class="' . $label . '">补充说明（可选）</label><textarea class="mt-1.5 block w-full rounded-lg border-0 bg-white px-3.5 py-2.5 text-sm ring-1 ring-neutral-200 placeholder:text-neutral-400 focus:ring-2 focus:ring-brand-500 focus:outline-none" name="note" rows="3" maxlength="300" placeholder="集数、清晰度、是否完结"></textarea></div>'
            . '<button class="btn w-full" type="submit">提交审核</button>'
            . '</form>';

        $content = '<div class="mx-auto max-w-xl pt-10">'
            . ($done
                ? '<div class="rounded-xl bg-white p-10 text-center ring-1 ring-neutral-200/80">'
                  . '<div class="mx-auto grid size-11 place-items-center rounded-full bg-emerald-50 text-emerald-600">'
                  . '<svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 10 3.5 3.5L15 7"/></svg></div>'
                  . '<h1 class="mt-4 text-base font-semibold">提交成功</h1>'
                  . '<p class="mt-1 text-sm text-neutral-500">审核通过后会出现在搜索结果里</p>'
                  . '<a class="btn mt-6" href="/">回首页</a></div>'
                : '<h1 class="text-xl font-semibold tracking-tight">投稿资源</h1>'
                  . '<p class="mt-1.5 text-sm text-neutral-500">只收主流网盘分享链接，人工审核后入库。</p>' . $form)
            . '</div>';
        $title = '投稿 - ' . $siteName;
        break;

    case 'api_search':
        json_out(repo_search([
            'q'        => (string) ($_GET['q'] ?? ''),
            'type'     => (string) ($_GET['type'] ?? ''),
            'provider' => (string) ($_GET['provider'] ?? ''),
        ], max(1, (int) ($_GET['page'] ?? 1)), (int) cfg('page_size', 20)));
        // no break

    default:
        $latest = repo_search([], 1, 24)['items'];
        $facets = repo_facets();
        $stats = repo_stats();

        $cards = '';
        foreach ($latest as $row) {
            $cards .= render_card($row);
        }
        $chips = '';
        foreach (array_slice($facets['types'], 0, 7) as $t) {
            $chips .= '<a href="/search?type=' . e($t['type']) . '" class="chip">' . e($t['type'])
                . ' <span class="text-neutral-400">' . (int) $t['c'] . '</span></a>';
        }

        $hotWords = top_searches(7, 8);
        $hotHtml = '';
        foreach ($hotWords as $h) {
            $hotHtml .= '<a href="/search?q=' . urlencode((string) $h['q']) . '" class="chip">' . e((string) $h['q']) . '</a>';
        }
        $content = '<section class="pb-10 pt-14 text-center">'
            . '<h1 class="text-3xl font-semibold tracking-tight text-neutral-900 sm:text-4xl">找资源，先搜这里</h1>'
            . '<p class="mt-3 text-sm text-neutral-500">'
            . number_format((int) $stats['total']) . ' 条网盘资源 · 覆盖 ' . count($facets['providers']) . ' 个网盘 · 每天自动更新</p>'
            . '<form action="/search" method="get" class="mx-auto mt-7 flex max-w-xl items-center gap-2">'
            . '<div class="relative flex-1">'
            . '<svg class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="9" r="6"/><path d="m13.5 13.5 3.5 3.5"/></svg>'
            . '<input type="search" name="q" autofocus placeholder="课程 / 影视 / 软件 / 电子书"'
            . ' class="h-12 w-full rounded-full border-0 bg-white pl-11 pr-4 text-[15px] shadow-sm ring-1 ring-neutral-200 placeholder:text-neutral-400 focus:ring-2 focus:ring-brand-500 focus:outline-none"></div>'
            . '<button class="btn h-12 rounded-full px-6" type="submit">搜索</button>'
            . '</form>'
            . '<div class="mt-6 flex flex-wrap items-center justify-center gap-2">' . $chips . '</div>'
            . ($hotHtml !== '' ? '<div class="mt-3 flex flex-wrap items-center justify-center gap-1.5 text-[11px] text-neutral-400">'
                . '<span class="mr-1">大家在搜</span>' . $hotHtml . '</div>' : '')
            . '</section>'
            . '<div class="mb-4 flex items-baseline justify-between">'
            . '<h2 class="text-sm font-medium text-neutral-900">最新收录</h2>'
            . '<a href="/search" class="text-xs text-neutral-400 hover:text-neutral-600">查看全部</a></div>'
            . '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">' . $cards . '</div>';
        $title = $siteName . ' - ' . cfg('site_slogan');
        break;
}

view('layout', ['content' => $content, 'title' => (string) $title]);
