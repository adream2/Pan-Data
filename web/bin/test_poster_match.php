<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/poster.php';

$cases = [
    // [说明, 查询标题, 查询年份, TMDB 候选, 期望命中标题|null]
    ['完全一致 + 年份吻合', '猜火车', 1996, [
        ['id' => 1, 'title' => '猜火车', 'release_date' => '1996-02-23', 'poster_path' => '/a.jpg'],
        ['id' => 2, 'title' => '猜火车2', 'release_date' => '2017-01-27', 'poster_path' => '/b.jpg'],
    ], '猜火车'],

    ['年份冲突要放弃', '猜火车', 2020, [
        ['id' => 1, 'title' => '猜火车', 'release_date' => '1996-02-23', 'poster_path' => '/a.jpg'],
    ], null],

    ['后缀差异（国语版，应命中）', '想飞的女孩 国语', 2025, [
        ['id' => 3, 'title' => '想飞的女孩', 'release_date' => '2025-03-08', 'poster_path' => '/c.jpg'],
    ], '想飞的女孩'],

    ['前缀差异可接受', '监视资本主义', 2020, [
        ['id' => 4, 'title' => '监视资本主义：智能陷阱', 'release_date' => '2020-09-09', 'poster_path' => '/d.jpg'],
    ], '监视资本主义：智能陷阱'],

    ['完全不相关要拒绝', '重生成蚊子我绑定愤怒值进化系统', null, [
        ['id' => 5, 'title' => '蚊子', 'release_date' => '2020-01-01', 'poster_path' => '/e.jpg'],
    ], null],

    ['无海报字段不采用', '世外', 2025, [
        ['id' => 6, 'title' => '世外', 'release_date' => '2025-05-01', 'poster_path' => ''],
    ], null],

    ['英文名也能匹配', 'Dune', 2021, [
        ['id' => 7, 'title' => 'Dune', 'release_date' => '2021-09-15', 'poster_path' => '/f.jpg'],
        ['id' => 8, 'title' => 'Dune: Part Two', 'release_date' => '2024-02-27', 'poster_path' => '/g.jpg'],
    ], 'Dune'],

    ['同名不同年优先取年份吻合的', '沙丘', 2021, [
        ['id' => 9,  'title' => '沙丘', 'release_date' => '1984-12-14', 'poster_path' => '/h.jpg'],
        ['id' => 10, 'title' => '沙丘', 'release_date' => '2021-09-15', 'poster_path' => '/i.jpg'],
    ], null],  // 两条都叫沙丘，年份都冲突（1984 和 2021），2021 那条应命中
];

$pass = 0;
$fail = 0;
foreach ($cases as [$desc, $title, $year, $results, $expect]) {
    $got = poster_pick($results, $title, $year, 90, 'movie');
    $gotTitle = $got['title'] ?? null;
    // 最后一条期望实际是 2021 那条
    if ($desc === '同名不同年优先取年份吻合的') {
        $expect = '沙丘';
        $ok = $gotTitle === '沙丘' && (int) $got['year'] === 2021;
    } else {
        $ok = $gotTitle === $expect;
    }
    printf("%-28s %s  得到=%s%s\n", $desc, $ok ? '✓' : '✗', $gotTitle ?? 'null',
        $got ? ' (' . $got['score'] . '分/' . $got['year'] . ')' : '');
    $ok ? $pass++ : $fail++;
}
printf("\n通过 %d，失败 %d\n", $pass, $fail);

echo "\n--- 归一化 ---\n";
foreach (['猜火车 德版', '【国漫】吞噬星空', 'Dune: Part Two', '想飞的女孩（2025）'] as $t) {
    printf("%-22s -> %s\n", $t, poster_norm($t));
}
echo "\n--- 匹配键（同片不同版本应一致）---\n";
foreach ([['猜火车', 1996], ['猜火车 德版', 1996], ['猜火车（国语中字）', 1996], ['猜火车', null]] as [$t, $y]) {
    printf("%-24s %s -> %s\n", $t, $y ?? '-', poster_key($t, $y));
}
exit($fail > 0 ? 1 : 0);
