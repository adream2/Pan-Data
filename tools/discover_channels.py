#!/usr/bin/env python3
"""频道发现：从已有频道的消息里挖「互推」的兄弟频道。

资源号几乎都会在正文尾部互推一串频道（「📢 综合频道：@xxx」），
这等于它们自己把整个矩阵交出来了。抓这些 @mention 是最省力的扩源方式。

用法：
    python tools/discover_channels.py --pages 3 --proxy http://127.0.0.1:7890
    python tools/discover_channels.py --pages 5          # 挖得更深
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "collector"))
import fetch_tg as tg  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "collector" / "discovered.txt"
REPORT = ROOT / "data" / "discover_report.json"

MENTION_RE = re.compile(r"(?<![\w/])@([A-Za-z][A-Za-z0-9_]{3,31})")
LINK_RE = re.compile(r"(?:t\.me|telegram\.me)/([A-Za-z][A-Za-z0-9_]{3,31})")
# 明显不是资源号的词
BLOCK = {"telegram", "durov", "joinchat", "addstickers", "share", "proxy", "socks"}


def collect_mentions(channel: str, op, pages: int, delay: float) -> Counter:
    seen: Counter = Counter()
    before = None
    for _ in range(pages):
        html = tg.fetch_page(op, channel, before)
        if not html:
            break
        msgs = tg.parse_messages(html, channel)
        if not msgs:
            break
        for msg in msgs:
            text = msg["text"]
            for name in MENTION_RE.findall(text) + LINK_RE.findall(text):
                low = name.lower()
                if low in BLOCK or low == channel.lower():
                    continue
                if low.endswith("bot"):
                    continue
                seen[low] += 1
        before = min(m["post_id"] for m in msgs)
        time.sleep(delay)
    return seen


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pages", type=int, default=3)
    ap.add_argument("--channels", default="")
    ap.add_argument("--proxy", default=None)
    ap.add_argument("--delay", type=float, default=1.0)
    ap.add_argument("--min-mentions", type=int, default=2, help="至少被提到几次才输出")
    args = ap.parse_args()

    if args.channels:
        raw = args.channels.split(",")
    else:
        raw = (ROOT / "collector" / "channels.txt").read_text(encoding="utf-8").splitlines()
    seeds = [c for c in (tg.norm_channel(x) for x in raw) if c]

    known = {s.lower() for s in seeds}
    op = tg.build_opener(tg.resolve_proxy(args.proxy))

    total: Counter = Counter()
    per_channel: dict[str, list[str]] = {}
    for ch in seeds:
        found = collect_mentions(ch, op, args.pages, args.delay)
        per_channel[ch] = [n for n, _ in found.most_common(20)]
        new = {n: c for n, c in found.items() if n not in known}
        total.update(new)
        print(f"  {ch:<16} 提到 {len(found):>3} 个频道，其中新面孔 {len(new)}")

    ranked = [(n, c) for n, c in total.most_common() if c >= args.min_mentions]
    OUT.parent.mkdir(parents=True, exist_ok=True)
    lines = ["# 由 tools/discover_channels.py 从互推里挖出来的候选频道（按被提及次数排序）",
             "# 用 tools/probe_channels.py --file collector/discovered.txt 验一遍再决定要不要用", ""]
    lines += [f"{name}  # 被提到 {count} 次" for name, count in ranked]
    OUT.write_text("\n".join(lines) + "\n", encoding="utf-8")

    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps({
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "seeds": seeds,
        "candidates": [{"channel": n, "mentions": c} for n, c in ranked],
        "per_channel": per_channel,
    }, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\n挖到 {len(ranked)} 个候选（≥{args.min_mentions} 次）-> {OUT}")
    print("Top10: " + "，".join(f"{n}({c})" for n, c in ranked[:10]))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
