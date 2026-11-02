<?php
/** 站点配置。本地覆盖：复制成 src/config.local.php 改值，不进版本库。 */
return [
    'site_name'    => '盘搜',
    'site_slogan'  => 'Telegram 网盘资源聚合搜索',
    'db_path'      => __DIR__ . '/../storage/app.sqlite',
    'base_url'     => '',
    'timezone'     => 'Asia/Shanghai',
    'page_size'    => 20,
    'per_page_max' => 100,

    // 数据仓库：走 GitHub Contents API（raw 域名在部分网络下 TLS 握手失败，API 更稳）
    'repo' => [
        'github'  => 'adream2/Pan-Data',
        'branch'  => 'data',
        'dir'     => 'data',
        'token'   => '',     // 可选；填了能提高 API 限额
        'proxy'   => '',
        'timeout' => 120,
    ],

    'github' => [
        'repo'  => 'adream2/Pan-Data',
        'token' => '',
        'workflows' => [
            'collect'  => 'collect.yml',
            'backfill' => 'backfill.yml',
            'mtproto'  => 'mtproto.yml',
        ],
    ],

    'crawler' => [
        'channels' => ['freekecheng', 'PanjClub', 'yunpanNB', 'Quark_Movies'],
        'pages'    => 1,
        'proxy'    => '',
        'sleep_ms' => 800,
    ],

    'security' => [
        'session_name'    => 'pansou_sid',
        'login_max_try'   => 8,
        'submit_per_hour' => 6,
    ],
];
