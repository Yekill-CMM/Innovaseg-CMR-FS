// Sincronización con el servidor al abrir: una copia vieja del navegador nunca pisa lo que hay en el servidor
// (16-11-2026: el iPhone abrió con datos de la mañana, las automatizaciones lo dejaron «más nuevo» y subió todo encima).
const fs=require('fs');const {JSDOM,VirtualConsole}=require('jsdom');
const HTML=fs.readFileSync(process.argv[2],'utf8');let f=0;const ok=(c,m)=>{console.log((c?'  ✓ ':'  ✗ ')+m);if(!c)f++};
const KEY='fs-crm-proto-v1';
const espera=ms=>new Promise(r=>setTimeout(r,ms));
// Lo que hace el servidor con un guardado registro por registro, sin conflictos (las reglas reales: guardado.test.php)
const aplicarCambios=(d,c)=>{for(const r of c.registros||[]){if(!Array.isArray(d[r.t]))d[r.t]=[];const L=d[r.t];const i=L.findIndex(x=>String(x.id)===String(r.id));
 if(r.despues===null){if(i>=0)L.splice(i,1);continue}if(i>=0){L[i]=r.despues;continue}const j=r.tras==null?-1:L.findIndex(x=>String(x.id)===String(r.tras));
 if(r.tras==null)L.unshift(r.despues);else if(j>=0)L.splice(j+1,0,r.despues);else L.push(r.despues)}for(const x of c.doc||[])d[x.k]=x.despues;return d};

// Datos base reales (sin automatizaciones, para que la prueba no dependa de ellas)
function base(){
 const d=new JSDOM(HTML,{runScripts:'dangerously',url:'http://localhost/',virtualConsole:new VirtualConsole(),beforeParse(w){w.fetch=()=>new Promise(()=>{})}});
 const S=d.window.eval(`(()=>{const s=cleanSeed();s.mode='real';s.config.auto=Object.assign({},s.config.auto,{on:false});return JSON.parse(JSON.stringify(s))})()`);
 return S;
}
function abrir({local,localRev,pend,server,serverRev}){
 const srv={rev:serverRev,data:JSON.parse(JSON.stringify(server)),posts:[]};
 const vc=new VirtualConsole();const errores=[];vc.on('jsdomError',e=>{if(!/Could not parse CSS|Not implemented/i.test(String(e.message)))errores.push(e.message)});
 const dom=new JSDOM(HTML,{runScripts:'dangerously',pretendToBeVisual:true,url:'http://localhost/',virtualConsole:vc,beforeParse(w){
  w.localStorage.setItem(KEY,JSON.stringify(local));w.localStorage.setItem('crm-clave','x');
  if(localRev!=null)w.localStorage.setItem(KEY+'-rev',String(localRev));if(pend)w.localStorage.setItem(KEY+'-pend','1');
  w.CompressionStream=undefined;
  w.fetch=async(url,o)=>{const q=String(url);const m=(o&&o.method)||'GET';let st=200,j;
   if(m==='GET'&&/accion=leer/.test(q))j={rev:srv.rev,savedAt:srv.data.savedAt,data:srv.data,ajustes:null};
   else if(m==='GET'&&/accion=rev/.test(q))j={rev:srv.rev};
   else if(m==='GET')st=404,j={};
   else if(m==='POST'&&!/accion=/.test(q)){const b=JSON.parse(o.body);srv.posts.push(b);
    if(b.cambios){srv.rev++;aplicarCambios(srv.data,b.cambios);j={rev:srv.rev,registros:[],doc:{}}}else if(b.baseRev!==srv.rev){st=409;j={rev:srv.rev,savedAt:srv.data.savedAt,data:srv.data}}else{srv.rev++;srv.data=b.data;j={rev:srv.rev,data:srv.data}}}
   else j={ok:true};
   return{status:st,json:async()=>JSON.parse(JSON.stringify(j))}};
 }});
 return{dom,w:dom.window,srv,errores};
}
const nombres=S=>S.plans.map(p=>p.name).sort().join(',');

(async()=>{
 const B=base();B.savedAt=1000;
 const viejo=JSON.parse(JSON.stringify(B));viejo.plans=[{id:'pm1',name:'Plan Energía noviembre 2026',vertical:'Energía',status:'Borrador',targets:[],ownerId:B.currentUser}];viejo.savedAt=5000; // «más nuevo» por fecha
 const nuevo=JSON.parse(JSON.stringify(B));nuevo.plans=[{id:'pw1',name:'Plan semana 47-26',vertical:'Todas',week:'47-26',status:'Borrador',targets:[],ownerId:B.currentUser}];nuevo.savedAt=2000;

 console.log('Copia vieja sin cambios pendientes (caso iPhone)');
 let t=abrir({local:viejo,localRev:234,pend:false,server:nuevo,serverRev:237});await espera(1500);
 ok(t.srv.posts.every(p=>!JSON.stringify(p).includes('pm1')),'no sube la copia vieja al servidor');
 ok(nombres(t.srv.data)==='Plan semana 47-26','el servidor conserva el plan semanal');
 ok(nombres(t.w.eval('S'))==='Plan semana 47-26','la pantalla muestra la versión del servidor');
 ok(!/Se cargó la versión del servidor/.test(t.w.document.querySelector('#modalLayer').textContent),'sin aviso: no había nada propio que perder');
 ok(!t.errores.length,'sin errores de JavaScript'+(t.errores.length?': '+t.errores.join(' | '):''));

 console.log('Copia de antes de esta versión (sin revisión guardada)');
 t=abrir({local:viejo,localRev:null,pend:false,server:nuevo,serverRev:237});await espera(1500);
 ok(nombres(t.srv.data)==='Plan semana 47-26','tampoco pisa el servidor');

 console.log('Cambios sin subir sobre una versión antigua');
 t=abrir({local:viejo,localRev:234,pend:true,server:nuevo,serverRev:237});await espera(1500);
 ok(nombres(t.srv.data)==='Plan semana 47-26','no pisa el servidor');
 ok(/Se cargó la versión del servidor/.test(t.w.document.querySelector('#modalLayer').textContent),'avisa que se cargó la versión del servidor');
 ok(!!t.w.localStorage.getItem(KEY+'-previo')&&/pm1/.test(t.w.localStorage.getItem(KEY+'-previo')),'guarda la copia local aparte para descargarla');

 console.log('Cambios sin subir sobre la misma versión del servidor');
 const local2=JSON.parse(JSON.stringify(nuevo));local2.plans[0].name='Plan semana 47-26 (editado sin conexión)';local2.savedAt=3000;
 t=abrir({local:local2,localRev:237,pend:true,server:nuevo,serverRev:237});await espera(1500);
 ok(nombres(t.srv.data)==='Plan semana 47-26 (editado sin conexión)','sube el cambio pendiente');
 ok(t.w.localStorage.getItem(KEY+'-pend')===null,'queda sin pendientes');
 ok(t.w.localStorage.getItem(KEY+'-rev')===String(t.srv.rev),'la copia queda en la nueva revisión');

 process.exit(f?1:0);
})();
