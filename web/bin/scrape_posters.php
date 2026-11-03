<?php
declare(strict_types=1);

/**
 * 封面刮削 CLI（适合放 crontab）
 *
 *   php bin/scrape_posters.php            # 刮 20 条影视
 *   php bin/scrape_posters.php 100        # 刮 100 条
 *   php bin/scrape_posters.php 50 all     # 所有类型
 *   php bin/scrape_posters.php stats      # 只看统计
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/poster.php';

$arg = $argv[1] ?? '20';

if ($arg === 'stats') {
    $s = poster_stats();
    printf("已覆盖 %d / 影视 %d（%.1f%%）｜已试未命中 %d｜本地化 %d\n",
        $s['covered'], $s['movie_total'], $s['rate'], $s['missed'], $s['local']);
    exit(0);
}

$limit = max(1, (int) $arg);
$type = (string) ($argv[2] ?? 'movie');

if (cfg_get('poster_enabled') !== '1') {
    fwrite(STDERR, "封面刮削未开启：后台 → 设置 → 封面 → 启用\n");
    exit(1);
}
if (cfg_get('poster_tmdb_key') === '') {
    fwrite(STDERR, "缺少 TMDB API Key：后台 → 设置 → 封面 → 填入\n");
    exit(1);
}

printf("开始刮削：%s，%d 条，间隔 %d 秒\n", $type, $limit, cfg_get_int('poster_interval'));
$r = poster_batch($limit, $type);
printf("完成：命中 %d，未命中 %d，处理 %d 条\n", $r['ok'], $r['miss'], $r['total'] ?? 0);
$s = poster_stats();
printf("累计覆盖 %d / %d（%.1f%%）\n", $s['covered'], $s['movie_total'], $s['rate']);
