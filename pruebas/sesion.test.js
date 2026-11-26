// Pantalla con cuentas por persona: ingreso en pasos (clave, clave propia, segundo factor), la persona la fija el
// servidor, una copia local de otra persona no se sube, avisos de lo no permitido, roles en la pantalla y cerrar sesión.
// El servidor es simulado (fetch); las reglas reales del servidor las prueban permisos.test.php y cuentas.test.php.
const fs=require('fs');const {JSDOM,VirtualConsole}=require('jsdom');
const HTML=fs.readFileSync(process.argv[2],'utf8');let f=0;const ok=(c,m)=>{console.log((c?'  ✓ ':'  ✗ ')+m);if(!c)f++};
const KEY='fs-crm-proto-v1';const espera=ms=>new Promise(r=>setTimeout(r,ms));
// Lo que hace el servidor con un guardado registro por registro, sin conflictos (las reglas reales: guardado.test.php)
const aplicarCambios=(d,c)=>{for(const r of c.registros||[]){if(!Array.isArray(d[r.t]))d[r.t]=[];const L=d[r.t];const i=L.findIndex(x=>String(x.id)===String(r.id));
 if(r.despues===null){if(i>=0)L.splice(i,1);continue}if(i>=0){L[i]=r.despues;continue}const j=r.tras==null?-1:L.findIndex(x=>String(x.id)===String(r.tras));
 if(r.tras==null)L.unshift(r.despues);else if(j>=0)L.splice(j+1,0,r.despues);else L.push(r.despues)}for(const x of c.doc||[])d[x.k]=x.despues;return d};
const USUARIOS=[
 {id:'g1',name:'Marcela Ibarra',role:'gerente',roleLabel:'Gerente comercial',equipo:'',admin:false,activo:true},
 {id:'sN',name:'Rodrigo Valdés',role:'subgerente',roleLabel:'Subgerente comercial',equipo:'Norte',admin:false,activo:true},
 {id:'eN1',name:'Paula Cifuentes',role:'ejecutivo',roleLabel:'Ejecutivo comercial',equipo:'Norte',admin:false,activo:true},
 {id:'eN2',name:'Tomás Herrera',role:'ejecutivo',roleLabel:'Ejecutivo comercial',equipo:'Norte',admin:false,activo:true},
 {id:'eS1',name:'Javier Núñez',role:'ejecutivo',roleLabel:'Ejecutivo comercial',equipo:'Sur',admin:false,activo:true},
 {id:'ad',name:'Admin Sistema',role:'admin',roleLabel:'Administrador',equipo:'',admin:true,activo:true}];
function base(){
 const d=new JSDOM(HTML,{runScripts:'dangerously',url:'http://localhost/',virtualConsole:new VirtualConsole(),beforeParse(w){w.fetch=()=>new Promise(()=>{})}});
 return d.window.eval(`(()=>{const s=cleanSeed();s.mode='real';s.config.auto=Object.assign({},s.config.auto,{on:false});return JSON.parse(JSON.stringify(s))})()`);
}
// srv.sesion: null (sin sesión) o id de la persona. srv.pasos: respuestas del ingreso, en orden.
function abrir({sesion=null,data,local,localUsuario,pend,pasos=[],avisos=null}){
 const srv={sesion,rev:5,data:JSON.parse(JSON.stringify(data)),posts:[],entradas:[],cabeceras:[],pasos:[...pasos],avisos};
 const vc=new VirtualConsole();const errores=[];vc.on('jsdomError',e=>{if(!/Could not parse CSS|Not implemented/i.test(String(e.message)))errores.push(e.message)});
 const dom=new JSDOM(HTML,{runScripts:'dangerously',pretendToBeVisual:true,url:'http://localhost/',virtualConsole:vc,beforeParse(w){
  if(local){w.localStorage.setItem(KEY,JSON.stringify(local));w.localStorage.setItem(KEY+'-rev','5');if(pend)w.localStorage.setItem(KEY+'-pend','1')}
  if(localUsuario)w.localStorage.setItem(KEY+'-usuario',localUsuario);
  w.CompressionStream=undefined;
  w.fetch=async(url,o)=>{const q=String(url);const m=(o&&o.method)||'GET';let st=200,j;srv.cabeceras.push((o&&o.headers)||{});
   const vista=()=>Object.assign({},srv.data,{users:USUARIOS,currentUser:srv.sesion});
   if(/accion=entrar/.test(q)){srv.entradas.push(JSON.parse(o.body));const p=srv.pasos.shift()||{st:401,j:{error:'Usuario o clave incorrectos.'}};st=p.st;j=p.j;if(p.sesion)srv.sesion=p.sesion}
   else if(/accion=salir/.test(q)){srv.salio=true;srv.sesion=null;j={ok:true}}
   else if(!srv.sesion){st=401;j={error:'Inicia sesión con tu usuario.',sesion:false}}
   else if(m==='GET'&&/accion=leer/.test(q))j={rev:srv.rev,savedAt:srv.data.savedAt,data:vista(),ajustes:null,yo:USUARIOS.find(u=>u.id===srv.sesion),usuarios:USUARIOS};
   else if(m==='GET'&&/accion=rev/.test(q))j={rev:srv.rev};
   else if(m==='GET'&&/accion=usuarios/.test(q))j={usuarios:USUARIOS.map(u=>Object.assign({usuario:u.id+'@prueba.cl',segundoFactor:true,claveTemporal:u.id==='eS1',bloqueada:false,ultimoIngreso:null},u))};
   else if(m==='GET')st=404,j={};
   else if(m==='POST'&&!/accion=/.test(q)){const b=JSON.parse(o.body);srv.posts.push(b);srv.rev++;
    if(srv.avisos){j={rev:srv.rev,avisos:srv.avisos,data:vista()};srv.avisos=null}else if(b.cambios){aplicarCambios(srv.data,b.cambios);j={rev:srv.rev,registros:[],doc:{}}}else{srv.data=b.data;j={rev:srv.rev,data:vista()}}}
   else j={ok:true};
   return{status:st,json:async()=>JSON.parse(JSON.stringify(j))}};
 }});
 return{dom,w:dom.window,srv,errores,E:s=>dom.window.eval(s),modal:()=>dom.window.document.querySelector('#modalLayer').textContent};
}
const enviar=async(t,form,valores)=>{const fm=t.w.document.querySelector(`form[data-form="${form}"]`);if(!fm)return false;for(const[k,v]of Object.entries(valores))fm.querySelector(`[name="${k}"]`).value=v;fm.dispatchEvent(new t.w.Event('submit',{bubbles:true,cancelable:true}));await espera(300);return true};

(async()=>{
 const B=base();B.savedAt=1000;
 B.opps=[{id:'oP',code:'CM-261101-01',name:'Oportunidad de Paula',companyId:'',ownerId:'eN1',status:'abierta',stage:'oferta',prob:30,contactIds:[],services:[],history:[]}];

 console.log('1. Primer ingreso: clave temporal, clave propia y segundo factor');
 let t=abrir({data:B,pasos:[
  {st:200,j:{paso:'nueva-clave',desafio:'d1',nombre:'Paula Cifuentes'}},
  {st:200,j:{paso:'activar',desafio:'d1',nombre:'Paula Cifuentes',secreto:'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',uri:'otpauth://totp/CRM%20FS:pcifuentes?secret=JBSW'}},
  {st:200,j:{ok:true},sesion:'eN1'}]});
 await espera(800);
 ok(!!t.w.document.querySelector('form[data-form="entrar"]')&&/Entrar al CRM/.test(t.modal()),'sin sesión pide usuario y clave');
 ok(t.E('STORE.mode')==='clave'&&/No has entrado con tu usuario/.test(t.w.document.querySelector('#avisoConexion').textContent),'y avisa arriba que no se está guardando nada');
 {const m=t.w.document.querySelector('#main').textContent,u=t.w.document.querySelector('#userSel');ok(/Entra con tu usuario/.test(m)&&!/Solar Andes|Claudio|Oportunidad de Paula/.test(m+t.w.document.querySelector('#side').textContent+u.innerHTML)&&u.hidden&&!t.w.document.querySelector('#side a'),'sin sesión no muestra datos detrás: ni los de ejemplo ni una copia que haya en el navegador')}
 await enviar(t,'entrar',{usuario:' pcifuentes ',clave:'Temporal-1234'});
 ok(t.srv.entradas[0].usuario==='pcifuentes'&&/Elige tu clave/.test(t.modal())&&/Hola, Paula/.test(t.modal()),'con clave temporal pide elegir una propia');
 await enviar(t,'entrarClave',{nueva:'Mi-clave-2026-x',repetir:'otra-cosa-2026'});
 ok(/no coinciden/.test(t.modal())&&t.srv.entradas.length===1,'si las dos claves no coinciden, no envía nada');
 await enviar(t,'entrarClave',{nueva:'Mi-clave-2026-x',repetir:'Mi-clave-2026-x'});
 ok(t.srv.entradas[1].desafio==='d1'&&t.srv.entradas[1].nueva_clave==='Mi-clave-2026-x','envía la clave nueva con el paso del ingreso');
 ok(/Activa el segundo factor/.test(t.modal())&&t.w.document.querySelector('[data-secreto]').textContent==='JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP'&&!!t.w.document.querySelector('a[href^="otpauth://"]'),'muestra la clave secreta en grupos y el enlace para la aplicación');
 await enviar(t,'entrarCodigo',{codigo:'123 456'});await espera(800);
 ok(t.srv.entradas[2].codigo==='123456','envía el código sin espacios');
 ok(t.E('STORE.mode')==='servidor'&&t.E('S.currentUser')==='eN1'&&t.E('me().name')==='Paula Cifuentes','entra: la persona la fija el servidor');
 ok(t.w.document.querySelector('#userSel').hidden&&!t.w.document.querySelector('#userChip').hidden&&t.w.document.querySelector('#userChip').textContent==='Paula Cifuentes','la cabecera muestra a la persona, sin selector de usuario');
 ok(t.srv.cabeceras.every(h=>h['X-CRM']==='1'&&!('X-CRM-Clave' in h)),'cada petición lleva X-CRM y ninguna lleva la antigua clave compartida');
 ok(!t.errores.length,'sin errores de JavaScript'+(t.errores.length?': '+t.errores.join(' | '):''));

 console.log('2. Código errado y sesión vencida');
 t=abrir({data:B,pasos:[{st:200,j:{paso:'codigo',desafio:'d2',nombre:'Paula Cifuentes'}},{st:401,j:{error:'El código no es correcto o ya se usó. Espera el siguiente e inténtalo de nuevo.'}}]});
 await espera(800);await enviar(t,'entrar',{usuario:'pcifuentes',clave:'x'});await enviar(t,'entrarCodigo',{codigo:'000000'});
 ok(/El código no es correcto/.test(t.modal())&&t.E('STORE.mode')==='clave','un código errado muestra el motivo y no entra');
 t=abrir({sesion:'eN1',data:B});await espera(800);
 t.srv.sesion=null;t.E(`S.opps[0].prob=50;save()`);await espera(1500);
 ok(t.E('STORE.mode')==='clave'&&/Tu sesión terminó/.test(t.modal()),'si la sesión vence al guardar, pide entrar de nuevo');
 ok(t.w.localStorage.getItem(KEY+'-pend')==='1','y el cambio queda pendiente en el navegador para subirlo al entrar');

 console.log('3. Copia local de otra persona en el mismo navegador');
 const ajena=JSON.parse(JSON.stringify(B));ajena.opps[0].name='Copia local de Tomás';ajena.savedAt=9000;
 t=abrir({sesion:'eN1',data:B,local:ajena,localUsuario:'eN2',pend:true});await espera(1500);
 ok(!t.srv.posts.some(p=>JSON.stringify(p).includes('Copia local de Tomás')),'no se sube al servidor con la sesión de Paula');
 ok(t.E('S.opps[0].name')==='Oportunidad de Paula'&&t.w.localStorage.getItem(KEY+'-usuario')==='eN1'&&!/Copia local de Tomás/.test(t.w.localStorage.getItem(KEY)||''),'se descarta y queda la vista del servidor de Paula');

 console.log('4. Lo que el servidor no aplica');
 t=abrir({sesion:'eN1',data:B});await espera(1500);t.srv.avisos=['No tienes permiso para cambiar la empresa «Puerto Austral».'];
 t.E(`S.opps[0].prob=55;save()`);await espera(1500);
 ok(/Parte del cambio no se guardó/.test(t.modal())&&/Puerto Austral/.test(t.modal()),'muestra los avisos del servidor');
 ok(t.E('S.opps[0].prob')===30&&t.w.localStorage.getItem(KEY+'-pend')===null,'y toma la versión del servidor');

 console.log('5. Roles en la pantalla');
 t=abrir({sesion:'eN1',data:B});await espera(800);
 t.E(`location.hash='config';render()`);
 ok(!!t.w.document.querySelector('form[data-form="config"] fieldset[disabled]')&&!/Usuarios y equipos/.test(t.w.document.querySelector('#main').textContent),'un ejecutivo ve la configuración sin poder cambiarla y sin la administración de cuentas');
 ok(!t.w.document.querySelector('[data-restore]')&&!t.w.document.querySelector('[data-funnel]'),'ni restaurar respaldos ni importar el funnel');
 ok(/Mi cuenta/.test(t.w.document.querySelector('#main').textContent)&&!!t.w.document.querySelector('[data-action="salir"]'),'sí ve «Mi cuenta» con cambiar clave y cerrar sesión');
 ok(t.E('asignables().map(u=>u.id).join()')==='eN1','solo se puede asignar a sí misma');
 t=abrir({sesion:'sN',data:B});await espera(800);
 ok(t.E('asignables().map(u=>u.id).sort().join()')==='eN1,eN2,sN'&&t.E('seesAll()')&&!t.E('esAdmin()'),'el subgerente asigna a su equipo y ve la vista del equipo, sin administrar');
 t=abrir({sesion:'g1',data:B});await espera(800);t.E(`location.hash='config';render()`);
 ok(t.E('asignables().length')===6&&!!t.w.document.querySelector('form[data-form="config"] fieldset[disabled]')&&!t.w.document.querySelector('[data-funnel]'),'el gerente asigna a cualquiera, con la configuración en solo lectura (y sin importar funnel: nada entra por Excel)');
 t=abrir({sesion:'ad',data:B});await espera(800);t.E(`location.hash='config';render()`);await espera(500);
 const txt=t.w.document.querySelector('#main').textContent;
 ok(/Usuarios y equipos/.test(txt)&&/Javier Núñez/.test(txt)&&/Clave temporal/.test(txt)&&!t.w.document.querySelector('form[data-form="config"] fieldset[disabled]'),'el administrador ve las cuentas con su estado y cambia la configuración');
 t.E(`ACTIONS.ctaNueva()`);
 ok(!!t.w.document.querySelector('form[data-form="ctaCrear"] [name="usuario"]')&&!!t.w.document.querySelector('form[data-form="ctaCrear"] [name="equipo"]'),'y crea cuentas con rol y equipo');

 console.log('6. Cerrar sesión');
 t=abrir({sesion:'eN1',data:B});await espera(800);
 t.w.localStorage.setItem('crm-ajustes','{"targets":{"eN1":1}}');
 t.E(`ACTIONS.salir()`);await espera(800);
 ok(t.srv.salio&&t.w.localStorage.getItem(KEY)===null&&t.w.localStorage.getItem(KEY+'-usuario')===null&&t.w.localStorage.getItem('crm-ajustes')===null,'cierra la sesión en el servidor y borra la copia de este navegador');
 process.exit(f?1:0);
})();
