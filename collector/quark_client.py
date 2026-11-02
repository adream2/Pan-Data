#!/usr/bin/env python3
"""夸克网盘客户端：转存别人分享 → 在自己网盘生成新分享链接（拉新用）。

只依赖标准库。认证靠浏览器 Cookie（`__pus` / `__puus` 等），从环境变量 QUARK_COOKIE 读，
格式就是浏览器里整条 Cookie 字符串。

接口来自实测可用的网页版接口：
    token   /share/sharepage/token     拿 stoken
    detail  /share/sharepage/detail    列出分享里的文件
    save    /share/sharepage/save      转存到自己的目录（异步任务）
    task    /task                      查任务结果
    share   /share                     创建分享（异步任务）
    publish /share/password            取分享链接和提取码
"""
from __future__ import annotations

import json
import os
import random
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass

BASE = "https://drive-pc.quark.cn/1/clouddrive"
SAVE_BASE = "https://drive.quark.cn/1/clouddrive"
COMMON_PARAMS = {"pr": "ucpro", "fr": "pc", "uc_param_str": ""}

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36")


class QuarkError(RuntimeError):
    def __init__(self, message: str, payload: dict | None = None):
        super().__init__(message)
        self.payload = payload or {}


@dataclass
class ShareFile:
    fid: str
    fid_token: str
    name: str
    is_dir: bool


class QuarkClient:
    def __init__(self, cookie: str | None = None, *, proxy: str | None = None, timeout: int = 25):
        self.cookie = cookie or os.getenv("QUARK_COOKIE", "")
        if not self.cookie:
            raise QuarkError("缺少 QUARK_COOKIE（浏览器登录夸克后复制整条 Cookie）")
        handler = urllib.request.ProxyHandler({"http": proxy, "https": proxy} if proxy else {})
        self._opener = urllib.request.build_opener(handler)
        self.timeout = timeout

    # ---------- 底层 ----------
    def _call(self, method: str, url: str, *, params: dict | None = None,
              body: dict | None = None, host_headers: dict | None = None) -> dict:
        query = dict(COMMON_PARAMS)
        query.update(params or {})
        full = f"{url}?{urllib.parse.urlencode(query)}"
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(full, data=data, method=method)
        req.add_header("cookie", self.cookie)
        req.add_header("user-agent", UA)
        req.add_header("accept", "application/json, text/plain, */*")
        req.add_header("origin", "https://pan.quark.cn")
        req.add_header("referer", "https://pan.quark.cn/")
        req.add_header("content-type", "application/json")
        for k, v in (host_headers or {}).items():
            req.add_header(k, v)
        try:
            with self._opener.open(req, timeout=self.timeout) as resp:
                payload = json.loads(resp.read() or b"{}")
        except urllib.error.HTTPError as exc:
            raw = exc.read()
            try:
                payload = json.loads(raw or b"{}")
            except json.JSONDecodeError:
                raise QuarkError(f"HTTP {exc.code}", {"body": raw[:200].decode('utf-8', 'replace')})
        except Exception as exc:  # 网络问题
            raise QuarkError(f"{type(exc).__name__}: {exc}")
        if payload.get("status") not in (200, None) or payload.get("code") not in (0, None):
            raise QuarkError(f"接口返回 {payload.get('code')}: {payload.get('message')}", payload)
        return payload

    # ---------- 业务 ----------
    def share_stoken(self, pwd_id: str, passcode: str = "") -> str:
        payload = self._call(
            "POST", f"{BASE}/share/sharepage/token",
            body={"pwd_id": pwd_id, "passcode": passcode, "support_visit_limit_private_share": True},
        )
        stoken = (payload.get("data") or {}).get("stoken")
        if not stoken:
            raise QuarkError("拿不到 stoken（分享可能已失效或需要提取码）", payload)
        return stoken

    def list_files(self, pwd_id: str, stoken: str, pdir_fid: str = "0") -> list[ShareFile]:
        payload = self._call(
            "GET", f"{BASE}/share/sharepage/detail",
            params={"pwd_id": pwd_id, "stoken": stoken, "pdir_fid": pdir_fid,
                    "force": "0", "_page": "1", "_size": "100",
                    "_fetch_banner": "0", "_fetch_share": "0", "_fetch_total": "1",
                    "_sort": "file_type:asc,updated_at:desc"},
        )
        items = (payload.get("data") or {}).get("list") or []
        return [ShareFile(fid=i.get("fid", ""), fid_token=i.get("share_fid_token", ""),
                          name=i.get("file_name", ""), is_dir=bool(i.get("dir")))
                for i in items if i.get("fid")]

    def my_root_fid(self) -> str:
        payload = self._call("GET", f"{BASE}/file/sort",
                             params={"pdir_fid": "0", "_page": "1", "_size": "1", "_fetch_total": "0"})
        return str((payload.get("data") or {}).get("fid") or "0")

    def save(self, *, pwd_id: str, stoken: str, files: list[ShareFile], to_pdir_fid: str = "0") -> str:
        payload = self._call(
            "POST", f"{SAVE_BASE}/share/sharepage/save",
            params={"__dt": str(random.randint(600, 9999)), "__t": str(int(random.random() * 10 ** 13))},
            body={
                "fid_list": [f.fid for f in files],
                "fid_token_list": [f.fid_token for f in files],
                "to_pdir_fid": to_pdir_fid,
                "pwd_id": pwd_id,
                "stoken": stoken,
                "pdir_fid": "0",
                "scene": "link",
            },
        )
        task_id = str((payload.get("data") or {}).get("task_id") or "")
        if not task_id:
            raise QuarkError("转存任务创建失败", payload)
        return task_id

    def wait_task(self, task_id: str, *, retries: int = 20, interval: float = 1.0) -> dict:
        last: dict = {}
        for i in range(retries):
            payload = self._call("GET", f"{BASE}/task",
                                 params={"task_id": task_id, "retry_index": str(i)})
            data = payload.get("data") or {}
            last = data
            if data.get("status") == 2:      # 2 = 完成
                return data
            if data.get("status") == 3:      # 3 = 失败
                raise QuarkError(f"任务失败：{data.get('message')}", payload)
            time.sleep(interval)
        raise QuarkError("任务超时", {"task": last})

    def create_share(self, *, fid: str, title: str, expire_days: int = 0) -> str:
        payload = self._call(
            "POST", f"{BASE}/share",
            body={"fid_list": [fid], "title": title[:60],
                  "url_type": 1, "expired_type": _expire_type(expire_days)},
        )
        task_id = str((payload.get("data") or {}).get("task_id") or "")
        if not task_id:
            raise QuarkError("创建分享任务失败", payload)
        return task_id

    def share_id_of(self, task_id: str, *, retries: int = 15) -> str:
        for i in range(retries):
            payload = self._call("GET", f"{BASE}/task",
                                 params={"task_id": task_id, "retry_index": str(i)})
            share_id = str((payload.get("data") or {}).get("share_id") or "")
            if share_id:
                return share_id
            time.sleep(0.8)
        raise QuarkError("分享任务没有返回 share_id")

    def publish(self, share_id: str) -> tuple[str, str | None]:
        payload = self._call("POST", f"{BASE}/share/password", body={"share_id": share_id})
        data = payload.get("data") or {}
        url = str(data.get("share_url") or "")
        if not url:
            raise QuarkError("取分享链接失败", payload)
        return url, (data.get("passcode") or None)


def _expire_type(days: int) -> int:
    # 1=永久 2=1天 3=7天 4=30天
    return {0: 1, 1: 2, 7: 3, 30: 4}.get(days, 1)


def pwd_id_of(url: str) -> str:
    """从 https://pan.quark.cn/s/xxxx 里取分享 ID。"""
    import re
    m = re.search(r"/s/([0-9A-Za-z]+)", url or "")
    if not m:
        raise QuarkError(f"不是夸克分享链接：{url}")
    return m.group(1)
