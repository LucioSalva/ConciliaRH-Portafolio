-- Revierte 0019 (sólo desarrollo/pruebas)
REVOKE SELECT (version, nombre, aplicada_at) ON schema_migraciones FROM conciliarh_web;
DROP TRIGGER IF EXISTS conciliaciones_notificar ON conciliaciones;
DROP TRIGGER IF EXISTS reportes_generados_notificar ON reportes_generados;
DROP TRIGGER IF EXISTS archivos_notificar_revision ON archivos_importados;
DROP TRIGGER IF EXISTS normalizaciones_notificar ON importaciones_normalizadas;
DROP FUNCTION IF EXISTS fn_notif_conciliacion();
DROP FUNCTION IF EXISTS fn_notif_reporte_generado();
DROP FUNCTION IF EXISTS fn_notif_archivo_revision();
DROP FUNCTION IF EXISTS fn_notif_normalizacion();
DROP FUNCTION IF EXISTS fn_mantenimiento_notificaciones();
DROP FUNCTION IF EXISTS fn_sistema_ejecucion(text, text, timestamptz, text, jsonb);
DROP FUNCTION IF EXISTS fn_notificar_permiso(text, bigint, text, text, text, text, text, text, bigint, text);
DROP FUNCTION IF EXISTS fn_notificar(bigint, text, text, text, text, text, text, bigint, text);
DROP TABLE IF EXISTS sistema_ejecuciones;
DROP TABLE IF EXISTS notificaciones;
DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE codigo IN ('sistema.salud', 'sistema.mantenimiento'));
DELETE FROM permisos WHERE codigo IN ('sistema.salud', 'sistema.mantenimiento');
DELETE FROM configuracion WHERE clave = 'notificaciones.retencion_dias';
