// Fase 1, puntos 5 y 6: exportar el funnel en el formato del Excel de Gerencia, metas por persona sumadas a la
// subgerencia y a la gerencia, y el selector de alcance. La parte de permisos usa lo que el servidor real
// (permisos.php) le manda a cada una de las 12 cuentas de la demo (vistas-demo.php): el archivo y las metas de cada
// persona salen solo de su alcance, y el selector nunca lo amplía.
// Uso: node funnel.test.js ../app/index.html
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
const HTML = fs.readFileSync(process.argv[2], 'utf8');
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
const errores = [];
function abrir() {
  const vc = new VirtualConsole(); vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS|navigation/i.test(String(e.message))) errores.push(e.message); });
  const dom = new JSDOM(HTML, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
  const w = dom.window; w.DecompressionStream = DecompressionStream; w.Response = Response; w.Blob = Blob; w.TextDecoder = TextDecoder; w.TextEncoder = TextEncoder;
  w.eval(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
  return { w, E: s => w.eval(s), main: () => w.document.querySelector('#main').textContent.replace(/\s+/g, ' '), modal: () => w.document.querySelector('#modalLayer').textContent.replace(/\s+/g, ' ') };
}

(async () => {
  console.log('1. Formato del Excel de Gerencia');
  const A = abrir(), E = A.E;
  E(`S=cleanSeed();S.mode='real';S.users=[{id:'g1',name:'Marcela Ibarra',role:'gerente',equipo:''},{id:'eN1',name:'Paula Cifuentes',role:'ejecutivo',equipo:'Norte'}];S.currentUser='g1';
    S.config.uf=40000;
    S.companies.push({id:'cA',legalName:'Eólica Norte SpA',tradeName:'Eólica Norte',industry:'Energía',sites:[]});
    S.contacts.push({id:'kD',companyId:'cA',firstName:'Ana',lastName:'Pérez',decisionRole:'Decisor'},{id:'kU',companyId:'cA',firstName:'Luis',lastName:'Gómez',decisionRole:'Usuario'});
    const b={crm:true,ownerId:'eN1',currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:'2026-10-01',createdAt:'2026-09-15',closeDate:'2027-01-31',monthly:0,term:0,oneTime:0,companyId:'cA'};
    S.opps.push({...b,id:'o1',code:'OP-260915-01',name:'Guardias parque',tipo:'Seg. Física',stage:'oferta',status:'abierta',prob:50,monthly:5000000,term:24,oneTime:1200000,contactIds:['kD','kU']});
    S.opps.push({...b,id:'o2',code:'OP-260915-02',name:'CCTV (fase 2)',tipo:'Seg. Electrónica',stage:'seguimiento',status:'abierta',prob:60,monthly:100,term:12,oneTime:50,currency:'UF',history:[{from:'oferta',to:'seguimiento',date:'2026-10-20'}]});
    S.opps.push({...b,id:'o3',code:'OP-260915-03',name:'Integral faena',tipo:'Seg. Integral',stage:'negociacion',status:'abierta',prob:80,monthly:9000000,term:36,oneTime:3000000});
    S.opps.push({...b,id:'o4',code:'OP-260915-04',name:'Mantención',tipo:'One Shot',stage:'adjudicada',status:'ganada',prob:100,oneTime:26000000,wonAt:'2026-11-10'});
    S.opps.push({...b,id:'o5',code:'OP-260915-05',name:'Rondas',tipo:'Seg. Física',stage:'oferta',status:'perdida',prob:0,monthly:7000000,term:12,lossReason:'Precio'});
    S.opps.push({...b,id:'o6',code:'OP-260915-06',name:'Postergada',tipo:'Seg. Física',stage:'oferta',status:'postergada',prob:50,monthly:1,term:1});
    S.opps.push({...b,id:'o7',code:'OP-260915-07',name:'En prospección',tipo:'Seg. Física',stage:'levantamiento',status:'abierta',prob:30,monthly:1,term:1});
    S.opps.push({...b,crm:false,id:'o8',code:'OP-2026-0001',name:'Antigua sin código',tipo:'Seg. Física',stage:'oferta',status:'abierta',prob:50,monthly:1,term:1});
    S.activities.push({id:'a1',entityType:'opp',entityId:'o1',companyId:'cA',type:'Llamada',date:'2026-11-02',userId:'eN1',desc:'x'});`);
  const filas = E(`JSON.parse(JSON.stringify(fgFilas()))`);
  const por = c => filas.find(r => r['Código Oportunidad'] === c) || {};
  ok(JSON.stringify(filas.map(r => r['Código Oportunidad'])) === JSON.stringify(['OP-260915-01', 'OP-260915-02', 'OP-260915-03', 'OP-260915-04', 'OP-260915-05']), 'van las oportunidades del funnel; no las postergadas, las de prospección ni las antiguas sin código OP');
  ok(E(`FG_COLS[10]`) === 'Generador' && E(`FG_COLS.length`) === 24, 'Generador en la columna K, como en el funnel de SharePoint');
  const leidas = ['id', 'titulo', 'estado', 'tipo de negocio', 'monto seg. fisica', 'monto seg. electronica', 'cargo habilitacion', 'probabilidad', 'mg %', 'costo del proyecto', 'proyeccion', 'primera facturacion', 'fecha de contacto', 'fecha presentacion', 'fecha de adjudicacion', 'fecha ultima conversacion', 'generador', 'tomador de decision', 'usuario final', 'notas', 'cuotas carg. habilitacion', 'ano', 'codigo oportunidad', 'motivo no adj.'];
  ok(E(`JSON.stringify(FG_COLS.map(fxNorm).sort())`) === JSON.stringify(leidas.sort()), 'las columnas son exactamente las que lee el importador del funnel (mismos nombres, sin tildes ni mayúsculas)');
  ok(JSON.stringify(filas.map(r => r.Estado)) === JSON.stringify(['Pipeline', 'Upside', 'Forecast', 'Booking', 'No Adjudicado']), 'estados del funnel: Pipeline, Upside, Forecast, Booking y No Adjudicado');
  ok(por('OP-260915-01')['Título'] === 'OP-260915-01-EÓLICA NORTE (Guardias parque)', 'título como en el funnel: código, cliente en mayúsculas y nombre entre paréntesis');
  ok(por('OP-260915-01')['Monto Seg. Física'] === 5000000 && por('OP-260915-01')['Monto Seg. Electrónica'] === 0 && por('OP-260915-01')['Cargo Habilitación'] === 1200000, 'Seguridad Física: el mensual en «Monto Seg. Física» y el monto único en «Cargo Habilitación»');
  ok(por('OP-260915-02')['Monto Seg. Electrónica'] === 4000000 && por('OP-260915-02')['Cargo Habilitación'] === 2000000 && por('OP-260915-02')['Proyección'] === 50 * 40000 + 100 * 40000 * 12, 'Seguridad Electrónica en UF: montos pasados a CLP con la UF de Configuración');
  ok(por('OP-260915-03')['Monto Seg. Física'] === 9000000 && por('OP-260915-03')['Cargo Habilitación'] === 3000000, 'Seguridad Integral: el mensual completo en Física');
  ok(por('OP-260915-04')['Monto Seg. Electrónica'] === 26000000 && por('OP-260915-04')['Cargo Habilitación'] === 0 && por('OP-260915-04')['Fecha de Adjudicación'] === '2026-11-10', 'One Shot (SSEE One Shot): el monto del proyecto en Electrónica, con fecha de adjudicación');
  ok(por('OP-260915-05')['Motivo No Adj.'] === 'Precio' && por('OP-260915-01')['Motivo No Adj.'] === '', 'motivo de no adjudicación solo en las perdidas');
  ok(por('OP-260915-01')['Probabilidad'] === 0.5 && por('OP-260915-01').Generador === 'Paula Cifuentes' && por('OP-260915-01')['Tomador de Decisión'] === 'Ana Pérez' && por('OP-260915-01')['Usuario Final'] === 'Luis Gómez', 'probabilidad como porcentaje, generador = responsable, decisor y usuario final desde los contactos');
  ok(por('OP-260915-02')['Fecha Presentación'] === '2026-10-20' && por('OP-260915-01')['Fecha Última Conversación'] === '2026-11-02' && por('OP-260915-01')['Fecha de Contacto'] === '2026-09-15', 'fechas: contacto = creación, presentación = paso a Upside, última conversación = última gestión');

  // Excel de verdad: se arma el .xlsx y se vuelve a leer con el lector del importador
  const bytes = E(`Array.from(fgXlsx(fgFilas()))`); const buf = Buffer.from(bytes);
  const tmp = path.join(require('os').tmpdir(), 'crm-funnel-gerencia.xlsx'); fs.writeFileSync(tmp, buf);
  A.w.__buf = buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength);
  const rt = await E(`(async()=>{const t=await fxReadXlsx(window.__buf,/Funnel/);return JSON.parse(JSON.stringify({head:t[0],rows:fxRows(t)}))})()`);
  ok(JSON.stringify(rt.head) === JSON.stringify(E(`FG_COLS`)), 'el .xlsx se abre con el lector de Excel del CRM y trae los encabezados en orden');
  const r1 = rt.rows.find(r => r.code === 'OP-260915-01') || {}, r2 = rt.rows.find(r => r.code === 'OP-260915-02') || {}, r4 = rt.rows.find(r => r.code === 'OP-260915-04') || {};
  ok(rt.rows.length === 5 && r1.comp === 'EÓLICA NORTE' && r1.desc === 'Guardias parque' && r2.desc === 'CCTV (fase 2)', 'el importador antiguo separa código, cliente y nombre del título (también con paréntesis en el nombre)');
  ok(r1.estado === 'Pipeline' && r1.tipo === 'Seg. Física' && r1.F === 5000000 && r1.Hb === 1200000 && r1.prob === 50 && r1.ej === 'Paula Cifuentes' && r1.td === 'Ana Pérez' && r1.uf === 'Luis Gómez', 'ida y vuelta: estado, tipo, montos, probabilidad, generador y contactos');
  ok(r1.created === '2026-09-15' && r2.pres === '2026-10-20' && r4.adj === '2026-11-10' && r1.ult === '2026-11-02', 'ida y vuelta: las fechas quedan como fechas de Excel y se leen iguales');
  ok(E(`JSON.stringify(fxTarget(${JSON.stringify(r4)}))`).includes('"status":"ganada"') && E(`fxTarget(${JSON.stringify(r1)}).monthly`) === 5000000, 'leído como funnel, Booking es ganada y los montos vuelven a ser los del CRM');
  const zipTxt = buf.toString('latin1');
  ok(/sheet name="Funnel de Ventas"/.test(zipTxt) && /<c r="C2" s="1"><v>\d{5}<\/v>/.test(zipTxt) && /<c r="J2" s="2"><v>0\.5<\/v>/.test(zipTxt) && /autoFilter ref="A1:X6"/.test(zipTxt), 'hoja «Funnel de Ventas», fechas con formato de fecha, probabilidad con formato %, filtro en los encabezados');
  ok(buf.readUInt32LE(0) === 0x04034b50 && buf.readUInt32LE(buf.length - 22) === 0x06054b50, 'archivo zip válido (.xlsx)');
  E(`location.hash='oportunidades';render();document.querySelector('[data-action=exportFunnel]').click()`);
  ok(A.modal().includes('5 oportunidades de Toda la gerencia') && A.modal().includes('1 postergada no va') && A.modal().includes('Seguridad Integral') && A.w.document.querySelector('#modalLayer [data-action=dlFunnel]'), 'botón en Oportunidades: vista previa con cuántas van, aviso de postergadas e integrales y descarga del Excel');
  ok(E(`S.audit.some(a=>a.entity==='Exportación'&&a.field==='Funnel formato Gerencia')`), 'la exportación queda en la auditoría');
  E(`location.hash='reportes';UI.repTab='opps';render()`);
  ok(!!A.w.document.querySelector('#main [data-action=exportFunnel]'), 'también se exporta desde Reportes');

  console.log('2. Permisos: lo que recibe cada cuenta de la demo');
  const V = JSON.parse(execFileSync('php', [path.join(__dirname, 'vistas-demo.php'), path.join(__dirname, '../app')], { encoding: 'utf8' }));
  const EQ = { g1: '*', ad: '*', sN: 'Norte', sS: 'Sur' };
  const users = V.g1.users; const nombre = id => (users.find(u => u.id === id) || {}).name;
  const alcance = id => { const u = users.find(x => x.id === id); if (EQ[id] === '*') return users.map(x => x.id); if (u.role === 'subgerente') return users.filter(x => x.equipo === u.equipo).map(x => x.id); return [id]; };
  const B = abrir();
  const como = (id, alc) => B.E(`S=${JSON.stringify(V[id].data)};S.users=${JSON.stringify(V[id].users)};S.currentUser='${id}';UI.alcance=${JSON.stringify(alc || '')};S.mode='real';`);
  let mal = [];
  for (const id of Object.keys(V)) {
    como(id);
    const gen = new Set(B.E(`fgFilas().map(r=>r.Generador)`)); const perm = new Set(alcance(id).map(nombre));
    const esperado = V.g1.data.opps.filter(o => alcance(id).includes(o.ownerId) && o.status !== 'postergada' && !['identificada', 'calificada', 'levantamiento'].includes(o.stage)).length;
    if ([...gen].some(g => !perm.has(g)) || B.E(`fgFilas().length`) !== esperado) mal.push(id);
  }
  ok(!mal.length, 'el funnel exportado de cada una de las 12 cuentas trae exactamente las oportunidades de su alcance' + (mal.length ? ' (fallan: ' + mal.join(', ') + ')' : ''));
  como('eN1');
  ok(B.E(`JSON.stringify([...new Set(fgFilas().map(r=>r.Generador))])`) === '["Paula Cifuentes"]' && B.E(`alcanceOpts().length`) === 0, 'una ejecutiva exporta solo lo suyo y no tiene selector de alcance');
  como('eN1', 'u:eN2');
  ok(B.E(`fgFilas().every(r=>r.Generador==='Paula Cifuentes')`) && B.E(`alcanceVal()`) === '', 'una ejecutiva que fuerza el alcance de otra persona sigue viendo solo lo suyo');
  como('sN');
  const optsN = B.E(`alcanceOpts().map(x=>x[0])`);
  ok(optsN[0] === '' && optsN[1] === 'yo' && optsN.length === 6 && optsN.slice(2).every(k => ['u:eN1', 'u:eN2', 'u:eN3', 'u:eN4'].includes(k)), 'el subgerente elige entre su subgerencia completa, su cartera y cada ejecutivo de su equipo; nada del Sur ni la gerencia');
  como('sN', 'eq:Sur');
  ok(B.E(`alcanceVal()`) === '' && B.E(`fgFilas().every(r=>${JSON.stringify(alcance('sN').map(nombre))}.includes(r.Generador))`), 'el subgerente que fuerza el alcance de la otra subgerencia sigue en la suya');
  como('sN', 'u:eS1');
  ok(B.E(`alcanceVal()`) === '' && B.E(`mine(S.opps).every(o=>${JSON.stringify(alcance('sN'))}.includes(o.ownerId))`), 'ni pidiendo una persona de otra subgerencia');
  como('sN', 'yo');
  ok(B.E(`mine(S.opps).every(o=>o.ownerId==='sN')&&mine(S.opps).length>0`) && B.E(`fgFilas().every(r=>r.Generador==='Rodrigo Valdés')`), '«Mi cartera» del subgerente: solo sus oportunidades');
  como('sN', 'u:eN1');
  ok(B.E(`mine(S.opps).every(o=>o.ownerId==='eN1')&&mine(S.leads).every(l=>l.ownerId==='eN1')&&mine(S.tasks).every(t=>(t.ownerId||t.assigneeId)==='eN1')`), 'el subgerente mira a una ejecutiva: oportunidades, prospectos y tareas solo de ella');
  como('g1');
  const optsG = B.E(`alcanceOpts().map(x=>x[0])`);
  ok(optsG.slice(0, 3).join() === ',eq:Norte,eq:Sur' && optsG.length === 13 && !optsG.includes('u:g1') && !optsG.includes('u:ad'), 'el gerente elige toda la gerencia, cada subgerencia o cada una de las 10 personas con cartera');
  como('g1', 'eq:Sur');
  ok(B.E(`mine(S.opps).every(o=>${JSON.stringify(alcance('sS'))}.includes(o.ownerId))&&mine(S.opps).length===${V.sS.data.opps.length}`), 'el gerente con «Subgerencia Sur» ve lo mismo que la subgerente Sur');

  console.log('3. Metas por persona, subgerencia y gerencia');
  const META = { sN: 70000000, eN1: 35000000, eN2: 32000000, eN3: 30000000, eN4: 25000000, sS: 65000000, eS1: 33000000, eS2: 30000000, eS3: 28000000, eS4: 22000000 };
  const suma = ids => ids.reduce((t, id) => t + (META[id] || 0), 0);
  const norte = ['sN', 'eN1', 'eN2', 'eN3', 'eN4'], sur = ['sS', 'eS1', 'eS2', 'eS3', 'eS4'];
  como('eN1');
  let M = B.E(`JSON.parse(JSON.stringify(metasEquipo()))`);
  ok(M.length === 1 && M[0].lab === 'Paula Cifuentes' && M[0].mes === META.eN1 && B.E(`metaTarget()`) === META.eN1, 'la ejecutiva ve solo su meta');
  como('sN');
  M = B.E(`JSON.parse(JSON.stringify(metasEquipo()))`);
  ok(M.filter(r => r.tipo === 'persona').length === 5 && M[0].lab === 'Rodrigo Valdés' && M[0].sub && M.at(-1).tipo === 'subgerencia' && M.at(-1).mes === suma(norte) && !M.some(r => r.tipo === 'gerencia'), 'el subgerente ve las 5 metas de su subgerencia (la suya primero, con cartera propia) y el total de la subgerencia');
  ok(B.E(`metaTarget()`) === suma(norte) && M.at(-1).lm === M.filter(r => r.tipo === 'persona').reduce((t, r) => t + r.lm, 0) && M.at(-1).la === M.filter(r => r.tipo === 'persona').reduce((t, r) => t + r.la, 0), 'lo logrado de la subgerencia es la suma de lo logrado por cada persona');
  ok(Object.keys(V.sN.data.config.targets).sort().join() === norte.slice().sort().join(), 'el servidor solo le manda al subgerente las metas de su equipo');
  como('sN', 'u:eN2');
  ok(B.E(`metaTarget()`) === META.eN2 && B.E(`metasEquipo().length`) === 1, 'con una persona elegida, la meta es la de esa persona');
  como('g1');
  M = B.E(`JSON.parse(JSON.stringify(metasEquipo()))`);
  const sub = q => M.find(r => r.lab === 'Subgerencia ' + q) || {};
  ok(M.filter(r => r.tipo === 'persona').length === 10 && sub('Norte').mes === suma(norte) && sub('Sur').mes === suma(sur) && M.at(-1).tipo === 'gerencia' && M.at(-1).mes === suma(norte) + suma(sur), 'el gerente ve las 10 metas, el total de cada subgerencia y el de la gerencia');
  ok(M.at(-1).lm === sub('Norte').lm + sub('Sur').lm && M.at(-1).la === sub('Norte').la + sub('Sur').la && M.at(-1).la > 0, 'la gerencia suma lo logrado por las dos subgerencias');
  como('g1', 'eq:Norte');
  ok(B.E(`metaTarget()`) === suma(norte) && B.E(`metasEquipo().map(r=>r.lab).join()`).includes('Subgerencia Norte') && !B.E(`metasEquipo().map(r=>r.lab).join()`).includes('Sur'), 'el gerente con una subgerencia elegida ve sus metas y su total');
  // Meta del Excel de presupuesto: es de toda la gerencia; con una subgerencia elegida se usa la suma por persona
  const yr = B.E(`todayIso.slice(0,4)`);
  como('g1'); B.E(`S.config.metas={'${yr}':{venta:{SSFF:Array(12).fill(400000000)}}};S.config.metaBase='factura'`);
  ok(B.E(`metaMes(todayIso.slice(0,7))`) === 400000000, 'gerencia completa: la meta del mes es la del Excel de presupuesto');
  B.E(`UI.alcance='eq:Sur'`);
  ok(B.E(`metaMes(todayIso.slice(0,7))`) === suma(sur) && B.E(`metaYear(todayIso.slice(0,4))`) === suma(sur) * 12, 'con una subgerencia elegida, la meta es la suma de sus personas, no la del Excel');
  B.E(`UI.alcance=''`);

  console.log('4. Pantallas con el selector');
  for (const [id, alc] of [['g1', ''], ['g1', 'eq:Norte'], ['sN', ''], ['sN', 'u:eN1'], ['eN1', '']]) {
    como(id, alc);
    B.E(`location.hash='oportunidades';UI.oppView='kanban';render()`); const k = B.main(); const sel = !!B.w.document.querySelector('#main select[data-filter=alcance]');
    B.E(`UI.oppView='tabla';render()`); const t = B.main();
    B.E(`location.hash='reportes';UI.repTab='opps';render()`); const r = B.main();
    B.E(`UI.repTab='fact';render()`); B.E(`location.hash='prospectos';render()`); B.E(`location.hash='actividades';render()`); B.E(`location.hash='hoy';render()`);
    const lab = B.E(`alcanceLabel()`);
    ok(k.includes('Pipeline') && t.includes('Oportunidades') && r.includes('Metas por persona y equipo') && r.includes(lab) && sel === (id !== 'eN1'), `${id}${alc ? ' con «' + lab + '»' : ''}: Kanban, tabla y reportes con ${sel ? 'selector de alcance' : 'su cartera, sin selector'}`);
  }
  como('g1', 'eq:Sur'); B.E(`location.hash='oportunidades';UI.oppView='tabla';UI.oppStatus='';render()`);
  const enTabla = B.E(`[...document.querySelectorAll('#main tbody tr')].length`);
  ok(enTabla > 0 && !B.E(`document.querySelector('#main tbody').textContent`).includes('Paula Cifuentes') && B.E(`document.querySelector('#main tbody').textContent`).includes('Verónica Lagos'), 'la tabla de oportunidades del gerente con «Subgerencia Sur» no muestra a nadie del Norte');
  B.E(`document.querySelector('#main select[data-filter=alcance]').value='u:eN1';document.querySelector('#main select[data-filter=alcance]').dispatchEvent(new window.Event('change',{bubbles:true}))`);
  ok(B.E(`UI.alcance`) === 'u:eN1' && B.E(`mine(S.opps).every(o=>o.ownerId==='eN1')`), 'cambiar el selector cambia lo que se ve');

  ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.slice(0, 3).join(' | ') : ''));
  console.log(f ? `\n${f} prueba(s) fallaron` : '\nTodas las pruebas pasaron'); process.exit(f ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
