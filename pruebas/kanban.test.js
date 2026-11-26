// Kanban de oportunidades interactivo: arrastrar una tarjeta a otra columna, o usar «Mover» (celular y teclado).
// Booking, perdida y postergada piden sus datos; nadie mueve lo que no puede editar ni lo que viene del Excel.
// El servidor vuelve a revisar todo al guardar (permisos.test.php y guardado.test.php, sección «Mover»).
// Uso: node kanban.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
const ultimoToast = () => E('window.__toasts[window.__toasts.length-1]||""');
const opp = id => E(`JSON.parse(JSON.stringify(byId(S.opps,'${id}')||null))`);
const modal = () => doc.querySelector('#modalLayer');
function enviar(sel, valores) {
  const form = doc.querySelector(sel); if (!form) throw new Error('No encontré el formulario ' + sel);
  for (const [k, v] of Object.entries(valores)) { const el = form.querySelector(`[name="${k}"]`); if (!el) throw new Error('Falta el campo ' + k); el.value = v; }
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
}
// Arrastre simulado: jsdom no trae DataTransfer, así que se pasa uno mínimo
function soltar(id, destino) {
  const col = doc.querySelector(`[data-drop="${destino}"]`); if (!col) return false;
  const card = doc.querySelector(`[data-drag="${id}"]`);
  const dt = { _v: '', setData(_, v) { this._v = v; }, getData() { return this._v; } };
  if (card) { const ev = new w.Event('dragstart', { bubbles: true }); ev.dataTransfer = dt; card.dispatchEvent(ev); } else dt._v = id;
  const over = new w.Event('dragover', { bubbles: true, cancelable: true }); col.dispatchEvent(over);
  const ev = new w.Event('drop', { bubbles: true, cancelable: true }); ev.dataTransfer = dt; col.dispatchEvent(ev);
  return true;
}
const clic = sel => { const el = doc.querySelector(sel); if (!el) throw new Error('No encontré ' + sel); el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true })); };

const USERS = [{ id: 'g1', name: 'Marcela Ibarra', role: 'gerente', equipo: '' }, { id: 'sN', name: 'Rodrigo Salinas', role: 'subgerente', equipo: 'Norte' },
  { id: 'eN1', name: 'Paula Cifuentes', role: 'ejecutivo', equipo: 'Norte' }, { id: 'eN2', name: 'Tomás Vergara', role: 'ejecutivo', equipo: 'Norte' },
  { id: 'eS1', name: 'Daniela Rojas', role: 'ejecutivo', equipo: 'Sur' }];
const O = (id, ownerId, extra) => `{id:'${id}',code:'OP-261120-${id.slice(1)}',name:'Oportunidad ${id}',companyId:'cA',siteId:'',ownerId:'${ownerId}',status:'abierta',stage:'oferta',prob:50,crm:true,
  tipo:'Seg. Física',montos:{fis:1000000,ele:0,hab:0,one:0},primeraFact:'',currency:'CLP',monthly:1000000,oneTime:0,term:12,contactIds:[],services:[],history:[],
  stageEnteredAt:addDays(-10),createdAt:addDays(-20),closeDate:addDays(30),nextAction:'Llamar',nextActionDate:addDays(2)${extra ? ',' + extra : ''}}`;
const como = (quien, opps) => E(`S=cleanSeed();S.mode='real';S.users=${JSON.stringify(USERS)};S.currentUser='${quien}';UI.alcance='';UI.oppView='kanban';
  S.companies.push({id:'cA',legalName:'Eólica Pampa Alta SpA',tradeName:'Eólica Pampa Alta',industry:'Energía',sites:[],createdAt:todayIso});
  S.opps=[${opps.join(',')}];S.tasks=[];S.audit=[];location.hash='oportunidades';render();`);

console.log('1. Tarjetas que se pueden mover y columnas donde soltar');
como('sN', [O('o1', 'sN'), O('o2', 'eN1', "stage:'seguimiento',prob:60"), O('o3', 'eN2', "fx:{id:'9',title:'CM-261001-01-X',estado:'Pipeline'},crm:false"), O('o4', 'eN1', "stage:'adjudicada',status:'ganada',prob:100,wonAt:todayIso,wonDoc:'OC 1'")]);
ok(!!doc.querySelector('[data-drag="o1"][draggable="true"]') && !!doc.querySelector('[data-drag="o2"]'), 'las oportunidades abiertas propias y del equipo se pueden arrastrar');
ok(!!doc.querySelector('[data-drag="o1"] [data-action="moveMenu"]'), 'y tienen el botón «Mover» (para el celular o el teclado)');
ok(!doc.querySelector('[data-drag="o3"]'), 'la que viene del Excel de Gerencia no se arrastra');
ok(!doc.querySelector('[data-drag="o4"]') && !!doc.querySelector('.card[data-id="o4"]'), 'las de Booking se ven pero no se arrastran');
const drops = [...doc.querySelectorAll('.kanban [data-drop]')].map(c => c.dataset.drop);
ok(JSON.stringify(drops) === JSON.stringify(['oferta', 'seguimiento', 'negociacion', 'adjudicada']), 'se suelta en Pipeline, Upside, Forecast y Booking (no en Facturado): ' + drops.join(', '));
ok(!!doc.querySelector('.opp-exits [data-drop="perdida"]') && !!doc.querySelector('.opp-exits [data-drop="postergada"]'), 'y abajo están Perdida y Postergada');

console.log('2. Arrastrar a otra etapa');
soltar('o1', 'seguimiento');
let o = opp('o1');
ok(o.stage === 'seguimiento' && o.prob === 60, 'pasa a Upside con la probabilidad de la etapa (60 %)');
ok(o.history.length === 1 && o.history[0].from === 'oferta' && o.history[0].to === 'seguimiento' && o.history[0].by === 'sN', 'queda en el historial de etapas, con quién la movió');
ok(E(`S.audit.some(a=>a.entity==='${o.code}'&&a.field==='Etapa'&&a.oldV==='Pipeline'&&a.newV==='Upside'&&a.userId==='sN')`), 'y en la auditoría');
ok(!!doc.querySelector('[data-drop="seguimiento"] [data-id="o1"]'), 'la tarjeta aparece en la nueva columna');
ok(/Movida a Upside/.test(ultimoToast()), 'avisa el cambio: «' + ultimoToast() + '»');
soltar('o1', 'seguimiento');
ok(opp('o1').history.length === 1, 'soltarla en la misma columna no hace nada');
soltar('o2', 'oferta');
ok(opp('o2').stage === 'oferta' && opp('o2').prob === 50, 'también se puede devolver a una etapa anterior');

console.log('3. «Mover» sin arrastrar');
clic('[data-drag="o2"] [data-action="moveMenu"]');
ok(!doc.querySelector('#drawerLayer').innerHTML.trim(), 'el botón no abre la ficha');
const opciones = [...modal().querySelectorAll('[data-action="moveTo"]')].map(b => b.dataset.v + (b.disabled ? '*' : ''));
ok(JSON.stringify(opciones) === JSON.stringify(['oferta*', 'seguimiento', 'negociacion', 'adjudicada', 'perdida', 'postergada']), 'ofrece las etapas, Booking, Perdida y Postergada (la actual desactivada): ' + opciones.join(', '));
clic('#modalLayer [data-action="moveTo"][data-v="negociacion"]');
ok(opp('o2').stage === 'negociacion' && opp('o2').prob === 80 && !modal().innerHTML.trim(), 'elegir Forecast la mueve y cierra el menú');
E(`openEntity('opp','o2')`);
ok(!!doc.querySelector('#drawerLayer [data-action="moveMenu"]'), 'la ficha también tiene «Cambiar etapa»');
E('closeDrawer()');

console.log('4. Booking pide fecha, contrato u OC y primera facturación');
soltar('o2', 'adjudicada');
ok(opp('o2').status === 'abierta' && !!modal().querySelector('form[data-form="won"]'), 'soltarla en Booking no la cierra: abre el formulario');
ok(!!modal().querySelector('form[data-form="won"] [name="primeraFact"]'), 'pide la primera facturación (falta en esta oportunidad)');
enviar('form[data-form="won"]', { date: E('todayIso'), doc: '   ', primeraFact: '2027-01' });
ok(opp('o2').status === 'abierta' && /contrato u orden de compra/.test(ultimoToast()), 'sin contrato u OC no pasa: «' + ultimoToast() + '»');
enviar('form[data-form="won"]', { date: E('todayIso'), doc: 'OC 4500321', primeraFact: '' });
ok(opp('o2').status === 'abierta' && /primera facturación/.test(ultimoToast()), 'sin primera facturación tampoco');
enviar('form[data-form="won"]', { date: E('todayIso'), doc: 'OC 4500321', primeraFact: '2027-01' });
o = opp('o2');
ok(o.status === 'ganada' && o.stage === 'adjudicada' && o.prob === 100 && o.wonDoc === 'OC 4500321' && o.primeraFact === '2027-01' && o.closeDate === E('todayIso'), 'con todo queda en Booking, con su respaldo y primera facturación');
ok(o.history.some(h => h.to === 'adjudicada' && h.by === 'sN') && E(`S.tasks.some(t=>t.entityId==='o2'&&/Traspaso/.test(t.title))`), 'queda en el historial y crea la tarea de traspaso a operaciones');
ok(!doc.querySelector('[data-drag="o2"]') && !!doc.querySelector('[data-drop="adjudicada"] [data-id="o2"]'), 'ya en Booking, no se arrastra más');

console.log('5. Perdida y postergada');
soltar('o1', 'perdida');
ok(!!modal().querySelector('form[data-form="lost"]') && opp('o1').status === 'abierta', 'soltarla en Perdida pide motivo y comentario');
enviar('form[data-form="lost"]', { reason: '', comment: 'El cliente eligió otra empresa' });
ok(opp('o1').status === 'abierta' && /motivo/.test(ultimoToast()), 'sin motivo no se marca');
enviar('form[data-form="lost"]', { reason: 'Precio', comment: 'caro' });
ok(opp('o1').status === 'abierta', 'con un comentario que no explica nada tampoco');
enviar('form[data-form="lost"]', { reason: 'Precio', comment: 'Competidor ofertó 15 % menos' });
ok(opp('o1').status === 'perdida' && opp('o1').lossReason === 'Precio' && !doc.querySelector('.kanban [data-id="o1"]'), 'con motivo y comentario queda perdida y sale del Kanban');
como('eN1', [O('o5', 'eN1')]);
clic('[data-drag="o5"] [data-action="moveMenu"]'); clic('#modalLayer [data-action="moveTo"][data-v="postergada"]');
ok(!!modal().querySelector('form[data-form="post"]'), '«Mover» → Postergada pide la fecha de reactivación y el motivo');
enviar('form[data-form="post"]', { date: '2027-03-01', comment: '' });
ok(opp('o5').status === 'abierta', 'sin motivo no se posterga');
enviar('form[data-form="post"]', { date: '2027-03-01', comment: 'El mandante congeló el presupuesto hasta marzo' });
ok(opp('o5').status === 'postergada' && opp('o5').nextActionDate === '2027-03-01', 'con fecha y motivo queda postergada');

console.log('6. Nadie mueve lo que no puede editar');
como('eN1', [O('o6', 'eN1'), O('o7', 'eN2')]);
ok(!!doc.querySelector('[data-drag="o6"]') && !doc.querySelector('[data-drag="o7"]') && !doc.querySelector('.card[data-id="o7"] [data-action="moveMenu"]'), 'el ejecutivo arrastra la suya; la de otra persona (si llegara a verla) no tiene arrastre ni «Mover»');
E(`moveStage('o7','seguimiento')`);
ok(opp('o7').stage === 'oferta' && /fuera de tu alcance/.test(ultimoToast()), 'y forzar el cambio desde la consola no la mueve: «' + ultimoToast() + '»');
E(`openWon('o7')`); enviar('form[data-form="won"]', { date: E('todayIso'), doc: 'OC 1', primeraFact: '2027-01' });
ok(opp('o7').status === 'abierta', 'ni a Booking con el formulario');
E(`ACTIONS.lostOpp({id:'o7'})`); enviar('form[data-form="lost"]', { reason: 'Precio', comment: 'Intento de cerrar la ajena' });
ok(opp('o7').status === 'abierta', 'ni como perdida');
como('eS1', [O('o8', 'eS1'), O('o9', 'eN1')]);
ok(!!doc.querySelector('[data-drag="o8"]') && !doc.querySelector('[data-drag="o9"]'), 'otra subgerencia: lo mismo');
como('g1', [O('o10', 'eS1'), O('o11', 'eN2')]);
ok(!!doc.querySelector('[data-drag="o10"]') && !!doc.querySelector('[data-drag="o11"]'), 'la gerente mueve las de cualquier equipo');
soltar('o10', 'negociacion');
ok(opp('o10').stage === 'negociacion' && opp('o10').history[0].by === 'g1', 'y queda a su nombre en el historial');

console.log('7. En el celular');
w.innerWidth = 390; w.dispatchEvent(new w.Event('resize'));
E(`render()`);
ok(!!doc.querySelector('[data-drag="o11"] [data-action="moveMenu"]'), 'con pantalla angosta cada tarjeta sigue teniendo «Mover»');
ok(/usa «Mover»/.test(doc.querySelector('#view, main, body').textContent), 'y la pantalla explica que se puede arrastrar o usar «Mover»');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.slice(0, 3).join(' | ') : ''));
console.log(f ? `\n${f} pruebas fallaron` : '\nTodas las pruebas del Kanban pasaron');
process.exit(f ? 1 : 0);
