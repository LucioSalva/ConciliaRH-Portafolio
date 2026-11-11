-- Reversión de 0010 (sólo desarrollo/pruebas). Restaura las definiciones de 0009.
-- Los trabajos ANALIZAR_ARCHIVO son artefactos de 0010: se devuelven los archivos a su último trabajo
-- de verificación y se eliminan (así un `up` posterior vuelve a encolar el análisis sin chocar con claves)
UPDATE archivos_importados a
   SET proceso_id = (SELECT max(v.id) FROM procesos v WHERE v.tipo = 'VERIFICAR_ARCHIVO' AND v.parametros->>'archivo_id' = a.id::text)
 WHERE a.proceso_id IN (SELECT id FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO');
DELETE FROM proceso_eventos WHERE proceso_id IN (SELECT id FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO');
UPDATE workers SET proceso_actual_id = NULL WHERE proceso_actual_id IN (SELECT id FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO');
DELETE FROM configuracion WHERE clave LIKE 'analisis.%';
DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo = 'analisis');
DELETE FROM permisos WHERE modulo = 'analisis';

DROP TRIGGER IF EXISTS archivos_zz_encolar_analisis ON archivos_importados;
DROP FUNCTION IF EXISTS fn_archivo_encolar_analisis();

DROP VIEW v_lotes_resumen;
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
         WHEN count(a.id) FILTER (WHERE a.estado = 'VERIFICADO') > 0 THEN 'VERIFICADO'
         ELSE 'LISTO'
       END AS estado_procesamiento
  FROM lotes_importacion l
  LEFT JOIN archivos_importados a ON a.lote_id = l.id
 GROUP BY l.id;
GRANT SELECT ON v_lotes_resumen TO conciliarh_web;

UPDATE archivos_importados SET analisis_vigente_id = NULL, perfil_importacion_id = NULL;
ALTER TABLE archivos_importados DROP COLUMN analisis_vigente_id;
DROP TABLE staging_registros;
DROP TABLE controles_archivo;
DROP TABLE archivo_columnas;
DROP TABLE archivo_hojas;
DROP TABLE analisis_archivo;
DELETE FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO';

-- Estados/clasificaciones nuevos → equivalentes previos antes de restaurar las restricciones
ALTER TABLE archivos_importados DISABLE TRIGGER archivos_reglas;
UPDATE archivos_importados SET estado = 'VERIFICADO' WHERE estado IN ('REVISION_MANUAL', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ANALIZANDO');
UPDATE archivos_importados SET clasificacion_duplicidad = 'PENDIENTE', archivo_relacionado_id = NULL
 WHERE clasificacion_duplicidad IN ('SIN_HUELLA', 'REVISION_MANUAL', 'NUEVO', 'DUPLICADO_CONTENIDO', 'POSIBLE_VERSION');
UPDATE archivos_importados SET huella_contenido = NULL, huella_version = NULL;
ALTER TABLE archivos_importados ENABLE TRIGGER archivos_reglas;

ALTER TABLE archivos_importados DROP CONSTRAINT archivos_hoja_manual;
ALTER TABLE archivos_importados DROP COLUMN hoja_manual_indice, DROP COLUMN hoja_manual_nombre,
    DROP COLUMN perfil_manual_id, DROP COLUMN seleccion_manual_por, DROP COLUMN seleccion_manual_at;
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_relacion;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_relacion CHECK (archivo_relacionado_id IS DISTINCT FROM id
    AND (clasificacion_duplicidad IN ('PENDIENTE', 'NUEVO') OR archivo_relacionado_id IS NOT NULL));
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_clasificacion;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_clasificacion CHECK (clasificacion_duplicidad IN
    ('PENDIENTE', 'NUEVO', 'DUPLICADO_BINARIO', 'DUPLICADO_CONTENIDO', 'POSIBLE_VERSION'));
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_estado;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_estado CHECK (estado IN ('RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'RECHAZADO', 'ANALIZANDO',
    'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ERROR', 'ANULADO'));

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

    IF NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NOT (
               (OLD.estado = 'RECIBIDO'    AND NEW.estado IN ('VERIFICANDO', 'ERROR', 'ANULADO'))
            OR (OLD.estado = 'VERIFICANDO' AND NEW.estado IN ('VERIFICADO', 'RECHAZADO', 'ERROR', 'RECIBIDO', 'ANULADO'))
            OR (OLD.estado = 'VERIFICADO'  AND NEW.estado IN ('ANALIZANDO', 'ANULADO'))
            OR (OLD.estado = 'ANALIZANDO'  AND NEW.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ERROR', 'VERIFICADO'))
            OR (OLD.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'RECHAZADO') AND NEW.estado = 'ANULADO')
            OR (OLD.estado = 'ERROR'       AND NEW.estado IN ('RECIBIDO', 'ANULADO'))
        ) THEN
            RAISE EXCEPTION 'Transición de estado inválida: % → %', OLD.estado, NEW.estado USING ERRCODE = 'check_violation';
        END IF;
        -- La aplicación web sólo reencola (→ RECIBIDO) o anula; los resultados los fija el motor.
        IF current_user = 'conciliarh_web' AND NEW.estado NOT IN ('RECIBIDO', 'ANULADO') THEN
            RAISE EXCEPTION 'El rol web no puede fijar el estado % (reservado al motor de verificación)', NEW.estado
                USING ERRCODE = 'insufficient_privilege';
        END IF;
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
            UPDATE archivos_importados
               SET estado = 'ERROR', error_codigo = 'VERIFICACION_CANCELADA',
                   error_resumen = 'La verificación fue cancelada; puede reprocesarse.'
             WHERE proceso_id = NEW.id AND estado IN ('RECIBIDO', 'VERIFICANDO');
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

DELETE FROM perfiles_importacion WHERE codigo IN ('RRHH_TABULADO_POLICIA', 'RRHH_TABULADO_BOMBEROS',
    'RRHH_POLIZA_GLOBAL_POLICIA', 'RRHH_POLIZA_GLOBAL_BOMBEROS');
UPDATE perfiles_importacion SET implementado = false;
UPDATE perfiles_importacion SET activo = true,
       descripcion = replace(descripcion, ' (Sustituido en FASE 3 por las variantes POLICIA/BOMBEROS.)', '')
 WHERE codigo IN ('RRHH_TABULADO', 'RRHH_POLIZA_GLOBAL');
ALTER TABLE perfiles_importacion DROP CONSTRAINT perfiles_familia_formato;
ALTER TABLE perfiles_importacion DROP COLUMN familia, DROP COLUMN reglas_version;
