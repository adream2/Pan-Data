<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/moderation.php';

session_name((string) cfg('security.session_name', 'pansou_sid'));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

if (!schema_ready()) {
    schema_migrate();
}

// 路由：支持 /search 这种伪静态，也支持 ?r=search
$path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$path = preg_replace('#^index\.php$#', '', $path) ?? '';
$route = (string) ($_GET['r'] ?? ($path === '' ? 'home' : $path));
$route = preg_replace('#[^a-z_]#', '', $route) ?: 'home';
$siteName = setting('site_name', (string) cfg('site_name'));

function render_card(array $row): string
{
    $title = e($row['title']);
    $meta = [];
    if ($row['year']) {
        $meta[] = (int) $row['year'] . '年';
    }
    if ($row['season']) {
        $meta[] = '第' . (int) $row['season'] . '季';
    }
    if ($row['episode']) {
        $meta[] = (!empty($row['complete']) ? '全' : '更新至') . (int) $row['episode'] . '集';
    }
    if ((int) $row['sources'] > 1) {
        $meta[] = (int) $row['sources'] . ' 个来源';
    }
    $status = $row['status'] === 'valid' ? '<i class="dot ok"></i>有效'
        : ($row['status'] === 'invalid' ? '<i class="dot bad"></i>失效' : '<i class="dot"></i>未巡检');
    $pwd = $row['pwd'] ? '<span class="pwd">码 ' . e($row['pwd']) . '</span>' : '';
    $date = $row['posted_at'] ? e(substr((string) $row['posted_at'], 0, 10)) : '';

    return '<a class="card" href="/detail?id=' . e($row['id']) . '">'
        . '<div class="card-h"><span class="tag">' . e($row['provider']) . '</span>'
        . '<span class="status">' . $status . '</span></div>'
        . '<div class="card-t">' . $title . '</div>'
        . '<div class="card-m">' . e(implode(' · ', $meta)) . '</div>'
        . '<div class="card-f">' . $pwd . '<span class="muted">' . $date . '</span></div>'
        . '</a>';
}

function pager(int $page, int $pages, array $query): string
{
    if ($pages <= 1) {
        return '';
    }
    $out = '<div class="pager">';
    $mk = function (int $p, string $label, bool $active = false) use ($query) {
        $query['page'] = $p;
        $cls = $active ? ' class="active"' : '';
        return '<a' . $cls . ' href="/search?' . e(http_build_query($query)) . '">' . $label . '</a>';
    };
    if ($page > 1) {
        $out .= $mk($page - 1, '上一页');
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        $out .= $mk($i, (string) $i, $i === $page);
    }
    if ($page < $pages) {
        $out .= $mk($page + 1, '下一页');
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
            'year'     => (string) ($_GET['year'] ?? ''),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = repo_search($filters, $page, (int) cfg('page_size', 20));
        $facets = repo_facets();

        $chips = '';
        foreach ($facets['types'] as $t) {
            $active = $filters['type'] === $t['type'] ? ' active' : '';
            $qs = array_merge($filters, ['type' => $t['type']]);
            unset($qs['page']);
            $chips .= '<a class="chip' . $active . '" href="/search?' . e(http_build_query(array_filter($qs, fn($v, $k) => $v !== '' || $k === 'type', ARRAY_FILTER_USE_BOTH)))
                . '">' . e($t['type']) . ' <b>' . (int) $t['c'] . '</b></a>';
        }
        $provChips = '';
        foreach ($facets['providers'] as $p) {
            $active = $filters['provider'] === $p['provider'] ? ' active' : '';
            $qs = array_merge($filters, ['provider' => $p['provider']]);
            unset($qs['page']);
            $provChips .= '<a class="chip' . $active . '" href="/search?' . e(http_build_query(array_filter($qs, fn($v) => $v !== '')))
                . '">' . e($p['provider']) . ' <b>' . (int) $p['c'] . '</b></a>';
        }

        $cards = '';
        foreach ($res['items'] as $row) {
            $cards .= render_card($row);
        }
        if ($cards === '') {
            $cards = '<div class="empty">没有找到匹配的资源。换个关键词，或者 <a href="/submit">投稿</a> 一条。</div>';
        }
        $content = '<div class="page">'
            . '<div class="filters"><div class="frow"><span class="flabel">类型</span>' . $chips . '</div>'
            . '<div class="frow"><span class="flabel">平台</span><a class="chip' . ($filters['provider'] === '' ? ' active' : '') . '" href="/search?'
            . e(http_build_query(array_filter(['q' => $q]))) . '">全部</a>' . $provChips . '</div></div>'
            . '<div class="result-head">' . ($q !== '' ? '「' . e($q) . '」' : '全部资源')
            . ' 共 <b>' . number_format($res['total']) . '</b> 条</div>'
            . '<div class="grid">' . $cards . '</div>'
            . pager($res['page'], $res['pages'], array_filter($filters, fn($v) => $v !== '') + ['page' => $res['page']])
            . '</div>';
        $title = $q !== '' ? $q . ' - ' . $siteName : '搜索 - ' . $siteName;
        break;

    case 'detail':
        $row = repo_get((string) ($_GET['id'] ?? ''));
        if ($row === null) {
            http_response_code(404);
            $content = '<div class="empty">资源不存在或已下架。<a href="/">回首页</a></div>';
            $title = '404';
            break;
        }
        $pwd = $row['pwd'] ? '<span class="pwd big">提取码 ' . e($row['pwd']) . '</span>' : '';
        $mirrors = '';
        foreach (($row['mirrors'] ?? []) as $m) {
            $mirrors .= '<li><span class="tag">' . e((string) ($m['provider'] ?? '')) . '</span>'
                . '<a href="' . e((string) ($m['share_url'] ?? '#')) . '" target="_blank" rel="nofollow noopener">' . e((string) ($m['share_url'] ?? '')) . '</a>'
                . (!empty($m['pwd']) ? '<span class="pwd">码 ' . e((string) $m['pwd']) . '</span>' : '')
                . '</li>';
        }
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
        $metaBits[] = '平台 ' . $row['provider'];
        $metaBits[] = '类型 ' . $row['type'];
        if ($row['channel']) {
            $metaBits[] = '来源 ' . $row['channel'];
        }

        $related = repo_search(['q' => mb_substr((string) $row['core_title'], 0, 12)], 1, 6)['items'];
        $relCards = '';
        foreach ($related as $rr) {
            if ($rr['id'] !== $row['id']) {
                $relCards .= render_card($rr);
            }
        }

        $content = '<div class="detail">'
            . '<h1>' . e($row['title']) . '</h1>'
            . '<div class="meta-row">' . e(implode(' · ', $metaBits)) . '</div>'
            . '<div class="linkbox">'
            . '<div class="linkline"><a class="btn" href="' . e($row['share_url']) . '" target="_blank" rel="nofollow noopener">打开网盘链接</a>' . $pwd . '</div>'
            . '<div class="rawurl">' . e($row['share_url']) . '</div>'
            . '</div>'
            . ($mirrors !== '' ? '<h3>备用链接（' . count($row['mirrors']) . '）</h3><ul class="mirrors">' . $mirrors . '</ul>' : '')
            . ($row['desc'] !== '' ? '<h3>简介</h3><p class="desc">' . nl2br(e($row['desc'])) . '</p>' : '')
            . ($row['post_url'] ? '<p class="muted">原帖：<a href="' . e($row['post_url']) . '" target="_blank" rel="nofollow noopener">' . e($row['post_url']) . '</a></p>' : '')
            . ($relCards !== '' ? '<h3>相关资源</h3><div class="grid">' . $relCards . '</div>' : '')
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
                    $error = '只支持主流网盘分享链接（夸克/百度/阿里/迅雷/UC/123 等）';
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
        $content = '<div class="submitbox">'
            . ($done
                ? '<div class="ok-box"><h2>提交成功</h2><p>审核通过后会出现在搜索结果里。</p><a class="btn" href="/">回首页</a></div>'
                : '<h1>投稿资源</h1><p class="muted">只收主流网盘分享链接，审核后入库。</p>'
                . ($error !== '' ? '<div class="flash err">' . e($error) . '</div>' : '')
                . '<form method="post" class="form">'
                . '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                . '<label>标题<input name="title" required maxlength="120" placeholder="例如：Python 从入门到实战 全 40 讲"></label>'
                . '<label>分享链接<input name="share_url" required maxlength="500" placeholder="https://pan.quark.cn/s/xxxx"></label>'
                . '<label>提取码（可选）<input name="pwd" maxlength="8" placeholder="4 位码，没有就留空"></label>'
                . '<label>补充说明（可选）<textarea name="note" rows="3" maxlength="300" placeholder="集数、清晰度、是否完结"></textarea></label>'
                . '<label>联系方式（可选）<input name="contact" maxlength="80" placeholder="TG / 邮箱，方便回访"></label>'
                . '<button class="btn" type="submit">提交审核</button>'
                . '</form>')
            . '</div>';
        $title = '投稿 - ' . $siteName;
        break;

    case 'api_search':
        $res = repo_search([
            'q'        => (string) ($_GET['q'] ?? ''),
            'type'     => (string) ($_GET['type'] ?? ''),
            'provider' => (string) ($_GET['provider'] ?? ''),
        ], max(1, (int) ($_GET['page'] ?? 1)), (int) cfg('page_size', 20));
        json_out($res);
        // no break

    default:
        $latest = repo_search([], 1, 24)['items'];
        $facets = repo_facets();
        $cards = '';
        foreach ($latest as $row) {
            $cards .= render_card($row);
        }
        $chips = '';
        foreach (array_slice($facets['types'], 0, 8) as $t) {
            $chips .= '<a class="chip" href="/search?type=' . e($t['type']) . '">' . e($t['type']) . ' <b>' . (int) $t['c'] . '</b></a>';
        }
        $stats = repo_stats();
        $content = '<section class="hero"><h1>找资源，先搜这里</h1>'
            . '<p class="muted">' . number_format((int) $stats['total']) . ' 条网盘资源 · 覆盖 ' . count($facets['providers']) . ' 个网盘 · 每天自动更新</p>'
            . '<form action="/search" method="get" class="heroform"><input type="search" name="q" placeholder="课程 / 影视 / 软件 / 电子书" autofocus><button>搜索</button></form>'
            . '<div class="chips">' . $chips . '</div></section>'
            . '<div class="section-h">最新收录</div><div class="grid">' . $cards . '</div>';
        $title = $siteName . ' - ' . cfg('site_slogan');
        break;
}

view('layout', ['content' => $content, 'title' => (string) $title]);
