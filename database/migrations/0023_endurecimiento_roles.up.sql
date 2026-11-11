-- =====================================================================
-- 0023 — Endurecimiento de los roles de servicio y del RBAC en la BD (FASE 9, auditoría de seguridad de PostgreSQL).
--
-- El modelo de actor (0012) restringía sólo la conexión WEB; el worker (que procesa libros no confiables) y el scheduler
-- eran «servicios de confianza» sin control de autoría. Esta migración:
--   H-1  auditoría: un servicio sólo registra origen WORKER, sin usuario ni roles (no puede firmar como una persona);
--   M-1  el worker no fabrica confirmaciones manuales (cuentas, beneficiarios) ni atribuye decisiones a personas,
--        salvo trasladar la decisión manual ya existente del MISMO archivo (renormalización);
--   M-2  el worker ya no puede reescribir el cifrado de cuentas confirmadas ni el del staging;
--   M-3  RBAC: roles y permisos sólo con roles.administrar; asignar roles con usuarios.crear/editar; nadie toca
--        desde la web los permisos del rol GOD;
--   M-4  las credenciales y el estado de una cuenta GOD sólo los cambia ella misma desde la web; todo cambio de
--        contraseña, activación o baja queda auditado POR LA BD (no se puede omitir ni falsificar desde PHP);
--   M-6  configuración: permiso, exclusividad GOD, tipo y rango validados en la BD;
--   L-1…L-7 e I-1: higiene (EXECUTE de PUBLIC, permisos en funciones expuestas, cola de trabajos, registro de ejecuciones,
--        search_path fijo, deleted_by, esquema public).
-- Las reglas nuevas sólo aplican a escrituras DIRECTAS de cada rol (current_user = session_user): las funciones
-- SECURITY DEFINER (dueño) ya validan su propio actor y permisos.
-- =====================================================================

-- ---------------------------------------------------------------- utilidades
CREATE FUNCTION fn_conexion_directa(p_rol text) RETURNS boolean
LANGUAGE sql STABLE SET search_path = public, pg_temp AS $$
    SELECT current_user = session_user AND pg_has_role(session_user, p_rol, 'MEMBER')
       AND NOT coalesce((SELECT rolsuper FROM pg_roles WHERE rolname = session_user), false);
$$;
COMMENT ON FUNCTION fn_conexion_directa(text) IS
    'Verdadero si la sentencia la ejecuta directamente un rol de servicio (no dentro de una función SECURITY DEFINER del dueño).';

-- ---------------------------------------------------------------- H-1: auditoría de servicios
CREATE OR REPLACE FUNCTION fn_auditoria_actor() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE
    v_actor bigint;
BEGIN
    -- Worker y scheduler: siempre como servicio, nunca a nombre de una persona ni con origen WEB
    IF fn_conexion_directa('conciliarh_worker') OR fn_conexion_directa('conciliarh_scheduler') THEN
        IF NEW.origen <> 'WORKER' OR NEW.usuario_id IS NOT NULL OR NEW.roles IS NOT NULL THEN
            RAISE EXCEPTION 'Un servicio sólo registra auditoría de origen WORKER y sin usuario' USING ERRCODE = 'insufficient_privilege';
        END IF;
        NEW.username := CASE WHEN pg_has_role(session_user, 'conciliarh_scheduler', 'MEMBER') THEN 'scheduler' END;
        RETURN NEW;
    END IF;
    IF NOT fn_es_conexion_web() THEN
        RETURN NEW;                     -- propietario (migraciones/CLI)
    END IF;
    -- Registros que escribe la propia BD dentro de funciones SECURITY DEFINER (vincular sesión; cambios de credenciales)
    IF current_user <> session_user AND NEW.origen = 'SISTEMA'
       AND NEW.accion IN ('auth.sesion_vinculada', 'usuarios.cambio_credenciales') THEN
        RETURN NEW;
    END IF;
    IF NEW.origen <> 'WEB' THEN
        RAISE EXCEPTION 'La aplicación web sólo registra auditoría de origen WEB' USING ERRCODE = 'insufficient_privilege';
    END IF;
    v_actor := fn_actor_actual();
    IF NEW.usuario_id IS NOT NULL AND NEW.usuario_id IS DISTINCT FROM v_actor THEN
        RAISE EXCEPTION 'La autoría del registro de auditoría no corresponde al usuario de la sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NEW.usuario_id IS NULL THEN
        NEW.username := NULL;
        NEW.roles := NULL;
    ELSE
        NEW.username := (SELECT username FROM usuarios WHERE id = NEW.usuario_id);
        NEW.roles := (SELECT string_agg(r.codigo, ',' ORDER BY r.codigo) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
                       WHERE ur.usuario_id = NEW.usuario_id);
    END IF;
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------- M-1: autoría de lo que escribe el worker
CREATE FUNCTION fn_worker_sin_confirmacion_manual() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF NOT fn_conexion_directa('conciliarh_worker') THEN
        RETURN NEW;
    END IF;
    -- Alta: nunca una confirmación manual. Actualización (p. ej. ultima_vista_at de una cuenta ya confirmada por una
    -- persona): permitida mientras no cambie el origen ni quién confirmó.
    IF (TG_OP = 'INSERT' AND (NEW.origen = 'CONFIRMACION_MANUAL' OR NEW.confirmado_por IS NOT NULL))
       OR (TG_OP = 'UPDATE' AND (NEW.origen IS DISTINCT FROM OLD.origen OR NEW.confirmado_por IS DISTINCT FROM OLD.confirmado_por)) THEN
        RAISE EXCEPTION 'El worker no registra confirmaciones manuales (%.%)', TG_TABLE_NAME, NEW.origen USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cuentas_bancarias_a_servicio BEFORE INSERT OR UPDATE ON cuentas_bancarias
    FOR EACH ROW EXECUTE FUNCTION fn_worker_sin_confirmacion_manual();
CREATE TRIGGER beneficiarios_pension_a_servicio BEFORE INSERT OR UPDATE ON beneficiarios_pension
    FOR EACH ROW EXECUTE FUNCTION fn_worker_sin_confirmacion_manual();
CREATE TRIGGER beneficiario_trabajadores_a_servicio BEFORE INSERT OR UPDATE ON beneficiario_trabajadores
    FOR EACH ROW EXECUTE FUNCTION fn_worker_sin_confirmacion_manual();

-- Decisiones humanas en registros_*: el worker sólo TRASLADA una decisión manual idéntica (misma persona, estado y autor) que
-- ya existe en otro registro del mismo archivo (renormalización), y nunca reasigna una decisión existente: lo único que puede
-- hacer con una fila manual es pasarla a CONFLICTO sin persona (la cuenta resultó de otra persona) o dejarla igual.
CREATE INDEX registros_rrhh_manual_idx ON registros_rrhh (archivo_id, asociado_por) WHERE asociado_por IS NOT NULL;
CREATE INDEX registros_tesoreria_manual_idx ON registros_tesoreria (archivo_id, asociado_por) WHERE asociado_por IS NOT NULL;

CREATE FUNCTION fn_worker_asociado_por() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE
    v_ok boolean;
    v_manual text[] := ARRAY['ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO'];
BEGIN
    -- Camino rápido (la inmensa mayoría de filas de una normalización): sin autor humano y sin decisión previa
    IF NEW.asociado_por IS NULL AND (TG_OP = 'INSERT' OR NOT (OLD.asociacion_estado = ANY (v_manual))) THEN
        RETURN NEW;
    END IF;
    IF NOT fn_conexion_directa('conciliarh_worker') THEN
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.asociacion_estado = ANY (v_manual) THEN
        IF (NEW.empleado_id IS NOT DISTINCT FROM OLD.empleado_id AND NEW.asociacion_estado = OLD.asociacion_estado
            AND NEW.asociado_por IS NOT DISTINCT FROM OLD.asociado_por)
           OR (NEW.asociacion_estado = 'CONFLICTO' AND NEW.empleado_id IS NULL) THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'El worker no modifica decisiones humanas (%)', TG_TABLE_NAME USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.asociado_por IS NOT DISTINCT FROM OLD.asociado_por THEN
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME = 'registros_rrhh' THEN
        v_ok := EXISTS (SELECT 1 FROM registros_rrhh x WHERE x.archivo_id = NEW.archivo_id AND x.id <> NEW.id
                          AND x.asociado_por = NEW.asociado_por AND x.asociacion_estado = NEW.asociacion_estado
                          AND x.asociacion_estado = ANY (v_manual) AND x.empleado_id IS NOT DISTINCT FROM NEW.empleado_id);
    ELSE
        v_ok := EXISTS (SELECT 1 FROM registros_tesoreria x WHERE x.archivo_id = NEW.archivo_id AND x.id <> NEW.id
                          AND x.asociado_por = NEW.asociado_por AND x.asociacion_estado = NEW.asociacion_estado
                          AND x.asociacion_estado = ANY (v_manual) AND x.empleado_id IS NOT DISTINCT FROM NEW.empleado_id);
    END IF;
    IF NOT v_ok THEN
        RAISE EXCEPTION 'El worker sólo traslada decisiones manuales idénticas del mismo archivo (%)', TG_TABLE_NAME
            USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER registros_rrhh_a_servicio BEFORE INSERT OR UPDATE ON registros_rrhh
    FOR EACH ROW EXECUTE FUNCTION fn_worker_asociado_por();
CREATE TRIGGER registros_tesoreria_a_servicio BEFORE INSERT OR UPDATE ON registros_tesoreria
    FOR EACH ROW EXECUTE FUNCTION fn_worker_asociado_por();

-- Historial del maestro: el worker sólo registra lo que viene de un tabulado (nunca un cambio «MANUAL» a nombre de alguien;
-- fn_fusion_conflictos_extra trata los MANUAL de identificadores como conflicto grave)
CREATE FUNCTION fn_worker_empleado_historial() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF fn_conexion_directa('conciliarh_worker') AND (NEW.fuente NOT IN ('ALTA_RRHH', 'RRHH_TABULADO') OR NEW.usuario_id IS NOT NULL) THEN
        RAISE EXCEPTION 'El worker sólo registra cambios de tabulado en el historial del empleado' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER empleado_historial_a_servicio BEFORE INSERT ON empleado_historial
    FOR EACH ROW EXECUTE FUNCTION fn_worker_empleado_historial();

-- historial: el worker sólo atribuye a una persona el TRASLADO de una decisión que el registro ya tiene
CREATE FUNCTION fn_worker_historial_autoria() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE
    v_ok boolean;
BEGIN
    IF NOT fn_conexion_directa('conciliarh_worker') OR NEW.usuario_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.registro_tipo = 'RRHH' THEN
        v_ok := EXISTS (SELECT 1 FROM registros_rrhh r WHERE r.id = NEW.registro_id AND r.asociado_por = NEW.usuario_id);
    ELSE
        v_ok := EXISTS (SELECT 1 FROM registros_tesoreria r WHERE r.id = NEW.registro_id AND r.asociado_por = NEW.usuario_id);
    END IF;
    IF NEW.accion <> 'TRASLADO' OR NOT v_ok THEN
        RAISE EXCEPTION 'El worker no atribuye decisiones a personas' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER asociaciones_historial_a_servicio BEFORE INSERT ON asociaciones_historial
    FOR EACH ROW EXECUTE FUNCTION fn_worker_historial_autoria();

-- ---------------------------------------------------------------- M-2: privilegios sobrantes del worker
REVOKE UPDATE (cuenta_cifrada, key_id) ON cuentas_bancarias FROM conciliarh_worker;
REVOKE UPDATE (cuenta_cifrada, cuenta_key_id) ON staging_registros FROM conciliarh_worker;

-- ---------------------------------------------------------------- M-3: RBAC validado en la BD
CREATE FUNCTION fn_rbac_web() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE
    v_god bigint := (SELECT id FROM roles WHERE codigo = 'GOD');
BEGIN
    IF NOT fn_conexion_directa('conciliarh_web') THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME IN ('roles', 'rol_permiso') THEN
        IF NOT fn_actor_tiene_permiso('roles.administrar') THEN
            RAISE EXCEPTION 'Sin permiso para administrar roles' USING ERRCODE = 'insufficient_privilege';
        END IF;
        -- (IF anidados: PL/pgSQL resuelve NEW.rol_id al planear la expresión aunque la tabla sea «roles»)
        IF TG_TABLE_NAME = 'rol_permiso' THEN
            IF TG_OP <> 'DELETE' THEN
                IF NEW.rol_id = v_god THEN
                    RAISE EXCEPTION 'Los permisos del rol GOD no se modifican desde la aplicación' USING ERRCODE = 'insufficient_privilege';
                END IF;
            END IF;
            IF TG_OP <> 'INSERT' THEN
                IF OLD.rol_id = v_god THEN
                    RAISE EXCEPTION 'Los permisos del rol GOD no se modifican desde la aplicación' USING ERRCODE = 'insufficient_privilege';
                END IF;
            END IF;
        END IF;
    ELSE  -- usuario_rol
        IF NOT (fn_actor_tiene_permiso('usuarios.editar') OR (TG_OP = 'INSERT' AND fn_actor_tiene_permiso('usuarios.crear'))) THEN
            RAISE EXCEPTION 'Sin permiso para asignar roles' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER roles_rbac_web BEFORE INSERT OR UPDATE OR DELETE ON roles FOR EACH ROW EXECUTE FUNCTION fn_rbac_web();
CREATE TRIGGER rol_permiso_rbac_web BEFORE INSERT OR UPDATE OR DELETE ON rol_permiso FOR EACH ROW EXECUTE FUNCTION fn_rbac_web();
CREATE TRIGGER usuario_rol_rbac_web BEFORE INSERT OR UPDATE OR DELETE ON usuario_rol FOR EACH ROW EXECUTE FUNCTION fn_rbac_web();

-- ---------------------------------------------------------------- M-4: cuentas GOD y auditoría de credenciales
CREATE FUNCTION fn_usuario_god_protegido() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF NOT fn_conexion_directa('conciliarh_web')
       OR NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = OLD.id AND r.codigo = 'GOD') THEN
        RETURN NEW;
    END IF;
    IF (NEW.password_hash IS DISTINCT FROM OLD.password_hash OR NEW.activo IS DISTINCT FROM OLD.activo
        OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at OR NEW.debe_cambiar_password IS DISTINCT FROM OLD.debe_cambiar_password
        OR NEW.username IS DISTINCT FROM OLD.username)
       AND fn_actor_actual() IS DISTINCT FROM OLD.id THEN
        RAISE EXCEPTION 'Las credenciales y el estado de una cuenta GOD sólo los cambia ella misma (o la CLI)'
            USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER usuarios_god_protegido BEFORE UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION fn_usuario_god_protegido();

-- Registro generado por la BD: no depende de que PHP audite (nunca incluye el hash ni la contraseña)
CREATE FUNCTION fn_usuario_credenciales_auditar() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_campos text[] := array_remove(ARRAY[
        CASE WHEN NEW.password_hash IS DISTINCT FROM OLD.password_hash THEN 'password' END,
        CASE WHEN NEW.activo IS DISTINCT FROM OLD.activo THEN 'activo' END,
        CASE WHEN NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN 'baja' END,
        CASE WHEN NEW.debe_cambiar_password IS DISTINCT FROM OLD.debe_cambiar_password THEN 'debe_cambiar_password' END,
        CASE WHEN NEW.username IS DISTINCT FROM OLD.username THEN 'usuario' END], NULL);
    v_actor bigint := fn_actor_actual();
BEGIN
    IF cardinality(v_campos) = 0 THEN
        RETURN NULL;
    END IF;
    INSERT INTO auditoria (usuario_id, username, origen, accion, entidad, entidad_id, valor_anterior, valor_nuevo, contexto, hash)
    VALUES (v_actor, (SELECT username FROM usuarios WHERE id = v_actor), 'SISTEMA', 'usuarios.cambio_credenciales', 'usuarios', NEW.id::text,
            jsonb_build_object('activo', OLD.activo, 'baja', OLD.deleted_at IS NOT NULL),
            jsonb_build_object('activo', NEW.activo, 'baja', NEW.deleted_at IS NOT NULL),
            jsonb_build_object('campos', to_jsonb(v_campos), 'conexion', session_user::text), repeat('0', 64));
    RETURN NULL;
END;
$$;
REVOKE ALL ON FUNCTION fn_usuario_credenciales_auditar() FROM PUBLIC;
CREATE TRIGGER usuarios_credenciales_auditar AFTER UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION fn_usuario_credenciales_auditar();

-- La vinculación de sesión indica si la cuenta tiene un rol protegido (para revisar accesos privilegiados)
CREATE OR REPLACE FUNCTION fn_sesion_vincular(p_token text, p_usuario_id bigint, p_ip inet, p_user_agent text)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_hash text := fn_token_hash(p_token);
    v_ok   text;
BEGIN
    IF v_hash IS NULL THEN
        RAISE EXCEPTION 'Token de sesión con formato inválido' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM usuarios WHERE id = p_usuario_id AND activo AND deleted_at IS NULL) THEN
        RAISE EXCEPTION 'Usuario inexistente o inactivo' USING ERRCODE = 'insufficient_privilege';
    END IF;
    PERFORM set_config('conciliarh.sesion_vinculando', v_hash, true);
    INSERT INTO sesiones (id, usuario_id, ip, user_agent) VALUES (v_hash, p_usuario_id, p_ip, left(p_user_agent, 300))
    ON CONFLICT (id) DO UPDATE SET usuario_id = EXCLUDED.usuario_id, ultima_actividad_at = now()
     WHERE sesiones.revocada_at IS NULL AND (sesiones.usuario_id IS NULL OR sesiones.usuario_id = EXCLUDED.usuario_id)
    RETURNING id INTO v_ok;
    PERFORM set_config('conciliarh.sesion_vinculando', '', true);
    IF v_ok IS NULL THEN
        RAISE EXCEPTION 'La sesión ya está vinculada a otro usuario o fue revocada' USING ERRCODE = 'insufficient_privilege';
    END IF;
    PERFORM set_config('conciliarh.actor_token', p_token, false);
    INSERT INTO auditoria (usuario_id, origen, ip, user_agent, accion, entidad, entidad_id, contexto, hash)
    VALUES (p_usuario_id, 'SISTEMA', p_ip, left(p_user_agent, 300), 'auth.sesion_vinculada', 'usuarios', p_usuario_id::text,
            jsonb_build_object('sesion', left(v_hash, 12),
                               'rol_protegido', EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
                                                         WHERE ur.usuario_id = p_usuario_id AND (r.protegido OR r.codigo = 'GOD'))),
            repeat('0', 64));
END;
$$;

-- ---------------------------------------------------------------- M-6: configuración validada en la BD
CREATE FUNCTION fn_configuracion_validar() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE
    v_num numeric;
BEGIN
    IF fn_conexion_directa('conciliarh_web') THEN
        IF NOT fn_actor_tiene_permiso('configuracion.editar') THEN
            RAISE EXCEPTION 'Sin permiso para editar la configuración' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF OLD.solo_god AND NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
                                         WHERE ur.usuario_id = fn_actor_actual() AND r.codigo = 'GOD') THEN
            RAISE EXCEPTION 'El parámetro % sólo lo cambia GOD', OLD.clave USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF NEW.clave IS DISTINCT FROM OLD.clave OR NEW.tipo IS DISTINCT FROM OLD.tipo OR NEW.minimo IS DISTINCT FROM OLD.minimo
           OR NEW.maximo IS DISTINCT FROM OLD.maximo OR NEW.solo_god IS DISTINCT FROM OLD.solo_god THEN
            RAISE EXCEPTION 'Sólo el valor de un parámetro es editable' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    -- Tipo y rango para cualquier conexión: un valor inválido rompería funciones que lo convierten (p. ej. ::integer)
    IF NEW.tipo = 'entero' AND NEW.valor !~ '^-?[0-9]{1,15}$' THEN
        RAISE EXCEPTION 'El parámetro % requiere un entero', NEW.clave USING ERRCODE = 'check_violation';
    ELSIF NEW.tipo = 'decimal' AND NEW.valor !~ '^-?[0-9]{1,15}(\.[0-9]{1,6})?$' THEN
        RAISE EXCEPTION 'El parámetro % requiere un número decimal', NEW.clave USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.tipo IN ('entero', 'decimal') THEN
        v_num := NEW.valor::numeric;
        IF (NEW.minimo IS NOT NULL AND v_num < NEW.minimo) OR (NEW.maximo IS NOT NULL AND v_num > NEW.maximo) THEN
            RAISE EXCEPTION 'El parámetro % está fuera de su rango [%, %]', NEW.clave, NEW.minimo, NEW.maximo USING ERRCODE = 'check_violation';
        END IF;
    ELSIF char_length(NEW.valor) > 1000 THEN
        RAISE EXCEPTION 'El parámetro % es demasiado largo', NEW.clave USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER configuracion_validar BEFORE UPDATE ON configuracion FOR EACH ROW EXECUTE FUNCTION fn_configuracion_validar();

-- ---------------------------------------------------------------- L-1: funciones de trigger sin EXECUTE de PUBLIC
REVOKE ALL ON FUNCTION fn_auditoria_encadenar(), fn_proceso_a_archivo(), fn_archivo_encolar_analisis(),
    fn_archivo_encolar_normalizacion(), fn_archivo_invalidar_importacion(), fn_proceso_a_conciliacion() FROM PUBLIC;

-- ---------------------------------------------------------------- L-2: confirmar cuenta (roles activos, actor explícito)
CREATE OR REPLACE FUNCTION fn_confirmar_cuenta(p_registro_id bigint, p_usuario_id bigint, p_motivo text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r        registros_tesoreria;
    v_uso    text;
    v_cuenta cuentas_bancarias;
    v_id     bigint;
BEGIN
    IF fn_es_conexion_web() AND p_usuario_id IS DISTINCT FROM fn_actor_actual() THEN
        RAISE EXCEPTION 'El usuario no corresponde a la sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    PERFORM pg_advisory_xact_lock(4850181374600825153);
    SELECT * INTO r FROM registros_tesoreria WHERE id = p_registro_id FOR UPDATE;
    IF r.id IS NULL THEN
        RAISE EXCEPTION 'El registro no existe' USING ERRCODE = 'no_data_found';
    END IF;
    IF r.asociacion_estado <> 'ASOCIADO_MANUAL' OR r.empleado_id IS NULL
       OR r.asociado_por IS DISTINCT FROM p_usuario_id OR r.asociado_at IS DISTINCT FROM now() THEN
        RAISE EXCEPTION 'La cuenta sólo se confirma junto con la asociación manual del mismo usuario' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.id = p_usuario_id AND u.activo AND u.deleted_at IS NULL)
       OR NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles ro ON ro.id = ur.rol_id AND ro.activo
                        LEFT JOIN rol_permiso rp ON rp.rol_id = ur.rol_id LEFT JOIN permisos pe ON pe.id = rp.permiso_id
                       WHERE ur.usuario_id = p_usuario_id AND (ro.codigo = 'GOD' OR pe.codigo = 'cuentas.confirmar')) THEN
        RAISE EXCEPTION 'El usuario no puede confirmar cuentas' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM importaciones_normalizadas n JOIN periodos_nomina pe ON pe.id = n.periodo_id
                     JOIN lotes_importacion l ON l.id = n.lote_id
                    WHERE n.id = r.importacion_id AND n.estado = 'VIGENTE' AND pe.estado = 'ABIERTO' AND l.estado <> 'ANULADO') THEN
        RAISE EXCEPTION 'Importación no vigente, periodo cerrado o lote anulado' USING ERRCODE = 'check_violation';
    END IF;
    IF r.cuenta_hmac IS NULL OR r.cuenta_cifrada IS NULL THEN
        RAISE EXCEPTION 'El registro no tiene una cuenta utilizable' USING ERRCODE = 'check_violation';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 5 THEN
        RAISE EXCEPTION 'El motivo es obligatorio' USING ERRCODE = 'check_violation';
    END IF;
    v_uso := CASE WHEN r.categoria = 'PENSION' THEN 'PENSION_BENEFICIARIO' ELSE 'NOMINA' END;
    SELECT * INTO v_cuenta FROM cuentas_bancarias WHERE cuenta_hmac = r.cuenta_hmac AND uso = v_uso AND estado = 'ACTIVA' FOR UPDATE;
    IF v_cuenta.id IS NOT NULL THEN
        IF fn_empleado_canonico(v_cuenta.empleado_id) <> fn_empleado_canonico(r.empleado_id) THEN
            RAISE EXCEPTION 'La cuenta ya está confirmada para otra persona' USING ERRCODE = 'unique_violation';
        END IF;
        RETURN v_cuenta.id;
    END IF;
    INSERT INTO cuentas_bancarias (empleado_id, uso, beneficiario_nombre, tipo, cuenta_hmac, cuenta_ultimos4, cuenta_cifrada, key_id,
                                   banco, origen, origen_registro_id, confirmado_por, confirmado_at, confirmacion_motivo,
                                   primer_periodo_id, ultimo_periodo_id)
    VALUES (r.empleado_id, v_uso, CASE WHEN r.categoria = 'PENSION' THEN r.nombre_original END,
            coalesce(r.tipo_cuenta, 'CUENTA'), r.cuenta_hmac, r.cuenta_ultimos4, r.cuenta_cifrada, r.cuenta_key_id,
            coalesce(r.banco_receptor, r.banco), 'CONFIRMACION_MANUAL', r.id, p_usuario_id, now(), btrim(p_motivo),
            r.periodo_id, r.periodo_id)
    RETURNING id INTO v_id;
    RETURN v_id;
END;
$$;

-- ---------------------------------------------------------------- L-3: permiso en funciones expuestas a la web
CREATE OR REPLACE FUNCTION fn_crear_lote(p_periodo_id bigint, p_nombre text, p_descripcion text, p_origen text, p_observaciones text,
                                         p_usuario_id bigint)
RETURNS lotes_importacion
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_numero integer;
    v_lote lotes_importacion;
BEGIN
    IF fn_es_conexion_web() AND (p_usuario_id IS DISTINCT FROM fn_actor_actual() OR NOT fn_actor_tiene_permiso('lotes.crear')) THEN
        RAISE EXCEPTION 'Sin permiso para crear lotes' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE periodos_nomina SET ultimo_numero_lote = ultimo_numero_lote + 1
     WHERE id = p_periodo_id AND estado = 'ABIERTO'
     RETURNING ultimo_numero_lote INTO v_numero;
    IF v_numero IS NULL THEN
        RAISE EXCEPTION 'El periodo no existe o no está abierto' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO lotes_importacion (periodo_id, numero, nombre, descripcion, origen, observaciones, creado_por)
    VALUES (p_periodo_id, v_numero,
            coalesce(nullif(btrim(p_nombre), ''), 'Carga ' || lpad(v_numero::text, 3, '0')),
            nullif(btrim(p_descripcion), ''), nullif(btrim(p_origen), ''), nullif(btrim(p_observaciones), ''),
            p_usuario_id)
    RETURNING * INTO v_lote;
    RETURN v_lote;
END;
$$;

CREATE OR REPLACE FUNCTION fn_importaciones_con_cuenta(p_registro_id bigint)
RETURNS SETOF bigint
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF fn_es_conexion_web() AND NOT fn_actor_tiene_permiso('asociaciones.resolver') THEN
        RAISE EXCEPTION 'Sin permiso' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN QUERY
    SELECT DISTINCT t.importacion_id
      FROM registros_tesoreria r
      JOIN cuentas_bancarias c ON c.origen_registro_id = r.id AND c.origen = 'CONFIRMACION_MANUAL' AND c.estado = 'ACTIVA'
      JOIN registros_tesoreria t ON t.cuenta_hmac = r.cuenta_hmac AND t.id <> r.id
      JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
      JOIN periodos_nomina pe ON pe.id = n.periodo_id AND pe.estado = 'ABIERTO'
     WHERE r.id = p_registro_id AND r.cuenta_hmac IS NOT NULL
       AND t.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');
END;
$$;

-- Lanza error (nunca devuelve «sin conflictos» a quien no puede verlos: la validación de fusiones depende de ella)
CREATE OR REPLACE FUNCTION fn_fusion_conflictos_extra(p_origen bigint, p_destino bigint)
RETURNS text[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF fn_es_conexion_web() AND NOT (fn_actor_tiene_permiso('empleados.duplicados') OR fn_actor_tiene_permiso('empleados.fusionar')
                                     OR fn_actor_tiene_permiso('empleados.fusionar_conflictos_aprobar')) THEN
        RAISE EXCEPTION 'Sin permiso' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN CASE WHEN EXISTS (SELECT 1 FROM empleado_historial h
                              WHERE h.empleado_id IN (p_origen, p_destino) AND h.fuente = 'MANUAL'
                                AND h.campo IN ('rfc', 'curp', 'issemym') AND h.valor_anterior IS NOT NULL)
                THEN ARRAY['IDENTIFICADOR_EDITADO']::text[] ELSE '{}'::text[] END;
END;
$$;

-- ---------------------------------------------------------------- L-4: cola de trabajos (web y worker)
CREATE FUNCTION fn_procesos_web() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF NOT fn_conexion_directa('conciliarh_web') THEN
        RETURN NEW;
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.tipo NOT IN ('DIAGNOSTICO', 'VERIFICAR_ARCHIVO', 'ANALIZAR_ARCHIVO', 'NORMALIZAR_REGISTROS', 'DETECTAR_DUPLICADOS')
           OR NEW.creado_por IS NULL OR NEW.creado_por IS DISTINCT FROM fn_actor_actual() OR NEW.estado <> 'PENDIENTE' THEN
            RAISE EXCEPTION 'La aplicación web no puede encolar el trabajo %', NEW.tipo USING ERRCODE = 'insufficient_privilege';
        END IF;
    ELSIF NOT (OLD.estado = 'PENDIENTE' AND NEW.estado = 'CANCELADO') THEN
        RAISE EXCEPTION 'La aplicación web sólo cancela trabajos pendientes' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER procesos_web BEFORE INSERT OR UPDATE ON procesos FOR EACH ROW EXECUTE FUNCTION fn_procesos_web();

CREATE OR REPLACE FUNCTION fn_procesos_consumidor() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF pg_has_role(session_user, 'conciliarh_scheduler', 'MEMBER') AND current_user = session_user
       AND OLD.tipo NOT IN ('GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS') THEN
        RAISE EXCEPTION 'El scheduler sólo atiende trabajos de reportes' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF pg_has_role(session_user, 'conciliarh_worker', 'MEMBER') AND current_user = session_user
       AND OLD.tipo IN ('GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS') THEN
        RAISE EXCEPTION 'El worker no atiende trabajos de reportes' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------- L-5: registro de ejecuciones por rol
CREATE OR REPLACE FUNCTION fn_sistema_ejecucion(p_tipo text, p_origen text, p_inicio timestamptz, p_resultado text, p_detalle jsonb)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_id bigint;
BEGIN
    IF fn_es_conexion_web() THEN
        IF NOT ((p_origen = 'WEB' AND p_tipo = 'ALMACENAMIENTO' AND fn_actor_actual() IS NOT NULL AND fn_actor_tiene_permiso('sistema.mantenimiento'))
                OR (p_origen = 'CLI' AND p_tipo = 'ALMACENAMIENTO' AND fn_actor_actual() IS NULL)) THEN
            RAISE EXCEPTION 'Registro de ejecución no permitido desde la web' USING ERRCODE = 'insufficient_privilege';
        END IF;
    ELSIF pg_has_role(session_user, 'conciliarh_scheduler', 'MEMBER') AND p_tipo NOT IN ('SCHEDULER', 'MANTENIMIENTO') THEN
        RAISE EXCEPTION 'El scheduler sólo registra sus propias ejecuciones' USING ERRCODE = 'insufficient_privilege';
    ELSIF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'conciliarh_backup') THEN
        -- (anidado: pg_has_role con un rol inexistente es un error y AND no garantiza el orden de evaluación)
        IF pg_has_role(session_user, 'conciliarh_backup', 'MEMBER') AND p_tipo <> 'RESPALDO' THEN
            RAISE EXCEPTION 'El respaldo sólo registra respaldos' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    INSERT INTO sistema_ejecuciones (tipo, origen, usuario_id, inicio_at, resultado, detalle)
    VALUES (p_tipo, p_origen, CASE WHEN p_origen = 'WEB' THEN fn_actor_actual() END, p_inicio, p_resultado, coalesce(p_detalle, '{}'::jsonb))
    RETURNING id INTO v_id;
    DELETE FROM sistema_ejecuciones WHERE tipo = p_tipo AND id NOT IN
        (SELECT id FROM sistema_ejecuciones WHERE tipo = p_tipo ORDER BY id DESC LIMIT 500);
    RETURN v_id;
END;
$$;

-- ---------------------------------------------------------------- L-6: search_path fijo en funciones plpgsql invoker
DO $$
DECLARE f regprocedure;
BEGIN
    FOR f IN SELECT p.oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang
              WHERE n.nspname = 'public' AND NOT p.prosecdef AND l.lanname = 'plpgsql' AND p.proconfig IS NULL LOOP
        EXECUTE format('ALTER FUNCTION %s SET search_path = public, pg_temp', f);
    END LOOP;
END $$;

-- ---------------------------------------------------------------- L-7: deleted_by también es autoría verificada
DROP TRIGGER usuarios_actor ON usuarios;
CREATE TRIGGER usuarios_actor BEFORE INSERT OR UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'deleted_by');

-- ---------------------------------------------------------------- I-1: el esquema public no es de PUBLIC
REVOKE ALL ON SCHEMA public FROM PUBLIC;
