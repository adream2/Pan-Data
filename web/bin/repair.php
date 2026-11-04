<?php
declare(strict_types=1);

/**
 * 一次性数据修复：清洗存量 mirrors。
 *
 * 背景：采集端早期生成 mirrors 时只排除了自己，没有按 URL 去重，
 * 也没过滤抓取时被截断的残链，导致单条资源最多挂了 503 个镜像
 * （同一 share_url 重复几十次 + `pan.baidu.com/s/16D` 这种打不开的链接）。
 * 采集端已修，但库里的存量数据不会自己变干净——而「自动换源」正是靠 mirrors，
 * 切到残链等于把用户送进死胡同。
 *
 * 用法：php web/bin/repair.php [--dry]
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';

$dry = in_array('--dry', $argv, true);

$pdo = db();
$rows = $pdo->query("SELECT id, share_url, mirrors_json FROM resources
    WHERE mirrors_json != '[]' ORDER BY json_array_length(mirrors_json) DESC")->fetchAll();

$before = $after = $changed = $droppedDup = $droppedShort = 0;
$upd = $pdo->prepare('UPDATE resources SET mirrors_json = ? WHERE id = ?');

if (!$dry) {
    $pdo->beginTransaction();
}
try {
    foreach ($rows as $r) {
        $old = json_decode((string) $r['mirrors_json'], true);
        if (!is_array($old)) {
            continue;
        }
        $before += count($old);
        $new = repo_clean_mirrors($old, (string) $r['share_url']);
        $after += count($new);

        // 统计丢弃原因，方便判断采集端是否还有没修干净的地方
        $keptUrls = array_column($new, 'share_url');
        foreach ($old as $m) {
            $u = (string) ($m['share_url'] ?? '');
            if ($u === '' || in_array($u, $keptUrls, true)) {
                continue;
            }
            $path = preg_replace('#\?.*$#', '', trim((string) parse_url($u, PHP_URL_PATH), '/')) ?? '';
            $tail = $path !== '' ? basename($path) : '';
            if (mb_strlen($tail) < 6) {
                $droppedShort++;
            } else {
                $droppedDup++;
            }
        }

        if ($new !== $old) {
            $changed++;
            if (!$dry) {
                $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) $r['id']]);
            }
        }
    }
    if (!$dry) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if (!$dry && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '失败：' . $e->getMessage() . "\n");
    exit(1);
}

if (!$dry) {
    repo_cache_flush();
}

printf("%s扫描 %d 条含镜像的资源\n", $dry ? '[dry] ' : '', count($rows));
printf("镜像条目：%d → %d（减少 %d）\n", $before, $after, $before - $after);
printf("改动资源：%d 条；丢弃重复 %d、残链 %d\n", $changed, $droppedDup, $droppedShort);
