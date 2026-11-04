#!/usr/bin/env python3
"""把 web/ 打成可直接部署到服务器的 zip。

打的是「运行必需」的最小集合：
  - 排除 storage/ 下的数据库、缓存、限流文件、同步状态（每台机器自己生成）
  - 排除 src/config.local.php（含本机代理等环境相关配置，不该带到服务器）
  - 排除测试脚本

用法：
    python tools/pack_web.py                    # 输出到桌面
    python tools/pack_web.py --out /tmp/x.zip
"""
from __future__ import annotations

import argparse
import os
import zipfile
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WEB = ROOT / "web"

# 目录整体跳过：storage 是运行时产物，node_modules 是 Tailwind 构建依赖
# （public/assets/app.css 已经是编译好的成品，服务器上不需要再 build）
SKIP_DIRS = {"storage", "node_modules"}
# 单文件跳过
SKIP_FILES = {"config.local.php", "test_poster_match.php", "package.json",
              "package-lock.json", "tailwind.config.js", "styles.css"}
# 后缀跳过
SKIP_SUFFIX = {".sqlite", ".sqlite-wal", ".sqlite-shm", ".log", ".zip", ".tmp"}

PACK_PREFIX = "pansou-web"


def default_out() -> Path:
    desktop = Path(os.path.expanduser("~")) / "Desktop"
    if not desktop.is_dir():
        desktop = ROOT
    return desktop / f"{PACK_PREFIX}-{datetime.now():%Y%m%d}.zip"


def iter_files():
    for path in sorted(WEB.rglob("*")):
        rel = path.relative_to(WEB)
        if any(part in SKIP_DIRS for part in rel.parts):
            continue
        if path.is_dir():
            continue
        if path.name in SKIP_FILES or path.suffix in SKIP_SUFFIX:
            continue
        if path.name.startswith(".") and path.name not in {".htaccess", ".gitkeep"}:
            continue
        yield path, rel


def to_zipinfo(name: str, mode: int, is_dir: bool = False) -> zipfile.ZipInfo:
    info = zipfile.ZipInfo(name, date_time=datetime.now().timetuple()[:6])
    info.external_attr = ((0o40000 if is_dir else 0o100000) | mode) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    return info


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="", help="输出 zip 路径，默认放桌面")
    args = ap.parse_args()

    out = Path(args.out) if args.out else default_out()
    out.parent.mkdir(parents=True, exist_ok=True)

    files = list(iter_files())
    if not files:
        print("web/ 下没有可打包的文件", flush=True)
        return 1

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        # 空目录也要在包里，否则解压后程序第一次运行才发现没法写
        zf.writestr(to_zipinfo(f"{PACK_PREFIX}/", 0o755, True), b"")
        for d, mode in (("public", 0o755), ("src", 0o755), ("bin", 0o755), ("storage", 0o775)):
            zf.writestr(to_zipinfo(f"{PACK_PREFIX}/{d}/", mode, True), b"")
        zf.writestr(
            to_zipinfo(f"{PACK_PREFIX}/storage/.gitkeep", 0o644),
            b"# \xe8\xbf\x90\xe8\xa1\x8c\xe6\x97\xb6\xe6\x95\xb0\xe6\x8d\xae\xe5\xad\x98\xe8\xbf\x99\xe9\x87\x8c\n",
        )
        for path, rel in files:
            mode = 0o755 if rel.parts[0] == "bin" and rel.suffix == ".php" else 0o644
            zf.writestr(to_zipinfo(f"{PACK_PREFIX}/{rel.as_posix()}", mode), path.read_bytes())

    size = out.stat().st_size
    print(f"已生成 {out}")
    print(f"  {len(files) + 5} 个条目，{size / 1024:.0f} KB")
    print(f"  内容：{', '.join(sorted({p.parts[0] for _, p in files}))}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
