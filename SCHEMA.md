# 数据契约 · resources.json

对外唯一入口。内部 `data/links.jsonl`（含冗余来源、巡检明细）不对外承诺。

| 项 | 值 |
|---|---|
| 入口 | `data` 分支：`data/index.json` → `data/resources.json`（热） / `data/shards/*.json`（库存） |
| 当前版本 | `1.2` |
| 生成方 | `tools/export.py`（每次采集/巡检后自动重跑，默认 90 天窗口） |
| 编码 | UTF-8，紧凑 JSON（`--pretty` 可缩进） |

## 文件结构

| 文件 | 内容 | 典型体积 |
|---|---|---|
| `data/index.json` | 清单：热文件 + 各分片的时间范围与条数 | 几 KB |
| `data/resources.json` | **热文件**，最近 90 天的当前版本资源 | 1 MB 起 |
| `data/shards/2026-Q4.json` | **库存分片**，按季度切，单片不会无限长 | 数 MB |

调用方先读 `index.json`（很小），需要历史时再按 `shards[].path` 拉对应季度。
分片文件与热文件**结构完全一致**，同一套解析代码即可，只多了 `period` 字段。

分片粒度可切换：`--shard month` 会按月切（单片更小，约 1/3）。

## 顶层

| 字段 | 类型 | 说明 |
|---|---|---|
| `schema_version` | string | 契约版本，**字段增删改必升** |
| `generated_at` | string | UTC ISO8601 |
| `counts` | object | `resources / links / links_in_window / window_days / channels / valid / invalid / unchecked` |
| `resources` | array | 资源列表，按 `posted_at` 倒序 |

## resources[]

字段顺序即契约顺序。

| 字段 | 类型 | 可空 | 说明 |
|---|---|---|---|
| `id` | string | 否 | 资源指纹（core_title+season 的 sha1 前 12 位），同作品不同集数共用 |
| `title` | string | 否 | 展示标题，优先网盘官方标题；占位标题会退回 `core_title` |
| `core_title` | string | 否 | 归一化标题（已剥 `更至X集`／`4K`／`【国漫】` 等） |
| `type` | string | 否 | `movie` / `music` / `ebook` / `software` / `course` / `other` |
| `year` | int | 是 | 原始标题里的年份，**不可靠**（常是搬运年份），仅作展示/弱筛选 |
| `season` | int | 是 | 第几季，如 `2` |
| `episode` | int | 是 | 已更新到第几集（取一组内的最大值） |
| `complete` | bool | 否 | 是否已完结 |
| `provider` | string | 否 | `quark` / `baidu` / `aliyun` / `xunlei` / `uc` / `123pan` / `189` / `139` / `115` / `lanzou` / `onedrive` / `mega` / `gdrive` |
| `share_url` | string | 否 | 分享链接（已清洗，不含跟踪参数；`pwd` 可能内嵌为 `?pwd=`） |
| `pwd` | string | 是 | 提取码，无则 `null` |
| `status` | string | 否 | `valid` / `invalid` / `unchecked` / `uncertain` / `requires_code` / `rate_limited` |
| `checked_at` | string | 是 | 上次巡检时间，UTC ISO8601 |
| `sources` | int | 否 | 该资源被多少个频道/链接转发过 |
| `mirrors` | array | 否 | 备用链接（同资源其它来源），元素见下 |
| `channel` | string | 否 | 当前版本来源频道 |
| `post_url` | string | 否 | 原帖地址 |
| `posted_at` | string | 是 | 原帖发布时间，UTC ISO8601 |
| `desc` | string | 否 | 描述（≤100 字，控制体积） |

### mirrors[]

| 字段 | 类型 | 说明 |
|---|---|---|
| `provider` | string | 同 `provider` |
| `share_url` | string | 备用分享链接 |
| `pwd` | string 或 null | 提取码 |
| `status` | string | 该备用链接的巡检状态 |

## 调用语义

- **只展示 `status != "invalid"` 的资源**。`unchecked` 表示还没巡检，不等于坏链。
- 一个 `id` 只出现一次，同作品的旧集数已被收敛进 `episode` / `mirrors`。
- `mirrors` 非空时建议给用户「换一个盘」入口：夸克失效换百度。
- 排序直接用数组顺序（`posted_at` 倒序），不要再二次排序。
- **热文件只含最近 90 天**（`counts.window_days`）；更早的数据在分片里，不会丢。
- 资源只会出现在一个分片里（按「当前版本」的发布时间归属），跨分片不重复。

## 示例

```bash
curl -s https://raw.githubusercontent.com/adream2/Pan-Data/data/data/resources.json | jq '.counts'
```

```js
const data = await (await fetch('data/resources.json')).json();
const list = data.resources.filter(r => r.status !== 'invalid');
```
