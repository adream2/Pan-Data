<?php
/**
 * 详情页模板
 * @var array       $row
 * @var array       $related
 * @var bool        $isFav
 * @var array|null  $currentUser
 */
$href = (string) $row['share_url'];
$pwd = (string) ($row['pwd'] ?? '');
$allMirrors = $row['mirrors'] ?? [];
$meta = ui_meta($row);
?>
<div class="mx-auto max-w-3xl px-2 sm:px-0">

  <a href="javascript:history.back()" class="inline-flex items-center gap-1 text-xs text-gray-400 transition hover:text-gray-600">
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
          <?php
          // 归一名只在明显更短时显示（否则和标题重复，纯噪音）
          $coreTitle = trim((string) $row['core_title']);
          if ($coreTitle !== '' && mb_strlen($coreTitle) < mb_strlen((string) $row['title']) * 0.75): ?>
            <span class="tag-gray">归一名 <?= e($coreTitle) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 主操作 -->
    <div class="p-5 sm:p-6">
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

      <!-- 互动行 -->
      <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
        <?php if ($currentUser !== null): ?>
          <form method="post" action="/user.php?r=favs">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="favorite">
            <input type="hidden" name="resource_id" value="<?= e((string) $row['id']) ?>">
            <button class="<?= $isFav ? 'btn-ghost !border-amber-300 !bg-amber-50 !text-amber-700' : 'btn-ghost' ?>" type="submit">
              <svg class="size-3.5" viewBox="0 0 20 20" fill="<?= $isFav ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.6"><path d="m10 3 2.2 4.5 5 .7-3.6 3.5.9 5-4.5-2.4L5.5 16.7l.9-5L2.8 8.2l5-.7L10 3Z"/></svg>
              <?= $isFav ? '已收藏' : '收藏' ?>
            </button>
          </form>
        <?php else: ?>
          <a class="btn-ghost" href="/user.php?r=login">登录后收藏</a>
        <?php endif; ?>

        <span class="inline-flex items-center gap-1 text-[11px] text-gray-400">
          <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 10s3-5 8-5 8 5 8 5-3 5-8 5-8-5-8-5Z"/><circle cx="10" cy="10" r="2"/></svg>
          <?= number_format(click_count((string) $row['id'])) ?> 次浏览
        </span>

        <form method="post" action="/detail?id=<?= e(urlencode((string) $row['id'])) ?>" class="ml-auto flex flex-wrap items-center gap-1.5">
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
                <a class="text-blue-600 hover:underline" href="/detail?id=<?= e(urlencode((string) $v['id'])) ?>">查看</a>
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
  <p class="mt-4 px-1 text-[11px] text-gray-400">
    <?php if ((string) $row['channel'] !== ''): ?>来源频道 <?= e((string) $row['channel']) ?><?php endif; ?>
    <?php if ((string) $row['post_url'] !== ''): ?>
      · <a class="hover:underline" href="<?= e((string) $row['post_url']) ?>" target="_blank" rel="nofollow noopener">查看原帖</a>
    <?php endif; ?>
  </p>
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
           href="/detail?id=<?= e(urlencode((string) $r['id'])) ?>">
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
