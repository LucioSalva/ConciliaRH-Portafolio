-- =====================================================================
-- 0019 · Notificaciones internas y registro de ejecuciones del sistema (FASE 8)
--  * notificaciones: bandeja PERSONAL (no es auditoría: se marcan leídas, se borran y se purgan por retención).
--    RLS: la web sólo ve y modifica las del actor de la sesión (fn_actor_actual); el IDOR queda bloqueado en PostgreSQL.
--    Se crean SÓLO con fn_notificar*/triggers (SECURITY DEFINER): títulos y mensajes armados en el servidor, sin RFC,
--    CURP, CLABE, cuentas ni nombres de personas (docs/NOTIFICATIONS.md).
--  * sistema_ejecuciones: última ejecución del scheduler, de la revisión de almacenamiento y del respaldo (salud del sistema).
--  * Permisos sistema.salud (GOD, ADMIN) y sistema.mantenimiento (GOD).
-- =====================================================================

CREATE TABLE notificaciones (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id   bigint      NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,   -- bandeja personal, no auditoría
    tipo         text        NOT NULL,
    prioridad    text        NOT NULL DEFAULT 'NORMAL',
    titulo       text        NOT NULL,
    mensaje      text        NOT NULL,
    url          text,
    entidad      text,
    entidad_id   bigint,
    clave        text,                      -- deduplicación por usuario (p. ej. 'normalizacion:42')
    leida_at     timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT notif_tipo CHECK (tipo IN ('CONCILIACION_TERMINADA', 'CONCILIACION_ERROR', 'REPORTE_LISTO', 'REPORTE_FALLIDO',
                                          'IMPORTACION_REVISION', 'ASOCIACIONES_PENDIENTES', 'FUSION_PENDIENTE_APROBACION',
                                          'FUSION_DECIDIDA', 'SISTEMA')),
    CONSTRAINT notif_prioridad CHECK (prioridad IN ('BAJA', 'NORMAL', 'ALTA')),
    CONSTRAINT notif_titulo CHECK (char_length(btrim(titulo)) BETWEEN 3 AND 120),
    CONSTRAINT notif_mensaje CHECK (char_length(mensaje) <= 300),
    -- Sólo rutas internas relativas (nunca un enlace externo ni javascript:)
    -- (sin «//»: una ruta que empieza con dos barras es un enlace EXTERNO relativo al protocolo)
    CONSTRAINT notif_url CHECK (url IS NULL OR url ~ '^/([A-Za-z0-9_?=&#.%-][A-Za-z0-9/_?=&#.%-]{0,198})?$'),
    CONSTRAINT notif_clave CHECK (clave IS NULL OR clave ~ '^[a-z_]{2,40}:[0-9]{1,18}(:[A-Za-z0-9_]{1,40})?$')
);
CREATE INDEX notif_usuario_idx ON notificaciones (usuario_id, created_at DESC);
CREATE INDEX notif_no_leidas_idx ON notificaciones (usuario_id) WHERE leida_at IS NULL;
CREATE UNIQUE INDEX notif_clave_uk ON notificaciones (usuario_id, clave) WHERE clave IS NOT NULL;

ALTER TABLE notificaciones ENABLE ROW LEVEL SECURITY;
CREATE POLICY notif_propias ON notificaciones FOR ALL TO conciliarh_web
    USING (usuario_id = fn_actor_actual()) WITH CHECK (usuario_id = fn_actor_actual());
GRANT SELECT, DELETE ON notificaciones TO conciliarh_web;
GRANT UPDATE (leida_at) ON notificaciones TO conciliarh_web;

-- Crea (o reactiva, si ya existía la misma clave) una notificación para un usuario activo
CREATE FUNCTION fn_notificar(p_usuario bigint, p_tipo text, p_prioridad text, p_titulo text, p_mensaje text, p_url text,
                             p_entidad text, p_entidad_id bigint, p_clave text)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF p_usuario IS NULL OR NOT EXISTS (SELECT 1 FROM usuarios WHERE id = p_usuario AND activo AND deleted_at IS NULL) THEN
        RETURN;
    END IF;
    INSERT INTO notificaciones (usuario_id, tipo, prioridad, titulo, mensaje, url, entidad, entidad_id, clave)
    VALUES (p_usuario, p_tipo, p_prioridad, left(p_titulo, 120), left(coalesce(p_mensaje, ''), 300), p_url, p_entidad, p_entidad_id, p_clave)
    ON CONFLICT (usuario_id, clave) WHERE clave IS NOT NULL
    DO UPDATE SET tipo = EXCLUDED.tipo, titulo = EXCLUDED.titulo, mensaje = EXCLUDED.mensaje, prioridad = EXCLUDED.prioridad, url = EXCLUDED.url,
                  entidad = EXCLUDED.entidad, entidad_id = EXCLUDED.entidad_id,
                  leida_at = NULL, created_at = now();
END;
$$;

-- A todos los usuarios activos con un permiso (GOD incluido: su rol tiene todos), salvo p_excluir
CREATE FUNCTION fn_notificar_permiso(p_permiso text, p_excluir bigint, p_tipo text, p_prioridad text, p_titulo text, p_mensaje text,
                                     p_url text, p_entidad text, p_entidad_id bigint, p_clave text)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    u bigint;
    n integer := 0;
BEGIN
    FOR u IN SELECT DISTINCT ur.usuario_id FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id AND r.activo
               JOIN rol_permiso rp ON rp.rol_id = r.id JOIN permisos p ON p.id = rp.permiso_id AND p.codigo = p_permiso
               JOIN usuarios us ON us.id = ur.usuario_id AND us.activo AND us.deleted_at IS NULL
              WHERE ur.usuario_id IS DISTINCT FROM p_excluir LOOP
        PERFORM fn_notificar(u, p_tipo, p_prioridad, p_titulo, p_mensaje, p_url, p_entidad, p_entidad_id, p_clave);
        n := n + 1;
    END LOOP;
    RETURN n;
END;
$$;

-- ---------------------------------------------------------------------
-- Disparadores (funcionan igual si el cambio lo hace PHP, el worker o el scheduler)
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_notif_conciliacion() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_desc text;
BEGIN
    IF NEW.estado IS NOT DISTINCT FROM OLD.estado
       OR NEW.estado NOT IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'ERROR') THEN
        RETURN NULL;
    END IF;
    SELECT pe.descripcion || ', lote ' || lpad(l.numero::text, 3, '0') INTO v_desc
      FROM lotes_importacion l JOIN periodos_nomina pe ON pe.id = l.periodo_id WHERE l.id = NEW.lote_id;
    IF NEW.estado = 'ERROR' THEN
        PERFORM fn_notificar(NEW.solicitado_por, 'CONCILIACION_ERROR', 'ALTA', 'La conciliación no pudo terminar',
            coalesce(v_desc, 'Lote') || ', ejecución ' || NEW.numero || '. Revise el proceso y solicite una nueva ejecución.',
            '/conciliaciones/' || NEW.id, 'conciliaciones', NEW.id, 'conciliacion:' || NEW.id);
    ELSE
        PERFORM fn_notificar(NEW.solicitado_por, 'CONCILIACION_TERMINADA',
            CASE WHEN NEW.estado = 'COMPLETADA' THEN 'NORMAL' ELSE 'ALTA' END, 'Conciliación terminada',
            coalesce(v_desc, 'Lote') || ', ejecución ' || NEW.numero || ': '
                || CASE NEW.estado WHEN 'COMPLETADA' THEN 'completada.' WHEN 'COMPLETADA_CON_INCIDENCIAS' THEN 'completada con incidencias.'
                                   ELSE 'requiere revisión manual.' END,
            '/conciliaciones/' || NEW.id, 'conciliaciones', NEW.id, 'conciliacion:' || NEW.id);
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER conciliaciones_notificar AFTER UPDATE OF estado ON conciliaciones
    FOR EACH ROW EXECUTE FUNCTION fn_notif_conciliacion();

CREATE FUNCTION fn_notif_reporte_generado() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_nombre text;
BEGIN
    IF NEW.estado IS NOT DISTINCT FROM OLD.estado OR NEW.estado NOT IN ('DISPONIBLE', 'ERROR') THEN
        RETURN NULL;
    END IF;
    SELECT left(nombre, 80) INTO v_nombre FROM reportes_programados WHERE id = NEW.programacion_id;
    IF NEW.estado = 'DISPONIBLE' THEN
        PERFORM fn_notificar(NEW.propietario_id, 'REPORTE_LISTO', 'NORMAL', 'Reporte programado listo',
            '«' || coalesce(v_nombre, 'Reporte') || '» está disponible para descargar.',
            '/reportes/generados', 'reportes_generados', NEW.id, 'reporte:' || NEW.id);
    ELSE
        PERFORM fn_notificar(NEW.propietario_id, 'REPORTE_FALLIDO', 'ALTA', 'Reporte programado falló',
            '«' || coalesce(v_nombre, 'Reporte') || '» no se generó (' || coalesce(NEW.error_codigo, 'ERROR') || ').',
            '/reportes/generados?estado=ERROR', 'reportes_generados', NEW.id, 'reporte:' || NEW.id);
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER reportes_generados_notificar AFTER UPDATE OF estado ON reportes_generados
    FOR EACH ROW EXECUTE FUNCTION fn_notif_reporte_generado();

CREATE FUNCTION fn_notif_archivo_revision() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.estado IS NOT DISTINCT FROM OLD.estado OR NEW.estado <> 'REVISION_MANUAL' THEN
        RETURN NULL;
    END IF;
    PERFORM fn_notificar(NEW.usuario_carga_id, 'IMPORTACION_REVISION', 'ALTA', 'Importación requiere revisión',
        'El archivo #' || NEW.id || ' no se pudo clasificar con confianza: elija la hoja o el perfil.',
        '/importaciones/' || NEW.id, 'archivos_importados', NEW.id, 'archivo:' || NEW.id);
    PERFORM fn_notificar_permiso('analisis.seleccionar_perfil', NEW.usuario_carga_id, 'IMPORTACION_REVISION', 'ALTA',
        'Importación requiere revisión', 'El archivo #' || NEW.id || ' no se pudo clasificar con confianza: elija la hoja o el perfil.',
        '/importaciones/' || NEW.id, 'archivos_importados', NEW.id, 'archivo:' || NEW.id);
    RETURN NULL;
END;
$$;
CREATE TRIGGER archivos_notificar_revision AFTER UPDATE OF estado ON archivos_importados
    FOR EACH ROW EXECUTE FUNCTION fn_notif_archivo_revision();

-- Normalización con registros por decidir (sugerencias, ambiguos, conflictos o sin identificar)
CREATE FUNCTION fn_notif_normalizacion() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_pend integer;
    v_antes integer;
BEGIN
    IF NEW.estado <> 'VIGENTE' OR NEW.metricas IS NOT DISTINCT FROM OLD.metricas THEN
        RETURN NULL;
    END IF;
    SELECT coalesce(sum((value)::integer), 0) INTO v_pend
      FROM jsonb_each_text(coalesce(NEW.metricas->'por_estado', '{}'::jsonb))
     WHERE key IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO') AND value ~ '^[0-9]{1,9}$';
    SELECT coalesce(sum((value)::integer), 0) INTO v_antes
      FROM jsonb_each_text(coalesce(OLD.metricas->'por_estado', '{}'::jsonb))
     WHERE key IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO') AND value ~ '^[0-9]{1,9}$';
    -- Sólo si AUMENTAN los pendientes (una re-asociación tras cada decisión manual no debe volver a avisar a todos)
    IF v_pend > v_antes THEN
        PERFORM fn_notificar_permiso('asociaciones.resolver', NULL, 'ASOCIACIONES_PENDIENTES', 'NORMAL',
            'Nuevas asociaciones pendientes',
            v_pend || ' registro(s) del archivo #' || NEW.archivo_id || ' requieren una decisión de identidad.',
            '/revision-masiva', 'importaciones_normalizadas', NEW.id, 'normalizacion:' || NEW.id);
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER normalizaciones_notificar AFTER UPDATE OF metricas ON importaciones_normalizadas
    FOR EACH ROW EXECUTE FUNCTION fn_notif_normalizacion();

-- ---------------------------------------------------------------------
-- Ejecuciones del sistema (salud): sólo conteos y resultado, nunca rutas ni datos
-- ---------------------------------------------------------------------
CREATE TABLE sistema_ejecuciones (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tipo        text        NOT NULL,
    origen      text        NOT NULL,
    usuario_id  bigint      REFERENCES usuarios (id) ON DELETE SET NULL,
    inicio_at   timestamptz NOT NULL,
    fin_at      timestamptz NOT NULL DEFAULT now(),
    resultado   text        NOT NULL,
    detalle     jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT se_tipo CHECK (tipo IN ('SCHEDULER', 'ALMACENAMIENTO', 'RESPALDO', 'MANTENIMIENTO')),
    CONSTRAINT se_origen CHECK (origen IN ('CLI', 'WEB', 'TIMER')),
    CONSTRAINT se_resultado CHECK (resultado IN ('OK', 'ADVERTENCIA', 'ERROR')),
    CONSTRAINT se_detalle CHECK (jsonb_typeof(detalle) = 'object' AND octet_length(detalle::text) <= 4000)
);
CREATE INDEX se_tipo_idx ON sistema_ejecuciones (tipo, fin_at DESC);
GRANT SELECT ON sistema_ejecuciones TO conciliarh_web;

CREATE FUNCTION fn_sistema_ejecucion(p_tipo text, p_origen text, p_inicio timestamptz, p_resultado text, p_detalle jsonb)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_id bigint;
BEGIN
    -- Desde la WEB sólo se registra la acción de mantenimiento permitida, con su actor y permiso (no se falsifican respaldos ni
    -- ejecuciones del scheduler). Desde CLI/servicio, sin usuario.
    -- (bin/verificar-almacenamiento.php corre con el rol web y SIN sesión: sólo puede registrar su propia revisión como CLI)
    IF fn_es_conexion_web() THEN
        IF NOT ((p_origen = 'WEB' AND p_tipo = 'ALMACENAMIENTO' AND fn_actor_actual() IS NOT NULL AND fn_actor_tiene_permiso('sistema.mantenimiento'))
                OR (p_origen = 'CLI' AND p_tipo = 'ALMACENAMIENTO' AND fn_actor_actual() IS NULL)) THEN
            RAISE EXCEPTION 'Registro de ejecución no permitido desde la web' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    INSERT INTO sistema_ejecuciones (tipo, origen, usuario_id, inicio_at, resultado, detalle)
    VALUES (p_tipo, p_origen, CASE WHEN p_origen = 'WEB' THEN fn_actor_actual() END, p_inicio, p_resultado, coalesce(p_detalle, '{}'::jsonb))
    RETURNING id INTO v_id;
    -- Se conservan las 500 más recientes por tipo
    DELETE FROM sistema_ejecuciones WHERE tipo = p_tipo AND id NOT IN
        (SELECT id FROM sistema_ejecuciones WHERE tipo = p_tipo ORDER BY id DESC LIMIT 500);
    RETURN v_id;
END;
$$;

-- Mantenimiento periódico (lo invoca el scheduler en cada ciclo): purga notificaciones por retención
CREATE FUNCTION fn_mantenimiento_notificaciones() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_dias integer := coalesce((SELECT valor::integer FROM configuracion WHERE clave = 'notificaciones.retencion_dias'), 90);
    n integer;
BEGIN
    DELETE FROM notificaciones
     WHERE (leida_at IS NOT NULL AND leida_at < now() - make_interval(days => v_dias))
        OR created_at < now() - make_interval(days => v_dias * 2);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END;
$$;

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('notificaciones.retencion_dias', '90', 'entero', 'Días que se conservan las notificaciones leídas (las no leídas, el doble)', 7, 365, true)
ON CONFLICT (clave) DO NOTHING;

INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('sistema.salud',         'sistema', 'Ver la salud del sistema: workers, cola, scheduler, almacenamiento, migraciones y versiones', false, false),
    ('sistema.mantenimiento', 'sistema', 'Ejecutar acciones seguras de mantenimiento (revisar almacenamiento)', false, true)
ON CONFLICT (codigo) DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('sistema.salud', 'sistema.mantenimiento') WHERE r.codigo = 'GOD'
ON CONFLICT DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'sistema.salud' WHERE r.codigo = 'ADMIN'
ON CONFLICT DO NOTHING;

REVOKE ALL ON FUNCTION fn_notificar(bigint, text, text, text, text, text, text, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_notificar_permiso(text, bigint, text, text, text, text, text, text, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_sistema_ejecucion(text, text, timestamptz, text, jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_mantenimiento_notificaciones() FROM PUBLIC;
REVOKE ALL ON FUNCTION fn_notif_conciliacion(), fn_notif_reporte_generado(), fn_notif_archivo_revision(), fn_notif_normalizacion() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_sistema_ejecucion(text, text, timestamptz, text, jsonb) TO conciliarh_web, conciliarh_scheduler;
GRANT EXECUTE ON FUNCTION fn_mantenimiento_notificaciones() TO conciliarh_scheduler;
GRANT SELECT ON sistema_ejecuciones TO conciliarh_scheduler;

-- Salud del sistema: la web ve QUÉ migraciones están aplicadas (versión, nombre, fecha), nunca su contenido ni quién las aplicó
GRANT SELECT (version, nombre, aplicada_at) ON schema_migraciones TO conciliarh_web;
