-- 0016 · FASE 7: rotación de la llave HMAC de cuentas/CLABE (docs/HMAC_ROTATION.md).
--
-- El HMAC de una cuenta es una CLAVE DE BÚSQUEDA: se compara por igualdad entre staging, registros de Tesorería y
-- cuentas_bancarias (matching, aprendizaje de CLABE, índice único de cuenta activa). Si unas filas usaran la llave nueva y
-- otras la anterior, dejarían de coincidir. Por eso NO se rota fila por fila sobre la columna viva:
--   1. PREPARACIÓN (reanudable, por lotes): la CLI calcula la huella con la llave nueva en una columna SOMBRA
--      (cuenta_hmac_nueva) mientras la columna viva sigue con la llave anterior: el matching no cambia.
--   2. CORTE (atómico): bajo el candado global del worker y con las tres tablas bloqueadas para ESCRITURA (las lecturas
--      siguen), completa lo que falte, verifica que la correspondencia anterior→nueva sea biyectiva (sin colisiones ni
--      inconsistencias) y en UNA transacción cambia la llave activa y copia la sombra a la columna viva.
-- La llave activa vive en la BD (hmac_llaves_estado), no en la configuración de cada proceso: web, worker y CLI no
-- pueden quedar desincronizados. Toda escritura de una huella declara su key_id y un trigger exige que sea la activa: un
-- trabajo que calculó con la llave anterior y escribe después del corte falla y se reintenta (nunca mezcla llaves).

CREATE TABLE hmac_llaves_estado (
    id              smallint    PRIMARY KEY DEFAULT 1,
    key_id_activa   text        NOT NULL,
    -- Valor de verificación de la llave activa: HMAC(secreto, "conciliarh:kcv:v1"). No revela el secreto; detecta un anillo
    -- mal configurado (mismo id, otro secreto) ANTES de escribir huellas. Se registra en el primer uso (instalaciones existentes).
    kcv_activa      text,
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT hmac_estado_unico CHECK (id = 1),
    CONSTRAINT hmac_estado_key CHECK (key_id_activa ~ '^[a-z0-9_-]{1,16}$' AND (kcv_activa IS NULL OR kcv_activa ~ '^[0-9a-f]{64}$'))
);
-- Instalaciones existentes: la llave única ACCOUNT_HMAC_KEY es la «h1» del anillo (ver HMAC_ROTATION.md §Compatibilidad)
INSERT INTO hmac_llaves_estado (id, key_id_activa) VALUES (1, 'h1');
CREATE TRIGGER hmac_llaves_estado_sin_borrado BEFORE DELETE ON hmac_llaves_estado FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER hmac_llaves_estado_sin_truncate BEFORE TRUNCATE ON hmac_llaves_estado FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

CREATE TABLE hmac_rotaciones (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key_origen        text        NOT NULL,
    key_destino       text        NOT NULL,
    estado            text        NOT NULL DEFAULT 'PREPARANDO',
    iniciada_at       timestamptz NOT NULL DEFAULT now(),
    iniciada_por      text        NOT NULL,              -- usuario del sistema operativo que ejecutó la CLI
    completada_at     timestamptz,
    cancelada_at      timestamptz,
    resumen           jsonb       NOT NULL DEFAULT '{}'::jsonb,   -- sólo conteos
    kcv_destino       text        NOT NULL,                        -- valor de verificación de la llave destino
    CONSTRAINT hmac_rot_keys CHECK (key_origen ~ '^[a-z0-9_-]{1,16}$' AND key_destino ~ '^[a-z0-9_-]{1,16}$' AND key_origen <> key_destino),
    CONSTRAINT hmac_rot_estado CHECK (estado IN ('PREPARANDO', 'COMPLETADA', 'CANCELADA')),
    CONSTRAINT hmac_rot_fechas CHECK ((estado = 'COMPLETADA') = (completada_at IS NOT NULL) AND (estado = 'CANCELADA') = (cancelada_at IS NOT NULL)),
    CONSTRAINT hmac_rot_por CHECK (iniciada_por ~ '^[A-Za-z0-9._-]{1,64}$'),
    CONSTRAINT hmac_rot_resumen CHECK (jsonb_typeof(resumen) = 'object' AND kcv_destino ~ '^[0-9a-f]{64}$')
);
-- Una sola rotación en curso (dos CLI simultáneas no pueden preparar rotaciones distintas)
CREATE UNIQUE INDEX hmac_rotaciones_en_curso_uk ON hmac_rotaciones ((true)) WHERE estado = 'PREPARANDO';
CREATE TRIGGER hmac_rotaciones_sin_borrado BEFORE DELETE ON hmac_rotaciones FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER hmac_rotaciones_sin_truncate BEFORE TRUNCATE ON hmac_rotaciones FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Columnas: key_id de cada huella + sombra para la preparación
-- ---------------------------------------------------------------------
ALTER TABLE staging_registros ADD COLUMN cuenta_hmac_key_id text, ADD COLUMN cuenta_hmac_nueva text;
ALTER TABLE registros_tesoreria ADD COLUMN cuenta_hmac_key_id text, ADD COLUMN cuenta_hmac_nueva text;
ALTER TABLE cuentas_bancarias ADD COLUMN hmac_key_id text, ADD COLUMN cuenta_hmac_nueva text;
UPDATE staging_registros SET cuenta_hmac_key_id = 'h1' WHERE cuenta_hmac IS NOT NULL;
UPDATE registros_tesoreria SET cuenta_hmac_key_id = 'h1' WHERE cuenta_hmac IS NOT NULL;
ALTER TABLE cuentas_bancarias DISABLE TRIGGER cuentas_bancarias_inmutable;
UPDATE cuentas_bancarias SET hmac_key_id = 'h1';
ALTER TABLE cuentas_bancarias ENABLE TRIGGER cuentas_bancarias_inmutable;
ALTER TABLE cuentas_bancarias ALTER COLUMN hmac_key_id SET NOT NULL;
ALTER TABLE staging_registros ADD CONSTRAINT staging_hmac_key CHECK ((cuenta_hmac IS NULL) = (cuenta_hmac_key_id IS NULL)
    AND (cuenta_hmac_key_id IS NULL OR cuenta_hmac_key_id ~ '^[a-z0-9_-]{1,16}$')
    AND (cuenta_hmac_nueva IS NULL OR (cuenta_hmac IS NOT NULL AND cuenta_hmac_nueva ~ '^[0-9a-f]{64}$')));
ALTER TABLE registros_tesoreria ADD CONSTRAINT tes_hmac_key CHECK ((cuenta_hmac IS NULL) = (cuenta_hmac_key_id IS NULL)
    AND (cuenta_hmac_key_id IS NULL OR cuenta_hmac_key_id ~ '^[a-z0-9_-]{1,16}$')
    AND (cuenta_hmac_nueva IS NULL OR (cuenta_hmac IS NOT NULL AND cuenta_hmac_nueva ~ '^[0-9a-f]{64}$')));
ALTER TABLE cuentas_bancarias ADD CONSTRAINT cuentas_hmac_key CHECK (hmac_key_id ~ '^[a-z0-9_-]{1,16}$'
    AND (cuenta_hmac_nueva IS NULL OR cuenta_hmac_nueva ~ '^[0-9a-f]{64}$'));

-- El índice único vivo NO se toca. La sombra tiene su propio índice único: si dos cuentas activas distintas produjeran la
-- misma huella nueva (colisión o dato inconsistente), la preparación falla ahí mismo en vez de fusionarlas en silencio.
CREATE UNIQUE INDEX cuentas_bancarias_activa_nueva_uk ON cuentas_bancarias (cuenta_hmac_nueva, uso)
    WHERE estado = 'ACTIVA' AND cuenta_hmac_nueva IS NOT NULL;
CREATE INDEX registros_tesoreria_hmac_pend_idx ON registros_tesoreria (id) WHERE cuenta_hmac IS NOT NULL AND cuenta_hmac_nueva IS NULL;
CREATE INDEX staging_registros_hmac_pend_idx ON staging_registros (id) WHERE cuenta_hmac IS NOT NULL AND cuenta_hmac_nueva IS NULL;

-- Llave con la que se calcularon hash_registro/clave_hash de un análisis (huellas de registro; ver HMAC_ROTATION.md §Huellas de registro)
ALTER TABLE analisis_archivo ADD COLUMN hash_key_id text;
UPDATE analisis_archivo SET hash_key_id = 'h1';
ALTER TABLE analisis_archivo ADD CONSTRAINT analisis_hash_key CHECK (hash_key_id IS NULL OR hash_key_id ~ '^[a-z0-9_-]{1,16}$');

-- Llaves HMAC que cada worker declara tener (la CLI se niega a cortar si un worker vivo no tiene la llave destino)
ALTER TABLE workers ADD COLUMN hmac_key_ids text[];

-- ---------------------------------------------------------------------
-- Coherencia de llaves
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_hmac_key_activa() RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$ SELECT key_id_activa FROM hmac_llaves_estado WHERE id = 1 $$;

-- staging_registros / registros_tesoreria: toda huella nueva o modificada declara la llave activa
CREATE FUNCTION fn_hmac_huella_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_activa text;
BEGIN
    IF NEW.cuenta_hmac IS NULL THEN
        NEW.cuenta_hmac_key_id := NULL;
        NEW.cuenta_hmac_nueva := NULL;
        RETURN NEW;
    END IF;
    IF TG_OP = 'INSERT' OR NEW.cuenta_hmac IS DISTINCT FROM OLD.cuenta_hmac OR NEW.cuenta_hmac_key_id IS DISTINCT FROM OLD.cuenta_hmac_key_id THEN
        -- Llave activa una vez por transacción (no por fila). Seguro: el corte de una rotación bloquea la ESCRITURA de estas tablas
        -- (SHARE ROW EXCLUSIVE) y espera a que termine cualquier transacción que ya insertó huellas.
        v_activa := nullif(current_setting('conciliarh.hmac_activa_tx', true), '');
        IF v_activa IS NULL THEN
            v_activa := fn_hmac_key_activa();
            PERFORM set_config('conciliarh.hmac_activa_tx', v_activa, true);
        END IF;
        IF NEW.cuenta_hmac_key_id IS NULL OR NEW.cuenta_hmac_key_id <> v_activa THEN
            -- 40001: el trabajo calculó con una llave que ya no es la activa (corte concurrente) → se reintenta
            RAISE EXCEPTION 'Huella HMAC con llave % distinta de la activa %', coalesce(NEW.cuenta_hmac_key_id, '(ninguna)'), v_activa
                USING ERRCODE = 'serialization_failure';
        END IF;
        -- Web y worker nunca reescriben una huella existente; el propietario sólo en el corte (sombra → viva) o al
        -- poner la primera huella de una fila que no tenía
        IF TG_OP = 'UPDATE' AND NEW.cuenta_hmac IS DISTINCT FROM OLD.cuenta_hmac
           AND (fn_es_conexion_web() OR pg_has_role(session_user, 'conciliarh_worker', 'MEMBER')
                OR (OLD.cuenta_hmac IS NOT NULL AND NEW.cuenta_hmac IS DISTINCT FROM OLD.cuenta_hmac_nueva)) THEN
            RAISE EXCEPTION 'La huella de una cuenta sólo cambia en el corte de una rotación' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
-- Sólo filas CON huella (las demás no tienen nada que verificar; la CHECK exige key_id NULL si no hay huella): evita el costo
-- del trigger en cada fila de RRHH y en las actualizaciones de asociación que no tocan la cuenta
CREATE TRIGGER staging_registros_hmac BEFORE INSERT OR UPDATE ON staging_registros
    FOR EACH ROW WHEN (NEW.cuenta_hmac IS NOT NULL) EXECUTE FUNCTION fn_hmac_huella_reglas();
CREATE TRIGGER registros_tesoreria_hmac BEFORE INSERT OR UPDATE OF cuenta_hmac, cuenta_hmac_key_id, cuenta_hmac_nueva ON registros_tesoreria
    FOR EACH ROW WHEN (NEW.cuenta_hmac IS NOT NULL) EXECUTE FUNCTION fn_hmac_huella_reglas();

-- cuentas_bancarias es una COPIA de la huella de su registro de origen: key_id y sombra se toman de él (también cuando la
-- inserta fn_confirmar_cuenta/fn_confirmar_beneficiario); nunca se aceptan de quien inserta.
CREATE FUNCTION fn_cuenta_hmac_origen() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    r record;
BEGIN
    SELECT cuenta_hmac, cuenta_hmac_key_id, cuenta_hmac_nueva INTO r FROM registros_tesoreria WHERE id = NEW.origen_registro_id;
    IF NOT FOUND OR r.cuenta_hmac IS DISTINCT FROM NEW.cuenta_hmac THEN
        RAISE EXCEPTION 'La cuenta no corresponde a la huella de su registro de origen' USING ERRCODE = 'check_violation';
    END IF;
    IF r.cuenta_hmac_key_id IS DISTINCT FROM fn_hmac_key_activa() THEN
        RAISE EXCEPTION 'Huella HMAC con llave distinta de la activa' USING ERRCODE = 'serialization_failure';
    END IF;
    NEW.hmac_key_id := r.cuenta_hmac_key_id;
    NEW.cuenta_hmac_nueva := r.cuenta_hmac_nueva;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cuentas_bancarias_hmac_origen BEFORE INSERT ON cuentas_bancarias FOR EACH ROW EXECUTE FUNCTION fn_cuenta_hmac_origen();

-- La identidad de la cuenta sigue inmutable; la huella sólo cambia en el corte (sombra → viva, con la llave activa)
CREATE OR REPLACE FUNCTION fn_cuenta_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.empleado_id <> OLD.empleado_id OR NEW.uso <> OLD.uso
       OR NEW.origen <> OLD.origen OR NEW.origen_registro_id <> OLD.origen_registro_id THEN
        RAISE EXCEPTION 'La identidad de una cuenta registrada es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.cuenta_hmac <> OLD.cuenta_hmac OR NEW.hmac_key_id <> OLD.hmac_key_id THEN
        IF fn_es_conexion_web() OR NEW.cuenta_hmac IS DISTINCT FROM OLD.cuenta_hmac_nueva OR NEW.hmac_key_id <> fn_hmac_key_activa()
           OR NEW.hmac_key_id = OLD.hmac_key_id THEN
            RAISE EXCEPTION 'La huella de una cuenta sólo cambia en el corte de una rotación' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF OLD.estado = 'INACTIVA' AND NEW.estado = 'ACTIVA' THEN
        RAISE EXCEPTION 'Una cuenta desactivada no se reactiva: confirme de nuevo' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

-- El estado sólo lo cambia el propietario (CLI) — nunca la web ni el worker
CREATE FUNCTION fn_hmac_estado_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- Mantenimiento explícito del propietario (restauración de pruebas/desarrollo), como fn_solo_insercion
    IF current_setting('conciliarh.identidad_mantenimiento', true) = 'on'
       AND current_user = (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = TG_RELID) THEN
        NEW.updated_at := now();
        RETURN NEW;
    END IF;
    IF NEW.key_id_activa <> OLD.key_id_activa AND NOT EXISTS (
            SELECT 1 FROM hmac_rotaciones WHERE estado = 'PREPARANDO' AND key_origen = OLD.key_id_activa AND key_destino = NEW.key_id_activa) THEN
        RAISE EXCEPTION 'La llave activa sólo cambia en el corte de una rotación en curso' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;
CREATE TRIGGER hmac_llaves_estado_reglas BEFORE UPDATE ON hmac_llaves_estado FOR EACH ROW EXECUTE FUNCTION fn_hmac_estado_reglas();

-- Verificación de una llave del anillo por su KCV. La activa se registra en su primer uso (TOFU); la destino, al iniciar la
-- rotación (CLI). Un proceso con el MISMO id y otro secreto no escribe nada.
CREATE FUNCTION fn_hmac_verificar_kcv(p_key_id text, p_kcv text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    e hmac_llaves_estado;
BEGIN
    IF p_kcv IS NULL OR p_kcv !~ '^[0-9a-f]{64}$' THEN
        RETURN false;
    END IF;
    SELECT * INTO e FROM hmac_llaves_estado WHERE id = 1;
    IF p_key_id = e.key_id_activa THEN
        IF e.kcv_activa IS NULL THEN
            UPDATE hmac_llaves_estado SET kcv_activa = p_kcv WHERE id = 1 AND kcv_activa IS NULL;
            RETURN (SELECT kcv_activa FROM hmac_llaves_estado WHERE id = 1) = p_kcv;
        END IF;
        RETURN e.kcv_activa = p_kcv;
    END IF;
    RETURN EXISTS (SELECT 1 FROM hmac_rotaciones WHERE estado = 'PREPARANDO' AND key_destino = p_key_id AND kcv_destino = p_kcv);
END;
$$;

-- ---------------------------------------------------------------------
-- Privilegios (la rotación sólo la ejecuta el propietario por CLI)
-- ---------------------------------------------------------------------
GRANT SELECT ON hmac_llaves_estado TO conciliarh_worker;
GRANT UPDATE (hash_key_id) ON analisis_archivo TO conciliarh_worker;
REVOKE ALL ON FUNCTION fn_hmac_key_activa() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_hmac_key_activa() TO conciliarh_worker, conciliarh_web;
REVOKE ALL ON FUNCTION fn_hmac_verificar_kcv(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_hmac_verificar_kcv(text, text) TO conciliarh_worker;
-- La web nunca lee huellas ni su key_id (igual que en 0011)
