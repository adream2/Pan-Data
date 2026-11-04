<?php
declare(strict_types=1);

/**
 * 浏览器安装向导。
 *
 * 三步：环境自检 → 站点信息 + 管理员 → 导入数据（分批 AJAX）。
 * 全程不需要 SSH；命令行脚本（bin/init.php、bin/sync.php）仍然保留给
 * 无 web 权限或要写进自动化脚本的场景。
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/installer.php';
require_once __DIR__ . '/../src/ui.php';

session_name((string) cfg('security.session_name', 'pansou_sid'));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

/* ---------------- AJAX：分批导入 ---------------- */

if (($_GET['ajax'] ?? '') === 'import') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'error' => '只接受 POST'], 405);
    }
    if (!hash_equals((string) ($_SESSION['_csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
        json_out(['ok' => false, 'error' => '会话已过期，刷新页面重试'], 419);
    }
    if (!installer_installed() || current_admin() === null) {
        json_out(['ok' => false, 'error' => '请先完成安装并登录后台'], 403);
    }
    $step = max(0, (int) ($_POST['step'] ?? 0));
    json_out(installer_import_step($step));
}

/* ---------------- 安装动作 ---------------- */

$installed = installer_installed();
$step = (string) ($_GET['step'] ?? 'checks');
$errors = [];
$notice = '';
$doneUser = '';

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish') {
    csrf_check();
    $errors = installer_finish(
        (string) ($_POST['site_name'] ?? ''),
        (string) ($_POST['site_url'] ?? ''),
        (string) ($_POST['username'] ?? ''),
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['repo_proxy'] ?? ''),
        (string) ($_POST['repo_token'] ?? '')
    );
    if ($errors === []) {
        $doneUser = (string) ($_POST['username'] ?? 'admin');
        redirect('/install.php?step=import');
    }
    $step = 'setup';
}

/** 页面外壳（独立于前台 layout：安装时还没有站点设置） */
function install_page(string $title, string $body): void
{
    $t = e($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t}</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
<div class="mx-auto max-w-2xl px-4 py-10">
  <div class="mb-6 flex items-center gap-2">
    <svg class="size-8" viewBox="0 0 32 32" fill="none" aria-hidden="true">
      <rect x="2" y="2" width="28" height="28" rx="8" fill="#2563eb"/>
      <path d="M10 20.5V11.5h6.2a4.5 4.5 0 1 1 0 9H10Z" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
      <circle cx="16.4" cy="16" r="1.6" fill="#fff"/>
    </svg>
    <div><div class="text-base font-semibold">盘搜 · 安装向导</div>
    <div class="text-[11px] text-slate-400">三步完成，不需要命令行</div></div>
  </div>
{$body}
  <p class="mt-8 text-center text-[11px] text-slate-400">安装完成后请删除或停用 install.php</p>
</div>
</body>
</html>
HTML;
}

/** 步骤条 */
function install_steps(int $current): string
{
    $labels = [1 => '环境检查', 2 => '站点信息', 3 => '导入数据'];
    $out = '<ol class="mb-5 flex items-center gap-2 text-[11px]">';
    foreach ($labels as $i => $label) {
        $active = $i === $current;
        $passed = $i < $current;
        $cls = $active ? 'bg-blue-600 text-white' : ($passed ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-white text-slate-400 ring-1 ring-slate-200');
        $out .= '<li class="flex items-center gap-1.5 rounded-full px-2.5 py-1 ' . $cls . '">'
            . '<span class="font-mono">' . ($passed ? '✓' : $i) . '</span>' . e($label) . '</li>';
        if ($i < 3) {
            $out .= '<li class="h-px w-4 bg-slate-200"></li>';
        }
    }
    return $out . '</ol>';
}

/* ---------------- 已安装 ---------------- */

if ($installed && $step !== 'import') {
    install_page('已安装', install_steps(3)
        . '<div class="rounded-xl bg-white p-6 shadow-sm">'
        . '<h1 class="text-base font-semibold text-slate-800">站点已经安装过了</h1>'
        . '<p class="mt-2 text-sm leading-6 text-slate-500">'
        . '重复运行安装向导不会重建数据。要重装请先删除 <code class="rounded bg-slate-100 px-1">storage/app.sqlite</code>，'
        . '或者用 <code class="rounded bg-slate-100 px-1">bin/reset-password.php</code> 重置管理员密码。</p>'
        . '<div class="mt-5 flex flex-wrap gap-2">'
        . '<a class="btn" href="/admin.php">进入后台</a>'
        . '<a class="btn-soft inline-flex" href="/">打开前台</a>'
        . '</div></div>');
    exit;
}

/* ---------------- 第 3 步：导入数据 ---------------- */

if ($step === 'import') {
    if (!$installed) {
        redirect('/install.php');
    }
    if (current_admin() === null) {
        redirect('/admin.php?r=login');
    }
    $csrf = csrf_token();
    $body = install_steps(3)
        . '<div class="rounded-xl bg-white p-6 shadow-sm">'
        . '<h1 class="text-base font-semibold text-slate-800">导入数据</h1>'
        . '<p class="mt-2 text-sm leading-6 text-slate-500">正在从数据仓库分批拉取：先热数据（最近 90 天），再逐个历史分片。'
        . '一共 30 步左右，全程约 5~15 分钟。<b class="text-slate-700">过程中请不要关闭页面</b>，'
        . '关闭了也不要紧——重新打开本页可以继续，已导入的部分不会重复。</p>'
        . '<div class="mt-5"><div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">'
        . '<div id="bar" class="h-full w-0 rounded-full bg-blue-600 transition-all duration-300"></div></div>'
        . '<p id="status" class="mt-2 text-xs text-slate-500">准备中…</p>'
        . '<p id="detail" class="mt-1 text-[11px] text-slate-400"></p></div>'
        . '<div id="log" class="mt-4 max-h-48 overflow-auto rounded-lg bg-slate-50 p-3 font-mono text-[11px] leading-relaxed text-slate-500"></div>'
        . '<div class="mt-5 flex flex-wrap items-center gap-2">'
        . '<button id="start" class="btn" type="button">开始导入</button>'
        . '<a id="next" class="btn-soft hidden" href="/admin.php">进入后台</a>'
        . '<button id="retry" class="btn-soft hidden" type="button">重试这一步</button>'
    . '<a class="hidden text-xs text-blue-600 hover:underline" id="fixlink" href="/admin.php?r=settings">去设置里配置代理 / Token</a>'
        . '<a class="text-xs text-slate-400 hover:text-slate-600" href="/" target="_blank">先去前台看看</a>'
        . '</div></div>'
        . '<script>
var CSRF = ' . json_encode($csrf) . ';
var step = 0, running = false;
var bar = document.getElementById("bar"), status = document.getElementById("status"),
    detail = document.getElementById("detail"), log = document.getElementById("log"),
    startBtn = document.getElementById("start"), nextBtn = document.getElementById("next"),
    retryBtn = document.getElementById("retry");

function addLog(text, cls) {
  var p = document.createElement("div");
  p.textContent = text;
  if (cls) p.className = cls;
  log.appendChild(p);
  log.scrollTop = log.scrollHeight;
}

async function runStep() {
  if (step === 0) {
    // 第一步是拉 6MB 的热文件，耗时几乎全在下载。不说明白，用户会以为卡死
    status.textContent = "正在下载最近 90 天的热数据（约 6MB）…";
    detail.textContent = "这一步取决于服务器到 GitHub 的带宽，慢的时候要 1~3 分钟";
    bar.classList.add("animate-pulse");
  } else {
    status.textContent = "正在拉取第 " + (step + 1) + " 步…";
    detail.textContent = "";
    bar.classList.remove("animate-pulse");
  }
  var res, data;
  try {
    res = await fetch("/install.php?ajax=import", {
      method: "POST",
      headers: {"content-type": "application/x-www-form-urlencoded"},
      body: new URLSearchParams({step: String(step), _csrf: CSRF})
    });
    data = await res.json();
  } catch (e) {
    status.textContent = "网络中断";
    addLog("请求失败：" + e.message, "text-rose-600");
    retryBtn.classList.remove("hidden");
    running = false;
    return;
  }
  if (!data.ok) {
    status.textContent = "这一步失败了";
    addLog("✗ " + (data.label || ("第 " + (step + 1) + " 步")) + "：" + (data.error || "未知错误"), "text-rose-600");
    retryBtn.classList.remove("hidden");
    document.getElementById("fixlink").classList.remove("hidden");
    running = false;
    return;
  }
  bar.classList.remove("animate-pulse");
  var pct = Math.round(((step + 1) / data.total) * 100);
  bar.style.width = pct + "%";
  detail.textContent = (step + 1) + " / " + data.total + " · " + pct + "%";
  addLog("✓ " + data.label + "：新增 " + data.insert + "，更新 " + data.update + "（未变 " + data.same + "）");
  step++;
  if (data.done) {
    if (data.fts) addLog("已建立搜索索引：" + data.fts + " 条");
    status.textContent = "全部导入完成，共 " + data.total + " 批";
    bar.style.width = "100%";
    nextBtn.classList.remove("hidden");
    startBtn.classList.add("hidden");
    running = false;
    return;
  }
  runStep();
}

startBtn.addEventListener("click", function () {
  if (running) return;
  running = true;
  startBtn.classList.add("hidden");
  retryBtn.classList.add("hidden");
  runStep();
});
retryBtn.addEventListener("click", function () {
  if (running) return;
  running = true;
  retryBtn.classList.add("hidden");
  runStep();
});
</script>';
    install_page('导入数据', $body);
    exit;
}

/* ---------------- 第 2 步：站点信息 ---------------- */

if ($step === 'setup') {
    $checks = installer_env_checks();
    if (!installer_env_ok($checks)) {
        redirect('/install.php?step=checks');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $guessUrl = preg_match('/^[A-Za-z0-9\.\-]+(:\d+)?$/', $host) ? $scheme . '://' . $host : '';

    $errHtml = '';
    foreach ($errors as $err) {
        $errHtml .= '<li>' . e($err) . '</li>';
    }
    $body = install_steps(2)
        . ($errHtml !== '' ? '<div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200"><ul class="list-inside list-disc space-y-1">' . $errHtml . '</ul></div>' : '')
        . '<form method="post" class="rounded-xl bg-white p-6 shadow-sm"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="action" value="finish">'
        . '<h1 class="text-base font-semibold text-slate-800">站点信息</h1>'
        . '<div class="mt-4 space-y-4">'
        . '<div><label class="mb-1 block text-[11px] font-medium text-slate-500">站点名称</label>'
        . '<input class="input" name="site_name" value="' . e((string) ($_POST['site_name'] ?? '盘搜')) . '" required maxlength="40"></div>'
        . '<div><label class="mb-1 block text-[11px] font-medium text-slate-500">站点完整地址</label>'
        . '<input class="input" name="site_url" value="' . e((string) ($_POST['site_url'] ?? $guessUrl)) . '" placeholder="https://pansou.example.com">'
        . '<p class="mt-1 text-[11px] text-slate-400">用于 sitemap 与 canonical。带协议、不带结尾斜杠；有 CDN 时请填真实域名</p></div>'
        . '<hr class="border-slate-100">'
        . '<div><label class="mb-1 block text-[11px] font-medium text-slate-500">管理员账号</label>'
        . '<input class="input" name="username" value="' . e((string) ($_POST['username'] ?? 'admin')) . '" required minlength="2" autocomplete="username"></div>'
        . '<div><label class="mb-1 block text-[11px] font-medium text-slate-500">密码</label>'
        . '<input class="input" type="password" name="password" required minlength="8" autocomplete="new-password">'
        . '<p class="mt-1 text-[11px] text-slate-400">至少 8 位。只有一个账号，忘密码时用 bin/reset-password.php 改</p></div>'
        . '</div>'
        . '<details class="mt-5 rounded-lg bg-slate-50 p-3">'
        . '<summary class="cursor-pointer select-none text-xs font-medium text-slate-600">数据拉取设置（服务器连不上 GitHub 时填）</summary>'
        . '<div class="mt-3 space-y-3">'
        . '<div><label class="mb-1 block text-[11px] font-medium text-slate-500">拉取代理</label>'
        . '<input class="input" name="repo_proxy" value="' . e((string) ($_POST['repo_proxy'] ?? '')) . '" placeholder="http://127.0.0.1:7890">'
        . '<p class="mt-1 text-[11px] text-slate-400">境内服务器基本连不上 api.github.com，需要代理才能导入数据</p></div>'
        . '<div><label class="mb-1 block text-[11px] font-medium text-slate-500">GitHub Token（可选）</label>'
        . '<input class="input font-mono text-[11px]" type="password" name="repo_token" autocomplete="off" placeholder="ghp_… 只需要 public_repo 读权限">'
        . '<p class="mt-1 text-[11px] text-slate-400">匿名每小时 60 次请求，带 Token 是 5000 次。首次导入约 30 次请求</p></div>'
        . '</div></details>'
        . '<div class="mt-6 flex items-center gap-2"><button class="btn" type="submit">完成安装</button>'
        . '<a class="text-xs text-slate-400 hover:text-slate-600" href="/install.php?step=checks">返回上一步</a></div>'
        . '</form>';
    install_page('站点信息', $body);
    exit;
}

/* ---------------- 第 1 步：环境检查 ---------------- */

$checks = installer_env_checks();
$allOk = installer_env_ok($checks);
$rows = '';
foreach ($checks as $c) {
    $isError = $c['level'] === 'error' && !$c['ok'];
    $isWarn = $c['level'] === 'warn' && !$c['ok'];
    $icon = $c['ok']
        ? '<span class="text-emerald-600">✓</span>'
        : ($isError ? '<span class="text-rose-600">✗</span>' : '<span class="text-amber-500">!</span>');
    $rows .= '<tr class="border-b border-slate-100 last:border-0">'
        . '<td class="py-2.5 pr-3 text-sm text-slate-700">' . $icon . ' ' . e($c['name']) . '</td>'
        . '<td class="py-2.5 pr-3 text-xs ' . ($isError ? 'text-rose-600' : ($isWarn ? 'text-amber-600' : 'text-slate-500')) . '">' . e($c['value']) . '</td>'
        . '<td class="py-2.5 text-[11px] text-slate-400">' . e($c['note'] !== '' ? $c['note'] : $c['need']) . '</td></tr>';
}

$body = install_steps(1)
    . '<div class="rounded-xl bg-white p-6 shadow-sm">'
    . '<h1 class="text-base font-semibold text-slate-800">环境检查</h1>'
    . '<p class="mt-1 text-xs text-slate-400">下面标红的项必须解决，标黄的可以继续</p>'
    . '<table class="mt-4 w-full text-left">' . $rows . '</table>'
    . '<div class="mt-6 flex items-center gap-3">'
    . ($allOk
        ? '<a class="btn" href="/install.php?step=setup">下一步：填写站点信息</a>'
        : '<a class="btn pointer-events-none opacity-50" href="#">请先修复上面的问题</a>')
    . '<a class="text-xs text-slate-400 hover:text-slate-600" href="/install.php?step=checks">重新检测</a>'
    . '</div></div>';

install_page('环境检查', $body);
