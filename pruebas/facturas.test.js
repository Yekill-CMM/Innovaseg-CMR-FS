// Facturas en las oportunidades de Booking: registro (neto, IVA, total, estado, comentario), validaciones,
// edición, eliminación, KPI de facturado y que el importador del funnel no las toque.
// Uso: node facturas.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
function enviar(valores) {
  const form = doc.querySelector('form[data-form="invoice"]'); if (!form) throw new Error('No encontré el formulario de factura');
  for (const [k, v] of Object.entries(valores)) {
    const el = form.querySelector(`[name="${k}"]`); if (!el) throw new Error('Falta el campo ' + k);
    el.value = v; el.dispatchEvent(new w.Event('input', { bubbles: true }));
  }
  const e0 = form.querySelector('[data-err]'); if (e0) e0.textContent = '';
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  const err = form.querySelector('[data-err]'); return err && doc.contains(form) ? err.textContent : '';
}
const anio = E('todayIso').slice(0, 4);
E(`S=cleanSeed();S.mode='real';S.companies.push({id:'cA',legalName:'Parque Solar SpA',tradeName:'Parque Solar',industry:'Energía',sites:[],createdAt:todayIso});
  const base={companyId:'cA',ownerId:'u1',oneTime:0,currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:todayIso,createdAt:todayIso,closeDate:todayIso,nextAction:'',nextActionDate:''};
  S.opps.push({...base,id:'oB',code:'CM-261110-01',name:'CCTV Parque',status:'ganada',stage:'adjudicada',prob:100,wonAt:todayIso,monthly:1000000,term:12,fx:{id:'',title:'CM-261110-01-Parque (CCTV Parque)',estado:'Booking',name:'CCTV Parque',proy:12000000}});
  S.opps.push({...base,id:'oU',code:'CM-261110-02',name:'Guardias',status:'abierta',stage:'seguimiento',prob:60,monthly:5000000,term:12,fx:{id:'',title:'CM-261110-02-Parque (Guardias)',estado:'Upside',name:'Guardias'}});
  migrate();`);
ok(Array.isArray(E('S.invoices')), 'la colección de facturas existe tras la migración');

console.log('1. Botón solo en Booking');
E(`openEntity('opp','oU')`);
ok(!doc.querySelector('#drawerLayer [data-action="newInvoice"]'), 'una oportunidad en Upside no tiene «Registrar factura»');
E(`openEntity('opp','oB')`);
ok(doc.querySelector('#drawerLayer [data-action="newInvoice"]'), 'una oportunidad en Booking sí lo tiene');
ok(/Sin facturas registradas/.test(doc.querySelector('#drawerLayer').textContent), 'la ficha muestra la sección de facturas vacía');

console.log('2. Registro y cálculo de IVA');
E(`ACTIONS.newInvoice({id:'oB'})`);
let form = doc.querySelector('form[data-form="invoice"]');
form.querySelector('[name=net]').value = '1000000'; form.querySelector('[name=net]').dispatchEvent(new w.Event('input', { bubbles: true }));
ok(form.querySelector('[name=iva]').value === '190000', 'al escribir el neto el IVA se calcula al 19 %');
ok(form.querySelector('[data-inv=total]').value === '$1.190.000', 'y el total se actualiza: ' + form.querySelector('[data-inv=total]').value);
let err = enviar({ number: '', net: '1000000', status: 'Emitida' });
ok(/Falta el N° de factura/.test(err), 'una factura emitida pide número');
err = enviar({ number: '4521', date: `${anio}-01-15`, net: '1000000', status: 'Emitida', comment: 'Enero, OC 4500123' });
ok(err === '', 'se registra la factura 4521');
const x = E(`JSON.parse(JSON.stringify(S.invoices[0]))`);
ok(x && x.oppId === 'oB' && x.net === 1000000 && x.iva === 190000 && x.total === 1190000 && x.status === 'Emitida' && x.comment === 'Enero, OC 4500123', 'guarda neto, IVA, total, estado y comentario');
ok(E(`S.audit.some(a=>a.entity==='CM-261110-01'&&a.field==='Factura'&&/4521/.test(a.newV))`), 'queda en la auditoría');
ok(E(`byId(S.opps,'oB').monthly`) === 1000000 && E(`byId(S.opps,'oB').status`) === 'ganada', 'no cambia los datos de la oportunidad');

console.log('3. Validaciones');
E(`ACTIONS.newInvoice({id:'oB'})`);
err = enviar({ number: '4521', net: '500000', status: 'Emitida' });
ok(/ya está registrada en CM-261110-01/.test(err), 'no permite repetir el N° de factura');
err = enviar({ number: '', net: '0', status: 'Por emitir' });
ok(/monto neto/.test(err), 'pide un neto mayor que cero');
err = enviar({ number: '', date: `${anio}-02-15`, net: '1000000', status: 'Por emitir' });
ok(err === '' && E('S.invoices.length') === 2, 'una factura «Por emitir» puede ir sin número');
E(`ACTIONS.newInvoice({id:'oB'})`);
form = doc.querySelector('form[data-form="invoice"]');
form.querySelector('[name=iva]').value = '0'; form.querySelector('[name=iva]').dispatchEvent(new w.Event('input', { bubbles: true }));
err = enviar({ number: '4600', date: `${anio}-03-15`, net: '200000', status: 'Pagada' });
ok(err === '' && E(`S.invoices.find(x=>x.number==='4600').iva`) === 0 && E(`S.invoices.find(x=>x.number==='4600').total`) === 200000, 'factura exenta: el IVA escrito a mano (0) se respeta');
E(`ACTIONS.newOpp?0:0;ACTIONS.newInvoice({id:'oU'})`);
ok(!doc.querySelector('form[data-form="invoice"][data-opp="oU"]') && /Booking/.test(E('window.__toasts.slice(-1)[0]')), 'no abre el formulario en una oportunidad que no está en Booking');

console.log('4. Totales en la ficha, Hoy, Kanban y Reportes');
E(`openEntity('opp','oB')`);
const ficha = doc.querySelector('#drawerLayer').textContent;
ok(/Facturado \(neto\)\$1\.200\.000/.test(ficha.replace(/\s+/g, '')) || /\$1\.200\.000/.test(ficha), 'la ficha suma lo facturado (emitida + pagada): $1.200.000');
ok(/Por cobrar.*\$1\.190\.000/s.test(ficha), 'y lo por cobrar con IVA (solo emitidas): $1.190.000');
ok(/Por emitir.*\$1\.000\.000/s.test(ficha), 'y lo por emitir aparte');
E(`closeDrawer();location.hash='hoy';render()`);
ok(/Facturado en el año\s*\$1,2 MM/.test(doc.querySelector('#main').textContent), 'Hoy muestra «Facturado en el año»');
E(`location.hash='oportunidades';UI.oppView='kanban';render()`);
ok(/Facturado \$1\.200\.000/.test(doc.querySelector('#main').textContent) || /Facturado \$1,2 MM/.test(doc.querySelector('#main').textContent), 'la tarjeta de Booking muestra lo facturado');
E(`location.hash='reportes';UI.repTab='opps';render()`);
ok(/Facturado del año/.test(doc.querySelector('#main').textContent), 'Reportes muestra «Facturado del año»');

console.log('5. Edición, anulación y eliminación');
const id = E(`S.invoices.find(x=>x.number==='4521').id`);
E(`ACTIONS.editInvoice({id:'${id}'})`);
err = enviar({ status: 'Anulada', comment: 'Anulada con NC 12' });
ok(err === '' && E(`byId(S.invoices,'${id}').status`) === 'Anulada', 'se puede anular');
ok(E(`invoicedNet(oppInvoices('oB'))`) === 200000, 'una anulada deja de sumar');
ok(E(`S.audit.some(a=>a.field==='Factura'&&/Emitida/.test(a.oldV)&&/Anulada/.test(a.newV))`), 'el cambio de estado queda en la auditoría');
E(`ACTIONS.newInvoice({id:'oB'})`);
err = enviar({ number: '4521', date: `${anio}-04-15`, net: '1000000', status: 'Emitida' });
ok(err === '', 'el número de una anulada se puede volver a usar');
const n = E('S.invoices.length');
E(`ACTIONS.delInvoice({id:'${id}'})`);
ok(doc.querySelector('#modalLayer [data-action="doDelInvoice"]'), 'eliminar pide confirmación');
E(`ACTIONS.doDelInvoice({id:'${id}'})`);
ok(E('S.invoices.length') === n - 1 && E(`S.audit.some(a=>a.field==='Factura eliminada')`), 'se elimina y queda en la auditoría');

console.log('6. El importador del funnel no toca las facturas');
const antes = E('JSON.stringify(S.invoices)');
w.__c = ['ID;Estado;Título;Tipo de negocio;Monto Seg. Fisica;Monto Seg. Electronica;Probabilidad;Fecha de contacto;Generador',
  `1;Booking;CM-261110-01-Parque (CCTV Parque);Seg. Electrónica;0;1500000;100%;${anio}-01-01;Claudio Méndez`].join('\n');
E(`FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__c)).filter(r=>fxIsOwner(r.ej)));fxApply()`);
ok(E('JSON.stringify(S.invoices)') === antes, 'las facturas quedan iguales tras importar');

console.log('7. Color por facturación en Booking');
const card = () => doc.querySelector('.kanban .card[data-id="oB"]');
E(`location.hash='oportunidades';UI.oppView='kanban';render()`);
ok(card() && card().classList.contains('inv-pend') && !card().classList.contains('inv-ok'), 'facturada a medias: tono rojo');
ok(!doc.querySelector('.kanban .card[data-id="oU"]')?.className.match(/inv-/), 'una oportunidad que no está en Booking no lleva color');
const falta = E(`tcv(byId(S.opps,'oB'))-invoicedNet(oppInvoices('oB'))`);
E(`S.invoices.push({id:'faX',oppId:'oB',name:'x',number:'9999',date:todayIso,net:${falta},iva:0,total:${falta},status:'Pagada',comment:''});render()`);
ok(card() && card().classList.contains('inv-ok') && /100%/.test(card().textContent), 'facturada al 100 %: tono verde y muestra 100%');
E(`UI.oppView='tabla';UI.oppStatus='';render()`);
const fila = doc.querySelector('tr[data-id="oB"]');
ok(fila && fila.classList.contains('inv-ok'), 'en la tabla la fila también va en verde');
E(`S.invoices=S.invoices.filter(x=>x.oppId!=='oB');UI.oppView='kanban';render()`);
ok(card() && card().classList.contains('inv-pend') && /Sin facturar/.test(card().textContent), 'sin facturas: tono rojo y «Sin facturar»');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
console.log(f ? `\n${f} pruebas fallaron` : '\nTodo bien'); process.exit(f ? 1 : 0);
