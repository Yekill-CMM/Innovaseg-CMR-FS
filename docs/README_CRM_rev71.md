# CRM Comercial

CRM personal para gestión comercial B2B: prospección por vertical (prospectos PR), oportunidades que son las ofertas (código CM), actividades, tareas y alertas de seguimiento.

## Estado

Revisión actual: **71** (25-11-2026). El número es la cantidad de commits en `main` (`git rev-list --count main`) y se ve junto al nombre, arriba a la izquierda, y en Configuración. Se actualiza en `CRM_REV`, al inicio del script de `app/index.html`, en cada cambio que se publique.


Fase 1 + planes de prospección, ahora con guardado en el servidor. La página HTML se sincroniza con `api.php` (PHP 7.2+, cPanel), que guarda los datos en `app/datos/` protegidos con una clave. PC y celular comparten los mismos datos.

## Estructura

```
app/index.html              CRM completo (HTML + JS, sin dependencias)
app/api.php                 Guardado en el servidor, control de versiones e historial por hora
app/config.php              Clave de acceso (cambiarla en el hosting, no en el repositorio)
app/.htaccess               Configuración para hosting cPanel (noindex, HTTPS, bloqueo de archivos internos)
app/datos/.htaccess         Bloquea el acceso directo a los datos guardados
app/datos/ajustes.json.php   (se crea en el servidor) Parámetros de configuración, aparte del programa y de los datos
supabase/migrations/        Base de datos para la versión con servidor (PostgreSQL / Supabase)
  001_fase1_crm_core.sql    Núcleo: empresas, contactos, leads, oportunidades, ofertas, alertas, auditoría, RLS
  002_planes_prospeccion.sql Planes de prospección por vertical y cadencias
docs/instalacion-cpanel.txt Cómo publicarlo en cPanel
```

## Uso local

Abrir `app/index.html` en el navegador. En Configuración:

- **Empezar con mis datos** borra los datos de ejemplo.
- **Descargar respaldo / Restaurar respaldo** guarda y recupera los datos en un archivo JSON.
- **Importar funnel** (en Oportunidades o Configuración) lee el Excel o CSV exportado de la lista *Funnel de Ventas* de SharePoint. Reconoce cada oportunidad por la columna ID (o por el título si no viene), crea las nuevas y actualiza solo lo que cambió en el funnel: estado, probabilidad, montos y nombre. No toca contactos, actividades ni próximas acciones, y muestra un resumen antes de aplicar. Solo importa las filas de Claudio Méndez según la columna «Generador» (habitualmente la K; si no la encuentra por nombre, usa la columna K); las de otros ejecutivos se omiten. Si el archivo no trae esa columna, no importa nada y lo avisa. Acepta «Claudio Méndez» y «C. Méndez». El importador nunca borra: si una oportunidad importada antes no viene en el archivo nuevo, queda marcada «No vino en el funnel» con la fecha en que dejó de venir, conserva su historial, tareas y ofertas, sigue sumando al pipeline y genera una alerta para confirmar con Gerencia si sigue vigente (cerrarla como perdida o postergada es decisión tuya). Si vuelve a venir en una importación posterior, la marca se quita sola. Las oportunidades creadas a mano no se tocan.

Prospección y oportunidades van separadas, cada una con su código y su Kanban. El cliente (empresa) es lo que las une.

**Prospectos** (código `PR-AAAA-NNNN`, asignado al crear). Un cliente puede tener varios prospectos abiertos a la vez; el *nombre del prospecto* los distingue. El Kanban tiene 8 etapas activas (Identificado, Investigación, Contacto inicial, Contactado, Reunión solicitada, Reunión realizada, Calificación, Levantamiento) y 3 salidas aparte: soltar en *Oportunidad creada* abre la conversión, en *No calificado* pide el motivo y en *Nurturing* pide la fecha de recontacto. Una reunión agendada con el prospecto lo pasa a «Reunión solicitada» y una reunión o visita realizada, a «Reunión realizada» (solo avanza, nunca retrocede).

**Oportunidades** = ofertas con código `CM-AAMMDD-NN`. **Fuente única: el Excel semanal de Gerencia** (Importar funnel), hasta que exista un conector para escribir en esa base. El CRM no crea oportunidades ni cambia su código, nombre, etapa, probabilidad, montos, fechas, responsable o estado: eso se ve en la ficha como «Datos del Excel de Gerencia», en solo lectura, y el Kanban no permite arrastrar. Lo que sí se registra en el CRM es la gestión: actividades, tareas, reuniones, próxima acción, estrategia, riesgos, competencia, contactos, sitio y servicios. Etapas: Pipeline (oferta en desarrollo), Upside (oferta entregada), Forecast (adjudicada verbalmente) y Booking (cerrada con contrato). Un Excel antiguo con «En Desarrollo» se importa en Pipeline, y las oportunidades que estaban en esa etapa pasan a Pipeline al abrir esta versión.

**Prospecto → oferta.** Al soltar un prospecto en «Oportunidad creada» (o con el botón de la ficha) pasa a oferta. El CRM no crea la oportunidad ni su código: el código CM viene en el título del Excel. Si la oferta ya está en el Excel, se elige ahí mismo; si no, el prospecto queda «esperando el Excel» y se vincula después desde su ficha («Vincular con la oportunidad del Excel»). La oportunidad vinculada muestra su prospecto de origen y el historial de prospección. Las oportunidades creadas a mano antes de esta regla no suman al pipeline ni salen en el Kanban: aparecen en un aviso «no viene en el Excel» y se vinculan solas si el Excel las trae con su código.

**Excel semanal (validado con la Semana 46).** Columnas que se leen: Estado, Título (de ahí sale el código `CM-AAMMDD-NN`), Fecha de Contacto, Tipo de negocio, Monto Seg. Física, Monto Seg. Electrónica, Cargo Habilitación, Cuotas Carg. Habilitación, Mg %, Probabilidad, Generador, Usuario Final, Tomador de Decisión, Notas, Costo del Proyecto, Año, Fecha Presentación, Fecha de Adjudicación, Fecha Ultima Conversación, Primera Facturación, Proyección, Código Oportunidad y Motivo No Adj. El archivo no trae ID de SharePoint: cada oportunidad se reconoce por su código CM; las filas sin CM en el título (antiguas) se reconocen por el título completo. **El valor de la oportunidad es la «Proyección» del Excel** (no un cálculo del CRM); los montos de Seg. Física / Electrónica (mensuales en los recurrentes), el cargo de habilitación y sus cuotas se muestran en la ficha. Tipos de negocio: Seguridad Física, Seguridad Electrónica, Seguridad Integral y One Shot.

**Plazos por tipo de negocio** (Configuración). Cada tipo puede tener sus propios días de alerta y riesgo sin gestión, de seguimiento en Upside (recordatorio, amarilla, roja) y de estancamiento; lo que se deja vacío toma el plazo general. Alertas y automatizaciones usan el plazo del tipo de cada oportunidad.

**Cliente sin gestión.** Si una empresa con oportunidades abiertas o prospectos activos no tiene ninguna gestión real en N días (Configuración, por defecto 15), aparece en Hoy en «Clientes sin gestión» (roja al doble del plazo).

No hay módulo de ofertas aparte: la oportunidad es la oferta. El seguimiento corre sobre las oportunidades en Upside (oferta entregada), desde la fecha de presentación del funnel o el ingreso a Upside, con los plazos de Configuración (recordatorio, alerta amarilla y roja); aparece en Hoy, en la tarjeta y en la ficha. Las ofertas con código OF del módulo anterior no se borraron: se ven como historial de solo consulta en la ficha de su oportunidad y de su empresa.

**Gestión de oportunidades = tareas.** En una oportunidad solo se mira el estatus (Excel) y se gestionan tareas: su próxima acción es su próxima tarea pendiente, y completar una tarea cuenta como gestión. Registrar una actividad con próxima acción sobre una oportunidad crea la tarea.

**Automatizaciones contra el tiempo sin gestión** (Configuración, se pueden apagar una a una). El CRM crea tareas solo, marcadas «Automática» y registradas en la auditoría, sin duplicar mientras la anterior siga abierta: (1) N días sin gestión (umbral «sin actividad: alerta», por defecto 10) → «Contactar al cliente», Alta, para hoy; (2) oferta entregada (Upside) sin seguimiento en el plazo de recordatorio (3 días) → «Seguimiento de la oferta entregada»; (3) sin tarea pendiente → «Definir la próxima gestión» para el día hábil siguiente; (4) el Excel la pasa a Forecast → «Confirmar adjudicación y pedir contrato u OC», a Booking → «Traspaso a operaciones» (una vez). Corren al abrir el CRM, al importar el Excel y después de cada cambio. Los «días sin gestión» se cuentan desde la última gestión real (actividad, tarea completada o «última conversación» del Excel): las notas que deja el importador no cuentan. Cada tarjeta muestra sus días sin gestión y Hoy muestra cuántas llevan 10 días o más y cuál es la más antigua.

**Facturas (medición 2027).** Desde 2027 lo que se mide es lo facturado. Las oportunidades en Booking tienen el botón **Registrar factura**: N° de factura, fecha de emisión, monto neto, IVA (se calcula al 19 % y se puede cambiar, por ejemplo a 0 si es exenta), total (neto + IVA), estado y comentario. Estados: *Por emitir* (puede ir sin número), *Emitida*, *Pagada* y *Anulada*. Suman como facturado solo las Emitidas y Pagadas, por su monto neto; «por cobrar» es el total con IVA de las Emitidas. No se repite un N° de factura (salvo el de una anulada). Las facturas son una colección propia (`invoices`), aparte de los datos del Excel: el importador no las toca. La ficha muestra la lista y los totales (facturado, % de la proyección, por cobrar, por emitir); la tarjeta de Booking, lo facturado y su %; las oportunidades en Booking 100 % facturadas (neto contra la proyección) se ven con fondo verde suave y borde izquierdo verde, y las sin facturar o a medias con fondo rojo suave y borde rojo, en el Kanban y en la lista; Hoy, «Facturado en el año» y del mes; Reportes, «Facturado del año». Cada alta, cambio o eliminación queda en la auditoría y en el historial; una factura eliminada se recupera desde «Ver registros eliminados».

**Kanban: Booking y Facturado por año.** El Kanban de oportunidades tiene un selector de año (por defecto el actual) y, después de Booking, la columna **Facturado**, que se llena sola según las facturas (no se arrastra: el estado Booking viene del Excel de Gerencia). Booking muestra solo las cerradas en el año elegido que aún no están facturadas al 100 % (las de años anteriores con saldo se ven en el Booking de su año, y en el año actual se avisa cuántas son con un enlace), con una barra de avance de facturación en cada tarjeta; su encabezado trae el **total cerrado del año** (por fecha de adjudicación, incluye las que ya pasaron a Facturado), el total del mes y lo pendiente por facturar. Una oportunidad pasa a Facturado cuando llega al 100 % facturado (solo facturas emitidas o pagadas) y queda en el año de su última factura. El encabezado de Facturado es el **total facturado del año** por fecha de factura, e incluye las facturas parciales de negocios que siguen en Booking.

**Acta de recepción de Instalaciones.** Cada oportunidad en Booking lleva su acta (código `AR-CM-…`), con la que Comercial entrega el proyecto adjudicado e Instalaciones lo recibe. Se prepara desde la ficha con los datos de la oportunidad ya puestos (sitio, contacto en terreno, alcance con nombre, servicios y notas del funnel, OC si existe) y se completa con jefe de proyecto, inicio y término, condiciones especiales, documentos entregados (lista de 9) y observaciones. Estados: *Borrador*, *Entregada a Instalaciones* (pide fecha de entrega), *Recibida* (pide quién recibe y fecha) y *Recibida con observaciones* (además, las observaciones). «Ver e imprimir acta» abre el documento en otra pestaña para imprimirlo o guardarlo en PDF y firmarlo; los montos y el costo del proyecto se pueden quitar. Al quedar recibida se completa sola la tarea «Traspaso a operaciones». La tarjeta de Booking muestra el estado del acta («Acta pendiente» en rojo). Colección propia (`recepciones`), que el importador no toca.

**La meta se mide por factura.** Lo que cuenta para la meta de un período es lo facturado en ese período (neto de facturas emitidas y pagadas, por fecha de emisión), sin importar cuándo se cerró la oportunidad: un Booking de un año anterior facturado este año suma a la meta de este año. Hoy muestra «Facturado en el mes» contra la meta mensual, «Facturado en el año» contra la meta anual (la importada, o la mensual × 12; separando lo que viene de Booking de años anteriores) y el «Booking del mes» aparte. En el Kanban, Booking y Facturado se ven por año (ver más abajo). En Configuración se puede volver a medir por Booking (TCV adjudicado).

**Meta de facturación mes a mes.** En Configuración, «Importar meta desde Excel de presupuesto» lee la hoja de meta del archivo de pre-work (sección «MI META DE VENTA AAAA» y «MI META DE MARGEN AAAA», montos en MM CLP): meta de cada mes por línea de negocio (SSFF, SSII, SSEE One Shot, SSEE Servicio), con vista previa antes de usarla. Hoy compara lo facturado del mes con la meta de ese mes y lo del año con la meta anual y con lo esperado a la fecha; el informe de facturación muestra la meta mes a mes con acumulados (Q1 a Q4) y por línea de negocio (según el tipo de negocio del funnel) con la meta de margen. Los años sin meta importada usan la meta mensual plana.

**Informes de facturación y conversión** (Reportes). *Facturación de Booking*: oportunidades cerradas con su proyección, lo facturado (neto de emitidas y pagadas), lo pendiente (proyección menos facturado), %, última factura, lo por cobrar con IVA y el estado del acta de recepción; ordenado por lo pendiente, filtrable por año de facturación (por defecto el año en curso: lo cerrado ese año y lo cerrado antes que tiene factura ese año; los Booking de años anteriores con saldo y sin factura en el año se ven en el informe de su año, con un aviso y enlace) y por estado (pendientes, sin ninguna factura, al 100 %), con totales y exportación. *Prospectos convertidos*: prospectos que pasaron a oportunidad, % sobre los prospectos creados, cuántos ya están en el Excel y cuántos esperan su código CM, monto generado, ganados y perdidos, días promedio de conversión, resumen por fuente y el estado actual de cada oportunidad; filtrable por año de conversión y exportable.

**Actividades** y **Tareas** se filtran por cliente y cada elemento lleva su etiqueta PR o CM; Tareas además filtra solo prospección o solo oportunidades.

Si quedan oportunidades abiertas en las etapas antiguas de prospección (Identificada, Calificada, Levantamiento), aparecen en un aviso «fuera del funnel» para revisarlas; no se muestran en el Kanban ni suman a los montos.

## Base de datos (SQLite)

`app/almacen.php` guarda el CRM en una base SQLite (`app/datos/crm.db`) en vez de un único archivo JSON. Cada prospecto, oportunidad, tarea, actividad, empresa o contacto es un registro propio y cada guardado es una transacción (se escribe todo o nada). Nada se borra físicamente: lo que se quita queda marcado como eliminado y se recupera desde Configuración → Datos y respaldos → Ver registros eliminados. La tabla `cambios` guarda el historial completo de cada registro (origen pantalla, Joan o migración; fecha; valor anterior y nuevo), visible con el botón «Historial de cambios» en las fichas de oportunidad y prospecto. Respaldos de la base en `datos/historial`: uno por hora (últimos 72) y uno por día (últimos 30), además de los JSON por hora de siempre.

Red de seguridad: después de cada guardado se sigue escribiendo `datos/crm.json.php` con el contenido al día. Si el PHP del servidor no tiene SQLite, o se define `define('CRM_ALMACEN', 'json');` en `config.php`, el CRM funciona con ese JSON exactamente como antes. La primera vez, los datos del JSON se cargan en la base y se verifica que la base devuelva exactamente lo mismo; si no cuadra, la base se descarta y se sigue con el JSON (detalle en `datos/almacen.log`). Requiere el paquete `php-sqlite3` (el instalador lo agrega). La pantalla revisa cada minuto si hubo cambios en otro dispositivo o de Joan.

## Pruebas

`pruebas/` tiene pruebas del importador del funnel que corren sin navegador (Node + jsdom, y PHP para el resumen de Joan). Ver `pruebas/LEEME.md`.

## Reuniones en Outlook

**Agendar reunión** (en Hoy, Actividades y en las fichas de oportunidad, prospecto, empresa y contacto) registra la reunión como actividad futura con hora, duración, lugar e invitados, la deja como próxima acción si corresponde y abre Outlook Web (`outlook.office.com`, cuenta de First Security) con la invitación llena. La invitación sale solo cuando se presiona Enviar en Outlook: el CRM no tiene acceso al calendario. Las reuniones agendadas muestran un botón **Outlook** para volver a abrir la invitación.

## Agente automático

`api.php?accion=agente` entrega un resumen compacto del CRM (oportunidades abiertas con sus alertas y últimas notas, ofertas vigentes y tareas pendientes) para un agente que prepare el resumen diario y proponga próximas acciones. Usa su propia clave, `CRM_CLAVE_AGENTE` en `config.php`. Con ella el agente puede leer y usar `api.php?accion=agente-escribir`: registrar actividades, próximas acciones y tareas en oportunidades y prospectos, crear empresas, contactos y prospectos (con código PR; si el cliente ya tiene prospectos abiertos, debe indicar el nombre del nuevo), y agregar empresas como objetivo «Pendiente» a los Planes de prospección (`plan_objetivo`; si no existe el plan de esa vertical y mes lo crea en Borrador y el guion se completa al abrir el CRM). Busca los prospectos por su código PR (o su id). No crea oportunidades ni cambia su nombre, etapa, probabilidad, montos o fecha de cierre, porque las oportunidades vienen del funnel oficial (Importar funnel); tampoco edita empresas o contactos existentes ni reemplaza los datos completos o los ajustes. Lo que escribe queda en la auditoría marcado «(Joan)». Los contactos del resumen incluyen el correo entre `< >`, para que Joan arme invitaciones de Outlook.

## Instalación en servidor propio (joansvr, privada)

`local/instalar-servidor.sh` (Ubuntu) deja el CRM en `~/crm` como servicio systemd en `127.0.0.1:8787` y lo publica solo dentro de la red Tailscale con `tailscale serve` (HTTPS). `local/conectar-joan.sh` (Mac) registra en la app de Claude el conector MCP `crm-comercial` (`local/crm_mcp.py`, Python estándar) apuntando a esa dirección, para que Joan lea y escriba en el CRM. Pasos completos en `local/LEEME.txt`.

Joan (el asistente por Telegram que usa esta API) vive en su propio repo, `Yekill-CMM/joansrv`, con sus instrucciones, conectores e instalador. `local/actualizar.sh` actualiza solo el CRM, sin tocar datos ni claves.

`local/abrir-internet.sh` abre el CRM a internet con Tailscale Funnel (cambia las claves y prueba desde fuera) y `local/cerrar-internet.sh` lo vuelve a dejar solo en la red Tailscale. `api.php` distingue el origen de cada petición (servidor, red Tailscale por la cabecera de identidad que agrega Serve, o internet): la clave del agente solo se acepta en los dos primeros, y hay bloqueo por intentos fallidos por IP y global.
