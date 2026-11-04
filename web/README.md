# 盘搜 · PHP 版全套搜索站

PHP 8.4+ / SQLite 单文件数据库，零框架零 Composer 依赖。前台搜索 + 投稿，后台审核 + 同步 + 采集。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | 8.4+（实测 8.5.12） |
| 扩展 | `pdo_sqlite` `sqlite3` `curl` `mbstring` `json` |
| SQLite | 3.34+（FTS5 trigram 分词，中文子串检索靠它） |

检查：`php -m | grep -E "pdo_sqlite|curl|mbstring"`

## 快速开始

```bash
cd web
php bin/init.php admin '你的密码'        # 建表 + 创建管理员
php bin/sync.php index                   # 检查能否连通数据仓库
php bin/sync.php hot                     # 导入最近 90 天（约 7000 条，1-2 分钟）
php -S 127.0.0.1:8000 -t public public/router.php
```

打开 http://127.0.0.1:8000 ，后台在 `/admin.php`。

## 用户中心

`/user` — 注册 / 登录 / 我的投稿 / 我的收藏 / 我的举报 / API 密钥。

- 注册即登录，密码 `password_hash`（bcrypt），登录限流 10 次 / 15 分钟
- 收藏：详情页一键收藏/取消
- 举报：详情页选类型（失效 / 内容不符 / 违规 / 其它）+ 备注，进后台举报池
- API 密钥：自助生成，90 天有效，可重新生成（旧 key 立即失效）

## 开放 API

```bash
curl -H "X-API-Key: pk_xxx" "https://your-domain/api.php?q=课程&limit=20&type=course&provider=quark"
curl "https://your-domain/api.php?action=stats"     # 公开统计，不需要 key
```

返回：`{code:0, data:{total,page,pageSize,pages,items:[{id,title,provider,type,year,episode,complete,share_url,pwd,status,posted_at,sources,detail_url}]}}`
限流：每 key 60 次/分钟。无效 key 返回 401，超限 429。

## 后台

| 模块 | 能力 |
|---|---|
| 概览 | 今日入库（含近 7 日）、今日搜索、今日点击、待处理举报、按类型/平台分布、近 7 天热搜与热门资源 |
| 投稿审核 | 风险分级 + 勾选批量通过/拒绝；顶部实时显示当前审核模式的语义 |
| 资源管理 | 关键词搜索、下架、**一键创建拉新任务**（仅夸克且拉新开启时出现） |
| 同步与采集 | 热文件同步、历史分片逐个导入、本地 t.me 采集、一键触发云端 workflow |
| 用户管理 | 列表、封禁/解封、发 API Key、重置密码 |
| 举报处理 | 待处理队列，标「失效」会自动把资源置为 invalid |
| 批量录入 | 粘贴多行「标题 链接」，自动识别平台/提取码、跳过重复 |
| 拉新任务 | 总开关状态、今日配额、待执行/已完成/失败统计、单条执行、批量执行、取消、重排、结果链接可点 |
| 网盘账号 | 夸克等多平台 Cookie 的增删启停，展示掩码与最近使用时间 |
| 设置 | **6 类共 29 项配置**，保存即时生效（见下） |

### 设置项（全部后台可改，不需要动文件）

| 分组 | 配置 |
|---|---|
| 外观 | 站点名称、副标题、站内公告 |
| 投稿 | 开放投稿、投稿需登录、每小时限流、重复链接跳过 |
| 审核 | 审核模式（全部人工 / 低风险自动通过 / 免审）、低风险免审类型白名单、举报自动判失效阈值、失效后是否自动换源 |
| 搜索与展示 | 每页条数、热门搜索条数、是否展示失效链接、是否展示未巡检链接、同义词扩展开关、自定义同义词 |
| 拉新 | 总开关、转存自动执行、每日上限、最小间隔 |
| 数据源 | 数据仓库 / 分支 / 目录 / 拉取代理 / 拉取 Token / 采集触发 Token / 超时 |

**三层回落**：后台设置值 → `src/config.local.php` → 内置默认。
所以老部署不用改文件，新部署也可以只改后台。

**敏感项**（`repo_token`、`github_token`）用密码框渲染，留空表示保持原值，不会把已有 token 覆盖成空。

### 投稿审核的三档语义

| 模式 | 行为 | 适用 |
|---|---|---|
| 全部人工审核（`all`） | 每条投稿都进待审队列 | 内容敏感、需要逐条把关 |
| 低风险自动通过（`low_auto`，默认） | 通过风控且类型在白名单内 → 直接入库；命中敏感词 → 进待审 | 大部分场景 |
| 免审（`none`） | 只要不命中违禁词就直接入库 | 自用/信任来源 |

**违禁词命中在三种模式下都会被拦**，这是硬闸，不受审核模式影响。

### 拉新（夸克转存再分享）

后台完整闭环，不依赖命令行：

```
资源管理点「拉新」→ 生成任务 → 拉新任务页执行 → 转存到自己网盘 → 生成新分享链接
```

前提：**拉新总开关打开 + 至少一个启用的夸克账号（Cookie）**。
缺任一项时，拉新页会显示黄色告警并给出跳转链接；资源管理页不会出现「拉新」按钮。

三道限制防封号：每日上限（默认 30）、两次执行最小间隔（默认 60 秒）、单次批量最多 5 条。

转存走 `src/quark.php`，接口与仓库根的 `collector/quark_client.py` 完全一致：
`sharepage/token` → `sharepage/detail` → `sharepage/save` → `task` → `share` → `share/password`。
失败会记录错误原因到任务行，可「重排」重试。

**Cookie 存 SQLite 明文**，与 urldb 同级别。生产环境请收紧 `storage/app.sqlite` 权限（`chmod 600`）。

## 排序与追更

### 排序切换

列表页可切三种排序（URL 参数 `sort`）：

| 参数 | 排序逻辑 | 用途 |
|---|---|---|
| `new`（默认） | 发布时间倒序；有关键词时按 bm25 相关度 | 常规搜索 |
| `sources` | 来源数倒序，相关度退为次级 | 找「被最多频道转发」的资源 |
| `episode` | 集数倒序（NULL 排最后） | 追更、找合集 |

选了非默认排序就以排序为主，相关度只作为同分时的次级条件——否则用户点了「集数最多」却还是按相关度排，等于白点。

### 追更日历 `/updates`

按 `core_title` 聚合，取每部剧最近更新的集数，按日期分组展示（今天 / 昨天 / 3月5日 周三）。

- 天数可切 7 / 14 / 30 天
- 每天最多列 25 部，其余折叠（否则 500 部会渲染出 500KB HTML，实测过）
- 单来源、单平台的行显示 `—`，不堆「1 源 1 端」这种噪音

### 同剧其他来源（详情页）

同一部剧被多个频道转发时，详情页会列出各自集数、平台、状态、时间。

排序与采集端 `grouping.py` 保持一致：**链接有效 > 未巡检 > 失效**，然后集数高、时间新。
所以打开详情页时，默认看到的第一条就是最可靠的那个版本。

### 标题清洗

频道文案经常把链接写进标题（`《吞噬星空》2026 更至248集 链接：https://pan.quark.cn/s/xxx`），
列表和详情统一过 `ui_clean_title()` 去掉尾部链接段，只保留对用户有用的信息。

**踩坑记录**：不能用 `trim($t, " ·，、—")` 去多字节标点——`trim` 按**字节**处理，
会把 `《`（3 字节）切掉一部分变成乱码，整个标题 UTF-8 失效。必须用 `preg_replace` + `/u`。

## 搜索质量：同义词、空结果兜底、相关搜索

网盘搜索站最大的体验损失不是「排序不准」，而是**库里有、但用户搜不到**。
标题是频道随手起的，同一部作品可能写「海贼王」也可能写「One Piece」，
课程写「PS教程」而不是「Photoshop教程」。按字面匹配，一半的搜索是白搜。

### 同义词扩展（`src/alias.php`）

内置约 80 组等价词（工具缩写、影视中英文名、考试别名、内容形态口语），
检索时按 `A OR B OR C` 展开，原词始终保留，所以只会扩大召回、不会丢结果：

```
搜「复联」 → 命中 0 条 → 扩展 (复联 OR 复仇者联盟 OR avengers) → 8 条
搜「网课」 → 3,457 条（含「课程」「教程」）
```

- 组内词互为别名，任一词进来都展开整组。
- 后端可在**设置 → 搜索与展示 → 自定义同义词**追加，每行一组、逗号分隔，改完即时生效。
- **有歧义的短词不硬收**：`ai` 只关联「人工智能 / AIGC」，不关联 Illustrator——搜 AI 的人九成在找课，不是找设计软件。
- 列表页会显示一行「已按同义词扩展 PS ≈ Photoshop」，让用户知道为什么结果变了，而不是莫名其妙。

**踩坑**：FTS5 的 trigram 分词要求每个词 ≥3 字符，`ps`、`三体` 这种短词在 FTS 里永远 0 命中。
现在只要查询里有短词就整体回退 `LIKE`，否则「三体 4K」会因为 `4k` 只有 2 字符而搜不到任何东西。

### 空结果兜底

0 结果时不再只显示「换个词试试」，而是给 4 个**已经实测有结果**的候选：

| 来源 | 例子 |
|---|---|
| 别名组其他写法 | `复联4` → 复仇者联盟 |
| 查询内嵌别名 | `复联4` 里含 `复联` → 取该组全称 |
| 去尾缀 | `海贼王剧场版` → 海贼王 |
| 逐级截断 | `甄嬛传高清` → 甄嬛传 |

每个候选都用 `repo_probe()` 扫一遍（只扫 title / core_title，不建 FTS 索引、不排序，单次约 10ms），
**搜不到的候选直接丢掉**——推荐过去还是空页，比不推荐更伤。

### 详情页「再搜一下」

从标题里剥出作品本体名（去掉季、集、画质、字幕、编码标记），生成 4 个搜索入口：

```
掌门低调点 (2026) 更至38集 [4K][国漫/奇幻/动画]
→ 掌门低调点 ｜ 掌门低调点 全集 ｜ 掌门低调点 2026 ｜ 掌门低调点 4K
```

同样每个词都先 probe 验证。用户从搜索结果点进某一条时，真正想问的往往是
「这剧还有别的版本吗」，给他能再搜的词比让他回搜索框重新想要有效。

## 链接健康度：举报联动下架 + 自动换源

原先的流程是「用户举报 → 堆在后台 → 管理员手动点失效」。问题是没人管等于没做，
用户第二次点进来还是坏链接，直接走人；而且就算标了失效，mirrors 里躺着的
同内容备用链接（别的频道转发同一部剧）还是没人用——本来换个链接就能救回来。

现在的链路（`src/health.php`）：

```
用户举报
  ↓ 同一资源被 ≥N 个不同 IP 报「失效/内容不符」（N 默认 2，后台可调，0=关闭）
自动标记 status=invalid
  ↓ 若开启「失效后自动换源」
mirrors 里挑状态最好的来源提升为主链接，原链接降级为 invalid 镜像
  ↓
用户点进来拿到的仍然是能打开的地址
```

- 计数用 **DISTINCT IP** 而不是条数，同一个人连点两次不算两个人说它坏了。
- 换源后 `checked_at` 置空、状态回 `unchecked`，等下次巡检重新确认，不假装已经验过。
- 前台会明说「该链接已失效，已自动切换到备用来源」——不说清楚，用户看到平台变了会以为是 bug。
- 后台「举报处理」页新增**按资源汇总**的待复核队列，直接显示「N 人报坏 / M 个可用备用源」，
  一眼决定点「标失效并换源」还是「重排复核」。

### 顺带修掉的一个数据缺陷

mirrors 生成时只排除了自己，没按 URL 去重，也没过滤抓取时被截断的残链。
实测有资源挂了 **349 个镜像**，其中同一 `share_url` 重复几十次，还混着
`https://pan.baidu.com/s/16D` 这种打不开的地址——「自动换源」切到这种链接，比不切更糟。

- 采集端 `tools/export.py`：`build_mirrors()` 按 URL 去重、丢弃尾段 <6 字符的残链、上限 12 条。
- 站内 `repo_clean_mirrors()` 做同样的清洗（防老数据 + 未来别的写入路径）。
- 存量数据用 `php web/bin/repair.php` 修一遍：**14,957 → 7,830 条镜像，砍掉 7,127 条冗余**（重复 6,417）。
- 详情页的备用链接只展示状态不是 invalid 的，valid 排前面。

## 性能：统计查询的 60 秒缓存

首页原来要跑 11 条全表 `GROUP BY`（总数、状态分布、今日、类型、平台、月份…），
实测 **500ms** 起步。数据 30 分钟才同步一次，这些数字没必要每次请求都重算。

- `repo_stats()` 合并成 6 条查询（一次 `GROUP BY status` 推出总数/有效/失效），
- 统计与筛选项结果缓存 60 秒到 `storage/cache/`，写入库后 `repo_mark_dirty()` 在进程退出时统一失效。
- 效果：首页 **500ms → 37ms**（冷启动 244ms），分类页 490ms → 88ms。

## 没有封面列（有意为之）

列表和详情都不放封面图。原因：

1. **海报刮削做不到够好**。豆瓣图片 CDN 服务端取不到（8 个域名/尺寸组合全失败），
   夸克 `sharepage/detail` 不返回缩略图字段，只剩 TMDB（要 Key，且实测命中率仅 20-30%——
   库存大量是短剧，TMDB 无条目）。
2. **半有图半无图比没有更难用**。列表里三行有海报、七行是占位块，用户会觉得站点坏了。
3. **色块占位也不好看**。试过按类型配色的首字色块，实际观感仍然是把视线从文字上拽走。

所以干脆不要。列表靠文字层次和排版取胜：类型标签 → 标题 → 元信息（年份/集数/来源数）→ 描述两行 → 来源频道。

`src/poster.php` 与 `bin/test_poster_match.php` 保留着（匹配逻辑 8 个用例可通过），
文件头写明了恢复需要的三处改动（参照 commit `1a0e529`），将来若有短剧库能补齐数据再接回来。

## 数据来源（三条腿，可同时开）

| 方式 | 入口 | 说明 |
|---|---|---|
| 仓库导入 **（主）** | 后台「同步与采集」/ `bin/sync.php` | 走 GitHub Contents API 拉 `resources.json` 和 `shards/*.json`，幂等 upsert |
| 本地采集 | 后台「本地采集」/ `bin/crawl.php` | 直接抓 `t.me/s/<频道>` 网页预览，兜底和自建频道用 |
| 触发云端 | 后台「触发云端跑一轮」 | 配了 GitHub token 就能一键 dispatch `collect/backfill/mtproto` |

> 为什么不用 `raw.githubusercontent.com`：部分网络下 TLS 握手会被掐断。Contents API 稳定，代价是每小时 60 次限额（填了 `repo.token` 提到 5000）。

## 热文件为什么有 8000 条上限

数据涨到 2.3 万资源后，90 天全量热文件到了 **13.8MB**。实测跨境拉取只有约 40KB/s，
180 秒超时都拉不完，PHP 站同步直接失败（报「拉取 resources.json 失败」）。

所以 `tools/export.py` 加了 `--max-hot`（默认 8000），按 `posted_at` 倒序截取：

| 项 | 值 |
|---|---|
| 热文件 | 最近 90 天里最新的 8000 条，**6.9MB** |
| 被截掉的部分 | 在 `shards/*.json` 里，一个不少 |
| `period.capped` | 被截断时标注「窗口内共 N 条，实际保留 M 条」 |

热文件的定位是「最近活跃的那批」，不是「全部历史」。要全量就导分片。

**另一个必须注意的点**：GitHub API 匿名请求限 60 次/小时，用 PHP 同步全量分片必然撞限。
后台 → 设置 → 数据源 → **拉取用 Token** 必须填（有 `repo` 读权限即可），
否则第 10 个分片之后全是 403。

## 巡检并发

数据上到 2 万条后，串行巡检（0.8s/条）要 5 小时，云端一次任务跑不完，覆盖率会一路下滑。

`check_links.py` 支持 `--workers`（默认 4），实测：

| 模式 | 40 条耗时 |
|---|---|
| 串行 | 62 秒 |
| 4 并发 | **10 秒** |

云端每天跑 `--limit 6000 --workers 4`，约 22 分钟，配合 `--recheck-days 7` 滚动覆盖全库。

并发别调太高：所有 worker 共用一个出口 IP 和同一个网盘账号，夸克会对单账号限流
（429 / 41031）。4-6 是安全区间，12 是上限。

## 定时任务

```cron
*/30 * * * * php /srv/pansou/web/bin/sync.php hot   >> /var/log/pansou.log 2>&1
5    4 * * * php /srv/pansou/web/bin/sync.php shard 2026-10 >> /var/log/pansou.log 2>&1
```

## 功能

**前台**

- 首页：总量、最新收录、类型/平台分布
- 搜索：关键词（空格分词）、类型、平台筛选，FTS5 相关性排序 + 时间兜底
- 详情：原始链接、提取码、**备用盘链接**（同一资源的其它网盘）、相关推荐
- 投稿：表单 → 风控 → 待审队列，限流 6 次/小时

**后台**

- 概览：资源总数、今日新增、待审数、按类型/平台分布
- 投稿审核：风险分级（low/medium/high/blocked），一键通过即入库
- 资源管理：搜索、下架
- 同步与采集：热文件同步、历史分片逐个导入、本地采集、触发云端
- 设置：站名/公告/是否开投稿/改密码

**安全**

- 密码 `argon2id`，登录失败限流（15 分钟 8 次）
- 全部写操作校验 CSRF，输出统一 `htmlspecialchars`
- 只索引分享链接，不存储任何文件内容

## 配置

默认配置在 `src/config.php`，本地覆盖写 `src/config.local.php`（已 gitignore）：

```php
<?php
return [
    'repo'    => ['proxy' => 'http://127.0.0.1:7890', 'token' => ''],
    'github'  => ['token' => 'ghp_xxx'],   // 后台一键触发采集用
    'crawler' => ['proxy' => 'http://127.0.0.1:7890'],
];
```

## 资源分类（7 类，规则在采集端）

分类不是一个装饰字段 —— 它决定每个资源能不能通过 `/movie` `/course` `/game` 这些分类页被发现。

| 类型 | 落地页 | 判定依据 |
|---|---|---|
| `movie` | `/movie` | 电影 / 剧集 / 动漫 / 4K / 蓝光 / 字幕组标记；**标题里的 `(1959)` 也算** |
| `duanju` | `/duanju` | 不是独立 type，而是 movie 里标题/简介带「短剧」的子集 |
| `course` | `/course` | 课程 / 教程 / 训练营 / 实战 / 运营 / 跨境电商 |
| `game` | `/game` | 游戏 / PC游戏 / Switch / 免安装 / 整合版 / 本体+升补 |
| `software` | `/software` | 去广告 / 精简版 / 破解 / 绿色版 / 安装包 / 插件 |
| `ebook` | `/ebook` | PDF / EPUB / 小说 / 教材 / 全书 / 百科 |
| `music` | `/music` | 无损 / FLAC / 专辑 / 原声 / 演唱会 |
| `other` | 无 | 上面都不匹配的（写真、壁纸、杂项） |

### 匹配顺序就是优先级

`RSC_KEYWORDS` 的键序决定谁先命中。最初写成 movie 优先，结果「PC游戏XXX 全集」被
「全集」两个字抢成了影视。现在顺序是：

```
game → software → ebook → music → course → movie
```

特征越独特的越靠前。movie 放最后是因为它的关键词（全集、高清）最泛。

### 年份兜底

「战上海 (1959)」「正午 High Noon (1952)」这类老片标题里没有"电影"二字，
但 `(19xx)` 括号基本只出现在影视作品的发布信息里，所以最后加一条兜底：
标题有年份且前面都没命中 → `movie`。

**实测效果**（23.5k 条）：`other` 从 34.3% 降到 20.4%，movie 补回 1,887 条"丢失"的老片。

### 为什么没有「图片」类

写真/壁纸有 450+ 条，但如果做成分类落地页，等于把这些内容主动推到前台 ——
平台风险大于流量收益。它们留在 `other`，不设入口、不进 sitemap。

### 历史数据怎么重算

改造前采集的几十万条 `rtype` 是旧的。**不回采、不写回填脚本**：
`tools/export.py` 在导出时用当前规则对 `best_title` 重算一次 type。
导出是唯一出口，在这里统一口径最省事。

## 频道页：站内最大的内链资产

详情页原本最大的问题是**12,000 多个页面结构雷同**——除了链接，几乎没有本页独有内容，容易被判薄内容。
真正能补上这一块的不是"多加几段通用说明"（那样 12,000 个页面文字完全一样，更糟），而是**按内容来源切出的频道维度**。

### 两个新页面

| 路径 | 内容 |
|---|---|
| `/channels` | 全部来源频道一览：收录量、平台数、类型数、占全站比、最近更新、失效数 |
| `/c/{channel}` | 单个频道：动态生成的说明段 + 频道内平台/类型构成 + 该频道全部资源（分页） |

### 为什么这能解决薄内容

`/c/leoziyuan` 的说明段是**按数据现算**的：

```
这是公开频道 @leoziyuan 的资源归档。本站从该频道的公开网页预览里采集到 948 条网盘分享，
覆盖 6 个网盘平台、4 种内容类型，其中夸克链接占 91%，内容以影视为主（88%）。
收录区间 2026-11-01 至 2026-11-03。
```

每个频道这句话都不一样，而且是事实（不是模板换词）。加上频道内的平台/类型分布、每页 20 条资源，
每个频道页都有独立可读的内容。

### 详情页也补了一块

「@频道 发布的其他资源」——同频道的 6 条资源，带平台/类型/时间。这既是独特内容，也是给频道页导流。
另外「来源频道」从纯文本改成了指向 `/c/{channel}` 的链接。

### 一个刻意的处理：停更频道如实标注

24 个频道里有 4 个已经 7 天以上没更新（`yespojie`、`movies12138` 等）。
频道页照实显示「最近更新 11 天前」，`/channels` 里也保留它们——**不假装活跃**。
用户自己判断这个号还值不值得看，比藏起来更可信。

### sitemap

频道页全部进 `sitemap-core.xml`（现在 33 条：首页 + 频道总览 + 6 个分类 + 24 个频道 + 追更）。
频道页的 `lastmod` 用该频道最后一条资源的时间，不是拉取时间——内容真的没变就不该骗爬虫重抓。

## SEO

### URL 结构

| 路径 | 内容 | 索引策略 |
|---|---|---|
| `/` | 首页 | index |
| `/movie` `/duanju` `/course` `/ebook` `/software` `/music` | 分类落地页（带独立的 H1、说明段、FAQ） | index，`?page=N` 各自 self-canonical |
| `/s/{id}` | 资源详情 | index，带 WebPage + BreadcrumbList |
| `/channels` | 来源频道总览 | index |
| `/c/{channel}` | 单频道页 | index，`?page=N` self-canonical |
| `/updates` | 追更日历 | index（`?days=30` 等参数页 noindex） |
| `/search` | 全部资源 | index，第 1 页 canonical 指 `/`（与首页同内容） |
| `/search?q=…` | 搜索结果 | **noindex, follow**（默认，可在设置里改） |
| `/search?type=&sort=` | 筛选组合 | noindex（避免几十万个组合被索引） |
| `/sitemap.xml` | sitemap 索引 | — |
| `/robots.txt` | 动态生成，域名跟着设置走 | — |

**为什么搜索词页默认 noindex**：中文站一旦放开，搜索引擎会爬进几百上千个 `?q=` 变体，索引爆掉、正文重复、权重稀释，是搜索站最典型的自伤。分类落地页已经承担了「让人搜到」的职责。

`/detail?id=xxx` 会 **301** 到 `/s/xxx`，一个内容只留一个地址。

**noindex 的页面不输出 canonical**——自己声明「我是正版」又声明「别收录」是矛盾信号，Google 会以 noindex 为准，不如干脆不给。

### 结构化数据

只标真实存在的类型，不猜：

| 页面 | 类型 |
|---|---|
| 全站 | `WebSite` + `SearchAction`（sitelinks 搜索框） |
| 分类页 | `CollectionPage` + `ItemList` + `FAQPage` |
| 详情页 | `WebPage` + `BreadcrumbList` |

**详情页故意不标 `Movie` / `Dataset`**：我们不是内容发布者，只是链接索引。乱标 rich result 会被判垃圾结构化数据，比不标更糟。

### sitemap

按 5000 条分片，`/sitemap.xml` 是索引：

```
/sitemap-core.xml     首页 + 6 个分类页 + 追更（8 条）
/sitemap-res-1..3.xml 详情页，每片 5000 条
```

详情分片只输出 `loc` + `lastmod`（Google 早已忽略 `changefreq`/`priority`，去掉省 30% 体积）。
超出范围的分片号返回空 `<urlset>` 而不是 404——404 的 sitemap 会让爬虫更快放弃。

### 必须先做的一步

后台 → 设置 → 外观 → **站点完整地址** 填 `https://你的域名`（带协议、不带结尾斜杠）。

留空会按请求推断：能从 `Host` 头拿到域名，但如果前面有 CDN / 反代，sitemap 与 canonical 里可能出现内网地址。

填好后访问 `/sitemap.xml` 确认域名正确，再提交到 Google Search Console / Bing Webmaster。

## 生产部署（Nginx + PHP-FPM）

```nginx
server {
    listen 80;
    server_name pansou.example.com;
    root /srv/pansou/web/public;
    index index.php;

    # 动态 robots 与 sitemap（public 下没有这两个实体文件）
    location = /robots.txt            { rewrite ^ /robots.php last; }
    location = /sitemap.xml           { rewrite ^ /sitemap.php?kind=index last; }
    location = /sitemap-core.xml      { rewrite ^ /sitemap.php?kind=core last; }
    location ~ ^/sitemap-res-(\d+)\.xml$ { rewrite ^ /sitemap.php?kind=res&n=$1 last; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    # 数据库、配置、缓存一律不可下载
    location ~* ^/(storage|src)/ { deny all; }
    location ~* \.(sqlite|sqlite-wal|sqlite-shm)$ { deny all; }

    gzip on;
    gzip_types text/html text/css application/javascript application/xml application/json;
    gzip_min_length 1024;
}
```

Apache 用户用 `public/.htaccess`（已写好同样的规则，需要 `mod_rewrite`）。

目录权限：`storage/` 需要 web 用户可写（SQLite + 限流文件）；`storage/app.sqlite` 建议 `chmod 600`——里面有网盘账号 Cookie。

定时任务：

```cron
*/30 * * * *  php /srv/pansou/web/bin/sync.php hot
5    4 * * *  php /srv/pansou/web/bin/sync.php all
```

`all` 会遍历 index.json 里的全部分片，增量导入（幂等 upsert），
首次约 15 分钟、之后每次只要拉有变化的片刻。

## 界面（照搬 urldb 的视觉语言）

前台结构对齐 [ctwj/urldb](https://github.com/ctwj/urldb)：深色圆角页头 + 圆形搜索框 + 平台/类型胶囊 + 深色表头的资源表，
桌面端用表格、移动端自动切成卡片流。差异是**没有文件缩略图**（我们只存链接），改用「首字 + 平台品牌色」的封面块。

配色：`slate-800` 深色块 + `blue-600` 主色 + `gray-50` 背景，只有一套亮色主题（不做暗色模式，避免每个模板都要维护两套类名）。

| 页面 | 文件 |
|---|---|
| 列表（首页/搜索共用） | `src/views/list.php` |
| 详情 | `src/views/detail.php` |
| 投稿 | `src/views/submit.php` |
| 外壳（页头/公告/页脚） | `src/views/layout.php` |
| 视图辅助（高亮/封面/胶囊/分页） | `src/ui.php` |

## 样式构建（Tailwind CSS 4）

界面用 Tailwind 工具类编写，产物已提交（`public/assets/app.css`，约 48KB），**部署不需要 Node**。

改样式才需要：

```bash
npm install          # 只装 devDependencies（tailwindcss + CLI）
npm run build        # 一次性编译 + 压缩
npm run dev          # 监听改动自动重编译
```

源文件 `src/styles.css`：`@layer components` 里定义 `.site-header/.pill/.res-table/.cover/.admin-nav-link` 等复用类。

**一个坑记在这里**：Tailwind 4 只在某个 theme 变量被工具类用到时才注入 `:root`。
如果某色只出现在 `@apply` 里（比如只在 `.admin-nav-link` 用了 `text-slate-300`），变量不会生成，
编译结果引用一个不存在的 `var(--color-slate-300)` → 颜色继承成黑色（深色侧栏上就是"看不见字"）。
所以深色区域的文字颜色在 `src/styles.css` 末尾用显式色值兜底。

## 目录结构

```
web/
├── public/          # 唯一对外目录（index.php / admin.php / assets / router.php）
├── src/             # bootstrap(配置+DB+工具) repo(搜索/导入) crawler(采集) moderation auth schema
├── bin/             # init 建库、sync 同步、crawl 采集
└── storage/         # app.sqlite + 限流文件 + 日志（gitignore）
```

## 已知边界

- FTS 用 trigram：1-2 个字的搜索会退化成 `LIKE`（慢一点，但能搜）
- `unchecked` 状态不等于坏链，只是还没巡检；前端默认都展示，详情页会标状态
- 投稿只做关键词风控，**人工复审仍然必要**，尤其影视类
