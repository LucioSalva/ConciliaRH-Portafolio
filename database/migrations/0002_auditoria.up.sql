-- =====================================================================
-- 0002 · Auditoría append-only con cadena de hash (evidencia de alteración)
--
-- Garantías:
--  * Los roles de aplicación sólo pueden INSERT/SELECT (sin UPDATE/DELETE/TRUNCATE).
--  * Triggers bloquean UPDATE, DELETE y TRUNCATE incluso al propietario, salvo
--    mantenimiento técnico explícito (GUC conciliarh.auditoria_mantenimiento=on
--    ejecutado por el propietario de la tabla; ver docs/SECURITY.md).
--  * Cada registro guarda hash = SHA-256(hash_prev || contenido). Cualquier
--    alteración posterior rompe la cadena y es detectada por fn_auditoria_verificar().
--  * La secuencia se asigna bajo un advisory lock de transacción, por lo que el
--    orden de la cadena es consistente aun con inserciones concurrentes.
-- =====================================================================

CREATE TABLE auditoria (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    secuencia         bigint      NOT NULL,
    creado_at         timestamptz NOT NULL DEFAULT now(),
    usuario_id        bigint      REFERENCES usuarios (id),
    username          text,          -- instantánea (el usuario puede cambiar de nombre)
    roles             text,          -- roles efectivos al momento de la acción
    origen            text        NOT NULL DEFAULT 'WEB',
    ip                inet,
    user_agent        text,
    accion            text        NOT NULL,
    entidad           text,
    entidad_id        text,
    valor_anterior    jsonb,
    valor_nuevo       jsonb,
    contexto          jsonb,
    hash_prev         text,
    hash              text        NOT NULL,
    CONSTRAINT auditoria_origen CHECK (origen IN ('WEB', 'CLI', 'WORKER', 'SISTEMA')),
    CONSTRAINT auditoria_accion_formato CHECK (accion ~ '^[a-z][a-z_]*(\.[a-z][a-z_]*)+$'),
    CONSTRAINT auditoria_hash_formato CHECK (hash ~ '^[0-9a-f]{64}$')
);
CREATE UNIQUE INDEX auditoria_secuencia_uk ON auditoria (secuencia);
CREATE INDEX auditoria_creado_idx   ON auditoria (creado_at DESC);
CREATE INDEX auditoria_usuario_idx  ON auditoria (usuario_id, creado_at DESC);
CREATE INDEX auditoria_accion_idx   ON auditoria (accion, creado_at DESC);
CREATE INDEX auditoria_entidad_idx  ON auditoria (entidad, entidad_id);

-- Contenido canónico que se encadena (independiente de la zona horaria de sesión)
CREATE FUNCTION fn_auditoria_contenido(a auditoria) RETURNS text
LANGUAGE sql STABLE AS $$
    SELECT concat_ws('|',
        a.secuencia::text,
        floor(extract(epoch FROM a.creado_at) * 1000000)::bigint::text,
        coalesce(a.usuario_id::text, ''),
        coalesce(a.username, ''),
        coalesce(a.roles, ''),
        a.origen,
        coalesce(host(a.ip), ''),
        a.accion,
        coalesce(a.entidad, ''),
        coalesce(a.entidad_id, ''),
        coalesce(a.valor_anterior::text, ''),
        coalesce(a.valor_nuevo::text, ''),
        coalesce(a.contexto::text, '')
    );
$$;

-- SECURITY DEFINER: el worker sólo tiene INSERT, pero el trigger necesita leer
-- el último hash. search_path fijado para evitar secuestro de funciones.
CREATE FUNCTION fn_auditoria_encadenar() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_prev_seq  bigint;
    v_prev_hash text;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtext('conciliarh.auditoria'));
    SELECT secuencia, hash INTO v_prev_seq, v_prev_hash
      FROM auditoria ORDER BY secuencia DESC LIMIT 1;
    NEW.secuencia := coalesce(v_prev_seq, 0) + 1;
    NEW.creado_at := now();
    NEW.hash_prev := v_prev_hash;
    NEW.hash := encode(sha256(convert_to(coalesce(v_prev_hash, '') || '|' || fn_auditoria_contenido(NEW), 'UTF8')), 'hex');
    RETURN NEW;
END;
$$;
CREATE TRIGGER auditoria_encadenar BEFORE INSERT ON auditoria
    FOR EACH ROW EXECUTE FUNCTION fn_auditoria_encadenar();

CREATE FUNCTION fn_auditoria_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_owner text;
BEGIN
    SELECT pg_get_userbyid(relowner) INTO v_owner FROM pg_class WHERE oid = TG_RELID;
    IF current_setting('conciliarh.auditoria_mantenimiento', true) = 'on'
       AND current_user = v_owner THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'La auditoría es de sólo inserción (operación % bloqueada)', TG_OP
        USING ERRCODE = 'insufficient_privilege';
END;
$$;
CREATE TRIGGER auditoria_sin_update BEFORE UPDATE OR DELETE ON auditoria
    FOR EACH ROW EXECUTE FUNCTION fn_auditoria_inmutable();
CREATE TRIGGER auditoria_sin_truncate BEFORE TRUNCATE ON auditoria
    FOR EACH STATEMENT EXECUTE FUNCTION fn_auditoria_inmutable();

-- Verificación de integridad de la cadena.
-- El primer registro existente actúa como ancla (su hash_prev se acepta tal cual),
-- lo que permite una futura depuración documentada sin invalidar la verificación.
CREATE FUNCTION fn_auditoria_verificar()
RETURNS TABLE (integra boolean, registros bigint, primer_secuencia_invalida bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r auditoria;
    v_prev text := NULL;
    v_first boolean := true;
    v_count bigint := 0;
BEGIN
    FOR r IN SELECT * FROM auditoria ORDER BY secuencia LOOP
        v_count := v_count + 1;
        IF v_first THEN
            v_prev := r.hash_prev;
            v_first := false;
        END IF;
        IF r.hash_prev IS DISTINCT FROM v_prev
           OR r.hash <> encode(sha256(convert_to(coalesce(v_prev, '') || '|' || fn_auditoria_contenido(r), 'UTF8')), 'hex') THEN
            integra := false; registros := v_count; primer_secuencia_invalida := r.secuencia;
            RETURN NEXT; RETURN;
        END IF;
        v_prev := r.hash;
    END LOOP;
    integra := true; registros := v_count; primer_secuencia_invalida := NULL;
    RETURN NEXT;
END;
$$;

REVOKE ALL ON auditoria FROM PUBLIC;
GRANT SELECT, INSERT ON auditoria TO conciliarh_web;
GRANT INSERT ON auditoria TO conciliarh_worker;
REVOKE ALL ON FUNCTION fn_auditoria_verificar() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_auditoria_verificar() TO conciliarh_web;
