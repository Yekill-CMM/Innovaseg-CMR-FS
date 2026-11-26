<?php
// Pruebas del almacenamiento SQLite con un servidor PHP real (php -S + router.php), como en joansvr.
// Uso: php almacen.test.php ../app [datos.json]
//   datos.json (opcional): documento del CRM para migrar (por ejemplo, exportado desde una prueba con el Excel real).
$APP = realpath($argv[1]);
$fuente = isset($argv[2]) && is_file($argv[2]) ? json_decode(file_get_contents($argv[2])) : null;
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$CAB = "<?php exit; ?>\n";

// Copia del CRM en una carpeta temporal
$T = sys_get_temp_dir() . '/crm-almacen-' . getmypid();
@mkdir("$T/app/datos", 0777, true); @mkdir("$T/local", 0777, true);
foreach (['index.html', 'api.php', 'almacen.php', 'cuentas.php', 'permisos.php', 'guardado.php'] as $f) copy("$APP/$f", "$T/app/$f");
require "$APP/cuentas.php"; require __DIR__ . '/sesion-prueba.php';
copy(dirname($APP) . '/local/router.php', "$T/local/router.php");
$CLAVE_AG = str_repeat('a', 24);
$config = function ($extra = '') use ($T, $CLAVE_AG) { file_put_contents("$T/app/config.php", "<?php define('CRM_CLAVE_AGENTE', '$CLAVE_AG'); define('CRM_AGENTE_USUARIO', 'admin.prueba'); $extra"); };
$CTA = pruebaCuentas("$T/app/datos", [['u1', 'admin.prueba', 'Admin Prueba', 'admin', '', true]]);
$config();

// Datos iniciales en el JSON de siempre
if (!$fuente) {
    $mk = function ($id, $code, $stage) { return (object) ['id' => $id, 'code' => $code, 'name' => "Oportunidad $code", 'companyId' => 'c1', 'status' => 'abierta', 'stage' => $stage, 'prob' => 50, 'ownerId' => 'u1', 'oneTime' => 0, 'monthly' => 1000000, 'term' => 12, 'currency' => 'CLP', 'contactIds' => [], 'services' => [], 'history' => [], 'createdAt' => '2026-10-01', 'fx' => (object) ['id' => '', 'title' => "$code-ACME (x)", 'proj' => 12000000]]; };
    $fuente = (object) ['v' => 5, 'mode' => 'real', 'currentUser' => 'u1', 'users' => [(object) ['id' => 'u1', 'name' => 'Claudio Méndez', 'role' => 'admin']],
        'companies' => [(object) ['id' => 'c1', 'legalName' => 'ACME', 'tradeName' => 'ACME', 'sites' => []]], 'contacts' => [], 'leads' => [(object) ['id' => 'l1', 'code' => 'PR-2026-0001', 'companyId' => 'c1', 'stage' => 'Identificado', 'createdAt' => '2026-10-01']],
        'opps' => [$mk('o1', 'CM-261001-01', 'oferta'), $mk('o2', 'CM-261001-02', 'seguimiento')], 'activities' => [], 'tasks' => [(object) ['id' => 't1', 'title' => 'Llamar', 'entityType' => 'opp', 'entityId' => 'o1', 'status' => 'Pendiente', 'due' => '2026-12-01']],
        'offers' => [], 'plans' => [], 'audit' => [], 'config' => (object) ['uf' => 39800, 'followup' => (object) ['remind' => 3]], 'seq' => (object) ['opp' => 0, 'lead' => 1], 'savedAt' => 1731400000000];
}
$rev0 = 41;
file_put_contents("$T/app/datos/crm.json.php", $CAB . json_encode((object) ['rev' => $rev0, 'savedAt' => $fuente->savedAt ?? 0, 'data' => $fuente], JSON_UNESCAPED_UNICODE));
// Los usuarios y la persona de la sesión no se guardan en los datos: el servidor los agrega desde las cuentas
$canon = function ($d) { $a = json_decode(json_encode($d, JSON_UNESCAPED_UNICODE), true); if (is_array($a)) { unset($a['users'], $a['currentUser']); ksort($a); } return json_encode($a, JSON_UNESCAPED_UNICODE); };

// Servidor
$port = pruebaServidor($T);
$http = pruebaHttp($port);
$SES = pruebaEntrar($http, 'admin.prueba');
// Con $clave, la petición va con la clave del agente y sin sesión
$req = function ($m, $q, $body = null, $clave = null) use ($http, &$SES) {
    $r = $clave ? $http($m, $q, $body, '', ['X-CRM-Clave: ' . $clave]) : $http($m, $q, $body, $SES);
    return [$r[0], $r[1]];
};

echo "1. Migración desde el JSON (primera vez)\n";
[$c, $d] = $req('GET', '?accion=leer');
$ok($c === 200 && isset($d->almacen->tipo) && $d->almacen->tipo === 'sqlite', 'el CRM pasa a usar la base de datos SQLite');
$ok($canon($d->data) === $canon($fuente) && (int) $d->rev === $rev0, 'devuelve exactamente los mismos datos y la misma versión que el JSON (' . count($fuente->opps) . ' oportunidades)');
$ok(is_file("$T/app/datos/crm.db") && is_file("$T/app/datos/crm.json.antes-de-sqlite.php"), 'se crea crm.db y queda una copia del JSON original');
$ok(strpos((string) @file_get_contents("$T/app/datos/almacen.log"), 'Migración verificada') !== false, 'el registro dice «Migración verificada»');

echo "2. Guardar desde la pantalla\n";
$data = $d->data; $data->opps[0]->prob = 80; $data->opps[0]->nextAction = 'Visita técnica';
$data->tasks[] = (object) ['id' => 'tNueva', 'title' => 'Enviar minuta', 'entityType' => 'opp', 'entityId' => $data->opps[0]->id, 'status' => 'Pendiente', 'due' => '2026-12-05'];
[$c, $r] = $req('POST', '', ['baseRev' => $rev0, 'data' => $data]);
$ok($c === 200 && (int) $r->rev === $rev0 + 1, 'guarda y sube la versión');
[$c, $d2] = $req('GET', '?accion=leer');
$ok($canon($d2->data) === $canon($data), 'lo leído es exactamente lo guardado');
$espejo = json_decode(substr(file_get_contents("$T/app/datos/crm.json.php"), strlen($CAB)));
$ok($canon($espejo->data) === $canon($data) && (int) $espejo->rev === $rev0 + 1, 'la copia JSON de seguridad queda al día');
[$c, $h] = $req('GET', '?accion=historial&tabla=opps&id=' . urlencode($data->opps[0]->id));
$ultimo = $h->historial[0] ?? null;
$ok($ultimo && $ultimo->accion === 'cambio' && $ultimo->origen === 'pantalla' && $ultimo->antes->prob != 80 && $ultimo->despues->prob == 80, 'el historial guarda quién, cuándo, el valor anterior y el nuevo');
[$c, $h] = $req('GET', '?accion=historial&tabla=tasks&id=tNueva');
$ok(($h->historial[0]->accion ?? '') === 'alta', 'una tarea nueva queda como «alta» en el historial');

echo "3. Nada se borra físicamente\n";
$data = $d2->data; $data->tasks = array_values(array_filter($data->tasks, function ($t) { return $t->id !== 'tNueva'; }));
[$c, $r] = $req('POST', '', ['baseRev' => $rev0 + 1, 'data' => $data]);
[$c, $d3] = $req('GET', '?accion=leer');
$ok(!array_filter($d3->data->tasks, function ($t) { return $t->id === 'tNueva'; }), 'la tarea quitada en la pantalla ya no aparece');
$db = new PDO('sqlite:' . "$T/app/datos/crm.db");
$fila = $db->query("SELECT eliminado_en, datos FROM registros WHERE tabla='tasks' AND id='tNueva'")->fetch(PDO::FETCH_ASSOC);
$ok($fila && $fila['eliminado_en'] && strpos($fila['datos'], 'Enviar minuta') !== false, 'pero sigue en la base, marcada como eliminada, con todos sus datos');
[$c, $r] = $req('POST', '?accion=restaurar', ['tabla' => 'tasks', 'id' => 'tNueva']);
[$c, $d4] = $req('GET', '?accion=leer');
$ok($c === 200 && array_filter($d4->data->tasks, function ($t) { return $t->id === 'tNueva'; }), 'se puede recuperar');
[$c, $h] = $req('GET', '?accion=historial&tabla=tasks&id=tNueva');
$ok(implode(',', array_map(function ($x) { return $x->accion; }, $h->historial)) === 'restaurado,baja,alta', 'y el historial muestra alta, baja y recuperación');

echo "4. Dos dispositivos a la vez\n";
$revA = (int) $d4->rev;
[$c1] = $req('POST', '', ['baseRev' => $revA, 'data' => $d4->data]);
[$c2, $r2] = $req('POST', '', ['baseRev' => $revA, 'data' => $d4->data]);
$ok($c1 === 200 && $c2 === 409 && isset($r2->data), 'el segundo que guarda con una versión vieja recibe los datos actuales y no pisa nada');

echo "5. Joan escribe por su API\n";
[$c, $d5] = $req('GET', '?accion=leer');
$code = $d5->data->opps[0]->code;
[$c, $r] = $req('POST', '?accion=agente-escribir', ['ops' => [['tipo' => 'tarea', 'titulo' => 'Tarea de Joan', 'vence' => '2026-12-10', 'oportunidad' => $code]]], $CLAVE_AG);
$ok($c === 200 && $r->ok, 'Joan crea una tarea');
$n = $db->query("SELECT COUNT(*) FROM cambios WHERE origen='Joan' AND tabla='tasks' AND accion='alta'")->fetchColumn();
$ok((int) $n === 1, 'el historial la registra con origen «Joan»');
[$c, $ra] = $req('GET', '?accion=agente', null, $CLAVE_AG);
$ok($c === 200 && isset($ra->oportunidades_abiertas), 'el resumen de Joan sigue funcionando');
[$c] = $req('GET', '?accion=historial&tabla=opps&id=x', null, $CLAVE_AG);
$ok($c === 403, 'el historial no está disponible con la clave de Joan');
$ok((int) $db->query("SELECT COUNT(*) FROM cambios WHERE origen='Joan' AND usuario='u1'")->fetchColumn() >= 1, 'y Joan actúa con la cuenta indicada en CRM_AGENTE_USUARIO');
$ok((int) $db->query("SELECT COUNT(*) FROM cambios WHERE origen='pantalla' AND usuario='u1'")->fetchColumn() >= 1, '«cambios» guarda la persona que hizo cada cambio');

echo "6. Respaldos y estado\n";
$ok(count(glob("$T/app/datos/historial/crm-*.db")) >= 1 && count(glob("$T/app/datos/historial/diario-*.db")) === 1, 'hay respaldo por hora y diario de la base');
$resp = glob("$T/app/datos/historial/crm-*.db")[0];
$ok((int) (new PDO("sqlite:$resp"))->query('SELECT COUNT(*) FROM registros')->fetchColumn() > 0, 'el respaldo se puede abrir y tiene los registros');
[$c, $st] = $req('GET', '?accion=rev');
$ok($st->almacen->tipo === 'sqlite' && $st->almacen->cambios > 0 && $st->almacen->ultimo_respaldo, 'la pantalla recibe el estado: base, cambios registrados y último respaldo');
$ok(@file_get_contents("http://127.0.0.1:$port/datos/crm.db") === false && strpos((string) @file_get_contents("http://127.0.0.1:$port/almacen.log"), 'Migra') === false, 'la base y su registro no se pueden descargar desde el navegador');

echo "7. Volver al JSON (si la base fallara o se desactiva)\n";
[$c, $antes] = $req('GET', '?accion=leer');
$config("define('CRM_ALMACEN', 'json');");
[$c, $dj] = $req('GET', '?accion=leer');
$ok($dj->almacen->tipo === 'json' && $canon($dj->data) === $canon($antes->data) && (int) $dj->rev === (int) $antes->rev, 'con el JSON, el CRM tiene exactamente los mismos datos al día');
$config();
[$c, $ds] = $req('GET', '?accion=leer');
$ok($ds->almacen->tipo === 'sqlite', 'y vuelve a la base al reactivarla');

echo "8. Rendimiento con los datos\n";
$t0 = microtime(true); [$c] = $req('POST', '', ['baseRev' => (int) $ds->rev, 'data' => $ds->data]); $ms = round((microtime(true) - $t0) * 1000);
$ok($c === 200 && $ms < 3000, "guardar todo el CRM toma $ms ms");
$t0 = microtime(true); $req('GET', '?accion=leer'); $ms = round((microtime(true) - $t0) * 1000);
$ok($ms < 2000, "leer todo el CRM toma $ms ms");

exit($fallas ? 1 : 0);
