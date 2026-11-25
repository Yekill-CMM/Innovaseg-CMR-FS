// Pruebas del importador del funnel sobre app/index.html, sin navegador (jsdom).
// Uso: node importador.test.js /ruta/a/app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

const html = fs.readFileSync(process.argv[2], 'utf8');
const vc = new VirtualConsole(); // silencia errores de red del arranque (no hay api.php)
const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window;
w.eval('window.toast=()=>{};');
const E = s => w.eval(s);

let fallas = 0;
const ok = (cond, msg) => { console.log((cond ? '  ✓ ' : '  ✗ ') + msg); if (!cond) fallas++; };

const CAB = 'ID;Estado;Título;Tipo de negocio;Monto Seg. Fisica;Monto Seg. Electronica;Probabilidad;Fecha de contacto;Generador';
const fila = (id, emp, desc, estado = 'Pipeline') => `${id};${estado};CM-000${id}-01-${emp} (${desc});Recurrente;1000000;500000;50%;2026-09-01;Claudio Méndez`;
const csv = filas => [CAB, ...filas].join('\n');

// Importa un CSV como lo hace la pantalla: lee, filtra por ejecutivo, arma el plan y aplica
function importar(texto) {
  w.__csv = texto;
  return E(`(()=>{const all=fxRows(fxReadCsv(window.__csv));const rows=all.filter(r=>fxIsOwner(r.ej));
    FX_PLAN=fxBuildPlan(rows);const p=FX_PLAN;const info={news:p.news.length,changes:p.changes.filter(x=>!x.quiet).length,missing:p.missing.length,
    missingNew:(p.missing||[]).filter(o=>!o.fx.ausente).length};fxApply();return info})()`);
}
const opp = id => E(`S.opps.find(o=>o.fx&&o.fx.id==='${id}')`);

E(`S=cleanSeed();applyAjustes();S.mode='real';`);

console.log('1. Primera importación: 3 oportunidades');
importar(csv([fila(101, 'ACME', 'CCTV planta'), fila(102, 'ACME', 'Guardias CD'), fila(103, 'BETA', 'Monitoreo')]));
ok(E('S.opps.length') === 3, 'se crean 3 oportunidades');

// Gestión local sobre la 102: actividad, tarea y próxima acción
E(`(()=>{const o=S.opps.find(o=>o.fx.id==='102');o.nextAction='Llamar al gerente';o.nextActionDate='${'2026-12-01'}';
  S.activities.unshift({id:'aLocal',date:todayIso,userId:'u1',type:'Llamada',entityType:'opp',entityId:o.id,companyId:o.companyId,desc:'Conversación con el cliente',result:'',nextAction:'',nextActionDate:''});
  S.tasks.push({id:'tLocal',title:'Enviar propuesta',entityType:'opp',entityId:o.id,assigneeId:'u1',priority:'Alta',due:'2026-12-01',status:'Pendiente',createdAt:todayIso})})()`);
const actsAntes = E('S.activities.length');

console.log('2. Segunda importación sin la 102 ni la 103');
const r2 = importar(csv([fila(101, 'ACME', 'CCTV planta')]));
ok(r2.missing === 2, 'la vista previa informa 2 oportunidades que no vienen');
ok(E('S.opps.length') === 3, 'no se elimina ninguna oportunidad');
ok(!!opp('102') && !!opp('102').fx.ausente, 'la 102 queda marcada como ausente');
ok(!!opp('103') && !!opp('103').fx.ausente, 'la 103 queda marcada como ausente');
ok(!opp('101').fx.ausente, 'la 101, que sí vino, no queda marcada');
ok(E(`S.activities.some(a=>a.id==='aLocal')`), 'se conserva la actividad local de la 102');
ok(E(`S.tasks.some(t=>t.id==='tLocal'&&t.status==='Pendiente')`), 'se conserva la tarea local de la 102');
ok(opp('102') && opp('102').nextAction === 'Llamar al gerente', 'se conserva la próxima acción local');
ok(opp('102') && opp('102').status === 'abierta', 'el estado de la oportunidad no cambia');
ok(E('S.activities.length') >= actsAntes, 'no se pierden actividades');
ok(E(`S.companies.some(c=>c.legalName==='BETA')`), 'la empresa BETA (solo con la 103) no se elimina');
ok(E(`computeAlerts().some(a=>a.id===S.opps.find(o=>o.fx.id==='102').id&&/funnel/i.test(a.kind))`), 'hay una alerta para revisar la 102');
ok(E(`S.audit.some(a=>/no vino/i.test(a.newV))`), 'queda registro en la auditoría');
const fecha102 = opp('102') && opp('102').fx.ausente;

console.log('3. Tercera importación: la 102 sigue sin venir y vuelve la 103');
const r3 = importar(csv([fila(101, 'ACME', 'CCTV planta'), fila(103, 'BETA', 'Monitoreo')]));
ok(r3.missing === 1 && r3.missingNew === 0, 'la 102 se informa como que sigue sin venir (no como nueva ausencia)');
ok(opp('102') && opp('102').fx.ausente === fecha102, 'la 102 conserva la fecha en que dejó de venir');
ok(opp('103') && !opp('103').fx.ausente, 'la 103 vuelve a quedar vinculada, sin marca');
ok(r3.news === 0, 'la 103 no se duplica como nueva');
ok(E('S.opps.length') === 3, 'siguen siendo 3 oportunidades');
ok(E(`S.audit.some(a=>/volvi/i.test(a.newV))`), 'la vuelta de la 103 queda en la auditoría');

console.log('4. Una oportunidad creada a mano no se toca');
E(`S.opps.push({id:'oManual',code:'OP-2026-9999',name:'Manual',companyId:S.companies[0].id,status:'abierta',stage:'oferta',prob:50,ownerId:'u1',oneTime:0,monthly:1,term:1,currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:todayIso,createdAt:todayIso})`);
importar(csv([fila(101, 'ACME', 'CCTV planta')]));
ok(E(`!!S.opps.find(o=>o.id==='oManual')&&!S.opps.find(o=>o.id==='oManual').fx`), 'la oportunidad manual sigue igual y sin vínculo');

console.log('4b. Archivo sin columna ID (Excel semanal actual): reconoce las importadas antes con ID');
E(`S.opps.forEach(o=>{if(o.fx&&o.fx.id==='101'){o.code='OP-2026-0001';}})`);
w.__csv = ['Estado;Título;Tipo de negocio;Monto Seg. Fisica;Monto Seg. Electronica;Probabilidad;Fecha de contacto;Generador',
  'Pipeline;CM-000101-01-ACME (CCTV planta);Recurrente;1000000;500000;50%;2026-09-01;Claudio Méndez',
  'Pipeline;MESSER (CCTV TILTIL);One Shot;0;500000;50%;2026-09-01;Claudio Méndez'].join('\n');
const nAntes = E('S.opps.length');
E(`FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__csv)).filter(r=>fxIsOwner(r.ej)));window.__news=FX_PLAN.news.map(r=>r.title);fxApply()`);
ok(!E('window.__news').includes('CM-000101-01-ACME (CCTV planta)'), 'una oportunidad importada antes con ID se reconoce por título aunque el archivo no traiga ID');
ok(E('S.opps.length') === nAntes + 1, 'solo la fila realmente nueva se agrega');
console.log('5. Resumen para Joan (api.php)');
fs.writeFileSync(require('os').tmpdir()+'/crm-datos.json', JSON.stringify({ rev: 1, savedAt: 0, data: JSON.parse(E('JSON.stringify(S)')) }));

console.log(fallas ? `\n${fallas} prueba(s) fallaron` : '\nTodas las pruebas pasaron');
process.exit(fallas ? 1 : 0);
