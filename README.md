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

## Pruebas

```
cd pruebas
npm install
npm test
```
