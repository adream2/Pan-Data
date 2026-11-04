<?php
/**
 * @var bool   $done
 * @var string $error
 * @var bool   $closed
 * @var string $reviewMode
 */
$reviewMode = $reviewMode ?? 'all';
$instant = $reviewMode === 'none';
?>
<div class="mx-auto max-w-xl px-2 sm:px-0">
  <?php if ($done): ?>
    <div class="rounded-lg bg-white p-10 text-center shadow-lg shadow-slate-900/10">
      <div class="mx-auto grid size-12 place-items-center rounded-full bg-emerald-50 text-emerald-600">
        <svg class="size-6" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 10 3.5 3.5L15 7"/></svg>
      </div>
      <h1 class="mt-4 text-base font-semibold text-gray-900">提交成功</h1>
      <p class="mt-1.5 text-sm text-gray-500">
        <?= $instant ? '已直接入库，现在就能被搜到' : '审核通过后会出现在搜索结果里' ?>
      </p>
      <div class="mt-6 flex justify-center gap-2">
        <a class="btn-primary" href="/">回首页</a>
        <a class="btn-secondary" href="/submit">再投一条</a>
      </div>
    </div>

  <?php elseif (!empty($closed)): ?>
    <div class="rounded-lg bg-white p-10 text-center shadow-lg shadow-slate-900/10">
      <div class="mx-auto grid size-12 place-items-center rounded-full bg-amber-50 text-amber-600">
        <svg class="size-6" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg>
      </div>
      <h1 class="mt-4 text-base font-semibold text-gray-900">投稿暂时关闭</h1>
      <p class="mt-1.5 text-sm text-gray-500">站方已暂停接收新投稿，稍后再来看看</p>
      <a class="btn-primary mt-6 inline-flex" href="/">回首页搜索</a>
    </div>

  <?php else: ?>
    <h1 class="text-xl font-semibold tracking-tight text-gray-900">投稿资源</h1>
    <p class="mt-1.5 text-sm text-gray-500">
      只收主流网盘分享链接。
      <?= $reviewMode === 'none'
          ? '当前免审，提交后立即入库。'
          : ($reviewMode === 'low_auto' ? '常规资源自动入库，敏感内容需人工审核。' : '人工审核后入库。') ?>
    </p>

    <form method="post" class="mt-5 space-y-4 rounded-lg bg-white p-5 shadow-lg shadow-slate-900/10 sm:p-6">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <?php if ($error !== ''): ?>
        <div class="rounded-lg bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700 ring-1 ring-rose-200"><?= e($error) ?></div>
      <?php endif; ?>

      <label class="block">
        <span class="mb-1.5 block text-xs font-medium text-gray-600">标题 <span class="text-rose-500">*</span></span>
        <input class="input-field" name="title" required maxlength="120" placeholder="例如：Python 从入门到实战 全 40 讲">
      </label>

      <label class="block">
        <span class="mb-1.5 block text-xs font-medium text-gray-600">分享链接 <span class="text-rose-500">*</span></span>
        <input class="input-field" name="share_url" required maxlength="500" placeholder="https://pan.quark.cn/s/xxxx">
      </label>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1.5 block text-xs font-medium text-gray-600">提取码（可选）</span>
          <input class="input-field" name="pwd" maxlength="8" placeholder="4 位码">
        </label>
        <label class="block">
          <span class="mb-1.5 block text-xs font-medium text-gray-600">联系方式（可选）</span>
          <input class="input-field" name="contact" maxlength="80" placeholder="TG / 邮箱">
        </label>
      </div>

      <label class="block">
        <span class="mb-1.5 block text-xs font-medium text-gray-600">补充说明（可选）</span>
        <textarea class="input-field !h-auto py-2.5" name="note" rows="3" maxlength="300" placeholder="集数、清晰度、是否完结"></textarea>
      </label>

      <button class="btn-primary w-full" type="submit">提交</button>
      <p class="text-center text-[11px] text-gray-400">请勿投稿违规内容；重复链接会被自动跳过。</p>
    </form>
  <?php endif; ?>
</div>
