-- Reversión de 0002 (sólo desarrollo/pruebas). Requiere modo mantenimiento
-- porque DROP no dispara triggers de fila, pero se deja explícito.
DROP FUNCTION IF EXISTS fn_auditoria_verificar();
DROP TABLE IF EXISTS auditoria;
DROP FUNCTION IF EXISTS fn_auditoria_inmutable();
DROP FUNCTION IF EXISTS fn_auditoria_encadenar();
DROP FUNCTION IF EXISTS fn_auditoria_contenido(auditoria);
