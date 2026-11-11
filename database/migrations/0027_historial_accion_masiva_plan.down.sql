-- 0027 (down) — Vuelve a la condición única de 0015 (sólo desarrollo/pruebas).
CREATE OR REPLACE FUNCTION fn_historial_accion_masiva() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.accion_masiva_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM acciones_masivas a WHERE a.id = NEW.accion_masiva_id
                                                          AND a.usuario_id IS NOT DISTINCT FROM NEW.usuario_id AND a.created_at = now()) THEN
        RAISE EXCEPTION 'El historial cita una acción masiva ajena' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
