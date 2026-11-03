<?php
/**
 * 频道页底部：同站其他频道（内链枢纽）+ 说明
 *
 * @var array  $allChannels repo_channels()
 * @var string $current     当前频道名
 */
$allChannels = $allChannels ?? [];
$current = (string) ($current ?? '');
$others = array_values(array_filter($allChannels, fn($c) => (string) $c['channel'] !== $current));
?>
<section class="mx-auto mt-8 w-full max-w-3xl border-t border-gray-200 px-2 pt-6 sm:px-0">
  <h2 class="mb-3 text-sm font-semibold text-gray-800">其他来源频道</h2>
  <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
    <?php foreach (array_slice($others, 0, 12) as $c): ?>
      <a class="flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 text-xs shadow-sm transition hover:shadow"
         href="/c/<?= e((string) $c['channel']) ?>">
        <span class="truncate text-gray-700">@<?= e((string) $c['channel']) ?></span>
        <span class="shrink-0 tabular-nums text-gray-400"><?= number_format((int) $c['resources']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-gray-500">
    <a class="text-blue-600 hover:underline" href="/channels">查看全部 <?= count($allChannels) ?> 个频道</a>
    <a class="text-blue-600 hover:underline" href="/search">浏览全部资源</a>
  </p>
  <p class="mt-4 text-[12px] leading-6 text-gray-500">
    频道内容由采集程序定时抓取该频道的公开网页预览，本站只保存链接与标题，不存储文件、不参与传播。
    频道本身若删除消息或改为私密，对应链接会失效，我们会在巡检时标记出来。
  </p>
</section>
