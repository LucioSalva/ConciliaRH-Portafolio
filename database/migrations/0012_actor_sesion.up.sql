-- =====================================================================
-- 0012 · Actor no suplantable (corrección de seguridad previa a la FASE 5).
--
-- Problema (FS4-10): el rol web podía escribir cualquier usuario en columnas de autoría
-- (creado_por, confirmado_por, resuelto_por, usuario_id de historiales/auditoría…). Un error de PHP
-- o un parámetro HTTP podía atribuir una acción a otra persona.
--
-- Solución (docs/SECURITY.md §2e):
--   * El ACTOR de una conexión web se deriva de la SESIÓN AUTENTICADA. PHP entrega a PostgreSQL el
--     token crudo de la cookie de sesión (`set_config('conciliarh.actor_token', …)` con parámetro
--     enlazado, sin concatenación). La BD sólo conoce SHA-256(token) en `sesiones`: conocer la tabla
--     no permite obtener el token de otra persona, así que no se puede "elegir" un actor.
--   * `fn_actor_actual()` resuelve el usuario de esa sesión (vigente, no revocada, usuario activo).
--   * Triggers `*_actor` exigen que toda columna de autoría escrita por una conexión del rol web
--     (session_user miembro de conciliarh_web; cubre también funciones SECURITY DEFINER invocadas
--     por la web) sea exactamente el actor de la sesión. La auditoría web toma usuario, nombre y roles
--     de la BD, no de PHP.
--   * La vinculación sesión → usuario sólo ocurre en `fn_sesion_vincular` (al iniciar sesión, tras la
--     verificación de contraseña en PHP), una única vez por sesión y con registro en auditoría. El rol
--     web ya no puede escribir `sesiones.usuario_id` directamente.
-- 0001–0011 no se editan.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Resolución del actor
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_token_hash(p_token text) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_token ~ '^[0-9a-f]{64}$' THEN encode(sha256(convert_to(p_token, 'UTF8')), 'hex') END;
$$;

-- Usuario de la sesión presentada por la conexión (NULL si no hay sesión autenticada válida).
-- 12 h es un tope de BD más amplio que la expiración absoluta de la aplicación (SESSION_ABSOLUTE_HOURS).
CREATE FUNCTION fn_actor_actual() RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT s.usuario_id
      FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id
     WHERE s.id = fn_token_hash(current_setting('conciliarh.actor_token', true))
       AND s.revocada_at IS NULL AND s.ultima_actividad_at > now() - interval '12 hours'
       AND u.activo AND u.deleted_at IS NULL;
$$;

CREATE FUNCTION fn_es_conexion_web() RETURNS boolean
LANGUAGE sql STABLE AS $$
    SELECT pg_has_role(session_user, 'conciliarh_web', 'MEMBER')
       AND NOT coalesce((SELECT rolsuper FROM pg_roles WHERE rolname = session_user), false);
$$;

-- Vincula la sesión (token crudo) al usuario autenticado. Única vía para fijar sesiones.usuario_id desde la
-- web: una sesión se vincula una sola vez; no se re-vincula a otra persona. Deja la conexión con ese actor.
CREATE FUNCTION fn_sesion_vincular(p_token text, p_usuario_id bigint, p_ip inet, p_user_agent text)
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
            jsonb_build_object('sesion', left(v_hash, 12)), repeat('0', 64));
END;
$$;

-- ---------------------------------------------------------------------
-- Reglas de sesiones: el rol web no vincula usuarios por su cuenta
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_sesion_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- La marca de vinculación sólo vale dentro de fn_sesion_vincular (SECURITY DEFINER: current_user ≠ session_user);
    -- un cliente que fije la misma variable desde su sesión no obtiene la excepción.
    IF NOT fn_es_conexion_web()
       OR (current_user <> session_user AND current_setting('conciliarh.sesion_vinculando', true) = NEW.id) THEN
        RETURN NEW;
    END IF;
    IF TG_OP = 'INSERT' AND NEW.usuario_id IS NOT NULL THEN
        RAISE EXCEPTION 'Una sesión sólo se vincula a un usuario con fn_sesion_vincular' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.usuario_id IS DISTINCT FROM OLD.usuario_id OR NEW.id <> OLD.id) THEN
        RAISE EXCEPTION 'El usuario de una sesión no se modifica' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sesiones_reglas BEFORE INSERT OR UPDATE ON sesiones
    FOR EACH ROW EXECUTE FUNCTION fn_sesion_reglas();

-- ---------------------------------------------------------------------
-- Verificación genérica de columnas de autoría (argumentos = nombres de columna)
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_actor_verificar() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_actor bigint;
    v_nuevo text;
    i       integer;
BEGIN
    IF NOT fn_es_conexion_web() THEN
        RETURN NEW;                     -- propietario (migraciones/CLI) y worker: servicios de confianza
    END IF;
    v_actor := fn_actor_actual();
    FOR i IN 0 .. TG_NARGS - 1 LOOP
        v_nuevo := to_jsonb(NEW) ->> TG_ARGV[i];
        IF v_nuevo IS NOT NULL
           AND (TG_OP = 'INSERT' OR v_nuevo IS DISTINCT FROM (to_jsonb(OLD) ->> TG_ARGV[i]))
           AND v_nuevo::bigint IS DISTINCT FROM v_actor THEN
            RAISE EXCEPTION 'La autoría (%.%) no corresponde al usuario de la sesión', TG_TABLE_NAME, TG_ARGV[i]
                USING ERRCODE = 'insufficient_privilege';
        END IF;
    END LOOP;
    RETURN NEW;
END;
$$;

CREATE TRIGGER archivos_importados_actor BEFORE INSERT OR UPDATE ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_carga_id', 'anulado_por', 'seleccion_manual_por');
CREATE TRIGGER asociaciones_historial_actor BEFORE INSERT ON asociaciones_historial
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_id');
CREATE TRIGGER conceptos_nomina_actor BEFORE INSERT OR UPDATE ON conceptos_nomina
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('actualizado_por');
CREATE TRIGGER configuracion_actor BEFORE INSERT OR UPDATE ON configuracion
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('actualizado_por');
CREATE TRIGGER cuentas_bancarias_actor BEFORE INSERT OR UPDATE ON cuentas_bancarias
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('confirmado_por', 'desactivado_por');
CREATE TRIGGER empleado_historial_actor BEFORE INSERT ON empleado_historial
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_id');
CREATE TRIGGER empleados_actor BEFORE INSERT OR UPDATE ON empleados
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('actualizado_por');
CREATE TRIGGER incidencias_identidad_actor BEFORE INSERT OR UPDATE ON incidencias_identidad
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('resuelto_por');
CREATE TRIGGER lotes_importacion_actor BEFORE INSERT OR UPDATE ON lotes_importacion
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'cerrado_por', 'anulado_por', 'declarado_oficial_por');
CREATE TRIGGER lotes_relaciones_actor BEFORE INSERT OR UPDATE ON lotes_relaciones
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'anulado_por');
CREATE TRIGGER periodos_nomina_actor BEFORE INSERT OR UPDATE ON periodos_nomina
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'cerrado_por', 'anulado_por');
CREATE TRIGGER procesos_actor BEFORE INSERT OR UPDATE ON procesos
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'cancelado_por');
CREATE TRIGGER registros_rrhh_actor BEFORE UPDATE ON registros_rrhh
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('asociado_por');
CREATE TRIGGER registros_tesoreria_actor BEFORE UPDATE ON registros_tesoreria
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('asociado_por');
CREATE TRIGGER rol_permiso_actor BEFORE INSERT OR UPDATE ON rol_permiso
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('asignado_por');
CREATE TRIGGER sesiones_actor BEFORE INSERT OR UPDATE ON sesiones
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('revocada_por');
CREATE TRIGGER usuario_rol_actor BEFORE INSERT OR UPDATE ON usuario_rol
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('asignado_por');
CREATE TRIGGER usuarios_actor BEFORE INSERT OR UPDATE ON usuarios
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por');

-- ---------------------------------------------------------------------
-- Auditoría web: el actor, su nombre y sus roles los fija la BD
-- (nombre del trigger: se ejecuta antes que auditoria_encadenar)
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_auditoria_actor() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_actor bigint;
BEGIN
    IF NOT fn_es_conexion_web() THEN
        RETURN NEW;
    END IF;
    -- Dentro de fn_sesion_vincular (SECURITY DEFINER) el registro lo escribe la propia BD
    IF current_user <> session_user AND NEW.origen = 'SISTEMA' AND NEW.accion = 'auth.sesion_vinculada' THEN
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
CREATE TRIGGER auditoria_actor BEFORE INSERT ON auditoria
    FOR EACH ROW EXECUTE FUNCTION fn_auditoria_actor();

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
REVOKE ALL ON FUNCTION fn_actor_actual() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_actor_actual() TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_sesion_vincular(text, bigint, inet, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_sesion_vincular(text, bigint, inet, text) TO conciliarh_web;
-- La web ya no escribe el usuario de una sesión directamente (sólo por fn_sesion_vincular): el privilegio
-- de tabla se sustituye por privilegios de columna sin `usuario_id`.
REVOKE INSERT, UPDATE ON sesiones FROM conciliarh_web;
GRANT INSERT (id, datos, ip, user_agent) ON sesiones TO conciliarh_web;
GRANT UPDATE (datos, ultima_actividad_at, revocada_at, revocada_por, motivo_revocacion) ON sesiones TO conciliarh_web;
