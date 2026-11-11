-- =====================================================================
-- 0024 — ADMIN deja de administrar el sistema.
-- Usuarios, roles, catálogos, conceptos, configuración, procesos y salud quedan sólo para GOD.
-- De la sección Administración, ADMIN conserva únicamente la auditoría (auditoria.ver, auditoria.verificar).
-- procesos.ver se conserva en ADMIN y ANALISTA: da el avance de un archivo en Importaciones; la LISTA de procesos
-- (/procesos) pasa a exigir procesos.diagnostico (config/routes.php).
-- =====================================================================
DELETE FROM rol_permiso rp
 USING roles r, permisos p
 WHERE rp.rol_id = r.id AND rp.permiso_id = p.id AND r.codigo = 'ADMIN'
   AND p.codigo IN ('usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar', 'usuarios.eliminar',
                    'usuarios.restablecer_password', 'usuarios.cerrar_sesiones', 'roles.ver', 'configuracion.ver',
                    'conceptos.editar', 'sistema.salud', 'procesos.cancelar', 'procesos.ver_detalle_tecnico');

UPDATE roles SET descripcion = 'Operación completa y consulta de auditoría. No administra usuarios, roles ni configuración.'
 WHERE codigo = 'ADMIN';
