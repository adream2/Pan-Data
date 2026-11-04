<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/moderation.php';
require_once __DIR__ . '/../src/user.php';
require_once __DIR__ . '/../src/health.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/promo.php';
require_once __DIR__ . '/../src/seo.php';

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
        $rep = report_create($rid, (string) ($_POST['kind'] ?? 'invalid'), (string) ($_POST['note'] ?? ''),
            $currentUser !== null ? (int) $currentUser['id'] : null);
        // 自动切换到备用来源是对用户有实际意义的结果，要说出来，
        // 否则他刷新页面看到换了平台会以为是 bug
        if (!empty($rep['switched'])) {
            flash('该链接已失效，已自动切换到备用来源');
        } elseif (!empty($rep['auto_invalid'])) {
            flash('感谢反馈，该资源已标记失效');
        } else {
            flash('举报已提交，感谢反馈');
        }
    } else {
        flash('举报太频繁，稍后再试', 'err');
    }
    redirect(seo_detail_path($rid));
}

// 路由：优先用路径（/movie、/s/xxx），其次 ?r= 兼容旧链接
$path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$path = preg_replace('#^index\.php$#', '', $path) ?? '';
$segments = $path === '' ? [] : explode('/', $path);
$seg0 = (string) ($segments[0] ?? '');

// /s/{id} -> 详情（SEO 友好路径）
$pathId = '';
if ($seg0 === 's' && isset($segments[1]) && $segments[1] !== '') {
    $pathId = (string) $segments[1];
}

// /movie、/duanju 等分类落地页
$landingSlug = seo_landing($seg0) !== null ? $seg0 : '';

// /c/{channel} 频道页（频道名只留字母数字下划线，避免奇怪 URL）
$channelSlug = '';
if ($seg0 === 'c' && isset($segments[1]) && $segments[1] !== '') {
    $channelSlug = repo_channel_slug((string) $segments[1]);
}

if ($pathId !== '') {
    $route = 'detail';
    $_GET['id'] = $pathId;
} elseif ($channelSlug !== '') {
    $route = 'channel';
} elseif ($landingSlug !== '') {
    $route = 'landing';
} else {
    $route = (string) ($_GET['r'] ?? ($seg0 === '' ? 'home' : $seg0));
    $route = preg_replace('#[^a-z_]#', '', $route) ?: 'home';
}

// 未知路径一律 404。否则 /任意字符串 都会返回 200 + 首页内容，
// 等于给搜索引擎敞开无限 URL（软 404 是最典型的收录陷阱）
$knownRoutes = ['home', 'search', 'detail', 'submit', 'updates', 'api_search', 'landing', 'channel', 'channels', 'notfound'];
if (!in_array($route, $knownRoutes, true)) {
    http_response_code(404);
    $route = 'notfound';
}

// 旧详情链接 301 到 /s/{id}：同一内容只有一个 URL，避免权重分散
if ($route === 'detail' && $pathId === '' && ($rid = (string) ($_GET['id'] ?? '')) !== '') {
    $qs = array_filter(['page' => $_GET['page'] ?? '']);
    if ($qs === []) {
        header('Location: ' . seo_detail_path($rid), true, 301);
        exit;
    }
}

$siteName = setting('site_name', (string) cfg('site_name'));

switch ($route) {
    case 'notfound':
        $content = '<div class="py-24 text-center">'
            . '<p class="text-3xl font-semibold text-gray-300">404</p>'
            . '<p class="mt-3 text-sm text-gray-500">这个页面不存在，或者资源已经下架了</p>'
            . '<div class="mt-6 flex flex-wrap items-center justify-center gap-2">'
            . '<a class="btn-primary inline-flex" href="/">回首页</a>'
            . '<a class="btn-soft inline-flex" href="/search">浏览全部资源</a></div></div>';
        $title = '404 页面不存在 - ' . $siteName;
        $description = '页面不存在。';
        break;

    case 'channels':
        $channels = repo_channels(200);
        $content = render_view('channels', [
            'channels'       => $channels,
            'totalResources' => repo_stats()['total'],
        ]);
        $title = '来源频道列表 - ' . count($channels) . ' 个公开频道 - ' . $siteName;
        $description = '本站内容来自 ' . count($channels) . ' 个公开 Telegram 频道，合计收录 '
            . number_format(array_sum(array_map(fn($c) => (int) $c['resources'], $channels)))
            . ' 条网盘分享。每个频道可查看收录量、平台构成与更新节奏。';
        break;

    case 'channel':
        $ch = repo_channel($channelSlug);
        if ($ch === null) {
            http_response_code(404);
            $route = 'notfound';
            $content = '';
            $title = '404 - ' . $siteName;
            $description = '频道不存在。';
            break;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $channelTypes = repo_channel_types($channelSlug);
        $channelProviders = repo_channel_providers($channelSlug);
        $res = repo_channel_search($channelSlug, $page, cfg_get_int('search_page_size') ?: 20);

        $headHtml = render_view('channel_head', [
            'ch'               => $ch,
            'channelTypes'     => $channelTypes,
            'channelProviders' => $channelProviders,
            'total'            => $res['total'],
        ]);
        $listHtml = render_view('list', [
            'q'        => '',
            'type'     => '',
            'provider' => '',
            'res'      => $res,
            'facets'   => repo_facets(),
            'stats'    => repo_stats(),
            'isHome'   => false,
            'hotWords' => [],
            'landing'  => null,
            'basePath' => '/c/' . $channelSlug,
            'rootPath' => false,
            'bareList' => true,
            'bareLabel' => '@' . $channelSlug . ' 的资源',
        ]);
        $footHtml = render_view('channel_foot', [
            'allChannels' => repo_channels(200),
            'current'     => $channelSlug,
        ]);
        $content = $headHtml . $listHtml . $footHtml;

        $title = '@' . $channelSlug . ' 频道资源 - ' . number_format((int) $res['total']) . ' 条网盘分享 - ' . $siteName;
        $description = '@' . $channelSlug . ' 频道收录的 ' . number_format((int) $res['total']) . ' 条网盘分享，覆盖 '
            . (int) $ch['platforms'] . ' 个平台，内容以'
            . ui_type_name((string) (repo_channel_lead_type($channelTypes, (int) $res['total'])['type'] ?? 'other')) . '为主，最近更新 '
            . ui_rel_time((string) $ch['latest']) . '。';
        break;

    case 'landing':
        // 分类落地页：/movie /duanju /course /ebook /software /music
        $spec = seo_landing($landingSlug);
        if ($spec === null) {
            http_response_code(404);
            $content = '<div class="py-24 text-center"><p class="text-sm text-gray-500">页面不存在</p>'
                . '<a class="btn-primary mt-5 inline-flex" href="/">回首页</a></div>';
            $title = '404 - ' . $siteName;
            $description = '页面不存在。';
            $route = 'notfound';
            break;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $filters = $spec['filter'];
        if (cfg_get('show_invalid') !== '1') {
            $filters['hide_invalid'] = true;
        }
        if (cfg_get('show_unchecked') !== '1') {
            $filters['only_valid'] = true;
        }
        $res = repo_search($filters, $page, cfg_get_int('search_page_size') ?: 20);
        $facets = repo_facets((array) $spec['filter']);
        $stats = repo_stats();
        $content = render_view('list', [
            'q'        => '',
            'type'     => (string) ($spec['filter']['type'] ?? ''),
            'provider' => '',
            'res'      => $res,
            'facets'   => $facets,
            'stats'    => $stats,
            'isHome'   => false,
            'hotWords' => [],
            'landing'  => $spec,
            'basePath' => '/' . $landingSlug,
            'rootPath' => false,
        ]);
        $title = $spec['title'] . ($page > 1 ? ' - 第 ' . $page . ' 页' : '') . ' - ' . $siteName;
        $description = $spec['desc'];
        break;

    case 'updates':
        $days = (int) ($_GET['days'] ?? 7);
        if (!in_array($days, [7, 14, 30], true)) {
            $days = 7;
        }
        $rows = repo_updates($days, 500);
        $totalToday = repo_today_updates();
        $content = render_view('updates', [
            'rows' => $rows,
            'days' => $days,
            'totalToday' => $totalToday,
        ]);
        $title = '追更日历 - 最近更新的剧集 - ' . $siteName;
        $description = '按日期查看最近 ' . $days . ' 天更新的剧集与短剧，标注最新集数、来源数与平台数，追剧不用逐个翻。';
        break;

    case 'detail':
        $row = repo_get((string) ($_GET['id'] ?? ''));
        if ($row === null) {
            http_response_code(404);
            $content = '<div class="py-24 text-center">'
                . '<p class="text-sm text-gray-500">资源不存在或已下架</p>'
                . '<a class="btn-primary mt-5 inline-flex" href="/">回首页</a></div>';
            $title = '404 - ' . $siteName;
            $description = '资源不存在或已下架。';
            $route = 'notfound';
            break;
        }
        click_track((string) $row['id']);
        $isFav = $currentUser !== null && user_is_favorite((int) $currentUser['id'], (string) $row['id']);
        $versions = repo_versions((string) $row['core_title'], 12);
        $related = repo_search(['q' => mb_substr((string) $row['core_title'], 0, 12)], 1, 7)['items'];
        // 同频道的其他资源：详情页独有的内容块，也是给频道路径导流
        $channelMore = repo_channel_more((string) $row['channel'], (string) $row['id'], 6);
        $content = render_view('detail', [
            'row'         => $row,
            'versions'    => $versions,
            'related'     => $related,
            'channelMore' => $channelMore,
            'isFav'       => $isFav,
            'currentUser' => $currentUser,
        ]);
        // 标题带上平台与「网盘」意图词：用户搜的是「xx 网盘」，不是「xx」
        $cleanTitle = ui_clean_title((string) $row['title']);
        // title 太长会被搜索引擎截断，控制在 34 字以内；h1 仍然展示完整标题
        $titleShort = mb_strlen($cleanTitle) > 34 ? mb_substr($cleanTitle, 0, 34) . '…' : $cleanTitle;
        $title = $titleShort . ' 网盘资源 - ' . ui_provider_name((string) $row['provider']) . ' - ' . $siteName;
        $descParts = [$cleanTitle, ui_type_name((string) $row['type']) . '类网盘分享'];
        if ((string) $row['year'] !== '') {
            $descParts[] = $row['year'] . ' 年';
        }
        if ((int) ($row['episode'] ?? 0) > 0) {
            $descParts[] = ((int) $row['complete'] === 1 ? '全' : '更新至 ') . (int) $row['episode'] . ' 集';
        }
        $descParts[] = '平台 ' . ui_provider_name((string) $row['provider']);
        if (count($versions) > 1) {
            $descParts[] = '共 ' . count($versions) . ' 个来源可切换';
        }
        $description = mb_substr(implode('，', $descParts), 0, 150)
            . '。当前链接状态：' . (['valid' => '有效', 'invalid' => '已失效', 'unchecked' => '待巡检'][(string) $row['status']] ?? '未知') . '。';
        $ogType = 'article';
        break;

    case 'submit':
        $done = false;
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            $needLogin = cfg_get_bool('submit_require_login');
            $maxPerHour = cfg_get_int('submit_max_per_hour');
            if (cfg_get('submit_open') !== '1') {
                $error = '投稿暂时关闭';
            } elseif ($needLogin && $currentUser === null) {
                $error = '投稿需要先登录';
                redirect('/user.php?r=login');
            } elseif ($maxPerHour > 0 && !rate_limit('submit', $maxPerHour, 3600)) {
                $error = '投稿太频繁，休息一下再来';
            } else {
                $t = trim((string) ($_POST['title'] ?? ''));
                $shareUrl = trim((string) ($_POST['share_url'] ?? ''));
                $pwd = mod_clean_pwd($_POST['pwd'] ?? null);
                $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300);
                $contact = mb_substr(trim((string) ($_POST['contact'] ?? '')), 0, 80);
                $provider = mod_provider_of($shareUrl);

                if (mb_strlen($t) < 2) {
                    $error = '标题至少 2 个字';
                } elseif ($provider === null) {
                    $error = '只支持主流网盘分享链接（夸克 / 百度 / 阿里 / 迅雷 / UC / 123 等）';
                } else {
                    $mod = mod_check($t, $note);
                    $dup = cfg_get_bool('submit_dup_skip')
                        ? db()->prepare('SELECT id FROM resources WHERE share_url = ?') : null;
                    $isDup = $dup !== null && $dup->execute([$shareUrl]) && $dup->fetchColumn() !== false;
                    if ($isDup) {
                        $error = '这条链接已经在库里了';
                    } elseif ($mod['level'] === 'blocked') {
                        $error = '内容不符合收录要求：' . implode('；', $mod['reasons']);
                    } else {
                        $mode = cfg_get('review_mode');
                        $allowed = array_filter(array_map('trim', explode(',', cfg_get('review_low_auto_targets'))));
                        $lowOk = $mode === 'low_auto' && $mod['level'] === 'low'
                            && ($allowed === [] || in_array(mod_type_guess($t), $allowed, true));
                        if ($mode === 'none' || $lowOk) {
                            $rid = 's' . substr(sha1($shareUrl), 0, 15);
                            repo_upsert([
                                'id' => $rid, 'title' => $t, 'core_title' => $t,
                                'type' => mod_type_guess($t), 'provider' => $provider,
                                'share_url' => $shareUrl, 'pwd' => $pwd,
                                'status' => 'unchecked', 'channel' => 'submission',
                                'desc' => $note, 'posted_at' => now_iso(),
                            ], 'submit');
                            db()->prepare('INSERT INTO submissions(title, share_url, pwd, note, contact, ip, status, created_at, reviewed_at, resource_id)
                                VALUES(?,?,?,?,?,?,?,?,?,?)')
                                ->execute([$t, $shareUrl, $pwd, $note, $contact,
                                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'approved', now_iso(), now_iso(), $rid]);
                        } else {
                            db()->prepare('INSERT INTO submissions(title, share_url, pwd, note, contact, ip, status, created_at)
                                VALUES(?,?,?,?,?,?,?,?)')
                                ->execute([$t, $shareUrl, $pwd, $note, $contact,
                                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'pending', now_iso()]);
                        }
                        $done = true;
                    }
                }
            }
        }
        $content = render_view('submit', [
            'done' => $done,
            'error' => $error,
            'closed' => cfg_get('submit_open') !== '1',
            'needLogin' => cfg_get_bool('submit_require_login') && $currentUser === null,
            'reviewMode' => cfg_get('review_mode'),
        ]);
        $title = '投稿 - ' . $siteName;
        break;

    case 'api_search':
        json_out(repo_search([
            'q'        => (string) ($_GET['q'] ?? ''),
            'type'     => (string) ($_GET['type'] ?? ''),
            'provider' => (string) ($_GET['provider'] ?? ''),
        ], max(1, (int) ($_GET['page'] ?? 1)), cfg_get_int('search_page_size') ?: 20));
        // no break

    case 'search':
    default:
        $q        = trim((string) ($_GET['q'] ?? ''));
        $type     = (string) ($_GET['type'] ?? '');
        $provider = (string) ($_GET['provider'] ?? '');
        $page     = max(1, (int) ($_GET['page'] ?? 1));

        $sort = (string) ($_GET['sort'] ?? 'new');
        $filters = ['q' => $q, 'type' => $type, 'provider' => $provider, 'sort' => $sort];
        if (cfg_get('show_invalid') !== '1') {
            $filters['hide_invalid'] = true;
        }
        if (cfg_get('show_unchecked') !== '1') {
            $filters['only_valid'] = true;
        }
        $res = repo_search($filters, $page, cfg_get_int('search_page_size') ?: 20);
        $facets = repo_facets(['type' => $type]);
        $stats = repo_stats();
        if ($q !== '') {
            search_track($q);
        }

        $isHome = ($q === '' && $type === '' && $provider === '');
        // 首页与 /search 同内容：前者是入口，后者是「全部资源」页，用 H1 区分
        $content = render_view('list', [
            'q'        => $q,
            'type'     => $type,
            'provider' => $provider,
            'res'      => $res,
            'facets'   => $facets,
            'stats'    => $stats,
            'isHome'   => $isHome,
            'hotWords' => $isHome ? top_searches(cfg_get_int('search_hot_words') ?: 7, 10) : [],
            'landing'  => null,
            'basePath' => '/search',
            'rootPath' => ($segments === []),
        ]);

        $siteDesc = (string) setting('site_desc', (string) cfg('site_slogan'));
        if ($q !== '') {
            $title = $q . ' 网盘资源搜索 - ' . $res['total'] . ' 条结果 - ' . $siteName;
            $description = '关于「' . $q . '」的网盘分享共 ' . number_format((int) $res['total'])
                . ' 条，覆盖夸克、百度、阿里、迅雷、UC 等平台，支持按平台、类型与集数筛选。';
        } elseif ($isHome) {
            $title = $siteName . ' - ' . $siteDesc;
            $description = $siteName . '：' . $siteDesc . '。已收录 ' . number_format((int) $stats['total'])
                . ' 条资源，覆盖影视、短剧、课程、电子书、软件、音乐，自动巡检链接有效性。';
        } else {
            $titleText = ($type !== '' ? ui_type_name($type) : '全部')
                . '资源' . ($provider !== '' ? ' · ' . ui_provider_name($provider) : '') . ' - ' . $siteName;
            $title = $titleText;
            $description = '浏览' . ($provider !== '' ? ui_provider_name($provider) . '网盘的' : '')
                . ($type !== '' ? ui_type_name($type) : '全部') . '类资源，共 ' . number_format((int) $res['total'])
                . ' 条，可按最新发布、来源最多、集数最多排序。';
        }
        break;
}

/* ---------------- SEO 元信息与结构化数据 ---------------- */
$seoMeta = seo_meta_for([
    'route'    => $route,
    'slug'     => $landingSlug,
    'channel'  => $channelSlug,
    'id'       => (string) ($row['id'] ?? ($_GET['id'] ?? '')),
    'q'        => (string) ($q ?? ''),
    'type'     => (string) ($type ?? ''),
    'provider' => (string) ($provider ?? ''),
    'sort'     => (string) ($sort ?? 'new'),
    'page'     => (int) ($page ?? ($_GET['page'] ?? 1)),
    'days'     => (int) ($days ?? 7),
]);

$jsonld = [seo_jsonld_website()];
$ogType = $ogType ?? 'website';

if ($route === 'detail' && isset($row) && is_array($row)) {
    // 详情页：WebPage + 面包屑。不标 Movie/Dataset —— 内容不是我们发布的，误标属于垃圾结构化数据
    $crumb = [['name' => '首页', 'url' => seo_abs('/')]];
    $landingForType = seo_landing_slug_for_type((string) $row['type']);
    if ($landingForType !== null) {
        $spec = seo_landing($landingForType);
        $crumb[] = ['name' => (string) $spec['h1'], 'url' => seo_abs('/' . $landingForType)];
    }
    $crumb[] = ['name' => ui_clean_title((string) $row['title']), 'url' => seo_detail_url((string) $row['id'])];
    $jsonld[] = seo_jsonld_breadcrumb($crumb);
    $jsonld[] = [
        '@context'      => 'https://schema.org',
        '@type'         => 'WebPage',
        'name'          => (string) $title,
        'url'           => seo_detail_url((string) $row['id']),
        'description'   => (string) $description,
        'inLanguage'    => 'zh-CN',
        'dateModified'  => (string) ($row['updated_at'] ?? $row['posted_at'] ?? gmdate('c')),
        'isPartOf'      => ['@type' => 'WebSite', 'name' => $siteName, 'url' => seo_abs('/')],
    ];
} elseif ($route === 'channel' && isset($ch) && is_array($ch)) {
    $jsonld[] = seo_jsonld_collection('@' . $channelSlug . ' 频道资源', (string) $description,
        seo_abs('/c/' . $channelSlug), (int) $res['total']);
    $jsonld[] = seo_jsonld_itemlist((array) ($res['items'] ?? []), '@' . $channelSlug . ' 频道资源');
    $jsonld[] = seo_jsonld_breadcrumb([
        ['name' => '首页', 'url' => seo_abs('/')],
        ['name' => '来源频道', 'url' => seo_abs('/channels')],
        ['name' => '@' . $channelSlug, 'url' => seo_abs('/c/' . $channelSlug)],
    ]);
} elseif ($route === 'channels' && isset($channels) && is_array($channels)) {
    $jsonld[] = seo_jsonld_collection('来源频道列表', (string) $description, seo_abs('/channels'),
        array_sum(array_map(fn($c) => (int) $c['resources'], $channels)));
    $jsonld[] = seo_jsonld_breadcrumb([
        ['name' => '首页', 'url' => seo_abs('/')],
        ['name' => '来源频道', 'url' => seo_abs('/channels')],
    ]);
} elseif ($route === 'landing' && isset($spec) && is_array($spec)) {
    $jsonld[] = seo_jsonld_collection((string) $spec['h1'], (string) $spec['desc'],
        seo_abs('/' . $landingSlug), (int) $res['total']);
    $jsonld[] = seo_jsonld_itemlist((array) ($res['items'] ?? []), (string) $spec['h1']);
    if (!empty($spec['faq'])) {
        $jsonld[] = seo_jsonld_faq($spec['faq']);
    }
} elseif (isset($res['items'])) {
    $listName = $q !== '' ? '「' . $q . '」的搜索结果' : '最新收录';
    $jsonld[] = seo_jsonld_itemlist((array) $res['items'], $listName);
}

view('layout', [
    'content'     => $content,
    'title'       => (string) $title,
    'description' => (string) ($description ?? ''),
    'canonical'   => $seoMeta['canonical'],
    'robots'      => $seoMeta['robots'],
    'ogType'      => $ogType,
    'jsonld'      => $jsonld,
    // 首页让页头站点名当唯一 h1，其余页面交给正文
    'siteHeading' => ($segments === []),
]);
