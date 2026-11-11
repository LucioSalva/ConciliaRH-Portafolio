-- 0015 · FASE 6: beneficiarios de pensión, revisión masiva de asociaciones y permisos de reportes.
-- Modelo en docs/BENEFICIARIES.md. Reglas:
--  * TRABAJADOR (empleados) ≠ BENEFICIARIO (beneficiarios_pension). La relación entre ambos es una entidad propia con
--    vigencia, origen y evidencia; nunca se crea sólo por nombre.
--  * Las cuentas/CLABE del beneficiario reutilizan cuentas_bancarias (uso PENSION_BENEFICIARIO): AES-256-GCM + HMAC,
--    historial (INACTIVA, nunca se sobrescribe) y rotación de llave ya cubiertos; aquí sólo se ligan al beneficiario.
--  * Toda acción masiva guarda el desglose registro por registro (acciones_masivas_registros).

-- ---------------------------------------------------------------------
-- Beneficiarios
-- ---------------------------------------------------------------------
CREATE TABLE beneficiarios_pension (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre_original      text        NOT NULL,             -- tal como apareció la primera vez
    nombre_normalizado   text        NOT NULL,
    estado               text        NOT NULL DEFAULT 'ACTIVO',
    origen               text        NOT NULL,
    origen_registro_id   bigint      NOT NULL REFERENCES registros_tesoreria (id),
    confirmado_por       bigint      REFERENCES usuarios (id),
    confirmado_at        timestamptz,
    observaciones        text,
    actualizado_por      bigint      REFERENCES usuarios (id),
    primer_periodo_id    bigint      NOT NULL REFERENCES periodos_nomina (id),
    ultimo_periodo_id    bigint      NOT NULL REFERENCES periodos_nomina (id),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT benef_estado CHECK (estado IN ('ACTIVO', 'INACTIVO')),
    -- EVIDENCIA_FUERTE: el archivo trae el número del trabajador; CONFIRMACION_MANUAL: decisión de un analista
    CONSTRAINT benef_origen CHECK (origen IN ('EVIDENCIA_FUERTE', 'CONFIRMACION_MANUAL')),
    CONSTRAINT benef_confirmacion CHECK ((origen = 'CONFIRMACION_MANUAL') = (confirmado_por IS NOT NULL AND confirmado_at IS NOT NULL)),
    CONSTRAINT benef_textos CHECK (char_length(btrim(nombre_original)) BETWEEN 1 AND 200 AND char_length(nombre_normalizado) BETWEEN 1 AND 200
                                   AND char_length(coalesce(observaciones, '')) <= 1000)
);
CREATE INDEX beneficiarios_nombre_idx ON beneficiarios_pension (nombre_normalizado text_pattern_ops);
CREATE INDEX beneficiarios_estado_idx ON beneficiarios_pension (estado, ultimo_periodo_id);

-- Relación beneficiario ↔ trabajador (vigencia por periodos; una corrección desactiva y crea otra, nunca borra)
CREATE TABLE beneficiario_trabajadores (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    beneficiario_id           bigint      NOT NULL REFERENCES beneficiarios_pension (id),
    empleado_id               bigint      NOT NULL REFERENCES empleados (id),
    origen                    text        NOT NULL,
    estado                    text        NOT NULL DEFAULT 'ACTIVA',
    evidencia                 jsonb       NOT NULL DEFAULT '{}'::jsonb,
    vigente_desde_periodo_id  bigint      NOT NULL REFERENCES periodos_nomina (id),
    vigente_hasta_periodo_id  bigint      REFERENCES periodos_nomina (id),
    confirmado_por            bigint      REFERENCES usuarios (id),
    confirmado_at             timestamptz,
    motivo                    text,
    desactivado_por           bigint      REFERENCES usuarios (id),
    desactivado_at            timestamptz,
    motivo_desactivacion      text,
    accion_masiva_id          bigint,
    created_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT bt_origen CHECK (origen IN ('NUMERO_EMPLEADO_ARCHIVO', 'CUENTA_CONFIRMADA', 'CONFIRMACION_MANUAL')),
    CONSTRAINT bt_estado CHECK (estado IN ('ACTIVA', 'INACTIVA')),
    CONSTRAINT bt_manual CHECK ((origen = 'CONFIRMACION_MANUAL') = (confirmado_por IS NOT NULL AND confirmado_at IS NOT NULL)
                               AND (origen <> 'CONFIRMACION_MANUAL' OR char_length(btrim(coalesce(motivo, ''))) >= 5)),
    CONSTRAINT bt_desactivacion CHECK ((estado = 'INACTIVA') = (desactivado_at IS NOT NULL)
                                       AND (desactivado_por IS NULL OR char_length(btrim(coalesce(motivo_desactivacion, ''))) >= 5)),
    CONSTRAINT bt_evidencia CHECK (jsonb_typeof(evidencia) = 'object'),
    CONSTRAINT bt_textos CHECK (char_length(coalesce(motivo, '') || coalesce(motivo_desactivacion, '')) <= 2000)
);
CREATE UNIQUE INDEX beneficiario_trabajadores_activa_uk ON beneficiario_trabajadores (beneficiario_id, empleado_id) WHERE estado = 'ACTIVA';
CREATE INDEX beneficiario_trabajadores_empleado_idx ON beneficiario_trabajadores (empleado_id, estado);

CREATE FUNCTION fn_beneficiario_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'beneficiarios_pension' THEN
        IF NEW.origen <> OLD.origen OR NEW.origen_registro_id <> OLD.origen_registro_id OR NEW.nombre_original <> OLD.nombre_original
           OR NEW.confirmado_por IS DISTINCT FROM OLD.confirmado_por OR NEW.confirmado_at IS DISTINCT FROM OLD.confirmado_at
           OR NEW.created_at <> OLD.created_at THEN
            RAISE EXCEPTION 'La identidad de un beneficiario es inmutable' USING ERRCODE = 'check_violation';
        END IF;
        -- Edición DIRECTA de la web (fuera de funciones SECURITY DEFINER, donde current_user ≠ session_user)
        IF fn_es_conexion_web() AND current_user = session_user THEN
            IF NEW.nombre_normalizado <> OLD.nombre_normalizado OR NEW.primer_periodo_id <> OLD.primer_periodo_id
               OR NEW.ultimo_periodo_id <> OLD.ultimo_periodo_id OR NEW.estado <> OLD.estado THEN
                RAISE EXCEPTION 'La aplicación sólo edita las observaciones' USING ERRCODE = 'insufficient_privilege';
            END IF;
            -- La autoría de cualquier cambio es SIEMPRE el actor de la sesión (no se puede omitir para heredar la anterior)
            NEW.actualizado_por := fn_actor_actual();
            IF NEW.actualizado_por IS NULL THEN
                RAISE EXCEPTION 'Cambio sin actor de sesión' USING ERRCODE = 'insufficient_privilege';
            END IF;
        END IF;
        NEW.updated_at := now();
        RETURN NEW;
    END IF;
    -- beneficiario_trabajadores: sólo se desactiva (decisión correctiva), nunca se reescribe ni se reactiva
    IF NEW.beneficiario_id <> OLD.beneficiario_id OR NEW.empleado_id <> OLD.empleado_id OR NEW.origen <> OLD.origen
       OR NEW.evidencia <> OLD.evidencia OR NEW.vigente_desde_periodo_id <> OLD.vigente_desde_periodo_id
       OR NEW.confirmado_por IS DISTINCT FROM OLD.confirmado_por OR NEW.motivo IS DISTINCT FROM OLD.motivo
       OR NEW.created_at <> OLD.created_at OR OLD.estado = 'INACTIVA' THEN
        RAISE EXCEPTION 'Una relación beneficiario–trabajador sólo se desactiva' USING ERRCODE = 'check_violation';
    END IF;
    IF fn_es_conexion_web() THEN
        IF NEW.estado <> 'INACTIVA' OR NEW.desactivado_por IS NULL THEN
            RAISE EXCEPTION 'La relación se desactiva con autor y motivo' USING ERRCODE = 'check_violation';
        END IF;
        NEW.desactivado_at := now();
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER beneficiarios_pension_reglas BEFORE UPDATE ON beneficiarios_pension FOR EACH ROW EXECUTE FUNCTION fn_beneficiario_reglas();
CREATE TRIGGER beneficiario_trabajadores_reglas BEFORE UPDATE ON beneficiario_trabajadores FOR EACH ROW EXECUTE FUNCTION fn_beneficiario_reglas();
CREATE TRIGGER beneficiarios_pension_actor BEFORE INSERT OR UPDATE ON beneficiarios_pension
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('confirmado_por', 'actualizado_por');
CREATE TRIGGER beneficiario_trabajadores_actor BEFORE INSERT OR UPDATE ON beneficiario_trabajadores
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('confirmado_por', 'desactivado_por');
CREATE TRIGGER beneficiarios_pension_sin_borrado BEFORE DELETE ON beneficiarios_pension FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER beneficiarios_pension_sin_truncate BEFORE TRUNCATE ON beneficiarios_pension FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER beneficiario_trabajadores_sin_borrado BEFORE DELETE ON beneficiario_trabajadores FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER beneficiario_trabajadores_sin_truncate BEFORE TRUNCATE ON beneficiario_trabajadores FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- Cuentas del beneficiario = cuentas_bancarias PENSION_BENEFICIARIO ligadas a él (una vez ligada, no cambia de beneficiario)
ALTER TABLE cuentas_bancarias ADD COLUMN beneficiario_id bigint REFERENCES beneficiarios_pension (id);
ALTER TABLE cuentas_bancarias ADD CONSTRAINT cuentas_beneficiario CHECK (beneficiario_id IS NULL OR uso = 'PENSION_BENEFICIARIO');
CREATE INDEX cuentas_bancarias_beneficiario_idx ON cuentas_bancarias (beneficiario_id) WHERE beneficiario_id IS NOT NULL;
CREATE FUNCTION fn_cuenta_beneficiario_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.beneficiario_id IS NOT NULL AND NEW.beneficiario_id IS DISTINCT FROM OLD.beneficiario_id THEN
        RAISE EXCEPTION 'La cuenta ya pertenece a un beneficiario' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cuentas_bancarias_beneficiario BEFORE UPDATE ON cuentas_bancarias FOR EACH ROW EXECUTE FUNCTION fn_cuenta_beneficiario_inmutable();

-- Beneficiario de cada pago de pensión (lo fija el motor por evidencia fuerte o la confirmación manual)
ALTER TABLE registros_tesoreria ADD COLUMN beneficiario_id bigint REFERENCES beneficiarios_pension (id);
ALTER TABLE registros_tesoreria ADD CONSTRAINT tes_beneficiario CHECK (beneficiario_id IS NULL OR categoria = 'PENSION');
CREATE INDEX registros_tesoreria_beneficiario_idx ON registros_tesoreria (beneficiario_id) WHERE beneficiario_id IS NOT NULL;

-- ---------------------------------------------------------------------
-- Acciones masivas (desglose registro por registro; nunca sólo "se confirmaron N")
-- ---------------------------------------------------------------------
CREATE TABLE acciones_masivas (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    accion           text        NOT NULL,
    usuario_id       bigint      NOT NULL REFERENCES usuarios (id),
    motivo           text        NOT NULL,
    registros        integer     NOT NULL,
    importe_total    numeric(16,2) NOT NULL DEFAULT 0,
    empleados        integer     NOT NULL DEFAULT 0,
    beneficiarios    integer     NOT NULL DEFAULT 0,
    cuentas          integer     NOT NULL DEFAULT 0,
    filtros          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    huella           text        NOT NULL,             -- SHA-256 de la selección previsualizada (id:versión)
    created_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT am_accion CHECK (accion IN ('CONFIRMAR_PROPUESTA', 'MARCAR_NO_IDENTIFICADO', 'IGNORAR_SUGERENCIA')),
    CONSTRAINT am_motivo CHECK (char_length(btrim(motivo)) BETWEEN 5 AND 1000),
    CONSTRAINT am_conteos CHECK (registros BETWEEN 1 AND 5000 AND empleados >= 0 AND beneficiarios >= 0 AND cuentas >= 0),
    CONSTRAINT am_huella CHECK (huella ~ '^[0-9a-f]{64}$'),
    CONSTRAINT am_filtros CHECK (jsonb_typeof(filtros) = 'object' AND octet_length(filtros::text) <= 4000)
);
CREATE INDEX acciones_masivas_usuario_idx ON acciones_masivas (usuario_id, id DESC);

CREATE TABLE acciones_masivas_registros (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    accion_masiva_id   bigint      NOT NULL REFERENCES acciones_masivas (id),
    registro_tipo      text        NOT NULL,
    registro_id        bigint      NOT NULL,
    version_anterior   integer     NOT NULL,
    estado_anterior    text        NOT NULL,
    estado_nuevo       text        NOT NULL,
    empleado_anterior  bigint      REFERENCES empleados (id),
    empleado_nuevo     bigint      REFERENCES empleados (id),
    beneficiario_id    bigint      REFERENCES beneficiarios_pension (id),
    cuenta_id          bigint      REFERENCES cuentas_bancarias (id),
    importe            numeric(14,2),
    CONSTRAINT amr_tipo CHECK (registro_tipo IN ('RRHH', 'TESORERIA'))
);
CREATE UNIQUE INDEX acciones_masivas_registros_uk ON acciones_masivas_registros (accion_masiva_id, registro_tipo, registro_id);
CREATE INDEX acciones_masivas_registros_registro_idx ON acciones_masivas_registros (registro_tipo, registro_id);
ALTER TABLE beneficiario_trabajadores ADD CONSTRAINT bt_accion_masiva FOREIGN KEY (accion_masiva_id) REFERENCES acciones_masivas (id);

CREATE TRIGGER acciones_masivas_actor BEFORE INSERT ON acciones_masivas FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_id');
CREATE TRIGGER acciones_masivas_sin_cambios BEFORE UPDATE OR DELETE ON acciones_masivas FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER acciones_masivas_registros_sin_cambios BEFORE UPDATE OR DELETE ON acciones_masivas_registros
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER acciones_masivas_sin_truncate BEFORE TRUNCATE ON acciones_masivas FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER acciones_masivas_registros_sin_truncate BEFORE TRUNCATE ON acciones_masivas_registros FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();
-- El desglose sólo se escribe para una acción del MISMO usuario de la sesión, en la transacción en que se creó
CREATE FUNCTION fn_accion_masiva_registro_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF fn_es_conexion_web() THEN
        -- sentencia propia (el worker nunca llega aquí; ver 0014)
        IF NOT EXISTS (SELECT 1 FROM acciones_masivas a WHERE a.id = NEW.accion_masiva_id AND a.created_at = now()
                          AND a.usuario_id = fn_actor_actual()) THEN
            RAISE EXCEPTION 'El desglose pertenece a una acción masiva del usuario, en esta transacción' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER acciones_masivas_registros_reglas BEFORE INSERT ON acciones_masivas_registros
    FOR EACH ROW EXECUTE FUNCTION fn_accion_masiva_registro_reglas();

ALTER TABLE asociaciones_historial ADD COLUMN accion_masiva_id bigint REFERENCES acciones_masivas (id);
ALTER TABLE asociaciones_historial DROP CONSTRAINT asoc_hist_accion;
ALTER TABLE asociaciones_historial ADD CONSTRAINT asoc_hist_accion CHECK (accion IN ('AUTOMATICA', 'REASOCIACION', 'ASOCIAR',
    'RECHAZAR_SUGERENCIA', 'MARCAR_NO_IDENTIFICADO', 'CONFIRMAR_CUENTA', 'TRASLADO', 'CONFIRMAR_BENEFICIARIO'));
ALTER TABLE asociaciones_historial DROP CONSTRAINT asoc_hist_manual;
ALTER TABLE asociaciones_historial ADD CONSTRAINT asoc_hist_manual CHECK (accion NOT IN ('ASOCIAR', 'RECHAZAR_SUGERENCIA',
    'MARCAR_NO_IDENTIFICADO', 'CONFIRMAR_CUENTA', 'CONFIRMAR_BENEFICIARIO')
    OR (usuario_id IS NOT NULL AND char_length(btrim(coalesce(motivo, ''))) >= 5));
CREATE INDEX asociaciones_historial_masiva_idx ON asociaciones_historial (accion_masiva_id) WHERE accion_masiva_id IS NOT NULL;
-- El historial sólo puede citar una acción masiva del MISMO usuario y de la MISMA transacción
CREATE FUNCTION fn_historial_accion_masiva() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.accion_masiva_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM acciones_masivas a WHERE a.id = NEW.accion_masiva_id
                                                          AND a.usuario_id IS NOT DISTINCT FROM NEW.usuario_id AND a.created_at = now()) THEN
        RAISE EXCEPTION 'El historial cita una acción masiva ajena' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER asociaciones_historial_accion_masiva BEFORE INSERT ON asociaciones_historial
    FOR EACH ROW EXECUTE FUNCTION fn_historial_accion_masiva();
CREATE INDEX asociaciones_historial_fecha_idx ON asociaciones_historial (created_at) WHERE usuario_id IS NOT NULL;

-- ---------------------------------------------------------------------
-- Confirmación manual de beneficiario (misma disciplina que fn_confirmar_cuenta)
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_confirmar_beneficiario(p_registro_id bigint, p_usuario_id bigint, p_beneficiario_id bigint, p_motivo text,
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
        IF v_cuenta.id IS NOT NULL AND v_cuenta.empleado_id <> r.empleado_id THEN
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

-- Clave OPACA de agrupación por cuenta para la revisión masiva: permite ver "N transferencias con la misma CLABE"
-- sin exponer el HMAC. Sólo registros pendientes de decisión y sólo con importación vigente.
CREATE VIEW v_revision_grupos_cuenta AS
SELECT r.id AS registro_id, left(encode(sha256(convert_to('revision:' || r.cuenta_hmac, 'UTF8')), 'hex'), 12) AS grupo_cuenta,
       EXISTS (SELECT 1 FROM cuentas_bancarias c WHERE c.cuenta_hmac = r.cuenta_hmac AND c.estado = 'ACTIVA'
                  AND c.uso = CASE WHEN r.categoria = 'PENSION' THEN 'PENSION_BENEFICIARIO' ELSE 'NOMINA' END) AS cuenta_registrada
  FROM registros_tesoreria r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
 WHERE r.cuenta_hmac IS NOT NULL AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');

-- ---------------------------------------------------------------------
-- Datos existentes: beneficiarios desde las cuentas de pensión ya registradas (mismo trabajador + mismo nombre = mismo beneficiario)
-- ---------------------------------------------------------------------
DO $$
DECLARE
    c   record;
    v_b bigint;
BEGIN
    FOR c IN SELECT cb.*, r.nombre_original AS r_nombre, r.nombre_normalizado AS r_norm, r.archivo_id, r.lote_id, r.fila_origen
               FROM cuentas_bancarias cb JOIN registros_tesoreria r ON r.id = cb.origen_registro_id
              WHERE cb.uso = 'PENSION_BENEFICIARIO' AND r.nombre_normalizado IS NOT NULL ORDER BY cb.id LOOP
        SELECT b.id INTO v_b FROM beneficiarios_pension b JOIN beneficiario_trabajadores bt ON bt.beneficiario_id = b.id AND bt.estado = 'ACTIVA'
         WHERE bt.empleado_id = c.empleado_id AND b.nombre_normalizado = c.r_norm ORDER BY b.id LIMIT 1;
        IF v_b IS NULL THEN
            INSERT INTO beneficiarios_pension (nombre_original, nombre_normalizado, origen, origen_registro_id, confirmado_por, confirmado_at,
                                               primer_periodo_id, ultimo_periodo_id)
            VALUES (left(btrim(coalesce(c.beneficiario_nombre, c.r_nombre)), 200), left(c.r_norm, 200), c.origen, c.origen_registro_id,
                    c.confirmado_por, c.confirmado_at, c.primer_periodo_id, c.ultimo_periodo_id)
            RETURNING id INTO v_b;
            INSERT INTO beneficiario_trabajadores (beneficiario_id, empleado_id, origen, evidencia, vigente_desde_periodo_id, confirmado_por,
                                                   confirmado_at, motivo)
            VALUES (v_b, c.empleado_id, CASE WHEN c.origen = 'CONFIRMACION_MANUAL' THEN 'CONFIRMACION_MANUAL' ELSE 'NUMERO_EMPLEADO_ARCHIVO' END,
                    jsonb_build_object('registro_id', c.origen_registro_id, 'archivo_id', c.archivo_id, 'lote_id', c.lote_id, 'fila', c.fila_origen,
                                       'migracion', '0015'),
                    c.primer_periodo_id, c.confirmado_por, c.confirmado_at,
                    CASE WHEN c.origen = 'CONFIRMACION_MANUAL' THEN coalesce(nullif(btrim(c.confirmacion_motivo), ''), 'Migrado de la confirmación de cuenta') END);
        END IF;
        UPDATE cuentas_bancarias SET beneficiario_id = v_b WHERE id = c.id;
    END LOOP;
END;
$$;
UPDATE registros_tesoreria r SET beneficiario_id = cb.beneficiario_id
  FROM cuentas_bancarias cb
 WHERE r.categoria = 'PENSION' AND r.cuenta_hmac = cb.cuenta_hmac AND cb.uso = 'PENSION_BENEFICIARIO' AND cb.estado = 'ACTIVA'
   AND cb.beneficiario_id IS NOT NULL AND r.empleado_id = cb.empleado_id
   AND r.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL');

-- ---------------------------------------------------------------------
-- Permisos y parámetros
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('beneficiarios.ver',          'beneficiarios', 'Consultar beneficiarios de pensión (cuentas enmascaradas)', false, false),
    ('beneficiarios.confirmar',    'beneficiarios', 'Confirmar beneficiario ↔ trabajador ↔ CLABE (aprendizaje controlado)', false, true),
    ('beneficiarios.editar',       'beneficiarios', 'Editar observaciones y desactivar relaciones de beneficiarios (decisión correctiva)', false, true),
    ('asociaciones.masivo',        'asociaciones', 'Resolver asociaciones pendientes en lote (con previsualización y doble confirmación)', false, true),
    ('reportes.ver',               'reportes', 'Consultar reportes de conciliación (datos sensibles enmascarados)', false, false),
    ('reportes.exportar',          'reportes', 'Exportar reportes (XLSX, CSV, PDF); sin identificadores en claro', false, false),
    ('reportes.exportar_sensible', 'reportes', 'Exportar reportes con RFC/CURP en claro (auditado)', false, true)
ON CONFLICT (codigo) DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo IN ('beneficiarios', 'reportes') OR p.codigo = 'asociaciones.masivo'
 WHERE r.codigo IN ('GOD', 'ADMIN')
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('beneficiarios.ver', 'beneficiarios.confirmar', 'beneficiarios.editar',
    'asociaciones.masivo', 'reportes.ver', 'reportes.exportar')
 WHERE r.codigo = 'ANALISTA'
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('beneficiarios.ver', 'reportes.ver')
 WHERE r.codigo = 'CONSULTA'
ON CONFLICT DO NOTHING;

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('revision.max_registros_masivo', '500', 'entero', 'Máximo de registros por acción masiva', 1, 5000, true),
    ('reportes.max_por_minuto', '10', 'entero', 'Exportaciones de reportes por usuario y minuto', 1, 120, true),
    ('reportes.max_filas', '50000', 'entero', 'Máximo de filas por exportación', 100, 500000, true)
ON CONFLICT (clave) DO NOTHING;

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON beneficiarios_pension, beneficiario_trabajadores, acciones_masivas, acciones_masivas_registros TO conciliarh_web;
GRANT SELECT (registro_id, grupo_cuenta, cuenta_registrada) ON v_revision_grupos_cuenta TO conciliarh_web;
GRANT SELECT (beneficiario_id) ON registros_tesoreria TO conciliarh_web;
GRANT SELECT (beneficiario_id) ON cuentas_bancarias TO conciliarh_web;
GRANT UPDATE (observaciones, actualizado_por) ON beneficiarios_pension TO conciliarh_web;
GRANT UPDATE (estado, vigente_hasta_periodo_id, desactivado_por, desactivado_at, motivo_desactivacion) ON beneficiario_trabajadores TO conciliarh_web;
GRANT INSERT (accion, usuario_id, motivo, registros, importe_total, empleados, beneficiarios, cuentas, filtros, huella) ON acciones_masivas TO conciliarh_web;
GRANT INSERT (accion_masiva_id, registro_tipo, registro_id, version_anterior, estado_anterior, estado_nuevo, empleado_anterior, empleado_nuevo,
              beneficiario_id, cuenta_id, importe) ON acciones_masivas_registros TO conciliarh_web;
GRANT INSERT (accion_masiva_id) ON asociaciones_historial TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_confirmar_beneficiario(bigint, bigint, bigint, text, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_confirmar_beneficiario(bigint, bigint, bigint, text, bigint) TO conciliarh_web;

GRANT SELECT, INSERT ON beneficiarios_pension, beneficiario_trabajadores TO conciliarh_worker;
GRANT UPDATE (ultimo_periodo_id) ON beneficiarios_pension TO conciliarh_worker;
GRANT UPDATE (beneficiario_id) ON registros_tesoreria, cuentas_bancarias TO conciliarh_worker;
