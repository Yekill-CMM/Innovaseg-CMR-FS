// Acta de recepción de Instalaciones en oportunidades de Booking: preparación con datos de la oportunidad,
// validaciones por estado, cierre de la tarea de traspaso, documento imprimible y que el importador no la toque.
// Uso: node recepcion.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
// pestaña nueva simulada: guarda lo que se escribe
E(`window.__pest=null;window.open=()=>{const p={html:'',document:{open(){},write(h){p.html+=h},close(){}}};window.__pest=p;return p}`);
function enviar(valores, ver) {
  const form = doc.querySelector('form[data-form="recepcion"]'); if (!form) throw new Error('No encontré el formulario del acta');
  for (const [k, v] of Object.entries(valores)) {
    if (k === 'docs') { form.querySelectorAll('input[name=docs]').forEach(c => { c.checked = v.includes(c.value); }); continue; }
    const el = form.querySelector(`[name="${k}"]`); if (!el) throw new Error('Falta el campo ' + k);
    if (el.type === 'checkbox') el.checked = !!v; else el.value = v;
  }
  const e0 = form.querySelector('[data-err]'); if (e0) e0.textContent = '';
  const ev = new w.Event('submit', { bubbles: true, cancelable: true });
  if (ver) Object.defineProperty(ev, 'submitter', { value: form.querySelector('[data-ver="1"]') });
  form.dispatchEvent(ev);
  const err = form.querySelector('[data-err]'); return err && doc.contains(form) ? err.textContent : '';
}
E(`S=cleanSeed();S.mode='real';S.companies.push({id:'cA',legalName:'Parque Solar SpA',tradeName:'Parque Solar',rut:'76.123.456-0',industry:'Energía',sites:[{id:'s1',name:'Planta Taltal',city:'Taltal'}],createdAt:todayIso});
  S.contacts.push({id:'k1',companyId:'cA',firstName:'Ana',lastName:'Pérez',position:'Jefa de planta',phone:'+56911111111',email:'ana@parque.cl'});
  const base={companyId:'cA',ownerId:'u1',oneTime:0,currency:'CLP',services:['CCTV','Monitoreo 24/7'],history:[],stageEnteredAt:todayIso,createdAt:todayIso,closeDate:todayIso,nextAction:'',nextActionDate:''};
  S.opps.push({...base,id:'oB',code:'CM-261110-01',name:'CCTV Parque Taltal',siteId:'s1',contactIds:['k1'],status:'ganada',stage:'adjudicada',prob:100,wonAt:todayIso,wonDoc:'OC 4500123',monthly:1000000,term:12,fx:{title:'CM-261110-01-Parque (CCTV Parque Taltal)',estado:'Booking',name:'CCTV Parque Taltal',cost:7000000,notes:'Incluye 40 cámaras'}});
  S.opps.push({...base,id:'oU',code:'CM-261110-02',name:'Guardias',contactIds:[],status:'abierta',stage:'seguimiento',prob:60,monthly:5000000,term:12,fx:{title:'x',estado:'Upside',name:'Guardias'}});
  migrate();runAutomations();`);
ok(Array.isArray(E('S.recepciones')), 'la colección de actas existe tras la migración');
ok(E(`S.tasks.some(t=>t.auto==='booking:oB'&&isOpenTask(t))`), 'el Booking tiene su tarea automática de traspaso');

console.log('1. Solo en Booking, y avisa que falta');
E(`openEntity('opp','oU')`);
ok(!doc.querySelector('#drawerLayer [data-action="recForm"]'), 'una oportunidad en Upside no tiene acta');
E(`openEntity('opp','oB')`);
ok(/Falta el acta de recepción/.test(doc.querySelector('#drawerLayer').textContent), 'la ficha de Booking avisa que falta el acta');
E(`location.hash='oportunidades';UI.oppView='kanban';render()`);
ok(/Acta pendiente/.test(doc.querySelector('.kanban .card[data-id="oB"]').textContent), 'la tarjeta de Booking dice «Acta pendiente»');
E(`ACTIONS.recForm({id:'oU'})`);
ok(!doc.querySelector('form[data-form="recepcion"]'), 'no abre el acta para una oportunidad que no está en Booking');

console.log('2. Se prepara con los datos de la oportunidad');
E(`ACTIONS.recForm({id:'oB'})`);
const form = doc.querySelector('form[data-form="recepcion"]');
ok(form.querySelector('[name=site]').value === 'Planta Taltal, Taltal', 'trae el sitio');
ok(form.querySelector('[name=contactId]').value === 'k1', 'trae el contacto');
ok(/CCTV Parque Taltal/.test(form.querySelector('[name=scope]').value) && /Monitoreo 24\/7/.test(form.querySelector('[name=scope]').value) && /40 cámaras/.test(form.querySelector('[name=scope]').value), 'el alcance parte con el nombre, los servicios y las notas del funnel');
ok(form.querySelector('input[name=docs][value="Orden de compra o contrato"]').checked, 'marca la OC porque la oportunidad tiene respaldo');
let err = enviar({ status: 'Entregada a Instalaciones', deliveredAt: '' });
ok(/fecha en que se entregó/.test(err), 'entregada pide fecha de entrega');
err = enviar({ status: 'Borrador', pm: 'Pedro Soto' }, true);
ok(err === '' && E(`recOf('oB').status`) === 'Borrador' && E(`recOf('oB').code`) === 'AR-CM-261110-01', 'se guarda en borrador con código AR-CM…');
ok(E('window.__pest') && /Acta de recepción de proyecto/.test(E('window.__pest.html')), '«Guardar y ver acta» abre el documento');

console.log('3. El documento');
const html = E('window.__pest.html');
ok(/Parque Solar SpA/.test(html) && /76\.123\.456-0/.test(html) && /Ana Pérez/.test(html) && /OC 4500123/.test(html), 'trae cliente, RUT, contacto y respaldo de adjudicación');
ok(/Costo del proyecto/.test(html) && /\$7\.000\.000/.test(html), 'incluye montos y costo si se marca');
ok(/Entrega: Comercial/.test(html) && /Recibe: Instalaciones/.test(html) && /Claudio Méndez/.test(html), 'tiene los dos bloques de firma');
ok(/window\.print\(\)/.test(html) && /@media print/.test(html), 'se imprime o guarda en PDF desde la pestaña');
E(`ACTIONS.recForm({id:'oB'})`);
enviar({ showAmounts: false });
E(`ACTIONS.recDoc({id:'oB'})`);
ok(!/Costo del proyecto/.test(E('window.__pest.html')), 'sin montos si se desmarca');

console.log('4. Recepción');
E(`ACTIONS.recForm({id:'oB'})`);
err = enviar({ status: 'Recibida', deliveredAt: E('todayIso'), receiver: '', receivedAt: '' });
ok(/quién recibe/.test(err), 'recibida pide quién recibe');
err = enviar({ status: 'Recibida con observaciones', receiver: 'Luis Rojas', receivedAt: E('todayIso'), observations: '' });
ok(/observaciones/.test(err), 'con observaciones pide escribirlas');
err = enviar({ status: 'Recibida', receiver: 'Luis Rojas', receivedAt: E('addDays(-5)') });
ok(/anterior a la entrega/.test(err), 'la recepción no puede ser anterior a la entrega');
err = enviar({ status: 'Recibida', receiver: 'Luis Rojas', receivedAt: E('todayIso'), docs: ['Oferta técnica', 'Orden de compra o contrato'] });
ok(err === '' && E(`recOf('oB').status`) === 'Recibida' && E(`recOf('oB').receiver`) === 'Luis Rojas', 'queda recibida por Luis Rojas');
ok(!E(`S.tasks.some(t=>t.auto==='booking:oB'&&isOpenTask(t))`), 'se completa la tarea de traspaso a operaciones');
ok(E(`S.audit.some(a=>a.entity==='CM-261110-01'&&a.field==='Acta de recepción'&&/Recibida/.test(a.newV))`), 'queda en la auditoría');
E(`runAutomations()`);
ok(!E(`S.tasks.some(t=>t.auto==='booking:oB'&&isOpenTask(t))`), 'las automatizaciones no vuelven a crear la tarea');
E(`openEntity('opp','oB')`);
const ficha = doc.querySelector('#drawerLayer').textContent;
ok(/AR-CM-261110-01/.test(ficha) && /Luis Rojas/.test(ficha) && /2 de 9/.test(ficha), 'la ficha muestra el acta, quién recibió y los documentos (2 de 9)');
E(`closeDrawer();render()`);
ok(/Recibida/.test(doc.querySelector('.kanban .card[data-id="oB"]').textContent), 'la tarjeta dice «Recibida»');

console.log('5. El importador no toca el acta');
const antes = E('JSON.stringify(S.recepciones)');
w.__c = ['ID;Estado;Título;Tipo de negocio;Monto Seg. Fisica;Monto Seg. Electronica;Probabilidad;Fecha de contacto;Generador',
  `1;Booking;CM-261110-01-Parque (CCTV Parque Taltal);Seg. Electrónica;0;1500000;100%;2026-01-01;Claudio Méndez`].join('\n');
E(`FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__c)).filter(r=>fxIsOwner(r.ej)));fxApply()`);
ok(E('JSON.stringify(S.recepciones)') === antes, 'el acta queda igual tras importar');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
console.log(f ? `\n${f} pruebas fallaron` : '\nTodo bien'); process.exit(f ? 1 : 0);
