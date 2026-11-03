<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/moderation.php';
require_once __DIR__ . '/../src/user.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/promo.php';

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

// 路由：支持 /search 这种伪静态，也支持 ?r=search
$path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$path = preg_replace('#^index\.php$#', '', $path) ?? '';
$route = (string) ($_GET['r'] ?? ($path === '' ? 'home' : $path));
$route = preg_replace('#[^a-z_]#', '', $route) ?: 'home';

$siteName = setting('site_name', (string) cfg('site_name'));

switch ($route) {
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
        $title = '追更日历 - ' . $siteName;
        break;

    case 'detail':
        $row = repo_get((string) ($_GET['id'] ?? ''));
        if ($row === null) {
            http_response_code(404);
            $content = '<div class="py-24 text-center">'
                . '<p class="text-sm text-gray-500">资源不存在或已下架</p>'
                . '<a class="btn-primary mt-5 inline-flex" href="/">回首页</a></div>';
            $title = '404 - ' . $siteName;
            break;
        }
        click_track((string) $row['id']);
        $isFav = $currentUser !== null && user_is_favorite((int) $currentUser['id'], (string) $row['id']);
        $versions = repo_versions((string) $row['core_title'], 12);
        $related = repo_search(['q' => mb_substr((string) $row['core_title'], 0, 12)], 1, 7)['items'];
        $content = render_view('detail', [
            'row'         => $row,
            'versions'    => $versions,
            'related'     => $related,
            'isFav'       => $isFav,
            'currentUser' => $currentUser,
        ]);
        $title = $row['title'] . ' - ' . $siteName;
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
        $facets = repo_facets();
        $stats = repo_stats();
        if ($q !== '') {
            search_track($q);
        }

        $isHome = ($q === '' && $type === '' && $provider === '');
        $content = render_view('list', [
            'q'        => $q,
            'type'     => $type,
            'provider' => $provider,
            'res'      => $res,
            'facets'   => $facets,
            'stats'    => $stats,
            'isHome'   => $isHome,
            'hotWords' => $isHome ? top_searches(cfg_get_int('search_hot_words') ?: 7, 10) : [],
        ]);
        $title = $q !== ''
            ? $q . ' - ' . $siteName
            : $siteName . ' - ' . cfg('site_slogan');
        break;
}

view('layout', ['content' => $content, 'title' => (string) $title]);
