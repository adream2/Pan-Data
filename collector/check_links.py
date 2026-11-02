#!/usr/bin/env python3
"""网盘分享链接巡检：判断链接是否还有效，结果回写 links.jsonl。

纯标准库。策略参考 tgmonitor 的分级判定：
    valid / invalid / requires_code / rate_limited / uncertain / unsupported
只把「确定失效」标成 invalid，网络抖动、限流、要提取码一律不算死链。

用法：
    python collector/check_links.py --limit 50            # 先抽 50 条试水
    python collector/check_links.py --provider quark      # 只查夸克
    python collector/check_links.py --recheck-days 7      # 跳过 7 天内查过的
"""
from __future__ import annotations

import argparse
import json
import random
import re
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT.parent / "data" / "links.jsonl"

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36")

VALID, INVALID, REQUIRES_CODE = "valid", "invalid", "requires_code"
RATE_LIMITED, UNCERTAIN, UNSUPPORTED = "rate_limited", "uncertain", "unsupported"

# 夸克 token 接口的错误码 → 结论（message 里出现 失效/取消/不存在 等也算死链）
QUARK_INVALID_CODES = {
    41004: "分享已取消",
    41006: "分享不存在",
    41011: "分享地址已失效",
    41031: "分享者封禁，链接查看受限",
}
INVALID_MARKERS = (
    "失效", "取消", "不存在", "删除", "违规", "封禁", "查看受限",
    "已过期", "过期", "not found", "not exist", "expired", "removed", "deleted", "banned",
)

QUARK_ID_RE = re.compile(r"/s/([0-9A-Za-z]+)")


def opener_for(proxy: str | None):
    handler = urllib.request.ProxyHandler({"http": proxy, "https": proxy} if proxy else {})
    op = urllib.request.build_opener(handler)
    op.addheaders = [("User-Agent", UA), ("Accept", "*/*")]
    return op


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _post_json(op, url: str, payload: dict, extra_headers: dict | None = None) -> tuple[int, dict]:
    body = json.dumps(payload).encode()
    headers = {"content-type": "application/json"}
    headers.update(extra_headers or {})
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    try:
        with op.open(req, timeout=20) as resp:
            raw = resp.read()
            return resp.status, json.loads(raw or b"{}")
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        try:
            return exc.code, json.loads(raw or b"{}")
        except json.JSONDecodeError:
            return exc.code, {}


def check_quark(row: dict, op) -> dict:
    m = QUARK_ID_RE.search(row["share_url"])
    if not m:
        return {"status": UNCERTAIN, "reason": "无法解析夸克分享 ID", "http": None}
    pwd_id = m.group(1)
    status, payload = _post_json(
        op,
        "https://drive-h.quark.cn/1/clouddrive/share/sharepage/token",
        {"pwd_id": pwd_id, "passcode": row.get("pwd") or "",
         "support_visit_limit_private_share": True},
        {"origin": "https://pan.quark.cn", "referer": "https://pan.quark.cn/"},
    )
    code = payload.get("code")
    message = str(payload.get("message") or "")
    if code in QUARK_INVALID_CODES:
        return {"status": INVALID, "reason": QUARK_INVALID_CODES[code], "http": status, "quark_code": code}
    if code != 0:
        lowered = message.lower()
        if any(mk in message or mk in lowered for mk in INVALID_MARKERS):
            return {"status": INVALID, "reason": message or "分享已失效", "http": status, "quark_code": code}
        if status == 429:
            return {"status": RATE_LIMITED, "reason": "限流", "http": status, "quark_code": code}
        if "提取码" in message or "密码" in message:
            return {"status": REQUIRES_CODE, "reason": "需要提取码", "http": status, "quark_code": code}
        return {"status": UNCERTAIN, "reason": message or "未知响应", "http": status, "quark_code": code}

    data = payload.get("data") or {}
    expires_ms = data.get("expired_at")
    out = {
        "status": VALID,
        "reason": "链接有效",
        "http": status,
        "share_title": data.get("title"),
        "file_num": data.get("file_num"),
        "expires_at": (datetime.fromtimestamp(expires_ms / 1000, timezone.utc).isoformat(timespec="seconds")
                       if isinstance(expires_ms, (int, float)) and expires_ms > 0 else None),
    }
    # 4102416000000 ≈ 2100-01-01，即「永久有效」
    if expires_ms and expires_ms < time.time() * 1000:
        out["status"] = INVALID
        out["reason"] = "分享已过期"
    return out


def check_http(row: dict, op) -> dict:
    """通用探测：只看 HTTP 状态，属于弱证据，只对 404/410 敢判死。"""
    req = urllib.request.Request(row["share_url"], headers={"User-Agent": UA})
    try:
        with op.open(req, timeout=20) as resp:
            code = resp.status
            resp.read(2048)
    except urllib.error.HTTPError as exc:
        code = exc.code
    except Exception as exc:
        return {"status": UNCERTAIN, "reason": f"{type(exc).__name__}", "http": None, "weak": True}
    if code in (404, 410):
        return {"status": INVALID, "reason": f"HTTP {code}", "http": code, "weak": True}
    if code == 429:
        return {"status": RATE_LIMITED, "reason": "限流", "http": code, "weak": True}
    if code >= 500:
        return {"status": UNCERTAIN, "reason": f"HTTP {code}", "http": code, "weak": True}
    return {"status": VALID, "reason": f"HTTP {code}", "http": code, "weak": True}


CHECKERS = {"quark": check_quark}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", default=str(DATA))
    ap.add_argument("--provider", default="", help="只查某个平台，如 quark")
    ap.add_argument("--limit", type=int, default=0, help="最多查多少条")
    ap.add_argument("--recheck-days", type=float, default=0, help="跳过 N 天内查过的")
    ap.add_argument("--delay", type=float, default=0.8, help="每次请求间隔（秒）")
    ap.add_argument("--proxy", default=None)
    args = ap.parse_args()

    path = Path(args.file)
    rows = [json.loads(x) for x in path.read_text(encoding="utf-8").splitlines() if x.strip()]
    if not rows:
        print("没有数据", file=sys.stderr)
        return 1

    cutoff = 0.0
    if args.recheck_days > 0:
        cutoff = time.time() - args.recheck_days * 86400

    targets = []
    for r in rows:
        if args.provider and r["provider"] != args.provider:
            continue
        if cutoff and r.get("checked_ts") and r["checked_ts"] > cutoff:
            continue
        targets.append(r)
    if args.limit:
        targets = targets[: args.limit]

    if not targets:
        print("没有需要巡检的条目（可加 --recheck-days 0 强制全查）")
        return 0

    proxy = args.proxy or None
    op = opener_for(proxy)
    stats: dict[str, int] = {}
    t0 = time.time()

    for i, row in enumerate(targets, 1):
        checker = CHECKERS.get(row["provider"], check_http)
        try:
            res = checker(row, op)
        except Exception as exc:
            res = {"status": UNCERTAIN, "reason": f"{type(exc).__name__}: {str(exc)[:50]}", "http": None}
        row.update(res)
        # 网盘官方标题通常比频道文案更全（带作者/机构），优先用它
        official = (res.get("share_title") or "").strip()
        row["title_best"] = official or (row.get("title") or "")
        row["checked_at"] = now_iso()
        row["checked_ts"] = time.time()
        stats[res["status"]] = stats.get(res["status"], 0) + 1
        if i % 20 == 0 or i == len(targets):
            mark = {"valid": "✓", "invalid": "✗", "uncertain": "?"}.get(res["status"], "·")
            print(f"  {i}/{len(targets)} {mark} {res['status']:<13} {res.get('reason','')[:24]}")
        time.sleep(args.delay + random.random() * 0.3)

    # 巡检结果会改变「谁是当前版本」，所以要重新收敛一次
    try:
        import grouping
        grouping.apply(rows)
    except Exception as exc:  # 单跑巡检时也不该因为分组失败而丢结果
        print(f"! 分组更新失败：{exc}", file=sys.stderr)

    path.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n", encoding="utf-8")
    span = time.time() - t0
    print(f"\n完成 {len(targets)} 条，耗时 {span:.0f}s")
    print("结果：" + "，".join(f"{k} {v}" for k, v in sorted(stats.items(), key=lambda x: -x[1])))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
