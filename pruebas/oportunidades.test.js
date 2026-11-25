// Oportunidades: fuente única = Excel de Gerencia. 4 etapas, código CM, prospecto que reserva su CM y se vincula al importar,
// datos comerciales en solo lectura, sin módulo de ofertas aparte.
// Uso: node oportunidades.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
const visible = () => [...w.document.body.children].filter(e => e.tagName !== 'SCRIPT').map(e => e.textContent).join(' ');
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
function enviar(sel, valores) {
  const form = doc.querySelector(sel); if (!form) throw new Error('No encontré el formulario ' + sel);
  for (const [k, v] of Object.entries(valores)) { const el = form.querySelector(`[name="${k}"]`); if (!el) throw new Error('Falta el campo ' + k); el.value = v; }
  const e0 = form.querySelector('[data-err]'); if (e0) e0.textContent = '';
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  const err = form.querySelector('[data-err]'); return err ? err.textContent : '';
}
const hoy = E('todayIso'), k = hoy.slice(2, 4) + hoy.slice(5, 7) + hoy.slice(8, 10);
const CAB = 'ID;Estado;Título;Tipo de negocio;Monto Seg. Fisica;Monto Seg. Electronica;Probabilidad;Fecha de contacto;Generador';
const imp = rows => { w.__c = [CAB, ...rows].join('\n'); return E(`(()=>{FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__c)).filter(r=>fxIsOwner(r.ej)));fxPreview(FX_PLAN);const prev=document.querySelector('#modalLayer').textContent;const p=FX_PLAN;const i={news:p.news.length,prev,ch:p.changes.filter(x=>!x.quiet).map(x=>x.ch.map(c=>c[0]))};fxApply();return i})()`); };
const opp = code => E(`JSON.parse(JSON.stringify(S.opps.find(o=>o.code==='${code}')||null))`);

console.log('1. Migración: lo que estaba en "En Desarrollo" pasa a Pipeline');
E(`S=cleanSeed();S.v=2;S.mode='real';S.companies.push({id:'cA',legalName:'ACME',tradeName:'ACME',industry:'Energía',sites:[],createdAt:todayIso});
  S.opps.push({id:'oV',code:'CM-250314-01',name:'Antigua',companyId:'cA',status:'abierta',stage:'ingenieria',prob:40,ownerId:'u1',oneTime:0,monthly:1000000,term:12,currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:'2026-10-01',createdAt:'2026-10-01',closeDate:'2026-12-31',nextAction:'x',nextActionDate:'2026-12-01',fx:{id:'',title:'CM-250314-01-ACME (Antigua)',estado:'En Desarrollo',name:'Antigua'}});
  migrate();`);
ok(E(`byId(S.opps,'oV').stage`) === 'oferta', 'la oportunidad queda en Pipeline');
ok(E(`byId(S.opps,'oV').history.some(h=>h.from==='ingenieria'&&h.to==='oferta')`), 'el cambio queda en su historial de etapas');
ok(E(`S.audit.some(a=>a.entity==='CM-250314-01'&&/nueva política/.test(a.field))`), 'y en la auditoría');
E('migrate()');
ok(E(`byId(S.opps,'oV').history.length`) === 1, 'correr la migración dos veces no repite el cambio');

console.log('2. Fuente única: el CRM no crea oportunidades');
E(`location.hash='oportunidades';render()`);
ok(!doc.querySelector('[data-action="newOpp"]'), 'no hay botón "Nueva oportunidad"');
E(`ACTIONS.newOpp({})`);
ok(/solo del Excel/.test(E('window.__toasts.slice(-1)[0]')), 'si se intenta, explica que vienen solo del Excel');
const cols = [...doc.querySelectorAll('.kanban .col h2')].map(h => h.firstChild.textContent.trim());
ok(JSON.stringify(cols) === JSON.stringify(['Pipeline', 'Upside', 'Forecast', 'Booking', 'Facturado']), 'Kanban con 4 etapas más Facturado: ' + cols.join(', '));
const txt = doc.querySelector('.kanban').textContent;
ok(/Oferta en desarrollo/.test(txt) && /Oferta entregada/.test(txt) && /Adjudicada verbalmente/.test(txt) && /Cerrada con contrato/.test(txt), 'cada columna dice qué significa');
ok(/Antigua/.test(txt) && !/no viene en el Excel/.test(visible()), 'la oportunidad del Excel queda en Pipeline, sin avisos de pendientes');
ok(!doc.querySelector('.kanban [draggable="true"]'), 'las tarjetas no se arrastran: la etapa viene del Excel');

console.log('3. Prospecto que pasa a oferta: no crea código ni oportunidad, espera el Excel');
E(`S.leads.push({id:'lP',code:'PR-2026-0001',name:'CCTV Parque Taltal',companyId:'cA',contactId:'',source:'Referido',stage:'Levantamiento',potential:'Alto',problem:'Robos en el parque',service:'CCTV',ownerId:'u1',nextAction:'x',nextActionDate:todayIso,vars:{},createdAt:todayIso});
  ACTIONS.convertLead({id:'lP'})`);
ok(!doc.querySelector('form[data-form="convert"] [name="code"]'), 'no pide ni genera código CM');
const n0 = E('S.opps.length');
let err = enviar('form[data-form="convert"]', { note: 'Oferta a cargo de ingeniería' });
ok(err === '' && E(`byId(S.leads,'lP').stage`) === 'Oportunidad creada' && E('S.opps.length') === n0, 'el prospecto pasa a "Oportunidad creada" sin crear oportunidad');
ok(/Esperando la oportunidad en el Excel/.test(doc.querySelector('#drawerLayer').textContent) && doc.querySelector('#drawerLayer [data-action="linkLead"]'), 'la ficha del prospecto dice que espera el Excel y ofrece vincular');
E('closeDrawer()');

console.log('4. El Excel trae la oferta: se vincula desde la ficha del prospecto');
let r = imp(['501;Upside;CM-261001-07-ACME (CCTV y analítica Parque Taltal);Seg. Electrónica;0;3000000;60%;2026-10-01;Claudio Méndez']);
const v = opp('CM-261001-07');
ok(v && v.stage === 'seguimiento' && v.prob === 60 && v.name === 'CCTV y analítica Parque Taltal', 'la oportunidad llega con código, etapa, probabilidad y nombre del Excel');
E(`ACTIONS.linkLead({id:'lP'})`);
ok(doc.querySelector('form[data-form="linkLead"] option[value="'+v.id+'"]'), 'el selector ofrece la oportunidad del mismo cliente');
enviar('form[data-form="linkLead"]', { oppId: v.id });
ok(E(`byId(S.opps,'${v.id}').leadId`) === 'lP' && E(`byId(S.leads,'lP').oppId`) === v.id, 'queda ligada al prospecto (y el prospecto a ella)');
E(`openEntity('lead','lP')`);
ok(/Convertido en oportunidad CM-261001-07/.test(doc.querySelector('#drawerLayer').textContent), 'el prospecto ahora apunta a la oportunidad');
E(`closeDrawer();S.leads.push({id:'lR',code:'PR-2026-0003',name:'Otro ACME',companyId:'cA',stage:'Calificación',ownerId:'u1',vars:{},createdAt:todayIso});ACTIONS.convertLead({id:'lR'})`);
ok(!doc.querySelector('form[data-form="convert"] option[value="'+v.id+'"]'), 'una oportunidad ya vinculada no se ofrece a otro prospecto');
E('closeModal()');

console.log('5. Reimportar actualiza la misma oportunidad (por su código CM)');
r = imp(['501;Upside;CM-261001-07-ACME (CCTV y analítica Parque Taltal);Recurrente;0;3000000;60%;2026-10-01;Claudio Méndez',
         '502;Pipeline;CM-250314-01-ACME (Monitoreo norte);Recurrente;0;1000000;30%;2026-03-14;Claudio Méndez']);
ok(r.news === 0 && E('S.opps.length') === 2 && opp('CM-250314-01').id === 'oV', 'actualiza la oportunidad existente sin duplicar');
ok(opp('CM-250314-01').name === 'Monitoreo norte' && opp('CM-250314-01').prob === 30, 'y toma nombre y probabilidad del Excel');

console.log('6. Nombre siempre desde el Excel');
imp(['501;Upside;CM-261001-07-ACME (CCTV térmico Taltal fase 1);Recurrente;0;3000000;60%;2026-10-01;Claudio Méndez',
     '502;Pipeline;CM-250314-01-ACME (Monitoreo norte);Recurrente;0;1000000;30%;2026-03-14;Claudio Méndez']);
ok(opp('CM-261001-07').name === 'CCTV térmico Taltal fase 1', 'si cambia el título en el Excel, cambia el nombre');

console.log('7. Excel antiguo con "En Desarrollo"');
imp(['501;Upside;CM-261001-07-ACME (CCTV térmico Taltal fase 1);Recurrente;0;3000000;60%;2026-10-01;Claudio Méndez',
     '502;Pipeline;CM-250314-01-ACME (Monitoreo norte);Recurrente;0;1000000;30%;2026-03-14;Claudio Méndez',
     '503;En Desarrollo;CM-261010-01-ACME (Guardias CD);Recurrente;800000;0;20%;2026-10-10;Claudio Méndez']);
ok(opp('CM-261010-01').stage === 'oferta', 'lo importa en Pipeline');

console.log('8. Ficha: solo estatus y tareas');
const idV = opp('CM-261001-07').id;
E(`S.config.auto.on=0;S.tasks=S.tasks.filter(t=>t.entityId!=='${idV}');openEntity('opp','${idV}')`);
let dr = doc.querySelector('#drawerLayer');
ok(/Estatus \(Excel de Gerencia\)/.test(dr.textContent) && /Solo lectura/.test(dr.textContent), 'muestra el estatus del Excel como solo lectura');
ok(!dr.querySelector('input:not([type=checkbox]),select,textarea'), 'no hay ningún campo editable de la oportunidad');
ok(!dr.querySelector('[data-action="wonOpp"],[data-action="lostOpp"],[data-action="postOpp"]'), 'no hay botones para Booking, perdida o postergar');
ok(dr.querySelector('.d-actions .btn.pri')?.dataset.action === 'newTask', 'la acción principal es "Nueva tarea"');
ok(/Título en el Excel/.test(dr.textContent), 'muestra el título tal como viene en el Excel');
ok(/Sin tarea pendiente/.test(dr.textContent) && E(`computeAlerts().some(a=>a.id==='${idV}'&&a.kind==='Oportunidad sin tarea pendiente')`), 'sin tareas: aviso en la ficha y alerta "sin tarea pendiente"');
E(`ACTIONS.newTask({ent:'opp:${idV}'})`);
enviar('form[data-form="task"]', { title: 'Llamar a la gerencia de O&M', due: '2026-12-02', priority: 'Alta' });
ok(E(`nextTask(byId(S.opps,'${idV}'))?.title`) === 'Llamar a la gerencia de O&M', 'se crea la tarea sobre la oportunidad');
ok(!E(`computeAlerts().some(a=>a.id==='${idV}'&&a.group==='regla')`), 'con una tarea al día, la alerta desaparece');
E(`location.hash='oportunidades';render()`);
ok(/Llamar a la gerencia de O&M/.test(doc.querySelector('.kanban').textContent), 'la tarjeta muestra la próxima tarea');
ok(opp('CM-261001-07').prob === 60 && opp('CM-261001-07').stage === 'seguimiento', 'los datos del Excel no cambian');
E(`(()=>{const t=nextTask(byId(S.opps,'${idV}'));t.due=addDays(-2)})()`);
ok(E(`computeAlerts().some(a=>a.id==='${idV}'&&a.kind==='Tarea vencida en la oportunidad')`), 'si la tarea vence, alerta "tarea vencida"');
E(`(()=>{const o=byId(S.opps,'${idV}');S.activities=S.activities.filter(a=>a.entityId!==o.id);delete o.fx.ult;const t=nextTask(o);ACTIONS.doneTask({id:t.id},null,{stopPropagation(){}})})()`);
ok(E(`lastActOpp(byId(S.opps,'${idV}'))`) === hoy, 'completar una tarea cuenta como gestión de la oportunidad');
E(`ACTIONS.quick({ent:'opp:${idV}'})`);
enviar('form[data-form="quick"]', { type: 'Llamada', desc: 'Conversación con O&M', nextAction: 'Enviar aclaración técnica', nextActionDate: '2026-12-05' });
ok(E(`nextTask(byId(S.opps,'${idV}'))?.title`) === 'Enviar aclaración técnica', 'registrar una actividad con próxima acción crea la tarea en la oportunidad');
E(`closeDrawer();moveStage('${idV}','adjudicada')`);
ok(opp('CM-261001-07').stage === 'seguimiento' && /viene del Excel/.test(E('window.__toasts.slice(-1)[0]')), 'mover de etapa no está permitido');
E(`openEntity('opp','${idV}')`);
ok(/Origen: PR-2026-0001/.test(doc.querySelector('#drawerLayer').textContent), 'muestra el prospecto de origen');
E('closeDrawer()');

console.log('9. Ya no hay módulo de ofertas: la oportunidad es la oferta');
ok(![...doc.querySelectorAll('nav a, nav button')].some(x => /^\s*Ofertas/.test(x.textContent)), 'el menú ya no tiene "Ofertas"');
E(`S.offers.push({id:'ofV',code:'OF-2026-001',oppId:'${idV}',ownerId:'u1',status:'Enviada',sentAt:'2026-09-01',validUntil:'2026-09-30',versions:[{n:1,net:1000000,locked:true,date:'2026-09-01'}]})`);
E(`openEntity('opp','${idV}')`);
const fichaO = doc.querySelector('#drawerLayer').innerHTML;
ok(!/data-action="newOffer"/.test(fichaO), 'la ficha no ofrece "Nueva oferta"');
ok(/Ofertas registradas antes \(solo consulta\)/.test(fichaO), 'una OF antigua se sigue viendo como historial');
E(`closeDrawer();openEntity('offer','ofV')`);
ok(/solo para consulta/.test(doc.querySelector('#drawerLayer').textContent) && !doc.querySelector('#drawerLayer [data-action="newVersion"],#drawerLayer [data-action="sendOffer"]'), 'la OF antigua se abre sin acciones');
E(`closeDrawer()`);
ok(!E("computeAlerts().some(a => a.entity === 'offer')"), 'las OF antiguas ya no generan alertas');
E(`(()=>{const o=byId(S.opps,'${idV}');o.stageEnteredAt=addDays(-10);delete o.fx.pres;delete o.fx.ult;S.activities=S.activities.filter(a=>a.entityId!==o.id);S.tasks=S.tasks.filter(t=>t.entityId!==o.id)})()`);
const alU = E(`JSON.parse(JSON.stringify(computeAlerts().filter(a=>a.group==='oferta')))`);
ok(alU.length === 1 && alU[0].sev === 'warn' && /10 días sin seguimiento/.test(alU[0].detail), 'Upside hace 10 días sin gestión: alerta amarilla de seguimiento');
E(`location.hash='hoy';render()`);
ok(/Ofertas entregadas que debo seguir/.test(visible()) && /CM-261001-07/.test(visible()), 'Hoy la muestra en "Ofertas entregadas que debo seguir"');
E(`location.hash='oportunidades';render()`);
E(`openEntity('opp','${idV}')`);
ok(/Oferta entregada\..*10 días sin seguimiento desde la entrega/.test(doc.querySelector('#drawerLayer').textContent), 'la ficha muestra los días sin seguimiento de la oferta');
E('closeDrawer()');
E(`ACTIONS.quick({ent:''})`);
ok(!/optgroup label="Ofertas"/.test(doc.querySelector('#modalLayer').innerHTML), 'al registrar una actividad no se elige una oferta aparte');
E('closeModal()');

console.log('10. Automatizaciones: ninguna oportunidad queda sin gestión');
E(`S.config.auto={on:1,noTask:1,idle:1,follow:1,stage:1};S.tasks=[];
  S.opps.forEach(o=>{if(o.status==='abierta'&&o.fx){delete o.fx.ult;delete o.fx.pres}});
  S.activities=S.activities.filter(a=>a.entityType!=='opp');
  const a=S.opps.find(o=>o.code==='CM-250314-01');a.stage='oferta';a.createdAt=addDays(-15);
  const b=S.opps.find(o=>o.code==='CM-261001-07');b.stage='seguimiento';b.stageEnteredAt=addDays(-4);b.createdAt=addDays(-4);
  const c=S.opps.find(o=>o.code==='CM-261010-01');c.stage='negociacion';c.createdAt=todayIso;
  S.activities.push({id:'aG',date:todayIso,userId:'u1',type:'Llamada',entityType:'opp',entityId:c.id,companyId:c.companyId,desc:'Gestión real',result:'',nextAction:'',nextActionDate:''});
  render()`);
const tareas = E(`JSON.parse(JSON.stringify(S.tasks.map(t=>({code:byId(S.opps,t.entityId)?.code,title:t.title,prio:t.priority,due:t.due,auto:t.auto}))))`);
const de = c => tareas.filter(t => t.code === c);
ok(de('CM-250314-01').some(t => /Contactar al cliente: 15 días sin gestión/.test(t.title) && t.prio === 'Alta' && t.due === hoy), '15 días sin gestión → tarea urgente "Contactar al cliente" para hoy');
ok(de('CM-261001-07').some(t => /Seguimiento de la oferta entregada/.test(t.title)), 'oferta entregada hace 4 días sin gestión → tarea de seguimiento');
ok(de('CM-261010-01').some(t => /Confirmar adjudicación y pedir contrato u OC/.test(t.title)), 'en Forecast → tarea para pedir contrato u OC');
ok(tareas.every(t => /^(sin-gestion|seguimiento|forecast|sin-tarea|booking):/.test(t.auto)), 'todas quedan marcadas como automáticas');
ok(E(`S.audit.some(a=>a.field==='Tarea automática')`), 'y registradas en la auditoría');
const n1 = E('S.tasks.length'); E('render();render()');
ok(E('S.tasks.length') === n1, 'volver a correr no duplica tareas');
E(`(()=>{const c=S.opps.find(o=>o.code==='CM-261010-01');S.tasks.filter(t=>t.entityId===c.id).forEach(t=>{t.status='Completada';t.completedAt=todayIso});render()})()`);
const tc = E(`JSON.parse(JSON.stringify(S.tasks.filter(t=>t.entityId===S.opps.find(o=>o.code==='CM-261010-01').id&&isOpenTask(t)).map(t=>t.title)))`);
ok(tc.length === 1 && /Definir la próxima gestión/.test(tc[0]), 'al completar todo, crea "Definir la próxima gestión" (y no repite la de Forecast)');
w.__c = [CAB, '501;Booking;CM-261001-07-ACME (CCTV térmico Taltal fase 1);Recurrente;0;3000000;100%;2026-10-01;Claudio Méndez',
  '502;Pipeline;CM-250314-01-ACME (Monitoreo norte);Recurrente;0;1000000;30%;2026-03-14;Claudio Méndez',
  '503;Forecast;CM-261010-01-ACME (Guardias CD);Recurrente;800000;0;80%;2026-10-10;Claudio Méndez'].join('\n');
E(`FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__c)).filter(r=>fxIsOwner(r.ej)));fxApply()`);
ok(E(`S.tasks.some(t=>t.auto==='booking:'+S.opps.find(o=>o.code==='CM-261001-07').id)`), 'el Excel la pasa a Booking → tarea "Traspaso a operaciones"');
ok(!E(`S.activities.filter(a=>a.entityType==='opp').some(a=>!isSysAct(a)&&/Actualización del funnel/.test(a.desc))`) && E(`idleDays(S.opps.find(o=>o.code==='CM-250314-01'))`) === 15, 'la nota de importación no cuenta como gestión: siguen 15 días sin gestión');
E(`location.hash='oportunidades';render()`);
ok(/15 días sin gestión/.test(doc.querySelector('.kanban').textContent), 'la tarjeta muestra los días sin gestión');
E(`location.hash='hoy';render()`);
ok(/Sin gestión sobre su plazo/.test(visible()) && /la más antigua lleva 15 días/.test(visible()), 'Hoy muestra cuántas oportunidades superan su plazo sin gestión');
E(`S.config.auto.on=0;const k=S.tasks.length;S.opps.find(o=>o.code==='CM-250314-01').createdAt=addDays(-40);S.tasks=S.tasks.filter(t=>!/sin-gestion/.test(t.auto||''));render()`);
ok(!E(`S.tasks.some(t=>/sin-gestion/.test(t.auto||''))`), 'con las automatizaciones apagadas no se crean tareas');
E(`location.hash='config';render()`);
ok(/Automatizaciones para evitar oportunidades sin gestión/.test(visible()) && doc.querySelector('input[name="a_idle"]'), 'se configuran en Configuración');

console.log('11. Excel real: Proyección, tipo de negocio, plazos por tipo y cliente sin gestión');
E(`S=cleanSeed();applyAjustes();S.mode='real';S.config.auto={on:1,noTask:1,idle:1,follow:1,stage:1};render()`);
const CAB2 = 'Estado;Título;Fecha de Contacto;Tipo de negocio;Monto Seg. Física ;Monto Seg. Electrónica ;Cargo Habilitación ;Cuotas Carg. Habilitación;Mg %;Probabilidad;Generador;Fecha Presentación;Fecha de Adjudicación;Proyección;Motivo No Adj.';
const d20 = E('addDays(-20)'), d40 = E('addDays(-400)');
w.__c = [CAB2,
  `Pipeline;CM-261101-01-APRO (VIG-CALAMA);${d20};Seg. Física;4900000;;;;25%;30%;Claudio Méndez;;;44100000;`,
  `Pipeline;CM-261101-02-ENEL (CCTV PFV);${d20};One Shot;;9979579;420000;1;30%;30%;C. Méndez;;;9979579;`,
  `Booking;CM-250121-01-MICROSECUR (CCTV PFV BALLOTA);${d40};One Shot;;9979579;;;30%;100%;C. Méndez;;${d40};9979579;`,
  `No Adjudicado;CM-250201-01-METKA (SES);${d40};One Shot;;1000000;;;30%;0%;C. Méndez;;;1000000;Precio`].join('\n');
E(`FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__c)).filter(r=>fxIsOwner(r.ej)));fxPreview(FX_PLAN);window.__p=document.querySelector('#modalLayer').textContent;fxApply()`);
ok(!/columna ID/.test(E('window.__p')), 'la vista previa ya no pide la columna ID');
ok(E(`tcv(S.opps.find(o=>o.code==='CM-261101-01'))`) === 44100000, 'el valor de la oportunidad es la Proyección del Excel (no mensual × 12)');
ok(E(`S.opps.find(o=>o.code==='CM-261101-01').fx.tipo`) === 'Seg. Física' && E(`tipoLabel(S.opps.find(o=>o.code==='CM-261101-01'))`) === 'Seguridad Física', 'guarda el tipo de negocio');
ok(E(`S.opps.find(o=>o.code==='CM-261101-02').fx.Hb`) === 420000 && E(`S.opps.find(o=>o.code==='CM-261101-02').fx.cuotas`) === '1', 'guarda el cargo de habilitación y sus cuotas');
ok(E(`S.opps.find(o=>o.code==='CM-250201-01').lossReason`) === 'Precio', 'usa el motivo de no adjudicación del Excel');
ok(!E(`S.tasks.some(t=>/^booking:/.test(t.auto||''))`), 'un Booking antiguo de la primera carga no genera "Traspaso a operaciones"');
// Plazos por tipo: Seg. Física con alerta a los 30 días; One Shot con la general (10)
E(`S.tasks=[];S.config.plazos={'Seg. Física':{warn:30,risk:45}};render()`);
ok(!E(`S.tasks.some(t=>t.auto==='sin-gestion:'+S.opps.find(o=>o.code==='CM-261101-01').id)`), 'Seguridad Física con plazo de 30 días: a los 20 días aún no genera tarea urgente');
ok(E(`S.tasks.some(t=>t.auto==='sin-gestion:'+S.opps.find(o=>o.code==='CM-261101-02').id)`), 'One Shot con el plazo general (10): a los 20 días sí la genera');
ok(E(`plazo(S.opps.find(o=>o.code==='CM-261101-01')).remind`) === 3, 'lo no definido en el tipo toma el plazo general');
E(`location.hash='config';render()`);
ok(doc.querySelector('input[name="p_0_warn"]').value === '30' && /Plazos por tipo de negocio/.test(visible()), 'la tabla de plazos por tipo está en Configuración');
// Cliente sin gestión: con el umbral por defecto del CRM FS (30 días), 20 días aún no alertan
ok(E('S.config.clientNoAct') === 30 && !E(`computeAlerts().some(a=>a.group==='cliente')`), 'con el umbral por defecto (30 días), 20 días sin gestión aún no generan alerta');
// Con umbral 15
E(`S.config.clientNoAct=15`);
const cl = E(`JSON.parse(JSON.stringify(computeAlerts().filter(a=>a.group==='cliente').map(a=>a.title)))`);
ok(cl.includes('APRO') && cl.includes('ENEL'), 'clientes con oportunidades abiertas y 20 días sin gestión: alerta "Cliente sin gestión"');
ok(!cl.includes('MICROSECUR'), 'un cliente sin oportunidades abiertas ni prospectos activos no genera alerta');
E(`S.activities.push({id:'aE',date:todayIso,userId:'u1',type:'Llamada',entityType:'company',entityId:S.opps.find(o=>o.code==='CM-261101-02').companyId,companyId:S.opps.find(o=>o.code==='CM-261101-02').companyId,desc:'Llamada al cliente',result:'',nextAction:'',nextActionDate:''});location.hash='hoy';render()`);
ok(!E(`computeAlerts().some(a=>a.group==='cliente'&&a.title==='ENEL')`), 'una gestión con el cliente quita la alerta');
ok(/Clientes sin gestión/.test(visible()) && /APRO/.test(visible()), 'Hoy muestra el panel "Clientes sin gestión"');

console.log('12. Booking muestra el año completo y el total del mes aparte');
E(`(()=>{const y=todayIso.slice(0,4),m=todayIso.slice(0,7);const mk=(code,won,proj)=>S.opps.push({id:uid('o'),code,name:code,companyId:S.opps[0].companyId,status:'ganada',stage:'adjudicada',prob:100,ownerId:'u1',oneTime:0,monthly:0,term:0,currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:won,createdAt:won,wonAt:won,closeDate:won,fx:{id:'',title:code,proj,tipo:'One Shot'}});
  mk('CM-B-ANIO',y+'-02-10',100000000);mk('CM-B-MES',todayIso,20000000);mk('CM-B-ANTERIOR',(Number(y)-1)+'-12-15',50000000);mk('CM-B-CERRADA',(Number(y)-1)+'-03-15',10000000);const oc=S.opps.find(o=>o.code==='CM-B-CERRADA');S.invoices.push({id:'fz',oppId:oc.id,number:'77',date:(Number(y)-1)+'-04-01',net:10000000,iva:0,total:10000000,status:'Pagada'});location.hash='oportunidades';UI.oppView='kanban';render()})()`);
const colB = [...doc.querySelectorAll('.kanban .col')].find(c => /Booking/.test(c.querySelector('h2').textContent));
const tb = colB.textContent;
ok(/CM-B-ANIO/.test(tb) && /CM-B-MES/.test(tb), 'muestra los Booking de todo el año');
ok(!/CM-B-ANTERIOR/.test(tb), 'un Booking del año anterior sin facturar no aparece en el Booking de este año');
ok(new RegExp('de ' + (Number(hoy.slice(0, 4)) - 1) + ' con saldo por facturar').test(tb) && colB.querySelector('a[data-k="oppYear"][data-v="' + (Number(hoy.slice(0, 4)) - 1) + '"]'), 'avisa que hay Booking del año anterior con saldo, con enlace a ese año');
ok(!/CM-B-CERRADA/.test(tb), 'uno del año anterior ya facturado el año anterior no aparece');
ok(/\$120 MM/.test(colB.querySelector('.col-h').textContent), 'el total de la columna es solo lo cerrado este año');
ok(/Total mes: \$20 MM/.test(tb) && /\(1 negocio\)/.test(tb), 'muestra el total del mes aparte');
ok(new RegExp('año ' + hoy.slice(0, 4)).test(tb), 'indica que la columna es del año');
ok(!colB.querySelector('.card.flag') && !/Sin tarea pendiente/.test(tb), 'un Booking no aparece como "sin tarea pendiente"');

console.log('13. Columna Facturado: total cerrado y total facturado por año');
const colOf = n => [...doc.querySelectorAll('.kanban .col')].find(c => c.querySelector('h2').textContent.startsWith(n));
const y = hoy.slice(0, 4), yAnt = String(Number(y) - 1);
const idOf = code => E(`S.opps.find(o=>o.code==='${code}').id`);
// Parcial: CM-B-ANIO ($100 MM) facturada en $40 MM este año
E(`S.invoices.push({id:'fp1',oppId:'${idOf('CM-B-ANIO')}',number:'80',date:todayIso,net:40000000,iva:0,total:40000000,status:'Emitida'});render()`);
let cB = colOf('Booking'), cF = colOf('Facturado');
ok(/CM-B-ANIO/.test(cB.textContent) && !/CM-B-ANIO/.test(cF.textContent), 'facturada a medias sigue en Booking');
ok(cB.querySelector(`.card[data-id="${idOf('CM-B-ANIO')}"] .meter i[style*="width:40%"]`), 'la tarjeta muestra la barra de avance (40 %)');
ok(/Incluye \$40 MM de facturas parciales/.test(cF.textContent) && /Total facturado \d{4}: \$40 MM/.test(cF.textContent), 'el total facturado del año incluye lo parcial y lo explica');
// Completa: CM-B-MES ($20 MM) facturada al 100 % este año
E(`S.invoices.push({id:'fp2',oppId:'${idOf('CM-B-MES')}',number:'81',date:todayIso,net:20000000,iva:0,total:20000000,status:'Pagada'});render()`);
cB = colOf('Booking'); cF = colOf('Facturado');
ok(!/CM-B-MES/.test(cB.textContent) && /CM-B-MES/.test(cF.textContent), 'al llegar al 100 % pasa sola a Facturado');
ok(/\$60 MM/.test(cF.querySelector('.col-h').textContent), 'el total de Facturado es lo facturado en el año ($60 MM)');
ok(/Total cerrado \d{4}: \$120 MM/.test(cB.textContent) && /\$120 MM/.test(cB.querySelector('.col-h').textContent), 'el total cerrado del año no cambia al facturar ($120 MM)');
const pendEsp = E(`mm(S.opps.filter(o=>o.status==='ganada'&&(invPct(o)??0)<100&&(o.wonAt||'').startsWith(todayIso.slice(0,4))).reduce((t,o)=>t+Math.max(tcv(o)-invoicedNet(oppInvoices(o.id)),0),0))`);
ok(cB.textContent.includes('Por facturar: ' + pendEsp) && E(`Math.max(tcv(byId(S.opps,'${idOf('CM-B-ANIO')}'))-invoicedNet(oppInvoices('${idOf('CM-B-ANIO')}')),0)`) === 60000000, 'Booking muestra lo pendiente por facturar (' + pendEsp + ', con los $60 MM que le faltan a la parcial)');
// «Por emitir» no cuenta
E(`S.invoices.push({id:'fp3',oppId:'${idOf('CM-B-ANTERIOR')}',number:'82',date:todayIso,net:50000000,iva:0,total:50000000,status:'Por emitir'});render()`);
ok(!/CM-B-ANTERIOR/.test(colOf('Facturado').textContent), 'una factura «Por emitir» no la pasa a Facturado');
// Booking del año anterior facturado este año: queda en Facturado del año de su factura
E(`S.invoices.find(x=>x.id==='fp3').status='Emitida';render()`);
cF = colOf('Facturado');
ok(/CM-B-ANTERIOR/.test(cF.textContent) && /\$110 MM/.test(cF.querySelector('.col-h').textContent), 'un Booking del año anterior facturado este año aparece en Facturado de este año');
ok(/Total cerrado \d{4}: \$120 MM/.test(colOf('Booking').textContent), 'y no suma al cerrado de este año');
// Año anterior en el selector
ok([...doc.querySelectorAll('select[data-filter="oppYear"] option')].some(x => x.value === yAnt), 'el selector de año ofrece el año anterior');
E(`UI.oppYear='${yAnt}';render()`);
cB = colOf('Booking'); cF = colOf('Facturado');
ok(!/CM-B-ANTERIOR/.test(cB.textContent), 'el Booking del año anterior ya facturado este año no vuelve a su año (está en Facturado de este año)');
const pendAnt = E(`S.opps.filter(o=>o.status==='ganada'&&(o.wonAt||'').startsWith('${yAnt}')&&(invPct(o)??0)<100).map(o=>o.id)`);
ok(pendAnt.length > 0 && pendAnt.every(id => cB.querySelector('.card[data-id="' + id + '"]')), 'los Booking del año anterior que siguen con saldo se ven en el Booking de ese año');
ok(/CM-B-CERRADA/.test(cF.textContent) && !/CM-B-ANIO/.test(cB.textContent + cF.textContent), 'en el año anterior, Facturado muestra lo facturado ese año y no lo de este año');
const cerrAnt = E(`mm(S.opps.filter(o=>o.status==='ganada'&&(o.wonAt||'').startsWith('${yAnt}')).reduce((t,o)=>t+tcv(o),0))`);
ok(cB.textContent.includes('Total cerrado ' + yAnt + ': ' + cerrAnt), 'el total cerrado del año anterior es su propio Booking (' + cerrAnt + ')');
ok(!/Total mes/.test(cB.textContent), 'en un año pasado no muestra el total del mes');
E(`UI.oppYear='';render()`);

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
console.log(f ? `\n${f} prueba(s) fallaron` : '\nTodas las pruebas pasaron');
process.exit(f ? 1 : 0);
