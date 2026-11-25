<?php
// Ayuda para las pruebas con servidor (php -S): cuentas inventadas listas para entrar y peticiones con sesión.
// pruebaCuentas($datos, [[id, usuario, nombre, rol, equipo, admin], ...]) deja cada cuenta con clave CLAVE_PRUEBA
// y segundo factor activo con SECRETO_PRUEBA. pruebaHttp($port) devuelve una función $req($metodo, $q, $cuerpo, $cookie, $cabeceras).
const CLAVE_PRUEBA = 'Clave-de-prueba-2026';
const SECRETO_PRUEBA = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function pruebaCuentas($datos, $lista) {
    @mkdir($datos, 0777, true);
    $pdo = ctaAbrir($datos);
    foreach ($lista as $u) {
        [$id, $usuario, $nombre, $rol, $equipo, $admin] = $u;
        [$e] = ctaCrear($pdo, (object) ['usuario' => $usuario, 'nombre' => $nombre, 'rol' => $rol, 'equipo' => $equipo, 'admin' => $admin], null, $id);
        if ($e) throw new Exception("No se pudo crear $usuario: $e");
        $pdo->prepare('UPDATE usuarios SET clave_hash = ?, clave_temporal = 0, totp_secreto = ?, totp_activo = 1 WHERE id = ?')
            ->execute([password_hash(CLAVE_PRUEBA, PASSWORD_DEFAULT), SECRETO_PRUEBA, $id]);
    }
    return $pdo;
}

function pruebaHttp($port) {
    return function ($m, $q, $body = null, $cookie = '', $cab = []) use ($port) {
        $h = array_merge(['Content-Type: application/json', 'X-CRM: 1'], $cookie ? ['Cookie: crm_sesion=' . $cookie] : [], $cab);
        $ctx = stream_context_create(['http' => ['method' => $m, 'header' => implode("\r\n", $h),
            'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE), 'ignore_errors' => true, 'timeout' => 30]]);
        $r = file_get_contents("http://127.0.0.1:$port/api.php$q", false, $ctx);
        preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $mm);
        $set = '';
        foreach ($http_response_header as $x) if (preg_match('/^Set-Cookie:\s*crm_sesion=([^;]*)/i', $x, $c)) $set = $c[1];
        return [(int) $mm[1], json_decode($r), $set, $http_response_header];
    };
}

// Entra con usuario, clave y código; devuelve el token de sesión. $pdo permite reiniciar el «último código usado» entre ingresos de la misma cuenta.
function pruebaEntrar($req, $usuario, $pdo = null) {
    if ($pdo) $pdo->prepare('UPDATE usuarios SET totp_ultimo = 0 WHERE usuario = ?')->execute([$usuario]);
    [$c, $r] = $req('POST', '?accion=entrar', ['usuario' => $usuario, 'clave' => CLAVE_PRUEBA]);
    if ($c !== 200 || ($r->paso ?? '') !== 'codigo') throw new Exception("Ingreso de $usuario: $c " . json_encode($r));
    [$c, $r, $tok] = $req('POST', '?accion=entrar', ['desafio' => $r->desafio, 'codigo' => ctaTotp(SECRETO_PRUEBA, intdiv(time(), 30))]);
    if ($c !== 200 || !$tok) throw new Exception("Código de $usuario: $c " . json_encode($r));
    return $tok;
}

function pruebaServidor($T) {
    $port = 19000 + random_int(0, 999);
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$T/app", "$T/local/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    register_shutdown_function(function () use ($srv, $T) { proc_terminate($srv); exec('rm -rf ' . escapeshellarg($T)); });
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    return $port;
}
