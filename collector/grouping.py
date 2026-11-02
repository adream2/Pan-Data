#!/usr/bin/env python3
"""组内收敛：同一个 group_key 下只留一条「当前版本」，其余标记为重复或旧版本。

判定优先级的顺序（高到低）：
    1. 链接有效（valid）> 未巡检 > 失效
    2. 集数大的优先（追更到最新）
    3. 标记完结的优先
    4. 发布时间新的优先

于是「更至26集」会盖掉「更至25集」；但「26集已失效」时会把「25集有效」顶上来，
不会让用户点开一个死链。

组内角色：
    current     当前版本（每个 group 唯一）
    duplicate   同集数的另一个来源，内容重复
    superseded  集数更少的旧版本
"""
from __future__ import annotations

STATUS_RANK = {"valid": 2, None: 1, "": 1, "uncertain": 1, "requires_code": 1, "rate_limited": 1}


def _rank(row: dict) -> tuple:
    return (
        STATUS_RANK.get(row.get("status"), 0),
        row.get("episode") or -1,
        1 if row.get("complete") else 0,
        row.get("posted_at") or "",
    )


def _episode(row: dict) -> int:
    return row.get("episode") or -1


def apply(rows: list[dict]) -> dict:
    """就地写入 group_size / best_episode / group_role / superseded_by，返回统计。"""
    groups: dict[str, list[dict]] = {}
    for row in rows:
        groups.setdefault(row.get("group_key") or row.get("id") or "", []).append(row)

    stats = {"groups": len(groups), "current": 0, "duplicate": 0, "superseded": 0, "advanced": 0}
    for members in groups.values():
        members.sort(key=_rank, reverse=True)
        best = members[0]
        best_episode = max(_episode(m) for m in members)
        for index, row in enumerate(members):
            row["group_size"] = len(members)
            row["best_episode"] = best_episode if best_episode >= 0 else None
            if index == 0:
                row["group_role"] = "current"
                row.pop("superseded_by", None)
                stats["current"] += 1
                continue
            row["superseded_by"] = best.get("id")
            if _episode(row) == _episode(best):
                row["group_role"] = "duplicate"
                stats["duplicate"] += 1
            else:
                row["group_role"] = "superseded"
                stats["superseded"] += 1
        if len({_episode(m) for m in members}) > 1:
            stats["advanced"] += 1
    return stats


def summary(rows: list[dict]) -> str:
    current = [r for r in rows if r.get("group_role") == "current"]
    advanced = sum(1 for r in current if (r.get("group_size") or 1) > 1)
    duplicate = sum(1 for r in rows if r.get("group_role") == "duplicate")
    superseded = sum(1 for r in rows if r.get("group_role") == "superseded")
    return (f"资源 {len(current)}（{advanced} 个有多来源）"
            f"｜重复 {duplicate}｜旧版本 {superseded}")


if __name__ == "__main__":
    import json
    import sys
    from pathlib import Path

    path = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent / "data" / "links.jsonl")
    rows = [json.loads(x) for x in path.read_text(encoding="utf-8").splitlines() if x.strip()]
    print(apply(rows))
    path.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n", encoding="utf-8")
    print(summary(rows))
