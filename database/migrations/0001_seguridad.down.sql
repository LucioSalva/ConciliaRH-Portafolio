-- Reversión de 0001 (sólo desarrollo/pruebas; bin/migrate.php la bloquea en producción)
DROP TABLE IF EXISTS configuracion;
DROP TABLE IF EXISTS intentos_login;
DROP TABLE IF EXISTS sesiones;
DROP TABLE IF EXISTS usuario_rol;
DROP TABLE IF EXISTS rol_permiso;
DROP FUNCTION IF EXISTS fn_rol_permiso_solo_god();
DROP TABLE IF EXISTS permisos;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS usuarios;
DROP FUNCTION IF EXISTS fn_set_updated_at();
DROP FUNCTION IF EXISTS unaccent_simple(text);
