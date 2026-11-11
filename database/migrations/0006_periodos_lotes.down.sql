-- Reversión de 0006 (sólo desarrollo/pruebas)
DROP FUNCTION IF EXISTS fn_crear_lote(bigint, text, text, text, text, bigint);
DROP TABLE IF EXISTS lotes_importacion;
DROP FUNCTION IF EXISTS fn_lote_periodo_abierto();
DROP TABLE IF EXISTS periodos_nomina;
DROP TABLE IF EXISTS perfiles_importacion;
DROP TABLE IF EXISTS origenes_administrativos;
DROP TABLE IF EXISTS tipos_documento;
