# 部署说明

从零到能访问，大约 10 分钟。按顺序做完这 6 步即可。

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

---

## 3. 初始化

```bash
cd /srv/pansou
sudo -u www-data php bin/init.php admin '你的密码'
```

输出会告诉你数据库建在哪、管理员是否创建成功。

**这一步做了什么**：建表（resources / submissions / reports / logs / settings / admins，
以及 FTS5 索引）、写入默认设置、创建第一个管理员。

密码忘了或想改：

```bash
sudo -u www-data php bin/reset-password.php admin '新密码'
```

这个脚本是幂等的：账号不存在就新建，存在就改密码。

---

## 4. 导入数据

```bash
cd /srv/pansou
sudo -u www-data php bin/sync.php index    # 先测能不能连通数据仓库
sudo -u www-data php bin/sync.php all      # 首次全量（29 个分片 + 热文件）
```

首次约 **5~15 分钟**（取决于到 GitHub 的速度），会导入 2.7 万条资源。

**服务器在国内、拉不动 GitHub 怎么办**：登录后台 → 设置 → 数据源，填：

- **拉取代理**：`http://127.0.0.1:7890`（或你本机的代理端口）
- **拉取 Token**：GitHub Personal Access Token，只需 `public_repo` 读权限。
  匿名每小时 60 次请求，带 token 是 5000 次 —— 首次全量要 30 次请求，配了更稳。

也可以先改 `src/config.local.php`（把 `config.php` 复制一份改值），效果一样。

---

## 5. 定时同步

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

## 6. 后台设置

访问 `https://你的域名/admin.php` 登录（前台导航里没有后台入口，只能直接敲地址）。

必改两项：

| 位置 | 项 | 说明 |
|---|---|---|
| 设置 → 外观 | **站点完整地址** | `https://你的域名`（带协议、不带结尾斜杠）。留空会按请求推断，有 CDN/反代时 sitemap 里可能出现内网地址 |
| 设置 → 外观 | 站点名称 / 副标题 | 页头与标题栏 |

改完访问 `/sitemap.xml` 确认里面的域名正确，再提交到 Google Search Console。

其余配置（审核模式、投稿开关、同义词、举报阈值、拉新）按需调，全部即时生效。

---

## 7. 自检清单

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
