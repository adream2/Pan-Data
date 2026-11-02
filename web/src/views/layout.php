<?php /** @var string $content @var string $title */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? setting('site_name', cfg('site_name'))) ?></title>
<meta name="description" content="<?= e(setting('site_desc', cfg('site_slogan'))) ?>">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen">
<header class="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md">
  <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
    <a href="/" class="flex shrink-0 items-center gap-2">
      <span class="grid size-8 place-items-center rounded-lg bg-neutral-900 text-sm font-semibold text-white">盘</span>
      <span class="hidden text-[15px] font-semibold tracking-tight sm:block"><?= e(setting('site_name', cfg('site_name'))) ?></span>
    </a>
    <form action="/search" method="get" class="relative flex-1 max-w-xl">
      <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
        <circle cx="9" cy="9" r="6"/><path d="m13.5 13.5 3.5 3.5"/>
      </svg>
      <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="搜课程 / 影视 / 软件，空格分词"
             class="h-10 w-full rounded-full border-0 bg-neutral-100 pl-9 pr-4 text-sm placeholder:text-neutral-400 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
    </form>
    <nav class="flex shrink-0 items-center gap-1 text-sm">
      <a href="/submit" class="rounded-lg px-3 py-2 text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900">投稿</a>
      <a href="/admin.php" class="rounded-lg px-3 py-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900">后台</a>
    </nav>
  </div>
</header>

<?php if ($f = flash()): ?>
<div class="mx-auto max-w-6xl px-4 pt-4 sm:px-6">
  <div class="rounded-lg px-4 py-3 text-sm <?= $f['type'] === 'err'
      ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
      : 'bg-brand-50 text-brand-700 ring-1 ring-brand-100' ?>"><?= e($f['msg']) ?></div>
</div>
<?php endif; ?>
<?php if ($notice = setting('notice')): ?>
<div class="mx-auto max-w-6xl px-4 pt-4 sm:px-6">
  <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200"><?= e($notice) ?></div>
</div>
<?php endif; ?>

<main class="mx-auto max-w-6xl px-4 pb-16 sm:px-6">
<?= $content ?>
</main>

<footer class="border-t border-neutral-200/70">
  <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-6 text-xs text-neutral-500 sm:flex-row sm:items-center sm:justify-between sm:px-6">
    <span><?= e(setting('site_name', cfg('site_name'))) ?> · 数据来自公开频道采集，仅提供检索，不存储文件</span>
    <span class="tabular-nums">已收录 <?= number_format((int) (repo_stats()['total'] ?? 0)) ?> 条</span>
  </div>
</footer>
</body>
</html>
