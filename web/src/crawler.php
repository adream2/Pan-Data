<?php
declare(strict_types=1);

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/moderation.php';

/**
 * 本地轻量采集：抓 t.me/s/<频道> 的网页预览。
 * 定位是「兜底 + 自建频道」，成规模的归一化去重交给仓库导入那条线。
 */

function crawler_url(string $channel, ?int $before = null): string
{
    $u = 'https://t.me/s/' . rawurlencode($channel);
    if ($before) {
        $u .= '?before=' . $before;
    }
    return $u;
}

function crawler_fetch(string $channel, ?int $before = null): ?string
{
    $html = http_fetch(
        crawler_url($channel, $before),
        (int) cfg('repo.timeout', 30),
        (string) cfg('crawler.proxy', '')
    );
    if ($html === null || !str_contains($html, 'tgme_widget_message')) {
        return null;
    }
    return $html;
}

/** @return array<int, array{post_id:int, url:string, posted_at:?string, text:string, hrefs:array<int,string>}> */
function crawler_parse(string $html, string $channel): array
{
    $chunks = preg_split('#<div class="tgme_widget_message_wrap#', $html) ?: [];
    $messages = [];
    foreach (array_slice($chunks, 1) as $chunk) {
        if (!preg_match('#data-post="([^"]+)"#', $chunk, $m)) {
            continue;
        }
        $postPath = $m[1];
        $postId = (int) preg_replace('#^.*/#', '', $postPath);

        $postedAt = null;
        if (preg_match('#<time[^>]*datetime="([^"]+)"#', $chunk, $t)) {
            $postedAt = $t[1];
        }

        $text = '';
        if (preg_match('#<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>#s', $chunk, $tm)) {
            $text = html_entity_decode(strip_tags(str_replace(['<br/>', '<br>', '</p>'], "\n", $tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
        }

        $hrefs = [];
        if (preg_match_all('#href="(https?://[^"]+)"#', $chunk, $hm)) {
            $hrefs = array_values(array_unique($hm[1]));
        }

        $messages[] = [
            'post_id'   => $postId,
            'url'       => 'https://t.me/' . $postPath,
            'posted_at' => $postedAt,
            'text'      => $text,
            'hrefs'     => $hrefs,
        ];
    }
    return $messages;
}

/** @return array<int, array{provider:string, url:string, pwd:?string}> */
function crawler_extract_links(string $text, array $hrefs): array
{
    $found = [];
    $seen = [];
    $candidates = array_merge([$text], $hrefs);
    foreach ($candidates as $chunk) {
        if (preg_match_all('#https?://[^\s"\'<>]+#i', $chunk, $m)) {
            foreach ($m[0] as $rawUrl) {
                $rawUrl = rtrim($rawUrl, '.,)]）】');
                $provider = mod_provider_of($rawUrl);
                if ($provider === null || isset($seen[$rawUrl])) {
                    continue;
                }
                $seen[$rawUrl] = true;
                $pwd = null;
                if (preg_match('#[?&]pwd=([0-9a-zA-Z]{2,8})#', $rawUrl, $pm)) {
                    $pwd = mod_clean_pwd($pm[1]);
                } elseif (preg_match('#(?:提取码|密码|访问码|口令)\s*[:：]?\s*([0-9a-zA-Z]{4,8})#u', $text, $cm)) {
                    $pwd = mod_clean_pwd($cm[1]);
                }
                $found[] = ['provider' => $provider, 'url' => $rawUrl, 'pwd' => $pwd];
            }
        }
    }
    return $found;
}

function crawler_title(string $text): string
{
    $lines = preg_split('/\R/u', $text) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(?:名称|标题|资源|课程)[:：]\s*(.+)$/u', $line, $m)) {
            return mb_substr(trim($m[1]), 0, 120);
        }
        return mb_substr($line, 0, 120);
    }
    return '';
}

function crawler_ingest(string $channel, int $pages = 1): array
{
    $stat = ['channel' => $channel, 'pages' => 0, 'messages' => 0, 'links' => 0, 'insert' => 0, 'dup' => 0, 'blocked' => 0];
    $before = null;
    $sleepMs = (int) cfg('crawler.sleep_ms', 800);

    for ($p = 0; $p < $pages; $p++) {
        $html = crawler_fetch($channel, $before);
        if ($html === null) {
            break;
        }
        $messages = crawler_parse($html, $channel);
        if (!$messages) {
            break;
        }
        $stat['pages']++;
        $stat['messages'] += count($messages);
        $minId = null;
        foreach ($messages as $msg) {
            if ($minId === null || $msg['post_id'] < $minId) {
                $minId = $msg['post_id'];
            }
            $text = $msg['text'];
            if ($text === '') {
                continue;
            }
            $links = crawler_extract_links($text, $msg['hrefs']);
            if (!$links) {
                continue;
            }
            $title = crawler_title($text);
            $mod = mod_check($title, $text);
            if ($mod['level'] === 'blocked') {
                $stat['blocked']++;
                continue;
            }
            foreach ($links as $link) {
                $stat['links']++;
                $exists = db()->prepare('SELECT id FROM resources WHERE share_url = ?');
                $exists->execute([$link['url']]);
                if ($exists->fetchColumn() !== false) {
                    $stat['dup']++;
                    continue;
                }
                $id = 'c' . substr(sha1($link['url']), 0, 15);
                $row = [
                    'id'         => $id,
                    'title'      => $title,
                    'core_title' => mb_substr(preg_replace('/[【】\[\]（）()]/u', '', $title) ?? $title, 0, 60),
                    'type'       => 'other',
                    'provider'   => $link['provider'],
                    'share_url'  => $link['url'],
                    'pwd'        => $link['pwd'],
                    'status'     => 'unchecked',
                    'channel'    => $channel,
                    'post_url'   => $msg['url'],
                    'posted_at'  => $msg['posted_at'],
                    'desc'       => mb_substr($text, 0, 200),
                ];
                repo_upsert($row, 'crawl');
                $stat['insert']++;
            }
        }
        if ($minId === null) {
            break;
        }
        $before = $minId;
        usleep($sleepMs * 1000);
    }

    repo_log('crawl', true, '采集 ' . $channel, $stat);
    return $stat;
}
