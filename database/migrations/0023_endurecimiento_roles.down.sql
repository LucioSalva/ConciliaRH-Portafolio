-- =====================================================================
-- 0023 (down) — Restaura el modelo anterior (sólo desarrollo/pruebas; en producción `down` está bloqueado).
-- Las definiciones de las funciones son las vigentes ANTES de 0023, tal como las reporta pg_get_functiondef.
-- =====================================================================
GRANT USAGE ON SCHEMA public TO PUBLIC;

DROP TRIGGER IF EXISTS usuarios_actor ON usuarios;
CREATE TRIGGER usuarios_actor BEFORE INSERT OR UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('creado_por');

-- search_path de las funciones plpgsql invoker (lo fijó 0023)
DO $$
DECLARE f regprocedure;
BEGIN
    FOR f IN SELECT p.oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang
              WHERE n.nspname = 'public' AND NOT p.prosecdef AND l.lanname = 'plpgsql'
                AND p.proconfig = ARRAY['search_path=public, pg_temp'] LOOP
        EXECUTE format('ALTER FUNCTION %s RESET search_path', f);
    END LOOP;
END $$;

DROP TRIGGER IF EXISTS procesos_web ON procesos;
DROP FUNCTION IF EXISTS fn_procesos_web();
DROP TRIGGER IF EXISTS configuracion_validar ON configuracion;
DROP FUNCTION IF EXISTS fn_configuracion_validar();
DROP TRIGGER IF EXISTS usuarios_credenciales_auditar ON usuarios;
DROP FUNCTION IF EXISTS fn_usuario_credenciales_auditar();
DROP TRIGGER IF EXISTS usuarios_god_protegido ON usuarios;
DROP FUNCTION IF EXISTS fn_usuario_god_protegido();
DROP TRIGGER IF EXISTS roles_rbac_web ON roles;
DROP TRIGGER IF EXISTS rol_permiso_rbac_web ON rol_permiso;
DROP TRIGGER IF EXISTS usuario_rol_rbac_web ON usuario_rol;
DROP FUNCTION IF EXISTS fn_rbac_web();
DROP TRIGGER IF EXISTS asociaciones_historial_a_servicio ON asociaciones_historial;
DROP FUNCTION IF EXISTS fn_worker_historial_autoria();
DROP TRIGGER IF EXISTS registros_rrhh_a_servicio ON registros_rrhh;
DROP TRIGGER IF EXISTS registros_tesoreria_a_servicio ON registros_tesoreria;
DROP FUNCTION IF EXISTS fn_worker_asociado_por();
DROP INDEX IF EXISTS registros_rrhh_manual_idx;
DROP INDEX IF EXISTS registros_tesoreria_manual_idx;
DROP TRIGGER IF EXISTS empleado_historial_a_servicio ON empleado_historial;
DROP FUNCTION IF EXISTS fn_worker_empleado_historial();
DROP TRIGGER IF EXISTS cuentas_bancarias_a_servicio ON cuentas_bancarias;
DROP TRIGGER IF EXISTS beneficiarios_pension_a_servicio ON beneficiarios_pension;
DROP TRIGGER IF EXISTS beneficiario_trabajadores_a_servicio ON beneficiario_trabajadores;
DROP FUNCTION IF EXISTS fn_worker_sin_confirmacion_manual();

GRANT UPDATE (cuenta_cifrada, key_id) ON cuentas_bancarias TO conciliarh_worker;
GRANT UPDATE (cuenta_cifrada, cuenta_key_id) ON staging_registros TO conciliarh_worker;
GRANT EXECUTE ON FUNCTION fn_auditoria_encadenar(), fn_proceso_a_archivo(), fn_archivo_encolar_analisis(),
    fn_archivo_encolar_normalizacion(), fn_archivo_invalidar_importacion(), fn_proceso_a_conciliacion() TO PUBLIC;

-- Funciones reemplazadas: versión anterior exacta
CREATE OR REPLACE FUNCTION public.fn_auditoria_actor()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_actor bigint;
BEGIN
    IF NOT fn_es_conexion_web() THEN
        RETURN NEW;
    END IF;
    -- Dentro de fn_sesion_vincular (SECURITY DEFINER) el registro lo escribe la propia BD
    IF current_user <> session_user AND NEW.origen = 'SISTEMA' AND NEW.accion = 'auth.sesion_vinculada' THEN
        RETURN NEW;
    END IF;
    IF NEW.origen <> 'WEB' THEN
        RAISE EXCEPTION 'La aplicación web sólo registra auditoría de origen WEB' USING ERRCODE = 'insufficient_privilege';
    END IF;
    v_actor := fn_actor_actual();
    IF NEW.usuario_id IS NOT NULL AND NEW.usuario_id IS DISTINCT FROM v_actor THEN
        RAISE EXCEPTION 'La autoría del registro de auditoría no corresponde al usuario de la sesión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NEW.usuario_id IS NULL THEN
        NEW.username := NULL;
        NEW.roles := NULL;
    ELSE
        NEW.username := (SELECT username FROM usuarios WHERE id = NEW.usuario_id);
        NEW.roles := (SELECT string_agg(r.codigo, ',' ORDER BY r.codigo) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
                       WHERE ur.usuario_id = NEW.usuario_id);
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.fn_sesion_vincular(p_token text, p_usuario_id bigint, p_ip inet, p_user_agent text)
 RETURNS void
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'public', 'pg_temp'
AS $function$
DECLARE
    v_hash text := fn_token_hash(p_token);
    v_ok   text;
BEGIN
    IF v_hash IS NULL THEN
        RAISE EXCEPTION 'Token de sesión con formato inválido' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM usuarios WHERE id = p_usuario_id AND activo AND deleted_at IS NULL) THEN
        RAISE EXCEPTION 'Usuario inexistente o inactivo' USING ERRCODE = 'insufficient_privilege';
    END IF;
    PERFORM set_config('conciliarh.sesion_vinculando', v_hash, true);
    INSERT INTO sesiones (id, usuario_id, ip, user_agent) VALUES (v_hash, p_usuario_id, p_ip, left(p_user_agent, 300))
    ON CONFLICT (id) DO UPDATE SET usuario_id = EXCLUDED.usuario_id, ultima_actividad_at = now()
     WHERE sesiones.revocada_at IS NULL AND (sesiones.usuario_id IS NULL OR sesiones.usuario_id = EXCLUDED.usuario_id)
    RETURNING id INTO v_ok;
    PERFORM set_config('conciliarh.sesion_vinculando', '', true);
    IF v_ok IS NULL THEN
        RAISE EXCEPTION 'La sesión ya está vinculada a otro usuario o fue revocada' USING ERRCODE = 'insufficient_privilege';
    END IF;
    PERFORM set_config('conciliarh.actor_token', p_token, false);
    INSERT INTO auditoria (usuario_id, origen, ip, user_agent, accion, entidad, entidad_id, contexto, hash)
    VALUES (p_usuario_id, 'SISTEMA', p_ip, left(p_user_agent, 300), 'auth.sesion_vinculada', 'usuarios', p_usuario_id::text,
            jsonb_build_object('sesion', left(v_hash, 12)), repeat('0', 64));
END;
$function$;

CREATE OR REPLACE FUNCTION public.fn_confirmar_cuenta(p_registro_id bigint, p_usuario_id bigint, p_motivo text)
 RETURNS bigint
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'public', 'pg_temp'
AS $function$
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
$function$;

CREATE OR REPLACE FUNCTION public.fn_crear_lote(p_periodo_id bigint, p_nombre text, p_descripcion text, p_origen text, p_observaciones text, p_usuario_id bigint)
 RETURNS lotes_importacion
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'public', 'pg_temp'
AS $function$
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
$function$;

CREATE OR REPLACE FUNCTION public.fn_procesos_consumidor()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF pg_has_role(session_user, 'conciliarh_scheduler', 'MEMBER') AND current_user = session_user
       AND OLD.tipo NOT IN ('GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS') THEN
        RAISE EXCEPTION 'El scheduler sólo atiende trabajos de reportes' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.fn_sistema_ejecucion(p_tipo text, p_origen text, p_inicio timestamp with time zone, p_resultado text, p_detalle jsonb)
 RETURNS bigint
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'public', 'pg_temp'
AS $function$
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
$function$;

CREATE OR REPLACE FUNCTION public.fn_importaciones_con_cuenta(p_registro_id bigint)
 RETURNS SETOF bigint
 LANGUAGE sql
 STABLE SECURITY DEFINER
 SET search_path TO 'public', 'pg_temp'
AS $function$
    SELECT DISTINCT t.importacion_id
      FROM registros_tesoreria r
      JOIN cuentas_bancarias c ON c.origen_registro_id = r.id AND c.origen = 'CONFIRMACION_MANUAL' AND c.estado = 'ACTIVA'
      JOIN registros_tesoreria t ON t.cuenta_hmac = r.cuenta_hmac AND t.id <> r.id
      JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
      JOIN periodos_nomina pe ON pe.id = n.periodo_id AND pe.estado = 'ABIERTO'
     WHERE r.id = p_registro_id AND r.cuenta_hmac IS NOT NULL
       AND t.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO');
$function$;

CREATE OR REPLACE FUNCTION public.fn_fusion_conflictos_extra(p_origen bigint, p_destino bigint)
 RETURNS text[]
 LANGUAGE sql
 STABLE SECURITY DEFINER
 SET search_path TO 'public', 'pg_temp'
AS $function$
    SELECT CASE WHEN EXISTS (SELECT 1 FROM empleado_historial h
                              WHERE h.empleado_id IN (p_origen, p_destino) AND h.fuente = 'MANUAL'
                                AND h.campo IN ('rfc', 'curp', 'issemym') AND h.valor_anterior IS NOT NULL)
                THEN ARRAY['IDENTIFICADOR_EDITADO']::text[] ELSE '{}'::text[] END
$function$;

DROP FUNCTION IF EXISTS fn_conexion_directa(text);
