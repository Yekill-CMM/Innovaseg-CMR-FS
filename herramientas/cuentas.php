<?php
/* =========================================================================
   CRM FS - cuentas desde la línea de comandos del servidor
   Uso (desde la carpeta del CRM):
     php herramientas/cuentas.php crear-admin <usuario> "<Nombre>"   primera cuenta de administrador
     php herramientas/cuentas.php listar                             cuentas y su estado
     php herramientas/cuentas.php clave <usuario>                    clave temporal nueva
     php herramientas/cuentas.php segundo-factor <usuario>           reinicia el segundo factor
   La clave temporal se muestra una sola vez; al entrar, la persona elige la suya y activa
   el segundo factor. Opcional: --datos=<carpeta> (por defecto app/datos).
   ========================================================================= */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('America/Santiago');
require __DIR__ . '/../app/cuentas.php';
$args = array_slice($argv, 1); $dir = __DIR__ . '/../app/datos';
foreach ($args as $i => $a) if (strpos($a, '--datos=') === 0) { $dir = substr($a, 8); unset($args[$i]); }
$args = array_values($args);
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { fwrite(STDERR, "No se pudo crear $dir\n"); exit(1); }
$pdo = ctaAbrir($dir);
if (!$pdo) { fwrite(STDERR, "Falta SQLite en el PHP del servidor (paquete php-sqlite3).\n"); exit(1); }
$cmd = $args[0] ?? '';
$fila = function ($u) use ($pdo) { $r = ctaFilaPorUsuario($pdo, $u); if (!$r) { fwrite(STDERR, "No existe la cuenta «{$u}».\n"); exit(1); } return $r; };
if ($cmd === 'crear-admin' && count($args) >= 3) {
    [$e, $id, $clave] = ctaCrear($pdo, (object) ['usuario' => $args[1], 'nombre' => $args[2], 'rol' => 'admin', 'equipo' => '', 'admin' => true]);
    if ($e) { fwrite(STDERR, $e . "\n"); exit(1); }
    echo "Cuenta creada: " . ctaNormUsuario($args[1]) . "\nClave temporal: $clave\nAl entrar se pide una clave propia y se activa el segundo factor.\n"; exit(0);
}
if ($cmd === 'listar') {
    foreach ($pdo->query('SELECT * FROM usuarios ORDER BY activo DESC, nombre') as $r)
        printf("%-28s %-26s %-11s %-14s %s%s%s\n", $r['usuario'], $r['nombre'], $r['rol'] . ((int) $r['admin'] && $r['rol'] !== 'admin' ? '+admin' : ''), $r['equipo'] ?: '-',
            (int) $r['activo'] ? 'activa' : 'desactivada', (int) $r['totp_activo'] ? ', 2FA' : ', sin 2FA', (int) $r['clave_temporal'] ? ', clave temporal' : '');
    exit(0);
}
if ($cmd === 'clave' && count($args) >= 2) { $r = $fila($args[1]); [$c] = ctaReiniciarClave($pdo, $r['id'], null); echo "Clave temporal de {$r['usuario']}: $c\n"; exit(0); }
if ($cmd === 'segundo-factor' && count($args) >= 2) { $r = $fila($args[1]); ctaReiniciarSegundoFactor($pdo, $r['id'], null); echo "Segundo factor reiniciado para {$r['usuario']}: lo activa en su próximo ingreso.\n"; exit(0); }
fwrite(STDERR, "Uso: php herramientas/cuentas.php crear-admin <usuario> \"<Nombre>\" | listar | clave <usuario> | segundo-factor <usuario>\n"); exit(1);
