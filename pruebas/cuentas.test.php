<?php
// Cuentas por persona y permisos con un servidor PHP real (php -S + router.php): ingreso con clave y segundo factor,
// sesión, administración de cuentas y lo que cada rol recibe y puede guardar por la API.
// Uso: php cuentas.test.php ../app
$APP = realpath($argv[1]);
require "$APP/cuentas.php"; require "$APP/permisos.php";
require __DIR__ . '/sesion-prueba.php'; require __DIR__ . '/equipo-prueba.php';
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$por = function ($l, $id) { foreach ((array) $l as $x) if ($x->id === $id) return $x; return null; };

$T = sys_get_temp_dir() . '/crm-cuentas-' . getmypid();
@mkdir("$T/app/datos", 0777, true); @mkdir("$T/local", 0777, true);
foreach (['index.html', 'api.php', 'almacen.php', 'cuentas.php', 'permisos.php'] as $f) copy("$APP/$f", "$T/app/$f");
copy(dirname($APP) . '/local/router.php', "$T/local/router.php");
$CLAVE_AG = str_repeat('b', 24);
file_put_contents("$T/app/config.php", "<?php define('CRM_CLAVE_AGENTE', '$CLAVE_AG'); define('CRM_AGENTE_USUARIO', 'pcifuentes');");
$CTA = pruebaCuentas("$T/app/datos", EQUIPO_PRUEBA);
$port = pruebaServidor($T);
$req = pruebaHttp($port);
$db = function () use ($T) { return new PDO("sqlite:$T/app/datos/crm.db"); };

echo "1. Sin sesión no hay datos\n";
[$c, $r] = $req('GET', '?accion=leer');
$ok($c === 401 && empty($r->data), 'sin sesión: 401 y ningún dato');
[$c] = $req('GET', '?accion=leer', null, '', ['X-CRM-Clave: cualquier-cosa-larga']);
$ok($c === 401, 'la antigua clave compartida ya no abre el CRM');
$ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true]]);
@file_get_contents("http://127.0.0.1:$port/api.php?accion=leer", false, $ctx);
$ok(strpos($http_response_header[0], '403') !== false, 'una petición sin la cabecera X-CRM (por ejemplo, desde otra página) se rechaza');
$ok(@file_get_contents("http://127.0.0.1:$port/datos/cuentas.db") === false && strpos((string) @file_get_contents("http://127.0.0.1:$port/cuentas.php"), 'password') === false, 'la base de cuentas y el código no se descargan desde el navegador');

echo "2. Primer ingreso: clave temporal, clave propia y segundo factor\n";
[$e, $nid, $temp] = ctaCrear($CTA, (object) ['usuario' => 'nueva.persona', 'nombre' => 'Persona Nueva', 'rol' => 'ejecutivo', 'equipo' => 'Norte']);
[$c, $r] = $req('POST', '?accion=entrar', ['usuario' => 'nueva.persona', 'clave' => 'otra-cosa-123']);
$ok($c === 401 && $r->error === 'Usuario o clave incorrectos.', 'clave errónea: mensaje genérico');
[$c, $r2] = $req('POST', '?accion=entrar', ['usuario' => 'no.existe', 'clave' => 'otra-cosa-123']);
$ok($c === 401 && $r2->error === $r->error, 'un usuario que no existe recibe el mismo mensaje');
[$c, $r, $tok] = $req('POST', '?accion=entrar', ['usuario' => 'Nueva.Persona', 'clave' => $temp]);
$ok($c === 200 && $r->paso === 'nueva-clave' && !$tok, 'con la clave temporal pide elegir una propia (sin abrir sesión)');
[$c] = $req('GET', '?accion=leer', null, $r->desafio);
$ok($c === 401, 'el paso intermedio no sirve como sesión');
[$c, $x] = $req('POST', '?accion=entrar', ['desafio' => $r->desafio, 'nueva_clave' => 'corta1']);
$ok($c === 400 && strpos($x->error, '10 caracteres') !== false, 'una clave débil se rechaza');
[$c, $r] = $req('POST', '?accion=entrar', ['desafio' => $r->desafio, 'nueva_clave' => 'Mi-clave-propia-2026']);
$ok($c === 200 && $r->paso === 'activar' && strlen($r->secreto) === 32 && strpos($r->uri, 'otpauth://totp/') === 0, 'luego pide activar el segundo factor (clave secreta y enlace para la aplicación)');
[$c, $x] = $req('POST', '?accion=entrar', ['desafio' => $r->desafio, 'codigo' => '000000']);
$ok($c === 401, 'un código errado no activa nada');
[$c, $x, $tok, $hdr] = $req('POST', '?accion=entrar', ['desafio' => $r->desafio, 'codigo' => ctaTotp($r->secreto, intdiv(time(), 30))]);
$ck = implode("\n", preg_grep('/^Set-Cookie: crm_sesion=/i', $hdr));
$ok($c === 200 && $x->ok && $tok && stripos($ck, 'HttpOnly') !== false && stripos($ck, 'SameSite=Strict') !== false, 'con el código correcto entra: cookie de sesión HttpOnly y SameSite=Strict');
[$c, $s] = $req('GET', '?accion=sesion', null, $tok);
$ok($c === 200 && $s->usuario->name === 'Persona Nueva' && !isset($s->usuario->clave_hash), 'la sesión sabe quién es (sin datos de la clave)');
$fila = ctaFila($CTA, $nid);
$ok($fila['clave_temporal'] == 0 && $fila['totp_activo'] == 1 && strpos($fila['clave_hash'], 'Mi-clave') === false, 'queda con clave propia (guardada como hash) y segundo factor activo');
[$c, $r] = $req('POST', '?accion=entrar', ['usuario' => 'nueva.persona', 'clave' => 'Mi-clave-propia-2026']);
$ok($c === 200 && $r->paso === 'codigo', 'el siguiente ingreso pide clave y código');
$paso = (int) $fila['totp_ultimo'];
[$c] = $req('POST', '?accion=entrar', ['desafio' => $r->desafio, 'codigo' => ctaTotp($r->secreto ?? $fila['totp_secreto'], $paso)]);
$ok($c === 401, 'un código ya usado no sirve de nuevo');
[$c] = $req('POST', '?accion=salir', null, $tok);
[$c2] = $req('GET', '?accion=leer', null, $tok);
$ok($c === 200 && $c2 === 401, 'al cerrar sesión, la cookie deja de servir');

echo "3. Bloqueo por intentos\n";
for ($i = 0; $i < 5; $i++) $req('POST', '?accion=entrar', ['usuario' => 'ivega', 'clave' => 'mala-clave-' . $i]);
[$c, $r] = $req('POST', '?accion=entrar', ['usuario' => 'ivega', 'clave' => CLAVE_PRUEBA]);
$ok($c === 429, 'cinco fallos seguidos bloquean la cuenta 15 minutos (ni con la clave correcta entra)');
$CTA->exec("UPDATE usuarios SET bloqueado_hasta = 0, fallos = 0 WHERE usuario = 'ivega'");
@unlink("$T/app/datos/intentos.json.php");

echo "4. Administración de cuentas\n";
$S = []; foreach (EQUIPO_PRUEBA as $u) $S[$u[0]] = pruebaEntrar($req, $u[1], $CTA);
[$c] = $req('GET', '?accion=usuarios', null, $S['g1']);
[$c2] = $req('POST', '?accion=usuarios', ['op' => 'crear', 'usuario' => 'x.y', 'nombre' => 'X', 'rol' => 'admin'], $S['eN1']);
$ok($c === 403 && $c2 === 403, 'el gerente y los ejecutivos no administran cuentas');
[$c, $l] = $req('GET', '?accion=usuarios', null, $S['ad']);
$ok($c === 200 && count($l->usuarios) === 13 && !isset($l->usuarios[0]->clave_hash) && !isset($l->usuarios[0]->totp_secreto), 'el administrador ve las cuentas, sin claves ni secretos');
[$c, $r] = $req('POST', '?accion=usuarios', ['op' => 'crear', 'usuario' => 'ejecutivo.sur5', 'nombre' => 'Ejecutivo Cinco', 'rol' => 'ejecutivo', 'equipo' => 'Sur'], $S['ad']);
$ok($c === 200 && strlen($r->claveTemporal) >= 14, 'crea una cuenta y entrega la clave temporal una sola vez');
[$c, $x] = $req('POST', '?accion=usuarios', ['op' => 'crear', 'usuario' => 'otro.sub', 'nombre' => 'Otro', 'rol' => 'subgerente', 'equipo' => 'Sur'], $S['ad']);
$ok($c === 400 && strpos($x->error, 'ya tiene subgerente') !== false, 'un equipo no puede tener dos subgerentes');
[$c, $x] = $req('POST', '?accion=usuarios', ['op' => 'editar', 'id' => 'ad', 'activo' => false], $S['ad']);
$ok($c === 400, 'el administrador no puede desactivarse a sí mismo');
[$c] = $req('POST', '?accion=usuarios', ['op' => 'segundo-factor', 'id' => 'eS3'], $S['ad']);
[$c2] = $req('GET', '?accion=sesion', null, $S['eS3']);
[$c3, $r] = $req('POST', '?accion=entrar', ['usuario' => 'mcontreras', 'clave' => CLAVE_PRUEBA]);
$ok($c === 200 && $c2 === 401 && $r->paso === 'activar', 'reiniciar el segundo factor cierra sus sesiones y lo hace activarlo de nuevo');
[$c, $r] = $req('POST', '?accion=usuarios', ['op' => 'editar', 'id' => $nid, 'activo' => false], $S['ad']);
[$c2] = $req('POST', '?accion=entrar', ['usuario' => 'nueva.persona', 'clave' => 'Mi-clave-propia-2026']);
$ok($c === 200 && $c2 === 401, 'una cuenta desactivada no entra');
[$c, $x] = $req('POST', '?accion=cambiar-clave', ['actual' => 'no-es', 'nueva' => 'Otra-clave-2026'], $S['eS2']);
[$c2] = $req('POST', '?accion=cambiar-clave', ['actual' => CLAVE_PRUEBA, 'nueva' => 'Otra-clave-de-Fernanda-1'], $S['eS2']);
$ok($c === 400 && $c2 === 200, 'cada persona cambia su clave indicando la actual');
$S['eS3'] = null;

echo "5. Datos: cada rol recibe solo lo suyo\n";
[$c, $x] = $req('POST', '', ['baseRev' => 0, 'data' => datosPrueba()], $S['eN1']);
$ok($c === 403, 'con el CRM vacío, solo el administrador lo inicia');
[$c, $r] = $req('POST', '', ['baseRev' => 0, 'data' => datosPrueba()], $S['ad']);
$ok($c === 200, 'el administrador carga los datos iniciales');
$ver = function ($quien) use ($req, &$S) { [, $d] = $req('GET', '?accion=leer', null, $S[$quien]); return $d; };
$malos = [];
foreach (['g1', 'sN', 'eN1', 'eS4', 'ad'] as $quien) {
    $d = $ver($quien); $j = json_encode($d, JSON_UNESCAPED_UNICODE);
    foreach (array_column(EQUIPO_PRUEBA, 0) as $u) if ((strpos($j, "marca-$u") !== false) !== in_array($u, alcancePrueba($quien), true)) $malos[] = "$quien/$u";
    if ($d->yo->id !== $quien || $d->data->currentUser !== $quien) $malos[] = "$quien: sesión";
}
$ok(!$malos, 'gerente, subgerente, ejecutivos y administrador reciben exactamente su alcance' . ($malos ? ': ' . implode(', ', $malos) : ''));
[$c] = $req('POST', '?accion=ajustes', ['ajustes' => ['uf' => 40000, 'targets' => ['eN1' => 1, 'eN2' => 2, 'eS1' => 3], 'metas' => ['2027' => ['venta' => []]]]], $S['ad']);
$d = $ver('eN1'); [, $rv] = $req('GET', '?accion=rev', null, $S['eN1']);
$ok($c === 200 && array_keys(get_object_vars($d->ajustes->targets)) === ['eN1'] && !isset($d->ajustes->metas) && array_keys(get_object_vars($rv->ajustes->targets)) === ['eN1'], 'los ajustes que recibe (al leer y al revisar la versión) tampoco traen metas ajenas');

echo "6. Guardar con permisos\n";
$d = $ver('eN1'); $data = $d->data;
$por($data->opps, 'O_eN1')->prob = 60;
[$c, $r] = $req('POST', '', ['baseRev' => $d->rev, 'data' => $data], $S['eN1']);
$ok($c === 200 && empty($r->avisos), 'Paula guarda su oportunidad');
$todo = $ver('ad')->data;
$ok($por($todo->opps, 'O_eN1')->prob === 60 && count($todo->opps) === 12 && count($todo->tasks) === 12, 'y los datos completos conservan todo lo que ella no ve');
$fila = $db()->query("SELECT usuario, origen FROM cambios WHERE tabla='opps' AND registro='O_eN1' ORDER BY n DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ok($fila['usuario'] === 'eN1' && $fila['origen'] === 'pantalla', '«cambios» registra que fue Paula');
$d = $ver('eN1'); $data = $d->data;
$por($data->opps, 'O_eN1')->ownerId = 'eS1'; $data->contacts = array_values(array_filter($data->contacts, function ($k) { return $k->id !== 'K2'; }));
[$c, $r] = $req('POST', '', ['baseRev' => $d->rev, 'data' => $data], $S['eN1']);
$ok($c === 200 && count($r->avisos) === 2 && $por($r->data->opps, 'O_eN1')->ownerId === 'eN1', 'lo no permitido no se aplica: vuelve con avisos y la vista del servidor');
$ok(strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-eN2') === false, 'esa vista tampoco trae datos ajenos');
[$c, $r] = $req('POST', '', ['baseRev' => 1, 'data' => $data], $S['eN1']);
$ok($c === 409 && isset($r->data) && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-eN2') === false, 'si otra persona guardó antes (409), recibe la versión actual de su vista, no la de todos');
$d = $ver('eN1'); $data = $d->data; $data->leads[] = (object) ['name' => 'sin id'];
[$c, $r] = $req('POST', '', ['baseRev' => $d->rev, 'data' => $data], $S['eN1']);
$ok($c === 422 && !empty($r->rechazo) && isset($r->data) && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-sN') === false, 'un rechazo (422: datos mal formados) tampoco filtra datos ajenos');
$d = $ver('eN1'); $data = $d->data; $por($data->companies, 'C1')->notes = 'Visita en enero'; $por($data->companies, 'C2')->notes = 'no';
[$c, $r] = $req('POST', '', ['baseRev' => $d->rev, 'data' => $data], $S['eN1']);
$todoE = $ver('ad')->data->companies; $c1 = $por($todoE, 'C1'); $c2 = $por($todoE, 'C2');
$ok($c === 200 && count($r->avisos) === 1 && $c1->notes === 'Visita en enero' && $c1->updatedBy === 'eN1' && !isset($c2->notes), 'Paula edita la empresa que creó (queda anotado que fue ella), no la que creó otra persona');
[$c] = $req('POST', '', ['baseRev' => $d->rev, 'data' => $d->data, 'reemplazo' => true], $S['g1']);
$ok($c === 403, 'solo el administrador reemplaza todos los datos (restaurar un respaldo)');
[$c] = $req('POST', '?accion=ajustes', ['ajustes' => ['uf' => 1]], $S['sN']);
$ok($c === 403, 'solo el administrador guarda la configuración');

echo "7. Historial, eliminados y recuperación\n";
[, $h] = $req('GET', '?accion=historial&tabla=opps&id=O_eN2', null, $S['eN1']);
[, $h2] = $req('GET', '?accion=historial&tabla=opps&id=O_eN2', null, $S['sN']);
$ok(!$h->historial && count($h2->historial) >= 1, 'el historial de la oportunidad de Tomás: Paula no lo ve, su subgerente sí');
$d = $ver('eN2'); $data = $d->data; $data->tasks = array_values(array_filter($data->tasks, function ($t) { return $t->id !== 'T_eN2'; }));
$req('POST', '', ['baseRev' => $d->rev, 'data' => $data], $S['eN2']);
[, $e1] = $req('GET', '?accion=eliminados', null, $S['eN1']);
[, $e2] = $req('GET', '?accion=eliminados', null, $S['eN2']);
$ok(!$e1->eliminados && count($e2->eliminados) === 1, 'los eliminados de Tomás no aparecen en la lista de Paula');
[$c] = $req('POST', '?accion=restaurar', ['tabla' => 'tasks', 'id' => 'T_eN2'], $S['eN1']);
[$c2] = $req('POST', '?accion=restaurar', ['tabla' => 'tasks', 'id' => 'T_eN2'], $S['eN2']);
$ok($c === 403 && $c2 === 200, 'y solo él (o su jefatura) lo recupera');

echo "8. Traspaso de cartera\n";
[$c, $r] = $req('POST', '?accion=usuarios', ['op' => 'traspasar', 'de' => 'eN4', 'a' => 'eN3'], $S['ad']);
$todo = $ver('ad')->data;
$ok($c === 200 && $por($todo->opps, 'O_eN4')->ownerId === 'eN3' && $por($todo->leads, 'L_eN4')->ownerId === 'eN3' && $por($todo->tasks, 'T_eN4')->assigneeId === 'eN3', 'la cartera de Diego pasa a Camila (prospectos, oportunidades y tareas abiertas)');
$ok($por($todo->activities, 'A_eN4')->userId === 'eN4', 'sus actividades siguen a su nombre: el historial conserva quién hizo cada cosa');
$ok(strpos(json_encode($ver('eN3')), 'marca-eN4') !== false, 'Camila ya ve lo traspasado');

echo "9. Agente con la cuenta de una persona\n";
[$c, $r] = $req('GET', '?accion=agente', null, '', ['X-CRM-Clave: ' . $CLAVE_AG]);
$ok($c === 200 && count($r->oportunidades_abiertas) === 1 && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-eN2') === false, 'el resumen del agente trae solo lo que ve su cuenta (Paula)');
[$c] = $req('GET', '?accion=leer', null, '', ['X-CRM-Clave: ' . $CLAVE_AG]);
$ok($c === 403, 'con la clave del agente no se leen los datos completos');

exit($fallas ? 1 : 0);
