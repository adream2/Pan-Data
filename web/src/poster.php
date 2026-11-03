<?php
declare(strict_types=1);

/**
 * 封面刮削（TMDB）——**当前未接入界面**。
 *
 * 下线原因：命中率只有 20-30%（库存里大量是短剧，TMDB 没有条目），
 * 列表里一半有图一半没图，比统一色块更难用。前端已改为统一「类型色 + 首字」封面。
 *
 * 要重新启用需要三步：
 *   1. src/settings.php 的 cfg_spec() 里加回 poster_* 配置项（见 git 历史 1a0e529）
 *   2. src/ui.php 的 ui_cover() 里加回海报分支（同样见 1a0e529）
 *   3. 后台加回「封面刮削」页（public/admin.php 同 commit）
 *
 * 匹配逻辑本身是可用的，bin/test_poster_match.php 的 8 个用例仍在。
 * 原始说明：用 TMDB 按「标题 + 年份」匹配海报，缓存到 posters 表。
 *
 * 为什么是 TMDB：
 *   1. 有正式 API，免费，中文数据齐全；
 *   2. 图片 CDN（image.tmdb.org）实测可直连、无防盗链；
 *   3. 豆瓣搜索接口能用，但图片 CDN 在服务端取不到（实测 8 个域名组合全部连接失败），
 *      而浏览器直引用会被 418 拦，所以豆瓣这条路走不通。
 *
 * 匹配原则：宁缺毋滥。标题必须完全一致或互为前后缀，年份冲突直接放弃，
 * 因为「张冠李戴的海报」比「没有海报」更伤体验。
 */

const POSTER_TABLE = 'posters';

function poster_migrate(): void
{
    $pdo = db();
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS posters (
    match_key   TEXT PRIMARY KEY,
    resource_id TEXT NOT NULL DEFAULT '',
    source      TEXT NOT NULL DEFAULT 'tmdb',
    source_id   TEXT NOT NULL DEFAULT '',
    media_type  TEXT NOT NULL DEFAULT '',
    title       TEXT NOT NULL DEFAULT '',
    year        INTEGER,
    poster_path TEXT NOT NULL DEFAULT '',
    score       INTEGER NOT NULL DEFAULT 0,
    local_file  TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posters_resource ON posters(resource_id)');
}

/** 匹配键：归一化标题 + 年份，同一部剧的不同资源共用一条海报 */
function poster_key(string $coreTitle, ?int $year): string
{
    $t = mb_strtolower(trim($coreTitle));
    // 去掉版本/语种/清晰度等尾巴，避免「猜火车 德版」匹配不上「猜火车」
    $t = preg_replace('/(德版|国语|粤语|中字|内封|内嵌|蓝光|原盘|修复版|加长版|导演剪辑版)/u', '', $t) ?? $t;
    $t = preg_replace('/[^\p{Han}\p{L}\p{N}]/u', '', $t) ?? $t;
    return $t . '|' . ($year ?: '');
}

/** 归一化用于比较（保留中英文数字） */
function poster_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    // 显式去掉中英文括号书名号（\p{Han} 会命中部分 CJK 标点，不能只靠它）
    $s = preg_replace('/[【】《》「」『』（）()\[\]{}<>]/u', '', $s) ?? $s;
    // 去掉尾巴上的年份，避免「想飞的女孩2025」匹配不上「想飞的女孩」
    $s = preg_replace('/\d{4}$/u', '', $s) ?? $s;
    return preg_replace('/[^\p{Han}\p{L}\p{N}]/u', '', $s) ?? $s;
}

/**
 * 在 TMDB 里找最佳候选。
 * @return array{source_id:string, media_type:string, title:string, year:?int, poster_path:string, score:int}|null
 */
function poster_search(string $title, ?int $year, string $type): ?array
{
    $key = cfg_get('poster_tmdb_key');
    if ($key === '') {
        return null;
    }
    if (mb_strlen(poster_norm($title)) < 2) {
        return null;
    }
    $lang = cfg_get('poster_lang') ?: 'zh-CN';
    $minScore = cfg_get_int('poster_min_score');
    $endpoints = [];
    if ($type !== 'tv') {
        $endpoints[] = 'movie';
    }
    if ($type !== 'movie') {
        $endpoints[] = 'tv';
    }

    $best = null;
    foreach ($endpoints as $ep) {
        $url = 'https://api.themoviedb.org/3/search/' . $ep . '?'
            . http_build_query(['api_key' => $key, 'query' => $title, 'language' => $lang, 'include_adult' => 'false']);
        $raw = http_fetch($url, 20, (string) repo_conf('proxy', ''));
        if ($raw === null) {
            continue;
        }
        $data = json_decode($raw, true);
        $picked = poster_pick((array) ($data['results'] ?? []), $title, $year, $minScore, $ep);
        if ($picked !== null && ($best === null || $picked['score'] > $best['score'])) {
            $best = $picked;
        }
        if ($best !== null && $best['score'] >= 110) {
            break;  // 标题完全一致且年份吻合，不用再查另一个类型
        }
    }
    return ($best !== null && $best['poster_path'] !== '') ? $best : null;
}

/**
 * 从一批 TMDB 候选里挑最佳，纯函数、不联网，方便单测。
 * 规则：标题必须完全一致或互为前后缀/包含；年份冲突直接淘汰；分数不到阈值放弃。
 */
function poster_pick(array $results, string $title, ?int $year, int $minScore, string $mediaType = 'movie'): ?array
{
    $q = poster_norm($title);
    if ($q === '') {
        return null;
    }
    $best = null;
    foreach ($results as $r) {
        $name = (string) ($r['title'] ?? $r['name'] ?? '');
        $candYear = null;
        $date = (string) ($r['release_date'] ?? $r['first_air_date'] ?? '');
        if (preg_match('/^(\d{4})/', $date, $m)) {
            $candYear = (int) $m[1];
        }
        // 年份冲突：宁可不要，避免张冠李戴
        if ($year !== null && $candYear !== null && $year !== $candYear) {
            continue;
        }
        $cn = poster_norm($name);
        if ($cn === '') {
            continue;
        }
        // 没海报的条目不能占用 best，否则会挡住后面有图的候选
        if ((string) ($r['poster_path'] ?? '') === '') {
            continue;
        }
        if ($cn === $q) {
            $score = 100;
        } elseif (str_starts_with($cn, $q) || str_starts_with($q, $cn)) {
            $score = 85;
        } elseif (str_contains($cn, $q) || str_contains($q, $cn)) {
            $score = 75;
        } else {
            continue;
        }
        if ($year !== null && $candYear === $year) {
            $score += 10;
        }
        if ($score < $minScore) {
            continue;
        }
        if ($best === null || $score > $best['score']) {
            $best = [
                'source_id'   => (string) ($r['id'] ?? ''),
                'media_type'  => $mediaType,
                'title'       => $name,
                'year'        => $candYear,
                'poster_path' => (string) ($r['poster_path'] ?? ''),
                'score'       => min(120, $score),
            ];
        }
    }
    return $best;
}

/** 查已缓存的封面（按资源 id） */
function poster_of(string $resourceId): ?array
{
    static $cache = [];
    if (array_key_exists($resourceId, $cache)) {
        return $cache[$resourceId];
    }
    try {
        $stmt = db()->prepare('SELECT * FROM posters WHERE resource_id = ? AND poster_path != "" LIMIT 1');
        $stmt->execute([$resourceId]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable) {
        $row = null;
    }
    $cache[$resourceId] = $row;
    return $row;
}

/** 拼图片地址：优先本地文件，其次 TMDB CDN */
function poster_url(array $poster, string $size = 'w185'): string
{
    if (($poster['local_file'] ?? '') !== '') {
        return '/poster.php?f=' . urlencode((string) $poster['local_file']);
    }
    $size = in_array($size, ['w92', 'w154', 'w185', 'w342', 'w500'], true) ? $size : 'w185';
    return 'https://image.tmdb.org/t/p/' . $size . (string) $poster['poster_path'];
}

/** 单条资源的刮削：命中则写入 posters（同一 match_key 只查一次） */
function poster_fetch_resource(array $row, bool $force = false): array
{
    poster_migrate();
    $rid = (string) $row['id'];
    $title = (string) ($row['core_title'] ?: $row['title']);
    $year = !empty($row['year']) ? (int) $row['year'] : null;
    $type = (string) ($row['type'] ?? 'other');
    $key = poster_key($title, $year);

    if (!$force) {
        $hit = poster_of($rid);
        if ($hit !== null) {
            return ['ok' => true, 'cached' => true, 'poster' => $hit];
        }
        $stmt = db()->prepare('SELECT * FROM posters WHERE match_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $byKey = $stmt->fetch();
        if ($byKey && $byKey['poster_path'] !== '') {
            db()->prepare('INSERT OR REPLACE INTO posters(match_key,resource_id,source,source_id,media_type,title,year,poster_path,score,local_file,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$key, $rid, $byKey['source'], $byKey['source_id'], $byKey['media_type'],
                    $byKey['title'], $byKey['year'], $byKey['poster_path'], $byKey['score'], $byKey['local_file'], now_iso()]);
            return ['ok' => true, 'cached' => true, 'poster' => $byKey + ['resource_id' => $rid]];
        }
    }

    $found = poster_search($title, $year, $type);
    if ($found === null) {
        // 记一条空记录，避免每次都重新查（force 才重试）
        db()->prepare('INSERT OR REPLACE INTO posters(match_key,resource_id,source,title,year,score,created_at)
            VALUES(?,?,?,?,?,?,?)')
            ->execute([$key, $rid, 'tmdb', $title, $year, 0, now_iso()]);
        return ['ok' => false, 'reason' => '未命中', 'key' => $key];
    }

    $local = '';
    if (cfg_get('poster_localize') === '1') {
        $local = poster_download($found['poster_path']);
    }
    db()->prepare('INSERT OR REPLACE INTO posters(match_key,resource_id,source,source_id,media_type,title,year,poster_path,score,local_file,created_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$key, $rid, 'tmdb', $found['source_id'], $found['media_type'], $found['title'],
            $found['year'], $found['poster_path'], $found['score'], $local, now_iso()]);

    return ['ok' => true, 'cached' => false, 'poster' => $found + ['resource_id' => $rid, 'local_file' => $local]];
}

/** 下载海报到 storage/posters，返回文件名；失败返回空串（不影响主流程） */
function poster_download(string $posterPath): string
{
    $dir = APP_ROOT . '/storage/posters';
    @mkdir($dir, 0775, true);
    $name = trim(str_replace('/', '_', $posterPath), '_');
    $file = $dir . '/' . $name;
    if (is_file($file) && filesize($file) > 1024) {
        return $name;
    }
    $url = 'https://image.tmdb.org/t/p/w342' . $posterPath;
    $body = http_fetch($url, 25, (string) repo_conf('proxy', ''));
    if ($body === null || strlen($body) < 1024) {
        return '';
    }
    file_put_contents($file, $body);
    return $name;
}

/** 批量刮削：按「有年份优先、影视优先、未刮过」排序 */
function poster_batch(int $limit = 20, string $type = 'movie'): array
{
    poster_migrate();
    if (cfg_get('poster_enabled') !== '1' || cfg_get('poster_tmdb_key') === '') {
        return ['ok' => 0, 'miss' => 0, 'skip' => 0, 'note' => '未开启封面刮削或未配置 TMDB API Key'];
    }
    $limit = max(1, min($limit, 100));
    $sql = "SELECT r.* FROM resources r
        LEFT JOIN posters p ON p.resource_id = r.id AND p.poster_path != ''
        WHERE r.hidden = 0 AND p.match_key IS NULL";
    $params = [];
    if ($type !== '' && $type !== 'all') {
        $sql .= ' AND r.type = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY (r.year IS NULL) ASC, r.posted_at DESC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $ok = $miss = 0;
    $interval = cfg_get_int('poster_interval');
    foreach ($rows as $i => $row) {
        if ($i > 0 && $interval > 0) {
            sleep($interval);
        }
        $r = poster_fetch_resource($row);
        if ($r['ok']) {
            $ok++;
        } else {
            $miss++;
        }
    }
    repo_log('poster', true, "封面刮削：命中 {$ok}，未命中 {$miss}，共 " . count($rows) . ' 条');
    return ['ok' => $ok, 'miss' => $miss, 'skip' => 0, 'total' => count($rows), 'note' => '完成'];
}

function poster_stats(): array
{
    poster_migrate();
    $pdo = db();
    $out = [
        'cached'  => (int) $pdo->query("SELECT COUNT(*) FROM posters WHERE poster_path != ''")->fetchColumn(),
        'missed'  => (int) $pdo->query("SELECT COUNT(*) FROM posters WHERE poster_path = ''")->fetchColumn(),
        'local'   => (int) $pdo->query("SELECT COUNT(*) FROM posters WHERE local_file != ''")->fetchColumn(),
    ];
    $out['movie_total'] = (int) $pdo->query("SELECT COUNT(*) FROM resources WHERE hidden = 0 AND type = 'movie'")->fetchColumn();
    $out['covered'] = (int) $pdo->query("SELECT COUNT(DISTINCT r.id) FROM resources r
        JOIN posters p ON p.resource_id = r.id AND p.poster_path != '' WHERE r.hidden = 0")->fetchColumn();
    $out['rate'] = $out['covered'] > 0 && $out['movie_total'] > 0
        ? round($out['covered'] * 100 / $out['movie_total'], 1) : 0;
    return $out;
}
