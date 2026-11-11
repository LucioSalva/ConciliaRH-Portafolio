-- =====================================================================
-- 0005 · Endurecimiento tras la revisión de seguridad de la FASE 1
--  1. Privilegios por columna (defensa en profundidad ante SQLi o errores de lógica).
--  2. El rol GOD sólo puede asignarse/retirarse por el propietario del esquema
--     (proceso CLI de setup), nunca por el rol web.
--  3. Auditoría: la cadena de hash v2 incluye user_agent y fecha clock_timestamp(); los registros previos
--     conservan su versión (v1) y siguen verificándose con su fórmula original.
--  4. Límite de intentos: por (usuario, IP) y además global por usuario.
-- =====================================================================

-- 1. Privilegios por columna ---------------------------------------------
REVOKE UPDATE ON usuarios FROM conciliarh_web;
GRANT UPDATE (nombre, password_hash, activo, debe_cambiar_password, password_cambiado_at,
              ultimo_acceso_at, deleted_at, deleted_by) ON usuarios TO conciliarh_web;

REVOKE INSERT, UPDATE ON roles FROM conciliarh_web;
GRANT INSERT (codigo, nombre, descripcion, protegido) ON roles TO conciliarh_web;
GRANT UPDATE (nombre, descripcion, activo) ON roles TO conciliarh_web;

REVOKE UPDATE ON configuracion FROM conciliarh_web;
GRANT UPDATE (valor, actualizado_por) ON configuracion TO conciliarh_web;

REVOKE UPDATE ON procesos FROM conciliarh_worker;
GRANT UPDATE (estado, etapa, progreso, mensaje, intentos, disponible_desde, bloqueado_por, token_bloqueo,
              bloqueado_at, heartbeat_at, iniciado_at, finalizado_at, resultado, error_codigo,
              error_mensaje, error_detalle) ON procesos TO conciliarh_worker;

-- 2. GOD sólo por el propietario --------------------------------------------
CREATE FUNCTION fn_usuario_rol_god_protegido() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_rol_id bigint := CASE WHEN TG_OP = 'DELETE' THEN OLD.rol_id ELSE NEW.rol_id END;
    v_owner text;
BEGIN
    IF EXISTS (SELECT 1 FROM roles WHERE id = v_rol_id AND codigo = 'GOD')
       OR (TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM roles WHERE id = OLD.rol_id AND codigo = 'GOD')) THEN
        SELECT pg_get_userbyid(relowner) INTO v_owner FROM pg_class WHERE oid = TG_RELID;
        IF current_user <> v_owner THEN
            RAISE EXCEPTION 'El rol GOD sólo se administra mediante el proceso CLI de setup'
                USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER usuario_rol_god_protegido BEFORE INSERT OR UPDATE OR DELETE ON usuario_rol
    FOR EACH ROW EXECUTE FUNCTION fn_usuario_rol_god_protegido();

-- 3. Auditoría: cadena v2 con user_agent ------------------------------------
ALTER TABLE auditoria ADD COLUMN hash_version smallint NOT NULL DEFAULT 1;
ALTER TABLE auditoria ADD CONSTRAINT auditoria_hash_version CHECK (hash_version IN (1, 2));

CREATE FUNCTION fn_auditoria_contenido_v2(a auditoria) RETURNS text
LANGUAGE sql STABLE AS $$
    SELECT concat_ws('|', 'v2', fn_auditoria_contenido(a), coalesce(a.user_agent, ''));
$$;

CREATE OR REPLACE FUNCTION fn_auditoria_encadenar() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_prev_seq  bigint;
    v_prev_hash text;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtext('conciliarh.auditoria'));
    SELECT secuencia, hash INTO v_prev_seq, v_prev_hash
      FROM auditoria ORDER BY secuencia DESC LIMIT 1;
    NEW.secuencia := coalesce(v_prev_seq, 0) + 1;
    -- clock_timestamp(): hora real al obtener el lock (coherente con el orden de secuencia),
    -- no la hora de inicio de la transacción.
    NEW.creado_at := clock_timestamp();
    NEW.hash_prev := v_prev_hash;
    NEW.hash_version := 2;
    NEW.hash := encode(sha256(convert_to(coalesce(v_prev_hash, '') || '|' || fn_auditoria_contenido_v2(NEW), 'UTF8')), 'hex');
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION fn_auditoria_verificar()
RETURNS TABLE (integra boolean, registros bigint, primer_secuencia_invalida bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r auditoria;
    v_prev text := NULL;
    v_first boolean := true;
    v_count bigint := 0;
    v_content text;
BEGIN
    FOR r IN SELECT * FROM auditoria ORDER BY secuencia LOOP
        v_count := v_count + 1;
        IF v_first THEN
            v_prev := r.hash_prev;
            v_first := false;
        END IF;
        v_content := CASE r.hash_version WHEN 2 THEN fn_auditoria_contenido_v2(r) ELSE fn_auditoria_contenido(r) END;
        IF r.hash_prev IS DISTINCT FROM v_prev
           OR r.hash <> encode(sha256(convert_to(coalesce(v_prev, '') || '|' || v_content, 'UTF8')), 'hex') THEN
            integra := false; registros := v_count; primer_secuencia_invalida := r.secuencia;
            RETURN NEXT; RETURN;
        END IF;
        v_prev := r.hash;
    END LOOP;
    integra := true; registros := v_count; primer_secuencia_invalida := NULL;
    RETURN NEXT;
END;
$$;
REVOKE ALL ON FUNCTION fn_auditoria_verificar() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_auditoria_verificar() TO conciliarh_web;

-- 4. Límites de intentos de login -------------------------------------------
ALTER TABLE intentos_login ADD COLUMN ambito text NOT NULL DEFAULT 'login';
ALTER TABLE intentos_login ADD CONSTRAINT intentos_login_ambito CHECK (ambito IN ('login', 'cambio_password'));
CREATE INDEX intentos_login_ip_ambito_idx ON intentos_login (ip, ambito, creado_at DESC);

UPDATE configuracion
   SET descripcion = 'Intentos fallidos permitidos por la pareja usuario + dirección IP dentro de la ventana antes de bloquear'
 WHERE clave = 'seguridad.login_max_intentos_usuario';
INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('seguridad.login_max_intentos_usuario_global', '20', 'entero',
     'Intentos fallidos permitidos para un usuario desde cualquier IP dentro de la ventana (ataque distribuido)', 5, 200, true),
    ('seguridad.cambio_password_max_intentos', '5', 'entero',
     'Intentos fallidos de «contraseña actual» al cambiar la propia contraseña antes de cerrar la sesión', 3, 20, true);
