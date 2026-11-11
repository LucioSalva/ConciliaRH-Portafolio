-- 0018 · FASE 7: reportes programados, reportes generados persistidos y retención (docs/SCHEDULED_REPORTS.md).
--
--  * Una programación pertenece a su creador. El reporte se genera con los permisos VIGENTES del creador al momento de
--    generarse (instantánea evaluada por la BD, no copiada al crear): si los perdió, la generación falla y la programación
--    se pausa. Nadie obtiene por una programación datos que su propio rol no le permite ver.
--  * La descarga de un reporte generado evalúa los permisos del QUE DESCARGA (dueño o administrador de programaciones, más
--    los permisos del reporte y, si es sensible, los de datos sensibles): compartir un id no concede nada.
--  * Los archivos viven fuera de public/ (storage/reportes/generados/AAAA/MM/<aleatorio>.<ext>), con SHA-256 verificado
--    antes de cada descarga. Al expirar se borra el archivo y se CONSERVA la fila (metadatos + huella).
--  * Los trabajos usan la cola existente (procesos): GENERAR_REPORTE_PROGRAMADO y LIMPIAR_REPORTES_EXPIRADOS los atiende el
--    ejecutor PHP (bin/scheduler.php --run, rol de servicio conciliarh_worker); el worker Python nunca los reclama.

CREATE TABLE reportes_programados (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre                 text        NOT NULL,
    tipo                   text        NOT NULL,
    formato                text        NOT NULL,
    frecuencia             text        NOT NULL,
    hora                   smallint    NOT NULL DEFAULT 7,
    dia_semana             smallint,
    dia_mes                smallint,
    alcance                text        NOT NULL DEFAULT 'RECIENTE',
    lote_id                bigint      REFERENCES lotes_importacion (id),
    sensible               boolean     NOT NULL DEFAULT false,
    retencion_dias         smallint    NOT NULL DEFAULT 30,
    activo                 boolean     NOT NULL DEFAULT true,
    pausa_motivo           text,
    creado_por             bigint      NOT NULL REFERENCES usuarios (id),
    actualizado_por        bigint      REFERENCES usuarios (id),
    version                integer     NOT NULL DEFAULT 1,
    proxima_ejecucion_at   timestamptz,
    ultima_ejecucion_at    timestamptz,
    ultimo_estado          text,
    errores_consecutivos   smallint    NOT NULL DEFAULT 0,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT rp_nombre CHECK (char_length(btrim(nombre)) BETWEEN 3 AND 120),
    CONSTRAINT rp_tipo CHECK (tipo IN ('resumen', 'empleados', 'diferencias', 'no_pagados', 'pagados_mas', 'pagados_menos', 'pendientes',
                                       'pensiones', 'bancos', 'grupos', 'incidencias')),
    CONSTRAINT rp_formato CHECK (formato IN ('XLSX', 'CSV', 'PDF')
                                 AND (formato <> 'PDF' OR tipo IN ('resumen', 'pensiones', 'bancos', 'grupos', 'incidencias'))),
    CONSTRAINT rp_frecuencia CHECK (frecuencia IN ('MANUAL', 'DIARIO', 'SEMANAL', 'QUINCENAL', 'MENSUAL')),
    CONSTRAINT rp_hora CHECK (hora BETWEEN 0 AND 23),
    CONSTRAINT rp_dias CHECK ((frecuencia = 'SEMANAL') = (dia_semana IS NOT NULL) AND (dia_semana IS NULL OR dia_semana BETWEEN 1 AND 7)
                              AND (frecuencia = 'MENSUAL') = (dia_mes IS NOT NULL) AND (dia_mes IS NULL OR dia_mes BETWEEN 1 AND 28)),
    CONSTRAINT rp_alcance CHECK (alcance IN ('RECIENTE', 'LOTE') AND (alcance = 'LOTE') = (lote_id IS NOT NULL)),
    CONSTRAINT rp_retencion CHECK (retencion_dias IN (30, 60, 90) AND (NOT sensible OR retencion_dias = 30)),
    CONSTRAINT rp_proxima CHECK (frecuencia <> 'MANUAL' OR proxima_ejecucion_at IS NULL),
    CONSTRAINT rp_pausa CHECK (char_length(coalesce(pausa_motivo, '')) <= 300),
    CONSTRAINT rp_ultimo CHECK (ultimo_estado IS NULL OR ultimo_estado IN ('DISPONIBLE', 'ERROR', 'SIN_DATOS'))
);
CREATE INDEX reportes_programados_vencidos_idx ON reportes_programados (proxima_ejecucion_at) WHERE activo AND proxima_ejecucion_at IS NOT NULL;
CREATE INDEX reportes_programados_creador_idx ON reportes_programados (creado_por, id DESC);

CREATE FUNCTION fn_reporte_programado_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.creado_por <> OLD.creado_por OR NEW.created_at <> OLD.created_at) THEN
        RAISE EXCEPTION 'El dueño de una programación no cambia' USING ERRCODE = 'check_violation';
    END IF;
    IF fn_es_conexion_web() AND current_user = session_user THEN
        -- La web edita la definición; los campos de ejecución son del servicio
        IF TG_OP = 'UPDATE' AND (NEW.ultima_ejecucion_at IS DISTINCT FROM OLD.ultima_ejecucion_at OR NEW.ultimo_estado IS DISTINCT FROM OLD.ultimo_estado
                                 OR NEW.errores_consecutivos <> OLD.errores_consecutivos) THEN
            RAISE EXCEPTION 'Campos de ejecución reservados al servicio' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF TG_OP = 'UPDATE' AND OLD.creado_por <> fn_actor_actual() THEN
            -- Un administrador sólo pausa o reactiva programaciones ajenas; la DEFINICIÓN (qué se genera con la identidad del
            -- dueño) es sólo del dueño: si no, podría obtener, con los permisos de otro, contenido que él no puede ver
            IF NOT fn_actor_tiene_permiso('reportes.administrar_programados') THEN
                RAISE EXCEPTION 'Programación de otro usuario' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF (NEW.tipo, NEW.formato, NEW.frecuencia, NEW.hora, NEW.dia_semana, NEW.dia_mes, NEW.alcance, NEW.lote_id, NEW.sensible,
                NEW.retencion_dias, NEW.nombre)
               IS DISTINCT FROM (OLD.tipo, OLD.formato, OLD.frecuencia, OLD.hora, OLD.dia_semana, OLD.dia_mes, OLD.alcance, OLD.lote_id,
                                 OLD.sensible, OLD.retencion_dias, OLD.nombre) THEN
                RAISE EXCEPTION 'Sólo el dueño modifica la definición de una programación' USING ERRCODE = 'insufficient_privilege';
            END IF;
        END IF;
        -- Una programación sensible sólo la define (o conserva al editarla) quien puede ver y exportar identificadores
        IF NEW.sensible AND (TG_OP = 'INSERT' OR OLD.creado_por = fn_actor_actual())
           AND NOT (fn_actor_tiene_permiso('reportes.exportar_sensible') AND fn_actor_tiene_permiso('empleados.ver_sensible')) THEN
            RAISE EXCEPTION 'Sin permiso para programar reportes con datos sensibles' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF NOT fn_actor_tiene_permiso('reportes.programar') THEN
            RAISE EXCEPTION 'Sin permiso para programar reportes' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    -- Cuota de programaciones ACTIVAS por dueño (al crear y al reactivar; también la hace cumplir la BD)
    IF NEW.activo AND (TG_OP = 'INSERT' OR NOT OLD.activo) THEN
        PERFORM pg_advisory_xact_lock(7303, NEW.creado_por::int);      -- (clase, dueño): la cuota se cuenta sin carreras
        IF (SELECT count(*) FROM reportes_programados WHERE creado_por = NEW.creado_por AND activo AND id IS DISTINCT FROM NEW.id)
           >= coalesce((SELECT nullif(valor, '')::integer FROM configuracion WHERE clave = 'reportes.programados_max_por_usuario'), 20) THEN
            RAISE EXCEPTION 'CUOTA_PROGRAMACIONES: alcanzó el máximo de programaciones activas' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.activo AND NOT OLD.activo THEN
        NEW.errores_consecutivos := 0;                -- reactivar da una oportunidad completa (no se re-pausa al primer fallo)
        NEW.pausa_motivo := NULL;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.tipo, NEW.formato, NEW.frecuencia, NEW.hora, NEW.dia_semana, NEW.dia_mes, NEW.alcance, NEW.lote_id, NEW.sensible,
            NEW.retencion_dias, NEW.activo, NEW.nombre)
           IS DISTINCT FROM (OLD.tipo, OLD.formato, OLD.frecuencia, OLD.hora, OLD.dia_semana, OLD.dia_mes, OLD.alcance, OLD.lote_id,
                             OLD.sensible, OLD.retencion_dias, OLD.activo, OLD.nombre) THEN
            NEW.version := OLD.version + 1;
        END IF;
        NEW.updated_at := now();
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER reportes_programados_reglas BEFORE INSERT OR UPDATE ON reportes_programados FOR EACH ROW EXECUTE FUNCTION fn_reporte_programado_reglas();
CREATE TRIGGER reportes_programados_actor BEFORE INSERT OR UPDATE ON reportes_programados
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por', 'actualizado_por');
CREATE TRIGGER reportes_programados_sin_borrado BEFORE DELETE ON reportes_programados FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER reportes_programados_sin_truncate BEFORE TRUNCATE ON reportes_programados FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

CREATE TABLE reportes_generados (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    programacion_id        bigint      NOT NULL REFERENCES reportes_programados (id),
    programacion_version   integer     NOT NULL,
    propietario_id         bigint      NOT NULL REFERENCES usuarios (id),
    tipo                   text        NOT NULL,
    formato                text        NOT NULL,
    sensible               boolean     NOT NULL,
    origen                 text        NOT NULL,
    solicitado_por         bigint      REFERENCES usuarios (id),
    programado_para        timestamptz NOT NULL,
    estado                 text        NOT NULL DEFAULT 'PENDIENTE',
    proceso_id             bigint      REFERENCES procesos (id),
    conciliacion_id        bigint      REFERENCES conciliaciones (id),
    conciliacion_numero    integer,
    periodo_id             bigint      REFERENCES periodos_nomina (id),
    lote_id                bigint      REFERENCES lotes_importacion (id),
    ruta                   text,
    sha256                 text,
    bytes                  bigint,
    filas                  integer,
    huella_contenido       text,
    error_codigo           text,
    error_mensaje          text,
    generado_at            timestamptz,
    expira_at              timestamptz,
    archivo_eliminado_at   timestamptz,
    descargas              integer     NOT NULL DEFAULT 0,
    permisos_requeridos    text[]      NOT NULL DEFAULT '{}',    -- permisos con los que se construyó el contenido; quien descarga debe tenerlos
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT rg_estado CHECK (estado IN ('PENDIENTE', 'GENERANDO', 'DISPONIBLE', 'ERROR', 'EXPIRADO', 'ALTERADO', 'SIN_DATOS')),
    CONSTRAINT rg_origen CHECK (origen IN ('PROGRAMADO', 'MANUAL') AND (origen = 'MANUAL') = (solicitado_por IS NOT NULL)),
    CONSTRAINT rg_formato CHECK (formato IN ('XLSX', 'CSV', 'PDF')),
    -- Ruta relativa generada por el sistema (nombre aleatorio); nunca una ruta del usuario
    CONSTRAINT rg_ruta CHECK (ruta IS NULL OR ruta ~ '^generados/[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.(xlsx|csv|pdf)$'),
    CONSTRAINT rg_sha CHECK ((sha256 IS NULL OR sha256 ~ '^[0-9a-f]{64}$') AND (huella_contenido IS NULL OR huella_contenido ~ '^[0-9a-f]{64}$')),
    CONSTRAINT rg_disponible CHECK (estado <> 'DISPONIBLE' OR (ruta IS NOT NULL AND sha256 IS NOT NULL AND generado_at IS NOT NULL
                                    AND expira_at IS NOT NULL AND archivo_eliminado_at IS NULL)),
    CONSTRAINT rg_expirado CHECK (estado <> 'EXPIRADO' OR archivo_eliminado_at IS NOT NULL),
    CONSTRAINT rg_error CHECK ((error_codigo IS NULL OR error_codigo ~ '^[A-Z][A-Z_]{2,39}$') AND char_length(coalesce(error_mensaje, '')) <= 300),
    CONSTRAINT rg_numeros CHECK (coalesce(bytes, 0) >= 0 AND coalesce(filas, 0) >= 0 AND descargas >= 0)
);
-- Idempotencia: una ejecución por programación y hora programada (dos schedulers no duplican) y una sola en curso
CREATE UNIQUE INDEX reportes_generados_programado_uk ON reportes_generados (programacion_id, programado_para);
CREATE UNIQUE INDEX reportes_generados_en_curso_uk ON reportes_generados (programacion_id) WHERE estado IN ('PENDIENTE', 'GENERANDO');
CREATE INDEX reportes_generados_propietario_idx ON reportes_generados (propietario_id, id DESC);
CREATE INDEX reportes_generados_expira_idx ON reportes_generados (expira_at) WHERE archivo_eliminado_at IS NULL AND ruta IS NOT NULL;

CREATE FUNCTION fn_reporte_generado_reglas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.programacion_id <> OLD.programacion_id OR NEW.propietario_id <> OLD.propietario_id OR NEW.tipo <> OLD.tipo
       OR NEW.formato <> OLD.formato OR NEW.sensible <> OLD.sensible OR NEW.origen <> OLD.origen
       OR NEW.programado_para <> OLD.programado_para OR NEW.created_at <> OLD.created_at
       OR (OLD.sha256 IS NOT NULL AND NEW.sha256 IS DISTINCT FROM OLD.sha256)
       OR (OLD.ruta IS NOT NULL AND NEW.ruta IS DISTINCT FROM OLD.ruta) THEN
        RAISE EXCEPTION 'La identidad, sensibilidad y huella de un reporte generado son inmutables' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado = 'EXPIRADO' AND NEW.estado <> OLD.estado THEN
        RAISE EXCEPTION 'Un reporte expirado no vuelve a estar disponible' USING ERRCODE = 'check_violation';
    END IF;
    -- Un archivo alterado nunca vuelve a entregarse; sólo expira. Un reporte terminado no vuelve a «en curso».
    IF OLD.estado = 'ALTERADO' AND NEW.estado NOT IN ('ALTERADO', 'EXPIRADO') THEN
        RAISE EXCEPTION 'Un reporte alterado no vuelve a estar disponible' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado IN ('DISPONIBLE', 'SIN_DATOS', 'ERROR') AND NEW.estado IN ('PENDIENTE', 'GENERANDO') THEN
        RAISE EXCEPTION 'Un reporte terminado no vuelve a generarse' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;
CREATE TRIGGER reportes_generados_reglas BEFORE UPDATE ON reportes_generados FOR EACH ROW EXECUTE FUNCTION fn_reporte_generado_reglas();
CREATE TRIGGER reportes_generados_sin_borrado BEFORE DELETE ON reportes_generados FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER reportes_generados_sin_truncate BEFORE TRUNCATE ON reportes_generados FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();

-- ---------------------------------------------------------------------
-- Encolado (idempotente). Lo llaman el scheduler (servicio) y la web («Generar ahora», con actor y permiso).
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_reporte_encolar(p_programacion_id bigint, p_programado_para timestamptz, p_origen text)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    p reportes_programados;
    v_actor bigint;
    v_id bigint;
    v_proc bigint;
BEGIN
    SELECT * INTO p FROM reportes_programados WHERE id = p_programacion_id FOR UPDATE;
    IF p.id IS NULL THEN
        RAISE EXCEPTION 'Programación inexistente' USING ERRCODE = 'no_data_found';
    END IF;
    IF p_origen NOT IN ('PROGRAMADO', 'MANUAL') THEN
        RAISE EXCEPTION 'Origen inválido' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF fn_es_conexion_web() THEN
        v_actor := fn_actor_actual();
        IF p_origen <> 'MANUAL' OR v_actor IS NULL OR NOT fn_actor_tiene_permiso('reportes.programar')
           OR (p.creado_por <> v_actor AND NOT fn_actor_tiene_permiso('reportes.administrar_programados')) THEN
            RAISE EXCEPTION 'Sin permiso para generar esta programación' USING ERRCODE = 'insufficient_privilege';
        END IF;
        -- Límite de generaciones manuales por usuario y hora (evita llenar el disco con «Generar ahora» repetido)
        IF (SELECT count(*) FROM reportes_generados WHERE solicitado_por = v_actor AND origen = 'MANUAL' AND created_at > now() - interval '1 hour')
           >= coalesce((SELECT nullif(valor, '')::integer FROM configuracion WHERE clave = 'reportes.generar_manual_por_hora'), 10) THEN
            RAISE EXCEPTION 'LIMITE_GENERACION_MANUAL' USING ERRCODE = 'check_violation';
        END IF;
    ELSIF p_origen <> 'PROGRAMADO' THEN
        RAISE EXCEPTION 'El servicio sólo encola ejecuciones programadas' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT p.activo THEN
        RAISE EXCEPTION 'La programación está pausada' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO reportes_generados (programacion_id, programacion_version, propietario_id, tipo, formato, sensible, origen, solicitado_por,
                                    programado_para)
    VALUES (p.id, p.version, p.creado_por, p.tipo, p.formato, p.sensible, p_origen, v_actor, date_trunc('second', p_programado_para))
    ON CONFLICT DO NOTHING
    RETURNING id INTO v_id;
    IF v_id IS NULL THEN
        RETURN NULL;                    -- ya encolado (otro scheduler, doble clic) o ya hay una generación en curso
    END IF;
    INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
    -- La idempotencia la dan los índices únicos de reportes_generados; la clave del trabajo sólo debe ser única en el tiempo
    VALUES ('GENERAR_REPORTE_PROGRAMADO', 1, jsonb_build_object('reporte_generado_id', v_id),
            'reporte:' || v_id || ':' || to_char(clock_timestamp(), 'YYYYMMDDHH24MISSUS'), 120,
            coalesce((SELECT nullif(valor, '')::integer FROM configuracion WHERE clave = 'procesos.max_intentos_predeterminado'), 3), 600, v_actor)
    RETURNING id INTO v_proc;
    UPDATE reportes_generados SET proceso_id = v_proc WHERE id = v_id;
    RETURN v_id;
END;
$$;

-- Limpieza de expirados: una sola en curso y como máximo una al día
CREATE FUNCTION fn_reportes_encolar_limpieza() RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_proc bigint;
BEGIN
    PERFORM pg_advisory_xact_lock(4850181374600825301);
    IF EXISTS (SELECT 1 FROM procesos WHERE tipo = 'LIMPIAR_REPORTES_EXPIRADOS'
                AND (estado IN ('PENDIENTE', 'EN_EJECUCION') OR created_at > now() - interval '23 hours')) THEN
        RETURN NULL;
    END IF;
    INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos)
    VALUES ('LIMPIAR_REPORTES_EXPIRADOS', 1, '{}'::jsonb, 'limpieza-reportes:' || to_char(now(), 'YYYYMMDDHH24MISS'), 200, 3, 600)
    RETURNING id INTO v_proc;
    RETURN v_proc;
END;
$$;

-- Descarga con archivo alterado: sólo quien puede descargar ese reporte lo marca (y queda auditado por la aplicación)
CREATE FUNCTION fn_reporte_marcar_alterado(p_id bigint) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r reportes_generados;
BEGIN
    SELECT * INTO r FROM reportes_generados WHERE id = p_id FOR UPDATE;
    IF r.id IS NULL OR fn_actor_actual() IS NULL
       OR (r.propietario_id <> fn_actor_actual() AND NOT fn_actor_tiene_permiso('reportes.administrar_programados')) THEN
        RAISE EXCEPTION 'Sin permiso' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF r.estado <> 'DISPONIBLE' THEN
        RETURN false;
    END IF;
    UPDATE reportes_generados SET estado = 'ALTERADO', error_codigo = 'ARCHIVO_ALTERADO',
           error_mensaje = 'El archivo no coincide con la huella registrada; no se entrega.' WHERE id = p_id;
    RETURN true;
END;
$$;

-- Conteo de descargas (sin contenido; el detalle va a la auditoría)
CREATE FUNCTION fn_reporte_descargado(p_id bigint) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
    UPDATE reportes_generados SET descargas = descargas + 1 WHERE id = p_id AND estado = 'DISPONIBLE'
       AND (propietario_id = fn_actor_actual() OR fn_actor_tiene_permiso('reportes.administrar_programados'))
$$;

-- Permisos VIGENTES de un usuario para la generación en segundo plano (sólo el servicio; sin contraseñas ni sesiones)
CREATE FUNCTION fn_servicio_usuario(p_usuario_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT jsonb_build_object(
        'id', u.id, 'username', u.username, 'nombre', u.nombre,
        'activo', u.activo AND u.deleted_at IS NULL, 'es_desarrollo', coalesce(u.es_desarrollo, false),
        'roles', coalesce((SELECT jsonb_agg(r.codigo ORDER BY r.codigo) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id AND r.activo
                            WHERE ur.usuario_id = u.id), '[]'::jsonb),
        'permisos', coalesce((SELECT jsonb_agg(DISTINCT p.codigo) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id AND r.activo
                               JOIN rol_permiso rp ON rp.rol_id = r.id JOIN permisos p ON p.id = rp.permiso_id
                              WHERE ur.usuario_id = u.id), '[]'::jsonb))
      FROM usuarios u WHERE u.id = p_usuario_id
$$;

-- ---------------------------------------------------------------------
-- Permisos y parámetros
-- ---------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('reportes.programar',               'reportes', 'Programar reportes propios y descargar los generados', false, false),
    ('reportes.administrar_programados', 'reportes', 'Ver, pausar y reactivar programaciones y reportes generados de otros usuarios', false, true)
ON CONFLICT (codigo) DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('reportes.programar', 'reportes.administrar_programados')
 WHERE r.codigo IN ('GOD', 'ADMIN')
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'reportes.programar' WHERE r.codigo = 'ANALISTA'
ON CONFLICT DO NOTHING;

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('reportes.programados_max_por_usuario', '20', 'entero', 'Programaciones activas por usuario', 1, 200, true),
    ('reportes.scheduler_max_por_ciclo', '200', 'entero', 'Programaciones vencidas que encola cada ejecución del scheduler', 1, 5000, true),
    ('reportes.errores_para_pausar', '3', 'entero', 'Fallos consecutivos que pausan una programación', 1, 20, true),
    ('reportes.generar_manual_por_hora', '10', 'entero', 'Generaciones manuales («Generar ahora») por usuario y hora', 1, 200, true),
    ('reportes.max_mb_por_usuario', '500', 'entero', 'Almacenamiento máximo de reportes generados disponibles por dueño (MB)', 10, 100000, true)
ON CONFLICT (clave) DO NOTHING;

-- ---------------------------------------------------------------------
-- Privilegios
-- ---------------------------------------------------------------------
GRANT SELECT ON reportes_programados, reportes_generados TO conciliarh_web;
GRANT INSERT (nombre, tipo, formato, frecuencia, hora, dia_semana, dia_mes, alcance, lote_id, sensible, retencion_dias, activo, creado_por,
              proxima_ejecucion_at) ON reportes_programados TO conciliarh_web;
GRANT UPDATE (nombre, tipo, formato, frecuencia, hora, dia_semana, dia_mes, alcance, lote_id, sensible, retencion_dias, activo, pausa_motivo,
              actualizado_por, proxima_ejecucion_at) ON reportes_programados TO conciliarh_web;
REVOKE ALL ON FUNCTION fn_reporte_encolar(bigint, timestamptz, text), fn_reportes_encolar_limpieza(), fn_reporte_marcar_alterado(bigint),
    fn_reporte_descargado(bigint), fn_servicio_usuario(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_reporte_encolar(bigint, timestamptz, text) TO conciliarh_web;
GRANT EXECUTE ON FUNCTION fn_reporte_marcar_alterado(bigint), fn_reporte_descargado(bigint) TO conciliarh_web;

-- Rol PROPIO del scheduler (conciliarh_scheduler): el worker Python procesa libros no confiables y no debe poder escribir
-- reportes ni enumerar permisos de usuarios. Lectura = exactamente lo que la web ya puede leer (mínimo privilegio, sin huellas
-- ni cifrado de cuentas) menos usuarios/sesiones/auditoría y el valor cifrado de las cuentas.
DO $$
DECLARE
    r record;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'conciliarh_scheduler') THEN
        RAISE EXCEPTION 'Falta el rol conciliarh_scheduler. Desarrollo: make db-roles. Producción: el DBA lo crea (docs/SCHEDULED_REPORTS.md §6).';
    END IF;
    FOR r IN SELECT table_name, string_agg(quote_ident(column_name), ', ' ORDER BY column_name) AS cols
               FROM information_schema.column_privileges
              WHERE grantee = 'conciliarh_web' AND privilege_type = 'SELECT' AND table_schema = 'public'
                AND table_name NOT IN ('usuarios', 'sesiones', 'intentos_login', 'auditoria', 'usuario_rol', 'rol_permiso', 'roles', 'permisos',
                                       'staging_registros', 'reportes_programados', 'reportes_generados')
              GROUP BY table_name LOOP
        EXECUTE format('GRANT SELECT (%s) ON %I TO conciliarh_scheduler', r.cols, r.table_name);
    END LOOP;
END $$;
REVOKE SELECT (cuenta_cifrada) ON cuentas_bancarias FROM conciliarh_scheduler;
GRANT SELECT (id, username, nombre) ON usuarios TO conciliarh_scheduler;
GRANT INSERT ON auditoria TO conciliarh_scheduler;
GRANT SELECT ON reportes_programados, reportes_generados TO conciliarh_scheduler;
GRANT UPDATE (proxima_ejecucion_at, ultima_ejecucion_at, ultimo_estado, errores_consecutivos, activo, pausa_motivo) ON reportes_programados TO conciliarh_scheduler;
GRANT UPDATE (estado, conciliacion_id, conciliacion_numero, periodo_id, lote_id, ruta, sha256, bytes, filas, huella_contenido, error_codigo,
              error_mensaje, generado_at, expira_at, archivo_eliminado_at, permisos_requeridos) ON reportes_generados TO conciliarh_scheduler;
GRANT SELECT ON procesos TO conciliarh_scheduler;
GRANT UPDATE (estado, intentos, bloqueado_por, token_bloqueo, bloqueado_at, heartbeat_at, iniciado_at, etapa, progreso, mensaje, error_codigo,
              error_mensaje, error_detalle, resultado, finalizado_at, disponible_desde) ON procesos TO conciliarh_scheduler;
GRANT EXECUTE ON FUNCTION fn_reporte_encolar(bigint, timestamptz, text), fn_reportes_encolar_limpieza(), fn_servicio_usuario(bigint) TO conciliarh_scheduler;

-- El scheduler sólo reclama/actualiza los trabajos de reportes (nunca los del worker Python)
CREATE FUNCTION fn_procesos_consumidor() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF pg_has_role(session_user, 'conciliarh_scheduler', 'MEMBER') AND current_user = session_user
       AND OLD.tipo NOT IN ('GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS') THEN
        RAISE EXCEPTION 'El scheduler sólo atiende trabajos de reportes' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER procesos_consumidor BEFORE UPDATE ON procesos FOR EACH ROW EXECUTE FUNCTION fn_procesos_consumidor();
