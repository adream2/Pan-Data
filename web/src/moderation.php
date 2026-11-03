<?php
declare(strict_types=1);

/** 内容风控：投稿和爬取入库前都过一遍。规则复用仓库根的 promo_rules.json。 */
function mod_rules(): array
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }
    $path = dirname(APP_ROOT) . '/promo_rules.json';
    $default = [
        'block_keywords' => ['幼女', '未成年', '裸聊', '赌博', '博彩', '毒品', '枪支', '身份证', '翻墙'],
        'warn_keywords'  => ['破解', '激活', '内部资料', '盗版', '外挂', '私服'],
        'min_title_length' => 4,
    ];
    if (is_file($path)) {
        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data)) {
            $rules = array_merge($default, $data);
            return $rules;
        }
    }
    $rules = $default;
    return $rules;
}

/** @return array{level:string, reasons:array<int,string>} */
function mod_check(string $title, string $extra = ''): array
{
    $rules = mod_rules();
    $text = $title . ' ' . $extra;
    $reasons = [];

    $hit = [];
    foreach ((array) ($rules['block_keywords'] ?? []) as $kw) {
        if ($kw !== '' && str_contains($text, (string) $kw)) {
            $hit[] = (string) $kw;
        }
    }
    if ($hit) {
        return ['level' => 'blocked', 'reasons' => array_map(fn($k) => "命中违禁词：{$k}", array_slice($hit, 0, 3))];
    }
    if (mb_strlen(trim($title)) < (int) ($rules['min_title_length'] ?? 4)) {
        $reasons[] = '标题过短';
    }
    $warn = [];
    foreach ((array) ($rules['warn_keywords'] ?? []) as $kw) {
        if ($kw !== '' && str_contains($text, (string) $kw)) {
            $warn[] = (string) $kw;
        }
    }
    if ($warn) {
        $reasons[] = '含敏感词：' . implode('、', array_slice($warn, 0, 2));
        return ['level' => 'medium', 'reasons' => $reasons];
    }
    return ['level' => $reasons ? 'high' : 'low', 'reasons' => $reasons];
}

/** 网盘平台识别（与采集端规则保持一致）。 */
function mod_provider_of(string $url): ?string
{
    $map = [
        'baidu'    => '#^https?://pan\.baidu\.com/s/#i',
        'aliyun'   => '#^https?://(www\.)?(aliyundrive|alipan)\.com/s/#i',
        'quark'    => '#^https?://(pan\.quark\.cn|pan\.qoark\.cn)/s/#i',
        'uc'       => '#^https?://(drive|yun)\.uc\.cn/s/#i',
        'xunlei'   => '#^https?://pan\.xunlei\.com/s/#i',
        '123pan'   => '#^https?://(www\.)?(123pan\.(com|cn)|123684|123685|123912|123592|123865)\.com/s/#i',
        '189'      => '#^https?://(h5\.)?cloud\.189\.cn/t/#i',
        '139'      => '#^https?://(yun|caiyun)\.139\.com/#i',
        '115'      => '#^https?://(115\.com|115cdn\.com|anxia\.com)/s/#i',
        'lanzou'   => '#^https?://[\w\-]+\.lanzou[a-z]?\.com/#i',
        'onedrive' => '#^https?://1drv\.ms/#i',
        'mega'     => '#^https?://mega\.nz/#i',
        'gdrive'   => '#^https?://drive\.google\.com/#i',
    ];
    foreach ($map as $name => $re) {
        if (preg_match($re, $url)) {
            return $name;
        }
    }
    return null;
}

function mod_clean_pwd(?string $pwd): ?string
{
    $pwd = $pwd === null ? '' : trim($pwd);
    if ($pwd === '' || !preg_match('/^[0-9a-zA-Z]{2,8}$/', $pwd)) {
        return null;
    }
    return $pwd;
}

/** 按标题关键词粗估资源类型（免审入库时用；粗分无妨，后续以官方标题为准） */
function mod_type_guess(string $title): string
{
    if (preg_match('/(课程|教程|培训|训练营|实战|从入门|bootcamp|lesson|course)/iu', $title)) {
        return 'course';
    }
    if (preg_match('/(电子书|pdf|epub|mobi|小说|漫画)/iu', $title)) {
        return 'ebook';
    }
    if (preg_match('/(软件|app|破解版|绿色版)/iu', $title)) {
        return 'software';
    }
    if (preg_match('/(mp3|flac|无损|专辑|音乐|歌曲)/iu', $title)) {
        return 'music';
    }
    if (preg_match('/(电影|电视剧|剧集|动漫|综艺|纪录片|全集|全\\d+集|第\\d+季|\\d{4}年|movie|drama)/iu', $title)) {
        return 'movie';
    }
    return 'other';
}
