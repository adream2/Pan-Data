<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/moderation.php';
require_once __DIR__ . '/../src/user.php';
require_once __DIR__ . '/../src/ui.php';

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
        $related = repo_search(['q' => mb_substr((string) $row['core_title'], 0, 12)], 1, 7)['items'];
        $content = render_view('detail', [
            'row'         => $row,
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
            if (setting('submit_open', '1') !== '1') {
                $error = '投稿暂时关闭';
            } elseif (!rate_limit('submit', (int) cfg('security.submit_per_hour', 6), 3600)) {
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
                    $dup = db()->prepare('SELECT id FROM resources WHERE share_url = ?');
                    $dup->execute([$shareUrl]);
                    if ($dup->fetchColumn() !== false) {
                        $error = '这条链接已经在库里了';
                    } elseif ($mod['level'] === 'blocked') {
                        $error = '内容不符合收录要求：' . implode('；', $mod['reasons']);
                    } else {
                        db()->prepare('INSERT INTO submissions(title, share_url, pwd, note, contact, ip, status, created_at)
                            VALUES(?,?,?,?,?,?,?,?)')
                            ->execute([$t, $shareUrl, $pwd, $note, $contact,
                                (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'pending', now_iso()]);
                        $done = true;
                    }
                }
            }
        }
        $content = render_view('submit', ['done' => $done, 'error' => $error]);
        $title = '投稿 - ' . $siteName;
        break;

    case 'api_search':
        json_out(repo_search([
            'q'        => (string) ($_GET['q'] ?? ''),
            'type'     => (string) ($_GET['type'] ?? ''),
            'provider' => (string) ($_GET['provider'] ?? ''),
        ], max(1, (int) ($_GET['page'] ?? 1)), (int) cfg('page_size', 20)));
        // no break

    case 'search':
    default:
        $q        = trim((string) ($_GET['q'] ?? ''));
        $type     = (string) ($_GET['type'] ?? '');
        $provider = (string) ($_GET['provider'] ?? '');
        $page     = max(1, (int) ($_GET['page'] ?? 1));

        $res = repo_search(
            ['q' => $q, 'type' => $type, 'provider' => $provider],
            $page,
            (int) cfg('page_size', 20)
        );
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
            'hotWords' => $isHome ? top_searches(7, 10) : [],
        ]);
        $title = $q !== ''
            ? $q . ' - ' . $siteName
            : $siteName . ' - ' . cfg('site_slogan');
        break;
}

view('layout', ['content' => $content, 'title' => (string) $title]);
