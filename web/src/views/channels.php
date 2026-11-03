<?php
/**
 * 频道总览 /channels
 * @var array $channels repo_channels()
 * @var int   $totalResources
 */
$channels = $channels ?? [];
$totalResources = (int) ($totalResources ?? 0);
$sum = array_sum(array_map(fn($c) => (int) $c['resources'], $channels));
?>
<nav class="mb-2 flex flex-wrap items-center gap-1 px-2 text-xs text-gray-500 sm:px-0" aria-label="面包屑">
  <a class="transition hover:text-gray-700" href="/">首页</a>
  <span aria-hidden="true">/</span>
  <span class="text-gray-400">来源频道</span>
</nav>

<section class="mx-auto mb-5 w-full max-w-3xl px-2 sm:px-0">
  <h1 class="text-lg font-semibold tracking-tight text-gray-900 sm:text-xl">来源频道</h1>
  <p class="mt-1 text-xs text-gray-500">
    <?= count($channels) ?> 个公开频道 · 合计 <span class="tabular-nums font-medium text-gray-700"><?= number_format($sum) ?></span> 条资源
  </p>
  <p class="mt-2 text-[13px] leading-6 text-gray-600">
    本站的内容全部来自下面这些公开 Telegram 频道。采集程序定时读取频道网页预览里的消息，
    从中提取网盘分享链接、标题和提取码，再做归一与去重。点进任意频道可以看到它收录的全部资源、
    内容构成和更新节奏 —— 判断一个号靠不靠谱，比看单条链接准得多。
  </p>
</section>

<section class="mx-auto w-full max-w-3xl px-2 sm:px-0" aria-label="频道列表">
  <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
    <?php foreach ($channels as $c):
        $n = max(1, (int) $c['resources']);
        $share = $totalResources > 0 ? round($n * 100 / $totalResources) : 0; ?>
      <a class="block rounded-lg bg-white p-3 shadow-sm transition hover:shadow-md"
         href="/c/<?= e((string) $c['channel']) ?>">
        <div class="flex items-baseline justify-between gap-2">
          <span class="truncate text-sm font-medium text-gray-800">@<?= e((string) $c['channel']) ?></span>
          <span class="shrink-0 text-xs tabular-nums text-gray-500"><?= number_format($n) ?> 条</span>
        </div>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-gray-400">
          <span><?= (int) $c['platforms'] ?> 个平台</span>
          <span class="text-gray-300">·</span>
          <span><?= (int) $c['types'] ?> 种类型</span>
          <span class="text-gray-300">·</span>
          <span>占全站 <?= $share ?>%</span>
          <?php if ((int) $c['invalid'] > 0): ?>
            <span class="text-gray-300">·</span>
            <span class="text-rose-500"><?= (int) $c['invalid'] ?> 失效</span>
          <?php endif; ?>
        </div>
        <div class="mt-1 text-[11px] text-gray-400">最近更新 <?= e(ui_rel_time((string) $c['latest'])) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="mx-auto mt-8 w-full max-w-3xl border-t border-gray-200 px-2 pt-6 text-[12px] leading-6 text-gray-500 sm:px-0">
  <p>
    频道清单由程序自动维护：从已有频道的互推消息里挖掘候选号，逐个探测单页产出率，
    达标的自动加入采集范围。所以这个列表会持续变化，来源越多，能搜到的东西越多。
  </p>
  <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
    <a class="text-blue-600 hover:underline" href="/search">浏览全部资源</a>
    <a class="text-blue-600 hover:underline" href="/updates">追更日历</a>
    <a class="text-blue-600 hover:underline" href="/submit">投稿一个链接</a>
  </p>
</section>
