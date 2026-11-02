#!/usr/bin/env python3
"""把内部 links.jsonl 导出成对外契约 resources.json。

对外只暴露「当前版本」的 698 个资源，字段顺序固定、带 schema_version。
内部字段（title_raw / 巡检细节 / 冗余来源）不外泄，避免调用方踩坑。

用法：
    python tools/export.py                      # 写 data/resources.json
    python tools/export.py --pretty --with-all  # 带冗余来源明细
"""
from __future__ import annotations

import argparse
import re
import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "data" / "links.jsonl"
OUT = ROOT / "data" / "resources.json"
SCHEMA_VERSION = "1.1"
DEFAULT_DAYS = 90


def load(path: Path) -> list[dict]:
    if not path.exists():
        raise SystemExit(f"没有数据文件：{path}")
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def status_of(row: dict) -> str:
    return row.get("status") or "unchecked"


JUNK_TITLE_RE = re.compile(r"最新集数$|^\s*$")


def best_title(row: dict) -> str:
    """频道偶尔会发「T TSXK 最新集数」这种占位标题，这时用归一化后的 core_title 兜底。"""
    title = (row.get("title_best") or row.get("title") or "").strip()
    core = (row.get("core_title") or "").strip()
    if (len(title) < 4 or JUNK_TITLE_RE.search(title)) and len(core) >= 2:
        return core
    return title or core


def within_days(row: dict, days: int) -> bool:
    """按发布时间过滤。没有时间戳的一律保留，宁可多不可漏。"""
    ts = row.get("posted_at")
    if not ts:
        return True
    try:
        posted = datetime.fromisoformat(ts)
    except ValueError:
        return True
    if posted.tzinfo is None:
        posted = posted.replace(tzinfo=timezone.utc)
    return (datetime.now(timezone.utc) - posted).days <= days


def build_resource(row: dict, siblings: list[dict]) -> dict:
    """固定字段顺序就是契约，改顺序要升 schema_version。"""
    mirrors = [
        {
            "provider": m["provider"],
            "share_url": m["share_url"],
            "pwd": m.get("pwd") or None,
            "status": status_of(m),
        }
        for m in siblings
        if m["id"] != row["id"] and m["share_url"] != row["share_url"]
    ]
    return {
        "id": row.get("group_key") or row["id"],
        "title": best_title(row),
        "core_title": row.get("core_title") or "",
        "type": row.get("rtype") or "other",
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


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default=str(SRC))
    ap.add_argument("--out", default=str(OUT))
    ap.add_argument("--days", type=int, default=DEFAULT_DAYS,
                    help=f"只导出最近 N 天的资源，0 = 全量（默认 {DEFAULT_DAYS}）")
    ap.add_argument("--pretty", action="store_true", help="缩进输出，方便人看；生产用紧凑")
    ap.add_argument("--with-all", action="store_true", help="额外导出被收敛掉的来源")
    args = ap.parse_args()

    rows = load(Path(args.src))
    total_rows = len(rows)
    if args.days > 0:
        rows = [r for r in rows if within_days(r, args.days)]

    by_group: dict[str, list[dict]] = {}
    for row in rows:
        by_group.setdefault(row.get("group_key") or row["id"], []).append(row)

    resources = []
    for key, members in by_group.items():
        current = next((m for m in members if m.get("group_role") == "current"), None)
        if current is None:  # 没跑过 grouping 的老数据，取排序第一条
            current = max(members, key=lambda m: m.get("posted_at") or "")
        resources.append(build_resource(current, members))
    resources.sort(key=lambda r: (r["posted_at"] or ""), reverse=True)

    payload = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "counts": {
            "resources": len(resources),
            "links": total_rows,
            "links_in_window": len(rows),
            "window_days": args.days,
            "channels": len({r.get("channel") for r in rows if r.get("channel")}),
            "valid": sum(1 for r in resources if r["status"] == "valid"),
            "invalid": sum(1 for r in resources if r["status"] == "invalid"),
            "unchecked": sum(1 for r in resources if r["status"] == "unchecked"),
        },
        "resources": resources,
    }
    if args.with_all:
        payload["superseded"] = [
            {
                "id": r["id"],
                "share_url": r["share_url"],
                "pwd": r.get("pwd") or None,
                "provider": r["provider"],
                "episode": r.get("episode"),
                "role": r.get("group_role"),
                "superseded_by": r.get("superseded_by"),
                "channel": r.get("channel"),
            }
            for r in rows if r.get("group_role") != "current"
        ]

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(payload, ensure_ascii=False, indent=2 if args.pretty else None)
    out.write_text(text + "\n", encoding="utf-8")
    size_kb = out.stat().st_size / 1024
    print(f"已导出 {len(resources)} 个资源 -> {out}（{size_kb:.0f} KB）")
    print("统计：" + "，".join(f"{k} {v}" for k, v in payload["counts"].items()))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
