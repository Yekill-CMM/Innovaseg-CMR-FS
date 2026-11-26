<?php
// La regla de bajas también la aplica el servidor (api.php, validarBajas), con un servidor PHP real como en joansvr.
// Uso: php bajas.test.php ../app <carpeta temporal>   (antes corre bajas.test.js, que deja ahí crm-bajas-*.json)
$APP = realpath($argv[1]); $TMP = $argv[2];
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$snap = function ($n) use ($TMP) { $f = "$TMP/crm-bajas-$n.json"; if (!is_file($f)) { echo "Falta $f: corre antes bajas.test.js\n"; exit(1); } return json_decode(file_get_contents($f)); };
$CAB = "<?php exit; ?>\n";

$T = sys_get_temp_dir() . '/crm-bajas-' . getmypid();
@mkdir("$T/app/datos", 0777, true); @mkdir("$T/local", 0777, true);
foreach (['index.html', 'api.php', 'almacen.php', 'cuentas.php', 'permisos.php', 'guardado.php'] as $f) copy("$APP/$f", "$T/app/$f");
require "$APP/cuentas.php"; require __DIR__ . '/sesion-prueba.php';
copy(dirname($APP) . '/local/router.php', "$T/local/router.php");
$CLAVE_AG = str_repeat('a', 24);
file_put_contents("$T/app/config.php", "<?php define('CRM_CLAVE_AGENTE', '$CLAVE_AG'); define('CRM_AGENTE_USUARIO', 'admin.prueba');");
pruebaCuentas("$T/app/datos", [['u1', 'admin.prueba', 'Admin Prueba', 'admin', '', true]]);
file_put_contents("$T/app/datos/crm.json.php", $CAB . json_encode((object) ['rev' => 10, 'savedAt' => 0, 'data' => $snap('0')], JSON_UNESCAPED_UNICODE));

$port = pruebaServidor($T);
$http = pruebaHttp($port);
$SES = pruebaEntrar($http, 'admin.prueba');
// Con $clave, la petición va con la clave del agente y sin sesión
$req = function ($m, $q, $body = null, $clave = null) use ($http, $SES) {
    $r = $clave ? $http($m, $q, $body, '', ['X-CRM-Clave: ' . $clave]) : $http($m, $q, $body, $SES);
    return [$r[0], $r[1]];
};
$leer = function () use ($req) { [, $d] = $req('GET', '?accion=leer'); return $d; };
$guardar = function ($data, $extra = []) use ($req, $leer) { $rev = (int) $leer()->rev; return $req('POST', '', array_merge(['baseRev' => $rev, 'data' => $data], $extra)); };
$copia = function ($x) { return json_decode(json_encode($x)); };
$db = function () use ($T) { return new PDO("sqlite:$T/app/datos/crm.db"); };
$cambio = function ($tabla, $id, $accion) use ($db) { $st = $db()->prepare('SELECT origen, despues FROM cambios WHERE tabla = ? AND registro = ? AND accion = ? ORDER BY n DESC LIMIT 1'); $st->execute([$tabla, $id, $accion]); return $st->fetch(PDO::FETCH_ASSOC); };
$sin = function ($lista, $id) { return array_values(array_filter($lista, function ($x) use ($id) { return $x->id !== $id; })); };

$d0 = $leer();
$ok($d0 && isset($d0->almacen->tipo) && $d0->almacen->tipo === 'sqlite' && count($d0->data->companies) === 4, 'el servidor parte con los datos de la pantalla en SQLite');

echo "1. Empresa sin historial (guardado desde la pantalla)\n";
[$c] = $guardar($snap('1'));
$ok($c === 200, 'se acepta');
$b = $cambio('companies', 'cVacia', 'baja'); $bk = $cambio('contacts', 'kVacia', 'baja');
$ok($b && $b['origen'] === 'pantalla' && $bk && $bk['origen'] === 'pantalla', 'queda en «cambios» como baja con origen pantalla (empresa y contacto)');
[, $el] = $req('GET', '?accion=eliminados');
$noms = array_map(function ($x) { return $x->nombre; }, $el->eliminados);
$ok(in_array('Empresa Sin Nada', $noms, true) && in_array('Pedro Plano', $noms, true), 'aparecen en «Registros eliminados» (el contacto con su nombre)');

echo "2. Contacto repetido con prospecto, fusionado desde la pantalla\n";
[$c] = $guardar($snap('2'));
$ok($c === 200, 'se acepta');
$fu = $cambio('contacts', 'kRep', 'fusión');
$ok($fu && $fu['origen'] === 'pantalla' && json_decode($fu['despues'])->fusionado_en === 'kBueno', '«cambios» registra la fusión (origen pantalla, fusionado en kBueno)');
$ok((bool) $cambio('contacts', 'kRep', 'baja') && (bool) $cambio('contacts', 'kBueno', 'cambio'), 'y también la baja del repetido y los datos completados en el elegido');

echo "3. Contacto sin historial eliminado y empresa fusionada\n";
[$c1] = $guardar($snap('3')); [$c2] = $guardar($snap('4'));
$ok($c1 === 200 && $c2 === 200, 'se aceptan');
$ok((bool) $cambio('companies', 'cAct', 'fusión'), 'la fusión de empresas queda en «cambios»');
[$c1] = $guardar($snap('4b')); [$c2, $r] = $guardar($snap('5'));
$ok($c1 === 200 && $c2 === 200, '«Eliminar empresas del plan» (empresa cargada, su prospecto sin actividad y su tarea) se acepta' . ($c2 !== 200 ? ': ' . ($r->error ?? '') : ''));

echo "4. Lo que el servidor rechaza\n";
$rev = (int) $leer()->rev;
[$c, $r] = $guardar($snap('malo'));
$ok($c === 422 && !empty($r->rechazo) && strpos($r->error, 'Eólica Sur') !== false && strpos($r->error, 'historial') !== false, 'eliminar una empresa con historial (sin fusión): 422 «' . ($r->error ?? '') . '»');
$ok(isset($r->data) && (int) $r->rev === $rev && (int) $leer()->rev === $rev, 'no se guarda nada y devuelve la versión del servidor para la pantalla');
$base = $leer()->data;
$x = $copia($base); $x->contacts = $sin($x->contacts, 'kBueno');
[$c, $r] = $guardar($x);
$ok($c === 422 && strpos($r->error, 'todavía tiene') !== false, 'eliminar un contacto que sigue con prospecto: «' . ($r->error ?? '') . '»');
$x = $copia($base); $x->contacts = $sin($x->contacts, 'kBueno');
foreach ($x->leads as $l) if ($l->contactId === 'kBueno') { $l->contactId = 'kLejos'; $l->qual->decisorId = 'kLejos'; }
foreach ($x->opps as $o) $o->contactIds = array_map(function ($i) { return $i === 'kBueno' ? 'kLejos' : $i; }, $o->contactIds);
foreach ($x->plans as $p) foreach ($p->targets as $t) if (isset($t->contactIds)) $t->contactIds = array_map(function ($i) { return $i === 'kBueno' ? 'kLejos' : $i; }, $t->contactIds);
$x->fusiones[] = (object) ['id' => 'fuOtra', 'tabla' => 'contacts', 'de' => 'kBueno', 'a' => 'kLejos'];
[$c, $r] = $guardar($x);
$ok($c === 422 && strpos($r->error, 'misma empresa') !== false, 'fusionar con un contacto de otra empresa: «' . ($r->error ?? '') . '»');
$x = $copia($base); $x->contacts = $sin($x->contacts, 'kBueno'); $x->leads = array_values(array_filter($x->leads, function ($l) { return $l->contactId !== 'kBueno'; }));
$x->tasks = array_values(array_filter($x->tasks, function ($t) { return $t->entityId !== 'lRep'; }));
foreach ($x->opps as $o) $o->contactIds = array_values(array_diff($o->contactIds, ['kBueno']));
foreach ($x->plans as $p) foreach ($p->targets as $t) if (isset($t->contactIds)) $t->contactIds = array_values(array_diff($t->contactIds, ['kBueno']));
[$c, $r] = $guardar($x);
$ok($c === 422 && strpos($r->error, 'historial') !== false, 'borrar el contacto junto con su prospecto (sin fusión): «' . ($r->error ?? '') . '»');
$viejo = $copia($x); $viejo->fusiones[0]->de = 'kBueno'; $viejo->fusiones[0]->a = 'kLejos';
[$c] = $guardar($viejo);
$ok($c === 422, 'una fusión ya anotada antes no sirve para otra baja');
[$c] = $guardar($snap('malo'), ['reemplazo' => true]);
$ok($c === 200, 'restaurar un respaldo (reemplazo completo) sí se acepta');

echo "5. Joan (API del agente) no cambia: no elimina ni fusiona\n";
[$c, $r] = $req('POST', '?accion=agente-escribir', ['ops' => [['tipo' => 'eliminar', 'empresa' => 'Otra SpA']]], $CLAVE_AG);
$ok($c === 400 && strpos($r->error, 'no permitido') !== false, 'un cambio «eliminar» se rechaza como tipo no permitido');
[$c] = $req('POST', '', ['baseRev' => (int) $leer()->rev, 'data' => $leer()->data], $CLAVE_AG);
$ok($c === 403, 'con su clave no puede guardar el documento completo');
[$c, $r] = $req('POST', '?accion=agente-escribir', ['ops' => [['tipo' => 'actividad', 'empresa' => 'Otra SpA', 'tipo_actividad' => 'Nota', 'descripcion' => 'Revisión']]], $CLAVE_AG);
$ok($c === 200 && $r->ok, 'sus cambios de siempre siguen funcionando');

echo "6. Recuperar lo eliminado\n";
[$c] = $req('POST', '?accion=restaurar', ['tabla' => 'contacts', 'id' => 'kVacia']);
$ok($c === 200 && in_array('kVacia', array_map(function ($k) { return $k->id; }, $leer()->data->contacts), true), 'un contacto eliminado se recupera');

exit($fallas ? 1 : 0);
