-- Revierte 0011 (sólo desarrollo/pruebas). Primero las tablas (referencian procesos), después los trabajos
-- NORMALIZAR_REGISTROS, que son artefactos de esta migración.

DROP TRIGGER IF EXISTS archivos_encolar_normalizacion ON archivos_importados;
DROP TRIGGER IF EXISTS archivos_invalidar_importacion ON archivos_importados;
DROP FUNCTION IF EXISTS fn_archivo_encolar_normalizacion();
DROP FUNCTION IF EXISTS fn_archivo_invalidar_importacion();
DROP FUNCTION IF EXISTS fn_confirmar_cuenta(bigint, bigint, text);
DROP FUNCTION IF EXISTS fn_importaciones_con_cuenta(bigint);
DROP VIEW IF EXISTS v_asociaciones_pendientes;

DROP TABLE IF EXISTS lotes_relaciones;
DROP TABLE IF EXISTS asociaciones_historial;
DROP TABLE IF EXISTS incidencias_identidad;
DROP TABLE IF EXISTS polizas_egreso_lineas;
DROP TABLE IF EXISTS polizas_globales_lineas;
DROP TABLE IF EXISTS polizas_globales;
DROP TABLE IF EXISTS cuentas_bancarias;
ALTER TABLE IF EXISTS empleados DROP CONSTRAINT IF EXISTS empleados_origen_registro_fk;
ALTER TABLE IF EXISTS empleado_historial DROP CONSTRAINT IF EXISTS empleado_historial_registro_fk;
DROP TABLE IF EXISTS registros_tesoreria;
DROP TABLE IF EXISTS registros_rrhh_conceptos;
DROP TABLE IF EXISTS registros_rrhh;
DROP TABLE IF EXISTS empleado_historial;
DROP TABLE IF EXISTS empleados;
DROP TABLE IF EXISTS importaciones_normalizadas;
DROP TABLE IF EXISTS conceptos_nomina;
DROP FUNCTION IF EXISTS fn_lote_relacion_reglas();
DROP FUNCTION IF EXISTS fn_cuenta_inmutable();
DROP FUNCTION IF EXISTS fn_empleado_inmutable();
DROP FUNCTION IF EXISTS fn_solo_insercion();

ALTER TABLE staging_registros DROP CONSTRAINT IF EXISTS staging_cuenta_cifrada,
    DROP COLUMN IF EXISTS cuenta_cifrada, DROP COLUMN IF EXISTS cuenta_key_id;

DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE codigo IN (
    'empleados.ver', 'empleados.ver_sensible', 'empleados.editar', 'asociaciones.ver', 'asociaciones.resolver',
    'cuentas.ver_sensible', 'cuentas.confirmar', 'lotes.relacionar', 'importacion.normalizar', 'conceptos.editar'));
DELETE FROM permisos WHERE codigo IN (
    'empleados.ver', 'empleados.ver_sensible', 'empleados.editar', 'asociaciones.ver', 'asociaciones.resolver',
    'cuentas.ver_sensible', 'cuentas.confirmar', 'lotes.relacionar', 'importacion.normalizar', 'conceptos.editar');
DELETE FROM configuracion WHERE clave IN ('normalizacion.timeout_segundos', 'identidad.similitud_minima');

DELETE FROM proceso_eventos WHERE proceso_id IN (SELECT id FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS');
UPDATE workers SET proceso_actual_id = NULL WHERE proceso_actual_id IN (SELECT id FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS');
DELETE FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS';
