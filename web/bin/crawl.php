<?php
declare(strict_types=1);

/**
 * 命令行采集（t.me/s 网页预览）。
 *   php bin/crawl.php freekecheng,yunpanNB 1
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/crawler.php';

$list = $argv[1] ?? implode(',', (array) cfg('crawler.channels', []));
$pages = max(1, (int) ($argv[2] ?? cfg('crawler.pages', 1)));
$channels = array_values(array_filter(array_map('trim', explode(',', (string) $list))));

if (!$channels) {
    fwrite(STDERR, "用法：php bin/crawl.php <频道,逗号分隔> [页数]" . PHP_EOL);
    exit(1);
}

$total = 0;
foreach ($channels as $ch) {
    if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $ch)) {
        fwrite(STDERR, "跳过非法频道名：{$ch}" . PHP_EOL);
        continue;
    }
    $stat = crawler_ingest($ch, $pages);
    $total += $stat['insert'];
    fwrite(STDOUT, sprintf("%-18s 页 %d｜消息 %d｜链接 %d｜新增 %d｜重复 %d｜拦截 %d\n",
        $ch, $stat['pages'], $stat['messages'], $stat['links'], $stat['insert'], $stat['dup'], $stat['blocked']));
}
fwrite(STDOUT, "合计新增 {$total} 条" . PHP_EOL);
