<?php
// Demo con los cuatro roles (herramientas/demo.php y demo-datos.php): datos coherentes y sin nada real, y con un
// servidor PHP real cargado con la demo, lo que recibe y lo que puede cambiar cada una de las 12 cuentas.
// Uso: php demo.test.php ../app
$APP = realpath($argv[1]); $RAIZ = dirname($APP);
require "$APP/cuentas.php"; require "$APP/permisos.php";
require "$RAIZ/herramientas/demo-datos.php"; require __DIR__ . '/sesion-prueba.php';
date_default_timezone_set('America/Santiago');
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$ids = function ($l) { $a = array_map(function ($x) { return (string) $x->id; }, (array) $l); sort($a); return $a; };
$por = function ($l, $id) { foreach ((array) $l as $x) if ((string) $x->id === $id) return $x; return null; };
$HOY = '2026-11-26';
$d = demoDatos($HOY);
$EQ = []; foreach (DEMO_EQUIPO as $u) $EQ[$u[0]] = $u;

echo "1. Datos de la demo\n";
$roles = array_count_values(array_column(DEMO_EQUIPO, 3));
$ok(count(DEMO_EQUIPO) === 12 && $roles === ['gerente' => 1, 'subgerente' => 2, 'ejecutivo' => 8, 'admin' => 1], '12 cuentas: gerente, 2 subgerentes, 8 ejecutivos y administrador');
$equipos = []; foreach (DEMO_EQUIPO as $u) if ($u[4] !== '') $equipos[$u[4]][] = $u[3];
$ok(count($equipos) === 2 && array_count_values($equipos['Norte']) === ['subgerente' => 1, 'ejecutivo' => 4] && array_count_values($equipos['Sur']) === ['subgerente' => 1, 'ejecutivo' => 4], 'cada subgerencia tiene su subgerente y 4 ejecutivos');
$conCartera = array_keys(array_filter($EQ, function ($u) { return in_array($u[3], ['subgerente', 'ejecutivo'], true); }));
$duenos = array_unique(array_map(function ($o) { return $o->ownerId; }, $d->opps)); sort($duenos); $cc = $conCartera; sort($cc);
$ok($duenos === $cc, 'los 2 subgerentes y los 8 ejecutivos tienen oportunidades propias; gerente y administrador no');
$cods = array_map(function ($o) { return $o->code; }, $d->opps);
$ok(count($cods) === count(array_unique($cods)) && !array_filter($cods, function ($c) { return !preg_match('/^OP-\d{6}-\d{2}$/', $c); }), 'cada oportunidad tiene su código OP-AAMMDD-NN, sin repetir');
$ok(!array_filter($d->opps, function ($o) use ($HOY) { return substr($o->code, 3, 6) !== date('ymd', strtotime($o->createdAt)); }), 'el código sale de la fecha de creación');
$tipos = ['Seg. Física', 'Seg. Electrónica', 'Seg. Integral', 'One Shot'];
$ok(!array_filter($d->opps, function ($o) use ($tipos) { return !in_array($o->tipo, $tipos, true) || empty($o->crm) || isset($o->fx); }), 'toda oportunidad tiene tipo de negocio y es del CRM (marca crm, sin vínculo al Excel)');
$emp = []; foreach ($d->companies as $c) $emp[$c->id] = array_map(function ($s) { return $s->id; }, $c->sites);
$ctos = []; foreach ($d->contacts as $k) $ctos[$k->id] = $k->companyId;
$rotas = [];
foreach ($d->opps as $o) {
    if (!isset($emp[$o->companyId])) $rotas[] = "$o->id empresa";
    elseif ($o->siteId !== '' && !in_array($o->siteId, $emp[$o->companyId], true)) $rotas[] = "$o->id sitio";
    foreach ($o->contactIds as $k) if (($ctos[$k] ?? '') !== $o->companyId) $rotas[] = "$o->id contacto $k";
}
foreach ($d->leads as $l) { if (!isset($emp[$l->companyId])) $rotas[] = "$l->id empresa"; if ($l->contactId !== '' && ($ctos[$l->contactId] ?? '') !== $l->companyId) $rotas[] = "$l->id contacto"; }
$oppIds = $ids($d->opps); $offIds = $ids($d->offers); $leadIds = $ids($d->leads);
foreach (['offers', 'invoices'] as $t) foreach ($d->$t as $x) if (!in_array($x->oppId, $oppIds, true)) $rotas[] = "$x->id oportunidad";
foreach (['activities', 'tasks'] as $t) foreach ($d->$t as $x) { $l = ['opp' => $oppIds, 'offer' => $offIds, 'lead' => $leadIds][$x->entityType]; if (!in_array($x->entityId, $l, true)) $rotas[] = "$x->id vínculo"; }
foreach (array_merge($d->companies, $d->contacts) as $x) if (!isset($EQ[$x->createdBy])) $rotas[] = "$x->id createdBy";
$ok(!$rotas, 'empresas, sitios, contactos, ofertas, facturas, actividades y tareas apuntan a registros que existen' . ($rotas ? ': ' . implode(', ', $rotas) : ''));
$ganadas = array_filter($d->opps, function ($o) { return $o->status === 'ganada'; });
$ok(count($ganadas) >= 3 && count(array_filter($d->opps, function ($o) { return $o->status === 'perdida'; })) >= 2 && count($d->invoices) >= 4, 'hay oportunidades ganadas con facturas y perdidas con motivo');
$txt = json_encode([$d, DEMO_EQUIPO], JSON_UNESCAPED_UNICODE);
$ok(!preg_match('/Claudio|M[ée]ndez|Codelco|Escondida|Prosegur|Securitas|G4S|@(?!\w+\.example")[\w.-]+"/u', $txt), 'sin nombres reales: ni el usuario del CRM personal ni empresas o competidores reales; correos con dominio .example');
$ok(json_encode(demoDatos('2027-03-01')->opps[0]->closeDate) !== json_encode($d->opps[0]->closeDate), 'las fechas se calculan desde el día de carga');
$ok(array_keys(get_object_vars($d->config->targets)) === array_keys(DEMO_METAS) && !isset($d->config->targets->g1), 'meta de venta para cada persona con cartera');

echo "2. Carga con herramientas/demo.php\n";
$T = sys_get_temp_dir() . '/crm-demo-' . getmypid();
@mkdir("$T/app", 0777, true); @mkdir("$T/local", 0777, true);
foreach (['index.html', 'api.php', 'almacen.php', 'cuentas.php', 'permisos.php', 'guardado.php'] as $f) copy("$APP/$f", "$T/app/$f");
copy("$RAIZ/local/router.php", "$T/local/router.php");
file_put_contents("$T/app/config.php", "<?php define('CRM_CLAVE_AGENTE', '');");
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$RAIZ/herramientas/demo.php") . ' ' . escapeshellarg("--datos=$T/app/datos") . " --hoy=$HOY 2>&1";
exec($cmd, $sal, $cod);
$ok($cod === 0 && strpos(implode("\n", $sal), '21 oportunidades') !== false, 'carga la demo en una carpeta vacía');
$claves = preg_grep('/^\s+\S+\s.+\s[A-Za-z0-9]{4}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}$/', $sal);
$ok(count($claves) === 12, 'muestra la clave temporal de las 12 cuentas');
$sal2 = []; exec($cmd, $sal2, $cod2);
$ok($cod2 === 1 && strpos(implode("\n", $sal2), 'ya tiene datos') !== false, 'no pisa una carpeta que ya tiene datos');
$T2 = "$T-cuentas"; @mkdir($T2, 0777, true); ctaCrear(ctaAbrir($T2), (object) ['usuario' => 'alguien', 'nombre' => 'Alguien', 'rol' => 'admin', 'equipo' => '', 'admin' => true]);
$sal3 = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$RAIZ/herramientas/demo.php") . ' ' . escapeshellarg("--datos=$T2") . ' 2>&1', $sal3, $cod3);
$ok($cod3 === 1 && !is_file("$T2/crm.json.php"), 'ni una que ya tiene cuentas'); exec('rm -rf ' . escapeshellarg($T2));
$CTA = ctaAbrir("$T/app/datos");
$filas = $CTA->query('SELECT id, rol, equipo, admin, clave_temporal, totp_activo FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$ok(count($filas) === 12 && !array_filter($filas, function ($r) use ($EQ) { return $EQ[$r['id']][3] !== $r['rol'] || $EQ[$r['id']][4] !== $r['equipo'] || !(int) $r['clave_temporal'] || (int) $r['totp_activo']; }),
    'cuentas con su rol y equipo, clave temporal y segundo factor por activar');

echo "3. Lo que recibe cada cuenta del servidor\n";
// Para entrar en la prueba: clave y segundo factor fijos en todas, salvo Isidora (primer ingreso)
foreach ($filas as $r) if (!in_array($EQ[$r['id']][1], DEMO_PRIMER_INGRESO, true))
    $CTA->prepare('UPDATE usuarios SET clave_hash = ?, clave_temporal = 0, totp_secreto = ?, totp_activo = 1 WHERE id = ?')->execute([password_hash(CLAVE_PRUEBA, PASSWORD_DEFAULT), SECRETO_PRUEBA, $r['id']]);
$port = pruebaServidor($T);
register_shutdown_function(function () use ($T) { exec('rm -rf ' . escapeshellarg($T)); });
$req = pruebaHttp($port);
[$c, $r] = $req('POST', '?accion=entrar', ['usuario' => 'ivega', 'clave' => 'otra-cualquiera-1']);
$ok($c === 401, 'Isidora no entra sin su clave temporal');
$tmpIsi = null; foreach ($sal as $l) if (preg_match('/^\s+ivega\s.*\s(\S+)$/', $l, $m)) $tmpIsi = $m[1];
[$c, $r] = $req('POST', '?accion=entrar', ['usuario' => 'ivega', 'clave' => $tmpIsi]);
$ok($c === 200 && ($r->paso ?? '') === 'nueva-clave', 'con la clave temporal se le pide elegir una propia (primer ingreso)');
$equipoDe = function ($id) use ($EQ) { return $EQ[$id][4]; };
$alcance = function ($id) use ($EQ) {
    $u = $EQ[$id];
    if (in_array($u[3], ['gerente', 'admin'], true)) return array_keys($EQ);
    if ($u[3] === 'subgerente') return array_keys(array_filter($EQ, function ($x) use ($u) { return $x[4] === $u[4]; }));
    return [$id];
};
$duenoOpp = []; foreach ($d->opps as $o) $duenoOpp[$o->id] = $o->ownerId;
$duenoEnt = function ($t, $id) use ($d, $duenoOpp) { if ($t === 'opp') return $duenoOpp[$id]; foreach ($d->{$t === 'lead' ? 'leads' : 'offers'} as $x) if ($x->id === $id) return $x->ownerId; };
$S = []; $V = []; $malos = [];
foreach ($EQ as $id => $u) {
    if (in_array($u[1], DEMO_PRIMER_INGRESO, true)) continue;
    $S[$id] = pruebaEntrar($req, $u[1], $CTA);
    [$c, $v] = $req('GET', '?accion=leer', null, $S[$id]); $V[$id] = $v;
    if ($c !== 200 || !$v->data) { $malos[] = "$id no lee ($c)"; continue; }
    $alc = $alcance($id); $x = $v->data;
    $esp = function ($lista, $campo) use ($alc) { $a = []; foreach ($lista as $r) if (in_array($r->$campo, $alc, true)) $a[] = $r->id; sort($a); return $a; };
    if ($ids($x->opps) !== $esp($d->opps, 'ownerId')) $malos[] = "$id: oportunidades " . implode(',', $ids($x->opps));
    if ($ids($x->leads) !== $esp($d->leads, 'ownerId')) $malos[] = "$id: prospectos";
    if ($ids($x->offers) !== $esp($d->offers, 'ownerId')) $malos[] = "$id: ofertas";
    if ($ids($x->tasks) !== $esp($d->tasks, 'assigneeId')) $malos[] = "$id: tareas";
    $inv = []; foreach ($d->invoices as $i) if (in_array($duenoOpp[$i->oppId], $alc, true)) $inv[] = $i->id; sort($inv);
    if ($ids($x->invoices) !== $inv) $malos[] = "$id: facturas";
    $act = []; foreach ($d->activities as $a) if (in_array($a->userId, $alc, true) || in_array($duenoEnt($a->entityType, $a->entityId), $alc, true)) $act[] = $a->id; sort($act);
    if ($ids($x->activities) !== $act) $malos[] = "$id: actividades " . implode(',', $ids($x->activities));
    if ($ids($x->companies) !== $ids($d->companies) || $ids($x->contacts) !== $ids($d->contacts)) $malos[] = "$id no ve todas las empresas y contactos";
    $tg = array_keys(get_object_vars($x->config->targets)); sort($tg); $te = array_values(array_intersect($alc, array_keys(DEMO_METAS))); sort($te);
    if ($tg !== $te) $malos[] = "$id ve las metas de " . implode(',', $tg);
    if (($v->yo->id ?? '') !== $id) $malos[] = "$id: la sesión dice " . ($v->yo->id ?? '?');
}
$ok(count($S) === 11 && !$malos, 'las 11 cuentas que entran reciben exactamente su alcance (oportunidades, prospectos, ofertas, tareas, facturas, actividades y metas), y todas las empresas y contactos' . ($malos ? ': ' . implode(' | ', array_slice($malos, 0, 6)) : ''));
$n = function ($id, $t) use (&$V) { return count($V[$id]->data->$t); };
$ok($n('g1', 'opps') === 21 && $n('ad', 'opps') === 21 && $n('sN', 'opps') === 12 && $n('sS', 'opps') === 9 && $n('eN1', 'opps') === 3, 'oportunidades: gerente y administrador 21, subgerencia Norte 12, Sur 9, Paula 3');
$quill = function ($id) use (&$V) { return array_values(array_map(function ($o) { return $o->id; }, array_filter($V[$id]->data->opps, function ($o) { return $o->companyId === 'dc02'; }))); };
$ok($quill('eN1') === ['do05'] && $quill('eN2') === ['do07'] && count($quill('sN')) === 4, 'en Minera Altos de Quillagua (la creó el subgerente) Paula ve solo su oportunidad, Tomás solo la suya y el subgerente las cuatro del equipo');
$sur = function ($id) use (&$V) { $a = array_values(array_map(function ($o) { return $o->id; }, array_filter($V[$id]->data->opps, function ($o) { return $o->companyId === 'dc14'; }))); sort($a); return $a; };
$ok($sur('sN') === ['do08'] && $sur('sS') === ['do14', 'do16'] && $sur('g1') === ['do08', 'do14', 'do16'], 'en Subestaciones del Sur cada subgerente ve solo lo de su equipo; la gerente ve todo');
$json = json_encode($V['eN1'], JSON_UNESCAPED_UNICODE);
$ok(strpos($json, 'Rondas móviles') === false && strpos($json, 'OF-2026-101') === false && strpos($json, 'Renovación seguridad CD Lo Aguirre') === false, 'a Paula no le llega nada de otra persona (ni nombres de oportunidades ni ofertas)');
$jsonS = json_encode($V['sN'], JSON_UNESCAPED_UNICODE);
$ok(strpos($jsonS, 'CCTV térmico') === false && strpos($jsonS, 'Protección portuaria') === false, 'al subgerente Norte no le llegan las oportunidades del Sur');
[$c, $r] = $req('GET', '?accion=usuarios', null, $S['eN1']); [$c2, $r2] = $req('GET', '?accion=usuarios', null, $S['ad']);
$ok($c === 403 && $c2 === 200 && count($r2->usuarios) === 12, 'solo el administrador ve y administra las cuentas');

echo "4. Lo que puede cambiar cada rol en la demo\n";
$DIR = ctaDirectorio($CTA);
$ctx = function ($id) use ($DIR) { foreach ($DIR as $u) if ($u->id === $id) return permCtx($u, $DIR); };
$full = json_decode(json_encode($d));
$guardar = function ($quien, $f) use ($ctx, $full) { $c = $ctx($quien); $v = permVista($full, $c); $f($v); $n = permAplicar($full, $v, $c); return [$n, $c->avisos]; };
[$x, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'dc02')->tradeName = 'Cambiada'; });
$ok($por($x->companies, 'dc02')->tradeName === 'Altos de Quillagua' && count($av) === 1, 'Paula no edita Minera Altos de Quillagua (la creó otra persona)');
[$x, $av] = $guardar('eN1', function ($v) { $v->contacts[] = (object) ['id' => 'dkN', 'companyId' => 'dc02', 'firstName' => 'Nuevo', 'lastName' => 'Contacto']; });
$ok((bool) $por($x->contacts, 'dkN') && $por($x->contacts, 'dkN')->createdBy === 'eN1' && !$av, 'pero sí le agrega un contacto, que queda a su nombre');
[$x, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'dc02')->sites[] = (object) ['id' => 'dc02s9', 'name' => 'Bodega nueva', 'type' => 'Bodega']; });
$ok(count($por($x->companies, 'dc02')->sites) === 5 && !$av, 'y un sitio nuevo');
[$x, $av] = $guardar('eN1', function ($v) { $v->opps[] = (object) ['id' => 'doN', 'name' => 'Nueva en Quillagua', 'companyId' => 'dc02', 'ownerId' => 'eN1', 'tipo' => 'Seg. Física', 'stage' => 'identificada', 'status' => 'abierta']; });
$ok((bool) $por($x->opps, 'doN') && !$av, 'y una oportunidad a su nombre');
[$x, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'dc01')->notes = 'Visita en diciembre'; });
$ok($por($x->companies, 'dc01')->notes === 'Visita en diciembre' && !$av, 'edita la empresa que ella creó (Pampa Clara)');
[$x, $av] = $guardar('eN1', function ($v) { $v->companies = array_values(array_filter($v->companies, function ($c) { return $c->id !== 'dc01'; })); });
$ok((bool) $por($x->companies, 'dc01') && $av, 'pero no la elimina');
[$x, $av] = $guardar('sN', function ($v) use ($por) { $por($v->opps, 'do05')->prob = 70; $por($v->companies, 'dc08')->notes = 'nota'; });
$ok($por($x->opps, 'do05')->prob === 70 && $por($x->companies, 'dc08')->notes === 'nota' && !$av, 'el subgerente Norte cambia la oportunidad de Paula y edita empresas');
[$x, $av] = $guardar('sN', function ($v) { $v->opps[] = (object) ['id' => 'doX', 'name' => 'Para el Sur', 'companyId' => 'dc08', 'ownerId' => 'eS1', 'tipo' => 'Seg. Física', 'stage' => 'identificada', 'status' => 'abierta']; });
$ok(!$por($x->opps, 'doX') && $av, 'pero no asigna oportunidades a alguien del Sur');
[$x, $av] = $guardar('g1', function ($v) use ($por) { $por($v->opps, 'do16')->ownerId = 'eS3'; });
$ok($por($x->opps, 'do16')->ownerId === 'eS3' && !$av, 'la gerente traspasa una oportunidad dentro de cualquier equipo');
[$x, $av] = $guardar('g1', function ($v) { $v->companies = array_values(array_filter($v->companies, function ($c) { return $c->id !== 'dc15'; })); });
$ok((bool) $por($x->companies, 'dc15') && $av, 'la gerente no elimina empresas');
[$x, $av] = $guardar('ad', function ($v) { $v->companies = array_values(array_filter($v->companies, function ($c) { return $c->id !== 'dc15'; })); });
$ok(!$por($x->companies, 'dc15') && !$av, 'el administrador sí');

echo $fallas ? "\n$fallas pruebas fallaron\n" : "\nTodas las pruebas de la demo pasaron\n";
exit($fallas ? 1 : 0);
