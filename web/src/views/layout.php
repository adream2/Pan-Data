<?php
/**
 * @var string $content
 * @var string $title
 * @var string $description
 * @var string $canonical
 * @var string $robots
 * @var string $ogType
 * @var array  $jsonld
 */
$pageTitle = $title ?? setting('site_name', cfg('site_name'));
$pageDesc  = ($description ?? '') !== '' ? (string) $description : (string) setting('site_desc', cfg('site_slogan'));
$pageDesc  = mb_substr(trim(preg_replace('/\s+/u', ' ', $pageDesc) ?? $pageDesc), 0, 155);
$pageRobots = $robots ?? 'index, follow';
$pageCanonical = $canonical ?? '';
$pageOgType = $ogType ?? 'website';
$siteNameGlobal = setting('site_name', (string) cfg('site_name'));
// 一页只允许一个 h1：首页用站点名当 h1，其余页面 h1 交给正文（分类名 / 搜索结果 / 资源标题）
$siteTitleTag = ($siteHeading ?? false) ? 'h1' : 'div';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<meta name="robots" content="<?= e($pageRobots) ?>">
<?php if ($pageCanonical !== ''): ?>
<link rel="canonical" href="<?= e($pageCanonical) ?>">
<?php endif; ?>
<meta name="theme-color" content="#1e293b">
<meta property="og:type" content="<?= e($pageOgType) ?>">
<meta property="og:site_name" content="<?= e($siteNameGlobal) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDesc) ?>">
<?php if ($pageCanonical !== ''): ?>
<meta property="og:url" content="<?= e($pageCanonical) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Cpath d='M10 20.5V11.5h6.2a4.5 4.5 0 1 1 0 9H10Z' stroke='%23fff' stroke-width='2.2' fill='none'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/app.css">
<?php foreach ($jsonld ?? [] as $block): ?>
<?= seo_jsonld($block) ?>
<?php endforeach; ?>
</head>
<body class="flex min-h-screen flex-col bg-gray-50 text-gray-800">
<main class="flex-1 p-3 sm:p-5">
<div class="mx-auto max-w-7xl">

  <?php
  $siteName = setting('site_name', (string) cfg('site_name'));
  ?>
  <header class="site-header">
    <<?= $siteTitleTag ?> class="site-title">
      <svg class="size-8 shrink-0" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <rect x="2" y="2" width="28" height="28" rx="8" fill="#2563eb"/>
        <path d="M10 20.5V11.5h6.2a4.5 4.5 0 1 1 0 9H10Z" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
        <circle cx="16.4" cy="16" r="1.6" fill="#fff"/>
      </svg>
      <a href="/" class="text-white no-underline transition hover:text-gray-200"><?= e($siteName) ?></a>
    </<?= $siteTitleTag ?>>

    <?php // 前台只留访客能用的三件事：找资源、追更、投稿。没有账号体系，所以没有登录/用户/后台入口 ?>
    <nav class="header-nav" aria-label="主导航">
      <a class="header-btn hidden sm:inline-flex" href="/search" title="浏览全部资源">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="6"/><path d="m13.5 13.5 3.5 3.5"/></svg>
        全部资源
      </a>
      <a class="header-btn" href="/updates" title="看最近更新的剧集">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l3 2"/></svg>
        追更
      </a>
      <a class="header-btn" href="/submit" title="投稿一个网盘链接">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15V5m0 0-4 4m4-4 4 4"/></svg>
        投稿
      </a>
    </nav>

    <p class="mt-3 text-xs text-slate-300/80 sm:mt-4 sm:text-sm"><?= e(setting('site_desc', (string) cfg('site_slogan'))) ?></p>
  </header>

  <?php if ($notice = setting('notice')): ?>
  <aside class="mx-auto mb-2 flex max-w-3xl items-center gap-2 px-2 text-sm sm:px-0" aria-label="站内公告">
    <svg class="size-4 shrink-0 text-blue-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8v4h3l5 4V4L6 8H3Z"/><path d="M14 7a4 4 0 0 1 0 6"/></svg>
    <span class="text-gray-700"><?= e($notice) ?></span>
  </aside>
  <?php endif; ?>

  <?php if ($f = flash()): ?>
  <div class="mx-auto mb-3 max-w-3xl px-2 sm:px-0">
    <div class="rounded-lg px-4 py-2.5 text-sm <?= $f['type'] === 'err'
        ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
        : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' ?>"><?= e($f['msg']) ?></div>
  </div>
  <?php endif; ?>

  <?= $content ?>

</div>
</main>

<footer class="footer-banner mt-auto border-t border-slate-700 py-6 text-center text-xs text-slate-400">
  <div class="mx-auto max-w-7xl px-4">
    <p class="mb-2 flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
      <?php foreach (seo_landings() as $slug => $spec): ?>
        <a class="transition hover:text-slate-200" href="/<?= e($slug) ?>"><?= e((string) $spec['h1']) ?></a>
      <?php endforeach; ?>
      <a class="transition hover:text-slate-200" href="/updates">追更日历</a>
      <a class="transition hover:text-slate-200" href="/channels">来源频道</a>
      <a class="transition hover:text-slate-200" href="/search">全部资源</a>
    </p>
    <p class="mb-2 text-slate-400/90">本站内容由公开频道自动采集整理，仅提供链接检索，不存储、不复制、不传播任何文件。如有侵权请联系删除。</p>
    <p class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
      <span>© <?= date('Y') ?> <?= e($siteName) ?></span>
      <span class="text-slate-600">|</span>
      <span class="tabular-nums">已收录 <?= number_format((int) (repo_stats()['total'] ?? 0)) ?> 条资源</span>
    </p>
  </div>
</footer>
</body>
</html>
