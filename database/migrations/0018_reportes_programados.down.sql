-- Revierte 0018 (sólo desarrollo/pruebas). Los archivos generados en storage/reportes no se borran aquí.
DROP TRIGGER IF EXISTS procesos_consumidor ON procesos;
DROP FUNCTION IF EXISTS fn_procesos_consumidor();
DO $$
DECLARE r record;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'conciliarh_scheduler') THEN
        FOR r IN SELECT DISTINCT table_name FROM information_schema.column_privileges WHERE grantee = 'conciliarh_scheduler' AND table_schema = 'public' LOOP
            EXECUTE format('REVOKE ALL ON %I FROM conciliarh_scheduler', r.table_name);
        END LOOP;
        FOR r IN SELECT DISTINCT table_name FROM information_schema.table_privileges WHERE grantee = 'conciliarh_scheduler' AND table_schema = 'public' LOOP
            EXECUTE format('REVOKE ALL ON %I FROM conciliarh_scheduler', r.table_name);
        END LOOP;
    END IF;
END $$;
DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE codigo IN ('reportes.programar', 'reportes.administrar_programados'));
DELETE FROM permisos WHERE codigo IN ('reportes.programar', 'reportes.administrar_programados');
DELETE FROM configuracion WHERE clave IN ('reportes.programados_max_por_usuario', 'reportes.scheduler_max_por_ciclo', 'reportes.errores_para_pausar',
    'reportes.generar_manual_por_hora', 'reportes.max_mb_por_usuario');
DROP FUNCTION IF EXISTS fn_servicio_usuario(bigint);
DROP FUNCTION IF EXISTS fn_reporte_descargado(bigint);
DROP FUNCTION IF EXISTS fn_reporte_marcar_alterado(bigint);
DROP FUNCTION IF EXISTS fn_reportes_encolar_limpieza();
DROP FUNCTION IF EXISTS fn_reporte_encolar(bigint, timestamptz, text);
DROP TABLE IF EXISTS reportes_generados;
DROP FUNCTION IF EXISTS fn_reporte_generado_reglas();
DROP TABLE IF EXISTS reportes_programados;
DROP FUNCTION IF EXISTS fn_reporte_programado_reglas();
