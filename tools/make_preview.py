#!/usr/bin/env python3
"""把 data/links.jsonl 渲染成一个可搜索的单文件预览页（不依赖任何外部资源）。"""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "data" / "links.jsonl"
OUT = ROOT / "preview.html"

TPL = """<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PANSOU · 采集预览</title>
<style>
:root{--fg:#1a1a1a;--mute:#8a8a8a;--line:#ececec;--bg:#fff;--soft:#f7f7f5;--accent:#185FA5}
*{box-sizing:border-box}
body{margin:0;padding:32px 20px 64px;background:var(--bg);color:var(--fg);
 font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:1080px;margin:0 auto}
h1{font-size:17px;font-weight:500;margin:0 0 4px}
.meta{color:var(--mute);font-size:13px;margin-bottom:20px}
.bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
input,select{font:inherit;padding:8px 12px;border:1px solid var(--line);border-radius:8px;
 background:var(--soft);color:var(--fg);outline:none}
input{flex:1;min-width:220px}
input:focus,select:focus{border-color:#c9c9c4;background:#fff}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-weight:500;font-size:12px;color:var(--mute);
 padding:8px 10px;border-bottom:1px solid var(--line);position:sticky;top:0;background:#fff}
td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
tr:hover td{background:var(--soft)}
.t{font-weight:500}
.d{color:var(--mute);font-size:12px;margin-top:2px;
 display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.m{color:#9a9a94;font-size:11px;margin-top:3px;letter-spacing:.2px}
.tag{display:inline-block;font-size:11px;padding:1px 7px;border-radius:99px;
 background:var(--soft);border:1px solid var(--line);color:#555;white-space:nowrap}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.time{color:var(--mute);font-size:12px;white-space:nowrap}
button{font:inherit;font-size:12px;padding:4px 10px;border:1px solid var(--line);
 border-radius:6px;background:#fff;cursor:pointer;color:var(--accent)}
button:hover{border-color:var(--accent)}
.empty{color:var(--mute);text-align:center;padding:40px}
label.chk{display:flex;align-items:center;gap:6px;font-size:13px;color:#555;white-space:nowrap}
.st{font-size:11px;padding:1px 7px;border-radius:99px;white-space:nowrap}
.st-valid{background:#EAF3DE;color:#3B6D11}
.st-invalid{background:#FCEBEB;color:#A32D2D}
.st-unknown{background:#F1EFE8;color:#5F5E5A}
</style>
</head>
<body>
<div class="wrap">
  <h1>PANSOU · 采集预览</h1>
  <div class="meta" id="meta"></div>
  <div class="bar">
    <input id="q" placeholder="搜标题 / 描述，支持空格分词" autofocus>
    <select id="p"><option value="">全部平台</option></select>
    <label class="chk"><input type="checkbox" id="onlyValid" checked> 只看有效</label>
    <label class="chk"><input type="checkbox" id="mergeDup" checked> 只看当前版本</label>
  </div>
  <table>
    <thead><tr><th style="width:48%">标题</th><th style="width:9%">平台</th>
      <th style="width:8%">状态</th><th style="width:8%">提取码</th>
      <th style="width:13%">发布</th><th style="width:12%"></th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
  <div class="empty" id="empty" hidden>没有匹配的条目</div>
</div>
<script>
const DATA = __DATA__;
const q = document.getElementById('q'), sel = document.getElementById('p');
const rowsEl = document.getElementById('rows'), emptyEl = document.getElementById('empty');
const onlyValid = document.getElementById('onlyValid');
const mergeDup = document.getElementById('mergeDup');
const provs = [...new Set(DATA.map(d => d.provider))].sort();
provs.forEach(p => sel.insertAdjacentHTML('beforeend', `<option value="${p}">${p}</option>`));
const dead = DATA.filter(d => d.status === 'invalid').length;
const groups = DATA.filter(d => (d.group_role || 'current') === 'current').length;
const gcount = {};
DATA.forEach(d => { const k = d.group_key || d.id; gcount[k] = (gcount[k] || 0) + 1; });
document.getElementById('meta').textContent =
  `${DATA.length} 条 → ${groups} 个资源 · ${provs.length} 个平台 · 失效 ${dead} 条 · 数据源 data/links.jsonl`;

function esc(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
const ST = {valid:['有效','st-valid'], invalid:['失效','st-invalid']};
function statusCell(s){
  const [txt, cls] = ST[s] || ['未巡检','st-unknown'];
  return `<span class="st ${cls}">${txt}</span>`;
}
function metaOf(d, mergedCount){
  const parts = [];
  if (d.year) parts.push(`${d.year}年`);
  if (d.season) parts.push(`第${d.season}季`);
  if (d.episode) parts.push(`${d.complete ? '全' : '更新至'}${d.episode}集`);
  if (d.group_role === 'duplicate') parts.push('同集重复来源');
  if (d.group_role === 'superseded') parts.push(`旧版本（已更新至 ${d.best_episode || '?'} 集）`);
  if (mergedCount > 1) parts.push(`${mergedCount} 个来源`);
  return parts.join(' · ');
}
function render(){
  const terms = q.value.trim().toLowerCase().split(/\\s+/).filter(Boolean);
  const pv = sel.value, vOnly = onlyValid.checked, curOnly = mergeDup.checked;
  const hit = DATA.filter(d => {
    if (pv && d.provider !== pv) return false;
    if (vOnly && d.status === 'invalid') return false;
    if (curOnly && (d.group_role || 'current') !== 'current') return false;
    const hay = ((d.title_best || d.title) + ' ' + (d.desc||'')).toLowerCase();
    return terms.every(t => hay.includes(t));
  });
  emptyEl.hidden = hit.length > 0;
  rowsEl.innerHTML = hit.slice(0, 400).map(d => `
    <tr>
      <td><div class="t">${esc(d.title_best || d.title)}</div><div class="m">${esc(metaOf(d, gcount[d.group_key || d.id]))}</div><div class="d">${esc(d.desc||'')}</div></td>
      <td><span class="tag">${esc(d.provider)}</span></td>
      <td>${statusCell(d.status)}</td>
      <td class="code">${esc(d.pwd||'—')}</td>
      <td class="time">${esc((d.posted_at||'').slice(0,10))}</td>
      <td><button data-u="${esc(d.share_url)}">复制链接</button></td>
    </tr>`).join('');
}
rowsEl.addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b) return;
  navigator.clipboard.writeText(b.dataset.u).then(() => {
    const old = b.textContent; b.textContent = '已复制';
    setTimeout(() => b.textContent = old, 1200);
  });
});
q.addEventListener('input', render); sel.addEventListener('change', render);
onlyValid.addEventListener('change', render);
mergeDup.addEventListener('change', render);
render();
</script>
</body>
</html>
"""


def main() -> int:
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else SRC
    if not src.exists():
        print(f"没有数据文件：{src}", file=sys.stderr)
        return 1
    rows = [json.loads(x) for x in src.read_text(encoding="utf-8").splitlines() if x.strip()]
    html = TPL.replace("__DATA__", json.dumps(rows, ensure_ascii=False))
    OUT.write_text(html, encoding="utf-8")
    print(f"已生成 {OUT}（{len(rows)} 条，{OUT.stat().st_size/1024:.0f} KB）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
