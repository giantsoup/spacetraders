import { waypointSprite } from '../../resources/js/waypointSprites.js';
import { writeFileSync } from 'node:fs';
const RESET = '2026-11-08';
const sprite = (point, S) => {
  const s = waypointSprite(point, RESET);
  const rows = s.pixels.filter(p => p.y >= 10 && p.y <= 13);
  const minX = rows.length ? Math.min(...rows.map(p => p.x)) : 6;
  const maxX = rows.length ? Math.max(...rows.map(p => p.x)) : 17;
  const svg = `<svg viewBox="0 0 24 24" shape-rendering="crispEdges" width="${24 * S}" height="${24 * S}">${s.pixels.map(p => `<rect x="${p.x}" y="${p.y}" width="1" height="1" fill="${p.fill}"/>`).join('')}</svg>`;
  return { svg, minX, maxX };
};
const ship = (K, flame, cls = '') => `<svg class="ship ${cls}" viewBox="0 0 32 24" shape-rendering="crispEdges" width="${32 * K}" height="${24 * K}">${flame ? '<g class="flame"><path fill="#f7aa62" d="M1 10h9v4H1zM4 8h6v8H4z"/><path fill="#f7dc91" d="M5 10h6v4H5z"/></g>' : ''}<path fill="#526881" d="M10 4h6v3h5v3h6v4h-6v3h-5v3h-6v-5H8V9h2z"/><path fill="#dce8f0" d="M12 6h4v3h7v2h5v2h-5v2h-7v3h-4v-5h-2v-2h2z"/><path fill="#a6f3d0" d="M16 10h5v4h-5z"/><path fill="#4d8e8d" d="M17 10h3v2h-3z"/><path fill="#8997be" d="M11 5h3v3h-3zM11 16h3v3h-3z"/></svg>`;
const label = (point, context, cls) => `<span class="lab ${cls}"><span class="ctx">${context}</span>${point.symbol || 'Location unknown'}</span>`;

// Stationary states: location and ship centred, as large as the hero allows.
function centred({ state, point, h, S, K }) {
  const { svg, minX, maxX } = sprite(point, S);
  const cy = h / 2, top = cy - 12 * S;
  let body = '';
  if (state === 'docked') {
    const gap = 3 * K, W = 20 * K + gap + (maxX + 1 - minX) * S;
    const shipLeft = -W / 2 - 8 * K, port = -W / 2 + 20 * K + gap, spriteLeft = port - minX * S;
    body = `<div class="abs" style="left:calc(50% + ${spriteLeft}px);top:${top}px">${svg}</div>`
      + `<div class="clamp" style="left:calc(50% + ${port - gap}px);top:${cy - 2.5 * K}px;width:${gap}px;height:${5 * K}px;--k:${K}px"></div>`
      + `<div class="port" style="left:calc(50% + ${port + S / 2 - K}px);top:${cy - K}px;width:${2 * K}px;height:${2 * K}px"></div>`
      + `<div class="abs" style="left:calc(50% + ${shipLeft}px);top:${cy - 12 * K}px">${ship(K, false)}</div>`;
  } else {
    const half = (maxX + 1 - minX) * S / 2, rx = half + 4.6 * S, ry = 4.4 * S, rot = -14 * Math.PI / 180, t = 150 * Math.PI / 180;
    const ex = rx * Math.cos(t), ey = ry * Math.sin(t);
    const sx = ex * Math.cos(rot) - ey * Math.sin(rot), sy = ex * Math.sin(rot) + ey * Math.cos(rot);
    const bw = 2 * rx + 20, bh = 2 * rx * Math.sin(14 * Math.PI / 180) + 2 * ry + 20;
    const ring = part => `<svg class="ring abs" style="left:calc(50% - ${bw / 2}px);top:${cy - bh / 2}px" width="${bw}" height="${bh}" viewBox="${-bw / 2} ${-bh / 2} ${bw} ${bh}"><path transform="rotate(-14)" d="M${-rx} 0 A${rx} ${ry} 0 0 ${part === 'front' ? 0 : 1} ${rx} 0" fill="none" stroke="${part === 'front' ? '#a6f3d0' : '#526881'}" stroke-width="${Math.max(1.5, K / 2)}" stroke-dasharray="${K * 1.5} ${K * 3}"/></svg>`;
    body = ring('back') + `<div class="abs" style="left:calc(50% - ${12 * S}px);top:${top}px">${svg}</div>` + ring('front')
      + `<div class="abs" style="left:calc(50% + ${Math.round(sx) - 16 * K}px);top:${Math.round(cy + sy) - 12 * K}px">${ship(K, false, 'bob')}</div>`;
  }
  return `<div class="scene" style="height:${h}px">${body}${label(point, 'Observed location', 'corner')}</div>`;
}

// Transit: unchanged from the first round (location right, ship travels the route).
function transit({ point, p, h = 230, flame = true }) {
  const S = 112 / 24, K = 2, { svg, minX } = sprite(point, S), d = Math.round(56 - minX * S), cy = 65, x = '78.125%';
  return `<div class="scene" style="height:${h}px"><div class="route" style="left:12.5%;right:calc(100% - ${x} + ${d + 8 + 24}px);top:${cy}px;bottom:25%"><svg viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0 100 L100 0" stroke="#526881" stroke-width="1.5" stroke-dasharray="3 6" vector-effect="non-scaling-stroke"/><path d="M0 100 L${p * 100} ${100 - p * 100}" stroke="#a6f3d0" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg><span class="origin"></span><div class="abs onroute" style="left:${p * 100}%;top:${100 - p * 100}%">${ship(K, flame, flame ? 'bob' : '')}</div></div><div class="loc" style="left:${x};top:${cy - 56}px">${svg}${label(point, 'Destination', '')}</div></div>`;
}

const A1 = { symbol: 'X1-PZ83-A1', type: 'PLANET', traits: [{ symbol: 'OCEAN', description: 'oceans' }] };
const A2 = { symbol: 'X1-PZ83-A2', type: 'ORBITAL_STATION' };
const H47 = { symbol: 'X1-PZ83-H47', type: 'PLANET', traits: [{ symbol: 'VOLCANIC' }] };
const card = (title, why, sceneHtml) => `<section><h3>${title}</h3>${why ? `<p class="why">${why}</p>` : ''}<div class="box">${sceneHtml}</div></section>`;
const html = `<!doctype html><html><head><meta charset="utf-8"></head><body><style>body{background:#0b0d10;color:#e8eef6;font-family:-apple-system,"Segoe UI",sans-serif;--foreground:#e8eef6;--muted-foreground:#97a8bc;--font-sans:-apple-system,"Segoe UI",sans-serif;--radius:10px;max-width:760px;margin:16px auto;padding:0 8px}
.phone{max-width:402px;margin:0 auto}
section{margin:0 0 18px} h3{font:600 15px/1.4 var(--font-sans);margin:0 0 2px;color:var(--foreground)}
h2{font:600 13px/1.4 var(--font-sans);margin:22px 0 10px;color:var(--muted-foreground);text-transform:none}
.why{margin:0 0 8px;color:var(--muted-foreground);font:14px/1.5 var(--font-sans)}
.box{background:#070c16;border-radius:var(--radius);padding:16px;overflow:hidden}
.scene{position:relative;background:radial-gradient(ellipse at top right,#14274488,transparent 65%)}
.scene::before{content:'';position:absolute;inset:0;background-image:radial-gradient(#97a8bc88 .7px,transparent .8px),radial-gradient(#97a8bc66 .7px,transparent .8px);background-size:43px 37px,71px 61px;background-position:12px 7px,24px 18px}
.abs{position:absolute}.abs>svg,.loc>svg{display:block}
.loc{position:absolute;width:112px;transform:translateX(-50%);text-align:center}
.lab{display:block;margin-top:4px;color:#c4d5e8;font:10px/1.4 ui-monospace,monospace}.ctx{display:block;color:#91a8c1;font:10px/1.4 -apple-system,"Segoe UI",sans-serif}
.corner{position:absolute;left:2px;bottom:0;margin:0;text-align:left}
.onroute{transform:translate(-50%,-50%)}
.bob{animation:bob 4s ease-in-out infinite}@keyframes bob{50%{transform:translateY(-4px)}}
.flame{animation:flick .35s steps(2) infinite}@keyframes flick{50%{opacity:.55}}
.ring{overflow:visible}.ring path{animation:drift 3s linear infinite}@keyframes drift{to{stroke-dashoffset:-36}}
.route{position:absolute}.route>svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible}
.origin{position:absolute;left:-3px;bottom:-3px;width:6px;height:6px;background:#526881}
.clamp{position:absolute;background:linear-gradient(#8997be 0 var(--k),transparent var(--k) calc(100% - var(--k)),#8997be calc(100% - var(--k)) 100%)}
.port{position:absolute;background:#a6f3d0;box-shadow:0 0 8px #a6f3d0;animation:blink 2.4s steps(1) infinite}@keyframes blink{50%{opacity:.35}}
@media(prefers-reduced-motion:reduce){.bob,.flame,.ring path,.port{animation:none}}
</style><div class="phone">
<h2>iPhone 17 Pro width (402 pt), full-height scene</h2>
${card('Docked', '', centred({ state: 'docked', point: A1, h: 230, S: 9, K: 4 }))}
${card('In orbit', '', centred({ state: 'orbit', point: A1, h: 230, S: 9, K: 4 }))}
${card('Docked at a station', '', centred({ state: 'docked', point: A2, h: 230, S: 9, K: 4 }))}
<h2>Same width, shorter scene (when the browser bars leave less than 720 pt of height)</h2>
${card('Docked', '', centred({ state: 'docked', point: A1, h: 128, S: 5, K: 2 }))}
${card('In orbit', '', centred({ state: 'orbit', point: A1, h: 128, S: 5, K: 2 }))}
<h2>Unchanged from last round, shown at phone width</h2>
${card('In transit', '', transit({ point: H47, p: .45 }))}
</div></body></html>`;
writeFileSync(process.argv[2], html);
console.log(html.length);
