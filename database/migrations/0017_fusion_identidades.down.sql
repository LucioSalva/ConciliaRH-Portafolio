-- Revierte 0017 (sólo desarrollo/pruebas). Las fusiones se pierden: los empleados vuelven a ser independientes.
DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE codigo IN ('empleados.duplicados', 'empleados.fusionar',
    'empleados.fusionar_conflictos', 'empleados.separar'));
DELETE FROM permisos WHERE codigo IN ('empleados.duplicados', 'empleados.fusionar', 'empleados.fusionar_conflictos', 'empleados.separar');
REVOKE EXECUTE ON FUNCTION fn_actor_tiene_permiso(text) FROM conciliarh_web;
DROP FUNCTION IF EXISTS fn_separar_fusion(bigint, bigint, text);
DROP FUNCTION IF EXISTS fn_fusionar_empleados(bigint, bigint, bigint, text, text[], text);
DROP FUNCTION IF EXISTS fn_fusion_huella(jsonb);
DROP FUNCTION IF EXISTS fn_fusion_recalcular();
DROP FUNCTION IF EXISTS fn_fusion_analisis(bigint, bigint);
DROP TRIGGER IF EXISTS registros_rrhh_no_fusionado ON registros_rrhh;
DROP TRIGGER IF EXISTS registros_tesoreria_no_fusionado ON registros_tesoreria;
DROP FUNCTION IF EXISTS fn_registro_no_fusionado();
DROP TABLE IF EXISTS duplicados_empleados;
DROP FUNCTION IF EXISTS fn_duplicado_reglas();
ALTER TABLE empleados DROP CONSTRAINT IF EXISTS empleados_fusion_fk, DROP CONSTRAINT IF EXISTS empleados_fusion;
DROP INDEX IF EXISTS empleados_fusionado_idx;
DROP INDEX IF EXISTS empleados_issemym_idx;
ALTER TABLE empleados DROP COLUMN IF EXISTS fusionado_en_id, DROP COLUMN IF EXISTS fusion_id;
DROP TABLE IF EXISTS fusiones_empleados;
DROP FUNCTION IF EXISTS fn_fusion_reglas();
SET conciliarh.identidad_mantenimiento = 'on';
DELETE FROM empleado_historial WHERE fuente IN ('FUSION', 'SEPARACION');
RESET conciliarh.identidad_mantenimiento;
ALTER TABLE empleado_historial DROP CONSTRAINT IF EXISTS empleado_historial_fusion;
ALTER TABLE empleado_historial DROP CONSTRAINT IF EXISTS empleado_historial_fuente;
ALTER TABLE empleado_historial ADD CONSTRAINT empleado_historial_fuente CHECK (fuente IN ('ALTA_RRHH', 'RRHH_TABULADO', 'MANUAL'));

-- Restaurar las funciones de confirmación de 0011/0015
CREATE OR REPLACE FUNCTION fn_confirmar_cuenta(p_registro_id bigint, p_usuario_id bigint, p_motivo text)
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

CREATE OR REPLACE FUNCTION fn_confirmar_beneficiario(p_registro_id bigint, p_usuario_id bigint, p_beneficiario_id bigint, p_motivo text,
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
DROP FUNCTION IF EXISTS fn_empleado_canonico(bigint);
