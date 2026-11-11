-- 0026 (down) — Vuelve a crear ANALISTA y APROBADOR con sus permisos (sólo desarrollo/pruebas).
-- Las personas que pasaron a ADMIN no regresan a su rol anterior: la auditoría registra cuál tenían.
INSERT INTO roles (codigo, nombre, descripcion, es_sistema, protegido) VALUES
    ('ANALISTA', 'Analista', 'Operación de importaciones y conciliaciones (se amplía en fases posteriores).', true, false),
    ('APROBADOR', 'Aprobador de identidades',
     'Segunda firma de fusiones con conflictos graves. Se asigna además del rol habitual; sólo GOD lo asigna.', true, true)
ON CONFLICT DO NOTHING;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'analisis.reprocesar', 'analisis.seleccionar_hoja', 'analisis.seleccionar_perfil', 'analisis.ver', 'analisis.ver_diagnostico',
    'archivos.cargar', 'archivos.clasificar', 'archivos.ver', 'asociaciones.masivo', 'asociaciones.resolver', 'asociaciones.ver',
    'beneficiarios.confirmar', 'beneficiarios.editar', 'beneficiarios.ver', 'catalogos.ver', 'conciliaciones.ejecutar',
    'conciliaciones.exportar', 'conciliaciones.relacionar', 'conciliaciones.reprocesar', 'conciliaciones.ver',
    'conciliaciones.ver_sensible', 'cuentas.confirmar', 'dashboard.ver', 'empleados.duplicados', 'empleados.ver',
    'empleados.ver_sensible', 'importacion.normalizar', 'incidencias.resolver', 'incidencias.ver', 'lotes.crear', 'lotes.editar',
    'lotes.ver', 'periodos.ver', 'procesos.ver', 'reportes.exportar', 'reportes.programar', 'reportes.ver')
 WHERE r.codigo = 'ANALISTA'
ON CONFLICT DO NOTHING;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('dashboard.ver', 'empleados.duplicados', 'empleados.fusionar_conflictos_aprobar', 'empleados.ver')
 WHERE r.codigo = 'APROBADOR'
ON CONFLICT DO NOTHING;

DELETE FROM rol_permiso rp USING roles r, permisos p
 WHERE rp.rol_id = r.id AND rp.permiso_id = p.id AND r.codigo = 'ADMIN'
   AND p.codigo IN ('empleados.fusionar_conflictos_aprobar', 'empleados.fusionar_conflictos', 'empleados.separar',
                    'lotes.declarar_oficial', 'lotes.reabrir', 'periodos.reabrir', 'periodos.anular');

UPDATE permisos SET solo_god = true
 WHERE codigo IN ('lotes.declarar_oficial', 'lotes.reabrir', 'periodos.reabrir', 'periodos.anular', 'empleados.separar');

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('auditoria.ver', 'auditoria.verificar') WHERE r.codigo = 'ADMIN'
ON CONFLICT DO NOTHING;

UPDATE roles SET descripcion = 'Operación completa y consulta de auditoría. No administra usuarios, roles ni configuración.'
 WHERE codigo = 'ADMIN';
