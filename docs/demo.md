# Demo del CRM FS con los cuatro roles

Todo lo que aparece en la demo es inventado: personas, empresas, RUT, correos (dominio `.example`), teléfonos y montos. Los datos salen de `herramientas/demo-datos.php` y se cargan con `php herramientas/demo.php` en una instalación de prueba. Las fechas se calculan desde el día de carga, así que la demo siempre se ve al día.

## El equipo

| Cuenta | Persona | Rol | Ve |
|---|---|---|---|
| mibarra | Marcela Ibarra | Gerente | Todo |
| rvaldes | Rodrigo Valdés | Subgerente Norte | Su cartera y la de Paula, Tomás, Camila y Diego |
| pcifuentes, therrera, crivas, dmorales | Paula Cifuentes, Tomás Herrera, Camila Rivas, Diego Morales | Ejecutivos Norte | Solo lo suyo |
| vlagos | Verónica Lagos | Subgerente Sur | Su cartera y la de Javier, Fernanda, Matías e Isidora |
| jnunez, fsoto, mcontreras, ivega | Javier Núñez, Fernanda Soto, Matías Contreras, Isidora Vega | Ejecutivos Sur | Solo lo suyo |
| admin.sistema | Admin Sistema | Administrador | Todo, y además las cuentas |

Las empresas y los contactos los ven todos. Prospectos, oportunidades, ofertas, actividades, tareas y facturas tienen dueño.

La cartera: 15 empresas (energía solar y eólica, transmisión, minería, hidrógeno verde, desalación, puertos, forestal, acuicultura, logística y retail), 21 contactos, 10 prospectos, 21 oportunidades con código OP y tipo de negocio, 9 ofertas, 6 facturas y metas mensuales por persona.

## Qué mostrar con cada cuenta

**Ejecutiva (Paula Cifuentes).** En Hoy y en Oportunidades aparecen solo sus 3 oportunidades. En Empresas ve las 15. Abre *Minera Altos de Quillagua*: la creó su subgerente y tiene oportunidades de otros ejecutivos, pero Paula solo ve la suya. Puede agregar un contacto, un sitio o una oportunidad a su nombre; si intenta cambiar el nombre de la empresa, el servidor no lo guarda y avisa. *Fotovoltaica Pampa Clara* la creó ella, así que esa sí la edita. Eliminar, no puede.

**Subgerente (Rodrigo Valdés).** Ve las 12 oportunidades de la subgerencia Norte, incluidas las de Paula, y puede cambiarlas o reasignarlas dentro de su equipo. En *Subestaciones del Sur* hay una oportunidad del Norte y dos del Sur: Rodrigo ve solo la del Norte. Verónica Lagos, con la misma empresa, ve solo las del Sur.

**Gerente (Marcela Ibarra).** Ve las 21 oportunidades y las metas de todos. Puede traspasar oportunidades entre personas de cualquier equipo. Tampoco elimina empresas ni contactos.

**Administrador (admin.sistema).** Ve todo y entra a Configuración → Usuarios y equipos: crea cuentas, reinicia claves y el segundo factor, traspasa la cartera de una persona a otra. Es el único que elimina o fusiona empresas y contactos.

**Primer ingreso (Isidora Vega).** Su cuenta aún no se ha usado: al entrar con la clave temporal se le pide una clave propia y activar el segundo factor.

## Selector de alcance y metas por equipo

El gerente, el administrador y los subgerentes tienen un selector de alcance en Oportunidades (Kanban y tabla), Prospectos, Actividades y Reportes. El gerente elige entre toda la gerencia, una subgerencia o una persona; el subgerente, entre su subgerencia completa, su cartera propia o uno de sus ejecutivos. El ejecutivo no tiene selector: ve lo suyo. El selector solo achica lo que el rol ya ve; lo de otros equipos no llega al navegador.

En Reportes → Oportunidades, la tabla «Metas por persona y equipo» muestra la meta mensual de cada persona, lo logrado en el mes y en el año, y los totales: la subgerencia suma a su subgerente y sus 4 ejecutivos, y la gerencia suma las dos subgerencias. En la demo la meta de la subgerencia Norte es de $192 MM al mes, la del Sur de $178 MM y la de la gerencia de $370 MM.

Para mostrarlo: entra como Marcela Ibarra, abre Reportes, cambia el selector a «Subgerencia Sur» y después a «Paula Cifuentes». Luego entra como Rodrigo Valdés: el selector solo ofrece el Norte.

## Montos por tipo de negocio

Al crear o editar una oportunidad, los montos dependen del tipo de negocio, igual que en el funnel de Gerencia:

- **Seguridad Física:** solo el mensual.
- **Seguridad Electrónica:** cargo de habilitación más mensualidades, o One Shot con un solo monto.
- **Seguridad Integral:** el mensual físico y la parte electrónica (habilitación más mensual) por separado.

Se indica además el mes de la primera facturación. El booking («Proyección») es la habilitación más los mensuales desde ese mes, incluido, hasta diciembre; en One Shot, el monto del proyecto. El plazo del contrato queda como dato informativo. El booking cuenta en el año en que se cierra la oportunidad; para la meta cuenta lo facturado en el año. El formulario muestra el cálculo mientras se escribe.

## Exportar el funnel en el formato de Gerencia

En Oportunidades (y en Reportes) el botón «Exportar funnel (formato Gerencia)» descarga un Excel con las 26 columnas de la lista «Funnel de Ventas», en el orden de la exportación de SharePoint: Estado, Título (`OP-AAMMDD-NN-CLIENTE (NOMBRE)`), Fecha de Contacto, Tipo de negocio, Monto Seg. Física, Monto Seg. Electrónica, Cargo Habilitación, Probabilidad, Generador (columna K), Primera Facturación, Año, Proyección y el resto. Los montos van en CLP. Sale lo que la persona ve con el alcance elegido: Paula exporta sus 3 oportunidades, Rodrigo las 12 del Norte y Marcela las 21. Las postergadas y las que siguen en prospección no van, porque el funnel no tiene esos estados.

## Lo que la demo todavía no muestra

- **Joan por usuario** (fase posterior).
