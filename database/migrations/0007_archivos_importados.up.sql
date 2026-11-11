-- =====================================================================
-- 0007 · Archivos importados (un registro por carga dentro de un lote)
--
-- Identidad y duplicidad (docs/IMPORTS.md):
--  * sha256            : SHA-256 del binario ORIGINAL recibido (inmutable).
--  * huella_contenido  : SHA-256 de los datos normalizados relevantes (FASE 3,
--                        contrato versionado en huella_version). Binario ≠ contenido.
--  * clasificacion_duplicidad: PENDIENTE | NUEVO | DUPLICADO_BINARIO |
--                        DUPLICADO_CONTENIDO | POSIBLE_VERSION (no una sola bandera).
--  * Un mismo binario NO puede repetirse dentro del mismo lote; en otro lote se
--    admite sólo con confirmación explícita y queda enlazado (archivo_relacionado_id).
--
-- Máquina de estados (validada por trigger, docs/IMPORTS.md §Estados):
--   RECIBIDO → VERIFICANDO → VERIFICADO → ANALIZANDO → ANALIZADO | ANALIZADO_CON_ADVERTENCIAS
--   VERIFICANDO → RECHAZADO | ERROR | RECIBIDO (cancelación del trabajo)
--   ANALIZANDO  → ERROR | VERIFICADO (reintento)
--   ERROR → RECIBIDO (reprocesar); cualquier estado no ANULADO → ANULADO
-- =====================================================================

CREATE TABLE archivos_importados (
    id                         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid                       uuid        NOT NULL DEFAULT gen_random_uuid(),
    lote_id                    bigint      NOT NULL REFERENCES lotes_importacion (id),
    tipo_documento_id          bigint      NOT NULL REFERENCES tipos_documento (id),
    origen_administrativo_id   bigint      NOT NULL REFERENCES origenes_administrativos (id),
    perfil_importacion_id      bigint      REFERENCES perfiles_importacion (id),
    nombre_original            text        NOT NULL,   -- sólo metadato; nunca se usa como ruta
    extension                  text        NOT NULL,
    mime_declarado             text,
    mime_detectado             text        NOT NULL,
    firma                      text        NOT NULL,
    tamano_bytes               bigint      NOT NULL,
    sha256                     text        NOT NULL,
    huella_contenido           text,
    huella_version             smallint,
    clasificacion_duplicidad   text        NOT NULL DEFAULT 'PENDIENTE',
    archivo_relacionado_id     bigint      REFERENCES archivos_importados (id),
    ruta_relativa              text        NOT NULL,   -- relativa a STORAGE_PATH (portable)
    tiene_macros               boolean     NOT NULL DEFAULT false,
    contenedor                 jsonb,                  -- métricas de inspección (entradas, tamaño descomprimido…)
    estado                     text        NOT NULL DEFAULT 'RECIBIDO',
    proceso_id                 bigint      REFERENCES procesos (id),
    observaciones              text,
    usuario_carga_id           bigint      NOT NULL REFERENCES usuarios (id),
    created_at                 timestamptz NOT NULL DEFAULT now(),
    updated_at                 timestamptz NOT NULL DEFAULT now(),
    verificado_at              timestamptz,
    procesado_at               timestamptz,
    error_codigo               text,
    error_resumen              text,
    anulado_por                bigint      REFERENCES usuarios (id),
    anulado_at                 timestamptz,
    motivo_anulacion           text,
    CONSTRAINT archivos_extension CHECK (extension IN ('xls', 'xlsx', 'xlsm')),
    CONSTRAINT archivos_firma CHECK (firma IN ('OLE2', 'OOXML')),
    CONSTRAINT archivos_firma_coherente CHECK ((firma = 'OLE2') = (extension = 'xls')),
    CONSTRAINT archivos_tamano CHECK (tamano_bytes > 0),
    CONSTRAINT archivos_sha256 CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT archivos_huella CHECK ((huella_contenido IS NULL) = (huella_version IS NULL)
                                      AND (huella_contenido IS NULL OR huella_contenido ~ '^[0-9a-f]{64}$')),
    CONSTRAINT archivos_nombre CHECK (char_length(nombre_original) BETWEEN 1 AND 255
                                      AND nombre_original !~ '[/\\[:cntrl:]]'),
    CONSTRAINT archivos_ruta CHECK (ruta_relativa ~ '^imports/[0-9]+/[0-9]+/[0-9a-f-]{36}/original\.(xls|xlsx|xlsm)$'),
    CONSTRAINT archivos_clasificacion CHECK (clasificacion_duplicidad IN
        ('PENDIENTE', 'NUEVO', 'DUPLICADO_BINARIO', 'DUPLICADO_CONTENIDO', 'POSIBLE_VERSION')),
    CONSTRAINT archivos_relacion CHECK (archivo_relacionado_id IS DISTINCT FROM id
        AND (clasificacion_duplicidad IN ('PENDIENTE', 'NUEVO') OR archivo_relacionado_id IS NOT NULL)),
    CONSTRAINT archivos_estado CHECK (estado IN ('RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'RECHAZADO', 'ANALIZANDO',
        'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ERROR', 'ANULADO')),
    CONSTRAINT archivos_anulacion CHECK ((estado = 'ANULADO') = (anulado_at IS NOT NULL AND anulado_por IS NOT NULL)),
    CONSTRAINT archivos_textos CHECK (coalesce(char_length(observaciones), 0) <= 2000
                                      AND coalesce(char_length(error_resumen), 0) <= 500)
);
CREATE UNIQUE INDEX archivos_uuid_uk ON archivos_importados (uuid);
CREATE UNIQUE INDEX archivos_ruta_uk ON archivos_importados (ruta_relativa);
-- Un mismo binario no se repite dentro de un lote (salvo anulados)
CREATE UNIQUE INDEX archivos_lote_sha_uk ON archivos_importados (lote_id, sha256) WHERE estado <> 'ANULADO';
CREATE INDEX archivos_sha256_idx ON archivos_importados (sha256);
CREATE INDEX archivos_huella_idx ON archivos_importados (huella_contenido) WHERE huella_contenido IS NOT NULL;
CREATE INDEX archivos_lote_idx ON archivos_importados (lote_id, created_at DESC);
CREATE INDEX archivos_estado_idx ON archivos_importados (estado, created_at DESC);
CREATE INDEX archivos_tipo_idx ON archivos_importados (tipo_documento_id);
CREATE INDEX archivos_origen_idx ON archivos_importados (origen_administrativo_id);
CREATE INDEX archivos_usuario_idx ON archivos_importados (usuario_carga_id, created_at DESC);
CREATE INDEX archivos_creado_idx ON archivos_importados (created_at DESC);
CREATE TRIGGER archivos_importados_updated_at BEFORE UPDATE ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- Reglas de integridad que no dependen de PHP
CREATE FUNCTION fn_archivo_reglas() RETURNS trigger
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

    -- Inmutabilidad de la identidad del original
    IF NEW.uuid <> OLD.uuid OR NEW.lote_id <> OLD.lote_id OR NEW.sha256 <> OLD.sha256
       OR NEW.ruta_relativa <> OLD.ruta_relativa OR NEW.tamano_bytes <> OLD.tamano_bytes
       OR NEW.extension <> OLD.extension OR NEW.firma <> OLD.firma OR NEW.nombre_original <> OLD.nombre_original
       OR NEW.usuario_carga_id <> OLD.usuario_carga_id OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'La identidad del archivo original es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.huella_contenido IS NOT NULL AND NEW.huella_contenido IS DISTINCT FROM OLD.huella_contenido
       AND NEW.huella_version IS NOT DISTINCT FROM OLD.huella_version THEN
        RAISE EXCEPTION 'La huella de contenido sólo cambia con una nueva versión del algoritmo' USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.estado IS DISTINCT FROM OLD.estado AND NOT (
           (OLD.estado = 'RECIBIDO'    AND NEW.estado IN ('VERIFICANDO', 'ANULADO'))
        OR (OLD.estado = 'VERIFICANDO' AND NEW.estado IN ('VERIFICADO', 'RECHAZADO', 'ERROR', 'RECIBIDO', 'ANULADO'))
        OR (OLD.estado = 'VERIFICADO'  AND NEW.estado IN ('ANALIZANDO', 'ANULADO'))
        OR (OLD.estado = 'ANALIZANDO'  AND NEW.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ERROR', 'VERIFICADO'))
        OR (OLD.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'RECHAZADO') AND NEW.estado = 'ANULADO')
        OR (OLD.estado = 'ERROR'       AND NEW.estado IN ('RECIBIDO', 'ANULADO'))
    ) THEN
        RAISE EXCEPTION 'Transición de estado inválida: % → %', OLD.estado, NEW.estado USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER archivos_reglas BEFORE INSERT OR UPDATE ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_archivo_reglas();

-- Sincronización con la cola: si el trabajo de verificación termina en ERROR (fallo del
-- worker o del reaper) o es CANCELADO, el archivo refleja ese resultado.
CREATE FUNCTION fn_proceso_a_archivo() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.tipo = 'VERIFICAR_ARCHIVO' AND NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NEW.estado = 'ERROR' THEN
            UPDATE archivos_importados
               SET estado = 'ERROR', error_codigo = coalesce(NEW.error_codigo, 'ERROR_VERIFICACION'),
                   error_resumen = left(coalesce(NEW.error_mensaje, 'La verificación del archivo falló.'), 500)
             WHERE proceso_id = NEW.id AND estado IN ('RECIBIDO', 'VERIFICANDO');
        ELSIF NEW.estado = 'CANCELADO' THEN
            UPDATE archivos_importados SET estado = 'RECIBIDO'
             WHERE proceso_id = NEW.id AND estado = 'VERIFICANDO';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER procesos_a_archivo AFTER UPDATE OF estado ON procesos
    FOR EACH ROW EXECUTE FUNCTION fn_proceso_a_archivo();

-- Resumen por lote: el estado de PROCESAMIENTO se deriva de los archivos (una sola fuente de verdad)
CREATE VIEW v_lotes_resumen AS
SELECT l.id AS lote_id,
       count(a.id) FILTER (WHERE a.estado <> 'ANULADO')                                    AS archivos,
       count(a.id) FILTER (WHERE a.estado IN ('RECIBIDO', 'VERIFICANDO', 'ANALIZANDO'))    AS en_proceso,
       count(a.id) FILTER (WHERE a.estado IN ('VERIFICADO', 'ANALIZADO'))                  AS correctos,
       count(a.id) FILTER (WHERE a.estado = 'ANALIZADO_CON_ADVERTENCIAS')                  AS con_advertencias,
       count(a.id) FILTER (WHERE a.estado IN ('RECHAZADO', 'ERROR'))                       AS con_error,
       count(a.id) FILTER (WHERE a.clasificacion_duplicidad = 'DUPLICADO_BINARIO' AND a.estado <> 'ANULADO') AS duplicados_binarios,
       CASE
         WHEN count(a.id) FILTER (WHERE a.estado <> 'ANULADO') = 0 THEN 'SIN_ARCHIVOS'
         WHEN count(a.id) FILTER (WHERE a.estado IN ('RECIBIDO', 'VERIFICANDO', 'ANALIZANDO')) > 0 THEN 'PROCESANDO'
         WHEN count(a.id) FILTER (WHERE a.estado IN ('RECHAZADO', 'ERROR')) > 0 THEN 'CON_ERRORES'
         WHEN count(a.id) FILTER (WHERE a.estado = 'ANALIZADO_CON_ADVERTENCIAS') > 0 THEN 'CON_ADVERTENCIAS'
         ELSE 'LISTO'
       END AS estado_procesamiento
  FROM lotes_importacion l
  LEFT JOIN archivos_importados a ON a.lote_id = l.id
 GROUP BY l.id;

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON archivos_importados, v_lotes_resumen TO conciliarh_web;
GRANT INSERT (lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension, mime_declarado,
              mime_detectado, firma, tamano_bytes, sha256, clasificacion_duplicidad, archivo_relacionado_id,
              ruta_relativa, tiene_macros, contenedor, observaciones, usuario_carga_id, uuid)
    ON archivos_importados TO conciliarh_web;
GRANT UPDATE (tipo_documento_id, origen_administrativo_id, observaciones, estado, proceso_id, error_codigo,
              error_resumen, anulado_por, anulado_at, motivo_anulacion)
    ON archivos_importados TO conciliarh_web;

-- Worker: lee y actualiza sólo columnas de verificación/análisis; nunca identidad ni clasificación manual.
GRANT SELECT ON archivos_importados TO conciliarh_worker;
GRANT UPDATE (estado, tiene_macros, contenedor, verificado_at, procesado_at, error_codigo, error_resumen,
              perfil_importacion_id, huella_contenido, huella_version, clasificacion_duplicidad, archivo_relacionado_id)
    ON archivos_importados TO conciliarh_worker;
