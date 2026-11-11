-- Reversión de 0004 (sólo desarrollo/pruebas)
DELETE FROM configuracion WHERE clave IN (
    'conciliacion.tolerancia_monetaria', 'seguridad.login_max_intentos_usuario',
    'seguridad.login_max_intentos_ip', 'seguridad.login_ventana_minutos',
    'seguridad.password_longitud_minima', 'procesos.max_intentos_predeterminado');
DELETE FROM rol_permiso;
DELETE FROM usuario_rol WHERE rol_id IN (SELECT id FROM roles WHERE es_sistema);
DELETE FROM permisos;
DELETE FROM roles WHERE es_sistema;
