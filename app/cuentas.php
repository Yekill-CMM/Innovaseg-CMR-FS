<?php
/* =========================================================================
   CRM FS - cuentas por persona: usuarios, claves, segundo factor y sesiones

   - Cada persona entra con su usuario, su clave y un código de 6 dígitos de una
     aplicación de autenticación (Google Authenticator, Microsoft Authenticator…).
     El segundo factor es obligatorio: quien no lo tiene activo lo activa en su
     primer ingreso, antes de ver cualquier dato.
   - Las claves se guardan con password_hash. Una clave temporal (la que entrega el
     administrador al crear la cuenta o al reiniciarla) obliga a elegir una propia.
   - La sesión es una cookie HttpOnly y SameSite=Strict; en la base solo queda el
     hash del token. Dura CRM_SESION_HORAS (12 por defecto) y se cierra sola tras
     CRM_SESION_INACTIVA_HORAS (4) sin uso.
   - Cinco fallos seguidos de clave o código bloquean la cuenta 15 minutos.
   - Base propia: datos/cuentas.db (SQLite), aparte de los datos del CRM.
   ========================================================================= */

const CTA_ROLES = ['gerente', 'subgerente', 'ejecutivo', 'admin'];
const CTA_ETIQUETAS = ['gerente' => 'Gerente comercial', 'subgerente' => 'Subgerente comercial', 'ejecutivo' => 'Ejecutivo comercial', 'admin' => 'Administrador'];
const CTA_MAX_FALLOS = 5;
const CTA_BLOQUEO_MIN = 15;
const CTA_DESAFIO_MIN = 10;

function ctaAbrir($dir) {
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) return null;
    $pdo = new PDO('sqlite:' . $dir . '/cuentas.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=10000;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS usuarios (
        id TEXT PRIMARY KEY, usuario TEXT NOT NULL UNIQUE, nombre TEXT NOT NULL, rol TEXT NOT NULL, equipo TEXT NOT NULL DEFAULT \'\',
        admin INTEGER NOT NULL DEFAULT 0, activo INTEGER NOT NULL DEFAULT 1,
        clave_hash TEXT NOT NULL, clave_temporal INTEGER NOT NULL DEFAULT 1,
        totp_secreto TEXT, totp_activo INTEGER NOT NULL DEFAULT 0, totp_ultimo INTEGER NOT NULL DEFAULT 0,
        fallos INTEGER NOT NULL DEFAULT 0, bloqueado_hasta INTEGER NOT NULL DEFAULT 0,
        ultimo_ingreso TEXT, creado_en TEXT NOT NULL, actualizado_en TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sesiones (
        token_hash TEXT PRIMARY KEY, usuario_id TEXT NOT NULL, tipo TEXT NOT NULL, datos TEXT,
        creada INTEGER NOT NULL, vista INTEGER NOT NULL, expira INTEGER NOT NULL, ip TEXT, agente TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS sesiones_usuario ON sesiones (usuario_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS eventos (
        n INTEGER PRIMARY KEY AUTOINCREMENT, en TEXT NOT NULL, usuario_id TEXT, actor_id TEXT, evento TEXT NOT NULL, detalle TEXT, ip TEXT)');
    return $pdo;
}

function ctaEvento($pdo, $usuarioId, $actorId, $evento, $detalle = '', $ip = '') {
    $pdo->prepare('INSERT INTO eventos (en, usuario_id, actor_id, evento, detalle, ip) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([date('c'), $usuarioId, $actorId, $evento, $detalle, $ip]);
}

/* ---------- Usuarios ---------- */
function ctaNormUsuario($u) { return strtolower(trim((string) $u)); }

// Usuario tal como lo ven los permisos y la pantalla (sin clave ni secreto)
function ctaPublico($r) {
    if (!$r) return null;
    return (object) ['id' => $r['id'], 'usuario' => $r['usuario'], 'name' => $r['nombre'], 'role' => $r['rol'], 'equipo' => (string) $r['equipo'],
        'admin' => (bool) $r['admin'] || $r['rol'] === 'admin', 'activo' => (bool) $r['activo'],
        'roleLabel' => CTA_ETIQUETAS[$r['rol']] . ((int) $r['admin'] && $r['rol'] !== 'admin' ? ' (administrador)' : '')];
}
function ctaFila($pdo, $id) { $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
function ctaFilaPorUsuario($pdo, $u) { $st = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ?'); $st->execute([ctaNormUsuario($u)]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
// Todos los usuarios (activos e inactivos: los inactivos siguen apareciendo como dueños de su historial)
function ctaDirectorio($pdo) {
    $out = [];
    foreach ($pdo->query('SELECT * FROM usuarios ORDER BY nombre') as $r) $out[] = ctaPublico($r);
    return $out;
}

function ctaClaveTemporal() {
    $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = ''; for ($i = 0; $i < 14; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4, 5) . '-' . substr($s, 9);
}
function ctaValidarClave($c) {
    $c = (string) $c;
    if (mb_strlen($c) < 10) return 'La clave debe tener al menos 10 caracteres.';
    if (mb_strlen($c) > 200) return 'La clave es demasiado larga.';
    if (!preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]/u', $c) || !preg_match('/[0-9]/', $c)) return 'La clave debe tener letras y números.';
    return '';
}

// Valida los datos de una cuenta. Devuelve [error, datos normalizados]
function ctaValidarDatos($pdo, $d, $id = null) {
    $usuario = ctaNormUsuario(isset($d->usuario) ? $d->usuario : '');
    $nombre = trim((string) (isset($d->nombre) ? $d->nombre : ''));
    $rol = (string) (isset($d->rol) ? $d->rol : '');
    $equipo = trim((string) (isset($d->equipo) ? $d->equipo : ''));
    $admin = !empty($d->admin) ? 1 : 0;
    if ($id === null && !preg_match('/^[a-z0-9._@-]{3,80}$/', $usuario)) return ['El usuario debe tener entre 3 y 80 caracteres: letras, números, punto, guion o @.', null];
    if ($nombre === '' || mb_strlen($nombre) > 120) return ['Indica el nombre de la persona.', null];
    if (!in_array($rol, CTA_ROLES, true)) return ['El rol debe ser gerente, subgerente, ejecutivo o admin.', null];
    if (in_array($rol, ['subgerente', 'ejecutivo'], true) && $equipo === '') return ['Un subgerente o ejecutivo necesita su equipo (subgerencia).', null];
    if (in_array($rol, ['gerente', 'admin'], true)) $equipo = '';
    if (mb_strlen($equipo) > 60) return ['El nombre del equipo es demasiado largo.', null];
    if ($rol === 'subgerente') {
        $st = $pdo->prepare("SELECT nombre FROM usuarios WHERE rol = 'subgerente' AND equipo = ? AND activo = 1 AND id <> ?"); $st->execute([$equipo, (string) $id]);
        if ($otro = $st->fetchColumn()) return ["El equipo «{$equipo}» ya tiene subgerente ({$otro}).", null];
    }
    if ($id === null && ctaFilaPorUsuario($pdo, $usuario)) return ['Ya existe una cuenta con ese usuario.', null];
    return ['', ['usuario' => $usuario, 'nombre' => $nombre, 'rol' => $rol, 'equipo' => $equipo, 'admin' => $rol === 'admin' ? 1 : $admin]];
}

// Crea una cuenta con clave temporal. Devuelve [error, id, clave temporal]
function ctaCrear($pdo, $d, $actorId = null, $id = null) {
    [$err, $v] = ctaValidarDatos($pdo, $d);
    if ($err) return [$err, null, null];
    $id = $id ?: 'u' . bin2hex(random_bytes(5));
    $clave = ctaClaveTemporal(); $ahora = date('c');
    $pdo->prepare('INSERT INTO usuarios (id, usuario, nombre, rol, equipo, admin, activo, clave_hash, clave_temporal, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, ?, ?)')
        ->execute([$id, $v['usuario'], $v['nombre'], $v['rol'], $v['equipo'], $v['admin'], password_hash($clave, PASSWORD_DEFAULT), $ahora, $ahora]);
    ctaEvento($pdo, $id, $actorId, 'cuenta creada', $v['rol'] . ($v['equipo'] !== '' ? ' · ' . $v['equipo'] : ''));
    return ['', $id, $clave];
}

// Cantidad de administradores activos, sin contar a $excepto
function ctaAdminsActivos($pdo, $excepto = '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND (admin = 1 OR rol = 'admin') AND id <> ?"); $st->execute([$excepto]);
    return (int) $st->fetchColumn();
}

function ctaEditar($pdo, $id, $d, $actorId) {
    $r = ctaFila($pdo, $id); if (!$r) return 'No existe esa cuenta.';
    $base = (object) ['usuario' => $r['usuario'], 'nombre' => isset($d->nombre) ? $d->nombre : $r['nombre'], 'rol' => isset($d->rol) ? $d->rol : $r['rol'],
        'equipo' => isset($d->equipo) ? $d->equipo : $r['equipo'], 'admin' => isset($d->admin) ? $d->admin : (bool) $r['admin']];
    [$err, $v] = ctaValidarDatos($pdo, $base, $id);
    if ($err) return $err;
    $activo = isset($d->activo) ? (!empty($d->activo) ? 1 : 0) : (int) $r['activo'];
    $quedaAdmin = $activo && ($v['admin'] || $v['rol'] === 'admin');
    if (!$quedaAdmin && ctaAdminsActivos($pdo, $id) === 0) return 'Debe quedar al menos un administrador activo.';
    $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ?, equipo = ?, admin = ?, activo = ?, actualizado_en = ? WHERE id = ?')
        ->execute([$v['nombre'], $v['rol'], $v['equipo'], $v['admin'], $activo, date('c'), $id]);
    if (!$activo) ctaCerrarSesiones($pdo, $id);
    $cambio = [];
    foreach (['nombre' => 'nombre', 'rol' => 'rol', 'equipo' => 'equipo', 'admin' => 'admin'] as $k => $c) if ((string) $r[$c] !== (string) $v[$k]) $cambio[] = "$k: {$r[$c]} → {$v[$k]}";
    if ((int) $r['activo'] !== $activo) $cambio[] = $activo ? 'reactivada' : 'desactivada';
    if ($cambio) ctaEvento($pdo, $id, $actorId, 'cuenta editada', implode('; ', $cambio));
    return '';
}

// Clave temporal nueva (la persona elige la suya al entrar). Cierra sus sesiones.
function ctaReiniciarClave($pdo, $id, $actorId) {
    if (!ctaFila($pdo, $id)) return [null, 'No existe esa cuenta.'];
    $clave = ctaClaveTemporal();
    $pdo->prepare('UPDATE usuarios SET clave_hash = ?, clave_temporal = 1, fallos = 0, bloqueado_hasta = 0, actualizado_en = ? WHERE id = ?')
        ->execute([password_hash($clave, PASSWORD_DEFAULT), date('c'), $id]);
    ctaCerrarSesiones($pdo, $id);
    ctaEvento($pdo, $id, $actorId, 'clave temporal entregada');
    return [$clave, ''];
}
// Quita el segundo factor: la persona lo vuelve a activar en su próximo ingreso. Cierra sus sesiones.
function ctaReiniciarSegundoFactor($pdo, $id, $actorId) {
    if (!ctaFila($pdo, $id)) return 'No existe esa cuenta.';
    $pdo->prepare('UPDATE usuarios SET totp_secreto = NULL, totp_activo = 0, totp_ultimo = 0, actualizado_en = ? WHERE id = ?')->execute([date('c'), $id]);
    ctaCerrarSesiones($pdo, $id);
    ctaEvento($pdo, $id, $actorId, 'segundo factor reiniciado');
    return '';
}
function ctaCambiarClave($pdo, $id, $nueva) {
    $e = ctaValidarClave($nueva); if ($e) return $e;
    $pdo->prepare('UPDATE usuarios SET clave_hash = ?, clave_temporal = 0, actualizado_en = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), date('c'), $id]);
    ctaEvento($pdo, $id, $id, 'clave cambiada');
    return '';
}

/* ---------- Segundo factor (TOTP, RFC 6238: SHA-1, 30 s, 6 dígitos) ---------- */
function ctaBase32($bin) {
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $b) $out .= $abc[bindec(str_pad($b, 5, '0'))];
    return $out;
}
function ctaDeBase32($s) {
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($abc, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}
function ctaTotp($secretoB32, $paso) {
    $h = hash_hmac('sha1', pack('N2', 0, $paso), ctaDeBase32($secretoB32), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}
// Devuelve el paso de tiempo que calza (±1, por diferencias de reloj) o 0. $ultimo evita reusar un código ya usado.
function ctaVerificarTotp($secretoB32, $codigo, $ultimo = 0, $ahora = null) {
    $codigo = preg_replace('/\s+/', '', (string) $codigo);
    if (!preg_match('/^\d{6}$/', $codigo)) return 0;
    $t = intdiv($ahora === null ? time() : $ahora, 30);
    foreach ([0, -1, 1] as $d) if ($t + $d > $ultimo && hash_equals(ctaTotp($secretoB32, $t + $d), $codigo)) return $t + $d;
    return 0;
}
function ctaUriTotp($secreto, $usuario) {
    $emisor = defined('CRM_NOMBRE') ? CRM_NOMBRE : 'CRM FS';
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $usuario) . '?secret=' . $secreto . '&issuer=' . rawurlencode($emisor) . '&algorithm=SHA1&digits=6&period=30';
}

/* ---------- Sesiones ---------- */
function ctaHorasSesion() { return defined('CRM_SESION_HORAS') ? (float) CRM_SESION_HORAS : 12; }
function ctaHorasInactiva() { return defined('CRM_SESION_INACTIVA_HORAS') ? (float) CRM_SESION_INACTIVA_HORAS : 4; }

function ctaNuevoToken($pdo, $usuarioId, $tipo, $segundos, $datos = null, $ip = '', $agente = '') {
    $token = bin2hex(random_bytes(32)); $ahora = time();
    $pdo->prepare('INSERT INTO sesiones (token_hash, usuario_id, tipo, datos, creada, vista, expira, ip, agente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $usuarioId, $tipo, $datos === null ? null : json_encode($datos), $ahora, $ahora, $ahora + (int) $segundos, $ip, mb_substr((string) $agente, 0, 200)]);
    return $token;
}
function ctaToken($pdo, $token, $tipo) {
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM sesiones WHERE token_hash = ? AND tipo = ?'); $st->execute([hash('sha256', $token), $tipo]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $ahora = time();
    if ($s['expira'] < $ahora || ($tipo === 'sesion' && $s['vista'] < $ahora - ctaHorasInactiva() * 3600)) {
        $pdo->prepare('DELETE FROM sesiones WHERE token_hash = ?')->execute([$s['token_hash']]);
        return null;
    }
    return $s;
}
function ctaBorrarToken($pdo, $token) { if (is_string($token) && $token !== '') $pdo->prepare('DELETE FROM sesiones WHERE token_hash = ?')->execute([hash('sha256', $token)]); }
function ctaCerrarSesiones($pdo, $id) { $pdo->prepare('DELETE FROM sesiones WHERE usuario_id = ?')->execute([$id]); }
function ctaLimpiar($pdo) { $pdo->prepare('DELETE FROM sesiones WHERE expira < ? OR (tipo = ? AND vista < ?)')->execute([time(), 'sesion', time() - (int) (ctaHorasInactiva() * 3600)]); }

// Usuario de la sesión de la cookie, o null. Actualiza «vista» como mucho una vez por minuto.
function ctaSesionActual($pdo, $token) {
    $s = ctaToken($pdo, $token, 'sesion');
    if (!$s) return null;
    $r = ctaFila($pdo, $s['usuario_id']);
    if (!$r || !(int) $r['activo'] || !(int) $r['totp_activo'] || (int) $r['clave_temporal']) { ctaBorrarToken($pdo, $token); return null; }
    if ($s['vista'] < time() - 60) $pdo->prepare('UPDATE sesiones SET vista = ? WHERE token_hash = ?')->execute([time(), $s['token_hash']]);
    return $r;
}

/* ---------- Ingreso en pasos ----------
   1. {usuario, clave}             → paso «nueva-clave» (clave temporal), «activar» (sin segundo factor) o «codigo»
   2. {desafio, nueva_clave}       → paso «activar» o «codigo»
   3. {desafio, codigo}            → activa el segundo factor si hacía falta y abre la sesión
   Devuelve [código HTTP, cuerpo, token de sesión o null]. */
function ctaBloqueada($r) { return (int) $r['bloqueado_hasta'] > time(); }
function ctaFallo($pdo, $r, $ip, $que) {
    $f = (int) $r['fallos'] + 1;
    $hasta = $f >= CTA_MAX_FALLOS ? time() + CTA_BLOQUEO_MIN * 60 : 0;
    $pdo->prepare('UPDATE usuarios SET fallos = ?, bloqueado_hasta = ? WHERE id = ?')->execute([$hasta ? 0 : $f, $hasta, $r['id']]);
    ctaEvento($pdo, $r['id'], null, $hasta ? 'cuenta bloqueada' : 'ingreso fallido', $que, $ip);
}
function ctaSiguiente($pdo, $r, $token, $ip) {
    if ((int) $r['clave_temporal']) return [200, ['paso' => 'nueva-clave', 'desafio' => $token, 'nombre' => $r['nombre']], null];
    if (!(int) $r['totp_activo']) {
        $s = ctaToken($pdo, $token, 'desafio'); $d = $s && $s['datos'] ? json_decode($s['datos'], true) : [];
        if (empty($d['secreto'])) { $d['secreto'] = ctaBase32(random_bytes(20)); $pdo->prepare('UPDATE sesiones SET datos = ? WHERE token_hash = ?')->execute([json_encode($d), hash('sha256', $token)]); }
        return [200, ['paso' => 'activar', 'desafio' => $token, 'nombre' => $r['nombre'], 'secreto' => $d['secreto'], 'uri' => ctaUriTotp($d['secreto'], $r['usuario'])], null];
    }
    return [200, ['paso' => 'codigo', 'desafio' => $token, 'nombre' => $r['nombre']], null];
}
function ctaEntrar($pdo, $in, $ip, $agente) {
    ctaLimpiar($pdo);
    $in = is_object($in) ? $in : new stdClass();
    $err = function ($c, $m) { return [$c, ['error' => $m], null]; };
    if (isset($in->desafio)) {
        $s = ctaToken($pdo, (string) $in->desafio, 'desafio');
        if (!$s) return $err(401, 'El ingreso venció. Vuelve a escribir tu usuario y clave.');
        $r = ctaFila($pdo, $s['usuario_id']);
        if (!$r || !(int) $r['activo']) return $err(401, 'Usuario o clave incorrectos.');
        if (ctaBloqueada($r)) return $err(429, 'Cuenta bloqueada por intentos fallidos. Espera 15 minutos.');
        $d = $s['datos'] ? json_decode($s['datos'], true) : [];
        if (isset($in->nueva_clave)) {
            if (!(int) $r['clave_temporal']) return $err(400, 'Este paso no corresponde.');
            if (password_verify((string) $in->nueva_clave, $r['clave_hash'])) return $err(400, 'Elige una clave distinta de la temporal.');
            $e = ctaCambiarClave($pdo, $r['id'], (string) $in->nueva_clave);
            if ($e) return $err(400, $e);
            return ctaSiguiente($pdo, ctaFila($pdo, $r['id']), (string) $in->desafio, $ip);
        }
        if (isset($in->codigo)) {
            if ((int) $r['clave_temporal']) return $err(400, 'Primero elige tu clave.');
            $activar = !(int) $r['totp_activo'];
            $secreto = $activar ? (isset($d['secreto']) ? $d['secreto'] : '') : $r['totp_secreto'];
            if (!$secreto) return $err(400, 'Vuelve a empezar el ingreso.');
            $paso = ctaVerificarTotp($secreto, $in->codigo, $activar ? 0 : (int) $r['totp_ultimo']);
            if (!$paso) { ctaFallo($pdo, $r, $ip, 'código'); return $err(401, 'El código no es correcto o ya se usó. Espera el siguiente e inténtalo de nuevo.'); }
            $pdo->prepare('UPDATE usuarios SET totp_secreto = ?, totp_activo = 1, totp_ultimo = ?, fallos = 0, bloqueado_hasta = 0, ultimo_ingreso = ? WHERE id = ?')
                ->execute([$secreto, $paso, date('c'), $r['id']]);
            ctaBorrarToken($pdo, (string) $in->desafio);
            if ($activar) ctaEvento($pdo, $r['id'], $r['id'], 'segundo factor activado', '', $ip);
            ctaEvento($pdo, $r['id'], $r['id'], 'ingreso', '', $ip);
            $tok = ctaNuevoToken($pdo, $r['id'], 'sesion', ctaHorasSesion() * 3600, null, $ip, $agente);
            return [200, ['ok' => true, 'usuario' => ctaPublico(ctaFila($pdo, $r['id']))], $tok];
        }
        return ctaSiguiente($pdo, $r, (string) $in->desafio, $ip);
    }
    $usuario = isset($in->usuario) ? (string) $in->usuario : ''; $clave = isset($in->clave) ? (string) $in->clave : '';
    $r = $usuario !== '' ? ctaFilaPorUsuario($pdo, $usuario) : null;
    if (!$r || !(int) $r['activo']) {
        password_verify($clave, password_hash('sin-cuenta', PASSWORD_DEFAULT));   // tiempo de respuesta parecido exista o no la cuenta
        return [401, ['error' => 'Usuario o clave incorrectos.', 'fallo' => true], null];
    }
    if (ctaBloqueada($r)) return $err(429, 'Cuenta bloqueada por intentos fallidos. Espera 15 minutos.');
    if (!password_verify($clave, $r['clave_hash'])) { ctaFallo($pdo, $r, $ip, 'clave'); return [401, ['error' => 'Usuario o clave incorrectos.', 'fallo' => true], null]; }
    $pdo->prepare('UPDATE usuarios SET fallos = 0 WHERE id = ?')->execute([$r['id']]);
    $tok = ctaNuevoToken($pdo, $r['id'], 'desafio', CTA_DESAFIO_MIN * 60, [], $ip, $agente);
    return ctaSiguiente($pdo, $r, $tok, $ip);
}
