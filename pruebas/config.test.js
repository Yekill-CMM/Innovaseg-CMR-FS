// Configuración por defecto del CRM FS: lo que trae app/index.html coincide con
// config/config-crm.default.json, y es lo que recibe una instalación nueva.
// Uso: node config.test.js ../app/index.html ../config/config-crm.default.json
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const vc = new VirtualConsole(); const errores = [];
vc.on('jsdomError', e => { if (!/fetch|api\.php|Network|Could not parse CSS|navigation/i.test(String(e.message))) errores.push(e.message); });
const dom = new JSDOM(fs.readFileSync(process.argv[2], 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole: vc });
const w = dom.window, E = s => w.eval(s);
let f = 0; const ok = (c, m) => { console.log((c ? '  ✓ ' : '  ✗ ') + m); if (!c) f++; };
const raw = fs.readFileSync(process.argv[3], 'utf8');
const cfg = JSON.parse(raw);
const sinMetas = o => { const c = JSON.parse(JSON.stringify(o)); delete c.targets; return c; };
const igual = (a, b) => JSON.stringify(orden(a)) === JSON.stringify(orden(b));
function orden(v) { if (v && typeof v === 'object' && !Array.isArray(v)) return Object.keys(v).sort().reduce((o, k) => (o[k] = orden(v[k]), o), {}); return v; }

console.log('1. El archivo config-crm.default.json');
ok(cfg.uf === 41200 && cfg.usd === 950, 'UF 41.200 y USD 950');
ok(cfg.clientNoAct === 30 && cfg.leadNoAct === 14 && cfg.stalledDays === 30 && cfg.offerExpiry === 15, 'cliente sin gestión 30, prospecto 14, estancada 30, vencimiento de oferta 15');
ok(cfg.followup.remind < cfg.followup.yellow && cfg.followup.yellow < cfg.followup.red, 'seguimiento: recordatorio < amarilla < roja (' + [cfg.followup.remind, cfg.followup.yellow, cfg.followup.red].join('/') + ')');
ok(cfg.oppNoAct.warn < cfg.oppNoAct.risk, 'oportunidad sin actividad: alerta < riesgo');
const tipos = E('TIPOS_NEGOCIO.map(t=>t[0])');
ok(Object.keys(cfg.plazos).every(k => tipos.includes(k)), 'los plazos son de tipos de negocio que existen: ' + Object.keys(cfg.plazos).join(', '));
ok(Object.values(cfg.plazos).every(p => ['warn', 'risk', 'remind', 'yellow', 'red', 'stalled'].every(k => Number.isInteger(p[k]) && p[k] > 0) && p.warn < p.risk && p.remind < p.yellow && p.yellow <= p.red), 'cada plazo por tipo es un entero positivo y va en orden');
ok(['factura', 'booking'].includes(cfg.metaBase) && Object.values(cfg.auto).every(v => v === 0 || v === 1), 'metaBase válida y automatizaciones en 0 o 1');
ok(!/api[_-]?key|password|secret|token|sk-ant/i.test(raw), 'sin claves ni secretos');

console.log('2. El programa usa esos valores');
ok(igual(sinMetas(E('CONFIG_DEFAULT')), cfg), 'CONFIG_DEFAULT de index.html es igual al archivo');
const limpia = E('JSON.stringify(cleanSeed().config)');
ok(igual(sinMetas(JSON.parse(limpia)), cfg) && JSON.stringify(JSON.parse(limpia).targets) === '{}', 'empezar de cero parte con esos valores y sin metas cargadas');
const demo = JSON.parse(E('JSON.stringify(seed().config)'));
ok(igual(sinMetas(demo), cfg), 'la demo usa los mismos parámetros');
E(`S=cleanSeed();AJUSTES=null;applyAjustes()`);
ok(E('S.config.uf') === 41200 && E('S.config.offerExpiry') === 15 && E(`S.config.plazos['One Shot'].stalled`) === 90, 'una instalación nueva queda con UF 41.200, aviso de oferta a 15 días y One Shot estancada a 90');
E(`S=cleanSeed();AJUSTES={uf:42000,plazos:{'One Shot':{warn:35}}};applyAjustes()`);
ok(E('S.config.uf') === 42000 && E(`S.config.plazos['One Shot'].warn`) === 35 && E(`S.config.plazos['One Shot'].risk`) === 60, 'lo que el administrador cambia en Configuración manda sobre el valor por defecto, sin perder el resto');
ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));

console.log(f ? `\n${f} prueba(s) fallaron` : '\nTodas las pruebas pasaron');
process.exit(f ? 1 : 0);
