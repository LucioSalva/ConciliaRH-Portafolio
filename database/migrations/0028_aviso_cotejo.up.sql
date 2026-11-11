-- =====================================================================
-- 0028 — El aviso de un cotejo terminado habla de la quincena y del número de cotejo, sin «lote» ni «ejecución».
-- Sólo cambian los textos; el disparador, los destinatarios y los permisos son los de 0019.
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_notif_conciliacion() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_desc text;
BEGIN
    IF NEW.estado IS NOT DISTINCT FROM OLD.estado
       OR NEW.estado NOT IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'ERROR') THEN
        RETURN NULL;
    END IF;
    SELECT pe.descripcion INTO v_desc
      FROM lotes_importacion l JOIN periodos_nomina pe ON pe.id = l.periodo_id WHERE l.id = NEW.lote_id;
    IF NEW.estado = 'ERROR' THEN
        PERFORM fn_notificar(NEW.solicitado_por, 'CONCILIACION_ERROR', 'ALTA', 'El cotejo no pudo terminar',
            coalesce(v_desc, 'Quincena') || ', cotejo n.º ' || NEW.numero || '. Vuelva a cotejar; si se repite, avise a la administración técnica.',
            '/conciliaciones/' || NEW.id, 'conciliaciones', NEW.id, 'conciliacion:' || NEW.id);
    ELSE
        PERFORM fn_notificar(NEW.solicitado_por, 'CONCILIACION_TERMINADA',
            CASE WHEN NEW.estado = 'COMPLETADA' THEN 'NORMAL' ELSE 'ALTA' END, 'Cotejo terminado',
            coalesce(v_desc, 'Quincena') || ', cotejo n.º ' || NEW.numero || ': '
                || CASE NEW.estado WHEN 'COMPLETADA' THEN 'completado.' WHEN 'COMPLETADA_CON_INCIDENCIAS' THEN 'completado con incidencias.'
                                   ELSE 'requiere revisión manual.' END,
            '/conciliaciones/' || NEW.id, 'conciliaciones', NEW.id, 'conciliacion:' || NEW.id);
    END IF;
    RETURN NULL;
END;
$$;
