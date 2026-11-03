<?php
declare(strict_types=1);

/**
 * 本地化海报输出：/poster.php?f=xxx.jpg
 * 只允许读 storage/posters 下的图片文件，防止路径穿越。
 */
require_once __DIR__ . '/../src/bootstrap.php';

$name = basename((string) ($_GET['f'] ?? ''));
if ($name === '' || !preg_match('/^[A-Za-z0-9_.\-]+\.(jpg|jpeg|png|webp)$/i', $name)) {
    http_response_code(404);
    exit;
}
$file = APP_ROOT . '/storage/posters/' . $name;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('content-type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('cache-control: public, max-age=604800, immutable');
header('content-length: ' . filesize($file));
readfile($file);
