<?php
declare(strict_types=1);

/**
 * robots.txt 动态输出。
 *
 * 策略：**不**在 robots.txt 里屏蔽 /search 参数页。
 * 那些页用 <meta name="robots" content="noindex,follow"> 控制 ——
 * 如果 robots.txt 先 Disallow 了，爬虫看不到 noindex，结果是被拦但已索引的 URL 永远清不掉。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/seo.php';

header('content-type: text/plain; charset=utf-8');
header('cache-control: public, max-age=3600');

$lines = [
    'User-agent: *',
    'Allow: /',
    'Disallow: /admin.php',
    'Disallow: /poster.php',
    '',
    '# 搜索参数页与筛选组合由页面级 noindex 控制，不在此拦截',
    '',
    'Sitemap: ' . rtrim(seo_base(), '/') . '/sitemap.xml',
];

echo implode("\n", $lines) . "\n";
