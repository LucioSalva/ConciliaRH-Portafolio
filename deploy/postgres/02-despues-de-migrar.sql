-- ConciliaRH — permisos que dependen de objetos creados por las migraciones. El DBA lo ejecuta DESPUÉS de
-- `php bin/migrate.php up` (y es inofensivo repetirlo):
--
--   psql -v ON_ERROR_STOP=1 -U postgres -d conciliarh -f deploy/postgres/02-despues-de-migrar.sql
\set ON_ERROR_STOP on
-- El respaldo registra su resultado para /sistema/salud (sólo esa función; sigue sin poder escribir tablas)
GRANT EXECUTE ON FUNCTION fn_sistema_ejecucion(text, text, timestamptz, text, jsonb) TO conciliarh_backup;
