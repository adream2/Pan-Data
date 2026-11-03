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
| 设置 | **5 类共 25 项配置**，保存即时生效（见下） |

### 设置项（全部后台可改，不需要动文件）

| 分组 | 配置 |
|---|---|
| 外观 | 站点名称、副标题、站内公告 |
| 投稿 | 开放投稿、投稿需登录、每小时限流、重复链接跳过 |
| 审核 | 审核模式（全部人工 / 低风险自动通过 / 免审）、低风险免审类型白名单 |
| 搜索与展示 | 每页条数、热门搜索条数、是否展示失效链接、是否展示未巡检链接 |
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

## 封面：统一类型色块（不做影视海报）

**结论先说：海报刮削已下线。** 不是技术上做不到，是做不到「够好」。

试过的三条路：

| 候选 | 结果 |
|---|---|
| 豆瓣 | 搜索接口可用且精准（猜火车→1996），但图片服务端取不到（img1/2/3/9 × 两种尺寸 8 个组合全失败，带 referer 也 403） |
| 夸克分享页 | `sharepage/detail` 不返回任何 thumbnail 字段，网盘不给首帧图 |
| TMDB | 能用，但要 Key，且**实测命中率只有 20-30%**——库存里大量是短剧/AI 短剧（`sss级校花找我当老师`），TMDB 没有条目 |

列表里一半有海报、一半是灰块，比整列统一色块**更难用**。所以撤掉，前端回到 100% 统一的色块封面。

### 现在的做法：把占位做成设计

不是「没有图」，是按内容类型分配色相，让列表本身携带信息：

| 类型 | 色系 |
|---|---|
| 影视 | 蓝 |
| 课程 | 青 |
| 电子书 | 橙 |
| 软件 | 紫 |
| 音乐 | 玫红 |
| 其它 | 石板灰 |

色块取「标题归一化后的首字」（自动跳过 `#【[「` 等符号），深色渐变 + 内描边 + 文字阴影，看起来是一张做过的占位图，而不是加载失败的方块。

### 想恢复海报怎么办

`src/poster.php` 和 `bin/test_poster_match.php` 都保留着（匹配逻辑 8 个用例仍可通过）。
文件头注释写了恢复需要改的三处，参照 commit `1a0e529` 即可。

## 数据来源（三条腿，可同时开）

| 方式 | 入口 | 说明 |
|---|---|---|
| 仓库导入 **（主）** | 后台「同步与采集」/ `bin/sync.php` | 走 GitHub Contents API 拉 `resources.json` 和 `shards/*.json`，幂等 upsert |
| 本地采集 | 后台「本地采集」/ `bin/crawl.php` | 直接抓 `t.me/s/<频道>` 网页预览，兜底和自建频道用 |
| 触发云端 | 后台「触发云端跑一轮」 | 配了 GitHub token 就能一键 dispatch `collect/backfill/mtproto` |

> 为什么不用 `raw.githubusercontent.com`：部分网络下 TLS 握手会被掐断。Contents API 稳定，代价是每小时 60 次限额（填了 `repo.token` 提到 5000）。

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

## 生产部署（Nginx + PHP-FPM）

```nginx
server {
    listen 80;
    server_name pansou.example.com;
    root /srv/pansou/web/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~* ^/(storage|src)/ { deny all; }
}
```

目录权限：`storage/` 需要 web 用户可写（SQLite + 限流文件）。

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
