#!/usr/bin/env python3
"""年度梳理：体检 + 清理 + 重新分片。

每年跑一次，处理「越积越脏」的问题：
    1. 统计该年的链接/资源/失效/重复/字段缺失
    2. --apply 时真正清理：删掉确认失效且超过 N 天的行
    3. 输出报告 data/audit/{year}.json
    4. --re-export 时重跑 export，让分片按新数据重建

用法：
    python tools/compact.py --year 2026            # 只体检（安全）
    python tools/compact.py --year 2026 --apply    # 执行清理
    python tools/compact.py --year 2026 --apply --re-export
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "data" / "links.jsonl"
AUDIT_DIR = ROOT / "data" / "audit"


def parse_time(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        dt = datetime.fromisoformat(value)
    except ValueError:
        return None
    return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)


def audit(rows: list[dict], year: int) -> dict:
    year_rows = [r for r in rows if (r.get("posted_at") or "").startswith(str(year))]
    groups: dict[str, list[dict]] = {}
    for r in year_rows:
        groups.setdefault(r.get("group_key") or r["id"], []).append(r)

    now = datetime.now(timezone.utc)
    stale_invalid = []
    for r in year_rows:
        if r.get("status") != "invalid":
            continue
        checked = parse_time(r.get("checked_at"))
        if checked and (now - checked).days >= 30:
            stale_invalid.append(r)

    return {
        "year": year,
        "generated_at": now.isoformat(timespec="seconds"),
        "links": len(year_rows),
        "resources": len(groups),
        "multi_source": sum(1 for v in groups.values() if len(v) > 1),
        "invalid": sum(1 for r in year_rows if r.get("status") == "invalid"),
        "unchecked": sum(1 for r in year_rows if not r.get("status")),
        "no_desc": sum(1 for r in year_rows if not r.get("desc")),
        "no_core_title": sum(1 for r in year_rows if not r.get("core_title")),
        "no_group_key": sum(1 for r in year_rows if not r.get("group_key")),
        "stale_invalid": len(stale_invalid),
        "channels": len({r.get("channel") for r in year_rows if r.get("channel")}),
        "providers": {},
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--year", type=int, default=datetime.now().year)
    ap.add_argument("--src", default=str(SRC))
    ap.add_argument("--apply", action="store_true", help="真正执行清理（默认只体检）")
    ap.add_argument("--re-export", action="store_true", help="清理后重跑 export")
    args = ap.parse_args()

    src = Path(args.src)
    rows = [json.loads(x) for x in src.read_text(encoding="utf-8").splitlines() if x.strip()]
    report = audit(rows, args.year)

    counts: dict[str, int] = {}
    for r in rows:
        if (r.get("posted_at") or "").startswith(str(args.year)):
            counts[r["provider"]] = counts.get(r["provider"], 0) + 1
    report["providers"] = dict(sorted(counts.items(), key=lambda x: -x[1]))

    print(f"== {args.year} 年体检 ==")
    for key in ("links", "resources", "multi_source", "invalid", "unchecked",
                "no_desc", "no_core_title", "no_group_key", "stale_invalid", "channels"):
        print(f"  {key:<14}{report[key]}")
    print("  providers    " + "，".join(f"{k} {v}" for k, v in report["providers"].items()))

    if args.apply and report["stale_invalid"]:
        keep = [
            r for r in rows
            if not (
                (r.get("posted_at") or "").startswith(str(args.year))
                and r.get("status") == "invalid"
                and (parse_time(r.get("checked_at")) or datetime.now(timezone.utc))
                and (datetime.now(timezone.utc) - parse_time(r.get("checked_at"))).days >= 30
            )
        ]
        removed = len(rows) - len(keep)
        src.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in keep) + "\n", encoding="utf-8")
        report["removed"] = removed
        print(f"已清理 {removed} 条失效且超 30 天的记录")
    elif args.apply:
        print("没有需要清理的记录")
        report["removed"] = 0

    AUDIT_DIR.mkdir(parents=True, exist_ok=True)
    out = AUDIT_DIR / f"{args.year}.json"
    out.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"报告 -> {out}")

    if args.re_export:
        import subprocess
        rc = subprocess.call([sys.executable, str(ROOT / "tools" / "export.py")])
        if rc != 0:
            print("重新导出失败", file=sys.stderr)
            return rc
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
