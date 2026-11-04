<?php
declare(strict_types=1);

// 命令行同步，适合放 crontab。
//
// 日常只需要这一条（每 30 分钟）：
//   php /path/to/web/bin/sync.php auto
// 首次部署想强制全量：
//   php /path/to/web/bin/sync.php all
//
// auto 会拿 index.json 跟上次的记录比对，只下载真正变化的热文件和分片；
// 数据没变化时一次请求都不发（只读 index.json 就退出）。
// 注意：cron 表达式别写进块注释，里面的星号加斜杠会提前闭合注释。

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';

$cmd = $argv[1] ?? 'hot';

try {
    switch ($cmd) {
        case 'index':
            $index = repo_index();
            if ($index === null) {
                fwrite(STDERR, "拉取 index.json 失败" . PHP_EOL);
                exit(1);
            }
            fwrite(STDOUT, '总库存 ' . ($index['totals']['resources'] ?? '?') . ' 条，分片 ' . count($index['shards'] ?? []) . ' 个' . PHP_EOL);
            foreach (($index['shards'] ?? []) as $s) {
                fwrite(STDOUT, sprintf("  %-9s %6d 条  %s ~ %s\n", $s['id'], $s['resources'], $s['from'], $s['to']));
            }
            break;

        case 'hot':
            $stat = repo_import_hot();
            fwrite(STDOUT, sprintf(
                "热文件导入：新增 %d，更新 %d，未变 %d，跳过 %d\n",
                $stat['insert'], $stat['update'], $stat['same'] ?? 0, $stat['skip']
            ));
            break;

        case 'shard':
            $id = $argv[2] ?? '';
            if ($id === '') {
                fwrite(STDERR, '用法：php bin/sync.php shard 2026-10' . PHP_EOL);
                exit(1);
            }
            $stat = repo_import_shard($id);
            fwrite(STDOUT, sprintf(
                "分片 %s 导入：新增 %d，更新 %d，未变 %d\n",
                $id, $stat['insert'], $stat['update'], $stat['same'] ?? 0
            ));
            break;

        case 'auto':
            // 增量：只拉变化的热文件与分片，cron 每 30 分钟跑一次
            $r = repo_auto_sync(0);
            fwrite(STDOUT, $r['message'] . PHP_EOL);
            exit($r['ok'] ? 0 : 1);

        case 'all':
            // 首次部署用：热文件只保留最近 8000 条，历史在分片里，
            // 只导 hot 会有大量资源搜不到
            $index = repo_index();
            if ($index === null) {
                fwrite(STDERR, '拉取 index.json 失败，检查网络/代理/Token' . PHP_EOL);
                exit(1);
            }
            $st = repo_import_hot();
            fwrite(STDOUT, sprintf("热文件：新增 %d，更新 %d，跳过 %d" . PHP_EOL, $st['insert'], $st['update'], $st['skip']));
            $shards = $index['shards'] ?? [];
            $totalIns = $st['insert'];
            $totalUpd = $st['update'];
            $failed = [];
            foreach ($shards as $i => $s) {
                $id = (string) ($s['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                try {
                    $r = repo_import_shard($id);
                    $totalIns += $r['insert'];
                    $totalUpd += $r['update'];
                    fwrite(STDOUT, sprintf(
                        "  [%2d/%2d] %-9s 新增 %4d 更新 %4d" . PHP_EOL,
                        $i + 1, count($shards), $id, $r['insert'], $r['update']
                    ));
                } catch (Throwable $e) {
                    $failed[] = $id;
                    fwrite(STDOUT, sprintf("  [%2d/%2d] %-9s 失败：%s" . PHP_EOL, $i + 1, count($shards), $id, $e->getMessage()));
                }
            }
            fwrite(STDOUT, sprintf(
                "全部完成：新增 %d，更新 %d，库内 %d 条%s" . PHP_EOL,
                $totalIns, $totalUpd,
                (int) db()->query('SELECT COUNT(*) FROM resources')->fetchColumn(),
                $failed !== [] ? '（失败分片：' . implode(',', $failed) . '）' : ''
            ));
            break;

        default:
            fwrite(STDERR, '用法：php bin/sync.php [index|hot|auto|all|shard <id>]' . PHP_EOL);
            exit(1);
    }
} catch (Throwable $e) {
    repo_log('cli_sync', false, $e->getMessage());
    fwrite(STDERR, '同步失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
