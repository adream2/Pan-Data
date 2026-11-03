<?php
/**
 * 追更日历：按日期分组展示最近有更新（集数变化）的剧集
 * @var array $rows    repo_updates() 结果
 * @var int   $days
 * @var int   $totalToday
 */
$days = (int) ($days ?? 7);
$totalToday = (int) ($totalToday ?? 0);
$PER_DAY = 25;
$grouped = [];
foreach ($rows as $r) {
    $day = substr((string) $r['last_at'], 0, 10);
    $grouped[$day][] = $r;
}
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

function ui_day_label(string $day, string $today, string $yesterday): string
{
    if ($day === $today) {
        return '今天';
    }
    if ($day === $yesterday) {
        return '昨天';
    }
    $ts = strtotime($day);
    $week = ['日', '一', '二', '三', '四', '五', '六'];
    return date('n月j日', $ts) . ' 周' . $week[(int) date('w', $ts)];
}
?>
<div class="mx-auto max-w-4xl px-2 sm:px-0">

  <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold tracking-tight text-gray-900">追更日历</h1>
      <p class="mt-1 text-sm text-gray-500">
        最近 <?= (int) $days ?> 天有集数变化的剧集，共 <span class="font-medium text-gray-700"><?= number_format(count($rows)) ?></span> 部（每天最多列 <?= $PER_DAY ?> 部）
        <?php if ($totalToday > 0): ?>
          · 今天更新 <span class="font-medium text-cyan-600"><?= (int) $totalToday ?></span> 部
        <?php endif; ?>
      </p>
    </div>
    <div class="flex gap-1 text-[11px]">
      <?php foreach ([7 => '近 7 天', 14 => '近 14 天', 30 => '近 30 天'] as $d => $label): ?>
        <a class="<?= (int) $days === $d ? 'rounded-full bg-slate-800 px-2.5 py-1 text-white' : 'rounded-full bg-gray-200 px-2.5 py-1 text-gray-700 hover:bg-gray-300' ?>"
           href="/updates?days=<?= $d ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($grouped === []): ?>
    <div class="rounded-lg bg-white px-6 py-16 text-center shadow-sm">
      <p class="text-sm text-gray-500">这段时间没有检测到集数更新的剧集</p>
      <p class="mt-1.5 text-xs text-gray-400">追更依赖资源标题里的「更至 X 集」，改名频繁的频道可能统计不到</p>
    </div>
  <?php else: ?>
    <?php foreach ($grouped as $day => $items): ?>
      <section class="mb-5">
        <h2 class="mb-2 flex items-center gap-2 px-1 text-sm font-medium text-gray-700">
          <?= e(ui_day_label($day, $today, $yesterday)) ?>
          <span class="text-[11px] font-normal text-gray-400">
            <?= count($items) ?> 部<?= count($items) > $PER_DAY ? '，显示前 ' . $PER_DAY : '' ?>
          </span>
        </h2>
        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
          <table class="w-full text-left">
            <tbody>
            <?php
            $shown = array_slice($items, 0, $PER_DAY);
            $rest = count($items) - count($shown);
            foreach ($shown as $it):
                $q = urlencode((string) $it['core_title']); ?>
              <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50">
                <td class="px-4 py-2">
                  <a class="text-[13px] text-gray-900 hover:text-blue-600" href="/search?q=<?= $q ?>"><?= e((string) $it['core_title']) ?></a>
                </td>
                <td class="w-24 px-2 py-2 whitespace-nowrap"><span class="tag"><?= (int) $it['max_ep'] ?> 集</span></td>
                <td class="w-16 px-2 py-2 text-[11px] text-gray-500 whitespace-nowrap">
                  <?= (int) $it['versions'] > 1 ? (int) $it['versions'] . ' 源' : '<span class="text-gray-300">—</span>' ?>
                </td>
                <td class="w-16 px-2 py-2 text-[11px] text-gray-400 whitespace-nowrap">
                  <?= (int) $it['platforms'] > 1 ? (int) $it['platforms'] . ' 端' : '<span class="text-gray-300">—</span>' ?>
                </td>
                <td class="w-14 px-3 py-2 text-right font-mono text-[11px] text-gray-400"><?= e(substr((string) $it['last_at'], 11, 5)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($rest > 0): ?>
              <tr><td colspan="5" class="px-4 py-2 text-[11px] text-gray-400">
                还有 <?= $rest ?> 部未显示 · 数据量大时按天只展示前 <?= $PER_DAY ?> 部
              </td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <p class="mt-6 px-1 text-[11px] leading-relaxed text-gray-400">
    数据来自资源标题里的集数标记（「更至 77 集」「全集」等）。频道改名或标题不含集数时统计不到；
    点剧名可以去搜索该剧的全部来源。
  </p>
</div>
