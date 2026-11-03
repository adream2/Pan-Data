<?php
declare(strict_types=1);

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

/** 把仓库的 resources.json 条目写进本地库（幂等）。 */
function repo_upsert(array $r, string $origin = 'repo'): string
{
    $id = trim((string) ($r['id'] ?? ''));
    if ($id === '' || empty($r['share_url'])) {
        return 'skip';
    }
    $title = trim((string) ($r['title'] ?? '')) ?: (string) $r['share_url'];
    $mirrors = $r['mirrors'] ?? [];
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
    $owner = (string) cfg('repo.github');
    $branch = (string) cfg('repo.branch', 'data');
    $dir = trim((string) cfg('repo.dir', 'data'), '/');
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
    if ((string) cfg('repo.token', '') !== '') {
        $headers[] = 'authorization: Bearer ' . (string) cfg('repo.token');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => (int) cfg('repo.timeout', 30),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $proxy = (string) cfg('repo.proxy', '');
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
    $order = 'r.posted_at DESC';

    $kw = trim((string) ($f['q'] ?? ''));
    if ($kw !== '') {
        $terms = preg_split('/\s+/u', $kw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (mb_strlen($kw) >= 3 && repo_fts_available()) {
            $join = 'JOIN resources_fts f ON f.id = r.id';
            $quoted = array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"', $terms);
            $where[] = 'resources_fts MATCH :fts';
            $params[':fts'] = implode(' AND ', $quoted);
            $order = 'bm25(resources_fts, 8.0, 4.0, 1.0) ASC, r.posted_at DESC';
        } else {
            foreach ($terms as $i => $t) {
                $where[] = "(r.title LIKE :lk{$i} OR r.core_title LIKE :lk{$i} OR r.desc LIKE :lk{$i})";
                $params[":lk{$i}"] = '%' . $t . '%';
            }
        }
    }
    if (!empty($f['type'])) {
        $where[] = 'r.type = :type';
        $params[':type'] = (string) $f['type'];
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
    ];
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

function repo_stats(): array
{
    $pdo = db();
    $out = [];
    $out['total'] = (int) $pdo->query('SELECT COUNT(*) FROM resources WHERE hidden = 0')->fetchColumn();
    $out['hidden'] = (int) $pdo->query('SELECT COUNT(*) FROM resources WHERE hidden = 1')->fetchColumn();
    $out['today'] = (int) $pdo->query("SELECT COUNT(*) FROM resources WHERE substr(posted_at,1,10) = date('now')")->fetchColumn();
    $out['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'pending'")->fetchColumn();
    $out['today_invalid'] = (int) $pdo->query("SELECT COUNT(*) FROM resources WHERE hidden = 0 AND status = 'invalid'
        AND substr(COALESCE(checked_at, updated_at), 1, 10) = date('now')")->fetchColumn();
    $out['valid'] = (int) $pdo->query("SELECT COUNT(*) FROM resources WHERE hidden = 0 AND status = 'valid'")->fetchColumn();
    $out['by_type'] = $pdo->query("SELECT type, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY type
        ORDER BY CASE type WHEN 'other' THEN 1 ELSE 0 END, c DESC")->fetchAll();
    $out['by_provider'] = $pdo->query('SELECT provider, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY provider ORDER BY c DESC')->fetchAll();
    $out['by_status'] = $pdo->query("SELECT status, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY status ORDER BY c DESC")->fetchAll();
    $out['months'] = $pdo->query("SELECT substr(posted_at,1,7) m, COUNT(*) c FROM resources WHERE posted_at IS NOT NULL
        GROUP BY m ORDER BY m DESC LIMIT 18")->fetchAll();
    return $out;
}

function repo_facets(): array
{
    return [
        'types'     => db()->query("SELECT type, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY type
        ORDER BY CASE type WHEN 'other' THEN 1 ELSE 0 END, c DESC")->fetchAll(),
        'providers' => db()->query('SELECT provider, COUNT(*) c FROM resources WHERE hidden = 0 GROUP BY provider ORDER BY c DESC')->fetchAll(),
        'years'     => db()->query('SELECT year, COUNT(*) c FROM resources WHERE hidden = 0 AND year IS NOT NULL GROUP BY year ORDER BY year DESC LIMIT 12')->fetchAll(),
    ];
}
