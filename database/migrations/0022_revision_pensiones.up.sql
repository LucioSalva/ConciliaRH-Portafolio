-- =====================================================================
-- 0022 · Revisión especializada de pensiones con evidencia MEDIA (FASE 8, /revision-pensiones)
--  * Confirmar y rechazar reutilizan la asociación manual y fn_confirmar_beneficiario (nada se confirma solo).
--  * «Dejar pendiente» es una decisión humana explícita que NO cambia la asociación: se registra aquí (quién, cuándo,
--    por qué) para que la bandeja muestre que el caso ya se examinó. Sólo inserción.
-- =====================================================================
CREATE TABLE revision_pendientes (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    registro_tesoreria_id   bigint      NOT NULL REFERENCES registros_tesoreria (id),
    asociacion_version      integer     NOT NULL,
    usuario_id              bigint      NOT NULL REFERENCES usuarios (id),
    motivo                  text        NOT NULL,
    created_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT rp_motivo CHECK (char_length(btrim(motivo)) BETWEEN 5 AND 500)
);
CREATE INDEX revision_pendientes_registro_idx ON revision_pendientes (registro_tesoreria_id, created_at DESC);
CREATE TRIGGER revision_pendientes_actor BEFORE INSERT ON revision_pendientes
    FOR EACH ROW EXECUTE FUNCTION fn_actor_verificar('usuario_id');
CREATE TRIGGER revision_pendientes_sin_cambios BEFORE UPDATE OR DELETE ON revision_pendientes FOR EACH ROW EXECUTE FUNCTION fn_solo_insercion();
CREATE TRIGGER revision_pendientes_sin_truncate BEFORE TRUNCATE ON revision_pendientes FOR EACH STATEMENT EXECUTE FUNCTION fn_solo_insercion();
GRANT SELECT ON revision_pendientes TO conciliarh_web;

-- Única vía de escritura (revisión de seguridad FASE 8): actor, permiso y que el caso siga siendo una sugerencia MEDIA en esa versión
CREATE FUNCTION fn_revision_dejar_pendiente(p_registro bigint, p_version integer, p_usuario_id bigint, p_motivo text) RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_id bigint;
BEGIN
    IF p_usuario_id IS NULL OR fn_actor_actual() IS DISTINCT FROM p_usuario_id OR NOT fn_actor_tiene_permiso('asociaciones.resolver') THEN
        RAISE EXCEPTION 'Sin permiso para decidir casos de pensión' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM registros_tesoreria WHERE id = p_registro AND categoria = 'PENSION' AND asociacion_estado = 'SUGERENCIA'
                     AND asociacion_evidencia->>'categoria' = 'MEDIA' AND asociacion_version = p_version) THEN
        RAISE EXCEPTION 'REGISTRO_MODIFICADO: el caso ya no es una sugerencia MEDIA en esa versión' USING ERRCODE = 'serialization_failure';
    END IF;
    INSERT INTO revision_pendientes (registro_tesoreria_id, asociacion_version, usuario_id, motivo)
    VALUES (p_registro, p_version, p_usuario_id, btrim(p_motivo)) RETURNING id INTO v_id;
    RETURN v_id;
END;
$$;
REVOKE ALL ON FUNCTION fn_revision_dejar_pendiente(bigint, integer, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_revision_dejar_pendiente(bigint, integer, bigint, text) TO conciliarh_web;

-- ---------------------------------------------------------------------
-- Historial de la MISMA CLABE (línea de tiempo de la revisión) sin exponer la huella ni nombres: periodo, lote, importe,
-- estado, número del trabajador asociado, si el beneficiario se confirmó y si el nombre coincide con el del registro revisado.
-- Exige asociaciones.ver al actor de la sesión (la web no lee huellas: la comparación ocurre aquí dentro).
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_historial_clabe(p_registro bigint)
RETURNS TABLE (evento text, fecha timestamptz, periodo_id bigint, periodo text, lote_numero integer, importe numeric, estado text,
               empleado_numero text, beneficiario_confirmado boolean, mismo_nombre boolean, es_actual boolean)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r registros_tesoreria;
BEGIN
    IF NOT fn_actor_tiene_permiso('asociaciones.ver') THEN
        RAISE EXCEPTION 'Sin permiso para consultar el historial' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT * INTO r FROM registros_tesoreria WHERE id = p_registro;
    IF r.id IS NULL OR r.cuenta_hmac IS NULL THEN
        RETURN;
    END IF;
    RETURN QUERY
    SELECT 'PAGO'::text, pe.fecha_inicio::timestamptz, t.periodo_id, pe.descripcion, l.numero, t.importe, t.asociacion_estado,
           e.numero_empleado, t.beneficiario_id IS NOT NULL, t.nombre_normalizado IS NOT DISTINCT FROM r.nombre_normalizado, t.id = r.id
      FROM registros_tesoreria t
      JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
      JOIN lotes_importacion l ON l.id = t.lote_id AND l.estado <> 'ANULADO'
      JOIN periodos_nomina pe ON pe.id = t.periodo_id
      LEFT JOIN empleados e ON e.id = coalesce((SELECT fusionado_en_id FROM empleados WHERE id = t.empleado_id), t.empleado_id)
     WHERE t.cuenta_hmac = r.cuenta_hmac AND t.categoria = r.categoria
    UNION ALL
    SELECT 'CUENTA_CONFIRMADA'::text, c.confirmado_at, c.primer_periodo_id, pe.descripcion, NULL::integer, NULL::numeric, c.estado,
           e.numero_empleado, c.beneficiario_id IS NOT NULL, NULL::boolean, false
      FROM cuentas_bancarias c
      LEFT JOIN periodos_nomina pe ON pe.id = c.primer_periodo_id
      LEFT JOIN empleados e ON e.id = c.empleado_id
     WHERE c.cuenta_hmac = r.cuenta_hmac AND c.uso = CASE WHEN r.categoria = 'PENSION' THEN 'PENSION_BENEFICIARIO' ELSE 'NOMINA' END
     ORDER BY 2, 1;
END;
$$;
REVOKE ALL ON FUNCTION fn_historial_clabe(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_historial_clabe(bigint) TO conciliarh_web;
