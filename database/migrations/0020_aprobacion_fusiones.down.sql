-- Revierte 0020 (sólo desarrollo/pruebas): restaura la fusión directa de 0017 (una persona acepta conflictos graves)
CREATE OR REPLACE FUNCTION fn_fusionar_empleados(p_origen bigint, p_destino bigint, p_usuario_id bigint, p_motivo text,
                                      p_aceptados text[], p_huella text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_an jsonb;
    v_graves text[];
    v_id bigint;
    v_reap bigint[];
BEGIN
    IF p_usuario_id IS NULL OR fn_actor_actual() IS DISTINCT FROM p_usuario_id THEN
        RAISE EXCEPTION 'Fusión sin actor de sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT fn_actor_tiene_permiso('empleados.fusionar') THEN
        RAISE EXCEPTION 'Sin permiso para fusionar empleados' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 20 THEN
        RAISE EXCEPTION 'La fusión exige un motivo de al menos 20 caracteres' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    -- Mismo candado global que el worker y la revisión masiva; después las filas en orden (sin interbloqueos)
    PERFORM pg_advisory_xact_lock(4850181374600825153);
    PERFORM 1 FROM empleados WHERE id IN (p_origen, p_destino) ORDER BY id FOR UPDATE;
    v_an := fn_fusion_analisis(p_origen, p_destino);
    IF fn_fusion_huella(v_an) IS DISTINCT FROM p_huella THEN
        RAISE EXCEPTION 'REGISTRO_MODIFICADO: los datos de los empleados cambiaron desde la previsualización'
            USING ERRCODE = 'serialization_failure';
    END IF;
    IF v_an->>'confianza' = 'BLOQUEADA' THEN
        RAISE EXCEPTION 'FUSION_BLOQUEADA' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT (v_an->>'fusionable')::boolean THEN
        RAISE EXCEPTION 'EVIDENCIA_INSUFICIENTE: el nombre por sí solo nunca fusiona' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(array_agg(c->>'codigo' ORDER BY c->>'codigo'), '{}') INTO v_graves
      FROM jsonb_array_elements(v_an->'conflictos') c WHERE c->>'severidad' = 'GRAVE';
    IF cardinality(v_graves) > 0 THEN
        IF NOT fn_actor_tiene_permiso('empleados.fusionar_conflictos') THEN
            RAISE EXCEPTION 'Conflictos graves: sólo un usuario autorizado puede aceptarlos' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF NOT (v_graves <@ coalesce(p_aceptados, '{}')) THEN
            RAISE EXCEPTION 'CONFLICTOS_SIN_ACEPTAR' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    -- Quienes ya estaban fusionados en el origen pasan al nuevo canónico (la resolución sigue siendo de un salto)
    WITH r AS (UPDATE empleados SET fusionado_en_id = p_destino, actualizado_por = p_usuario_id WHERE fusionado_en_id = p_origen RETURNING id)
    SELECT coalesce(array_agg(id ORDER BY id), '{}') INTO v_reap FROM r;
    INSERT INTO fusiones_empleados (origen_id, destino_id, usuario_id, motivo, confianza, analisis, conflictos_aceptados, reapuntados, huella)
    VALUES (p_origen, p_destino, p_usuario_id, btrim(p_motivo), v_an->>'confianza', v_an, v_graves, v_reap, p_huella)
    RETURNING id INTO v_id;
    UPDATE empleados SET fusionado_en_id = p_destino, fusion_id = v_id, actualizado_por = p_usuario_id WHERE id = p_origen;
    INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo)
    VALUES (p_origen, 'fusionado_en_id', NULL, p_destino::text, 'FUSION', p_usuario_id, 'Fusión #' || v_id || ': ' || left(btrim(p_motivo), 400));
    UPDATE duplicados_empleados SET estado = 'FUSIONADO'
     WHERE empleado_a = least(p_origen, p_destino) AND empleado_b = greatest(p_origen, p_destino) AND estado <> 'FUSIONADO';
    PERFORM fn_fusion_recalcular();
    RETURN v_id;
END;
$$;
DROP FUNCTION IF EXISTS fn_solicitudes_fusion_expirar();
DROP FUNCTION IF EXISTS fn_decidir_solicitud_fusion(bigint, bigint, text, text);
DROP FUNCTION IF EXISTS fn_aprobar_solicitud_fusion(bigint, bigint, text);
DROP FUNCTION IF EXISTS fn_notif_solicitud_decidida(bigint);
DROP FUNCTION IF EXISTS fn_solicitar_fusion_critica(bigint, bigint, bigint, text, text[], text);
DROP FUNCTION IF EXISTS fn_fusion_validar(bigint, bigint, text);
DROP FUNCTION IF EXISTS fn_fusion_conflictos_extra(bigint, bigint);
DROP TRIGGER IF EXISTS usuario_rol_protegido ON usuario_rol;
DROP FUNCTION IF EXISTS fn_usuario_rol_protegido();
DROP FUNCTION IF EXISTS fn_fusion_aplicar(bigint, bigint, bigint, text, jsonb, text[], text, bigint, bigint);
ALTER TABLE fusiones_empleados DROP COLUMN IF EXISTS solicitud_id;
ALTER TABLE fusiones_empleados DROP COLUMN IF EXISTS solicitado_por;
DROP TABLE IF EXISTS solicitudes_fusion;
DROP FUNCTION IF EXISTS fn_solicitud_fusion_reglas();
DELETE FROM notificaciones WHERE tipo IN ('FUSION_PENDIENTE_APROBACION', 'FUSION_DECIDIDA');
DELETE FROM usuario_rol WHERE rol_id IN (SELECT id FROM roles WHERE codigo = 'APROBADOR');
DELETE FROM rol_permiso WHERE rol_id IN (SELECT id FROM roles WHERE codigo = 'APROBADOR')
   OR permiso_id IN (SELECT id FROM permisos WHERE codigo = 'empleados.fusionar_conflictos_aprobar');
DELETE FROM roles WHERE codigo = 'APROBADOR';
DELETE FROM permisos WHERE codigo = 'empleados.fusionar_conflictos_aprobar';
DELETE FROM configuracion WHERE clave = 'fusiones.solicitud_expira_horas';
