<?php /** @var string $content @var string $title */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? setting('site_name', cfg('site_name'))) ?></title>
<meta name="description" content="<?= e(setting('site_desc', cfg('site_slogan'))) ?>">
<meta name="theme-color" content="#1e293b">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="flex min-h-screen flex-col bg-gray-50 text-gray-800">
<main class="flex-1 p-3 sm:p-5">
<div class="mx-auto max-w-7xl">

  <?php
  $navUser = function_exists('user_current') ? user_current() : null;
  $siteName = setting('site_name', (string) cfg('site_name'));
  ?>
  <header class="site-header">
    <h1 class="site-title">
      <svg class="size-8 shrink-0" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <rect x="2" y="2" width="28" height="28" rx="8" fill="#2563eb"/>
        <path d="M10 20.5V11.5h6.2a4.5 4.5 0 1 1 0 9H10Z" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
        <circle cx="16.4" cy="16" r="1.6" fill="#fff"/>
      </svg>
      <a href="/" class="text-white no-underline transition hover:text-gray-200"><?= e($siteName) ?></a>
    </h1>

    <nav class="header-nav" aria-label="主导航">
      <a class="header-btn hidden sm:inline-flex" href="/search" title="浏览全部资源">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="6"/><path d="m13.5 13.5 3.5 3.5"/></svg>
        全部资源
      </a>
      <a class="header-btn" href="/submit" title="投稿一个网盘链接">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15V5m0 0-4 4m4-4 4 4"/></svg>
        投稿
      </a>
      <a class="header-btn hidden sm:inline-flex" href="/api.php?action=stats" title="查看开放接口">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 6-4 4 4 4m6-8 4 4-4 4"/></svg>
        API
      </a>
      <?php if ($navUser): ?>
        <a class="header-btn" href="/user.php?r=me" title="用户中心">
          <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="7" r="3"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
          <?= e((string) $navUser['username']) ?>
        </a>
      <?php else: ?>
        <a class="header-btn" href="/user.php?r=login" title="登录账号">
          <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 7l3 3-3 3M15 10H4m8-6h4v12h-4"/></svg>
          登录
        </a>
      <?php endif; ?>
      <a class="header-btn hidden md:inline-flex" href="/admin.php" title="进入管理后台">
        <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2 3 5v6c0 4 3 6.5 7 7 4-.5 7-3 7-7V5l-7-3Z"/></svg>
        后台
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
    <p class="mb-2 text-slate-400/90">本站内容由公开频道自动采集整理，仅提供链接检索，不存储、不复制、不传播任何文件。如有侵权请联系删除。</p>
    <p class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
      <span>© <?= date('Y') ?> <?= e($siteName) ?></span>
      <span class="text-slate-600">|</span>
      <span class="tabular-nums">已收录 <?= number_format((int) (repo_stats()['total'] ?? 0)) ?> 条资源</span>
      <span class="text-slate-600">|</span>
      <a class="transition hover:text-slate-200" href="/api.php?action=stats">开放接口</a>
    </p>
  </div>
</footer>
</body>
</html>
