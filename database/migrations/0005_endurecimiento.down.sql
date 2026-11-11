-- Reversión de 0005 (sólo desarrollo/pruebas)
DELETE FROM configuracion WHERE clave IN ('seguridad.login_max_intentos_usuario_global', 'seguridad.cambio_password_max_intentos');
DROP INDEX IF EXISTS intentos_login_ip_ambito_idx;
ALTER TABLE intentos_login DROP COLUMN IF EXISTS ambito;
DROP TRIGGER IF EXISTS usuario_rol_god_protegido ON usuario_rol;
DROP FUNCTION IF EXISTS fn_usuario_rol_god_protegido();
-- Nota: la función de verificación v2 se conserva mientras existan registros hash_version = 2.
GRANT UPDATE ON usuarios TO conciliarh_web;
GRANT INSERT, UPDATE ON roles TO conciliarh_web;
GRANT UPDATE ON configuracion TO conciliarh_web;
GRANT UPDATE ON procesos TO conciliarh_worker;
