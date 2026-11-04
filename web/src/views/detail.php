<?php
/**
 * 详情页模板
 * @var array $row
 * @var array $related
 */
$href = (string) $row['share_url'];
$pwd = (string) ($row['pwd'] ?? '');
// 已明确失效的备用链接不展示：列出来用户点进去还是打不开，等于误导。
// 有效在前、未检在后，跟采集端分组优先级一致。
$allMirrors = array_values(array_filter($row['mirrors'] ?? [], static function ($m) {
    return is_array($m) && (string) ($m['status'] ?? 'unchecked') !== 'invalid'
        && (string) ($m['share_url'] ?? '') !== '';
}));
usort($allMirrors, static function (array $a, array $b): int {
    $rank = static fn(array $m): int => (string) ($m['status'] ?? 'unchecked') === 'valid' ? 0 : 1;
    return $rank($a) <=> $rank($b);
});
$meta = ui_meta($row);
$landingSlug = seo_landing_slug_for_type((string) $row['type']);
$landingSpec = $landingSlug !== null ? seo_landing($landingSlug) : null;
?>
<div class="mx-auto max-w-3xl px-2 sm:px-0">

  <nav class="flex flex-wrap items-center gap-1 text-xs text-gray-500" aria-label="面包屑">
    <a class="transition hover:text-gray-600" href="/">首页</a>
    <?php if ($landingSpec !== null): ?>
      <span aria-hidden="true">/</span>
      <a class="transition hover:text-gray-600" href="/<?= e($landingSlug) ?>"><?= e((string) $landingSpec['h1']) ?></a>
    <?php endif; ?>
    <span aria-hidden="true">/</span>
    <span class="truncate text-gray-400"><?= e(mb_substr(ui_clean_title((string) $row['title']), 0, 28)) ?></span>
  </nav>

  <a href="javascript:history.back()" class="mt-2 inline-flex items-center gap-1 text-xs text-gray-400 transition hover:text-gray-600">
    <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l-5 5 5 5"/></svg>
    返回列表
  </a>

  <article class="mt-3 overflow-hidden rounded-lg bg-white shadow-lg shadow-slate-900/10">

    <!-- 标题区 -->
    <div class="border-b border-gray-100 p-5 sm:p-6">
      <div class="min-w-0">
        <h1 class="break-words text-lg font-semibold leading-snug text-gray-900 sm:text-xl"><?= e(ui_clean_title((string) $row['title'])) ?></h1>
        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
          <span class="tag-gray"><?= e(ui_type_name((string) $row['type'])) ?></span>
          <span class="tag"><?= e(ui_provider_name((string) $row['provider'])) ?></span>
          <?php if ($meta !== ''): ?><span class="tag"><?= e($meta) ?></span><?php endif; ?>
          <?= ui_status((string) $row['status']) ?>
        </div>
      </div>
    </div>

    <!-- 主操作 -->
    <div class="p-5 sm:p-6">
      <?php if ((string) $row['status'] === 'invalid'): ?>
        <div class="mb-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-800 ring-1 ring-amber-200">
          <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 3 2.5 16h15L10 3Z"/><path d="M10 8v3.5M10 13.5v.5"/></svg>
          <span>当前主链接已失效。
            <?= $allMirrors !== [] ? '请改用下方的备用链接，或等待系统自动换源。' : '我们正在巡检，暂时没有可用来源。' ?>
          </span>
        </div>
      <?php endif; ?>
      <div class="flex flex-wrap items-center gap-3">
        <a class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-blue-600 px-6 text-sm font-medium text-white transition hover:bg-blue-700"
           href="<?= e($href) ?>" target="_blank" rel="nofollow noopener">
          打开网盘链接
          <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 13 13 7M8 7h5v5"/></svg>
        </a>
        <?php if ($pwd !== ''): ?>
          <button type="button" data-copy="<?= e($pwd) ?>"
                  class="inline-flex h-11 items-center gap-2 rounded-lg bg-amber-50 px-3 font-mono text-sm text-amber-700 ring-1 ring-amber-200 transition hover:bg-amber-100">
            提取码 <?= e($pwd) ?>
            <span class="text-[10px] font-sans text-amber-600/70">点击复制</span>
          </button>
        <?php endif; ?>
      </div>

      <p class="mt-3 break-all font-mono text-[11px] text-gray-400"><?= e($href) ?></p>

      <!-- 互动行：本站不设账号体系，只保留匿名举报 -->
      <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
        <span class="inline-flex items-center gap-1 text-[11px] text-gray-400">
          <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 10s3-5 8-5 8 5 8 5-3 5-8 5-8-5-8-5Z"/><circle cx="10" cy="10" r="2"/></svg>
          <?= number_format(click_count((string) $row['id'])) ?> 次浏览
        </span>

        <form method="post" action="<?= e(seo_detail_path((string) $row['id'])) ?>" class="ml-auto flex flex-wrap items-center gap-1.5">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="report">
          <input type="hidden" name="resource_id" value="<?= e((string) $row['id']) ?>">
          <select name="kind" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-xs text-gray-600 focus:outline-none">
            <option value="invalid">链接失效</option>
            <option value="wrong">内容不符</option>
            <option value="illegal">违规内容</option>
            <option value="other">其它</option>
          </select>
          <input name="note" maxlength="120" placeholder="补充说明"
                 class="h-8 w-28 rounded-md border border-gray-200 bg-white px-2 text-xs placeholder:text-gray-400 focus:outline-none sm:w-36">
          <button class="btn-ghost" type="submit">举报</button>
        </form>
      </div>
    </div>
  </article>

  <?php if ($allMirrors !== []): ?>
  <section class="mt-5">
    <h2 class="mb-2 px-1 text-xs font-medium uppercase tracking-wide text-gray-400">备用链接 · <?= count($allMirrors) ?> 个来源</h2>
    <ul class="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm">
      <?php foreach (array_slice($allMirrors, 0, 6) as $m): ?>
        <li class="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
          <span class="tag-gray shrink-0"><?= e(ui_provider_name((string) ($m['provider'] ?? ''))) ?></span>
          <a class="min-w-0 flex-1 break-all text-blue-600 hover:underline" href="<?= e((string) ($m['share_url'] ?? '#')) ?>"
             target="_blank" rel="nofollow noopener"><?= e((string) ($m['share_url'] ?? '')) ?></a>
          <?php if (!empty($m['pwd'])): ?><span class="tag-pwd"><?= e((string) $m['pwd']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (count($allMirrors) > 6): ?>
        <li class="px-4 py-3 text-[11px] text-gray-400">还有 <?= count($allMirrors) - 6 ?> 个来源未展开</li>
      <?php endif; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if (count($versions ?? []) > 1): ?>
  <section class="mt-5">
    <h2 class="mb-2 px-1 text-xs font-medium uppercase tracking-wide text-gray-400">
      同剧其他来源 · <?= count($versions) ?> 个
    </h2>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
      <table class="w-full text-left">
        <thead class="bg-slate-800 text-[11px] uppercase tracking-wide text-white">
          <tr><th class="px-4 py-2 font-medium">集数</th><th class="px-4 py-2 font-medium">平台</th>
          <th class="px-4 py-2 font-medium">状态</th><th class="px-4 py-2 font-medium">时间</th>
          <th class="px-4 py-2 font-medium"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($versions as $v):
            $same = (string) $v['id'] === (string) $row['id']; ?>
          <tr class="border-b border-gray-100 last:border-0 <?= $same ? 'bg-cyan-50/50' : 'hover:bg-gray-50' ?>">
            <td class="px-4 py-2 text-xs">
              <?php if (!empty($v['episode'])): ?>
                <span class="tag"><?= !empty($v['complete']) ? '全' : '更至' ?> <?= (int) $v['episode'] ?> 集</span>
              <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
            </td>
            <td class="px-4 py-2 text-xs text-gray-700"><?= e(ui_provider_name((string) $v['provider'])) ?></td>
            <td class="px-4 py-2 text-xs"><?= ui_status((string) $v['status']) ?></td>
            <td class="px-4 py-2 font-mono text-[11px] text-gray-400"><?= e(ui_rel_time($v['posted_at'])) ?></td>
            <td class="px-4 py-2 text-right text-xs">
              <?php if ($same): ?>
                <span class="text-[11px] text-cyan-600">当前</span>
              <?php else: ?>
                <a class="text-blue-600 hover:underline" href="<?= e(seo_detail_path((string) $v['id'])) ?>">查看</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mt-1.5 px-1 text-[11px] text-gray-400">同一部剧被多个频道转发时，这里列出各自的集数与来源</p>
  </section>
  <?php endif; ?>

  <?php if ((string) $row['desc'] !== ''): ?>
  <section class="mt-5">
    <h2 class="mb-2 px-1 text-xs font-medium uppercase tracking-wide text-gray-400">资源简介</h2>
    <div class="rounded-lg bg-white p-5 text-sm leading-relaxed text-gray-600 shadow-sm"><?= nl2br(e((string) $row['desc'])) ?></div>
  </section>
  <?php endif; ?>

  <?php if ((string) $row['post_url'] !== '' || (string) $row['channel'] !== ''): ?>
  <p class="mt-4 flex flex-wrap items-center gap-x-2 px-1 text-[11px] text-gray-400">
    <?php if ((string) $row['channel'] !== ''): ?>
      <span>来源频道
        <a class="text-blue-600 hover:underline" href="/c/<?= e((string) $row['channel']) ?>">@<?= e((string) $row['channel']) ?></a>
      </span>
    <?php endif; ?>
    <?php if ((string) $row['post_url'] !== ''): ?>
      <span class="text-gray-300">·</span>
      <a class="hover:underline" href="<?= e((string) $row['post_url']) ?>" target="_blank" rel="nofollow noopener">查看原帖</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>

  <?php if (!empty($channelMore)): ?>
  <section class="mt-6" aria-label="同频道其他资源">
    <div class="mb-2 flex items-baseline justify-between px-1">
      <h2 class="text-sm font-medium text-gray-700">@<a class="hover:underline" href="/c/<?= e((string) $row['channel']) ?>"><?= e((string) $row['channel']) ?></a> 发布的其他资源</h2>
      <a class="text-[11px] text-blue-600 hover:underline" href="/c/<?= e((string) $row['channel']) ?>">全部 →</a>
    </div>
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <?php foreach ($channelMore as $r): ?>
        <a class="flex items-start gap-2 rounded-lg bg-white p-3 shadow-sm transition hover:shadow-md"
           href="<?= e(seo_detail_path((string) $r['id'])) ?>">
          <span class="min-w-0 flex-1">
            <span class="clamp-2 block text-sm leading-snug text-gray-800"><?= e(ui_clean_title((string) $r['title'])) ?></span>
            <span class="mt-1 block text-[11px] text-gray-400">
              <?= e(ui_provider_name((string) $r['provider'])) ?>
              · <?= e(ui_type_name((string) $r['type'])) ?>
              · <?= e(ui_rel_time($r['posted_at'])) ?>
            </span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php
  // 相关搜索：只给实测有结果的词，别让用户再点进一个空页
  $relatedSearches = function_exists('ui_related_searches') ? ui_related_searches($row, 4) : [];
  ?>
  <?php if (count($relatedSearches) > 1): ?>
  <section class="mt-6" aria-label="相关搜索">
    <h2 class="mb-2 px-1 text-xs font-medium uppercase tracking-wide text-gray-400">再搜一下</h2>
    <div class="flex flex-wrap gap-2 px-1">
      <?php foreach ($relatedSearches as $rs): ?>
        <a class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1.5 text-xs text-gray-600 shadow-sm ring-1 ring-gray-200/80 transition hover:text-blue-600 hover:ring-blue-200"
           href="/search?q=<?= e(urlencode((string) $rs['q'])) ?>">
          <svg class="size-3 text-gray-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="6"/><path d="m13.5 13.5 3.5 3.5"/></svg>
          <?= e((string) $rs['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($related !== []): ?>
  <section class="mt-8">
    <h2 class="mb-3 px-1 text-sm font-medium text-gray-700">相关资源</h2>
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <?php foreach ($related as $r):
          if ((string) $r['id'] === (string) $row['id']) {
              continue;
          } ?>
        <a class="flex items-center gap-3 rounded-lg bg-white p-3 shadow-sm transition hover:shadow-md"
           href="<?= e(seo_detail_path((string) $r['id'])) ?>">
          <span class="min-w-0 flex-1">
            <span class="clamp-1 block text-sm text-gray-800"><?= e((string) $r['title']) ?></span>
            <span class="mt-0.5 block text-[11px] text-gray-400"><?= e(ui_provider_name((string) $r['provider'])) ?> · <?= e(ui_rel_time($r['posted_at'])) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var code = btn.getAttribute('data-copy');
    var done = function () {
      var old = btn.innerHTML;
      btn.innerHTML = '已复制 ' + code;
      setTimeout(function () { btn.innerHTML = old; }, 1200);
    };
    if (navigator.clipboard && location.protocol !== 'http:') {
      navigator.clipboard.writeText(code).then(done, done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = code;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      done();
    }
  });
});
</script>
