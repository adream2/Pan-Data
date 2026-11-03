<?php
/**
 * 列表页模板（首页与搜索结果共用，对齐 urldb 首页布局）
 * @var string $q
 * @var string $type
 * @var string $provider
 * @var array  $res
 * @var array  $facets
 * @var array  $stats
 * @var bool   $isHome
 * @var array  $hotWords
 */
$baseQuery = array_filter(['q' => $q, 'type' => $type, 'provider' => $provider], fn($v) => $v !== '');
$typesTotal = array_sum(array_column($facets['types'], 'c'));
$provTotal = array_sum(array_column($facets['providers'], 'c'));
?>
<section class="mx-auto mb-4 w-full max-w-3xl px-2 sm:mb-8 sm:px-0" aria-label="搜索">

  <form action="/search" method="get" class="flex items-center gap-2" role="search">
    <div class="relative flex-1">
      <svg class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <circle cx="9" cy="9" r="6"/><path d="m13.5 13.5 3.5 3.5"/>
      </svg>
      <input class="search-input" type="search" name="q" value="<?= e($q) ?>"
             placeholder="搜索资源名称、关键词…" aria-label="搜索资源"
             <?= $isHome ? 'autofocus' : '' ?>>
      <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
      <?php if ($provider !== ''): ?><input type="hidden" name="provider" value="<?= e($provider) ?>"><?php endif; ?>
    </div>
    <button class="search-btn" type="submit">搜索</button>
  </form>

  <!-- 平台筛选 -->
  <nav class="mt-4 flex flex-wrap gap-1.5" aria-label="平台筛选">
    <?= ui_pill(array_filter(['q' => $q, 'type' => $type], fn($v) => $v !== ''), '全部平台', $provider === '', $q === '' ? $provTotal : 0) ?>
    <?php foreach ($facets['providers'] as $pi => $p):
        $pv = (string) $p['provider'];
        // 小平台在手机上一次藏起来，避免筛选区吃掉首屏
        $hide = $pi >= 6 ? 'hidden sm:inline-flex' : ''; ?>
      <?= ui_pill(array_filter(['q' => $q, 'type' => $type, 'provider' => $pv], fn($v) => $v !== ''),
                  ui_provider_name($pv), $provider === $pv, $q === '' ? (int) $p['c'] : 0, ui_provider_color($pv), $hide) ?>
    <?php endforeach; ?>
  </nav>

  <!-- 类型筛选 -->
  <nav class="mt-2 flex flex-wrap gap-1.5" aria-label="类型筛选">
    <?= ui_pill(array_filter(['q' => $q, 'provider' => $provider], fn($v) => $v !== ''), '全部类型', $type === '', $q === '' ? $typesTotal : 0) ?>
    <?php foreach ($facets['types'] as $t):
        $tv = (string) $t['type']; ?>
      <?= ui_pill(array_filter(['q' => $q, 'provider' => $provider, 'type' => $tv], fn($v) => $v !== ''),
                  ui_type_name($tv), $type === $tv, $q === '' ? (int) $t['c'] : 0) ?>
    <?php endforeach; ?>
  </nav>

  <!-- 统计 -->
  <div class="mt-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-gray-200/70 px-1 pt-3 text-xs text-gray-600 sm:text-sm" role="status">
    <div class="flex items-center gap-4">
      <span class="inline-flex items-center gap-1">
        <svg class="size-3.5 text-cyan-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4.5" width="14" height="12" rx="2"/><path d="M3 8.5h14M7 3v3M13 3v3"/></svg>
        今日资源 <span class="ml-0.5 font-medium text-cyan-600"><?= number_format((int) $stats['today']) ?></span>
      </span>
      <span class="inline-flex items-center gap-1">
        <svg class="size-3.5 text-emerald-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m4 10.5 4 4 8-9"/></svg>
        有效链接 <span class="ml-0.5 font-medium text-emerald-600"><?= number_format((int) ($stats['valid'] ?? 0)) ?></span>
      </span>
    </div>
    <span class="inline-flex items-center gap-1">
      <svg class="size-3.5 text-blue-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><ellipse cx="10" cy="5" rx="6" ry="2.5"/><path d="M4 5v10c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5V5"/><path d="M4 10c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5"/></svg>
      总资源数 <span class="ml-0.5 font-medium text-blue-600"><?= number_format((int) $stats['total']) ?></span>
    </span>
  </div>

  <?php
  $sortNow = (string) ($_GET['sort'] ?? 'new');
  $sortLink = function (string $key) use ($q, $type, $provider): string {
      return '/search?' . http_build_query(array_filter(
          ['q' => $q, 'type' => $type, 'provider' => $provider, 'sort' => $key === 'new' ? '' : $key],
          fn($v) => $v !== ''
      ));
  };
  ?>
  <nav class="mt-4 flex flex-wrap items-center gap-1 border-t border-gray-200/70 px-1 pt-3 text-[11px] text-gray-500" aria-label="排序方式">
    <span class="mr-1 text-gray-400">排序</span>
    <?php foreach (['new' => '最新发布', 'sources' => '来源最多', 'episode' => '集数最多'] as $k => $label):
        $on = $sortNow === $k; ?>
      <a class="<?= $on ? 'font-medium text-blue-600 underline underline-offset-2' : 'rounded px-1.5 py-0.5 hover:bg-gray-100 hover:text-gray-700' ?>"
         href="<?= e($sortLink($k)) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <a class="ml-auto inline-flex items-center gap-1 text-blue-600 hover:underline" href="/updates">
      追更日历
      <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 5 5 5-5 5"/></svg>
    </a>
  </nav>

  <?php if ($hotWords !== []): ?>
  <div class="mt-2 flex flex-wrap items-center gap-1.5 px-1 pb-1 text-[11px] text-gray-400">
    <span class="mr-1">大家在搜</span>
    <?php foreach ($hotWords as $h): ?>
      <a class="transition hover:text-blue-600" href="/search?q=<?= e(urlencode((string) $h['q'])) ?>"><?= e((string) $h['q']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="panel" aria-label="资源列表">
  <?php if ($res['items'] === []): ?>
    <div class="flex flex-col items-center justify-center px-6 py-20 text-center">
      <svg class="mb-5 size-20 text-gray-200" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <rect x="10" y="18" width="44" height="32" rx="4"/><path d="M10 28h44"/><circle cx="17" cy="23" r="1.6" fill="currentColor"/>
        <path d="M22 40h20"/>
      </svg>
      <p class="text-base font-medium text-gray-600"><?= $q !== '' ? '没有找到相关资源' : '暂无资源数据' ?></p>
      <p class="mt-1.5 text-sm text-gray-400">
        <?= $q !== '' ? '换个关键词试试，或者' : '资源正在整理中，或者' ?>
        <a class="text-blue-600 hover:underline" href="/submit">投稿</a> 一条
      </p>
    </div>
  <?php else: ?>

    <!-- 移动端卡片流 -->
    <div class="space-y-2 p-2 sm:hidden">
      <?php foreach ($res['items'] as $row):
          $new = ui_is_today($row['posted_at']); ?>
        <article class="rounded-lg p-3 transition-colors <?= $new ? 'bg-cyan-50' : 'bg-gray-50' ?> active:bg-gray-100"
                 onclick="location.href='/detail?id=<?= e(urlencode((string) $row['id'])) ?>'">
          <div class="min-w-0">
            <div class="flex items-center gap-1">
              <span class="tag-gray shrink-0"><?= e(ui_type_name((string) $row['type'])) ?></span>
              <h3 class="clamp-1 text-sm font-medium text-gray-900"><?= ui_hl(ui_clean_title((string) $row['title']), $q) ?></h3>
            </div>
            <?php if ((string) $row['desc'] !== ''): ?>
              <p class="clamp-2 mt-1 text-xs text-gray-500"><?= ui_hl((string) $row['desc'], $q) ?></p>
            <?php endif; ?>
            <div class="mt-1.5 flex items-center justify-between gap-2">
              <span class="text-[11px] text-gray-400"><?= e(ui_rel_time($row['posted_at'])) ?></span>
              <span class="flex items-center gap-2">
                <?= ui_status((string) $row['status']) ?>
                <span class="text-[11px] text-blue-600">详情</span>
              </span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- 桌面端表格 -->
    <table class="res-table hidden sm:table">
      <thead>
        <tr>
          <th>文件名</th>
          <th class="w-24">平台</th>
          <th class="w-24">提取码</th>
          <th class="w-28">更新时间</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($res['items'] as $row):
          $new = ui_is_today($row['posted_at']);
          $href = '/detail?id=' . urlencode((string) $row['id']);
          $meta = ui_meta($row); ?>
        <tr class="<?= $new ? 'is-new' : '' ?>" onclick="location.href='<?= e($href) ?>'">
          <td>
            <div class="flex items-start gap-2">
              <span class="tag-gray mt-0.5 shrink-0"><?= e(ui_type_name((string) $row['type'])) ?></span>
              <div class="min-w-0 flex-1">
                <a class="block break-words text-base font-medium text-gray-900 transition hover:text-blue-600"
                   href="<?= e($href) ?>"><?= ui_hl(ui_clean_title((string) $row['title']), $q) ?></a>
                <?php if ($meta !== ''): ?>
                  <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-gray-400">
                    <span class="tag"><?= e($meta) ?></span>
                    <?php if ($new): ?><span class="rounded-full bg-cyan-100 px-2 py-0.5 text-[11px] font-medium text-cyan-700">今日新增</span><?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ((string) $row['desc'] !== ''): ?>
                  <div class="clamp-2 mt-1 text-xs text-gray-500"><?= ui_hl((string) $row['desc'], $q) ?></div>
                <?php endif; ?>
                <?php if ((string) $row['channel'] !== ''): ?>
                  <div class="mt-1 inline-flex items-center gap-1 text-[11px] text-cyan-600">
                    <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 15V6m0 0-3.5 3.5M10 6l3.5 3.5"/><path d="M4 14v2h12v-2"/></svg>
                    <?= e((string) $row['channel']) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <div class="flex flex-col gap-1 whitespace-nowrap">
              <span class="inline-flex items-center gap-1 text-gray-700">
                <i class="dot shrink-0" style="background:<?= e(ui_provider_color((string) $row['provider'])) ?>"></i>
                <?= e(ui_provider_name((string) $row['provider'])) ?>
              </span>
              <?= ui_status((string) $row['status']) ?>
            </div>
          </td>
          <td>
            <?php if ((string) $row['pwd'] !== ''): ?>
              <span class="tag-pwd"><?= e((string) $row['pwd']) ?></span>
            <?php else: ?>
              <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="text-xs text-gray-500" title="<?= e((string) $row['posted_at']) ?>"><?= e(ui_rel_time($row['posted_at'])) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<div class="mt-3 flex flex-wrap items-center justify-between gap-2 px-1 text-xs text-gray-500">
  <span>
    <?= $q !== '' ? '「' . e($q) . '」' : '最新收录' ?>
    · 共 <span class="tabular-nums font-medium text-gray-700"><?= number_format((int) $res['total']) ?></span> 条
    <?= $res['pages'] > 1 ? '· 第 ' . (int) $res['page'] . ' / ' . (int) $res['pages'] . ' 页' : '' ?>
  </span>
  <?php if ($q !== ''): ?>
    <a class="text-blue-600 hover:underline" href="/search">清除搜索</a>
  <?php endif; ?>
</div>

<?= ui_pager((int) $res['page'], (int) $res['pages'], $baseQuery) ?>
