#!/usr/bin/env python3
"""把内部明细 data/links.jsonl 按月切块 + gzip，绕开 GitHub 单文件 100MB 硬限。

为什么不用明文单文件进 git：
- 单文件只增不减，迟早撞 100MB，push 会被 pre-receive hook 直接拒（GH001）
- 每次发布都整树重写，等于每次把整份明细重新上一次网，仓库体积和带宽双炸

设计：运行时仍是 data/links.jsonl 一个明文文件（采集/巡检脚本零改动），
只在发布前切块压缩、恢复时拼回。

    python tools/pack_links.py --split    # links.jsonl -> links/YYYY-MM.jsonl.gz，删明文
    python tools/pack_links.py --join     # links/*.jsonl.gz -> links.jsonl，拼回来
    python tools/pack_links.py --check    # 体检：单文件体积 / 行数 / 超限
"""
from __future__ import annotations

import argparse
import gzip
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RAW = Path("data") / "links.jsonl"
CHUNK_DIR = Path("data") / "links"
MONTH_RE = re.compile(r"^\d{4}-\d{2}$")
UNDATED = "0000-00"
LIMIT_MB = 95  # GitHub 硬限 100MB，留 5MB 余量


def month_of(line: str) -> str:
    try:
        row = json.loads(line)
    except Exception:
        return UNDATED
    posted = (row.get("posted_at") or "")[:7]
    return posted if MONTH_RE.match(posted) else UNDATED


def gz_bytes(text: str) -> bytes:
    """确定性 gzip：mtime=0、无文件名，内容相同则字节相同，git 不会误判未变文件。"""
    import io

    buf = io.BytesIO()
    with gzip.GzipFile(filename="", mode="wb", fileobj=buf, mtime=0, compresslevel=9) as f:
        f.write(text.encode("utf-8"))
    return buf.getvalue()


def human(n: int) -> str:
    return f"{n / 1024 / 1024:.2f} MB" if n >= 1024 * 1024 else f"{n / 1024:.0f} KB"


def split(root: Path) -> int:
    raw = root / RAW
    if not raw.exists():
        print(f"没有 {RAW}，跳过切块")
        return 0
    buckets: dict[str, list[str]] = {}
    total = 0
    with raw.open(encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            total += 1
            buckets.setdefault(month_of(line), []).append(line)

    chunk_dir = root / CHUNK_DIR
    chunk_dir.mkdir(parents=True, exist_ok=True)
    written = kept = 0
    for key in sorted(buckets):
        lines = buckets[key]
        blob = gz_bytes("\n".join(lines) + "\n")
        path = chunk_dir / f"{key}.jsonl.gz"
        if path.exists() and path.read_bytes() == blob:
            kept += 1
            tag = "（未变）"
        else:
            path.write_bytes(blob)
            written += 1
            tag = ""
        print(f"  明细 {key}: {len(lines):>6} 条 -> {human(len(blob))}{tag}")

    # 清掉已空的月份，别留幽灵文件
    for stale in chunk_dir.glob("*.jsonl.gz"):
        if stale.name[: -len(".jsonl.gz")] not in buckets:
            stale.unlink()
            print(f"  删除空分块 {stale.name}")

    # 校验：拼回去的行数必须一致，否则宁可不删明文
    recovered = sum(
        1
        for p in sorted(chunk_dir.glob("*.jsonl.gz"))
        for line in gzip.decompress(p.read_bytes()).decode("utf-8").splitlines()
        if line.strip()
    )
    if recovered != total:
        print(f"!! 切块行数不符：原 {total} / 回读 {recovered}，保留明文不删除", file=sys.stderr)
        return 1
    raw.unlink()
    print(f"切块完成：{total} 条 -> {len(buckets)} 个月（{written} 个更新 / {kept} 个未变），明文已删")
    return 0


def join(root: Path) -> int:
    raw = root / RAW
    chunk_dir = root / CHUNK_DIR
    chunks = sorted(chunk_dir.glob("*.jsonl.gz")) if chunk_dir.exists() else []
    if not chunks:
        print("没有切块文件，保持现状")
        return 0

    seen: set[str] = set()
    out_lines: list[str] = []
    # 明文若已存在（迁移期），先收进来再去重
    if raw.exists():
        for line in raw.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and line not in seen:
                seen.add(line)
                out_lines.append(line)
        print(f"  合并已有明文 {len(out_lines)} 条")
    for path in chunks:
        n = 0
        for line in gzip.decompress(path.read_bytes()).decode("utf-8").splitlines():
            line = line.strip()
            if line and line not in seen:
                seen.add(line)
                out_lines.append(line)
                n += 1
        print(f"  {path.name}: +{n} 条")

    raw.parent.mkdir(parents=True, exist_ok=True)
    raw.write_text("\n".join(out_lines) + "\n", encoding="utf-8")
    print(f"拼回完成：{len(out_lines)} 条 / {human(raw.stat().st_size)}")
    return 0


def check(root: Path) -> int:
    bad = []
    biggest = (0, "")
    files = [p for p in root.rglob("*") if p.is_file() and ".git" not in p.parts]
    for path in files:
        size = path.stat().st_size
        rel = path.relative_to(root)
        if size > biggest[0]:
            biggest = (size, str(rel))
        if size > LIMIT_MB * 1024 * 1024:
            bad.append((size, str(rel)))
    print(f"最大文件：{biggest[1]} = {human(biggest[0])}（上限 {LIMIT_MB} MB）")
    if bad:
        for size, rel in bad:
            print(f"!! 超限：{rel} = {human(size)}，push 会被 GitHub 拒", file=sys.stderr)
        return 1
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--split", action="store_true", help="links.jsonl 切成按月 gzip")
    ap.add_argument("--join", action="store_true", help="按月 gzip 拼回 links.jsonl")
    ap.add_argument("--check", action="store_true", help="体检体积上限")
    ap.add_argument("--root", default=str(ROOT), help="仓库根目录（默认脚本上一级）")
    args = ap.parse_args()
    root = Path(args.root).resolve()
    code = 0
    if args.join:
        code |= join(root)
    if args.split:
        code |= split(root)
    if args.check:
        code |= check(root)
    if not (args.join or args.split or args.check):
        ap.print_help()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
