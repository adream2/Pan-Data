<?php
declare(strict_types=1);

/**
 * 链接健康度：举报联动下架 + 备用来源自动接管。
 *
 * 之前的流程是「用户举报 → 堆在后台 → 管理员手动点失效」。问题是：
 *  1. 没人管的后台等于没做，用户第二次点进来还是坏链接，直接走人；
 *  2. 就算标了失效，那条资源的备用链接（别的频道转发同一部剧）还是躺在
 *     mirrors 里没人用——本来换个链接就能救回来。
 *
 * 所以这里做两件事：
 *  - 多个不同来源举报「失效」达到阈值 → 自动标记 invalid（不等人工）；
 *  - 标记失效后，若 mirrors 里有状态更好的来源，直接提升为主链接，
 *    用户点进来拿到的仍然是能打开的地址。
 *
 * 全部走设置项，站长可以关掉自动判定，只保留人工。
 */

require_once __DIR__ . '/repo.php';

/** 计入「链接挂了」的举报类型 */
function health_bad_kinds(): array
{
    return ['invalid', 'wrong'];
}

/** 可被提升为主链接的镜像：排除明确失效的 */
function health_mirror_candidates(array $mirrors): array
{
    $good = [];
    foreach ($mirrors as $m) {
        if (!is_array($m)) {
            continue;
        }
        $url = (string) ($m['share_url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $status = (string) ($m['status'] ?? 'unchecked');
        if ($status === 'invalid') {
            continue;
        }
        $good[] = $m;
    }
    // valid 优先，其次未巡检；同类保持原有顺序
    usort($good, static function (array $a, array $b): int {
        $rank = static fn(array $m): int => match ((string) ($m['status'] ?? 'unchecked')) {
            'valid' => 0,
            'unchecked' => 1,
            default => 2,
        };
        return $rank($a) <=> $rank($b);
    });
    return $good;
}

/**
 * 把 mirrors 里状态最好的来源提升为主链接，原主链接降级为失效镜像。
 * 返回是否发生了切换。
 */
function health_promote_mirror(string $resourceId): bool
{
    $stmt = db()->prepare('SELECT * FROM resources WHERE id = ?');
    $stmt->execute([$resourceId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    $mirrors = json_decode((string) $row['mirrors_json'], true);
    if (!is_array($mirrors) || $mirrors === []) {
        return false;
    }
    $candidates = health_mirror_candidates($mirrors);
    if ($candidates === []) {
        return false;
    }

    $pick = $candidates[0];
    $pickUrl = (string) $pick['share_url'];

    // 原主链接进 mirrors：状态明确标成失效，避免下次又被当成候选
    $demoted = [
        'provider'  => (string) $row['provider'],
        'share_url' => (string) $row['share_url'],
        'pwd'       => ($row['pwd'] ?? null) !== null && $row['pwd'] !== '' ? (string) $row['pwd'] : null,
        'status'    => 'invalid',
    ];
    $rest = [];
    foreach ($mirrors as $m) {
        if (is_array($m) && (string) ($m['share_url'] ?? '') === $pickUrl) {
            continue;
        }
        $rest[] = $m;
    }
    $newMirrors = array_merge([$demoted], $rest);

    $newStatus = (string) ($pick['status'] ?? 'unchecked');
    if (!in_array($newStatus, ['valid', 'unchecked'], true)) {
        $newStatus = 'unchecked';
    }

    db()->prepare('UPDATE resources SET provider = ?, share_url = ?, pwd = ?, status = ?,
            mirrors_json = ?, checked_at = NULL, updated_at = ? WHERE id = ?')
        ->execute([
            (string) ($pick['provider'] ?? $row['provider']),
            $pickUrl,
            ($pick['pwd'] ?? null) !== null && $pick['pwd'] !== '' ? (string) $pick['pwd'] : null,
            $newStatus,
            json_encode($newMirrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            now_iso(),
            $resourceId,
        ]);

    repo_mark_dirty();
    if (function_exists('repo_log')) {
        repo_log('mirror_switch', true, '主链接失效，已切换到备用来源', [
            'id' => $resourceId, 'from' => (string) $row['provider'], 'to' => (string) ($pick['provider'] ?? ''),
        ]);
    }
    return true;
}

/**
 * 举报落地判定：够票就自动下线。
 *
 * @return array{count:int,bad:int,auto_invalid:bool,switched:bool}
 */
function health_after_report(string $resourceId): array
{
    $out = ['count' => 0, 'bad' => 0, 'auto_invalid' => false, 'switched' => false];

    $stmt = db()->prepare("SELECT COUNT(*) c, COUNT(DISTINCT ip) ips FROM reports
        WHERE resource_id = ? AND status = 'open'");
    $stmt->execute([$resourceId]);
    $row = $stmt->fetch() ?: [];
    $out['count'] = (int) ($row['c'] ?? 0);

    $stmt = db()->prepare("SELECT COUNT(*) c, COUNT(DISTINCT ip) ips FROM reports
        WHERE resource_id = ? AND status = 'open' AND kind IN ('invalid','wrong')");
    $stmt->execute([$resourceId]);
    $bad = $stmt->fetch() ?: [];
    // 用独立 IP 计数而不是条数：同一个人连点两次不算两个人说它坏了
    $out['bad'] = (int) ($bad['ips'] ?? 0);

    $threshold = function_exists('cfg_get_int') ? cfg_get_int('report_auto_invalid') : 0;
    if ($threshold > 0 && $out['bad'] >= $threshold) {
        $cur = db()->prepare('SELECT status, hidden FROM resources WHERE id = ?');
        $cur->execute([$resourceId]);
        $r = $cur->fetch();
        if ($r && (string) $r['status'] !== 'invalid') {
            db()->prepare("UPDATE resources SET status = 'invalid', checked_at = ?, updated_at = ? WHERE id = ?")
                ->execute([now_iso(), now_iso(), $resourceId]);
            $out['auto_invalid'] = true;
            repo_mark_dirty();
            if (function_exists('repo_log')) {
                repo_log('report_auto', true, '举报达阈值，自动标记失效', [
                    'id' => $resourceId, 'reports' => $out['bad'], 'threshold' => $threshold,
                ]);
            }

            $switch = !function_exists('cfg_get_bool') || cfg_get_bool('report_auto_switch');
            if ($switch) {
                $out['switched'] = health_promote_mirror($resourceId);
            }
        }
    }
    return $out;
}

/** 人工复核后重新排队：清掉巡检时间，让它在下次巡检里被优先看到 */
function health_requeue(string $resourceId): void
{
    db()->prepare("UPDATE resources SET status = 'unchecked', checked_at = NULL, updated_at = ? WHERE id = ?")
        ->execute([now_iso(), $resourceId]);
    repo_mark_dirty();
}

/**
 * 待复核队列：被举报、但还没被判定失效的资源。
 * 后台列表用，避免举报堆着没人看。
 */
function health_review_queue(int $limit = 50): array
{
    $limit = max(1, min($limit, 200));
    return db()->query("SELECT r.id, r.title, r.provider, r.status, r.share_url, r.mirrors_json,
            COUNT(*) reports, COUNT(DISTINCT rp.ip) ips, SUM(CASE WHEN rp.kind IN ('invalid','wrong') THEN 1 ELSE 0 END) bad,
            MAX(rp.created_at) last_at
        FROM reports rp JOIN resources r ON r.id = rp.resource_id
        WHERE rp.status = 'open'
        GROUP BY rp.resource_id
        ORDER BY bad DESC, reports DESC, last_at DESC
        LIMIT " . $limit)->fetchAll();
}
