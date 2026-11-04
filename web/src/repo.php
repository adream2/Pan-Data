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

function repo_fts_available(bool $reset = false): bool
{
    // 用全局而不是函数内 static：重建索引后要能刷新这个判断
    if ($reset) {
        unset($GLOBALS['__fts_ok']);
    }
    if (array_key_exists('__fts_ok', $GLOBALS)) {
        return (bool) $GLOBALS['__fts_ok'];
    }
    try {
        db()->query('SELECT 1 FROM resources_fts LIMIT 1');
        // 索引表存在但没数据（导入中断、或刚用快速通道灌完还没建索引）时，
        // 用 FTS 查会「一条都搜不到」，比慢更糟。这种情况先降级 LIKE。
        $indexed = (int) db()->query('SELECT COUNT(*) FROM resources_fts')->fetchColumn();
        $total = (int) db()->query('SELECT COUNT(*) FROM resources')->fetchColumn();
        $ok = $indexed > 0 || $total === 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    $GLOBALS['__fts_ok'] = $ok;
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

/**
 * 该资源与库里已有内容是否完全一致。
 *
 * 热文件和分片里绝大部分条目每轮都没变（只是同一批数据被重新导出），
 * 但 upsert 会重写主表和 FTS 索引，实测 17ms/条 —— 同步 7700 条要两分多钟，
 * 而且这段时间一直占着数据库写锁，前台的点击统计和举报会被卡住。
 * 先比对再写，能省掉绝大部分开销。
 */
function repo_row_same(array $old, array $row): bool
{
    $map = [
        'title' => ':title', 'core_title' => ':core_title', 'type' => ':type',
        'year' => ':year', 'season' => ':season', 'episode' => ':episode',
        'complete' => ':complete', 'provider' => ':provider', 'share_url' => ':share_url',
        'pwd' => ':pwd', 'status' => ':status', 'checked_at' => ':checked_at',
        'sources' => ':sources', 'mirrors_json' => ':mirrors', 'channel' => ':channel',
        'post_url' => ':post_url', 'posted_at' => ':posted_at', 'desc' => ':desc',
    ];
    foreach ($map as $col => $key) {
        $a = $old[$col] === null ? '' : (string) $old[$col];
        $b = $row[$key] === null ? '' : (string) $row[$key];
        if ($a !== $b) {
            return false;
        }
    }
    return true;
}

/**
 * 把仓库的 resources.json 条目写进本地库（幂等）。
 *
 * $fast = true 走「首次灌库」通道：不做逐字段比对、不写 FTS 索引、直接 INSERT OR REPLACE。
 * 全新安装时库里本来就是空的，比对毫无意义；而 FTS 双写是单条 5ms 里的大头。
 * 导入完调用 repo_fts_rebuild() 一次性建索引（实测 8000 条 0.8 秒）。
 */
function repo_upsert(array $r, string $origin = 'repo', bool $fast = false): string
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

    if ($fast) {
        $cols = 'id,title,core_title,type,year,season,episode,complete,provider,share_url,pwd,status,'
            . 'checked_at,sources,mirrors_json,channel,post_url,posted_at,desc,origin,created_at,updated_at';
        db()->prepare("INSERT OR REPLACE INTO resources({$cols})
            VALUES(:id,:title,:core_title,:type,:year,:season,:episode,:complete,:provider,:share_url,:pwd,:status,
              :checked_at,:sources,:mirrors,:channel,:post_url,:posted_at,:desc,:origin,:ts,:ts)")->execute($row);
        repo_mark_dirty();
        return 'insert';
    }

    $exists = db()->prepare('SELECT title, core_title, type, year, season, episode, complete,
            provider, share_url, pwd, status, checked_at, sources, mirrors_json, channel,
            post_url, posted_at, desc FROM resources WHERE id = ?');
    $exists->execute([$id]);
    $old = $exists->fetch();
    $isNew = $old === false;

    if (!$isNew && repo_row_same($old, $row)) {
        return 'same';
    }

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

/**
 * 批量导入。
 *
 * 分批提交（每 500 条一个事务）而不是一个事务包几千条：
 * 大事务会把整个导入期间的数据库写锁一直攥着，前台的点击统计、举报写入
 * 只能等着（busy_timeout 5 秒），体验就是「同步一开始，站就卡」。
 * 分批后单次持锁只有几百毫秒，而且中途失败也不会丢掉已经写好的部分——
 * upsert 本身幂等，重跑就能补齐。
 */
function repo_import_payload(array $payload, string $origin, bool $fast = false): array
{
    $items = $payload['resources'] ?? [];
    $stat = ['insert' => 0, 'update' => 0, 'same' => 0, 'skip' => 0, 'total' => count($items)];
    $pdo = db();
    $batch = 500;
    for ($offset = 0; $offset < count($items); $offset += $batch) {
        $pdo->beginTransaction();
        try {
            foreach (array_slice($items, $offset, $batch) as $item) {
                if (!is_array($item)) {
                    $stat['skip']++;
                    continue;
                }
                $stat[repo_upsert($item, $origin, $fast)]++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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

/** 上一次拉取失败的原因，供安装向导/后台给出可操作的提示 */
function repo_last_error(): string
{
    return (string) ($GLOBALS['__repo_last_error'] ?? '');
}

/** @return array{code:int,body:string,error:string} */
function repo_http_get(string $url, array $headers, string $proxy): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => max(10, (int) repo_conf('timeout', 120)),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($proxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    $body = curl_exec($ch);
    return [
        'code'  => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body'  => is_string($body) ? $body : '',
        'error' => (string) curl_error($ch),
    ];
}

function repo_fetch_once(string $file): ?string
{
    $url = repo_url($file);
    $headers = ['accept: application/vnd.github.raw', 'user-agent: pansou-php'];
    if ((string) repo_conf('token', '') !== '') {
        $headers[] = 'authorization: Bearer ' . (string) repo_conf('token');
    }
    $proxy = (string) repo_conf('proxy', '');
    $res = repo_http_get($url, $headers, $proxy);

    // 代理被打爆（GitHub 匿名限额 60 次/小时、或走的是共用出口 IP）时换直连再试一次。
    // 两条路是不同出口 IP，各有独立额度；反过来直连不通时也一样靠这条兜底。
    if ($proxy !== '' && ($res['code'] === 0 || $res['code'] === 403 || $res['code'] === 429)) {
        $alt = repo_http_get($url, $headers, '');
        if ($alt['code'] >= 200 && $alt['code'] < 400) {
            $res = $alt;
        }
    }

    if ($res['code'] >= 200 && $res['code'] < 400 && $res['body'] !== '') {
        $GLOBALS['__repo_last_error'] = '';
        return $res['body'];
    }

    $GLOBALS['__repo_last_error'] = repo_explain_error($res);
    return null;
}

/** 把 GitHub 的错误翻译成「接下来该做什么」 */
function repo_explain_error(array $res): string
{
    $body = trim($res['body']);
    $msg = '';
    if ($body !== '' && $body[0] === '{') {
        $json = json_decode($body, true);
        $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
    }
    if ($res['code'] === 0) {
        return '网络不通' . ($res['error'] !== '' ? '（' . $res['error'] . '）' : '') . '，检查代理设置';
    }
    if ($res['code'] === 403 || $res['code'] === 429) {
        if (stripos($msg, 'rate limit') !== false) {
            return 'GitHub 接口额度用完（匿名每小时 60 次）。填一个 Token（每小时 5000 次），或等一小时再试';
        }
        return 'GitHub 拒绝访问（403）' . ($msg !== '' ? '：' . $msg : '') . '，检查 Token 是否有效';
    }
    if ($res['code'] === 404) {
        return '数据文件不存在（404）：仓库/分支/目录配置不对，或这条分支还没生成数据';
    }
    return 'HTTP ' . $res['code'] . ($msg !== '' ? '：' . $msg : '');
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

/**
 * 全量重建搜索索引（快速通道导入后调用）。
 * 一次性 INSERT ... SELECT 比逐条写快两个数量级，实测 8000 条 0.8 秒。
 */
function repo_fts_rebuild(): int
{
    // 注意不能先问 repo_fts_available()：它把「索引为空」当成不可用（是为了搜索降级），
    // 而这里恰恰就是要从空建起来，问了必然返回 false 然后永远不建。
    try {
        db()->exec('DELETE FROM resources_fts');
        db()->exec('INSERT INTO resources_fts(id, title, core_title, desc)
            SELECT id, title, core_title, desc FROM resources');
        $n = (int) db()->query('SELECT COUNT(*) FROM resources_fts')->fetchColumn();
    } catch (Throwable $e) {
        return 0; // 这套 SQLite 没编 FTS5，搜索会走 LIKE
    }
    repo_fts_available(true);
    return $n;
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

/* ------------------------------------------------------------------ */
/* 增量同步                                                            */
/* ------------------------------------------------------------------ */

/**
 * 上次同步到的快照，用来判断哪些文件真的变了。
 * 落在 storage/ 下（不进版本库），每台机器的进度各自独立。
 */
function repo_sync_state_path(): string
{
    return APP_ROOT . '/storage/sync_state.json';
}

function repo_sync_state(): array
{
    $path = repo_sync_state_path();
    $state = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    if (!is_array($state)) {
        $state = [];
    }
    return $state + ['generated_at' => '', 'hot_bytes' => 0, 'shards' => []];
}

function repo_sync_state_save(array $state): void
{
    @mkdir(dirname(repo_sync_state_path()), 0775, true);
    @file_put_contents(repo_sync_state_path(),
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * 增量同步：只下载真正变化的热文件与分片。
 *
 * 全量 24.6MB（热文件 6MB + 29 个分片 18.5MB），跨境拉一次十分钟起步，
 * 而每轮真正变化的只有几十到几百条。所以：
 *   1. 先读 index.json（几 KB），拿 generated_at 与每个分片的 resources/bytes
 *   2. 跟上次快照比对，没变就不下载
 *   3. 导入时逐字段比对，内容一致就跳过写库
 *
 * @param int $maxShards 本轮最多处理几个分片（0=不限）。
 *                      后台按钮在 web 请求里跑，传小值避免把请求拖到超时。
 * @return array{ok:bool,requests:int,insert:int,update:int,same:int,total:int,
 *               shards:array<int,string>,skipped:bool,message:string}
 */
function repo_auto_sync(int $maxShards = 0): array
{
    $out = ['ok' => true, 'requests' => 0, 'insert' => 0, 'update' => 0, 'same' => 0,
        'total' => 0, 'shards' => [], 'skipped' => false, 'message' => ''];

    // 单实例：上一轮没跑完就跳过，避免两个进程同时写库
    $lockFile = APP_ROOT . '/storage/sync.lock';
    @mkdir(dirname($lockFile), 0775, true);
    $lock = fopen($lockFile, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        $out['skipped'] = true;
        $out['message'] = '上一轮同步仍在运行，跳过本次';
        return $out;
    }

    $started = microtime(true);
    $state = repo_sync_state();
    $index = repo_index();
    $out['requests']++;
    if ($index === null) {
        $out['ok'] = false;
        $out['message'] = '拉取 index.json 失败（检查网络 / 代理 / Token）';
        return $out;
    }

    $generatedAt = (string) ($index['generated_at'] ?? '');
    $hotBytes = (int) ($index['hot']['bytes'] ?? 0);

    $todo = [];
    foreach ((array) ($index['shards'] ?? []) as $s) {
        $id = (string) ($s['id'] ?? '');
        if ($id === '' || !preg_match('/^\d{4}-(\d{2}|Q[1-4])$/', $id)) {
            continue;
        }
        $cur = ['resources' => (int) ($s['resources'] ?? 0), 'bytes' => (int) ($s['bytes'] ?? 0)];
        $prev = $state['shards'][$id] ?? null;
        if ($prev === null
            || (int) ($prev['resources'] ?? -1) !== $cur['resources']
            || (int) ($prev['bytes'] ?? -1) !== $cur['bytes']) {
            $todo[$id] = $cur;
        }
    }
    $hotChanged = $hotBytes !== (int) $state['hot_bytes'];

    if ($generatedAt === $state['generated_at'] && !$hotChanged && $todo === []) {
        $out['skipped'] = true;
        $out['message'] = '数据无变化（导出于 ' . ($generatedAt !== '' ? $generatedAt : '未知') . '）';
        return $out;
    }

    $isFirstRun = $state['generated_at'] === '';
    $lines = [];
    $lines[] = sprintf('%s导出于 %s：热文件%s，待同步分片 %d 个',
        $isFirstRun ? '首次运行，' : '',
        $generatedAt !== '' ? $generatedAt : '未知',
        $hotChanged ? '有更新' : '无变化',
        count($todo));

    if ($hotChanged) {
        $payload = repo_fetch_json('resources.json');
        $out['requests']++;
        if ($payload === null) {
            $out['ok'] = false;
            $lines[] = '热文件下载失败';
        } else {
            $st = repo_import_payload($payload, 'repo');
            $out['insert'] += $st['insert'];
            $out['update'] += $st['update'];
            $out['same'] += $st['same'];
            $out['total'] += $st['total'];
            $state['hot_bytes'] = $hotBytes;
            $lines[] = sprintf('热文件 %d 条：新增 %d，更新 %d，未变 %d',
                $st['total'], $st['insert'], $st['update'], $st['same']);
        }
    }

    if ($maxShards > 0) {
        $todo = array_slice($todo, 0, $maxShards, true);
    }
    foreach ($todo as $id => $cur) {
        try {
            $payload = repo_fetch_json('shards/' . $id . '.json');
            $out['requests']++;
            if ($payload === null) {
                throw new RuntimeException('下载失败');
            }
            $st = repo_import_payload($payload, 'shard:' . $id);
            $out['insert'] += $st['insert'];
            $out['update'] += $st['update'];
            $out['same'] += $st['same'];
            $out['total'] += $st['total'];
            $out['shards'][] = $id;
            $state['shards'][$id] = $cur;
            $lines[] = sprintf('分片 %-9s %d 条：新增 %d，更新 %d，未变 %d',
                $id, $st['total'], $st['insert'], $st['update'], $st['same']);
        } catch (Throwable $e) {
            // 失败的分片不写状态，下次自动重试
            $out['ok'] = false;
            $lines[] = sprintf('分片 %-9s 失败：%s', $id, $e->getMessage());
        }
    }

    if ($out['ok']) {
        $state['generated_at'] = $generatedAt;
    }
    $state['last_run'] = date('c');
    repo_sync_state_save($state);

    $elapsed = microtime(true) - $started;
    $dbTotal = (int) db()->query('SELECT COUNT(*) FROM resources')->fetchColumn();
    repo_log('sync_auto', $out['ok'], '自动同步', [
        'requests' => $out['requests'], 'insert' => $out['insert'], 'update' => $out['update'],
        'shards' => count($out['shards']), 'seconds' => round($elapsed, 1), 'total' => $dbTotal,
    ]);

    $lines[] = sprintf('%s：%d 次请求，新增 %d，更新 %d，耗时 %.1fs，库内 %d 条',
        $out['ok'] ? '完成' : '部分失败（未变化的分片下次重试）',
        $out['requests'], $out['insert'], $out['update'], $elapsed, $dbTotal);
    $out['message'] = implode("\n", $lines);
    return $out;
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
