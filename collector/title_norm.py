#!/usr/bin/env python3
"""标题归一化：剥掉「更至 248 集」「4K」「国语中字」「夸克网盘」这类元数据，提 core_title。

目的：同一个资源被 10 个频道转发时，标题写法各不相同但 core_title 相同，可以聚成一条。
规则参考 tgmonitor 的 resource_identity/parser.py，只保留盘搜够用的部分。

    >>> parse_title("风与潮 (2025) [WEB-4K] [国语中字] [更至23集]")["core_title"]
    '风与潮'
"""
from __future__ import annotations

import hashlib
import html
import re

YEAR_RE = re.compile(r"(?<!\d)((?:19|20)\d{2})(?!\d)")

# 标题前缀：「名称：」「国剧：」「电影 」之类
PREFIX_RES = (
    re.compile(r"^\s*(?:资源标题|资源名称|标题|名称|课程名称|剧名|书名)\s*[:：]\s*"),
    re.compile(
        r"^\s*(?:国剧|国漫|国综|韩剧|日剧|日漫|美剧|英剧|欧美剧|动漫|动画|电影|剧集|"
        r"电视剧|综艺|真人秀|纪录片|短剧|课程|教程)\s*[:：]\s*"
    ),
    # 「国漫 吞噬星空」这种不带冒号、靠空格分隔的分类前缀
    re.compile(
        r"^\s*(?:国剧|国漫|国综|韩剧|日剧|日漫|美剧|英剧|欧美剧|动漫|动画|电影|剧集|"
        r"电视剧|综艺|真人秀|纪录片|短剧|课程|教程)\s+(?=\S)"
    ),
)

# 出现这些就该「砍尾巴」了（元数据从第一个命中位置截断）
META_CUT_RES = (
    re.compile(r"[（(\[【]\s*(?:19|20)\d{2}"),
    re.compile(r"[（(\[【]\s*(?:(?:更新|更至?)\s*)?(?:全|第)?\d{1,4}\s*[集期]"),
    re.compile(r"(?<!\d)(?:19|20)\d{2}(?=\s|$|[.·•+|｜/])"),
    re.compile(r"第\s*[零一二三四五六七八九十百两\d]+\s*季"),
    re.compile(r"\bSeason\s*\d+", re.I),
    re.compile(r"\bS\d{1,2}(?:E\d{1,4})?\b", re.I),
    re.compile(r"(?:更新至|更新到|更新中|更至)\s*\d{0,4}\s*[集期]?"),
    re.compile(r"更\s*\d{1,4}\s*[集期]"),
    re.compile(r"第\s*\d{1,4}\s*集"),
    re.compile(r"(?:^|[\s·•|｜/])全\s*\d{1,4}\s*[集季期]"),
    re.compile(r"(?:^|[\s·•|｜/])\d{1,4}\s*[集期](?:\s|$|[+&|｜])"),
    re.compile(
        r"(?:^|[\s·•|｜/+])(?:4K|8K|720P|1080P?|1440P|2160P?|HDR(?:10\+?)?|DV|HQ|"
        r"WEB(?:-DL|-Rip)?|BluRay|REMUX|HEVC|H\.?26[45]|60FPS|杜比|臻彩|高码|"
        r"无标|无水印|无台标|内封|外封|内嵌|外挂|简繁|中字|双语)",
        re.I,
    ),
    re.compile(r"(?:^|[\s·•|｜])(?:类型|主演|演员|导演|简介|描述)\s*[:：]"),
)
# 只删尾部的噪音
TRAILING_RES = (
    re.compile(r"\s*(?:夸克|百度|阿里|迅雷|UC|115|123|天翼|移动|蓝奏)网盘\s*$", re.I),
    re.compile(r"\s*(?:超清|高清|蓝光|原盘|合集|完整版|未删减)\s*$", re.I),
)
COMPLETE_RE = re.compile(r"(?:全集|全季|完结|超前完结|S\d{1,2}全|全\d{1,4}[集期])", re.I)
ONGOING_RE = re.compile(r"更新中", re.I)

SEASON_RES = (
    re.compile(r"\bSeason\s*([0-9]{1,2})\b", re.I),
    re.compile(r"(?<![A-Za-z0-9])S(?:eason\s*)?0*([1-9][0-9]?)(?=E|\b|全)", re.I),
    re.compile(r"第\s*([零一二三四五六七八九十百两\d]+)\s*季"),
)
EPISODE_RES = (
    re.compile(r"(?<![A-Za-z0-9])S\d{1,2}\s*E\d{1,4}\s*[-~至]\s*E?\s*0*(\d{1,4})", re.I),
    re.compile(r"第\s*\d{1,4}\s*[-~至]\s*(\d{1,4})\s*集"),
    re.compile(r"(?<![A-Za-z0-9])S\d{1,2}E0*(\d{1,4})(?!\d)", re.I),
    re.compile(r"(?<![A-Za-z0-9])EP?\s*0*(\d{1,4})(?!\d)", re.I),
    re.compile(r"更新(?:至|到)?\s*(?:EP|E|第)?\s*0*(\d{1,4})\s*[集期]?", re.I),
    re.compile(r"更至?\s*0*(\d{1,4})\s*[集期]", re.I),
    re.compile(r"第\s*0*(\d{1,4})\s*集"),
    re.compile(r"(?<!\d)0*(\d{1,4})\s*集"),
)
GENERIC_TITLES = {"资源", "资源标题", "标题", "名称", "未知", "无", "剧名", "课程", "合集", "更新"}
CJK_RE = re.compile(r"[\u4e00-\u9fff]")
CN_DIGITS = {"零": 0, "一": 1, "二": 2, "两": 2, "三": 3, "四": 4, "五": 5,
             "六": 6, "七": 7, "八": 8, "九": 9}
TRIM_CHARS = " -_|/\\,.，。:：;；!！?？[]【】()（）<>《》「」『』·•~——　"
# str.strip(TRIM_CHARS) 会把成对的右括号一起剥掉，
# 「2027《高考数学•一轮复习讲练测》」会被切成「2027《高考数学•一轮复习讲练测」。
# 括号类只在落单（右多于左）时才当噪音清理。
BARE_TRIM_CHARS = " -_|/\\,.，。:：;；!！?？·•~——　"
CLOSE_TO_OPEN = {
    ")": "(", "）": "（", "]": "[", "】": "【", ">": "<",
    "》": "《", "」": "「", "』": "『", "›": "‹", "〕": "〔",
}


def _trim_edges(text: str) -> str:
    text = text.strip(BARE_TRIM_CHARS)
    while text and text[-1] in CLOSE_TO_OPEN:
        close = text[-1]
        if text.count(CLOSE_TO_OPEN[close]) >= text.count(close):
            break  # 右括号数量不多于左括号，说明它是配对的，留着
        text = text[:-1].strip(BARE_TRIM_CHARS)
    return text


def _balance_brackets(text: str) -> str:
    """补齐频道作者少打的右括号。

    「上海交大附中 570道高中物理必刷经典题（解析版+原题版」这种原文就缺一半的，
    展示时括号悬空看着像渲染坏了；补上比剥掉更接近作者本意。
    """
    for close, open_ in CLOSE_TO_OPEN.items():
        diff = text.count(open_) - text.count(close)
        if diff > 0 and (open_ in "（(《<「『["):
            text = text + close * diff
    return text


def normalize(text: str) -> str:
    text = html.unescape(text or "")
    text = text.replace("\u200b", "").replace("\ufeff", "")
    # 频道文案爱在标题前挂表情（🗄📺🔥），对检索没意义
    text = re.sub(r"[\U0001F000-\U0001FAFF\u2600-\u27BF\uFE0F\u2B00-\u2BFF]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def chinese_number(value: str) -> int | None:
    if not value:
        return None
    if value.isdigit():
        return int(value)
    if value == "十":
        return 10
    if "百" in value:
        return None
    if "十" in value:
        head, _, tail = value.partition("十")
        tens = CN_DIGITS.get(head, 1) if head else 1
        ones = CN_DIGITS.get(tail, 0) if tail else 0
        return tens * 10 + ones
    return CN_DIGITS.get(value)


BRACKET_LABEL_RE = re.compile(r"^\s*[【\[（(]\s*([^】\]）)]{1,10})\s*[】\]）)]\s*")
LABEL_WORDS = {
    "国漫", "国剧", "国综", "韩剧", "日剧", "日漫", "美剧", "英剧", "动漫", "动画",
    "电影", "剧集", "电视剧", "综艺", "真人秀", "纪录片", "短剧", "课程", "教程",
    "4k", "8k", "高清", "超清", "蓝光", "原盘", "合集", "完结", "更新", "推荐", "热门",
}


def _strip_prefix(text: str) -> str:
    # 「【国漫】xxx」「【4K】xxx」这种分类/画质方括号前缀直接丢掉
    while True:
        m = BRACKET_LABEL_RE.match(text)
        if not m or m.group(1).strip().lower() not in LABEL_WORDS:
            break
        text = text[m.end():]
    for pattern in PREFIX_RES:
        text = pattern.sub("", text, count=1)
    return text.strip()


def _strip_wrappers(text: str) -> str:
    m = re.match(r"^[《〈「『]\s*(.+?)\s*[》〉」』]", text)
    return m.group(1) if m else text


def _cut_meta(text: str) -> str:
    indexes = [m.start() for pattern in META_CUT_RES for m in [pattern.search(text)] if m and m.start() > 0]
    return text[: min(indexes)].strip() if indexes else text


def parse_title(raw: str) -> dict:
    text = normalize(raw)
    working = _strip_prefix(text)
    working = _strip_wrappers(working)
    core = _cut_meta(working)
    for _ in range(3):
        before = core
        for pattern in TRAILING_RES:
            core = pattern.sub("", core)
        core = _trim_edges(core)
        if core == before:
            break
    # 落单的括号（如「【尚硅谷】xxx」剥掉前括号后剩下的」）当噪音清掉
    core = re.sub(r"[【】\[\]〔〕]", " ", core)
    core = _balance_brackets(normalize(core))

    year_m = YEAR_RE.search(text)
    year = int(year_m.group(1)) if year_m else None

    season = None
    for pattern in SEASON_RES:
        m = pattern.search(text)
        if m:
            season = chinese_number(m.group(1))
            if season:
                break

    episodes = []
    for pattern in EPISODE_RES:
        for value in pattern.findall(text):
            try:
                episodes.append(int(value))
            except (TypeError, ValueError):
                continue
    episode = max(episodes) if episodes else None

    complete = bool(COMPLETE_RE.search(text)) and not ONGOING_RE.search(text)
    usable = len(core) >= 2 and core not in GENERIC_TITLES and bool(CJK_RE.search(core) or len(core) >= 3)
    if not usable:
        core = normalize(_strip_prefix(text))[:60]

    return {
        "core_title": core,
        "year": year,
        "season": season,
        "episode": episode,
        "complete": complete,
        "group_key": group_key(core, year, season),
    }


def group_key(core: str, year: int | None, season: int | None) -> str:
    """同一部作品的不同集数/画质/字幕版本，落到同一个 group_key。

    年份刻意不进指纹：实测频道里的「(2025)」经常是搬运年份而非出品年份，
    同一部剧会被拆成两组（吞噬星空、仙逆都踩过）。季必须进，第二季是另一个资源。
    """
    signature = f"{re.sub(r'[^0-9a-z\u4e00-\u9fff]', '', core.lower())}|{season or ''}"
    return hashlib.sha1(signature.encode()).hexdigest()[:12]
