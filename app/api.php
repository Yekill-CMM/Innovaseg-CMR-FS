<?php
/* =========================================================================
   CRM FS - API del servidor (multiusuario)
   Cuentas y sesión (cuentas.php):
   - POST ?accion=entrar         ingreso en pasos: usuario y clave, clave propia si la actual es
                                 temporal, segundo factor (código de 6 dígitos). Deja la cookie de sesión.
   - POST ?accion=salir          cierra la sesión
   - GET  ?accion=sesion         quién es la persona de la sesión
   - POST ?accion=cambiar-clave  {actual, nueva}
   - GET/POST ?accion=usuarios   administración de cuentas y traspaso de cartera (solo administrador)
   Datos (siempre con los permisos de la persona: permisos.php):
   - GET  ?accion=leer           lo que la persona puede ver {rev, savedAt, data, yo, usuarios}
   - GET  ?accion=rev            solo la revisión actual {rev, savedAt}
   - POST {baseRev, cambios}     guardado registro por registro (guardado.php): solo lo que cambió, con la
                                 versión que tenía la pantalla de cada registro. Lo que nadie más tocó se aplica;
                                 si otra persona cambió otros campos del mismo registro, se juntan; si cambió los
                                 mismos, queda la del servidor y vuelve en {conflictos}. Después pasa por los
                                 permisos de siempre. Responde {rev, registros, doc} con la versión final de lo
                                 que tocó, o {rev, data} con la vista completa si otra persona guardó entremedio,
                                 hubo conflictos o avisos. Las oportunidades nuevas reciben su código OP aquí.
   - POST {baseRev, data}        copia completa (iniciar, restaurar o subir cambios hechos sin conexión): se
                                 compara con lo que la persona veía y cada alta, cambio y baja se revisa antes
                                 de aplicarla. Si baseRev no coincide con la revisión del servidor responde 409
                                 con la vista actual y no escribe nada. Responde {rev, data} (y {avisos}).
   - POST ?accion=ajustes        guarda {ajustes} (parámetros de configuración; solo administrador)
   - GET  ?accion=historial | eliminados, POST ?accion=restaurar: dentro del alcance de la persona
   - GET  ?accion=agente, POST ?accion=agente-escribir: para un programa con la clave del agente
     (CRM_CLAVE_AGENTE), que actúa con los permisos de la cuenta CRM_AGENTE_USUARIO.
   Toda petición de la pantalla trae la cabecera X-CRM: 1 y la cookie de sesión (HttpOnly, SameSite=Strict).
   Los datos se guardan en la base SQLite datos/crm.db, con la copia JSON de siempre en
   datos/crm.json.php y respaldos en datos/historial. Las cuentas van en datos/cuentas.db. Los
   parámetros de configuración van aparte, en datos/ajustes.json.php.
   ========================================================================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('America/Santiago');

require __DIR__ . '/almacen.php';
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) responder(503, ['error' => 'Falta el archivo config.php.']);
require $configFile;

$DIR_DATOS = __DIR__ . '/datos';
$ARCHIVO   = $DIR_DATOS . '/crm.json.php';
$HISTORIAL = $DIR_DATOS . '/historial';
$CABECERA  = "<?php exit; ?>\n";   // impide leer el archivo aunque se llame directo
$MAX_HISTORIAL = 72;
$AJUSTES   = $DIR_DATOS . '/ajustes.json.php';

function responder($codigo, $cuerpo) {
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function leerDatos($archivo, $cabecera) {
    $vacio = (object) ['rev' => 0, 'savedAt' => 0, 'data' => null];
    if (!is_file($archivo)) return $vacio;
    $raw = file_get_contents($archivo);
    if ($raw === false) return $vacio;
    if (strpos($raw, $cabecera) === 0) $raw = substr($raw, strlen($cabecera));
    $j = json_decode($raw);   // como objetos, para conservar {} vacíos
    return is_object($j) ? $j : $vacio;
}

// Respaldo en el servidor: como máximo uno por hora
function respaldoHora($ARCHIVO, $HISTORIAL, $MAX_HISTORIAL) {
    if (!is_file($ARCHIVO)) return;
    if (!is_dir($HISTORIAL)) @mkdir($HISTORIAL, 0750, true);
    $copias = glob($HISTORIAL . '/crm-*.json.php') ?: [];
    sort($copias);
    $ultima = end($copias);
    if (!$ultima || filemtime($ultima) < time() - 3600) {
        @copy($ARCHIVO, $HISTORIAL . '/crm-' . date('Ymd-His') . '.json.php');
        $copias = glob($HISTORIAL . '/crm-*.json.php') ?: [];
        sort($copias);
        while (count($copias) > $MAX_HISTORIAL) @unlink(array_shift($copias));
    }
}

function leerAjustes($archivo, $cabecera) {
    if (!is_file($archivo)) return null;
    $raw = file_get_contents($archivo);
    if ($raw === false) return null;
    if (strpos($raw, $cabecera) === 0) $raw = substr($raw, strlen($cabecera));
    $j = json_decode($raw);
    return is_object($j) ? $j : null;
}

// Resumen para el agente: lo mínimo para revisar el día sin leer todo el CRM
// Correo del contacto para el resumen del agente (Joan lo usa para armar invitaciones de Outlook)
function mailDe($k) { return !empty($k->email) ? ' <' . $k->email . '>' : ''; }

function resumenAgente($actual, $ajustes) {
    $d = $actual->data;
    if (!$d) return ['error' => 'El CRM aún no tiene datos.'];
    $hoy = date('Y-m-d');
    $dias = function ($f) use ($hoy) { return $f ? (int) round((strtotime($hoy) - strtotime(substr($f, 0, 10))) / 86400) : null; };
    $cfg = json_decode(json_encode(isset($d->config) ? $d->config : new stdClass()), true);
    if ($ajustes) $cfg = array_replace_recursive($cfg ?: [], json_decode(json_encode($ajustes), true));
    // Política comercial: la oportunidad es una oferta con código CM. 'ingenieria' (En Desarrollo) ya no se usa y se informa como Pipeline.
    $etapas = ['identificada' => 'Identificada', 'calificada' => 'Calificada', 'levantamiento' => 'Levantamiento', 'ingenieria' => 'Pipeline (oferta en desarrollo)',
               'oferta' => 'Pipeline (oferta en desarrollo)', 'seguimiento' => 'Upside (oferta entregada)', 'negociacion' => 'Forecast (adjudicada verbalmente)', 'adjudicada' => 'Booking (cerrada con contrato)'];
    $empresas = []; foreach ((array) $d->companies as $c) $empresas[$c->id] = $c->tradeName ?: $c->legalName;
    $contactos = []; foreach ((array) $d->contacts as $k) $contactos[$k->id] = $k;
    $opps = []; foreach ((array) $d->opps as $o) $opps[$o->id] = $o;
    $ofertaDeOpp = []; foreach ((array) (isset($d->offers) ? $d->offers : []) as $of) $ofertaDeOpp[$of->id] = $of->oppId;
    $acts = [];   // actividades por oportunidad (las de sus ofertas cuentan para la oportunidad)
    foreach ((array) $d->activities as $a) {
        $oid = $a->entityType === 'opp' ? $a->entityId : ($a->entityType === 'offer' && isset($ofertaDeOpp[$a->entityId]) ? $ofertaDeOpp[$a->entityId] : null);
        // Las notas que deja el importador del funnel no son gestión comercial: no cuentan para «días sin actividad»
        $sys = !empty($a->sys) || ($a->type === 'Nota' && preg_match('/^(Funnel: |Actualización del funnel)/u', (string) $a->desc));
        if ($oid && $a->date <= $hoy && !$sys) $acts[$oid][] = $a;
    }
    $corta = function ($t, $n = 280) { $t = trim((string) $t); return mb_strlen($t) > $n ? mb_substr($t, 0, $n) . '…' : $t; };
    $sinAct0 = isset($cfg['oppNoAct']) ? $cfg['oppNoAct'] : ['warn' => 10, 'risk' => 20];
    $estancada0 = isset($cfg['stalledDays']) ? $cfg['stalledDays'] : 30;
    $sinAct = $sinAct0;
    // Valor de la oportunidad: «Proyección» del Excel; si no, el booking (habilitación + mensual × meses desde la primera facturación hasta diciembre; One Shot: el monto) y, en las antiguas, único + mensual × plazo
    $valor = function ($o) { return !empty($o->fx->proj) && (float) $o->fx->proj > 0 ? (float) $o->fx->proj : (!empty($o->primeraFact) ? (($o->tipo ?? '') === 'One Shot' ? (float) $o->oneTime : (float) $o->oneTime + (float) $o->monthly * max(0, 13 - (int) substr($o->primeraFact, 5, 2))) : (float) $o->oneTime + (float) $o->monthly * (float) $o->term); };
    // En una oportunidad solo se mira el estatus (Excel) y se gestionan tareas: su próxima acción es su próxima tarea pendiente
    $tareasOpp = [];
    foreach ((array) (isset($d->tasks) ? $d->tasks : []) as $t) if ($t->entityType === 'opp' && in_array($t->status, ['Pendiente', 'En curso'], true)) $tareasOpp[$t->entityId][] = $t;
    foreach ($tareasOpp as &$lt) usort($lt, function ($x, $y) { return strcmp($x->due, $y->due); }); unset($lt);
    $abiertas = [];
    foreach ($opps as $o) {
        if ($o->status !== 'abierta') continue;
        $lista = isset($acts[$o->id]) ? $acts[$o->id] : [];
        usort($lista, function ($x, $y) { return strcmp($y->date, $x->date); });
        $ultima = $lista ? $lista[0]->date : null;
        if (!empty($o->fx->ult) && $o->fx->ult <= $hoy && (!$ultima || $o->fx->ult > $ultima)) $ultima = $o->fx->ult;
        foreach ((array) (isset($d->tasks) ? $d->tasks : []) as $t) if ($t->entityType === 'opp' && $t->entityId === $o->id && $t->status === 'Completada' && !empty($t->completedAt) && $t->completedAt <= $hoy && (!$ultima || $t->completedAt > $ultima)) $ultima = $t->completedAt;
        if (!$ultima && !empty($o->fx->pres)) $ultima = $o->fx->pres;
        $sa = $dias($ultima ?: $o->createdAt); $de = $dias($o->stageEnteredAt);
        // Plazos por tipo de negocio (Configuración > Plazos por tipo); lo no definido toma los generales
        $tipo = !empty($o->tipo) ? $o->tipo : (!empty($o->fx->tipo) ? $o->fx->tipo : '');   // en el CRM FS lo elige quien crea la oportunidad
        $pl = ($tipo !== '' && isset($cfg['plazos'][$tipo]) && is_array($cfg['plazos'][$tipo])) ? $cfg['plazos'][$tipo] : [];
        $sinAct = ['warn' => !empty($pl['warn']) ? $pl['warn'] : $sinAct0['warn'], 'risk' => !empty($pl['risk']) ? $pl['risk'] : $sinAct0['risk']];
        $estancada = !empty($pl['stalled']) ? $pl['stalled'] : $estancada0;
        $alertas = [];
        if ($sa >= $sinAct['risk']) $alertas[] = "$sa días sin actividad (en riesgo)"; elseif ($sa >= $sinAct['warn']) $alertas[] = "$sa días sin actividad";
        if ($de >= $estancada) $alertas[] = "$de días en la misma etapa";
        if (!empty($o->closeDate) && $o->closeDate < $hoy) $alertas[] = 'fecha de cierre vencida';
        $pt = isset($tareasOpp[$o->id]) ? $tareasOpp[$o->id][0] : null;
        if (!$pt) $alertas[] = 'sin tarea pendiente';
        elseif ($pt->due < $hoy) $alertas[] = 'tarea vencida';
        if (!empty($o->fx->ausente)) $alertas[] = 'no vino en el funnel desde ' . $o->fx->ausente . ' (confirmar con Gerencia si sigue vigente)';
        $abiertas[] = [
            'codigo' => $o->code, 'en_excel' => !empty($o->fx), 'en_funnel' => !empty($o->fx) || !empty($o->crm), 'nombre' => $o->name, 'titulo_funnel' => isset($o->fx->title) ? $o->fx->title : null, 'empresa' => isset($empresas[$o->companyId]) ? $empresas[$o->companyId] : '',
            'etapa' => isset($etapas[$o->stage]) ? $etapas[$o->stage] : $o->stage, 'probabilidad' => $o->prob, 'moneda' => $o->currency,
            'tipo_negocio' => $tipo !== '' ? $tipo : null, 'valor_unico' => $o->oneTime, 'mensual' => $o->monthly, 'plazo_meses' => $o->term,
            'valor_total' => $valor($o), 'plazo_sin_gestion' => $sinAct['warn'],
            'fecha_cierre' => $o->closeDate, 'proxima_tarea' => $pt ? $pt->title : null, 'fecha_proxima_tarea' => $pt ? $pt->due : null,
            'dias_en_etapa' => $de, 'ultima_actividad' => $ultima, 'dias_sin_actividad' => $sa, 'alertas' => $alertas,
            'servicios' => isset($o->services) ? $o->services : [],
            'contactos' => array_values(array_filter(array_map(function ($id) use ($contactos) { if (!isset($contactos[$id])) return null; $k = $contactos[$id];
                return trim($k->firstName . ' ' . $k->lastName) . ($k->position ? ', ' . $k->position : '') . ' (' . $k->decisionRole . ')' . mailDe($k); }, isset($o->contactIds) ? $o->contactIds : []))),
            'ultimas_notas' => array_map(function ($a) use ($corta) { return ['fecha' => $a->date, 'tipo' => $a->type, 'detalle' => $corta($a->desc), 'resultado' => $corta($a->result)]; }, array_slice($lista, 0, 3)),
        ];
    }
    usort($abiertas, function ($x, $y) { return $y['valor_total'] <=> $x['valor_total']; });
    // Ofertas vigentes = oportunidades en Upside (oferta entregada). La oportunidad es la oferta; el módulo OF anterior
    // queda solo como historial en la pantalla. Días sin seguimiento: desde la entrega (Fecha presentación del funnel o
    // ingreso a Upside) o desde la última gestión posterior.
    $ofertas = [];
    foreach ($opps as $o) {
        if ($o->status !== 'abierta' || $o->stage !== 'seguimiento') continue;
        $ent = !empty($o->fx->pres) ? $o->fx->pres : (isset($o->stageEnteredAt) ? $o->stageEnteredAt : null);
        $ref = $ent;
        foreach (isset($acts[$o->id]) ? $acts[$o->id] : [] as $a) if ($a->date > $ref) $ref = $a->date;
        if (!empty($o->fx->ult) && $o->fx->ult <= $hoy && $o->fx->ult > $ref) $ref = $o->fx->ult;
        $ofertas[] = ['codigo' => $o->code, 'estado' => 'Upside (oferta entregada)', 'oportunidad' => $o->name, 'empresa' => isset($empresas[$o->companyId]) ? $empresas[$o->companyId] : '',
            'entregada' => $ent, 'dias_sin_seguimiento' => $ref ? $dias($ref) : null, 'valor_total' => $valor($o)];
    }
    // Prospectos abiertos (los que no pasaron a oportunidad ni se descartaron)
    $leads = []; foreach ((array) (isset($d->leads) ? $d->leads : []) as $l) $leads[$l->id] = $l;
    $actsLead = [];
    foreach ((array) $d->activities as $a) if ($a->entityType === 'lead' && $a->date <= $hoy) $actsLead[$a->entityId][] = $a;
    $prospectos = [];
    $empObjL = []; foreach ((array) $d->companies as $c) $empObjL[$c->id] = $c;
    foreach ($leads as $l) {
        if (in_array($l->stage, ['Oportunidad creada', 'No calificado'], true)) continue;
        $lista = isset($actsLead[$l->id]) ? $actsLead[$l->id] : [];
        usort($lista, function ($x, $y) { return strcmp($y->date, $x->date); });
        $sa = $dias($lista ? $lista[0]->date : (isset($l->createdAt) ? $l->createdAt : null));
        $alertas = [];
        if (!contactoEfectivo($k = (!empty($l->contactId) && isset($contactos[$l->contactId]) ? $contactos[$l->contactId] : null))) $alertas[] = 'sin contacto efectivo (correo, teléfono o LinkedIn)';
        if ($l->stage !== 'Nurturing') {
            if (empty($l->nextAction) || empty($l->nextActionDate)) $alertas[] = 'sin próxima acción';
            elseif ($l->nextActionDate < $hoy) $alertas[] = 'próxima acción atrasada';
            if ($sa !== null && $sa >= $sinAct0['warn']) $alertas[] = "$sa días sin actividad";
        }
        $k = !empty($l->contactId) && isset($contactos[$l->contactId]) ? $contactos[$l->contactId] : null;
        $prospectos[] = ['codigo' => !empty($l->code) ? $l->code : $l->id, 'id' => $l->id, 'nombre' => isset($l->name) ? $l->name : '', 'empresa' => isset($empresas[$l->companyId]) ? $empresas[$l->companyId] : '', 'etapa' => $l->stage,
            'potencial' => isset($l->potential) ? $l->potential : '', 'servicio' => isset($l->service) ? $l->service : '', 'problema' => $corta(isset($l->problem) ? $l->problem : '', 200),
            'origen' => isset($l->source) ? $l->source : '', 'contacto' => $k ? trim($k->firstName . ' ' . $k->lastName) . ($k->position ? ', ' . $k->position : '') . mailDe($k) : null, 'contacto_efectivo' => contactoEfectivo($k),
            'calificacion' => isset($l->qual) && is_object($l->qual) ? ['decisor' => !empty($l->qual->decisorId) && isset($contactos[$l->qual->decisorId]) ? trim($contactos[$l->qual->decisorId]->firstName . ' ' . $contactos[$l->qual->decisorId]->lastName) : null,
                'plazo' => isset($l->qual->plazo) ? $l->qual->plazo : '', 'presupuesto' => isset($l->qual->presupuesto) ? $l->qual->presupuesto : '', 'completa' => empty($l->qualIncompleta)] : null,
            'operacion' => operacionDe(isset($empObjL[$l->companyId]) ? $empObjL[$l->companyId] : null, isset($l->siteId) ? $l->siteId : ''),
            'proxima_accion' => isset($l->nextAction) ? $l->nextAction : '', 'fecha_proxima_accion' => isset($l->nextActionDate) ? $l->nextActionDate : '',
            'dias_sin_actividad' => $sa, 'alertas' => $alertas,
            'ultimas_notas' => array_map(function ($a) use ($corta) { return ['fecha' => $a->date, 'tipo' => $a->type, 'detalle' => $corta($a->desc, 160)]; }, array_slice($lista, 0, 2))];
    }
    // Empresas de planes activos que esperan un contacto efectivo para pasar a prospecto
    $empObj = []; foreach ((array) $d->companies as $c) $empObj[$c->id] = $c;
    $buscar = [];
    foreach ((array) (isset($d->plans) ? $d->plans : []) as $p) {
        if (!isset($p->status) || $p->status !== 'Activo') continue;
        foreach ((array) (isset($p->targets) ? $p->targets : []) as $t) {
            if (!isset($t->status) || $t->status !== 'Buscar contacto') continue;
            $ids = isset($t->contactIds) && is_array($t->contactIds) ? $t->contactIds : (!empty($t->contactId) ? [$t->contactId] : []);
            $ks = []; foreach ($ids as $id) if (isset($contactos[$id])) { $ki = $contactos[$id]; $ks[] = trim($ki->firstName . ' ' . $ki->lastName) . ($ki->position ? ', ' . $ki->position : '') . (!empty($ki->siteId) && isset($empObj[$t->companyId]) && operacionDe($empObj[$t->companyId], $ki->siteId) !== '' ? ' · ' . operacionDe($empObj[$t->companyId], $ki->siteId) : ''); }
            $buscar[] = ['empresa' => isset($empresas[$t->companyId]) ? $empresas[$t->companyId] : '', 'plan' => $p->name, 'necesidad' => isset($t->need) ? $t->need : '',
                'contacto_identificado' => $ks ? implode('; ', $ks) : null];
        }
    }
    // Planes de la semana actual y activos: tiempo (días hábiles) y empresas que faltan por activar
    $planes = [];
    $semHoy = date('W') . '-' . substr(date('o'), 2);
    foreach ((array) (isset($d->plans) ? $d->plans : []) as $p) {
        $esSem = isset($p->week) && $p->week === $semHoy;
        if (!$esSem && (!isset($p->status) || $p->status !== 'Activo')) continue;
        if (isset($p->status) && $p->status === 'Cerrado') continue;
        $vivos = array_filter((array) (isset($p->targets) ? $p->targets : []), function ($t) { return !isset($t->status) || $t->status !== 'Descartado'; });
        $pend = count(array_filter($vivos, function ($t) { return $t->status === 'Pendiente'; }));
        $busc = count(array_filter($vivos, function ($t) { return $t->status === 'Buscar contacto'; }));
        $quedan = null;
        if (!empty($p->week) && preg_match('/^(\d{2})-(\d{2})$/', $p->week, $mm)) {
            $lun = (new DateTime())->setISODate(2000 + (int) $mm[2], (int) $mm[1], 1)->format('Y-m-d');
            $vie = date('Y-m-d', strtotime($lun . ' +4 days'));
            $quedan = 0; for ($x = max($lun, $hoy); $x <= $vie; $x = date('Y-m-d', strtotime($x . ' +1 day'))) if ((int) date('N', strtotime($x)) <= 5) $quedan++;
        }
        $planes[] = ['plan' => $p->name, 'estado' => isset($p->status) ? $p->status : '', 'empresas' => count($vivos), 'por_activar' => $pend + $busc,
            'pendientes' => $pend, 'buscando_contacto' => $busc, 'dias_habiles_restantes' => $quedan];
    }
    $tareas = [];
    foreach ((array) (isset($d->tasks) ? $d->tasks : []) as $t) {
        if (!in_array($t->status, ['Pendiente', 'En curso'], true)) continue;
        $tareas[] = ['id' => $t->id, 'titulo' => $t->title, 'vence' => $t->due, 'prioridad' => isset($t->priority) ? $t->priority : '', 'atrasada' => $t->due < $hoy,
            'oportunidad' => $t->entityType === 'opp' && isset($opps[$t->entityId]) ? $opps[$t->entityId]->code . ' · ' . $opps[$t->entityId]->name : null,
            'prospecto' => $t->entityType === 'lead' && isset($leads[$t->entityId]) && isset($empresas[$leads[$t->entityId]->companyId]) ? (!empty($leads[$t->entityId]->code) ? $leads[$t->entityId]->code . ' · ' : '') . $empresas[$leads[$t->entityId]->companyId] : null];
    }
    usort($tareas, function ($x, $y) { return strcmp($x['vence'], $y['vence']); });
    return [
        'hoy' => $hoy, 'guardado' => $actual->savedAt, 'parametros' => $cfg,
        'totales' => ['abiertas' => count($abiertas), 'pipeline' => array_sum(array_column(array_filter($abiertas, function ($o) { return $o['en_funnel']; }), 'valor_total')),
                      'sin_tarea_pendiente' => count(array_filter($abiertas, function ($o) { return in_array('sin tarea pendiente', $o['alertas'], true); })),
                      'prospectos_abiertos' => count($prospectos)],
        'oportunidades_abiertas' => $abiertas, 'prospectos_abiertos' => $prospectos, 'empresas_buscar_contacto' => $buscar, 'planes' => $planes, 'ofertas_vigentes' => $ofertas, 'tareas_pendientes' => $tareas,
        'empresas' => array_values(array_map(function ($c) use ($d) {
            $ks = []; foreach ((array) $d->contacts as $k) if ($k->companyId === $c->id) $ks[] = trim($k->firstName . ' ' . $k->lastName) . ($k->position ? ', ' . $k->position : '') . (!empty($k->decisionRole) ? ' (' . $k->decisionRole . ')' : '') . (operacionDe($c, isset($k->siteId) ? $k->siteId : '') !== '' ? ' · ' . operacionDe($c, $k->siteId) : '') . mailDe($k);
            return ['nombre' => $c->tradeName ?: $c->legalName, 'industria' => isset($c->industry) ? $c->industry : '', 'relacion' => isset($c->relation) ? $c->relation : '', 'contactos' => $ks]; }, (array) $d->companies)),
        'etapas_prospecto' => array_values(array_diff($GLOBALS['ETAPAS_PROSPECTO'], ['Oportunidad creada'])),
    ];
}



$ETAPAS_CRM = ['identificada' => ['Identificada', 10], 'calificada' => ['Calificada', 20], 'levantamiento' => ['Levantamiento', 30],
    'oferta' => ['Pipeline', 50], 'seguimiento' => ['Upside', 60], 'negociacion' => ['Forecast', 80]];
$SERVICIOS_CRM = ['Guardias', 'Supervisión', 'CCTV', 'Control de acceso', 'Intrusión / alarmas', 'Protección perimetral', 'Analítica de video', 'LPR', 'Radares',
    'Monitoreo remoto 24/7', 'SOC', 'Comunicaciones', 'Integración de sistemas', 'Mantenimiento preventivo', 'Mantenimiento correctivo', 'Ingeniería / proyectos'];
$INDUSTRIAS_CRM = ['Energía', 'Minería', 'Industria', 'Logística', 'Centros de distribución', 'Data Centers', 'Puertos', 'Retail', 'Salud', 'Educación', 'Construcción',
    'Inmobiliario', 'Agroindustria', 'Smart Cities', 'Banca / Finanzas', 'Servicios', 'Sector público', 'Otros'];
$ETAPAS_PROSPECTO = ['Identificado', 'Investigación', 'Contacto inicial', 'Contactado', 'Reunión solicitada', 'Reunión realizada', 'Calificación', 'Levantamiento', 'Oportunidad creada', 'No calificado', 'Nurturing'];
$ROLES_CONTACTO = ['Decisor', 'Influenciador', 'Usuario', 'Comprador', 'Prescriptor', 'Técnico', 'Gerencia', 'Finanzas', 'Operaciones', 'Seguridad', 'Facility Management', 'Prevención de Riesgos', 'TI'];
function normTxt($s) {
    $s = mb_strtolower(trim((string) $s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}
// Acepta el id ('oferta') o el nombre ('Ingeniería / Solución', 'ingenieria')
function etapaId($v, $ETAPAS) {
    $n = normTxt($v);
    foreach ($ETAPAS as $id => $e) if ($n === $id || $n === normTxt($e[0]) || ($n !== '' && strpos(normTxt($e[0]), $n) === 0)) return $id;
    return null;
}

/* Cambios del agente. Cada op es un objeto con "tipo":
   Seguimiento (sobre oportunidades del funnel, prospectos o empresas):
   - actividad:       oportunidad (código) | prospecto (id) | empresa (nombre); tipo_actividad,
                      descripcion, [resultado], [fecha], [proxima_accion, fecha_proxima_accion]
                      (la próxima acción actualiza la oportunidad o el prospecto; no aplica a empresas)
   - proxima_accion:  oportunidad | prospecto, accion, fecha (en una oportunidad se registra como tarea: sobre las
                      oportunidades solo se gestionan tareas; el estatus viene del Excel)
   - tarea:           titulo, vence (AAAA-MM-DD), [prioridad Alta|Media|Baja], [oportunidad | prospecto]
   - completar_tarea: tarea (id de la tarea, viene en el resumen)
   Altas (solo crea; no edita empresas ni contactos existentes):
   - empresa:         nombre, [industria], [ciudad], [region], [sitio_web], [potencial Alto|Medio|Bajo], [notas]
   - contacto:        empresa (existente), nombre, [cargo], [email], [telefono], [linkedin], [operacion], [rol], [notas]
                      operacion = faena, división o planta donde trabaja (se crea como sitio de la empresa si no existe).
                      Si el contacto ya existe, solo completa correo, teléfono o LinkedIn que falten (nunca reemplaza).
                      El link de LinkedIn se guarda en las notas del contacto.
   - prospecto:       empresa (se busca por nombre; si no existe se crea, con [industria]), [nombre] (obligatorio si el
                      cliente ya tiene prospectos abiertos; un cliente puede tener varios). Se le asigna código PR-AAAA-NNNN.
                      contacto {nombre, [cargo], [email], [telefono], [linkedin], [operacion], [rol]} OBLIGATORIO y efectivo: con correo,
                      teléfono o LinkedIn (propio o ya registrado en el CRM). Sin eso, la empresa va a un plan (plan_objetivo).
                      [operacion] (faena, división o planta; por defecto la del contacto), [problema], [servicio], [origen],
                      [potencial], [etapa], [proxima_accion, fecha_proxima_accion]. La prospección va por contacto: en una empresa
                      con varias operaciones (Codelco, SQM…) cada contacto efectivo puede tener su prospecto (con «nombre» distinto).
   - actualizar_prospecto: prospecto (código PR o id) y cualquiera de: nombre, etapa, potencial, problema, servicio,
                      proxima_accion + fecha_proxima_accion, y la calificación: decisor (nombre de un contacto de la
                      empresa), plazo (Menos de 3 meses | 3 a 6 meses | 6 a 12 meses | Más de 12 meses), presupuesto
                      (Presupuesto aprobado | Contrato actual vence pronto | En preparación de presupuesto | Sin presupuesto aún).
                      Pasar a Calificación o Levantamiento sin esos tres datos se permite, pero queda «calificación incompleta».
   Planes de prospección (para cargas automáticas, p. ej. el reporte semanal del SEIA):
   - plan_objetivo:   vertical (industria del CRM), empresa (se busca por nombre; si no existe se crea),
                      [semana NN-YY (semana ISO), por defecto la actual], [origen], [nota], [necesidad] (del servicio),
                      [region], [ciudad], [notas], [solo_existente] (true: solo actualiza si la empresa ya está en ese plan),
                      [contacto {nombre, cargo, email, telefono, linkedin, operacion}] (contacto identificado;
                      se suma a los de la empresa en el plan: en empresas con varias operaciones puede haber uno por operación;
                      puede venir sin datos: al activar el plan la empresa queda en «Buscar contacto» hasta tenerlos)
                      Agrega la empresa como objetivo «Pendiente» del plan de esa semana (uno por semana, con
                      todas las verticales; la vertical se usa para la empresa si se crea); si el plan
                      no existe lo crea en Borrador (el guion se completa al abrir el CRM). Si la empresa ya
                      está en el plan, no hace nada. No crea prospectos ni tareas: eso ocurre al activar el plan.
   Las oportunidades se crean y gestionan en la pantalla del CRM (con su código OP): el agente no las crea
   ni cambia su nombre, etapa, probabilidad, montos o fecha de cierre.
   Todo o nada: si una op es inválida no se guarda ninguna. */
// Asigna código PR-AAAA-NNNN a los prospectos que aún no lo tienen (misma regla que index.html, versión 4 de datos)
function asegurarCodigosPR($d) {
    if (!isset($d->seq) || !is_object($d->seq)) $d->seq = new stdClass();
    $n = isset($d->seq->lead) ? (int) $d->seq->lead : 0;
    $ls = isset($d->leads) && is_array($d->leads) ? $d->leads : [];
    usort($ls, function ($a, $b) { return strcmp(isset($a->createdAt) ? $a->createdAt : '', isset($b->createdAt) ? $b->createdAt : ''); });
    foreach ($ls as $l) if (empty($l->code)) { $n++; $l->code = 'PR-' . substr(!empty($l->createdAt) ? $l->createdAt : date('Y-m-d'), 0, 4) . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT); }
    $d->seq->lead = $n;
}
function nuevoCodigoPR($d) {
    asegurarCodigosPR($d);
    $d->seq->lead = (int) $d->seq->lead + 1;
    return 'PR-' . date('Y') . '-' . str_pad((string) $d->seq->lead, 4, '0', STR_PAD_LEFT);
}
// Contacto efectivo (17-11-2026): correo, teléfono/WhatsApp o link de LinkedIn en las notas
// Nombre de la operación (sitio) de un registro con siteId
function operacionDe($c, $siteId) {
    if (!$c || empty($siteId) || !isset($c->sites) || !is_array($c->sites)) return '';
    foreach ($c->sites as $x) if (isset($x->id) && $x->id === $siteId) return (string) $x->name;
    return '';
}
function contactoEfectivo($k) {
    if (!$k) return false;
    foreach (['email', 'phone', 'whatsapp'] as $c) if (isset($k->$c) && trim((string) $k->$c) !== '') return true;
    return isset($k->notes) && preg_match('~linkedin\.com/\S+~i', (string) $k->notes) === 1;
}
function aplicarAgente($d, $ops) {
    global $SERVICIOS_CRM, $INDUSTRIAS_CRM, $ETAPAS_PROSPECTO, $ROLES_CONTACTO;
    $hoy = date('Y-m-d'); $hora = date('H:i');
    $TIPOS = ['Llamada', 'Email', 'WhatsApp', 'Reunión', 'Videollamada', 'Visita a terreno', 'Presentación', 'Envío de oferta', 'Negociación', 'Nota'];
    $POTENCIAL = ['Alto', 'Medio', 'Bajo'];
    $user = isset($d->currentUser) && $d->currentUser ? $d->currentUser : 'u1';
    $uid = function ($p) { return $p . base_convert((string) time(), 10, 36) . bin2hex(random_bytes(3)); };
    $fecha = function ($f) { return is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false; };
    $txt = function ($v, $n = 2000) { $v = trim((string) $v); return mb_substr($v, 0, $n); };
    $campo = function ($op, $k) { return isset($op->$k) ? $op->$k : ''; };
    if (!isset($d->audit) || !is_array($d->audit)) $d->audit = [];
    foreach (['tasks', 'activities', 'opps', 'companies', 'contacts', 'leads', 'plans'] as $k) if (!isset($d->$k) || !is_array($d->$k)) $d->$k = [];
    asegurarCodigosPR($d);
    $audit = function ($ent, $campo, $a, $b) use (&$d, $hoy, $hora, $user, $uid) {
        array_unshift($d->audit, (object) ['id' => $uid('au'), 'at' => "$hoy $hora", 'userId' => $user, 'entity' => $ent, 'field' => $campo . ' (Joan)', 'oldV' => (string) $a, 'newV' => (string) $b]);
        if (count($d->audit) > 400) $d->audit = array_slice($d->audit, 0, 400);
    };
    $nombreEmp = function ($c) { return $c->tradeName ?: $c->legalName; };
    $buscarOpp = function ($cod) use (&$d) {
        $cod = mb_strtolower(trim((string) $cod));
        if ($cod === '') return null;
        foreach ((array) $d->opps as $o) if (mb_strtolower($o->code) === $cod) return $o;
        return null;
    };
    $buscarLead = function ($id) use (&$d) {
        $id = trim((string) $id);
        if ($id === '') return null;
        foreach ((array) $d->leads as $l) if ($l->id === $id || (!empty($l->code) && strcasecmp($l->code, $id) === 0)) return $l;
        return null;
    };
    // Empresa por razón social o nombre comercial; después, por coincidencia al inicio del nombre
    $buscarEmpresa = function ($nom) use (&$d) {
        $ne = normTxt($nom);
        if ($ne === '') return null;
        foreach ($d->companies as $x) if (normTxt($x->legalName) === $ne || normTxt($x->tradeName) === $ne) return $x;
        foreach ($d->companies as $x) { $a = normTxt($x->tradeName ?: $x->legalName); if (strlen($ne) >= 4 && $a !== '' && (strpos($a, $ne) === 0 || strpos($ne, $a) === 0)) return $x; }
        return null;
    };
    $crearEmpresa = function ($nom, $op) use (&$d, $uid, $hoy, $user, $txt, $audit, $INDUSTRIAS_CRM, $POTENCIAL) {
        $ind = isset($op->industria) && in_array($op->industria, $INDUSTRIAS_CRM, true) ? $op->industria : 'Otros';
        $pot = isset($op->potencial) && in_array($op->potencial, $POTENCIAL, true) ? $op->potencial : 'Medio';
        $ciudad = $txt(isset($op->ciudad) ? $op->ciudad : '', 80);
        $c = (object) ['id' => $uid('c'), 'legalName' => $nom, 'tradeName' => $nom, 'rut' => '', 'industry' => $ind, 'region' => $txt(isset($op->region) ? $op->region : '', 80),
            'city' => $ciudad, 'relation' => 'Prospecto', 'ownerId' => $user, 'potential' => $pot, 'level' => 'B', 'source' => 'Joan (Telegram)',
            'website' => $txt(isset($op->sitio_web) ? $op->sitio_web : '', 200), 'size' => '', 'notes' => $txt(isset($op->notas) ? $op->notas : '', 1000), 'createdAt' => $hoy,
            'sites' => [(object) ['id' => $uid('s'), 'name' => 'Casa matriz', 'type' => 'Casa matriz', 'city' => $ciudad]]];
        $d->companies[] = $c;
        $audit('Empresa ' . $nom, 'Creación', '', '');
        return $c;
    };
    // Operación (sitio de la empresa: faena, división, planta); la crea si no existe. Devuelve el id o ''
    $sitioEn = function ($c, $nom) use ($uid, $txt, $audit, $nombreEmp) {
        $nom = $txt($nom, 120); if ($nom === '') return '';
        if (!isset($c->sites) || !is_array($c->sites)) $c->sites = [];
        foreach ($c->sites as $x) if (normTxt($x->name) === normTxt($nom)) return $x->id;
        $x = (object) ['id' => $uid('s'), 'name' => $nom, 'type' => 'Operación', 'city' => ''];
        $c->sites[] = $x; $audit('Empresa ' . $nombreEmp($c), 'Sitio', '', $nom);
        return $x->id;
    };
    // Devuelve [contacto, creado, completado]; si ya existe uno con ese nombre en la empresa, lo reutiliza y solo
    // completa correo, teléfono o LinkedIn que falten (nunca reemplaza un dato existente)
    $contactoEn = function ($c, $k, $n) use (&$d, $uid, $hoy, $txt, $audit, $ROLES_CONTACTO, $sitioEn) {
        $sid = $sitioEn($c, isset($k->operacion) ? $k->operacion : '');
        $cn = $txt(isset($k->nombre) ? $k->nombre : '', 120);
        if ($cn === '') throw new Exception("Cambio $n: el contacto necesita nombre.");
        $li = $txt(isset($k->linkedin) ? $k->linkedin : '', 300);
        if ($li !== '' && !preg_match('~linkedin\.com/\S+~i', $li)) throw new Exception("Cambio $n: el LinkedIn debe ser el link del perfil (linkedin.com/in/…).");
        foreach ($d->contacts as $x) if ($x->companyId === $c->id && normTxt($x->firstName . ' ' . $x->lastName) === normTxt($cn)) {
            $comp = [];
            $em = $txt(isset($k->email) ? $k->email : '', 120); $te = $txt(isset($k->telefono) ? $k->telefono : '', 40);
            if ($em !== '' && trim((string) (isset($x->email) ? $x->email : '')) === '') { $x->email = $em; $comp[] = 'correo'; }
            if ($te !== '' && trim((string) (isset($x->phone) ? $x->phone : '')) === '') { $x->phone = $te; $comp[] = 'teléfono'; }
            if ($li !== '' && stripos((string) (isset($x->notes) ? $x->notes : ''), $li) === false && !preg_match('~linkedin\.com/\S+~i', (string) (isset($x->notes) ? $x->notes : ''))) {
                $x->notes = trim((isset($x->notes) ? $x->notes : '') . "\nLinkedIn: $li"); $comp[] = 'LinkedIn'; }
            if ($sid !== '' && empty($x->siteId)) { $x->siteId = $sid; $comp[] = 'operación'; }
            if ($comp) $audit('Contacto ' . $cn, 'Datos completados', '', implode(', ', $comp));
            return [$x, false, $comp];
        }
        $rol = 'Influenciador';
        if (!empty($k->rol)) { $rol = null; foreach ($ROLES_CONTACTO as $R) if (normTxt($R) === normTxt($k->rol)) $rol = $R;
            if (!$rol) throw new Exception("Cambio $n: rol de contacto no válido. Usa: " . implode(', ', $ROLES_CONTACTO) . '.'); }
        $pp = preg_split('/\s+/', $cn);
        $x = (object) ['id' => $uid('k'), 'companyId' => $c->id, 'firstName' => array_shift($pp), 'lastName' => implode(' ', $pp), 'position' => $txt(isset($k->cargo) ? $k->cargo : '', 120),
            'area' => '', 'siteId' => $sid, 'decisionRole' => $rol, 'influence' => 3, 'relationship' => 'Fría', 'email' => $txt(isset($k->email) ? $k->email : '', 120),
            'phone' => $txt(isset($k->telefono) ? $k->telefono : '', 40), 'whatsapp' => '', 'notes' => trim($txt(isset($k->notas) ? $k->notas : '', 1000) . ($li !== '' ? "\nLinkedIn: $li" : '')), 'createdAt' => $hoy];
        $d->contacts[] = $x;
        $audit('Contacto ' . $cn, 'Creación', '', '');
        return [$x, true, []];
    };
    $servicio = function ($v, $n) use ($SERVICIOS_CRM) {
        if ($v === '' || $v === null) return '';
        foreach ($SERVICIOS_CRM as $S) if (normTxt($S) === normTxt($v) || strpos(normTxt($S), normTxt($v)) === 0) return $S;
        throw new Exception("Cambio $n: servicio no válido. Usa: " . implode(', ', $SERVICIOS_CRM) . '.');
    };
    $etapaProspecto = function ($v, $n) use ($ETAPAS_PROSPECTO) {
        foreach ($ETAPAS_PROSPECTO as $E) if (normTxt($E) === normTxt($v)) {
            if ($E === 'Oportunidad creada') throw new Exception("Cambio $n: las oportunidades se crean en la pantalla del CRM; Joan no marca prospectos como «Oportunidad creada».");
            return $E;
        }
        throw new Exception("Cambio $n: etapa de prospecto no válida. Usa: " . implode(', ', array_diff($ETAPAS_PROSPECTO, ['Oportunidad creada'])) . '.');
    };
    $leadAbierto = function ($l) { return !in_array($l->stage, ['Oportunidad creada', 'No calificado'], true); };
    // Destino de una actividad, tarea o próxima acción: oportunidad o prospecto
    $destino = function ($op, $n, $permiteEmpresa = false) use ($buscarOpp, $buscarLead, $buscarEmpresa, $nombreEmp) {
        if (!empty($op->oportunidad)) { $o = $buscarOpp($op->oportunidad); if (!$o) throw new Exception("Cambio $n: no existe la oportunidad «{$op->oportunidad}»."); return ['opp', $o, $o->code, $o->companyId]; }
        if (!empty($op->prospecto)) { $l = $buscarLead($op->prospecto); if (!$l) throw new Exception("Cambio $n: no existe el prospecto «{$op->prospecto}»."); return ['lead', $l, 'Prospecto ' . (!empty($l->code) ? $l->code : $l->id), $l->companyId]; }
        if ($permiteEmpresa && !empty($op->empresa)) { $c = $buscarEmpresa($op->empresa); if (!$c) throw new Exception("Cambio $n: no existe la empresa «{$op->empresa}»."); return ['company', $c, 'Empresa ' . $nombreEmp($c), $c->id]; }
        return null;
    };
    $res = [];
    foreach ($ops as $i => $op) {
        $n = $i + 1;
        if (!is_object($op) || !isset($op->tipo)) throw new Exception("Cambio $n: falta «tipo».");
        switch ($op->tipo) {
        case 'actividad':
            $dst = $destino($op, $n, true);
            if (!$dst) throw new Exception("Cambio $n: indica oportunidad (código), prospecto (id) o empresa.");
            list($tipoEnt, $ent, $ref, $cid) = $dst;
            $tipo = isset($op->tipo_actividad) ? $op->tipo_actividad : 'Nota';
            if (!in_array($tipo, $TIPOS, true)) throw new Exception("Cambio $n: tipo_actividad debe ser uno de: " . implode(', ', $TIPOS) . '.');
            $desc = $txt($campo($op, 'descripcion'));
            if ($desc === '') throw new Exception("Cambio $n: falta la descripción.");
            $f = isset($op->fecha) && $op->fecha ? $op->fecha : $hoy;
            if (!$fecha($f)) throw new Exception("Cambio $n: fecha inválida (usa AAAA-MM-DD).");
            $pa = $txt($campo($op, 'proxima_accion'), 300); $pf = (string) $campo($op, 'fecha_proxima_accion');
            if ($pa !== '' && $tipoEnt === 'company') throw new Exception("Cambio $n: la próxima acción va en una oportunidad o un prospecto, no en la empresa.");
            if ($pa !== '' && !$fecha($pf)) throw new Exception("Cambio $n: la próxima acción necesita fecha_proxima_accion (AAAA-MM-DD).");
            array_unshift($d->activities, (object) ['id' => $uid('a'), 'date' => $f, 'userId' => $user, 'type' => $tipo, 'entityType' => $tipoEnt, 'entityId' => $ent->id,
                'companyId' => $cid, 'desc' => 'Joan: ' . $desc, 'result' => $txt($campo($op, 'resultado')), 'nextAction' => $pa, 'nextActionDate' => $pa !== '' ? $pf : '']);
            $audit($ref, 'Actividad', '', $tipo . ': ' . mb_substr($desc, 0, 80));
            if ($pa !== '' && $tipoEnt === 'opp') {
                $d->tasks[] = (object) ['id' => $uid('t'), 'title' => $pa, 'assigneeId' => $user, 'priority' => 'Media', 'due' => $pf, 'status' => 'Pendiente', 'createdAt' => $hoy, 'by' => 'Joan', 'entityType' => 'opp', 'entityId' => $ent->id];
                $audit($ref, 'Tarea', '', "$pa ($pf)");
            } elseif ($pa !== '') {
                $audit($ref, 'Próxima acción', $ent->nextAction . ($ent->nextActionDate ? ' (' . $ent->nextActionDate . ')' : ''), "$pa ($pf)");
                $ent->nextAction = $pa; $ent->nextActionDate = $pf;
            }
            $res[] = "Actividad registrada en $ref" . ($pa !== '' && $tipoEnt === 'opp' ? " y tarea «{$pa}» ({$pf})" : '');
            break;
        case 'proxima_accion':
            $dst = $destino($op, $n);
            if (!$dst) throw new Exception("Cambio $n: indica oportunidad (código) o prospecto (id).");
            list($tipoEnt, $ent, $ref) = $dst;
            $pa = $txt($campo($op, 'accion'), 300); $pf = (string) $campo($op, 'fecha');
            if ($pa === '' || !$fecha($pf)) throw new Exception("Cambio $n: indica accion y fecha (AAAA-MM-DD).");
            if ($tipoEnt === 'opp') {   // en oportunidades solo se gestionan tareas
                $d->tasks[] = (object) ['id' => $uid('t'), 'title' => $pa, 'assigneeId' => $user, 'priority' => 'Media', 'due' => $pf, 'status' => 'Pendiente', 'createdAt' => $hoy, 'by' => 'Joan', 'entityType' => 'opp', 'entityId' => $ent->id];
                $audit($ref, 'Tarea', '', "$pa ($pf)");
                $res[] = "Tarea creada en $ref: $pa ($pf)";
                break;
            }
            $audit($ref, 'Próxima acción', $ent->nextAction . ($ent->nextActionDate ? ' (' . $ent->nextActionDate . ')' : ''), "$pa ($pf)");
            $ent->nextAction = $pa; $ent->nextActionDate = $pf;
            $res[] = "Próxima acción actualizada en $ref";
            break;
        case 'tarea':
            $tit = $txt($campo($op, 'titulo'), 300); $v = (string) $campo($op, 'vence');
            if ($tit === '' || !$fecha($v)) throw new Exception("Cambio $n: la tarea necesita titulo y vence (AAAA-MM-DD).");
            $pr = isset($op->prioridad) ? $op->prioridad : 'Media';
            if (!in_array($pr, ['Alta', 'Media', 'Baja'], true)) throw new Exception("Cambio $n: prioridad debe ser Alta, Media o Baja.");
            $t = (object) ['id' => $uid('t'), 'title' => $tit, 'assigneeId' => $user, 'priority' => $pr, 'due' => $v, 'status' => 'Pendiente', 'createdAt' => $hoy, 'by' => 'Joan', 'entityType' => '', 'entityId' => ''];
            $dst = $destino($op, $n);
            if ($dst) { $t->entityType = $dst[0]; $t->entityId = $dst[1]->id; }
            $d->tasks[] = $t;
            $audit('Tarea: ' . $tit, 'Creada', '', $v);
            $res[] = "Tarea creada: $tit ($v)";
            break;
        case 'completar_tarea':
            $id = (string) $campo($op, 'tarea'); $t = null;
            foreach ($d->tasks as $x) if ($x->id === $id) $t = $x;
            if (!$t) throw new Exception("Cambio $n: no existe la tarea «$id».");
            $ant = $t->status; $t->status = 'Completada'; $t->completedAt = $hoy;
            $audit('Tarea: ' . $t->title, 'Estado', $ant, 'Completada');
            $res[] = "Tarea completada: {$t->title}";
            break;
        case 'empresa':
            $nom = $txt($campo($op, 'nombre'), 200);
            if ($nom === '') throw new Exception("Cambio $n: la empresa necesita nombre.");
            $c = $buscarEmpresa($nom);
            if ($c) throw new Exception("Cambio $n: ya existe la empresa «" . $nombreEmp($c) . "». Joan no edita empresas existentes.");
            if (!empty($op->industria) && !in_array($op->industria, $INDUSTRIAS_CRM, true)) throw new Exception("Cambio $n: industria no válida. Usa: " . implode(', ', $INDUSTRIAS_CRM) . '.');
            if (!empty($op->potencial) && !in_array($op->potencial, $POTENCIAL, true)) throw new Exception("Cambio $n: potencial debe ser Alto, Medio o Bajo.");
            $c = $crearEmpresa($nom, $op);
            $res[] = "Empresa creada: $nom ({$c->industry}, prospecto)";
            break;
        case 'contacto':
            $c = $buscarEmpresa($campo($op, 'empresa'));
            if (!$c) throw new Exception("Cambio $n: no existe la empresa «" . $campo($op, 'empresa') . "». Créala primero.");
            list($k, $nuevo, $comp) = $contactoEn($c, $op, $n);
            if (!$nuevo && !$comp) throw new Exception("Cambio $n: ya existe el contacto «" . trim($k->firstName . ' ' . $k->lastName) . "» en " . $nombreEmp($c) . " y no trae correo, teléfono ni LinkedIn nuevos. Joan no edita datos existentes de un contacto.");
            if (!$nuevo) { $res[] = 'Contacto completado: ' . trim($k->firstName . ' ' . $k->lastName) . ' en ' . $nombreEmp($c) . ' (' . implode(', ', $comp) . ')'; break; }
            $res[] = 'Contacto creado: ' . trim($k->firstName . ' ' . $k->lastName) . ($k->position ? ", {$k->position}" : '') . ' en ' . $nombreEmp($c);
            break;
        case 'prospecto':
            $emp = $txt($campo($op, 'empresa'), 200);
            if ($emp === '') throw new Exception("Cambio $n: el prospecto necesita empresa.");
            $c = $buscarEmpresa($emp); $empNueva = false;
            // Un cliente puede tener varios prospectos abiertos; el nombre los distingue y evita duplicar el mismo
            $nomP = $txt($campo($op, 'nombre'), 160);
            if ($c) foreach ($d->leads as $x) if ($x->companyId === $c->id && $leadAbierto($x)) {
                $nx = normTxt(isset($x->name) ? $x->name : '');
                if ($nomP === '') throw new Exception("Cambio $n: " . $nombreEmp($c) . " ya tiene prospectos abiertos (" . (isset($x->code) ? $x->code : $x->id) . "). Indica «nombre» para crear otro distinto, o usa actualizar_prospecto.");
                if ($nx !== '' && $nx === normTxt($nomP)) throw new Exception("Cambio $n: ya existe el prospecto «{$x->name}» (" . (isset($x->code) ? $x->code : $x->id) . ", etapa {$x->stage}) en " . $nombreEmp($c) . '.');
            }
            $et = !empty($op->etapa) ? $etapaProspecto($op->etapa, $n) : 'Identificado';
            $pot = !empty($op->potencial) ? $op->potencial : 'Medio';
            if (!in_array($pot, $POTENCIAL, true)) throw new Exception("Cambio $n: potencial debe ser Alto, Medio o Bajo.");
            $sv = $servicio($campo($op, 'servicio'), $n);
            $pa = $txt($campo($op, 'proxima_accion'), 300); $pf = (string) $campo($op, 'fecha_proxima_accion');
            if ($pa !== '' && !$fecha($pf)) throw new Exception("Cambio $n: la próxima acción necesita fecha_proxima_accion (AAAA-MM-DD).");
            if (!isset($op->contacto) || !is_object($op->contacto) || empty($op->contacto->nombre))
                throw new Exception("Cambio $n: un prospecto necesita un contacto con correo, teléfono o LinkedIn (contacto {nombre, email | telefono | linkedin}). Si aún no lo tienes, agrega la empresa al plan de la semana con plan_objetivo (con la necesidad y el contacto identificado).");
            if (!$c) { $c = $crearEmpresa($emp, $op); $empNueva = true; }
            list($k, $kNuevo) = $contactoEn($c, $op->contacto, $n); $kid = $k->id;
            if (!contactoEfectivo($k)) throw new Exception("Cambio $n: el contacto «" . trim($k->firstName . ' ' . $k->lastName) . "» no tiene correo, teléfono ni LinkedIn. Un prospecto necesita un contacto efectivo; si aún no lo tienes, agrega la empresa al plan de la semana con plan_objetivo.");
            foreach ($d->leads as $x) if ($x->companyId === $c->id && $leadAbierto($x) && isset($x->contactId) && $x->contactId === $k->id)
                throw new Exception("Cambio $n: " . trim($k->firstName . ' ' . $k->lastName) . " ya es el contacto del prospecto abierto " . (isset($x->code) ? $x->code : $x->id) . ". Regístrale seguimiento con actualizar_prospecto.");
            $sidL = !empty($op->operacion) ? $sitioEn($c, $op->operacion) : (isset($k->siteId) ? (string) $k->siteId : '');
            $l = (object) ['id' => $uid('l'), 'code' => nuevoCodigoPR($d), 'name' => $nomP !== '' ? $nomP : $sv, 'stageAt' => $hoy, 'companyId' => $c->id, 'contactId' => $kid, 'siteId' => $sidL, 'source' => $txt($campo($op, 'origen'), 120) ?: 'Joan (Telegram)', 'stage' => $et,
                'potential' => $pot, 'problem' => $txt($campo($op, 'problema'), 1000), 'service' => $sv, 'ownerId' => $user, 'nextAction' => $pa, 'nextActionDate' => $pa !== '' ? $pf : '',
                'vars' => new stdClass(), 'createdAt' => $hoy];
            $d->leads[] = $l;
            $audit('Prospecto ' . $l->code, 'Creación', '', $et);
            $res[] = "Prospecto creado ({$l->code}): " . $nombreEmp($c) . ($l->name !== '' ? " · {$l->name}" : '') . ($empNueva ? ' (empresa nueva)' : '') . ($kNuevo ? ', contacto nuevo' : '') . ", etapa $et"
                . ($pa === '' ? '. Falta: próxima acción' : '');
            break;
        case 'actualizar_prospecto':
            $l = $buscarLead($campo($op, 'prospecto'));
            if (!$l) throw new Exception("Cambio $n: no existe el prospecto «" . $campo($op, 'prospecto') . "».");
            $ref = 'Prospecto ' . (!empty($l->code) ? $l->code : $l->id); $cambios = [];
            // Calificación (decisor, plazo, presupuesto)
            $PLAZOS = ['Menos de 3 meses', '3 a 6 meses', '6 a 12 meses', 'Más de 12 meses'];
            $PRESUP = ['Presupuesto aprobado', 'Contrato actual vence pronto', 'En preparación de presupuesto', 'Sin presupuesto aún'];
            $q = isset($l->qual) && is_object($l->qual) ? $l->qual : (object) ['decisorId' => '', 'plazo' => '', 'presupuesto' => ''];
            $qCambio = false;
            if (!empty($op->decisor)) {
                $kd = null; foreach ($d->contacts as $x) if ($x->companyId === $l->companyId && normTxt($x->firstName . ' ' . $x->lastName) === normTxt($op->decisor)) $kd = $x;
                if (!$kd) throw new Exception("Cambio $n: no hay un contacto «{$op->decisor}» en esa empresa. Créalo primero con contacto.");
                $q->decisorId = $kd->id; if (!isset($kd->decisionRole) || $kd->decisionRole !== 'Decisor') { $kd->decisionRole = 'Decisor'; } $qCambio = true;
            }
            if (!empty($op->plazo)) { $pv = null; foreach ($PLAZOS as $P) if (normTxt($P) === normTxt($op->plazo)) $pv = $P; if (!$pv) throw new Exception("Cambio $n: plazo no válido. Usa: " . implode(', ', $PLAZOS) . '.'); $q->plazo = $pv; $qCambio = true; }
            if (!empty($op->presupuesto)) { $pv = null; foreach ($PRESUP as $P) if (normTxt($P) === normTxt($op->presupuesto)) $pv = $P; if (!$pv) throw new Exception("Cambio $n: presupuesto no válido. Usa: " . implode(', ', $PRESUP) . '.'); $q->presupuesto = $pv; $qCambio = true; }
            if (!empty($op->etapa)) { $et = $etapaProspecto($op->etapa, $n); if ($et !== $l->stage) {
                $orden = ['Identificado', 'Investigación', 'Contacto inicial', 'Contactado', 'Reunión solicitada', 'Reunión realizada', 'Calificación', 'Levantamiento'];
                $iA = array_search($l->stage, $orden, true); $iB = array_search($et, $orden, true); $iC = array_search('Calificación', $orden, true);
                if ($iB !== false && $iB >= $iC && ($iA === false || $iA < $iC)) $qCambio = true;
                $audit($ref, 'Etapa', $l->stage, $et); $l->stage = $et; $l->stageAt = $hoy; $cambios[] = 'etapa'; } }
            if ($qCambio) { $q->at = $hoy; $l->qual = $q; $l->qualIncompleta = !($q->decisorId && $q->plazo && $q->presupuesto); $cambios[] = 'calificación' . ($l->qualIncompleta ? ' (incompleta)' : ''); }
            if (isset($op->nombre) && $txt($op->nombre, 160) !== '' && $txt($op->nombre, 160) !== (isset($l->name) ? $l->name : '')) { $audit($ref, 'Nombre', isset($l->name) ? $l->name : '', $txt($op->nombre, 160)); $l->name = $txt($op->nombre, 160); $cambios[] = 'nombre'; }
            if (!empty($op->potencial)) {
                if (!in_array($op->potencial, $POTENCIAL, true)) throw new Exception("Cambio $n: potencial debe ser Alto, Medio o Bajo.");
                if ($op->potencial !== $l->potential) { $audit($ref, 'Potencial', $l->potential, $op->potencial); $l->potential = $op->potencial; $cambios[] = 'potencial'; }
            }
            if (isset($op->problema) && $txt($op->problema, 1000) !== '' && $txt($op->problema, 1000) !== $l->problem) { $audit($ref, 'Problema', mb_substr($l->problem, 0, 80), mb_substr($txt($op->problema), 0, 80)); $l->problem = $txt($op->problema, 1000); $cambios[] = 'problema'; }
            if (!empty($op->servicio)) { $sv = $servicio($op->servicio, $n); if ($sv !== $l->service) { $audit($ref, 'Servicio', $l->service, $sv); $l->service = $sv; $cambios[] = 'servicio'; } }
            $pa = $txt($campo($op, 'proxima_accion'), 300);
            if ($pa !== '') {
                $pf = (string) $campo($op, 'fecha_proxima_accion');
                if (!$fecha($pf)) throw new Exception("Cambio $n: la próxima acción necesita fecha_proxima_accion (AAAA-MM-DD).");
                $audit($ref, 'Próxima acción', $l->nextAction . ($l->nextActionDate ? ' (' . $l->nextActionDate . ')' : ''), "$pa ($pf)");
                $l->nextAction = $pa; $l->nextActionDate = $pf; $cambios[] = 'próxima acción';
            }
            $res[] = $cambios ? "$ref actualizado: " . implode(', ', $cambios) : "$ref: no había nada que cambiar";
            break;
        case 'plan_objetivo':
            $vert = $txt($campo($op, 'vertical'), 60);
            if (!in_array($vert, $INDUSTRIAS_CRM, true)) throw new Exception("Cambio $n: vertical no válida. Usa: " . implode(', ', $INDUSTRIAS_CRM) . '.');
            // Planes semanales (desde 16-11-2026): semana ISO NN-YY, de lunes a domingo
            $sem = $txt($campo($op, 'semana'), 5);
            if ($sem === '') { $t0 = strtotime($hoy); $sem = date('W', $t0) . '-' . substr(date('o', $t0), 2); }
            $lunes = null;
            if (preg_match('/^(\d{2})-(\d{2})$/', $sem, $mm)) {
                $dt = (new DateTime())->setISODate(2000 + (int) $mm[2], (int) $mm[1], 1);
                if ((int) $mm[1] >= 1 && $dt->format('W') . '-' . substr($dt->format('o'), 2) === $sem) $lunes = $dt->format('Y-m-d');
            }
            if (!$lunes) throw new Exception("Cambio $n: semana inválida (usa NN-YY, por ejemplo 47-26).");
            $mes = substr($lunes, 0, 7);
            $emp = $txt($campo($op, 'empresa'), 200);
            if ($emp === '') throw new Exception("Cambio $n: falta la empresa.");
            $c = $buscarEmpresa($emp); $empNueva = false;
            $soloEx = !empty($op->solo_existente);
            if ($soloEx) {   // solo actualiza empresas que ya están en el plan de esa semana (recargas)
                $enPlan = false;
                if ($c) foreach ($d->plans as $x) if (isset($x->week) && $x->week === $sem) foreach ((array) $x->targets as $t) if ($t->companyId === $c->id) $enPlan = true;
                if (!$enPlan) { $res[] = $emp . ' no estaba en el Plan semana ' . $sem; break; }
            }
            if (!$c) {
                $oc = clone $op; $oc->industria = $vert;
                $c = $crearEmpresa($emp, $oc); $empNueva = true;
                $org = $txt($campo($op, 'origen'), 120); if ($org !== '') $c->source = $org;
            }
            $kNuevo = false; $kObj = null;
            if (isset($op->contacto) && is_object($op->contacto) && !empty($op->contacto->nombre)) { list($kObj, $kNuevo) = $contactoEn($c, $op->contacto, $n); }
            $nec = $txt($campo($op, 'necesidad'), 500);
            $plan = null;
            // Un solo plan por semana con todas las verticales (16-11-2026); la vertical queda en la empresa
            foreach ($d->plans as $x) if (isset($x->week) && $x->week === $sem && (!isset($x->ownerId) || $x->ownerId === $user)) $plan = $x;
            if (!$plan) {
                $plan = (object) ['id' => $uid('p'), 'name' => "Plan semana $sem", 'vertical' => 'Todas', 'month' => $mes, 'week' => $sem,
                    'status' => 'Borrador', 'ownerId' => $user, 'pace' => 5, 'objective' => '', 'service' => '', 'subsegments' => [], 'roles' => [], 'valueProp' => '',
                    'pains' => [], 'questions' => [], 'objections' => [], 'cadence' => [], 'goals' => new stdClass(), 'targets' => [], 'createdAt' => $hoy, 'porAgente' => true];
                $d->plans[] = $plan;
                $audit($plan->name, 'Creación', '', 'Borrador');
            }
            if (!isset($plan->targets) || !is_array($plan->targets)) $plan->targets = [];
            $ya = null; foreach ($plan->targets as $t) if ($t->companyId === $c->id) $ya = $t;
            if ($ya) {
                $agr = [];
                if ($nec !== '' && empty($ya->need)) { $ya->need = $nec; $agr[] = 'necesidad'; }
                $nota = $txt($campo($op, 'nota'), 1000);
                $vieja = isset($ya->note) ? (string) $ya->note : '';
                if ($nota !== '' && $nota !== $vieja && ($vieja === '' || (mb_strlen($nota) > mb_strlen($vieja) && strpos($vieja, 'Titular:') === false))) { $ya->note = $nota; $agr[] = 'observación'; }
                $ids = isset($ya->contactIds) && is_array($ya->contactIds) ? $ya->contactIds : (!empty($ya->contactId) ? [$ya->contactId] : []);
                if ($kObj && !in_array($kObj->id, $ids, true)) { $ids[] = $kObj->id; $ya->contactIds = $ids; unset($ya->contactId); $agr[] = 'contacto'; }
                $res[] = $nombreEmp($c) . " ya estaba en {$plan->name}" . ($agr ? ' (se agregó ' . implode(' y ', $agr) . ')' : ''); break;
            }
            $plan->targets[] = (object) ['companyId' => $c->id, 'status' => 'Pendiente', 'addedAt' => $hoy, 'source' => $txt($campo($op, 'origen'), 120), 'note' => $txt($campo($op, 'nota'), 1000),
                'need' => $nec, 'contactIds' => $kObj ? [$kObj->id] : []];
            $audit($plan->name, 'Empresa objetivo', '', $nombreEmp($c));
            $res[] = $nombreEmp($c) . " agregada a {$plan->name}" . ($empNueva ? ' (empresa nueva)' : '') . ($kNuevo ? ', contacto nuevo' : '');
            break;
        case 'oportunidad':
        case 'actualizar_oportunidad':
            throw new Exception("Cambio $n: Joan todavía no crea oportunidades ni cambia su nombre, etapa, probabilidad, montos o fecha de cierre; eso se hace en la pantalla del CRM. Puede registrar actividades, tareas y próximas acciones, o crear un prospecto.");
        default:
            throw new Exception("Cambio $n: tipo «{$op->tipo}» no permitido. Usa actividad, proxima_accion, tarea, completar_tarea, empresa, contacto, prospecto, actualizar_prospecto o plan_objetivo.");
        }
    }
    $d->savedAt = (int) round(microtime(true) * 1000);
    return $res;
}

/* ---------- Bajas de empresas y contactos desde la pantalla ----------
   Regla (la misma que aplica la ficha):
   - Sin historial se puede eliminar; queda en «Registros eliminados».
   - Con historial (actividades, oportunidades, ofertas o sitios; o prospectos, tareas o planes que sigan
     apuntando a él) no se elimina: solo se fusiona con otro. La fusión viene declarada en «fusiones»
     ({id, tabla, de, a}); todo lo que tenía debe seguir existiendo, ahora en el destino, y los contactos
     solo se fusionan dentro de la misma empresa.
   - Nunca puede quedar un registro apuntando a una empresa o contacto que ya no existe.
   - Excepción acotada: «Eliminar empresas del plan» borra a la vez el lugar en el plan, sus tareas
     pendientes y los prospectos que creó el plan sin actividad ni oportunidad. Eso se acepta (todo
     queda recuperable).
   No aplica a datos de ejemplo ni a un reemplazo completo (restaurar respaldo, «Empezar con mis datos»).
   La API del agente no pasa por aquí: Joan no elimina ni fusiona. */
function lista($o, $k) { return is_object($o) && isset($o->$k) && is_array($o->$k) ? $o->$k : []; }
function porId($l) { $m = []; foreach ($l as $x) if (is_object($x) && isset($x->id)) $m[(string) $x->id] = $x; return $m; }
function valTxt($o, $k) { return is_object($o) && isset($o->$k) && is_scalar($o->$k) ? (string) $o->$k : ''; }
function idsObjetivo($t) { return isset($t->contactIds) && is_array($t->contactIds) ? $t->contactIds : (!empty($t->contactId) ? [$t->contactId] : []); }

// Registros que apuntan a una empresa ($tabla = companies) o a un contacto (contacts): lista de [tabla, id]
function referenciasA($d, $tabla, $id) {
    $r = [];
    if ($tabla === 'companies') {
        foreach (lista($d, 'activities') as $a) if (valTxt($a, 'companyId') === $id || (valTxt($a, 'entityType') === 'company' && valTxt($a, 'entityId') === $id)) $r[] = ['activities', (string) $a->id];
        foreach (['opps', 'offers', 'leads', 'contacts'] as $t) foreach (lista($d, $t) as $x) if (valTxt($x, 'companyId') === $id) $r[] = [$t, (string) $x->id];
        foreach (lista($d, 'tasks') as $x) if (valTxt($x, 'entityType') === 'company' && valTxt($x, 'entityId') === $id) $r[] = ['tasks', (string) $x->id];
        foreach (lista($d, 'plans') as $p) foreach (lista($p, 'targets') as $t) if (valTxt($t, 'companyId') === $id) $r[] = ['plans', (string) $p->id];
    } else {
        foreach (lista($d, 'activities') as $a) if ((valTxt($a, 'entityType') === 'contact' && valTxt($a, 'entityId') === $id) || valTxt($a, 'contactId') === $id || in_array($id, lista($a, 'contactIds'), true)) $r[] = ['activities', (string) $a->id];
        foreach (lista($d, 'opps') as $o) if (in_array($id, lista($o, 'contactIds'), true)) $r[] = ['opps', (string) $o->id];
        foreach (lista($d, 'offers') as $o) if (valTxt($o, 'contactId') === $id) $r[] = ['offers', (string) $o->id];
        foreach (lista($d, 'leads') as $l) if (valTxt($l, 'contactId') === $id || (isset($l->qual) && valTxt($l->qual, 'decisorId') === $id)) $r[] = ['leads', (string) $l->id];
        foreach (lista($d, 'tasks') as $x) if (valTxt($x, 'entityType') === 'contact' && valTxt($x, 'entityId') === $id) $r[] = ['tasks', (string) $x->id];
        foreach (lista($d, 'plans') as $p) foreach (lista($p, 'targets') as $t) if (in_array($id, idsObjetivo($t), true)) $r[] = ['plans', (string) $p->id];
    }
    return $r;
}
function contarRefs($refs) {
    $N = ['activities' => ['actividad', 'actividades'], 'opps' => ['oportunidad', 'oportunidades'], 'offers' => ['oferta', 'ofertas'], 'leads' => ['prospecto', 'prospectos'],
          'tasks' => ['tarea', 'tareas'], 'plans' => ['plan', 'planes'], 'contacts' => ['contacto', 'contactos'], 'sites' => ['sitio', 'sitios']];
    $c = []; foreach ($refs as $x) $c[$x[0]][$x[1]] = 1;
    $out = []; foreach ($N as $t => $w) if (!empty($c[$t])) { $n = count($c[$t]); $out[] = "$n " . $w[$n === 1 ? 0 : 1]; }
    return implode(', ', $out);
}
// Devuelve [error, filas extra para «cambios»]. Error vacío = se puede guardar.
function validarBajas($antes, $nuevo) {
    if (!is_object($antes) || valTxt($antes, 'mode') !== 'real') return ['', []];
    $fusPrev = porId(lista($antes, 'fusiones')); $fus = [];
    foreach (lista($nuevo, 'fusiones') as $f) if (is_object($f) && isset($f->id) && !isset($fusPrev[(string) $f->id]) && valTxt($f, 'tabla') !== '' && valTxt($f, 'de') !== '')
        $fus[valTxt($f, 'tabla') . '|' . valTxt($f, 'de')] = valTxt($f, 'a');
    $vivos = []; foreach (['companies', 'contacts', 'activities', 'opps', 'offers', 'leads', 'tasks', 'plans'] as $t) $vivos[$t] = porId(lista($nuevo, $t));
    $leadsAntes = porId(lista($antes, 'leads'));
    $actsDeLead = []; foreach (lista($antes, 'activities') as $a) if (valTxt($a, 'entityType') === 'lead') $actsDeLead[valTxt($a, 'entityId')] = 1;
    $nombre = function ($t, $x) { return $t === 'companies' ? (valTxt($x, 'tradeName') ?: valTxt($x, 'legalName')) : trim(valTxt($x, 'firstName') . ' ' . valTxt($x, 'lastName')); };
    $extra = [];
    foreach (['companies', 'contacts'] as $tabla) foreach (lista($antes, $tabla) as $x) {
        $id = (string) $x->id;
        if (isset($vivos[$tabla][$id])) continue;
        $nom = $nombre($tabla, $x) ?: $id; $que = $tabla === 'companies' ? 'la empresa' : 'el contacto';
        $quedan = referenciasA($nuevo, $tabla, $id);
        if ($quedan) return ["No se puede eliminar $que «{$nom}»: todavía tiene " . contarRefs($quedan) . '.', []];
        $refs = referenciasA($antes, $tabla, $id);
        if (isset($fus["$tabla|$id"])) {
            $a = $fus["$tabla|$id"]; $dst = isset($vivos[$tabla][$a]) ? $vivos[$tabla][$a] : null;
            if (!$dst || $a === $id) return ["No se pudo fusionar «{$nom}»: el registro de destino no existe.", []];
            if ($tabla === 'contacts') {
                $emp = valTxt($x, 'companyId'); if (isset($fus["companies|$emp"])) $emp = $fus["companies|$emp"];
                if (valTxt($dst, 'companyId') !== $emp) return ["No se pudo fusionar «{$nom}»: solo se fusionan contactos de la misma empresa.", []];
            }
            foreach ($refs as $r) if (!isset($vivos[$r[0]][$r[1]])) return ["No se pudo fusionar «{$nom}»: la fusión no puede borrar su historial (" . contarRefs([$r]) . ').', []];
            $extra[] = ['tabla' => $tabla, 'registro' => $id, 'accion' => 'fusión', 'antes' => $x,
                'despues' => ['fusionado_en' => $a, 'nombre' => $nombre($tabla, $dst), 'movido' => contarRefs($refs)]];
            continue;
        }
        // Baja simple: no puede llevarse historial
        $duro = array_values(array_filter($refs, function ($r) use ($vivos, $leadsAntes, $actsDeLead) {
            if (in_array($r[0], ['activities', 'opps', 'offers'], true)) return true;
            if ($r[0] === 'leads') { $l = isset($leadsAntes[$r[1]]) ? $leadsAntes[$r[1]] : null;
                return isset($actsDeLead[$r[1]]) || ($l && (valTxt($l, 'oppId') !== '' || valTxt($l, 'planId') === '')); }
            return false;   // contactos, tareas y el lugar en un plan que se van junto con él (ya validados arriba)
        }));
        if ($tabla === 'companies' && lista($x, 'sites')) foreach (lista($x, 'sites') as $s) $duro[] = ['sites', valTxt($s, 'id') ?: $nom . count($duro)];
        if ($duro) return ["No se puede eliminar $que «{$nom}» porque tiene historial (" . contarRefs($duro) . '). Solo se puede fusionar con otro registro.', []];
    }
    return ['', $extra];
}

/* ---------- Lectura y escritura (base SQLite, o el JSON si la base no está disponible) ---------- */
function leerActual() { global $PDO, $ARCHIVO, $CABECERA; return $PDO ? almLeer($PDO) : leerDatos($ARCHIVO, $CABECERA); }
// Guarda el documento completo con la rev indicada. Debe llamarse con el bloqueo tomado. En la base es una transacción:
// se escribe todo o nada. Después escribe el JSON de siempre como red de seguridad (y su respaldo por hora).
// $extra: filas adicionales para «cambios» (p. ej. la fusión de una empresa o un contacto), en la misma transacción.
function escribirDatos($data, $rev, $savedAt, $origen, $extra = []) {
    global $PDO, $ARCHIVO, $CABECERA, $HISTORIAL, $MAX_HISTORIAL, $DIR_DATOS, $USUARIO_ACTUAL;
    $usr = isset($USUARIO_ACTUAL) ? $USUARIO_ACTUAL : null;
    if ($PDO) {
        try { $PDO->exec('BEGIN IMMEDIATE'); almGuardar($PDO, $data, $rev, $savedAt, $origen, $usr);
            $insX = $PDO->prepare('INSERT INTO cambios (rev, en, origen, tabla, registro, accion, antes, despues, usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($extra as $x) $insX->execute([$rev, date('c'), $origen, $x['tabla'], $x['registro'], $x['accion'], almJson($x['antes']), almJson($x['despues']), $usr]);
            $PDO->exec('COMMIT'); }
        catch (Exception $e) { try { $PDO->exec('ROLLBACK'); } catch (Exception $x) {} almLog($DIR_DATOS, 'Guardado fallido (no se escribió nada): ' . $e->getMessage()); return 'No se pudo guardar en la base de datos; no se perdió nada. Intenta de nuevo.'; }
        almRespaldo($PDO, $DIR_DATOS);
    }
    respaldoHora($ARCHIVO, $HISTORIAL, $MAX_HISTORIAL);
    $json = json_encode((object) ['rev' => $rev, 'savedAt' => $savedAt, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $tmp = $ARCHIVO . '.tmp';
    if ($json === false || file_put_contents($tmp, $CABECERA . $json, LOCK_EX) === false || !rename($tmp, $ARCHIVO)) {
        if ($PDO) { almLog($DIR_DATOS, 'Aviso: se guardó en la base, pero no se pudo actualizar la copia JSON.'); return ''; }
        return 'No se pudo escribir el archivo de datos. Revisa el espacio y los permisos.';
    }
    return '';
}

/* ---------- Clave ---------- */
/* Desde aquí se atiende la petición. Cada persona entra con su cuenta (cuentas.php) y
   los permisos de su rol y equipo se aplican en cada lectura y escritura (permisos.php). */
require __DIR__ . '/cuentas.php';
require __DIR__ . '/permisos.php';
require __DIR__ . '/guardado.php';
/* Origen de la petición:
   - local:      desde el mismo servidor (Joan en joansvr), sin pasar por ningún proxy
   - tailnet:    desde tu red Tailscale. Tailscale Serve agrega Tailscale-User-Login
                 y borra esa cabecera si la trae el cliente, así que no se puede falsificar
   - cloudflare: por el túnel de Cloudflare, con Cloudflare Access ya superado (correo +
                 código). Se verifica la firma del JWT que agrega Access (Cf-Access-Jwt-Assertion),
                 su audiencia, emisor, vencimiento y que el correo esté autorizado.
   - publico:    cualquier otro (internet vía Tailscale Funnel, o un hosting)
   Cloudflare reenvía las cabeceras del cliente, así que si la petición trae Cf-Ray o
   Cf-Connecting-Ip se decide SOLO por el JWT: nunca cuenta como local ni tailnet. */
function b64url($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function cfCertificados($equipo, $cacheF, $cab) {
    $url = defined('CF_ACCESS_CERTS_URL') ? CF_ACCESS_CERTS_URL : "https://$equipo.cloudflareaccess.com/cdn-cgi/access/certs";
    $raw = is_file($cacheF) ? (string) @file_get_contents($cacheF) : '';
    if (strpos($raw, $cab) === 0) $raw = substr($raw, strlen($cab));
    $c = json_decode($raw, true);
    $edad = is_array($c) && isset($c['_en']) ? time() - (int) $c['_en'] : PHP_INT_MAX;
    if ($edad > 3600) {   // se refresca cada hora; si Cloudflare no responde se usa el guardado hasta 24 h
        $j = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
        $n = $j ? json_decode($j, true) : null;
        if (is_array($n) && !empty($n['public_certs'])) { $n['_en'] = time(); $c = $n; @file_put_contents($cacheF, $cab . json_encode($n), LOCK_EX); }
        elseif ($edad > 86400) return [];
    }
    $out = [];
    foreach ((array) ($c['public_certs'] ?? []) as $pc) if (!empty($pc['kid']) && !empty($pc['cert'])) $out[$pc['kid']] = $pc['cert'];
    return $out;
}
/* Devuelve el correo autorizado si el JWT de Access es válido; si no, ''. */
function cfAccessCorreo($jwt, $cacheF, $cab) {
    if (!defined('CF_ACCESS_EQUIPO') || !defined('CF_ACCESS_AUD') || !defined('CF_ACCESS_CORREOS')) return '';
    $partes = explode('.', (string) $jwt);
    if (count($partes) !== 3) return '';
    [$h64, $p64, $s64] = $partes;
    $h = json_decode((string) b64url($h64), true); $p = json_decode((string) b64url($p64), true);
    if (!is_array($h) || !is_array($p) || ($h['alg'] ?? '') !== 'RS256' || empty($h['kid'])) return '';
    $certs = cfCertificados(CF_ACCESS_EQUIPO, $cacheF, $cab);
    if (!isset($certs[$h['kid']])) return '';
    $llave = @openssl_pkey_get_public($certs[$h['kid']]);
    if (!$llave || openssl_verify("$h64.$p64", (string) b64url($s64), $llave, OPENSSL_ALGO_SHA256) !== 1) return '';
    $ahora = time();
    $aud = (array) ($p['aud'] ?? []);
    if (!in_array(CF_ACCESS_AUD, $aud, true)) return '';
    if (($p['iss'] ?? '') !== 'https://' . CF_ACCESS_EQUIPO . '.cloudflareaccess.com') return '';
    if ((int) ($p['exp'] ?? 0) < $ahora - 30 || (int) ($p['nbf'] ?? 0) > $ahora + 30) return '';
    $correo = strtolower(trim((string) ($p['email'] ?? '')));
    $permitidos = array_filter(array_map(function ($x) { return strtolower(trim($x)); }, explode(',', CF_ACCESS_CORREOS)));
    return ($correo !== '' && in_array($correo, $permitidos, true)) ? $correo : '';
}
$loopback = in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', ['127.0.0.1', '::1'], true);
$porCloudflare = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
$correoAccess = '';
if ($porCloudflare) {
    $correoAccess = $loopback ? cfAccessCorreo(isset($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION']) ? $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] : '', $DIR_DATOS . '/cf_certs.json.php', $CABECERA) : '';
    if ($correoAccess === '') {
        usleep(300000);
        responder(403, ['error' => 'Acceso por Cloudflare no verificado. Entra de nuevo con tu correo (Cloudflare Access).']);
    }
    $origen = 'cloudflare';
}
elseif ($loopback && empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_TAILSCALE_USER_LOGIN'])) $origen = 'local';
elseif ($loopback && !empty($_SERVER['HTTP_TAILSCALE_USER_LOGIN'])) $origen = 'tailnet';
else $origen = 'publico';
$ipCliente = $porCloudflare && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim($_SERVER['HTTP_CF_CONNECTING_IP'])
    : ($loopback && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
    ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));

/* Bloqueo por intentos fallidos (no aplica a peticiones locales):
   10 fallos de una misma IP en 15 minutos → esa IP queda bloqueada 15 minutos.
   60 fallos en total en una hora → se bloquea todo acceso desde internet por una hora. */
$INTENTOS = $DIR_DATOS . '/intentos.json.php';
function leerIntentos($f, $cab) {
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    if (strpos($raw, $cab) === 0) $raw = substr($raw, strlen($cab));
    $j = json_decode($raw, true);
    $ahora = time(); $out = [];
    foreach (is_array($j) ? $j : [] as $ip => $ts) { $ts = array_values(array_filter((array) $ts, function ($t) use ($ahora) { return $t > $ahora - 3600; })); if ($ts) $out[$ip] = $ts; }
    return $out;
}
function bloqueado($int, $ip, $origen) {
    $ahora = time();
    $mios = isset($int[$ip]) ? count(array_filter($int[$ip], function ($t) use ($ahora) { return $t > $ahora - 900; })) : 0;
    if ($mios >= 10) return 'Demasiados intentos fallidos desde tu conexión. Espera 15 minutos.';
    if ($origen === 'publico' && array_sum(array_map('count', $int)) >= 60) return 'Acceso desde internet suspendido por una hora por demasiados intentos fallidos.';
    return '';
}
if ($origen !== 'local') {
    $msg = bloqueado(leerIntentos($INTENTOS, $CABECERA), $ipCliente, $origen);
    if ($msg) { usleep(500000); responder(429, ['error' => $msg]); }
}
function registrarFallo($f, $cab, $ip) {
    $h = @fopen($f . '.lock', 'c'); if ($h) flock($h, LOCK_EX);
    $int = leerIntentos($f, $cab); $int[$ip][] = time();
    @file_put_contents($f, $cab . json_encode($int), LOCK_EX);
    if ($h) { flock($h, LOCK_UN); fclose($h); }
}

/* ---------- Carpeta de datos ---------- */
if (!is_dir($DIR_DATOS) && !@mkdir($DIR_DATOS, 0750, true)) {
    responder(500, ['error' => 'No se pudo crear la carpeta datos. Revisa los permisos de la carpeta del CRM.']);
}
if (!is_file($DIR_DATOS . '/.htaccess')) {
    @file_put_contents($DIR_DATOS . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'leer';

try { $CTA = ctaAbrir($DIR_DATOS); } catch (Exception $e) { $CTA = null; }
if (!$CTA) responder(503, ['error' => 'Las cuentas de usuario necesitan SQLite en el PHP del servidor (paquete php-sqlite3).']);

/* Clave del agente (opcional): un programa (Joan) lee y escribe con los permisos de la cuenta
   CRM_AGENTE_USUARIO. Sin esa cuenta definida y activa, la clave no sirve. Solo dentro del
   servidor o la red Tailscale, salvo CRM_AGENTE_PUBLICO. */
$clave = isset($_SERVER['HTTP_X_CRM_CLAVE']) ? (string) $_SERVER['HTTP_X_CRM_CLAVE'] : '';
$esAgente = $clave !== '' && defined('CRM_CLAVE_AGENTE') && strlen(CRM_CLAVE_AGENTE) >= 16 && hash_equals(CRM_CLAVE_AGENTE, $clave);
if ($clave !== '' && !$esAgente) {
    if ($origen !== 'local') registrarFallo($INTENTOS, $CABECERA, $ipCliente);
    usleep(500000);
    responder(401, ['error' => 'Clave incorrecta.']);
}
if ($esAgente && ($origen === 'cloudflare' || ($origen === 'publico' && !(defined('CRM_AGENTE_PUBLICO') && CRM_AGENTE_PUBLICO === true)))) {
    if ($origen !== 'local') registrarFallo($INTENTOS, $CABECERA, $ipCliente);
    usleep(500000);
    responder(403, ['error' => 'La clave del agente no se acepta desde internet.']);
}
if ($esAgente && !in_array($accion, ['agente', 'agente-escribir'], true)) {
    responder(403, ['error' => 'Con la clave del agente solo se puede usar agente y agente-escribir.']);
}
// Toda petición de la pantalla trae la cabecera X-CRM: una página de otro sitio no puede agregarla (ni leer la respuesta)
if (!$esAgente && (!isset($_SERVER['HTTP_X_CRM']) || $_SERVER['HTTP_X_CRM'] !== '1')) {
    responder(403, ['error' => 'Petición no válida.']);
}

$esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || $porCloudflare;
$rutaCookie = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api.php')), '/') . '/';
function ponerCookie($valor, $hasta) {
    global $esHttps, $rutaCookie;
    setcookie('crm_sesion', $valor, ['expires' => $hasta, 'path' => $rutaCookie, 'secure' => $esHttps, 'httponly' => true, 'samesite' => 'Strict']);
}
$tokenSesion = isset($_COOKIE['crm_sesion']) ? (string) $_COOKIE['crm_sesion'] : '';
$agenteNav = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

/* ---------- Ingreso y salida ----------
   POST ?accion=entrar  {usuario, clave} | {desafio, nueva_clave} | {desafio, codigo}  (ver ctaEntrar)
   POST ?accion=salir   cierra la sesión de este navegador */
if ($accion === 'entrar') {
    if ($metodo !== 'POST') responder(405, ['error' => 'Usa POST.']);
    $in = json_decode((string) file_get_contents('php://input'));
    [$cod, $cuerpo, $tok] = ctaEntrar($CTA, $in, $ipCliente, $agenteNav);
    if ($cod === 401 && $origen !== 'local') { registrarFallo($INTENTOS, $CABECERA, $ipCliente); usleep(300000); }
    if ($tok) ponerCookie($tok, time() + (int) (ctaHorasSesion() * 3600));
    unset($cuerpo['fallo']);
    responder($cod, $cuerpo);
}
if ($accion === 'salir') {
    if ($metodo !== 'POST') responder(405, ['error' => 'Usa POST.']);
    $s = ctaToken($CTA, $tokenSesion, 'sesion');
    if ($s) { ctaEvento($CTA, $s['usuario_id'], $s['usuario_id'], 'salida', '', $ipCliente); ctaBorrarToken($CTA, $tokenSesion); }
    ponerCookie('', time() - 3600);
    responder(200, ['ok' => true]);
}

/* ---------- Quién hace la petición ---------- */
if ($esAgente) {
    $fila = defined('CRM_AGENTE_USUARIO') ? ctaFilaPorUsuario($CTA, CRM_AGENTE_USUARIO) : null;
    if (!$fila || !(int) $fila['activo']) responder(403, ['error' => 'La clave del agente no tiene una cuenta activa asociada (CRM_AGENTE_USUARIO en config.php).']);
} else {
    $fila = ctaSesionActual($CTA, $tokenSesion);
    if (!$fila) { if ($tokenSesion !== '') ponerCookie('', time() - 3600); responder(401, ['error' => 'Inicia sesión con tu usuario.', 'sesion' => false]); }
}
$YO = ctaPublico($fila);
$CTX = permCtx($YO, ctaDirectorio($CTA));
$USUARIO_ACTUAL = $YO->id;   // queda en «cambios» junto con cada alta, cambio y baja

if ($accion === 'sesion') responder(200, ['usuario' => $YO]);

// Cambiar la propia clave: pide la actual. Cierra las otras sesiones de la persona.
if ($accion === 'cambiar-clave') {
    if ($metodo !== 'POST') responder(405, ['error' => 'Usa POST.']);
    $in = json_decode((string) file_get_contents('php://input'));
    if (!is_object($in) || !password_verify(isset($in->actual) ? (string) $in->actual : '', $fila['clave_hash'])) { usleep(300000); responder(400, ['error' => 'La clave actual no es correcta.']); }
    if (password_verify(isset($in->nueva) ? (string) $in->nueva : '', $fila['clave_hash'])) responder(400, ['error' => 'La clave nueva debe ser distinta de la actual.']);
    $e = ctaCambiarClave($CTA, $YO->id, isset($in->nueva) ? (string) $in->nueva : '');
    if ($e) responder(400, ['error' => $e]);
    ctaCerrarSesiones($CTA, $YO->id);
    ponerCookie(ctaNuevoToken($CTA, $YO->id, 'sesion', ctaHorasSesion() * 3600, null, $ipCliente, $agenteNav), time() + (int) (ctaHorasSesion() * 3600));
    responder(200, ['ok' => true]);
}

/* ---------- Administración de cuentas (solo administrador) ----------
   GET  ?accion=usuarios → cuentas con su estado
   POST ?accion=usuarios {op: crear | editar | clave | segundo-factor | traspasar, …} */
if ($accion === 'usuarios') {
    if (!$CTX->admin) responder(403, ['error' => 'Solo el administrador administra las cuentas.']);
    if ($metodo === 'GET') {
        $out = [];
        foreach ($CTA->query('SELECT * FROM usuarios ORDER BY activo DESC, nombre') as $r) {
            $p = ctaPublico($r);
            $p->segundoFactor = (bool) $r['totp_activo']; $p->claveTemporal = (bool) $r['clave_temporal'];
            $p->bloqueada = (int) $r['bloqueado_hasta'] > time(); $p->ultimoIngreso = $r['ultimo_ingreso'];
            $out[] = $p;
        }
        responder(200, ['usuarios' => $out, 'roles' => CTA_ETIQUETAS]);
    }
    if ($metodo !== 'POST') responder(405, ['error' => 'Método no permitido.']);
    $in = json_decode((string) file_get_contents('php://input'));
    $op = is_object($in) && isset($in->op) ? (string) $in->op : '';
    $id = is_object($in) && isset($in->id) ? (string) $in->id : '';
    if ($op === 'crear') {
        [$e, $nid, $temp] = ctaCrear($CTA, $in, $YO->id);
        if ($e) responder(400, ['error' => $e]);
        responder(200, ['ok' => true, 'id' => $nid, 'claveTemporal' => $temp]);
    }
    if ($op === 'editar') {
        if ($id === $YO->id && isset($in->activo) && empty($in->activo)) responder(400, ['error' => 'No puedes desactivar tu propia cuenta.']);
        $e = ctaEditar($CTA, $id, $in, $YO->id);
        if ($e) responder(400, ['error' => $e]);
        responder(200, ['ok' => true]);
    }
    if ($op === 'clave') {
        [$temp, $e] = ctaReiniciarClave($CTA, $id, $YO->id);
        if ($e) responder(400, ['error' => $e]);
        responder(200, ['ok' => true, 'claveTemporal' => $temp]);
    }
    if ($op === 'segundo-factor') {
        $e = ctaReiniciarSegundoFactor($CTA, $id, $YO->id);
        if ($e) responder(400, ['error' => $e]);
        responder(200, ['ok' => true]);
    }
    if ($op === 'traspasar') {
        // La cartera de una persona pasa a otra: prospectos, planes, oportunidades, ofertas, tareas abiertas y empresas a su cargo.
        // Las actividades y las tareas cerradas siguen con quien las hizo (el historial conserva quién hizo cada cosa).
        $de = is_object($in) && isset($in->de) ? (string) $in->de : ''; $a = is_object($in) && isset($in->a) ? (string) $in->a : '';
        $fa = ctaFila($CTA, $a);
        if ($de === '' || !ctaFila($CTA, $de) || !$fa || !(int) $fa['activo'] || $de === $a) responder(400, ['error' => 'Elige a la persona que entrega y a una persona activa distinta que recibe.']);
        $PDO = almAbrir($DIR_DATOS, $ARCHIVO, $CABECERA);
        $lock = fopen($DIR_DATOS . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) responder(500, ['error' => 'No se pudo bloquear el archivo de datos.']);
        $actual = leerActual();
        if (!$actual->data) { flock($lock, LOCK_UN); responder(409, ['error' => 'El CRM aún no tiene datos.']); }
        $n = [];
        foreach (['leads' => 'ownerId', 'plans' => 'ownerId', 'opps' => 'ownerId', 'offers' => 'ownerId', 'companies' => 'ownerId', 'tasks' => 'assigneeId'] as $t => $campo)
            foreach (permLista($actual->data, $t) as $x) {
                if (permTxt($x, $campo) !== $de) continue;
                if ($t === 'tasks' && !in_array(permTxt($x, 'status'), ['Pendiente', 'En curso'], true)) continue;
                $x->$campo = $a; $n[$t] = ($n[$t] ?? 0) + 1;
            }
        if (isset($actual->data->config->targets->$de) && !isset($actual->data->config->targets->$a)) { $actual->data->config->targets->$a = $actual->data->config->targets->$de; }
        $nrev = (int) $actual->rev + 1;
        $err = $n ? escribirDatos($actual->data, $nrev, isset($actual->data->savedAt) ? $actual->data->savedAt : 0, 'traspaso') : '';
        flock($lock, LOCK_UN);
        if ($err) responder(500, ['error' => $err]);
        ctaEvento($CTA, $de, $YO->id, 'cartera traspasada', 'a ' . $fa['nombre'] . ': ' . json_encode($n, JSON_UNESCAPED_UNICODE));
        responder(200, ['ok' => true, 'traspasados' => (object) $n, 'rev' => $n ? $nrev : null]);
    }
    responder(400, ['error' => 'Operación no reconocida.']);
}

/* ---------- Chat con Joan ----------
   El Joan de la versión de una sola persona (servicio joan-chat del servidor de Claudio) no se usa
   aquí. Joan por usuario, con la clave de API de cada uno, llega en una fase posterior. */
if ($accion === 'joan' || $accion === 'joan-respuesta' || $accion === 'joan-estado') {
    responder(403, ['error' => 'Joan por usuario todavía no está disponible.']);
}

$PDO = almAbrir($DIR_DATOS, $ARCHIVO, $CABECERA);   // null: se sigue con el JSON (sin historial ni recuperación)

// Lo que esta persona ve de un documento {rev, savedAt, data}
function vistaDe($doc) {
    global $CTX;
    $v = (object) ['rev' => $doc->rev, 'savedAt' => $doc->savedAt, 'data' => $doc->data ? permVista($doc->data, $CTX) : null];
    return $v;
}

// Conflictos: quién y cuándo cambió por última vez cada registro (con la base; con el JSON no hay ese dato)
function guardQuien($conf) {
    global $PDO, $CTX;
    foreach ($conf as &$c) {
        $c['quien'] = ''; $c['en'] = '';
        if (!$PDO) continue;
        $st = $PDO->prepare('SELECT usuario, en, origen FROM cambios WHERE tabla = ? AND registro = ? ORDER BY n DESC LIMIT 1'); $st->execute([$c['t'], $c['id']]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if ($f) { $c['quien'] = isset($CTX->dir[$f['usuario']]) ? $CTX->dir[$f['usuario']]->name : ($f['origen'] === 'Joan' ? 'Joan' : ''); $c['en'] = $f['en']; }
    }
    return $conf;
}

/* ---------- Lectura ---------- */
if ($metodo === 'GET') {
    if ($accion === 'historial') {   // historial completo de un registro que la persona puede ver
        $t = isset($_GET['tabla']) ? (string) $_GET['tabla'] : ''; $i = isset($_GET['id']) ? (string) $_GET['id'] : '';
        if (!$PDO) responder(200, ['almacen' => 'json', 'historial' => []]);
        $st = $PDO->prepare('SELECT datos FROM registros WHERE tabla = ? AND id = ?'); $st->execute([$t, $i]); $dat = $st->fetchColumn();
        if ($t === '' || $i === '' || $dat === false || !permVeRegistro($CTX, leerActual()->data, $t, json_decode($dat))) responder(200, ['almacen' => 'sqlite', 'historial' => []]);
        responder(200, ['almacen' => 'sqlite', 'historial' => almHistorial($PDO, $t, $i)]);
    }
    if ($accion === 'eliminados') {   // registros eliminados que la persona puede recuperar
        if (!$PDO) responder(200, ['almacen' => 'json', 'eliminados' => []]);
        $full = leerActual()->data;
        responder(200, ['almacen' => 'sqlite', 'eliminados' => almEliminados($PDO, 100, function ($t, $d) use ($full) { global $CTX; return permRecupera($CTX, $full, $t, $d); })]);
    }
    if ($accion === 'rev') {   // liviano: sin leer todos los datos
        responder(200, ['rev' => $PDO ? (int) almMeta($PDO, 'rev') : (int) leerDatos($ARCHIVO, $CABECERA)->rev, 'savedAt' => $PDO ? almMeta($PDO, 'savedAt') : null, 'ajustes' => permAjustesVista(leerAjustes($AJUSTES, $CABECERA), $CTX), 'almacen' => $esAgente ? null : almEstado($PDO, $DIR_DATOS)]);
    }
    $actual = leerActual();
    $ajustes = permAjustesVista(leerAjustes($AJUSTES, $CABECERA), $CTX);
    if ($accion === 'agente') {
        if (!$actual->data) responder(200, resumenAgente((object) ['rev' => 0, 'data' => (object) []], $ajustes));
        responder(200, resumenAgente(vistaDe($actual), $ajustes));
    }
    if ($esAgente) responder(403, ['error' => 'Con la clave del agente solo se puede usar agente y agente-escribir.']);
    $v = vistaDe($actual);
    $v->ajustes = $ajustes;
    $v->almacen = almEstado($PDO, $DIR_DATOS);
    $v->yo = $YO;
    $v->usuarios = permVista((object) [], $CTX)->users;
    responder(200, $v);
}

/* ---------- Escritura ---------- */
if ($metodo === 'POST') {
    $entrada = file_get_contents('php://input');
    if (isset($_SERVER['HTTP_X_CRM_GZIP']) && $_SERVER['HTTP_X_CRM_GZIP'] === '1') {
        $entrada = function_exists('gzdecode') ? @gzdecode($entrada) : false;
        if ($entrada === false) responder(400, ['error' => 'No se pudieron descomprimir los datos (falta zlib en el PHP del hosting).']);
    }
    $cuerpo = json_decode($entrada);

    // Cambios acotados del agente: se aplican sobre lo que ve su cuenta y pasan por los mismos permisos
    if ($accion === 'agente-escribir') {
        if (!is_object($cuerpo) || !isset($cuerpo->ops) || !is_array($cuerpo->ops) || !$cuerpo->ops) responder(400, ['error' => 'Envía {"ops":[...]} con al menos un cambio.']);
        if (count($cuerpo->ops) > 50) responder(400, ['error' => 'Máximo 50 cambios por llamada.']);
        $lock = fopen($DIR_DATOS . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) responder(500, ['error' => 'No se pudo bloquear el archivo de datos.']);
        $actual = leerActual();
        if (!$actual->data) { flock($lock, LOCK_UN); responder(409, ['error' => 'El CRM aún no tiene datos.']); }
        $vista = permVista($actual->data, $CTX);
        try { $hecho = aplicarAgente($vista, $cuerpo->ops); $nuevo = permAplicar($actual->data, $vista, $CTX); guardCodigos($actual->data, $nuevo, $CTX); }
        catch (Exception $e) { flock($lock, LOCK_UN); responder(400, ['error' => $e->getMessage()]); }
        if ($CTX->avisos) { flock($lock, LOCK_UN); responder(403, ['error' => implode(' ', $CTX->avisos)]); }
        $nrev = (int) $actual->rev + 1;
        $err = escribirDatos($nuevo, $nrev, isset($actual->data->savedAt) ? $actual->data->savedAt : 0, 'Joan');
        flock($lock, LOCK_UN);
        if ($err) responder(500, ['error' => $err]);
        responder(200, ['ok' => true, 'rev' => $nrev, 'hecho' => $hecho]);
    }
    if ($esAgente) responder(403, ['error' => 'Con la clave del agente solo se puede usar agente y agente-escribir.']);

    // Recuperar un registro eliminado (vuelve con sus últimos datos; queda en el historial)
    if ($accion === 'restaurar') {
        if (!$PDO) responder(409, ['error' => 'Recuperar registros requiere la base de datos.']);
        $t = isset($cuerpo->tabla) ? (string) $cuerpo->tabla : ''; $i = isset($cuerpo->id) ? (string) $cuerpo->id : '';
        $lock = fopen($DIR_DATOS . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) responder(500, ['error' => 'No se pudo bloquear el archivo de datos.']);
        $st = $PDO->prepare('SELECT datos FROM registros WHERE tabla = ? AND id = ? AND eliminado_en IS NOT NULL'); $st->execute([$t, $i]); $dat = $st->fetchColumn();
        if ($dat === false) { flock($lock, LOCK_UN); responder(404, ['error' => 'No hay un registro eliminado con ese id.']); }
        if (!permRecupera($CTX, leerActual()->data, $t, json_decode($dat))) { flock($lock, LOCK_UN); responder(403, ['error' => 'No tienes permiso para recuperar ese registro.']); }
        try { $PDO->exec('BEGIN IMMEDIATE'); $nrev = almRestaurar($PDO, $t, $i, 'pantalla', $USUARIO_ACTUAL); $PDO->exec('COMMIT'); }
        catch (Exception $e) { try { $PDO->exec('ROLLBACK'); } catch (Exception $x) {} flock($lock, LOCK_UN); responder(500, ['error' => 'No se pudo recuperar.']); }
        if (!$nrev) { flock($lock, LOCK_UN); responder(404, ['error' => 'No hay un registro eliminado con ese id.']); }
        $doc = almLeer($PDO);
        escribirDatos($doc->data, $nrev, $doc->savedAt, 'pantalla');   // reescribe igual: deja el JSON al día
        flock($lock, LOCK_UN);
        responder(200, ['ok' => true, 'rev' => $nrev]);
    }

    // Parámetros de configuración: archivo propio, sin control de revisión. Solo el administrador.
    if ($accion === 'ajustes') {
        if (!$CTX->admin) responder(403, ['error' => 'Solo el administrador cambia la configuración.']);
        if (!is_object($cuerpo) || !isset($cuerpo->ajustes) || !is_object($cuerpo->ajustes)) {
            responder(400, ['error' => 'Los ajustes enviados no tienen el formato esperado.']);
        }
        $json = json_encode($cuerpo->ajustes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || strlen($json) > 100000) responder(400, ['error' => 'Los ajustes no son válidos.']);
        $tmp = $AJUSTES . '.tmp';
        if (file_put_contents($tmp, $CABECERA . $json, LOCK_EX) === false || !rename($tmp, $AJUSTES)) {
            responder(500, ['error' => 'No se pudo escribir el archivo de ajustes. Revisa los permisos de la carpeta datos.']);
        }
        responder(200, ['ok' => true]);
    }

    if ($accion !== 'leer' && $accion !== '') responder(400, ['error' => 'Acción no reconocida.']);

    // Guardado registro por registro (guardado.php): solo lo que cambió, mezclado con lo que guardaron otras personas
    if (is_object($cuerpo) && isset($cuerpo->cambios) && !isset($cuerpo->data)) {
        $lock = fopen($DIR_DATOS . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) responder(500, ['error' => 'No se pudo bloquear el archivo de datos.']);
        $actual = leerActual();
        if ($actual->data === null) { flock($lock, LOCK_UN); responder(409, ['error' => 'El CRM aún no tiene datos: el administrador debe iniciarlo.', 'rev' => (int) $actual->rev, 'data' => null]); }
        $base = isset($cuerpo->baseRev) ? (int) $cuerpo->baseRev : -1;
        try {
            list($vMezcla, $conflictos, $tocados) = guardMezclar(permVista($actual->data, $CTX, false), $cuerpo->cambios, $CTX);
            $nuevo = permAplicar($actual->data, $vMezcla, $CTX);
        } catch (Exception $e) {
            if (!($e instanceof GuardRechazo) && !($e instanceof PermRechazo)) throw $e;
            flock($lock, LOCK_UN); $v = vistaDe($actual); $v->error = $e->getMessage(); $v->rechazo = true; responder(422, $v);
        }
        $avisos = $CTX->avisos;
        list($avCod, $renombres) = guardCodigos($actual->data, $nuevo, $CTX);
        $avisos = array_merge($avisos, $avCod);
        list($errBaja, $extra) = validarBajas($actual->data, $nuevo);
        if ($errBaja !== '') { flock($lock, LOCK_UN); $v = vistaDe($actual); $v->error = $errBaja; $v->rechazo = true; responder(422, $v); }
        $nrev = (int) $actual->rev + 1;
        $err = escribirDatos($nuevo, $nrev, isset($nuevo->savedAt) ? $nuevo->savedAt : 0, 'pantalla', $extra);
        flock($lock, LOCK_UN);
        if ($err) responder(500, ['error' => $err]);
        $vf = permVista($nuevo, $CTX);
        $r = ['rev' => $nrev];
        if ($conflictos) $r['conflictos'] = guardQuien($conflictos);
        if ($avisos) $r['avisos'] = $avisos;
        if ($renombres) $r['codigos'] = $renombres;
        // Si alguien más guardó entremedio (o algo no se aplicó), la pantalla recibe su vista completa; si no, solo lo que tocó
        if ($base !== (int) $actual->rev || $conflictos || $avisos) $r['data'] = $vf; else $r += guardDevolver($vf, $tocados);
        responder(200, $r);
    }

    if (!is_object($cuerpo) || !isset($cuerpo->data) || !is_object($cuerpo->data)
        || !isset($cuerpo->data->companies) || !is_array($cuerpo->data->companies)) {
        responder(400, ['error' => 'Los datos enviados no tienen el formato del CRM.']);
    }
    $reemplazo = isset($cuerpo->reemplazo) && $cuerpo->reemplazo === true;
    if ($reemplazo && !$CTX->admin) responder(403, ['error' => 'Solo el administrador puede reemplazar todos los datos (restaurar un respaldo o empezar de cero).']);

    $lock = fopen($DIR_DATOS . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) responder(500, ['error' => 'No se pudo bloquear el archivo de datos.']);

    $actual = leerActual();
    if ($actual->data === null && !$CTX->admin) { flock($lock, LOCK_UN); responder(403, ['error' => 'El CRM aún no tiene datos: el administrador debe iniciarlo.']); }
    $base = isset($cuerpo->baseRev) ? (int) $cuerpo->baseRev : -1;
    if ($actual->data !== null && $base !== (int) $actual->rev) {
        flock($lock, LOCK_UN);
        responder(409, vistaDe($actual));   // otra persona u otro dispositivo guardó antes
    }

    // Cada alta, cambio y baja se revisa contra los permisos y se aplica sobre los datos completos
    if ($reemplazo || $actual->data === null) {
        $nuevo = permCopia($cuerpo->data);
        foreach (PERM_NO_GUARDAR as $k) unset($nuevo->$k);
    } else {
        try { $nuevo = permAplicar($actual->data, $cuerpo->data, $CTX); }
        catch (PermRechazo $e) { flock($lock, LOCK_UN); $v = vistaDe($actual); $v->error = $e->getMessage(); $v->rechazo = true; responder(422, $v); }
    }
    $avisos = $CTX->avisos;
    $renombres = [];
    if (!$reemplazo && $actual->data !== null) { list($avCod, $renombres) = guardCodigos($actual->data, $nuevo, $CTX); $avisos = array_merge($avisos, $avCod); }

    // Empresas y contactos que dejan de venir: solo sin historial o fusionados (ver validarBajas)
    $extra = [];
    if ($actual->data !== null && !$reemplazo) {
        list($errBaja, $extra) = validarBajas($actual->data, $nuevo);
        if ($errBaja !== '') {
            flock($lock, LOCK_UN);
            $v = vistaDe($actual); $v->error = $errBaja; $v->rechazo = true;
            responder(422, $v);   // la pantalla vuelve a la versión del servidor y muestra el motivo
        }
    }

    $nrev = (int) $actual->rev + 1;
    $err = escribirDatos($nuevo, $nrev, isset($cuerpo->data->savedAt) ? $cuerpo->data->savedAt : 0, 'pantalla', $extra);
    flock($lock, LOCK_UN);
    if ($err) responder(500, ['error' => $err]);
    // La copia completa se usa solo al iniciar, al restaurar o al subir cambios hechos sin conexión: la pantalla
    // recibe siempre su vista como quedó (con lo que puso el servidor: códigos, trazabilidad, lo no permitido fuera)
    $r = ['rev' => $nrev, 'data' => permVista($nuevo, $CTX)];
    if ($avisos) $r['avisos'] = $avisos;
    if ($renombres) $r['codigos'] = $renombres;
    responder(200, $r);
}

responder(405, ['error' => 'Método no permitido.']);
