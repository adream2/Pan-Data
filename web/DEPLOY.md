# 部署说明

从零到能访问，大约 10 分钟。第 3 步之后就不需要命令行了。

> 这个包只有站点本体（`public/` `src/` `bin/`），不含数据库和采集代码。
> 数据由服务器定时从 GitHub 的 `data` 分支拉取，采集本身在 GitHub Actions 里跑。

---

## 0. 环境要求

| 项 | 要求 |
|---|---|
| PHP | **8.1 及以上**（推荐 8.2/8.3，实测 8.5 正常） |
| 扩展 | `pdo_sqlite` `sqlite3` `curl` `mbstring` `json` |
| SQLite | 必须带 **FTS5**（全文检索，用于搜索） |
| Web 服务器 | Nginx 或 Apache（`public/.htaccess` 已备好） |
| 磁盘 | 30MB 程序 + 数据库（27k 条约 90MB，会随数据增长） |

先跑这三条确认环境：

```bash
php -v
php -m | grep -E 'pdo_sqlite|sqlite3|curl|mbstring'
php -r '$p=new PDO("sqlite::memory:"); $p->exec("CREATE VIRTUAL TABLE t USING fts5(x)"); echo "FTS5 OK\n";'
```

第三条如果报错（`no such module: fts5`），说明 PHP 的 SQLite 没编译 FTS5。
Debian/Ubuntu 装 `php-sqlite3` 一般自带；自编译的 PHP 需要 `--with-sqlite3` 且 SQLite ≥ 3.9。
**没有 FTS5 也能跑**，搜索会自动降级成 `LIKE` 模糊匹配，只是大库下慢一些。

---

## 1. 上传与权限

```bash
# 上传 pansou-web.zip 到服务器后
cd /srv && unzip pansou-web.zip && mv pansou-web pansou
cd /srv/pansou

# 目录属主给 web 用户（按发行版改，Debian/Ubuntu 是 www-data）
chown -R www-data:www-data /srv/pansou
chmod -R 755 /srv/pansou
chmod -R 775 /srv/pansou/storage
```

`storage/` 必须可写：这里放 SQLite 数据库、限流文件、同步状态。
Nginx 的 `root` 指向 **`public/`**，不是包根目录 —— `src/` 和 `storage/` 绝不能被外部访问。

---

## 2. Web 服务器

### 两件必须做的事

**① 站点根目录要指向 `public/`，不是包根目录。**

包根下除了 `public/`，还有 `src/`（配置、可能带 Token）和 `storage/`（SQLite 数据库）。
根目录指错的话，这两样都能被直接在浏览器里下载 —— 数据库里有管理员密码 hash
和网盘 Cookie，等于把后台送人。

自检：浏览器打开下面两个地址，**都应该返回 403 / 404**，如果下载下来文件就是配错了：

```
https://你的域名/storage/app.sqlite
https://你的域名/src/config.php
```

**② 必须配伪静态（重写规则）。**

`/movie`、`/s/xxx`、`/c/频道名`、`/updates` 都是「虚拟路径」，磁盘上没有对应文件。
不配重写，只有首页 `/` 能打开（它默认落到 `index.php`），点任何分类或详情都是 404。

### Nginx

```nginx
server {
    listen 80;
    server_name pansou.example.com;
    root /srv/pansou/public;
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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # 按实际 PHP 版本改
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;                      # 后台同步可能要几分钟
    }

    # 数据库、配置、缓存一律不可下载
    location ~* ^/(storage|src)/ { deny all; }
    location ~* \.(sqlite|sqlite-wal|sqlite-shm)$ { deny all; }

    gzip on;
    gzip_types text/html text/css application/javascript application/xml application/json;
    gzip_min_length 1024;
}
```

改完 `nginx -t && systemctl reload nginx`。

### Apache

包内已带 `public/.htaccess`。确认 `AllowOverride All` 且启用了 `mod_rewrite`：

```apache
<Directory /srv/pansou/public>
    AllowOverride All
    Require all granted
</Directory>
```

再把 DocumentRoot 指到 `/srv/pansou/public`。

**宝塔 / aaPanel**：站点设置 → 网站目录 → **运行目录** 选 `public`（等价于改 root），
再到「伪静态」里把上面的 `location` 段贴进去。只改运行目录不配伪静态，分类页照样 404。

### 如果面板强制要求根目录

有些面板不允许把运行目录设成子目录。那就保持 root 为包根，但要在 location 级覆盖，
并且屏蔽敏感目录做双保险：

```nginx
server {
    listen 80;
    server_name pansou.example.com;
    root /srv/pansou;            # 面板给的是包根

    # 下面每个 location 都把根指回 public
    location / {
        root /srv/pansou/public;
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /robots.txt          { rewrite ^ /robots.php last; }
    location = /sitemap.xml         { rewrite ^ /sitemap.php?kind=index last; }
    location = /sitemap-core.xml    { rewrite ^ /sitemap.php?kind=core last; }
    location ~ ^/sitemap-res-([0-9]+)\.xml$ { rewrite ^ /sitemap.php?kind=res&n=$1 last; }

    location ~ \.php$ {
        root /srv/pansou/public;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    # 双保险：上面万一写漏，这里也不让下载
    location ~ ^/(src|storage|bin)/ { deny all; }
    location ~ \.(sqlite|sqlite-wal|sqlite-shm)$ { deny all; }
}
```

改完务必用前面那两个 URL 复测一遍。

---

## 3. 浏览器安装（推荐）

打开 **`https://你的域名/install.php`**，三步点完，全程不需要 SSH。

**第一步 · 环境检查**：自动检测 PHP 版本、扩展、`storage` 可写、FTS5、样式文件。
红的必须解决，黄的可以继续（比如没有 FTS5 只是搜索降级，站点照样能跑）。

**第二步 · 站点信息**：站点名称、完整地址、管理员账号密码，
外加一个折叠的「数据拉取设置」：

| 项 | 什么时候填 |
|---|---|
| 拉取代理 | 服务器连不上 `api.github.com` 时填，如 `http://127.0.0.1:7890` |
| GitHub Token | 建议填。匿名每小时 60 次请求，带 Token 是 5000 次 |

**第三步 · 导入数据**：点「开始导入」，页面自动分批拉取（1 个热文件 + 29 个分片），
进度条 + 日志实时显示每批新增/更新条数。中途关掉页面也没关系，重新打开可以继续，已导入的不会重复。

首步是下载 6MB 的最近 90 天热数据，**这一段取决于你服务器到 GitHub 的带宽**
（实测慢的线路约 30KB/s，要 3 分钟）；后面的分片都是几 MB 以内，很快。
全部完成会显示「已建立搜索索引」并给出「进入后台」按钮。

装完可以直接访问首页搜索。建议之后**删除或改名 `public/install.php`**
（已安装状态下它只会提示「已安装」，但少一个入口更保险）。

### 用命令行安装（没有 web 权限时）

```bash
cd /srv/pansou
sudo -u www-data php bin/init.php admin '你的密码'    # 建表 + 管理员
sudo -u www-data php bin/sync.php all                 # 全量导入
```

密码忘了或想改：

```bash
sudo -u www-data php bin/reset-password.php admin '新密码'
```

这个脚本是幂等的：账号不存在就新建，存在就改密码。

---

## 4. 定时同步

```cron
# 采集在 GitHub 上每 30 分钟推送一次，这里错开 8 分钟
15,45 * * * * cd /srv/pansou && php bin/sync.php auto >> /var/log/pansou-sync.log 2>&1
```

`auto` 是增量同步：先读 `index.json` 比对，只下载变化的热文件与分片，
内容没变的条目不重写数据库。**数据没更新时只发 1 次请求、1.5 秒就退出**。

建议用 web 用户跑，避免之后数据库文件属主混乱：

```cron
15,45 * * * * cd /srv/pansou && sudo -u www-data php bin/sync.php auto >> /var/log/pansou-sync.log 2>&1
```

确认它在工作：

```bash
cat /srv/pansou/storage/sync_state.json     # 看 last_run 有没有在动
tail -5 /var/log/pansou-sync.log
```

---

## 5. 后台设置

访问 `https://你的域名/admin.php` 登录（前台导航里没有后台入口，只能直接敲地址）。

必改两项：

| 位置 | 项 | 说明 |
|---|---|---|
| 设置 → 外观 | **站点完整地址** | `https://你的域名`（带协议、不带结尾斜杠）。留空会按请求推断，有 CDN/反代时 sitemap 里可能出现内网地址 |
| 设置 → 外观 | 站点名称 / 副标题 | 页头与标题栏 |

改完访问 `/sitemap.xml` 确认里面的域名正确，再提交到 Google Search Console。

其余配置（审核模式、投稿开关、同义词、举报阈值、拉新）按需调，全部即时生效。

---

## 6. 自检清单

部署完逐条过一遍：

```bash
# 首页 200，且能看到资源
curl -sI https://你的域名/ | head -1

# 搜索有结果（换成你库里有的词）
curl -s "https://你的域名/search?q=电影" | grep -o '共 <span[^>]*>[0-9,]*' | head -1

# 详情页 200
curl -sI https://你的域名/s/任意存在的id | head -1

# 未知路径必须 404（软 404 会被搜索引擎惩罚）
curl -s -o /dev/null -w '%{http_code}\n' https://你的域名/一个不存在的路径

# robots / sitemap 正常
curl -s https://你的域名/robots.txt
curl -s https://你的域名/sitemap.xml | head -3

# 源码目录不可下载（应返回 403/404）
curl -s -o /dev/null -w '%{http_code}\n' https://你的域名/../src/config.php
```

后台再确认：概览有数据、投稿审核能打开、设置能保存。

---

## 常见问题

**页面 500，日志里是 `unable to open database file`**
`storage/` 不可写或属主不对。`chown -R www-data:www-data storage && chmod -R 775 storage`。

**搜索很慢 / 没有结果**
先确认 FTS5 是否可用（见第 0 步）。不可用时搜索降级为 `LIKE`，2 万条以上会明显变慢。

**安装向导第二步提交后没反应**
密码至少要 8 位；站点地址要带 `http://` 或 `https://`。这两个校验没过会带着错误提示留在原页。

**安装向导导入卡在第一步很久**
那一步在下载 6MB 热数据，纯粹是带宽问题，不是卡死。页面会一直显示「正在下载…」，
等它返回即可。如果超过 5 分钟，多半是代理不通 —— 回后台改「数据源」里的代理/Token 后重试。

**导入到一半断了**
重新打开 `/install.php?step=import` 点「开始导入」会从第 1 步重来，
已导入的条目会被识别为「未变」跳过，不会产生重复数据。

**后台「同步」点了没反应 / 超时**
首次全量请在命令行跑 `bin/sync.php all`，不要在后台点（web 请求有超时限制）。
后台按钮只做热文件 + 最多 2 个分片。

**同步日志里 `拉取 index.json 失败`**
到 GitHub 的网络不通。配代理或 Token（第 4 步）。

**改了 `site_url` 但 sitemap 还是 localhost**
设置 → 外观里有值时会优先用设置值；确认没保存成功时看是否被 `config.local.php` 的 `base_url` 覆盖。

**想换域名 / 迁移服务器**
整个 `storage/` 目录拷走即可（数据库 + 同步状态都在里面）。迁移后跑一次
`bin/reset-password.php` 确认账号可登录。

**数据库备份**

```bash
# WAL 模式下不要直接 cp 正在写的库，用 sqlite3 的备份命令
sqlite3 /srv/pansou/storage/app.sqlite ".backup /backup/pansou-$(date +%F).sqlite"
```

放进 cron 每天跑一次，比任何其他备份方式都安全（不需要停站）。
