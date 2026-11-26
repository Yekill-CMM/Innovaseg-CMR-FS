<?php
// Equipo y datos inventados para las pruebas de permisos: 1 gerente, 2 subgerencias (subgerente + 4 ejecutivos) y un administrador.
// Cada registro con dueño lleva la marca «marca-<id del dueño>», para revisar que no salga del servidor hacia quien no debe verlo.
const EQUIPO_PRUEBA = [
    ['g1', 'mibarra', 'Marcela Ibarra', 'gerente', '', false],
    ['sN', 'rvaldes', 'Rodrigo Valdés', 'subgerente', 'Norte', false],
    ['eN1', 'pcifuentes', 'Paula Cifuentes', 'ejecutivo', 'Norte', false],
    ['eN2', 'therrera', 'Tomás Herrera', 'ejecutivo', 'Norte', false],
    ['eN3', 'crivas', 'Camila Rivas', 'ejecutivo', 'Norte', false],
    ['eN4', 'dmorales', 'Diego Morales', 'ejecutivo', 'Norte', false],
    ['sS', 'vlagos', 'Verónica Lagos', 'subgerente', 'Sur', false],
    ['eS1', 'jnunez', 'Javier Núñez', 'ejecutivo', 'Sur', false],
    ['eS2', 'fsoto', 'Fernanda Soto', 'ejecutivo', 'Sur', false],
    ['eS3', 'mcontreras', 'Matías Contreras', 'ejecutivo', 'Sur', false],
    ['eS4', 'ivega', 'Isidora Vega', 'ejecutivo', 'Sur', false],
    ['ad', 'admin.sistema', 'Admin Sistema', 'admin', '', true],
];
const ALCANCE_PRUEBA = [   // quién ve lo de quién
    'g1' => '*', 'ad' => '*',
    'sN' => ['sN', 'eN1', 'eN2', 'eN3', 'eN4'], 'sS' => ['sS', 'eS1', 'eS2', 'eS3', 'eS4'],
];
function alcancePrueba($id) {
    $todos = array_column(EQUIPO_PRUEBA, 0);
    $a = ALCANCE_PRUEBA[$id] ?? [$id];
    return $a === '*' ? $todos : $a;
}
function directorioPrueba() {
    return array_map(function ($u) { return (object) ['id' => $u[0], 'usuario' => $u[1], 'name' => $u[2], 'role' => $u[3], 'equipo' => $u[4], 'admin' => $u[5] || $u[3] === 'admin', 'activo' => true, 'roleLabel' => $u[3]]; }, EQUIPO_PRUEBA);
}
function datosPrueba() {
    $o = function ($a) { return json_decode(json_encode($a)); };
    $d = ['v' => 5, 'mode' => 'real', 'companies' => [], 'contacts' => [], 'leads' => [], 'opps' => [], 'offers' => [], 'activities' => [], 'tasks' => [],
        'invoices' => [], 'recepciones' => [], 'plans' => [], 'audit' => [], 'fusiones' => [],
        'config' => ['uf' => 39000, 'targets' => [], 'metas' => ['2027' => ['venta' => ['SSFF' => [1, 2, 3]]]]], 'seq' => ['opp' => 10, 'lead' => 20, 'offer' => 5],
        'fxLast' => ['at' => '2026-11-20', 'rows' => [['titulo' => 'fila del funnel de todos']]], 'savedAt' => 1, 'lastBackup' => ''];
    $d['companies'][] = ['id' => 'C1', 'legalName' => 'Eólica Norte SpA', 'tradeName' => 'Eólica Norte', 'ownerId' => 'eN1', 'createdBy' => 'eN1', 'sites' => []];
    $d['companies'][] = ['id' => 'C2', 'legalName' => 'Puerto Austral SA', 'tradeName' => 'Puerto Austral', 'ownerId' => 'eS1', 'sites' => []];
    $d['companies'][] = ['id' => 'C3', 'legalName' => 'Minera Cordillera', 'tradeName' => 'Minera Cordillera', 'ownerId' => '', 'sites' => []];
    $d['contacts'][] = ['id' => 'K1', 'firstName' => 'Ana', 'lastName' => 'Pérez', 'companyId' => 'C1', 'createdBy' => 'eN1'];
    $d['contacts'][] = ['id' => 'K2', 'firstName' => 'Luis', 'lastName' => 'Gómez', 'companyId' => 'C2', 'createdBy' => 'eS1'];
    foreach (EQUIPO_PRUEBA as $i => $u) {
        $id = $u[0]; $m = "marca-$id"; $emp = $id === 'eN2' ? 'C3' : ($u[4] === 'Sur' ? 'C2' : 'C1');
        $d['leads'][] = ['id' => "L_$id", 'code' => "PR-2026-0$i", 'name' => "Prospecto $m", 'companyId' => $emp, 'ownerId' => $id, 'stage' => 'Contactado'];
        $d['opps'][] = ['id' => "O_$id", 'code' => "CM-2611" . sprintf('%02d', $i) . '-01', 'name' => "Oportunidad $m", 'companyId' => $emp, 'ownerId' => $id, 'status' => 'abierta', 'stage' => 'oferta', 'prob' => 30];
        $d['offers'][] = ['id' => "F_$id", 'code' => "OF-2026-$i", 'oppId' => "O_$id", 'ownerId' => $id, 'status' => 'Enviada', 'note' => $m];
        $d['activities'][] = ['id' => "A_$id", 'userId' => $id, 'entityType' => 'opp', 'entityId' => "O_$id", 'companyId' => $emp, 'type' => 'Llamada', 'desc' => "Llamada $m", 'date' => '2026-11-20'];
        $d['tasks'][] = ['id' => "T_$id", 'title' => "Tarea $m", 'entityType' => 'opp', 'entityId' => "O_$id", 'assigneeId' => $id, 'status' => 'Pendiente', 'due' => '2026-12-01'];
        $d['invoices'][] = ['id' => "I_$id", 'oppId' => "O_$id", 'name' => "Factura $m", 'net' => 1000];
        $d['recepciones'][] = ['id' => "R_$id", 'oppId' => "O_$id", 'name' => "Acta $m"];
        $d['plans'][] = ['id' => "P_$id", 'name' => "Plan $m", 'ownerId' => $id, 'targets' => [['companyId' => $emp, 'status' => 'Pendiente']]];
        $d['audit'][] = ['id' => "AU_$id", 'userId' => $id, 'entity' => "Oportunidad $m", 'field' => 'Prob', 'oldV' => '20', 'newV' => '30'];
        $d['config']['targets'][$id] = 1000000 * ($i + 1);
    }
    // La subgerente del norte registra una gestión y le asigna una tarea a Paula sobre la oportunidad de Paula
    $d['activities'][] = ['id' => 'A_sN_en_eN1', 'userId' => 'sN', 'entityType' => 'opp', 'entityId' => 'O_eN1', 'companyId' => 'C1', 'type' => 'Reunión', 'desc' => 'Revisión con vinculo-eN1', 'date' => '2026-11-21'];
    return $o($d);
}
