// Prueba con un Excel REAL del funnel (no se versiona: .gitignore excluye .xlsx).
// Uso: node real.test.js ../app/index.html /ruta/al/funnel.xlsx
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const [, , html, xlsx] = process.argv;
if (!xlsx || !fs.existsSync(xlsx)) { console.log('Sin archivo real: se omite.'); process.exit(0); }
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(html, 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, E = s => w.eval(s);
// El lector de Excel usa DecompressionStream, Blob.stream y Response: se toman de Node
w.DecompressionStream = DecompressionStream; w.Response = Response; w.Blob = Blob; w.TextDecoder = TextDecoder;
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
(async () => {
  E(`toast=m=>{window.__t=m};S=cleanSeed();applyAjustes();S.mode='real';`);
  const buf = fs.readFileSync(xlsx); w.__buf = buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength);
  await E(`(async()=>{const t=await fxReadXlsx(window.__buf);window.__rows=fxRows(t).filter(r=>fxIsOwner(r.ej));FX_PLAN=fxBuildPlan(window.__rows);fxPreview(FX_PLAN);window.__prev=document.querySelector('#modalLayer').textContent;fxApply()})()`);
  const r = E(`JSON.parse(JSON.stringify({filas:window.__rows.length,opps:S.opps.length,abiertas:S.opps.filter(o=>o.status==='abierta').length,
    sinCM:S.opps.filter(o=>!/^CM-/.test(o.code)).length,etapas:S.opps.filter(o=>o.status==='abierta').reduce((m,o)=>(m[o.stage]=(m[o.stage]||0)+1,m),{}),
    tipos:S.opps.reduce((m,o)=>(m[o.fx.tipo||'?']=(m[o.fx.tipo||'?']||0)+1,m),{}),
    tareas:S.tasks.length,tareasPorRegla:S.tasks.reduce((m,t)=>(m[(t.auto||'manual').split(':')[0]]=(m[(t.auto||'manual').split(':')[0]]||0)+1,m),{}),
    pipeline:S.opps.filter(o=>o.status==='abierta').reduce((s,o)=>s+tcv(o),0),proy:S.opps.filter(o=>o.status==='abierta').reduce((s,o)=>s+(o.fx.proj||0),0),
    prev:window.__prev.slice(0,400)}))`);
  console.log(JSON.stringify(r, null, 1));
  ok(r.filas === r.opps, 'una oportunidad por fila');
  ok(!r.tipos['?'], 'todas traen tipo de negocio');
  ok(Math.abs(r.pipeline - r.proy) < 1, 'el pipeline del CRM es la suma de la Proyección del Excel');
  ok(!/columna ID/.test(r.prev), 'sin aviso de columna ID');
  const r2 = await E(`(async()=>{const t=await fxReadXlsx(window.__buf);FX_PLAN=fxBuildPlan(fxRows(t).filter(r=>fxIsOwner(r.ej)));const p=FX_PLAN;const x={news:p.news.length,ch:p.changes.filter(c=>!c.quiet).length,miss:p.missing.length,n:S.opps.length,t:S.tasks.length};fxApply();x.n2=S.opps.length;x.t2=S.tasks.length;return JSON.parse(JSON.stringify(x))})()`);
  ok(r2.news === 0 && r2.ch === 0 && r2.miss === 0 && r2.n2 === r2.n && r2.t2 === r2.t, 'importar dos veces el mismo archivo no cambia nada ' + JSON.stringify(r2));
  ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  if (process.env.CRM_ESTADO) fs.writeFileSync(process.env.CRM_ESTADO, E('JSON.stringify(S)'));
  process.exit(f ? 1 : 0);
})();
