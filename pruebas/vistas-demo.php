<?php
// Lo que el servidor le manda a cada cuenta de la demo (herramientas/demo-datos.php filtrado con el permisos.php real),
// para que funnel.test.js pruebe la pantalla con los datos que de verdad recibe cada rol. Escribe JSON en la salida.
// Uso: php vistas-demo.php ../app [AAAA-MM-DD]
$APP = realpath($argv[1]); $RAIZ = dirname($APP);
require "$APP/permisos.php"; require "$RAIZ/herramientas/demo-datos.php";
date_default_timezone_set('America/Santiago');
$dir = array_map(function ($u) { return (object) ['id' => $u[0], 'usuario' => $u[1], 'name' => $u[2], 'role' => $u[3], 'equipo' => $u[4], 'admin' => $u[5] || $u[3] === 'admin', 'activo' => true, 'roleLabel' => $u[6]]; }, DEMO_EQUIPO);
$d = demoDatos($argv[2] ?? null);
$out = [];
foreach ($dir as $u) { $ctx = permCtx($u, $dir); $v = permVista($d, $ctx); $out[$u->id] = ['data' => $v, 'users' => permVista((object) [], $ctx)->users]; }
echo json_encode($out, JSON_UNESCAPED_UNICODE);
