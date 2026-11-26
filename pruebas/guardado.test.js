// Pantalla con guardado registro por registro (servidor simulado; las reglas reales las prueba guardado.test.php):
// manda solo lo que cambió, toma el código definitivo que pone el servidor, junta lo que otra persona guardó sin perder
// lo que se está escribiendo y avisa los conflictos.
const fs=require('fs');const {JSDOM,VirtualConsole}=require('jsdom');
const HTML=fs.readFileSync(process.argv[2],'utf8');let f=0;const ok=(c,m)=>{console.log((c?'  ✓ ':'  ✗ ')+m);if(!c)f++};
const espera=ms=>new Promise(r=>setTimeout(r,ms));
const USUARIOS=[
 {id:'sN',name:'Rodrigo Valdés',role:'subgerente',roleLabel:'Subgerente comercial',equipo:'Norte',admin:false,activo:true},
 {id:'eN1',name:'Paula Cifuentes',role:'ejecutivo',roleLabel:'Ejecutivo comercial',equipo:'Norte',admin:false,activo:true}];
function base(){
 const d=new JSDOM(HTML,{runScripts:'dangerously',url:'http://localhost/',virtualConsole:new VirtualConsole(),beforeParse(w){w.fetch=()=>new Promise(()=>{})}});
 const s=d.window.eval(`(()=>{const s=cleanSeed();s.mode='real';s.config.auto=Object.assign({},s.config.auto,{on:false});return JSON.parse(JSON.stringify(s))})()`);
 s.companies=[{id:'C1',legalName:'Eólica Norte SpA',tradeName:'Eólica Norte',industry:'Energía',sites:[],createdAt:'2026-11-01'}];
 s.opps=[{id:'O1',code:'OP-261120-01',crm:true,name:'CCTV parque',companyId:'C1',ownerId:'eN1',status:'abierta',stage:'oferta',prob:50,currency:'CLP',oneTime:10000000,monthly:0,term:0,closeDate:'2027-02-01',contactIds:[],services:[],history:[],createdAt:'2026-11-20',stageEnteredAt:'2026-11-20'},
  {id:'O2',code:'OP-261120-02',crm:true,name:'Monitoreo subestación',companyId:'C1',ownerId:'eN1',status:'abierta',stage:'oferta',prob:50,currency:'CLP',oneTime:5000000,monthly:0,term:0,closeDate:'2027-02-01',contactIds:[],services:[],history:[],createdAt:'2026-11-20',stageEnteredAt:'2026-11-20'}];
 s.seq=Object.assign({},s.seq,{opUlt:'OP-261120-02'});s.savedAt=1000;
 return s}
// srv.responder(cuerpo): respuesta del POST (por defecto: aplica sin conflictos y devuelve lo tocado)
function abrir(data){
 const srv={rev:5,data:JSON.parse(JSON.stringify(data)),posts:[],responder:null,demora:0};
 const vc=new VirtualConsole();const errores=[];vc.on('jsdomError',e=>{if(!/Could not parse CSS|Not implemented/i.test(String(e.message)))errores.push(e.message)});
 const dom=new JSDOM(HTML,{runScripts:'dangerously',pretendToBeVisual:true,url:'http://localhost/',virtualConsole:vc,beforeParse(w){
  w.CompressionStream=undefined;
  w.fetch=async(url,o)=>{const q=String(url);const m=(o&&o.method)||'GET';let st=200,j;
   const vista=()=>Object.assign(JSON.parse(JSON.stringify(srv.data)),{users:USUARIOS,currentUser:'eN1'});
   if(m==='GET'&&/accion=leer/.test(q))j={rev:srv.rev,data:vista(),ajustes:null,yo:USUARIOS[1],usuarios:USUARIOS};
   else if(m==='GET'&&/accion=rev/.test(q))j={rev:srv.rev};
   else if(m==='GET')st=404,j={};
   else if(m==='POST'&&!/accion=/.test(q)){const b=JSON.parse(o.body);srv.posts.push(b);if(srv.demora)await new Promise(r=>setTimeout(r,srv.demora));
    if(srv.responder){j=srv.responder(b,vista);srv.responder=null}
    else{srv.rev++;const regs=[];for(const r of b.cambios.registros){const L=srv.data[r.t]||(srv.data[r.t]=[]);const i=L.findIndex(x=>x.id===r.id);if(r.despues===null){if(i>=0)L.splice(i,1)}else if(i>=0)L[i]=r.despues;else L.push(r.despues);regs.push({t:r.t,id:r.id,datos:r.despues})}
     for(const d of b.cambios.doc)srv.data[d.k]=d.despues;j={rev:srv.rev,registros:regs,doc:{seq:srv.data.seq}}}}
   else j={ok:true};
   return{status:st,json:async()=>JSON.parse(JSON.stringify(j))}};
 }});
 return{w:dom.window,srv,errores,E:s=>dom.window.eval(s),modal:()=>dom.window.document.querySelector('#modalLayer').textContent};
}

(async()=>{
 const B=base();
 console.log('1. Solo se manda lo que cambió');
 let t=abrir(B);await espera(1200);
 ok(t.E('STORE.mode')==='servidor'&&!!t.E('STORE.base'),'entra con la versión del servidor como base');
 t.E(`byId(S.opps,'O1').prob=60;audit('OP-261120-01','Probabilidad',50,60);save()`);await espera(1200);
 let p=t.srv.posts[0];
 ok(p&&!p.data&&p.cambios&&p.baseRev===5,'el guardado lleva cambios y la revisión, no la copia completa');
 const tablas=p?p.cambios.registros.map(r=>r.t+':'+(r.antes?'cambio':'alta')).sort().join(', '):'';
 ok(tablas==='audit:alta, opps:cambio','solo la oportunidad cambiada y su movimiento de auditoría: '+tablas);
 const ro=p&&p.cambios.registros.find(r=>r.t==='opps');
 ok(ro&&ro.antes.prob===50&&ro.despues.prob===60,'con la versión que había (antes) y la nueva (después)');
 ok(t.E('STORE.rev')===6&&t.E('localStorage.getItem("fs-crm-proto-v1-pend")')===null,'queda en la nueva revisión y sin pendientes');
 t.E(`byId(S.opps,'O2').name='Monitoreo subestación Cardones';save()`);await espera(1200);
 p=t.srv.posts[1];
 ok(p&&p.cambios.registros.length===1&&p.cambios.registros[0].id==='O2','el siguiente guardado ya no repite lo anterior');
 const n=t.srv.posts.length;t.E('save()');await espera(1200);
 ok(t.srv.posts.length===n,'si no cambió nada, no se manda nada');

 console.log('2. El código definitivo lo pone el servidor');
 t.E(`ACTIONS.newOpp({})`);
 const prov=t.w.document.querySelector('form[data-form="newOpp"] input[disabled]').value;
 const hoy=t.E('todayIso'),k=hoy.slice(2,4)+hoy.slice(5,7)+hoy.slice(8,10);
 ok(prov==='OP-'+k+'-01','el formulario propone el provisional del día: '+prov);
 t.E('closeModal()');
 t.srv.responder=(b,vista)=>{t.srv.rev++;const o=b.cambios.registros.find(r=>r.t==='opps').despues;const fin=Object.assign({},o,{code:'OP-'+k+'-03'});t.srv.data.opps.push(fin);
  return{rev:t.srv.rev,codigos:{[o.code]:fin.code},registros:b.cambios.registros.map(r=>({t:r.t,id:r.id,datos:r.t==='opps'?fin:Object.assign({},r.despues,{entity:fin.code})})),doc:{seq:Object.assign({},t.srv.data.seq,{opUlt:fin.code})}}};
 t.E(`(()=>{const o=nuevaOpp({name:'Control de acceso',oneTime:'3000000',closeDate:addDays(20),nextAction:'x',nextActionDate:todayIso},{companyId:'C1'});S.opps.push(o);audit(o.code,'Creación','',o.name);save();window.__nueva=o.id})()`);await espera(1200);
 ok(t.E('byId(S.opps,__nueva).code')==='OP-'+k+'-03','la pantalla toma el código que asignó el servidor');
 ok(t.E('STORE.base.opps.find(o=>o.id===__nueva).code')==='OP-'+k+'-03'&&t.E('S.seq.opUlt')==='OP-'+k+'-03','y lo deja en su base (el próximo provisional parte de ahí)');
 t.E(`byId(S.opps,__nueva).prob=70;save()`);await espera(1200);
 p=t.srv.posts[t.srv.posts.length-1];
 ok(p.cambios.registros.length===1&&p.cambios.registros[0].antes.code==='OP-'+k+'-03','el siguiente cambio ya parte del código definitivo (sin conflicto falso)');

 console.log('3. Otra persona guardó entremedio: se junta sin perder lo que se escribía');
 t.srv.responder=(b,vista)=>{t.srv.rev+=2;for(const r of b.cambios.registros){const i=t.srv.data[r.t].findIndex(x=>x.id===r.id);if(i>=0)t.srv.data[r.t][i]=r.despues;else t.srv.data[r.t].push(r.despues)}
  t.srv.data.opps.find(o=>o.id==='O2').nextAction='Lo agregó Rodrigo';return{rev:t.srv.rev,data:vista()}};
 t.srv.demora=800;const nPost=t.srv.posts.length;
 t.E(`byId(S.opps,'O1').closeDate='2027-03-01';save()`);await espera(900);   // queda en camino…
 ok(t.srv.posts.length===nPost+1,'(el guardado va en camino)');
 t.E(`byId(S.opps,'O1').name='CCTV parque Taltal'`);                         // …y mientras, se sigue editando
 await espera(2500);t.srv.demora=0;await espera(1200);
 ok(t.E(`byId(S.opps,'O2').nextAction`)==='Lo agregó Rodrigo','llega lo que guardó la otra persona');
 ok(t.E(`byId(S.opps,'O1').closeDate`)==='2027-03-01'&&t.E(`byId(S.opps,'O1').name`)==='CCTV parque Taltal','y se conserva lo propio, también lo escrito mientras se guardaba');

 console.log('4. Conflicto: los dos cambiaron lo mismo');
 t.srv.responder=(b,vista)=>{t.srv.rev++;t.srv.data.opps.find(o=>o.id==='O2').prob=80;
  return{rev:t.srv.rev,conflictos:[{t:'opps',id:'O2',motivo:'cambio',nombre:'Monitoreo subestación Cardones',quien:'Rodrigo Valdés',en:new Date().toISOString()}],data:vista()}};
 t.E(`byId(S.opps,'O2').prob=55;save()`);await espera(1500);
 ok(/Otra persona cambió lo mismo/.test(t.modal())&&/Monitoreo subestación Cardones/.test(t.modal())&&/Rodrigo Valdés/.test(t.modal()),'avisa qué registro y quién lo cambió');
 ok(t.E(`byId(S.opps,'O2').prob`)===80&&t.E('localStorage.getItem("fs-crm-proto-v1-pend")')===null,'queda la versión del servidor, sin volver a insistir');
 t.E('closeModal()');

 console.log('5. Al actualizar desde el servidor, lo que no se ha guardado no se pierde');
 t.srv.data.opps.find(o=>o.id==='O1').prob=90;t.srv.rev++;
 t.E(`byId(S.opps,'O2').nextAction='Escribiendo todavía'`);   // cambio local que aún no se guarda
 await t.E('refreshFromServer()');await espera(1500);
 ok(t.E(`byId(S.opps,'O1').prob`)===90,'trae el cambio de la otra persona');
 ok(t.E(`byId(S.opps,'O2').nextAction`)==='Escribiendo todavía','y conserva el propio, que se sube después');
 ok(t.srv.data.opps.find(o=>o.id==='O2').nextAction==='Escribiendo todavía','(ya quedó en el servidor)');
 ok(!t.errores.length,'sin errores de JavaScript'+(t.errores.length?': '+t.errores.join(' | '):''));
 process.exit(f?1:0);
})();
