#!/usr/bin/env python3
"""自动扩源：挖互推 → 探产出 → 达标的直接写进 channels.txt。

一条龙替代人工三步走。判据很简单：探针抓 1 页，能出 >= N 条网盘链接就收。
重复跑是安全的——已存在的频道会被跳过，所以可以每周定时执行，雪球越滚越大。

用法：
    python tools/auto_expand.py --pages 3 --min-links 8            # 真正写入
    python tools/auto_expand.py --pages 3 --dry-run                # 只看结果
"""
from __future__ import annotations

import argparse
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "collector"))
sys.path.insert(0, str(ROOT / "tools"))

import fetch_tg as tg  # noqa: E402
import discover_channels as disc  # noqa: E402
import probe_channels as probe_mod  # noqa: E402

CHANNELS = ROOT / "collector" / "channels.txt"
MARK = "# --- 自动扩源"


def read_channels() -> list[str]:
    raw = CHANNELS.read_text(encoding="utf-8").splitlines()
    return [c for c in (tg.norm_channel(x) for x in raw) if c]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pages", type=int, default=3, help="每个种子频道翻几页找互推")
    ap.add_argument("--min-links", type=int, default=8, help="探针单页至少出多少条链接才收")
    ap.add_argument("--max-add", type=int, default=15, help="单次最多新增多少个频道")
    ap.add_argument("--proxy", default=None)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    known = read_channels()
    known_set = {c.lower() for c in known}
    op = tg.build_opener(tg.resolve_proxy(args.proxy))
    print(f"现有 {len(known)} 个频道，开始挖互推…")

    from collections import Counter
    total: Counter = Counter()
    for ch in known:
        found = disc.collect_mentions(ch, op, args.pages, 1.0)
        total.update({n: c for n, c in found.items() if n not in known_set})
    candidates = [n for n, c in total.most_common(60)]
    print(f"挖到 {len(candidates)} 个新候选，逐个探产出（≥{args.min_links} 条才收）…")

    picked = []
    for name in candidates:
        if len(picked) >= args.max_add:
            break
        row = probe_mod.probe(name, op, 0.8)
        if not row.get("ok"):
            print(f"  ✗ {name:<18} 抓取失败")
            continue
        links = row.get("links", 0)
        flag = "✓" if links >= args.min_links else "·"
        print(f"  {flag} {name:<18} 消息 {row.get('messages',0):>2}｜链接 {links:>3}")
        if links >= args.min_links:
            picked.append((name, links, row.get("providers") or {}))

    if not picked:
        print("没有新频道达标，收工")
        return 0

    print(f"\n本次可新增 {len(picked)} 个：")
    for name, links, provs in picked:
        print(f"  {name:<18} {links} 条/页  {'，'.join(f'{k}{v}' for k, v in provs.items())}")

    if args.dry_run:
        print("(dry-run，未写入)")
        return 0

    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    lines = [f"\n{MARK}（{stamp}，探针 ≥{args.min_links} 条/页）---"]
    lines += [f"{name}  # {links} 条/页" for name, links, _ in picked]
    CHANNELS.write_text(CHANNELS.read_text(encoding="utf-8").rstrip() + "\n" + "\n".join(lines) + "\n",
                        encoding="utf-8")
    print(f"已写入 {CHANNELS}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
