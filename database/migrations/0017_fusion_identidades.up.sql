-- 0017 · FASE 7: fusión NO destructiva de empleados duplicados y detección de posibles duplicados (docs/IDENTITY_MERGE.md).
--
-- Modelo de ALIAS:
--  * Una fusión NO mueve ni borra nada. El empleado origen queda FUSIONADO (fusionado_en_id → canónico) y todos sus
--    registros, cuentas, relaciones de beneficiario, incidencias e historial siguen apuntando a él.
--  * Las lecturas ACTUALES resuelven la identidad canónica con coalesce(fusionado_en_id, id) (siempre un salto: al
--    fusionar un canónico, quienes apuntaban a él se re-apuntan y quedan registrados en la fusión).
--  * Las conciliaciones históricas guardaron sus propios resultados (inmutables): no cambian. Una ejecución NUEVA usa la
--    identidad canónica.
--  * Corregir una fusión equivocada es otra decisión (fn_separar_fusion, sólo GOD, auditada); nunca un «deshacer» que borre.

ALTER TABLE empleados ADD COLUMN fusionado_en_id bigint REFERENCES empleados (id), ADD COLUMN fusion_id bigint;
ALTER TABLE empleados ADD CONSTRAINT empleados_fusion CHECK ((fusionado_en_id IS NULL) = (fusion_id IS NULL) AND fusionado_en_id IS DISTINCT FROM id);
CREATE INDEX empleados_fusionado_idx ON empleados (fusionado_en_id) WHERE fusionado_en_id IS NOT NULL;
CREATE INDEX empleados_issemym_idx ON empleados (upper(btrim(issemym))) WHERE issemym IS NOT NULL;

-- El historial del maestro registra también la fusión y la separación (con autor y motivo)
ALTER TABLE empleado_historial DROP CONSTRAINT empleado_historial_fuente;
ALTER TABLE empleado_historial ADD CONSTRAINT empleado_historial_fuente CHECK (fuente IN ('ALTA_RRHH', 'RRHH_TABULADO', 'MANUAL', 'FUSION', 'SEPARACION'));
ALTER TABLE empleado_historial ADD CONSTRAINT empleado_historial_fusion CHECK (fuente NOT IN ('FUSION', 'SEPARACION')
    OR (usuario_id IS NOT NULL AND char_length(btrim(coalesce(motivo, ''))) >= 20));

CREATE TABLE fusiones_empleados (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    origen_id              bigint      NOT NULL REFERENCES empleados (id),
    destino_id             bigint      NOT NULL REFERENCES empleados (id),
    estado                 text        NOT NULL DEFAULT 'APLICADA',
    usuario_id             bigint      NOT NULL REFERENCES usuarios (id),
    motivo                 text        NOT NULL,
    confianza              text        NOT NULL,
    analisis               jsonb       NOT NULL,           -- evidencias, conflictos, comparación (sin valores) y conteos
    conflictos_aceptados   text[]      NOT NULL DEFAULT '{}',
    reapuntados            bigint[]    NOT NULL DEFAULT '{}',   -- fusionados previamente en el origen, re-apuntados al destino
    huella                 text        NOT NULL,           -- SHA-256 del análisis previsualizado (el que se confirmó)
    version                smallint    NOT NULL DEFAULT 1,
    created_at             timestamptz NOT NULL DEFAULT now(),
    separada_por           bigint      REFERENCES usuarios (id),
    separada_at            timestamptz,
    motivo_separacion      text,
    CONSTRAINT fusiones_distintos CHECK (origen_id <> destino_id),
    CONSTRAINT fusiones_estado CHECK (estado IN ('APLICADA', 'SEPARADA')),
    CONSTRAINT fusiones_confianza CHECK (confianza IN ('ALTA', 'MEDIA')),
    CONSTRAINT fusiones_motivo CHECK (char_length(btrim(motivo)) BETWEEN 20 AND 2000),
    CONSTRAINT fusiones_huella CHECK (huella ~ '^[0-9a-f]{64}$'),
    CONSTRAINT fusiones_analisis CHECK (jsonb_typeof(analisis) = 'object'),
    CONSTRAINT fusiones_separacion CHECK ((estado = 'SEPARADA') = (separada_at IS NOT NULL AND separada_por IS NOT NULL)
                                          AND (estado <> 'SEPARADA' OR char_length(btrim(coalesce(motivo_separacion, ''))) BETWEEN 20 AND 2000))
);
CREATE UNIQUE INDEX fusiones_origen_aplicada_uk ON fusiones_empleados (origen_id) WHERE estado = 'APLICADA';
CREATE INDEX fusiones_destino_idx ON fusiones_empleados (destino_id, created_at DESC);
ALTER TABLE empleados ADD CONSTRAINT empleados_fusion_fk FOREIGN KEY (fusion_id) REFERENCES fusiones_empleados (id);

CREATE FUNCTION fn_fusion_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- Sólo la separación (decisión correctiva) cambia una fusión; lo demás es inmutable
    IF NEW.origen_id <> OLD.origen_id OR NEW.destino_id <> OLD.destino_id OR NEW.usuario_id <> OLD.usuario_id
       OR NEW.motivo <> OLD.motivo OR NEW.analisis <> OLD.analisis OR NEW.huella <> OLD.huella OR NEW.created_at <> OLD.created_at
       OR NEW.conflictos_aceptados <> OLD.conflictos_aceptados OR NEW.reapuntados <> OLD.reapuntados
       OR OLD.estado = 'SEPARADA' OR NEW.estado <> 'SEPARADA' THEN
        RAISE EXCEPTION 'Una fusión sólo puede separarse (decisión correctiva)' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fusiones_empleados_reglas BEFORE UPDATE ON fusiones_empleados FOR EACH ROW EXECUTE FUNCTION fn_fusion_reglas();
CREATE TRIGGER fusiones_empleados_actor BEFORE INSERT OR UPDATE ON fusiones_empleados
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_id', 'separada_por');
CREATE TRIGGER fusiones_empleados_sin_borrado BEFORE DELETE ON fusiones_empleados FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER fusiones_empleados_sin_truncate BEFORE TRUNCATE ON fusiones_empleados FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Posibles duplicados (motor determinístico; nunca fusiona)
-- ---------------------------------------------------------------------
CREATE TABLE duplicados_empleados (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    empleado_a             bigint      NOT NULL REFERENCES empleados (id),
    empleado_b             bigint      NOT NULL REFERENCES empleados (id),
    confianza              text        NOT NULL,
    evidencias             jsonb       NOT NULL DEFAULT '[]'::jsonb,
    conflictos             jsonb       NOT NULL DEFAULT '[]'::jsonb,
    estado                 text        NOT NULL DEFAULT 'PENDIENTE',
    detectado_at           timestamptz NOT NULL DEFAULT now(),
    actualizado_at         timestamptz NOT NULL DEFAULT now(),
    proceso_id             bigint      REFERENCES procesos (id),
    decidido_por           bigint      REFERENCES usuarios (id),
    decidido_at            timestamptz,
    motivo                 text,
    confianza_al_decidir   text,
    CONSTRAINT dup_orden CHECK (empleado_a < empleado_b),
    CONSTRAINT dup_confianza CHECK (confianza IN ('ALTA', 'MEDIA', 'BAJA')),
    CONSTRAINT dup_estado CHECK (estado IN ('PENDIENTE', 'DESCARTADO', 'FUSIONADO', 'OBSOLETO')),
    CONSTRAINT dup_json CHECK (jsonb_typeof(evidencias) = 'array' AND jsonb_typeof(conflictos) = 'array'),
    CONSTRAINT dup_descarte CHECK ((estado = 'DESCARTADO') <= (decidido_por IS NOT NULL AND decidido_at IS NOT NULL
                                    AND char_length(btrim(coalesce(motivo, ''))) BETWEEN 5 AND 1000))
);
CREATE UNIQUE INDEX duplicados_empleados_par_uk ON duplicados_empleados (empleado_a, empleado_b);
CREATE INDEX duplicados_empleados_estado_idx ON duplicados_empleados (estado, confianza);

CREATE FUNCTION fn_duplicado_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.empleado_a <> OLD.empleado_a OR NEW.empleado_b <> OLD.empleado_b THEN
        RAISE EXCEPTION 'El par de un posible duplicado es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    -- Edición DIRECTA de la web: sólo descartar un par pendiente (con autor y motivo); la autoría es la de la sesión
    IF fn_es_conexion_web() AND current_user = session_user THEN
        IF OLD.estado <> 'PENDIENTE' OR NEW.estado <> 'DESCARTADO' OR NEW.confianza <> OLD.confianza
           OR NEW.evidencias <> OLD.evidencias OR NEW.conflictos <> OLD.conflictos THEN
            RAISE EXCEPTION 'La aplicación sólo descarta pares pendientes' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF NOT fn_actor_tiene_permiso('empleados.duplicados') THEN
            RAISE EXCEPTION 'Sin permiso para descartar posibles duplicados' USING ERRCODE = 'insufficient_privilege';
        END IF;
        NEW.decidido_por := fn_actor_actual();
        NEW.decidido_at := now();
        NEW.confianza_al_decidir := OLD.confianza;
    END IF;
    NEW.actualizado_at := now();
    RETURN NEW;
END;
$$;
CREATE TRIGGER duplicados_empleados_reglas BEFORE UPDATE ON duplicados_empleados FOR EACH ROW EXECUTE FUNCTION fn_duplicado_reglas();
CREATE TRIGGER duplicados_empleados_actor BEFORE INSERT OR UPDATE ON duplicados_empleados
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('decidido_por');
CREATE TRIGGER duplicados_empleados_sin_borrado BEFORE DELETE ON duplicados_empleados FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER duplicados_empleados_sin_truncate BEFORE TRUNCATE ON duplicados_empleados FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- La web nunca asocia un registro NUEVO a un empleado fusionado: se usa el canónico (el worker sí puede asociar por
-- alias: el número o la cuenta del origen siguen identificándolo, y la lectura resuelve el canónico)
CREATE FUNCTION fn_registro_no_fusionado() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.empleado_id IS NOT NULL AND NEW.empleado_id IS DISTINCT FROM OLD.empleado_id AND fn_es_conexion_web()
       AND EXISTS (SELECT 1 FROM empleados WHERE id = NEW.empleado_id AND fusionado_en_id IS NOT NULL) THEN
        RAISE EXCEPTION 'EMPLEADO_FUSIONADO: asocie al empleado canónico' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER registros_rrhh_no_fusionado BEFORE UPDATE OF empleado_id ON registros_rrhh FOR EACH ROW EXECUTE FUNCTION fn_registro_no_fusionado();
CREATE TRIGGER registros_tesoreria_no_fusionado BEFORE UPDATE OF empleado_id ON registros_tesoreria FOR EACH ROW EXECUTE FUNCTION fn_registro_no_fusionado();

-- ---------------------------------------------------------------------
-- Análisis determinístico de un par (lo usan la previsualización, la fusión y el detector: una sola regla)
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_fusion_analisis(p_origen bigint, p_destino bigint) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    a empleados;
    b empleados;
    v_ev jsonb := '[]'::jsonb;
    v_co jsonb := '[]'::jsonb;
    v_conf text;
    v_bloqueo boolean;
    v_fuerte boolean;
    v_media boolean;
    v_debil boolean;
    v_conteos jsonb := '{}'::jsonb;
    v_emp bigint;
    v_lotes int;
BEGIN
    IF fn_es_conexion_web() THEN
        -- Revela IGUAL/DIFERENTE de identificadores: sólo quien puede fusionar o revisar duplicados
        IF NOT (fn_actor_tiene_permiso('empleados.fusionar') OR fn_actor_tiene_permiso('empleados.duplicados')) THEN
            RAISE EXCEPTION 'Sin permiso para analizar fusiones' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    SELECT * INTO a FROM empleados WHERE id = p_origen;
    SELECT * INTO b FROM empleados WHERE id = p_destino;
    IF a.id IS NULL OR b.id IS NULL THEN
        RAISE EXCEPTION 'Empleado inexistente' USING ERRCODE = 'no_data_found';
    END IF;
    -- Conflictos que impiden fusionar
    IF a.id = b.id THEN
        v_co := v_co || jsonb_build_object('codigo', 'MISMO_EMPLEADO', 'severidad', 'BLOQUEO');
    END IF;
    IF a.fusionado_en_id IS NOT NULL OR b.fusionado_en_id IS NOT NULL THEN
        v_co := v_co || jsonb_build_object('codigo', 'YA_FUSIONADO', 'severidad', 'BLOQUEO');
    END IF;
    -- Evidencias
    IF a.rfc_valido AND b.rfc_valido THEN
        IF a.rfc = b.rfc THEN v_ev := v_ev || jsonb_build_object('codigo', 'MISMO_RFC', 'fuerza', 'FUERTE');
        ELSE v_co := v_co || jsonb_build_object('codigo', 'RFC_DIFERENTE', 'severidad', 'GRAVE'); END IF;
    END IF;
    IF a.curp_valida AND b.curp_valida THEN
        IF a.curp = b.curp THEN v_ev := v_ev || jsonb_build_object('codigo', 'MISMA_CURP', 'fuerza', 'FUERTE');
        ELSE v_co := v_co || jsonb_build_object('codigo', 'CURP_DIFERENTE', 'severidad', 'GRAVE'); END IF;
    END IF;
    IF char_length(btrim(coalesce(a.issemym, ''))) >= 4 AND char_length(btrim(coalesce(b.issemym, ''))) >= 4 THEN
        IF upper(btrim(a.issemym)) = upper(btrim(b.issemym)) THEN v_ev := v_ev || jsonb_build_object('codigo', 'MISMO_ISSEMYM', 'fuerza', 'FUERTE');
        ELSE v_co := v_co || jsonb_build_object('codigo', 'ISSEMYM_DIFERENTE', 'severidad', 'GRAVE'); END IF;
    END IF;
    IF EXISTS (SELECT 1 FROM cuentas_bancarias ca JOIN cuentas_bancarias cb ON cb.cuenta_hmac = ca.cuenta_hmac AND cb.uso = ca.uso
                WHERE ca.empleado_id = a.id AND cb.empleado_id = b.id) THEN
        v_ev := v_ev || jsonb_build_object('codigo', 'CUENTA_CONFIRMADA_COMPARTIDA', 'fuerza', 'FUERTE');
    END IF;
    IF EXISTS (SELECT 1 FROM registros_tesoreria ta JOIN registros_tesoreria tb ON tb.cuenta_hmac = ta.cuenta_hmac AND tb.categoria = ta.categoria
                WHERE ta.empleado_id = a.id AND tb.empleado_id = b.id AND ta.categoria = 'NOMINA'
                  AND ta.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL') AND tb.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')) THEN
        v_ev := v_ev || jsonb_build_object('codigo', 'PAGOS_A_LA_MISMA_CUENTA', 'fuerza', 'MEDIA');
    END IF;
    IF EXISTS (SELECT 1 FROM incidencias_identidad i
                WHERE i.detalle->'empleados' @> to_jsonb(a.id) AND i.detalle->'empleados' @> to_jsonb(b.id)) THEN
        v_ev := v_ev || jsonb_build_object('codigo', 'REGISTRO_CON_IDENTIFICADORES_DE_AMBOS', 'fuerza', 'MEDIA');
    END IF;
    IF a.nombre_normalizado = b.nombre_normalizado THEN
        v_ev := v_ev || jsonb_build_object('codigo', 'MISMO_NOMBRE', 'fuerza', 'DEBIL');
    ELSE
        v_co := v_co || jsonb_build_object('codigo', 'NOMBRE_DIFERENTE', 'severidad', 'ADVERTENCIA');
    END IF;
    -- Actividad simultánea: los dos en la MISMA carga (lote) = dos nóminas a la vez → grave; en cargas distintas del
    -- mismo periodo sólo se advierte (es justamente cómo aparece un número corregido entre cargas)
    IF EXISTS (SELECT 1 FROM registros_rrhh ra JOIN registros_rrhh rb ON rb.lote_id = ra.lote_id
                 JOIN importaciones_normalizadas ia ON ia.id = ra.importacion_id AND ia.estado = 'VIGENTE'
                 JOIN importaciones_normalizadas ib ON ib.id = rb.importacion_id AND ib.estado = 'VIGENTE'
                 JOIN lotes_importacion l ON l.id = ra.lote_id AND l.estado <> 'ANULADO'
                WHERE ra.empleado_id = a.id AND rb.empleado_id = b.id) THEN
        v_co := v_co || jsonb_build_object('codigo', 'NOMINA_SIMULTANEA_MISMO_LOTE', 'severidad', 'GRAVE');
    ELSIF EXISTS (SELECT 1 FROM registros_rrhh ra JOIN registros_rrhh rb ON rb.periodo_id = ra.periodo_id
                    JOIN importaciones_normalizadas ia ON ia.id = ra.importacion_id AND ia.estado = 'VIGENTE'
                    JOIN importaciones_normalizadas ib ON ib.id = rb.importacion_id AND ib.estado = 'VIGENTE'
                   WHERE ra.empleado_id = a.id AND rb.empleado_id = b.id) THEN
        v_co := v_co || jsonb_build_object('codigo', 'MISMO_PERIODO_CARGAS_DISTINTAS', 'severidad', 'ADVERTENCIA');
    END IF;
    IF EXISTS (SELECT 1 FROM registros_tesoreria ta JOIN registros_tesoreria tb ON tb.lote_id = ta.lote_id AND tb.categoria = 'NOMINA'
                WHERE ta.empleado_id = a.id AND tb.empleado_id = b.id AND ta.categoria = 'NOMINA'
                  AND ta.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL') AND tb.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')) THEN
        v_co := v_co || jsonb_build_object('codigo', 'PAGOS_NOMINA_EN_EL_MISMO_LOTE', 'severidad', 'ADVERTENCIA');
    END IF;
    IF (SELECT count(DISTINCT empleado_id) FROM cuentas_bancarias WHERE empleado_id IN (a.id, b.id) AND uso = 'NOMINA' AND estado = 'ACTIVA') = 2
       AND a.id <> b.id THEN
        v_co := v_co || jsonb_build_object('codigo', 'CUENTAS_NOMINA_ACTIVAS_DISTINTAS', 'severidad', 'ADVERTENCIA');
    END IF;
    IF a.grupo <> b.grupo THEN
        v_co := v_co || jsonb_build_object('codigo', 'GRUPO_DIFERENTE', 'severidad', 'ADVERTENCIA');
    END IF;

    v_bloqueo := jsonb_path_exists(v_co, '$[*] ? (@.severidad == "BLOQUEO")');
    v_fuerte := jsonb_path_exists(v_ev, '$[*] ? (@.fuerza == "FUERTE")');
    v_media := jsonb_path_exists(v_ev, '$[*] ? (@.fuerza == "MEDIA")');
    v_debil := jsonb_path_exists(v_ev, '$[*] ? (@.fuerza == "DEBIL")');
    v_conf := CASE WHEN v_fuerte THEN 'ALTA' WHEN v_media THEN 'MEDIA' WHEN v_debil THEN 'BAJA' ELSE 'NINGUNA' END;

    -- Qué quedaría bajo la identidad canónica (conteos; nada se mueve). Incluye a quienes ya apuntan a cada uno.
    FOREACH v_emp IN ARRAY ARRAY[a.id, b.id] LOOP
        SELECT count(DISTINCT r.lote_id) INTO v_lotes
          FROM (SELECT lote_id FROM registros_rrhh WHERE empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)
                UNION ALL SELECT lote_id FROM registros_tesoreria WHERE empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)) r
          JOIN lotes_importacion l ON l.id = r.lote_id AND l.estado <> 'ANULADO';
        v_conteos := v_conteos || jsonb_build_object(v_emp::text, jsonb_build_object(
            'alias', (SELECT count(*) FROM empleados WHERE fusionado_en_id = v_emp),
            'registros_rrhh', (SELECT count(*) FROM registros_rrhh WHERE empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'registros_tesoreria', (SELECT count(*) FROM registros_tesoreria WHERE categoria = 'NOMINA'
                                     AND empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'pensiones', (SELECT count(*) FROM registros_tesoreria WHERE categoria = 'PENSION'
                           AND empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'beneficiarios', (SELECT count(*) FROM beneficiario_trabajadores WHERE estado = 'ACTIVA'
                               AND empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'cuentas_activas', (SELECT count(*) FROM cuentas_bancarias WHERE estado = 'ACTIVA'
                                 AND empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'cuentas_historicas', (SELECT count(*) FROM cuentas_bancarias WHERE estado = 'INACTIVA'
                                    AND empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'incidencias_abiertas', (SELECT count(*) FROM incidencias_identidad WHERE estado = 'ABIERTA'
                                      AND empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'periodos', (SELECT count(DISTINCT periodo_id) FROM registros_rrhh WHERE empleado_id IN (SELECT id FROM empleados WHERE id = v_emp OR fusionado_en_id = v_emp)),
            'conciliaciones_historicas', (SELECT count(DISTINCT conciliacion_id) FROM conciliacion_empleados WHERE empleado_id = v_emp),
            'lotes_afectables', v_lotes,
            'actualizado', (SELECT updated_at FROM empleados WHERE id = v_emp)));
    END LOOP;
    RETURN jsonb_build_object(
        'version', 1, 'origen', a.id, 'destino', b.id,
        'confianza', CASE WHEN v_bloqueo THEN 'BLOQUEADA' ELSE v_conf END,
        'fusionable', NOT v_bloqueo AND v_conf IN ('ALTA', 'MEDIA'),
        'evidencias', v_ev, 'conflictos', v_co,
        -- Comparación de campos SIN valores (los valores se consultan con permiso en el maestro)
        'comparacion', jsonb_build_object(
            'numero', 'DIFERENTE',
            'nombre', CASE WHEN a.nombre_normalizado = b.nombre_normalizado THEN 'IGUAL' ELSE 'DIFERENTE' END,
            'rfc', CASE WHEN a.rfc IS NULL OR b.rfc IS NULL THEN 'FALTA' WHEN a.rfc = b.rfc THEN 'IGUAL' ELSE 'DIFERENTE' END,
            'curp', CASE WHEN a.curp IS NULL OR b.curp IS NULL THEN 'FALTA' WHEN a.curp = b.curp THEN 'IGUAL' ELSE 'DIFERENTE' END,
            'issemym', CASE WHEN a.issemym IS NULL OR b.issemym IS NULL THEN 'FALTA'
                            WHEN upper(btrim(a.issemym)) = upper(btrim(b.issemym)) THEN 'IGUAL' ELSE 'DIFERENTE' END,
            'grupo', CASE WHEN a.grupo = b.grupo THEN 'IGUAL' ELSE 'DIFERENTE' END),
        'conteos', v_conteos);
END;
$$;

CREATE FUNCTION fn_fusion_huella(p_analisis jsonb) RETURNS text
LANGUAGE sql IMMUTABLE AS $$ SELECT encode(sha256(convert_to(p_analisis::text, 'UTF8')), 'hex') $$;

-- Punteros canónicos = raíz de la cadena de fusiones APLICADAS de cada alias (un salto). Se recalcula tras fusionar o separar:
-- una separación en medio de una cadena nunca deja un alias apuntando a quien no corresponde.
CREATE FUNCTION fn_fusion_recalcular() RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
    n integer;
BEGIN
    WITH RECURSIVE ap AS (SELECT origen_id, destino_id FROM fusiones_empleados WHERE estado = 'APLICADA'),
         c(origen, raiz, nivel) AS (SELECT origen_id, destino_id, 1 FROM ap
                                     UNION ALL SELECT c.origen, ap.destino_id, c.nivel + 1 FROM c JOIN ap ON ap.origen_id = c.raiz WHERE c.nivel < 100),
         fin AS (SELECT DISTINCT ON (origen) origen, raiz FROM c ORDER BY origen, nivel DESC)
    UPDATE empleados e SET fusionado_en_id = fin.raiz FROM fin WHERE e.id = fin.origen AND e.fusionado_en_id IS DISTINCT FROM fin.raiz;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END;
$$;

-- ---------------------------------------------------------------------
-- Fusión (uno a uno; sólo GOD/ADMIN con empleados.fusionar; conflictos graves con empleados.fusionar_conflictos)
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_fusionar_empleados(p_origen bigint, p_destino bigint, p_usuario_id bigint, p_motivo text,
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

-- ---------------------------------------------------------------------
-- Separación: decisión correctiva (sólo GOD). No borra: la fusión queda SEPARADA y el origen vuelve a ser canónico.
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_separar_fusion(p_fusion_id bigint, p_usuario_id bigint, p_motivo text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    f fusiones_empleados;
BEGIN
    IF p_usuario_id IS NULL OR fn_actor_actual() IS DISTINCT FROM p_usuario_id THEN
        RAISE EXCEPTION 'Separación sin actor de sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT fn_actor_tiene_permiso('empleados.separar') THEN
        RAISE EXCEPTION 'Sólo GOD separa una fusión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 20 THEN
        RAISE EXCEPTION 'La separación exige un motivo de al menos 20 caracteres' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    PERFORM pg_advisory_xact_lock(4850181374600825153);
    SELECT * INTO f FROM fusiones_empleados WHERE id = p_fusion_id FOR UPDATE;
    IF f.id IS NULL OR f.estado <> 'APLICADA' THEN
        RAISE EXCEPTION 'La fusión no existe o ya fue separada' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM 1 FROM empleados WHERE id IN (f.origen_id, f.destino_id) OR id = ANY(f.reapuntados) ORDER BY id FOR UPDATE;
    UPDATE fusiones_empleados SET estado = 'SEPARADA', separada_por = p_usuario_id, separada_at = now(), motivo_separacion = btrim(p_motivo)
     WHERE id = f.id;
    UPDATE empleados SET fusionado_en_id = NULL, fusion_id = NULL, actualizado_por = p_usuario_id WHERE id = f.origen_id;
    -- Cada alias vuelve a la raíz de SU cadena de fusiones vigentes (p. ej. quienes se habían fusionado en el origen, a él)
    PERFORM fn_fusion_recalcular();
    INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo)
    VALUES (f.origen_id, 'fusionado_en_id', f.destino_id::text, NULL, 'SEPARACION', p_usuario_id,
            'Separación de la fusión #' || f.id || ': ' || left(btrim(p_motivo), 400));
    UPDATE duplicados_empleados SET estado = 'PENDIENTE'
     WHERE empleado_a = least(f.origen_id, f.destino_id) AND empleado_b = greatest(f.origen_id, f.destino_id) AND estado = 'FUSIONADO';
    RETURN f.id;
END;
$$;

-- ---------------------------------------------------------------------
-- Identidad canónica en la confirmación de cuentas: la cuenta registrada para un alias fusionado es de la MISMA persona
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_empleado_canonico(p_id bigint) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT coalesce(fusionado_en_id, id) FROM empleados WHERE id = p_id
$$;
REVOKE ALL ON FUNCTION fn_empleado_canonico(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_empleado_canonico(bigint) TO conciliarh_web, conciliarh_worker;

CREATE OR REPLACE FUNCTION fn_confirmar_cuenta(p_registro_id bigint, p_usuario_id bigint, p_motivo text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r        registros_tesoreria;
    v_uso    text;
    v_cuenta cuentas_bancarias;
    v_id     bigint;
BEGIN
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
       OR NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles ro ON ro.id = ur.rol_id
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
        IF fn_empleado_canonico(v_cuenta.empleado_id) <> fn_empleado_canonico(r.empleado_id) THEN   -- misma persona fusionada: no es conflicto
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

CREATE OR REPLACE FUNCTION fn_confirmar_beneficiario(p_registro_id bigint, p_usuario_id bigint, p_beneficiario_id bigint, p_motivo text,
                                          p_accion_masiva_id bigint DEFAULT NULL)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r        registros_tesoreria;
    v_ben    beneficiarios_pension;
    v_cuenta cuentas_bancarias;
    v_cid    bigint;
BEGIN
    PERFORM pg_advisory_xact_lock(4850181374600825153);         -- mismo candado que cuentas y normalización
    SELECT * INTO r FROM registros_tesoreria WHERE id = p_registro_id FOR UPDATE;
    IF r.id IS NULL OR r.categoria <> 'PENSION' THEN
        RAISE EXCEPTION 'El registro no es un pago de pensión' USING ERRCODE = 'check_violation';
    END IF;
    IF fn_es_conexion_web() AND p_usuario_id IS DISTINCT FROM fn_actor_actual() THEN
        RAISE EXCEPTION 'La confirmación no corresponde al usuario de la sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    -- La acción masiva, si se indica, debe ser del mismo usuario y de esta transacción (procedencia no falsificable)
    IF p_accion_masiva_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM acciones_masivas a WHERE a.id = p_accion_masiva_id
                                                         AND a.usuario_id = p_usuario_id AND a.created_at = now()) THEN
        RAISE EXCEPTION 'La acción masiva no pertenece a esta operación' USING ERRCODE = 'insufficient_privilege';
    END IF;
    -- Sólo junto con la asociación manual del trabajador hecha por el mismo usuario en esta transacción
    IF r.asociacion_estado <> 'ASOCIADO_MANUAL' OR r.empleado_id IS NULL
       OR r.asociado_por IS DISTINCT FROM p_usuario_id OR r.asociado_at IS DISTINCT FROM now() THEN
        RAISE EXCEPTION 'El beneficiario se confirma junto con la asociación manual del mismo usuario' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.id = p_usuario_id AND u.activo AND u.deleted_at IS NULL)
       OR NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles ro ON ro.id = ur.rol_id AND ro.activo
                        LEFT JOIN rol_permiso rp ON rp.rol_id = ur.rol_id LEFT JOIN permisos pe ON pe.id = rp.permiso_id
                       WHERE ur.usuario_id = p_usuario_id AND (ro.codigo = 'GOD' OR pe.codigo = 'beneficiarios.confirmar')) THEN
        RAISE EXCEPTION 'El usuario no puede confirmar beneficiarios' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM importaciones_normalizadas n JOIN periodos_nomina pe ON pe.id = n.periodo_id
                     JOIN lotes_importacion l ON l.id = n.lote_id
                    WHERE n.id = r.importacion_id AND n.estado = 'VIGENTE' AND pe.estado = 'ABIERTO' AND l.estado <> 'ANULADO') THEN
        RAISE EXCEPTION 'Importación no vigente, periodo cerrado o lote anulado' USING ERRCODE = 'check_violation';
    END IF;
    IF char_length(btrim(coalesce(p_motivo, ''))) < 5 THEN
        RAISE EXCEPTION 'El motivo es obligatorio' USING ERRCODE = 'check_violation';
    END IF;
    -- La CLABE del registro, si ya pertenece a un beneficiario, manda (evita dos identidades para la misma cuenta)
    IF r.cuenta_hmac IS NOT NULL THEN
        SELECT * INTO v_cuenta FROM cuentas_bancarias WHERE cuenta_hmac = r.cuenta_hmac AND uso = 'PENSION_BENEFICIARIO' AND estado = 'ACTIVA'
           FOR UPDATE;
        IF v_cuenta.id IS NOT NULL AND fn_empleado_canonico(v_cuenta.empleado_id) <> fn_empleado_canonico(r.empleado_id) THEN
            RAISE EXCEPTION 'La CLABE ya está confirmada para el beneficiario de otro trabajador' USING ERRCODE = 'unique_violation';
        END IF;
        IF v_cuenta.beneficiario_id IS NOT NULL AND p_beneficiario_id IS NOT NULL AND v_cuenta.beneficiario_id <> p_beneficiario_id THEN
            RAISE EXCEPTION 'La CLABE ya pertenece a otro beneficiario' USING ERRCODE = 'unique_violation';
        END IF;
        p_beneficiario_id := coalesce(p_beneficiario_id, v_cuenta.beneficiario_id);
    END IF;
    IF p_beneficiario_id IS NOT NULL THEN
        SELECT * INTO v_ben FROM beneficiarios_pension WHERE id = p_beneficiario_id FOR UPDATE;
        IF v_ben.id IS NULL OR v_ben.estado <> 'ACTIVO' THEN
            RAISE EXCEPTION 'El beneficiario no existe o está inactivo' USING ERRCODE = 'check_violation';
        END IF;
        UPDATE beneficiarios_pension SET ultimo_periodo_id = r.periodo_id
         WHERE id = v_ben.id AND (SELECT fecha_fin FROM periodos_nomina WHERE id = ultimo_periodo_id)
                                 < (SELECT fecha_fin FROM periodos_nomina WHERE id = r.periodo_id);
    ELSE
        IF r.nombre_original IS NULL OR r.nombre_normalizado IS NULL THEN
            RAISE EXCEPTION 'El registro no trae nombre de beneficiario' USING ERRCODE = 'check_violation';
        END IF;
        INSERT INTO beneficiarios_pension (nombre_original, nombre_normalizado, origen, origen_registro_id, confirmado_por, confirmado_at,
                                           primer_periodo_id, ultimo_periodo_id)
        VALUES (left(btrim(r.nombre_original), 200), left(r.nombre_normalizado, 200), 'CONFIRMACION_MANUAL', r.id, p_usuario_id, now(),
                r.periodo_id, r.periodo_id)
        RETURNING * INTO v_ben;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM beneficiario_trabajadores WHERE beneficiario_id = v_ben.id AND empleado_id = r.empleado_id AND estado = 'ACTIVA') THEN
        INSERT INTO beneficiario_trabajadores (beneficiario_id, empleado_id, origen, evidencia, vigente_desde_periodo_id, confirmado_por,
                                               confirmado_at, motivo, accion_masiva_id)
        VALUES (v_ben.id, r.empleado_id, 'CONFIRMACION_MANUAL',
                jsonb_build_object('registro_id', r.id, 'archivo_id', r.archivo_id, 'lote_id', r.lote_id, 'periodo_id', r.periodo_id,
                                   'fila', r.fila_origen, 'banco', r.banco),
                r.periodo_id, p_usuario_id, now(), left(btrim(p_motivo), 1000), p_accion_masiva_id);
    END IF;
    -- La CLABE se confirma como medio de pago conocido del beneficiario (misma protección: cifrado + HMAC)
    IF r.cuenta_hmac IS NOT NULL AND r.cuenta_cifrada IS NOT NULL THEN
        IF v_cuenta.id IS NULL THEN
            v_cid := fn_confirmar_cuenta(r.id, p_usuario_id, p_motivo);
        ELSE
            v_cid := v_cuenta.id;
        END IF;
        UPDATE cuentas_bancarias SET beneficiario_id = v_ben.id WHERE id = v_cid AND beneficiario_id IS NULL;
    END IF;
    UPDATE registros_tesoreria SET beneficiario_id = v_ben.id WHERE id = r.id;
    INSERT INTO asociaciones_historial (registro_tipo, registro_id, accion, estado_anterior, estado_nuevo, empleado_anterior, empleado_nuevo,
                                        metodo, confianza, usuario_id, motivo, accion_masiva_id)
    VALUES ('TESORERIA', r.id, 'CONFIRMAR_BENEFICIARIO', r.asociacion_estado, r.asociacion_estado, r.empleado_id, r.empleado_id,
            'MANUAL', 'MANUAL', p_usuario_id, left(btrim(p_motivo), 1000), p_accion_masiva_id);
    RETURN v_ben.id;
END;
$$;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('empleados.duplicados',          'empleados', 'Ver posibles duplicados de empleados y descartar pares', false, false),
    ('empleados.fusionar',            'empleados', 'Fusionar dos empleados (uno a uno, con previsualización y motivo)', false, true),
    -- Por omisión sólo GOD: una fusión con conflictos graves cambia cómo se atribuyen pagos futuros. GOD puede otorgarlo a un rol autorizado.
    ('empleados.fusionar_conflictos', 'empleados', 'Aceptar conflictos graves al fusionar (RFC/CURP/ISSEMYM distintos, nómina simultánea)', false, true),
    ('empleados.separar',             'empleados', 'Separar una fusión (decisión correctiva)', true, true)
ON CONFLICT (codigo) DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('empleados.duplicados', 'empleados.fusionar', 'empleados.fusionar_conflictos', 'empleados.separar')
 WHERE r.codigo = 'GOD'
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('empleados.duplicados', 'empleados.fusionar')
 WHERE r.codigo = 'ADMIN'
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'empleados.duplicados' WHERE r.codigo = 'ANALISTA'
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON fusiones_empleados, duplicados_empleados TO conciliarh_web;
GRANT SELECT (fusionado_en_id, fusion_id) ON empleados TO conciliarh_web;
GRANT UPDATE (estado, motivo, decidido_por) ON duplicados_empleados TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_fusion_analisis(bigint, bigint), fn_fusionar_empleados(bigint, bigint, bigint, text, text[], text),
    fn_separar_fusion(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_fusion_analisis(bigint, bigint), fn_fusion_huella(jsonb) TO conciliarh_web, conciliarh_worker;
-- Los triggers verifican permisos del ACTOR (sólo sobre la propia sesión)
GRANT EXECUTE ON FUNCTION fn_actor_tiene_permiso(text) TO conciliarh_web;
GRANT EXECUTE ON FUNCTION fn_fusionar_empleados(bigint, bigint, bigint, text, text[], text), fn_separar_fusion(bigint, bigint, text) TO conciliarh_web;
GRANT SELECT (fusionado_en_id, fusion_id) ON empleados TO conciliarh_worker;
GRANT SELECT ON fusiones_empleados TO conciliarh_worker;
GRANT SELECT, INSERT ON duplicados_empleados TO conciliarh_worker;
GRANT UPDATE (confianza, evidencias, conflictos, estado, actualizado_at, proceso_id) ON duplicados_empleados TO conciliarh_worker;
