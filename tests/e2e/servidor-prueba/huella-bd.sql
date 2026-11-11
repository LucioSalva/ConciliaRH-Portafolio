SELECT 'usuarios', count(*)::text FROM usuarios
UNION ALL SELECT 'archivos', count(*)::text FROM archivos_importados
UNION ALL SELECT 'archivos_sha256', md5(string_agg(sha256, ',' ORDER BY id)) FROM archivos_importados
UNION ALL SELECT 'registros_rrhh', count(*)::text FROM registros_rrhh
UNION ALL SELECT 'registros_tesoreria', count(*)::text FROM registros_tesoreria
UNION ALL SELECT 'conciliaciones', string_agg(id || ':' || estado, ',' ORDER BY id) FROM conciliaciones
UNION ALL SELECT 'resultados', md5(string_agg(empleado_id || ':' || estado || ':' || diferencia, ',' ORDER BY empleado_id)) FROM conciliacion_empleados
UNION ALL SELECT 'incidencias', string_agg(estado || ':' || n, ',' ORDER BY estado) FROM (SELECT estado, count(*) AS n FROM conciliacion_incidencias GROUP BY 1) x
UNION ALL SELECT 'cuentas', count(*)::text FROM cuentas_bancarias
UNION ALL SELECT 'reportes', string_agg(id || ':' || estado || ':' || sha256, ',' ORDER BY id) FROM reportes_generados
UNION ALL SELECT 'auditoria', count(*)::text FROM auditoria
UNION ALL SELECT 'auditoria_ultimo_hash', (SELECT hash FROM auditoria ORDER BY secuencia DESC LIMIT 1)
UNION ALL SELECT 'auditoria_integra', (SELECT integra::text FROM fn_auditoria_verificar())
UNION ALL SELECT 'migraciones', string_agg(version, ',' ORDER BY version) FROM schema_migraciones;
