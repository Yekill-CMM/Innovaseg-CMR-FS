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
| Ejecutivo | Empresas y contactos (todos); lo suyo en prospectos, planes, oportunidades, ofertas, tareas, actividades, facturas y actas; su meta | Lo suyo. Empresas que creó, tiene a cargo o trabaja; contactos que creó o de esas empresas |
| Subgerente | Además, lo de su equipo y sus metas | Lo de su equipo (puede traspasar y asignar tareas dentro de él); todas las empresas y contactos |
| Gerente | Todo | Todo lo comercial; la configuración, solo lectura |
| Administrador | Todo | Todo, más cuentas, configuración, eliminar y fusionar empresas y contactos, restaurar respaldos y traspasar carteras |

Una actividad o tarea de otra persona sobre un registro que ves (por ejemplo, la gestión de tu
subgerente en tu oportunidad) también se ve, pero no se cambia. La meta del Excel de presupuesto
y el detalle del último funnel importado los ven solo el gerente y el administrador.

La pantalla sigue enviando su copia completa: el servidor la compara con lo que esa persona
veía, revisa cada alta, cambio y baja y la aplica sobre los datos completos sin tocar lo
que no ve. Lo no permitido no se aplica y vuelve como aviso. Cada fila de `cambios` guarda la
persona que hizo el cambio.

## Pruebas

```
cd pruebas
npm install
npm test
```
