// Meta de facturación mes a mes y por línea, importada desde el Excel de presupuesto («MI META DE VENTA AAAA»).
// Usa filas inventadas con la misma forma de la hoja; no usa el Excel real.
// Uso: node meta.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS|navigation/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
const main = () => { const tw = doc.createTreeWalker(doc.querySelector('#main'), 4); const o = []; while (tw.nextNode()) o.push(tw.currentNode.nodeValue); return o.join(' ').replace(/\s+/g, ' '); };
const yr = E('todayIso').slice(0, 4), mesIdx = Number(E('todayIso').slice(5, 7)) - 1;
// Hoja de ejemplo: SSFF 1 MM al mes, SSII 0, One Shot 50 MM en junio y diciembre, Servicio 2 MM al mes (MM CLP)
const fila = (n, f) => [n, ...Array.from({ length: 12 }, (_, i) => f(i)), null];
w.__rows = [
  ['Mi meta y el funnel'], [], [`A. MI META DE VENTA ${yr} (MM CLP)`], ['Facturación mensual de venta nueva...'],
  ['Línea de negocio', ...['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'], 'Total'],
  fila('SSFF', () => 1), fila('SSII', () => 0), fila('SSEE One Shot', i => (i === 5 || i === 11 ? 50 : 0)), fila('SSEE Servicio', () => 2), ['Total'],
  [], [`B. MI META DE MARGEN ${yr} (MM CLP)`], ['texto'], ['Línea de negocio', 'Ene'],
  fila('SSFF', () => 0.1), fila('SSEE One Shot', i => (i === 5 || i === 11 ? 10 : 0)), ['Total'],
];
E(`S=cleanSeed();S.mode='real';S.config.targets={u1:7000000};S.config.metaBase='factura';
  S.companies.push({id:'cA',legalName:'Enel',tradeName:'ENEL',industry:'Energía',sites:[]});
  const b={ownerId:'u1',currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:todayIso,createdAt:todayIso,closeDate:todayIso,monthly:0,term:0,status:'ganada',stage:'adjudicada',prob:100};
  S.opps.push({...b,id:'oS',code:'CM-1',name:'Proyecto CCTV',companyId:'cA',wonAt:todayIso,oneTime:60000000,fx:{title:'t',estado:'Booking',name:'x',tipo:'One Shot'}});
  S.opps.push({...b,id:'oG',code:'CM-2',name:'Guardias',companyId:'cA',wonAt:'${Number(yr) - 1}-11-01',monthly:3000000,term:12,fx:{title:'t',estado:'Booking',name:'x',tipo:'Seg. Física'}});
  S.invoices=[{id:'i1',oppId:'oS',number:'1',date:todayIso,net:30000000,iva:0,total:30000000,status:'Emitida'},{id:'i2',oppId:'oG',number:'2',date:todayIso,net:3000000,iva:0,total:3000000,status:'Pagada'}];
  migrate();`);

console.log('1. Lectura de la hoja de presupuesto');
const m = JSON.parse(E(`JSON.stringify(parseMetaPresupuesto(window.__rows))`));
ok(m.yr === yr, 'toma el año del título: ' + m.yr);
ok(m.venta.SSFF.length === 12 && m.venta.SSFF[0] === 1000000 && m.venta['SSEE One Shot'][5] === 50000000, 'convierte MM CLP a pesos, mes a mes y por línea');
ok(m.margen.SSFF[0] === 100000 && m.margen['SSEE One Shot'][11] === 10000000, 'lee también la meta de margen');
ok(/MI META DE VENTA/.test(E(`(()=>{try{parseMetaPresupuesto([['otra cosa']]);return ''}catch(e){return e.message}})()`)), 'avisa si el archivo no trae la meta');

console.log('2. Vista previa y carga');
E(`metaPreview({...parseMetaPresupuesto(window.__rows),fuente:'presupuesto.xlsx'})`);
const prev = doc.querySelector('#modalLayer').textContent;
ok(/Meta de facturación/.test(prev) && /\$136 MM/.test(prev), 'la vista previa muestra la tabla y el total del año ($136 MM)');
E(`ACTIONS.metaUsar()`);
ok(E(`metaYear('${yr}')`) === 136000000, 'la meta del año queda cargada');
ok(E(`metaMes('${yr}-06')`) === 53000000 && E(`metaMes('${yr}-01')`) === 3000000, 'la meta de cada mes es la suma de las líneas (junio $53 MM, enero $3 MM)');
ok(E(`metaAcum('${yr}',5)`) === 18000000 + 50000000, 'meta acumulada a junio: $68 MM');
ok(E(`metaMes('${Number(yr) + 1}-03')`) === 7000000, 'un año sin meta importada usa la meta mensual plana');
ok(E(`S.audit.some(a=>a.entity==='Configuración'&&a.field==='Meta ${yr}')`), 'queda en la auditoría');

console.log('3. Hoy y el informe usan la meta del mes');
E(`location.hash='hoy';render()`);
let t = main();
const metaHoy = E(`metaMes(todayIso.slice(0,7))`);
ok(new RegExp('Facturado en el mes \\$33 MM ' + Math.round(33000000 / metaHoy * 100) + '% de la meta de').test(t), 'Hoy compara lo facturado del mes con la meta de ese mes');
ok(/de la meta anual de \$136 MM/.test(t) && /a la fecha se esperaban/.test(t), 'y lo del año con la meta anual y con lo esperado a la fecha');
E(`location.hash='reportes';UI.repTab='fact';UI.repFactYear=null;render()`);
t = main();
ok(/Meta \d{4} contra lo facturado/.test(t) && /Acumulado meta/.test(t), 'el informe de facturación trae la meta mes a mes');
ok(/SSEE One Shot One Shot \$100 MM \$30 MM 30%/.test(t), 'por línea: One Shot $30 MM de $100 MM (30 %)');
ok(/SSFF Seguridad Física \$12 MM \$3 MM 25%/.test(t), 'SSFF: lo facturado de guardias cerradas el año anterior cuenta en su línea');
ok(/Meta de margen/.test(t), 'muestra la meta de margen por línea');
const filaMes = [...doc.querySelectorAll('#main table')].find(t => /Acumulado meta/.test(t.textContent)).querySelectorAll('tbody tr')[mesIdx];
ok(filaMes && /inv-(ok|pend)/.test(filaMes.className), 'el mes en curso se pinta según vaya o no sobre la meta acumulada');

console.log('4. Configuración');
E(`location.hash='config';render()`);
t = main();
ok(new RegExp('Meta ' + yr + ': \\$136 MM').test(t) && /desde presupuesto\.xlsx/.test(t), 'lista la meta importada con su archivo');
ok(doc.querySelector('input[type=file][data-meta]'), 'tiene el botón para importar el Excel de presupuesto');
E(`ACTIONS.metaBorrar({y:'${yr}'})`);
ok(!E(`metaAnual('${yr}')`) && E(`metaMes('${yr}-06')`) === 7000000, 'quitar la meta vuelve a la meta mensual plana');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
console.log(f ? `\n${f} pruebas fallaron` : '\nTodo bien'); process.exit(f ? 1 : 0);
