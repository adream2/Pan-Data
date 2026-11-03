<?php
declare(strict_types=1);

/**
 * 视图辅助：平台映射、封面块、关键词高亮、相对时间。
 * 全部返回已转义的 HTML 片段，模板里直接 echo。
 */

const UI_PROVIDERS = [
    'quark'    => ['夸克', '夸', ['#3b82f6', '#1d4ed8']],
    'baidu'    => ['百度', '百', ['#22d3ee', '#0e7490']],
    'aliyun'   => ['阿里', '阿', ['#fb923c', '#ea580c']],
    'xunlei'   => ['迅雷', '迅', ['#22d3ee', '#2563eb']],
    'uc'       => ['UC', 'UC', ['#fb7185', '#ef4444']],
    '123pan'   => ['123盘', '123', ['#34d399', '#0d9488']],
    '189'      => ['天翼', '189', ['#818cf8', '#7c3aed']],
    '139'      => ['移动', '139', ['#f472b6', '#e11d48']],
    '115'      => ['115', '115', ['#4ade80', '#059669']],
    'lanzou'   => ['蓝奏', '蓝', ['#60a5fa', '#0284c7']],
    'onedrive' => ['OneDrive', 'OD', ['#38bdf8', '#0369a1']],
    'mega'     => ['MEGA', 'M', ['#f87171', '#b91c1c']],
    'gdrive'   => ['谷歌', 'G', ['#fbbf24', '#16a34a']],
];

const UI_TYPES = [
    'movie'    => '影视',
    'course'   => '课程',
    'ebook'    => '电子书',
    'software' => '软件',
    'music'    => '音乐',
    'other'    => '其它',
];

function ui_provider_name(string $p): string
{
    return UI_PROVIDERS[$p][0] ?? $p;
}

function ui_provider_short(string $p): string
{
    return UI_PROVIDERS[$p][1] ?? mb_substr($p, 0, 2);
}

function ui_type_name(string $t): string
{
    return UI_TYPES[$t] ?? '其它';
}

/**
 * 标题清洗：频道文案经常把链接、提取码写进标题
 * （例：「《吞噬星空》2026 更至248集 链接：https://pan.quark.cn/s/xxx」）。
 * 只去掉尾巴上的链接段，不动前面对用户有用的信息。
 */
function ui_clean_title(string $title): string
{
    $t = trim($title);
    // 「链接：https://...」「地址: http://...」整段去掉
    $t = preg_replace('/\s*[（(【\[]?\s*(?:链接|地址|网盘|提取码|密码)\s*[:：]?\s*https?:\/\/\S+.*$/iu', '', $t) ?? $t;
    // 裸露的 URL 及其后内容
    $t = preg_replace('/\s*https?:\/\/\S+.*$/iu', '', $t) ?? $t;
    // 收尾残留的「链接：」「地址:」
    $t = preg_replace('/\s*[（(【\[]?\s*(?:链接|地址)\s*[:：]\s*$/iu', '', $t) ?? $t;
    // 注意：不能用 trim() 去多字节标点
    $t = preg_replace('/^[\s\x{3000}·|，,、\-_—]+/u', '', $t) ?? $t;
    $t = preg_replace('/[\s\x{3000}·|，,、\-_—]+$/u', '', $t) ?? $t;
    return $t !== '' ? $t : trim($title);
}

/** 关键词高亮：先在原文上定位，再逐段转义，避免二次转义与标签注入 */
function ui_hl(string $text, string $kw = ''): string
{
    if ($kw === '' || $text === '') {
        return e($text);
    }
    $terms = array_values(array_filter(preg_split('/\s+/u', $kw, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    if ($terms === []) {
        return e($text);
    }
    $pattern = '/' . implode('|', array_map(fn($t) => preg_quote($t, '/'), $terms)) . '/iu';
    $out = '';
    $last = 0;
    if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($m[0] as $hit) {
            [$word, $pos] = $hit;
            if ($pos > $last) {
                $out .= e(substr($text, $last, $pos - $last));
            }
            $out .= '<mark>' . e($word) . '</mark>';
            $last = $pos + strlen($word);
        }
    }
    $out .= e(substr($text, $last));
    return $out;
}

/** 封面：有刮削到的海报就用图片，否则回退到「平台色 + 首字」色块 */
function ui_status(string $status): string
{
    return match ($status) {
        'valid'   => '<span class="inline-flex items-center gap-1 text-[11px] text-emerald-600" title="链接可用">'
            . '<i class="dot bg-emerald-500"></i>有效</span>',
        'invalid' => '<span class="inline-flex items-center gap-1 text-[11px] text-red-600" title="链接已失效">'
            . '<i class="dot bg-red-500"></i>失效</span>',
        default   => '<span class="inline-flex items-center gap-1 text-[11px] text-gray-400" title="尚未巡检">'
            . '<i class="dot bg-gray-300"></i>未检</span>',
    };
}

/** 年份 / 季 / 集 / 来源数 —— 一行元信息 */
function ui_meta(array $row): string
{
    $bits = [];
    if (!empty($row['year'])) {
        $bits[] = (int) $row['year'] . '年';
    }
    if (!empty($row['season'])) {
        $bits[] = '第' . (int) $row['season'] . '季';
    }
    if (!empty($row['episode'])) {
        $bits[] = (!empty($row['complete']) ? '全' : '更至') . (int) $row['episode'] . '集';
    }
    if ((int) ($row['sources'] ?? 1) > 1) {
        $bits[] = (int) $row['sources'] . ' 个来源';
    }
    return implode(' · ', $bits);
}

function ui_rel_time(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return date('Y-m-d', $ts);
    }
    if ($diff < 3600) {
        return max(1, (int) ($diff / 60)) . ' 分钟前';
    }
    if ($diff < 86400) {
        return (int) ($diff / 3600) . ' 小时前';
    }
    if ($diff < 86400 * 30) {
        return (int) ($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}

function ui_is_today(?string $iso): bool
{
    return $iso !== null && $iso !== '' && substr($iso, 0, 10) === date('Y-m-d');
}

/** 筛选胶囊。query 传数组，URL 在这里统一拼装并转义，避免调用处二次转义 */
function ui_pill(array $query, string $label, bool $active, int $count = 0, string $dotColor = '', string $extraClass = ''): string
{
    $cls = ($active ? 'pill pill-active' : 'pill') . ($extraClass !== '' ? ' ' . $extraClass : '');
    $badge = $count > 0 ? '<span class="pill-count">' . number_format($count) . '</span>' : '';
    $dot = $dotColor !== ''
        ? '<span class="dot shrink-0" style="background:' . e($dotColor) . '"></span>'
        : '';
    $href = '/search' . ($query !== [] ? '?' . http_build_query($query) : '');
    return '<a class="' . $cls . '" href="' . e($href) . '">' . $dot . e($label) . $badge . '</a>';
}

/** 平台品牌色（用于胶囊圆点） */
function ui_provider_color(string $p): string
{
    return UI_PROVIDERS[$p][2][0] ?? '#94a3b8';
}

/** 分页条 */
function ui_pager(int $page, int $pages, array $query): string
{
    if ($pages <= 1) {
        return '';
    }
    $make = function (int $p, string $label, bool $active = false) use ($query): string {
        $query['page'] = $p;
        $cls = $active ? 'pager-link pager-active' : 'pager-link';
        return '<a class="' . $cls . '" href="/search?' . e(http_build_query($query)) . '">' . $label . '</a>';
    };
    $out = '<nav class="mt-6 flex flex-wrap items-center justify-center gap-1.5" aria-label="分页">';
    if ($page > 1) {
        $out .= $make($page - 1, '上一页');
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $out .= $make(1, '1');
        if ($start > 2) {
            $out .= '<span class="px-1 text-xs text-gray-400">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $out .= $make($i, (string) $i, $i === $page);
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $out .= '<span class="px-1 text-xs text-gray-400">…</span>';
        }
        $out .= $make($pages, (string) $pages);
    }
    if ($page < $pages) {
        $out .= $make($page + 1, '下一页');
    }
    return $out . '</nav>';
}
