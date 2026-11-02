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
<body>
<header class="top">
  <div class="wrap">
    <a class="brand" href="/"><?= e(setting('site_name', cfg('site_name'))) ?></a>
    <form class="quick" action="/search" method="get" role="search">
      <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="搜课程 / 影视 / 软件，空格分词" autocomplete="off">
      <button type="submit">搜索</button>
    </form>
    <nav class="nav">
      <a href="/submit">投稿</a>
      <a href="/admin.php">后台</a>
    </nav>
  </div>
</header>
<?php if ($f = flash()): ?>
<div class="wrap"><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div></div>
<?php endif; ?>
<?php if ($notice = setting('notice')): ?>
<div class="wrap"><div class="notice"><?= e($notice) ?></div></div>
<?php endif; ?>
<main class="wrap">
<?= $content ?>
</main>
<footer class="foot">
  <div class="wrap">
    <span><?= e(setting('site_name', cfg('site_name'))) ?> · 数据来自公开频道采集，仅供检索，不存储文件</span>
    <span class="muted">资源 <?= number_format((int) (repo_stats()['total'] ?? 0)) ?> 条</span>
  </div>
</footer>
</body>
</html>
