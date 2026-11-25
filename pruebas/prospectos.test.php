<?php
// Joan y los prospectos: código PR, varios por cliente, búsqueda por código y resumen.
// Uso: php prospectos.test.php ../app/api.php <tmp>/crm-prospectos.json
$src = file_get_contents($argv[1]);
$fin = strpos($src, "/* ---------- Clave ---------- */");
$codigo = substr($src, strpos($src, '<?php') + 5, $fin - 5);
$codigo = preg_replace('/^header\(.*$/m', '', $codigo);
$codigo = str_replace("require __DIR__ . '/almacen.php';", 'require ' . var_export(dirname(realpath($argv[1])) . '/almacen.php', true) . ';', $codigo);
$codigo = str_replace(["\$configFile = __DIR__ . '/config.php';", "if (!is_file(\$configFile))", "require \$configFile;"], ['', 'if (false)', ''], $codigo);
eval($codigo);
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$cargar = function () use ($argv) { return json_decode(file_get_contents($argv[2])); };
$aplicar = function ($d, $ops) { try { return aplicarAgente($d, json_decode(json_encode($ops))); } catch (Exception $e) { return 'ERROR: ' . $e->getMessage(); } };

$d = $cargar()->data;
$antes = (int) $d->seq->lead;
$r = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'ACME', 'servicio' => 'CCTV']]);
$ok(is_string($r) && strpos($r, 'Indica «nombre»') !== false, 'sin nombre, Joan no crea otro prospecto en un cliente que ya tiene abiertos');
$d = $cargar()->data;
// Contacto efectivo (17-11-2026): sin contacto, o con uno sin correo, teléfono ni LinkedIn, no hay prospecto
$r0 = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'ACME', 'nombre' => 'Control de acceso casa matriz', 'servicio' => 'Control de acceso']]);
$ok(is_string($r0) && strpos($r0, 'correo, teléfono o LinkedIn') !== false && strpos($r0, 'plan_objetivo') !== false, 'sin contacto no crea el prospecto y sugiere el plan');
$d = $cargar()->data;
$r0 = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'ACME', 'nombre' => 'Control de acceso casa matriz', 'contacto' => ['nombre' => 'Pedro Sin Datos', 'cargo' => 'Jefe']]]);
$ok(is_string($r0) && strpos($r0, 'no tiene correo, teléfono ni LinkedIn') !== false, 'con un contacto sin datos tampoco');
$d = $cargar()->data;
$r0 = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'ACME', 'nombre' => 'Control de acceso casa matriz', 'contacto' => ['nombre' => 'Pedro Lin', 'linkedin' => 'buscar en linkedin']]]);
$ok(is_string($r0) && strpos($r0, 'link del perfil') !== false, 'un LinkedIn que no es link se rechaza');
$d = $cargar()->data;
$r = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'ACME', 'nombre' => 'Control de acceso casa matriz', 'servicio' => 'Control de acceso', 'contacto' => ['nombre' => 'Pedro Lin', 'cargo' => 'Jefe de seguridad', 'linkedin' => 'https://www.linkedin.com/in/pedro-lin']]]);
$kP = null; foreach ($d->contacts as $k) if ($k->firstName === 'Pedro') $kP = $k;
$ok($kP && strpos($kP->notes, 'linkedin.com/in/pedro-lin') !== false, 'con LinkedIn sí: el link queda en las notas del contacto');
$esperado = 'PR-' . date('Y') . '-' . str_pad((string) ($antes + 1), 4, '0', STR_PAD_LEFT);
$ok(is_array($r) && strpos($r[0], $esperado) !== false, "con nombre lo crea con el código siguiente ($esperado)");
$r2 = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'ACME', 'nombre' => 'control de acceso casa matriz']]);
$ok(is_string($r2) && strpos($r2, 'ya existe el prospecto') !== false, 'no duplica un prospecto con el mismo nombre en el mismo cliente');
$r3 = $aplicar($d, [['tipo' => 'actualizar_prospecto', 'prospecto' => strtolower($esperado), 'etapa' => 'Contactado']]);
$ok(is_array($r3) && strpos($r3[0], $esperado) !== false, 'actualiza un prospecto buscándolo por su código PR');
$r4 = $aplicar($d, [['tipo' => 'actividad', 'prospecto' => $esperado, 'tipo_actividad' => 'Llamada', 'descripcion' => 'Primer contacto']]);
$ok(is_array($r4) && strpos($r4[0], 'Prospecto ' . $esperado) !== false, 'registra una actividad en el prospecto por código');
// Contacto existente: Joan completa lo que falta, nunca reemplaza
$rc = $aplicar($d, [['tipo' => 'contacto', 'empresa' => 'ACME', 'nombre' => 'Pedro Lin', 'email' => 'pedro@acme.cl', 'telefono' => '+56 9 1111 2222']]);
$ok(is_array($rc) && strpos($rc[0], 'Contacto completado') !== false && $kP->email === 'pedro@acme.cl' && $kP->phone === '+56 9 1111 2222', 'un contacto existente se completa con correo y teléfono');
$rc = $aplicar($d, [['tipo' => 'contacto', 'empresa' => 'ACME', 'nombre' => 'Pedro Lin', 'email' => 'otro@acme.cl']]);
$ok(is_string($rc) && $kP->email === 'pedro@acme.cl', 'pero no reemplaza un correo que ya tenía');
// Planes: necesidad y contacto identificado (aunque no tenga datos)
$rp = $aplicar($d, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Parque Nuevo SpA', 'necesidad' => 'CCTV y cerco eléctrico en el parque', 'contacto' => ['nombre' => 'Rosa Legal', 'cargo' => 'Representante legal']]]);
$pl = null; foreach ($d->plans as $p) if (isset($p->week) && $p->week === date('W') . '-' . substr(date('o'), 2)) $pl = $p;
$tg = null; foreach ($pl->targets as $t) if (isset($t->need) && $t->need === 'CCTV y cerco eléctrico en el parque') $tg = $t;
$kR = null; foreach ($d->contacts as $k) if ($k->firstName === 'Rosa') $kR = $k;
$ok(is_array($rp) && $tg && $kR && $tg->contactIds === [$kR->id], 'plan_objetivo guarda la necesidad y el contacto identificado');
// Operaciones separadas: un prospecto por contacto y operación
$ro = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'Codelco', 'industria' => 'Minería', 'nombre' => 'Control de acceso Andina', 'contacto' => ['nombre' => 'Ana Andina', 'cargo' => 'Jefa de Seguridad', 'email' => 'ana@codelco.cl', 'operacion' => 'División Andina']]]);
$cod = null; foreach ($d->companies as $c) if ($c->legalName === 'Codelco') $cod = $c;
$lA = end($d->leads); $sA = null; foreach ($cod->sites as $x) if ($x->name === 'División Andina') $sA = $x;
$ok(is_array($ro) && $sA && $lA->siteId === $sA->id, 'la operación del contacto se crea como sitio y queda en el prospecto');
$ro = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'Codelco', 'nombre' => 'CCTV El Teniente', 'contacto' => ['nombre' => 'Tito Teniente', 'telefono' => '+56 9 3333 4444', 'operacion' => 'División El Teniente']]]);
$ok(is_array($ro) && count(array_filter($d->leads, function ($l) use ($cod) { return $l->companyId === $cod->id; })) === 2, 'la misma empresa puede tener un prospecto por operación y contacto');
$ro = $aplicar($d, [['tipo' => 'prospecto', 'empresa' => 'Codelco', 'nombre' => 'Otro Andina', 'contacto' => ['nombre' => 'Ana Andina']]]);
$ok(is_string($ro) && strpos($ro, 'ya es el contacto del prospecto abierto') !== false, 'pero no dos prospectos abiertos para el mismo contacto');
$lT = end($d->leads);
$rq = $aplicar($d, [['tipo' => 'actualizar_prospecto', 'prospecto' => $lT->code, 'etapa' => 'Calificación', 'decisor' => 'Tito Teniente', 'plazo' => '3 a 6 meses']]);
$ok(is_array($rq) && $lT->stage === 'Calificación' && $lT->qualIncompleta === true && strpos($rq[0], 'calificación (incompleta)') !== false, 'Joan puede calificar; sin presupuesto queda incompleta');
$rq = $aplicar($d, [['tipo' => 'actualizar_prospecto', 'prospecto' => $lT->code, 'presupuesto' => 'Contrato actual vence pronto']]);
$ok(is_array($rq) && $lT->qualIncompleta === false, 'y al completar el presupuesto queda completa');
$rsm = resumenAgente((object) ['rev' => 1, 'savedAt' => 0, 'data' => $d], null);
$ok((bool) array_filter($rsm['prospectos_abiertos'], function ($p) { return $p['operacion'] === 'División El Teniente'; }), 'el resumen entrega la operación de cada prospecto');
$det = 'SEIA exp. 1: Parque X. Titular: tel. +56977074406, e-mail a@b.cl, Apoquindo 4660. Ficha: https://seia.sea.gob.cl/x (DIA, En Calificación)';
$rn = $aplicar($d, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Parque Nuevo SpA', 'nota' => $det, 'solo_existente' => true]]);
$ok(is_array($rn) && $tg->note === $det && strpos($rn[0], 'observación') !== false, 'una recarga completa la observación de una empresa que ya está en el plan');
$nEmp = count($d->companies);
$rn = $aplicar($d, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Empresa Que No Está SpA', 'nota' => $det, 'solo_existente' => true]]);
$ok(is_array($rn) && strpos($rn[0], 'no estaba') !== false && count($d->companies) === $nEmp, 'con solo_existente no agrega empresas nuevas');
$tg->status = 'Buscar contacto'; $pl->status = 'Activo';
$res = resumenAgente((object) ['rev' => 1, 'savedAt' => 0, 'data' => $d], null);
$pr = array_values(array_filter($res['planes'], function ($x) use ($pl) { return $x['plan'] === $pl->name; }));
$ok($pr && $pr[0]['buscando_contacto'] >= 1 && $pr[0]['por_activar'] >= 1 && is_int($pr[0]['dias_habiles_restantes']), 'el resumen trae el plan de la semana con días hábiles restantes y empresas por activar');
$ok((bool) array_filter($res['empresas_buscar_contacto'], function ($x) { return $x['empresa'] === 'Parque Nuevo SpA' && $x['necesidad'] === 'CCTV y cerco eléctrico en el parque' && strpos((string) $x['contacto_identificado'], 'Rosa Legal') === 0; }), 'el resumen lista las empresas que esperan contacto, con necesidad y contacto identificado');
$cods = array_column($res['prospectos_abiertos'], 'codigo');
$ok(in_array($esperado, $cods, true) && !array_filter($cods, function ($c) { return !preg_match('/^PR-\d{4}-\d{4}$/', $c); }), 'el resumen entrega el código PR de cada prospecto abierto');
$ok((bool) array_filter($res['prospectos_abiertos'], function ($p) { return $p['nombre'] === 'Control de acceso casa matriz'; }), 'y su nombre');
$etq = array_column($res['oportunidades_abiertas'], 'etapa');
$ok($etq && !array_filter($etq, function ($e) { return !preg_match('/^(Pipeline|Upside|Forecast|Booking) \(/', $e); }), 'las etapas de oportunidad llegan con su significado (p. ej. «Pipeline (oferta en desarrollo)»)');
$u = $cargar()->data; foreach ($u->opps as $o) if ($o->status === 'abierta') { $o->stage = 'seguimiento'; $o->stageEnteredAt = date('Y-m-d', strtotime('-9 days')); }
$ru = resumenAgente((object) ['rev' => 1, 'savedAt' => 0, 'data' => $u], null);
$ok(count($ru['ofertas_vigentes']) >= 1 && $ru['ofertas_vigentes'][0]['estado'] === 'Upside (oferta entregada)' && preg_match('/^CM-/', $ru['ofertas_vigentes'][0]['codigo']), 'las ofertas vigentes de Joan son las oportunidades en Upside, con su código CM');
$t = $cargar()->data; $nt = count($t->tasks);
$rt = $aplicar($t, [['tipo' => 'proxima_accion', 'oportunidad' => 'CM-261112-09', 'accion' => 'Enviar aclaración', 'fecha' => '2026-11-20']]);
$opT = null; foreach ($t->opps as $o) if ($o->code === 'CM-261112-09') $opT = $o;
$ok(is_array($rt) && count($t->tasks) === $nt + 1 && end($t->tasks)->entityId === $opT->id && end($t->tasks)->title === 'Enviar aclaración', 'en una oportunidad, la próxima acción de Joan se registra como tarea');
$rs = resumenAgente((object) ['rev' => 1, 'savedAt' => 0, 'data' => $t], null);
$fila = array_values(array_filter($rs['oportunidades_abiertas'], function ($o) { return $o['codigo'] === 'CM-261112-09'; }))[0];
$ok(!empty($fila['proxima_tarea']) && !empty($fila['fecha_proxima_tarea']) && !in_array('sin tarea pendiente', $fila['alertas'], true), 'el resumen muestra la próxima tarea de la oportunidad');
// Datos antiguos sin códigos: Joan los asigna con la misma regla que la pantalla
$v = $cargar()->data; foreach ($v->leads as $l) unset($l->code); unset($v->seq->lead);
$aplicar($v, [['tipo' => 'tarea', 'titulo' => 'x', 'vence' => '2026-12-01']]);
$ok(!array_filter($v->leads, function ($l) { return empty($l->code); }) && $v->seq->lead === count($v->leads), 'con datos antiguos sin código, asigna códigos PR a todos antes de escribir');
// Planes semanales (16-11-2026): un solo plan por semana ISO NN-YY con todas las verticales
$p = $cargar()->data; $p->plans = [];
$semHoy = date('W') . '-' . substr(date('o'), 2);
$rp = $aplicar($p, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Solar Norte SpA', 'origen' => "Prospección semanal $semHoy", 'nota' => 'PFV 200 MW']]);
$ok(is_array($rp) && count($p->plans) === 1 && $p->plans[0]->week === $semHoy && $p->plans[0]->name === "Plan semana {$semHoy}" && $p->plans[0]->vertical === 'Todas', "sin semana, crea «Plan semana {$semHoy}» con todas las verticales");
$aplicar($p, [['tipo' => 'plan_objetivo', 'vertical' => 'Minería', 'empresa' => 'Minera Uno']]);
$rr = $aplicar($p, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Solar Norte SpA']]);
$mu = null; foreach ($p->companies as $c) if ($c->legalName === 'Minera Uno') $mu = $c;
$ok(count($p->plans) === 1 && count($p->plans[0]->targets) === 2 && is_array($rr) && strpos($rr[0], 'ya estaba') !== false && $mu && $mu->industry === 'Minería', 'otra vertical va al mismo plan de la semana (la empresa queda en su vertical) y no repite empresas');
$aplicar($p, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Solar Norte SpA', 'semana' => '01-27']]);
$w = array_values(array_filter($p->plans, function ($x) { return $x->week === '01-27'; }));
$ok(count($p->plans) === 2 && $w && $w[0]->month === '2027-01', 'otra semana crea su propio plan (01-27 parte el lunes 4 de enero de 2027)');
$w53 = $aplicar($p, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'X', 'semana' => '53-26']]);
$ok(is_array($w53) && end($p->plans)->month === '2026-12', 'la semana 53-26 existe (lunes 28-12-2026)');
foreach (['54-26', '00-26', '53-27', '2026-11', '7-26'] as $mala) { $rm = $aplicar($cargar()->data, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'X', 'semana' => $mala]]); $ok(is_string($rm) && strpos($rm, 'semana inválida') !== false, "rechaza la semana «{$mala}»"); }
$viejo = $cargar()->data; $viejo->plans = [(object) ['id' => 'pv', 'name' => 'Plan Energía noviembre 2026', 'vertical' => 'Energía', 'month' => date('Y-m'), 'status' => 'Activo', 'ownerId' => 'u1', 'targets' => []]];
$aplicar($viejo, [['tipo' => 'plan_objetivo', 'vertical' => 'Energía', 'empresa' => 'Solar Norte SpA']]);
$ok(count($viejo->plans) === 2 && count($viejo->plans[0]->targets) === 0, 'un plan mensual antiguo no se toca: la carga va al plan semanal');
exit($fallas ? 1 : 0);
