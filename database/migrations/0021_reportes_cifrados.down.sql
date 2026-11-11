-- Revierte 0021 (sólo desarrollo/pruebas). Los reportes cifrados quedan ilegibles para el código anterior: expírelos antes.
CREATE OR REPLACE FUNCTION fn_reporte_generado_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.programacion_id <> OLD.programacion_id OR NEW.propietario_id <> OLD.propietario_id OR NEW.tipo <> OLD.tipo
       OR NEW.formato <> OLD.formato OR NEW.sensible <> OLD.sensible OR NEW.origen <> OLD.origen
       OR NEW.programado_para <> OLD.programado_para OR NEW.created_at <> OLD.created_at
       OR (OLD.sha256 IS NOT NULL AND NEW.sha256 IS DISTINCT FROM OLD.sha256)
       OR (OLD.ruta IS NOT NULL AND NEW.ruta IS DISTINCT FROM OLD.ruta) THEN
        RAISE EXCEPTION 'La identidad, sensibilidad y huella de un reporte generado son inmutables' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado = 'EXPIRADO' AND NEW.estado <> OLD.estado THEN
        RAISE EXCEPTION 'Un reporte expirado no vuelve a estar disponible' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado = 'ALTERADO' AND NEW.estado NOT IN ('ALTERADO', 'EXPIRADO') THEN
        RAISE EXCEPTION 'Un reporte alterado no vuelve a estar disponible' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado IN ('DISPONIBLE', 'SIN_DATOS', 'ERROR') AND NEW.estado IN ('PENDIENTE', 'GENERANDO') THEN
        RAISE EXCEPTION 'Un reporte terminado no vuelve a generarse' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;
-- Sin cifrado, el código anterior no podría leerlos: los reportes cifrados quedan EXPIRADOS (su archivo queda huérfano en desarrollo)
ALTER TABLE reportes_generados DISABLE TRIGGER reportes_generados_reglas;
UPDATE reportes_generados SET estado = 'EXPIRADO', archivo_eliminado_at = coalesce(archivo_eliminado_at, now()), ruta = NULL
 WHERE cifrado OR ruta LIKE '%.enc';
ALTER TABLE reportes_generados ENABLE TRIGGER reportes_generados_reglas;
DROP FUNCTION IF EXISTS fn_reporte_requiere_cifrado(text, boolean);
ALTER TABLE reportes_generados DROP CONSTRAINT IF EXISTS rg_cifrado;
ALTER TABLE reportes_generados DROP CONSTRAINT IF EXISTS rg_ruta;
ALTER TABLE reportes_generados ADD CONSTRAINT rg_ruta CHECK (ruta IS NULL OR ruta ~ '^generados/[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.(xlsx|csv|pdf)$') NOT VALID;
ALTER TABLE reportes_generados DROP COLUMN IF EXISTS cifrado_at;
ALTER TABLE reportes_generados DROP COLUMN IF EXISTS cifrado_llave;
ALTER TABLE reportes_generados DROP COLUMN IF EXISTS cifrado;
