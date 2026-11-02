# PANSOU · TG 公开频道网盘链接采集

用 GitHub Actions 定时抓取 Telegram 公开频道里的网盘分享链接（夸克/百度/阿里/迅雷/UC/123/天翼/移动…），
解析出分享链接 + 提取码，去重后落到 `data/links.jsonl`，供搜索站或 Meilisearch 消费。

## 快速开始

1. 编辑 `collector/channels.txt`，每行一个公开频道（`@name` 或 `t.me/name` 都行）。
2. 推到 GitHub 仓库，打开 Actions → `collect` → Run workflow，`pages` 填 `20` 先建库。
3. 之后每 30 分钟自动采集（`collect`），每天自动巡检（`check`），跑完都会重新导出契约并提交。

> 云端如果直连不了 `t.me`，在仓库 Secrets 里加 `TG_PROXY=http://user:pass@host:port`，两个 workflow 都会用。

## 对外契约

**只认 `data` 分支的 `index.json`**，格式见 [`SCHEMA.md`](SCHEMA.md)（v1.2）。

```
https://raw.githubusercontent.com/adream2/Pan-Data/data/data/index.json      # 清单（几 KB）
https://raw.githubusercontent.com/adream2/Pan-Data/data/data/resources.json  # 热数据（最近 90 天）
https://raw.githubusercontent.com/adream2/Pan-Data/data/data/shards/2026-Q4.json  # 库存分片
```

```bash
python tools/export.py                 # 默认 90 天窗口
python tools/export.py --days 0        # 全量（本地排查用，别发布）
```

- 热文件：最近 90 天的当前版本资源；历史按季度切进 `data/shards/`，单个文件不会无限长
- 体积可控：desc 截 100 字，超出窗口的不导出，稳态约 15MB 以内
- 字段顺序固定，带 `schema_version`，改字段必须升版本
- `mirrors` 给出同资源的备用盘链接（夸克挂了换百度）
- 内部 `data/links.jsonl` 是工作数据，随时会变，别对外承诺

## 自动化

| workflow | 频率 | 干什么 |
|---|---|---|
| `collect.yml` | 每 30 分钟（:07/:37） | 抓新消息 → 归一/分组 → 导出 → 发布 `data` 分支 |
| `check.yml` | 每天 03:23 UTC | 巡检 7 天前查过的链接 → 重算当前版本 → 导出 → 发布 `data` 分支 |
| `compact.yml` | 每年 1 月 1 日 | 年度体检（只读）+ 可选清理失效记录 → 重新分片 |

两个 job 共用 `concurrency: pan-data`，不会互相踩。首次建库用 `workflow_dispatch` 把 `pages` 填 20。

## 体积策略

三个层次的取舍：

| 机制 | 作用 |
|---|---|
| 热文件 90 天 | 首屏只拉最近数据，1 MB 级 |
| 季度分片 | 库存全留，单片封顶（`--shard month` 可再小到 1/3） |
| 年度 compact | 体检 + 按需清理死链（默认不删，库存是资产） |
| data 分支滚动提交 | git 历史不累积，仓库体积 = 最新文件 |

**数据为什么在独立分支**：数据是派生产物，每次全量重写。若跟着代码提交，每天 20 次 × 整文件 = 每月上百 MB 的 git 历史。
`data` 分支每次用 `git push -f` 覆盖成**单条提交**，主仓永远干净，仓库体积只等于最新文件。

本地跑（纯标准库，零依赖）：

```bash
python collector/fetch_tg.py --pages 3
# 国内直连不通就挂代理
python collector/fetch_tg.py --pages 20 --proxy http://127.0.0.1:7890
TG_PROXY=auto python collector/fetch_tg.py --pages 1   # 自动探测本机常见代理端口
```

## PHP 搜索站（web/）

采集仓库负责产数据，`web/` 是完整的 PHP 8.4 + SQLite 搜索站：前台搜索/投稿、后台审核/同步/采集。

```bash
cd web && php bin/init.php admin '密码' && php bin/sync.php hot
php -S 127.0.0.1:8000 -t public public/router.php
```

详见 [web/README.md](web/README.md)。

## 扩源（发现 → 探针 → 入库）

一条命令跑完「挖互推 → 探产出 → 写清单」，每周三自动执行（`expand.yml`）：

```bash
python tools/auto_expand.py --pages 3 --min-links 8    # 达标就自动写进 channels.txt
python tools/auto_expand.py --dry-run                   # 只看结果
```

判据：探针抓 1 页，能出 ≥8 条网盘链接就收。重复跑是安全的（已有频道跳过），所以是滚雪球。
手动拆开跑也可以：`discover_channels.py` → `probe_channels.py` → 手工编辑清单。

2026-11 实测：16 个种子频道挖出 12 个候选，8 个有产出（合计单页 207 条链接），
`aliyun_4k_movies`、`netdisk_movies` 这类还带来了阿里云盘的新来源。

## 频道探针

候选频道先探再采，别把配额浪费在「看着像资源号、其实全是广告」的地方：

```bash
python tools/probe_channels.py --proxy http://127.0.0.1:7890
```

每个频道只抓 1 页（20 条），输出消息数、有链率、链接数、平台分布、最新发布时间，
写 `data/channel_probe.json`。2026-11 实测 18 个候选，17 个活着，单页产出 430 条。

## 跨频道合并

同一部剧/课被 10 个频道转发会有 10 个不同分享链接，光靠 URL 去重挡不住。
`collector/title_norm.py` 负责把标题剥成 `core_title`，并抽出 `year / season / episode / complete`：

```
风与潮 (2025) [WEB-4K] [国语中字] [更至23集]  ┐
风与潮（2025）【4K】更新至 25 集              ├─ core_title=风与潮, year=2025, episode=25, group_key 相同
【国漫】风与潮 2025 4K 更26集                ┘
```

`group_key = sha1(core_title|season)`，同作品不同集数/画质/字幕版本会落到同一组。
年份刻意不进指纹——频道写 `(2025)` 往往是搬运年份而非出品年份，真会把同一部剧拆成两组。

## 追更收敛

`collector/grouping.py` 在每组里选一条「当前版本」，其余降级，前端只展示 current：

| 角色 | 含义 |
|---|---|
| `current` | 当前版本，每组唯一 |
| `duplicate` | 同集数的另一个来源 |
| `superseded` | 集数更少的旧版本，带 `superseded_by` 指向当前版本 |

优先级：**链接有效 > 未巡检 > 失效** → 集数大 → 完结 → 发布时间新。
所以「更至26集」会盖掉「25集」，但「26集已失效」时会把「25集有效」顶上来，不会让用户点死链。

当前 894 条链接 → **698 个资源**：重复 179、旧版本 17；最大一组「走马上任」14 个来源、跨 6 个频道。
采集和巡检跑完都会自动重算（巡检改了链接状态，当前版本可能换人）。

## MTProto 冲量（推荐开）

网页预览有两个绕不过的硬伤：频道关了预览就完全抓不到（实测 `naclzy`、`yespojie` 等一批号返回 11KB 空页），
历史深度也有限。MTProto 用账号直连，这两条都绕开，而且能秒级追新。

**开通步骤（一次性，5 分钟）**

1. 打开 https://my.telegram.org/apps 建一个应用，拿 `api_id` / `api_hash`
2. 本地跑 `pip install telethon python-socks` 然后 `python tools/tg_login.py`
   （交互式输手机号 + 验证码，成功后写 `.env.mtproto`，已 gitignore）
3. 仓库 Settings → Secrets 加三个：`TG_API_ID`、`TG_API_HASH`、`TG_SESSION`
4. Actions → `mtproto` → Run workflow，`limit` 填 `500` 做一次历史回填；之后每 15 分钟自动追新

**注意**

- `TG_SESSION` 等同于账号登录凭证，泄露等于账号被接管。**别用主号**，建议小号。
- 频道清单在 `collector/channels.txt`，网页抓不到的号另放 `collector/mtproto_channels.txt`。
- 解析失败/限流不会中断：失败频道会列在日志末尾，`FloodWait` 会按 Telegram 要求等待。
- 增量靠 `data/mtproto_state.json` 记录每个频道的水位（最大消息 ID），不会重复入库。

## 拉新（夸克）

网盘拉新的唯一正确姿势：**先把资源转存到自己账号，再用自己的分享发出去**。
别人给的链接你控制不了——随时被删、随时失效、也带不上你的渠道参数。

```bash
python tools/promo.py scan --limit 50 --skip-blocked   # 选品 + 风控打分
python tools/promo.py list                             # 看候选（! 表示中高风险）
python tools/promo.py approve <id> [--force]           # 人工审批
python tools/promo.py run --live                       # 转存 + 再分享（不加 --live 是演练）
python tools/promo.py dashboard                        # 生成 data/promo.html 管控台
```

**四道闸，缺一不可**

| 闸门 | 作用 |
|---|---|
| 类型白名单 | 默认只放课程/电子书/软件/音乐；**影视默认拦**（版权风险最高，最容易封号） |
| 违禁词库 | 命中即 `blocked`，人工也批不了（要放行只能改 `promo_rules.json`） |
| 提醒词 | 破解/激活/内部资料这类标 `medium`，必须 `--force` 明确确认 |
| 日限额 | 默认每天 30 条，防止账号被判定为机器行为 |

状态机：`candidate → approved → transferring → transferred → sharing → live`（失败落 `failed`，全记录在 `data/promo.json`）。

**需要配置**

- `QUARK_COOKIE`：浏览器登录夸克后复制整条 Cookie，存到仓库 Secrets。**等同账号凭证**，别外传。
- `promo_rules.json` 里的 `affiliate`：夸克的佣金**不是靠分享链接本身**计，要走渠道参数。
  把渠道后台给你的落地页/参数填进去，`enabled=true` 后管控台会自动生成带参数的分发链接。

## 链接巡检

```bash
python collector/check_links.py --proxy http://127.0.0.1:7890            # 全量
python collector/check_links.py --provider quark --limit 50              # 抽样
python collector/check_links.py --recheck-days 7                         # 只查 7 天前查过的
```

判定分级（参考 tgmonitor）：`valid / invalid / requires_code / rate_limited / uncertain / unsupported`，
网络抖动和限流一律不算死链。夸克走官方 `sharepage/token` 接口，能拿到**真实的分享标题、文件数、到期时间**；
其余平台退化为 HTTP 状态探测（弱证据，只对 404/410 判死）。

巡检结果回写 `links.jsonl`：`status / reason / share_title / expires_at / checked_at`，
并按「官方标题优先」写 `title_best`——频道文案常常把作者、机构名删掉。

## 三种采集路线

| 路线 | 能拿到什么 | 成本 | 适合 |
|---|---|---|---|
| `t.me/s/<频道>` 网页预览 | 公开频道最近约 20 条/页，可翻页回溯 | 零成本、零账号 | 主力方案，Actions 直接跑 |
| MTProto（telethon + session） | 全量历史、私有/受限频道、更快 | 需一个 TG 账号 + 2FA，session 存 Secret | 兜底 / 大批量回填 |
| Bot API | 只有「机器人是管理员」的频道 | 零成本 | 自己建频道时用 |

> Bot API 读不了别人的公开频道历史，别在这上面浪费时间。

## MTProto 兜底（可选）

```python
# pip install telethon
import os, asyncio
from telethon import TelegramClient
from telethon.sessions import StringSession

async def pull(channel: str, limit: int = 200):
    async with TelegramClient(StringSession(os.environ["TG_SESSION"]), 12345, "hash") as c:
        async for msg in c.iter_messages(channel, limit=limit):
            if msg.message:
                yield msg.message
```

session 生成一次后存到仓库 Secret `TG_SESSION`（等价于账号凭证，别提交进仓库）。
注意：Actions 的 Azure IP 段偶发被 TG 风控，加 `--sleep 3` 或挂代理更稳。

## 存储演进

- **起步**：`data/links.jsonl` 直接 commit（简单，但每 30 分钟一次提交会让仓库膨胀）。
- **进阶**：写到独立 `data` 分支，或推到 Cloudflare D1 / R2 / 自己的 VPS 接口，仓库只留代码。
- **搜索**：Meilisearch（中文分词好、开箱即用）或 SQLite FTS5（零依赖，适合静态站）。

## 注意

- 只采公开频道；被频道屏蔽、设了「禁止转发/预览」的抓不到。
- 采集结果里可能混入侵权或违规内容，上线前建议加关键词黑名单 + 人工抽检，并对 DMCA/投诉留通道。
- 抓取频率别太激进，`429` 会直接封 IP 一段时间（脚本已带退避重试）。
