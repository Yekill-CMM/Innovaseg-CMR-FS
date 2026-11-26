// Prospección: código PR, varios prospectos por cliente, Kanban 8 + 3 salidas, actividades que mueven el prospecto,
// historial heredado al convertir y filtro por cliente en Actividades y Tareas.
// Uso: node prospectos.test.js ../app/index.html   (deja datos de prueba para prospectos.test.php)
const fs = require('fs'), os = require('os');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
const visible = () => [...w.document.body.children].filter(e => e.tagName !== 'SCRIPT').map(e => e.textContent).join(' ');
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`toast=()=>{};window.open=()=>({});`);
function enviar(sel, valores) {
  const form = doc.querySelector(sel); if (!form) throw new Error('No encontré el formulario ' + sel);
  for (const [k, v] of Object.entries(valores)) { const el = form.querySelector(`[name="${k}"]`); if (!el) throw new Error('Falta el campo ' + k); el.value = v; }
  const e0 = form.querySelector('[data-err]'); if (e0) e0.textContent = '';
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  const err = form.querySelector('[data-err]'); return err ? err.textContent : '';
}
const year = E('todayIso').slice(0, 4);
const lead = q => E(`JSON.parse(JSON.stringify(${q}||null))`);

console.log('1. Migración: los prospectos existentes reciben código PR por orden de creación');
E(`S=cleanSeed();S.v=3;S.mode='real';
  S.companies.push({id:'cA',legalName:'ACME',tradeName:'ACME',industry:'Energía',sites:[],createdAt:'2026-01-01'},{id:'cB',legalName:'BETA',tradeName:'BETA',industry:'Retail',sites:[],createdAt:'2026-01-01'});
  const L=(id,c,st,cr)=>({id,companyId:c,contactId:'',source:'Referido',stage:st,potential:'Medio',problem:'',service:'CCTV',ownerId:'u1',nextAction:'Llamar',nextActionDate:'2026-12-01',vars:{},createdAt:cr});
  S.leads.push(L('l2','cA','Contactado','2026-05-02'),L('l1','cA','Identificado','2025-03-01'),L('l3','cB','Identificado','2026-06-01'));
  migrate();`);
ok(E(`byId(S.leads,'l1').code`) === 'PR-2025-0001' && E(`byId(S.leads,'l2').code`) === 'PR-2026-0002' && E(`byId(S.leads,'l3').code`) === 'PR-2026-0003', 'PR-2025-0001, PR-2026-0002 y PR-2026-0003');
E('migrate()');
ok(E('S.seq.lead') === 3 && E(`byId(S.leads,'l1').code`) === 'PR-2025-0001', 'correrla de nuevo no cambia los códigos');

console.log('2. Nuevo prospecto: código PR y varios por cliente');
E(`S.contacts.push({id:'kA0',companyId:'cA',firstName:'Sin',lastName:'Datos',position:'Jefe',email:'',phone:'',whatsapp:'',notes:''},{id:'kA1',companyId:'cA',firstName:'Ana',lastName:'Pérez',position:'Jefa de seguridad',email:'ana@acme.cl',phone:'',whatsapp:'',notes:''})`);
E(`openModal(leadNewForm())`);
const fNL = doc.querySelector('form[data-form="newLead"]'); fNL.querySelector('[name=companyId]').value = 'cA'; fNL.querySelector('[name=companyId]').dispatchEvent(new w.Event('change', { bubbles: true }));
const errSin = enviar('form[data-form="newLead"]', { name: 'Monitoreo subestación Norte', contactId: 'kA0', nextAction: 'Investigar', nextActionDate: '2026-12-05' });
ok(/correo, teléfono o LinkedIn/.test(errSin) && !E(`S.leads.some(l=>l.name==='Monitoreo subestación Norte')`), 'sin contacto efectivo no se crea y explica por qué');
enviar('form[data-form="newLead"]', { name: 'Monitoreo subestación Norte', contactId: 'kA1', nextAction: 'Investigar', nextActionDate: '2026-12-05' });
const nuevo = lead(`S.leads.find(l=>l.name==='Monitoreo subestación Norte')`);
ok(nuevo && nuevo.code === `PR-${year}-0004`, `se crea con código PR-${year}-0004`);
ok(E(`S.leads.filter(l=>l.companyId==='cA'&&!leadClosed(l)).length`) === 3, 'ACME queda con 3 prospectos abiertos a la vez');
E('closeDrawer()');

console.log('3. Kanban de prospección');
E(`location.hash='prospectos';UI.leadView='kanban';render()`);
const cols = [...doc.querySelectorAll('.kanban.leads .col h2')].map(h => h.firstChild.textContent.trim());
ok(JSON.stringify(cols) === JSON.stringify(['Identificado', 'Investigación', 'Contacto inicial', 'Contactado', 'Reunión solicitada', 'Reunión realizada', 'Calificación', 'Levantamiento']), '8 columnas activas en orden');
const salidas = [...doc.querySelectorAll('.lead-exits .col h2')].map(h => h.firstChild.textContent.trim());
ok(JSON.stringify(salidas) === JSON.stringify(['Oportunidad creada', 'No calificado', 'Nurturing']), '3 salidas aparte');
ok(/PR-2026-0002/.test(doc.querySelector('.kanban.leads').textContent), 'la tarjeta muestra el código PR');
E(`UI.leadCompany='cB';render()`);
ok(doc.querySelectorAll('.kanban.leads .card').length === 1, 'filtrar por cliente deja solo los prospectos de BETA');
E(`UI.leadCompany='';render()`);

console.log('4. Mover en el Kanban');
E(`moveLead('l1','Contactado')`);
ok(E(`byId(S.leads,'l1').stage`) === 'Contactado' && E(`byId(S.leads,'l1').stageAt`) === E('todayIso'), 'pasa a Contactado y registra la fecha de la etapa');
ok(E(`S.audit.some(a=>a.entity==='Prospecto PR-2025-0001'&&a.newV==='Contactado')`), 'queda en la auditoría con su código');
E(`moveLead('l1','Oportunidad creada')`);
ok(!!doc.querySelector('#modalLayer form[data-form="convert"]'), 'soltar en "Oportunidad creada" abre la conversión');
E(`closeModal();moveLead('l1','No calificado')`);
ok(/no calificado/i.test(doc.querySelector('#modalLayer').textContent), 'soltar en "No calificado" pide el motivo');
E(`closeModal();moveLead('l3','Nurturing')`);
enviar('#modalLayer form[data-form="disq"]', { recontact: '2027-03-01', reason: 'Sin presupuesto', comment: 'Retomar en el presupuesto 2027' });
ok(E(`byId(S.leads,'l3').stage`) === 'Nurturing' && E(`byId(S.leads,'l3').nextActionDate`) === '2027-03-01', 'Nurturing con la fecha de recontacto elegida');

console.log('5. Las reuniones mueven el prospecto (solo hacia adelante)');
const idN = nuevo.id;
E(`ACTIONS.quick({ent:'lead:${idN}'})`);
enviar('form[data-form="quick"]', { type: 'Reunión', date: '2027-01-15', desc: 'Reunión con el jefe de seguridad' });
ok(E(`byId(S.leads,'${idN}').stage`) === 'Reunión solicitada', 'una reunión agendada lo pasa a "Reunión solicitada"');
E(`ACTIONS.quick({ent:'lead:${idN}'})`);
enviar('form[data-form="quick"]', { type: 'Visita a terreno', date: E('todayIso'), desc: 'Recorrido por la subestación' });
ok(E(`byId(S.leads,'${idN}').stage`) === 'Reunión realizada', 'una visita realizada lo pasa a "Reunión realizada"');
E(`byId(S.leads,'l2').stage='Calificación';ACTIONS.quick({ent:'lead:l2'})`);
enviar('form[data-form="quick"]', { type: 'Reunión', date: E('todayIso'), desc: 'Seguimiento' });
ok(E(`byId(S.leads,'l2').stage`) === 'Calificación', 'no retrocede a un prospecto que ya estaba más adelante');

console.log('6. Pasar a oferta y vincular con la oportunidad del Excel: hereda el historial de prospección');
w.__c = ['ID;Estado;Título;Tipo de negocio;Monto Seg. Fisica;Monto Seg. Electronica;Probabilidad;Fecha de contacto;Generador',
  '901;Pipeline;CM-261112-09-ACME (Monitoreo subestación Norte);Seg. Electrónica;0;2500000;30%;' + E('todayIso') + ';Claudio Méndez'].join('\n');
E(`FX_PLAN=fxBuildPlan(fxRows(fxReadCsv(window.__c)).filter(r=>fxIsOwner(r.ej)));fxApply()`);
const nOpp = E('S.opps.length');
E(`ACTIONS.convertLead({id:'${idN}'})`);
enviar('form[data-form="convert"]', { note: 'Oferta de monitoreo', oppId: E(`S.opps.find(o=>o.code==='CM-261112-09').id`) });
ok(E(`byId(S.leads,'${idN}').stage`) === 'Oportunidad creada' && E('S.opps.length') === nOpp, 'pasa a oferta vinculándose con la del Excel, sin crear otra');
const o = lead(`S.opps.find(o=>o.code==='CM-261112-09')`);
ok(o && o.leadId === idN, 'la oportunidad CM-261112-09 del Excel queda ligada al prospecto');
E(`openEntity('opp',S.opps.find(o=>o.code==='CM-261112-09').id)`);
const ficha = doc.querySelector('#drawerLayer').textContent;
ok(/Origen: PR-/.test(ficha), 'la ficha muestra el prospecto de origen');
ok(/etapa de prospección/.test(ficha) && /Recorrido por la subestación/.test(ficha), 'el timeline incluye las actividades del prospecto, marcadas');
ok(E(`(()=>{const oid=S.opps.find(o=>o.code==='CM-261112-09').id;return S.activities.filter(a=>a.entityType==='opp'&&a.entityId===oid).every(a=>/^Funnel: /.test(a.desc))&&S.activities.some(a=>a.entityType==='lead'&&a.entityId==='${idN}'&&/Recorrido/.test(a.desc))})()`), 'esas actividades siguen siendo del prospecto: no se suman como gestión de la oportunidad');
E(`openEntity('lead','${idN}')`);
ok(/Convertido en oportunidad CM-261112-09/.test(doc.querySelector('#drawerLayer').textContent), 'el prospecto muestra a qué oportunidad pasó');
ok(/Otros prospectos de este cliente/.test(doc.querySelector('#drawerLayer').textContent), 'y los otros prospectos del mismo cliente');
E('closeDrawer()');

console.log('7. Actividades y Tareas: el cliente es el filtro y cada elemento dice si es PR o CM');
E(`const oid=S.opps.find(o=>o.code==='CM-261112-09').id;
  S.activities.push({id:'aO',date:todayIso,userId:'u1',type:'Llamada',entityType:'opp',entityId:oid,companyId:'cA',desc:'Llamada por la oferta',result:'',nextAction:'',nextActionDate:''});
  S.activities.push({id:'aB',date:todayIso,userId:'u1',type:'Email',entityType:'lead',entityId:'l3',companyId:'cB',desc:'Correo a BETA',result:'',nextAction:'',nextActionDate:''});
  S.tasks.push({id:'tO',title:'Revisar oferta',entityType:'opp',entityId:oid,assigneeId:'u1',priority:'Alta',due:'2026-12-01',status:'Pendiente',createdAt:todayIso});
  S.tasks.push({id:'tL',title:'Llamar a ACME',entityType:'lead',entityId:'l2',assigneeId:'u1',priority:'Media',due:'2026-12-01',status:'Pendiente',createdAt:todayIso});
  location.hash='actividades';UI.actCompany='cA';render()`);
let main = doc.querySelector('main') ? doc.querySelector('main').textContent : visible();
ok(/Llamada por la oferta/.test(main) && !/Correo a BETA/.test(main), 'Actividades filtradas por ACME');
ok(/CM-261112-09/.test(main) && /PR-/.test(main), 'cada actividad lleva su etiqueta CM o PR');
E(`UI.actCompany='';location.hash='tareas';UI.taskCompany='cA';UI.taskKind='PR';render()`);
main = doc.querySelector('main') ? doc.querySelector('main').textContent : visible();
ok(/Llamar a ACME/.test(main) && !/Revisar oferta/.test(main), 'Tareas de ACME, solo de prospección');
E(`UI.taskKind='CM';render()`);
main = doc.querySelector('main') ? doc.querySelector('main').textContent : visible();
ok(/Revisar oferta/.test(main) && !/Llamar a ACME/.test(main), 'Tareas de ACME, solo de oportunidades');

console.log('8. Planes: los prospectos que crea un plan también tienen código');
E(`S.contacts.push({id:'kB1',companyId:'cB',firstName:'Beto',lastName:'Ruiz',position:'Gerente',email:'',phone:'+56911111111',whatsapp:'',notes:''});const p=makePlan('Retail',todayIso.slice(0,7),'u1');p.targets.push({companyId:'cB',status:'Pendiente',addedAt:todayIso});S.plans.push(p);byId(S.leads,'l3').stage='No calificado';activateTargets(p)`);
ok(/^PR-\d{4}-\d{4}$/.test(E(`S.leads.find(l=>l.companyId==='cB'&&l.planId)?.code||''`)), 'el prospecto del plan recibe código PR');

console.log('8b. Planes semanales NN-YY con todas las verticales');
const sem = E('thisWeek()');
ok(/^\d{2}-\d{2}$/.test(sem) && E(`isoWeekOf('2026-11-16')`) === '47-26' && E(`isoWeekOf('2027-01-03')`) === '53-26', 'la semana ISO se calcula bien (16-11-2026 = 47-26; 03-01-2027 = 53-26)');
ok(JSON.stringify(E(`weekRange('47-26')`)) === '["2026-11-16","2026-11-22"]' && E(`weekRange('53-27')`) === null && E(`weekRange('00-26')`) === null, 'la semana va de lunes a domingo y rechaza semanas que no existen');
E(`location.hash='planes';render();openModal(planForm())`);
ok(doc.querySelector('form[data-form="plan"] [name="week"]')?.value === sem && !doc.querySelector('form[data-form="plan"] [name="vertical"]'), 'el plan nuevo pide solo la semana (por defecto la actual), sin vertical');
let er = enviar('form[data-form="plan"]', { week: '7-26' });
ok(/Semana inválida/.test(er), 'rechaza una semana mal escrita');
er = enviar('form[data-form="plan"]', { week: sem });
const pj = JSON.parse(E(`JSON.stringify(S.plans.find(p=>p.week===${JSON.stringify(sem)})||{})`));
ok(!er && pj.name === 'Plan semana ' + sem && pj.vertical === 'Todas', 'crea «Plan semana ' + sem + '» con todas las verticales');
ok(pj.goals && pj.goals.llamadas === Math.ceil(E(`PLAYBOOKS._generic.goals.llamadas`) / 4), 'las metas se ajustan a una semana (una cuarta parte del mes)');
E(`closeModal();location.hash='planes';render();openModal(planForm())`);
ok(/esa semana/.test(enviar('form[data-form="plan"]', { week: sem })), 'no deja crear dos planes de la misma semana');
E(`closeModal();location.hash='planes';render()`);
ok(/Semana \d{2}-\d{2}/.test(doc.querySelector('main').textContent) && /Todas las verticales/.test(doc.querySelector('main').textContent), 'la lista muestra «Semana NN-YY» y «Todas las verticales»');

// Carga de Joan con varias verticales; filtro y eliminación
E(`(()=>{const p=S.plans.find(x=>x.week===${JSON.stringify(sem)});window.__pid=p.id;
 const mk=(id,n,ind,src)=>{S.companies.push({id,legalName:n,tradeName:n,industry:ind,relation:'Prospecto',ownerId:'u1',source:src,notes:'',createdAt:todayIso,sites:[]});p.targets.push({companyId:id,status:'Pendiente',addedAt:todayIso,source:src,note:'Proyecto de prueba'})};
 mk('cE1','Solar Uno SpA','Energía','Prospección semanal ${sem} · P1 [A]');mk('cE2','Eólica Dos','Energía','SEIA exp. 1');mk('cM1','Minera Tres','Minería','SEIA exp. 2');mk('cM2','Minera Cuatro','Minería','SEIA exp. 3');
 S.contacts.push({id:'kM1',companyId:'cM1',firstName:'Ana',lastName:'Rep',position:'Representante legal',createdAt:todayIso});
 p.targets.push({companyId:'cA',status:'Pendiente',addedAt:todayIso});
 S.activities.push({id:'aM2',date:todayIso,userId:'u1',type:'Llamada',entityType:'company',entityId:'cM2',companyId:'cM2',desc:'Ya conversamos'});
 location.hash='planes/'+p.id;UI.planVert='';render()})()`);
const mainT = () => doc.querySelector('main').textContent;
ok(/Minería \(2\)/.test(mainT()) && /Energía \(3\)/.test(mainT()) && /SEIA exp\. 1/.test(mainT()), 'la ficha del plan muestra vertical y origen de cada empresa, y el filtro cuenta por vertical');
E(`UI.planVert='Minería';render()`);
ok(/Minera Tres/.test(mainT()) && !/Solar Uno/.test(mainT()), 'el filtro por vertical muestra solo esa vertical');
E(`ACTIONS.selAllTargets({plan:window.__pid})`);
ok(E(`UI.planSel[window.__pid].size`) === 2 && /Eliminar seleccionadas \(2\)/.test(mainT()), '«Seleccionar las de Minería» marca solo las visibles');
E(`ACTIONS.delTargets({plan:window.__pid})`);
const modal = doc.querySelector('#modalLayer').textContent;
ok(/Eliminar 2 empresas/.test(modal) && /Se eliminan 1 empresas/.test(modal) && /1 empresas se mantienen/.test(modal), 'antes de borrar avisa qué se elimina y qué se mantiene (la que tiene historial no se borra)');
E(`ACTIONS.doDelTargets({plan:window.__pid});UI.planVert='';render()`);
ok(E(`byId(S.plans,window.__pid).targets.length`) === 3 && !E(`!!byId(S.companies,'cM1')`) && !E(`!!byId(S.contacts,'kM1')`) && E(`!!byId(S.companies,'cM2')`), 'salen del plan; la empresa sin historial se borra con su contacto y la con actividad queda en el CRM');
// Plan activado: el prospecto creado por el plan sin actividad se borra; con actividad queda «No calificado»
E(`(()=>{const p=byId(S.plans,window.__pid);S.contacts.push({id:'kE1',companyId:'cE1',firstName:'Uno',lastName:'Solar',position:'O&M',email:'uno@solar.cl',notes:''},{id:'kE2',companyId:'cE2',firstName:'Dos',lastName:'Eolo',position:'HSE',email:'',phone:'',notes:'https://www.linkedin.com/in/dos-eolo'});p.targets.forEach(t=>t.status='Pendiente');activateTargets(p);p.status='Activo';
 const l1=S.leads.find(l=>l.companyId==='cE1'&&l.planId===p.id);const l2=S.leads.find(l=>l.companyId==='cE2'&&l.planId===p.id);window.__l1=l1.id;window.__l2=l2.id;
 S.activities.push({id:'aL2',date:todayIso,userId:'u1',type:'Llamada',entityType:'lead',entityId:l2.id,companyId:'cE2',desc:'Llamé'});
 UI.planSel[p.id]=new Set(['cE1','cE2']);ACTIONS.doDelTargets({plan:p.id})})()`);
ok(!E(`!!byId(S.leads,window.__l1)`) && !E(`S.tasks.some(t=>t.entityId===window.__l1)`) && !E(`!!byId(S.companies,'cE1')`), 'con el plan activo, el prospecto sin actividad se borra con sus tareas y su empresa');
ok(E(`byId(S.leads,window.__l2).stage`) === 'No calificado' && E(`!!byId(S.companies,'cE2')`) && !E(`S.tasks.some(t=>t.entityId===window.__l2&&t.planId===window.__pid&&isOpenTask(t))`), 'el prospecto con actividad queda «No calificado», sin tareas del plan, y la empresa se mantiene');
ok(E(`!!byId(S.companies,'cA')`) && E(`byId(S.plans,window.__pid).targets.some(t=>t.companyId==='cA')`), 'las empresas no seleccionadas no se tocan');
E(`ACTIONS.addTargets({id:window.__pid})`);
ok(!!doc.querySelector('form[data-form="addTargets"] [name="nvert"]'), 'al agregar empresas nuevas a mano se elige su vertical');
E(`closeModal()`);
E(`S.plans.push({id:'pj',name:'Plan semana 01-27',vertical:'Todas',month:'2027-01',week:'01-27',status:'Borrador',ownerId:'u1',pace:5,objective:'',service:'',subsegments:[],roles:[],valueProp:'',pains:[],questions:[],objections:[],cadence:[],goals:{},targets:[],createdAt:todayIso,porAgente:true});migrate()`);
ok(E(`byId(S.plans,'pj').goals.llamadas`) === Math.ceil(E(`PLAYBOOKS._generic.goals.llamadas`) / 4) && E(`byId(S.plans,'pj').cadence.length`) > 0, 'un plan semanal creado por Joan recibe el guion y metas semanales al abrir el CRM');
E(`const pm=makePlan('Retail','2026-10','u1');S.plans.push(pm);window.__pm=pm.id;location.hash='planes/'+pm.id;render()`);
ok(E(`byId(S.plans,window.__pm).name`) === 'Plan Retail octubre 2026' && /octubre 2026/.test(mainT()), 'los planes mensuales antiguos se siguen viendo como antes');

console.log('8c. Planes mensuales en borrador pasan a semanas');
E(`(()=>{S.plans=S.plans.filter(p=>p.week!=='46-26'&&p.week!=='47-26');
 const mk=(id,n,ind)=>S.companies.push({id,legalName:n,tradeName:n,industry:ind,relation:'Prospecto',ownerId:'u1',source:'SEIA exp. x',notes:'',createdAt:'2026-11-09',sites:[]});
 mk('cS1','Agro Uno','Agroindustria');mk('cS2','Minera Cinco','Minería');mk('cS3','Solar Seis','Energía');
 const a=makePlan('Agroindustria','2026-11','u1');a.targets.push({companyId:'cS1',status:'Pendiente',addedAt:'2026-11-09',source:'SEIA exp. 11',note:'Planta'});
 const m=makePlan('Minería','2026-11','u1');m.targets.push({companyId:'cS2',status:'Pendiente',addedAt:'2026-11-10',source:'SEIA exp. 12'},{companyId:'cS3',status:'Pendiente',addedAt:'2026-11-16',source:'SEIA exp. 13'});
 const act=makePlan('Retail','2026-11','u1');act.status='Activo';act.targets.push({companyId:'cA',status:'En cadencia',addedAt:'2026-11-02'});
 S.plans.push(a,m,act);window.__act=act.id;location.hash='planes';render()})()`);
ok(/Pasar \d+ borradores mensuales a semanas/.test(doc.querySelector('main').textContent), 'la lista ofrece pasar los borradores mensuales a semanas');
E(`ACTIONS.toWeekly()`);
const mw = doc.querySelector('#modalLayer').textContent;
ok(/Plan semana 46-26: 2 empresas/.test(mw) && /Plan semana 47-26: \d+ empresa/.test(mw), 'antes de pasar muestra a qué semana va cada grupo (por fecha de carga)');
E(`ACTIONS.doToWeekly()`);
const w46 = JSON.parse(E(`JSON.stringify(S.plans.find(p=>p.week==='46-26')||{})`)), w47 = JSON.parse(E(`JSON.stringify(S.plans.find(p=>p.week==='47-26')||{})`));
ok(w46.name === 'Plan semana 46-26' && w46.targets.map(t => t.companyId).sort().join() === 'cS1,cS2' && w46.targets.find(t => t.companyId === 'cS1').note === 'Planta', 'las empresas quedan en «Plan semana 46-26» con su origen y nota');
ok(w47.targets && w47.targets.some(t => t.companyId === 'cS3'), 'la que llegó el 16-11 queda en la semana 47-26');
ok(!E(`S.plans.some(p=>!p.week&&p.status==='Borrador')`) && E(`!!byId(S.plans,window.__act)`), 'los borradores mensuales se eliminan y el plan activo no se toca');
E(`location.hash='planes';render()`);
ok(!/borradores? mensual/.test(doc.querySelector('main').textContent), 'después ya no aparece el botón');

console.log('9. Oportunidades del CRM que no vienen en el Excel pasan a prospectos (datos versión 5)');
E(`S.v=4;S.opps.push({id:'oX',code:'OP-2026-0802',name:'Seguridad Libélula + BESS Lile',companyId:'cA',status:'abierta',stage:'oferta',prob:50,ownerId:'u1',oneTime:0,monthly:5000000,term:12,currency:'CLP',contactIds:[],services:['CCTV'],history:[],stageEnteredAt:todayIso,createdAt:'2026-10-20',closeDate:'2026-12-31',nextAction:'',nextActionDate:'',need:'Robo de cable'});
  S.activities.push({id:'aX',date:'2026-10-21',userId:'u1',type:'Llamada',entityType:'opp',entityId:'oX',companyId:'cA',desc:'Primer contacto Engie',result:'',nextAction:'',nextActionDate:''});
  S.tasks.push({id:'tX',title:'Enviar presentación',entityType:'opp',entityId:'oX',assigneeId:'u1',priority:'Alta',due:'2026-12-01',status:'Pendiente',createdAt:todayIso});
  migrate()`);
const lx = lead(`S.leads.find(l=>l.fromOpp&&l.fromOpp.code==='OP-2026-0802')`);
ok(lx && /^PR-\d{4}-\d{4}$/.test(lx.code) && lx.name === 'Seguridad Libélula + BESS Lile' && lx.companyId === 'cA', 'queda como prospecto PR con su nombre y cliente');
ok(lx.stage === 'Levantamiento' && lx.problem === 'Robo de cable' && lx.service === 'CCTV', 'en Levantamiento, con su necesidad y servicio');
ok(lx.nextAction === 'Enviar presentación' && lx.nextActionDate === '2026-12-01', 'la próxima tarea pasa a ser su próxima acción');
ok(E(`byId(S.activities,'aX').entityType==='lead'&&byId(S.activities,'aX').entityId==='${lx.id}'`) && E(`byId(S.tasks,'tX').entityId`) === lx.id, 'sus actividades y tareas se trasladan al prospecto');
ok(!E(`S.opps.some(o=>o.code==='OP-2026-0802')`) && E(`S.audit.some(a=>a.entity==='OP-2026-0802'&&a.field==='Pasa a prospecto')`), 'la oportunidad se retira y queda en la auditoría');
ok(E('S.v') === 5, 'datos en versión 5');
E(`openEntity('lead','${lx.id}')`);
ok(/Viene de la oportunidad OP-2026-0802/.test(doc.querySelector('#drawerLayer').textContent) && /Primer contacto Engie/.test(doc.querySelector('#drawerLayer').textContent), 'la ficha del prospecto explica de dónde viene y muestra su historial');
E('closeDrawer()');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
fs.writeFileSync(os.tmpdir() + '/crm-prospectos.json', JSON.stringify({ rev: 1, savedAt: 0, data: JSON.parse(E('JSON.stringify(S)')) }));
console.log(f ? `\n${f} prueba(s) fallaron` : '\nTodas las pruebas pasaron');
process.exit(f ? 1 : 0);
