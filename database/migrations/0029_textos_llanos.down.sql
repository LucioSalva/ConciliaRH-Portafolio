-- 0029 (down) — Vuelve a los textos de 0019 y a los nombres de catálogo anteriores (sólo desarrollo/pruebas).
CREATE OR REPLACE FUNCTION fn_notif_archivo_revision() RETURNS trigger
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

CREATE OR REPLACE FUNCTION fn_notif_normalizacion() RETURNS trigger
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
    IF v_pend > v_antes THEN
        PERFORM fn_notificar_permiso('asociaciones.resolver', NULL, 'ASOCIACIONES_PENDIENTES', 'NORMAL',
            'Nuevas asociaciones pendientes',
            v_pend || ' registro(s) del archivo #' || NEW.archivo_id || ' requieren una decisión de identidad.',
            '/revision-masiva', 'importaciones_normalizadas', NEW.id, 'normalizacion:' || NEW.id);
    END IF;
    RETURN NULL;
END;
$$;

UPDATE tipos_documento SET nombre = 'Tabulado individual RRHH' WHERE codigo = 'RRHH_TABULADO' AND nombre = 'Nómina de RH (tabulado)';
UPDATE tipos_documento SET nombre = 'Póliza global RRHH' WHERE codigo = 'RRHH_POLIZA_GLOBAL' AND nombre = 'Póliza GLOBAL de RH';
UPDATE perfiles_importacion SET nombre = 'Tabulado RRHH (hoja Tabla)' WHERE codigo = 'RRHH_TABULADO' AND nombre = 'Nómina de RH (hoja Tabla)';
UPDATE perfiles_importacion SET nombre = 'Tabulado RRHH · Bomberos' WHERE codigo = 'RRHH_TABULADO_BOMBEROS' AND nombre = 'Nómina de RH · Bomberos';
UPDATE perfiles_importacion SET nombre = 'Tabulado RRHH · Policía' WHERE codigo = 'RRHH_TABULADO_POLICIA' AND nombre = 'Nómina de RH · Policías';
UPDATE perfiles_importacion SET nombre = 'Póliza GLOBAL RRHH · Bomberos' WHERE codigo = 'RRHH_POLIZA_GLOBAL_BOMBEROS' AND nombre = 'Póliza GLOBAL de RH · Bomberos';
UPDATE perfiles_importacion SET nombre = 'Póliza GLOBAL RRHH · Policía' WHERE codigo = 'RRHH_POLIZA_GLOBAL_POLICIA' AND nombre = 'Póliza GLOBAL de RH · Policías';
