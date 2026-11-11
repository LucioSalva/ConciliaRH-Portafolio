-- =====================================================================
-- 0027 — El historial de asociaciones ya no exige leer acciones_masivas cuando no cita ninguna.
-- Con un plan genérico (tras varias ejecuciones o con estadísticas, p. ej. después de restaurar), PostgreSQL revisa el
-- permiso sobre todas las tablas de la expresión aunque la condición ya sea falsa; el worker, que no puede leer
-- acciones_masivas, fallaba al registrar su historial. Las dos comprobaciones van ahora en IF separados.
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_historial_accion_masiva() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.accion_masiva_id IS NOT NULL THEN
        IF NOT EXISTS (SELECT 1 FROM acciones_masivas a WHERE a.id = NEW.accion_masiva_id
                         AND a.usuario_id IS NOT DISTINCT FROM NEW.usuario_id AND a.created_at = now()) THEN
            RAISE EXCEPTION 'El historial cita una acción masiva ajena' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
