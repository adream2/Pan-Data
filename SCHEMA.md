# 数据契约 · resources.json

对外唯一入口。内部 `data/links.jsonl`（含冗余来源、巡检明细）不对外承诺。

| 项 | 值 |
|---|---|
| 路径 | `data/resources.json` |
| 当前版本 | `1.0` |
| 生成方 | `tools/export.py`（每次采集/巡检后自动重跑） |
| 编码 | UTF-8，紧凑 JSON（`--pretty` 可缩进） |

## 顶层

| 字段 | 类型 | 说明 |
|---|---|---|
| `schema_version` | string | 契约版本，**字段增删改必升** |
| `generated_at` | string | UTC ISO8601 |
| `counts` | object | `resources / links / channels / valid / invalid / unchecked` |
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
| `desc` | string | 否 | 描述（≤300 字） |

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

## 示例

```bash
curl -s https://raw.githubusercontent.com/<owner>/<repo>/main/data/resources.json | jq '.counts'
```

```js
const data = await (await fetch('data/resources.json')).json();
const list = data.resources.filter(r => r.status !== 'invalid');
```
