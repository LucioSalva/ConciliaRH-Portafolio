-- ConciliaRH — roles y base de datos (docs/DEPLOYMENT.md §3). Lo ejecuta el DBA como superusuario ANTES de migrar:
--
--   cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres -v db=conciliarh -f /opt/conciliarh/current/deploy/postgres/01-roles-y-base.sql
--
-- Crea los roles SIN contraseña; después el DBA asigna cada una de forma interactiva (no queda en la línea de comandos, en el
-- historial ni en los logs del servidor):
--
--   sudo -u postgres psql -c '\password conciliarh_owner'    (y conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup)
--
-- Idempotente: si un rol existe sólo actualiza sus atributos. Ningún rol es superusuario ni crea bases o roles.
\set ON_ERROR_STOP on

SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', r)
  FROM unnest(ARRAY['conciliarh_owner', 'conciliarh_web', 'conciliarh_worker', 'conciliarh_scheduler', 'conciliarh_backup']) r
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) \gexec

ALTER ROLE conciliarh_owner     LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE conciliarh_web       LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE conciliarh_worker    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE conciliarh_scheduler LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
-- El respaldo lee TODO, incluida la tabla con RLS (notificaciones): pg_dump falla sin BYPASSRLS. Sigue sin poder escribir nada.
ALTER ROLE conciliarh_backup    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS;

-- Límites de conexión por rol (docs/DEPLOYMENT.md §3.4): una fuga de conexiones de un servicio no agota a los demás
ALTER ROLE conciliarh_web       CONNECTION LIMIT 60;
ALTER ROLE conciliarh_worker    CONNECTION LIMIT 10;
ALTER ROLE conciliarh_scheduler CONNECTION LIMIT 5;
ALTER ROLE conciliarh_backup    CONNECTION LIMIT 3;
-- Consultas de la web y del worker acotadas; sesiones inactivas en transacción no retienen candados
ALTER ROLE conciliarh_web    SET statement_timeout = '300s';
ALTER ROLE conciliarh_web    SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE conciliarh_worker SET idle_in_transaction_session_timeout = '10min';

SELECT format('CREATE DATABASE %I OWNER conciliarh_owner ENCODING ''UTF8'' TEMPLATE template0', :'db')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'db') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup', :'db') \gexec
SELECT format('ALTER DATABASE %I SET timezone TO ''America/Mexico_City''', :'db') \gexec

\connect :db
ALTER SCHEMA public OWNER TO conciliarh_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup;
-- El respaldo sólo LEE (PostgreSQL 14+): pg_dump consistente sin poder modificar nada
GRANT pg_read_all_data TO conciliarh_backup;
