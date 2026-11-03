<?php
// PHP 内置服务器路由：静态文件直出，其余交给对应脚本
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 真实存在的文件（CSS / 图片 / api.php / admin.php 等）交给内置服务器直接处理
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// robots.txt 是动态生成的（域名跟着后台设置走），public 下没有这个文件
if ($path === '/robots.txt') {
    require __DIR__ . '/robots.php';
    return true;
}
if ($path === '/sitemap.xml' || $path === '/sitemap_index.xml') {
    $_GET['kind'] = 'index';
    require __DIR__ . '/sitemap.php';
    return true;
}
if ($path === '/sitemap-core.xml') {
    $_GET['kind'] = 'core';
    require __DIR__ . '/sitemap.php';
    return true;
}
if (preg_match('#^/sitemap-res-(\d+)\.xml$#', $path, $m)) {
    $_GET['kind'] = 'res';
    $_GET['n'] = $m[1];
    require __DIR__ . '/sitemap.php';
    return true;
}

require __DIR__ . '/index.php';
