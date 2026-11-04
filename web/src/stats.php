<?php
declare(strict_types=1);

/**
 * 站内轻量统计：点击与搜索词。
 *
 * 这两块原先挂在用户模块里，但那套「注册 / 登录 / 收藏 / API Key」已经整体下线
 * （本站只服务匿名访客，管理只有一个站长账号），统计逻辑与用户无关，独立成文件。
 */

/** 资源详情点击（按天聚合，供后台看热门） */
function click_track(string $resourceId): void
{
    try {
        $day = gmdate('Y-m-d');
        db()->prepare('INSERT INTO clicks(resource_id, day, count) VALUES(?,?,1)
            ON CONFLICT(resource_id, day) DO UPDATE SET count = count + 1')->execute([$resourceId, $day]);
    } catch (Throwable $e) {
        // 统计是附属功能：同步任务正好在写库时可能拿不到锁，
        // 不能因为「少记一次点击」把整个详情页打成 500
    }
}

function click_count(string $resourceId): int
{
    $stmt = db()->prepare('SELECT SUM(count) FROM clicks WHERE resource_id = ?');
    $stmt->execute([$resourceId]);
    return (int) $stmt->fetchColumn();
}

/** 搜索词记录（首页"大家在搜"与后台热搜榜单的唯一来源） */
function search_track(string $q): void
{
    $q = mb_substr(trim($q), 0, 40);
    if ($q === '') {
        return;
    }
    try {
        $day = gmdate('Y-m-d');
        db()->prepare('INSERT INTO search_logs(q, day, count) VALUES(?,?,1)
            ON CONFLICT(q, day) DO UPDATE SET count = count + 1')->execute([$q, $day]);
    } catch (Throwable $e) {
        // 同上：搜索词热度记不上就算了，不能影响搜索结果页
    }
}

function top_searches(int $days = 7, int $limit = 10): array
{
    $since = gmdate('Y-m-d', time() - 86400 * $days);
    $stmt = db()->prepare('SELECT q, SUM(count) c FROM search_logs WHERE day >= ? GROUP BY q ORDER BY c DESC LIMIT ' . (int) $limit);
    $stmt->execute([$since]);
    return $stmt->fetchAll();
}

function top_resources(int $days = 7, int $limit = 10): array
{
    $since = gmdate('Y-m-d', time() - 86400 * $days);
    $stmt = db()->prepare('SELECT c.resource_id, SUM(c.count) hits, r.title
        FROM clicks c LEFT JOIN resources r ON r.id = c.resource_id
        WHERE c.day >= ? GROUP BY c.resource_id ORDER BY hits DESC LIMIT ' . (int) $limit);
    $stmt->execute([$since]);
    return $stmt->fetchAll();
}
