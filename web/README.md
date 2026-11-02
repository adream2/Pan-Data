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

## 后台（对齐 urldb 的能力面）

| 模块 | 能力 |
|---|---|
| 概览 | 今日入库（含昨日环比）、今日搜索、今日点击、待处理举报、按类型/平台分布、近 7 天热搜与热门资源 |
| 投稿审核 | 风险分级（low/medium/high/blocked），通过即入库 |
| 资源管理 | 关键词搜索、下架 |
| 同步与采集 | 热文件同步、历史分片逐个导入、本地 t.me 采集、一键触发云端 workflow |
| 用户管理 | 列表、封禁/解封、发 API Key、重置密码 |
| 网盘账号 | 多账号 CRUD（夸克/百度/阿里/迅雷/UC）、启用停用，供自动转存用 |
| 举报处理 | 待处理队列，标「失效」会自动把资源置为 invalid |
| 批量录入 | 粘贴多行「标题 链接」，自动识别平台/提取码、跳过重复 |
| 设置 | 站名/副标题/公告/开放投稿/改密 |

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

## 样式构建（Tailwind CSS 4）

界面用 Tailwind 工具类编写，产物已提交（`public/assets/app.css`，约 31KB），**部署不需要 Node**。

改样式才需要：

```bash
npm install          # 只装 devDependencies（tailwindcss + CLI）
npm run build        # 一次性编译 + 压缩
npm run dev          # 监听改动自动重编译
```

源文件：`src/styles.css`（`@theme` 定义品牌色，`@layer components` 定义 `.card/.btn/.chip/.input` 等复用类）。
新增页面时 Tailwind 会自动扫描 `public/**/*.php` 和 `src/views/**/*.php`，不用手动维护 safelist。

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
