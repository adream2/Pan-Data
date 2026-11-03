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
    'game'     => '游戏',
    'software' => '软件',
    'ebook'    => '电子书',
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
 * 标题清洗：频道文案常把链接、推广话术写进标题，直接展示会污染页面标题与结构化数据。
 *
 * 真实样本（ai短剧 频道固定模板）：
 *   【ai短剧】假面热恋（191集 / 简介：2026-11-03更新短剧！ 和谐失效看其他网盘 先转存-再观看-避免和谐失效
 *   -> 【ai短剧】假面热恋（191集）
 *
 * 原则：只截掉「之后全是营销话术」的部分，保留标题本体。
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

    // 频道自带的分类前缀：「#电影 / 电影：生化危机」-> 「生化危机」
    $t = preg_replace('/^#\S+\s*[\/|｜]\s*/u', '', $t) ?? $t;
    $t = preg_replace('/^#\S+\s+/u', '', $t) ?? $t;
    $t = preg_replace('/^(?:电影|电视剧|剧集|电视剧集|动漫|动画|综艺|纪录片|短剧|课程|教程|游戏|软件|音乐|电子书|有声书)\s*[:：]\s*/u', '', $t) ?? $t;

    // 「/ 简介：」「| 描述:」之后基本全是推广，从分隔符起整段丢弃
    $t = preg_replace('/\s*[\/|｜]\s*(?:简介|描述|介绍|说明)\s*[:：].*$/u', '', $t) ?? $t;
    // 没有分隔符、直接「简介：...」的
    $t = preg_replace('/\s*(?:简介|描述|介绍|说明)\s*[:：].*$/u', '', $t) ?? $t;

    // 残留的推广短语（不截断整段，只抹掉这几个词，避免误伤标题里的正常词）
    $promo = [
        '/和谐失效[^\s，。]*/u', '/先转存[^\s，。]*/u', '/避免和谐[^\s，。]*/u',
        '/建议转存[^\s，。]*/u', '/更多(?:资源|精彩)[^\s，。]*/u',
        '/持续更新[^\s，。]*/u', '/每日更新[^\s，。]*/u',
        '/关注(?:频道|公众号|我)[^\s，。]*/u',
    ];
    foreach ($promo as $p) {
        $t = preg_replace($p, '', $t) ?? $t;
    }

    // 收尾标点
    // 注意：不能用 trim() 去多字节标点，trim 按字节切会把「《」切坏
    $t = preg_replace('/^[\s\x{3000}·|，,、\-_—\/]+/u', '', $t) ?? $t;
    $t = preg_replace('/[\s\x{3000}·|，,、\-_—\/]+$/u', '', $t) ?? $t;

    // 截断后可能留下没闭合的括号（「（191集 」），补上才不会像渲染坏了
    $t = preg_replace('/[（(]\s*([^（）()]*) \s*[）)]/u', '（$1）', $t) ?? $t;
    $pairs = ['（' => '）', '(' => ')', '【' => '】', '《' => '》', '[' => ']'];
    foreach ($pairs as $open => $close) {
        $diff = mb_substr_count($t, $open) - mb_substr_count($t, $close);
        if ($diff > 0) {
            $t .= str_repeat($close, $diff);
        }
    }
    // 补完括号后再去一次尾部空白
    $t = rtrim($t);

    return $t !== '' ? $t : trim($title);
}

/**
 * 简介清洗：去掉频道塞在简介里的推广话术，保留真正的剧情/内容说明。
 * 不清空 —— 如果去掉后没剩东西就退回原文，宁可脏一点也不显示空。
 */
function ui_clean_desc(string $desc): string
{
    $t = trim($desc);
    if ($t === '') {
        return '';
    }
    // 日期型更新播报：「2026-11-03更新短剧！」
    $t = preg_replace('/\d{4}-\d{2}-\d{2}\s*(?:更新|发布)[^！!。，,\s]*[！!。]?/u', '', $t) ?? $t;
    $promo = [
        '/和谐失效[^\s，。！]*[，。！]?/u',
        '/看其他网盘[^\s，。！]*[，。！]?/u',
        '/先转存[^\s，。！]*[，。！]?/u',
        '/避免和谐[^\s，。！]*[，。！]?/u',
        '/建议转存[^\s，。！]*[，。！]?/u',
        '/更多(?:资源|精彩)[^\s，。！]*[，。！]?/u',
        '/持续更新[^\s，。！]*[，。！]?/u',
        '/每日更新[^\s，。！]*[，。！]?/u',
        '/关注(?:频道|公众号|我)[^\s，。！]*[，。！]?/u',
        '/[「『]?公众号[」』]?[^\s，。！]*/u',
    ];
    foreach ($promo as $p) {
        $t = preg_replace($p, '', $t) ?? $t;
    }
    $t = preg_replace('/\s{2,}/u', ' ', $t) ?? $t;
    $t = trim($t, " 	

");
    $t = preg_replace('/^[\s，,。、·\-_—]+/u', '', $t) ?? $t;

    // 清完什么都不剩 → 说明这段简介本来就是纯推广，直接不显示，
    // 比把「和谐失效看其他网盘」再贴回去好
    if (mb_strlen(preg_replace('/[^\p{Han}\p{L}\p{N}]/u', '', $t) ?? '') < 4) {
        return '';
    }
    return $t;
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

/** 分页条。$base 允许分类落地页（/movie 等）复用同一套分页组件 */
function ui_pager(int $page, int $pages, array $query, string $base = '/search'): string
{
    if ($pages <= 1) {
        return '';
    }
    $make = function (int $p, string $label, bool $active = false) use ($query, $base): string {
        $query['page'] = $p;
        $cls = $active ? 'pager-link pager-active' : 'pager-link';
        $href = $base . '?' . http_build_query($query);
        // 第 1 页用裸 URL，避免 /movie?page=1 与 /movie 两个地址争同一份权重
        if ($p === 1 && count($query) === 1) {
            $href = $base;
        }
        return '<a class="' . $cls . '" href="' . e($href) . '">' . $label . '</a>';
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
