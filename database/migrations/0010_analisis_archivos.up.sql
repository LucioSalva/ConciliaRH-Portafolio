-- =====================================================================
-- 0010 · Motor de análisis de archivos (FASE 3).
--   - Perfiles de importación reales (familia + variante) y versión de reglas.
--   - Estados del archivo: REVISION_MANUAL; clasificación: SIN_HUELLA y REVISION_MANUAL.
--   - Selección manual de hoja/perfil (invalida la huella por trigger).
--   - analisis_archivo (historial de corridas), archivo_hojas, archivo_columnas,
--     controles_archivo y staging_registros (sólo del análisis vigente).
--   - Encadenamiento VERIFICADO → ANALIZAR_ARCHIVO por trigger (el worker no
--     inserta en la cola).
--   - Permisos analisis.* y parámetros de análisis.
-- 0001–0009 no se editan.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Perfiles: familia/variante y versión de reglas
-- ---------------------------------------------------------------------
ALTER TABLE perfiles_importacion
    ADD COLUMN familia text,
    ADD COLUMN reglas_version smallint NOT NULL DEFAULT 1,
    ADD CONSTRAINT perfiles_familia_formato CHECK (familia IS NULL OR familia ~ '^[A-Z][A-Z0-9_]{1,59}$');

UPDATE perfiles_importacion SET familia = 'TESORERIA_SANTANDER', implementado = true WHERE codigo IN ('TESORERIA_SANTANDER', 'TESORERIA_PENSION_SANTANDER');
UPDATE perfiles_importacion SET familia = 'TESORERIA_INTERBANCARIA', implementado = true WHERE codigo IN ('TESORERIA_INTERBANCARIA', 'TESORERIA_PENSION_INTERBANCARIA');
UPDATE perfiles_importacion SET familia = 'TESORERIA_BBVA', implementado = true WHERE codigo = 'TESORERIA_BBVA';
UPDATE perfiles_importacion SET familia = 'TESORERIA_PENSION_BBVA', implementado = true WHERE codigo = 'TESORERIA_PENSION_BBVA';
UPDATE perfiles_importacion SET familia = 'POLIZA_EGRESO_BANCARIA', implementado = true WHERE codigo = 'POLIZA_EGRESO_BANCARIA';
-- Los genéricos de RRHH quedan como familia documental (no seleccionables): se especializan por grupo.
UPDATE perfiles_importacion SET familia = codigo, activo = false,
       descripcion = coalesce(descripcion, '') || ' (Sustituido en FASE 3 por las variantes POLICIA/BOMBEROS.)'
 WHERE codigo IN ('RRHH_TABULADO', 'RRHH_POLIZA_GLOBAL');

INSERT INTO perfiles_importacion (codigo, tipo_documento_id, nombre, descripcion, familia, implementado)
SELECT v.codigo, t.id, v.nombre, v.descripcion, v.familia, true
  FROM (VALUES
    ('RRHH_TABULADO_POLICIA', 'RRHH_TABULADO', 'Tabulado RRHH · Policía', 'Tabulado individual; grupo determinado por el contenido (área/cargo).', 'RRHH_TABULADO'),
    ('RRHH_TABULADO_BOMBEROS', 'RRHH_TABULADO', 'Tabulado RRHH · Bomberos', 'Tabulado individual; grupo determinado por el contenido (área/cargo).', 'RRHH_TABULADO'),
    ('RRHH_POLIZA_GLOBAL_POLICIA', 'RRHH_POLIZA_GLOBAL', 'Póliza GLOBAL RRHH · Policía', 'Bloques PERCEPCIONES/DEDUCCIONES y pie con NETO.', 'RRHH_POLIZA_GLOBAL'),
    ('RRHH_POLIZA_GLOBAL_BOMBEROS', 'RRHH_POLIZA_GLOBAL', 'Póliza GLOBAL RRHH · Bomberos', 'Bloques PERCEPCIONES/DEDUCCIONES y pie con NETO.', 'RRHH_POLIZA_GLOBAL')
  ) AS v(codigo, tipo, nombre, descripcion, familia)
  JOIN tipos_documento t ON t.codigo = v.tipo;

-- ---------------------------------------------------------------------
-- archivos_importados: estados, clasificación y selección manual
-- ---------------------------------------------------------------------
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_estado;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_estado CHECK (estado IN (
    'RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'RECHAZADO', 'ANALIZANDO', 'ANALIZADO',
    'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL', 'ERROR', 'ANULADO'));
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_clasificacion;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_clasificacion CHECK (clasificacion_duplicidad IN (
    'PENDIENTE', 'NUEVO', 'DUPLICADO_BINARIO', 'DUPLICADO_CONTENIDO', 'POSIBLE_VERSION', 'SIN_HUELLA', 'REVISION_MANUAL'));
-- Sólo las clasificaciones de relación exigen el archivo relacionado
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_relacion;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_relacion CHECK (archivo_relacionado_id IS DISTINCT FROM id
    AND (clasificacion_duplicidad IN ('PENDIENTE', 'NUEVO', 'SIN_HUELLA', 'REVISION_MANUAL') OR archivo_relacionado_id IS NOT NULL));

ALTER TABLE archivos_importados
    ADD COLUMN hoja_manual_indice   smallint,
    ADD COLUMN hoja_manual_nombre   text,
    ADD COLUMN perfil_manual_id     bigint REFERENCES perfiles_importacion (id),
    ADD COLUMN seleccion_manual_por bigint REFERENCES usuarios (id),
    ADD COLUMN seleccion_manual_at  timestamptz,
    ADD CONSTRAINT archivos_hoja_manual CHECK ((hoja_manual_indice IS NULL) = (hoja_manual_nombre IS NULL)
                                               AND (hoja_manual_indice IS NULL OR hoja_manual_indice >= 0)
                                               AND char_length(coalesce(hoja_manual_nombre, '')) <= 255);

-- ---------------------------------------------------------------------
-- Historial de análisis (una fila por corrida) y metadatos del vigente
-- ---------------------------------------------------------------------
CREATE TABLE analisis_archivo (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    archivo_id        bigint      NOT NULL REFERENCES archivos_importados (id),
    numero            integer     NOT NULL,
    proceso_id        bigint      REFERENCES procesos (id),
    vigente           boolean     NOT NULL DEFAULT false,
    resultado         text        NOT NULL,
    motor_version     text        NOT NULL,
    reglas_version    smallint    NOT NULL,
    perfil_id         bigint      REFERENCES perfiles_importacion (id),
    perfil_confianza  text        NOT NULL,
    perfil_origen     text        NOT NULL,
    perfil_puntaje    smallint,
    hoja_indice       smallint,
    hoja_origen       text        NOT NULL,
    total_hojas       smallint    NOT NULL,
    filas_detalle     integer     NOT NULL DEFAULT 0,
    huella_contenido  text,
    huella_version    smallint,
    advertencias      jsonb       NOT NULL DEFAULT '[]'::jsonb,
    evidencia         jsonb       NOT NULL DEFAULT '{}'::jsonb,
    metricas          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    seleccion_manual  jsonb,
    created_at        timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT analisis_numero CHECK (numero > 0),
    CONSTRAINT analisis_resultado CHECK (resultado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL')),
    CONSTRAINT analisis_confianza CHECK (perfil_confianza IN ('ALTA', 'MEDIA', 'BAJA', 'MANUAL', 'NINGUNA')),
    CONSTRAINT analisis_perfil_origen CHECK (perfil_origen IN ('AUTOMATICO', 'MANUAL', 'NINGUNO')),
    CONSTRAINT analisis_hoja_origen CHECK (hoja_origen IN ('AUTOMATICO', 'MANUAL', 'NINGUNA')),
    CONSTRAINT analisis_huella CHECK ((huella_contenido IS NULL) = (huella_version IS NULL)
                                      AND (huella_contenido IS NULL OR huella_contenido ~ '^[0-9a-f]{64}$')),
    CONSTRAINT analisis_advertencias_arreglo CHECK (jsonb_typeof(advertencias) = 'array')
);
CREATE UNIQUE INDEX analisis_archivo_numero_uk ON analisis_archivo (archivo_id, numero);
CREATE UNIQUE INDEX analisis_archivo_vigente_uk ON analisis_archivo (archivo_id) WHERE vigente;
CREATE INDEX analisis_archivo_huella_idx ON analisis_archivo (huella_contenido) WHERE huella_contenido IS NOT NULL;

ALTER TABLE archivos_importados ADD COLUMN analisis_vigente_id bigint REFERENCES analisis_archivo (id);

CREATE TABLE archivo_hojas (
    id                       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    analisis_id              bigint      NOT NULL REFERENCES analisis_archivo (id) ON DELETE CASCADE,
    archivo_id               bigint      NOT NULL REFERENCES archivos_importados (id),
    indice                   smallint    NOT NULL,
    nombre_original          text        NOT NULL,
    visibilidad              text        NOT NULL,
    filas_detectadas         integer     NOT NULL DEFAULT 0,
    columnas_detectadas      integer     NOT NULL DEFAULT 0,
    rango_usado              text,
    dimension_declarada      text,
    cantidad_celdas_no_vacias integer    NOT NULL DEFAULT 0,
    tiene_filtros            boolean     NOT NULL DEFAULT false,
    tiene_filas_ocultas      boolean     NOT NULL DEFAULT false,
    filas_ocultas            integer     NOT NULL DEFAULT 0,
    tiene_columnas_ocultas   boolean     NOT NULL DEFAULT false,
    tiene_formulas           boolean,           -- NULL: el formato (.xls) no expone fórmulas
    cantidad_formulas        integer,
    formulas_sin_cache       integer,
    celdas_combinadas        integer     NOT NULL DEFAULT 0,
    encabezado_fila          integer,           -- base 1, como en Excel
    encabezado_filas         smallint,
    perfil_sugerido_id       bigint      REFERENCES perfiles_importacion (id),
    puntaje_candidata        smallint    NOT NULL DEFAULT 0,
    seleccionada             boolean     NOT NULL DEFAULT false,
    estado                   text        NOT NULL,
    motivo_seleccion         text,
    filas_detalle            integer     NOT NULL DEFAULT 0,
    filas_total              integer     NOT NULL DEFAULT 0,
    advertencias             jsonb       NOT NULL DEFAULT '[]'::jsonb,
    metadata                 jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT hojas_visibilidad CHECK (visibilidad IN ('VISIBLE', 'OCULTA', 'MUY_OCULTA')),
    CONSTRAINT hojas_estado CHECK (estado IN ('SELECCIONADA', 'INCLUIDA', 'CANDIDATA', 'DESCARTADA', 'VACIA', 'AUXILIAR')),
    CONSTRAINT hojas_seleccion CHECK (seleccionada = (estado = 'SELECCIONADA')),
    CONSTRAINT hojas_nombre CHECK (char_length(nombre_original) BETWEEN 1 AND 255),
    CONSTRAINT hojas_advertencias_arreglo CHECK (jsonb_typeof(advertencias) = 'array')
);
CREATE UNIQUE INDEX archivo_hojas_indice_uk ON archivo_hojas (analisis_id, indice);
CREATE UNIQUE INDEX archivo_hojas_seleccionada_uk ON archivo_hojas (analisis_id) WHERE seleccionada;
CREATE INDEX archivo_hojas_archivo_idx ON archivo_hojas (archivo_id);

CREATE TABLE archivo_columnas (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    hoja_id                bigint      NOT NULL REFERENCES archivo_hojas (id) ON DELETE CASCADE,
    indice                 smallint    NOT NULL,          -- base 0
    letra                  text        NOT NULL,
    encabezado_original    text,
    encabezado_normalizado text,
    campo_canonico         text,
    tipo_inferido          text        NOT NULL,
    pct_nulos              numeric(5,2) NOT NULL DEFAULT 0,
    pct_texto              numeric(5,2) NOT NULL DEFAULT 0,
    pct_numerico           numeric(5,2) NOT NULL DEFAULT 0,
    pct_fecha              numeric(5,2) NOT NULL DEFAULT 0,
    identificador_probable text,
    es_auxiliar            boolean     NOT NULL DEFAULT false,
    CONSTRAINT columnas_tipo CHECK (tipo_inferido IN ('TEXTO', 'NUMERO', 'MONEDA', 'FECHA', 'BOOLEANO', 'MIXTO', 'VACIA')),
    CONSTRAINT columnas_identificador CHECK (identificador_probable IS NULL OR identificador_probable IN
        ('RFC', 'CURP', 'CLABE', 'CUENTA', 'NUM_EMPLEADO')),
    CONSTRAINT columnas_encabezado CHECK (char_length(coalesce(encabezado_original, '')) <= 300)
);
CREATE UNIQUE INDEX archivo_columnas_uk ON archivo_columnas (hoja_id, indice);

CREATE TABLE controles_archivo (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    analisis_id      bigint      NOT NULL REFERENCES analisis_archivo (id) ON DELETE CASCADE,
    archivo_id       bigint      NOT NULL REFERENCES archivos_importados (id),
    hoja_id          bigint      REFERENCES archivo_hojas (id) ON DELETE CASCADE,
    codigo           text        NOT NULL,
    descripcion      text        NOT NULL,
    tipo_valor       text        NOT NULL,
    valor_declarado  numeric(18,2),
    valor_calculado  numeric(18,2),
    diferencia       numeric(18,2),
    tolerancia       numeric(18,2),
    estado           text        NOT NULL,
    filas_evidencia  integer[]   NOT NULL DEFAULT '{}',
    mensaje          text,
    detalle          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT controles_codigo CHECK (codigo ~ '^[A-Z][A-Z0-9_]{1,59}$'),
    CONSTRAINT controles_tipo CHECK (tipo_valor IN ('MONEDA', 'ENTERO')),
    CONSTRAINT controles_estado CHECK (estado IN ('CUADRA', 'NO_CUADRA', 'SIN_TOTAL', 'MULTIPLES_TOTALES', 'TOTAL_INVALIDO', 'NO_APLICA'))
);
CREATE UNIQUE INDEX controles_archivo_uk ON controles_archivo (analisis_id, coalesce(hoja_id, 0), codigo);
CREATE INDEX controles_archivo_archivo_idx ON controles_archivo (archivo_id);

-- Staging: una fila por REGISTRO DE DETALLE (nunca TOTAL, vacías ni auxiliares). Sin EAV:
-- los campos normalizados del perfil van en `datos`; las cuentas sólo como HMAC + últimos 4.
CREATE TABLE staging_registros (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    analisis_id      bigint      NOT NULL REFERENCES analisis_archivo (id) ON DELETE CASCADE,
    archivo_id       bigint      NOT NULL REFERENCES archivos_importados (id),
    hoja_id          bigint      NOT NULL REFERENCES archivo_hojas (id) ON DELETE CASCADE,
    perfil_id        bigint      REFERENCES perfiles_importacion (id),
    fila_origen      integer     NOT NULL,          -- base 1, como en Excel
    oculta           boolean     NOT NULL DEFAULT false,
    importe          numeric(14,2),
    importe_estado   text        NOT NULL,
    datos            jsonb       NOT NULL,
    cuenta_hmac      text,
    cuenta_ultimos4  text,
    hash_registro    text        NOT NULL,          -- SHA-256 del registro canónico (igualdad exacta)
    clave_hash       text,                          -- SHA-256 de la clave del perfil (p. ej. empleado+cuenta): coincidencia entre versiones
    CONSTRAINT staging_importe_estado CHECK (importe_estado IN ('OK', 'VACIO', 'INVALIDO', 'NO_APLICA')),
    CONSTRAINT staging_hash CHECK (hash_registro ~ '^[0-9a-f]{64}$' AND (clave_hash IS NULL OR clave_hash ~ '^[0-9a-f]{64}$')),
    CONSTRAINT staging_cuenta_hmac CHECK (cuenta_hmac IS NULL OR cuenta_hmac ~ '^[0-9a-f]{64}$'),
    CONSTRAINT staging_ultimos4 CHECK (cuenta_ultimos4 IS NULL OR cuenta_ultimos4 ~ '^[0-9]{1,4}$')
);
CREATE INDEX staging_registros_analisis_idx ON staging_registros (analisis_id, fila_origen);
CREATE INDEX staging_registros_hash_idx ON staging_registros (hash_registro);
CREATE INDEX staging_registros_clave_idx ON staging_registros (clave_hash) WHERE clave_hash IS NOT NULL;

-- ---------------------------------------------------------------------
-- Reglas del archivo v3 (reemplaza la v2 de 0009)
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_archivo_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.estado <> 'RECIBIDO' THEN
            RAISE EXCEPTION 'Un archivo nuevo debe registrarse como RECIBIDO' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
                        WHERE l.id = NEW.lote_id AND l.estado = 'ABIERTO' AND p.estado = 'ABIERTO') THEN
            RAISE EXCEPTION 'El lote o el periodo no están abiertos' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.uuid <> OLD.uuid OR NEW.lote_id <> OLD.lote_id OR NEW.sha256 <> OLD.sha256
       OR NEW.ruta_relativa <> OLD.ruta_relativa OR NEW.tamano_bytes <> OLD.tamano_bytes
       OR NEW.extension <> OLD.extension OR NEW.firma <> OLD.firma OR NEW.nombre_original <> OLD.nombre_original
       OR NEW.usuario_carga_id <> OLD.usuario_carga_id OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'La identidad del archivo original es inmutable' USING ERRCODE = 'check_violation';
    END IF;

    -- Selección manual de hoja/perfil: sólo en estados analizables; invalida la huella y la
    -- clasificación por contenido (el nuevo análisis las recalcula).
    IF NEW.hoja_manual_indice IS DISTINCT FROM OLD.hoja_manual_indice
       OR NEW.hoja_manual_nombre IS DISTINCT FROM OLD.hoja_manual_nombre
       OR NEW.perfil_manual_id IS DISTINCT FROM OLD.perfil_manual_id THEN
        IF OLD.estado NOT IN ('VERIFICADO', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL') THEN
            RAISE EXCEPTION 'La selección manual no está permitida en el estado %', OLD.estado USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
                        WHERE l.id = NEW.lote_id AND l.estado = 'ABIERTO' AND p.estado = 'ABIERTO') THEN
            RAISE EXCEPTION 'La selección manual exige lote y periodo abiertos' USING ERRCODE = 'check_violation';
        END IF;
        NEW.huella_contenido := NULL;
        NEW.huella_version := NULL;
        IF NEW.clasificacion_duplicidad NOT IN ('DUPLICADO_BINARIO') THEN
            NEW.clasificacion_duplicidad := 'PENDIENTE';
            NEW.archivo_relacionado_id := NULL;
        END IF;
    END IF;

    -- La huella sólo la fija el motor AL CERRAR un análisis (ANALIZANDO → resultado); fuera de eso no cambia.
    -- (Si con la misma versión resulta distinta —otra hoja/perfil elegidos o un ajuste del motor— el trabajo
    -- lo registra como advertencia HUELLA_DISTINTA_MISMA_VERSION y lo audita; nunca en silencio.)
    IF NEW.huella_contenido IS NOT NULL AND NEW.huella_contenido IS DISTINCT FROM OLD.huella_contenido
       AND NOT (OLD.estado = 'ANALIZANDO' AND NEW.estado IS DISTINCT FROM OLD.estado) THEN
        RAISE EXCEPTION 'La huella de contenido sólo se fija al terminar un análisis' USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NOT (
               (OLD.estado = 'RECIBIDO'    AND NEW.estado IN ('VERIFICANDO', 'ERROR', 'ANULADO'))
            OR (OLD.estado = 'VERIFICANDO' AND NEW.estado IN ('VERIFICADO', 'RECHAZADO', 'ERROR', 'RECIBIDO', 'ANULADO'))
            OR (OLD.estado = 'VERIFICADO'  AND NEW.estado IN ('ANALIZANDO', 'ERROR', 'ANULADO'))
            OR (OLD.estado = 'ANALIZANDO'  AND NEW.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL', 'ERROR', 'VERIFICADO'))
            OR (OLD.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL') AND NEW.estado IN ('ANALIZANDO', 'ANULADO'))
            OR (OLD.estado = 'RECHAZADO'   AND NEW.estado = 'ANULADO')
            OR (OLD.estado = 'ERROR'       AND NEW.estado IN ('RECIBIDO', 'ANULADO'))
        ) THEN
            RAISE EXCEPTION 'Transición de estado inválida: % → %', OLD.estado, NEW.estado USING ERRCODE = 'check_violation';
        END IF;
        -- Por pertenencia (no sólo por nombre): cualquier rol miembro de conciliarh_web queda limitado
        IF NEW.estado NOT IN ('RECIBIDO', 'ANULADO') AND pg_has_role(current_user, 'conciliarh_web', 'MEMBER')
           AND NOT coalesce((SELECT rolsuper FROM pg_roles WHERE rolname = current_user), false) THEN
            RAISE EXCEPTION 'El rol web no puede fijar el estado % (reservado al motor)', NEW.estado
                USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    -- Una huella del vigente sin versión no existe; el cambio de estado a análisis no toca identidad
    RETURN NEW;
END;
$$;

-- Encadenamiento: al quedar VERIFICADO se encola ANALIZAR_ARCHIVO en la misma transacción.
-- SECURITY DEFINER: el worker no tiene INSERT sobre procesos; sólo este trigger lo hace.
CREATE FUNCTION fn_archivo_encolar_analisis() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_job     bigint;
    v_n       integer;
    v_timeout integer;
BEGIN
    IF OLD.estado = 'VERIFICANDO' AND NEW.estado = 'VERIFICADO' THEN
        SELECT count(*) + 1 INTO v_n FROM procesos
         WHERE tipo = 'ANALIZAR_ARCHIVO' AND parametros->>'archivo_id' = NEW.id::text;
        SELECT coalesce(nullif(valor, '')::integer, 900) INTO v_timeout FROM configuracion WHERE clave = 'analisis.timeout_segundos';
        INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
        -- Clave única por transición (txid): una verificación nunca queda sin su análisis
        VALUES ('ANALIZAR_ARCHIVO', 1, jsonb_build_object('archivo_id', NEW.id),
                'analizar:' || NEW.uuid || ':v' || v_n || ':' || txid_current(), 60, 3, coalesce(v_timeout, 900), NEW.usuario_carga_id)
        RETURNING id INTO v_job;
        NEW.proceso_id := v_job;
        PERFORM pg_notify('conciliarh_procesos', v_job::text);
    END IF;
    RETURN NEW;
END;
$$;
-- Nombre elegido para ejecutarse DESPUÉS de archivos_reglas (orden alfabético de triggers BEFORE)
CREATE TRIGGER archivos_zz_encolar_analisis BEFORE UPDATE OF estado ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_archivo_encolar_analisis();

-- Sincronización con la cola v3: también ANALIZAR_ARCHIVO
CREATE OR REPLACE FUNCTION fn_proceso_a_archivo() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.estado IS NOT DISTINCT FROM OLD.estado OR NEW.estado NOT IN ('ERROR', 'CANCELADO') THEN
        RETURN NEW;
    END IF;
    IF NEW.tipo = 'VERIFICAR_ARCHIVO' THEN
        UPDATE archivos_importados
           SET estado = 'ERROR',
               error_codigo = CASE WHEN NEW.estado = 'CANCELADO' THEN 'VERIFICACION_CANCELADA'
                                   ELSE coalesce(NEW.error_codigo, 'ERROR_VERIFICACION') END,
               error_resumen = CASE WHEN NEW.estado = 'CANCELADO' THEN 'La verificación fue cancelada; puede reprocesarse.'
                                    ELSE left(coalesce(NEW.error_mensaje, 'La verificación del archivo falló.'), 500) END
         WHERE proceso_id = NEW.id AND estado IN ('RECIBIDO', 'VERIFICANDO');
    ELSIF NEW.tipo = 'ANALIZAR_ARCHIVO' THEN
        -- Un REanálisis fallido o cancelado conserva el análisis vigente (el archivo vuelve a su resultado);
        -- sin análisis previo, el archivo queda en ERROR (reprocesable).
        UPDATE archivos_importados a
           SET estado = coalesce(CASE WHEN a.estado = 'ANALIZANDO' THEN
                                     (SELECT an.resultado FROM analisis_archivo an WHERE an.id = a.analisis_vigente_id) END, 'ERROR'),
               error_codigo = CASE WHEN NEW.estado = 'CANCELADO' THEN 'ANALISIS_CANCELADO'
                                   ELSE coalesce(NEW.error_codigo, 'ERROR_ANALISIS') END,
               error_resumen = CASE WHEN a.analisis_vigente_id IS NOT NULL AND a.estado = 'ANALIZANDO'
                                    THEN 'El reanálisis no terminó; se conserva el análisis anterior.'
                                    WHEN NEW.estado = 'CANCELADO' THEN 'El análisis fue cancelado; puede reprocesarse.'
                                    ELSE left(coalesce(NEW.error_mensaje, 'El análisis del archivo falló.'), 500) END
         WHERE a.proceso_id = NEW.id AND a.estado IN ('VERIFICADO', 'ANALIZANDO');
    END IF;
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------
-- Resumen por lote v3: VERIFICADO = pendiente de análisis; REVISION_MANUAL visible
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_lotes_resumen AS
SELECT l.id AS lote_id,
       count(a.id) FILTER (WHERE a.estado <> 'ANULADO')                                                   AS archivos,
       count(a.id) FILTER (WHERE a.estado IN ('RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'ANALIZANDO'))     AS en_proceso,
       count(a.id) FILTER (WHERE a.estado = 'ANALIZADO')                                                  AS correctos,
       count(a.id) FILTER (WHERE a.estado = 'ANALIZADO_CON_ADVERTENCIAS')                                 AS con_advertencias,
       count(a.id) FILTER (WHERE a.estado IN ('RECHAZADO', 'ERROR'))                                      AS con_error,
       count(a.id) FILTER (WHERE a.clasificacion_duplicidad = 'DUPLICADO_BINARIO' AND a.estado <> 'ANULADO') AS duplicados_binarios,
       CASE
         WHEN count(a.id) FILTER (WHERE a.estado <> 'ANULADO') = 0 THEN 'SIN_ARCHIVOS'
         WHEN count(a.id) FILTER (WHERE a.estado IN ('RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'ANALIZANDO')) > 0 THEN 'PROCESANDO'
         WHEN count(a.id) FILTER (WHERE a.estado IN ('RECHAZADO', 'ERROR')) > 0 THEN 'CON_ERRORES'
         WHEN count(a.id) FILTER (WHERE a.estado = 'REVISION_MANUAL') > 0 THEN 'REVISION_MANUAL'
         WHEN count(a.id) FILTER (WHERE a.estado = 'ANALIZADO_CON_ADVERTENCIAS') > 0 THEN 'CON_ADVERTENCIAS'
         ELSE 'LISTO'
       END AS estado_procesamiento,
       count(a.id) FILTER (WHERE a.estado = 'REVISION_MANUAL')                                            AS en_revision,
       count(a.id) FILTER (WHERE a.clasificacion_duplicidad = 'DUPLICADO_CONTENIDO' AND a.estado <> 'ANULADO') AS duplicados_contenido
  FROM lotes_importacion l
  LEFT JOIN archivos_importados a ON a.lote_id = l.id
 GROUP BY l.id;

-- ---------------------------------------------------------------------
-- Permisos y parámetros
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('analisis.ver',                'analisis', 'Consultar el resultado del análisis (perfil, controles, relación entre archivos)', false, false),
    ('analisis.ver_diagnostico',    'analisis', 'Consultar el diagnóstico técnico: hojas, encabezados, columnas, comparación', false, false),
    ('analisis.reprocesar',         'analisis', 'Volver a ejecutar el análisis de un archivo', false, true),
    ('analisis.seleccionar_hoja',   'analisis', 'Elegir manualmente la hoja principal de un archivo', false, true),
    ('analisis.seleccionar_perfil', 'analisis', 'Elegir manualmente el perfil de importación de un archivo', false, true);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo = 'analisis'
 WHERE r.codigo IN ('GOD', 'ADMIN', 'ANALISTA');
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'analisis.ver'
 WHERE r.codigo = 'CONSULTA';

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('analisis.max_celdas', '1000000', 'entero',
     'Máximo de celdas no vacías que el análisis lee de un libro (protección de memoria)', 10000, 2000000, true),
    ('analisis.timeout_segundos', '900', 'entero',
     'Tiempo máximo de un trabajo ANALIZAR_ARCHIVO', 60, 7200, true),
    ('analisis.reanalisis_espera_segundos', '30', 'entero',
     'Espera mínima entre dos análisis encolados manualmente para el mismo archivo (evita saturar la cola)', 0, 3600, true),
    ('analisis.margen_ambiguedad', '10', 'entero',
     'Diferencia mínima de puntaje entre las dos mejores hojas para elegir sin revisión manual', 1, 50, true);

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON analisis_archivo, archivo_hojas, archivo_columnas, controles_archivo TO conciliarh_web;
-- Staging: el web sólo necesita conteos y hashes (comparación). `datos` (nombres, RFC, CURP) y el HMAC de
-- cuentas NO son legibles por el rol web; la FASE 4 ampliará el acceso de forma explícita.
GRANT SELECT (id, analisis_id, archivo_id, hoja_id, perfil_id, fila_origen, oculta, importe, importe_estado,
              cuenta_ultimos4, hash_registro, clave_hash) ON staging_registros TO conciliarh_web;
GRANT UPDATE (hoja_manual_indice, hoja_manual_nombre, perfil_manual_id, seleccion_manual_por, seleccion_manual_at)
    ON archivos_importados TO conciliarh_web;

-- El worker persiste el análisis vigente y sustituye (borra) los derivados del anterior.
GRANT SELECT, INSERT ON analisis_archivo TO conciliarh_worker;
GRANT UPDATE (vigente) ON analisis_archivo TO conciliarh_worker;
GRANT SELECT, INSERT, DELETE ON archivo_hojas, archivo_columnas, controles_archivo, staging_registros TO conciliarh_worker;
GRANT UPDATE (analisis_vigente_id) ON archivos_importados TO conciliarh_worker;

-- Archivos ya verificados antes de esta migración: se encola su análisis
INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
SELECT 'ANALIZAR_ARCHIVO', 1, jsonb_build_object('archivo_id', a.id), 'analizar:' || a.uuid || ':v1', 60, 3, 900, a.usuario_carga_id
  FROM archivos_importados a WHERE a.estado = 'VERIFICADO'
ON CONFLICT (clave_idempotencia) WHERE clave_idempotencia IS NOT NULL DO NOTHING;
UPDATE archivos_importados a SET proceso_id = p.id
  FROM procesos p
 WHERE p.tipo = 'ANALIZAR_ARCHIVO' AND p.clave_idempotencia = 'analizar:' || a.uuid || ':v1' AND a.estado = 'VERIFICADO';
