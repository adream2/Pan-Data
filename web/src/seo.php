<?php
declare(strict_types=1);

/**
 * SEO：站点地址、分类落地页、canonical/robots 判定、JSON-LD、sitemap。
 *
 * 设计原则
 *  - 只做「我们真的有的内容」：不做关键词堆砌，不标不存在的结构化类型。
 *  - 详情页只用 WebPage + BreadcrumbList，不用 Movie/Dataset —— 我们不是内容发布者，
 *    乱标 rich result 会被判垃圾结构化数据。
 *  - 搜索参数页默认 noindex,follow：中文站被索引几十万个 `?q=` 组合是灾难。
 */

/** 站点根地址（不带结尾斜杠）。优先后台设置，其次配置，最后按请求推断。 */
function seo_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $candidates = [
        (string) (function_exists('cfg_get') ? cfg_get('site_url') : ''),
        (string) cfg('base_url', ''),
    ];
    foreach ($candidates as $c) {
        $c = rtrim(trim($c), '/');
        if ($c !== '' && preg_match('#^https?://#i', $c)) {
            $base = $c;
            return $base;
        }
    }
    // 兜底：按请求推断（反向代理下需要正确处理 X-Forwarded-Proto）
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    // 只保留合法 host，避免 Host 头注入到 canonical
    if (!preg_match('/^[A-Za-z0-9\.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    $base = ($https ? 'https://' : 'http://') . $host;
    return $base;
}

function seo_abs(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return seo_base() . '/' . ltrim($path, '/');
}

/** 详情页路径：/s/{id}（id 只含字母数字，安全） */
function seo_detail_path(string $id): string
{
    return '/s/' . rawurlencode($id);
}

function seo_detail_url(string $id): string
{
    return seo_abs(seo_detail_path($id));
}

/**
 * 分类落地页表。slug => spec
 *  filter 直接喂给 repo_search；desc/intro/faq 都是各页独有的，避免多页同质化。
 *
 * @return array<string, array{slug:string,type:string,name:string,h1:string,title:string,
 *   desc:string,intro:string,keywords:string,filter:array,faq:array<int,array{q:string,a:string}>}>
 */
function seo_landings(): array
{
    return [
        'movie' => [
            'slug'  => 'movie',
            'type'  => 'movie',
            'name'  => '影视',
            'h1'    => '影视资源',
            'title' => '影视资源网盘搜索 - 电影 / 电视剧 / 动漫 / 综艺',
            'desc'  => '收录电影、电视剧、动漫、综艺的网盘分享链接，按更新时间和集数排序，自动标注链接有效性。',
            'intro' => '这里汇总了来自公开频道的影视类网盘分享，包含院线电影、国产剧、美剧、日韩剧、动漫与综艺。'
                . '列表默认按发布时间倒序；追剧可以直接切换「集数最多」，或去追更日历看今天更新了什么。'
                . '每条记录都会标注网盘平台和链接状态，失效链接不会出现在默认结果里。',
            'keywords' => '电影网盘, 电视剧网盘, 4K电影, 国漫, 美剧, 综艺, 夸克网盘影视',
            'filter' => ['type' => 'movie'],
            'faq' => [
                ['q' => '这些影视资源是免费的吗？', 'a' => '本站只索引公开频道里出现的网盘分享链接，本身不提供文件、不参与交易。文件能否保存或下载，取决于对应网盘账号和该分享的当前状态。'],
                ['q' => '为什么有的资源显示「失效」？', 'a' => '网盘分享会被分享者删除或过期。我们会定期巡检，检测到失效就会标记，默认不在列表里展示；但巡检有周期，仍可能遇到刚失效的情况，可用详情页的备用链接或换其他来源。'],
                ['q' => '怎么找更新到最新一集的剧？', 'a' => '两种方式：列表页把排序切成「集数最多」，或者进「追更」页按日期看最近更新的剧集。详情页还会列出同一部剧的其他来源和各自集数。'],
            ],
        ],
        'duanju' => [
            'slug'  => 'duanju',
            'type'  => 'movie',
            'name'  => '短剧',
            'h1'    => '短剧资源',
            'title' => '短剧网盘资源 - 全集在线更新，按集数排序',
            'desc'  => '竖屏短剧、小程序短剧的网盘合集，按总集数和更新时间排序，含多个来源可切换。',
            'intro' => '短剧是这个库更新最快的一类，通常几十到两百集，一次性放全集。'
                . '下面筛出了标题或简介里标注短剧的资源，默认按发布时间倒序，想看完整的可以切「集数最多」。'
                . '短剧的分享链接失效速度比长剧快，遇到打不开优先换同剧的其他来源。',
            'keywords' => '短剧, 竖屏短剧, 小程序短剧, 短剧全集, 短剧网盘',
            'filter' => ['duanju' => true],
            'faq' => [
                ['q' => '短剧一般多少集？', 'a' => '常见 60 到 120 集，单集 1-2 分钟。列表里标题括号中的数字就是总集数，例如「（77集）」。'],
                ['q' => '为什么同一部短剧有多个链接？', 'a' => '不同频道会各自转存后再分享，链接不同但内容往往一致。详情页的「同剧其他来源」会把它们列在一起，方便换一个还能用的。'],
                ['q' => '短剧链接为什么失效得快？', 'a' => '短剧传播量大、举报多，分享者常在几天内主动删除或改私密。我们每日巡检一次，但仍有时间差。'],
            ],
        ],
        'course' => [
            'slug'  => 'course',
            'type'  => 'course',
            'name'  => '课程',
            'h1'    => '课程教程',
            'title' => '课程教程网盘资源 - 编程 / 设计 / 职场 / 考证',
            'desc'  => '编程、设计、剪辑、职场、考证类课程与教程的网盘合集，含配套源码与课件。',
            'intro' => '课程类资源的分享寿命通常比影视长，也更容易带完整课件。'
                . '这里汇总编程开发、UI 设计、视频剪辑、数据分析、职业考证等方向的教程合集，'
                . '多数是整套打包（视频 + 源码 + 素材），下载前可以先看描述里标注的机构与讲师。',
            'keywords' => '课程网盘, 视频教程, 编程教程, 设计教程, 网课资源, 配套源码',
            'filter' => ['type' => 'course'],
            'faq' => [
                ['q' => '课程资源包含源码和课件吗？', 'a' => '取决于分享者打包的内容。标题或描述里写了「带源码」「含课件」的通常会包含；没写的建议先打开分享页看一眼目录再转存。'],
                ['q' => '为什么搜不到某个机构的课？', 'a' => '我们只索引公开频道里出现过的分享。某些机构会主动投诉下架，这类资源不会被收录，也搜不到。'],
            ],
        ],
        'ebook' => [
            'slug'  => 'ebook',
            'type'  => 'ebook',
            'name'  => '电子书',
            'h1'    => '电子书',
            'title' => '电子书网盘下载 - PDF / EPUB / MOBI / 有声书',
            'desc'  => '电子书、技术文档、小说、有声书的网盘分享，支持按格式与平台筛选。',
            'intro' => '电子书类资源体积小、失效慢，是网盘分享里保存率最高的一类。'
                . '这里包含技术书、小说、教材、杂志与有声书的分享链接，格式以 PDF、EPUB、MOBI、AZW3 为主，'
                . '部分资源附带多个版本（扫描版 / 文字版 / 精校版）。',
            'keywords' => '电子书网盘, PDF下载, EPUB, MOBI, 技术书, 有声书',
            'filter' => ['type' => 'ebook'],
            'faq' => [
                ['q' => '扫描版和文字版有什么区别？', 'a' => '扫描版是纸质书逐页拍/扫的图片，能保留版式但无法检索文字、体积大；文字版可以复制和搜索，排版可能简化。标题里通常会标明。'],
                ['q' => '电子书一般用什么格式？', 'a' => 'PDF 最通用；EPUB 适合手机阅读器（可重排）；MOBI/AZW3 是 Kindle 专用。多个格式都在同一个分享里时，按设备挑一个即可。'],
            ],
        ],
        'software' => [
            'slug'  => 'software',
            'type'  => 'software',
            'name'  => '软件',
            'h1'    => '软件工具',
            'title' => '软件工具网盘资源 - 设计 / 办公 / 开发 / 系统工具',
            'desc'  => '设计、办公、开发、系统工具类软件的安装包与绿色版网盘分享。',
            'intro' => '这里汇总设计类（PS、AI、PR、AE）、办公套件、开发工具、系统工具与插件的安装包分享。'
                . '需要提醒的是：软件类资源风险最高，来源不可控时请自行判断，'
                . '涉及激活、破解的分享随时可能被删除，也建议优先考虑正版或官方试用。',
            'keywords' => '软件下载, 设计软件, 办公软件, 开发工具, 安装包',
            'filter' => ['type' => 'software'],
            'faq' => [
                ['q' => '下载的安装包安全吗？', 'a' => '我们无法验证文件内容，只索引链接。请自行用杀毒软件检查，敏感软件建议从官方渠道获取。'],
                ['q' => '为什么软件类资源失效得特别快？', 'a' => '版权方投诉集中在软件类，分享者经常被要求删除或主动下架。这类资源可用时间通常比影视、课程短得多。'],
            ],
        ],
        'music' => [
            'slug'  => 'music',
            'type'  => 'music',
            'name'  => '音乐',
            'h1'    => '音乐资源',
            'title' => '音乐资源网盘 - 无损音质 / 专辑 / 歌单',
            'desc'  => '无损音乐、专辑合集、演唱会现场的网盘分享，含 FLAC / APE / WAV 等格式。',
            'intro' => '音乐类分享以无损专辑和歌单合集为主，格式常见 FLAC、APE、WAV、DSD。'
                . '这里按发布时间倒序，适合找整张专辑或某个歌手的合集，而不是单曲试听。',
            'keywords' => '无损音乐, FLAC, APE, 专辑下载, 歌单合集',
            'filter' => ['type' => 'music'],
            'faq' => [
                ['q' => '无损和普通音质差别大吗？', 'a' => '取决于设备和音源。手机配普通耳机差别有限；用解码器或好一点的耳机时，FLAC 相比 320kbps MP3 的高频和动态会更自然。'],
                ['q' => '这些音乐可以商用吗？', 'a' => '不可以。这些分享仅供个人试听学习，商用需要取得版权方授权，否则属于侵权。'],
            ],
        ],
    ];
}

/** 按 slug 取落地页；不存在返回 null */
function seo_landing(string $slug): ?array
{
    return seo_landings()[$slug] ?? null;
}

/** 类型 → 落地页 slug（用于站内互链） */
function seo_landing_slug_for_type(string $type): ?string
{
    foreach (seo_landings() as $slug => $spec) {
        if (($spec['filter']['type'] ?? null) === $type && $slug !== 'duanju') {
            return $slug;
        }
    }
    return null;
}

/**
 * 计算当前请求的 canonical 与 robots。
 *
 * @param array{route:string,q?:string,type?:string,provider?:string,sort?:string,page?:int,id?:string,slug?:string,days?:int} $ctx
 * @return array{canonical:string,robots:string}
 */
function seo_meta_for(array $ctx): array
{
    $route = (string) ($ctx['route'] ?? 'search');
    $page  = max(1, (int) ($ctx['page'] ?? 1));
    $q     = trim((string) ($ctx['q'] ?? ''));
    $type  = (string) ($ctx['type'] ?? '');
    $prov  = (string) ($ctx['provider'] ?? '');
    $sort  = (string) ($ctx['sort'] ?? 'new');
    $allowSearch = (string) (function_exists('cfg_get') ? cfg_get('seo_index_search') : '0') === '1';

    $pageQs = $page > 1 ? '?page=' . $page : '';

    switch ($route) {
        case 'detail':
            return [
                'canonical' => seo_detail_url((string) ($ctx['id'] ?? '')),
                'robots'    => 'index, follow, max-image-preview:large',
            ];

        case 'landing':
            return [
                'canonical' => seo_abs('/' . (string) ($ctx['slug'] ?? '') . $pageQs),
                'robots'    => 'index, follow, max-image-preview:large',
            ];

        case 'channel':
            return [
                'canonical' => seo_abs('/c/' . (string) ($ctx['channel'] ?? '') . $pageQs),
                'robots'    => 'index, follow, max-image-preview:large',
            ];

        case 'channels':
            return ['canonical' => seo_abs('/channels'), 'robots' => 'index, follow'];

        case 'updates':
            // 只有默认 7 天版本参与索引，其余参数页权重合并过去
            $days = (int) ($ctx['days'] ?? 7);
            return [
                'canonical' => $days === 7 ? seo_abs('/updates') : '',
                'robots'    => $days === 7 ? 'index, follow' : 'noindex, follow',
            ];

        case 'notfound':
            return ['canonical' => '', 'robots' => 'noindex, nofollow'];

        case 'submit':
        case 'user':
            return ['canonical' => seo_abs('/' . $route), 'robots' => 'noindex, follow'];

        case 'search':
        default:
            // 纯搜索词页：默认不索引
            if ($q !== '') {
                return [
                    'canonical' => $allowSearch
                        ? seo_abs('/search?q=' . rawurlencode($q) . ($page > 1 ? '&page=' . $page : ''))
                        : '',
                    'robots'    => $allowSearch ? 'index, follow' : 'noindex, follow',
                ];
            }
            // 只有筛选、没有关键词
            if ($type !== '' || $prov !== '' || $sort !== 'new') {
                $qs = http_build_query(array_filter([
                    'type' => $type, 'provider' => $prov,
                    'sort' => $sort !== 'new' ? $sort : '',
                    'page' => $page > 1 ? $page : '',
                ]));
                // 筛选组合页 noindex 且不给 canonical（否则是「自己是正版但别收录」的矛盾）
                return ['canonical' => '', 'robots' => 'noindex, follow'];
            }
            // 无任何参数：第 1 页把权重合并给首页，第 2 页起自指
            return [
                'canonical' => $page > 1 ? seo_abs('/search?page=' . $page) : seo_abs('/'),
                'robots'    => 'index, follow',
            ];
    }
}

/** 输出 JSON-LD <script>。数据里的 & < > 在 script 里不合法，统一转义。 */
function seo_jsonld(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '';
    }
    // 防止 </script> 提前闭合
    $json = str_replace(['<', '>', '&'], ['\u003C', '\u003E', '\u0026'], $json);
    return '<script type="application/ld+json">' . $json . '</script>';
}

function seo_jsonld_website(): array
{
    $name = setting('site_name', (string) cfg('site_name'));
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => $name,
        'url'      => seo_abs('/'),
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => seo_abs('/search?q={search_term_string}')],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/** @param array<int,array{name:string,url:string}> $items */
function seo_jsonld_breadcrumb(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => $it) {
        $list[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => (string) $it['name'],
            'item'     => (string) $it['url'],
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** @param array<int,array> $rows */
function seo_jsonld_itemlist(array $rows, string $name): array
{
    $items = [];
    foreach (array_values($rows) as $i => $row) {
        if (!isset($row['id'])) {
            continue;
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'url'      => seo_detail_url((string) $row['id']),
            'name'     => ui_clean_title((string) ($row['title'] ?? '')),
        ];
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => $name,
        'numberOfItems'   => count($items),
        'itemListElement' => $items,
    ];
}

/** @param array<int,array{q:string,a:string}> $faq */
function seo_jsonld_faq(array $faq): array
{
    $items = [];
    foreach ($faq as $f) {
        $items[] = [
            '@type' => 'Question',
            'name'  => (string) $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $f['a']],
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
}

/** 站点首页 CollectionPage */
function seo_jsonld_collection(string $name, string $desc, string $url, int $total): array
{
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $name,
        'description' => $desc,
        'url'         => $url,
        'isPartOf'    => ['@type' => 'WebSite', 'name' => setting('site_name', (string) cfg('site_name')), 'url' => seo_abs('/')],
        'mainEntity'  => ['@type' => 'ItemList', 'numberOfItems' => $total],
    ];
}

/* ------------------------------------------------------------------ */
/* sitemap                                                             */
/* ------------------------------------------------------------------ */

const SEO_SITEMAP_CHUNK = 5000;

/** 详情页总数（用于分片） */
function seo_sitemap_chunk_count(): int
{
    $n = (int) db()->query('SELECT COUNT(*) FROM resources WHERE hidden = 0')->fetchColumn();
    return max(1, (int) ceil($n / SEO_SITEMAP_CHUNK));
}

/**
 * 取一个分片的详情 URL 行
 * @return array<int,array{loc:string,lastmod:string}>
 */
function seo_sitemap_resources(int $chunk): array
{
    $chunk = max(1, $chunk);
    $offset = ($chunk - 1) * SEO_SITEMAP_CHUNK;
    $stmt = db()->prepare(
        'SELECT id, updated_at, posted_at FROM resources WHERE hidden = 0
         ORDER BY posted_at DESC, id ASC LIMIT ' . SEO_SITEMAP_CHUNK . ' OFFSET ' . $offset
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt as $r) {
        $lm = (string) ($r['updated_at'] ?: $r['posted_at'] ?: '');
        $out[] = [
            'loc'     => seo_detail_url((string) $r['id']),
            'lastmod' => $lm !== '' ? gmdate('Y-m-d', strtotime($lm) ?: time()) : gmdate('Y-m-d'),
        ];
    }
    return $out;
}

/**
 * 静态页 + 分类页 + 首页（放同一个 sitemap）
 * @return array<int,array{loc:string,lastmod:string,changefreq:string,priority:string}>
 */
function seo_sitemap_core(): array
{
    $today = gmdate('Y-m-d');
    $out = [
        ['loc' => seo_abs('/'), 'lastmod' => $today, 'changefreq' => 'hourly', 'priority' => '1.0'],
        ['loc' => seo_abs('/updates'), 'lastmod' => $today, 'changefreq' => 'hourly', 'priority' => '0.9'],
    ];
    $out[] = ['loc' => seo_abs('/channels'), 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '0.8'];

    foreach (seo_landings() as $slug => $spec) {
        $out[] = [
            'loc' => seo_abs('/' . $slug), 'lastmod' => $today,
            'changefreq' => 'daily', 'priority' => '0.8',
        ];
    }

    // 频道页：每个都是独立内容（收录量、构成、节奏都不同），值得进 sitemap
    if (function_exists('repo_channels')) {
        foreach (repo_channels(200) as $i => $c) {
            $lm = (string) ($c['latest'] ?? '');
            $out[] = [
                'loc'        => seo_abs('/c/' . (string) $c['channel']),
                'lastmod'    => $lm !== '' ? gmdate('Y-m-d', strtotime($lm) ?: time()) : $today,
                'changefreq' => 'daily',
                'priority'   => $i < 5 ? '0.7' : '0.6',
            ];
        }
    }

    return $out;
}

/** XML 转义（URL 里的 & 必须转义，否则 sitemap 解析失败） */
function seo_xml(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
