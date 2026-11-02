#!/usr/bin/env python3
"""频道探针：每个频道只抓 1 页，看它到底有没有货、主要发哪个网盘。

用来给一堆候选频道排优先级，避免把采集配额浪费在「看着像资源频道、其实全是广告」的地方。

用法：
    python tools/probe_channels.py --file collector/seed_channels.txt --proxy http://127.0.0.1:7890
"""
from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "collector"))
import fetch_tg as tg  # noqa: E402

OUT = Path(__file__).resolve().parent.parent / "data" / "channel_probe.json"


def probe(channel: str, op, delay: float) -> dict:
    started = time.time()
    html = tg.fetch_page(op, channel)
    if not html:
        return {"channel": channel, "ok": False, "error": "抓取失败（私有/限流/不存在）"}
    msgs = tg.parse_messages(html, channel)
    links, providers = 0, {}
    newest = ""
    with_links = 0
    for m in msgs:
        found = tg.extract_links(m["text"])
        if found:
            with_links += 1
        for link in found:
            links += 1
            providers[link["provider"]] = providers.get(link["provider"], 0) + 1
        if m["posted_at"] and m["posted_at"] > newest:
            newest = m["posted_at"]
    time.sleep(delay)
    return {
        "channel": channel,
        "ok": True,
        "messages": len(msgs),
        "messages_with_links": with_links,
        "links": links,
        "hit_rate": round(with_links / len(msgs), 2) if msgs else 0,
        "providers": dict(sorted(providers.items(), key=lambda x: -x[1])),
        "newest_post": newest,
        "elapsed": round(time.time() - started, 1),
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", default=str(Path(__file__).resolve().parent.parent / "collector" / "seed_channels.txt"))
    ap.add_argument("--proxy", default=None)
    ap.add_argument("--delay", type=float, default=1.0)
    args = ap.parse_args()

    raw = Path(args.file).read_text(encoding="utf-8").splitlines()
    channels = [c for c in (tg.norm_channel(x) for x in raw) if c]
    if not channels:
        print("没有候选频道", file=sys.stderr)
        return 1

    op = tg.build_opener(tg.resolve_proxy(args.proxy))
    results = []
    for ch in channels:
        row = probe(ch, op, args.delay)
        results.append(row)
        if not row["ok"]:
            print(f"  ✗ {ch:<18} {row['error']}")
        else:
            prov = "，".join(f"{k} {v}" for k, v in row["providers"].items()) or "无"
            print(f"  ✓ {ch:<18} 消息 {row['messages']:>2}｜有链 {row['messages_with_links']:>2}"
                  f"｜链接 {row['links']:>2}｜{prov}")

    ok = [r for r in results if r["ok"]]
    ok.sort(key=lambda r: (-r["links"], -r["hit_rate"]))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({
        "probed_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "results": results,
    }, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\n可用 {len(ok)}/{len(results)} 个频道，总链接 {sum(r['links'] for r in ok)} 条")
    print("产出 Top5：" + "，".join(f"{r['channel']}({r['links']})" for r in ok[:5]))
    print(f"明细已写入 {OUT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
