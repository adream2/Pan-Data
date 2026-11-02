<?php
declare(strict_types=1);

/**
 * 开放 API v1
 *   GET /api.php?q=关键词&page=1&limit=20&type=course&provider=quark
 *   鉴权：Header `X-API-Key: pk_xxx` 或 `?key=pk_xxx`
 *   公开（不需要 key）：/api.php?action=stats
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/user.php';

header('access-control-allow-origin: *');
header('access-control-allow-headers: x-api-key, content-type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

schema_upgrade();

$action = (string) ($_GET['action'] ?? 'search');

if ($action === 'stats') {
    $s = repo_stats();
    json_out([
        'code' => 0,
        'data' => [
            'resources' => $s['total'],
            'today'     => $s['today'],
            'providers' => $s['by_provider'],
            'types'     => $s['by_type'],
        ],
    ]);
}

$key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ''));
$user = api_user_by_key($key);
if ($user === null) {
    json_out(['code' => 401, 'message' => 'API Key 无效或已过期（在用户中心申请）'], 401);
}

// 简单限流：每个 key 每分钟 60 次
$bucket = 'api_' . md5($key);
if (!rate_limit($bucket, 60, 60)) {
    json_out(['code' => 429, 'message' => '请求过于频繁（60 次/分钟）'], 429);
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = (int) ($_GET['limit'] ?? 20);
$limit = max(1, min($limit, (int) cfg('per_page_max', 100)));

$res = repo_search([
    'q'        => $q,
    'type'     => (string) ($_GET['type'] ?? ''),
    'provider' => (string) ($_GET['provider'] ?? ''),
], $page, $limit);

if ($q !== '') {
    search_track($q);
}

$items = [];
foreach ($res['items'] as $r) {
    $items[] = [
        'id'         => $r['id'],
        'title'      => $r['title'],
        'provider'   => $r['provider'],
        'type'       => $r['type'],
        'year'       => $r['year'] !== null ? (int) $r['year'] : null,
        'episode'    => $r['episode'] !== null ? (int) $r['episode'] : null,
        'complete'   => (bool) $r['complete'],
        'share_url'  => $r['share_url'],
        'pwd'        => $r['pwd'],
        'status'     => $r['status'],
        'posted_at'  => $r['posted_at'],
        'sources'    => (int) $r['sources'],
        'detail_url' => '/detail?id=' . $r['id'],
    ];
}

json_out([
    'code' => 0,
    'data' => [
        'total'    => $res['total'],
        'page'     => $res['page'],
        'pageSize' => $res['per'],
        'pages'    => $res['pages'],
        'items'    => $items,
    ],
]);
