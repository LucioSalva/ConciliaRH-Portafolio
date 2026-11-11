-- 0014 · Corrección (defecto de 0.1.0-alpha.5): el worker de larga duración fallaba al terminar conciliaciones.
-- Tras ~5 ejecuciones en una sesión, plpgsql usa un plan GENÉRICO para la expresión de un IF; ese plan verifica el
-- privilegio EXECUTE de todas las funciones que menciona, aunque el cortocircuito nunca las evalúe. La expresión de
-- fn_conciliacion_reglas mencionaba fn_actor_actual() (EXECUTE sólo para la web) junto a su guarda fn_es_conexion_web().
-- Se reemplaza la función con la consulta en una sentencia propia dentro de la rama web. Sin cambios de datos.
CREATE OR REPLACE FUNCTION fn_conciliacion_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.estado <> 'PENDIENTE' THEN
            RAISE EXCEPTION 'Una conciliación nueva inicia en PENDIENTE' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM lotes_importacion WHERE id = NEW.lote_id AND periodo_id = NEW.periodo_id AND estado <> 'ANULADO') THEN
            RAISE EXCEPTION 'El lote no pertenece al periodo o está anulado' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.periodo_id <> OLD.periodo_id OR NEW.lote_id <> OLD.lote_id OR NEW.numero <> OLD.numero OR NEW.tolerancia <> OLD.tolerancia
       OR NEW.solicitado_por <> OLD.solicitado_por OR NEW.created_at <> OLD.created_at OR NEW.lote_oficial <> OLD.lote_oficial THEN
        RAISE EXCEPTION 'La identidad de una conciliación es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NOT (
               (OLD.estado = 'PENDIENTE'  AND NEW.estado IN ('EJECUTANDO', 'ERROR', 'INVALIDADA'))
            OR (OLD.estado = 'EJECUTANDO' AND NEW.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'ERROR', 'PENDIENTE'))
            OR (OLD.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL') AND NEW.estado IN ('CERRADA', 'INVALIDADA'))
            OR (OLD.estado = 'ERROR'      AND NEW.estado = 'INVALIDADA')
            OR (OLD.estado = 'CERRADA'    AND NEW.estado = 'INVALIDADA')
        ) THEN
            RAISE EXCEPTION 'Transición de conciliación inválida: % → %', OLD.estado, NEW.estado USING ERRCODE = 'check_violation';
        END IF;
        -- La web sólo cierra o invalida; ERROR únicamente cuando su trabajo terminó en ERROR/CANCELADO (fn_proceso_a_conciliacion)
        IF fn_es_conexion_web() AND NOT (NEW.estado IN ('CERRADA', 'INVALIDADA')
               OR (NEW.estado = 'ERROR' AND EXISTS (SELECT 1 FROM procesos p WHERE p.id = NEW.proceso_id AND p.estado IN ('ERROR', 'CANCELADO')))) THEN
            RAISE EXCEPTION 'La aplicación no fija el estado % de una conciliación', NEW.estado USING ERRCODE = 'insufficient_privilege';
        END IF;
        -- Sólo se cierra la última ejecución vigente del lote (el historial nunca tiene dos «versiones oficiales»)
        IF NEW.estado = 'CERRADA' AND EXISTS (SELECT 1 FROM conciliaciones x WHERE x.lote_id = NEW.lote_id AND x.numero > NEW.numero
                                                 AND x.estado NOT IN ('INVALIDADA', 'ERROR')) THEN
            RAISE EXCEPTION 'Sólo se cierra la ejecución más reciente del lote' USING ERRCODE = 'check_violation';
        END IF;
        -- Invalidar una conciliación CERRADA es exclusivo de GOD (defensa en profundidad del permiso de la aplicación)
        -- La consulta con fn_actor_actual() (sólo web) va en una sentencia PROPIA dentro de la rama web: plpgsql planea cada
        -- sentencia al ejecutarla y el worker nunca llega aquí (en una misma expresión, el plan genérico verificaba EXECUTE).
        IF OLD.estado = 'CERRADA' AND fn_es_conexion_web() THEN
            IF NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
                            WHERE ur.usuario_id = fn_actor_actual() AND r.codigo = 'GOD') THEN
                RAISE EXCEPTION 'Sólo GOD invalida una conciliación cerrada' USING ERRCODE = 'insufficient_privilege';
            END IF;
        END IF;
        -- Sólo el motor fija los estados de ejecución
        IF NEW.estado IN ('EJECUTANDO', 'COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL') AND fn_es_conexion_web() THEN
            RAISE EXCEPTION 'El estado % lo fija el motor de conciliación', NEW.estado USING ERRCODE = 'insufficient_privilege';
        END IF;
    ELSIF OLD.estado NOT IN ('PENDIENTE', 'EJECUTANDO') THEN
        RAISE EXCEPTION 'Una conciliación terminada no se modifica (sólo se cierra o se invalida)' USING ERRCODE = 'check_violation';
    END IF;
    -- Autoría de cierre e invalidación: se fija una vez, en su transición, y nunca se borra ni se reescribe
    IF OLD.cerrada_por IS NOT NULL AND (NEW.cerrada_por IS DISTINCT FROM OLD.cerrada_por OR NEW.cerrada_at IS DISTINCT FROM OLD.cerrada_at
                                        OR NEW.motivo_cierre IS DISTINCT FROM OLD.motivo_cierre)
       OR OLD.cerrada_por IS NULL AND (NEW.cerrada_por IS NOT NULL OR NEW.cerrada_at IS NOT NULL OR NEW.motivo_cierre IS DISTINCT FROM OLD.motivo_cierre)
          AND NOT (NEW.estado = 'CERRADA' AND OLD.estado <> 'CERRADA') THEN
        RAISE EXCEPTION 'Los datos de cierre se registran sólo al cerrar y no se modifican' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.invalidada_por IS NOT NULL AND (NEW.invalidada_por IS DISTINCT FROM OLD.invalidada_por OR NEW.invalidada_at IS DISTINCT FROM OLD.invalidada_at
                                           OR NEW.motivo_invalidacion IS DISTINCT FROM OLD.motivo_invalidacion)
       OR OLD.invalidada_por IS NULL AND (NEW.invalidada_por IS NOT NULL OR NEW.invalidada_at IS NOT NULL
                                          OR NEW.motivo_invalidacion IS DISTINCT FROM OLD.motivo_invalidacion)
          AND NOT (NEW.estado = 'INVALIDADA' AND OLD.estado <> 'INVALIDADA') THEN
        RAISE EXCEPTION 'Los datos de invalidación se registran sólo al invalidar y no se modifican' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.proceso_id IS NOT NULL AND NEW.proceso_id IS DISTINCT FROM OLD.proceso_id THEN
        RAISE EXCEPTION 'El trabajo de una conciliación no se reasigna' USING ERRCODE = 'check_violation';
    END IF;
    -- La hora de cierre/invalidación la pone la BD, no el cliente
    IF fn_es_conexion_web() THEN
        IF NEW.cerrada_por IS NOT NULL AND OLD.cerrada_por IS NULL THEN NEW.cerrada_at := now(); END IF;
        IF NEW.invalidada_por IS NOT NULL AND OLD.invalidada_por IS NULL THEN NEW.invalidada_at := now(); END IF;
    END IF;
    IF OLD.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA', 'ERROR')
       AND (NEW.totales <> OLD.totales OR NEW.resumen <> OLD.resumen OR NEW.huella_entradas IS DISTINCT FROM OLD.huella_entradas
            OR NEW.motor_version IS DISTINCT FROM OLD.motor_version) THEN
        RAISE EXCEPTION 'Los resultados de una conciliación terminada no se modifican' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
