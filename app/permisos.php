<?php
/* =========================================================================
   CRM FS - permisos por rol y equipo, aplicados en el servidor

   Quién ve qué (lo que no se puede ver nunca sale del servidor):
   - Empresas y contactos: compartidos, todos los ven.
   - Prospectos, planes, oportunidades, ofertas, tareas y actividades: por dueño
     (ownerId; en actividades userId, en tareas assigneeId). Facturas y actas van con
     el dueño de su oportunidad. Una actividad o tarea de otra persona sobre una
     oportunidad, prospecto u oferta que sí ves también se ve. La tarea no se puede
     editar; la actividad sí la actualiza quien tiene al dueño del registro en su alcance
     (sin cambiar su autor ni su registro: ver permEditaActividad).
   - Alcance: ejecutivo = lo suyo; subgerente = lo suyo y lo de su equipo;
     gerente y administrador = todo.
   - Metas (config.targets): solo las de las personas del alcance. La meta del Excel
     de presupuesto (config.metas) y el resumen del último funnel (fxLast), solo
     gerente y administrador.

   Quién cambia qué:
   - En lo que tiene dueño: lo de su alcance, y al crear o traspasar, solo hacia
     personas a las que puede asignar (ejecutivo: a sí mismo; subgerente: a su
     equipo; gerente y administrador: a cualquiera).
   - Empresas y contactos: cualquier persona los crea. Un ejecutivo no cambia los datos de una
     empresa que creó otra persona (pasa con grandes corporaciones, donde trabajan varios
     ejecutivos): en ella solo agrega contactos, sitios, prospectos y oportunidades. Tampoco
     cambia contactos que creó otra persona. Subgerente, gerente y administrador editan todo.
     Trazabilidad: el servidor anota createdBy al crear y updatedBy/updatedAt en cada cambio
     (la pantalla no puede cambiar esos campos), y «cambios» guarda la persona, el valor
     anterior y el nuevo. El responsable de la empresa (ownerId) solo lo cambia quien puede
     asignar a la persona nueva y a la anterior.
   - Eliminar o fusionar una empresa o un contacto: solo el administrador (y siempre que
     no tenga historial: ver validarBajas).
   - Configuración, modo y reemplazo completo de los datos: solo el administrador.
   - Auditoría de la pantalla: solo se agregan movimientos, firmados por quien guarda.

   La pantalla sigue enviando su copia completa (la que ve). permAplicar compara esa
   copia con lo que esa persona veía, revisa cada alta, cambio y baja contra estas
   reglas y aplica el resultado sobre los datos completos, sin tocar lo que no ve.
   ========================================================================= */

const PERM_DUENO = ['leads' => 'ownerId', 'opps' => 'ownerId', 'offers' => 'ownerId', 'plans' => 'ownerId',
    'activities' => 'userId', 'tasks' => 'assigneeId', 'audit' => 'userId', 'invoices' => '', 'recepciones' => ''];
const PERM_COMPARTIDAS = ['companies', 'contacts'];
const PERM_PUBLICAS = ['fusiones'];
const PERM_NO_GUARDAR = ['users', 'currentUser'];
const PERM_DOC_TODOS = ['v', 'mode', 'lastBackup', 'savedAt', 'seq', 'config'];   // claves del documento que ve cualquier rol
const PERM_MAX_AUDIT = 2000;

class PermRechazo extends Exception {}

function permCopia($x) { return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); }
function permLista($o, $k) { return is_object($o) && isset($o->$k) && is_array($o->$k) ? $o->$k : []; }
function permTxt($o, $k) { return is_object($o) && isset($o->$k) && is_scalar($o->$k) ? (string) $o->$k : ''; }

/* Contexto de permisos de una persona. $u y $directorio vienen de ctaPublico / ctaDirectorio. */
function permCtx($u, $directorio) {
    $dir = []; foreach ($directorio as $x) $dir[$x->id] = $x;
    $todo = $u->admin || in_array($u->role, ['gerente', 'admin'], true);
    $alc = null; $asign = null;
    if (!$todo) {
        if ($u->role === 'subgerente' && $u->equipo !== '') {
            $alc = []; $asign = [];
            foreach ($dir as $x) if ($x->equipo === $u->equipo) { $alc[$x->id] = 1; if ($x->activo) $asign[$x->id] = 1; }
        } else { $alc = [$u->id => 1]; $asign = [$u->id => 1]; }
        $alc[$u->id] = 1; $asign[$u->id] = 1;
    }
    return (object) ['u' => $u, 'id' => $u->id, 'dir' => $dir, 'todo' => $todo, 'admin' => (bool) $u->admin, 'avisos' => [],
        'alc' => $alc, 'asign' => $asign, 'sub' => $u->role === 'subgerente' && !$todo];
}
function permEnAlcance($ctx, $id) { return $ctx->alc === null || ($id !== '' && isset($ctx->alc[$id])); }
function permAsignable($ctx, $id) {
    if ($ctx->asign === null) return $id === '' || (isset($ctx->dir[$id]) && $ctx->dir[$id]->activo);
    return $id !== '' && isset($ctx->asign[$id]);
}

// Índices de los datos completos para resolver dueños (ofertas, facturas y actas por su oportunidad; vínculos de tareas y actividades)
function permIndice($d) {
    $i = ['opps' => [], 'leads' => [], 'offers' => []];
    foreach (['opps', 'leads', 'offers'] as $t) foreach (permLista($d, $t) as $x) if (is_object($x) && isset($x->id)) $i[$t][(string) $x->id] = $x;
    return $i;
}
function permDueno($tabla, $x, $idx) {
    if ($tabla === 'invoices' || $tabla === 'recepciones') { $o = $idx['opps'][permTxt($x, 'oppId')] ?? null; return $o ? permTxt($o, 'ownerId') : ''; }
    $k = PERM_DUENO[$tabla];
    $v = permTxt($x, $k);
    if ($v === '' && $tabla === 'offers') { $o = $idx['opps'][permTxt($x, 'oppId')] ?? null; if ($o) $v = permTxt($o, 'ownerId'); }
    return $v;
}
// Registro al que está vinculada una actividad o tarea (oportunidad, prospecto u oferta), o null
function permVinculo($x, $idx) {
    $m = ['opp' => 'opps', 'lead' => 'leads', 'offer' => 'offers'];
    $t = permTxt($x, 'entityType');
    if (!isset($m[$t])) return null;
    $r = $idx[$m[$t]][permTxt($x, 'entityId')] ?? null;
    return $r ? [$m[$t], $r] : null;
}
// ¿Puede ver este registro? (en lo compartido, siempre)
function permVe($ctx, $tabla, $x, $idx) {
    if (in_array($tabla, PERM_COMPARTIDAS, true) || in_array($tabla, PERM_PUBLICAS, true)) return true;
    if (!isset(PERM_DUENO[$tabla])) return $ctx->alc === null;
    if ($ctx->alc === null) return true;
    if (permEnAlcance($ctx, permDueno($tabla, $x, $idx))) return true;
    if ($tabla === 'activities' || $tabla === 'tasks') { $v = permVinculo($x, $idx); return $v && permEnAlcance($ctx, permDueno($v[0], $v[1], $idx)); }
    return false;
}

/* ---------- Lectura: lo que esta persona puede ver ---------- */
function permVista($full, $ctx, $conUsuarios = true) {
    if (!is_object($full)) return $full;
    $idx = permIndice($full);
    $v = new stdClass();
    foreach (get_object_vars($full) as $k => $val) {
        if (in_array($k, PERM_NO_GUARDAR, true)) continue;
        if (isset(PERM_DUENO[$k]) || in_array($k, PERM_COMPARTIDAS, true) || in_array($k, PERM_PUBLICAS, true)) {
            $v->$k = is_array($val) ? array_values(array_filter($val, function ($x) use ($ctx, $k, $idx) { return is_object($x) && permVe($ctx, $k, $x, $idx); })) : $val;
            continue;
        }
        if ($ctx->alc !== null && !in_array($k, PERM_DOC_TODOS, true)) continue;   // otras claves (fxLast, colecciones nuevas): solo gerente y administrador
        $v->$k = $k === 'config' ? permConfigVista($val, $ctx) : $val;
    }
    $v = permCopia($v);
    if ($conUsuarios) {
        $v->users = array_values(array_map(function ($x) { return (object) ['id' => $x->id, 'name' => $x->name, 'role' => $x->role, 'roleLabel' => $x->roleLabel,
            'equipo' => $x->equipo, 'admin' => $x->admin, 'activo' => $x->activo]; }, $ctx->dir));
        $v->currentUser = $ctx->id;
    }
    return $v;
}
function permConfigVista($cfg, $ctx) {
    if (!is_object($cfg)) return $cfg;
    $c = permCopia($cfg);
    if ($ctx->alc !== null) {
        if (isset($c->targets) && is_object($c->targets)) foreach (array_keys(get_object_vars($c->targets)) as $id) if (!permEnAlcance($ctx, (string) $id)) unset($c->targets->$id);
        unset($c->metas);
    }
    return $c;
}
// Los ajustes (datos/ajustes.json.php) llevan la misma configuración: se filtran igual
function permAjustesVista($aj, $ctx) { return is_object($aj) ? permConfigVista($aj, $ctx) : $aj; }

/* ---------- Escritura ---------- */
function permNombre($tabla, $x) {
    foreach (['code', 'name', 'tradeName', 'legalName', 'title', 'entity'] as $k) if (permTxt($x, $k) !== '') return permTxt($x, $k);
    if (permTxt($x, 'firstName') !== '') return trim(permTxt($x, 'firstName') . ' ' . permTxt($x, 'lastName'));
    return permTxt($x, 'id');
}
const PERM_TABLAS = ['companies' => 'la empresa', 'contacts' => 'el contacto', 'leads' => 'el prospecto', 'opps' => 'la oportunidad', 'offers' => 'la oferta',
    'plans' => 'el plan', 'activities' => 'la actividad', 'tasks' => 'la tarea', 'invoices' => 'la factura', 'recepciones' => 'el acta de recepción'];
// Lo que no está permitido no se aplica (el servidor deja su versión) y queda como aviso para la pantalla
function permNo($ctx, $que, $tabla, $x, $extra = '') { $ctx->avisos[] = "No tienes permiso para $que " . (PERM_TABLAS[$tabla] ?? 'el registro') . ' «' . permNombre($tabla, $x) . "»$extra."; }

// Campos de trazabilidad de empresas y contactos: los pone el servidor
const PERM_TRAZA = ['createdBy', 'updatedBy', 'updatedAt'];
// ¿Puede cambiar esta empresa o contacto? Un ejecutivo, solo lo que creó; en una empresa ajena solo agrega sitios nuevos.
function permEditaCompartido($ctx, $tabla, $antes, $x) {
    if ($ctx->alc === null || $ctx->sub || permTxt($antes, 'createdBy') === $ctx->id) return true;
    if ($tabla !== 'companies') return false;
    $a = permCopia($antes); $b = permCopia($x);
    $sa = permLista($a, 'sites'); $sb = permLista($b, 'sites');
    if (count($sb) < count($sa) || json_encode(array_slice($sb, 0, count($sa))) !== json_encode($sa)) return false;   // los sitios que había no cambian
    unset($a->sites, $b->sites);
    return json_encode($a) === json_encode($b);
}
function permBorraCompartido($ctx, $x) { return $ctx->admin; }
// Actividad que registró otra persona sobre un registro del alcance (oportunidad, prospecto u oferta): el dueño del
// registro, su subgerente y el gerente pueden actualizarla (resultado, próxima acción, descripción…), pero no cambiar
// quién la registró ni moverla a otro registro. Las de una empresa quedan solo para el autor y su alcance.
function permEditaActividad($ctx, $tabla, $antes, $x, $idx) {
    if ($tabla !== 'activities') return false;
    foreach (['userId', 'entityType', 'entityId', 'companyId'] as $c) if (permTxt($x, $c) !== permTxt($antes, $c)) return false;
    $v = permVinculo($antes, $idx);
    return $v && permEnAlcance($ctx, permDueno($v[0], $v[1], $idx));
}

/* Aplica sobre $full (datos completos) la copia $nuevo que envió la pantalla de esta persona.
   Devuelve los datos completos resultantes. Lo no permitido queda fuera y se anota en $ctx->avisos
   (la pantalla recibe esos avisos y vuelve a cargar su vista). Lanza PermRechazo si los datos vienen mal formados. */
function permAplicar($full, $nuevo, $ctx) {
    if (!is_object($nuevo)) throw new PermRechazo('Los datos enviados no tienen el formato del CRM.');
    $ctx->avisos = [];
    $full = is_object($full) ? permCopia($full) : new stdClass();
    $nuevo = permCopia($nuevo);
    $previo = permVista($full, $ctx, false);
    $idx = permIndice($full); $idxN = permIndice($nuevo);
    // el dueño de facturas, actas y ofertas sin dueño propio se resuelve con las oportunidades vigentes y las que llegan
    $idxAmbos = ['opps' => $idx['opps'] + $idxN['opps'], 'leads' => $idx['leads'] + $idxN['leads'], 'offers' => $idx['offers'] + $idxN['offers']];
    $out = new stdClass();
    $claves = array_unique(array_merge(array_keys(get_object_vars($full)), array_keys(get_object_vars($nuevo))));
    foreach ($claves as $k) {
        if (in_array($k, PERM_NO_GUARDAR, true)) continue;
        $tiene = property_exists($nuevo, $k); $antes = property_exists($full, $k) ? $full->$k : null;
        $esCol = isset(PERM_DUENO[$k]) || in_array($k, PERM_COMPARTIDAS, true) || in_array($k, PERM_PUBLICAS, true);
        if ($esCol) {
            if (!$tiene || !is_array($nuevo->$k)) { if ($antes !== null) $out->$k = $antes; continue; }   // no vino: queda igual
            $out->$k = permFusionarLista($k, is_array($antes) ? $antes : [], permLista($previo, $k), $nuevo->$k, $ctx, $idxAmbos);
            continue;
        }
        // Claves del documento
        if ($k === 'seq') { $out->seq = permSeq($antes, $tiene ? $nuevo->seq : null); continue; }
        if ($ctx->admin) { if ($tiene) $out->$k = $nuevo->$k; continue; }                                        // administrador: lo que envía
        if (in_array($k, ['savedAt', 'lastBackup'], true)) { if ($tiene) $out->$k = $nuevo->$k; elseif ($antes !== null) $out->$k = $antes; continue; }
        if ($k === 'v') { $out->v = max((int) $antes, $tiene ? (int) $nuevo->v : 0); continue; }
        if ($ctx->alc === null && !in_array($k, ['config', 'mode'], true)) { if ($tiene) $out->$k = $nuevo->$k; continue; }   // gerente: p. ej. fxLast al importar el funnel
        if ($antes !== null) $out->$k = $antes;   // el resto (configuración, modo…) no lo cambia: queda como estaba
    }
    if (isset($out->audit) && is_array($out->audit) && count($out->audit) > PERM_MAX_AUDIT) $out->audit = array_slice($out->audit, 0, PERM_MAX_AUDIT);
    return $out;
}
function permSeq($a, $b) {
    $o = is_object($a) ? permCopia($a) : new stdClass();
    if (is_object($b)) foreach (get_object_vars($b) as $k => $v) if (is_numeric($v)) $o->$k = max(isset($o->$k) && is_numeric($o->$k) ? $o->$k + 0 : 0, $v + 0);
    return $o;
}

function permFusionarLista($tabla, $full, $previo, $nuevo, $ctx, $idx) {
    $porId = function ($l) use ($tabla) { $m = []; foreach ($l as $x) { if (!is_object($x) || !isset($x->id) || (string) $x->id === '') throw new PermRechazo("Hay un registro sin id en $tabla."); $m[(string) $x->id] = $x; } return $m; };
    $F = $porId($full); $P = $porId($previo); $N = $porId($nuevo);
    if (count($N) !== count($nuevo)) throw new PermRechazo("Hay registros con el id repetido en $tabla.");
    $j = function ($x) { return json_encode($x, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); };
    $compartida = in_array($tabla, PERM_COMPARTIDAS, true);
    $soloAgregar = $tabla === 'audit' || in_array($tabla, PERM_PUBLICAS, true);
    $quitar = []; $reemplazo = []; $altas = [];
    // Bajas
    foreach ($P as $id => $x) if (!isset($N[$id])) {
        if ($soloAgregar) continue;   // la auditoría y las fusiones no se borran desde la pantalla
        $ok = $compartida ? permBorraCompartido($ctx, $F[$id]) : permEnAlcance($ctx, permDueno($tabla, $F[$id], $idx));
        if (!$ok) { permNo($ctx, 'eliminar', $tabla, $F[$id]); continue; }
        $quitar[$id] = 1;
    }
    // Cambios
    foreach ($N as $id => $x) if (isset($P[$id]) && $j($x) !== $j($P[$id])) {
        if ($soloAgregar) continue;
        $antes = $F[$id];
        if ($compartida) {
            foreach (PERM_TRAZA as $c) { if (isset($antes->$c)) $x->$c = $antes->$c; else unset($x->$c); }
            if ($j($x) === $j($antes)) continue;   // solo faltaban los campos de trazabilidad
            if (!permEditaCompartido($ctx, $tabla, $antes, $x)) { permNo($ctx, 'cambiar los datos de', $tabla, $antes, $tabla === 'companies' ? ' (la creó otra persona: puedes agregarle contactos, sitios, prospectos y oportunidades)' : ' (lo creó otra persona)'); continue; }
            if ($tabla === 'companies' && permTxt($x, 'ownerId') !== permTxt($antes, 'ownerId')
                && !(permAsignable($ctx, permTxt($x, 'ownerId')) && (permTxt($antes, 'ownerId') === '' || permEnAlcance($ctx, permTxt($antes, 'ownerId'))))) {
                permNo($ctx, 'cambiar el responsable de', $tabla, $antes); continue;
            }
            $x->updatedBy = $ctx->id; $x->updatedAt = date('c');
        } else {
            $da = permDueno($tabla, $antes, $idx); $dn = permDueno($tabla, $x, $idx);
            if (!permEnAlcance($ctx, $da) && !permEditaActividad($ctx, $tabla, $antes, $x, $idx)) { permNo($ctx, 'cambiar', $tabla, $antes); continue; }
            if ($dn !== $da && !permAsignable($ctx, $dn)) { permNo($ctx, $tabla === 'tasks' ? 'asignar a esa persona' : 'traspasar a esa persona', $tabla, $antes); continue; }
        }
        $reemplazo[$id] = $x;
    }
    // Altas
    foreach ($N as $id => $x) if (!isset($P[$id])) {
        if (isset($F[$id])) {
            if (!$soloAgregar) permNo($ctx, 'crear', $tabla, $x, ' (choca con un registro que no ves)');
            continue;
        }
        if ($tabla === 'audit') { $x->userId = $ctx->id; }
        elseif (in_array($tabla, PERM_PUBLICAS, true)) { if (!$ctx->admin) continue; }
        elseif ($compartida) { foreach (PERM_TRAZA as $c) unset($x->$c); $x->createdBy = $ctx->id; }
        elseif (!permAsignable($ctx, permDueno($tabla, $x, $idx)) && !($ctx->alc === null && permDueno($tabla, $x, $idx) === '')) { permNo($ctx, 'crear', $tabla, $x); continue; }
        $altas[$id] = $x;
    }
    // Resultado: el orden de los datos completos, con las altas detrás del registro que las antecede en la pantalla
    $res = [];
    foreach ($full as $x) { $id = (string) $x->id; if (isset($quitar[$id])) continue; $res[] = isset($reemplazo[$id]) ? $reemplazo[$id] : $x; }
    if ($altas) {
        $pos = []; foreach ($res as $i => $x) $pos[(string) $x->id] = $i;
        $antes = null; $insertar = [];   // id anterior => [altas]
        foreach ($nuevo as $x) { $id = (string) $x->id; if (isset($altas[$id])) $insertar[$antes === null ? '' : $antes][] = $altas[$id]; elseif (isset($pos[$id])) $antes = $id; }
        $out = isset($insertar['']) ? $insertar[''] : [];
        foreach ($res as $x) { $out[] = $x; if (isset($insertar[(string) $x->id])) foreach ($insertar[(string) $x->id] as $a) $out[] = $a; }
        $res = $out;
    }
    return $res;
}

// ¿Puede ver el historial de este registro (vigente o eliminado)? $datos = sus últimos datos
function permVeRegistro($ctx, $full, $tabla, $datos) {
    if ($ctx->alc === null) return true;
    if (!is_object($datos)) return false;
    if ($tabla === 'doc' || $tabla === 'audit') return false;
    return permVe($ctx, $tabla, $datos, permIndice($full));
}
// ¿Puede recuperar este registro eliminado?
function permRecupera($ctx, $full, $tabla, $datos) {
    if ($ctx->admin) return true;
    if (!is_object($datos) || !isset(PERM_TABLAS[$tabla])) return false;
    if (in_array($tabla, PERM_COMPARTIDAS, true)) return false;   // empresas y contactos: solo el administrador
    return permEnAlcance($ctx, permDueno($tabla, $datos, permIndice($full)));
}
