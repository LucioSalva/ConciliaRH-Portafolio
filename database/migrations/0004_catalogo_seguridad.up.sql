-- =====================================================================
-- 0004 · Catálogo base: roles del sistema, permisos de la FASE 1,
--        asignaciones y configuración funcional inicial.
-- Nuevos módulos agregarán sus permisos en migraciones posteriores.
-- GOD recibe TODOS los permisos (explícitos aquí y además por regla en la app).
-- =====================================================================

INSERT INTO roles (codigo, nombre, descripcion, es_sistema, protegido) VALUES
    ('GOD',      'GOD',      'Máximo nivel. Uso exclusivo del responsable del sistema. Se crea sólo por CLI.', true, true),
    ('ADMIN',    'Administrador', 'Administración operativa: usuarios no protegidos, consulta de auditoría y procesos.', true, true),
    ('ANALISTA', 'Analista', 'Operación de importaciones y conciliaciones (se amplía en fases posteriores).', true, false),
    ('CONSULTA', 'Consulta', 'Sólo lectura de tableros y resultados.', true, false);

INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('dashboard.ver',                 'dashboard',     'Ver el tablero principal', false, false),
    ('usuarios.ver',                  'usuarios',      'Listar y consultar usuarios', false, false),
    ('usuarios.crear',                'usuarios',      'Crear usuarios', false, true),
    ('usuarios.editar',               'usuarios',      'Editar datos y roles de usuarios', false, true),
    ('usuarios.desactivar',           'usuarios',      'Activar o desactivar usuarios', false, true),
    ('usuarios.eliminar',             'usuarios',      'Eliminar lógicamente usuarios', false, true),
    ('usuarios.restablecer_password', 'usuarios',      'Asignar contraseña temporal a un usuario', false, true),
    ('usuarios.cerrar_sesiones',      'usuarios',      'Revocar sesiones activas de un usuario', false, true),
    ('roles.ver',                     'roles',         'Consultar roles y sus permisos', false, false),
    ('roles.administrar',             'roles',         'Crear roles y modificar la matriz de permisos', true, true),
    ('auditoria.ver',                 'auditoria',     'Consultar la bitácora de auditoría', false, false),
    ('auditoria.verificar',           'auditoria',     'Verificar la integridad de la cadena de auditoría', false, false),
    ('auditoria.administrar',         'auditoria',     'Mantenimiento técnico de la auditoría', true, true),
    ('configuracion.ver',             'configuracion', 'Consultar la configuración funcional', false, false),
    ('configuracion.editar',          'configuracion', 'Modificar la configuración funcional', true, true),
    ('procesos.ver',                  'procesos',      'Consultar trabajos en segundo plano', false, false),
    ('procesos.ver_detalle_tecnico',  'procesos',      'Ver el detalle técnico de errores de trabajos', false, false),
    ('procesos.cancelar',             'procesos',      'Cancelar trabajos pendientes', false, true),
    ('procesos.diagnostico',          'procesos',      'Encolar un trabajo de diagnóstico del worker', true, false);

-- GOD: todo
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p WHERE r.codigo = 'GOD';

-- ADMIN: administración operativa sin permisos solo_god
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'dashboard.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar',
    'usuarios.eliminar', 'usuarios.restablecer_password', 'usuarios.cerrar_sesiones',
    'roles.ver', 'auditoria.ver', 'auditoria.verificar', 'configuracion.ver',
    'procesos.ver', 'procesos.ver_detalle_tecnico', 'procesos.cancelar')
WHERE r.codigo = 'ADMIN';

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('dashboard.ver', 'procesos.ver')
WHERE r.codigo = 'ANALISTA';

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('dashboard.ver')
WHERE r.codigo = 'CONSULTA';

INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('conciliacion.tolerancia_monetaria', '0.01', 'decimal',
     'Diferencia máxima (MXN, en valor absoluto) para considerar un importe CORRECTO', 0, 100, true),
    ('seguridad.login_max_intentos_usuario', '5', 'entero',
     'Intentos fallidos permitidos por usuario dentro de la ventana antes de bloquear', 3, 20, true),
    ('seguridad.login_max_intentos_ip', '30', 'entero',
     'Intentos fallidos permitidos por dirección IP dentro de la ventana antes de bloquear', 5, 500, true),
    ('seguridad.login_ventana_minutos', '15', 'entero',
     'Ventana de tiempo (minutos) para contar intentos fallidos y duración del bloqueo', 1, 1440, true),
    ('seguridad.password_longitud_minima', '12', 'entero',
     'Longitud mínima de contraseña', 10, 64, true),
    ('procesos.max_intentos_predeterminado', '3', 'entero',
     'Intentos máximos por trabajo en segundo plano antes de marcarlo como ERROR', 1, 10, true);
