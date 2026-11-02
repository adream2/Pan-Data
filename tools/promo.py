#!/usr/bin/env python3
"""拉新管控台：选品 → 风控 → 审批 → 转存 → 再分享 → 上线。

产品原则（写死在流程里）：
    1. 只做「自有链接」：必须先在夸克账号里转存，再用自己的分享发出去，否则拉新归因不到你。
    2. 不是什么都转：默认只放课程/电子书/软件/音乐，影视默认拦（版权风险最高）。
    3. 违规一票否决：命中硬词库直接 blocked，人工也不能批（要批只能改词库）。
    4. 全流程可停：dry-run 默认开启，--live 才真的动网盘。

    python tools/promo.py scan --limit 50      # 选品 + 风控打分，写进候选池
    python tools/promo.py list                 # 看候选
    python tools/promo.py approve <id...>      # 审批（medium 风险需 --force）
    python tools/promo.py run --live           # 转存 + 再分享
    python tools/promo.py dashboard            # 生成 data/promo.html 管控台
    python tools/promo.py stats
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "collector"))

LINKS = ROOT / "data" / "links.jsonl"
STATE = ROOT / "data" / "promo.json"
RULES = ROOT / "promo_rules.json"
DASH = ROOT / "data" / "promo.html"

LEVEL_ORDER = {"low": 0, "medium": 1, "high": 2, "blocked": 3}
ACTIVE = ("candidate", "approved", "transferring", "transferred", "sharing", "live")


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def load_json(path: Path, default):
    if not path.exists():
        return default
    return json.loads(path.read_text(encoding="utf-8"))


def save_state(state: dict) -> None:
    STATE.parent.mkdir(parents=True, exist_ok=True)
    STATE.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


def load_rows() -> list[dict]:
    if not LINKS.exists():
        raise SystemExit(f"没有 {LINKS}，先跑采集")
    return [json.loads(x) for x in LINKS.read_text(encoding="utf-8").splitlines() if x.strip()]


# ---------------- 风控 ----------------
def evaluate(row: dict, rules: dict) -> tuple[str, list[str]]:
    text = f'{row.get("title_best") or row.get("title") or ""} {row.get("desc") or ""} {row.get("core_title") or ""}'
    reasons: list[str] = []

    hits = [w for w in rules.get("block_keywords", []) if w and w in text]
    if hits:
        return "blocked", [f"命中违禁词：{w}" for w in hits[:3]]

    if rules.get("require_valid_link") and row.get("status") not in ("valid", None):
        reasons.append(f"链接状态 {row.get('status')}")
    if len(row.get("title") or "") < int(rules.get("min_title_length", 4)):
        reasons.append("标题过短")
    if row.get("channel") in set(rules.get("block_channels") or []):
        reasons.append("频道在黑名单")
    if row.get("rtype") in set(rules.get("block_types") or []):
        reasons.append(f"类型 {row.get('rtype')} 默认不做（版权风险）")
    warns = [w for w in rules.get("warn_keywords", []) if w and w in text]
    if warns:
        reasons.append("命中提醒词：" + "、".join(warns[:2]))

    if any(r.startswith(("链接状态", "标题过短", "频道在", "类型 ")) for r in reasons):
        return "high", reasons
    if warns:
        return "medium", reasons
    return "low", reasons


# ---------------- 选品 ----------------
def cmd_scan(args) -> int:
    rules = load_json(RULES, {})
    state = load_json(STATE, {"version": 1, "items": {}, "log": []})
    items = state["items"]
    rows = load_rows()

    pool = [r for r in rows
            if r.get("group_role") == "current"
            and r.get("provider") == "quark"
            and r.get("group_key")
            and r["group_key"] not in items]
    if args.type:
        wanted = {t.strip() for t in args.type.split(",")}
        pool = [r for r in pool if r.get("rtype") in wanted]
    elif args.allow_movie:
        pool = [r for r in pool if r.get("rtype") != "other"]
    else:
        allow = set(rules.get("allow_types") or [])
        pool = [r for r in pool if r.get("rtype") in allow]

    pool.sort(key=lambda r: r.get("posted_at") or "", reverse=True)
    added = 0
    stats = {"low": 0, "medium": 0, "high": 0, "blocked": 0}
    for row in pool:
        if args.limit and added >= args.limit:
            break
        level, reasons = evaluate(row, rules)
        if args.skip_blocked and level == "blocked":
            continue
        items[row["group_key"]] = {
            "id": row["group_key"],
            "title": row.get("title_best") or row.get("title") or "",
            "core_title": row.get("core_title") or "",
            "type": row.get("rtype") or "other",
            "source_url": row["share_url"],
            "source_pwd": row.get("pwd") or None,
            "channel": row.get("channel"),
            "posted_at": row.get("posted_at"),
            "risk": {"level": level, "reasons": reasons},
            "status": "blocked" if level == "blocked" else "candidate",
            "added_at": now(),
        }
        stats[level] += 1
        added += 1

    state["log"].append({"at": now(), "action": "scan", "added": added, "stats": stats})
    save_state(state)
    print(f"新增候选 {added} 条 | 风险分布 {stats} | 池子共 {len(items)} 条")
    if stats["blocked"]:
        print(f"其中 {stats['blocked']} 条命中违禁词，已直接 blocked（不可审批）")
    return 0


def cmd_list(args) -> int:
    state = load_json(STATE, {"items": {}})
    items = state.get("items", {})
    wanted = args.status.split(",") if args.status else list(ACTIVE)
    rows = [i for i in items.values() if i["status"] in wanted]
    rows.sort(key=lambda i: (i["status"], -LEVEL_ORDER[i["risk"]["level"]]))
    if not rows:
        print("没有符合条件的条目")
        return 0
    for i in rows[: args.limit or 60]:
        risk = i["risk"]["level"]
        flag = {"low": " ", "medium": "!", "high": "!", "blocked": "×"}.get(risk, " ")
        pwd = f" 码{i['source_pwd']}" if i.get("source_pwd") else ""
        print(f"{flag} {i['status']:<11}{risk:<8}{i['type']:<9}{i['title'][:36]:<38}{i['id']}{pwd}")
    print(f"\n共 {len(rows)} 条（显示前 {min(len(rows), args.limit or 60)}）")
    return 0


def cmd_approve(args) -> int:
    state = load_json(STATE, {"items": {}, "log": []})
    items = state["items"]
    ok = 0
    for key in args.ids:
        item = items.get(key) or next((i for i in items.values() if i["id"].startswith(key)), None)
        if not item:
            print(f"✗ 找不到 {key}")
            continue
        level = item["risk"]["level"]
        if level == "blocked":
            print(f"✗ {item['title'][:24]} 命中违禁词，禁止审批（要放行只能改词库）")
            continue
        if level in ("medium", "high") and not args.force:
            print(f"! {item['title'][:24]} 风险={level}，需加 --force 明确确认")
            continue
        item["status"] = "approved"
        item["approved_at"] = now()
        item["note"] = args.note or ""
        ok += 1
        print(f"✓ 已批准 {item['title'][:30]}")
    state["log"].append({"at": now(), "action": "approve", "count": ok})
    save_state(state)
    return 0


def cmd_reject(args) -> int:
    state = load_json(STATE, {"items": {}, "log": []})
    for key in args.ids:
        item = state["items"].get(key)
        if item:
            item["status"] = "rejected"
            item["note"] = args.reason or ""
            print(f"✓ 已拒绝 {item['title'][:30]}")
    state["log"].append({"at": now(), "action": "reject", "count": len(args.ids)})
    save_state(state)
    return 0


# ---------------- 转存 + 再分享 ----------------
def cmd_run(args) -> int:
    rules = load_json(RULES, {})
    state = load_json(STATE, {"items": {}, "log": []})
    items = state["items"]

    today = now()[:10]
    used = sum(1 for i in items.values() if (i.get("transfer") or {}).get("at", "").startswith(today))
    limit = int(rules.get("daily_transfer_limit", 30))
    todo = [i for i in items.values() if i["status"] == "approved"]
    todo.sort(key=lambda i: i.get("approved_at") or "")
    if args.limit:
        todo = todo[: args.limit]
    room = max(0, limit - used)
    if room <= 0:
        print(f"今日转存已达上限 {limit}，明天再来（或改 promo_rules.json）")
        return 0
    todo = todo[:room]

    print(f"待处理 {len(todo)} 条 | 今日已用 {used}/{limit} | 模式：{'LIVE' if args.live else 'DRY-RUN'}")
    if not args.live:
        for i in todo:
            print(f"  [dry] {i['title'][:40]} <- {i['source_url']}")
        print("\n加 --live 才真正转存。执行前请确认 QUARK_COOKIE 已配置。")
        return 0

    from quark_client import QuarkClient, QuarkError, pwd_id_of

    client = QuarkClient(proxy=args.proxy)
    ok = fail = 0
    for item in todo:
        item["status"] = "transferring"
        save_state(state)
        try:
            pwd_id = pwd_id_of(item["source_url"])
            stoken = client.share_stoken(pwd_id, item.get("source_pwd") or "")
            files = client.list_files(pwd_id, stoken)
            if not files:
                raise QuarkError("分享里没有可转存的文件")
            task_id = client.save(pwd_id=pwd_id, stoken=stoken, files=files)
            result = client.wait_task(task_id)
            item["transfer"] = {"task_id": task_id, "at": now(),
                                "saved": len(files), "status": result.get("status")}
            item["status"] = "transferred"

            # 再分享：拿转存后的第一个文件（通常是一个文件夹）
            saved_fids = result.get("save_as") or {}
            fid = (saved_fids.get("fid") if isinstance(saved_fids, dict) else None) or ""
            if not fid:
                fid = _first_saved_fid(result)
            if not fid:
                raise QuarkError("转存成功但拿不到新文件 fid，无法再分享")
            share_task = client.create_share(fid=fid, title=item["core_title"] or item["title"],
                                             expire_days=int(rules.get("share_expire_days", 0)))
            item["status"] = "sharing"
            share_id = client.share_id_of(share_task)
            url, pwd = client.publish(share_id)
            item["share"] = {"share_id": share_id, "url": url, "pwd": pwd, "at": now()}
            item["status"] = "live"
            ok += 1
            print(f"  ✓ {item['title'][:30]} -> {url}{' 码' + pwd if pwd else ''}")
        except QuarkError as exc:
            item["status"] = "failed"
            item["note"] = str(exc)[:200]
            fail += 1
            print(f"  ✗ {item['title'][:30]}：{exc}")
            if "Cookie" in str(exc):
                break
        save_state(state)

    state["log"].append({"at": now(), "action": "run", "ok": ok, "fail": fail})
    save_state(state)
    print(f"\n完成：成功 {ok}，失败 {fail}")
    return 0


def _first_saved_fid(result: dict) -> str:
    for key in ("save_as", "file_list"):
        value = result.get(key)
        if isinstance(value, list) and value:
            first = value[0]
            if isinstance(first, dict) and first.get("fid"):
                return str(first["fid"])
    return ""


# ---------------- 管控台 ----------------
def distribute_url(share_url: str, rules: dict) -> str:
    """拉新分发链接：官方分享链接 + 渠道参数（或渠道落地页）。"""
    aff = rules.get("affiliate") or {}
    if not aff.get("enabled"):
        return share_url
    base = aff.get("landing_url") or share_url
    params = {k: str(v) for k, v in (aff.get("params") or {}).items() if v}
    if not params:
        return base
    sep = "&" if "?" in base else "?"
    return base + sep + "&".join(f"{k}={v}" for k, v in params.items())


def cmd_dashboard(args) -> int:
    rules = load_json(RULES, {})
    state = load_json(STATE, {"items": {}})
    items = list(state.get("items", {}).values())
    counts: dict[str, int] = {}
    for i in items:
        counts[i["status"]] = counts.get(i["status"], 0) + 1
    live = [i for i in items if i["status"] == "live" and i.get("share")]

    def badge(status: str, risk: str) -> str:
        color = {"live": "#3B6D11", "approved": "#185FA5", "candidate": "#5F5E5A",
                 "blocked": "#A32D2D", "failed": "#A32D2D", "rejected": "#8a8a8a",
                 "transferred": "#185FA5", "sharing": "#185FA5", "transferring": "#185FA5"}.get(status, "#5F5E5A")
        return f'<span class="b" style="color:{color};border-color:{color}33">{status}</span>'

    rows = []
    for i in sorted(items, key=lambda x: (x["status"] != "approved", x.get("posted_at") or ""), reverse=True)[:400]:
        share = i.get("share") or {}
        if share.get("url"):
            url = distribute_url(share["url"], rules)
            pwd = (" 码" + share["pwd"]) if share.get("pwd") else ""
            target = f'<a href="{url}" target="_blank">{url}{pwd}</a>'
        else:
            target = "—"
        reason = "；".join((i.get("risk") or {}).get("reasons", []))[:40] or "—"
        rows.append(f"""<tr>
<td><div class="t">{i['title'][:56]}</div><div class="m">{i['type']} · {i.get('channel','')} · {(i.get('posted_at') or '')[:10]} · {reason}</div></td>
<td>{badge(i['status'], i['risk']['level'])}</td>
<td class="r">{i['risk']['level']}</td>
<td><a href="{i['source_url']}" target="_blank">原链</a></td>
<td class="r">{target}</td>
</tr>""")

    html = f"""<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
<title>拉新管控台</title><style>
:root{{--fg:#1a1a1a;--mute:#8a8a8a;--line:#ececea}}
body{{margin:0;padding:28px 32px;font:14px/1.55 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:var(--fg);background:#fff}}
h1{{font-size:18px;margin:0 0 4px}} .sub{{color:var(--mute);font-size:12px;margin-bottom:18px}}
.cards{{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}}
.card{{border:1px solid var(--line);border-radius:10px;padding:10px 14px;min-width:96px}}
.card b{{display:block;font-size:20px;font-weight:600}} .card span{{color:var(--mute);font-size:12px}}
table{{width:100%;border-collapse:collapse;font-size:13px}}
th{{text-align:left;font-weight:500;color:var(--mute);font-size:12px;padding:8px 10px;border-bottom:1px solid var(--line)}}
td{{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}}
.t{{font-weight:500}} .m{{color:var(--mute);font-size:11px;margin-top:2px}}
.b{{font-size:11px;border:1px solid;border-radius:99px;padding:1px 8px}}
.r{{color:var(--mute);font-size:12px}}
a{{color:#185FA5;text-decoration:none;word-break:break-all}}
</style></head><body>
<h1>拉新管控台</h1>
<div class="sub">生成于 {now()} · 规则 promo_rules.json · 数据 data/promo.json</div>
<div class="cards">
{''.join(f'<div class="card"><b>{v}</b><span>{k}</span></div>' for k, v in sorted(counts.items(), key=lambda x: -x[1]))}
</div>
<table><thead><tr><th>资源</th><th>状态</th><th>风险</th><th>源</th><th>拉新链接</th></tr></thead>
<tbody>{''.join(rows)}</tbody></table>
</body></html>"""
    DASH.write_text(html, encoding="utf-8")
    print(f"管控台 -> {DASH}（{len(items)} 条，已上线 {len(live)} 条）")
    return 0


def cmd_stats(args) -> int:
    state = load_json(STATE, {"items": {}})
    items = list(state.get("items", {}).values())
    by_status: dict[str, int] = {}
    by_level: dict[str, int] = {}
    for i in items:
        by_status[i["status"]] = by_status.get(i["status"], 0) + 1
        by_level[i["risk"]["level"]] = by_level.get(i["risk"]["level"], 0) + 1
    print("状态：", by_status)
    print("风险：", by_level)
    live = [i for i in items if i["status"] == "live"]
    print("已上线链接：")
    for i in live[:20]:
        s = i.get("share") or {}
        print(f"  {i['title'][:34]:<36}{s.get('url','')}{' 码' + s['pwd'] if s.get('pwd') else ''}")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("scan"); p.add_argument("--limit", type=int, default=50)
    p.add_argument("--type", default="", help="逗号分隔，覆盖 allow_types")
    p.add_argument("--allow-movie", action="store_true")
    p.add_argument("--skip-blocked", action="store_true")
    p.set_defaults(func=cmd_scan)

    p = sub.add_parser("list"); p.add_argument("--status", default=""); p.add_argument("--limit", type=int, default=60)
    p.set_defaults(func=cmd_list)

    p = sub.add_parser("approve"); p.add_argument("ids", nargs="+"); p.add_argument("--force", action="store_true")
    p.add_argument("--note", default=""); p.set_defaults(func=cmd_approve)

    p = sub.add_parser("reject"); p.add_argument("ids", nargs="+"); p.add_argument("--reason", default="")
    p.set_defaults(func=cmd_reject)

    p = sub.add_parser("run"); p.add_argument("--limit", type=int, default=0)
    p.add_argument("--live", action="store_true"); p.add_argument("--proxy", default=None)
    p.set_defaults(func=cmd_run)

    p = sub.add_parser("dashboard"); p.set_defaults(func=cmd_dashboard)
    p = sub.add_parser("stats"); p.set_defaults(func=cmd_stats)

    args = ap.parse_args()
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
