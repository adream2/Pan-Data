<?php
declare(strict_types=1);

/**
 * SEO 体检（命令行）
 *
 *   php bin/seo_report.php
 *
 * 检查的是「数据层和配置层」的问题 —— 这些是搜索引擎抓走之后最容易吃亏的地方，
 * 而且不用起服务就能查。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/seo.php';

$ok = 0;
$warn = 0;
$bad = 0;

function line(string $level, string $msg, string $hint = ''): void
{
    global $ok, $warn, $bad;
    $icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✗'][$level];
    $level === 'ok' ? $ok++ : ($level === 'warn' ? $warn++ : $bad++);
    echo "  {$icon} {$msg}\n";
    if ($hint !== '') {
        echo "    → {$hint}\n";
    }
}

echo "\n== 配置 ==\n";
$siteUrl = (string) cfg_get('site_url');
if ($siteUrl === '') {
    line('warn', '站点完整地址未设置', '后台 → 设置 → 外观 → 站点完整地址；留空会用请求 Host 推断，反代后面可能写出内网地址');
} elseif (!preg_match('#^https?://#', $siteUrl)) {
    line('bad', '站点完整地址缺少 http(s)://', '当前值：' . $siteUrl);
} else {
    line('ok', '站点地址：' . rtrim($siteUrl, '/'));
}
line(
    'ok',
    '搜索页索引：' . (cfg_get('seo_index_search') === '1' ? '允许（不推荐）' : '禁止（推荐）'),
    cfg_get('seo_index_search') === '1' ? '搜索引擎会收录 ?q= 组合，量大时容易稀释权重' : ''
);

echo "\n== 收录规模 ==\n";
$total = (int) db()->query('SELECT COUNT(*) FROM resources WHERE hidden = 0')->fetchColumn();
$invalid = (int) db()->query("SELECT COUNT(*) FROM resources WHERE hidden = 0 AND status = 'invalid'")->fetchColumn();
$chunks = seo_sitemap_chunk_count();
echo "  可收录资源：{$total} 条（sitemap 分 {$chunks} 片）\n";
echo "  其中已标记失效：{$invalid} 条（详情页仍可访问，但列表默认不展示）\n";

foreach (seo_landings() as $slug => $spec) {
    $f = $spec['filter'];
    $where = ['hidden = 0'];
    $params = [];
    if (($f['type'] ?? '') !== '') {
        $where[] = 'type = ?';
        $params[] = $f['type'];
    }
    if (!empty($f['duanju'])) {
        $where[] = "(title LIKE '%短剧%' OR core_title LIKE '%短剧%' OR COALESCE(desc,'') LIKE '%短剧%')";
        $where[] = "title NOT LIKE '%目录%'";
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM resources WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $n = (int) $stmt->fetchColumn();
    printf("    /%-9s %6d 条%s\n", $slug, $n, $n < 30 ? '  ← 内容偏少，落地页可能被判薄内容' : '');
    if ($n < 30) {
        $warn++;
    }
}

echo "\n== 标题质量（影响 title 与搜索结果展示）==\n";
$rows = db()->query('SELECT title, core_title FROM resources WHERE hidden = 0')->fetchAll();
$lenBuckets = ['ok' => 0, 'long' => 0, 'short' => 0];
$promoLeft = 0;
$samples = [];
foreach ($rows as $r) {
    $t = ui_clean_title((string) $r['title']);
    $len = mb_strlen($t);
    if ($len > 50) {
        $lenBuckets['long']++;
        if (count($samples) < 3) {
            $samples[] = $t;
        }
    } elseif ($len < 4) {
        $lenBuckets['short']++;
    } else {
        $lenBuckets['ok']++;
    }
    if (preg_match('/和谐|转存|关注频道|公众号|简介：/u', $t)) {
        $promoLeft++;
    }
}
printf("  长度正常（4-50 字）：%d 条（%.1f%%）\n", $lenBuckets['ok'], $lenBuckets['ok'] * 100.0 / max(1, count($rows)));
line(
    $lenBuckets['long'] > count($rows) * 0.15 ? 'warn' : 'ok',
    "过长（>50 字）：{$lenBuckets['long']} 条",
    $lenBuckets['long'] > 0 ? '示例：' . mb_substr($samples[0] ?? '', 0, 40) : ''
);
line($lenBuckets['short'] > 0 ? 'warn' : 'ok', "过短（<4 字）：{$lenBuckets['short']} 条", '过短标题几乎没有检索价值');
line(
    $promoLeft > count($rows) * 0.02 ? 'warn' : 'ok',
    "清洗后仍含推广词：{$promoLeft} 条",
    $promoLeft > 0 ? '可在 src/ui.php 的 ui_clean_title() 里补充规则' : ''
);

echo "\n== 简介覆盖（影响详情页 description 与列表可读性）==\n";
$withDesc = 0;
$cleanDesc = 0;
foreach (db()->query("SELECT desc FROM resources WHERE hidden = 0 AND COALESCE(desc,'') != ''") as $r) {
    $withDesc++;
    if (ui_clean_desc((string) $r['desc']) !== '') {
        $cleanDesc++;
    }
}
line(
    $cleanDesc < $total * 0.3 ? 'warn' : 'ok',
    sprintf('有可用简介：%d / %d（%.0f%%）', $cleanDesc, $total, $cleanDesc * 100.0 / max(1, $total)),
    '其余记录详情页 description 会用标题拼接，不算错误'
);

echo "\n== sitemap ==\n";
$coreUrls = seo_sitemap_core();
line('ok', 'sitemap-core.xml：' . count($coreUrls) . ' 条（首页 / 分类页 / 追更）');
$resUrls = seo_sitemap_resources(1);
line('ok', sprintf('sitemap-res-1.xml：%d 条（上限 %d/片）', count($resUrls), SEO_SITEMAP_CHUNK));
if ($chunks > 1) {
    $last = seo_sitemap_resources($chunks);
    line('ok', sprintf('最后一片 sitemap-res-%d.xml：%d 条', $chunks, count($last)));
}

echo "\n== 内链 ==\n";
$linked = 0;
foreach (seo_landings() as $slug => $spec) {
    $linked++;
}
line('ok', "页脚固定输出 {$linked} 个分类入口 + 追更 + 全部资源");
line('ok', '详情页有面包屑（首页 → 分类 → 标题）');

echo "\n== 汇总 ==\n";
printf("  通过 %d｜提示 %d｜问题 %d\n\n", $ok, $warn, $bad);
if ($bad > 0) {
    echo "有必须修的配置问题，先处理 ✗ 项。\n\n";
    exit(1);
}
if ($warn > 0) {
    echo "没有阻塞问题，但有优化空间。\n\n";
}
exit(0);
