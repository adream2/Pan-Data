#!/usr/bin/env python3
"""采集 Telegram 公开频道的网盘分享链接（网页预览版，纯标准库，无需 API Key / 无需登录）。

原理：t.me/s/<频道名> 以静态 HTML 返回频道最近的公开消息，分页用 ?before=<消息ID>。
零第三方依赖，GitHub Actions 里连 pip install 都省了。

用法：
    python collector/fetch_tg.py --pages 20
    python collector/fetch_tg.py --channels quark_share --pages 3
    python collector/fetch_tg.py --proxy http://127.0.0.1:7890 --pages 1

环境变量：
    TG_PROXY   代理，如 http://127.0.0.1:7890；填 auto 则自动探测本机常见端口
    TG_UA      自定义 User-Agent
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import random
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path

from title_norm import parse_title
import grouping

ROOT = Path(__file__).resolve().parent
DATA_DIR = ROOT.parent / "data"
OUT_FILE = DATA_DIR / "links.jsonl"

UA = os.getenv("TG_UA") or (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
AUTO_PROXY_CANDIDATES = [
    "http://127.0.0.1:7890",
    "http://127.0.0.1:10808",
    "http://127.0.0.1:10809",
    "http://127.0.0.1:8080",
]

# 网盘链接规则：(provider, 正则)。新增平台只改这里。
# 网盘链接规则：(provider, 正则)。域名别名参考 tgmonitor 的 platforms.py，镜像站一并覆盖。
PROVIDERS: list[tuple[str, str]] = [
    ("baidu",    r"https?://pan\.baidu\.com/s/[\w\-~]+"),
    ("aliyun",   r"https?://(?:www\.)?(?:aliyundrive|alipan)\.com/s/[\w]+"),
    ("quark",    r"https?://(?:pan\.quark\.cn|pan\.qoark\.cn)/s/[\w\-]+"),
    ("uc",       r"https?://(?:drive|yun)\.uc\.cn/s/[\w\-]+"),
    ("xunlei",   r"https?://pan\.xunlei\.com/s/[\w\-]+"),
    ("123pan",   r"https?://(?:www\.)?(?:123pan\.(?:com|cn)|123684|123685|123912|123592|123865)\.com/s/[\w\-]+"),
    ("189",      r"https?://(?:h5\.)?cloud\.189\.cn/t/[\w\-]+"),
    ("139",      r"https?://(?:yun|caiyun)\.139\.com/(?:m/i|w/i|web/share)?[?\w=&/\-]*"),
    ("115",      r"https?://(?:115\.com|115cdn\.com|anxia\.com)/s/[\w\-]+"),
    ("lanzou",   r"https?://[\w\-]+\.lanzou[a-z]?\.com/[\w\-]+"),
    ("onedrive", r"https?://1drv\.ms/[a-z]/[\w\-]+"),
    ("mega",     r"https?://mega\.nz/(?:file|folder)/[\w\-]+"),
    ("gdrive",   r"https?://drive\.google\.com/(?:file/d/|drive/folders/)[\w\-]+"),
]
URL_TAIL = r"(?:[?&#][\w=&%\-]+)*"
LINK_RES = [(p, re.compile(rx + URL_TAIL, re.I)) for p, rx in PROVIDERS]

CODE_RE = re.compile(
    r"(?:提取码|提取碼|访问码|訪問碼|密码|密碼|口令|code)\s*[:：]?\s*([0-9a-zA-Z]{4,8})",
    re.I,
)
PWD_IN_URL = re.compile(r"[?&]pwd=([0-9a-zA-Z]{4,8})", re.I)
ANY_URL_RE = re.compile(r"https?://[^\s\"'<>]+", re.I)

# 结构化消息字段（很多资源频道都是「名称：/描述：/链接：」这种模板）
TITLE_RE = re.compile(r"^[【\[（(]?\s*(?:名\s*称|标\s*题|资源名称|資源名稱|课程名称|书名|剧名|资源)\s*[】\]）)]?\s*[：:]\s*(.+)$")
DESC_RE = re.compile(r"^[【\[（(]?\s*(?:描\s*述|简\s*介|介\s*绍|资源介绍|内容简介)\s*[】\]）)]?\s*[：:]\s*(.*)$")
PROMO_RE = re.compile(r"频道\s*[：:]\s*@|@\w+\s*$|^[📢💻🎬🎮💃👨‍💻📱🔥✨🌟]")

# 资源分类（关键词表参考 netdisk-spider，去掉 ps/bd/dc 这类误伤严重的短词）
RSC_KEYWORDS: dict[str, tuple[str, ...]] = {
    "movie": ("电影", "剧集", "电视剧", "动漫", "动画", "纪录片", "综艺", "短剧",
              "4k", "1080p", "2160p", "蓝光", "hdr", "remux", "杜比"),
    "music": ("音乐", "专辑", "歌曲", "mp3", "flac", "wav", "无损", "原声", "ost",
              "演唱会", "钢琴", "吉他", "曲谱"),
    "ebook": ("电子书", "pdf", "epub", "书籍", "图书", "教材", "小说", "漫画", "杂志"),
    "software": ("软件", "破解", "激活", "adobe", "office", "photoshop", "cad", "插件", "安装包"),
    "course": ("教程", "课程", "学习", "教学", "培训", "入门", "进阶", "实战", "网课",
               "公开课", "编程", "python", "java", "前端", "后端", "人工智能", "机器学习"),
}


# ---------------------------------------------------------------- 网络

def resolve_proxy(arg: str | None) -> str | None:
    raw = (arg or os.getenv("TG_PROXY") or "").strip()
    if raw.lower() == "auto":
        for cand in AUTO_PROXY_CANDIDATES:
            try:
                build_opener(cand).open("https://t.me/s/telegram", timeout=6).read(2048)
                print(f"· 代理可用：{cand}", file=sys.stderr)
                return cand
            except Exception:
                continue
        print("· 未探测到可用代理，走直连", file=sys.stderr)
        return None
    return raw or None


def build_opener(proxy: str | None) -> urllib.request.OpenerDirector:
    handler = urllib.request.ProxyHandler(
        {"http": proxy, "https": proxy} if proxy else {}
    )
    opener = urllib.request.build_opener(handler)
    opener.addheaders = [
        ("User-Agent", UA),
        ("Accept", "text/html,application/xhtml+xml"),
        ("Accept-Language", "zh-CN,zh;q=0.9,en;q=0.8"),
    ]
    return opener


def fetch_page(opener, channel: str, before: int | None = None) -> str:
    url = f"https://t.me/s/{channel}"
    if before:
        url += f"?before={before}"
    for attempt in range(4):
        try:
            with opener.open(url, timeout=30) as resp:
                return resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as exc:
            if exc.code == 429:
                wait = int(exc.headers.get("Retry-After") or 15) + random.randint(1, 5)
                print(f"  ! 429 限流，等待 {wait}s", file=sys.stderr)
                time.sleep(wait)
                continue
            print(f"  ! HTTP {exc.code} {url}", file=sys.stderr)
            if exc.code in (403, 404):
                return ""
        except Exception as exc:
            wait = 2 ** attempt + random.random()
            print(f"  ! {type(exc).__name__} {str(exc)[:60]}，{wait:.1f}s 后重试", file=sys.stderr)
            time.sleep(wait)
    return ""


# ---------------------------------------------------------------- 解析

class TgChannelParser(HTMLParser):
    """按 div 层级切消息，避免 br/img/svg 这类空标签把嵌套栈搞乱。"""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.messages: list[dict] = []
        self._div = 0
        self._msg: dict | None = None
        self._msg_depth = 0
        self._in_text = False
        self._text_depth = 0
        self._buf: list[str] = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "div":
            self._div += 1
            cls = a.get("class") or ""
            if a.get("data-post"):
                self._flush_msg()
                self._msg = {"post": a["data-post"], "text": "", "hrefs": [], "posted_at": None}
                self._msg_depth = self._div
            elif self._msg is not None and "tgme_widget_message_text" in cls:
                self._in_text = True
                self._text_depth = self._div
                self._buf = []
            return
        if self._msg is None:
            return
        if tag == "br" and self._in_text:
            self._buf.append("\n")
        elif tag == "a":
            href = a.get("href") or ""
            if href.startswith("http") and "t.me/" not in href:
                self._msg["hrefs"].append(href)
        elif tag == "time" and a.get("datetime") and not self._msg["posted_at"]:
            self._msg["posted_at"] = a["datetime"]

    def handle_endtag(self, tag):
        if tag != "div":
            return
        if self._in_text and self._div == self._text_depth:
            self._in_text = False
            if self._msg is not None:
                self._msg["text"] = "".join(self._buf).strip()
        if self._msg is not None and self._div == self._msg_depth:
            self._flush_msg()
        self._div -= 1

    def handle_data(self, data):
        if self._in_text:
            self._buf.append(data)

    def _flush_msg(self):
        if self._msg is not None:
            self.messages.append(self._msg)
            self._msg = None
        self._in_text = False

    def close(self):
        super().close()
        self._flush_msg()


def parse_messages(html: str, channel: str) -> list[dict]:
    p = TgChannelParser()
    p.feed(html)
    p.close()
    out = []
    for m in p.messages:
        post = m.get("post") or ""
        try:
            post_id = int(post.split("/")[-1])
        except ValueError:
            continue
        out.append({
            "channel": channel,
            "post_id": post_id,
            "url": f"https://t.me/{post}",
            "posted_at": m["posted_at"],
            # 正文 + 富文本里的真实 href（正文常把域名缩写）
            "text": "\n".join(x for x in [m["text"], *m["hrefs"]] if x),
        })
    return out


# ---------------------------------------------------------------- 抽取

def clean_url(url: str) -> str:
    url = url.rstrip("。，、；;,.）)】]\"'<>")
    m = PWD_IN_URL.search(url)
    pwd = m.group(1) if m else None
    base = re.split(r"[?&]", url, maxsplit=1)[0]
    return f"{base}?pwd={pwd}" if pwd else base


def link_key(provider: str, url: str) -> str:
    path = re.sub(r"^https?://[^/]+", "", url)
    path = re.split(r"[?#]", path)[0]
    return hashlib.sha1(f"{provider}:{path}".encode()).hexdigest()[:16]


def norm_channel(raw: str) -> str:
    # 支持行尾注释：`freekecheng  # 高产，被提到 120 次`
    raw = re.split(r"[#\s]", raw.strip(), maxsplit=1)[0]
    if not raw or raw.startswith("#"):
        return ""
    raw = re.sub(r"^https?://t\.me/", "", raw)
    raw = re.sub(r"^s/", "", raw)
    raw = raw.strip("/@").split("/")[0]
    return raw.strip("/@")


def nearest_code(codes: list[tuple[int, str]], pos: int, limit: int = 200) -> str | None:
    """提取码按「字符距离最近」匹配，比按行匹配更能应付一行多链接。

    参照 netdisk-spider 的做法：只认 200 字符以内的码，避免把上一条资源的码安到这条上。
    """
    best, best_dist = None, limit + 1
    for code_pos, code in codes:
        dist = abs(code_pos - pos)
        if dist < best_dist:
            best, best_dist = code, dist
    return best if best_dist <= limit else None


META_PAIR_RE = re.compile(r"(\w+)\s*=\s*(\d+)")


def channel_meta(raw: str) -> dict:
    """解析行尾注释里的参数，如 `leoziyuan  # 高产 deep=2000`。"""
    _, _, comment = raw.partition("#")
    return {k: int(v) for k, v in META_PAIR_RE.findall(comment)}


def load_channel_config(path) -> list[tuple[str, dict]]:
    """读频道清单，返回 [(频道名, {deep:2000})]。"""
    out: list[tuple[str, dict]] = []
    for line in Path(path).read_text(encoding="utf-8").splitlines():
        name = norm_channel(line)
        if name:
            out.append((name, channel_meta(line)))
    return out


def iter_link_variants(line: str):
    """一行文本可能把真链藏在 URL 编码或 ?url= 之类的跳转参数里，展开后再匹配。"""
    yield line
    decoded = urllib.parse.unquote(line)
    if decoded != line:
        yield decoded
    for raw in ANY_URL_RE.findall(line):
        query = urllib.parse.urlparse(raw).query
        if not query:
            continue
        for values in urllib.parse.parse_qs(query).values():
            for value in values:
                if value.startswith("http"):
                    yield urllib.parse.unquote(value)


def extract_links(text: str) -> list[dict]:
    codes = [(m.start(), m.group(1)) for m in CODE_RE.finditer(text)]

    # 先收集所有链接及其绝对位置，再按「区间」分配提取码
    matches: list[tuple[int, str, str]] = []
    seen: set[str] = set()
    offset = 0
    for line in text.split("\n"):
        for variant in iter_link_variants(line):
            for provider, rx in LINK_RES:
                for m in rx.finditer(variant):
                    url = clean_url(m.group(0))
                    key = link_key(provider, url)
                    if key in seen:
                        continue
                    seen.add(key)
                    matches.append((offset + m.start(), provider, url))
        offset += len(line) + 1
    matches.sort()

    bounds = [pos for pos, _, _ in matches] + [10 ** 9]
    found: list[dict] = []
    for i, (pos, provider, url) in enumerate(matches):
        pwd_in_url = PWD_IN_URL.search(url)
        if pwd_in_url:
            pwd = pwd_in_url.group(1)
        else:
            # 优先取「本条链接之后、下一条链接之前」的码，其次回头看前一条之后
            pwd = next((c for p, c in codes if pos <= p < bounds[i + 1]), None)
            if pwd is None and i > 0:
                back = [c for p, c in codes if bounds[i - 1] <= p < pos]
                pwd = back[-1] if back else None
            if pwd is None:
                pwd = nearest_code(codes, pos)
        found.append({"provider": provider, "share_url": url, "pwd": pwd})
    return found


def build_title(text: str, url: str) -> str:
    """优先取「名称／标题：xxx」这种结构化字段，否则退回首行。"""
    lines = [ln.strip() for ln in text.split("\n")]
    for ln in lines:
        m = TITLE_RE.match(ln)
        if m:
            return m.group(1).strip(" 　:：-|，,。")[:120]
    picked: list[str] = []
    for ln in lines:
        if not ln or "http" in ln.lower() or PROMO_RE.search(ln):
            continue
        ln = CODE_RE.sub("", ln).strip(" :：-|，,。·[]()（）")
        if ln and len(ln) >= 2:
            picked.append(ln)
        if len(picked) >= 2:
            break
    return (" / ".join(picked) or url)[:120]


def classify_type(title: str) -> str:
    """按标题关键词粗分资源类型，供搜索页做筛选。"""
    t = (title or "").lower()
    for name, kws in RSC_KEYWORDS.items():
        if any(k in t for k in kws):
            return name
    return "other"


def build_desc(text: str) -> str | None:
    """抽「描述／简介」段，供搜索用；没有就退回正文里最长的一段。"""
    lines = [ln.strip() for ln in text.split("\n") if ln.strip()]
    for i, ln in enumerate(lines):
        m = DESC_RE.match(ln)
        if m:
            body = m.group(1).strip()
            if not body and i + 1 < len(lines):
                body = lines[i + 1]
            return body[:300] or None
    body = " ".join(ln for ln in lines if "http" not in ln.lower() and not PROMO_RE.search(ln))
    return body[:300] or None


def load_seen(path: Path) -> tuple[set[str], list[dict]]:
    if not path.exists():
        return set(), []
    rows = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return {r["id"] for r in rows if "id" in r}, rows


# ---------------------------------------------------------------- 主流程

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pages", type=int, default=1, help="每个频道回溯页数（约 20 条/页）")
    ap.add_argument("--channels", default="", help="逗号分隔，覆盖 channels.txt")
    ap.add_argument("--proxy", default=None, help="http://ip:port，或 auto")
    ap.add_argument("--sleep", type=float, default=1.2, help="请求间隔下限（秒）")
    ap.add_argument("--out", default=str(OUT_FILE), help="输出 jsonl 路径")
    args = ap.parse_args()

    if args.channels:
        raw_channels = args.channels.split(",")
    else:
        cfg = ROOT / "channels.txt"
        raw_channels = cfg.read_text(encoding="utf-8").splitlines() if cfg.exists() else []
    channels = [c for c in (norm_channel(x) for x in raw_channels) if c]
    if not channels:
        print("没有配置频道，请编辑 collector/channels.txt 或用 --channels", file=sys.stderr)
        return 1

    out_file = Path(args.out)
    out_file.parent.mkdir(parents=True, exist_ok=True)
    seen, rows = load_seen(out_file)
    opener = build_opener(resolve_proxy(args.proxy))
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")

    added = 0
    fetched_msgs = 0
    for ch in channels:
        before = None
        ch_new = 0
        print(f"→ {ch}")
        for page in range(1, args.pages + 1):
            html = fetch_page(opener, ch, before)
            if not html:
                break
            msgs = parse_messages(html, ch)
            if not msgs:
                print(f"  ! 第 {page} 页无消息（私有频道 / 限制网页预览 / 名称拼错）")
                break
            fetched_msgs += len(msgs)
            next_before = min(m["post_id"] for m in msgs)
            for msg in msgs:
                title = build_title(msg["text"], "")
                desc = build_desc(msg["text"])
                parsed = parse_title(title)
                for link in extract_links(msg["text"]):
                    item_id = f'{link["provider"]}::{link_key(link["provider"], link["share_url"])}'
                    if item_id in seen:
                        continue
                    seen.add(item_id)
                    rows.append({
                        "id": item_id,
                        "provider": link["provider"],
                        "share_url": link["share_url"],
                        "pwd": link["pwd"],
                        "title": title or link["share_url"],
                        "desc": desc,
                        "rtype": classify_type(title),
                        "core_title": parsed["core_title"],
                        "year": parsed["year"],
                        "season": parsed["season"],
                        "episode": parsed["episode"],
                        "complete": parsed["complete"],
                        "group_key": parsed["group_key"],
                        "channel": msg["channel"],
                        "post_url": msg["url"],
                        "posted_at": msg["posted_at"],
                        "collected_at": now,
                    })
                    ch_new += 1
                    added += 1
            print(f"  · 第 {page:>2} 页 {len(msgs):>2} 条消息，before={next_before}，新增 {ch_new}")
            if next_before == before or len(msgs) < 10:
                print("  · 已到频道底部")
                break
            before = next_before
            time.sleep(args.sleep + random.random())

    rows.sort(key=lambda r: r.get("posted_at") or "", reverse=True)
    group_stats = grouping.apply(rows)
    tmp = out_file.with_suffix(".tmp")
    with tmp.open("w", encoding="utf-8", newline="\n") as fh:
        for r in rows:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")
    tmp.replace(out_file)

    by_provider: dict[str, int] = {}
    for r in rows:
        by_provider[r["provider"]] = by_provider.get(r["provider"], 0) + 1
    print(f"\n完成：扫 {fetched_msgs} 条消息，新增 {added}，库内共 {len(rows)} 条 -> {out_file}")
    print(f"收敛：{grouping.summary(rows)}")
    if by_provider:
        print("分布：" + "，".join(f"{k} {v}" for k, v in sorted(by_provider.items(), key=lambda x: -x[1])))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
