<?php
// Verifica que el resumen de Joan informe la marca "no vino en el funnel". Uso: php agente.test.php /ruta/api.php /tmp/crm-datos.json
$src = file_get_contents($argv[1]);
// Solo las funciones y constantes; sin la parte que atiende peticiones
$fin = strpos($src, "/* ---------- Clave ---------- */");
$codigo = substr($src, strpos($src, '<?php') + 5, $fin - 5);
$codigo = preg_replace('/^header\(.*$/m', '', $codigo);
$codigo = str_replace("require __DIR__ . '/almacen.php';", 'require ' . var_export(dirname(realpath($argv[1])) . '/almacen.php', true) . ';', $codigo);
$codigo = str_replace(["\$configFile = __DIR__ . '/config.php';", "if (!is_file(\$configFile))", "require \$configFile;"], ['', 'if (false)', ''], $codigo);
eval($codigo);
$actual = json_decode(file_get_contents($argv[2]));
$r = resumenAgente($actual, null);
$fallas = 0;
$con = array_values(array_filter($r['oportunidades_abiertas'], function ($o) { return (bool) preg_grep('/no vino en el funnel/', $o['alertas']); }));
$ok = function ($c, $m) use (&$fallas) { echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fallas++; };
$ok(count($con) === 2, 'el resumen de Joan marca las 2 oportunidades que no vinieron en el último funnel (102 y 103)');
$ok(count($r['oportunidades_abiertas']) === 5, 'el resumen sigue entregando las 5 oportunidades abiertas');
exit($fallas ? 1 : 0);
