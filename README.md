# CRM FS

Versión multiusuario del CRM Comercial para First Security (producto de Innovaseg).

Nace de una copia del CRM Comercial, revisión 71 (commit 8551601 del repo CRM). La
documentación de esa base está en `docs/README_CRM_rev71.md`. De `local/` solo se trajo
`router.php`, que usan las pruebas; los scripts del servidor personal quedaron fuera.

## Configuración por defecto

`config/config-crm.default.json` tiene los valores con que parte una instalación nueva:
UF y USD de referencia, plazos por tipo de negocio, umbrales de seguimiento de ofertas,
inactividad de clientes, prospectos y oportunidades, aviso de vencimiento de ofertas y
la base de cálculo de metas (`metaBase`). No lleva datos reales ni claves.

El programa los tiene en `CONFIG_DEFAULT` (`app/index.html`). Si cambias uno, cambia el
otro: `pruebas/config.test.js` revisa que sean iguales. Lo que el administrador ajuste
después en Configuración se guarda aparte y manda sobre estos valores.

## Cuentas y permisos

Cada persona entra con su usuario, su clave y un código de 6 dígitos de una aplicación de
autenticación (segundo factor obligatorio). No hay clave compartida. La primera cuenta se
crea en el servidor con `php herramientas/cuentas.php crear-admin <usuario> "<Nombre>"`; las
demás, desde Configuración → Usuarios y equipos. Instalación: `docs/instalacion.txt`.

Los permisos los aplica el servidor (`app/permisos.php`); lo que una persona no puede ver
nunca llega a su navegador:

| Rol | Ve | Cambia |
|---|---|---|
| Ejecutivo | Empresas y contactos (todos); lo suyo en prospectos, planes, oportunidades, ofertas, tareas, actividades, facturas y actas; su meta | Lo suyo. Crea empresas y contactos y edita los que creó; en una empresa de otra persona solo agrega contactos, sitios, prospectos y oportunidades |
| Subgerente | Además, lo de su equipo y sus metas | Lo de su equipo (puede traspasar y asignar tareas dentro de él); edita todas las empresas y contactos |
| Gerente | Todo | Todo lo comercial; la configuración, solo lectura |
| Administrador | Todo | Todo, más cuentas, configuración, eliminar y fusionar empresas y contactos, restaurar respaldos y traspasar carteras |

En empresas y contactos queda la trazabilidad: el servidor anota quién creó cada uno
(`createdBy`) y quién lo cambió por última vez (`updatedBy`, `updatedAt`), y la tabla `cambios`
guarda cada cambio con la persona, el valor anterior y el nuevo. Eliminarlos o fusionarlos es solo
del administrador. Metas: cada ejecutivo ve la suya, el subgerente las de su equipo y el gerente
las de todos.

Una actividad o tarea de otra persona sobre un registro que ves (por ejemplo, la gestión de tu
subgerente en tu oportunidad) también se ve, pero no se cambia. La meta del Excel de presupuesto
y el detalle del último funnel importado los ven solo el gerente y el administrador.

Lo no permitido no se aplica y vuelve como aviso. Cada fila de `cambios` guarda la persona que
hizo el cambio, y el historial de cada registro lo muestra en la pantalla.

## Guardado registro por registro

Varias personas pueden trabajar a la vez sin pisarse (`app/guardado.php`). La pantalla manda solo
los registros que cambió, cada uno con la versión que tenía. El servidor aplica lo que nadie más
tocó; si otra persona cambió otros campos del mismo registro, junta los dos cambios; si cambió los
mismos campos (o lo eliminó), queda la versión del servidor y la pantalla avisa quién y cuándo lo
cambió. Todo pasa después por los mismos permisos. La copia completa queda solo para iniciar el
CRM, restaurar un respaldo o subir cambios hechos sin conexión.

## Oportunidades

Se crean y gestionan en el CRM: con «Nueva oportunidad» o al convertir un prospecto. El código es
automático, `OP-AAMMDD-NN` (fecha de creación y correlativo del día), y lo asigna el servidor, que
ve todas las oportunidades: el que muestra la pantalla antes de guardar es provisional. El código
no cambia después de creado. Quien la crea elige el tipo de negocio, que define los plazos de
seguimiento y la línea de la meta de presupuesto.

No entra nada por Excel: la pantalla ya no tiene «Importar funnel» y el servidor le pone código OP
a toda oportunidad nueva. El funnel solo se exporta, en el formato del Excel de Gerencia (punto 5
del plan). La meta del Excel de presupuesto se sigue cargando igual: es otra planilla y no trae
oportunidades.

## Pruebas

```
cd pruebas
npm install
npm test
```
