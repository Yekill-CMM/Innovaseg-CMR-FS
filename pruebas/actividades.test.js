// Actividades editables y visibles arriba en las fichas (traído del CRM Comercial el 26-11-2026), adaptado a varias
// personas: quién ve «Editar / actualizar», a quién va la tarea y que la tarea movida conserve su responsable.
// El servidor vuelve a revisar lo mismo (permisos.test.php, sección 7; agente-actividad.test.php).
// Uso: node actividades.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const HTML = fs.readFileSync(process.argv[2], 'utf8');
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
const errores = [];
const vc = new VirtualConsole(); vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS|navigation/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(HTML, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window; w.DecompressionStream = DecompressionStream; w.Response = Response; w.Blob = Blob; w.TextDecoder = TextDecoder; w.TextEncoder = TextEncoder;
const E = s => w.eval(s);
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
const drawer = () => w.document.querySelector('#drawerLayer');
const editar = id => !!drawer().querySelector(`[data-action="editActResult"][data-id="${id}"]`);
const como = id => E(`S.currentUser='${id}';closeModal();closeDrawer()`);

E(`S=cleanSeed();S.mode='real';
  S.users=[{id:'g1',name:'Marcela Ibarra',role:'gerente',equipo:''},{id:'sN',name:'Carolina Rojas',role:'subgerente',equipo:'Norte'},
   {id:'eN1',name:'Paula Cifuentes',role:'ejecutivo',equipo:'Norte'},{id:'eN2',name:'Tomás Vera',role:'ejecutivo',equipo:'Norte'},
   {id:'sS',name:'Rodrigo Muñoz',role:'subgerente',equipo:'Sur'}];
  S.companies.push({id:'cA',legalName:'Eólica Norte SpA',tradeName:'Eólica Norte',industry:'Energía',sites:[]});
  const b={crm:true,currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:todayIso,createdAt:todayIso,closeDate:addDays(60),monthly:1000000,term:12,oneTime:0,companyId:'cA',tipo:'Seg. Física',stage:'oferta',status:'abierta',prob:50};
  S.opps.push({...b,id:'o1',code:'OP-261126-01',name:'Guardias parque',ownerId:'eN1'});
  S.leads.push({id:'l1',code:'PR-2026-0101',name:'CCTV subestación',companyId:'cA',ownerId:'eN1',stage:'Contactado',createdAt:todayIso});
  S.activities.push({id:'aP',date:todayIso,userId:'eN1',type:'Email',entityType:'opp',entityId:'o1',companyId:'cA',desc:'Envié la propuesta',result:'',nextAction:'Llamar por la propuesta',nextActionDate:todayIso,taskId:'tP'});
  S.tasks.push({id:'tP',title:'Llamar por la propuesta',entityType:'opp',entityId:'o1',assigneeId:'eN1',priority:'Media',due:todayIso,status:'Pendiente',createdAt:todayIso});
  S.activities.push({id:'aS',date:todayIso,userId:'sN',type:'Reunión',entityType:'opp',entityId:'o1',companyId:'cA',desc:'Revisión con la subgerente',result:'',nextAction:'Ajustar precio',nextActionDate:todayIso,taskId:'tS'});
  S.tasks.push({id:'tS',title:'Ajustar precio',entityType:'opp',entityId:'o1',assigneeId:'sN',priority:'Media',due:todayIso,status:'Pendiente',createdAt:todayIso});
  S.activities.push({id:'aL',date:todayIso,userId:'eN1',type:'Llamada',entityType:'lead',entityId:'l1',companyId:'cA',desc:'Llamé al jefe de seguridad',result:''});
  S.activities.push({id:'aSys',date:todayIso,userId:'eN1',type:'Nota',entityType:'opp',entityId:'o1',companyId:'cA',desc:'Funnel: nota antigua del importador',sys:true});
  save=()=>{};`);

console.log('1. Fichas: actividades arriba');
como('eN1'); E(`openEntity('opp','o1')`);
{ const h = drawer().innerHTML; const iA = h.indexOf('Actividades ('), iT = h.indexOf('Tareas (');
  ok(iA > 0 && iT > 0 && iA < iT, 'ficha de oportunidad: «Actividades» va antes de las tareas');
  ok(!/<h2>Timeline<\/h2>/.test(h), 'ya no está la sección vieja «Timeline»');
  ok(!!drawer().querySelector('[data-sec="actividades"] [data-action="quick"][data-ent="opp:o1"]'), 'con «+ Registrar actividad» en la misma sección');
  const sec = drawer().querySelector('[data-sec="actividades"]'); const det = sec.querySelector('details');
  ok(det && /Funnel: nota antigua/.test(det.textContent) && ![...sec.querySelectorAll(':scope > div > ul.tl > li')].some(li => /Funnel:/.test(li.textContent)), 'las notas automáticas van aparte, plegadas');
  ok(!editar('aSys'), 'y no se editan'); }
E(`openEntity('lead','l1')`);
{ const h = drawer().innerHTML; ok(h.indexOf('Actividades (') > 0 && h.indexOf('Actividades (') < h.indexOf('Datos del prospecto') && !/<h2>Historial<\/h2>/.test(h) && editar('aL'), 'ficha de prospecto: actividades arriba y editables'); }

console.log('2. Quién edita');
como('eN1'); E(`openEntity('opp','o1')`);
ok(editar('aP') && editar('aS'), 'la dueña de la oportunidad edita la suya y la que registró su subgerente en ella');
como('sN'); E(`openEntity('opp','o1')`);
ok(editar('aP') && editar('aS'), 'la subgerente edita las de su equipo');
como('g1'); E(`openEntity('opp','o1')`);
ok(editar('aP'), 'el gerente edita todas');
como('sS'); E(`openEntity('opp','o1')`);
ok(!editar('aP') && !editar('aS'), 'la subgerente de otro equipo no ve «Editar / actualizar»');
E(`modalActResult('aP')`); ok(!w.document.querySelector('#modalLayer form[data-form="actResult"]') && E(`__toasts.some(t=>/No puedes editar/.test(t))`), 'ni puede abrir el formulario');
como('eN2'); E(`modalActResult('aP')`); ok(!w.document.querySelector('#modalLayer form[data-form="actResult"]'), 'un ejecutivo no edita la actividad de otro');

console.log('3. Editar y correr la tarea');
como('sN'); E(`modalActResult('aP')`);
ok(/Mover la tarea «Llamar por la propuesta».*de Paula Cifuentes/.test(w.document.querySelector('#modalLayer').textContent), 'ofrece mover la tarea y dice de quién es');
E(`const f=document.querySelector('#modalLayer form[data-form="actResult"]');f.querySelector('[name=result]').value='Esperando respuesta';f.querySelector('[name=nextActionDate]').value=addDays(5);f.requestSubmit()`);
ok(E(`byId(S.activities,'aP').result==='Esperando respuesta'&&byId(S.activities,'aP').nextActionDate===addDays(5)`), 'guarda resultado y fecha nueva');
ok(E(`byId(S.tasks,'tP').due===addDays(5)&&byId(S.tasks,'tP').assigneeId==='eN1'&&S.tasks.filter(t=>t.title==='Llamar por la propuesta').length===1`), 'la tarea se corre sin duplicarse y sigue siendo de Paula');
ok(E(`const h=byId(S.activities,'aP').hist;h.length===1&&h[0].by==='sN'&&h[0].nextActionDate===todayIso`), 'el historial guarda lo anterior y quién lo cambió');
ok(E(`S.audit.some(a=>a.field==='Resultado'&&a.newV==='Esperando respuesta'&&a.userId==='sN')`), 'queda en la auditoría a nombre de quien edita');
E(`closeModal();openEntity('opp','o1')`);
ok(/Actualizada una vez.*por Carolina Rojas/.test(drawer().textContent), 'la ficha muestra «Actualizada… por» con el nombre');
E(`modalActResult('aP');const f=document.querySelector('#modalLayer form[data-form="actResult"]');f.querySelector('[name=desc]').value='Envié la propuesta corregida';f.querySelector('[name=type]').value='Llamada';f.requestSubmit()`);
ok(E(`const a=byId(S.activities,'aP');a.desc==='Envié la propuesta corregida'&&a.type==='Llamada'&&a.hist.length===2&&a.hist[1].type==='Email'`), 'también se editan tipo y descripción, con historial');

console.log('4. Tarea de otra persona y tarea nueva');
como('eN1'); E(`modalActResult('aS')`);
ok(/es de Carolina Rojas: no puedes moverla/.test(w.document.querySelector('#modalLayer').textContent) && !w.document.querySelector('#modalLayer [name=mkTask]'), 'la ejecutiva no puede correr la tarea de su subgerente (se lo dice)');
E(`const f=document.querySelector('#modalLayer form[data-form="actResult"]');f.querySelector('[name=result]').value='Lo vi con el cliente';f.querySelector('[name=nextActionDate]').value=addDays(9);f.requestSubmit()`);
ok(E(`byId(S.activities,'aS').result==='Lo vi con el cliente'&&byId(S.tasks,'tS').due===todayIso&&byId(S.tasks,'tS').assigneeId==='sN'`), 'actualiza el resultado sin tocar esa tarea');
E(`S.activities.push({id:'aN',date:todayIso,userId:'sN',type:'Llamada',entityType:'opp',entityId:'o1',companyId:'cA',desc:'Llamada de la subgerente',result:''})`);
como('sN'); E(`modalActResult('aN')`);
ok(/Crear tarea con la próxima acción para Paula Cifuentes/.test(w.document.querySelector('#modalLayer').textContent), 'una tarea nueva se ofrece para la dueña de la oportunidad');
E(`const f=document.querySelector('#modalLayer form[data-form="actResult"]');f.querySelector('[name=nextAction]').value='Enviar contrato';f.querySelector('[name=nextActionDate]').value=addDays(3);f.requestSubmit()`);
ok(E(`const a=byId(S.activities,'aN'),t=byId(S.tasks,a.taskId);!!t&&t.assigneeId==='eN1'&&t.title==='Enviar contrato'`), 'y queda asignada a ella, no a quien edita');

console.log('5. Registrar actividad guarda su tarea');
como('eN1');
E(`(()=>{const n=S.activities.length;SUBMITS.quick({ent:'opp:o1',date:todayIso,type:'Llamada',desc:'Seguimiento',result:'',nextAction:'Volver a llamar',nextActionDate:addDays(2),mkTask:'on'},document.createElement('form'));window.__nueva=S.activities[n]})()`);
ok(E(`!!__nueva&&!!__nueva.taskId&&byId(S.tasks,__nueva.taskId).title==='Volver a llamar'`), 'la actividad nueva queda ligada a su tarea de seguimiento');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
process.exit(f ? 1 : 0);
