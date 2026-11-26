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

## Lo que la demo todavía no muestra

- **Exportar el funnel al formato del Excel de Gerencia** (Fase 1, punto 5).
- **Metas por persona sumadas a la subgerencia y a la gerencia, con selector de alcance** (punto 6). Hoy cada persona tiene su meta y cada rol ve las que le corresponden, pero no hay totales por subgerencia ni un selector para cambiar de vista.
- **Joan por usuario** (fase posterior).
