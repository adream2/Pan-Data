<?php
declare(strict_types=1);

/**
 * 设置中心：单一入口定义全部后台可配项，用于渲染表单 + 校验 + 读回。
 * 分组：site / submit / review / search / promo
 */

/** @return array<string, array{group:string, label:string, desc:string, kind:string, def:string, options?:array<string,string>}> */
function cfg_spec(): array
{
    return [
        // ---- 外观 ----
        'site_name' => ['group' => 'site', 'label' => '站点名称', 'desc' => '页头与标题栏显示', 'kind' => 'text', 'def' => '盘搜'],
        'site_desc' => ['group' => 'site', 'label' => '副标题', 'desc' => '页头 slogan', 'kind' => 'text', 'def' => 'Telegram 网盘资源聚合搜索'],
        'notice' => ['group' => 'site', 'label' => '站内公告', 'desc' => '留空不显示，支持纯文本', 'kind' => 'textarea', 'def' => ''],
        'site_url' => ['group' => 'site', 'label' => '站点完整地址', 'desc' => 'SEO 用，必须带 https://，不要结尾斜杠；留空则按请求推断（sitemap 会漏域名）', 'kind' => 'text', 'def' => ''],
        'seo_index_search' => ['group' => 'site', 'label' => '允许搜索引擎收录搜索页', 'desc' => '关则搜索参数页 noindex, follow；推荐关闭以免无限组合被索引', 'kind' => 'select',
            'def' => '0', 'options' => ['0' => '不收录（推荐）', '1' => '收录']],
        // ---- 投稿 ----
        'submit_open' => ['group' => 'submit', 'label' => '开放投稿', 'desc' => '关闭后前台提示投稿暂闭', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '开启', '0' => '关闭']],
        'submit_require_login' => ['group' => 'submit', 'label' => '投稿需登录', 'desc' => '开启后匿名访客不能投稿', 'kind' => 'select',
            'def' => '0', 'options' => ['0' => '允许匿名', '1' => '必须登录']],
        'submit_max_per_hour' => ['group' => 'submit', 'label' => '投稿限流（条/小时/IP）', 'desc' => '0 = 不限制', 'kind' => 'int', 'def' => '6'],
        'submit_dup_skip' => ['group' => 'submit', 'label' => '重复链接自动跳过', 'desc' => '同链接已在库则拒绝', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '是', '0' => '否']],
        // ---- 审核 ----
        'review_mode' => ['group' => 'review', 'label' => '审核模式', 'desc' => '免审投稿直接入库；风控拦截的照样拦',
            'kind' => 'select', 'def' => 'low_auto',
            'options' => ['all' => '全部人工审核', 'low_auto' => '低风险自动通过', 'none' => '免审（全部直接入库）']],
        'review_low_auto_targets' => ['group' => 'review', 'label' => '低风险免审类型', 'desc' => '只有这些类型且无敏感词才自动过，逗号分隔',
            'kind' => 'text', 'def' => 'movie,course,ebook,software,music'],
        'review_notify_admin' => ['group' => 'review', 'label' => '新投稿提醒', 'desc' => '概览页待审红点（始终显示），此项控制是否在投稿被自动拒绝时记录', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '在待审红点中提示', '0' => '不额外提示']],
        'report_auto_invalid' => ['group' => 'review', 'label' => '举报自动判失效阈值', 'desc' => '同一资源被几个不同 IP 举报「失效」就自动标记失效；0 = 只人工处理', 'kind' => 'int', 'def' => '2'],
        'report_auto_switch' => ['group' => 'review', 'label' => '失效后自动换源', 'desc' => '标记失效时，若存在其他平台的备用链接，自动把它提升为主链接', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '自动切换', '0' => '不切换']],
        // ---- 搜索与展示 ----
        'search_page_size' => ['group' => 'search', 'label' => '每页条数', 'desc' => '前台列表分页', 'kind' => 'int', 'def' => '20'],
        'search_hot_words' => ['group' => 'search', 'label' => '热门搜索条数', 'desc' => '首页"大家在搜"，0 = 隐藏', 'kind' => 'int', 'def' => '7'],
        'show_invalid' => ['group' => 'search', 'label' => '展示失效链接', 'desc' => '关闭后失效资源从列表隐藏，详情页可直达', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '展示（带失效标记）', '0' => '隐藏']],
        'show_unchecked' => ['group' => 'search', 'label' => '展示未巡检链接', 'desc' => '关闭后只展示巡检有效的资源', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '展示', '0' => '只看有效']],
        'search_alias_on' => ['group' => 'search', 'label' => '同义词扩展', 'desc' => '搜「复联」也能搜到「复仇者联盟」，搜「PS」也能搜到 Photoshop；关闭则只按字面匹配', 'kind' => 'select',
            'def' => '1', 'options' => ['1' => '开启', '0' => '关闭']],
        'search_alias_extra' => ['group' => 'search', 'label' => '自定义同义词', 'desc' => '每行一组，逗号分隔，组内互为别名。例：扫黑风暴,反黑风暴', 'kind' => 'textarea', 'def' => ''],
        // ---- 拉新 ----
        'promo_open' => ['group' => 'promo', 'label' => '拉新总开关', 'desc' => '关闭后拉新 tab 与转存按钮全部隐藏', 'kind' => 'select',
            'def' => '0', 'options' => ['0' => '关闭', '1' => '开启']],
        'promo_auto_approve' => ['group' => 'promo', 'label' => '转存自动执行', 'desc' => '关闭则只生成任务，需在拉新页手动执行', 'kind' => 'select',
            'def' => '0', 'options' => ['0' => '手动执行', '1' => '审核通过即执行']],
        'promo_daily_limit' => ['group' => 'promo', 'label' => '每日转存上限', 'desc' => '防封号；达到后当天不再执行', 'kind' => 'int', 'def' => '30'],
        'promo_min_interval' => ['group' => 'promo', 'label' => '转存最小间隔（秒）', 'desc' => '两次转存之间至少间隔多久', 'kind' => 'int', 'def' => '60'],
        // ---- 数据源（对应 src/config.local.php 的 repo.* / github.*） ----
        'repo_github' => ['group' => 'source', 'label' => '数据仓库', 'desc' => 'owner/repo，例如 adream2/Pan-Data', 'kind' => 'text', 'def' => 'adream2/Pan-Data'],
        'repo_branch' => ['group' => 'source', 'label' => '数据分支', 'desc' => '采集端发布数据的分支', 'kind' => 'text', 'def' => 'data'],
        'repo_dir'    => ['group' => 'source', 'label' => '数据目录', 'desc' => '分支下存放 index.json 的目录', 'kind' => 'text', 'def' => 'data'],
        'repo_proxy'  => ['group' => 'source', 'label' => '拉取代理', 'desc' => '留空直连；例 http://127.0.0.1:7890', 'kind' => 'text', 'def' => ''],
        'repo_token'  => ['group' => 'source', 'label' => '拉取用 Token', 'desc' => '可选，私有仓库或提高 API 限额时填；留空用匿名', 'kind' => 'secret', 'def' => ''],
        'github_repo' => ['group' => 'source', 'label' => '采集仓库', 'desc' => '触发 Actions 用的 owner/repo，一般同数据仓库', 'kind' => 'text', 'def' => 'adream2/Pan-Data'],
        'github_token' => ['group' => 'source', 'label' => '采集触发 Token', 'desc' => '需要 actions:write 权限，用于后台一键触发云端采集', 'kind' => 'secret', 'def' => ''],
        'repo_timeout' => ['group' => 'source', 'label' => '拉取超时（秒）', 'desc' => '跨境拉取慢就调大；13MB 热文件实测需要 200s+', 'kind' => 'int', 'def' => '300'],
    ];
}

const CFG_GROUPS = [
    'site'   => '外观',
    'submit' => '投稿',
    'review' => '审核',
    'search' => '搜索与展示',
    'promo'  => '拉新',
    'source' => '数据源',
];

const CFG_SOURCE_MAP = [
    'github'       => 'repo_github',
    'branch'       => 'repo_branch',
    'dir'          => 'repo_dir',
    'proxy'        => 'repo_proxy',
    'token'        => 'repo_token',
    'timeout'      => 'repo_timeout',
    'github_repo'  => 'github_repo',
    'github_token' => 'github_token',
];

/** 读数据源配置：后台设置优先，其次 config.local.php，最后默认值 */
function repo_conf(string $key, mixed $default = null): mixed
{
    $settingKey = CFG_SOURCE_MAP[$key] ?? ('repo_' . $key);
    $v = setting($settingKey);
    if ($v !== null && $v !== '') {
        return $v;
    }
    $cfgKey = in_array($key, ['github_repo', 'github_token'], true)
        ? 'github.' . substr($key, 7)
        : 'repo.' . $key;
    return cfg($cfgKey, $default);
}

function cfg_get(string $key): string
{
    static $spec = null;
    if ($spec === null) {
        $spec = cfg_spec();
    }
    $stored = setting($key);
    if ($stored !== null && $stored !== '') {
        return (string) $stored;
    }
    // 数据源组没有存过值时，展示 config.local.php 里的真实配置
    foreach (CFG_SOURCE_MAP as $short => $settingKey) {
        if ($settingKey === $key) {
            return (string) repo_conf($short, $spec[$key]['def'] ?? '');
        }
    }
    return (string) ($spec[$key]['def'] ?? '');
}

function cfg_get_int(string $key): int
{
    return max(0, (int) cfg_get($key));
}

function cfg_get_bool(string $key): bool
{
    $v = cfg_get($key);
    return $v === '1' || $v === 'true' || $v === 'on';
}

/** 保存设置：按 spec 校验后写 settings 表，返回实际写入的键值对 */
function cfg_save(array $post): array
{
    $spec = cfg_spec();
    $saved = [];
    foreach ($spec as $key => $meta) {
        if (!array_key_exists($key, $post)) {
            continue;
        }
        $raw = is_string($post[$key]) ? trim($post[$key]) : '';
        if ($meta['kind'] === 'select' && !isset($meta['options'][(string) $raw])) {
            continue;
        }
        if ($meta['kind'] === 'secret') {
            $raw = mb_substr($raw, 0, 400);
        }
        if ($meta['kind'] === 'int') {
            if (!preg_match('/^\d{1,5}$/', $raw)) {
                continue;
            }
            $raw = (string) min(99999, (int) $raw);
        }
        if ($meta['kind'] === 'textarea') {
            $raw = mb_substr($raw, 0, 3000);
        } elseif ($meta['kind'] === 'text') {
            $raw = mb_substr($raw, 0, 120);
        }
        if ($meta['kind'] === 'secret' && $raw === '' && cfg_get($key) !== '') {
            continue; // 留空表示保持原值
        }
        set_setting($key, $raw);
        $saved[$key] = $raw;
    }
    return $saved;
}

/** 按分组渲染设置表单 */
function cfg_render_form(): string
{
    $spec = cfg_spec();
    $out = '<form method="post" class="space-y-6"><input type="hidden" name="_csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="action" value="settings">';
    foreach (CFG_GROUPS as $gkey => $glabel) {
        $out .= '<section class="rounded-lg bg-white p-5 shadow-sm">'
            . '<h2 class="text-sm font-semibold">' . e($glabel) . '</h2><div class="mt-4 space-y-4">';
        foreach ($spec as $key => $meta) {
            if ($meta['group'] !== $gkey) {
                continue;
            }
            $val = cfg_get($key);
            $out .= '<div><label class="mb-1 block text-[11px] font-medium text-neutral-500" for="cfg_' . $key . '">'
                . e($meta['label']) . '</label>';
            if ($meta['kind'] === 'select') {
                $out .= '<select class="input" name="' . $key . '" id="cfg_' . $key . '">';
                foreach ((array) ($meta['options'] ?? []) as $ov => $ol) {
                    // 注意：PHP 会把 '1'/'0' 这类数组键转成 int，e() 只吃 string
                    $out .= '<option value="' . e((string) $ov) . '"' . ($val === (string) $ov ? ' selected' : '') . '>'
                        . e($ol) . '</option>';
                }
                $out .= '</select>';
            } elseif ($meta['kind'] === 'textarea') {
                $out .= '<textarea class="input h-auto py-2" name="' . $key . '" id="cfg_' . $key . '" rows="2">'
                    . e($val) . '</textarea>';
            } elseif ($meta['kind'] === 'secret') {
                $has = $val !== '';
                $out .= '<input class="input font-mono text-[11px]" type="password" autocomplete="new-password" name="' . $key . '" id="cfg_' . $key . '"'
                    . ' placeholder="' . ($has ? '已设置（' . mb_strlen($val) . ' 字符），留空不修改' : '未设置') . '"'
                    . ' value="">';
            } elseif ($meta['kind'] === 'int') {
                $out .= '<input class="input w-36" type="number" min="0" max="99999" name="' . $key . '" id="cfg_' . $key . '" value="' . e($val) . '">';
            } else {
                $out .= '<input class="input" name="' . $key . '" id="cfg_' . $key . '" value="' . e($val) . '">';
            }
            $out .= '<p class="mt-1 text-[11px] text-neutral-400">' . e($meta['desc']) . '</p></div>';
        }
        $out .= '</div></section>';
    }
    return $out . '<div class="flex items-center gap-3"><button class="btn" type="submit">保存全部设置</button>'
        . '<span class="text-xs text-neutral-400">即时生效</span></div></form>';
}
