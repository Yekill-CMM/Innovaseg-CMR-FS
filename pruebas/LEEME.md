# Pruebas

Corren sin navegador sobre `app/index.html` (con jsdom) y `app/api.php` (con PHP).

```
cd pruebas
npm install
npm test
```

- `config.test.js`: la configuración por defecto de `app/index.html` es igual a `config/config-crm.default.json`, una instalación nueva y la demo parten con esos valores y lo que se cambia en Configuración manda sobre ellos.
- `importador.test.js`: importa varios funnels seguidos y verifica que nada se borre, que las oportunidades ausentes queden marcadas, que la marca se quite sola cuando vuelven y que las oportunidades creadas a mano no se toquen. Al final deja un archivo de datos de prueba en la carpeta temporal.
- `pantallas.test.js`: genera la vista previa de importación, el Kanban, la ficha y Hoy, y revisa los avisos y que no haya errores de JavaScript.
- `sincro.test.js`: al abrir, una copia vieja del navegador no pisa lo que hay en el servidor; solo se suben cambios pendientes hechos sobre la misma revisión.
- `contacto.test.js`: contacto efectivo (correo, teléfono o LinkedIn): activar un plan solo crea prospectos con contacto, el resto queda en «Buscar contacto» y pasa solo al encontrarlo; devolución de prospectos sin contacto a su plan.
- `bajas.test.js` y `bajas.test.php` (con sesión de administrador): eliminar y fusionar empresas y contactos. Sin historial se elimina (queda en «Registros eliminados»); con historial solo se fusiona. La fusión de contactos mueve lo que tiene y completa datos sin reemplazar. El servidor aplica la misma regla, la deja en `cambios` con origen pantalla y la API de Joan sigue sin poder borrar ni fusionar.
- `oportunidades.test.js`: oportunidades creadas en el CRM con código OP-AAMMDD-NN (nueva, desde un prospecto y edición, con auditoría), Kanban y tabla de oportunidades, alertas y plazos por tipo, Booking por año y la columna Facturado (pasa sola al 100 %, total cerrado contra total facturado, selector de año).
- `facturas.test.js`: facturas en oportunidades de Booking: cálculo de IVA y total, validaciones (número, neto, duplicados), estados que suman, totales en la ficha, Hoy, Kanban y Reportes, anulación, eliminación y que el importador no las toque.
- `recepcion.test.js`: acta de recepción de Instalaciones en Booking: datos que trae de la oportunidad, validaciones por estado, documento imprimible (con y sin montos), cierre de la tarea de traspaso y que el importador no la toque.
- `informes.test.js`: informes de facturación de Booking (totales, pendiente, filtros, exportación) y de prospectos convertidos (tasa, monto generado, tiempo de conversión, fuentes, exportación).
- `meta.test.js`: meta de facturación importada del Excel de presupuesto (filas inventadas con la misma forma): lectura por mes y línea, vista previa, meta del mes en Hoy, informe mes a mes y por línea, quitar la meta.
- `permisos.test.php`: reglas de `app/permisos.php` con un equipo inventado (gerente, dos subgerencias de subgerente y 4 ejecutivos, y administrador). Cada registro lleva la marca de su dueño y se revisa que a cada una de las 12 cuentas le llegue exactamente su alcance; además, qué puede crear, cambiar, traspasar y eliminar cada rol, empresas y contactos compartidos, auditoría, contadores, historial y recuperación.
- `cuentas.test.php`: con un servidor PHP real: sin sesión no hay datos, ingreso con clave temporal → clave propia → segundo factor, código reusado, bloqueo por intentos, cookie HttpOnly y SameSite=Strict, cerrar sesión, administración de cuentas, lo que recibe cada rol (también en 409 y 422), avisos de lo no permitido, historial, eliminados, traspaso de cartera y agente con la cuenta de una persona.
- `sesion.test.js`: la pantalla con cuentas (servidor simulado): ingreso en pasos, la persona la fija el servidor, una copia local de otra persona no se sube, avisos, roles en la pantalla y cerrar sesión.
- `guardado.test.php`: guardado registro por registro con un servidor PHP real y el equipo inventado: dos personas guardan a la vez sin 409, cambios en campos distintos del mismo registro se juntan, el mismo campo (o eliminar lo que otro cambió) es conflicto con quién lo hizo, altas en orden y sin duplicar, los mismos permisos que con la copia completa (sin datos ajenos en ninguna respuesta), la persona de cada cambio en «cambios», códigos OP que asigna el servidor (dos personas a la vez, corrección en auditoría y tareas, código fijo, carga del funnel vigente con CM) y PR repetidos.
- `guardado.test.js`: la pantalla con el guardado por registro (servidor simulado): manda solo lo cambiado, toma el código definitivo, junta lo que otra persona guardó sin perder lo que se está escribiendo y avisa los conflictos.
- `agente.test.php`: con ese archivo, revisa que el resumen para Joan informe las oportunidades que no vinieron en el funnel.

Los datos son inventados. No uses aquí exportaciones reales del funnel (el `.gitignore` ya excluye `.xlsx` y `.csv`).
