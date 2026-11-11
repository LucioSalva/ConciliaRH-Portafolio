-- Reversión de 0009 (sólo desarrollo/pruebas): restaura las definiciones de 0007/0006/0008
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

CREATE OR REPLACE FUNCTION fn_proceso_a_archivo() RETURNS trigger
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

CREATE OR REPLACE VIEW v_lotes_resumen AS
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

GRANT INSERT (periodo_id, numero, nombre, descripcion, origen, observaciones, creado_por) ON lotes_importacion TO conciliarh_web;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p WHERE r.codigo = 'ANALISTA' AND p.codigo = 'lotes.cerrar'
ON CONFLICT DO NOTHING;
