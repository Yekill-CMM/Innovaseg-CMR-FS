<?php
// Permisos por rol y equipo (app/permisos.php), sin servidor: qué ve cada persona y qué puede cambiar.
// Uso: php permisos.test.php ../app
require realpath($argv[1]) . '/permisos.php';
require __DIR__ . '/equipo-prueba.php';
$fallas = 0;
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$DIR = directorioPrueba();
$ctx = function ($id) use ($DIR) { foreach ($DIR as $u) if ($u->id === $id) return permCtx($u, $DIR); };
$ids = function ($l) { $a = array_map(function ($x) { return $x->id; }, $l); sort($a); return $a; };
$por = function ($l, $id) { foreach ($l as $x) if ($x->id === $id) return $x; return null; };
$full = datosPrueba();
$TODOS = array_column(EQUIPO_PRUEBA, 0);

echo "1. Lo que ve cada persona (y que nada más sale del servidor)\n";
$malos = [];
foreach ($TODOS as $quien) {
    $v = permVista($full, $ctx($quien)); $json = json_encode($v, JSON_UNESCAPED_UNICODE);
    $alc = alcancePrueba($quien);
    foreach ($TODOS as $duenio) {
        $debe = in_array($duenio, $alc, true); $esta = strpos($json, "marca-$duenio\"") !== false || strpos($json, "marca-$duenio ") !== false || strpos($json, "marca-$duenio") !== false;
        if ($debe !== $esta) $malos[] = "$quien " . ($debe ? 'no ve' : 've') . " lo de $duenio";
    }
    $esp = function ($pref) use ($alc) { $a = array_map(function ($u) use ($pref) { return $pref . $u; }, $alc); sort($a); return $a; };
    foreach (['leads' => 'L_', 'opps' => 'O_', 'offers' => 'F_', 'invoices' => 'I_', 'recepciones' => 'R_', 'plans' => 'P_', 'tasks' => 'T_', 'audit' => 'AU_'] as $t => $p)
        if ($ids($v->$t) !== $esp($p)) $malos[] = "$quien: $t = " . implode(',', $ids($v->$t));
    $acts = $esp('A_'); if (in_array('eN1', $alc, true)) { $acts[] = 'A_sN_en_eN1'; sort($acts); }
    if ($ids($v->activities) !== $acts) $malos[] = "$quien: actividades = " . implode(',', $ids($v->activities));
    if ($ids($v->companies) !== ['C1', 'C2', 'C3'] || $ids($v->contacts) !== ['K1', 'K2']) $malos[] = "$quien no ve todas las empresas y contactos";
    $tg = array_keys(get_object_vars($v->config->targets)); sort($tg); $alcS = $alc; sort($alcS);
    if ($tg !== $alcS) $malos[] = "$quien ve las metas de " . implode(',', $tg);
    $todo = ALCANCE_PRUEBA[$quien] ?? null;
    if (($todo === '*') !== isset($v->config->metas) || ($todo === '*') !== isset($v->fxLast)) $malos[] = "$quien: meta del presupuesto o funnel completo mal filtrados";
    if ($v->currentUser !== $quien || count($v->users) !== 12) $malos[] = "$quien: usuario de la sesión o directorio";
}
$ok(!$malos, 'las 12 cuentas ven exactamente su alcance en prospectos, oportunidades, ofertas, facturas, actas, planes, tareas, actividades, auditoría y metas' . ($malos ? ': ' . implode(' | ', array_slice($malos, 0, 6)) : ''));
$v = permVista($full, $ctx('eN1'));
$ok((bool) $por($v->activities, 'A_sN_en_eN1') && !$por(permVista($full, $ctx('eN2'))->activities, 'A_sN_en_eN1'), 'una gestión de la subgerente sobre la oportunidad de Paula la ve Paula, no Tomás');
$ok(!isset($v->fxLast) && !isset($v->config->metas) && isset($v->config->uf), 'un ejecutivo no recibe el funnel completo ni la meta del presupuesto, sí los parámetros');
$aj = permAjustesVista($full->config, $ctx('eS2'));
$ok(array_keys(get_object_vars($aj->targets)) === ['eS2'], 'los ajustes del servidor también salen filtrados (metas)');

// Guarda como $quien: parte de su vista, aplica $f y devuelve [datos completos nuevos, avisos]
$guardar = function ($quien, $f, $base = null) use ($ctx, &$full) {
    $c = $ctx($quien); $v = permVista($base ?: $full, $c); $f($v);
    $n = permAplicar($base ?: $full, $v, $c);
    return [$n, $c->avisos];
};

echo "2. Ejecutivo\n";
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->opps, 'O_eN1')->prob = 70; });
$ok($por($n->opps, 'O_eN1')->prob === 70 && !$av, 'cambia su oportunidad (probabilidad)');
$ok(count($n->opps) === 12 && count($n->tasks) === 12 && count($n->leads) === 12, 'lo que no ve queda intacto en los datos completos');
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->opps, 'O_eN1')->ownerId = 'eN2'; });
$ok($por($n->opps, 'O_eN1')->ownerId === 'eN1' && count($av) === 1, 'no traspasa su oportunidad a otro ejecutivo (queda aviso)');
[$n, $av] = $guardar('eN1', function ($v) { $v->opps[] = (object) ['id' => 'O_eN2', 'name' => 'pisada', 'ownerId' => 'eN1']; });
$ok(strpos($por($n->opps, 'O_eN2')->name, 'marca-eN2') !== false && $av, 'no puede pisar un registro que no ve usando su id');
[$n, $av] = $guardar('eN1', function ($v) { $v->leads[] = (object) ['id' => 'Lnuevo', 'name' => 'Nuevo', 'ownerId' => 'eN1', 'companyId' => 'C1']; $v->leads[] = (object) ['id' => 'Lajeno', 'name' => 'Ajeno', 'ownerId' => 'eN2']; });
$ok((bool) $por($n->leads, 'Lnuevo') && !$por($n->leads, 'Lajeno') && count($av) === 1, 'crea prospectos a su nombre, no a nombre de otro');
[$n, $av] = $guardar('eN1', function ($v) { $v->tasks[] = (object) ['id' => 'Tx', 'title' => 'Para Tomás', 'assigneeId' => 'eN2', 'status' => 'Pendiente']; });
$ok(!$por($n->tasks, 'Tx') && $av, 'no asigna tareas a otra persona');
[$n, $av] = $guardar('eN1', function ($v) { $v->tasks = array_values(array_filter($v->tasks, function ($t) { return $t->id !== 'T_eN1'; })); });
$ok(!$por($n->tasks, 'T_eN1') && count($n->tasks) === 11 && !$av, 'elimina su tarea sin tocar las demás');
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->activities, 'A_sN_en_eN1')->desc = 'cambiada'; });
$ok($por($n->activities, 'A_sN_en_eN1')->desc === 'Revisión con vinculo-eN1' && $av, 've la gestión de su subgerente pero no la cambia');
[$n, $av] = $guardar('eN1', function ($v) { $v->activities = array_values(array_filter($v->activities, function ($a) { return $a->id !== 'A_sN_en_eN1'; })); });
$ok((bool) $por($n->activities, 'A_sN_en_eN1') && $av, 'ni la elimina');
[$n, $av] = $guardar('eN1', function ($v) { $v->invoices[] = (object) ['id' => 'Inueva', 'oppId' => 'O_eN1', 'name' => 'Factura 2']; $v->invoices[] = (object) ['id' => 'Iajena', 'oppId' => 'O_eN2', 'name' => 'Factura ajena']; });
$ok((bool) $por($n->invoices, 'Inueva') && !$por($n->invoices, 'Iajena'), 'registra facturas solo en sus oportunidades');

echo "3. Empresas y contactos (compartidos, con trazabilidad)\n";
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'C1')->tradeName = 'Eólica Norte (editada)'; $por($v->contacts, 'K1')->phone = '+56 9 1111 1111'; });
$ok($por($n->companies, 'C1')->tradeName === 'Eólica Norte (editada)' && $por($n->contacts, 'K1')->phone && !$av, 'un ejecutivo edita la empresa y el contacto que creó');
$ok($por($n->companies, 'C1')->updatedBy === 'eN1' && $por($n->contacts, 'K1')->updatedBy === 'eN1' && !empty($por($n->companies, 'C1')->updatedAt), 'queda anotado quién lo cambió y cuándo (updatedBy, updatedAt)');
$ok($por($n->contacts, 'K1')->createdBy === 'eN1' && !isset($por($n->companies, 'C2')->updatedBy), 'se conserva quién lo creó y lo que no cambió no se marca');
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'C2')->tradeName = 'Cambiada'; $por($v->contacts, 'K2')->lastName = 'Cambiado'; });
$ok($por($n->companies, 'C2')->tradeName === 'Puerto Austral' && $por($n->contacts, 'K2')->lastName === 'Gómez' && count($av) === 2, 'no cambia los datos de una empresa ni de un contacto que creó otra persona (p. ej. una gran corporación)');
[$n, $av] = $guardar('eN1', function ($v) {
    $v->contacts[] = (object) ['id' => 'K8', 'firstName' => 'Pedro', 'lastName' => 'Soto', 'companyId' => 'C2'];
    $v->leads[] = (object) ['id' => 'L8', 'name' => 'Nuevo en Puerto Austral', 'companyId' => 'C2', 'contactId' => 'K8', 'ownerId' => 'eN1'];
    $v->opps[] = (object) ['id' => 'O8', 'name' => 'Oportunidad en Puerto Austral', 'companyId' => 'C2', 'contactIds' => ['K8'], 'ownerId' => 'eN1'];
    foreach ($v->companies as $c) if ($c->id === 'C2') $c->sites[] = (object) ['id' => 'C2s9', 'name' => 'Terminal norte'];
});
$ok($por($n->contacts, 'K8')->createdBy === 'eN1' && (bool) $por($n->leads, 'L8') && (bool) $por($n->opps, 'O8') && !$av, 'en esa empresa sí agrega contactos, prospectos y oportunidades a su nombre');
$ok(count($por($n->companies, 'C2')->sites) === 1 && $por($n->companies, 'C2')->updatedBy === 'eN1', 'y sitios nuevos (queda anotado quién los agregó)');
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'C2')->sites[] = (object) ['id' => 'x', 'name' => 'y']; $por($v->companies, 'C2')->notes = 'z'; });
$ok(!$por($n->companies, 'C2')->sites && $av, 'pero no aprovecha un sitio nuevo para cambiar otros datos');
[$n, $av] = $guardar('sN', function ($v) use ($por) { $por($v->companies, 'C2')->tradeName = 'Puerto Austral SA'; $por($v->contacts, 'K2')->phone = '+56 2 2222 2222'; });
$ok($por($n->companies, 'C2')->tradeName === 'Puerto Austral SA' && $por($n->contacts, 'K2')->phone && $por($n->companies, 'C2')->updatedBy === 'sN' && !$av, 'el subgerente edita cualquier empresa y contacto');
[$n, $av] = $guardar('sN', function ($v) use ($por) { $c = $por($v->companies, 'C1'); $c->tradeName = 'x'; $c->createdBy = 'sN'; $c->updatedBy = 'g1'; $c->updatedAt = '2020-01-01'; });
$ok($por($n->companies, 'C1')->createdBy === 'eN1' && $por($n->companies, 'C1')->updatedBy === 'sN' && $por($n->companies, 'C1')->updatedAt !== '2020-01-01', 'la pantalla no puede falsificar los campos de trazabilidad');
[$n, $av] = $guardar('eN1', function ($v) use ($por) { $por($v->companies, 'C1')->ownerId = 'eN3'; });
$ok($por($n->companies, 'C1')->ownerId === 'eN1' && $av, 'no cambia el responsable de la empresa a otra persona');
[$n, $av] = $guardar('eN1', function ($v) { $v->companies[] = (object) ['id' => 'C9', 'legalName' => 'Nueva SpA', 'createdBy' => 'g1', 'updatedBy' => 'g1']; $v->contacts[] = (object) ['id' => 'K9', 'firstName' => 'Rosa', 'lastName' => 'Díaz', 'companyId' => 'C9']; });
$ok($por($n->companies, 'C9')->createdBy === 'eN1' && !isset($por($n->companies, 'C9')->updatedBy) && $por($n->contacts, 'K9')->createdBy === 'eN1', 'lo que crea queda a su nombre (createdBy lo pone el servidor, no la pantalla)');
[$n2, $av] = $guardar('eN1', function ($v) use ($por) { unset($por($v->companies, 'C9')->createdBy); $por($v->companies, 'C9')->city = 'Antofagasta'; }, $n);
$ok($por($n2->companies, 'C9')->createdBy === 'eN1' && $por($n2->companies, 'C9')->city === 'Antofagasta', 'y se conserva aunque la pantalla no lo mande: quien la creó la sigue editando');
[$n3, $av] = $guardar('eN2', function ($v) use ($por) { $por($v->companies, 'C9')->city = 'Calama'; }, $n2);
$ok($por($n3->companies, 'C9')->city === 'Antofagasta' && $av, 'otro ejecutivo ya no la cambia');
[$n, $av] = $guardar('eN1', function ($v) { $v->companies = array_values(array_filter($v->companies, function ($c) { return $c->id !== 'C1'; })); $v->contacts = array_values(array_filter($v->contacts, function ($c) { return $c->id !== 'K1'; })); });
$ok((bool) $por($n->companies, 'C1') && (bool) $por($n->contacts, 'K1') && count($av) === 2, 'ni quien la creó elimina una empresa o un contacto');
foreach (['sN', 'g1'] as $q) { [$n, $av] = $guardar($q, function ($v) { $v->companies = array_values(array_filter($v->companies, function ($c) { return $c->id !== 'C3'; })); }); if ($por($n->companies, 'C3') === null) break; }
$ok((bool) $por($n->companies, 'C3'), 'tampoco el subgerente ni el gerente');
[$n, $av] = $guardar('ad', function ($v) { $v->companies = array_values(array_filter($v->companies, function ($c) { return $c->id !== 'C1'; })); });
$ok(!$por($n->companies, 'C1') && !$av, 'solo el administrador (la regla de historial la revisa validarBajas aparte)');
[$n, $av] = $guardar('eN1', function ($v) { $v->fusiones[] = (object) ['id' => 'fu1', 'tabla' => 'companies', 'de' => 'C2', 'a' => 'C1']; });
$ok(!$n->fusiones, 'y solo el administrador fusiona');

echo "4. Subgerente\n";
[$n, $av] = $guardar('sN', function ($v) use ($por) { $por($v->opps, 'O_eN1')->ownerId = 'eN2'; $por($v->opps, 'O_eN3')->prob = 90; });
$ok($por($n->opps, 'O_eN1')->ownerId === 'eN2' && $por($n->opps, 'O_eN3')->prob === 90 && !$av, 'traspasa y edita oportunidades de su equipo');
[$n, $av] = $guardar('sN', function ($v) use ($por) { $por($v->opps, 'O_eN1')->ownerId = 'eS1'; });
$ok($por($n->opps, 'O_eN1')->ownerId === 'eN1' && $av, 'no las traspasa a otra subgerencia');
[$n, $av] = $guardar('sN', function ($v) { $v->tasks[] = (object) ['id' => 'Tsub', 'title' => 'Llamar', 'assigneeId' => 'eN3', 'status' => 'Pendiente']; $v->tasks[] = (object) ['id' => 'Tsur', 'title' => 'x', 'assigneeId' => 'eS1', 'status' => 'Pendiente']; });
$ok((bool) $por($n->tasks, 'Tsub') && !$por($n->tasks, 'Tsur'), 'asigna tareas a su equipo, no a otro');
[$n, $av] = $guardar('sN', function ($v) use ($por) { $por($v->companies, 'C2')->notes = 'ok'; });
$ok(($por($n->companies, 'C2')->notes ?? '') === 'ok', 'edita cualquier empresa');
[$n, $av] = $guardar('sN', function ($v) { $v->config->uf = 1; $v->mode = 'demo'; });
$ok($n->config->uf === 39000 && $n->mode === 'real', 'no cambia la configuración ni el modo');

echo "5. Gerente y administrador\n";
[$n, $av] = $guardar('g1', function ($v) use ($por) { $por($v->opps, 'O_eS4')->prob = 10; $por($v->leads, 'L_eN4')->ownerId = 'eS3'; $v->fxLast = (object) ['at' => '2026-11-25']; });
$ok($por($n->opps, 'O_eS4')->prob === 10 && $por($n->leads, 'L_eN4')->ownerId === 'eS3' && $n->fxLast->at === '2026-11-25' && !$av, 'el gerente cambia y traspasa en todas las subgerencias e importa el funnel');
[$n, $av] = $guardar('g1', function ($v) { $v->config->uf = 1; });
$ok($n->config->uf === 39000, 'la configuración es de solo lectura para el gerente');
[$n, $av] = $guardar('ad', function ($v) { $v->config->uf = 41000; $v->config->targets->eN1 = 5; });
$ok($n->config->uf === 41000 && $n->config->targets->eN1 === 5 && count((array) $n->config->targets) === 12, 'el administrador cambia la configuración y las metas');

echo "6. Auditoría, contadores y orden\n";
[$n, $av] = $guardar('eN1', function ($v) { array_unshift($v->audit, (object) ['id' => 'AUn', 'userId' => 'g1', 'entity' => 'x']); array_pop($v->audit); });
$ok($por($n->audit, 'AUn')->userId === 'eN1' && count($n->audit) === 13 && $n->audit[0]->id === 'AUn', 'la auditoría solo suma movimientos, firmados por quien guarda (no se borran ni se falsifican)');
[$n, $av] = $guardar('eN1', function ($v) { $v->seq->opp = 4; $v->seq->lead = 25; });
$ok($n->seq->opp === 10 && $n->seq->lead === 25, 'los contadores de códigos nunca retroceden');
[$n, $av] = $guardar('eN1', function ($v) { array_unshift($v->tasks, (object) ['id' => 'Tprimera', 'title' => 'a', 'assigneeId' => 'eN1', 'status' => 'Pendiente']); });
$ok($n->tasks[0]->id === 'Tprimera' && $ids($n->tasks) === $ids(array_merge($full->tasks, [(object) ['id' => 'Tprimera']])), 'un alta al inicio de la lista queda al inicio, sin mover lo demás');
[$n, $av] = $guardar('eN1', function ($v) { unset($v->opps); $v->users = []; $v->currentUser = 'ad'; });
$ok(count($n->opps) === 12 && !isset($n->users) && !isset($n->currentUser), 'una lista que no viene queda igual; los usuarios y la persona de la sesión nunca se guardan');
$err = '';
try { $guardar('eN1', function ($v) { $v->leads[] = (object) ['name' => 'sin id']; }); } catch (PermRechazo $e) { $err = $e->getMessage(); }
$ok($err !== '', 'datos mal formados (registro sin id) se rechazan: «' . $err . '»');

echo "7. Historial y recuperación\n";
$ok(permVeRegistro($ctx('eN1'), $full, 'opps', $por($full->opps, 'O_eN1')) && !permVeRegistro($ctx('eN1'), $full, 'opps', $por($full->opps, 'O_eN2')), 'el historial de un registro solo para quien lo ve');
$ok(!permVeRegistro($ctx('eN1'), $full, 'doc', (object) []) && permVeRegistro($ctx('g1'), $full, 'doc', (object) []), 'el historial de la configuración, solo gerente y administrador');
$ok(permRecupera($ctx('sN'), $full, 'leads', $por($full->leads, 'L_eN2')) && !permRecupera($ctx('sN'), $full, 'leads', $por($full->leads, 'L_eS2')), 'el subgerente recupera lo eliminado de su equipo, no de otro');
$ok(!permRecupera($ctx('eN1'), $full, 'contacts', $por($full->contacts, 'K1')) && permRecupera($ctx('ad'), $full, 'contacts', $por($full->contacts, 'K1')), 'una empresa o un contacto eliminado lo recupera solo el administrador');

exit($fallas ? 1 : 0);
