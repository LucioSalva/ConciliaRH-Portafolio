-- =====================================================================
-- 0020 · Doble aprobación (cuatro ojos) de fusiones con conflictos GRAVES (FASE 8, docs/CRITICAL_MERGE_APPROVAL.md)
--  * Una fusión con conflictos graves ya NO la aplica una sola persona: el usuario A la SOLICITA (con motivo, cada conflicto
--    aceptado y la huella del análisis) y un usuario B DISTINTO la APRUEBA. La BD lo exige (no sólo PHP).
--  * Estados: PENDIENTE_APROBACION → EJECUTADA | RECHAZADA | CANCELADA | EXPIRADA. «Borrador» es la previsualización (no se
--    persiste) y «aprobada» se ejecuta en la misma transacción que la aprobación (no existe un aprobado sin ejecutar).
--  * Expira (fusiones.solicitud_expira_horas, 48 h por omisión, 24–72). Si el análisis cambió, la aprobación la CANCELA.
--  * Permiso empleados.fusionar_conflictos_aprobar (GOD) y rol protegido APROBADOR (sólo GOD lo asigna): así un segundo
--    usuario —p. ej. un ADMIN de confianza— puede aprobar sin crear un segundo GOD.
--  * Las fusiones sin conflictos graves siguen igual (una persona con empleados.fusionar).
-- =====================================================================

CREATE TABLE solicitudes_fusion (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    origen_id           bigint      NOT NULL REFERENCES empleados (id),
    destino_id          bigint      NOT NULL REFERENCES empleados (id),
    estado              text        NOT NULL DEFAULT 'PENDIENTE_APROBACION',
    motivo              text        NOT NULL,
    conflictos_graves   text[]      NOT NULL,
    analisis            jsonb       NOT NULL,
    huella              text        NOT NULL,
    solicitado_por      bigint      NOT NULL REFERENCES usuarios (id),
    solicitado_at       timestamptz NOT NULL DEFAULT now(),
    expira_at           timestamptz NOT NULL,
    decidido_por        bigint      REFERENCES usuarios (id),
    decidido_at         timestamptz,
    motivo_decision     text,
    fusion_id           bigint      REFERENCES fusiones_empleados (id),
    CONSTRAINT sf_distintos CHECK (origen_id <> destino_id),
    CONSTRAINT sf_estado CHECK (estado IN ('PENDIENTE_APROBACION', 'EJECUTADA', 'RECHAZADA', 'CANCELADA', 'EXPIRADA')),
    CONSTRAINT sf_motivo CHECK (char_length(btrim(motivo)) BETWEEN 20 AND 2000),
    CONSTRAINT sf_graves CHECK (cardinality(conflictos_graves) > 0),
    CONSTRAINT sf_huella CHECK (huella ~ '^[0-9a-f]{64}$'),
    CONSTRAINT sf_expira CHECK (expira_at > solicitado_at),
    CONSTRAINT sf_cuatro_ojos CHECK (decidido_por IS NULL OR estado IN ('CANCELADA', 'EXPIRADA') OR decidido_por <> solicitado_por),
    CONSTRAINT sf_decision CHECK ((estado = 'PENDIENTE_APROBACION') = (decidido_at IS NULL)
                                  AND (estado <> 'EJECUTADA' OR (fusion_id IS NOT NULL AND decidido_por IS NOT NULL))
                                  AND (estado NOT IN ('RECHAZADA', 'CANCELADA') OR char_length(btrim(coalesce(motivo_decision, ''))) >= 5))
);
-- Una sola solicitud pendiente por pareja (en cualquier dirección)
CREATE UNIQUE INDEX sf_pendiente_uk ON solicitudes_fusion (least(origen_id, destino_id), greatest(origen_id, destino_id))
    WHERE estado = 'PENDIENTE_APROBACION';
CREATE INDEX sf_estado_idx ON solicitudes_fusion (estado, solicitado_at DESC);

ALTER TABLE fusiones_empleados ADD COLUMN solicitud_id bigint REFERENCES solicitudes_fusion (id);
ALTER TABLE fusiones_empleados ADD COLUMN solicitado_por bigint REFERENCES usuarios (id);

CREATE FUNCTION fn_solicitud_fusion_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- Inmutable salvo la decisión, que ocurre una sola vez desde PENDIENTE_APROBACION
    IF NEW.origen_id <> OLD.origen_id OR NEW.destino_id <> OLD.destino_id OR NEW.motivo <> OLD.motivo
       OR NEW.conflictos_graves <> OLD.conflictos_graves OR NEW.analisis <> OLD.analisis OR NEW.huella <> OLD.huella
       OR NEW.solicitado_por <> OLD.solicitado_por OR NEW.solicitado_at <> OLD.solicitado_at OR NEW.expira_at <> OLD.expira_at
       OR OLD.estado <> 'PENDIENTE_APROBACION' OR NEW.estado = 'PENDIENTE_APROBACION' THEN
        RAISE EXCEPTION 'Una solicitud de fusión sólo se decide una vez' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER solicitudes_fusion_reglas BEFORE UPDATE ON solicitudes_fusion FOR EACH ROW EXECUTE FUNCTION fn_solicitud_fusion_reglas();
CREATE TRIGGER solicitudes_fusion_actor BEFORE INSERT OR UPDATE ON solicitudes_fusion
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('solicitado_por', 'decidido_por');
CREATE TRIGGER solicitudes_fusion_sin_borrado BEFORE DELETE ON solicitudes_fusion FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER solicitudes_fusion_sin_truncate BEFORE TRUNCATE ON solicitudes_fusion FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Aplicación de una fusión (interna; sin permisos de ejecución para nadie). La llaman fn_fusionar_empleados (sin conflictos
-- graves) y fn_aprobar_solicitud_fusion (con doble aprobación). Supone candados tomados y análisis ya validado.
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_fusion_aplicar(p_origen bigint, p_destino bigint, p_usuario_id bigint, p_motivo text, p_an jsonb,
                                  p_graves text[], p_huella text, p_solicitud bigint, p_solicitante bigint)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_id bigint;
    v_reap bigint[];
BEGIN
    WITH r AS (UPDATE empleados SET fusionado_en_id = p_destino, actualizado_por = p_usuario_id WHERE fusionado_en_id = p_origen RETURNING id)
    SELECT coalesce(array_agg(id ORDER BY id), '{}') INTO v_reap FROM r;
    INSERT INTO fusiones_empleados (origen_id, destino_id, usuario_id, motivo, confianza, analisis, conflictos_aceptados, reapuntados, huella,
                                    solicitud_id, solicitado_por)
    VALUES (p_origen, p_destino, p_usuario_id, btrim(p_motivo), p_an->>'confianza', p_an, p_graves, v_reap, p_huella, p_solicitud, p_solicitante)
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

-- Conflicto grave ADICIONAL (revisión de seguridad FASE 8): si alguno de los dos tuvo un RFC, CURP o ISSEMYM EDITADO o BORRADO a mano,
-- la ausencia del conflicto «identificadores distintos» puede ser artificial (borrar el RFC de uno quita el conflicto). Esa fusión
-- también exige la segunda firma. Fuente: empleado_historial (MANUAL), nunca el valor.
CREATE FUNCTION fn_fusion_conflictos_extra(p_origen bigint, p_destino bigint) RETURNS text[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT CASE WHEN EXISTS (SELECT 1 FROM empleado_historial h
                              WHERE h.empleado_id IN (p_origen, p_destino) AND h.fuente = 'MANUAL'
                                AND h.campo IN ('rfc', 'curp', 'issemym') AND h.valor_anterior IS NOT NULL)
                THEN ARRAY['IDENTIFICADOR_EDITADO']::text[] ELSE '{}'::text[] END
$$;

-- Validación común: candados, huella, bloqueo, evidencia. Devuelve el análisis vigente y sus conflictos graves.
CREATE FUNCTION fn_fusion_validar(p_origen bigint, p_destino bigint, p_huella text, OUT o_an jsonb, OUT o_graves text[])
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(4850181374600825153);
    PERFORM 1 FROM empleados WHERE id IN (p_origen, p_destino) ORDER BY id FOR UPDATE;
    o_an := fn_fusion_analisis(p_origen, p_destino);
    IF fn_fusion_huella(o_an) IS DISTINCT FROM p_huella THEN
        RAISE EXCEPTION 'REGISTRO_MODIFICADO: los datos de los empleados cambiaron desde la previsualización'
            USING ERRCODE = 'serialization_failure';
    END IF;
    IF o_an->>'confianza' = 'BLOQUEADA' THEN
        RAISE EXCEPTION 'FUSION_BLOQUEADA' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT (o_an->>'fusionable')::boolean THEN
        RAISE EXCEPTION 'EVIDENCIA_INSUFICIENTE: el nombre por sí solo nunca fusiona' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(array_agg(x ORDER BY x), '{}') INTO o_graves
      FROM (SELECT c->>'codigo' AS x FROM jsonb_array_elements(o_an->'conflictos') c WHERE c->>'severidad' = 'GRAVE'
            UNION SELECT unnest(fn_fusion_conflictos_extra(p_origen, p_destino))) g;
END;
$$;

-- Fusión directa: SÓLO sin conflictos graves (con graves ⇒ APROBACION_REQUERIDA)
CREATE OR REPLACE FUNCTION fn_fusionar_empleados(p_origen bigint, p_destino bigint, p_usuario_id bigint, p_motivo text,
                                                 p_aceptados text[], p_huella text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v record;
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
    SELECT * INTO v FROM fn_fusion_validar(p_origen, p_destino, p_huella);
    IF cardinality(v.o_graves) > 0 THEN
        RAISE EXCEPTION 'APROBACION_REQUERIDA: una fusión con conflictos graves requiere la aprobación de una segunda persona'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN fn_fusion_aplicar(p_origen, p_destino, p_usuario_id, p_motivo, v.o_an, '{}', p_huella, NULL, NULL);
END;
$$;

CREATE FUNCTION fn_solicitar_fusion_critica(p_origen bigint, p_destino bigint, p_usuario_id bigint, p_motivo text,
                                            p_aceptados text[], p_huella text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v record;
    v_id bigint;
    v_horas integer := coalesce((SELECT valor::integer FROM configuracion WHERE clave = 'fusiones.solicitud_expira_horas'), 48);
BEGIN
    IF p_usuario_id IS NULL OR fn_actor_actual() IS DISTINCT FROM p_usuario_id THEN
        RAISE EXCEPTION 'Solicitud sin actor de sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT fn_actor_tiene_permiso('empleados.fusionar') THEN
        RAISE EXCEPTION 'Sin permiso para fusionar empleados' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 20 THEN
        RAISE EXCEPTION 'La solicitud exige un motivo de al menos 20 caracteres' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    -- Primero la evidencia (el nombre solo nunca fusiona, pida quien pida); después, quién puede aceptar conflictos graves.
    -- fn_fusion_validar toma el candado global ANTES que cualquier fila (mismo orden que la aprobación: sin interbloqueos)
    SELECT * INTO v FROM fn_fusion_validar(p_origen, p_destino, p_huella);
    PERFORM fn_solicitudes_fusion_expirar();
    IF NOT fn_actor_tiene_permiso('empleados.fusionar_conflictos') THEN
        RAISE EXCEPTION 'Sin permiso para solicitar una fusión con conflictos graves' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF cardinality(v.o_graves) = 0 THEN
        RAISE EXCEPTION 'SIN_CONFLICTOS_GRAVES: esta fusión no requiere aprobación' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT (v.o_graves <@ coalesce(p_aceptados, '{}')) THEN
        RAISE EXCEPTION 'CONFLICTOS_SIN_ACEPTAR' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO solicitudes_fusion (origen_id, destino_id, motivo, conflictos_graves, analisis, huella, solicitado_por, expira_at)
    VALUES (p_origen, p_destino, btrim(p_motivo), v.o_graves, v.o_an, p_huella, p_usuario_id,
            now() + make_interval(hours => greatest(24, least(72, v_horas))))
    RETURNING id INTO v_id;
    PERFORM fn_notificar_permiso('empleados.fusionar_conflictos_aprobar', p_usuario_id, 'FUSION_PENDIENTE_APROBACION', 'ALTA',
        'Solicitud de fusión pendiente de aprobación',
        'La solicitud #' || v_id || ' une dos empleados con ' || cardinality(v.o_graves) || ' conflicto(s) grave(s). Requiere una segunda persona.',
        '/empleados/solicitudes-fusion/' || v_id, 'solicitudes_fusion', v_id, 'solicitud_fusion:' || v_id);
    RETURN v_id;
END;
$$;

-- Aprueba y EJECUTA. Devuelve el id de la fusión, o NULL si la solicitud expiró o su análisis cambió (queda EXPIRADA/CANCELADA).
CREATE FUNCTION fn_aprobar_solicitud_fusion(p_id bigint, p_usuario_id bigint, p_motivo text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    s solicitudes_fusion;
    v_an jsonb;
    v_graves text[];
    v_fusion bigint;
BEGIN
    IF p_usuario_id IS NULL OR fn_actor_actual() IS DISTINCT FROM p_usuario_id THEN
        RAISE EXCEPTION 'Aprobación sin actor de sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT fn_actor_tiene_permiso('empleados.fusionar_conflictos_aprobar') THEN
        RAISE EXCEPTION 'Sin permiso para aprobar fusiones con conflictos graves' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 5 THEN
        RAISE EXCEPTION 'La aprobación exige un motivo' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    -- Candado global primero y después la fila (mismo orden que el resto de las fusiones)
    PERFORM pg_advisory_xact_lock(4850181374600825153);
    SELECT * INTO s FROM solicitudes_fusion WHERE id = p_id FOR UPDATE;
    IF s.id IS NULL OR s.estado <> 'PENDIENTE_APROBACION' THEN
        RAISE EXCEPTION 'SOLICITUD_NO_PENDIENTE' USING ERRCODE = 'check_violation';
    END IF;
    IF s.solicitado_por = p_usuario_id THEN
        RAISE EXCEPTION 'CUATRO_OJOS: quien solicita no puede aprobar su propia solicitud' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF s.expira_at <= now() THEN
        UPDATE solicitudes_fusion SET estado = 'EXPIRADA', decidido_at = now() WHERE id = s.id;
        PERFORM fn_notif_solicitud_decidida(s.id);
        RETURN NULL;
    END IF;
    -- El solicitante debe seguir activo Y conservar el permiso con el que solicitó (un permiso revocado no se aprueba después)
    IF NOT EXISTS (SELECT 1 FROM usuarios WHERE id = s.solicitado_por AND activo AND deleted_at IS NULL)
       OR NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id AND r.activo JOIN rol_permiso rp ON rp.rol_id = r.id
                        JOIN permisos pe ON pe.id = rp.permiso_id AND pe.codigo = 'empleados.fusionar_conflictos'
                       WHERE ur.usuario_id = s.solicitado_por) THEN
        UPDATE solicitudes_fusion SET estado = 'CANCELADA', decidido_por = p_usuario_id, decidido_at = now(),
               motivo_decision = 'El solicitante ya no está activo o ya no tiene permiso para solicitarla.' WHERE id = s.id;
        PERFORM fn_notif_solicitud_decidida(s.id);
        RETURN NULL;
    END IF;
    PERFORM 1 FROM empleados WHERE id IN (s.origen_id, s.destino_id) ORDER BY id FOR UPDATE;
    v_an := fn_fusion_analisis(s.origen_id, s.destino_id);
    SELECT coalesce(array_agg(x ORDER BY x), '{}') INTO v_graves
      FROM (SELECT c->>'codigo' AS x FROM jsonb_array_elements(v_an->'conflictos') c WHERE c->>'severidad' = 'GRAVE'
            UNION SELECT unnest(fn_fusion_conflictos_extra(s.origen_id, s.destino_id))) g;
    IF fn_fusion_huella(v_an) IS DISTINCT FROM s.huella OR v_an->>'confianza' = 'BLOQUEADA' OR NOT (v_an->>'fusionable')::boolean
       OR NOT (v_graves <@ s.conflictos_graves) THEN
        UPDATE solicitudes_fusion SET estado = 'CANCELADA', decidido_por = p_usuario_id, decidido_at = now(),
               motivo_decision = 'El análisis cambió desde la solicitud: debe solicitarse de nuevo.' WHERE id = s.id;
        PERFORM fn_notif_solicitud_decidida(s.id);
        RETURN NULL;
    END IF;
    v_fusion := fn_fusion_aplicar(s.origen_id, s.destino_id, p_usuario_id, s.motivo, v_an, s.conflictos_graves, s.huella, s.id, s.solicitado_por);
    UPDATE solicitudes_fusion SET estado = 'EJECUTADA', decidido_por = p_usuario_id, decidido_at = now(),
           motivo_decision = btrim(p_motivo), fusion_id = v_fusion WHERE id = s.id;
    PERFORM fn_notif_solicitud_decidida(s.id);
    RETURN v_fusion;
END;
$$;

-- Rechazo (aprobador distinto) o cancelación (el propio solicitante)
CREATE FUNCTION fn_decidir_solicitud_fusion(p_id bigint, p_usuario_id bigint, p_decision text, p_motivo text)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    s solicitudes_fusion;
BEGIN
    IF p_usuario_id IS NULL OR fn_actor_actual() IS DISTINCT FROM p_usuario_id THEN
        RAISE EXCEPTION 'Decisión sin actor de sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_decision NOT IN ('RECHAZADA', 'CANCELADA') THEN
        RAISE EXCEPTION 'Decisión inválida' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 5 THEN
        RAISE EXCEPTION 'La decisión exige un motivo' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    SELECT * INTO s FROM solicitudes_fusion WHERE id = p_id FOR UPDATE;
    IF s.id IS NULL OR s.estado <> 'PENDIENTE_APROBACION' THEN
        RAISE EXCEPTION 'SOLICITUD_NO_PENDIENTE' USING ERRCODE = 'check_violation';
    END IF;
    IF p_decision = 'RECHAZADA' AND (s.solicitado_por = p_usuario_id OR NOT fn_actor_tiene_permiso('empleados.fusionar_conflictos_aprobar')) THEN
        RAISE EXCEPTION 'Sólo un aprobador distinto del solicitante rechaza' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_decision = 'CANCELADA' AND s.solicitado_por <> p_usuario_id THEN
        RAISE EXCEPTION 'Sólo quien solicitó puede cancelar' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE solicitudes_fusion SET estado = p_decision, decidido_por = p_usuario_id, decidido_at = now(), motivo_decision = btrim(p_motivo)
     WHERE id = s.id;
    IF p_decision = 'RECHAZADA' THEN
        PERFORM fn_notif_solicitud_decidida(s.id);
    END IF;
END;
$$;

-- Expiración (la invoca el scheduler; también antes de solicitar). Devuelve cuántas expiraron.
CREATE FUNCTION fn_solicitudes_fusion_expirar() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r record;
    n integer := 0;
BEGIN
    FOR r IN SELECT id FROM solicitudes_fusion WHERE estado = 'PENDIENTE_APROBACION' AND expira_at <= now() FOR UPDATE SKIP LOCKED LOOP
        UPDATE solicitudes_fusion SET estado = 'EXPIRADA', decidido_at = now() WHERE id = r.id;
        PERFORM fn_notif_solicitud_decidida(r.id);
        n := n + 1;
    END LOOP;
    RETURN n;
END;
$$;

CREATE FUNCTION fn_notif_solicitud_decidida(p_id bigint) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    s solicitudes_fusion;
BEGIN
    SELECT * INTO s FROM solicitudes_fusion WHERE id = p_id;
    PERFORM fn_notificar(s.solicitado_por, 'FUSION_DECIDIDA', CASE WHEN s.estado = 'EJECUTADA' THEN 'NORMAL' ELSE 'ALTA' END,
        'Solicitud de fusión ' || CASE s.estado WHEN 'EJECUTADA' THEN 'aprobada y aplicada' WHEN 'RECHAZADA' THEN 'rechazada'
                                              WHEN 'EXPIRADA' THEN 'expirada' ELSE 'cancelada' END,
        'Solicitud #' || s.id || '.' || CASE WHEN s.estado = 'EXPIRADA' THEN ' Nadie la aprobó a tiempo; puede solicitarla de nuevo.' ELSE '' END,
        '/empleados/solicitudes-fusion/' || s.id, 'solicitudes_fusion', s.id, 'solicitud_fusion:' || s.id);
    -- La notificación pendiente de los aprobadores ya no aplica
    DELETE FROM notificaciones WHERE clave = 'solicitud_fusion:' || s.id AND tipo = 'FUSION_PENDIENTE_APROBACION';
END;
$$;

-- ---------------------------------------------------------------------
-- Roles PROTEGIDOS (ADMIN, APROBADOR…): desde la web sólo los asigna o retira un actor GOD. Antes sólo lo hacía PHP
-- (UserService); ahora también la BD (revisión de seguridad FASE 8). GOD sigue reservado a la CLI (0005).
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_usuario_rol_protegido() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_actor bigint;
BEGIN
    IF fn_es_conexion_web() AND EXISTS (SELECT 1 FROM roles WHERE protegido AND codigo <> 'GOD'
                                          AND id IN (CASE WHEN TG_OP = 'DELETE' THEN OLD.rol_id ELSE NEW.rol_id END,
                                                     CASE WHEN TG_OP = 'UPDATE' THEN OLD.rol_id END)) THEN
        v_actor := fn_actor_actual();
        IF v_actor IS NULL OR NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
                                                WHERE ur.usuario_id = v_actor AND r.codigo = 'GOD') THEN
            RAISE EXCEPTION 'Sólo GOD asigna o retira roles protegidos' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER usuario_rol_protegido BEFORE INSERT OR UPDATE OR DELETE ON usuario_rol
    FOR EACH ROW EXECUTE FUNCTION fn_usuario_rol_protegido();

-- ---------------------------------------------------------------------
-- Permiso, rol APROBADOR (protegido: sólo GOD lo asigna) y parámetro de expiración
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('empleados.fusionar_conflictos_aprobar', 'empleados',
     'Aprobar (segunda persona) fusiones con conflictos graves solicitadas por otro usuario', false, true)
ON CONFLICT (codigo) DO NOTHING;
INSERT INTO roles (codigo, nombre, descripcion, es_sistema, protegido)
VALUES ('APROBADOR', 'Aprobador de identidades',
        'Segunda firma de fusiones con conflictos graves. Se asigna además del rol habitual; sólo GOD lo asigna.', true, true)
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'empleados.fusionar_conflictos_aprobar' WHERE r.codigo IN ('GOD', 'APROBADOR')
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('dashboard.ver', 'empleados.ver', 'empleados.duplicados') WHERE r.codigo = 'APROBADOR'
ON CONFLICT DO NOTHING;
INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('fusiones.solicitud_expira_horas', '48', 'entero', 'Horas que una solicitud de fusión con conflictos graves espera su aprobación', 24, 72, true)
ON CONFLICT (clave) DO NOTHING;

REVOKE ALL ON FUNCTION fn_fusion_aplicar(bigint, bigint, bigint, text, jsonb, text[], text, bigint, bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_fusion_validar(bigint, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_fusion_conflictos_extra(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_fusion_conflictos_extra(bigint, bigint) TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_solicitar_fusion_critica(bigint, bigint, bigint, text, text[], text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_aprobar_solicitud_fusion(bigint, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_decidir_solicitud_fusion(bigint, bigint, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_solicitudes_fusion_expirar() FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_notif_solicitud_decidida(bigint) FROM PUBLIC;
GRANT SELECT ON solicitudes_fusion TO conciliarh_web;
GRANT EXECUTE ON FUNCTION fn_solicitar_fusion_critica(bigint, bigint, bigint, text, text[], text), fn_aprobar_solicitud_fusion(bigint, bigint, text),
    fn_decidir_solicitud_fusion(bigint, bigint, text, text), fn_solicitudes_fusion_expirar() TO conciliarh_web;
GRANT EXECUTE ON FUNCTION fn_solicitudes_fusion_expirar() TO conciliarh_scheduler;
