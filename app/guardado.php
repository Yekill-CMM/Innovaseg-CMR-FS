<?php
/* =========================================================================
   CRM FS - guardado registro por registro y códigos automáticos

   Guardado por registro (para que varias personas trabajen a la vez sin pisarse)
   - La pantalla ya no manda su copia completa: manda solo lo que cambió desde la última
     versión que recibió del servidor, registro por registro:
       {baseRev, cambios: {registros: [{t, id, antes, despues, tras}], doc: [{k, antes, despues}]}}
     antes = el registro como lo tenía la pantalla (null si es nuevo); despues = como quedó
     (null si se eliminó); tras = id del registro que lo antecede en la lista (para el orden).
   - El servidor compara cada registro con su versión actual:
       · si nadie más lo tocó desde que la pantalla lo leyó, se aplica el cambio;
       · si otra persona lo cambió, pero en otros campos, se juntan los dos cambios;
       · si otra persona cambió los mismos campos (o lo eliminó), queda la versión del
         servidor y el registro vuelve como «conflicto» para que la persona lo revise.
   - El resultado pasa después por los mismos permisos de siempre (permAplicar), así que
     esto no abre nada que antes estuviera cerrado.

   Códigos automáticos (los asigna el servidor, que ve todo)
   - Oportunidades: OP-AAMMDD-NN (fecha de creación y correlativo del día). El código que
     trae la pantalla es provisional: el servidor pone el definitivo y lo corrige también en
     la auditoría, tareas y actividades nuevas que lo mencionan. El código de una oportunidad
     no cambia después de creada. En el CRM FS no entra nada por Excel: toda oportunidad
     nueva recibe su OP, venga con el código que venga.
   - Prospectos: si dos personas crean a la vez el mismo PR-AAAA-NNNN, el segundo recibe
     el siguiente número libre.
   ========================================================================= */

const GUARD_MAX_REGISTROS = 20000;
const GUARD_OP_RE = '/^OP-\d{6}-\d{2,}$/';

class GuardRechazo extends Exception {}

// Mismo contenido, sin importar el orden de las claves (para comparar versiones de un registro)
function guardCanon($v) {
    $a = json_decode(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), true);
    $orden = function (&$x) use (&$orden) { if (!is_array($x)) return; $lista = array_keys($x) === range(0, count($x) - 1); if (!$lista) ksort($x); foreach ($x as &$y) $orden($y); };
    $orden($a);
    return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
function guardIgual($a, $b) { return guardCanon($a) === guardCanon($b); }

// Valida la forma de los cambios que manda la pantalla
function guardValidar($c) {
    if (!is_object($c)) throw new GuardRechazo('Los cambios enviados no tienen el formato esperado.');
    $regs = isset($c->registros) ? $c->registros : [];
    $doc = isset($c->doc) ? $c->doc : [];
    if (!is_array($regs) || !is_array($doc)) throw new GuardRechazo('Los cambios enviados no tienen el formato esperado.');
    if (count($regs) > GUARD_MAX_REGISTROS) throw new GuardRechazo('Demasiados cambios en un solo guardado.');
    foreach ($regs as $r) {
        if (!is_object($r) || !isset($r->t) || !is_string($r->t) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/', $r->t)
            || !isset($r->id) || !(is_string($r->id) || is_int($r->id)) || (string) $r->id === '') throw new GuardRechazo('Hay un cambio sin tabla o sin id.');
        $antes = property_exists($r, 'antes') ? $r->antes : null; $despues = property_exists($r, 'despues') ? $r->despues : null;
        if (($antes !== null && !is_object($antes)) || ($despues !== null && !is_object($despues)) || ($antes === null && $despues === null)) throw new GuardRechazo("El cambio de {$r->t} {$r->id} no tiene el formato esperado.");
        if ($despues !== null && (!isset($despues->id) || (string) $despues->id !== (string) $r->id)) throw new GuardRechazo("El cambio de {$r->t} {$r->id} trae otro id.");
        if (in_array($r->t, PERM_NO_GUARDAR, true)) throw new GuardRechazo('Esa parte de los datos no se guarda desde la pantalla.');
    }
    foreach ($doc as $d) if (!is_object($d) || !isset($d->k) || !is_string($d->k) || $d->k === '' || !property_exists($d, 'despues')) throw new GuardRechazo('Hay un cambio de configuración sin nombre.');
    return [$regs, $doc];
}

// Campos que pone el servidor: no cuentan para decidir si hubo conflicto
function guardIgnorados($tabla) { return in_array($tabla, PERM_COMPARTIDAS, true) ? PERM_TRAZA : []; }

/* Mezcla de tres versiones de un registro (la que leyó la pantalla, la que quedó en la pantalla
   y la que tiene hoy el servidor), campo por campo de primer nivel.
   Devuelve el registro mezclado, o null si la misma información cambió en los dos lados. */
function guardMezclarRegistro($antes, $despues, $actual, $ignorar) {
    $a = get_object_vars($antes); $d = get_object_vars($despues); $s = get_object_vars($actual);
    $res = permCopia($actual);
    $j = function ($arr, $k) { return array_key_exists($k, $arr) ? guardCanon($arr[$k]) : "\0sin"; };
    foreach (array_unique(array_merge(array_keys($a), array_keys($d))) as $k) {
        if (in_array($k, $ignorar, true)) continue;
        $va = $j($a, $k); $vd = $j($d, $k);
        if ($va === $vd) continue;                       // la pantalla no cambió este campo
        $vs = $j($s, $k);
        if ($vs === $vd) continue;                       // el servidor ya tiene ese valor
        if ($vs !== $va) return null;                    // otra persona cambió el mismo campo
        if (array_key_exists($k, $d)) $res->$k = permCopia($d[$k]); else unset($res->$k);
    }
    return $res;
}

/* Aplica los cambios de la pantalla sobre la vista actual de esa persona.
   Devuelve [vista con los cambios, conflictos, tocados]. conflictos: [{t, id, motivo, nombre}];
   tocados: ['registros' => [[t, id]], 'doc' => [k]] (para devolver a la pantalla sus versiones finales). */
function guardMezclar($vista, $cambios, $ctx) {
    list($regs, $doc) = guardValidar($cambios);
    $v = permCopia($vista);
    $conf = []; $toc = ['registros' => [], 'doc' => []];
    $cache = [];   // tabla => [id => posición]; se recalcula solo cuando la lista cambia de largo
    $pos = function ($t) use (&$v, &$cache) { if (isset($cache[$t])) return $cache[$t]; $m = []; foreach (isset($v->$t) && is_array($v->$t) ? $v->$t : [] as $i => $x) if (is_object($x) && isset($x->id)) $m[(string) $x->id] = $i; return $cache[$t] = $m; };
    foreach ($regs as $r) {
        $t = $r->t; $id = (string) $r->id;
        $antes = property_exists($r, 'antes') ? $r->antes : null; $despues = property_exists($r, 'despues') ? $r->despues : null;
        if (isset($v->$t) && !is_array($v->$t)) throw new GuardRechazo("$t no es una lista de registros.");
        if (!isset($v->$t)) $v->$t = [];
        $p = $pos($t);
        $actual = isset($p[$id]) ? $v->{$t}[$p[$id]] : null;
        $toc['registros'][] = [$t, $id];
        $ign = guardIgnorados($t);
        $quitarIgn = function ($x) use ($ign) { if (!is_object($x)) return $x; $c = permCopia($x); foreach ($ign as $k) unset($c->$k); return $c; };
        if ($antes === null) {                                         // alta
            if ($actual !== null) { if (!guardIgual($quitarIgn($actual), $quitarIgn($despues))) $conf[] = ['t' => $t, 'id' => $id, 'motivo' => 'existe', 'nombre' => permNombre($t, $actual)]; continue; }
            $tras = isset($r->tras) && (is_string($r->tras) || is_int($r->tras)) ? (string) $r->tras : null;
            $lista = $v->$t;
            if ($tras !== null && isset($p[$tras])) array_splice($lista, $p[$tras] + 1, 0, [permCopia($despues)]);
            elseif ($tras === null || $tras === '') array_unshift($lista, permCopia($despues));
            else $lista[] = permCopia($despues);
            $v->$t = $lista; unset($cache[$t]);
            continue;
        }
        if ($actual === null) {                                        // ya no existe en el servidor (o ya no lo ve)
            if ($despues !== null) $conf[] = ['t' => $t, 'id' => $id, 'motivo' => 'eliminado', 'nombre' => permNombre($t, $antes)];
            continue;
        }
        if ($despues === null) {                                       // baja
            if (!guardIgual($quitarIgn($actual), $quitarIgn($antes))) { $conf[] = ['t' => $t, 'id' => $id, 'motivo' => 'cambio', 'nombre' => permNombre($t, $actual)]; continue; }
            $lista = $v->$t; array_splice($lista, $p[$id], 1); $v->$t = $lista; unset($cache[$t]);
            continue;
        }
        $m = guardMezclarRegistro($antes, $despues, $actual, $ign);   // cambio
        if ($m === null) { $conf[] = ['t' => $t, 'id' => $id, 'motivo' => 'cambio', 'nombre' => permNombre($t, $actual)]; continue; }
        $lista = $v->$t; $lista[$p[$id]] = $m; $v->$t = $lista;
    }
    foreach ($doc as $d) {
        $k = $d->k; $toc['doc'][] = $k;
        if (in_array($k, PERM_NO_GUARDAR, true)) continue;
        $actual = property_exists($v, $k) ? $v->$k : null;
        // Contadores, marcas de tiempo y versión de los datos: no son conflicto (seq se junta tomando el mayor)
        if (in_array($k, ['seq', 'savedAt', 'lastBackup', 'v'], true)) { $v->$k = permCopia($d->despues); continue; }
        $antes = property_exists($d, 'antes') ? $d->antes : null;
        if ($actual === null || guardIgual($actual, $antes) || guardIgual($actual, $d->despues)) { $v->$k = permCopia($d->despues); continue; }
        $conf[] = ['t' => 'doc', 'id' => $k, 'motivo' => 'cambio', 'nombre' => $k === 'config' ? 'la configuración' : $k];
    }
    return [$v, $conf, $toc];
}

/* ---------- Códigos automáticos ---------- */
function guardCodigoOP($usados, $fecha = null) {
    $k = date('ymd', $fecha === null ? time() : strtotime($fecha));
    for ($n = 1; ; $n++) { $c = sprintf('OP-%s-%02d', $k, $n); if (!isset($usados[$c])) return $c; }
}
function guardMax($a, $b) { return $a > $b ? $a : $b; }

/* Pone los códigos definitivos en las altas de $nuevo (datos completos ya revisados por permisos)
   comparando con $full (lo que había). Devuelve [avisos, renombres provisional => definitivo]. */
function guardCodigos($full, $nuevo, $ctx) {
    $avisos = []; $ren = [];
    $F = []; foreach (permLista($full, 'opps') as $o) if (is_object($o) && isset($o->id)) $F[(string) $o->id] = $o;
    $usados = [];
    foreach ($F as $o) { $c = strtoupper(trim(permTxt($o, 'code'))); if ($c !== '') $usados[$c] = 1; }
    foreach (permLista($full, 'leads') as $l) { $c = strtoupper(trim(permTxt($l, 'cmCode'))); if ($c !== '') $usados[$c] = 1; }
    $ult = null;
    foreach (permLista($nuevo, 'opps') as $o) {
        if (!is_object($o) || !isset($o->id)) continue;
        $id = (string) $o->id;
        if (isset($F[$id])) {                                          // ya existía: el código no cambia
            $antes = permTxt($F[$id], 'code');
            if (permTxt($o, 'code') !== $antes && !$ctx->admin) { $avisos[] = 'El código de la oportunidad «' . permNombre('opps', $F[$id]) . "» lo asigna el CRM y no se cambia (sigue siendo $antes)."; $o->code = $antes; }
            continue;
        }
        $c = strtoupper(trim(permTxt($o, 'code')));
        $def = guardCodigoOP($usados);
        if ($c !== '' && $c !== $def) $ren[permTxt($o, 'code')] = $def;
        $o->code = $def; $o->crm = true; $usados[$def] = 1; $ult = $def;
    }
    // Prospectos: un PR repetido (dos personas creando a la vez) recibe el siguiente libre
    $L = []; $prUsados = []; $maxPR = 0;
    foreach (permLista($full, 'leads') as $l) if (is_object($l) && isset($l->id)) { $L[(string) $l->id] = 1; $c = permTxt($l, 'code'); if ($c !== '') $prUsados[$c] = 1; if (preg_match('/^PR-\d{4}-(\d+)$/', $c, $m)) $maxPR = guardMax($maxPR, (int) $m[1]); }
    foreach (permLista($nuevo, 'leads') as $l) {
        if (!is_object($l) || !isset($l->id) || isset($L[(string) $l->id])) continue;
        $c = permTxt($l, 'code');
        if (preg_match('/^PR-\d{4}-(\d+)$/', $c, $m) && !isset($prUsados[$c])) { $prUsados[$c] = 1; $maxPR = guardMax($maxPR, (int) $m[1]); continue; }
        if ($c === '') continue;
        $maxPR = guardMax($maxPR, isset($nuevo->seq->lead) && is_numeric($nuevo->seq->lead) ? (int) $nuevo->seq->lead : 0) + 1;
        $def = 'PR-' . date('Y') . '-' . str_pad((string) $maxPR, 4, '0', STR_PAD_LEFT);
        $ren[$c] = $def; $l->code = $def; $prUsados[$def] = 1;
    }
    if ($maxPR) { if (!isset($nuevo->seq) || !is_object($nuevo->seq)) $nuevo->seq = new stdClass(); $nuevo->seq->lead = guardMax(isset($nuevo->seq->lead) ? (int) $nuevo->seq->lead : 0, $maxPR); }
    if ($ult !== null) { if (!isset($nuevo->seq) || !is_object($nuevo->seq)) $nuevo->seq = new stdClass(); $nuevo->seq->opUlt = $ult; }
    // El código provisional también aparece en la auditoría, tareas y actividades nuevas: se corrige ahí
    if ($ren) {
        $de = array_map('strval', array_keys($ren)); $a = array_values($ren);
        $exist = [];
        foreach (['audit', 'tasks', 'activities'] as $t) foreach (permLista($full, $t) as $x) if (is_object($x) && isset($x->id)) $exist[$t][(string) $x->id] = 1;
        foreach (['audit' => ['entity', 'oldV', 'newV'], 'tasks' => ['title'], 'activities' => ['desc']] as $t => $campos)
            foreach (permLista($nuevo, $t) as $x) if (is_object($x) && isset($x->id) && !isset($exist[$t][(string) $x->id]))
                foreach ($campos as $k) if (isset($x->$k) && is_string($x->$k)) $x->$k = str_replace($de, $a, $x->$k);
    }
    return [$avisos, $ren];
}

// Lo que la pantalla necesita para quedar igual al servidor después de guardar: la versión final de lo que tocó
function guardDevolver($vista, $toc) {
    $idx = [];
    $out = ['registros' => [], 'doc' => new stdClass()];
    foreach ($toc['registros'] as $x) {
        list($t, $id) = $x;
        if (!isset($idx[$t])) { $idx[$t] = []; foreach (permLista($vista, $t) as $r) if (is_object($r) && isset($r->id)) $idx[$t][(string) $r->id] = $r; }
        $out['registros'][] = ['t' => $t, 'id' => $id, 'datos' => isset($idx[$t][$id]) ? $idx[$t][$id] : null];
    }
    foreach (array_unique(array_merge($toc['doc'], ['seq'])) as $k) if (property_exists($vista, $k)) $out['doc']->$k = $vista->$k;
    return $out;
}
