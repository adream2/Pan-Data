<?php
/**
 * 频道页头部：H1 + 规模统计 + 内容构成 + 动态说明
 *
 * @var array $ch              repo_channel() 的返回
 * @var array $channelTypes    repo_channel_types()
 * @var array $channelProviders repo_channel_providers()
 * @var int   $total           当前筛选下的条数
 */
$ch = $ch ?? [];
$channelTypes = $channelTypes ?? [];
$channelProviders = $channelProviders ?? [];
$total = (int) ($total ?? 0);
$count = max(1, (int) ($ch['resources'] ?? 1));

$topType = repo_channel_lead_type($channelTypes, (int) $ch['resources']);
$topProv = $channelProviders[0] ?? null;
$channelName = (string) ($ch['channel'] ?? '');

$pct = static fn(int $n): int => (int) round($n * 100 / $count);

// 这段说明按频道动态拼，每个频道都不一样 —— 详情页/频道页最缺的就是「本页独有文字」
$intro = '这是公开频道 @' . $channelName . ' 的资源归档。本站从该频道的公开网页预览里采集到 '
    . number_format((int) $ch['resources']) . ' 条网盘分享，覆盖 '
    . (int) $ch['platforms'] . ' 个网盘平台、' . (int) $ch['types'] . ' 种内容类型';
if ($topProv !== null) {
    $intro .= '，其中' . ui_provider_name((string) $topProv['provider']) . '链接占 ' . $pct((int) $topProv['c']) . '%';
}
if ($topType !== null) {
    $intro .= '，内容以' . ui_type_name((string) $topType['type']) . '为主（' . $pct((int) $topType['c']) . '%）';
}
$intro .= '。收录区间 ' . date('Y-m-d', strtotime((string) $ch['earliest']))
    . ' 至 ' . date('Y-m-d', strtotime((string) $ch['latest'])) . '。';
?>
<nav class="mb-2 flex flex-wrap items-center gap-1 px-2 text-xs text-gray-500 sm:px-0" aria-label="面包屑">
  <a class="transition hover:text-gray-700" href="/">首页</a>
  <span aria-hidden="true">/</span>
  <a class="transition hover:text-gray-700" href="/channels">来源频道</a>
  <span aria-hidden="true">/</span>
  <span class="text-gray-400">@<?= e($channelName) ?></span>
</nav>

<section class="mx-auto mb-4 w-full max-w-3xl px-2 sm:px-0">
  <h1 class="text-lg font-semibold tracking-tight text-gray-900 sm:text-xl">
    @<?= e($channelName) ?> 频道资源
  </h1>
  <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500">
    <span>共 <span class="font-medium tabular-nums text-gray-700"><?= number_format($total) ?></span> 条</span>
    <span class="text-gray-300">|</span>
    <span><?= (int) $ch['platforms'] ?> 个平台</span>
    <span class="text-gray-300">|</span>
    <span>最近更新 <?= e(ui_rel_time((string) $ch['latest'])) ?></span>
    <?php if ((int) $ch['invalid'] > 0): ?>
      <span class="text-gray-300">|</span>
      <span class="text-rose-600"><?= (int) $ch['invalid'] ?> 条已失效</span>
    <?php endif; ?>
  </p>

  <p class="mt-2 text-[13px] leading-6 text-gray-600"><?= e($intro) ?></p>

  <?php if (count($channelTypes) > 1 || count($channelProviders) > 1): ?>
  <div class="mt-3 flex flex-wrap items-center gap-1.5">
    <?php foreach (array_slice($channelProviders, 0, 6) as $p): ?>
      <span class="pill">
        <span class="dot shrink-0" style="background:<?= e(ui_provider_color((string) $p['provider'])) ?>"></span>
        <?= e(ui_provider_name((string) $p['provider'])) ?>
        <span class="pill-count"><?= number_format((int) $p['c']) ?></span>
      </span>
    <?php endforeach; ?>
    <?php foreach (array_slice($channelTypes, 0, 6) as $t): ?>
      <span class="pill">
        <?= e(ui_type_name((string) $t['type'])) ?>
        <span class="pill-count"><?= number_format((int) $t['c']) ?></span>
      </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
