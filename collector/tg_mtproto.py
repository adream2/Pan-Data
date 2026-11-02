#!/usr/bin/env python3
"""MTProto 采集（Telethon）：补 t.me/s 网页预览抓不到的部分。

网页预览的两个硬伤：
    1. 频道关掉预览就完全抓不到（实测 naclzy / yespojie 等一批号都是这样）
    2. 只能翻到有限的历史深度

MTProto 用账号直连，这两条都绕过。代价是需要一个账号的 session（等同凭证，只能放 Secret）。

用法（先跑 tools/tg_login.py 拿到 session）：
    TG_API_ID=... TG_API_HASH=... TG_SESSION=... python collector/tg_mtproto.py
    ... python collector/tg_mtproto.py --limit 500     # 每个频道回填 500 条历史
    ... python collector/tg_mtproto.py --channels a,b --limit 200

状态文件 data/mtproto_state.json 记录每个频道已采到的最大消息 ID，增量不重复。
"""
from __future__ import annotations

import argparse
import asyncio
import json
import os
import random
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import fetch_tg as tg  # noqa: E402  复用解析/去重/归一逻辑
import grouping  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "data"
LINKS = DATA / "links.jsonl"
STATE = DATA / "mtproto_state.json"


def load_local_env() -> None:
    """本地跑的时候从 .env.mtproto 读凭证（Actions 里直接用 Secrets）。"""
    path = ROOT / ".env.mtproto"
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            key, _, value = line.partition("=")
            os.environ.setdefault(key.strip(), value.strip())


def parse_proxy(value: str | None):
    """支持 socks5://host:port 与 http://host:port；没配就直连（Actions 里直连可用）。"""
    if not value:
        return None
    from urllib.parse import urlparse
    parsed = urlparse(value if "://" in value else f"socks5://{value}")
    if not parsed.hostname or not parsed.port:
        return None
    return (parsed.scheme, parsed.hostname, parsed.port)


def load_json(path: Path, default):
    if not path.exists():
        return default
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return default


def load_rows(path: Path) -> list[dict]:
    if not path.exists():
        return []
    return [json.loads(x) for x in path.read_text(encoding="utf-8").splitlines() if x.strip()]


def build_rows(msg_text: str, channel: str, msg_id: int, posted_at: str | None) -> list[dict]:
    """把一条消息变成若干条链接记录，字段与网页采集完全一致。"""
    title = tg.build_title(msg_text, "")
    desc = tg.build_desc(msg_text)
    parsed = tg.parse_title(title)
    rows = []
    for link in tg.extract_links(msg_text):
        item_id = f'{link["provider"]}::{tg.link_key(link["provider"], link["share_url"])}'
        rows.append({
            "id": item_id,
            "provider": link["provider"],
            "share_url": link["share_url"],
            "pwd": link["pwd"],
            "title": title or link["share_url"],
            "desc": desc,
            "rtype": tg.classify_type(title),
            "core_title": parsed["core_title"],
            "year": parsed["year"],
            "season": parsed["season"],
            "episode": parsed["episode"],
            "complete": parsed["complete"],
            "group_key": parsed["group_key"],
            "channel": channel,
            "post_url": f"https://t.me/{channel}/{msg_id}",
            "posted_at": posted_at,
            "collected_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "via": "mtproto",
        })
    return rows


def channel_depth(meta: dict, st: dict, default: int) -> int:
    """按频道产出率决定回溯深度：`# deep=N` 显式指定优先，其次看历史产出率。

    产出率 = 累计入库链接 / 累计扫描消息。高产号给 1500-2000 条，纯引流号只给 120 条。
    """
    if meta.get("deep"):
        return int(meta["deep"])
    if not default:
        return 0
    scanned = int(st.get("scanned") or 0)
    links = int(st.get("links") or 0)
    if scanned < 50:
        return default
    rate = links * 100.0 / scanned
    if rate >= 60:
        return max(default, 2500)
    if rate >= 40:
        return max(default, 2000)
    if rate >= 30:
        return max(default, 1500)
    if rate >= 15:
        return max(default, 800)
    if rate >= 5:
        return max(default, 300)
    return min(default, 120)


async def run(args) -> int:
    load_local_env()
    try:
        from telethon import TelegramClient  # noqa: F401
        from telethon.errors import FloodWaitError
        from telethon.sessions import StringSession
    except ImportError:
        print("缺少 telethon：pip install telethon", file=sys.stderr)
        return 2

    api_id = os.getenv("TG_API_ID")
    api_hash = os.getenv("TG_API_HASH")
    session = os.getenv("TG_SESSION")
    if not (api_id and api_hash and session):
        print("缺少 TG_API_ID / TG_API_HASH / TG_SESSION（先跑 tools/tg_login.py）", file=sys.stderr)
        return 2

    if args.channels:
        channels = [(c, {}) for c in (tg.norm_channel(x) for x in args.channels.split(",")) if c]
    else:
        channels = tg.load_channel_config(ROOT / "collector" / "channels.txt")
    if args.extra_file:
        extra = Path(args.extra_file)
        if extra.exists():
            channels += tg.load_channel_config(extra)
    # 同一个频道出现两次时保留带 meta 的那份
    merged: dict[str, dict] = {}
    for name, meta in channels:
        merged[name] = {**merged.get(name, {}), **meta}
    channels = list(merged.items())

    def channel_state(ch: str) -> dict:
        value = state.get(ch)
        if isinstance(value, int):
            return {"last_id": value, "backfill_before": None}
        if isinstance(value, dict):
            return {"last_id": int(value.get("last_id") or 0),
                    "backfill_before": value.get("backfill_before")}
        return {"last_id": 0, "backfill_before": None}

    rows = load_rows(LINKS)
    seen = {r["id"] for r in rows}
    state = load_json(STATE, {})
    added = 0
    scanned = 0
    failed = []

    try:
        string_session = StringSession(session)
    except ValueError:
        print("TG_SESSION 不是合法的 StringSession（重新跑 tools/tg_login.py 生成）", file=sys.stderr)
        return 2

    proxy = parse_proxy(os.getenv("MT_PROXY"))
    if proxy is None and os.getenv("MT_PROXY"):
        print("本地代理需要 python-socks：pip install python-socks", file=sys.stderr)

    async with TelegramClient(string_session, int(api_id), api_hash, proxy=proxy) as client:
        me = await client.get_me()
        print(f"登录身份：{getattr(me, 'username', None) or me.id}")
        for channel, meta in channels:
            st = channel_state(channel)
            depth = channel_depth(meta, st, args.deep)
            last_id = st["last_id"]
            ch_scanned = 0
            new_rows: list[dict] = []
            try:
                entity = await client.get_entity(channel)
            except FloodWaitError as exc:
                print(f"  ! 触发限流，等 {exc.seconds}s")
                await asyncio.sleep(exc.seconds + 5)
                continue
            except Exception as exc:
                failed.append((channel, type(exc).__name__))
                print(f"  ✗ {channel:<18} 无法解析（{type(exc).__name__}）")
                continue
            # 两种模式：
            #   --deep N  → 从游标往历史深处翻（MTProto 独有的价值）
            #   默认      → 从水位往上取新消息
            kwargs = {"limit": depth or args.limit or None, "reverse": False}
            if depth:
                cursor = st.get("backfill_before") or last_id
                if cursor:
                    kwargs["max_id"] = int(cursor)
            elif last_id:
                kwargs["min_id"] = last_id
            try:
                async for msg in client.iter_messages(entity, **kwargs):
                    if not msg.message:
                        continue
                    scanned += 1
                    ch_scanned += 1
                    posted = msg.date.astimezone(timezone.utc).isoformat(timespec="seconds") if msg.date else None
                    new_rows.extend(build_rows(msg.message, channel, msg.id, posted))
                    if msg.id > last_id:
                        last_id = msg.id
                    if depth:
                        prev = st.get("backfill_before")
                        st["backfill_before"] = msg.id - 1 if prev is None else min(prev, msg.id - 1)
            except FloodWaitError as exc:
                failed.append((channel, f"floodwait_{exc.seconds}s"))
                print(f"  ! {channel:<18} 限流，等 {exc.seconds}s")
                await asyncio.sleep(exc.seconds + 5)
            except Exception as exc:
                failed.append((channel, type(exc).__name__))
                print(f"  ! {channel:<18} 读取中断（{type(exc).__name__}: {str(exc)[:40]}）")
            fresh = [r for r in new_rows if r["id"] not in seen]
            for r in fresh:
                seen.add(r["id"])
            rows.extend(fresh)
            added += len(fresh)
            st["last_id"] = last_id
            st["scanned"] = int(st.get("scanned") or 0) + ch_scanned
            st["links"] = int(st.get("links") or 0) + len(fresh)
            state[channel] = st
            cursor_note = f"｜回溯 {depth} 条｜游标 {st['backfill_before']}" if depth else ""
            print(f"  ✓ {channel:<18} 新链接 {len(fresh):>4}｜水位 {last_id}{cursor_note}")
            await asyncio.sleep(args.sleep + random.random())

    rows.sort(key=lambda r: r.get("posted_at") or "", reverse=True)
    stats = grouping.apply(rows)
    LINKS.parent.mkdir(parents=True, exist_ok=True)
    LINKS.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n", encoding="utf-8")
    STATE.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\n扫 {scanned} 条消息，新增 {added} 条链接，库内 {len(rows)} 条")
    print(f"收敛：{grouping.summary(rows)}")
    if failed:
        print(f"失败 {len(failed)} 个：" + "，".join(f"{c}({e})" for c, e in failed[:8]))
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--channels", default="", help="逗号分隔，覆盖 channels.txt")
    ap.add_argument("--extra-file", default=str(ROOT / "collector" / "mtproto_channels.txt"),
                    help="额外频道清单（网页预览抓不到、但账号能看的）")
    ap.add_argument("--limit", type=int, default=0, help="每个频道最多拉多少条（0=只取新消息）")
    ap.add_argument("--deep", type=int, default=0,
                    help="深度回溯模式：从游标往历史翻 N 条（可反复跑，游标自动前移）")
    ap.add_argument("--sleep", type=float, default=1.5, help="频道间隔，别把账号搞限流")
    args = ap.parse_args()
    return asyncio.run(run(args))


if __name__ == "__main__":
    raise SystemExit(main())
