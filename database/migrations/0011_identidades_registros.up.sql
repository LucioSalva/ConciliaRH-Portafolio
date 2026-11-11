-- =====================================================================
-- 0011 · Capa maestra de personas, identidades y registros importados (FASE 4).
--   - Catálogo de conceptos de nómina (pensiones por CÓDIGO, configurable).
--   - Empleado maestro + historial de identidad (append-only).
--   - Importaciones normalizadas (una VIGENTE por archivo) y registros definitivos:
--     RRHH (snapshot por quincena + conceptos), Tesorería (nómina/pensión), póliza GLOBAL
--     y póliza de egreso. Trazabilidad archivo → hoja → fila → persona.
--   - Cuentas/CLABE en entidad propia: HMAC (búsqueda) + AES-256-GCM (valor) + últimos 4.
--   - Estados de asociación, incidencias de identidad, historial de asociaciones.
--   - Relaciones entre lotes (CORRIGE/SUSTITUYE/COMPLEMENTA/RELACIONADO).
--   - Encadenamiento ANALIZADO → NORMALIZAR_REGISTROS por trigger.
-- 0001–0010 no se editan. Ver docs/IDENTITY_MATCHING.md.
-- =====================================================================

-- Tablas de historial: sólo inserción (mismo criterio que auditoría).
-- Única excepción: mantenimiento explícito del PROPIETARIO (limpieza de pruebas/desarrollo), igual que auditoría.
CREATE FUNCTION fn_solo_insercion() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_owner text;
BEGIN
    SELECT pg_get_userbyid(relowner) INTO v_owner FROM pg_class WHERE oid = TG_RELID;
    IF current_setting('conciliarh.identidad_mantenimiento', true) = 'on' AND current_user = v_owner THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'La tabla % es de sólo inserción (operación % bloqueada)', TG_TABLE_NAME, TG_OP
        USING ERRCODE = 'insufficient_privilege';
END;
$$;

-- ---------------------------------------------------------------------
-- Staging: la cuenta principal también cifrada (la web nunca la lee)
-- ---------------------------------------------------------------------
ALTER TABLE staging_registros
    ADD COLUMN cuenta_cifrada bytea,
    ADD COLUMN cuenta_key_id  text,
    ADD CONSTRAINT staging_cuenta_cifrada CHECK ((cuenta_cifrada IS NULL) = (cuenta_key_id IS NULL)
                                                 AND (cuenta_cifrada IS NULL OR cuenta_hmac IS NOT NULL));

-- ---------------------------------------------------------------------
-- Conceptos de nómina (catálogo configurable)
-- ---------------------------------------------------------------------
CREATE TABLE conceptos_nomina (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo           text        NOT NULL,
    descripcion      text,
    tipo             text        NOT NULL DEFAULT 'DESCONOCIDO',
    es_pension       boolean     NOT NULL DEFAULT false,
    clasificacion    text        NOT NULL DEFAULT 'INFERIDA',
    evidencia        jsonb       NOT NULL DEFAULT '{}'::jsonb,
    actualizado_por  bigint      REFERENCES usuarios (id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT conceptos_codigo CHECK (codigo ~ '^[0-9]{3}$'),
    CONSTRAINT conceptos_tipo CHECK (tipo IN ('PERCEPCION', 'DEDUCCION', 'DESCONOCIDO')),
    CONSTRAINT conceptos_clasificacion CHECK (clasificacion IN ('CONFIGURADA', 'INFERIDA')),
    CONSTRAINT conceptos_descripcion CHECK (char_length(coalesce(descripcion, '')) <= 200),
    CONSTRAINT conceptos_pension_deduccion CHECK (NOT es_pension OR tipo = 'DEDUCCION')
);
CREATE UNIQUE INDEX conceptos_nomina_codigo_uk ON conceptos_nomina (codigo);
CREATE TRIGGER conceptos_nomina_updated_at BEFORE UPDATE ON conceptos_nomina
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- Pensiones alimenticias: se identifican por CÓDIGO (nunca por texto de la descripción)
INSERT INTO conceptos_nomina (codigo, descripcion, tipo, es_pension, clasificacion) VALUES
    ('044', 'PENSION ALIMENTICIA', 'DEDUCCION', true, 'CONFIGURADA'),
    ('060', 'PENSION ALIMENTICIA M', 'DEDUCCION', true, 'CONFIGURADA'),
    ('171', 'PENSION ALIMENTICIA D', 'DEDUCCION', true, 'CONFIGURADA'),
    ('249', 'PENSION ALIMENTICIA', 'DEDUCCION', true, 'CONFIGURADA'),
    ('263', 'PENSION ALIMENTICIA', 'DEDUCCION', true, 'CONFIGURADA');

-- ---------------------------------------------------------------------
-- Importaciones normalizadas (resultado de NORMALIZAR_REGISTROS por análisis)
-- ---------------------------------------------------------------------
CREATE TABLE importaciones_normalizadas (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    archivo_id                bigint      NOT NULL REFERENCES archivos_importados (id),
    analisis_id               bigint      NOT NULL REFERENCES analisis_archivo (id),
    numero                    integer     NOT NULL,
    estado                    text        NOT NULL DEFAULT 'VIGENTE',
    tipo_registro             text        NOT NULL,
    periodo_id                bigint      NOT NULL REFERENCES periodos_nomina (id),
    lote_id                   bigint      NOT NULL REFERENCES lotes_importacion (id),
    perfil_id                 bigint      NOT NULL REFERENCES perfiles_importacion (id),
    grupo                     text,
    proceso_id                bigint      REFERENCES procesos (id),
    matching_version          smallint    NOT NULL,
    puede_actualizar_maestro  boolean     NOT NULL DEFAULT false,
    motivo_sin_maestro        text,
    registros                 integer     NOT NULL DEFAULT 0,
    metricas                  jsonb       NOT NULL DEFAULT '{}'::jsonb,
    estado_motivo             text,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT importaciones_numero CHECK (numero > 0),
    CONSTRAINT importaciones_estado CHECK (estado IN ('VIGENTE', 'SUSTITUIDA', 'INVALIDADA')),
    CONSTRAINT importaciones_tipo CHECK (tipo_registro IN ('RRHH', 'TESORERIA', 'POLIZA_GLOBAL', 'POLIZA_EGRESO')),
    CONSTRAINT importaciones_grupo CHECK (grupo IS NULL OR grupo IN ('POLICIA', 'BOMBEROS')),
    CONSTRAINT importaciones_maestro CHECK (NOT puede_actualizar_maestro OR tipo_registro = 'RRHH'),
    CONSTRAINT importaciones_metricas_objeto CHECK (jsonb_typeof(metricas) = 'object')
);
CREATE UNIQUE INDEX importaciones_numero_uk ON importaciones_normalizadas (archivo_id, numero);
CREATE UNIQUE INDEX importaciones_vigente_uk ON importaciones_normalizadas (archivo_id) WHERE estado = 'VIGENTE';
CREATE INDEX importaciones_analisis_idx ON importaciones_normalizadas (analisis_id);
CREATE INDEX importaciones_periodo_idx ON importaciones_normalizadas (periodo_id, tipo_registro) WHERE estado = 'VIGENTE';
CREATE TRIGGER importaciones_normalizadas_updated_at BEFORE UPDATE ON importaciones_normalizadas
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- ---------------------------------------------------------------------
-- Empleado maestro
-- ---------------------------------------------------------------------
CREATE TABLE empleados (
    id                      bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    numero_empleado         text        NOT NULL,
    nombre_completo         text        NOT NULL,
    nombre_normalizado      text        NOT NULL,
    apellido_paterno        text,
    apellido_materno        text,
    nombres                 text,
    rfc                     text,
    rfc_valido              boolean     GENERATED ALWAYS AS (coalesce(rfc ~ '^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$', false)) STORED,
    curp                    text,
    curp_valida             boolean     GENERATED ALWAYS AS (coalesce(curp ~ '^[A-Z][AEIOUX][A-Z]{2}[0-9]{6}[HMX](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9][0-9]$', false)) STORED,
    issemym                 text,
    grupo                   text        NOT NULL DEFAULT 'OTRO',
    area                    text,
    departamento            text,
    puesto                  text,
    cargo                   text,
    fecha_ingreso           date,
    fecha_baja              date,
    activo                  boolean     NOT NULL DEFAULT true,
    fuente_creacion         text        NOT NULL DEFAULT 'RRHH_TABULADO',
    confianza_identidad     text        NOT NULL DEFAULT 'ALTA',
    origen_importacion_id   bigint      NOT NULL REFERENCES importaciones_normalizadas (id),
    origen_registro_id      bigint      NOT NULL,
    primer_periodo_id       bigint      NOT NULL REFERENCES periodos_nomina (id),
    ultimo_periodo_id       bigint      NOT NULL REFERENCES periodos_nomina (id),
    ultimo_periodo_fecha    date        NOT NULL,
    observaciones           text,
    actualizado_por         bigint      REFERENCES usuarios (id),
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    -- Mismo criterio que conciliarh/identity/normalize.employee_number: sin espacios, con algún dígito
    CONSTRAINT empleados_numero CHECK (numero_empleado ~ '^[0-9A-Z][0-9A-Z-]{0,19}$' AND numero_empleado ~ '[0-9]'),
    CONSTRAINT empleados_nombre CHECK (char_length(btrim(nombre_completo)) BETWEEN 1 AND 200
                                       AND char_length(nombre_normalizado) BETWEEN 1 AND 200),
    CONSTRAINT empleados_rfc CHECK (rfc IS NULL OR rfc ~ '^[A-ZÑ&0-9]{1,20}$'),
    CONSTRAINT empleados_curp CHECK (curp IS NULL OR curp ~ '^[A-Z0-9]{1,25}$'),
    CONSTRAINT empleados_grupo CHECK (grupo IN ('POLICIA', 'BOMBEROS', 'OTRO')),
    -- Tesorería NUNCA crea empleados: el único origen automático es un tabulado RRHH confiable
    CONSTRAINT empleados_fuente CHECK (fuente_creacion IN ('RRHH_TABULADO')),
    CONSTRAINT empleados_confianza CHECK (confianza_identidad IN ('ALTA', 'MEDIA', 'BAJA')),
    CONSTRAINT empleados_textos CHECK (char_length(coalesce(observaciones, '')) <= 2000
                                       AND char_length(coalesce(area, '') || coalesce(departamento, '') || coalesce(puesto, '') || coalesce(cargo, '')) <= 1000)
);
CREATE UNIQUE INDEX empleados_numero_uk ON empleados (numero_empleado);
-- RFC/CURP SIN unicidad: un duplicado es una incidencia de identidad, no un error de carga
CREATE INDEX empleados_rfc_idx ON empleados (rfc) WHERE rfc IS NOT NULL;
CREATE INDEX empleados_curp_idx ON empleados (curp) WHERE curp IS NOT NULL;
CREATE INDEX empleados_nombre_idx ON empleados (nombre_normalizado text_pattern_ops);
CREATE INDEX empleados_grupo_idx ON empleados (grupo, activo);
CREATE TRIGGER empleados_updated_at BEFORE UPDATE ON empleados
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- El número de empleado y el origen son inmutables (no hay merge en esta fase)
CREATE FUNCTION fn_empleado_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.numero_empleado <> OLD.numero_empleado OR NEW.origen_registro_id <> OLD.origen_registro_id
       OR NEW.origen_importacion_id <> OLD.origen_importacion_id OR NEW.fuente_creacion <> OLD.fuente_creacion
       OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'El número de empleado y el origen del maestro son inmutables' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER empleados_inmutable BEFORE UPDATE ON empleados
    FOR EACH ROW EXECUTE FUNCTION fn_empleado_inmutable();
CREATE TRIGGER empleados_sin_borrado BEFORE DELETE ON empleados
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER empleados_sin_truncate BEFORE TRUNCATE ON empleados
    FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

CREATE TABLE empleado_historial (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    empleado_id       bigint      NOT NULL REFERENCES empleados (id),
    campo             text        NOT NULL,
    valor_anterior    text,
    valor_nuevo       text,
    fuente            text        NOT NULL,
    importacion_id    bigint      REFERENCES importaciones_normalizadas (id),
    registro_rrhh_id  bigint,
    periodo_id        bigint      REFERENCES periodos_nomina (id),
    usuario_id        bigint      REFERENCES usuarios (id),
    proceso_id        bigint      REFERENCES procesos (id),
    motivo            text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT empleado_historial_campo CHECK (campo ~ '^[a-z_]{2,40}$'),
    CONSTRAINT empleado_historial_fuente CHECK (fuente IN ('ALTA_RRHH', 'RRHH_TABULADO', 'MANUAL')),
    CONSTRAINT empleado_historial_manual CHECK (fuente <> 'MANUAL' OR (usuario_id IS NOT NULL AND char_length(btrim(coalesce(motivo, ''))) >= 5)),
    CONSTRAINT empleado_historial_textos CHECK (char_length(coalesce(valor_anterior, '')) <= 300
                                                AND char_length(coalesce(valor_nuevo, '')) <= 300
                                                AND char_length(coalesce(motivo, '')) <= 500)
);
CREATE INDEX empleado_historial_empleado_idx ON empleado_historial (empleado_id, id DESC);
CREATE TRIGGER empleado_historial_solo_insercion BEFORE UPDATE OR DELETE ON empleado_historial
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER empleado_historial_sin_truncate BEFORE TRUNCATE ON empleado_historial
    FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Registros RRHH (snapshot de la quincena por persona)
-- ---------------------------------------------------------------------
CREATE TABLE registros_rrhh (
    id                     bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    importacion_id         bigint      NOT NULL REFERENCES importaciones_normalizadas (id),
    archivo_id             bigint      NOT NULL REFERENCES archivos_importados (id),
    analisis_id            bigint      NOT NULL REFERENCES analisis_archivo (id),
    periodo_id             bigint      NOT NULL REFERENCES periodos_nomina (id),
    lote_id                bigint      NOT NULL REFERENCES lotes_importacion (id),
    perfil_id              bigint      NOT NULL REFERENCES perfiles_importacion (id),
    grupo                  text        NOT NULL,
    -- La hoja del análisis se sustituye al reanalizar: el id puede quedar NULL, el índice y el nombre se conservan
    hoja_id                bigint      REFERENCES archivo_hojas (id) ON DELETE SET NULL,
    hoja_indice            smallint    NOT NULL,
    hoja_nombre            text        NOT NULL,
    fila_origen            integer     NOT NULL,
    oculta                 boolean     NOT NULL DEFAULT false,
    hash_registro          text        NOT NULL,
    -- identificación: ORIGINAL y normalizado
    numero_original        text,
    numero_empleado        text,
    numero_valido          boolean     NOT NULL DEFAULT false,
    nombre_original        text,
    nombre_normalizado     text,
    rfc_original           text,
    rfc                    text,
    rfc_valido             boolean     NOT NULL DEFAULT false,
    curp_original          text,
    curp                   text,
    curp_valida            boolean     NOT NULL DEFAULT false,
    issemym                text,
    folio                  text,
    plaza                  text,
    -- snapshot administrativo de la quincena
    departamento           text,
    area                   text,
    puesto                 text,
    cargo                  text,
    fecha_ingreso          date,
    fecha_baja             date,
    fecha_ingreso_original text,
    fecha_baja_original    text,
    fuente_financiamiento  text,
    clave_programatica     text,
    banco                  text,
    tipo_pago              text,
    nivel                  text,
    horas                  text,
    -- importes declarados por RRHH
    percepciones           numeric(14,2),
    deducciones            numeric(14,2),
    neto                   numeric(14,2),
    importes_validos       boolean     NOT NULL DEFAULT true,
    -- asociación
    empleado_id            bigint      REFERENCES empleados (id),
    asociacion_estado      text        NOT NULL,
    asociacion_metodo      text,
    asociacion_confianza   text        NOT NULL DEFAULT 'NINGUNA',
    asociacion_evidencia   jsonb       NOT NULL DEFAULT '{}'::jsonb,
    asociacion_version     integer     NOT NULL DEFAULT 0,
    rechazados             bigint[]    NOT NULL DEFAULT '{}',
    asociado_por           bigint      REFERENCES usuarios (id),
    asociado_at            timestamptz,
    created_at             timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT rrhh_grupo CHECK (grupo IN ('POLICIA', 'BOMBEROS')),
    CONSTRAINT rrhh_fila CHECK (fila_origen > 0 AND hoja_indice >= 0),
    CONSTRAINT rrhh_hash CHECK (hash_registro ~ '^[0-9a-f]{64}$'),
    CONSTRAINT rrhh_estado CHECK (asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL', 'SUGERENCIA', 'AMBIGUO',
                                                        'CONFLICTO', 'NO_IDENTIFICADO', 'NO_IDENTIFICADO_CONFIRMADO', 'INVALIDO')),
    CONSTRAINT rrhh_metodo CHECK (asociacion_metodo IS NULL OR asociacion_metodo IN ('NUMERO_EMPLEADO', 'RFC', 'CURP',
                                  'CUENTA_CONFIRMADA', 'NOMBRE_EXACTO', 'ALTA_RRHH', 'MANUAL')),
    CONSTRAINT rrhh_confianza CHECK (asociacion_confianza IN ('ALTA', 'MEDIA', 'BAJA', 'MANUAL', 'NINGUNA')),
    -- Asociado ⇔ tiene empleado; una sugerencia NUNCA es asociación definitiva
    CONSTRAINT rrhh_asociado CHECK ((empleado_id IS NOT NULL) = (asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL'))),
    -- Decisión manual ⇔ usuario y fecha (las automáticas nunca llevan usuario)
    CONSTRAINT rrhh_manual CHECK ((asociado_por IS NOT NULL) = (asociacion_estado IN ('ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO'))
                               AND (asociado_por IS NULL) = (asociado_at IS NULL)),
    CONSTRAINT rrhh_evidencia_objeto CHECK (jsonb_typeof(asociacion_evidencia) = 'object')
);
CREATE UNIQUE INDEX registros_rrhh_origen_uk ON registros_rrhh (importacion_id, hoja_indice, fila_origen);
CREATE INDEX registros_rrhh_empleado_idx ON registros_rrhh (empleado_id, periodo_id) WHERE empleado_id IS NOT NULL;
CREATE INDEX registros_rrhh_periodo_idx ON registros_rrhh (periodo_id, grupo);
CREATE INDEX registros_rrhh_archivo_idx ON registros_rrhh (archivo_id, fila_origen);
CREATE INDEX registros_rrhh_pendientes_idx ON registros_rrhh (asociacion_estado, importacion_id)
    WHERE asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');
CREATE INDEX registros_rrhh_hash_idx ON registros_rrhh (archivo_id, hash_registro);

ALTER TABLE empleados ADD CONSTRAINT empleados_origen_registro_fk
    FOREIGN KEY (origen_registro_id) REFERENCES registros_rrhh (id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE empleado_historial ADD CONSTRAINT empleado_historial_registro_fk
    FOREIGN KEY (registro_rrhh_id) REFERENCES registros_rrhh (id) DEFERRABLE INITIALLY DEFERRED;

CREATE TABLE registros_rrhh_conceptos (
    id                    bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    registro_id           bigint      NOT NULL REFERENCES registros_rrhh (id),
    concepto_id           bigint      NOT NULL REFERENCES conceptos_nomina (id),
    codigo                text        NOT NULL,
    descripcion_original  text,
    -- El TIPO (percepción/deducción) y si es pensión se leen del catálogo (fuente única, configurable)
    importe               numeric(14,2),
    importe_estado        text        NOT NULL,
    CONSTRAINT rrhh_conceptos_estado CHECK (importe_estado IN ('OK', 'INVALIDO')),
    CONSTRAINT rrhh_conceptos_importe CHECK ((importe IS NOT NULL) = (importe_estado = 'OK'))
);
CREATE UNIQUE INDEX registros_rrhh_conceptos_uk ON registros_rrhh_conceptos (registro_id, codigo);
CREATE INDEX registros_rrhh_conceptos_concepto_idx ON registros_rrhh_conceptos (concepto_id);

-- ---------------------------------------------------------------------
-- Registros de Tesorería (dispersión de nómina y pensiones)
-- ---------------------------------------------------------------------
CREATE TABLE registros_tesoreria (
    id                     bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    importacion_id         bigint      NOT NULL REFERENCES importaciones_normalizadas (id),
    archivo_id             bigint      NOT NULL REFERENCES archivos_importados (id),
    analisis_id            bigint      NOT NULL REFERENCES analisis_archivo (id),
    periodo_id             bigint      NOT NULL REFERENCES periodos_nomina (id),
    lote_id                bigint      NOT NULL REFERENCES lotes_importacion (id),
    perfil_id              bigint      NOT NULL REFERENCES perfiles_importacion (id),
    categoria              text        NOT NULL,
    banco                  text        NOT NULL,
    hoja_id                bigint      REFERENCES archivo_hojas (id) ON DELETE SET NULL,
    hoja_indice            smallint    NOT NULL,
    hoja_nombre            text        NOT NULL,
    fila_origen            integer     NOT NULL,
    oculta                 boolean     NOT NULL DEFAULT false,
    hash_registro          text        NOT NULL,
    -- identificación ORIGINAL (en pensión el nombre es del BENEFICIARIO)
    numero_original        text,
    numero_empleado        text,
    numero_valido          boolean     NOT NULL DEFAULT false,
    nombre_original        text,
    nombre_normalizado     text,
    apellido_paterno       text,
    apellido_materno       text,
    nombres                text,
    rfc_original           text,
    rfc                    text,
    rfc_valido             boolean     NOT NULL DEFAULT false,
    -- cuenta de abono: nunca en claro
    tipo_cuenta            text,
    cuenta_hmac            text,
    cuenta_ultimos4        text,
    cuenta_cifrada         bytea,
    cuenta_key_id          text,
    banco_receptor         text,
    concepto               text,
    referencia             text,
    importe                numeric(14,2),
    importe_estado         text        NOT NULL,
    -- asociación (en pensión: el TRABAJADOR que genera la pensión)
    empleado_id            bigint      REFERENCES empleados (id),
    asociacion_estado      text        NOT NULL,
    asociacion_metodo      text,
    asociacion_confianza   text        NOT NULL DEFAULT 'NINGUNA',
    asociacion_evidencia   jsonb       NOT NULL DEFAULT '{}'::jsonb,
    asociacion_version     integer     NOT NULL DEFAULT 0,
    rechazados             bigint[]    NOT NULL DEFAULT '{}',
    asociado_por           bigint      REFERENCES usuarios (id),
    asociado_at            timestamptz,
    created_at             timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT tes_categoria CHECK (categoria IN ('NOMINA', 'PENSION')),
    CONSTRAINT tes_banco CHECK (banco IN ('SANTANDER', 'BBVA', 'INTERBANCARIA')),
    CONSTRAINT tes_fila CHECK (fila_origen > 0 AND hoja_indice >= 0),
    CONSTRAINT tes_hash CHECK (hash_registro ~ '^[0-9a-f]{64}$'),
    CONSTRAINT tes_cuenta CHECK ((cuenta_hmac IS NULL OR cuenta_hmac ~ '^[0-9a-f]{64}$')
                                 AND (cuenta_ultimos4 IS NULL OR cuenta_ultimos4 ~ '^[0-9]{1,4}$')
                                 AND (cuenta_cifrada IS NULL) = (cuenta_key_id IS NULL)
                                 AND (cuenta_cifrada IS NULL OR cuenta_hmac IS NOT NULL)
                                 AND (tipo_cuenta IS NULL OR tipo_cuenta IN ('CUENTA', 'CLABE'))),
    CONSTRAINT tes_importe_estado CHECK (importe_estado IN ('OK', 'VACIO', 'INVALIDO', 'NO_APLICA')),
    CONSTRAINT tes_estado CHECK (asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL', 'SUGERENCIA', 'AMBIGUO',
                                                       'CONFLICTO', 'NO_IDENTIFICADO', 'NO_IDENTIFICADO_CONFIRMADO', 'INVALIDO')),
    -- Tesorería no usa ALTA_RRHH: nunca crea empleados
    CONSTRAINT tes_metodo CHECK (asociacion_metodo IS NULL OR asociacion_metodo IN ('NUMERO_EMPLEADO', 'RFC', 'CURP',
                                 'CUENTA_CONFIRMADA', 'NOMBRE_EXACTO', 'MANUAL')),
    CONSTRAINT tes_confianza CHECK (asociacion_confianza IN ('ALTA', 'MEDIA', 'BAJA', 'MANUAL', 'NINGUNA')),
    CONSTRAINT tes_asociado CHECK ((empleado_id IS NOT NULL) = (asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL'))),
    -- Decisión manual ⇔ usuario y fecha (las automáticas nunca llevan usuario)
    CONSTRAINT tes_manual CHECK ((asociado_por IS NOT NULL) = (asociacion_estado IN ('ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO'))
                               AND (asociado_por IS NULL) = (asociado_at IS NULL)),
    -- En pensiones el nombre es del beneficiario: jamás se asocia por nombre
    CONSTRAINT tes_pension_sin_nombre CHECK (NOT (categoria = 'PENSION' AND asociacion_metodo = 'NOMBRE_EXACTO')),
    CONSTRAINT tes_evidencia_objeto CHECK (jsonb_typeof(asociacion_evidencia) = 'object')
);
CREATE UNIQUE INDEX registros_tesoreria_origen_uk ON registros_tesoreria (importacion_id, hoja_indice, fila_origen);
CREATE INDEX registros_tesoreria_empleado_idx ON registros_tesoreria (empleado_id, periodo_id) WHERE empleado_id IS NOT NULL;
CREATE INDEX registros_tesoreria_periodo_idx ON registros_tesoreria (periodo_id, banco, categoria);
CREATE INDEX registros_tesoreria_archivo_idx ON registros_tesoreria (archivo_id, fila_origen);
CREATE INDEX registros_tesoreria_cuenta_idx ON registros_tesoreria (cuenta_hmac) WHERE cuenta_hmac IS NOT NULL;
CREATE INDEX registros_tesoreria_pendientes_idx ON registros_tesoreria (asociacion_estado, importacion_id)
    WHERE asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');
CREATE INDEX registros_tesoreria_hash_idx ON registros_tesoreria (archivo_id, hash_registro);

-- ---------------------------------------------------------------------
-- Cuentas bancarias / CLABE (entidad propia; sólo HMAC + cifrado + últimos 4)
-- ---------------------------------------------------------------------
CREATE TABLE cuentas_bancarias (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    empleado_id            bigint      NOT NULL REFERENCES empleados (id),
    uso                    text        NOT NULL,
    beneficiario_nombre    text,
    tipo                   text        NOT NULL,
    cuenta_hmac            text        NOT NULL,
    cuenta_ultimos4        text        NOT NULL,
    cuenta_cifrada         bytea       NOT NULL,
    key_id                 text        NOT NULL,
    banco                  text,
    origen                 text        NOT NULL,
    estado                 text        NOT NULL DEFAULT 'ACTIVA',
    origen_registro_id     bigint      NOT NULL REFERENCES registros_tesoreria (id),
    confirmado_por         bigint      REFERENCES usuarios (id),
    confirmado_at          timestamptz,
    confirmacion_motivo    text,
    primer_periodo_id      bigint      NOT NULL REFERENCES periodos_nomina (id),
    ultimo_periodo_id      bigint      NOT NULL REFERENCES periodos_nomina (id),
    primera_vista_at       timestamptz NOT NULL DEFAULT now(),
    ultima_vista_at        timestamptz NOT NULL DEFAULT now(),
    veces_vista            integer     NOT NULL DEFAULT 1,
    desactivado_por        bigint      REFERENCES usuarios (id),
    desactivado_at         timestamptz,
    desactivacion_motivo   text,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT cuentas_uso CHECK (uso IN ('NOMINA', 'PENSION_BENEFICIARIO')),
    CONSTRAINT cuentas_tipo CHECK (tipo IN ('CUENTA', 'CLABE')),
    CONSTRAINT cuentas_hmac CHECK (cuenta_hmac ~ '^[0-9a-f]{64}$' AND cuenta_ultimos4 ~ '^[0-9]{1,4}$'),
    CONSTRAINT cuentas_key_id CHECK (key_id ~ '^[a-z0-9_-]{1,16}$'),
    CONSTRAINT cuentas_cifrada CHECK (octet_length(cuenta_cifrada) BETWEEN 29 AND 64 AND get_byte(cuenta_cifrada, 0) = 1),
    CONSTRAINT cuentas_origen CHECK (origen IN ('EVIDENCIA_FUERTE', 'CONFIRMACION_MANUAL')),
    CONSTRAINT cuentas_estado CHECK (estado IN ('ACTIVA', 'INACTIVA')),
    CONSTRAINT cuentas_confirmacion CHECK ((origen = 'CONFIRMACION_MANUAL') = (confirmado_por IS NOT NULL AND confirmado_at IS NOT NULL)),
    CONSTRAINT cuentas_desactivacion CHECK ((estado = 'INACTIVA') = (desactivado_at IS NOT NULL)),
    CONSTRAINT cuentas_textos CHECK (char_length(coalesce(confirmacion_motivo, '') || coalesce(desactivacion_motivo, '')) <= 1000
                                     AND char_length(coalesce(beneficiario_nombre, '')) <= 200),
    CONSTRAINT cuentas_veces CHECK (veces_vista >= 1)
);
-- Una cuenta ACTIVA pertenece a un solo empleado por uso (otro empleado con la misma cuenta = CONFLICTO)
CREATE UNIQUE INDEX cuentas_bancarias_activa_uk ON cuentas_bancarias (cuenta_hmac, uso) WHERE estado = 'ACTIVA';
CREATE INDEX cuentas_bancarias_empleado_idx ON cuentas_bancarias (empleado_id, estado);
CREATE TRIGGER cuentas_bancarias_updated_at BEFORE UPDATE ON cuentas_bancarias
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();
CREATE FUNCTION fn_cuenta_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- La identidad de la cuenta no cambia; la re-encriptación (rotación de llave) sí puede cambiar
    -- el texto cifrado y el key_id, pero nunca el HMAC, el dueño ni el uso.
    IF NEW.empleado_id <> OLD.empleado_id OR NEW.uso <> OLD.uso OR NEW.cuenta_hmac <> OLD.cuenta_hmac
       OR NEW.origen <> OLD.origen OR NEW.origen_registro_id <> OLD.origen_registro_id THEN
        RAISE EXCEPTION 'La identidad de una cuenta registrada es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado = 'INACTIVA' AND NEW.estado = 'ACTIVA' THEN
        RAISE EXCEPTION 'Una cuenta desactivada no se reactiva: confirme de nuevo' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cuentas_bancarias_inmutable BEFORE UPDATE ON cuentas_bancarias
    FOR EACH ROW EXECUTE FUNCTION fn_cuenta_inmutable();
CREATE TRIGGER cuentas_bancarias_sin_borrado BEFORE DELETE ON cuentas_bancarias
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER cuentas_bancarias_sin_truncate BEFORE TRUNCATE ON cuentas_bancarias
    FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Pólizas (tipo documental separado del origen administrativo)
-- ---------------------------------------------------------------------
CREATE TABLE polizas_globales (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    importacion_id  bigint      NOT NULL REFERENCES importaciones_normalizadas (id),
    archivo_id      bigint      NOT NULL REFERENCES archivos_importados (id),
    periodo_id      bigint      NOT NULL REFERENCES periodos_nomina (id),
    lote_id         bigint      NOT NULL REFERENCES lotes_importacion (id),
    grupo           text        NOT NULL,
    hoja_indice     smallint    NOT NULL,
    hoja_nombre     text        NOT NULL,
    total_percepciones numeric(16,2),
    total_deducciones  numeric(16,2),
    lineas          integer     NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT polizas_globales_grupo CHECK (grupo IN ('POLICIA', 'BOMBEROS'))
);
CREATE UNIQUE INDEX polizas_globales_uk ON polizas_globales (importacion_id, hoja_indice);
CREATE INDEX polizas_globales_periodo_idx ON polizas_globales (periodo_id, grupo);

CREATE TABLE polizas_globales_lineas (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    poliza_id       bigint      NOT NULL REFERENCES polizas_globales (id),
    fila_origen     integer     NOT NULL,
    bloque          text        NOT NULL,
    codigo          text,
    concepto_id     bigint      REFERENCES conceptos_nomina (id),
    descripcion     text,
    importe         numeric(16,2),
    importe_estado  text        NOT NULL,
    CONSTRAINT polizas_lineas_bloque CHECK (bloque IN ('PERCEPCIONES', 'DEDUCCIONES')),
    CONSTRAINT polizas_lineas_estado CHECK (importe_estado IN ('OK', 'VACIO', 'INVALIDO'))
);
CREATE UNIQUE INDEX polizas_globales_lineas_uk ON polizas_globales_lineas (poliza_id, fila_origen, bloque);

CREATE TABLE polizas_egreso_lineas (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    importacion_id  bigint      NOT NULL REFERENCES importaciones_normalizadas (id),
    archivo_id      bigint      NOT NULL REFERENCES archivos_importados (id),
    periodo_id      bigint      NOT NULL REFERENCES periodos_nomina (id),
    lote_id         bigint      NOT NULL REFERENCES lotes_importacion (id),
    hoja_indice     smallint    NOT NULL,
    hoja_nombre     text        NOT NULL,
    banco           text,
    fila_origen     integer     NOT NULL,
    cuenta          text,
    subcuenta       text,
    subsubcuenta    text,
    subcuenta_3     text,
    subcuenta_4     text,
    referencia      text,
    concepto        text,
    debe            numeric(16,2),
    haber           numeric(16,2),
    importe_estado  text        NOT NULL,
    CONSTRAINT egreso_banco CHECK (banco IS NULL OR banco IN ('SANTANDER', 'BBVA', 'INTERBANCARIA', 'OTRO')),
    CONSTRAINT egreso_estado CHECK (importe_estado IN ('OK', 'VACIO', 'INVALIDO', 'NO_APLICA'))
);
CREATE UNIQUE INDEX polizas_egreso_lineas_uk ON polizas_egreso_lineas (importacion_id, hoja_indice, fila_origen);
CREATE INDEX polizas_egreso_periodo_idx ON polizas_egreso_lineas (periodo_id, banco);

-- ---------------------------------------------------------------------
-- Incidencias de identidad e historial de asociaciones
-- ---------------------------------------------------------------------
CREATE TABLE incidencias_identidad (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    clave            text        NOT NULL,     -- deduplicación determinística (sin PII)
    tipo             text        NOT NULL,
    severidad        text        NOT NULL,
    estado           text        NOT NULL DEFAULT 'ABIERTA',
    importacion_id   bigint      REFERENCES importaciones_normalizadas (id),
    registro_tipo    text,
    registro_id      bigint,
    empleado_id      bigint      REFERENCES empleados (id),
    detalle          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    resuelto_por     bigint      REFERENCES usuarios (id),
    resuelto_at      timestamptz,
    resolucion       text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT incidencias_tipo CHECK (tipo ~ '^[A-Z][A-Z0-9_]{2,59}$'),
    CONSTRAINT incidencias_severidad CHECK (severidad IN ('INFO', 'ADVERTENCIA', 'ALTA')),
    CONSTRAINT incidencias_estado CHECK (estado IN ('ABIERTA', 'RESUELTA', 'DESCARTADA')),
    CONSTRAINT incidencias_registro CHECK ((registro_tipo IS NULL) = (registro_id IS NULL)
                                           AND (registro_tipo IS NULL OR registro_tipo IN ('RRHH', 'TESORERIA'))),
    CONSTRAINT incidencias_cierre CHECK ((estado = 'ABIERTA') = (resuelto_at IS NULL)),
    CONSTRAINT incidencias_detalle_objeto CHECK (jsonb_typeof(detalle) = 'object'),
    CONSTRAINT incidencias_resolucion CHECK (char_length(coalesce(resolucion, '')) <= 1000)
);
CREATE UNIQUE INDEX incidencias_identidad_clave_uk ON incidencias_identidad (clave);
CREATE INDEX incidencias_identidad_abiertas_idx ON incidencias_identidad (tipo, severidad) WHERE estado = 'ABIERTA';
CREATE INDEX incidencias_identidad_registro_idx ON incidencias_identidad (registro_tipo, registro_id);
CREATE INDEX incidencias_identidad_empleado_idx ON incidencias_identidad (empleado_id) WHERE empleado_id IS NOT NULL;
CREATE INDEX incidencias_identidad_importacion_idx ON incidencias_identidad (importacion_id);
CREATE TRIGGER incidencias_identidad_updated_at BEFORE UPDATE ON incidencias_identidad
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

CREATE TABLE asociaciones_historial (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    registro_tipo     text        NOT NULL,
    registro_id       bigint      NOT NULL,
    accion            text        NOT NULL,
    estado_anterior   text,
    estado_nuevo      text        NOT NULL,
    empleado_anterior bigint      REFERENCES empleados (id),
    empleado_nuevo    bigint      REFERENCES empleados (id),
    metodo            text,
    confianza         text,
    usuario_id        bigint      REFERENCES usuarios (id),
    proceso_id        bigint      REFERENCES procesos (id),
    motivo            text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT asoc_hist_tipo CHECK (registro_tipo IN ('RRHH', 'TESORERIA')),
    CONSTRAINT asoc_hist_accion CHECK (accion IN ('AUTOMATICA', 'REASOCIACION', 'ASOCIAR', 'RECHAZAR_SUGERENCIA',
                                                  'MARCAR_NO_IDENTIFICADO', 'CONFIRMAR_CUENTA', 'TRASLADO')),
    CONSTRAINT asoc_hist_actor CHECK (usuario_id IS NOT NULL OR proceso_id IS NOT NULL),
    CONSTRAINT asoc_hist_manual CHECK (accion NOT IN ('ASOCIAR', 'RECHAZAR_SUGERENCIA', 'MARCAR_NO_IDENTIFICADO', 'CONFIRMAR_CUENTA')
                                       OR (usuario_id IS NOT NULL AND char_length(btrim(coalesce(motivo, ''))) >= 5)),
    CONSTRAINT asoc_hist_motivo CHECK (char_length(coalesce(motivo, '')) <= 1000)
);
CREATE INDEX asociaciones_historial_registro_idx ON asociaciones_historial (registro_tipo, registro_id, id DESC);
CREATE INDEX asociaciones_historial_empleado_idx ON asociaciones_historial (empleado_nuevo) WHERE empleado_nuevo IS NOT NULL;
CREATE TRIGGER asociaciones_historial_solo_insercion BEFORE UPDATE OR DELETE ON asociaciones_historial
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER asociaciones_historial_sin_truncate BEFORE TRUNCATE ON asociaciones_historial
    FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Relaciones entre lotes (la columna de 0006 queda sin uso; se conserva por inmutabilidad)
-- ---------------------------------------------------------------------
CREATE TABLE lotes_relaciones (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    lote_origen_id    bigint      NOT NULL REFERENCES lotes_importacion (id),
    lote_destino_id   bigint      NOT NULL REFERENCES lotes_importacion (id),
    tipo              text        NOT NULL,
    motivo            text        NOT NULL,
    estado            text        NOT NULL DEFAULT 'ACTIVA',
    creado_por        bigint      NOT NULL REFERENCES usuarios (id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    anulado_por       bigint      REFERENCES usuarios (id),
    anulado_at        timestamptz,
    motivo_anulacion  text,
    CONSTRAINT lotes_rel_tipo CHECK (tipo IN ('CORRIGE', 'SUSTITUYE', 'COMPLEMENTA', 'RELACIONADO')),
    CONSTRAINT lotes_rel_distintos CHECK (lote_origen_id <> lote_destino_id),
    CONSTRAINT lotes_rel_estado CHECK (estado IN ('ACTIVA', 'ANULADA')),
    CONSTRAINT lotes_rel_anulacion CHECK ((estado = 'ANULADA') = (anulado_por IS NOT NULL AND anulado_at IS NOT NULL
                                          AND char_length(btrim(coalesce(motivo_anulacion, ''))) >= 5)),
    CONSTRAINT lotes_rel_motivo CHECK (char_length(btrim(motivo)) BETWEEN 5 AND 1000
                                       AND char_length(coalesce(motivo_anulacion, '')) <= 1000)
);
CREATE UNIQUE INDEX lotes_relaciones_activa_uk ON lotes_relaciones (lote_origen_id, lote_destino_id, tipo) WHERE estado = 'ACTIVA';
CREATE INDEX lotes_relaciones_destino_idx ON lotes_relaciones (lote_destino_id);

CREATE FUNCTION fn_lote_relacion_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- Serializa relaciones del mismo par de lotes (la regla contra relaciones inversas no admite carreras)
    PERFORM pg_advisory_xact_lock(least(NEW.lote_origen_id, NEW.lote_destino_id)::integer, greatest(NEW.lote_origen_id, NEW.lote_destino_id)::integer);
    IF NOT EXISTS (SELECT 1 FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
                    WHERE l.id = NEW.lote_origen_id AND p.estado = 'ABIERTO') THEN
        RAISE EXCEPTION 'Las relaciones entre lotes sólo se registran o anulan con el periodo abierto' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' THEN
        -- Ambos lotes del MISMO periodo y no anulados
        IF (SELECT count(DISTINCT periodo_id) FROM lotes_importacion
             WHERE id IN (NEW.lote_origen_id, NEW.lote_destino_id) AND estado <> 'ANULADO') <> 1
           OR (SELECT count(*) FROM lotes_importacion WHERE id IN (NEW.lote_origen_id, NEW.lote_destino_id) AND estado <> 'ANULADO') <> 2 THEN
            RAISE EXCEPTION 'Sólo se relacionan lotes vigentes del mismo periodo' USING ERRCODE = 'check_violation';
        END IF;
        -- Sin ciclos directos de sustitución/corrección (A→B y B→A)
        IF NEW.tipo IN ('CORRIGE', 'SUSTITUYE') AND EXISTS (
            SELECT 1 FROM lotes_relaciones WHERE estado = 'ACTIVA' AND tipo IN ('CORRIGE', 'SUSTITUYE')
               AND lote_origen_id = NEW.lote_destino_id AND lote_destino_id = NEW.lote_origen_id) THEN
            RAISE EXCEPTION 'La relación inversa ya existe' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.lote_origen_id <> OLD.lote_origen_id OR NEW.lote_destino_id <> OLD.lote_destino_id OR NEW.tipo <> OLD.tipo
       OR NEW.motivo <> OLD.motivo OR NEW.creado_por <> OLD.creado_por OR NEW.created_at <> OLD.created_at
       OR (OLD.estado = 'ANULADA') THEN
        RAISE EXCEPTION 'Una relación de lotes sólo puede anularse' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER lotes_relaciones_reglas BEFORE INSERT OR UPDATE ON lotes_relaciones
    FOR EACH ROW EXECUTE FUNCTION fn_lote_relacion_reglas();
CREATE TRIGGER lotes_relaciones_sin_borrado BEFORE DELETE ON lotes_relaciones
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER lotes_relaciones_sin_truncate BEFORE TRUNCATE ON lotes_relaciones
    FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Encadenamiento y vigencia
-- ---------------------------------------------------------------------
-- Al cerrar un análisis con resultado importable se encola NORMALIZAR_REGISTROS (misma transacción).
CREATE FUNCTION fn_archivo_encolar_normalizacion() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_job     bigint;
    v_timeout integer;
BEGIN
    IF OLD.estado = 'ANALIZANDO' AND NEW.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS')
       AND NEW.analisis_vigente_id IS NOT NULL THEN
        SELECT coalesce(nullif(valor, '')::integer, 900) INTO v_timeout FROM configuracion WHERE clave = 'normalizacion.timeout_segundos';
        INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
        VALUES ('NORMALIZAR_REGISTROS', 1, jsonb_build_object('archivo_id', NEW.id),
                'normalizar:' || NEW.analisis_vigente_id || ':' || txid_current(), 70, 3, coalesce(v_timeout, 900), NEW.usuario_carga_id)
        ON CONFLICT (clave_idempotencia) WHERE clave_idempotencia IS NOT NULL DO NOTHING
        RETURNING id INTO v_job;
        IF v_job IS NOT NULL THEN
            PERFORM pg_notify('conciliarh_procesos', v_job::text);
        END IF;
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER archivos_encolar_normalizacion AFTER UPDATE OF estado ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_archivo_encolar_normalizacion();

-- Un nuevo análisis vigente (o la anulación del archivo) invalida la importación normalizada vigente:
-- sus registros dejan de contar hasta que la nueva normalización termine.
CREATE FUNCTION fn_archivo_invalidar_importacion() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.analisis_vigente_id IS DISTINCT FROM OLD.analisis_vigente_id
       OR (NEW.estado = 'ANULADO' AND OLD.estado <> 'ANULADO') THEN
        UPDATE importaciones_normalizadas
           SET estado = 'INVALIDADA',
               estado_motivo = CASE WHEN NEW.estado = 'ANULADO' THEN 'ARCHIVO_ANULADO' ELSE 'NUEVO_ANALISIS' END
         WHERE archivo_id = NEW.id AND estado = 'VIGENTE'
           AND (NEW.estado = 'ANULADO' OR analisis_id IS DISTINCT FROM NEW.analisis_vigente_id);
        UPDATE incidencias_identidad i
           SET estado = 'DESCARTADA', resuelto_at = now(), resolucion = 'Importación invalidada (' ||
               CASE WHEN NEW.estado = 'ANULADO' THEN 'archivo anulado' ELSE 'nuevo análisis' END || ').'
          FROM importaciones_normalizadas n
         WHERE i.importacion_id = n.id AND n.archivo_id = NEW.id AND n.estado = 'INVALIDADA' AND i.estado = 'ABIERTA';
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER archivos_invalidar_importacion AFTER UPDATE OF analisis_vigente_id, estado ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_archivo_invalidar_importacion();

-- Confirmación manual de una cuenta: copia HMAC/cifrado DESDE el registro (PHP nunca maneja la cuenta en
-- claro ni lee su HMAC). Defensa en profundidad frente a un rol web comprometido:
--   * la asociación manual debe haberla hecho ESE usuario en ESTA transacción (asociado_at = now());
--   * el usuario debe estar activo y tener `cuentas.confirmar` (o ser GOD);
--   * importación VIGENTE, periodo ABIERTO y lote no anulado;
--   * la cuenta no puede estar ACTIVA para otra persona.
-- Toma el mismo candado consultivo que la normalización (sin carreras con el motor). Devuelve la cuenta.
CREATE FUNCTION fn_confirmar_cuenta(p_registro_id bigint, p_usuario_id bigint, p_motivo text)
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
        IF v_cuenta.empleado_id <> r.empleado_id THEN
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

-- Importaciones VIGENTES de periodos ABIERTOS con registros pendientes que comparten la cuenta de un registro
-- cuya cuenta YA fue confirmada manualmente (para re-asociar tras la confirmación). No expone el HMAC y no
-- sirve como oráculo de igualdad de cuentas para registros no confirmados.
CREATE FUNCTION fn_importaciones_con_cuenta(p_registro_id bigint)
RETURNS SETOF bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT DISTINCT t.importacion_id
      FROM registros_tesoreria r
      JOIN cuentas_bancarias c ON c.origen_registro_id = r.id AND c.origen = 'CONFIRMACION_MANUAL' AND c.estado = 'ACTIVA'
      JOIN registros_tesoreria t ON t.cuenta_hmac = r.cuenta_hmac AND t.id <> r.id
      JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
      JOIN periodos_nomina pe ON pe.id = n.periodo_id AND pe.estado = 'ABIERTO'
     WHERE r.id = p_registro_id AND r.cuenta_hmac IS NOT NULL
       AND t.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');
$$;

-- ---------------------------------------------------------------------
-- Vista de pendientes (sólo importaciones VIGENTES; sin cuentas)
-- ---------------------------------------------------------------------
CREATE VIEW v_asociaciones_pendientes AS
SELECT 'RRHH'::text AS registro_tipo, r.id AS registro_id, r.importacion_id, r.archivo_id, r.periodo_id, r.lote_id,
       r.perfil_id, r.grupo AS detalle, NULL::text AS categoria, r.fila_origen, r.hoja_nombre,
       r.numero_original, r.nombre_original, r.asociacion_estado, r.asociacion_metodo, r.asociacion_confianza,
       r.asociacion_evidencia, r.neto AS importe, NULL::text AS cuenta_ultimos4, r.created_at
  FROM registros_rrhh r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
 WHERE r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')
UNION ALL
SELECT 'TESORERIA', t.id, t.importacion_id, t.archivo_id, t.periodo_id, t.lote_id,
       t.perfil_id, t.banco, t.categoria, t.fila_origen, t.hoja_nombre,
       t.numero_original, t.nombre_original, t.asociacion_estado, t.asociacion_metodo, t.asociacion_confianza,
       t.asociacion_evidencia, t.importe, t.cuenta_ultimos4, t.created_at
  FROM registros_tesoreria t JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
 WHERE t.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');

-- ---------------------------------------------------------------------
-- Permisos y parámetros
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('empleados.ver',            'empleados',    'Consultar el padrón de empleados (datos sensibles enmascarados)', false, false),
    ('empleados.ver_sensible',   'empleados',    'Ver RFC y CURP completos y buscar por ellos', false, true),
    ('empleados.editar',         'empleados',    'Corregir datos del empleado maestro (con motivo e historial)', false, true),
    ('asociaciones.ver',         'asociaciones', 'Consultar asociaciones registro ↔ empleado e incidencias de identidad', false, false),
    ('asociaciones.resolver',    'asociaciones', 'Asociar manualmente, rechazar sugerencias y marcar registros no identificados', false, true),
    ('cuentas.ver_sensible',     'cuentas',      'Revelar el número completo de una cuenta o CLABE (auditado)', false, true),
    ('cuentas.confirmar',        'cuentas',      'Confirmar la relación empleado ↔ cuenta/CLABE y desactivar cuentas', false, true),
    ('lotes.relacionar',         'lotes',        'Declarar relaciones entre lotes (corrige, sustituye, complementa)', false, true),
    ('importacion.normalizar',   'importacion',  'Volver a normalizar y asociar los registros de un archivo analizado', false, true),
    ('conceptos.editar',         'conceptos',    'Configurar el catálogo de conceptos de nómina (tipo, pensión)', false, true);

-- GOD y ADMIN: todos (explícito; ADMIN no hereda a GOD).
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'empleados.ver', 'empleados.ver_sensible', 'empleados.editar', 'asociaciones.ver', 'asociaciones.resolver',
    'cuentas.ver_sensible', 'cuentas.confirmar', 'lotes.relacionar', 'importacion.normalizar', 'conceptos.editar')
 WHERE r.codigo IN ('GOD', 'ADMIN');
-- ANALISTA: resuelve asociaciones y confirma cuentas, pero NO ve cuentas completas ni relaciona lotes
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'empleados.ver', 'empleados.ver_sensible', 'asociaciones.ver', 'asociaciones.resolver', 'cuentas.confirmar',
    'importacion.normalizar')
 WHERE r.codigo = 'ANALISTA';
-- CONSULTA: sólo lectura enmascarada
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('empleados.ver', 'asociaciones.ver')
 WHERE r.codigo = 'CONSULTA';

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('normalizacion.timeout_segundos', '900', 'entero',
     'Tiempo máximo de un trabajo NORMALIZAR_REGISTROS', 60, 7200, true),
    ('identidad.similitud_minima', '0.85', 'decimal',
     'Parecido mínimo de nombre para MOSTRAR candidatos similares (nunca asocia)', 0.70, 0.99, true);

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON conceptos_nomina, importaciones_normalizadas, empleados, empleado_historial, registros_rrhh,
               registros_rrhh_conceptos, polizas_globales, polizas_globales_lineas, polizas_egreso_lineas,
               incidencias_identidad, asociaciones_historial, lotes_relaciones, v_asociaciones_pendientes TO conciliarh_web;
-- Tesorería: la web NO lee el HMAC ni el texto cifrado de la cuenta del registro
GRANT SELECT (id, importacion_id, archivo_id, analisis_id, periodo_id, lote_id, perfil_id, categoria, banco, hoja_id, hoja_indice,
              hoja_nombre, fila_origen, oculta, hash_registro, numero_original, numero_empleado, numero_valido,
              nombre_original, nombre_normalizado, apellido_paterno, apellido_materno, nombres, rfc_original, rfc,
              rfc_valido, tipo_cuenta, cuenta_ultimos4, banco_receptor, concepto, referencia, importe, importe_estado,
              empleado_id, asociacion_estado, asociacion_metodo, asociacion_confianza, asociacion_evidencia,
              asociacion_version, rechazados, asociado_por, asociado_at, created_at) ON registros_tesoreria TO conciliarh_web;
-- Cuentas: el web lee el cifrado sólo para REVELAR (con permiso y auditoría); nunca el HMAC
GRANT SELECT (id, empleado_id, uso, beneficiario_nombre, tipo, cuenta_ultimos4, cuenta_cifrada, key_id, banco, origen, estado,
              origen_registro_id, confirmado_por, confirmado_at, confirmacion_motivo, primer_periodo_id, ultimo_periodo_id,
              primera_vista_at, ultima_vista_at, veces_vista, desactivado_por, desactivado_at, desactivacion_motivo,
              created_at, updated_at) ON cuentas_bancarias TO conciliarh_web;
GRANT UPDATE (estado, desactivado_por, desactivado_at, desactivacion_motivo) ON cuentas_bancarias TO conciliarh_web;
GRANT UPDATE (empleado_id, asociacion_estado, asociacion_metodo, asociacion_confianza, asociacion_evidencia, asociacion_version,
              rechazados, asociado_por, asociado_at) ON registros_rrhh, registros_tesoreria TO conciliarh_web;
GRANT UPDATE (apellido_paterno, apellido_materno, nombres, rfc, curp, issemym, activo, fecha_baja, observaciones, actualizado_por)
    ON empleados TO conciliarh_web;
GRANT INSERT (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo) ON empleado_historial TO conciliarh_web;
GRANT INSERT (registro_tipo, registro_id, accion, estado_anterior, estado_nuevo, empleado_anterior, empleado_nuevo, metodo,
              confianza, usuario_id, motivo) ON asociaciones_historial TO conciliarh_web;
GRANT UPDATE (estado, resuelto_por, resuelto_at, resolucion) ON incidencias_identidad TO conciliarh_web;
GRANT INSERT (lote_origen_id, lote_destino_id, tipo, motivo, creado_por) ON lotes_relaciones TO conciliarh_web;
GRANT UPDATE (estado, anulado_por, anulado_at, motivo_anulacion) ON lotes_relaciones TO conciliarh_web;
GRANT UPDATE (descripcion, tipo, es_pension, clasificacion, actualizado_por) ON conceptos_nomina TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_confirmar_cuenta(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_confirmar_cuenta(bigint, bigint, text) TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_importaciones_con_cuenta(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_importaciones_con_cuenta(bigint) TO conciliarh_web;

-- Worker: normaliza, asocia y mantiene el maestro. Nunca borra registros (historia completa).
GRANT SELECT, INSERT ON importaciones_normalizadas, empleados, empleado_historial, registros_rrhh, registros_rrhh_conceptos,
                        registros_tesoreria, cuentas_bancarias, polizas_globales, polizas_globales_lineas, polizas_egreso_lineas,
                        incidencias_identidad, asociaciones_historial TO conciliarh_worker;
GRANT SELECT, INSERT, UPDATE ON conceptos_nomina TO conciliarh_worker;
GRANT UPDATE (estado, estado_motivo, registros, metricas, puede_actualizar_maestro, motivo_sin_maestro)
    ON importaciones_normalizadas TO conciliarh_worker;
GRANT UPDATE (nombre_completo, nombre_normalizado, rfc, curp, issemym, grupo, area, departamento, puesto, cargo, fecha_ingreso,
              fecha_baja, activo, ultimo_periodo_id, ultimo_periodo_fecha) ON empleados TO conciliarh_worker;
GRANT UPDATE (empleado_id, asociacion_estado, asociacion_metodo, asociacion_confianza, asociacion_evidencia, asociacion_version,
              rechazados, asociado_por, asociado_at) ON registros_rrhh, registros_tesoreria TO conciliarh_worker;
GRANT UPDATE (ultimo_periodo_id, ultima_vista_at, veces_vista, cuenta_cifrada, key_id) ON cuentas_bancarias TO conciliarh_worker;
GRANT UPDATE (estado, resuelto_at, resolucion, detalle, importacion_id) ON incidencias_identidad TO conciliarh_worker;
GRANT SELECT ON lotes_relaciones TO conciliarh_worker;
GRANT USAGE ON SEQUENCE empleados_id_seq, registros_rrhh_id_seq, registros_tesoreria_id_seq TO conciliarh_worker;
GRANT UPDATE (cuenta_cifrada, cuenta_key_id) ON staging_registros TO conciliarh_worker;

-- ---------------------------------------------------------------------
-- Actualización desde 0.1.0-alpha.3: el staging existente no tiene la cuenta cifrada.
-- Se encola el REANÁLISIS de los archivos ya analizados (resultado determinista, misma huella);
-- al cerrar, el trigger encola su normalización.
-- ---------------------------------------------------------------------
INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
SELECT 'ANALIZAR_ARCHIVO', 1, jsonb_build_object('archivo_id', a.id), 'analizar:' || a.uuid || ':fase4:' || txid_current(), 80, 3, 900, a.usuario_carga_id
  FROM archivos_importados a WHERE a.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS')
ON CONFLICT (clave_idempotencia) WHERE clave_idempotencia IS NOT NULL DO NOTHING;
UPDATE archivos_importados a SET proceso_id = p.id
  FROM procesos p
 WHERE p.tipo = 'ANALIZAR_ARCHIVO' AND p.clave_idempotencia = 'analizar:' || a.uuid || ':fase4:' || txid_current()
   AND a.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS');
