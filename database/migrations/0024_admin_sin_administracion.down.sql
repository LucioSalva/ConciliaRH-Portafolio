-- 0024 (down) — Devuelve a ADMIN los permisos de administración (sólo desarrollo/pruebas).
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar', 'usuarios.eliminar',
    'usuarios.restablecer_password', 'usuarios.cerrar_sesiones', 'roles.ver', 'configuracion.ver',
    'conceptos.editar', 'sistema.salud', 'procesos.cancelar', 'procesos.ver_detalle_tecnico')
 WHERE r.codigo = 'ADMIN'
ON CONFLICT DO NOTHING;

UPDATE roles SET descripcion = 'Administración operativa: usuarios no protegidos, consulta de auditoría y procesos.'
 WHERE codigo = 'ADMIN';
