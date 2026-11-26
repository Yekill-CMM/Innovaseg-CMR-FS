<?php
// Guardado registro por registro (app/guardado.php) con un servidor PHP real y el equipo inventado de las pruebas de permisos:
// dos personas trabajando a la vez sin pisarse, conflictos solo cuando tocan lo mismo, permisos iguales que con la copia
// completa, usuario de cada cambio en «cambios» y códigos OP-AAMMDD-NN que asigna el servidor.
// Uso: php guardado.test.php ../app
$APP = realpath($argv[1]);
require "$APP/cuentas.php"; require "$APP/permisos.php"; require "$APP/guardado.php";
require __DIR__ . '/sesion-prueba.php'; require __DIR__ . '/equipo-prueba.php';
date_default_timezone_set('America/Santiago');
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$por = function ($l, $id) { foreach ((array) $l as $x) if ((string) $x->id === $id) return $x; return null; };
$cp = function ($x) { return json_decode(json_encode($x)); };

$T = sys_get_temp_dir() . '/crm-guardado-' . getmypid();
@mkdir("$T/app/datos", 0777, true); @mkdir("$T/local", 0777, true);
foreach (['index.html', 'api.php', 'almacen.php', 'cuentas.php', 'permisos.php', 'guardado.php'] as $f) copy("$APP/$f", "$T/app/$f");
copy(dirname($APP) . '/local/router.php', "$T/local/router.php");
file_put_contents("$T/app/config.php", "<?php define('CRM_CLAVE_AGENTE', '');");
$CTA = pruebaCuentas("$T/app/datos", EQUIPO_PRUEBA);
$port = pruebaServidor($T);
$req = pruebaHttp($port);
$db = function () use ($T) { return new PDO("sqlite:$T/app/datos/crm.db"); };
$S = [];
foreach (['g1', 'sN', 'eN1', 'eN2', 'eS1', 'ad'] as $id) { $u = EQUIPO_PRUEBA[array_search($id, array_column(EQUIPO_PRUEBA, 0))][1]; $S[$id] = pruebaEntrar($req, $u, $CTA); }
$ver = function ($quien) use ($req, &$S) { [, $d] = $req('GET', '?accion=leer', null, $S[$quien]); return $d; };
// Un guardado como lo arma la pantalla: [tabla, id, antes, despues, tras]
$guardar = function ($quien, $rev, $regs, $doc = []) use ($req, &$S) {
    $c = ['registros' => array_map(function ($r) { return ['t' => $r[0], 'id' => $r[1], 'antes' => $r[2], 'despues' => $r[3], 'tras' => $r[4] ?? null]; }, $regs),
        'doc' => array_map(function ($k, $v) { return ['k' => $k, 'antes' => $v[0], 'despues' => $v[1]]; }, array_keys($doc), $doc)];
    return $req('POST', '', ['baseRev' => $rev, 'cambios' => $c], $S[$quien]);
};
[$c] = $req('POST', '', ['baseRev' => 0, 'data' => datosPrueba()], $S['ad']);
if ($c !== 200) { echo "No se pudieron cargar los datos iniciales ($c)\n"; exit(1); }
$hoy = date('ymd');

echo "1. Dos personas a la vez, en registros distintos\n";
$dP = $ver('eN1'); $dT = $ver('eN2');                      // las dos leen la misma versión
$oP = $por($dP->data->opps, 'O_eN1'); $oP2 = $cp($oP); $oP2->prob = 70;
[$c, $r] = $guardar('eN1', $dP->rev, [['opps', 'O_eN1', $oP, $oP2]]);
$ok($c === 200 && !isset($r->data) && $por($r->registros, 'O_eN1')->datos->prob === 70, 'Paula guarda y recibe solo la versión final de lo que tocó (no toda la base)');
$oT = $por($dT->data->opps, 'O_eN2'); $oT2 = $cp($oT); $oT2->prob = 40;
[$c, $r] = $guardar('eN2', $dT->rev, [['opps', 'O_eN2', $oT, $oT2]]);
$ok($c === 200 && empty($r->conflictos), 'Tomás guarda después sobre la versión vieja: ya no hay 409');
$todo = $ver('ad')->data;
$ok($por($todo->opps, 'O_eN1')->prob === 70 && $por($todo->opps, 'O_eN2')->prob === 40, 'quedan los dos cambios');
$ok(isset($r->data) && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-eN1') === false && $por($r->data->opps, 'O_eN2')->prob === 40, 'como otra persona guardó entremedio, Tomás recibe su vista al día (sin datos de Paula)');

echo "2. El mismo registro: campos distintos se juntan, el mismo campo es conflicto\n";
$dS = $ver('sN'); $dP = $ver('eN1');                        // la subgerente y Paula abren la misma oportunidad
$a = $por($dS->data->opps, 'O_eN1'); $b = $cp($a); $b->nextAction = 'Reunión con el gerente de planta';
[$c] = $guardar('sN', $dS->rev, [['opps', 'O_eN1', $a, $b]]);
$a = $por($dP->data->opps, 'O_eN1'); $b = $cp($a); $b->closeDate = '2027-01-15';
[$c, $r] = $guardar('eN1', $dP->rev, [['opps', 'O_eN1', $a, $b]]);
$o = $por($ver('ad')->data->opps, 'O_eN1');
$ok($c === 200 && empty($r->conflictos) && $o->nextAction === 'Reunión con el gerente de planta' && $o->closeDate === '2027-01-15', 'subgerente cambia la próxima acción y Paula la fecha de cierre: quedan las dos');
$dS = $ver('sN'); $dP = $ver('eN1');
$a = $por($dS->data->opps, 'O_eN1'); $b = $cp($a); $b->prob = 80;
$guardar('sN', $dS->rev, [['opps', 'O_eN1', $a, $b]]);
$a = $por($dP->data->opps, 'O_eN1'); $b = $cp($a); $b->prob = 50; $b->name = 'Oportunidad marca-eN1 renombrada';
$t0 = $por($dP->data->tasks, 'T_eN1'); $t1 = $cp($t0); $t1->status = 'Completada';
[$c, $r] = $guardar('eN1', $dP->rev, [['opps', 'O_eN1', $a, $b], ['tasks', 'T_eN1', $t0, $t1]]);
$o = $por($ver('ad')->data->opps, 'O_eN1');
$cf = $r->conflictos[0] ?? null;
$ok($c === 200 && count($r->conflictos) === 1 && $cf->t === 'opps' && $cf->id === 'O_eN1' && $cf->quien === 'Rodrigo Valdés', 'las dos cambian la probabilidad: conflicto, con quién lo cambió (' . ($cf->quien ?? '') . ')');
$ok($o->prob === 80 && $o->name !== 'Oportunidad marca-eN1 renombrada', 'queda la versión de quien guardó primero, entera (no a medias)');
$ok($por($ver('ad')->data->tasks, 'T_eN1')->status === 'Completada' && isset($r->data), 'lo demás del mismo guardado sí se aplica, y la pantalla recibe su vista para revisar');

echo "3. Eliminar y cambiar a la vez\n";
$dP = $ver('eN1'); $dS = $ver('sN');
$t0 = $por($dS->data->tasks, 'T_eN1'); $t1 = $cp($t0); $t1->due = '2026-12-20';
$guardar('sN', $dS->rev, [['tasks', 'T_eN1', $t0, $t1]]);
[$c, $r] = $guardar('eN1', $dP->rev, [['tasks', 'T_eN1', $por($dP->data->tasks, 'T_eN1'), null]]);
$ok($c === 200 && count($r->conflictos) === 1 && $r->conflictos[0]->motivo === 'cambio' && $por($ver('ad')->data->tasks, 'T_eN1'), 'no se elimina algo que otra persona acaba de cambiar');
$dP = $ver('eN1'); $dS = $ver('sN');
$guardar('sN', $dS->rev, [['tasks', 'T_eN1', $por($dS->data->tasks, 'T_eN1'), null]]);
$t0 = $por($dP->data->tasks, 'T_eN1'); $t1 = $cp($t0); $t1->title = 'Tarea editada';
[$c, $r] = $guardar('eN1', $dP->rev, [['tasks', 'T_eN1', $t0, $t1]]);
$ok($c === 200 && $r->conflictos[0]->motivo === 'eliminado' && !$por($ver('ad')->data->tasks, 'T_eN1'), 'y lo que otra persona eliminó no revive al editarlo');

echo "4. Altas: orden y registros de otra persona\n";
$d = $ver('eN1');
$n1 = ['id' => 'T_nueva1', 'title' => 'Nueva 1', 'assigneeId' => 'eN1', 'status' => 'Pendiente', 'due' => '2026-12-05'];
$n2 = ['id' => 'T_nueva2', 'title' => 'Nueva 2', 'assigneeId' => 'eN1', 'status' => 'Pendiente', 'due' => '2026-12-06'];
$ult = $d->data->tasks ? end($d->data->tasks)->id : null;
[$c, $r] = $guardar('eN1', $d->rev, [['tasks', 'T_nueva1', null, $n1, $ult], ['tasks', 'T_nueva2', null, $n2, 'T_nueva1']]);
$ids = array_column($ver('eN1')->data->tasks, 'id');
$ok($c === 200 && array_slice($ids, -2) === ['T_nueva1', 'T_nueva2'], 'las altas quedan en el orden de la pantalla');
[$c, $r] = $guardar('eN1', $d->rev, [['tasks', 'T_nueva1', null, $n1, $ult]]);
$ok($c === 200 && empty($r->conflictos) && count(array_keys(array_column($ver('eN1')->data->tasks, 'id'), 'T_nueva1')) === 1, 'reenviar la misma alta (un reintento) no la duplica');
$d = $ver('eN1');
$aj = ['id' => 'T_eS1', 'title' => 'pisar la tarea de Javier', 'assigneeId' => 'eN1'];
[$c, $r] = $guardar('eN1', $d->rev, [['tasks', 'T_eS1', null, $aj]]);
$ok($por($ver('ad')->data->tasks, 'T_eS1')->title === 'Tarea marca-eS1' && !empty($r->avisos), 'un alta con el id de un registro que no ve no lo pisa (aviso)');

echo "5. Los permisos son los mismos que con la copia completa\n";
$d = $ver('eN1');
$a = $por($d->data->opps, 'O_eN1'); $b = $cp($a); $b->ownerId = 'eS1';
$c2 = $por($d->data->companies, 'C2'); $c2b = $cp($c2); $c2b->notes = 'cambio ajeno';
[$c, $r] = $guardar('eN1', $d->rev, [['opps', 'O_eN1', $a, $b], ['companies', 'C2', $c2, $c2b], ['contacts', 'K2', $por($d->data->contacts, 'K2'), null]]);
$todo = $ver('ad')->data;
$ok($c === 200 && count($r->avisos) === 3 && $por($todo->opps, 'O_eN1')->ownerId === 'eN1' && !isset($por($todo->companies, 'C2')->notes) && $por($todo->contacts, 'K2'), 'traspasar fuera de su alcance, editar la empresa que creó otra persona o eliminar un contacto: no se aplica y vuelve como aviso');
$ok(strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-eN2') === false, 'la respuesta no trae datos ajenos');
$a = $por($d->data->opps, 'O_eN1');
[$c, $r] = $guardar('eN1', $d->rev, [['opps', 'O_eN2', (object) ['id' => 'O_eN2'], (object) ['id' => 'O_eN2', 'prob' => 1]]]);
$ok($c === 200 && $por($ver('ad')->data->opps, 'O_eN2')->prob === 40, 'cambiar una oportunidad que no ve no tiene efecto');
[$c, $r] = $guardar('eN1', $d->rev, [['users', 'eN1', null, (object) ['id' => 'eN1', 'role' => 'admin']]]);
$ok($c === 422 && !empty($r->rechazo) && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'marca-eN2') === false, 'las cuentas no se cambian por aquí (422, sin datos ajenos)');
$cfg = $d->data->config; $cfg2 = $cp($cfg); $cfg2->uf = 1;
[$c, $r] = $guardar('eN1', $d->rev, [], ['config' => [$cfg, $cfg2]]);
$ok((int) $ver('ad')->data->config->uf === 39000, 'un ejecutivo no cambia la configuración');
$d = $ver('eN1'); $c1 = $por($d->data->companies, 'C1'); $c1b = $cp($c1); $c1b->notes = 'Visita en enero'; $c1b->createdBy = 'eS1';
[$c, $r] = $guardar('eN1', $d->rev, [['companies', 'C1', $c1, $c1b]]);
$x = $por($ver('ad')->data->companies, 'C1');
$ok($c === 200 && $x->notes === 'Visita en enero' && $x->createdBy === 'eN1' && $x->updatedBy === 'eN1' && $por($r->registros, 'C1')->datos->updatedBy === 'eN1', 'en su empresa sí: el servidor anota quién la cambió (y la pantalla lo recibe), sin dejar cambiar quién la creó');
$d = $ver('eN1'); $c1 = $por($d->data->companies, 'C1'); $c1b = $cp($c1); unset($c1b->updatedBy, $c1b->updatedAt); $c1b->city = 'Calama';
[$c, $r] = $guardar('eN1', $d->rev, [['companies', 'C1', $c1, $c1b]]);
$ok($c === 200 && empty($r->conflictos) && $por($ver('ad')->data->companies, 'C1')->city === 'Calama', 'los campos de trazabilidad no generan conflictos');

echo "6. Auditoría: quién hizo cada cambio\n";
$filas = $db()->query("SELECT registro, usuario FROM cambios WHERE tabla='opps' AND registro IN ('O_eN1','O_eN2') AND accion='cambio' ORDER BY n")->fetchAll(PDO::FETCH_ASSOC);
$u = array_unique(array_column($filas, 'usuario')); sort($u);
$ok($u === ['eN1', 'eN2', 'sN'], '«cambios» guarda, registro por registro, la persona que hizo cada cambio: ' . implode(', ', $u));
[, $h] = $req('GET', '?accion=historial&tabla=opps&id=O_eN1', null, $S['eN1']);
$ok($h->historial && in_array('sN', array_column($h->historial, 'usuario'), true), 'el historial de la oportunidad muestra quién la cambió');
$d = $ver('eN1');
[$c, $r] = $guardar('eN1', $d->rev, [['audit', 'AU_x', null, (object) ['id' => 'AU_x', 'userId' => 'eS1', 'entity' => 'algo', 'field' => 'x', 'oldV' => '', 'newV' => '']]]);
$ok($por($ver('ad')->data->audit, 'AU_x')->userId === 'eN1', 'un movimiento de auditoría queda firmado por quien guarda, no por quien diga');
$au = $por($ver('eN1')->data->audit, 'AU_eN1');
[$c, $r] = $guardar('eN1', $ver('eN1')->rev, [['audit', 'AU_eN1', $au, null]]);
$ok($por($ver('ad')->data->audit, 'AU_eN1') !== null, 'la auditoría no se borra');

echo "7. Código de oportunidad OP-AAMMDD-NN\n";
$dP = $ver('eN1'); $dJ = $ver('eS1');   // dos personas de equipos distintos crean una oportunidad a la vez
$prov = "OP-$hoy-01";
$nueva = function ($id, $quien) use ($prov) { return (object) ['id' => $id, 'code' => $prov, 'crm' => true, 'name' => "Nueva de $quien", 'companyId' => 'C3', 'ownerId' => $quien, 'status' => 'abierta', 'stage' => 'oferta', 'prob' => 50]; };
[$c1, $r1] = $guardar('eN1', $dP->rev, [['opps', 'O_nP', null, $nueva('O_nP', 'eN1'), end($dP->data->opps)->id],
    ['audit', 'AU_nP', null, (object) ['id' => 'AU_nP', 'entity' => $prov, 'field' => 'Creación', 'oldV' => '', 'newV' => 'Nueva de eN1']],
    ['tasks', 'T_nP', null, (object) ['id' => 'T_nP', 'title' => "Definir la próxima gestión ($prov)", 'entityType' => 'opp', 'entityId' => 'O_nP', 'assigneeId' => 'eN1', 'status' => 'Pendiente']]]);
[$c2, $r2] = $guardar('eS1', $dJ->rev, [['opps', 'O_nJ', null, $nueva('O_nJ', 'eS1'), end($dJ->data->opps)->id]]);
$todo = $ver('ad')->data;
$cP = $por($todo->opps, 'O_nP')->code; $cJ = $por($todo->opps, 'O_nJ')->code;
$ok($cP === "OP-$hoy-01" && $cJ === "OP-$hoy-02", "las dos con el mismo provisional quedan con códigos distintos: $cP y $cJ");
$ok(isset($r2->codigos) && $r2->codigos->{$prov} === "OP-$hoy-02", 'quien recibió otro número se entera (códigos en la respuesta)');
$ok($c1 === 200 && empty($r1->codigos) && $por($r1->registros, 'O_nP')->datos->code === "OP-$hoy-01", 'quien llegó primero conserva el provisional');
$d = $ver('eS1');
[$c, $r] = $guardar('eS1', $d->rev, [['opps', 'O_nJ2', null, (object) ['id' => 'O_nJ2', 'code' => $prov, 'name' => 'Otra', 'companyId' => 'C3', 'ownerId' => 'eS1', 'status' => 'abierta', 'stage' => 'oferta'], 'O_nJ'],
    ['audit', 'AU_nJ2', null, (object) ['id' => 'AU_nJ2', 'entity' => $prov, 'field' => 'Creación', 'oldV' => '', 'newV' => 'Otra']],
    ['tasks', 'T_nJ2', null, (object) ['id' => 'T_nJ2', 'title' => "Definir la próxima gestión ($prov)", 'entityType' => 'opp', 'entityId' => 'O_nJ2', 'assigneeId' => 'eS1', 'status' => 'Pendiente']]]);
$todo = $ver('ad')->data;
$ok($por($todo->opps, 'O_nJ2')->code === "OP-$hoy-03" && $por($todo->audit, 'AU_nJ2')->entity === "OP-$hoy-03" && $por($todo->tasks, 'T_nJ2')->title === "Definir la próxima gestión (OP-$hoy-03)", 'el código definitivo se corrige también en la auditoría y la tarea nuevas');
$ok($por($todo->opps, 'O_nJ2')->crm === true && $todo->seq->opUlt === "OP-$hoy-03", 'queda marcada como del CRM y el último código queda en seq (para el próximo provisional)');
$d = $ver('eN1'); $a = $por($d->data->opps, 'O_nP'); $b = $cp($a); $b->code = 'OP-990101-01';
[$c, $r] = $guardar('eN1', $d->rev, [['opps', 'O_nP', $a, $b]]);
$ok($por($ver('ad')->data->opps, 'O_nP')->code === "OP-$hoy-01" && !empty($r->avisos), 'el código no cambia después de creado');
$d = $ver('eN1');
[$c, $r] = $guardar('eN1', $d->rev, [['opps', 'O_cm', null, (object) ['id' => 'O_cm', 'code' => 'CM-261120-05', 'name' => 'Con código CM', 'companyId' => 'C1', 'ownerId' => 'eN1', 'status' => 'abierta', 'stage' => 'oferta']]]);
$ok($por($ver('ad')->data->opps, 'O_cm')->code === "OP-$hoy-04", 'un ejecutivo no elige el código: un CM se reemplaza por el siguiente OP');
$d = $ver('g1');
[$c, $r] = $guardar('g1', $d->rev, [['opps', 'O_fx', null, (object) ['id' => 'O_fx', 'code' => 'CM-261001-07', 'name' => 'Del funnel vigente', 'companyId' => 'C1', 'ownerId' => 'eN1', 'status' => 'abierta', 'stage' => 'seguimiento', 'fx' => ['id' => '501']]],
    ['opps', 'O_fx2', null, (object) ['id' => 'O_fx2', 'code' => 'CM-261001-07', 'name' => 'Repetida', 'companyId' => 'C1', 'ownerId' => 'eN1', 'status' => 'abierta', 'stage' => 'oferta'], 'O_fx']]);
$todo = $ver('ad')->data;
$ok($por($todo->opps, 'O_fx')->code === 'CM-261001-07' && $por($todo->opps, 'O_fx2')->code === "OP-$hoy-05", 'la gerencia puede cargar el funnel vigente con sus códigos CM; uno repetido recibe un OP');
$d = $ver('eN1');
[$c, $r] = $req('POST', '', ['baseRev' => $d->rev, 'data' => (function ($x) { $x->opps[] = (object) ['id' => 'O_full', 'code' => 'OP-000000-01', 'name' => 'Por copia completa', 'companyId' => 'C1', 'ownerId' => 'eN1', 'status' => 'abierta', 'stage' => 'oferta']; return $x; })($cp($d->data))], $S['eN1']);
$ok($c === 200 && $por($r->data->opps, 'O_full')->code === "OP-$hoy-06", 'con la copia completa (cambios hechos sin conexión) también se asigna el código');

echo "8. Prospectos: el mismo PR creado a la vez\n";
$dP = $ver('eN1'); $dJ = $ver('eS1');
$l = function ($id, $q) { return (object) ['id' => $id, 'code' => 'PR-2026-0300', 'name' => "Prospecto de $q", 'companyId' => 'C3', 'ownerId' => $q, 'stage' => 'Identificado']; };
$guardar('eN1', $dP->rev, [['leads', 'L_nP', null, $l('L_nP', 'eN1')]], ['seq' => [$dP->data->seq, (object) ['lead' => 300]]]);
[$c, $r] = $guardar('eS1', $dJ->rev, [['leads', 'L_nJ', null, $l('L_nJ', 'eS1')]], ['seq' => [$dJ->data->seq, (object) ['lead' => 300]]]);
$todo = $ver('ad')->data;
$ok($por($todo->leads, 'L_nP')->code === 'PR-2026-0300' && $por($todo->leads, 'L_nJ')->code === 'PR-' . date('Y') . '-0301' && (int) $todo->seq->lead === 301, 'el segundo recibe el siguiente número libre: ' . $por($todo->leads, 'L_nJ')->code);

echo "9. Lo que no cambia\n";
$d = $ver('eN1');
[$c, $r] = $guardar('eN1', $d->rev, [['opps', 'O_eN1', null, null]]);
$ok($c === 422, 'un cambio sin antes ni después se rechaza');
[$c, $r] = $guardar('eN1', $d->rev, [['opps', 'O_eN1', $por($d->data->opps, 'O_eN1'), (object) ['id' => 'otro']]]);
$ok($c === 422, 'un cambio que trae otro id se rechaza');
$todoAntes = json_encode($ver('ad')->data);
[$c] = $req('POST', '', ['baseRev' => $d->rev, 'cambios' => ['registros' => 'x']], $S['eN1']);
$ok($c === 422 && json_encode($ver('ad')->data) === $todoAntes, 'con datos mal formados no se escribe nada');
[$c, $r] = $req('POST', '', ['baseRev' => 1, 'data' => $d->data], $S['eN1']);
$ok($c === 409, 'la copia completa sobre una versión vieja sigue respondiendo 409 (no se puede mezclar sin saber qué cambió)');

// Reglas sin servidor: mezcla de un registro
$m = guardMezclarRegistro((object) ['a' => 1, 'b' => 1], (object) ['a' => 2, 'b' => 1], (object) ['a' => 1, 'b' => 3], []);
$ok($m && $m->a === 2 && $m->b === 3, 'mezcla: cada lado conserva el campo que cambió');
$ok(guardMezclarRegistro((object) ['a' => 1], (object) ['a' => 2], (object) ['a' => 3], []) === null, 'mezcla: el mismo campo con valores distintos es conflicto');
$ok(guardMezclarRegistro((object) ['a' => 1], (object) ['a' => 2], (object) ['a' => 2], []) !== null, 'mezcla: si los dos pusieron lo mismo, no es conflicto');
$ok(guardIgual((object) ['x' => 1, 'y' => ['b' => 1, 'a' => 2]], (object) ['y' => ['a' => 2, 'b' => 1], 'x' => 1]), 'el orden de los campos no cuenta como cambio');
$ok(guardCodigoOP(["OP-261126-01" => 1, "OP-261126-02" => 1], '2026-11-26') === 'OP-261126-03' && guardCodigoOP([], '2027-01-05') === 'OP-270105-01', 'OP-AAMMDD-NN: fecha de creación y siguiente correlativo libre del día');

echo $fallas ? "\n$fallas pruebas fallaron\n" : "\nTodo bien\n";
exit($fallas ? 1 : 0);
