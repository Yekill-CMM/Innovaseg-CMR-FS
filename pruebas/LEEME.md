# Pruebas

Corren sin navegador sobre `app/index.html` (con jsdom) y `app/api.php` (con PHP).

```
cd pruebas
npm install
npm test
```

- `importador.test.js`: importa varios funnels seguidos y verifica que nada se borre, que las oportunidades ausentes queden marcadas, que la marca se quite sola cuando vuelven y que las oportunidades creadas a mano no se toquen. Al final deja un archivo de datos de prueba en la carpeta temporal.
- `pantallas.test.js`: genera la vista previa de importación, el Kanban, la ficha y Hoy, y revisa los avisos y que no haya errores de JavaScript.
- `sincro.test.js`: al abrir, una copia vieja del navegador no pisa lo que hay en el servidor; solo se suben cambios pendientes hechos sobre la misma revisión.
- `contacto.test.js`: contacto efectivo (correo, teléfono o LinkedIn): activar un plan solo crea prospectos con contacto, el resto queda en «Buscar contacto» y pasa solo al encontrarlo; devolución de prospectos sin contacto a su plan.
- `bajas.test.js` y `bajas.test.php`: eliminar y fusionar empresas y contactos. Sin historial se elimina (queda en «Registros eliminados»); con historial solo se fusiona. La fusión de contactos mueve lo que tiene y completa datos sin reemplazar. El servidor aplica la misma regla, la deja en `cambios` con origen pantalla y la API de Joan sigue sin poder borrar ni fusionar.
- `oportunidades.test.js`: Kanban y tabla de oportunidades, alertas y plazos por tipo, Booking por año y la columna Facturado (pasa sola al 100 %, total cerrado contra total facturado, selector de año).
- `facturas.test.js`: facturas en oportunidades de Booking: cálculo de IVA y total, validaciones (número, neto, duplicados), estados que suman, totales en la ficha, Hoy, Kanban y Reportes, anulación, eliminación y que el importador no las toque.
- `recepcion.test.js`: acta de recepción de Instalaciones en Booking: datos que trae de la oportunidad, validaciones por estado, documento imprimible (con y sin montos), cierre de la tarea de traspaso y que el importador no la toque.
- `informes.test.js`: informes de facturación de Booking (totales, pendiente, filtros, exportación) y de prospectos convertidos (tasa, monto generado, tiempo de conversión, fuentes, exportación).
- `meta.test.js`: meta de facturación importada del Excel de presupuesto (filas inventadas con la misma forma): lectura por mes y línea, vista previa, meta del mes en Hoy, informe mes a mes y por línea, quitar la meta.
- `agente.test.php`: con ese archivo, revisa que el resumen para Joan informe las oportunidades que no vinieron en el funnel.

Los datos son inventados. No uses aquí exportaciones reales del funnel (el `.gitignore` ya excluye `.xlsx` y `.csv`).
