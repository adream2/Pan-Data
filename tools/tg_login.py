#!/usr/bin/env python3
"""一次性生成本机 Telegram session（StringSession），用于 MTProto 采集。

需要先在 https://my.telegram.org/apps 建一个应用，拿到 api_id / api_hash。
跑完把输出的 TG_SESSION 存进 GitHub Secrets，**不要提交到仓库**。

    pip install telethon
    python tools/tg_login.py

会交互式要手机号 + 验证码（有二级密码也会问），成功后写 .env.mtproto（已 gitignore）。
"""
from __future__ import annotations

import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / ".env.mtproto"


def main() -> int:
    try:
        from telethon.sync import TelegramClient
        from telethon.sessions import StringSession
    except ImportError:
        print("先装依赖：pip install telethon")
        return 2

    api_id = os.getenv("TG_API_ID") or input("api_id: ").strip()
    api_hash = os.getenv("TG_API_HASH") or input("api_hash: ").strip()
    if not api_id.isdigit() or not api_hash:
        print("api_id 必须是数字，api_hash 不能为空")
        return 2

    with TelegramClient(StringSession(), int(api_id), api_hash) as client:
        me = client.get_me()
        session = client.session.save()
        name = getattr(me, "username", None) or str(me.id)
        print(f"\n登录成功：{name}（id={me.id}）")
        OUT.write_text(
            f"TG_API_ID={api_id}\nTG_API_HASH={api_hash}\nTG_SESSION={session}\n",
            encoding="utf-8",
        )
        print(f"已写入 {OUT}（已 gitignore）")
        print("\n接下来把这些值加到 GitHub 仓库 Secrets：")
        print(f"  TG_API_ID   = {api_id}")
        print("  TG_API_HASH = (你的 api_hash)")
        print(f"  TG_SESSION  = {session[:24]}…（共 {len(session)} 字符）")
        print("\n⚠️  session 等同于账号登录凭证，泄露等于账号被接管，别贴到任何公开地方。")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
