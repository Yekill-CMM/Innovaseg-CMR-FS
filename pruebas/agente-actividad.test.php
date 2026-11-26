<?php
// Joan actualiza una actividad existente (actualizar_actividad), con los permisos de la cuenta del agente.
// Uso: php agente-actividad.test.php ../app
$app = realpath($argv[1]);
$src = file_get_contents("$app/api.php");
$fin = strpos($src, "/* ---------- Clave ---------- */");
$codigo = substr($src, strpos($src, '<?php') + 5, $fin - 5);
$codigo = preg_replace('/^header\(.*$/m', '', $codigo);
$codigo = str_replace("require __DIR__ . '/almacen.php';", 'require ' . var_export("$app/almacen.php", true) . ';', $codigo);
$codigo = str_replace(["\$configFile = __DIR__ . '/config.php';", "if (!is_file(\$configFile))", "require \$configFile;"], ['', 'if (false)', ''], $codigo);
eval($codigo);
require "$app/permisos.php";
require __DIR__ . '/equipo-prueba.php';
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$DIR = directorioPrueba();
$ctx = function ($id) use ($DIR) { foreach ($DIR as $u) if ($u->id === $id) return permCtx($u, $DIR); };
$por = function ($l, $id) { foreach ($l as $x) if ($x->id === $id) return $x; return null; };
// Igual que la acción agente-escribir de api.php: vista de la cuenta, cambios, permisos (lo no permitido rechaza todo)
$joan = function ($quien, $ops, $full) use ($ctx) {
    $c = $ctx($quien); $v = permVista($full, $c);
    try { $hecho = aplicarAgente($v, json_decode(json_encode($ops))); } catch (Exception $e) { return [null, $e->getMessage()]; }
    $n = permAplicar($full, $v, $c);
    return $c->avisos ? [null, implode(' ', $c->avisos)] : [$n, $hecho];
};
$full = datosPrueba();
$full->activities[] = (object) ['id' => 'A_resp', 'userId' => 'eN1', 'entityType' => 'opp', 'entityId' => 'O_eN1', 'companyId' => 'C1', 'type' => 'Email', 'desc' => 'Envié la propuesta',
    'result' => '', 'nextAction' => 'Llamar por la propuesta', 'nextActionDate' => '2026-11-27', 'date' => '2026-11-25', 'taskId' => 'T_resp'];
$full->tasks[] = (object) ['id' => 'T_resp', 'title' => 'Llamar por la propuesta', 'entityType' => 'opp', 'entityId' => 'O_eN1', 'assigneeId' => 'eN1', 'status' => 'Pendiente', 'due' => '2026-11-27'];

echo "Joan: actualizar_actividad\n";
$er = error_reporting(E_ALL & ~E_WARNING); // los datos de prueba son mínimos (sin montos ni fechas)
$r = resumenAgente((object) ['data' => permVista($full, $ctx('eN1'))], null);
error_reporting($er);
$notas = []; foreach ($r['oportunidades_abiertas'] as $o) foreach ($o['ultimas_notas'] ?? [] as $x) $notas[] = $x;
$ok((bool) array_filter($notas, function ($x) { return $x['id'] === 'A_resp' && $x['proxima_accion'] === 'Llamar por la propuesta'; }), 'el resumen trae el id y la próxima acción de las últimas notas');

[$n, $h] = $joan('eN1', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_resp', 'resultado' => 'Esperando respuesta', 'fecha_proxima_accion' => '2026-12-03']], $full);
$a = $n ? $por($n->activities, 'A_resp') : null; $t = $n ? $por($n->tasks, 'T_resp') : null;
$ok($a && $a->result === 'Esperando respuesta' && $a->nextActionDate === '2026-12-03', 'el autor actualiza resultado y fecha de la próxima acción' . ($n ? '' : ": $h"));
$ok($t && $t->due === '2026-12-03' && $t->assigneeId === 'eN1' && count(array_filter($n->tasks, function ($x) { return $x->title === 'Llamar por la propuesta'; })) === 1, 'la tarea se corre sin duplicarse y conserva su responsable');
$ok($a && count($a->hist) === 1 && $a->hist[0]->by === 'eN1' && $a->hist[0]->via === 'Joan' && $a->hist[0]->nextActionDate === '2026-11-27', 'el historial guarda lo anterior, quién y que fue por Joan');
$ok($n && (bool) array_filter($n->audit, function ($x) { return $x->field === 'Resultado (Joan)' && $x->userId === 'eN1'; }), 'el cambio queda en la auditoría a nombre de la cuenta');

[$n, $h] = $joan('eN2', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_resp', 'resultado' => 'pisada']], $full);
$ok(!$n && strpos($h, 'no encontré la actividad') !== false, 'el Joan de otro ejecutivo no la encuentra (no la ve)');

[$n, $h] = $joan('sN', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_resp', 'resultado' => 'Respondió']], $full);
$ok($n && $por($n->activities, 'A_resp')->result === 'Respondió', 'el Joan de la subgerente la actualiza (ejecutivo de su equipo)');

$b = permCopia($full); $b->tasks[] = (object) ['id' => 'T_sN', 'title' => 'Revisar con gerencia', 'entityType' => 'opp', 'entityId' => 'O_eN1', 'assigneeId' => 'sN', 'status' => 'Pendiente', 'due' => '2026-12-01'];
foreach ($b->activities as $x) if ($x->id === 'A_sN_en_eN1') { $x->nextAction = 'Revisar con gerencia'; $x->nextActionDate = '2026-12-01'; $x->taskId = 'T_sN'; }
[$n, $h] = $joan('eN1', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_sN_en_eN1', 'resultado' => 'Visto con el cliente']], $b);
$ok($n && $por($n->activities, 'A_sN_en_eN1')->result === 'Visto con el cliente', 'el dueño de la oportunidad actualiza el resultado de la gestión de su subgerente');
[$n, $h] = $joan('eN1', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_sN_en_eN1', 'fecha_proxima_accion' => '2026-12-15']], $b);
$ok(!$n && strpos($h, 'No tienes permiso') !== false, 'pero no corre la tarea de su subgerente: se rechaza todo y no se guarda nada');

[$n, $h] = $joan('eN1', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_resp', 'proxima_accion' => 'Visita']], $full);
$ok(!$n && strpos($h, 'fecha_proxima_accion') !== false, 'la próxima acción sin fecha se rechaza');
[$n, $h] = $joan('eN1', [['tipo' => 'actualizar_actividad', 'actividad' => 'A_resp', 'resultado' => '']], $full);
$ok($n && count($h) === 1 && strpos($h[0], "Sin cambios") === 0 && empty($por($n->activities, 'A_resp')->hist), 'sin cambios no deja historial');
exit($fallas ? 1 : 0);
