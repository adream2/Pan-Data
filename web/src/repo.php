<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/alias.php';

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';

function now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function repo_log(string $kind, bool $ok, string $message, array $stats = []): void
{
    $stmt = db()->prepare('INSERT INTO logs(kind, ok, message, stats_json, created_at) VALUES(?,?,?,?,?)');
    $stmt->execute([$kind, $ok ? 1 : 0, $message, json_encode($stats, JSON_UNESCAPED_UNICODE), now_iso()]);
}

function repo_fts_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT 1 FROM resources_fts LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * 清洗备用链接：去重、丢掉被截断的残缺 URL、限制条数。
 *
 * 真实脏数据：同一部热剧被几百个频道转发，mirrors 里同一条 share_url 重复几十次，
 * 还混着 `https://pan.baidu.com/s/16D` 这种抓取时被截断的链接。
 * 不清洗的话「自动换源」会切到一个打不开的地址，比不切更糟。
 */
function repo_clean_mirrors(array $mirrors, string $selfUrl): array
{
    $seen = [$selfUrl => true];
    $out = [];
    foreach ($mirrors as $m) {
        if (!is_array($m)) {
            continue;
        }
        $url = trim((string) ($m['share_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = preg_replace('#\?.*$#', '', trim($path, '/')) ?? '';
        $tail = $path !== '' ? basename($path) : '';
        // 分享 id 普遍 8 位以上；少于 6 位基本是被截断的残链
        if (mb_strlen($tail) < 6) {
            continue;
        }
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $out[] = [
            'provider'  => (string) ($m['provider'] ?? 'unknown'),
            'share_url' => $url,
            'pwd'       => ($m['pwd'] ?? null) !== null && $m['pwd'] !== '' ? (string) $m['pwd'] : null,
            'status'    => in_array((string) ($m['status'] ?? ''), ['valid', 'invalid', 'unchecked'], true)
                ? (string) $m['status'] : 'unchecked',
        ];
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

/** 把仓库的 resources.json 条目写进本地库（幂等）。 */
function repo_upsert(array $r, string $origin = 'repo'): string
{
    $id = trim((string) ($r['id'] ?? ''));
    if ($id === '' || empty($r['share_url'])) {
        return 'skip';
    }
    $title = trim((string) ($r['title'] ?? '')) ?: (string) $r['share_url'];
    $mirrors = repo_clean_mirrors((array) ($r['mirrors'] ?? []), (string) $r['share_url']);
    $row = [
        ':id'         => $id,
        ':title'      => mb_substr($title, 0, 200),
        ':core_title' => mb_substr((string) ($r['core_title'] ?? ''), 0, 200),
        ':type'       => (string) ($r['type'] ?? 'other'),
        ':year'       => isset($r['year']) && $r['year'] !== null ? (int) $r['year'] : null,
        ':season'     => isset($r['season']) && $r['season'] !== null ? (int) $r['season'] : null,
        ':episode'    => isset($r['episode']) && $r['episode'] !== null ? (int) $r['episode'] : null,
        ':complete'   => !empty($r['complete']) ? 1 : 0,
        ':provider'   => (string) ($r['provider'] ?? 'unknown'),
        ':share_url'  => (string) $r['share_url'],
        ':pwd'        => ($r['pwd'] ?? null) !== null && $r['pwd'] !== '' ? (string) $r['pwd'] : null,
        ':status'     => (string) ($r['status'] ?? 'unchecked'),
        ':checked_at' => $r['checked_at'] ?? null,
        ':sources'    => (int) ($r['sources'] ?? 1),
        ':mirrors'    => json_encode($mirrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':channel'    => $r['channel'] ?? null,
        ':post_url'   => $r['post_url'] ?? null,
        ':posted_at'  => $r['posted_at'] ?? null,
        ':desc'       => mb_substr((string) ($r['desc'] ?? ''), 0, 300),
        ':origin'     => $origin,
        ':ts'         => now_iso(),
    ];

    $exists = db()->prepare('SELECT id FROM resources WHERE id = ?');
    $exists->execute([$id]);
    $isNew = $exists->fetchColumn() === false;

    $sql = $isNew
        ? 'INSERT INTO resources(id,title,core_title,type,year,season,episode,complete,provider,share_url,pwd,status,
             checked_at,sources,mirrors_json,channel,post_url,posted_at,desc,origin,created_at,updated_at)
           VALUES(:id,:title,:core_title,:type,:year,:season,:episode,:complete,:provider,:share_url,:pwd,:status,
             :checked_at,:sources,:mirrors,:channel,:post_url,:posted_at,:desc,:origin,:ts,:ts)'
        : 'UPDATE resources SET title=:title, core_title=:core_title, type=:type, year=:year, season=:season,
             episode=:episode, complete=:complete, provider=:provider, share_url=:share_url, pwd=:pwd,
             status=:status, checked_at=:checked_at, sources=:sources, mirrors_json=:mirrors,
             channel=:channel, post_url=:post_url, posted_at=:posted_at, desc=:desc,
             origin=:origin, updated_at=:ts WHERE id=:id';
    db()->prepare($sql)->execute($row);

    if (repo_fts_available()) {
        db()->prepare('DELETE FROM resources_fts WHERE id = ?')->execute([$id]);
        db()->prepare('INSERT INTO resources_fts(id, title, core_title, desc) VALUES(?,?,?,?)')
            ->execute([$id, $row[':title'], $row[':core_title'], $row[':desc']]);
    }
    repo_mark_dirty();
    return $isNew ? 'insert' : 'update';
}

function repo_import_payload(array $payload, string $origin): array
{
    $items = $payload['resources'] ?? [];
    $stat = ['insert' => 0, 'update' => 0, 'skip' => 0, 'total' => count($items)];
    db()->beginTransaction();
    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                $stat['skip']++;
                continue;
            }
            $stat[repo_upsert($item, $origin)]++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $stat;
}

function repo_url(string $file): string
{
    $owner = (string) repo_conf('github');
    $branch = (string) repo_conf('branch', 'data');
    $dir = trim((string) repo_conf('dir', 'data'), '/');
    return 'https://api.github.com/repos/' . $owner . '/contents/' . $dir . '/' . ltrim($file, '/')
        . '?ref=' . rawurlencode($branch);
}

function repo_fetch_json(string $file): ?array
{
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $body = repo_fetch_once($file);
        if ($body !== null) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                return $data;
            }
        }
        usleep(500000);
    }
    return null;
}

function repo_fetch_once(string $file): ?string
{
    $ch = curl_init(repo_url($file));
    $headers = ['accept: application/vnd.github.raw', 'user-agent: pansou-php'];
    if ((string) repo_conf('token', '') !== '') {
        $headers[] = 'authorization: Bearer ' . (string) repo_conf('token');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => max(10, (int) repo_conf('timeout', 120)),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $proxy = (string) repo_conf('proxy', '');
    if ($proxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || $code >= 400) {
        return null;
    }
    return $body;
}

function repo_index(): ?array
{
    return repo_fetch_json('index.json');
}

/** 导入热文件（最近 N 天）。 */
function repo_import_hot(): array
{
    $payload = repo_fetch_json('resources.json');
    if ($payload === null) {
        throw new RuntimeException('拉取 resources.json 失败（检查网络/代理）');
    }
    $stat = repo_import_payload($payload, 'repo');
    repo_log('import_hot', true, '导入热文件', $stat);
    return $stat;
}

/** 导入某个历史分片，如 2026-10。 */
function repo_import_shard(string $shardId): array
{
    if (!preg_match('/^\d{4}-(\d{2}|Q[1-4])$/', $shardId)) {
        throw new RuntimeException('分片 ID 不合法');
    }
    $payload = repo_fetch_json('shards/' . $shardId . '.json');
    if ($payload === null) {
        throw new RuntimeException("分片 {$shardId} 拉取失败");
    }
    $stat = repo_import_payload($payload, 'shard:' . $shardId);
    repo_log('import_shard', true, '导入分片 ' . $shardId, $stat);
    return $stat;
}

/** 搜索。返回 ['items'=>[], 'total'=>int, 'pages'=>int, 'page'=>int] */
function repo_search(array $f, int $page = 1, int $per = 20): array
{
    $per = max(1, min($per, (int) cfg('per_page_max', 100)));
    $page = max(1, $page);
    $offset = ($page - 1) * $per;

    $where = ['r.hidden = 0'];
    $params = [];
    $join = '';

    // 排序：new=最新发布（默认）｜sources=来源最多｜episode=集数最多（追更）
    $sort = (string) ($f['sort'] ?? 'new');
    if (!in_array($sort, ['new', 'sources', 'episode'], true)) {
        $sort = 'new';
    }
    $order = match ($sort) {
        'sources' => 'r.sources DESC, r.posted_at DESC',
        'episode' => 'r.episode IS NULL ASC, r.episode DESC, r.posted_at DESC',
        default   => 'r.posted_at DESC',
    };

    $kw = trim((string) ($f['q'] ?? ''));
    $aliasHits = [];
    $aliasExpanded = false;
    if ($kw !== '') {
        $terms = array_slice(preg_split('/\s+/u', $kw, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
        $plan = alias_plan($kw);
        $aliasHits = $plan['hits'];
        $aliasExpanded = $plan['expanded'];

        // trigram 分词要求每个词至少 3 个字符，否则 FTS 永远 0 命中
        // （搜「ps」「三体」这类短词必须回退 LIKE，不然名字里有却搜不到）
        $ftsOk = repo_fts_available() && mb_strlen($kw) >= 3;
        foreach ($terms as $t) {
            if (mb_strlen($t) < 3) {
                $ftsOk = false;
                break;
            }
        }

        if ($ftsOk) {
            $join = 'JOIN resources_fts f ON f.id = r.id';
            $parts = [];
            foreach ($plan['terms'] as [$t, $variants]) {
                $quoted = array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $variants);
                $parts[] = count($quoted) > 1 ? '(' . implode(' OR ', $quoted) . ')' : $quoted[0];
            }
            $where[] = 'resources_fts MATCH :fts';
            $params[':fts'] = implode(' AND ', $parts);
            // 默认按相关度；用户显式选了排序就以排序为主，相关度退为次级
            $order = match ($sort) {
                'sources' => 'r.sources DESC, bm25(resources_fts, 8.0, 4.0, 1.0) ASC',
                'episode' => 'r.episode IS NULL ASC, r.episode DESC, bm25(resources_fts, 8.0, 4.0, 1.0) ASC',
                default   => 'bm25(resources_fts, 8.0, 4.0, 1.0) ASC, r.posted_at DESC',
            };
        } else {
            foreach ($plan['terms'] as $i => [, $variants]) {
                $ors = [];
                foreach ($variants as $j => $v) {
                    $key = ':lk' . $i . '_' . $j;
                    $ors[] = "r.title LIKE {$key} OR r.core_title LIKE {$key} OR COALESCE(r.desc, '') LIKE {$key}";
                    $params[$key] = '%' . $v . '%';
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            }
        }
    }
    if (!empty($f['type'])) {
        $where[] = 'r.type = :type';
        $params[':type'] = (string) $f['type'];
    }
    // 短剧：库里没有独立的 type，靠标题/简介里自带的「短剧」标记筛。
    // 同时排掉「短剧更新目录」这类索引帖——它不是资源，只是一堆链接的目录。
    if (!empty($f['duanju'])) {
        $where[] = "(r.title LIKE '%短剧%' OR r.core_title LIKE '%短剧%' OR COALESCE(r.desc, '') LIKE '%短剧%')";
        $where[] = "r.title NOT LIKE '%目录%'";
    }
    if (!empty($f['provider'])) {
        $where[] = 'r.provider = :provider';
        $params[':provider'] = (string) $f['provider'];
    }
    if (!empty($f['year'])) {
        $where[] = 'r.year = :year';
        $params[':year'] = (int) $f['year'];
    }
    if (!empty($f['only_valid'])) {
        $where[] = "r.status = 'valid'";
    }
    if (!empty($f['hide_invalid'])) {
        $where[] = "r.status != 'invalid'";
    }

    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) FROM resources r {$join} WHERE {$whereSql}";
    $stmt = db()->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $sql = "SELECT r.* FROM resources r {$join} WHERE {$whereSql} ORDER BY {$order} LIMIT {$per} OFFSET {$offset}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    return [
        'items' => $items,
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $per)),
        'per'   => $per,
        'sort'  => $sort,
        // 别名词典命中的词，用于前端提示「PS 也匹配 Photoshop」
        'alias_hits'     => $aliasHits,
        'alias_expanded' => $aliasExpanded,
    ];
}

/** 快速判断一条查询是否有结果（无结果页给建议用，避免推荐过去又是一个空页） */
function repo_has_results(string $q): bool
{
    $r = repo_search(['q' => $q], 1, 1);
    return (int) $r['total'] > 0;
}

/**
 * 极轻量的存在性探测：只扫 title / core_title，不做 FTS、不排序。
 * 无结果页与详情页要在几十毫秒内验证几个候选词，用 repo_search 太重（每次都算 COUNT + 排序）。
 *
 * 多词按 AND 处理：搜「蛊降 2026」时标题里写的是「蛊降(2026)」，
 * 拿整串去 LIKE 一定落空，必须拆词分别判。
 */
function repo_probe(string $q): bool
{
    $terms = array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
    if ($terms === []) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM resources WHERE hidden = 0
        AND (title LIKE ? OR core_title LIKE ?) LIMIT 1');
    foreach ($terms as $t) {
        $stmt->execute(['%' . $t . '%', '%' . $t . '%']);
        if ($stmt->fetchColumn() === false) {
            return false;
        }
    }
    return true;
}

function repo_get(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM resources WHERE id = ? AND hidden = 0');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['mirrors'] = json_decode((string) $row['mirrors_json'], true) ?: [];
    return $row;
}

/* ------------------------------------------------------------------ */
/* 轻量缓存                                                            */
/* ------------------------------------------------------------------ */

/**
 * 统计类查询全是全表 GROUP BY，一次首页要跑 10 几条，实测 250ms 起步。
 * 数据每 30 分钟才同步一次，60 秒缓存完全不影响新鲜度，但能把首屏打下来。
 * 导入/巡检写完库后调 repo_cache_flush() 立即失效。
 */
function repo_cache_dir(): string
{
    return APP_ROOT . '/storage/cache';
}

function repo_cache_read(string $key, int $ttl): ?array
{
    if ($ttl <= 0) {
        return null;
    }
    $f = repo_cache_dir() . '/' . $key . '.json';
    if (!is_file($f) || time() - (int) filemtime($f) >= $ttl) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function repo_cache_write(string $key, array $data): void
{
    @mkdir(repo_cache_dir(), 0775, true);
    @file_put_contents(repo_cache_dir() . '/' . $key . '.json',
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function repo_cache_flush(): void
{
    foreach (glob(repo_cache_dir() . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
}

/**
 * 标记「库已变」。批量导入会调上千次 upsert，不能每次都清缓存，
 * 所以只置脏标记，进程退出时清一次。
 */
function repo_mark_dirty(): void
{
    $GLOBALS['__repo_dirty'] = true;
    static $registered = false;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(static function (): void {
            if (!empty($GLOBALS['__repo_dirty'])) {
                repo_cache_flush();
            }
        });
    }
}

function repo_stats(bool $fresh = false): array
{
    if (!$fresh && ($c = repo_cache_read('stats', 60)) !== null) {
        return $c;
    }
    $pdo = db();
    $out = [];

    // 一次 GROUP BY 拿到总数与状态分布，省掉 3 次全表 COUNT
    $statusRows = $pdo->query('SELECT status, COUNT(*) c FROM resources WHERE hidden = 0
        GROUP BY status ORDER BY c DESC')->fetchAll();
    $byStatus = [];
    foreach ($statusRows as $r) {
        $byStatus[(string) $r['status']] = (int) $r['c'];
    }
    $out['by_status'] = $statusRows;
    $out['total'] = array_sum($byStatus);
    $out['valid'] = $byStatus['valid'] ?? 0;
    $out['invalid'] = $byStatus['invalid'] ?? 0;
    $out['hidden'] = (int) $pdo->query('SELECT COUNT(*) FROM resources WHERE hidden = 1')->fetchColumn();
    $out['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'pending'")->fetchColumn();

    // 用范围比较而不是 substr()：substr 是函数，索引直接废掉，全表扫
    $out['today'] = (int) $pdo->query("SELECT COUNT(*) FROM resources
        WHERE posted_at >= date('now') AND posted_at < date('now', '+1 day')")->fetchColumn();
    $out['today_invalid'] = (int) $pdo->query("SELECT COUNT(*) FROM resources
        WHERE hidden = 0 AND status = 'invalid'
          AND COALESCE(checked_at, updated_at) >= date('now')")->fetchColumn();

    $out['by_type'] = $pdo->query("SELECT type, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY type
        ORDER BY CASE type WHEN 'other' THEN 1 ELSE 0 END, c DESC")->fetchAll();
    $out['by_provider'] = $pdo->query('SELECT provider, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY provider ORDER BY c DESC')->fetchAll();
    $out['months'] = $pdo->query("SELECT substr(posted_at,1,7) m, COUNT(*) c FROM resources WHERE posted_at IS NOT NULL
        GROUP BY m ORDER BY m DESC LIMIT 18")->fetchAll();

    repo_cache_write('stats', $out);
    return $out;
}

/**
 * 筛选项计数。
 *
 * $base 是当前页面的固定筛选（分类页的 type、短剧页的 duanju 条件）。
 * types 始终给全局数（点某个类型是跳去别的分类页，全局数才对）；
 * providers 按 $base 过滤 —— 否则在 /movie 页会显示「全部平台 12,417」，
 * 而列表只有 5,718 条，用户会以为筛选坏了。
 */
function repo_facets(array $base = []): array
{
    $cacheKey = 'facets_' . md5((string) json_encode($base));
    if (($cached = repo_cache_read($cacheKey, 60)) !== null) {
        return $cached;
    }

    $where = ['hidden = 0'];
    $params = [];
    if (($base['type'] ?? '') !== '') {
        $where[] = 'type = ?';
        $params[] = (string) $base['type'];
    }
    if (!empty($base['duanju'])) {
        $where[] = "(title LIKE '%短剧%' OR core_title LIKE '%短剧%' OR COALESCE(desc, '') LIKE '%短剧%')";
        $where[] = "title NOT LIKE '%目录%'";
    }
    $whereSql = implode(' AND ', $where);
    $stmt = db()->prepare("SELECT provider, COUNT(*) c FROM resources WHERE {$whereSql} GROUP BY provider ORDER BY c DESC");
    $stmt->execute($params);

    $out = [
        'types'     => db()->query("SELECT type, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY type
        ORDER BY CASE type WHEN 'other' THEN 1 ELSE 0 END, c DESC")->fetchAll(),
        'providers' => $stmt->fetchAll(),
        'years'     => db()->query('SELECT year, COUNT(*) c FROM resources WHERE hidden = 0 AND year IS NOT NULL GROUP BY year ORDER BY year DESC LIMIT 12')->fetchAll(),
    ];
    repo_cache_write($cacheKey, $out);
    return $out;
}

/**
 * 追更列表：按 core_title 聚合，取最近更新到第几集。
 * 只统计近 N 天有动静、且带集数的影视资源。
 *
 * @return array<int, array{core_title:string,max_ep:int,last_at:string,versions:int,sources:int,platforms:int}>
 */
function repo_updates(int $days = 14, int $limit = 200): array
{
    $days = max(1, min($days, 90));
    $limit = max(1, min($limit, 500));
    $since = date('Y-m-d', strtotime('-' . $days . ' days'));
    $sql = "SELECT core_title,
                   MAX(COALESCE(episode, 0)) AS max_ep,
                   MAX(posted_at) AS last_at,
                   COUNT(*) AS versions,
                   MAX(COALESCE(sources, 1)) AS sources,
                   COUNT(DISTINCT provider) AS platforms
            FROM resources
            WHERE hidden = 0
              AND type = 'movie'
              AND core_title != ''
              AND episode IS NOT NULL AND episode > 0
              AND posted_at >= :since
            GROUP BY core_title
            ORDER BY last_at DESC
            LIMIT " . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':since' => $since]);
    return $stmt->fetchAll();
}

/** 今日有更新的剧集数（首页提示条用） */
function repo_today_updates(): int
{
    return (int) db()->query("SELECT COUNT(DISTINCT core_title) FROM resources
        WHERE hidden = 0 AND type = 'movie' AND core_title != ''
          AND episode IS NOT NULL AND episode > 0
          AND substr(posted_at, 1, 10) = date('now')")->fetchColumn();
}

/** 某部剧的全部来源版本，按集数倒序（详情页「同剧版本」用） */
function repo_versions(string $coreTitle, int $limit = 12): array
{
    // 优先级与采集端 grouping.py 保持一致：链接有效 > 未巡检 > 失效，然后集数高、时间新
    $stmt = db()->prepare("SELECT * FROM resources
        WHERE hidden = 0 AND core_title = TRIM(?)
        ORDER BY CASE status WHEN 'valid' THEN 0 WHEN 'unchecked' THEN 1 ELSE 2 END,
                 COALESCE(episode, 0) DESC, posted_at DESC LIMIT " . max(1, min($limit, 30)));
    $stmt->execute([$coreTitle]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['mirrors'] = json_decode((string) $r['mirrors_json'], true) ?: [];
    }
    return $rows;
}

/* ------------------------------------------------------------------ */
/* 频道（来源）维度                                                     */
/* ------------------------------------------------------------------ */

/** 频道名只允许字母数字下划线短横，防止进 URL 或 SQL 时出问题 */
function repo_channel_slug(string $raw): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '', $raw) ?? '';
}

/**
 * 全部频道及其规模。
 * 这些频道是内容的真实来源，做成落地页既是给用户看「这个号靠不靠谱」，
 * 也是站内最大的一块内链枢纽。
 *
 * @return array<int,array{channel:string,resources:int,platforms:int,types:int,latest:string,earliest:string,invalid:int}>
 */
function repo_channels(int $limit = 200): array
{
    $limit = max(1, min($limit, 500));
    $sql = "SELECT channel,
                   COUNT(*) AS resources,
                   COUNT(DISTINCT provider) AS platforms,
                   COUNT(DISTINCT type) AS types,
                   MAX(posted_at) AS latest,
                   MIN(posted_at) AS earliest,
                   SUM(CASE WHEN status = 'invalid' THEN 1 ELSE 0 END) AS invalid
            FROM resources
            WHERE hidden = 0 AND channel != ''
            GROUP BY channel
            ORDER BY resources DESC
            LIMIT " . $limit;
    return db()->query($sql)->fetchAll();
}

/** 单个频道的汇总；不存在返回 null */
function repo_channel(string $channel): ?array
{
    $slug = repo_channel_slug($channel);
    if ($slug === '') {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT channel,
                COUNT(*) AS resources,
                COUNT(DISTINCT provider) AS platforms,
                COUNT(DISTINCT type) AS types,
                MAX(posted_at) AS latest,
                MIN(posted_at) AS earliest,
                SUM(CASE WHEN status = 'invalid' THEN 1 ELSE 0 END) AS invalid
         FROM resources
         WHERE hidden = 0 AND channel = ?
         GROUP BY channel"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 频道内的资源（分页） */
function repo_channel_search(string $channel, int $page = 1, int $per = 20): array
{
    $slug = repo_channel_slug($channel);
    $per = max(1, min($per, 100));
    $page = max(1, $page);
    $offset = ($page - 1) * $per;

    $stmt = db()->prepare('SELECT COUNT(*) FROM resources WHERE hidden = 0 AND channel = ?');
    $stmt->execute([$slug]);
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT * FROM resources WHERE hidden = 0 AND channel = ?
         ORDER BY posted_at DESC, id ASC LIMIT ' . $per . ' OFFSET ' . $offset
    );
    $stmt->execute([$slug]);

    return [
        'items' => $stmt->fetchAll(),
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $per)),
        'per'   => $per,
    ];
}

/** 频道内的类型分布，用于落地页给人看「这个号主要发什么」 */
function repo_channel_types(string $channel): array
{
    $stmt = db()->prepare(
        "SELECT type, COUNT(*) c FROM resources
         WHERE hidden = 0 AND channel = ? GROUP BY type ORDER BY c DESC"
    );
    $stmt->execute([repo_channel_slug($channel)]);
    return $stmt->fetchAll();
}

/** 频道内的平台分布 */
function repo_channel_providers(string $channel): array
{
    $stmt = db()->prepare(
        'SELECT provider, COUNT(*) c FROM resources
         WHERE hidden = 0 AND channel = ? GROUP BY provider ORDER BY c DESC'
    );
    $stmt->execute([repo_channel_slug($channel)]);
    return $stmt->fetchAll();
}

/** 同频道的其他资源（详情页用，排除当前条） */
function repo_channel_more(string $channel, string $excludeId, int $limit = 6): array
{
    $slug = repo_channel_slug($channel);
    if ($slug === '') {
        return [];
    }
    $limit = max(1, min($limit, 20));
    $stmt = db()->prepare(
        'SELECT * FROM resources
         WHERE hidden = 0 AND channel = ? AND id != ?
         ORDER BY posted_at DESC LIMIT ' . $limit
    );
    $stmt->execute([$slug, $excludeId]);
    return $stmt->fetchAll();
}

/**
 * 频道的主打类型：优先非 other 且占比 ≥15% 的那一类。
 * 「内容以其它为主」这种描述对读者没信息量，宁可退到第二类。
 */
function repo_channel_lead_type(array $types, int $total): ?array
{
    $total = max(1, $total);
    foreach ($types as $t) {
        if ((string) $t['type'] !== 'other' && (int) $t['c'] * 100.0 / $total >= 15) {
            return $t;
        }
    }
    return $types[0] ?? null;
}
