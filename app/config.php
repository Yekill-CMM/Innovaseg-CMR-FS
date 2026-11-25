<?php
/* =========================================================================
   CRM Comercial - configuración
   Cambia la clave antes de usar el CRM. Debe tener al menos 10 caracteres.
   Es la que el CRM te pedirá una vez en cada dispositivo (PC, celular).
   Ejemplo:  define('CRM_CLAVE', 'Mi-Clave-Larga-2026');
   ========================================================================= */
define('CRM_CLAVE', 'CAMBIA-ESTA-CLAVE');

/* Clave del agente (opcional), de solo lectura: permite que un agente automático
   lea el resumen del CRM (api.php?accion=agente) pero no cambiar nada.
   Debe ser distinta de la anterior y tener al menos 16 caracteres.
   Déjala vacía para desactivar el acceso del agente. */
define('CRM_CLAVE_AGENTE', '');
