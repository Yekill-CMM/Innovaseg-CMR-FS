<?php
/* =========================================================================
   CRM Comercial - almacenamiento en base de datos (SQLite)

   Qué resuelve
   - Cada prospecto, oportunidad, tarea, actividad, empresa, contacto, etc. es un registro propio en la
     tabla «registros». Cada guardado es una transacción: se escribe completo o no se escribe nada.
   - Nada se borra físicamente: lo que deja de venir queda con «eliminado_en» y se puede recuperar.
   - Tabla «cambios»: historial completo (sin tope) de cada alta, cambio y baja, con origen
     (pantalla o Joan), fecha, valor anterior y valor nuevo.
   - Respaldos de la base: uno por hora (últimos 72) y uno por día (últimos 30), en datos/historial.
   - Red de seguridad: después de cada guardado se sigue escribiendo el archivo JSON de siempre
     (datos/crm.json.php). Si la base no está disponible, el CRM sigue funcionando con ese archivo.

   Migración
   - La primera vez que se abre la base, si existe datos/crm.json.php se cargan sus datos y se verifica que
     la base devuelva exactamente lo mismo. Solo entonces se activa. Si no cuadra, la base se descarta y el
     CRM sigue con el JSON (queda registrado en datos/almacen.log).

   Modelo
   - Los arreglos de objetos con «id» (companies, contacts, leads, opps, activities, tasks, offers, plans,
     users, audit…) van a «registros», uno por fila, con su orden. El resto (config, seq, mode, v…) va a «doc».
     Es genérico: una colección nueva en el futuro se guarda sin cambiar esta capa.
   - «meta» guarda rev y savedAt (control de versiones entre dispositivos, igual que antes).

   Para volver al JSON a propósito: en config.php, define('CRM_ALMACEN', 'json');
   ========================================================================= */

const ALM_ESQUEMA = 1;

function almLog($dir, $msg) { @file_put_contents($dir . '/almacen.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND); }

// Abre la base. Devuelve PDO, o null si hay que seguir con el JSON (sin SQLite, desactivada o migración fallida).
function almAbrir($dir, $archivoJson, $cabecera) {
    if (defined('CRM_ALMACEN') && CRM_ALMACEN === 'json') return null;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) return null;
    $ruta = $dir . '/crm.db';
    $nueva = !is_file($ruta);
    if (is_file($dir . '/crm.db.descartada') && $nueva) return null;   // una migración anterior falló: no reintentar sola
    try {
        $pdo = new PDO('sqlite:' . $ruta, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000;');
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS doc (clave TEXT PRIMARY KEY, valor TEXT NOT NULL, actualizado_en TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS registros (
            tabla TEXT NOT NULL, id TEXT NOT NULL, orden INTEGER NOT NULL, datos TEXT NOT NULL,
            creado_en TEXT NOT NULL, actualizado_en TEXT NOT NULL, eliminado_en TEXT, rev INTEGER NOT NULL,
            PRIMARY KEY (tabla, id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS registros_vivos ON registros (tabla, eliminado_en, orden)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cambios (
            n INTEGER PRIMARY KEY AUTOINCREMENT, rev INTEGER NOT NULL, en TEXT NOT NULL, origen TEXT NOT NULL,
            tabla TEXT NOT NULL, registro TEXT NOT NULL, accion TEXT NOT NULL, antes TEXT, despues TEXT, usuario TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS cambios_registro ON cambios (tabla, registro, n)');
        // Bases anteriores al multiusuario: se agrega la columna con la persona que hizo el cambio
        $cols = array_column($pdo->query('PRAGMA table_info(cambios)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('usuario', $cols, true)) $pdo->exec('ALTER TABLE cambios ADD COLUMN usuario TEXT');
        if ($nueva || almMeta($pdo, 'esquema') === null) {
            // Solo un proceso crea y migra (si la pantalla y Joan consultan al mismo tiempo, el otro espera)
            $lk = fopen($dir . '/.lock-db', 'c'); if ($lk) flock($lk, LOCK_EX);
            if (almMeta($pdo, 'esquema') !== null) { if ($lk) flock($lk, LOCK_UN); return $pdo; }
            almMetaPut($pdo, 'esquema', (string) ALM_ESQUEMA);
            almMetaPut($pdo, 'rev', '0'); almMetaPut($pdo, 'savedAt', '0'); almMetaPut($pdo, 'creada_en', date('c'));
            if (is_file($archivoJson) && !almMigrar($pdo, $dir, $archivoJson, $cabecera)) {
                $pdo = null;
                foreach (['', '-wal', '-shm'] as $s) if (is_file($ruta . $s)) @rename($ruta . $s, $dir . '/crm.db.descartada' . $s);
                if ($lk) flock($lk, LOCK_UN);
                return null;
            }
            if ($lk) flock($lk, LOCK_UN);
        }
        return $pdo;
    } catch (Exception $e) {
        almLog($dir, 'No se pudo abrir la base: ' . $e->getMessage() . ' (se sigue con el JSON)');
        return null;
    }
}

function almMeta($pdo, $k) { $st = $pdo->prepare('SELECT valor FROM meta WHERE clave = ?'); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? null : $v; }
function almMetaPut($pdo, $k, $v) { $pdo->prepare('INSERT INTO meta (clave, valor) VALUES (?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor')->execute([$k, (string) $v]); }

// ¿Este valor se guarda como colección de registros? (arreglo de objetos, todos con id texto o número, sin repetir)
function almEsColeccion($v) {
    if (!is_array($v) || !$v) return false;
    $ids = [];
    foreach ($v as $x) {
        if (!is_object($x) || !isset($x->id) || !(is_string($x->id) || is_int($x->id)) || (string) $x->id === '') return false;
        if (isset($ids[(string) $x->id])) return false;
        $ids[(string) $x->id] = 1;
    }
    return true;
}

function almJson($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }

// Arma el documento del CRM (el mismo formato que antes: {rev, savedAt, data})
function almLeer($pdo) {
    $rev = (int) almMeta($pdo, 'rev'); $savedAt = almMeta($pdo, 'savedAt');
    $data = new stdClass(); $hay = false;
    $orden = almMeta($pdo, 'orden_claves'); $orden = $orden ? json_decode($orden, true) : [];
    $partes = [];
    foreach ($pdo->query('SELECT clave, valor FROM doc') as $r) { $partes[$r['clave']] = json_decode($r['valor']); $hay = true; }
    $st = $pdo->query('SELECT tabla, datos FROM registros WHERE eliminado_en IS NULL ORDER BY tabla, orden');
    foreach ($st as $r) { if (!isset($partes[$r['tabla']]) || !is_array($partes[$r['tabla']])) $partes[$r['tabla']] = []; $partes[$r['tabla']][] = json_decode($r['datos']); $hay = true; }
    $vac = json_decode(almMeta($pdo, 'colecciones_vacias') ?: '[]', true) ?: [];
    foreach ($vac as $k) if (!isset($partes[$k])) $partes[$k] = [];
    foreach ($orden as $k) if (array_key_exists($k, $partes)) { $data->$k = $partes[$k]; unset($partes[$k]); }
    foreach ($partes as $k => $v) $data->$k = $v;
    return (object) ['rev' => $rev, 'savedAt' => is_numeric($savedAt) ? $savedAt + 0 : $savedAt, 'data' => $hay ? $data : null];
}

// Guarda el documento completo calculando diferencias. Devuelve la nueva rev. Debe llamarse dentro de una transacción.
// $usuario: id de la persona que guarda (queda en cada fila de «cambios»)
function almGuardar($pdo, $data, $rev, $savedAt, $origen, $usuario = null) {
    $ahora = date('c');
    $claves = []; $vacias = [];
    $insC0 = $pdo->prepare('INSERT INTO cambios (rev, en, origen, tabla, registro, accion, antes, despues, usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insC = new class($insC0, $usuario) { private $st; private $u; function __construct($st, $u) { $this->st = $st; $this->u = $u; } function execute($v) { $v[] = $this->u; return $this->st->execute($v); } };
    foreach (get_object_vars($data) as $k => $v) {
        $claves[] = $k;
        $ant = $pdo->prepare('SELECT valor FROM doc WHERE clave = ?'); $ant->execute([$k]); $antes = $ant->fetchColumn();
        if (almEsColeccion($v) || (is_array($v) && !$v && almEsColeccionAntes($pdo, $k))) {
            if ($antes !== false) { $pdo->prepare('DELETE FROM doc WHERE clave = ?')->execute([$k]); }
            if (!$v) $vacias[] = $k;
            almGuardarColeccion($pdo, $k, $v, $rev, $ahora, $origen, $insC);
        } else {
            $j = almJson($v);
            if ($antes === false || $antes !== $j) {
                $pdo->prepare('INSERT INTO doc (clave, valor, actualizado_en) VALUES (?, ?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, actualizado_en = excluded.actualizado_en')->execute([$k, $j, $ahora]);
                $insC->execute([$rev, $ahora, $origen, 'doc', $k, $antes === false ? 'alta' : 'cambio', $antes === false ? null : $antes, $j]);
            }
            // si antes era colección y ahora no, sus registros quedan como eliminados
            almGuardarColeccion($pdo, $k, [], $rev, $ahora, $origen, $insC);
        }
    }
    // claves que ya no vienen: los registros quedan como eliminados; las del doc se borran con constancia en «cambios»
    foreach ($pdo->query('SELECT DISTINCT tabla FROM registros WHERE eliminado_en IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $t) if (!in_array($t, $claves, true)) almGuardarColeccion($pdo, $t, [], $rev, $ahora, $origen, $insC);
    foreach ($pdo->query('SELECT clave, valor FROM doc')->fetchAll(PDO::FETCH_ASSOC) as $r) if (!in_array($r['clave'], $claves, true)) {
        $pdo->prepare('DELETE FROM doc WHERE clave = ?')->execute([$r['clave']]);
        $insC->execute([$rev, $ahora, $origen, 'doc', $r['clave'], 'baja', $r['valor'], null]);
    }
    almMetaPut($pdo, 'orden_claves', almJson($claves));
    almMetaPut($pdo, 'colecciones_vacias', almJson($vacias));
    almMetaPut($pdo, 'rev', (string) $rev);
    almMetaPut($pdo, 'savedAt', (string) $savedAt);
    return $rev;
}

function almEsColeccionAntes($pdo, $tabla) { $st = $pdo->prepare('SELECT 1 FROM registros WHERE tabla = ? LIMIT 1'); $st->execute([$tabla]); return (bool) $st->fetchColumn(); }

function almGuardarColeccion($pdo, $tabla, $lista, $rev, $ahora, $origen, $insC) {
    $st = $pdo->prepare('SELECT id, orden, datos, eliminado_en FROM registros WHERE tabla = ?'); $st->execute([$tabla]);
    $exist = []; foreach ($st as $r) $exist[$r['id']] = $r;
    $ins = $pdo->prepare('INSERT INTO registros (tabla, id, orden, datos, creado_en, actualizado_en, eliminado_en, rev) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)');
    $upd = $pdo->prepare('UPDATE registros SET orden = ?, datos = ?, actualizado_en = ?, eliminado_en = NULL, rev = ? WHERE tabla = ? AND id = ?');
    $ord = $pdo->prepare('UPDATE registros SET orden = ? WHERE tabla = ? AND id = ?');
    $baja = $pdo->prepare('UPDATE registros SET eliminado_en = ?, actualizado_en = ?, rev = ? WHERE tabla = ? AND id = ?');
    $vistos = [];
    foreach (array_values($lista) as $i => $x) {
        $id = (string) $x->id; $vistos[$id] = 1; $j = almJson($x);
        if (!isset($exist[$id])) { $ins->execute([$tabla, $id, $i, $j, $ahora, $ahora, $rev]); $insC->execute([$rev, $ahora, $origen, $tabla, $id, 'alta', null, $j]); continue; }
        $e = $exist[$id];
        if ($e['datos'] !== $j || $e['eliminado_en'] !== null) {
            $upd->execute([$i, $j, $ahora, $rev, $tabla, $id]);
            $insC->execute([$rev, $ahora, $origen, $tabla, $id, $e['eliminado_en'] !== null ? 'restaurado' : 'cambio', $e['datos'], $j]);
        } elseif ((int) $e['orden'] !== $i) $ord->execute([$i, $tabla, $id]);
    }
    foreach ($exist as $id => $e) if (!isset($vistos[(string) $id]) && $e['eliminado_en'] === null) {
        $baja->execute([$ahora, $ahora, $rev, $tabla, (string) $id]);
        $insC->execute([$rev, $ahora, $origen, $tabla, (string) $id, 'baja', $e['datos'], null]);
    }
}

// Normaliza para comparar: mismo contenido, sin importar el orden de las claves de primer nivel
function almCanon($doc) { $a = json_decode(almJson($doc), true); if (is_array($a)) ksort($a); return almJson($a); }

function almMigrar($pdo, $dir, $archivoJson, $cabecera) {
    $actual = leerDatos($archivoJson, $cabecera);
    if (!$actual->data) return true;   // JSON vacío: nada que migrar
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        almGuardar($pdo, $actual->data, (int) $actual->rev, $actual->savedAt, 'migración');
        $pdo->exec("UPDATE cambios SET accion = 'migración' WHERE origen = 'migración'");
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Exception $x) {}
        almLog($dir, 'Migración fallida (error al cargar): ' . $e->getMessage());
        return false;
    }
    $leido = almLeer($pdo);
    if (almCanon($leido->data) !== almCanon($actual->data) || (int) $leido->rev !== (int) $actual->rev) {
        almLog($dir, 'Migración fallida: la base no devuelve exactamente los datos del JSON. Se sigue con el JSON.');
        return false;
    }
    @copy($archivoJson, $dir . '/crm.json.antes-de-sqlite.php');
    almMetaPut($pdo, 'migrada_desde_json', date('c'));
    $n = []; foreach ($pdo->query('SELECT tabla, COUNT(*) c FROM registros GROUP BY tabla') as $r) $n[] = $r['tabla'] . ' ' . $r['c'];
    almLog($dir, 'Migración verificada: ' . implode(', ', $n));
    return true;
}

// Respaldo de la base: uno por hora (últimos 72) y el primero de cada día (últimos 30)
function almRespaldo($pdo, $dir) {
    $h = $dir . '/historial'; if (!is_dir($h)) @mkdir($h, 0750, true);
    $horas = glob($h . '/crm-*.db') ?: []; sort($horas);
    $ult = end($horas);
    if ($ult && filemtime($ult) > time() - 3600) return;
    $dest = $h . '/crm-' . date('Ymd-His') . '.db';
    try { $pdo->exec('VACUUM INTO ' . $pdo->quote($dest)); } catch (Exception $e) { almLog($dir, 'Respaldo fallido: ' . $e->getMessage()); return; }
    $dia = $h . '/diario-' . date('Ymd') . '.db';
    if (!is_file($dia)) @copy($dest, $dia);
    $horas = glob($h . '/crm-*.db') ?: []; sort($horas); while (count($horas) > 72) @unlink(array_shift($horas));
    $dias = glob($h . '/diario-*.db') ?: []; sort($dias); while (count($dias) > 30) @unlink(array_shift($dias));
}

// Estado para la pantalla (Configuración)
function almEstado($pdo, $dir) {
    if (!$pdo) {
        $sin = !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true);
        return ['tipo' => 'json', 'motivo' => (defined('CRM_ALMACEN') && CRM_ALMACEN === 'json') ? 'desactivada en config.php' : ($sin ? 'falta SQLite en el PHP del servidor (paquete php-sqlite3)' : (is_file($dir . '/crm.db.descartada') ? 'la migración no cuadró; revisa datos/almacen.log' : 'no disponible'))];
    }
    $h = glob($dir . '/historial/crm-*.db') ?: []; sort($h); $u = end($h);
    return ['tipo' => 'sqlite', 'cambios' => (int) $pdo->query('SELECT COUNT(*) FROM cambios')->fetchColumn(),
        'registros' => (int) $pdo->query('SELECT COUNT(*) FROM registros WHERE eliminado_en IS NULL')->fetchColumn(),
        'eliminados' => (int) $pdo->query("SELECT COUNT(*) FROM registros WHERE eliminado_en IS NOT NULL AND tabla <> 'audit'")->fetchColumn(),
        'ultimo_respaldo' => $u ? date('c', filemtime($u)) : null, 'respaldos' => count($h) + count(glob($dir . '/historial/diario-*.db') ?: []),
        'migrada_desde_json' => almMeta($pdo, 'migrada_desde_json')];
}

// Historial de un registro (o de una tabla), del más reciente al más antiguo
function almHistorial($pdo, $tabla, $id, $limite = 200) {
    $st = $pdo->prepare('SELECT n, rev, en, origen, accion, antes, despues, usuario FROM cambios WHERE tabla = ? AND registro = ? ORDER BY n DESC LIMIT ' . (int) $limite);
    $st->execute([$tabla, $id]);
    $out = [];
    foreach ($st as $r) $out[] = ['n' => (int) $r['n'], 'rev' => (int) $r['rev'], 'en' => $r['en'], 'origen' => $r['origen'], 'usuario' => $r['usuario'], 'accion' => $r['accion'],
        'antes' => $r['antes'] !== null ? json_decode($r['antes']) : null, 'despues' => $r['despues'] !== null ? json_decode($r['despues']) : null];
    return $out;
}

// Registros eliminados recuperables (los más recientes primero). La auditoría de la pantalla guarda solo los últimos 400
// movimientos; los más antiguos quedan aquí como eliminados, así que no se ofrecen para recuperar.
// $filtro($tabla, $datos): si se indica, solo los registros para los que devuelve true (permisos de quien consulta)
function almEliminados($pdo, $limite = 100, $filtro = null) {
    $st = $pdo->query("SELECT tabla, id, datos, eliminado_en FROM registros WHERE eliminado_en IS NOT NULL AND tabla <> 'audit' ORDER BY eliminado_en DESC");
    $out = [];
    foreach ($st as $r) { $d = json_decode($r['datos']); $nom = '';
        if ($filtro && !$filtro($r['tabla'], $d)) continue;
        if (count($out) >= $limite) break;
        foreach (['code', 'name', 'title', 'legalName', 'desc'] as $k) if (!empty($d->$k) && is_string($d->$k)) { $nom .= ($nom ? ' · ' : '') . mb_substr($d->$k, 0, 90); if ($k === 'name' || $k === 'title' || $k === 'legalName' || $k === 'desc') break; }
        if ($nom === '' && !empty($d->firstName)) $nom = trim($d->firstName . ' ' . (isset($d->lastName) ? $d->lastName : '')) . (!empty($d->email) ? ' · ' . $d->email : '');
        $out[] = ['tabla' => $r['tabla'], 'id' => $r['id'], 'nombre' => $nom ?: $r['id'], 'eliminado_en' => $r['eliminado_en']]; }
    return $out;
}

// Recupera un registro eliminado (vuelve a la colección con sus últimos datos)
function almRestaurar($pdo, $tabla, $id, $origen, $usuario = null) {
    $st = $pdo->prepare('SELECT datos FROM registros WHERE tabla = ? AND id = ? AND eliminado_en IS NOT NULL'); $st->execute([$tabla, $id]);
    $d = $st->fetchColumn(); if ($d === false) return false;
    $rev = (int) almMeta($pdo, 'rev') + 1; $ahora = date('c');
    $max = (int) $pdo->query('SELECT COALESCE(MAX(orden), -1) FROM registros WHERE tabla = ' . $pdo->quote($tabla))->fetchColumn();
    $pdo->prepare('UPDATE registros SET eliminado_en = NULL, actualizado_en = ?, rev = ?, orden = ? WHERE tabla = ? AND id = ?')->execute([$ahora, $rev, $max + 1, $tabla, $id]);
    $pdo->prepare('INSERT INTO cambios (rev, en, origen, tabla, registro, accion, antes, despues, usuario) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)')->execute([$rev, $ahora, $origen, $tabla, $id, 'restaurado', $d, $usuario]);
    almMetaPut($pdo, 'rev', (string) $rev);
    return $rev;
}
