// Informes: facturación de Booking (cerradas y pendientes de facturar) y prospectos convertidos en oportunidad.
// Uso: node informes.test.js ../app/index.html
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS|navigation/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, doc = w.document, E = s => w.eval(s);
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
E(`window.__toasts=[];toast=m=>window.__toasts.push(m);`);
const main = () => { const tw = doc.createTreeWalker(doc.querySelector('#main'), 4); const out = []; while (tw.nextNode()) out.push(tw.currentNode.nodeValue); return out.join(' ').replace(/\s+/g, ' '); };
E(`S=cleanSeed();S.mode='real';
  S.companies.push({id:'cA',legalName:'Enel',tradeName:'ENEL',industry:'Energía',sites:[]},{id:'cB',legalName:'Colbún',tradeName:'COLBUN',industry:'Energía',sites:[]});
  const b={ownerId:'u1',currency:'CLP',contactIds:[],services:[],history:[],stageEnteredAt:todayIso,createdAt:'2026-03-01',closeDate:todayIso,monthly:0,term:0,oneTime:0};
  S.opps.push({...b,id:'o1',code:'CM-261101-01',name:'CCTV Tarapacá',companyId:'cA',status:'ganada',stage:'adjudicada',prob:100,wonAt:'2026-11-09',oneTime:10000000,fx:{title:'t',estado:'Booking',name:'x'}});
  S.opps.push({...b,id:'o2',code:'CM-261101-02',name:'Bodega DDAT',companyId:'cB',status:'ganada',stage:'adjudicada',prob:100,wonAt:'2026-08-28',oneTime:5000000,fx:{title:'t',estado:'Booking',name:'x'},leadId:'l1'});
  S.opps.push({...b,id:'o3',code:'CM-251201-01',name:'Antigua',companyId:'cB',status:'ganada',stage:'adjudicada',prob:100,wonAt:'2025-12-10',oneTime:2000000,fx:{title:'t',estado:'Booking',name:'x'}});
  S.opps.push({...b,id:'o4',code:'CM-261101-04',name:'SES Arica',companyId:'cB',status:'abierta',stage:'seguimiento',prob:60,oneTime:8000000,fx:{title:'t',estado:'Upside',name:'x'},leadId:'l2'});
  S.invoices=[{id:'f1',oppId:'o1',number:'10',date:'2026-11-15',net:4000000,iva:760000,total:4760000,status:'Emitida'},
              {id:'f2',oppId:'o2',number:'11',date:'2026-09-01',net:5000000,iva:950000,total:5950000,status:'Pagada'},
              {id:'f3',oppId:'o1',number:'',date:'2026-12-15',net:6000000,iva:1140000,total:7140000,status:'Por emitir'}];
  S.recepciones=[{id:'r1',oppId:'o2',code:'AR-CM-261101-02',status:'Recibida',receiver:'Luis',receivedAt:'2026-09-01',docs:[]}];
  S.leads.push({id:'l1',code:'PR-2026-0001',name:'Bodega DDAT',companyId:'cB',source:'Referido',stage:'Oportunidad creada',oppId:'o2',ownerId:'u1',createdAt:'2026-06-01',cmAt:'2026-07-01',vars:{}},
               {id:'l2',code:'PR-2026-0002',name:'SES Arica',companyId:'cB',source:'LinkedIn',stage:'Oportunidad creada',oppId:'o4',ownerId:'u1',createdAt:'2026-09-01',cmAt:'2026-09-11',vars:{}},
               {id:'l3',code:'PR-2026-0003',name:'Monitoreo Quilicura',companyId:'cA',source:'LinkedIn',stage:'Oportunidad creada',oppId:'',ownerId:'u1',createdAt:'2026-10-01',cmAt:'2026-10-21',vars:{}},
               {id:'l4',code:'PR-2026-0004',name:'Sin convertir',companyId:'cA',source:'Web',stage:'Contactado',ownerId:'u1',createdAt:'2026-10-05',vars:{}});
  migrate();location.hash='reportes';UI.repTab='opps';render();`);

console.log('1. Pestañas');
const tabs = [...doc.querySelectorAll('[data-action="repTab"]')].map(b => b.textContent);
ok(tabs.includes('Facturación de Booking') && tabs.includes('Prospectos convertidos'), 'Reportes tiene las dos pestañas nuevas: ' + tabs.join(', '));

console.log('2. Facturación de Booking');
E(`ACTIONS.repTab({v:'fact'})`);
let t = main();
ok(E('factYear()') === E('todayIso').slice(0, 4), 'por defecto muestra la facturación del año en curso');
E(`UI.repFactYear='';render()`);t = main();
ok(/Oportunidades 3/.test(t), 'con todo el historial cuenta las 3 cerradas');
ok(/Facturado total \(neto\) \$9 MM/.test(t), 'facturado neto: $9 MM (emitida + pagada, sin la «por emitir»)');
ok(/Pendiente de facturar \$8 MM/.test(t) && /2 oportunidades, 1 sin ninguna factura/.test(t), 'pendiente $8 MM en 2 oportunidades, 1 sin facturas');
ok(/Por cobrar \$4,8 MM/.test(t) && /Por emitir \$6 MM/.test(t), 'por cobrar (con IVA) y por emitir');
ok(/Actas sin recibir 2/.test(t), 'actas sin recibir: 2');
const filas = [...doc.querySelectorAll('#main tbody tr[data-id]')].map(r => r.dataset.id);
ok(filas[0] === 'o1' && filas.length === 3, 'ordena por lo pendiente, de mayor a menor');
ok(doc.querySelector('#main tr[data-id="o2"]').classList.contains('inv-ok') && doc.querySelector('#main tr[data-id="o1"]').classList.contains('inv-pend'), 'las filas llevan el color de facturación');
E(`UI.repFactYear='2026';render()`);
t = main();
ok(/Facturado en 2026 \(meta\) \$9 MM/.test(t), 'el período es el año de facturación: $9 MM facturados en 2026');
ok([...doc.querySelectorAll('#main tbody tr[data-id]')].map(r => r.dataset.id).sort().join() === 'o1,o2', 'no lista el Booking de 2025 pendiente que no tiene factura en 2026');
ok(/1 Booking de 2025 con saldo y sin factura en 2026 no se listan aquí/.test(t) && doc.querySelector('#main a[data-k="repFactYear"][data-v="2025"]'), 'avisa que existe, con enlace al informe de 2025');
E(`S.invoices.push({id:'f9',oppId:'o3',number:'12',date:'2026-02-10',net:2000000,iva:380000,total:2380000,status:'Pagada'});render()`);
t = main();
ok(/Facturado en 2026 \(meta\) \$11 MM/.test(t) && /\$2 MM de Booking de años anteriores/.test(t), 'un Booking de 2025 facturado en 2026 suma a la meta de 2026');
ok(doc.querySelector('#main tr[data-id="o3"]') && /Año anterior/.test(t), 'y como tiene factura en 2026 sí aparece, marcado «Año anterior»');
E(`UI.repFactYear='2025';render()`);
t = main();
ok(/Facturado en 2025 \(meta\) \$0/.test(t), 'y no suma a 2025, aunque se cerró ese año');
E(`S.invoices=S.invoices.filter(x=>x.id!=='f9');render()`);
ok(doc.querySelector('#main tr[data-id="o3"]') && !doc.querySelector('#main tr[data-id="o1"]'), 'el Booking de 2025 pendiente se ve en el informe de 2025');
E(`UI.repFactYear='2026';render()`);
E(`UI.repFactState='pend';render()`);
ok([...doc.querySelectorAll('#main tbody tr[data-id]')].map(r => r.dataset.id).sort().join() === 'o1', 'filtra las pendientes de facturar (solo la de 2026)');
E(`UI.repFactState='ok';render()`);
ok([...doc.querySelectorAll('#main tbody tr[data-id]')].map(r => r.dataset.id).join() === 'o2', 'filtra solo las facturadas al 100 %');
E(`UI.repFactYear='';UI.repFactState='';render()`);
const ex = E(`(()=>{let cap=null;const o=window.URL.createObjectURL;window.URL.createObjectURL=()=>'blob:x';try{doExport('facturacion')}catch(e){}window.URL.createObjectURL=o;return LAST_EXPORT?JSON.stringify(LAST_EXPORT).slice(0,2000):''})()`);
ok(/Pendiente CLP/.test(ex) && /CM-261101-01/.test(ex), 'se exporta con las columnas de facturación');

console.log('2b. Meta por factura en Hoy y en Configuración');
E(`S.config.targets={u1:5000000};S.config.metaBase='factura';S.invoices.push({id:'fm',oppId:'o3',number:'13',date:todayIso,net:2500000,iva:0,total:2500000,status:'Emitida'});location.hash='hoy';render()`);
t = main();
ok(/Facturado en el mes \$6,5 MM 130% de la meta de \$5 MM/.test(t), 'Hoy mide la meta del mes por lo facturado, incluida la factura de un Booking de 2025: 130 %');
ok(/de Booking de años anteriores/.test(t) && /Booking del mes/.test(t), 'separa lo facturado de Booking anteriores y muestra el Booking del mes aparte');
E(`S.config.metaBase='booking';render()`);
ok(/Vendido en el mes/.test(main()), 'con base Booking vuelve a medir lo vendido');
E(`location.hash='config';render()`);
ok(doc.querySelector('select[name=metaBase]'), 'Configuración permite elegir la base de la meta');
E(`S.config.metaBase='factura';S.invoices=S.invoices.filter(x=>x.id!=='fm');location.hash='reportes';render()`);

console.log('3. Prospectos convertidos');
E(`ACTIONS.repTab({v:'conv'})`);
t = main();
ok(/Prospectos convertidos 3/.test(t) && /75% de 4 prospectos creados/.test(t), '3 convertidos de 4 creados (75 %)');
ok(/Ya en el Excel 2/.test(t) && /1 esperando su código CM/.test(t), '2 ya en el Excel y 1 esperando');
ok(/Monto generado \$13 MM/.test(t), 'monto generado: $13 MM');
ok(/Ganadas \(Booking\) 1/.test(t), '1 ganada');
ok(/Tiempo de conversión 20 días/.test(t), 'tiempo promedio de conversión: 20 días');
ok(/LinkedIn/.test(t) && /Referido/.test(t), 'resume por fuente del prospecto');
ok(/Esperando el Excel/.test(t) && /Upside/.test(t) && /Booking/.test(t), 'muestra el estado de cada oportunidad');
E(`UI.repConvYear='2025';render()`);
ok(/Ningún prospecto/.test(main()), 'filtra por año de conversión');
E(`UI.repConvYear='';render()`);
const ex2 = E(`(()=>{try{doExport('conversion')}catch(e){}return LAST_EXPORT?JSON.stringify(LAST_EXPORT).slice(0,3000):''})()`);
ok(/PR-2026-0003/.test(ex2) && /Esperando el Excel/.test(ex2), 'se exporta con los prospectos convertidos');

ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
console.log(f ? `\n${f} pruebas fallaron` : '\nTodo bien'); process.exit(f ? 1 : 0);
