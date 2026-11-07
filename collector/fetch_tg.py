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
from concurrent.futures import ThreadPoolExecutor
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
    # 百度另一种分享入口：短链跳过去是 /share/init?surl=xxx，surl 就是去掉首字母 1 的分享 id
    ("baidu",    r"https?://pan\.baidu\.com/share/init\?[\w=&%\-]*surl=[\w\-~]+"),
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

# 需要「跟一次跳转」才知道目标在哪的短链服务。
# 有频道（如 wpzyk）整条消息全是 t.cn 微博短链，正文里看不到任何网盘域名，
# 不解析就完全抓不到。
SHORT_HOSTS = {
    "t.cn", "t.co", "dwz.cn", "url.cn", "suo.im", "sinaurl.cn",
    "bit.ly", "tinyurl.com", "reurl.cc", "s.id", "w.url.cn",
}
SHORT_CACHE = DATA_DIR / "short_cache.jsonl"

# 结构化消息字段（很多资源频道都是「名称：/描述：/链接：」这种模板）
TITLE_RE = re.compile(r"^[【\[（(]?\s*(?:名\s*称|标\s*题|资源名称|資源名稱|课程名称|书名|剧名|资源)\s*[】\]）)]?\s*[：:]\s*(.+)$")
DESC_RE = re.compile(r"^[【\[（(]?\s*(?:描\s*述|简\s*介|介\s*绍|资源介绍|内容简介)\s*[】\]）)]?\s*[：:]\s*(.*)$")
PROMO_RE = re.compile(r"频道\s*[：:]\s*@|@\w+\s*$|^[📢💻🎬🎮💃👨‍💻📱🔥✨🌟]")

# 资源分类（关键词表参考 netdisk-spider，去掉 ps/bd/dc 这类误伤严重的短词）
# 顺序 = 优先级。特征越强的类型越靠前，否则会被泛词抢走：
# 「PC游戏XXX 中文版 免安装」既有「中文版」又可能含「全集」，
# 放在 movie 之后就会被误判成影视。
#
# 为什么没有 image：写真/壁纸类有 450+ 条，但做成分类落地页等于主动放大这类内容，
# 平台风险大于流量收益。它们留在 other，不设入口。
#
# 这是 2026-11 在 23.5k 真实数据上校准过的结果：other 从 34.3% 降到 17.4%，
# 主要修的是「战上海 (1959)」这种没有"电影"字样、只靠年份和片名识别的老片。
RSC_KEYWORDS: dict[str, tuple[str, ...]] = {
    "game": ("游戏", "pc游戏", "switch", "steam", "ps4", "ps5", "xbox",
             "免安装", "整合版", "本体+", "升补", "模拟器", "解压即玩",
             "联机版", "掌机", "手机游戏"),
    "software": ("软件", "破解", "激活", "adobe", "office", "photoshop", "cad",
                 "去广告", "精简版", "修改版", "解锁版", "纯净版", "绿色版",
                 "内置", "安装包", "插件", "模块", "apk", "exe", "dmg"),
    "ebook": ("电子书", "pdf", "epub", "mobi", "azw3", "书籍", "图书",
              "教材", "小说", "漫画", "杂志", "读物", "全书", "百科", "册全",
              "作者：", "作者:"),
    "music": ("音乐", "专辑", "歌曲", "mp3", "flac", "wav", "无损", "原声", "ost",
              "演唱会", "钢琴", "吉他", "曲谱", "伴奏"),
    "course": ("教程", "课程", "学习", "教学", "培训", "入门", "进阶", "实战", "网课",
               "公开课", "编程", "python", "java", "前端", "后端", "人工智能", "机器学习",
               "训练营", "训练班", "必修", "体系课", "案例", "运营", "带货",
               "跨境电商", "干货", "私域", "变现",
               # 考试 / 教辅：考公考研频道的内容几乎全是这类，缺了全落 other
               "高考", "中考", "考研", "公考", "国考", "省考", "四六级", "雅思", "托福",
               "真题", "考点", "讲义", "押题", "冲刺", "模拟卷", "复习", "必刷", "题库",
               "背诵", "默写", "词汇", "试卷", "练习册", "教辅", "一轮", "二轮", "三轮",
               "笔记", "课件", "同步练习", "错题", "知识点"),
    "movie": ("电影", "剧集", "电视剧", "动漫", "动画", "纪录片", "综艺", "短剧",
              "年番", "番剧", "连载",
              "4k", "1080p", "2160p", "蓝光", "hdr", "remux", "杜比",
              "国漫", "美剧", "韩剧", "日剧", "英剧", "全集", "季全", "高清"),
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


BAIDU_INIT_RE = re.compile(r"[?&]surl=([\w\-~]+)", re.I)


def normalize_share_url(provider: str, url: str) -> str:
    """把各种入口形式收敛成一种可长期使用的地址。

    百度从短链跳过来常落在 `/share/init?surl=xxx&pwd=6666`，
    这不是标准分享页（用户打开还要过一次跳转），而 surl 就是分享 id 去掉首位 `1`。
    转成 `/s/1{surl}?pwd=xxx` 后，去重、巡检、前端展示都按同一套逻辑走。
    """
    if provider != "baidu" or "/share/init" not in url:
        return url
    m = BAIDU_INIT_RE.search(url)
    if not m:
        return url
    pwd = PWD_IN_URL.search(url)
    # surl 可能已经是完整的（老数据），已经带 1 前缀就别再加
    surl = m.group(1)
    sid = surl if surl.startswith("1") else "1" + surl
    return f"https://pan.baidu.com/s/{sid}" + (f"?pwd={pwd.group(1)}" if pwd else "")


class ShortLinkResolver:
    """短链 → 真实地址。

    有的频道（如 wpzyk 考公考研自习室）整条消息里没有一个网盘域名，
    全是 t.cn 微博短链，必须跟一次 302 才知道那是什么盘。

    缓存落盘到 data/short_cache.jsonl 并随 data 分支提交：同一个短链只解析一次，
    第二轮起整页 0 请求。短链不会失效后再指到另一个盘，所以缓存可以长期使用。

    网络策略：先直连（t.cn / 网盘都在国内），失败再用代理重试，
    这样墙内墙外的部署都能跑。
    """

    def __init__(self, direct_opener, proxy_opener=None, cache_path: Path = SHORT_CACHE,
                 workers: int = 8) -> None:
        self.direct = direct_opener
        self.proxy = proxy_opener
        self.cache_path = cache_path
        self.workers = workers
        self.cache: dict[str, str] = {}
        self._new: list[dict] = []
        self._load()

    def _load(self) -> None:
        if not self.cache_path.exists():
            return
        for line in self.cache_path.read_text(encoding="utf-8", errors="replace").splitlines():
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
            except json.JSONDecodeError:
                continue
            short, real = rec.get("short"), rec.get("real")
            if short and real:
                self.cache[short] = real

    def _save(self) -> None:
        if not self._new:
            return
        self.cache_path.parent.mkdir(parents=True, exist_ok=True)
        with self.cache_path.open("a", encoding="utf-8", newline="\n") as fh:
            for rec in self._new:
                fh.write(json.dumps(rec, ensure_ascii=False) + "\n")
        self._new = []

    @staticmethod
    def is_short(url: str) -> bool:
        try:
            host = (urllib.parse.urlparse(url).hostname or "").lower()
        except ValueError:
            return False
        return host in SHORT_HOSTS

    def collect(self, text: str) -> list[str]:
        """抽出文本里所有待解析的短链（保留原样，位置不动）。"""
        out = []
        for raw in ANY_URL_RE.findall(text):
            url = raw.rstrip("。，、；;,.）)】]\"'<>")
            if self.is_short(url):
                out.append(url)
        return out

    def _open(self, opener, url: str) -> str | None:
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        with opener.open(req, timeout=15) as resp:
            final = resp.geturl()
            # 没跳转说明短链失效或落到说明页
            if not final or final == url:
                return None
            return final

    def _resolve_one(self, url: str) -> str | None:
        try:
            real = self._open(self.direct, url)
        except Exception:
            real = None
        if real is None and self.proxy is not None:
            try:
                real = self._open(self.proxy, url)
            except Exception:
                real = None
        if real and not self.is_short(real):
            return real
        return None

    def resolve_many(self, urls: list[str]) -> dict[str, str]:
        uniq: list[str] = []
        for u in urls:
            if u not in uniq:
                uniq.append(u)
        todo = [u for u in uniq if u not in self.cache]
        if todo:
            with ThreadPoolExecutor(max_workers=self.workers) as pool:
                results = list(pool.map(self._resolve_one, todo))
            for url, real in zip(todo, results):
                if real:
                    self.cache[url] = real
                    self._new.append({"short": url, "real": real,
                                      "at": datetime.now(timezone.utc).isoformat(timespec="seconds")})
            self._save()
        return {u: self.cache[u] for u in uniq if u in self.cache}

    @staticmethod
    def substitute(text: str, mapping: dict[str, str]) -> str:
        """把正文里的短链替换成真实地址，位置不变——
        这样「资料名 + 链接」的相邻关系还在，标题才抽得对。"""
        if not mapping:
            return text
        def repl(m: re.Match) -> str:
            url = m.group(0).rstrip("。，、；;,.）)】]\"'<>")
            return mapping.get(url, url)
        return ANY_URL_RE.sub(repl, text)


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
    matches: list[tuple[int, str, str, str]] = []
    seen: set[str] = set()
    offset = 0
    for line in text.split("\n"):
        # 同一行里「资料名 http://短链」是最常见的写法，行内文字往往比整条消息更适合当标题
        context = ANY_URL_RE.sub("", line)
        context = CODE_RE.sub("", context).strip(" 　:：-|，,。·[]()（）　\t")
        for variant in iter_link_variants(line):
            for provider, rx in LINK_RES:
                for m in rx.finditer(variant):
                    url = normalize_share_url(provider, clean_url(m.group(0)))
                    key = link_key(provider, url)
                    if key in seen:
                        continue
                    seen.add(key)
                    matches.append((offset + m.start(), provider, url, context))
        offset += len(line) + 1
    matches.sort(key=lambda x: x[0])

    bounds = [pos for pos, _, _, _ in matches] + [10 ** 9]
    found: list[dict] = []
    for i, (pos, provider, url, context) in enumerate(matches):
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
        found.append({"provider": provider, "share_url": url, "pwd": pwd, "context": context})
    return found


# 「百度 | 夸克 | 阿里」这种只有平台名的行，不能拿来当标题
PLATFORM_LINE_RE = re.compile(
    r"^[\s🔽⬇👇👉➡↓·]*"
    r"(?:(?:百度|夸克|阿里|阿里云|迅雷|UC|123盘|123云盘|天翼|移动|115|蓝奏|谷歌|网盘|下载|链接|地址|点击)"
    r"[\s|｜/、,，+&和及]*)+$",
    re.I,
)


def build_title(text: str, url: str, context: str = "") -> str:
    """优先取「名称／标题：xxx」这种结构化字段；其次用链接所在行的文字。

    一行一个资源的频道（考试资料、书单）如果只用整条消息的前两行当标题，
    一条消息里十条资源会全叫同一个名字，检索时根本分不开。
    """
    lines = [ln.strip() for ln in text.split("\n")]
    for ln in lines:
        m = TITLE_RE.match(ln)
        if m:
            return m.group(1).strip(" 　:：-|，,。")[:120]

    ctx = (context or "").strip(" 　:：-|，,。·")
    if len(ctx) >= 4 and not PLATFORM_LINE_RE.match(ctx) and "http" not in ctx.lower():
        return ctx[:120]

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


YEAR_IN_TITLE_RE = re.compile(r"[（(](19\d{2}|20\d{2})[）)]")


def classify_type(title: str) -> str:
    """按标题关键词粗分资源类型，供搜索页做筛选。

    顺序匹配（RSC_KEYWORDS 的键序即优先级），最后用「标题里的年份」兜底：
    「战上海 (1959)」「正午 High Noon (1952)」这类老片标题里没有"电影"二字，
    但 `(19xx)` 括号基本只出现在影视作品的发布信息里，判成 movie 的准确率很高。
    """
    t = (title or "").lower()
    for name, kws in RSC_KEYWORDS.items():
        if any(k in t for k in kws):
            return name
    if YEAR_IN_TITLE_RE.search(title or ""):
        return "movie"
    # 书名号包裹但一个类型词都没有：基本都是书（《超越智商：为什么聪明人也会做蠢事》）
    if re.search(r"《[^》]{2,40}》", title or ""):
        return "ebook"
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
    proxy = resolve_proxy(args.proxy)
    opener = build_opener(proxy)
    # 短链解析走直连优先：t.cn 和网盘都在国内，绕代理反而慢
    resolver = ShortLinkResolver(build_opener(None), opener)
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

            # 先把本页所有短链解析成真实地址（带落盘缓存 + 并发），再替换回正文。
            # 替换而不是另建映射，是为了保住「资料名 + 链接」的相邻关系。
            pending: list[str] = []
            for msg in msgs:
                pending.extend(resolver.collect(msg["text"]))
            mapping = resolver.resolve_many(pending) if pending else {}
            if pending:
                print(f"  · 短链 {len(set(pending))} 个，解析成功 {len(mapping)} 个")

            for msg in msgs:
                if mapping:
                    msg["text"] = resolver.substitute(msg["text"], mapping)
                desc = build_desc(msg["text"])
                for link in extract_links(msg["text"]):
                    title = build_title(msg["text"], link["share_url"], link.get("context", ""))
                    parsed = parse_title(title)
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
