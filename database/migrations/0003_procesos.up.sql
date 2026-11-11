-- =====================================================================
-- 0003 · Cola de trabajos PHP → Python (PostgreSQL como broker)
--
-- Protocolo (ver docs/ARCHITECTURE.md §Cola):
--  * PHP inserta (estado PENDIENTE) y emite NOTIFY conciliarh_procesos.
--  * El worker reclama con UPDATE ... WHERE id = (SELECT ... FOR UPDATE SKIP LOCKED)
--    y recibe un token_bloqueo (lease). Toda escritura posterior del worker
--    exige ese token: un worker "zombi" cuyo trabajo fue recuperado no puede
--    completar ni sobrescribir (fencing).
--  * Latido periódico (heartbeat_at). Un trabajo sin latido > N s o que excede
--    timeout_segundos es recuperado: vuelve a PENDIENTE con backoff o pasa a
--    ERROR si agotó max_intentos. Nunca hay reintentos infinitos.
-- =====================================================================

CREATE TABLE procesos (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tipo                text        NOT NULL,
    contract_version    smallint    NOT NULL DEFAULT 1,
    parametros          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    clave_idempotencia  text,
    estado              text        NOT NULL DEFAULT 'PENDIENTE',
    etapa               text,
    progreso            numeric(5,2) NOT NULL DEFAULT 0,
    mensaje             text,
    prioridad           smallint    NOT NULL DEFAULT 100,
    intentos            smallint    NOT NULL DEFAULT 0,
    max_intentos        smallint    NOT NULL DEFAULT 3,
    timeout_segundos    integer     NOT NULL DEFAULT 1800,
    disponible_desde    timestamptz NOT NULL DEFAULT now(),
    bloqueado_por       text,
    token_bloqueo       uuid,
    bloqueado_at        timestamptz,
    heartbeat_at        timestamptz,
    iniciado_at         timestamptz,
    finalizado_at       timestamptz,
    resultado           jsonb,
    error_codigo        text,
    error_mensaje       text,       -- mensaje comprensible para el usuario
    error_detalle       text,       -- detalle técnico: sólo roles autorizados
    creado_por          bigint      REFERENCES usuarios (id),
    cancelado_por       bigint      REFERENCES usuarios (id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT procesos_tipo_formato CHECK (tipo ~ '^[A-Z][A-Z0-9_]{2,49}$'),
    CONSTRAINT procesos_estado CHECK (estado IN (
        'PENDIENTE', 'EN_EJECUCION', 'COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS', 'ERROR', 'CANCELADO')),
    CONSTRAINT procesos_etapa CHECK (etapa IS NULL OR etapa IN (
        'ANALIZANDO', 'IMPORTANDO', 'NORMALIZANDO', 'CONCILIANDO', 'VALIDANDO', 'EXPORTANDO', 'FINALIZANDO')),
    CONSTRAINT procesos_progreso CHECK (progreso BETWEEN 0 AND 100),
    CONSTRAINT procesos_intentos CHECK (intentos >= 0 AND max_intentos BETWEEN 1 AND 10 AND intentos <= max_intentos),
    CONSTRAINT procesos_timeout CHECK (timeout_segundos BETWEEN 10 AND 86400),
    CONSTRAINT procesos_parametros_objeto CHECK (jsonb_typeof(parametros) = 'object'),
    -- Un trabajo en ejecución siempre tiene dueño y token; fuera de ejecución, nunca.
    CONSTRAINT procesos_bloqueo_coherente CHECK (
        (estado = 'EN_EJECUCION') = (token_bloqueo IS NOT NULL AND bloqueado_por IS NOT NULL))
);
CREATE UNIQUE INDEX procesos_idempotencia_uk ON procesos (clave_idempotencia) WHERE clave_idempotencia IS NOT NULL;
-- Índice para el reclamo: sólo filas pendientes, en orden de prioridad
CREATE INDEX procesos_pendientes_idx ON procesos (prioridad, id) WHERE estado = 'PENDIENTE';
CREATE INDEX procesos_ejecucion_idx ON procesos (heartbeat_at) WHERE estado = 'EN_EJECUCION';
CREATE INDEX procesos_creado_idx ON procesos (created_at DESC);
CREATE TRIGGER procesos_updated_at BEFORE UPDATE ON procesos
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

CREATE TABLE proceso_eventos (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proceso_id  bigint      NOT NULL REFERENCES procesos (id),
    nivel       text        NOT NULL,
    mensaje     text        NOT NULL,
    datos       jsonb,
    creado_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT proceso_eventos_nivel CHECK (nivel IN ('DEBUG', 'INFO', 'WARNING', 'ERROR'))
);
CREATE INDEX proceso_eventos_proceso_idx ON proceso_eventos (proceso_id, id);

-- Registro de workers vivos (para el dashboard y diagnóstico)
CREATE TABLE workers (
    id                 text        PRIMARY KEY,
    hostname           text        NOT NULL,
    pid                integer     NOT NULL,
    version            text        NOT NULL,
    iniciado_at        timestamptz NOT NULL DEFAULT now(),
    ultimo_latido_at   timestamptz NOT NULL DEFAULT now(),
    detenido_at        timestamptz,
    proceso_actual_id  bigint      REFERENCES procesos (id)
);

-- PHP: crea, consulta y cancela (sólo columnas de cancelación).
GRANT SELECT, INSERT ON procesos TO conciliarh_web;
GRANT UPDATE (estado, cancelado_por, finalizado_at, mensaje) ON procesos TO conciliarh_web;
GRANT SELECT, INSERT ON proceso_eventos TO conciliarh_web;
GRANT SELECT ON workers TO conciliarh_web;

-- Worker: toma y actualiza trabajos; nunca los crea ni los borra.
GRANT SELECT, UPDATE ON procesos TO conciliarh_worker;
GRANT SELECT, INSERT ON proceso_eventos TO conciliarh_worker;
GRANT SELECT, INSERT, UPDATE ON workers TO conciliarh_worker;
