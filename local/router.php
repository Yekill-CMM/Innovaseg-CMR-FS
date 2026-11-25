<?php
/* Router para el servidor local de PHP (php -S). Hace lo que en el hosting
   hacía .htaccess: bloquea archivos internos y agrega cabeceras de seguridad. */
$ruta = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
if (preg_match('#(^|/)(config\.php|almacen\.php|almacen\.log|\.lock|\.lock-db|\.htaccess|[^/]*\.json\.php|[^/]*\.tmp|[^/]*\.db[^/]*)$#', $ruta) || preg_match('#^/datos(/|$)#', $ruta) || strpos($ruta, '..') !== false) {
    http_response_code(403);
    exit('Prohibido');
}
return false;   // el resto lo sirve php -S tal cual (index.html, api.php)
