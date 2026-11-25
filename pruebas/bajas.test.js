// Eliminar y fusionar empresas y contactos (24-11-2026). Regla:
// - Sin historial: «Eliminar» con confirmación; queda en «Registros eliminados».
// - Con historial: no se elimina; el botón pasa a «Fusionar con…» con una frase que dice por qué.
// - Contactos: «Fusionar con otro» dentro de la misma empresa; mueve lo que tiene y completa datos sin reemplazar.
// Deja en la carpeta temporal los estados de cada paso (crm-bajas-*.json) para que bajas.test.php los suba al servidor.
const fs=require('fs'),os=require('os'),path=require('path');const {JSDOM,VirtualConsole}=require('jsdom');
const vc=new VirtualConsole();const errores=[];vc.on('jsdomError',e=>{if(!/fetch|api\.php|Network|Could not parse CSS/i.test(String(e.message)))errores.push(e.message)});
const dom=new JSDOM(fs.readFileSync(process.argv[2],'utf8'),{runScripts:'dangerously',pretendToBeVisual:true,url:'http://localhost/',virtualConsole:vc});
const w=dom.window,doc=w.document,E=s=>w.eval(s);let f=0;const ok=(c,m)=>{console.log((c?'  ✓ ':'  ✗ ')+m);if(!c)f++};
const snap=n=>fs.writeFileSync(path.join(os.tmpdir(),`crm-bajas-${n}.json`),E('JSON.stringify(S)'));
E(`toast=()=>{};S=cleanSeed();S.mode='real';S.config.auto=Object.assign({},S.config.auto,{on:false});`);
const drawer=()=>doc.querySelector('#drawerLayer').innerHTML;
const modal=()=>doc.querySelector('#modalLayer').textContent;
const submit=sel=>{const fm=doc.querySelector(sel);fm.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));return fm};

E(`(()=>{const C=(id,n,x)=>S.companies.push(Object.assign({id,legalName:n,tradeName:n,industry:'Energía',relation:'Prospecto',ownerId:'u1',source:'Manual',notes:'',createdAt:todayIso,sites:[]},x||{}));
 C('cVacia','Empresa Sin Nada');C('cAct','Solar Norte');C('cDup','Eólica Sur');C('cOtra','Otra SpA');
 const K=(id,co,fn,ln,x)=>S.contacts.push(Object.assign({id,companyId:co,firstName:fn,lastName:ln,position:'Jefe',decisionRole:'Influenciador',influence:3,relationship:'Fría',email:'',phone:'',whatsapp:'',notes:''},x||{}));
 K('kVacia','cVacia','Pedro','Plano');
 K('kAct','cAct','Ana','Activa',{email:'ana@solarnorte.cl'});
 K('kBueno','cDup','María','González',{phone:'+56 9 1111 1111'});
 K('kRep','cDup','Maria','Gonzalez',{email:'mgonzalez@eolicasur.cl',phone:'+56 9 2222 2222',notes:'https://www.linkedin.com/in/maria-gonzalez-eolica',siteId:'sOp'});
 K('kLejos','cOtra','María','González',{email:'otra@otra.cl'});
 byId(S.companies,'cDup').sites=[{id:'sOp',name:'Parque Eólico Sur',type:'Planta',city:'Osorno'}];
 S.activities.push({id:'aAct',date:todayIso,userId:'u1',type:'Llamada',entityType:'company',entityId:'cAct',companyId:'cAct',desc:'Llamada con Ana',result:'',nextAction:'',nextActionDate:''});
 S.leads.push({id:'lRep',code:'PR-2026-0900',name:'CCTV parque',companyId:'cDup',contactId:'kRep',siteId:'sOp',source:'Manual',stage:'Contactado',potential:'Medio',problem:'Robo de cable',service:'CCTV',ownerId:'u1',nextAction:'',nextActionDate:'',vars:{},createdAt:todayIso,stageAt:todayIso,qual:{decisorId:'kRep',plazo:'',presupuesto:''}});
 S.tasks.push({id:'tRep',title:'Llamar a Maria',entityType:'lead',entityId:'lRep',assigneeId:'u1',priority:'Media',due:todayIso,status:'Pendiente',createdAt:todayIso});
 S.opps.push({id:'oDup',code:'CM-261124-01',name:'Monitoreo Eólica Sur',companyId:'cDup',siteId:'',status:'abierta',stage:'oferta',prob:50,ownerId:'u1',oneTime:0,monthly:0,term:0,currency:'CLP',contactIds:['kRep','kBueno'],services:[],history:[],createdAt:todayIso});
 const p=makePlan(ALL_VERT,thisWeek(),'u1');p.id='pB';S.plans.push(p);p.targets.push({companyId:'cDup',status:'En cadencia',addedAt:todayIso,need:'CCTV',contactIds:['kRep']});
 save()})()`);
snap('0');

console.log('1. Empresa sin historial: «Eliminar» con confirmación');
E(`openEntity('company','cVacia')`);
ok(/data-action="delCompany"/.test(drawer())&&!/data-baja-nota/.test(drawer()),'la ficha muestra «Eliminar» y no el aviso de historial');
E(`ACTIONS.delCompany({id:'cVacia'})`);
ok(/Eliminar Empresa Sin Nada/.test(modal())&&/Se eliminan también sus contacto/.test(modal())&&/Ver registros eliminados/.test(modal()),'pide confirmación, avisa que se va su contacto y que queda recuperable');
E(`ACTIONS.doDelCompany({id:'cVacia'})`);
ok(!E(`!!byId(S.companies,'cVacia')`)&&!E(`!!byId(S.contacts,'kVacia')`),'al confirmar se eliminan la empresa y su contacto sin historial');
ok(E(`S.audit.some(a=>a.entity==='Empresa Empresa Sin Nada'&&a.field==='Eliminada')`),'queda en la auditoría de la pantalla');
snap('1');

console.log('2. Empresa con un contacto con actividad: no se elimina, se fusiona');
E(`openEntity('company','cAct')`);
ok(!/data-action="delCompany"/.test(drawer())&&/data-action="mergeCompany"[^>]*>Fusionar con…/.test(drawer()),'no hay «Eliminar»: el botón es «Fusionar con…»');
ok(/No se puede eliminar porque tiene 1 actividad\. Si es un duplicado, fusiónala con la empresa correcta\./.test(doc.querySelector('#drawerLayer [data-baja-nota]').textContent),'una frase corta dice por qué');
E(`openEntity('contact','kAct')`);
ok(/data-action="delContact"/.test(drawer()),'el contacto mismo no tiene historial propio: se puede eliminar');
E(`S.activities.push({id:'aK',date:todayIso,userId:'u1',type:'Reunión',entityType:'contact',entityId:'kAct',companyId:'',desc:'Café',result:''});S.activities=S.activities.filter(a=>a.id!=='aAct');openEntity('company','cAct')`);
ok(/1 contacto con historial/.test(drawer())&&!/data-action="delCompany"/.test(drawer()),'si la actividad es del contacto, la empresa tampoco se elimina («1 contacto con historial»)');
E(`ACTIONS.delCompany({id:'cAct'})`);
ok(/Fusionar Solar Norte/.test(modal()),'aunque se llame a «Eliminar» por otro camino, abre la fusión');
ok(E(`deleteCompany('cAct')`)===null&&E(`!!byId(S.companies,'cAct')`),'deleteCompany se niega');
E(`closeModal();S.activities=S.activities.filter(a=>a.id!=='aK');S.activities.push({id:'aAct',date:todayIso,userId:'u1',type:'Llamada',entityType:'company',entityId:'cAct',companyId:'cAct',desc:'Llamada con Ana',result:'',nextAction:'',nextActionDate:''});save()`);

console.log('3. Contacto repetido con prospecto: «Fusionar con…»');
E(`openEntity('contact','kRep')`);
ok(!/data-action="delContact"/.test(drawer())&&/data-action="mergeContact"[^>]*>Fusionar con…/.test(drawer()),'sin «Eliminar»: «Fusionar con…»');
ok(/No se puede eliminar porque tiene 1 prospecto, 1 oportunidad y 1 plan\./.test(doc.querySelector('#drawerLayer [data-baja-nota]').textContent),'dice qué historial tiene');
E(`ACTIONS.mergeContact({id:'kRep'})`);
const sel=doc.querySelector('form[data-form="mergeK"] [name=dst]');
ok(sel&&[...sel.options].map(o=>o.value).filter(Boolean).join()==='kBueno','solo ofrece contactos de la misma empresa (no la María González de Otra SpA)');
ok(/Se mueven: 1 prospecto, 1 rol en oportunidad, 1 plan/.test(modal())&&/Se completan en el contacto elegido: correo, LinkedIn, operación/.test(modal()),'antes de fusionar dice qué se mueve y qué datos se completan');
ok(E(`mergeContacts('kRep','kLejos',true)`)===null,'mergeContacts rechaza un contacto de otra empresa');
submit('form[data-form="mergeK"]');
ok(!E(`!!byId(S.contacts,'kRep')`),'el repetido se elimina');
ok(E(`byId(S.leads,'lRep').contactId`)==='kBueno'&&E(`byId(S.leads,'lRep').qual.decisorId`)==='kBueno','el prospecto (y su decisor) pasan al contacto elegido');
ok(E(`JSON.stringify(byId(S.opps,'oDup').contactIds)`)==='["kBueno"]','en la oportunidad queda una sola vez');
ok(E(`JSON.stringify(byId(S.plans,'pB').targets[0].contactIds)`)==='["kBueno"]','el contacto identificado en el plan es el elegido');
ok(E(`byId(S.tasks,'tRep').entityId`)==='lRep','la tarea sigue en su prospecto');

console.log('4. La fusión completa datos sin reemplazar');
const k=E(`JSON.stringify(byId(S.contacts,'kBueno'))`);const kb=JSON.parse(k);
ok(kb.email==='mgonzalez@eolicasur.cl'&&kb.siteId==='sOp'&&/LinkedIn: https:\/\/www\.linkedin\.com\/in\/maria-gonzalez-eolica/.test(kb.notes),'completa correo, LinkedIn y operación que faltaban');
ok(kb.phone==='+56 9 1111 1111','el teléfono que ya tenía no se reemplaza');
ok(E(`S.fusiones.length`)===1&&E(`JSON.stringify(S.fusiones.map(x=>[x.tabla,x.de,x.a]))`)==='[["contacts","kRep","kBueno"]]','la fusión queda anotada para el servidor');
ok(E(`S.audit.some(a=>a.field==='Fusión'&&/completa correo, LinkedIn, operación/.test(a.newV))`),'y en la auditoría');
snap('2');

console.log('5. Contacto sin historial: «Eliminar» y también «Fusionar con otro»');
E(`openEntity('contact','kAct')`);
ok(/data-action="delContact"/.test(drawer())&&/Fusionar con otro/.test(drawer()),'muestra ambos');
E(`ACTIONS.delContact({id:'kAct'})`);ok(/Eliminar Ana Activa/.test(modal()),'pide confirmación');
E(`ACTIONS.doDelContact({id:'kAct'})`);ok(!E(`!!byId(S.contacts,'kAct')`),'se elimina');
snap('3');

console.log('6. Fusión de empresas: queda anotada');
E(`mergeCompanies('cAct','cOtra',false);save()`);
ok(E(`S.fusiones.some(x=>x.tabla==='companies'&&x.de==='cAct'&&x.a==='cOtra')`)&&E(`byId(S.activities,'aAct').companyId`)==='cOtra','se mueve su actividad y la fusión queda anotada');
snap('4');

console.log('6b. «Eliminar empresas del plan» sigue borrando las cargadas sin historial; las con sitios se quedan');
E(`(()=>{const p=byId(S.plans,'pB');
 S.companies.push({id:'cCarga',legalName:'Carga SEIA',tradeName:'Carga SEIA',industry:'Energía',source:'SEIA exp. 9',sites:[],createdAt:todayIso},{id:'cSitio',legalName:'Carga Con Sitio',tradeName:'Carga Con Sitio',industry:'Energía',source:'Joan',sites:[{id:'sJ',name:'PFV Joan',type:'Planta'}],createdAt:todayIso});
 S.contacts.push({id:'kCarga',companyId:'cCarga',firstName:'Carla',lastName:'Carga',position:'Gerente',email:'c@carga.cl',phone:'',whatsapp:'',notes:''});
 p.targets.push({companyId:'cCarga',status:'En cadencia',addedAt:todayIso,need:'CCTV',contactIds:['kCarga']},{companyId:'cSitio',status:'Pendiente',addedAt:todayIso,need:''});
 S.leads.push({id:'lCarga',code:'PR-2026-0901',companyId:'cCarga',contactId:'kCarga',planId:'pB',source:'Plan: x',stage:'Identificado',potential:'Medio',problem:'',service:'',ownerId:'u1',vars:{},createdAt:todayIso});
 S.tasks.push({id:'tCarga',title:'Primer contacto',entityType:'lead',entityId:'lCarga',planId:'pB',assigneeId:'u1',priority:'Media',due:todayIso,status:'Pendiente',createdAt:todayIso});save()})()`);
snap('4b');
const tr=JSON.parse(E(`JSON.stringify(targetRemoval(byId(S.plans,'pB'),['cCarga','cSitio'],false))`));E('save()');
ok(!E(`!!byId(S.companies,'cCarga')`)&&!E(`!!byId(S.leads,'lCarga')`)&&E(`!!byId(S.companies,'cSitio')`)&&tr.keepCompanies===1,'se va la cargada sin historial (con su prospecto y tarea del plan); la que tiene sitios se mantiene');
snap('5');

console.log('7. Un borrado que no pasó por la ficha (para probar el servidor)');
E(`S.companies=S.companies.filter(c=>c.id!=='cDup');S.contacts=S.contacts.filter(k=>k.companyId!=='cDup');S.leads=S.leads.filter(l=>l.companyId!=='cDup');S.opps=S.opps.filter(o=>o.companyId!=='cDup');S.tasks=S.tasks.filter(t=>t.entityId!=='lRep');S.plans.forEach(p=>p.targets=p.targets.filter(t=>t.companyId!=='cDup'))`);
snap('malo');

console.log('8. Restaurar un respaldo marca un reemplazo completo');
E(`marcarReemplazo()`);ok(E(`esReemplazo()`),'queda marcado hasta que el servidor lo acepte');
E(`localStorage.removeItem(KEY+'-reemplazo')`);

(async()=>{
console.log('9. Si el servidor rechaza, la pantalla vuelve a su versión y explica por qué');
E(`window.__srv=JSON.parse(JSON.stringify(S));window.__srv.companies.push({id:'cSrv',legalName:'Del Servidor',tradeName:'Del Servidor',sites:[]});
 STORE.mode='servidor';STORE.ready=true;STORE.remoteHas=true;STORE.rev=7;
 api=async(m,q,b)=>{window.__body=b;return{status:422,j:{rechazo:true,error:'No se puede eliminar la empresa «Eólica Sur» porque tiene historial.',rev:9,data:window.__srv}}};flushServer()`);
await E('STORE.queue');
ok(/El servidor no aceptó el cambio/.test(modal())&&/Eólica Sur/.test(modal()),'muestra el motivo');
ok(E(`!!byId(S.companies,'cSrv')&&STORE.rev===9`)&&!E(`'reemplazo' in window.__body`),'carga la versión del servidor (y un guardado normal no va como reemplazo)');
E(`closeModal();closeDrawer();location.hash='hoy';render()`);
ok(!errores.length,'sin errores de JavaScript'+(errores.length?': '+errores.join(' | '):''));
process.exit(f?1:0)})();
