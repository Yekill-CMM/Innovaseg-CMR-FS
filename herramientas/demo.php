<?php
/* =========================================================================
   CRM FS - instalación de demostración con datos inventados
   Uso (desde la carpeta del CRM, en una instalación de prueba, nunca en la de producción):
     php herramientas/demo.php                     carga la demo en app/datos
     php herramientas/demo.php --datos=<carpeta>   en otra carpeta de datos
     php herramientas/demo.php --hoy=2026-12-01    fechas calculadas desde ese día
   Crea las 12 cuentas de herramientas/demo-datos.php (gerente, dos subgerencias con
   subgerente y 4 ejecutivos, administrador) con clave temporal, y la cartera de ejemplo.
   Las claves temporales se muestran una sola vez: al entrar, cada persona elige la suya y
   activa el segundo factor.
   No pisa nada: si la carpeta ya tiene datos o cuentas, se detiene sin tocarla.
   ========================================================================= */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('America/Santiago');
require __DIR__ . '/../app/cuentas.php';
require __DIR__ . '/demo-datos.php';
$dir = __DIR__ . '/../app/datos'; $hoy = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--datos=') === 0) $dir = substr($a, 8);
    elseif (strpos($a, '--hoy=') === 0) $hoy = substr($a, 6);
    else { fwrite(STDERR, "Uso: php herramientas/demo.php [--datos=<carpeta>] [--hoy=AAAA-MM-DD]\n"); exit(1); }
}
if ($hoy !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hoy)) { fwrite(STDERR, "La fecha va como AAAA-MM-DD.\n"); exit(1); }
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { fwrite(STDERR, "No se pudo crear $dir\n"); exit(1); }
foreach (['crm.json.php', 'crm.db'] as $x) if (is_file("$dir/$x")) { fwrite(STDERR, "La carpeta $dir ya tiene datos ($x). La demo se carga solo en una carpeta sin datos.\n"); exit(1); }
$pdo = ctaAbrir($dir);
if (!$pdo) { fwrite(STDERR, "Falta SQLite en el PHP del servidor (paquete php-sqlite3).\n"); exit(1); }
if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) { fwrite(STDERR, "La carpeta $dir ya tiene cuentas. La demo se carga solo en una carpeta sin cuentas.\n"); exit(1); }

$claves = [];
$pdo->beginTransaction();
foreach (DEMO_EQUIPO as [$id, $usuario, $nombre, $rol, $equipo, $admin]) {
    [$e, , $clave] = ctaCrear($pdo, (object) ['usuario' => $usuario, 'nombre' => $nombre, 'rol' => $rol, 'equipo' => $equipo, 'admin' => $admin], null, $id);
    if ($e) { $pdo->rollBack(); fwrite(STDERR, "No se pudo crear $usuario: $e\n"); exit(1); }
    $claves[] = [$usuario, $nombre, $rol . ($equipo !== '' ? " $equipo" : ''), $clave];
}
$pdo->commit();

$data = demoDatos($hoy);
$doc = ['rev' => 1, 'savedAt' => $data->savedAt, 'data' => $data];
$json = json_encode($doc, JSON_UNESCAPED_UNICODE);
if ($json === false || file_put_contents("$dir/crm.json.php", "<?php exit; ?>\n" . $json, LOCK_EX) === false) { fwrite(STDERR, "No se pudo escribir $dir/crm.json.php\n"); exit(1); }

echo "Demo cargada en $dir: ", count($data->companies), ' empresas, ', count($data->contacts), ' contactos, ', count($data->leads), ' prospectos, ', count($data->opps), " oportunidades.\n\n";
echo "Cuentas (clave temporal, se muestra una sola vez):\n";
foreach ($claves as [$u, $n, $r, $c]) printf("  %-15s %-18s %-15s %s\n", $u, $n, $r, $c);
echo "\nAl entrar, cada cuenta elige su clave y activa el segundo factor.\n";
