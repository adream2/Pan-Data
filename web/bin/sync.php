<?php
declare(strict_types=1);

// 命令行同步，适合放 crontab：
//   每 30 分钟   php /path/to/web/bin/sync.php hot
//   每天 04:05   php /path/to/web/bin/sync.php shard 2026-10
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
            fwrite(STDOUT, sprintf("热文件导入：新增 %d，更新 %d，跳过 %d\n", $stat['insert'], $stat['update'], $stat['skip']));
            break;

        case 'shard':
            $id = $argv[2] ?? '';
            if ($id === '') {
                fwrite(STDERR, '用法：php bin/sync.php shard 2026-10' . PHP_EOL);
                exit(1);
            }
            $stat = repo_import_shard($id);
            fwrite(STDOUT, sprintf("分片 %s 导入：新增 %d，更新 %d\n", $id, $stat['insert'], $stat['update']));
            break;

        default:
            fwrite(STDERR, '用法：php bin/sync.php [index|hot|shard <id>]' . PHP_EOL);
            exit(1);
    }
} catch (Throwable $e) {
    repo_log('cli_sync', false, $e->getMessage());
    fwrite(STDERR, '同步失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
