#!/usr/bin/env python3
"""二维码登录：不用输手机号，手机扫一下就行（比短信验证码省事）。

跑起来会在 .tmp/qr.png 生成二维码并持续刷新（过期自动换），手机用 Telegram 扫即可。
如果账号开了二级密码，脚本会等 .tmp/2fa.txt 出现（把密码写进去，不要带换行）。

    python tools/tg_qr_login.py [--minutes 10]
"""
from __future__ import annotations

import argparse
import asyncio
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ENV = ROOT / ".env.mtproto"
QR = ROOT / ".tmp" / "qr.png"
STATUS = ROOT / ".tmp" / "qr_status.txt"
TWOFA = ROOT / ".tmp" / "2fa.txt"


def load_env() -> dict[str, str]:
    values: dict[str, str] = {}
    if ENV.exists():
        for line in ENV.read_text(encoding="utf-8").splitlines():
            if "=" in line and not line.strip().startswith("#"):
                k, _, v = line.partition("=")
                values[k.strip()] = v.strip()
    for key in ("TG_API_ID", "TG_API_HASH"):
        if os.getenv(key):
            values[key] = os.environ[key]
    return values


def write_status(text: str) -> None:
    STATUS.parent.mkdir(parents=True, exist_ok=True)
    STATUS.write_text(text, encoding="utf-8")
    print(text, flush=True)


def render_qr(url: str) -> None:
    import qrcode
    img = qrcode.make(url)
    QR.parent.mkdir(parents=True, exist_ok=True)
    img.save(QR)


async def wait_for_password(timeout: int = 300) -> str | None:
    deadline = asyncio.get_event_loop().time() + timeout
    while asyncio.get_event_loop().time() < deadline:
        if TWOFA.exists():
            pwd = TWOFA.read_text(encoding="utf-8").strip()
            TWOFA.unlink(missing_ok=True)
            return pwd or None
        await asyncio.sleep(2)
    return None


async def main() -> int:
    from telethon import TelegramClient
    from telethon.errors import SessionPasswordNeededError
    from telethon.sessions import StringSession

    env = load_env()
    api_id, api_hash = env.get("TG_API_ID"), env.get("TG_API_HASH")
    if not (api_id and api_hash):
        print("缺少 TG_API_ID / TG_API_HASH")
        return 2

    proxy = None
    if os.getenv("MT_PROXY"):
        from urllib.parse import urlparse
        raw = os.environ["MT_PROXY"]
        p = urlparse(raw if "://" in raw else f"socks5://{raw}")
        proxy = (p.scheme, p.hostname, p.port)

    client = TelegramClient(StringSession(), int(api_id), api_hash, proxy=proxy)
    await client.connect()
    if await client.is_user_authorized():
        write_status("already_authorized")
    else:
        qr = await client.qr_login()
        render_qr(qr.url)
        write_status(f"qr_ready {qr.url}")
        for attempt in range(1, 31):
            try:
                await qr.wait(timeout=30)
                break
            except asyncio.TimeoutError:
                await qr.recreate()
                render_qr(qr.url)
                write_status(f"qr_refreshed {attempt}")
            except SessionPasswordNeededError:
                write_status("need_2fa")
                pwd = await wait_for_password()
                if not pwd:
                    write_status("2fa_timeout")
                    await client.disconnect()
                    return 3
                await client.sign_in(password=pwd)
                break

    me = await client.get_me()
    session = client.session.save()
    ENV.write_text(
        f"TG_API_ID={api_id}\nTG_API_HASH={api_hash}\nTG_SESSION={session}\n",
        encoding="utf-8",
    )
    write_status(f"ok user={getattr(me, 'username', None) or me.id} session_len={len(session)}")
    await client.disconnect()
    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
