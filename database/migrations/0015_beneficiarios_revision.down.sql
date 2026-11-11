-- Revierte 0015 (sólo desarrollo/pruebas).
DROP VIEW IF EXISTS v_revision_grupos_cuenta;
DROP TRIGGER IF EXISTS asociaciones_historial_accion_masiva ON asociaciones_historial;
DROP FUNCTION IF EXISTS fn_historial_accion_masiva();
DROP FUNCTION IF EXISTS fn_confirmar_beneficiario(bigint, bigint, bigint, text, bigint);
ALTER TABLE asociaciones_historial DROP CONSTRAINT asoc_hist_manual;
DELETE FROM asociaciones_historial WHERE accion = 'CONFIRMAR_BENEFICIARIO';
ALTER TABLE asociaciones_historial ADD CONSTRAINT asoc_hist_manual CHECK (accion NOT IN ('ASOCIAR', 'RECHAZAR_SUGERENCIA', 'MARCAR_NO_IDENTIFICADO',
    'CONFIRMAR_CUENTA') OR (usuario_id IS NOT NULL AND char_length(btrim(coalesce(motivo, ''))) >= 5));
ALTER TABLE asociaciones_historial DROP CONSTRAINT asoc_hist_accion;
ALTER TABLE asociaciones_historial ADD CONSTRAINT asoc_hist_accion CHECK (accion IN ('AUTOMATICA', 'REASOCIACION', 'ASOCIAR', 'RECHAZAR_SUGERENCIA',
    'MARCAR_NO_IDENTIFICADO', 'CONFIRMAR_CUENTA', 'TRASLADO'));
DROP INDEX IF EXISTS asociaciones_historial_fecha_idx;
ALTER TABLE asociaciones_historial DROP COLUMN IF EXISTS accion_masiva_id;
ALTER TABLE beneficiario_trabajadores DROP CONSTRAINT IF EXISTS bt_accion_masiva;
DROP TABLE IF EXISTS acciones_masivas_registros;
DROP TABLE IF EXISTS acciones_masivas;
DROP FUNCTION IF EXISTS fn_accion_masiva_registro_reglas();
ALTER TABLE registros_tesoreria DROP COLUMN IF EXISTS beneficiario_id;
DROP TRIGGER IF EXISTS cuentas_bancarias_beneficiario ON cuentas_bancarias;
DROP FUNCTION IF EXISTS fn_cuenta_beneficiario_inmutable();
ALTER TABLE cuentas_bancarias DROP COLUMN IF EXISTS beneficiario_id;
DROP TABLE IF EXISTS beneficiario_trabajadores;
DROP TABLE IF EXISTS beneficiarios_pension;
DROP FUNCTION IF EXISTS fn_beneficiario_reglas();
DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo IN ('beneficiarios', 'reportes') OR codigo = 'asociaciones.masivo');
DELETE FROM permisos WHERE modulo IN ('beneficiarios', 'reportes') OR codigo = 'asociaciones.masivo';
DELETE FROM configuracion WHERE clave IN ('revision.max_registros_masivo', 'reportes.max_por_minuto', 'reportes.max_filas');
