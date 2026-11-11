-- =====================================================================
-- 0013 · Motor de conciliación financiera (FASE 5). Ver docs/CONCILIATION_ENGINE.md.
--   - conciliaciones: una EJECUCIÓN por (periodo, lote, número). Inmutable al terminar; se cierra o se
--     invalida, nunca se reescribe. Una nueva ejecución es otra fila (v1, v2…).
--   - Snapshot: archivos usados/excluidos, controles de los 5 niveles, resultado por empleado (nómina y
--     pensión), fuentes exactas (registro, archivo, fila, importe, empleado asociado AL EJECUTAR) y pagos
--     pendientes de identidad. Huella SHA-256 de todas las entradas + versión del motor.
--   - Incidencias financieras con ciclo de vida propio (resolver ≠ cambiar dinero) e historial append-only.
--   - Relación manual auditada hoja de póliza de egreso ↔ dispersión.
-- 0001–0012 no se editan.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Ejecuciones
-- ---------------------------------------------------------------------
CREATE TABLE conciliaciones (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    periodo_id          bigint      NOT NULL REFERENCES periodos_nomina (id),
    lote_id             bigint      NOT NULL REFERENCES lotes_importacion (id),
    numero              integer     NOT NULL,
    estado              text        NOT NULL DEFAULT 'PENDIENTE',
    motor_version       smallint,
    tolerancia          numeric(10,2) NOT NULL,
    lote_oficial        boolean     NOT NULL,          -- snapshot al solicitar
    proceso_id          bigint      REFERENCES procesos (id),
    solicitado_por      bigint      NOT NULL REFERENCES usuarios (id),
    motivo_solicitud    text,
    huella_entradas     text,
    igual_a_id          bigint      REFERENCES conciliaciones (id),  -- ejecución previa con la misma huella y versión
    totales             jsonb       NOT NULL DEFAULT '{}'::jsonb,
    resumen             jsonb       NOT NULL DEFAULT '{}'::jsonb,
    metricas            jsonb       NOT NULL DEFAULT '{}'::jsonb,
    error_codigo        text,
    error_mensaje       text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    iniciada_at         timestamptz,
    terminada_at        timestamptz,
    cerrada_por         bigint      REFERENCES usuarios (id),
    cerrada_at          timestamptz,
    motivo_cierre       text,
    invalidada_por      bigint      REFERENCES usuarios (id),
    invalidada_at       timestamptz,
    motivo_invalidacion text,
    CONSTRAINT conciliaciones_numero CHECK (numero > 0),
    CONSTRAINT conciliaciones_estado CHECK (estado IN ('PENDIENTE', 'EJECUTANDO', 'COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS',
                                                       'REVISION_MANUAL', 'ERROR', 'INVALIDADA', 'CERRADA')),
    CONSTRAINT conciliaciones_tolerancia CHECK (tolerancia >= 0 AND tolerancia <= 100),
    CONSTRAINT conciliaciones_huella CHECK (huella_entradas IS NULL OR huella_entradas ~ '^[0-9a-f]{64}$'),
    -- CERRADA exige datos de cierre; una cerrada que después se invalida CONSERVA su cierre (historial)
    CONSTRAINT conciliaciones_cierre CHECK ((estado <> 'CERRADA' OR (cerrada_por IS NOT NULL AND cerrada_at IS NOT NULL))
                                           AND (cerrada_por IS NULL OR estado IN ('CERRADA', 'INVALIDADA'))
                                           AND ((cerrada_por IS NULL) = (cerrada_at IS NULL))),
    CONSTRAINT conciliaciones_invalidacion CHECK ((estado = 'INVALIDADA') = (invalidada_por IS NOT NULL AND invalidada_at IS NOT NULL)),
    CONSTRAINT conciliaciones_terminada CHECK (estado IN ('PENDIENTE', 'EJECUTANDO', 'ERROR') OR terminada_at IS NOT NULL
                                               OR estado = 'INVALIDADA'),
    CONSTRAINT conciliaciones_textos CHECK (char_length(coalesce(motivo_solicitud, '') || coalesce(motivo_cierre, '')
                                                        || coalesce(motivo_invalidacion, '') || coalesce(error_mensaje, '')) <= 3000),
    CONSTRAINT conciliaciones_objetos CHECK (jsonb_typeof(totales) = 'object' AND jsonb_typeof(resumen) = 'object'
                                             AND jsonb_typeof(metricas) = 'object')
);
CREATE UNIQUE INDEX conciliaciones_numero_uk ON conciliaciones (lote_id, numero);
-- Como máximo una ejecución en curso por lote
CREATE UNIQUE INDEX conciliaciones_en_curso_uk ON conciliaciones (lote_id) WHERE estado IN ('PENDIENTE', 'EJECUTANDO');
CREATE INDEX conciliaciones_periodo_idx ON conciliaciones (periodo_id, lote_id, numero DESC);
CREATE INDEX conciliaciones_estado_idx ON conciliaciones (estado);

-- Transiciones válidas y congelamiento (una ejecución terminada no cambia; una cerrada sólo se invalida por GOD)
CREATE FUNCTION fn_conciliacion_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.estado <> 'PENDIENTE' THEN
            RAISE EXCEPTION 'Una conciliación nueva inicia en PENDIENTE' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM lotes_importacion WHERE id = NEW.lote_id AND periodo_id = NEW.periodo_id AND estado <> 'ANULADO') THEN
            RAISE EXCEPTION 'El lote no pertenece al periodo o está anulado' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.periodo_id <> OLD.periodo_id OR NEW.lote_id <> OLD.lote_id OR NEW.numero <> OLD.numero OR NEW.tolerancia <> OLD.tolerancia
       OR NEW.solicitado_por <> OLD.solicitado_por OR NEW.created_at <> OLD.created_at OR NEW.lote_oficial <> OLD.lote_oficial THEN
        RAISE EXCEPTION 'La identidad de una conciliación es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NOT (
               (OLD.estado = 'PENDIENTE'  AND NEW.estado IN ('EJECUTANDO', 'ERROR', 'INVALIDADA'))
            OR (OLD.estado = 'EJECUTANDO' AND NEW.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'ERROR', 'PENDIENTE'))
            OR (OLD.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL') AND NEW.estado IN ('CERRADA', 'INVALIDADA'))
            OR (OLD.estado = 'ERROR'      AND NEW.estado = 'INVALIDADA')
            OR (OLD.estado = 'CERRADA'    AND NEW.estado = 'INVALIDADA')
        ) THEN
            RAISE EXCEPTION 'Transición de conciliación inválida: % → %', OLD.estado, NEW.estado USING ERRCODE = 'check_violation';
        END IF;
        -- La web sólo cierra o invalida; ERROR únicamente cuando su trabajo terminó en ERROR/CANCELADO (fn_proceso_a_conciliacion)
        IF fn_es_conexion_web() AND NOT (NEW.estado IN ('CERRADA', 'INVALIDADA')
               OR (NEW.estado = 'ERROR' AND EXISTS (SELECT 1 FROM procesos p WHERE p.id = NEW.proceso_id AND p.estado IN ('ERROR', 'CANCELADO')))) THEN
            RAISE EXCEPTION 'La aplicación no fija el estado % de una conciliación', NEW.estado USING ERRCODE = 'insufficient_privilege';
        END IF;
        -- Sólo se cierra la última ejecución vigente del lote (el historial nunca tiene dos «versiones oficiales»)
        IF NEW.estado = 'CERRADA' AND EXISTS (SELECT 1 FROM conciliaciones x WHERE x.lote_id = NEW.lote_id AND x.numero > NEW.numero
                                                 AND x.estado NOT IN ('INVALIDADA', 'ERROR')) THEN
            RAISE EXCEPTION 'Sólo se cierra la ejecución más reciente del lote' USING ERRCODE = 'check_violation';
        END IF;
        -- Invalidar una conciliación CERRADA es exclusivo de GOD (defensa en profundidad del permiso de la aplicación)
        IF OLD.estado = 'CERRADA' AND fn_es_conexion_web() AND NOT EXISTS (
               SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = fn_actor_actual() AND r.codigo = 'GOD') THEN
            RAISE EXCEPTION 'Sólo GOD invalida una conciliación cerrada' USING ERRCODE = 'insufficient_privilege';
        END IF;
        -- Sólo el motor fija los estados de ejecución
        IF NEW.estado IN ('EJECUTANDO', 'COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL') AND fn_es_conexion_web() THEN
            RAISE EXCEPTION 'El estado % lo fija el motor de conciliación', NEW.estado USING ERRCODE = 'insufficient_privilege';
        END IF;
    ELSIF OLD.estado NOT IN ('PENDIENTE', 'EJECUTANDO') THEN
        RAISE EXCEPTION 'Una conciliación terminada no se modifica (sólo se cierra o se invalida)' USING ERRCODE = 'check_violation';
    END IF;
    -- Autoría de cierre e invalidación: se fija una vez, en su transición, y nunca se borra ni se reescribe
    IF OLD.cerrada_por IS NOT NULL AND (NEW.cerrada_por IS DISTINCT FROM OLD.cerrada_por OR NEW.cerrada_at IS DISTINCT FROM OLD.cerrada_at
                                        OR NEW.motivo_cierre IS DISTINCT FROM OLD.motivo_cierre)
       OR OLD.cerrada_por IS NULL AND (NEW.cerrada_por IS NOT NULL OR NEW.cerrada_at IS NOT NULL OR NEW.motivo_cierre IS DISTINCT FROM OLD.motivo_cierre)
          AND NOT (NEW.estado = 'CERRADA' AND OLD.estado <> 'CERRADA') THEN
        RAISE EXCEPTION 'Los datos de cierre se registran sólo al cerrar y no se modifican' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.invalidada_por IS NOT NULL AND (NEW.invalidada_por IS DISTINCT FROM OLD.invalidada_por OR NEW.invalidada_at IS DISTINCT FROM OLD.invalidada_at
                                           OR NEW.motivo_invalidacion IS DISTINCT FROM OLD.motivo_invalidacion)
       OR OLD.invalidada_por IS NULL AND (NEW.invalidada_por IS NOT NULL OR NEW.invalidada_at IS NOT NULL
                                          OR NEW.motivo_invalidacion IS DISTINCT FROM OLD.motivo_invalidacion)
          AND NOT (NEW.estado = 'INVALIDADA' AND OLD.estado <> 'INVALIDADA') THEN
        RAISE EXCEPTION 'Los datos de invalidación se registran sólo al invalidar y no se modifican' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.proceso_id IS NOT NULL AND NEW.proceso_id IS DISTINCT FROM OLD.proceso_id THEN
        RAISE EXCEPTION 'El trabajo de una conciliación no se reasigna' USING ERRCODE = 'check_violation';
    END IF;
    -- La hora de cierre/invalidación la pone la BD, no el cliente
    IF fn_es_conexion_web() THEN
        IF NEW.cerrada_por IS NOT NULL AND OLD.cerrada_por IS NULL THEN NEW.cerrada_at := now(); END IF;
        IF NEW.invalidada_por IS NOT NULL AND OLD.invalidada_por IS NULL THEN NEW.invalidada_at := now(); END IF;
    END IF;
    IF OLD.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA', 'ERROR')
       AND (NEW.totales <> OLD.totales OR NEW.resumen <> OLD.resumen OR NEW.huella_entradas IS DISTINCT FROM OLD.huella_entradas
            OR NEW.motor_version IS DISTINCT FROM OLD.motor_version) THEN
        RAISE EXCEPTION 'Los resultados de una conciliación terminada no se modifican' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER conciliaciones_reglas BEFORE INSERT OR UPDATE ON conciliaciones
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_reglas();
CREATE TRIGGER conciliaciones_actor BEFORE INSERT OR UPDATE ON conciliaciones
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('solicitado_por', 'cerrada_por', 'invalidada_por');
CREATE TRIGGER conciliaciones_sin_borrado BEFORE DELETE ON conciliaciones
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER conciliaciones_sin_truncate BEFORE TRUNCATE ON conciliaciones
    FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- Las tablas de resultados sólo reciben filas mientras la ejecución está EJECUTANDO (luego son inmutables)
CREATE FUNCTION fn_conciliacion_resultado_escribible() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Los resultados de una conciliación son de sólo inserción' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM conciliaciones WHERE id = NEW.conciliacion_id AND estado = 'EJECUTANDO') THEN
        RAISE EXCEPTION 'Sólo una conciliación en ejecución recibe resultados' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------
-- Snapshot de entradas: qué archivo/importación se usó o se excluyó y por qué (completitud)
-- ---------------------------------------------------------------------
CREATE TABLE conciliacion_archivos (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conciliacion_id  bigint      NOT NULL REFERENCES conciliaciones (id),
    archivo_id       bigint      NOT NULL REFERENCES archivos_importados (id),
    importacion_id   bigint      REFERENCES importaciones_normalizadas (id),
    tipo_registro    text,
    rol              text        NOT NULL,
    grupo            text,
    banco            text,
    categoria        text,
    registros        integer     NOT NULL DEFAULT 0,
    total_detalle    numeric(16,2),
    total_declarado  numeric(16,2),
    motivo           text,
    CONSTRAINT conc_archivos_rol CHECK (rol IN ('FUENTE_RRHH', 'TESORERIA_NOMINA', 'TESORERIA_PENSION', 'POLIZA_GLOBAL', 'POLIZA_EGRESO',
                                                'EXCLUIDO_DERIVADO', 'EXCLUIDO_DUPLICADO', 'EXCLUIDO_SUBCONJUNTO', 'EXCLUIDO_NO_NORMALIZADO',
                                                'EXCLUIDO_ANULADO', 'EXCLUIDO_VERSION_AMBIGUA')),
    CONSTRAINT conc_archivos_grupo CHECK (grupo IS NULL OR grupo IN ('POLICIA', 'BOMBEROS', 'OTRO', 'MIXTO', 'SIN_GRUPO'))
);
CREATE UNIQUE INDEX conciliacion_archivos_uk ON conciliacion_archivos (conciliacion_id, archivo_id);
CREATE TRIGGER conciliacion_archivos_escribible BEFORE INSERT OR UPDATE OR DELETE ON conciliacion_archivos
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_resultado_escribible();

-- ---------------------------------------------------------------------
-- Controles globales (niveles 1–3 y resumen de 4–5)
-- ---------------------------------------------------------------------
CREATE TABLE conciliacion_controles (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conciliacion_id  bigint      NOT NULL REFERENCES conciliaciones (id),
    nivel            smallint    NOT NULL,
    codigo           text        NOT NULL,
    grupo            text,
    banco            text,
    categoria        text,
    referencia       text,                               -- discriminador: archivo:<id> u hoja:<archivo>:<índice>
    descripcion      text        NOT NULL,
    valor_a          numeric(16,2),
    etiqueta_a       text,
    valor_b          numeric(16,2),
    etiqueta_b       text,
    valor_c          numeric(16,2),
    etiqueta_c       text,
    diferencia       numeric(16,2),
    estado           text        NOT NULL,
    fuentes          bigint[]    NOT NULL DEFAULT '{}',   -- archivos que sustentan el control
    detalle          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT conc_controles_nivel CHECK (nivel BETWEEN 1 AND 5),
    CONSTRAINT conc_controles_codigo CHECK (codigo ~ '^[A-Z][A-Z0-9_]{2,79}$'),
    CONSTRAINT conc_controles_estado CHECK (estado IN ('CUADRA', 'NO_CUADRA', 'NO_DETERMINABLE', 'REVISION_MANUAL', 'INFORMATIVO')),
    CONSTRAINT conc_controles_grupo CHECK (grupo IS NULL OR grupo IN ('POLICIA', 'BOMBEROS', 'OTRO', 'MIXTO', 'SIN_GRUPO', 'TOTAL'))
);
CREATE UNIQUE INDEX conciliacion_controles_uk ON conciliacion_controles (conciliacion_id, nivel, codigo, coalesce(grupo, ''),
                                                                          coalesce(banco, ''), coalesce(categoria, ''), coalesce(referencia, ''));
CREATE INDEX conciliacion_controles_estado_idx ON conciliacion_controles (conciliacion_id, nivel, estado);
CREATE TRIGGER conciliacion_controles_escribible BEFORE INSERT OR UPDATE OR DELETE ON conciliacion_controles
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_resultado_escribible();

-- ---------------------------------------------------------------------
-- Nivel 4: nómina por empleado
-- ---------------------------------------------------------------------
CREATE TABLE conciliacion_empleados (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conciliacion_id       bigint      NOT NULL REFERENCES conciliaciones (id),
    empleado_id           bigint      NOT NULL REFERENCES empleados (id),
    grupo                 text        NOT NULL,
    numero_empleado       text        NOT NULL,          -- snapshot
    area                  text,                          -- snapshot RRHH de la quincena
    registro_rrhh_id      bigint      REFERENCES registros_rrhh (id),
    percepciones          numeric(14,2),
    deducciones           numeric(14,2),
    neto_esperado         numeric(14,2) NOT NULL DEFAULT 0,
    pagado_santander      numeric(14,2) NOT NULL DEFAULT 0,
    pagado_bbva           numeric(14,2) NOT NULL DEFAULT 0,
    pagado_interbancaria  numeric(14,2) NOT NULL DEFAULT 0,
    pagado_otros          numeric(14,2) NOT NULL DEFAULT 0,
    pagado_total          numeric(14,2) NOT NULL DEFAULT 0,
    diferencia            numeric(14,2) NOT NULL DEFAULT 0,
    pagos                 smallint    NOT NULL DEFAULT 0,
    estado                text        NOT NULL,
    alerta                text,
    posible_pendiente     numeric(14,2) NOT NULL DEFAULT 0,
    con_pension           boolean     NOT NULL DEFAULT false,
    detalle               jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT conc_emp_grupo CHECK (grupo IN ('POLICIA', 'BOMBEROS', 'OTRO')),
    CONSTRAINT conc_emp_estado CHECK (estado IN ('CORRECTO', 'PAGO_MENOR', 'PAGO_MAYOR', 'NO_PAGADO', 'PAGO_SIN_RESPALDO_RRHH',
                                                 'POSIBLE_PAGO_DUPLICADO', 'PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD',
                                                 'ERROR_DATOS_RRHH', 'NO_APLICA')),
    CONSTRAINT conc_emp_alerta CHECK (alerta IS NULL OR alerta IN ('POSIBLE_PAGO_PENDIENTE', 'POSIBLE_PAGO_DUPLICADO', 'PAGOS_DIVIDIDOS',
                                                                  'REGISTRO_EN_CONFLICTO', 'BAJA_EN_RRHH')),
    -- La diferencia siempre es pagado − esperado (no se guarda un resultado inconsistente)
    CONSTRAINT conc_emp_aritmetica CHECK (pagado_total = pagado_santander + pagado_bbva + pagado_interbancaria + pagado_otros
                                          AND diferencia = pagado_total - neto_esperado)
);
CREATE UNIQUE INDEX conciliacion_empleados_uk ON conciliacion_empleados (conciliacion_id, empleado_id);
CREATE INDEX conciliacion_empleados_estado_idx ON conciliacion_empleados (conciliacion_id, estado);
CREATE INDEX conciliacion_empleados_empleado_idx ON conciliacion_empleados (empleado_id);
CREATE INDEX conciliacion_empleados_grupo_idx ON conciliacion_empleados (conciliacion_id, grupo);
CREATE TRIGGER conciliacion_empleados_escribible BEFORE INSERT OR UPDATE OR DELETE ON conciliacion_empleados
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_resultado_escribible();

-- ---------------------------------------------------------------------
-- Nivel 5: pensión retenida vs dispersada, por trabajador
-- ---------------------------------------------------------------------
CREATE TABLE conciliacion_pensiones (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conciliacion_id   bigint      NOT NULL REFERENCES conciliaciones (id),
    empleado_id       bigint      NOT NULL REFERENCES empleados (id),
    grupo             text        NOT NULL,
    numero_empleado   text        NOT NULL,
    retenido          numeric(14,2) NOT NULL DEFAULT 0,
    dispersado        numeric(14,2) NOT NULL DEFAULT 0,
    diferencia        numeric(14,2) NOT NULL DEFAULT 0,
    dispersiones      smallint    NOT NULL DEFAULT 0,
    estado            text        NOT NULL,
    alerta            text,
    detalle           jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT conc_pen_grupo CHECK (grupo IN ('POLICIA', 'BOMBEROS', 'OTRO')),
    CONSTRAINT conc_pen_estado CHECK (estado IN ('CORRECTA', 'NO_DISPERSADA', 'PENSION_MENOR', 'PENSION_MAYOR', 'PENDIENTE_IDENTIDAD',
                                                 'SIN_RETENCION_RRHH')),
    CONSTRAINT conc_pen_aritmetica CHECK (diferencia = dispersado - retenido)
);
CREATE UNIQUE INDEX conciliacion_pensiones_uk ON conciliacion_pensiones (conciliacion_id, empleado_id);
CREATE INDEX conciliacion_pensiones_estado_idx ON conciliacion_pensiones (conciliacion_id, estado);
CREATE TRIGGER conciliacion_pensiones_escribible BEFORE INSERT OR UPDATE OR DELETE ON conciliacion_pensiones
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_resultado_escribible();

-- ---------------------------------------------------------------------
-- Fuentes exactas de cada resultado (explicabilidad + snapshot de la asociación al ejecutar)
-- ---------------------------------------------------------------------
CREATE TABLE conciliacion_fuentes (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conciliacion_id  bigint      NOT NULL REFERENCES conciliaciones (id),
    empleado_id      bigint      REFERENCES empleados (id),
    tipo             text        NOT NULL,
    registro_id      bigint      NOT NULL,
    archivo_id       bigint      NOT NULL REFERENCES archivos_importados (id),
    hoja_nombre      text,
    fila_origen      integer,
    banco            text,
    codigo_concepto  text,
    importe_original numeric(14,2),
    importe          numeric(14,2) NOT NULL,           -- importe con la semántica del concepto (siempre ≥ 0 en pagos y retenciones)
    asociacion_estado text,
    CONSTRAINT conc_fuentes_tipo CHECK (tipo IN ('RRHH', 'TESORERIA_NOMINA', 'TESORERIA_PENSION', 'RRHH_PENSION',
                                                 'PENDIENTE_NOMINA', 'PENDIENTE_PENSION'))
);
CREATE INDEX conciliacion_fuentes_empleado_idx ON conciliacion_fuentes (conciliacion_id, empleado_id);
CREATE INDEX conciliacion_fuentes_tipo_idx ON conciliacion_fuentes (conciliacion_id, tipo);
CREATE TRIGGER conciliacion_fuentes_escribible BEFORE INSERT OR UPDATE OR DELETE ON conciliacion_fuentes
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_resultado_escribible();

-- ---------------------------------------------------------------------
-- Incidencias financieras
-- ---------------------------------------------------------------------
CREATE TABLE conciliacion_incidencias (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conciliacion_id  bigint      NOT NULL REFERENCES conciliaciones (id),
    clave            text        NOT NULL,
    tipo             text        NOT NULL,
    severidad        text        NOT NULL,
    estado           text        NOT NULL DEFAULT 'ABIERTA',
    nivel            smallint    NOT NULL,
    empleado_id      bigint      REFERENCES empleados (id),
    grupo            text,
    banco            text,
    importe          numeric(16,2),
    mensaje          text        NOT NULL,
    detalle          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    actualizado_por  bigint      REFERENCES usuarios (id),
    actualizado_at   timestamptz,
    comentario       text,
    evidencia        text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT conc_inc_tipo CHECK (tipo ~ '^[A-Z][A-Z0-9_]{2,59}$'),
    CONSTRAINT conc_inc_severidad CHECK (severidad IN ('INFO', 'ADVERTENCIA', 'ERROR', 'CRITICA')),
    CONSTRAINT conc_inc_estado CHECK (estado IN ('ABIERTA', 'EN_REVISION', 'RESUELTA', 'ACEPTADA_JUSTIFICADA')),
    CONSTRAINT conc_inc_nivel CHECK (nivel BETWEEN 0 AND 5),
    CONSTRAINT conc_inc_gestion CHECK (estado = 'ABIERTA' OR (actualizado_por IS NOT NULL AND actualizado_at IS NOT NULL)),
    CONSTRAINT conc_inc_justificacion CHECK (estado NOT IN ('RESUELTA', 'ACEPTADA_JUSTIFICADA') OR char_length(btrim(coalesce(comentario, ''))) >= 5),
    CONSTRAINT conc_inc_textos CHECK (char_length(mensaje) <= 500 AND char_length(coalesce(comentario, '')) <= 2000
                                      AND char_length(coalesce(evidencia, '')) <= 1000)
);
CREATE UNIQUE INDEX conciliacion_incidencias_uk ON conciliacion_incidencias (conciliacion_id, clave);
CREATE INDEX conciliacion_incidencias_filtro_idx ON conciliacion_incidencias (conciliacion_id, estado, severidad, tipo);
CREATE INDEX conciliacion_incidencias_empleado_idx ON conciliacion_incidencias (conciliacion_id, empleado_id) WHERE empleado_id IS NOT NULL;
CREATE INDEX conciliacion_incidencias_banco_idx ON conciliacion_incidencias (conciliacion_id, banco) WHERE banco IS NOT NULL;
CREATE INDEX conciliacion_incidencias_tipo_idx ON conciliacion_incidencias (conciliacion_id, tipo);

-- El motor inserta durante la ejecución; la web sólo cambia estado/comentario/evidencia y nunca en una conciliación cerrada o invalidada
CREATE FUNCTION fn_conciliacion_incidencia_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_estado text;
BEGIN
    SELECT estado INTO v_estado FROM conciliaciones WHERE id = coalesce(NEW.conciliacion_id, OLD.conciliacion_id);
    IF TG_OP = 'INSERT' THEN
        IF v_estado <> 'EJECUTANDO' THEN
            RAISE EXCEPTION 'Sólo una conciliación en ejecución registra incidencias' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las incidencias no se borran' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF v_estado IN ('CERRADA', 'INVALIDADA') THEN
        RAISE EXCEPTION 'La conciliación está %: sus incidencias no se modifican', lower(v_estado) USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.conciliacion_id <> OLD.conciliacion_id OR NEW.clave <> OLD.clave OR NEW.tipo <> OLD.tipo OR NEW.severidad <> OLD.severidad
       OR NEW.nivel <> OLD.nivel OR NEW.empleado_id IS DISTINCT FROM OLD.empleado_id OR NEW.importe IS DISTINCT FROM OLD.importe
       OR NEW.mensaje <> OLD.mensaje OR NEW.detalle <> OLD.detalle OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'Resolver una incidencia no modifica su contenido ni importes' USING ERRCODE = 'check_violation';
    END IF;
    -- Cada cambio de gestión es un cambio de estado con autor, comentario y hora de la BD (el historial lo escribe la BD)
    IF NEW.estado = OLD.estado OR NEW.actualizado_por IS NULL OR char_length(btrim(coalesce(NEW.comentario, ''))) < 5 THEN
        RAISE EXCEPTION 'Una incidencia cambia de estado con autor y comentario' USING ERRCODE = 'check_violation';
    END IF;
    NEW.actualizado_at := now();
    RETURN NEW;
END;
$$;
CREATE FUNCTION fn_conciliacion_incidencia_historial() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    INSERT INTO conciliacion_incidencias_historial (incidencia_id, estado_anterior, estado_nuevo, usuario_id, comentario, evidencia)
    VALUES (NEW.id, OLD.estado, NEW.estado, NEW.actualizado_por, NEW.comentario, NEW.evidencia);
    RETURN NULL;
END;
$$;
CREATE TRIGGER conciliacion_incidencias_reglas BEFORE INSERT OR UPDATE OR DELETE ON conciliacion_incidencias
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_incidencia_reglas();
CREATE TRIGGER conciliacion_incidencias_actor BEFORE INSERT OR UPDATE ON conciliacion_incidencias
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('actualizado_por');

CREATE TABLE conciliacion_incidencias_historial (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incidencia_id    bigint      NOT NULL REFERENCES conciliacion_incidencias (id),
    estado_anterior  text        NOT NULL,
    estado_nuevo     text        NOT NULL,
    usuario_id       bigint      NOT NULL REFERENCES usuarios (id),
    comentario       text        NOT NULL,
    evidencia        text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT conc_inc_hist_comentario CHECK (char_length(btrim(comentario)) BETWEEN 5 AND 2000
                                               AND char_length(coalesce(evidencia, '')) <= 1000)
);
CREATE INDEX conciliacion_incidencias_historial_idx ON conciliacion_incidencias_historial (incidencia_id, id DESC);
CREATE TRIGGER conciliacion_incidencias_historial_actor BEFORE INSERT ON conciliacion_incidencias_historial
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_id');
CREATE TRIGGER conciliacion_incidencias_historial_solo_insercion BEFORE UPDATE OR DELETE ON conciliacion_incidencias_historial
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER conciliacion_incidencias_historial AFTER UPDATE ON conciliacion_incidencias
    FOR EACH ROW EXECUTE FUNCTION fn_conciliacion_incidencia_historial();

-- ---------------------------------------------------------------------
-- Relación manual hoja de póliza de egreso ↔ dispersión (auditada, se anula, no se borra)
-- ---------------------------------------------------------------------
CREATE TABLE relaciones_poliza_dispersion (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    lote_id                bigint      NOT NULL REFERENCES lotes_importacion (id),
    archivo_egreso_id      bigint      NOT NULL REFERENCES archivos_importados (id),
    hoja_indice            smallint    NOT NULL,
    archivo_dispersion_id  bigint      NOT NULL REFERENCES archivos_importados (id),
    motivo                 text        NOT NULL,
    estado                 text        NOT NULL DEFAULT 'ACTIVA',
    creado_por             bigint      NOT NULL REFERENCES usuarios (id),
    created_at             timestamptz NOT NULL DEFAULT now(),
    anulado_por            bigint      REFERENCES usuarios (id),
    anulado_at             timestamptz,
    motivo_anulacion       text,
    CONSTRAINT rel_pd_estado CHECK (estado IN ('ACTIVA', 'ANULADA')),
    CONSTRAINT rel_pd_anulacion CHECK ((estado = 'ANULADA') = (anulado_por IS NOT NULL AND anulado_at IS NOT NULL)),
    CONSTRAINT rel_pd_motivo CHECK (char_length(btrim(motivo)) BETWEEN 5 AND 1000 AND char_length(coalesce(motivo_anulacion, '')) <= 1000)
);
CREATE UNIQUE INDEX relaciones_poliza_dispersion_uk ON relaciones_poliza_dispersion (archivo_egreso_id, hoja_indice) WHERE estado = 'ACTIVA';
CREATE FUNCTION fn_relacion_poliza_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.estado <> 'ACTIVA' OR NEW.anulado_por IS NOT NULL THEN
            RAISE EXCEPTION 'Una relación nueva nace ACTIVA' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
                        WHERE l.id = NEW.lote_id AND l.estado <> 'ANULADO' AND p.estado NOT IN ('CERRADO', 'ANULADO'))
           OR NOT EXISTS (SELECT 1 FROM archivos_importados a JOIN importaciones_normalizadas n ON n.archivo_id = a.id AND n.estado = 'VIGENTE'
                           JOIN polizas_egreso_lineas pl ON pl.importacion_id = n.id AND pl.hoja_indice = NEW.hoja_indice
                          WHERE a.id = NEW.archivo_egreso_id AND a.lote_id = NEW.lote_id AND a.estado <> 'ANULADO'
                            AND n.tipo_registro = 'POLIZA_EGRESO')
           OR NOT EXISTS (SELECT 1 FROM archivos_importados a JOIN importaciones_normalizadas n ON n.archivo_id = a.id AND n.estado = 'VIGENTE'
                          WHERE a.id = NEW.archivo_dispersion_id AND a.lote_id = NEW.lote_id AND a.estado <> 'ANULADO'
                            AND n.tipo_registro = 'TESORERIA') THEN
            RAISE EXCEPTION 'La hoja debe ser de una póliza de egreso vigente y la dispersión de Tesorería, del mismo lote abierto'
                USING ERRCODE = 'check_violation';
        END IF;
        IF fn_es_conexion_web() THEN NEW.created_at := now(); END IF;
        RETURN NEW;
    END IF;
    IF OLD.estado = 'ANULADA' THEN
        RAISE EXCEPTION 'Una relación anulada no cambia' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.lote_id <> OLD.lote_id OR NEW.archivo_egreso_id <> OLD.archivo_egreso_id OR NEW.hoja_indice <> OLD.hoja_indice
       OR NEW.archivo_dispersion_id <> OLD.archivo_dispersion_id OR NEW.motivo <> OLD.motivo OR NEW.creado_por <> OLD.creado_por
       OR NEW.created_at <> OLD.created_at OR NEW.estado <> 'ANULADA' OR NEW.anulado_por IS NULL
       OR char_length(btrim(coalesce(NEW.motivo_anulacion, ''))) < 5 THEN
        RAISE EXCEPTION 'Una relación sólo se anula, con autor y motivo' USING ERRCODE = 'check_violation';
    END IF;
    IF fn_es_conexion_web() THEN NEW.anulado_at := now(); END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER relaciones_poliza_dispersion_reglas BEFORE INSERT OR UPDATE ON relaciones_poliza_dispersion
    FOR EACH ROW EXECUTE FUNCTION fn_relacion_poliza_reglas();
CREATE TRIGGER relaciones_poliza_dispersion_actor BEFORE INSERT OR UPDATE ON relaciones_poliza_dispersion
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'anulado_por');
CREATE TRIGGER relaciones_poliza_dispersion_sin_borrado BEFORE DELETE ON relaciones_poliza_dispersion
    FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Encolado seguro: número de ejecución bajo candado del lote
-- ---------------------------------------------------------------------
-- Permiso del actor de la sesión (defensa en profundidad de los permisos de la aplicación)
CREATE FUNCTION fn_actor_tiene_permiso(p_codigo text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id AND r.activo
                     JOIN rol_permiso rp ON rp.rol_id = r.id JOIN permisos p ON p.id = rp.permiso_id
                    WHERE ur.usuario_id = fn_actor_actual() AND p.codigo = p_codigo)
$$;

CREATE FUNCTION fn_solicitar_conciliacion(p_lote_id bigint, p_usuario_id bigint, p_motivo text)
RETURNS conciliaciones
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_lote    lotes_importacion;
    v_tol     numeric;
    v_n       integer;
    v_previas integer;
    v_c       conciliaciones;
    v_job     bigint;
BEGIN
    -- El candado del lote serializa las solicitudes (número de ejecución y reglas de reproceso sin carreras)
    SELECT * INTO v_lote FROM lotes_importacion WHERE id = p_lote_id FOR UPDATE;
    IF v_lote.id IS NULL OR v_lote.estado = 'ANULADO' THEN
        RAISE EXCEPTION 'El lote no existe o está anulado' USING ERRCODE = 'check_violation';
    END IF;
    SELECT count(*) INTO v_previas FROM conciliaciones WHERE lote_id = p_lote_id;
    IF fn_es_conexion_web() THEN
        IF p_usuario_id IS DISTINCT FROM fn_actor_actual() THEN
            RAISE EXCEPTION 'La solicitud no corresponde al usuario de la sesión' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF NOT fn_actor_tiene_permiso(CASE WHEN v_previas > 0 THEN 'conciliaciones.reprocesar' ELSE 'conciliaciones.ejecutar' END) THEN
            RAISE EXCEPTION 'Sin permiso para conciliar este lote' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    IF v_previas > 0 AND char_length(btrim(coalesce(p_motivo, ''))) < 5 THEN
        RAISE EXCEPTION 'Una nueva ejecución requiere motivo' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF EXISTS (SELECT 1 FROM conciliaciones WHERE lote_id = p_lote_id AND estado = 'CERRADA') THEN
        RAISE EXCEPTION 'El lote tiene una conciliación cerrada: invalídela antes de conciliar de nuevo' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM archivos_importados WHERE lote_id = p_lote_id AND estado IN ('RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'ANALIZANDO')) THEN
        RAISE EXCEPTION 'El lote tiene archivos en proceso' USING ERRCODE = 'object_in_use';
    END IF;
    SELECT coalesce(nullif(valor, '')::numeric, 0.01) INTO v_tol FROM configuracion WHERE clave = 'conciliacion.tolerancia_monetaria';
    v_n := (SELECT coalesce(max(numero), 0) + 1 FROM conciliaciones WHERE lote_id = p_lote_id);
    INSERT INTO conciliaciones (periodo_id, lote_id, numero, tolerancia, lote_oficial, solicitado_por, motivo_solicitud)
    VALUES (v_lote.periodo_id, v_lote.id, v_n, coalesce(v_tol, 0.01), v_lote.es_oficial, p_usuario_id, nullif(btrim(p_motivo), ''))
    RETURNING * INTO v_c;
    -- El trabajo se encola en la MISMA transacción: nunca queda una ejecución PENDIENTE sin trabajo que bloquee el lote
    INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
    VALUES ('CONCILIAR_LOTE', 1, jsonb_build_object('conciliacion_id', v_c.id), 'conciliar:' || v_c.id, 50,
            coalesce((SELECT nullif(valor, '')::integer FROM configuracion WHERE clave = 'procesos.max_intentos_predeterminado'), 3),
            coalesce((SELECT nullif(valor, '')::integer FROM configuracion WHERE clave = 'conciliacion.timeout_segundos'), 900),
            p_usuario_id)
    RETURNING id INTO v_job;
    UPDATE conciliaciones SET proceso_id = v_job WHERE id = v_c.id RETURNING * INTO v_c;
    PERFORM pg_notify('conciliarh_procesos', v_job::text);
    RETURN v_c;
END;
$$;

-- Un trabajo CONCILIAR_LOTE en ERROR o CANCELADO deja su conciliación en ERROR (nunca queda "en curso")
CREATE FUNCTION fn_proceso_a_conciliacion() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.tipo = 'CONCILIAR_LOTE' AND NEW.estado IN ('ERROR', 'CANCELADO') AND NEW.estado IS DISTINCT FROM OLD.estado THEN
        UPDATE conciliaciones
           SET estado = 'ERROR',
               error_codigo = CASE WHEN NEW.estado = 'CANCELADO' THEN 'CONCILIACION_CANCELADA' ELSE coalesce(NEW.error_codigo, 'ERROR_CONCILIACION') END,
               error_mensaje = CASE WHEN NEW.estado = 'CANCELADO' THEN 'La conciliación fue cancelada.'
                                    ELSE left(coalesce(NEW.error_mensaje, 'La conciliación falló.'), 500) END
         WHERE proceso_id = NEW.id AND estado IN ('PENDIENTE', 'EJECUTANDO');
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER procesos_a_conciliacion AFTER UPDATE OF estado ON procesos
    FOR EACH ROW EXECUTE FUNCTION fn_proceso_a_conciliacion();

-- ---------------------------------------------------------------------
-- Vista: última ejecución terminada por lote (el tablero prioriza el lote oficial)
-- ---------------------------------------------------------------------
CREATE VIEW v_conciliaciones_vigentes AS
SELECT DISTINCT ON (c.lote_id) c.*
  FROM conciliaciones c
 WHERE c.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA')
 ORDER BY c.lote_id, c.numero DESC;

-- ---------------------------------------------------------------------
-- Permisos y parámetros
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('conciliaciones.ver',          'conciliaciones', 'Consultar conciliaciones, controles y resultados (datos sensibles enmascarados)', false, false),
    ('conciliaciones.ver_sensible', 'conciliaciones', 'Ver RFC/CURP completos y buscar por ellos en los resultados de conciliación', false, true),
    ('conciliaciones.ejecutar',     'conciliaciones', 'Ejecutar la primera conciliación de un lote', false, true),
    ('conciliaciones.reprocesar',   'conciliaciones', 'Ejecutar una nueva conciliación de un lote ya conciliado', false, true),
    ('conciliaciones.cerrar',       'conciliaciones', 'Cerrar una conciliación (congela sus incidencias)', false, true),
    ('conciliaciones.invalidar',    'conciliaciones', 'Invalidar una conciliación (queda en el historial)', false, true),
    ('conciliaciones.exportar',     'conciliaciones', 'Exportar resultados de conciliación (CSV/XLSX, auditado)', false, true),
    ('conciliaciones.relacionar',   'conciliaciones', 'Relacionar manualmente una hoja de póliza de egreso con una dispersión', false, true),
    ('incidencias.ver',             'incidencias',    'Consultar incidencias financieras de conciliación', false, false),
    ('incidencias.resolver',        'incidencias',    'Revisar, resolver o justificar incidencias financieras (no modifica importes)', false, true);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo IN ('conciliaciones', 'incidencias')
 WHERE r.codigo IN ('GOD', 'ADMIN');
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'conciliaciones.ver', 'conciliaciones.ver_sensible', 'conciliaciones.ejecutar', 'conciliaciones.reprocesar',
    'conciliaciones.exportar', 'conciliaciones.relacionar', 'incidencias.ver', 'incidencias.resolver')
 WHERE r.codigo = 'ANALISTA';
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('conciliaciones.ver', 'incidencias.ver')
 WHERE r.codigo = 'CONSULTA';

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('conciliacion.timeout_segundos', '900', 'entero', 'Tiempo máximo de un trabajo CONCILIAR_LOTE', 60, 7200, true),
    ('conciliacion.cuenta_bancos', '1112', 'texto', 'Cuenta contable de bancos en las pólizas de egreso (su HABER es lo pagado por hoja)', NULL, NULL, true)
ON CONFLICT (clave) DO NOTHING;

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON conciliaciones, conciliacion_archivos, conciliacion_controles, conciliacion_empleados, conciliacion_pensiones,
               conciliacion_fuentes, conciliacion_incidencias, conciliacion_incidencias_historial, relaciones_poliza_dispersion,
               v_conciliaciones_vigentes TO conciliarh_web;
GRANT UPDATE (estado, cerrada_por, cerrada_at, motivo_cierre, invalidada_por, invalidada_at, motivo_invalidacion)
    ON conciliaciones TO conciliarh_web;
GRANT UPDATE (estado, actualizado_por, actualizado_at, comentario, evidencia) ON conciliacion_incidencias TO conciliarh_web;
GRANT INSERT (lote_id, archivo_egreso_id, hoja_indice, archivo_dispersion_id, motivo, creado_por) ON relaciones_poliza_dispersion TO conciliarh_web;
GRANT UPDATE (estado, anulado_por, anulado_at, motivo_anulacion) ON relaciones_poliza_dispersion TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_solicitar_conciliacion(bigint, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_actor_tiene_permiso(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_conciliacion_incidencia_historial() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_solicitar_conciliacion(bigint, bigint, text) TO conciliarh_web;

GRANT SELECT ON conciliaciones, relaciones_poliza_dispersion TO conciliarh_worker;
GRANT UPDATE (estado, motor_version, huella_entradas, igual_a_id, totales, resumen, metricas, error_codigo, error_mensaje,
              iniciada_at, terminada_at) ON conciliaciones TO conciliarh_worker;
GRANT SELECT, INSERT ON conciliacion_archivos, conciliacion_controles, conciliacion_empleados, conciliacion_pensiones,
                        conciliacion_fuentes, conciliacion_incidencias TO conciliarh_worker;
