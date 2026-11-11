-- =====================================================================
-- 0008 · Permisos de periodos/lotes/archivos/catálogos, límites de
--        seguridad de archivos y marca de usuarios de desarrollo.
-- =====================================================================

INSERT INTO permisos (codigo, modulo, descripcion, solo_god, sensible) VALUES
    ('periodos.ver',              'periodos',  'Consultar periodos de nómina', false, false),
    ('periodos.crear',            'periodos',  'Crear periodos de nómina', false, true),
    ('periodos.editar',           'periodos',  'Editar descripción y fechas de un periodo abierto', false, true),
    ('periodos.cerrar',           'periodos',  'Cerrar un periodo (bloquea nuevas cargas)', false, true),
    ('periodos.reabrir',          'periodos',  'Reabrir un periodo cerrado (acción excepcional)', true, true),
    ('periodos.anular',           'periodos',  'Anular un periodo sin archivos vigentes', true, true),
    ('lotes.ver',                 'lotes',     'Consultar lotes de importación', false, false),
    ('lotes.crear',               'lotes',     'Crear lotes de importación en un periodo abierto', false, true),
    ('lotes.editar',              'lotes',     'Editar nombre, origen y observaciones de un lote abierto', false, false),
    ('lotes.cerrar',              'lotes',     'Cerrar un lote (deja de recibir archivos)', false, true),
    ('lotes.reabrir',             'lotes',     'Reabrir un lote cerrado (acción excepcional)', true, true),
    ('lotes.anular',              'lotes',     'Anular un lote', false, true),
    ('lotes.declarar_oficial',    'lotes',     'Declarar el lote oficial de un periodo', true, true),
    ('archivos.ver',              'archivos',  'Consultar archivos importados (metadatos)', false, false),
    ('archivos.cargar',           'archivos',  'Cargar archivos a un lote abierto', false, true),
    ('archivos.clasificar',       'archivos',  'Cambiar tipo documental u origen de un archivo', false, true),
    ('archivos.descargar_original','archivos', 'Descargar el archivo original (contiene datos personales)', false, true),
    ('archivos.reprocesar',       'archivos',  'Reencolar la verificación/análisis de un archivo con error', false, true),
    ('archivos.anular',           'archivos',  'Anular lógicamente un archivo', false, true),
    ('catalogos.ver',             'catalogos', 'Consultar catálogos documentales', false, false),
    ('catalogos.administrar',     'catalogos', 'Crear y activar/desactivar tipos documentales y orígenes', true, true);

-- GOD: todo
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE r.codigo = 'GOD' AND NOT EXISTS (SELECT 1 FROM rol_permiso x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- ADMIN: operación y administración sin acciones exclusivas de GOD
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'periodos.ver', 'periodos.crear', 'periodos.editar', 'periodos.cerrar',
    'lotes.ver', 'lotes.crear', 'lotes.editar', 'lotes.cerrar', 'lotes.anular',
    'archivos.ver', 'archivos.cargar', 'archivos.clasificar', 'archivos.descargar_original',
    'archivos.reprocesar', 'archivos.anular', 'catalogos.ver')
WHERE r.codigo = 'ADMIN';

-- ANALISTA: trabajo operativo con importaciones; sin acciones destructivas ni descarga de originales
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN (
    'periodos.ver', 'lotes.ver', 'lotes.crear', 'lotes.editar', 'lotes.cerrar',
    'archivos.ver', 'archivos.cargar', 'archivos.clasificar', 'catalogos.ver')
WHERE r.codigo = 'ANALISTA';

-- CONSULTA: sólo lectura
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('periodos.ver', 'lotes.ver', 'archivos.ver')
WHERE r.codigo = 'CONSULTA';

-- Límites de seguridad de contenedores OOXML (PHP los aplica al recibir; Python los
-- vuelve a aplicar descomprimiendo en streaming con tope). Fuente única: esta tabla.
INSERT INTO configuracion (clave, valor, tipo, descripcion, minimo, maximo, solo_god) VALUES
    ('importacion.zip_max_entradas', '5000', 'entero',
     'Máximo de entradas internas de un XLSX/XLSM', 50, 100000, true),
    ('importacion.zip_max_descomprimido_mb', '300', 'entero',
     'Tamaño máximo descomprimido total de un XLSX/XLSM (MB)', 10, 4096, true),
    ('importacion.zip_max_ratio', '150', 'entero',
     'Relación de compresión máxima por entrada (descomprimido/comprimido) para entradas > 1 MB', 10, 1000, true),
    ('importacion.xml_max_mb', '200', 'entero',
     'Tamaño máximo descomprimido de una sola parte XML (MB)', 5, 2048, true);

-- Usuarios de desarrollo: marcados explícitamente; la aplicación les niega el acceso en producción.
ALTER TABLE usuarios ADD COLUMN es_desarrollo boolean NOT NULL DEFAULT false;
