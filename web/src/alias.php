<?php
declare(strict_types=1);

/**
 * 搜索别名（同义词）词典。
 *
 * 为什么需要：网盘资源的标题是频道随手起的，同一部剧可能写「海贼王」也可能写
 * 「One Piece」，课程会写「PS教程」而不是「Photoshop教程」。用户按自己习惯搜，
 * 字面不匹配就零结果——这类「其实库里有但搜不到」是搜索站最大的体验损失，
 * 比排序不准严重得多。
 *
 * 设计约束：
 *  - 组内所有词互为等价，任一命中即整组扩展（OR）。
 *  - 只收「搜 A 时用户也接受 B」的词；收进来就会扩大召回，宁可少而准。
 *  - 有歧义的短词不进组（如 ai 既可指 Illustrator 也可指人工智能，只保留后者；
 *    ps 会同时命中 PS4/PS5 游戏，仍然保留——因为 OR 不会丢原词，只是多召回）。
 *  - 后台可在「搜索与展示」里追加自定义组，格式每行一组、逗号分隔。
 */

/** 内置词典：每组是一条等价链 */
function alias_builtin(): array
{
    return [
        // ---- 设计 / 剪辑工具（缩写 ↔ 全称）----
        ['ps', 'photoshop'],
        ['pr', 'premiere', 'premierepro'],
        ['ae', 'aftereffects'],
        ['c4d', 'cinema4d'],
        ['lr', 'lightroom'],
        ['su', 'sketchup'],
        ['cad', 'autocad'],
        ['ppt', 'powerpoint', '幻灯片', '演示文稿'],
        ['剪映', 'jianying', '剪映专业版'],
        ['达芬奇', 'davinci', '达芬奇调色'],
        ['fcp', 'finalcut', 'finalcutpro'],
        ['au', 'audition'],

        // ---- 影视：中文名 ↔ 英文名 / 俗称 ----
        ['复仇者联盟', '复联', 'avengers'],
        ['蜘蛛侠', 'spiderman'],
        ['钢铁侠', 'ironman'],
        ['蝙蝠侠', 'batman'],
        ['海贼王', '航海王', 'onepiece'],
        ['火影忍者', '火影', 'naruto'],
        ['进击的巨人', '巨人', 'attackontitan'],
        ['鬼灭之刃', '鬼灭', 'demonslayer', 'kimetsu'],
        ['咒术回战', '咒术', 'jujutsukaisen'],
        ['名侦探柯南', '柯南', 'detectiveconan'],
        ['权力的游戏', '权游', 'gameofthrones'],
        ['绝命毒师', 'breakingbad'],
        ['生活大爆炸', 'tbbt', 'bigbangtheory'],
        ['西部世界', 'westworld'],
        ['黑镜', 'blackmirror'],
        ['纸牌屋', 'houseofcards'],
        ['星际穿越', 'interstellar'],
        ['盗梦空间', 'inception'],
        ['奥本海默', 'oppenheimer'],
        ['流浪地球', 'wanderingearth'],
        ['三体', 'threebody', '三体电视剧'],
        ['鬼怪', '孤单又灿烂的神'],
        ['鱿鱼游戏', 'squidgame'],
        ['怪奇物语', 'strangerthings'],
        ['曼达洛人', 'mandalorian'],
        ['黑豹', 'blackpanther'],
        ['死侍', 'deadpool'],
        ['变形金刚', 'transformers'],
        ['速度与激情', '速激', 'fastandfurious'],
        ['碟中谍', 'missionimpossible'],
        ['哈利波特', 'harrypotter'],
        ['指环王', '魔戒', 'lordofrings'],
        ['霍比特人', 'hobbit'],
        ['星球大战', '星战', 'starwars'],
        ['侏罗纪公园', 'jurassicpark'],
        ['泰坦尼克号', 'titanic'],
        ['阿凡达', 'avatar'],
        ['沙丘', 'dune'],
        ['芭比', 'barbie'],
        ['请回答1988', '请回答'],
        ['知否知否', '知否'],
        ['后宫甄嬛传', '甄嬛传'],
        ['隐秘的角落', '隐秘角落'],

        // ---- 考试 / 职业 ----
        ['考研', '研究生考试', '研究生入学考试'],
        ['四六级', '英语四六级', 'cet4', 'cet6'],
        ['雅思', 'ielts'],
        ['托福', 'toefl'],
        ['公考', '公务员考试', '国考', '省考'],
        ['教资', '教师资格证'],
        ['cpa', '注册会计师'],
        ['一建', '一级建造师'],
        ['二建', '二级建造师'],
        ['软考', '软件水平考试'],
        ['法考', '司法考试', '法律职业资格'],

        // ---- 技术 / 课程 ----
        ['前端', 'web前端', '前端开发'],
        ['后端', '服务端开发', '后端开发'],
        ['全栈', 'fullstack'],
        ['人工智能', 'ai', 'aigc'],
        ['机器学习', 'ml', '深度学习'],
        ['数据分析', 'dataanalysis'],
        ['爬虫', '网络爬虫', 'spider'],
        ['运维', 'devops', 'kubernetes', 'k8s'],
        ['算法', '算法与数据结构'],
        ['面试', '面经', '面试题'],

        // ---- 内容形态口语 ----
        ['网课', '课程', '教程'],
        ['电子书', 'epub', 'mobi', 'azw3'],
        ['有声书', '听书', 'audiobook'],
        ['短剧', '竖屏短剧', '小程序短剧'],
        ['动漫', '番剧', '动画'],
        ['国漫', '国产动漫', '国创'],
        ['综艺', '真人秀'],
        ['纪录片', '记录片', 'documentary'],
        ['合集', '全集', '打包'],
        ['完结', '已完结', '全本'],
        ['4k', 'uhd', '超高清'],
        ['蓝光', 'bluray', 'bdrip'],

        // ---- 游戏 ----
        ['switch', '任天堂', 'ns游戏'],
        ['steam', '蒸汽平台'],
        ['黑神话', '黑神话悟空', 'wukong', 'blackmyth'],
        ['艾尔登法环', '老头环', 'eldenring'],
        ['塞尔达', 'zelda'],
        ['双人成行', 'ittakestwo'],
        ['原神', 'genshin'],
    ];
}

/** 归一化：小写、去空格、全角转半角，用于查表 */
function alias_key(string $s): string
{
    $s = mb_strtolower(trim($s));
    // 全角字母数字 → 半角，否则「ＰＳ」查不到「ps」
    $s = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', function ($m) {
        return mb_chr(mb_ord($m[0]) - 0xFEE0);
    }, $s) ?? $s;
    return preg_replace('/\s+/u', '', $s) ?? $s;
}

/**
 * 合并内置词典与后台自定义组，返回 term(归一化) => [变体原文]。
 * 后台自定义组的优先级更高（同 key 覆盖），方便站长改词。
 */
function alias_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $groups = alias_builtin();

    if (function_exists('cfg_get_bool')) {
        $extra = (string) cfg_get('search_alias_extra');
        if ($extra !== '') {
            foreach (preg_split('/\R/u', $extra) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = array_values(array_filter(
                    array_map('trim', preg_split('/[,，|｜]/u', $line) ?: []),
                    fn($v) => $v !== ''
                ));
                if (count($parts) >= 2) {
                    $groups[] = $parts;
                }
            }
        }
    }

    $map = [];
    foreach ($groups as $group) {
        $variants = [];
        foreach ($group as $w) {
            $k = alias_key((string) $w);
            if ($k !== '') {
                $variants[$k] = (string) $w;
            }
        }
        if (count($variants) < 2) {
            continue;
        }
        $list = array_values($variants);
        foreach ($variants as $k => $origin) {
            // 同一词出现在多组时合并，不覆盖——否则后一组会把前一组吃掉
            $merged = $map[$k] ?? [];
            foreach ($list as $v) {
                $vk = alias_key($v);
                if ($vk !== $k && !in_array($v, $merged, true)) {
                    $merged[] = $v;
                }
            }
            $map[$k] = $merged;
        }
    }
    return $map;
}

function alias_enabled(): bool
{
    if (!function_exists('cfg_get')) {
        return true;
    }
    return cfg_get('search_alias_on') !== '0';
}

/** 某个词的全部等价写法（含自身）；未命中返回 [原词] */
function alias_variants(string $term): array
{
    $term = trim($term);
    if ($term === '' || !alias_enabled()) {
        return $term === '' ? [] : [$term];
    }
    $map = alias_map();
    $k = alias_key($term);
    if (!isset($map[$k])) {
        return [$term];
    }
    return array_merge([$term], $map[$k]);
}

/**
 * 整条查询的扩展计划。
 * 返回 ['terms'=>[[词, [变体...]], ...], 'hits'=>[原词 => [变体...]], 'expanded'=>bool]
 *
 * @return array{terms:array<int,array{0:string,1:array<int,string>}>,hits:array<string,array<int,string>>,expanded:bool}
 */
function alias_plan(string $q): array
{
    $out = ['terms' => [], 'hits' => [], 'expanded' => false];
    $terms = array_values(array_filter(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    // 词太多时不再扩展：SQL 会膨胀，且长查询本身已经足够精确
    $allow = count($terms) <= 4;
    foreach ($terms as $t) {
        $variants = $allow ? alias_variants($t) : [$t];
        if (count($variants) > 1) {
            $out['hits'][$t] = array_slice(array_slice($variants, 1), 0, 4);
            $out['expanded'] = true;
        }
        $out['terms'][] = [$t, $variants];
    }
    return $out;
}

/**
 * 无结果时的兜底建议词。
 * 只给「确认搜得到」的候选（由调用方 probe 验证），否则又是一次空结果，比不给更糟。
 */
function alias_suggest(string $q, int $max = 6): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $out = [];

    // 1) 别名组的其他写法（最可能命中）
    $plan = alias_plan($q);
    foreach ($plan['hits'] as $alts) {
        foreach ($alts as $a) {
            $out[] = $a;
        }
    }

    // 2) 查询里「包含」某个别名（复联4 → 复联 → 复仇者联盟）。
    //    加序号/后缀是最常见的搜法，精确匹配往往落空，这一步能救回一大片。
    $qk = alias_key($q);
    $bestKey = '';
    foreach (alias_map() as $key => $alts) {
        if ($key !== $qk && mb_strlen($key) >= 2 && str_contains($qk, $key)
            && mb_strlen($key) > mb_strlen($bestKey)) {
            $bestKey = $key;
        }
    }
    if ($bestKey !== '') {
        foreach (alias_map()[$bestKey] as $a) {
            $out[] = $a;
        }
    }

    // 3) 去掉末尾序号 / 修饰词
    $trimmed = preg_replace('/[\s·・,，。、!！?？:：;；"\'\x{201c}\x{201d}\x{2018}\x{2019}]+$/u', '', $q) ?? $q;
    $trimmed = preg_replace('/\s*(全集|完结|更新|下载|资源|网盘|高清|在线观看|免费|剧场版|电影版)\s*$/u', '', $trimmed) ?? $trimmed;
    $trimmed = preg_replace('/[\d\s]+$/u', '', $trimmed) ?? $trimmed;
    if ($trimmed !== '' && $trimmed !== $q) {
        $out[] = $trimmed;
    }

    // 4) 逐级截断（只对纯中文做：按字符切英文会切出「ps20」这种垃圾词）
    $len = mb_strlen($q);
    if (preg_match('/^\p{Han}+$/u', $q) && $len >= 3) {
        $out[] = mb_substr($q, 0, $len - 1);
        if ($len >= 5) {
            $out[] = mb_substr($q, 0, 4);
        }
        if ($len >= 4) {
            $out[] = mb_substr($q, 0, 3);
        }
        if ($len >= 5) {
            $out[] = mb_substr($q, 0, 2);
        }
    }
    if (str_contains($q, ' ')) {
        $out[] = str_replace(' ', '', $q);
        $out[] = str_replace(' ', '', mb_strtolower($q));
    }

    $out = array_values(array_unique(array_filter($out, fn($v) => $v !== '' && $v !== $q)));
    return array_slice($out, 0, $max);
}
