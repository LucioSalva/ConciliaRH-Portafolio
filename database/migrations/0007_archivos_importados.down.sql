-- Reversión de 0007 (sólo desarrollo/pruebas). Los binarios en storage/imports NO se borran.
DROP VIEW IF EXISTS v_lotes_resumen;
DROP TRIGGER IF EXISTS procesos_a_archivo ON procesos;
DROP FUNCTION IF EXISTS fn_proceso_a_archivo();
DROP TABLE IF EXISTS archivos_importados;
DROP FUNCTION IF EXISTS fn_archivo_reglas();
