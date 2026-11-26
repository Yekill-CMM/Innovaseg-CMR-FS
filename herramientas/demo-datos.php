<?php
/* =========================================================================
   CRM FS - datos de demostración (todo inventado: personas, empresas, RUT, correos y montos)

   Un equipo de 12 cuentas con la estructura de First Security: gerente, dos subgerencias
   (subgerente con cartera propia y 4 ejecutivos cada una) y un administrador. La cartera es del
   rubro: energía, minería, transmisión, puertos, forestal, acuicultura, logística y retail.

   Está armada para mostrar los cuatro roles:
   - Empresas y contactos compartidos: todos ven todas, aunque las haya creado otra persona.
   - «Minera Altos de Quillagua» la creó el subgerente Norte y tiene oportunidades de dos ejecutivos
     de su equipo: cada uno ve solo la suya y puede agregar contactos y sitios, pero no editar la empresa.
   - «Subestaciones del Sur» tiene una oportunidad del Norte y otra del Sur: cada subgerente ve solo
     la de su equipo.
   - Isidora Vega entra por primera vez (clave temporal): se le pide clave propia y segundo factor.

   Las fechas se calculan desde el día en que se cargan, para que la demo siempre se vea al día.
   Ningún dato corresponde a personas o empresas reales.
   ========================================================================= */

// [id, usuario, nombre, rol, equipo, admin, cargo]
const DEMO_EQUIPO = [
    ['g1', 'mibarra', 'Marcela Ibarra', 'gerente', '', false, 'Gerente Comercial'],
    ['sN', 'rvaldes', 'Rodrigo Valdés', 'subgerente', 'Norte', false, 'Subgerente Norte'],
    ['eN1', 'pcifuentes', 'Paula Cifuentes', 'ejecutivo', 'Norte', false, 'Ejecutiva Comercial'],
    ['eN2', 'therrera', 'Tomás Herrera', 'ejecutivo', 'Norte', false, 'Ejecutivo Comercial'],
    ['eN3', 'crivas', 'Camila Rivas', 'ejecutivo', 'Norte', false, 'Ejecutiva Comercial'],
    ['eN4', 'dmorales', 'Diego Morales', 'ejecutivo', 'Norte', false, 'Ejecutivo Comercial'],
    ['sS', 'vlagos', 'Verónica Lagos', 'subgerente', 'Sur', false, 'Subgerente Sur'],
    ['eS1', 'jnunez', 'Javier Núñez', 'ejecutivo', 'Sur', false, 'Ejecutivo Comercial'],
    ['eS2', 'fsoto', 'Fernanda Soto', 'ejecutivo', 'Sur', false, 'Ejecutiva Comercial'],
    ['eS3', 'mcontreras', 'Matías Contreras', 'ejecutivo', 'Sur', false, 'Ejecutivo Comercial'],
    ['eS4', 'ivega', 'Isidora Vega', 'ejecutivo', 'Sur', false, 'Ejecutiva Comercial'],
    ['ad', 'admin.sistema', 'Admin Sistema', 'admin', '', true, 'Administrador'],
];
const DEMO_PRIMER_INGRESO = ['ivega'];   // cuentas que en la demo aún no han entrado nunca

// Metas mensuales (CLP) por persona con cartera: el CRM las usa en Hoy (config.targets, «Meta mensual» en Configuración)
const DEMO_METAS = ['sN' => 70000000, 'eN1' => 35000000, 'eN2' => 32000000, 'eN3' => 30000000, 'eN4' => 25000000,
    'sS' => 65000000, 'eS1' => 33000000, 'eS2' => 30000000, 'eS3' => 28000000, 'eS4' => 22000000];

function demoRut($n) {
    $s = 0; $m = 2; for ($x = $n; $x > 0; $x = intdiv($x, 10)) { $s += ($x % 10) * $m; $m = $m === 7 ? 2 : $m + 1; }
    $d = 11 - $s % 11; $dv = $d === 11 ? '0' : ($d === 10 ? 'K' : (string) $d);
    return number_format($n, 0, ',', '.') . '-' . $dv;
}

// Texto en minúsculas sin tildes ni espacios, para armar correos inventados
function demoAscii($s) {
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

function demoDatos($hoy = null) {
    $t0 = strtotime(($hoy ?: date('Y-m-d')) . ' 12:00:00');
    $f = function ($d) use ($t0) { return date('Y-m-d', $t0 + 86400 * $d); };
    $hoyIso = $f(0);
    $d = ['v' => 5, 'mode' => 'real', 'lastBackup' => '', 'companies' => [], 'contacts' => [], 'leads' => [], 'opps' => [], 'offers' => [],
        'activities' => [], 'tasks' => [], 'invoices' => [], 'recepciones' => [], 'plans' => [], 'audit' => [], 'fusiones' => []];

    // ---- Empresas (compartidas). createdBy = quien la creó; ownerId = responsable de la cuenta
    $C = function ($id, $legal, $trade, $rut, $ind, $region, $city, $rel, $by, $pot, $lvl, $src, $sites, $dias = -160) use (&$d, $f) {
        $d['companies'][] = ['id' => $id, 'legalName' => $legal, 'tradeName' => $trade, 'rut' => demoRut($rut), 'industry' => $ind, 'region' => $region, 'city' => $city,
            'relation' => $rel, 'ownerId' => $by, 'createdBy' => $by, 'updatedBy' => $by, 'potential' => $pot, 'level' => $lvl, 'source' => $src, 'website' => '', 'size' => 'Grande',
            'notes' => '', 'createdAt' => $f($dias), 'updatedAt' => $f($dias),
            'sites' => array_map(function ($s, $i) use ($id, $city) { return ['id' => "{$id}s$i", 'name' => $s[0], 'type' => $s[1], 'city' => $s[2] ?? $city]; }, $sites, array_keys($sites))];
    };
    $C('dc01', 'Fotovoltaica Pampa Clara SpA', 'Pampa Clara', 77310452, 'Energía', 'Antofagasta', 'María Elena', 'Cliente', 'eN1', 'Alto', 'A', 'Referido EPC', [['Parque FV Pampa Clara', 'Parque fotovoltaico'], ['Subestación elevadora Pampa Clara', 'Subestación']]);
    $C('dc02', 'Minera Altos de Quillagua S.A.', 'Altos de Quillagua', 96874310, 'Minería', 'Antofagasta', 'Calama', 'Cliente', 'sN', 'Alto', 'A', 'Licitación', [['Faena Quillagua', 'Faena'], ['Planta de óxidos', 'Planta'], ['Oficina Calama', 'Oficina'], ['Puerto de embarque Tocopilla', 'Puerto', 'Tocopilla']], -300);
    $C('dc03', 'Transmisora Cordón Andino S.A.', 'Cordón Andino', 96512877, 'Energía', 'Atacama', 'Copiapó', 'Cliente', 'eN2', 'Alto', 'A', 'Referido', [['Subestación Los Loros', 'Subestación'], ['Subestación Pajonales', 'Subestación', 'Diego de Almagro']]);
    $C('dc04', 'Hidrógeno Verde Mejillones SpA', 'H2V Mejillones', 77654018, 'Energía', 'Antofagasta', 'Mejillones', 'Prospecto', 'eN3', 'Alto', 'A', 'Evento sectorial', [['Planta piloto Mejillones', 'Planta']]);
    $C('dc05', 'Desaladora Costa Norte SpA', 'Costa Norte', 76988203, 'Sanitaria', 'Antofagasta', 'Taltal', 'Prospecto', 'eN3', 'Medio', 'B', 'Empresa de ingeniería', [['Planta desaladora Paposo', 'Planta']]);
    $C('dc06', 'Puerto Seco Tarapacá S.A.', 'Puerto Seco Tarapacá', 96230415, 'Logística', 'Tarapacá', 'Alto Hospicio', 'Cliente', 'eN4', 'Medio', 'B', 'Prospección directa', [['Centro de distribución Alto Hospicio', 'Centro de distribución']]);
    $C('dc07', 'Minera Valle de Elqui SpA', 'Valle de Elqui', 76402981, 'Minería', 'Coquimbo', 'Vicuña', 'Prospecto', 'eN4', 'Medio', 'B', 'LinkedIn', [['Faena El Molle', 'Faena']]);
    $C('dc08', 'Eólica Lomas del Biobío SpA', 'Lomas del Biobío', 77140326, 'Energía', 'Biobío', 'Mulchén', 'Cliente', 'eS1', 'Alto', 'A', 'Referido EPC', [['Parque eólico Lomas', 'Parque eólico'], ['Subestación Lomas', 'Subestación']]);
    $C('dc09', 'Terminal Portuario Bahía Lenga S.A.', 'Bahía Lenga', 96115820, 'Puertos', 'Biobío', 'Coronel', 'Cliente', 'eS2', 'Alto', 'A', 'Licitación', [['Terminal Bahía Lenga', 'Puerto'], ['Antepuerto Escuadrón', 'Centro de distribución']]);
    $C('dc10', 'Celulosa Río Itata S.A.', 'Celulosa Itata', 96455012, 'Forestal', 'Ñuble', 'Quillón', 'Prospecto', 'eS3', 'Alto', 'A', 'Evento sectorial', [['Planta Itata', 'Planta'], ['Patio de maderas Nueva Aldea', 'Planta']]);
    $C('dc11', 'Centro Logístico Lo Aguirre SpA', 'CL Lo Aguirre', 77822641, 'Centros de distribución', 'Metropolitana', 'Pudahuel', 'Cliente', 'sS', 'Medio', 'A', 'Referido', [['CD Lo Aguirre', 'Centro de distribución']]);
    $C('dc12', 'Salmones Seno Austral S.A.', 'Seno Austral', 96780534, 'Acuicultura', 'Los Lagos', 'Puerto Montt', 'Prospecto', 'eS4', 'Medio', 'B', 'Prospección directa', [['Planta de proceso Chinquihue', 'Planta'], ['Centros de cultivo Reloncaví', 'Faena']]);
    $C('dc13', 'Solar Valle de Colchagua SpA', 'Solar Colchagua', 77409125, 'Energía', "O'Higgins", 'Santa Cruz', 'Prospecto', 'eS2', 'Medio', 'B', 'Empresa de ingeniería', [['Parque FV Colchagua', 'Parque fotovoltaico']]);
    $C('dc14', 'Subestaciones del Sur S.A.', 'Subestaciones del Sur', 96330981, 'Energía', 'Araucanía', 'Temuco', 'Cliente', 'eS1', 'Alto', 'A', 'Licitación', [['Subestación Cautín', 'Subestación'], ['Subestación Antuco', 'Subestación', 'Los Ángeles'], ['Subestación Diego de Almagro Norte', 'Subestación', 'Diego de Almagro']]);
    $C('dc15', 'Mercados del Sur SpA', 'Mercados del Sur', 76219874, 'Retail', 'Los Ríos', 'Valdivia', 'Prospecto', 'eS3', 'Medio', 'B', 'Base importada', [['Casa matriz', 'Casa matriz'], ['CD Valdivia', 'Centro de distribución']], -20);

    // ---- Contactos (compartidos). createdBy = quien lo registró
    $K = function ($id, $co, $fn, $ln, $pos, $area, $role, $infl, $rel, $by, $tel, $dias = -120) use (&$d, $f) {
        $emp = ''; foreach ($d['companies'] as $c) if ($c['id'] === $co) $emp = $c['tradeName'];
        $mail = demoAscii(mb_substr($fn, 0, 1) . $ln) . '@' . demoAscii($emp) . '.example';
        $d['contacts'][] = ['id' => $id, 'companyId' => $co, 'firstName' => $fn, 'lastName' => $ln, 'position' => $pos, 'area' => $area, 'decisionRole' => $role, 'influence' => $infl,
            'relationship' => $rel, 'email' => $mail, 'phone' => $tel, 'whatsapp' => $tel, 'notes' => '', 'createdBy' => $by, 'createdAt' => $f($dias)];
    };
    $K('dk01', 'dc01', 'Andrés', 'Pizarro', 'Gerente de Operación', 'Operaciones', 'Decisor', 5, 'Fuerte', 'eN1', '+56 9 6120 4410');
    $K('dk02', 'dc01', 'Carla', 'Mondaca', 'Jefa de Seguridad Patrimonial', 'Seguridad', 'Influenciador', 4, 'Tibia', 'eN1', '+56 9 6120 4471');
    $K('dk03', 'dc02', 'Hernán', 'Cortés', 'Superintendente de Seguridad', 'Seguridad', 'Decisor', 5, 'Fuerte', 'sN', '+56 9 5871 2203', -280);
    $K('dk04', 'dc02', 'Pamela', 'Rojas', 'Jefa de Contratos', 'Abastecimiento', 'Comprador', 4, 'Tibia', 'sN', '+56 9 5871 2290', -280);
    $K('dk05', 'dc02', 'Mauricio', 'Tapia', 'Jefe de Puerto Tocopilla', 'Operaciones', 'Influenciador', 3, 'Tibia', 'eN2', '+56 9 5871 3318', -60);
    $K('dk06', 'dc03', 'Gonzalo', 'Espinoza', 'Gerente de Mantenimiento', 'Operaciones', 'Decisor', 5, 'Sponsor', 'eN2', '+56 9 7340 1185');
    $K('dk07', 'dc03', 'Natalia', 'Godoy', 'Ingeniera de Protecciones', 'Técnico', 'Técnico', 3, 'Tibia', 'eN2', '+56 9 7340 1123');
    $K('dk08', 'dc04', 'Felipe', 'Araya', 'Gerente de Proyecto', 'Proyectos', 'Decisor', 4, 'Tibia', 'eN3', '+56 9 8812 6045');
    $K('dk09', 'dc05', 'Rocío', 'Valenzuela', 'Jefa de Prevención de Riesgos', 'Prevención de Riesgos', 'Influenciador', 3, 'Fría', 'eN3', '+56 9 8813 2210');
    $K('dk10', 'dc06', 'Cristián', 'Olivares', 'Gerente de Administración', 'Administración', 'Decisor', 4, 'Fuerte', 'eN4', '+56 9 7231 5590');
    $K('dk11', 'dc07', 'Sofía', 'Carvajal', 'Administradora de Contratos', 'Abastecimiento', 'Comprador', 3, 'Fría', 'eN4', '+56 9 7232 0081');
    $K('dk12', 'dc08', 'Ricardo', 'Sandoval', 'Gerente de Activos', 'Operaciones', 'Decisor', 5, 'Fuerte', 'eS1', '+56 9 6450 7712');
    $K('dk13', 'dc09', 'Javiera', 'Muñoz', 'Gerenta de Terminal', 'Operaciones', 'Decisor', 5, 'Tibia', 'eS2', '+56 9 6451 9034');
    $K('dk14', 'dc09', 'Esteban', 'Leiva', 'Jefe de Protección Portuaria', 'Seguridad', 'Influenciador', 4, 'Fuerte', 'eS2', '+56 9 6451 9088');
    $K('dk15', 'dc10', 'Patricio', 'Bustos', 'Gerente de Planta', 'Operaciones', 'Decisor', 5, 'Fría', 'eS3', '+56 9 5562 3407');
    $K('dk16', 'dc11', 'Daniela', 'Fuentes', 'Jefa de Facility', 'Facility Management', 'Decisor', 4, 'Sponsor', 'sS', '+56 9 9021 4456');
    $K('dk17', 'dc12', 'Álvaro', 'Navarro', 'Subgerente de Seguridad y Salud', 'Seguridad', 'Influenciador', 3, 'Fría', 'eS4', '+56 9 9022 1183');
    $K('dk18', 'dc13', 'Constanza', 'Reyes', 'Jefa de Proyecto EPC', 'Proyectos', 'Técnico', 3, 'Tibia', 'eS2', '+56 9 7783 6620');
    $K('dk19', 'dc14', 'Luis', 'Figueroa', 'Gerente de Operación y Mantenimiento', 'Operaciones', 'Decisor', 5, 'Fuerte', 'eS1', '+56 9 7784 0912');
    $K('dk20', 'dc14', 'Marta', 'Henríquez', 'Jefa de Zona Norte', 'Operaciones', 'Influenciador', 3, 'Tibia', 'eN2', '+56 9 7784 0950', -30);
    $K('dk21', 'dc15', 'Óscar', 'Jara', 'Gerente de Prevención de Pérdidas', 'Prevención de Pérdidas', 'Decisor', 4, 'Fría', 'eS3', '+56 9 6640 2219', -15);

    // ---- Oportunidades (por dueño). Código OP-AAMMDD-NN según la fecha de creación
    $usados = [];
    $op = function ($creada) use (&$usados, $t0) {
        $k = date('ymd', $t0 + 86400 * $creada);
        for ($n = 1; isset($usados["OP-$k-" . sprintf('%02d', $n)]); $n++);
        return $usados["OP-$k-" . sprintf('%02d', $n)] = "OP-$k-" . sprintf('%02d', $n);
    };
    $O = function ($o) use (&$d, $f, $op) {
        $creada = $o['creada']; unset($o['creada']);
        $base = ['crm' => true, 'currency' => 'CLP', 'oneTime' => 0, 'monthly' => 0, 'term' => 0, 'status' => 'abierta', 'contactIds' => [], 'competitors' => '', 'currentProvider' => '',
            'need' => '', 'pain' => '', 'strategy' => '', 'risks' => '', 'probReason' => '', 'history' => [], 'siteId' => '', 'services' => [], 'nextAction' => '', 'nextActionDate' => ''];
        $o = array_merge($base, $o, ['code' => $op($creada), 'createdAt' => $f($creada), 'createdBy' => $o['ownerId'], 'updatedBy' => $o['ownerId']]);
        foreach (['closeDate', 'nextActionDate', 'stageEnteredAt', 'wonAt', 'lostAt'] as $k) if (isset($o[$k]) && is_int($o[$k])) $o[$k] = $f($o[$k]);
        $d['opps'][] = $o; return $o['code'];
    };
    $P = ['identificada' => 10, 'calificada' => 20, 'levantamiento' => 30, 'oferta' => 50, 'seguimiento' => 60, 'negociacion' => 80, 'adjudicada' => 100];
    $o = function ($id, $owner, $name, $co, $site, $tipo, $stage, $creada, $extra) use ($O, $P) {
        return $O(array_merge(['id' => $id, 'ownerId' => $owner, 'name' => $name, 'companyId' => $co, 'siteId' => $site, 'tipo' => $tipo, 'stage' => $stage, 'prob' => $P[$stage] ?? 0, 'creada' => $creada, 'stageEnteredAt' => -10], $extra));
    };
    // Subgerencia Norte
    $o('do01', 'sN', 'Seguridad integral faena Quillagua 2027-2029', 'dc02', 'dc02s0', 'Seg. Integral', 'negociacion', -95, ['monthly' => 92000000, 'term' => 36, 'oneTime' => 140000000, 'closeDate' => 15, 'stageEnteredAt' => -12, 'contactIds' => ['dk03', 'dk04'], 'services' => ['Guardias', 'Supervisión', 'CCTV', 'Monitoreo remoto 24/7'], 'need' => 'Renovación del contrato de seguridad con tecnificación', 'pain' => 'Robos de cobre en botaderos y alta rotación de guardias', 'competitors' => 'Dos empresas nacionales de guardias', 'currentProvider' => 'Proveedor nacional de guardias', 'strategy' => 'Bajar dotación nocturna con radares y analítica sobre el perímetro', 'risks' => 'Contratos pide revisar reajuste polinómico', 'nextAction' => 'Reunión de cierre con Contratos', 'nextActionDate' => 2]);
    $o('do02', 'sN', 'Control de acceso vehicular puerto Tocopilla', 'dc02', 'dc02s3', 'Seg. Electrónica', 'calificada', -8, ['oneTime' => 58000000, 'closeDate' => 70, 'stageEnteredAt' => -4, 'contactIds' => ['dk05'], 'services' => ['Control de acceso', 'LPR'], 'nextAction' => 'Visita técnica al puerto', 'nextActionDate' => 6]);
    $o('do03', 'eN1', 'CCTV y perimetral Parque FV Pampa Clara', 'dc01', 'dc01s0', 'Seg. Electrónica', 'oferta', -60, ['oneTime' => 165000000, 'monthly' => 4200000, 'term' => 36, 'closeDate' => 30, 'stageEnteredAt' => -15, 'contactIds' => ['dk01', 'dk02'], 'services' => ['CCTV', 'Protección perimetral', 'Analítica de video', 'Monitoreo remoto 24/7'], 'need' => 'Robo de cable en strings y hurto de paneles', 'pain' => 'Penalizaciones por indisponibilidad de la planta', 'competitors' => 'Integrador regional', 'currentProvider' => 'Guardias del EPC', 'strategy' => 'Perímetro de 7 km con fibra sensora y cámaras térmicas', 'nextAction' => 'Presentar oferta técnica al gerente de operación', 'nextActionDate' => 0]);
    $o('do04', 'eN1', 'Monitoreo 24/7 subestación elevadora Pampa Clara', 'dc01', 'dc01s1', 'Seg. Electrónica', 'adjudicada', -110, ['status' => 'ganada', 'monthly' => 3100000, 'term' => 36, 'closeDate' => -18, 'wonAt' => -18, 'wonDoc' => 'OC 4500-0071 (inventada)', 'stageEnteredAt' => -18, 'contactIds' => ['dk01'], 'services' => ['Monitoreo remoto 24/7', 'CCTV'], 'nextAction' => 'Traspaso a operaciones', 'nextActionDate' => -18]);
    $o('do05', 'eN1', 'Guardias planta de óxidos Quillagua', 'dc02', 'dc02s1', 'Seg. Física', 'seguimiento', -40, ['monthly' => 21000000, 'term' => 24, 'closeDate' => 20, 'stageEnteredAt' => -9, 'contactIds' => ['dk03'], 'services' => ['Guardias', 'Supervisión'], 'nextAction' => 'Seguimiento de la oferta con Hernán Cortés', 'nextActionDate' => -2]);
    $o('do06', 'eN2', 'Monitoreo remoto subestaciones Los Loros y Pajonales', 'dc03', 'dc03s0', 'Seg. Electrónica', 'negociacion', -85, ['currency' => 'UF', 'oneTime' => 1400, 'monthly' => 165, 'term' => 36, 'closeDate' => 10, 'stageEnteredAt' => -6, 'contactIds' => ['dk06', 'dk07'], 'services' => ['Monitoreo remoto 24/7', 'Intrusión / alarmas', 'CCTV'], 'need' => 'Supervisión de subestaciones no atendidas', 'pain' => 'Intrusiones en patio de alta tensión', 'nextAction' => 'Enviar condiciones revisadas', 'nextActionDate' => 1]);
    $o('do07', 'eN2', 'Rondas móviles puerto Tocopilla', 'dc02', 'dc02s3', 'Seg. Física', 'perdida', -120, ['status' => 'perdida', 'stage' => 'seguimiento', 'prob' => 0, 'monthly' => 7800000, 'term' => 24, 'closeDate' => -25, 'lostAt' => -25, 'lossReason' => 'Precio', 'lossComment' => 'Competidor ofertó 15 % bajo nuestro valor', 'stageEnteredAt' => -40]);
    $o('do08', 'eN2', 'Analítica de video subestación Diego de Almagro Norte', 'dc14', 'dc14s2', 'Seg. Electrónica', 'oferta', -22, ['oneTime' => 47000000, 'closeDate' => 40, 'stageEnteredAt' => -7, 'contactIds' => ['dk20'], 'services' => ['Analítica de video', 'CCTV'], 'nextAction' => 'Cerrar arquitectura con Marta Henríquez', 'nextActionDate' => 3]);
    $o('do09', 'eN3', 'Seguridad integral planta piloto H2V Mejillones', 'dc04', 'dc04s0', 'Seg. Integral', 'levantamiento', -18, ['oneTime' => 75000000, 'monthly' => 18000000, 'term' => 24, 'closeDate' => 85, 'stageEnteredAt' => -5, 'contactIds' => ['dk08'], 'services' => ['Guardias', 'Control de acceso', 'CCTV', 'Ingeniería / proyectos'], 'nextAction' => 'Levantamiento en terreno', 'nextActionDate' => 4]);
    $o('do10', 'eN3', 'Mantenimiento preventivo sistemas Paposo', 'dc05', 'dc05s0', 'One Shot', 'oferta', -30, ['oneTime' => 26000000, 'closeDate' => 25, 'stageEnteredAt' => -12, 'contactIds' => ['dk09'], 'services' => ['Mantenimiento preventivo'], 'nextAction' => 'Llamar a Rocío Valenzuela por la oferta', 'nextActionDate' => -1]);
    $o('do11', 'eN4', 'Guardias y CCTV CD Alto Hospicio', 'dc06', 'dc06s0', 'Seg. Integral', 'adjudicada', -90, ['status' => 'ganada', 'monthly' => 14500000, 'term' => 24, 'closeDate' => -35, 'wonAt' => -35, 'wonDoc' => 'Contrato firmado (inventado)', 'stageEnteredAt' => -35, 'contactIds' => ['dk10'], 'services' => ['Guardias', 'CCTV']]);
    $o('do12', 'eN4', 'Control de acceso faena El Molle', 'dc07', 'dc07s0', 'Seg. Electrónica', 'identificada', -3, ['oneTime' => 32000000, 'closeDate' => 110, 'stageEnteredAt' => -3, 'contactIds' => ['dk11'], 'services' => ['Control de acceso'], 'nextAction' => 'Confirmar interés con Sofía Carvajal', 'nextActionDate' => 5]);
    // Subgerencia Sur
    $o('do13', 'sS', 'Renovación seguridad CD Lo Aguirre', 'dc11', 'dc11s0', 'Seg. Integral', 'seguimiento', -50, ['monthly' => 24000000, 'term' => 36, 'closeDate' => 18, 'stageEnteredAt' => -10, 'contactIds' => ['dk16'], 'services' => ['Guardias', 'CCTV', 'Monitoreo remoto 24/7'], 'nextAction' => 'Reunión con Daniela Fuentes por la oferta', 'nextActionDate' => 1]);
    $o('do14', 'sS', 'Mantenimiento correctivo red de subestaciones', 'dc14', 'dc14s0', 'One Shot', 'adjudicada', -70, ['status' => 'ganada', 'oneTime' => 38000000, 'closeDate' => -8, 'wonAt' => -8, 'wonDoc' => 'OC 7700-0123 (inventada)', 'stageEnteredAt' => -8, 'contactIds' => ['dk19'], 'services' => ['Mantenimiento correctivo'], 'nextAction' => 'Traspaso a operaciones', 'nextActionDate' => -8]);
    $o('do15', 'eS1', 'CCTV térmico y perimetral parque eólico Lomas', 'dc08', 'dc08s0', 'Seg. Electrónica', 'negociacion', -75, ['oneTime' => 132000000, 'monthly' => 3600000, 'term' => 36, 'closeDate' => 12, 'stageEnteredAt' => -5, 'contactIds' => ['dk12'], 'services' => ['CCTV', 'Protección perimetral', 'Radares', 'Monitoreo remoto 24/7'], 'need' => 'Robo de cobre en aerogeneradores', 'pain' => 'Tres robos en el último trimestre', 'nextAction' => 'Negociación final con Ricardo Sandoval', 'nextActionDate' => 2]);
    $o('do16', 'eS1', 'Monitoreo 24/7 subestaciones Cautín y Antuco', 'dc14', 'dc14s0', 'Seg. Electrónica', 'oferta', -28, ['currency' => 'UF', 'oneTime' => 900, 'monthly' => 120, 'term' => 36, 'closeDate' => 35, 'stageEnteredAt' => -14, 'contactIds' => ['dk19'], 'services' => ['Monitoreo remoto 24/7', 'Intrusión / alarmas'], 'nextAction' => 'Ajustar la oferta con el gerente de O&M', 'nextActionDate' => 0]);
    $o('do17', 'eS1', 'Guardias subestación elevadora Lomas', 'dc08', 'dc08s1', 'Seg. Física', 'adjudicada', -130, ['status' => 'ganada', 'monthly' => 6200000, 'term' => 24, 'closeDate' => -45, 'wonAt' => -45, 'wonDoc' => 'Contrato firmado (inventado)', 'stageEnteredAt' => -45, 'contactIds' => ['dk12'], 'services' => ['Guardias']]);
    $o('do18', 'eS2', 'Protección portuaria y LPR Terminal Bahía Lenga', 'dc09', 'dc09s0', 'Seg. Integral', 'oferta', -45, ['oneTime' => 98000000, 'monthly' => 31000000, 'term' => 36, 'closeDate' => 28, 'stageEnteredAt' => -16, 'contactIds' => ['dk13', 'dk14'], 'services' => ['Guardias', 'LPR', 'Control de acceso', 'CCTV'], 'need' => 'Cumplimiento del código PBIP y control de camiones', 'competitors' => 'Proveedor internacional', 'nextAction' => 'Entregar la oferta a la gerenta de terminal', 'nextActionDate' => 3]);
    $o('do19', 'eS2', 'CCTV Parque FV Colchagua', 'dc13', 'dc13s0', 'Seg. Electrónica', 'perdida', -100, ['status' => 'perdida', 'stage' => 'oferta', 'prob' => 0, 'oneTime' => 54000000, 'closeDate' => -30, 'lostAt' => -30, 'lossReason' => 'Cliente postergó', 'lossComment' => 'El EPC movió la puesta en marcha a 2027', 'stageEnteredAt' => -50]);
    $o('do20', 'eS3', 'Seguridad integral Planta Itata', 'dc10', 'dc10s0', 'Seg. Integral', 'levantamiento', -14, ['oneTime' => 60000000, 'monthly' => 38000000, 'term' => 36, 'closeDate' => 90, 'stageEnteredAt' => -6, 'contactIds' => ['dk15'], 'services' => ['Guardias', 'Supervisión', 'CCTV', 'Control de acceso'], 'nextAction' => 'Recorrido por patio de maderas', 'nextActionDate' => 7]);
    $o('do21', 'eS4', 'Monitoreo remoto centros de cultivo Reloncaví', 'dc12', 'dc12s1', 'Seg. Electrónica', 'calificada', -10, ['oneTime' => 41000000, 'monthly' => 2900000, 'term' => 24, 'closeDate' => 75, 'stageEnteredAt' => -4, 'contactIds' => ['dk17'], 'services' => ['CCTV', 'Radares', 'Monitoreo remoto 24/7'], 'nextAction' => 'Enviar propuesta preliminar', 'nextActionDate' => 2]);
    foreach ($d['opps'] as &$x) if (!isset($x['closeDate'])) $x['closeDate'] = $f(60); unset($x);

    // ---- Prospectos (por dueño)
    $L = function ($id, $owner, $co, $ct, $src, $stage, $pot, $problem, $svc, $next, $nd, $creada, $extra = []) use (&$d, $f) {
        $d['leads'][] = array_merge(['id' => $id, 'code' => 'PR-' . substr($f($creada), 0, 4) . '-' . sprintf('%04d', count($d['leads']) + 1), 'companyId' => $co, 'contactId' => $ct, 'source' => $src, 'stage' => $stage,
            'potential' => $pot, 'problem' => $problem, 'service' => $svc, 'ownerId' => $owner, 'nextAction' => $next, 'nextActionDate' => $nd === '' ? '' : $f($nd), 'vars' => (object) [],
            'createdAt' => $f($creada), 'createdBy' => $owner], $extra);
    };
    $L('dl01', 'eN1', 'dc02', 'dk03', 'Referido', 'Reunión solicitada', 'Alto', 'Puerto de embarque sin control de acceso vehicular', 'Control de acceso y LPR', 'Confirmar fecha de reunión', 3, -12);
    $L('dl02', 'eN3', 'dc05', 'dk09', 'Empresa de ingeniería', 'Contactado', 'Medio', 'Planta nueva sin sistema de intrusión', 'Intrusión y CCTV', 'Enviar presentación de la compañía', 1, -9);
    $L('dl03', 'eN4', 'dc07', 'dk11', 'LinkedIn', 'Investigación', 'Medio', 'Por confirmar', 'Guardias', 'Identificar al superintendente de seguridad', 4, -6);
    $L('dl04', 'sN', 'dc03', 'dk06', 'Referido', 'Nurturing', 'Alto', 'Sin presupuesto este año para la ampliación', 'Monitoreo remoto 24/7', 'Retomar para presupuesto 2027', 40, -70);
    $L('dl05', 'eS1', 'dc14', 'dk19', 'Licitación', 'Calificación', 'Alto', 'Licitación de vigilancia de 12 subestaciones', 'Seguridad integral', 'Revisar bases de la licitación', 2, -15);
    $L('dl06', 'eS2', 'dc13', 'dk18', 'Empresa de ingeniería', 'Contacto inicial', 'Medio', 'Puesta en marcha 2027', 'CCTV y perimetral', 'Segundo intento por correo', -1, -20);
    $L('dl07', 'eS3', 'dc15', 'dk21', 'Base importada', 'Contactado', 'Medio', 'Merma en salas y CD', 'CCTV y monitoreo', 'Agendar visita al CD', 5, -10);
    $L('dl08', 'eS4', 'dc12', '', 'Prospección directa', 'Identificado', 'Bajo', 'Por confirmar', 'Guardias', 'Buscar contacto en la planta de proceso', 6, -4);
    $L('dl09', 'sS', 'dc09', 'dk14', 'Referido', 'Reunión realizada', 'Alto', 'Antepuerto con congestión y sin control de camiones', 'LPR y control de acceso', 'Calificar y proponer levantamiento', 2, -25);
    $L('dl10', 'eN2', 'dc03', 'dk07', 'Referido', 'Oportunidad creada', 'Alto', 'Intrusiones en subestaciones', 'Monitoreo remoto 24/7', '', '', -90, ['oppId' => 'do06']);

    // ---- Ofertas (por dueño, ligadas a la oportunidad)
    $V = function ($n, $net, $dd, $note, $locked) use ($f) { return ['n' => $n, 'net' => $net, 'date' => $f($dd), 'note' => $note, 'locked' => $locked]; };
    $F = function ($id, $code, $opp, $owner, $status, $sent, $valid, $versions) use (&$d, $f) {
        $d['offers'][] = ['id' => $id, 'code' => $code, 'oppId' => $opp, 'ownerId' => $owner, 'status' => $status, 'sentAt' => $sent === '' ? '' : $f($sent), 'validUntil' => $valid === '' ? '' : $f($valid),
            'createdAt' => $f(($sent === '' ? -1 : $sent) - 5), 'versions' => $versions];
    };
    $y = substr($hoyIso, 0, 4);
    $F('df01', "OF-$y-101", 'do01', 'sN', 'Negociación', -20, 25, [$V(1, 3600000000, -40, 'Versión inicial', true), $V(2, 3452000000, -20, 'Menos dotación nocturna con radares', true)]);
    $F('df02', "OF-$y-104", 'do03', 'eN1', 'Borrador', '', '', [$V(1, 316200000, -2, 'En preparación', false)]);
    $F('df03', "OF-$y-098", 'do05', 'eN1', 'En seguimiento', -9, 21, [$V(1, 504000000, -9, 'Versión inicial', true)]);
    $F('df04', "OF-$y-095", 'do06', 'eN2', 'Negociación', -14, 16, [$V(1, 296000000, -30, 'Versión inicial', true), $V(2, 288000000, -14, 'Condiciones de pago a 36 meses', true)]);
    $F('df05', "OF-$y-103", 'do10', 'eN3', 'Enviada', -12, 3, [$V(1, 26000000, -12, 'Versión inicial', true)]);
    $F('df06', "OF-$y-099", 'do13', 'sS', 'En seguimiento', -10, 20, [$V(1, 864000000, -10, 'Versión inicial', true)]);
    $F('df07', "OF-$y-096", 'do15', 'eS1', 'Negociación', -12, 18, [$V(1, 275000000, -25, 'Versión inicial', true), $V(2, 261600000, -12, 'Ajuste de cámaras térmicas', true)]);
    $F('df08', "OF-$y-102", 'do16', 'eS1', 'Enviada', -14, 16, [$V(1, 175000000, -14, 'Versión inicial', true)]);
    $F('df09', "OF-$y-100", 'do18', 'eS2', 'Borrador', '', '', [$V(1, 1214000000, -3, 'En preparación', false)]);

    // ---- Actividades (de quien las hizo) y tareas (de quien las tiene asignadas)
    $A = function ($dd, $u, $type, $et, $eid, $co, $desc, $res = '') use (&$d, $f) {
        $d['activities'][] = ['id' => 'da' . sprintf('%02d', count($d['activities']) + 1), 'date' => $f($dd), 'userId' => $u, 'type' => $type, 'entityType' => $et, 'entityId' => $eid, 'companyId' => $co,
            'desc' => $desc, 'result' => $res, 'nextAction' => '', 'nextActionDate' => ''];
    };
    $A(-40, 'sN', 'Visita a terreno', 'opp', 'do01', 'dc02', 'Recorrido por botaderos y accesos de la faena', 'Se identifican 5 puntos ciegos en el perímetro sur');
    $A(-12, 'sN', 'Negociación', 'opp', 'do01', 'dc02', 'Reunión con Contratos por reajuste', 'Piden reajuste por IPC en lugar de polinómico');
    $A(-5, 'sN', 'Reunión', 'opp', 'do05', 'dc02', 'Revisión de la oferta de guardias con Paula y el superintendente', 'El cliente valora la supervisión en terreno');
    $A(-15, 'eN1', 'Visita a terreno', 'opp', 'do03', 'dc01', 'Levantamiento del perímetro del parque', 'Perímetro de 7,1 km con dos accesos sin control');
    $A(-9, 'eN1', 'Envío de oferta', 'offer', 'df03', 'dc02', 'Envío de la oferta de guardias de la planta de óxidos');
    $A(-18, 'eN1', 'Negociación', 'opp', 'do04', 'dc01', 'Cierre del monitoreo de la subestación', 'Adjudicado con OC');
    $A(-6, 'eN2', 'Negociación', 'opp', 'do06', 'dc03', 'Reunión con mantenimiento por condiciones', 'Piden 4 % de descuento; se ofrece 2 % con plazo de 36 meses');
    $A(-7, 'eN2', 'Videollamada', 'opp', 'do08', 'dc14', 'Revisión técnica con Marta Henríquez', 'Envía planos de la subestación');
    $A(-5, 'eN3', 'Reunión', 'opp', 'do09', 'dc04', 'Primera reunión con el gerente de proyecto', 'La planta piloto parte en el segundo semestre');
    $A(-12, 'eN3', 'Envío de oferta', 'offer', 'df05', 'dc05', 'Envío de la oferta de mantenimiento');
    $A(-3, 'eN4', 'Llamada', 'opp', 'do12', 'dc07', 'Primer contacto con administración de contratos', 'Interesada; pide una presentación');
    $A(-10, 'sS', 'Envío de oferta', 'offer', 'df06', 'dc11', 'Envío de la oferta de renovación');
    $A(-8, 'sS', 'Negociación', 'opp', 'do14', 'dc14', 'Cierre del mantenimiento correctivo', 'OC recibida');
    $A(-5, 'eS1', 'Negociación', 'opp', 'do15', 'dc08', 'Reunión con el gerente de activos', 'Acepta la segunda versión si se incluye mantenimiento el primer año');
    $A(-14, 'eS1', 'Envío de oferta', 'offer', 'df08', 'dc14', 'Envío de la oferta de monitoreo');
    $A(-16, 'eS2', 'Visita a terreno', 'opp', 'do18', 'dc09', 'Recorrido por el terminal y el antepuerto', 'Cuatro accesos de camiones sin lectura de patentes');
    $A(-6, 'eS3', 'Visita a terreno', 'opp', 'do20', 'dc10', 'Visita a la planta con el gerente', 'Pide incluir el patio de maderas');
    $A(-4, 'eS4', 'Llamada', 'opp', 'do21', 'dc12', 'Llamada con el subgerente de seguridad', 'Robos de equipos en centros de cultivo');
    $A(-2, 'g1', 'Reunión', 'opp', 'do01', 'dc02', 'Revisión del cierre de Quillagua con Rodrigo', 'Se aprueba un 2 % de descuento como máximo');
    $A(1, 'eN1', 'Reunión', 'opp', 'do03', 'dc01', 'Presentación de la oferta técnica en el parque');
    $A(2, 'eS1', 'Reunión', 'opp', 'do15', 'dc08', 'Negociación final del parque eólico');
    $T = function ($title, $et, $eid, $who, $pri, $due, $by = null) use (&$d, $f) {
        $d['tasks'][] = ['id' => 'dt' . sprintf('%02d', count($d['tasks']) + 1), 'title' => $title, 'entityType' => $et, 'entityId' => $eid, 'assigneeId' => $who, 'priority' => $pri,
            'due' => $f($due), 'status' => 'Pendiente', 'createdAt' => $f(-5), 'createdBy' => $by ?: $who];
    };
    $T('Preparar minuta para la reunión de cierre de Quillagua', 'opp', 'do01', 'sN', 'Alta', 1);
    $T('Presentar oferta técnica a Andrés Pizarro', 'opp', 'do03', 'eN1', 'Alta', 0);
    $T('Llamar a Hernán Cortés por la oferta de guardias', 'opp', 'do05', 'eN1', 'Alta', -2, 'sN');
    $T('Enviar condiciones revisadas a Cordón Andino', 'opp', 'do06', 'eN2', 'Alta', 1);
    $T('Cerrar arquitectura de analítica con Marta Henríquez', 'opp', 'do08', 'eN2', 'Media', 3);
    $T('Coordinar levantamiento en Mejillones', 'opp', 'do09', 'eN3', 'Media', 4);
    $T('Llamar a Rocío Valenzuela por la oferta', 'opp', 'do10', 'eN3', 'Media', -1);
    $T('Armar presentación para Valle de Elqui', 'opp', 'do12', 'eN4', 'Baja', 5);
    $T('Reunión con Daniela Fuentes por la renovación', 'opp', 'do13', 'sS', 'Alta', 1);
    $T('Ajustar oferta de monitoreo con el gerente de O&M', 'opp', 'do16', 'eS1', 'Alta', 0);
    $T('Entregar oferta de Bahía Lenga', 'opp', 'do18', 'eS2', 'Alta', 3, 'sS');
    $T('Recorrer patio de maderas Nueva Aldea', 'opp', 'do20', 'eS3', 'Media', 7);
    $T('Enviar propuesta preliminar a Seno Austral', 'opp', 'do21', 'eS4', 'Media', 2);
    $T('Revisar bases de la licitación de subestaciones', 'lead', 'dl05', 'eS1', 'Alta', 2);
    $T('Buscar contacto en la planta de proceso Chinquihue', 'lead', 'dl08', 'eS4', 'Baja', 6);

    // ---- Facturas y actas de recepción de las oportunidades ganadas
    $I = function ($opp, $num, $dd, $net, $status) use (&$d, $f) {
        $code = ''; foreach ($d['opps'] as $x) if ($x['id'] === $opp) $code = $x['code'];
        $iva = (int) round($net * 0.19);
        $d['invoices'][] = ['id' => 'di' . sprintf('%02d', count($d['invoices']) + 1), 'oppId' => $opp, 'name' => "Factura $num · $code", 'number' => $num, 'date' => $f($dd), 'net' => $net,
            'iva' => $iva, 'total' => $net + $iva, 'status' => $status, 'comment' => '', 'createdAt' => $f($dd)];
    };
    $I('do04', '90011', -10, 3100000, 'Pagada');
    $I('do11', '90004', -30, 14500000, 'Pagada');
    $I('do11', '90015', -2, 14500000, 'Emitida');
    $I('do17', '90002', -40, 6200000, 'Pagada');
    $I('do17', '90013', -8, 6200000, 'Emitida');
    $I('do14', '', 0, 19000000, 'Por emitir');

    // ---- Auditoría de ejemplo (quién cambió qué)
    $au = function ($dd, $hora, $u, $ent, $campo, $antes, $despues) use (&$d, $f) {
        $d['audit'][] = ['id' => 'dau' . sprintf('%02d', count($d['audit']) + 1), 'at' => $f($dd) . ' ' . $hora, 'userId' => $u, 'entity' => $ent, 'field' => $campo, 'oldV' => $antes, 'newV' => $despues];
    };
    $cod = function ($id) use (&$d) { foreach ($d['opps'] as $x) if ($x['id'] === $id) return $x['code']; return $id; };
    $au(-6, '10:14', 'eN2', $cod('do06'), 'Etapa', 'Upside', 'Forecast');
    $au(-5, '16:40', 'eS1', $cod('do15'), 'Etapa', 'Upside', 'Forecast');
    $au(-2, '09:05', 'sN', $cod('do01'), 'Monto', '$3.600.000.000', '$3.452.000.000');

    $d['config'] = json_decode(file_get_contents(__DIR__ . '/../config/config-crm.default.json'), true);
    $d['config']['targets'] = DEMO_METAS;
    $d['seq'] = ['opp' => 0, 'offer' => 104, 'lead' => count($d['leads'])];
    $d['savedAt'] = $t0 * 1000;
    return json_decode(json_encode($d, JSON_UNESCAPED_UNICODE));
}
