-- =====================================================================
-- 0006 · Catálogos documentales, periodos de nómina y lotes de importación
--
-- Decisiones (docs/DATABASE.md §6, docs/IMPORTS.md):
--  * TIPO DOCUMENTAL (qué es el documento) ≠ PERFIL DE IMPORTACIÓN (cómo se lee)
--    ≠ ORIGEN ADMINISTRATIVO (quién lo emite). Tres catálogos independientes.
--  * Periodo = (año, mes numérico, quincena, tipo de nómina). Policía y Bomberos
--    comparten el periodo; el grupo se asociará a cada importación (FASE 4).
--  * Estados del periodo: ABIERTO → CERRADO → (reapertura GOD) ABIERTO; ANULADO.
--    "Conciliado" NO es un estado del periodo: es el resultado de una conciliación
--    (FASE 6) y se mostrará a partir de ella, evitando dos fuentes de verdad.
--  * Lotes: numeración por periodo con contador bajo bloqueo de fila
--    (fn_crear_lote), respaldada por UNIQUE(periodo_id, numero).
--  * El estado de PROCESAMIENTO del lote se deriva de sus archivos (vista
--    v_lotes_resumen); el estado almacenado es sólo su ciclo de vida.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Catálogos
-- ---------------------------------------------------------------------
CREATE TABLE tipos_documento (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo       text        NOT NULL,
    nombre       text        NOT NULL,
    descripcion  text,
    orden        smallint    NOT NULL DEFAULT 100,
    activo       boolean     NOT NULL DEFAULT true,
    es_sistema   boolean     NOT NULL DEFAULT false,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT tipos_documento_codigo_formato CHECK (codigo ~ '^[A-Z][A-Z0-9_]{1,59}$'),
    CONSTRAINT tipos_documento_nombre CHECK (char_length(btrim(nombre)) BETWEEN 2 AND 120)
);
CREATE UNIQUE INDEX tipos_documento_codigo_uk ON tipos_documento (codigo);
CREATE TRIGGER tipos_documento_updated_at BEFORE UPDATE ON tipos_documento
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

CREATE TABLE origenes_administrativos (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo       text        NOT NULL,
    nombre       text        NOT NULL,
    descripcion  text,
    orden        smallint    NOT NULL DEFAULT 100,
    activo       boolean     NOT NULL DEFAULT true,
    es_sistema   boolean     NOT NULL DEFAULT false,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT origenes_codigo_formato CHECK (codigo ~ '^[A-Z][A-Z0-9_]{1,59}$'),
    CONSTRAINT origenes_nombre CHECK (char_length(btrim(nombre)) BETWEEN 2 AND 120)
);
CREATE UNIQUE INDEX origenes_administrativos_codigo_uk ON origenes_administrativos (codigo);
CREATE TRIGGER origenes_administrativos_updated_at BEFORE UPDATE ON origenes_administrativos
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- Perfil de importación: la "receta" de lectura que especializa un tipo documental.
-- Se definen aquí para que la FASE 3 los implemente; implementado=false hasta entonces.
CREATE TABLE perfiles_importacion (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo             text        NOT NULL,
    tipo_documento_id  bigint      NOT NULL REFERENCES tipos_documento (id),
    nombre             text        NOT NULL,
    descripcion        text,
    version            smallint    NOT NULL DEFAULT 1,
    implementado       boolean     NOT NULL DEFAULT false,
    activo             boolean     NOT NULL DEFAULT true,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT perfiles_codigo_formato CHECK (codigo ~ '^[A-Z][A-Z0-9_]{1,59}$')
);
CREATE UNIQUE INDEX perfiles_importacion_codigo_uk ON perfiles_importacion (codigo);
CREATE INDEX perfiles_importacion_tipo_idx ON perfiles_importacion (tipo_documento_id);
CREATE TRIGGER perfiles_importacion_updated_at BEFORE UPDATE ON perfiles_importacion
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

INSERT INTO tipos_documento (codigo, nombre, descripcion, orden, es_sistema) VALUES
    ('RRHH_TABULADO', 'Tabulado individual RRHH', 'Detalle por trabajador: percepciones, deducciones y neto (nivel 1).', 10, true),
    ('RRHH_POLIZA_GLOBAL', 'Póliza global RRHH', 'Póliza contable de nómina con totales por concepto (nivel 2).', 20, true),
    ('POLIZA_EGRESO_BANCARIA', 'Póliza de egreso bancaria', 'Póliza por banco/dispersión; control entre la póliza global y la dispersión.', 30, true),
    ('TESORERIA_DISPERSION', 'Dispersión de Tesorería', 'Archivo de pagos de nómina enviado al banco.', 40, true),
    ('TESORERIA_PENSION', 'Dispersión de pensiones', 'Archivo de pagos de pensiones alimenticias.', 50, true),
    ('OTRO', 'Otro', 'Documento de apoyo no interpretado por el sistema.', 90, true);

INSERT INTO origenes_administrativos (codigo, nombre, descripcion, orden, es_sistema) VALUES
    ('SIN_DEFINIR', 'Sin definir', 'Origen aún no determinado. Valor por omisión.', 10, true),
    ('RRHH', 'Recursos Humanos', NULL, 20, true),
    ('TESORERIA', 'Tesorería', NULL, 30, true),
    ('CONTABILIDAD', 'Contabilidad', NULL, 40, true),
    ('OTRO', 'Otro', NULL, 90, true);

INSERT INTO perfiles_importacion (codigo, tipo_documento_id, nombre, descripcion)
SELECT v.codigo, t.id, v.nombre, v.descripcion
  FROM (VALUES
    ('RRHH_TABULADO', 'RRHH_TABULADO', 'Tabulado RRHH (hoja Tabla)', 'Encabezado con Núm., Nombre, R.F.C., CURP, Percep., Deduc., TOTAL y conceptos CCC.'),
    ('RRHH_POLIZA_GLOBAL', 'RRHH_POLIZA_GLOBAL', 'Póliza contable GLOBAL', 'Bloques PERCEPCIONES/DEDUCCIONES y pie con NETO.'),
    ('POLIZA_EGRESO_BANCARIA', 'POLIZA_EGRESO_BANCARIA', 'Póliza de egreso por banco', 'CUENTA/SCTA/…/CONCEPTO/DEBE/HABER; una hoja por banco.'),
    ('TESORERIA_SANTANDER', 'TESORERIA_DISPERSION', 'Santander · Pago de Nómina', 'Layout "Pago de Nomina" (.xlsm).'),
    ('TESORERIA_BBVA', 'TESORERIA_DISPERSION', 'BBVA · nómina', 'Núm. | Nombre | R.F.C. | IMPORTE | CTA BBVA.'),
    ('TESORERIA_INTERBANCARIA', 'TESORERIA_DISPERSION', 'Interbancaria (layout Santander)', 'Hoja "Inter sin Compro Fiscal FA" (.xls).'),
    ('TESORERIA_PENSION_SANTANDER', 'TESORERIA_PENSION', 'Pensiones · Santander', 'Layout "Pago de Nomina".'),
    ('TESORERIA_PENSION_BBVA', 'TESORERIA_PENSION', 'Pensiones · BBVA', 'Núm. | Nombre | TOTAL | Cuenta.'),
    ('TESORERIA_PENSION_INTERBANCARIA', 'TESORERIA_PENSION', 'Pensiones · Interbancaria', 'Layout interbancario.')
  ) AS v(codigo, tipo, nombre, descripcion)
  JOIN tipos_documento t ON t.codigo = v.tipo;

-- ---------------------------------------------------------------------
-- Periodos de nómina
-- ---------------------------------------------------------------------
CREATE TABLE periodos_nomina (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    anio                 smallint    NOT NULL,
    mes                  smallint    NOT NULL,
    quincena             smallint    NOT NULL,
    tipo                 text        NOT NULL DEFAULT 'ORDINARIA',
    fecha_inicio         date        NOT NULL,
    fecha_fin            date        NOT NULL,
    descripcion          text        NOT NULL,
    estado               text        NOT NULL DEFAULT 'ABIERTO',
    ultimo_numero_lote   integer     NOT NULL DEFAULT 0,
    creado_por           bigint      NOT NULL REFERENCES usuarios (id),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    cerrado_por          bigint      REFERENCES usuarios (id),
    cerrado_at           timestamptz,
    anulado_por          bigint      REFERENCES usuarios (id),
    anulado_at           timestamptz,
    motivo_estado        text,
    CONSTRAINT periodos_anio CHECK (anio BETWEEN 2000 AND 2100),
    CONSTRAINT periodos_mes CHECK (mes BETWEEN 1 AND 12),
    CONSTRAINT periodos_quincena CHECK (quincena IN (1, 2)),
    CONSTRAINT periodos_tipo CHECK (tipo IN ('ORDINARIA', 'EXTRAORDINARIA')),
    CONSTRAINT periodos_estado CHECK (estado IN ('ABIERTO', 'CERRADO', 'ANULADO')),
    CONSTRAINT periodos_descripcion CHECK (char_length(btrim(descripcion)) BETWEEN 3 AND 150),
    CONSTRAINT periodos_contador CHECK (ultimo_numero_lote >= 0),
    -- Las fechas pertenecen al mes del periodo y respetan la quincena
    CONSTRAINT periodos_fechas CHECK (
        fecha_fin >= fecha_inicio
        AND date_trunc('month', fecha_inicio) = make_date(anio, mes, 1)
        AND date_trunc('month', fecha_fin) = make_date(anio, mes, 1)
        AND (quincena = 1 AND extract(day FROM fecha_fin) <= 15
             OR quincena = 2 AND extract(day FROM fecha_inicio) >= 16)),
    CONSTRAINT periodos_cierre_coherente CHECK ((estado = 'CERRADO') = (cerrado_at IS NOT NULL AND cerrado_por IS NOT NULL)),
    CONSTRAINT periodos_anulacion_coherente CHECK ((estado = 'ANULADO') = (anulado_at IS NOT NULL AND anulado_por IS NOT NULL))
);
CREATE UNIQUE INDEX periodos_nomina_uk ON periodos_nomina (anio, mes, quincena, tipo);
CREATE INDEX periodos_nomina_estado_idx ON periodos_nomina (estado, anio DESC, mes DESC);
CREATE TRIGGER periodos_nomina_updated_at BEFORE UPDATE ON periodos_nomina
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- ---------------------------------------------------------------------
-- Lotes de importación
-- ---------------------------------------------------------------------
CREATE TABLE lotes_importacion (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    periodo_id             bigint      NOT NULL REFERENCES periodos_nomina (id),
    numero                 integer     NOT NULL,
    nombre                 text        NOT NULL,
    descripcion            text,
    origen                 text,        -- procedencia descriptiva (p. ej. "Carpeta entregada por Tesorería")
    observaciones          text,
    estado                 text        NOT NULL DEFAULT 'ABIERTO',
    es_oficial             boolean     NOT NULL DEFAULT false,
    declarado_oficial_por  bigint      REFERENCES usuarios (id),
    declarado_oficial_at   timestamptz,
    -- Relación futura entre lotes (sustituye/corrige/complementa). Sin UI en FASE 2.
    lote_relacionado_id    bigint      REFERENCES lotes_importacion (id),
    tipo_relacion          text,
    creado_por             bigint      NOT NULL REFERENCES usuarios (id),
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    cerrado_por            bigint      REFERENCES usuarios (id),
    cerrado_at             timestamptz,
    anulado_por            bigint      REFERENCES usuarios (id),
    anulado_at             timestamptz,
    motivo_estado          text,
    CONSTRAINT lotes_numero CHECK (numero > 0),
    CONSTRAINT lotes_estado CHECK (estado IN ('ABIERTO', 'CERRADO', 'ANULADO')),
    CONSTRAINT lotes_nombre CHECK (char_length(btrim(nombre)) BETWEEN 3 AND 120),
    CONSTRAINT lotes_textos CHECK (coalesce(char_length(descripcion), 0) <= 1000 AND coalesce(char_length(observaciones), 0) <= 2000
                                   AND coalesce(char_length(origen), 0) <= 200),
    CONSTRAINT lotes_relacion CHECK ((lote_relacionado_id IS NULL) = (tipo_relacion IS NULL)
                                     AND (tipo_relacion IS NULL OR tipo_relacion IN ('SUSTITUYE', 'CORRIGE', 'COMPLEMENTA'))
                                     AND lote_relacionado_id IS DISTINCT FROM id),
    CONSTRAINT lotes_oficial_coherente CHECK (es_oficial = (declarado_oficial_por IS NOT NULL AND declarado_oficial_at IS NOT NULL)),
    CONSTRAINT lotes_oficial_no_anulado CHECK (NOT (es_oficial AND estado = 'ANULADO')),
    CONSTRAINT lotes_cierre_coherente CHECK ((estado = 'CERRADO') = (cerrado_at IS NOT NULL AND cerrado_por IS NOT NULL)),
    CONSTRAINT lotes_anulacion_coherente CHECK ((estado = 'ANULADO') = (anulado_at IS NOT NULL AND anulado_por IS NOT NULL))
);
CREATE UNIQUE INDEX lotes_periodo_numero_uk ON lotes_importacion (periodo_id, numero);
-- Como máximo un lote oficial por periodo
CREATE UNIQUE INDEX lotes_un_oficial_por_periodo_uk ON lotes_importacion (periodo_id) WHERE es_oficial;
CREATE INDEX lotes_estado_idx ON lotes_importacion (estado);
CREATE TRIGGER lotes_importacion_updated_at BEFORE UPDATE ON lotes_importacion
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- Un lote sólo puede crearse, o reabrirse, en un periodo ABIERTO.
CREATE FUNCTION fn_lote_periodo_abierto() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' OR (NEW.estado = 'ABIERTO' AND OLD.estado <> 'ABIERTO') THEN
        IF NOT EXISTS (SELECT 1 FROM periodos_nomina WHERE id = NEW.periodo_id AND estado = 'ABIERTO') THEN
            RAISE EXCEPTION 'El periodo no está abierto' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.periodo_id <> OLD.periodo_id THEN
        RAISE EXCEPTION 'Un lote no puede cambiar de periodo' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER lotes_periodo_abierto BEFORE INSERT OR UPDATE ON lotes_importacion
    FOR EACH ROW EXECUTE FUNCTION fn_lote_periodo_abierto();

-- Creación con numeración segura ante concurrencia:
-- el UPDATE del contador toma un bloqueo de fila sobre el periodo; transacciones
-- concurrentes esperan y obtienen el siguiente número. Si la transacción se revierte,
-- el contador también (sin huecos por fallos). UNIQUE(periodo_id, numero) es el respaldo.
CREATE FUNCTION fn_crear_lote(p_periodo_id bigint, p_nombre text, p_descripcion text, p_origen text,
                              p_observaciones text, p_usuario_id bigint)
RETURNS lotes_importacion
-- SECURITY DEFINER: el rol web no tiene privilegio para modificar el contador directamente.
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_numero integer;
    v_lote lotes_importacion;
BEGIN
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

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON tipos_documento, origenes_administrativos, perfiles_importacion TO conciliarh_web, conciliarh_worker;
GRANT INSERT (codigo, nombre, descripcion, orden) ON tipos_documento, origenes_administrativos TO conciliarh_web;
GRANT UPDATE (nombre, descripcion, orden, activo) ON tipos_documento, origenes_administrativos TO conciliarh_web;

GRANT SELECT ON periodos_nomina, lotes_importacion TO conciliarh_web, conciliarh_worker;
GRANT INSERT (anio, mes, quincena, tipo, fecha_inicio, fecha_fin, descripcion, creado_por) ON periodos_nomina TO conciliarh_web;
GRANT UPDATE (fecha_inicio, fecha_fin, descripcion, estado, cerrado_por, cerrado_at, anulado_por, anulado_at,
              motivo_estado) ON periodos_nomina TO conciliarh_web;
GRANT INSERT (periodo_id, numero, nombre, descripcion, origen, observaciones, creado_por) ON lotes_importacion TO conciliarh_web;
GRANT UPDATE (nombre, descripcion, origen, observaciones, estado, es_oficial, declarado_oficial_por, declarado_oficial_at,
              cerrado_por, cerrado_at, anulado_por, anulado_at, motivo_estado) ON lotes_importacion TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_crear_lote(bigint, text, text, text, text, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_crear_lote(bigint, text, text, text, text, bigint) TO conciliarh_web;
