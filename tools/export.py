#!/usr/bin/env python3
"""把内部 links.jsonl 导出成对外契约：热文件 + 分片 + 索引。

- `data/resources.json`   最近 N 天（默认 90）的当前版本资源，首屏/搜索直接用
- `data/shards/*.json`    按季度切的全量库存，单个文件不会无限长
- `data/index.json`       清单：有哪些分片、各多少条、时间范围

用法：
    python tools/export.py                  # 90 天热文件 + 全量季度分片 + 索引
    python tools/export.py --days 30        # 热文件收窄到 30 天
    python tools/export.py --shard month    # 分片改成按月
    python tools/export.py --no-shards      # 只出热文件（排查用）
"""
from __future__ import annotations

import argparse
import calendar
import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# 复用采集端的分类器，避免导出和采集两套规则各自漂移
sys.path.insert(0, str(ROOT / "collector"))
from fetch_tg import classify_type  # noqa: E402
SRC = ROOT / "data" / "links.jsonl"
OUT = ROOT / "data" / "resources.json"
SHARD_DIR = ROOT / "data" / "shards"
INDEX = ROOT / "data" / "index.json"
SCHEMA_VERSION = "1.3"
DEFAULT_DAYS = 90
# 热文件条数上限。90 天全量在数据涨到 20k+ 后会到 14MB，
# 跨境拉一次要 3 分钟以上（实测 40KB/s），前端和 PHP 站都吃不消。
MAX_HOT = 8000

JUNK_TITLE_RE = re.compile(r"最新集数$|^\s*$")


def load(path: Path) -> list[dict]:
    if not path.exists():
        raise SystemExit(f"没有数据文件：{path}")
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def status_of(row: dict) -> str:
    return row.get("status") or "unchecked"


def best_title(row: dict) -> str:
    """频道偶尔会发「T TSXK 最新集数」这种占位标题，这时用归一化后的 core_title 兜底。"""
    title = (row.get("title_best") or row.get("title") or "").strip()
    core = (row.get("core_title") or "").strip()
    if (len(title) < 4 or JUNK_TITLE_RE.search(title)) and len(core) >= 2:
        return core
    return title or core


def parse_time(row: dict) -> datetime | None:
    ts = row.get("posted_at")
    if not ts:
        return None
    try:
        value = datetime.fromisoformat(ts)
    except ValueError:
        return None
    return value if value.tzinfo else value.replace(tzinfo=timezone.utc)


def within_days(row: dict, days: int) -> bool:
    """按发布时间过滤。没有时间戳的一律保留，宁可多不可漏。"""
    posted = parse_time(row)
    if posted is None:
        return True
    return (datetime.now(timezone.utc) - posted).days <= days


# 备用链接最多保留多少条。同一部热剧可能被几百个频道转发，
# 全塞进 mirrors 会让单条记录涨到几十 KB，热文件和详情页都吃不消；
# 前台只展示前 6 条，留 12 条已经足够切换。
MIRROR_LIMIT = 12


def mirror_url_ok(url: str) -> bool:
    """过滤抓取时被截断的残缺链接（如 https://pan.baidu.com/s/16D）。

    这类 URL 打不开也切不过去，留着只会让「备用链接」变成坑。
    """
    if not url or not url.startswith("http"):
        return False
    tail = url.rstrip("/").rsplit("/", 1)[-1]
    tail = tail.split("?")[0]
    return len(tail) >= 6


def build_mirrors(row: dict, siblings: list[dict]) -> list[dict]:
    """同组的其他来源，按 URL 去重后截断。

    siblings 里同一 share_url 会出现多次（多频道转发同一条时重复收录），
    之前只排除了自己，导致 mirrors 里大量重复条目。
    """
    seen: set[str] = set()
    out: list[dict] = []
    for m in siblings:
        url = m["share_url"]
        if m["id"] == row["id"] or url == row["share_url"]:
            continue
        if url in seen or not mirror_url_ok(url):
            continue
        seen.add(url)
        out.append({
            "provider": m["provider"],
            "share_url": url,
            "pwd": m.get("pwd") or None,
            "status": status_of(m),
        })
        if len(out) >= MIRROR_LIMIT:
            break
    return out


def build_resource(row: dict, siblings: list[dict]) -> dict:
    """固定字段顺序就是契约，改顺序要升 schema_version。"""
    mirrors = build_mirrors(row, siblings)
    return {
        "id": row.get("group_key") or row["id"],
        "title": best_title(row),
        "core_title": row.get("core_title") or "",
        # 用当前规则重算，而不是直接信 links.jsonl 里的 rtype：
        # 规则改过之后，几十万条历史数据不可能回头重采，
        # 导出这一步是唯一能统一口径的地方。
        "type": classify_type(best_title(row)),
        "year": row.get("year"),
        "season": row.get("season"),
        "episode": row.get("episode"),
        "complete": bool(row.get("complete")),
        "provider": row["provider"],
        "share_url": row["share_url"],
        "pwd": row.get("pwd") or None,
        "status": status_of(row),
        "checked_at": row.get("checked_at"),
        "sources": row.get("group_size") or 1,
        "mirrors": mirrors,
        "channel": row.get("channel"),
        "post_url": row.get("post_url"),
        "posted_at": row.get("posted_at"),
        "desc": (row.get("desc") or "")[:100],
    }


def build_resources(rows: list[dict]) -> list[dict]:
    by_group: dict[str, list[dict]] = {}
    for row in rows:
        by_group.setdefault(row.get("group_key") or row["id"], []).append(row)

    resources = []
    for _key, members in by_group.items():
        current = next((m for m in members if m.get("group_role") == "current"), None)
        if current is None:  # 没跑过 grouping 的老数据，取最新那条
            current = max(members, key=lambda m: m.get("posted_at") or "")
        resources.append(build_resource(current, members))
    resources.sort(key=lambda r: (r["posted_at"] or ""), reverse=True)
    return resources


def period_of(row: dict, mode: str) -> str:
    """归属周期。按「当前版本」的发布时间切，资源只出现在一个分片里。"""
    posted = parse_time(row)
    if posted is None:
        return "unknown"
    if mode == "month":
        return f"{posted.year}-{posted.month:02d}"
    return f"{posted.year}-Q{(posted.month - 1) // 3 + 1}"


def period_range(period: str) -> tuple[str, str]:
    """返回周期的起止日期（含当月最后一天，别写出 09-31 这种不存在的日期）。"""
    m = re.fullmatch(r"(\d{4})-Q([1-4])", period)
    if m:
        year, quarter = int(m.group(1)), int(m.group(2))
        start_month = (quarter - 1) * 3 + 1
        end_month = start_month + 2
    else:
        m = re.fullmatch(r"(\d{4})-(\d{2})", period)
        if not m:
            return "", ""
        year, start_month = int(m.group(1)), int(m.group(2))
        end_month = start_month
    last_day = calendar.monthrange(year, end_month)[1]
    return f"{year}-{start_month:02d}-01", f"{year}-{end_month:02d}-{last_day:02d}"


def envelope(payload_resources: list[dict], **extra) -> dict:
    data = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        **extra,
        "counts": {
            "resources": len(payload_resources),
            "valid": sum(1 for r in payload_resources if r["status"] == "valid"),
            "invalid": sum(1 for r in payload_resources if r["status"] == "invalid"),
            "unchecked": sum(1 for r in payload_resources if r["status"] == "unchecked"),
        },
        "resources": payload_resources,
    }
    return data


def write_if_changed(path: Path, payload: dict) -> tuple[bool, int]:
    """分片内容没变就不重写，避免每次 CI 都动归档文件。"""
    path.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n"
    digest = hashlib.sha1(text.encode()).hexdigest()
    if path.exists():
        old = path.read_bytes()
        if hashlib.sha1(old).hexdigest() == hashlib.sha1(text.encode()).hexdigest():
            return False, len(old)
    path.write_text(text, encoding="utf-8")
    return True, len(text.encode())


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default=str(SRC))
    ap.add_argument("--out", default=str(OUT))
    ap.add_argument("--shard-dir", default=str(SHARD_DIR))
    ap.add_argument("--index", default=str(INDEX))
    ap.add_argument("--days", type=int, default=DEFAULT_DAYS,
                    help=f"热文件保留最近 N 天，0 = 全量（默认 {DEFAULT_DAYS}）")
    ap.add_argument("--shard", choices=["quarter", "month"], default="month",
                    help="库存分片粒度；默认按月（单片约 5MB，季度会到 14MB）")
    ap.add_argument("--no-shards", action="store_true", help="只出热文件")
    ap.add_argument("--max-hot", type=int, default=MAX_HOT,
                    help=f"热文件条数上限（默认 {MAX_HOT}，0 = 不限制）")
    ap.add_argument("--pretty", action="store_true")
    args = ap.parse_args()

    rows = load(Path(args.src))
    all_resources = build_resources(rows)
    total_links = len(rows)

    hot = all_resources if args.days <= 0 else [r for r in all_resources if within_days(r, args.days)]
    # 按发布时间倒序取前 N 条：热文件的定位是「最近活跃」，不是「全部历史」
    hot_total = len(hot)
    if args.max_hot > 0 and len(hot) > args.max_hot:
        hot = sorted(hot, key=lambda r: r.get("posted_at") or "", reverse=True)[:args.max_hot]
    hot_payload = envelope(hot, period={"mode": "hot", "days": args.days})
    if len(hot) < hot_total:
        hot_payload["period"]["capped"] = {
            "kept": len(hot),
            "in_window": hot_total,
            "note": "热文件只保留窗口内最新的 N 条，更早的在分片里",
        }
    hot_payload["counts"].update({
        "links": total_links,
        "links_in_window": sum(1 for r in rows if args.days <= 0 or within_days(r, args.days)),
        "window_days": args.days,
        "channels": len({r.get("channel") for r in rows if r.get("channel")}),
    })
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(
        json.dumps(hot_payload, ensure_ascii=False, indent=2 if args.pretty else None) + "\n",
        encoding="utf-8",
    )
    cap_note = f"（窗口内 {hot_total} 条，截取最新 {len(hot)} 条）" if len(hot) < hot_total else ""
    print(f"热文件 {len(hot)} 个资源{cap_note} -> {out}（{out.stat().st_size/1024:.0f} KB）")

    if args.no_shards:
        return 0

    # 全量按周期聚合（先建「每组当前版本」索引，避免逐个资源反查整张表）
    base_row: dict[str, dict] = {}
    for row in rows:
        key = row.get("group_key") or row["id"]
        if key not in base_row or row.get("group_role") == "current":
            base_row[key] = row

    buckets: dict[str, list[dict]] = {}
    for res in all_resources:
        key = period_of(base_row.get(res["id"], {"posted_at": res.get("posted_at")}), args.shard)
        buckets.setdefault(key, []).append(res)

    shard_dir = Path(args.shard_dir)
    shards_meta = []
    for key in sorted(buckets):
        items = buckets[key]
        start, end = period_range(key)
        payload = envelope(items, period={"id": key, "from": start, "to": end, "mode": args.shard})
        path = shard_dir / f"{key}.json"
        changed, size = write_if_changed(path, payload)
        shards_meta.append({
            "id": key,
            "path": f"data/shards/{path.name}",
            "from": start,
            "to": end,
            "resources": len(items),
            "bytes": size,
            "updated": changed,
        })
        print(f"  分片 {key}: {len(items)} 条 / {size/1024:.0f} KB {'(更新)' if changed else '(未变)'}")

    index_payload = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "hot": {
            "path": "data/resources.json",
            "days": args.days,
            "resources": len(hot),
            "bytes": out.stat().st_size,
        },
        "totals": {
            "resources": len(all_resources),
            "links": total_links,
            "shards": len(shards_meta),
        },
        "shards": [{k: v for k, v in s.items() if k != "updated"} for s in shards_meta],
    }
    index_path = Path(args.index)
    write_if_changed(index_path, index_payload)
    print(f"索引 -> {index_path}（{len(shards_meta)} 个分片，总库存 {len(all_resources)} 条）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
