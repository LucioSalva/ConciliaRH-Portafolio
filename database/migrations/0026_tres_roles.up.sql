-- =====================================================================
-- 0026 — Sólo tres roles: GOD, ADMIN y CONSULTA.
-- ADMIN ya tenía todos los permisos de ANALISTA; recibe además la aprobación de fusiones con conflictos graves
-- (antes APROBADOR). La regla de dos personas se conserva: quien solicita una fusión no puede aprobarla.
-- Quien tenía ANALISTA o APROBADOR pasa a ADMIN, con un registro en la auditoría por persona.
-- ADMIN también declara el lote oficial, reabre lotes, reabre y anula quincenas, solicita fusiones con conflictos graves y
-- separa fusiones (esos permisos dejan de ser exclusivos de GOD). Una fusión con conflictos sigue necesitando a otra persona
-- que la apruebe.
-- ADMIN deja de consultar la auditoría: usuarios, roles, auditoría, configuración y sistema quedan sólo para GOD.
-- =====================================================================
UPDATE permisos SET solo_god = false
 WHERE codigo IN ('lotes.declarar_oficial', 'lotes.reabrir', 'periodos.reabrir', 'periodos.anular', 'empleados.separar');

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'empleados.fusionar_conflictos_aprobar', 'empleados.fusionar_conflictos', 'empleados.separar',
    'lotes.declarar_oficial', 'lotes.reabrir', 'periodos.reabrir', 'periodos.anular')
 WHERE r.codigo = 'ADMIN'
ON CONFLICT DO NOTHING;

INSERT INTO auditoria (usuario_id, username, origen, accion, entidad, entidad_id, valor_anterior, valor_nuevo, contexto, hash)
SELECT NULL, NULL, 'SISTEMA', 'usuarios.roles_consolidados', 'usuarios', u.id::text,
       jsonb_build_object('roles', (SELECT jsonb_agg(r2.codigo ORDER BY r2.codigo) FROM usuario_rol ur2 JOIN roles r2 ON r2.id = ur2.rol_id
                                     WHERE ur2.usuario_id = u.id)),
       jsonb_build_object('roles', (SELECT jsonb_agg(DISTINCT c ORDER BY c) FROM (
                                        SELECT CASE WHEN r2.codigo IN ('ANALISTA', 'APROBADOR') THEN 'ADMIN' ELSE r2.codigo END AS c
                                          FROM usuario_rol ur2 JOIN roles r2 ON r2.id = ur2.rol_id WHERE ur2.usuario_id = u.id) x)),
       jsonb_build_object('migracion', '0026', 'motivo', 'Sólo quedan los roles GOD, ADMIN y CONSULTA'), repeat('0', 64)
  FROM usuarios u
 WHERE EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id AND r.codigo IN ('ANALISTA', 'APROBADOR'))
 ORDER BY u.id;

INSERT INTO usuario_rol (usuario_id, rol_id)
SELECT DISTINCT ur.usuario_id, (SELECT id FROM roles WHERE codigo = 'ADMIN')
  FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
 WHERE r.codigo IN ('ANALISTA', 'APROBADOR')
   AND NOT EXISTS (SELECT 1 FROM usuario_rol x JOIN roles a ON a.id = x.rol_id WHERE x.usuario_id = ur.usuario_id AND a.codigo = 'ADMIN');

DELETE FROM usuario_rol ur USING roles r WHERE ur.rol_id = r.id AND r.codigo IN ('ANALISTA', 'APROBADOR');
DELETE FROM rol_permiso rp USING roles r WHERE rp.rol_id = r.id AND r.codigo IN ('ANALISTA', 'APROBADOR');
DELETE FROM roles WHERE codigo IN ('ANALISTA', 'APROBADOR');

DELETE FROM rol_permiso rp USING roles r, permisos p
 WHERE rp.rol_id = r.id AND rp.permiso_id = p.id AND r.codigo = 'ADMIN' AND p.codigo IN ('auditoria.ver', 'auditoria.verificar');

UPDATE roles SET descripcion = 'Operación completa de las quincenas, lotes, identidades y fusiones. No administra usuarios, roles, auditoría, configuración ni el sistema.'
 WHERE codigo = 'ADMIN';
