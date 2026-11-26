<?php
/* =========================================================================
   CRM FS - configuración del servidor

   Cuentas: cada persona entra con su usuario, su clave y un código de su aplicación de
   autenticación. La primera cuenta (administrador) se crea en el servidor con:
       php herramientas/cuentas.php crear-admin <usuario> "<Nombre Apellido>"
   Las demás las crea el administrador desde Configuración → Usuarios y equipos.
   ========================================================================= */

/* Duración de la sesión (opcional). Por defecto: 12 horas desde que se entra y se cierra
   sola tras 4 horas sin uso. */
// define('CRM_SESION_HORAS', 12);
// define('CRM_SESION_INACTIVA_HORAS', 4);

/* Nombre que aparece en la aplicación de autenticación (opcional). */
// define('CRM_NOMBRE', 'CRM FS');

/* Clave del agente (opcional). Permite que un programa lea el resumen
   (api.php?accion=agente) y haga cambios acotados (agente-escribir) con los permisos de
   la cuenta indicada en CRM_AGENTE_USUARIO. Al menos 16 caracteres. Déjala vacía para
   desactivarla. */
define('CRM_CLAVE_AGENTE', '');
// define('CRM_AGENTE_USUARIO', 'usuario-de-la-cuenta');
