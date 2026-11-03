<?php
declare(strict_types=1);

/**
 * sitemap 输出（XML）
 *
 *   /sitemap.xml                -> sitemap 索引（列出下面两个）
 *   /sitemap-core.xml           -> 首页 + 分类落地页 + 追更
 *   /sitemap-res-{n}.xml        -> 详情页分片，每片 5000 条
 *
 * 通过 router.php / nginx rewrite 映射到本文件；也支持直接访问
 *   /sitemap.php?kind=index|core|res&n=1
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/seo.php';

$kind = (string) ($_GET['kind'] ?? 'index');
$n    = max(1, (int) ($_GET['n'] ?? 1));

header('content-type: application/xml; charset=utf-8');
header('cache-control: public, max-age=1800');

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($kind === 'index') {
    $today = gmdate('Y-m-d');
    echo $xml . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    printf("  <sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>\n", seo_xml(seo_abs('/sitemap-core.xml')), $today);
    $chunks = seo_sitemap_chunk_count();
    for ($i = 1; $i <= $chunks; $i++) {
        printf(
            "  <sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>\n",
            seo_xml(seo_abs('/sitemap-res-' . $i . '.xml')),
            $today
        );
    }
    echo '</sitemapindex>';
    exit;
}

echo $xml . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if ($kind === 'core') {
    foreach (seo_sitemap_core() as $u) {
        printf(
            "  <url><loc>%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%s</priority></url>\n",
            seo_xml($u['loc']), seo_xml($u['lastmod']), seo_xml($u['changefreq']), seo_xml($u['priority'])
        );
    }
} else {
    $chunks = seo_sitemap_chunk_count();
    if ($n > $chunks) {
        // 超出范围给空的 urlset，不要 404 —— 搜索引擎对 404 的 sitemap 更敏感
        echo "</urlset>";
        exit;
    }
    foreach (seo_sitemap_resources($n) as $u) {
        // Google 明确忽略 changefreq / priority，详情分片只留 loc + lastmod，省约 30% 体积
        printf(
            "  <url><loc>%s</loc><lastmod>%s</lastmod></url>\n",
            seo_xml($u['loc']), seo_xml($u['lastmod'])
        );
    }
}

echo '</urlset>';
