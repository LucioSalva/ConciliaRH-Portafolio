-- =====================================================================
-- 0021 · Cifrado en reposo de reportes generados con datos de personas (FASE 8, docs/REPORT_ENCRYPTION.md)
--  * Clasificación: 'resumen', 'bancos' y 'grupos' son AGREGADOS (sin personas) y pueden guardarse en claro si no son
--    sensibles; cualquier otro tipo (detalle por persona, diferencias, pendientes, pensiones, incidencias…) o cualquier
--    reporte sensible (RFC/CURP) se guarda CIFRADO. La BD lo exige al pasar a DISPONIBLE.
--  * Formato: libsodium secretstream XChaCha20-Poly1305 (AEAD por segmentos, detecta truncado) con llave del anillo
--    REPORT_ENC_KEYS, DISTINTA de ACCOUNT_ENC_KEYS y ACCOUNT_HMAC_KEYS. El sha256 sigue siendo el del archivo guardado.
--  * Rotación (bin/rotate-report-key.php, rol propietario): vuelve a cifrar con la llave activa; sólo entonces cambian
--    ruta, sha256 y bytes. Los reportes en claro previos a esta versión que debían cifrarse se cifran con la misma CLI.
-- =====================================================================

ALTER TABLE reportes_generados ADD COLUMN cifrado boolean NOT NULL DEFAULT false;
ALTER TABLE reportes_generados ADD COLUMN cifrado_llave text;
ALTER TABLE reportes_generados ADD COLUMN cifrado_at timestamptz;

ALTER TABLE reportes_generados DROP CONSTRAINT rg_ruta;
ALTER TABLE reportes_generados ADD CONSTRAINT rg_ruta
    CHECK (ruta IS NULL OR ruta ~ '^generados/[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.(xlsx|csv|pdf)(\.enc)?$');
ALTER TABLE reportes_generados ADD CONSTRAINT rg_cifrado
    CHECK ((cifrado_llave IS NULL OR cifrado_llave ~ '^[a-z0-9_-]{1,16}$')
           AND (NOT cifrado OR (cifrado_llave IS NOT NULL AND cifrado_at IS NOT NULL))
           AND (ruta IS NULL OR (ruta LIKE '%.enc') = cifrado));

-- Tipos agregados (sin datos de personas): el resto exige cifrado
CREATE FUNCTION fn_reporte_requiere_cifrado(p_tipo text, p_sensible boolean) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
    SELECT p_sensible OR p_tipo NOT IN ('resumen', 'bancos', 'grupos')
$$;

CREATE OR REPLACE FUNCTION fn_reporte_generado_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_rotacion boolean;
BEGIN
    -- Rotación de llave: sólo el propietario del esquema (CLI) re-cifra un reporte ya cifrado o cifra uno legado
    v_rotacion := NOT fn_es_conexion_web()
                  AND (coalesce((SELECT rolsuper FROM pg_roles WHERE rolname = session_user), false)
                       OR NOT pg_has_role(session_user, 'conciliarh_scheduler', 'MEMBER'))
                  AND NEW.cifrado AND NEW.cifrado_llave IS DISTINCT FROM OLD.cifrado_llave AND OLD.estado = 'DISPONIBLE'
                  AND NEW.estado = 'DISPONIBLE';
    IF NEW.programacion_id <> OLD.programacion_id OR NEW.propietario_id <> OLD.propietario_id OR NEW.tipo <> OLD.tipo
       OR NEW.formato <> OLD.formato OR NEW.sensible <> OLD.sensible OR NEW.origen <> OLD.origen
       OR NEW.programado_para <> OLD.programado_para OR NEW.created_at <> OLD.created_at
       OR (NOT v_rotacion AND OLD.sha256 IS NOT NULL AND NEW.sha256 IS DISTINCT FROM OLD.sha256)
       OR (NOT v_rotacion AND OLD.ruta IS NOT NULL AND NEW.ruta IS DISTINCT FROM OLD.ruta)
       OR (OLD.cifrado AND NOT NEW.cifrado) THEN
        RAISE EXCEPTION 'La identidad, sensibilidad, huella y cifrado de un reporte generado son inmutables' USING ERRCODE = 'check_violation';
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
    IF NEW.estado = 'DISPONIBLE' AND OLD.estado <> 'DISPONIBLE' AND NOT NEW.cifrado
       AND fn_reporte_requiere_cifrado(NEW.tipo, NEW.sensible) THEN
        RAISE EXCEPTION 'REPORTE_SIN_CIFRAR: un reporte con datos de personas se guarda cifrado' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

GRANT UPDATE (cifrado, cifrado_llave, cifrado_at) ON reportes_generados TO conciliarh_scheduler;
GRANT EXECUTE ON FUNCTION fn_reporte_requiere_cifrado(text, boolean) TO conciliarh_web, conciliarh_scheduler;
