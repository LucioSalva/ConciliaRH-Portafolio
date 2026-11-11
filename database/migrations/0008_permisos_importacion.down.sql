-- Reversión de 0008 (sólo desarrollo/pruebas)
ALTER TABLE usuarios DROP COLUMN IF EXISTS es_desarrollo;
DELETE FROM configuracion WHERE clave LIKE 'importacion.%';
DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo IN ('periodos', 'lotes', 'archivos', 'catalogos'));
DELETE FROM permisos WHERE modulo IN ('periodos', 'lotes', 'archivos', 'catalogos');
