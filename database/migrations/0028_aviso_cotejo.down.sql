-- 0028 (down) — Vuelve a los textos de la notificación de 0019 (sólo desarrollo/pruebas).
CREATE OR REPLACE FUNCTION fn_notif_conciliacion() RETURNS trigger
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
