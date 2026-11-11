-- =====================================================================
-- 0001 · Seguridad: usuarios, roles, permisos, sesiones, intentos de login,
--        configuración del sistema.
-- Requiere que existan los roles de BD conciliarh_web y conciliarh_worker
-- (creados por el DBA; en desarrollo por infra/docker/postgres/init).
-- =====================================================================

-- Función genérica para mantener updated_at
CREATE FUNCTION fn_set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

-- Normalización simple para búsquedas insensibles a acentos y mayúsculas,
-- sin requerir la extensión unaccent (que exige privilegios de superusuario).
CREATE FUNCTION unaccent_simple(text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT lower(translate($1, 'ÁÉÍÓÚÜÑáéíóúüñÀÈÌÒÙàèìòù', 'AEIOUUNaeiouunAEIOUaeiou'));
$$;

-- ---------------------------------------------------------------------
-- Usuarios (login por nombre de usuario; NO existe correo)
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username               text        NOT NULL,
    nombre                 text        NOT NULL,
    password_hash          text        NOT NULL,
    activo                 boolean     NOT NULL DEFAULT true,
    debe_cambiar_password  boolean     NOT NULL DEFAULT false,
    password_cambiado_at   timestamptz,
    ultimo_acceso_at       timestamptz,
    creado_por             bigint      REFERENCES usuarios (id),
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    deleted_at             timestamptz,
    deleted_by             bigint      REFERENCES usuarios (id),
    -- username normalizado en minúsculas por la aplicación; la BD lo exige
    CONSTRAINT usuarios_username_formato CHECK (username ~ '^[a-z0-9][a-z0-9._-]{2,49}$'),
    CONSTRAINT usuarios_nombre_longitud  CHECK (char_length(btrim(nombre)) BETWEEN 3 AND 150),
    CONSTRAINT usuarios_hash_no_vacio    CHECK (char_length(password_hash) >= 20),
    CONSTRAINT usuarios_borrado_coherente CHECK ((deleted_at IS NULL) = (deleted_by IS NULL)),
    CONSTRAINT usuarios_borrado_inactivo  CHECK (deleted_at IS NULL OR activo = false)
);
-- Único incluso para usuarios borrados lógicamente: un username nunca se reutiliza
-- (evita ambigüedad en la auditoría histórica).
CREATE UNIQUE INDEX usuarios_username_uk ON usuarios (username);
CREATE TRIGGER usuarios_updated_at BEFORE UPDATE ON usuarios
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- ---------------------------------------------------------------------
-- Roles y permisos (RBAC)
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo       text        NOT NULL,
    nombre       text        NOT NULL,
    descripcion  text,
    es_sistema   boolean     NOT NULL DEFAULT false,  -- creado por migración
    protegido    boolean     NOT NULL DEFAULT false,  -- sólo GOD puede asignarlo
    activo       boolean     NOT NULL DEFAULT true,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT roles_codigo_formato CHECK (codigo ~ '^[A-Z][A-Z0-9_]{1,39}$'),
    CONSTRAINT roles_nombre_longitud CHECK (char_length(btrim(nombre)) BETWEEN 2 AND 80)
);
CREATE UNIQUE INDEX roles_codigo_uk ON roles (codigo);
CREATE TRIGGER roles_updated_at BEFORE UPDATE ON roles
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

CREATE TABLE permisos (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo       text        NOT NULL,
    modulo       text        NOT NULL,
    descripcion  text        NOT NULL,
    solo_god     boolean     NOT NULL DEFAULT false,  -- nunca asignable a otros roles
    sensible     boolean     NOT NULL DEFAULT false,  -- su uso se audita siempre
    created_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT permisos_codigo_formato CHECK (codigo ~ '^[a-z][a-z_]*\.[a-z][a-z_]*$')
);
CREATE UNIQUE INDEX permisos_codigo_uk ON permisos (codigo);

CREATE TABLE rol_permiso (
    rol_id        bigint      NOT NULL REFERENCES roles (id),
    permiso_id    bigint      NOT NULL REFERENCES permisos (id),
    asignado_por  bigint      REFERENCES usuarios (id),
    asignado_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (rol_id, permiso_id)
);
CREATE INDEX rol_permiso_permiso_idx ON rol_permiso (permiso_id);

-- Un permiso solo_god no puede asignarse a ningún rol distinto de GOD,
-- sin importar lo que haga la aplicación.
CREATE FUNCTION fn_rol_permiso_solo_god() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_solo_god boolean;
    v_rol text;
BEGIN
    SELECT solo_god INTO v_solo_god FROM permisos WHERE id = NEW.permiso_id;
    SELECT codigo INTO v_rol FROM roles WHERE id = NEW.rol_id;
    IF v_solo_god AND v_rol <> 'GOD' THEN
        RAISE EXCEPTION 'El permiso % es exclusivo de GOD', NEW.permiso_id
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER rol_permiso_solo_god BEFORE INSERT OR UPDATE ON rol_permiso
    FOR EACH ROW EXECUTE FUNCTION fn_rol_permiso_solo_god();

CREATE TABLE usuario_rol (
    usuario_id    bigint      NOT NULL REFERENCES usuarios (id),
    rol_id        bigint      NOT NULL REFERENCES roles (id),
    asignado_por  bigint      REFERENCES usuarios (id),
    asignado_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (usuario_id, rol_id)
);
CREATE INDEX usuario_rol_rol_idx ON usuario_rol (rol_id);

-- ---------------------------------------------------------------------
-- Sesiones en BD (permiten revocación inmediata)
-- id = SHA-256 hex del identificador de sesión: el ID real nunca se guarda.
-- ---------------------------------------------------------------------
CREATE TABLE sesiones (
    id                    text        PRIMARY KEY,
    usuario_id            bigint      REFERENCES usuarios (id),
    datos                 text        NOT NULL DEFAULT '',
    ip                    inet,
    user_agent            text,
    creada_at             timestamptz NOT NULL DEFAULT now(),
    ultima_actividad_at   timestamptz NOT NULL DEFAULT now(),
    revocada_at           timestamptz,
    revocada_por          bigint      REFERENCES usuarios (id),
    motivo_revocacion     text,
    CONSTRAINT sesiones_id_formato CHECK (id ~ '^[0-9a-f]{64}$'),
    CONSTRAINT sesiones_user_agent_largo CHECK (user_agent IS NULL OR char_length(user_agent) <= 300)
);
CREATE INDEX sesiones_usuario_activas_idx ON sesiones (usuario_id) WHERE revocada_at IS NULL;
CREATE INDEX sesiones_ultima_actividad_idx ON sesiones (ultima_actividad_at);

-- ---------------------------------------------------------------------
-- Intentos de login (limitación de fuerza bruta).
-- Se guarda HMAC del username, no el texto: evita almacenar contraseñas
-- tecleadas por error en el campo usuario.
-- ---------------------------------------------------------------------
CREATE TABLE intentos_login (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username_hmac  text        NOT NULL,
    ip             inet,
    exito          boolean     NOT NULL,
    creado_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT intentos_login_hmac_formato CHECK (username_hmac ~ '^[0-9a-f]{64}$')
);
CREATE INDEX intentos_login_usuario_idx ON intentos_login (username_hmac, creado_at DESC);
CREATE INDEX intentos_login_ip_idx ON intentos_login (ip, creado_at DESC);

-- ---------------------------------------------------------------------
-- Configuración funcional (la de infraestructura vive en .env)
-- ---------------------------------------------------------------------
CREATE TABLE configuracion (
    clave            text        PRIMARY KEY,
    valor            text        NOT NULL,
    tipo             text        NOT NULL,
    descripcion      text        NOT NULL,
    minimo           numeric,
    maximo           numeric,
    solo_god         boolean     NOT NULL DEFAULT true,
    actualizado_por  bigint      REFERENCES usuarios (id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT configuracion_clave_formato CHECK (clave ~ '^[a-z][a-z_]*(\.[a-z][a-z_]*)+$'),
    CONSTRAINT configuracion_tipo CHECK (tipo IN ('decimal', 'entero', 'booleano', 'texto'))
);
CREATE TRIGGER configuracion_updated_at BEFORE UPDATE ON configuracion
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- ---------------------------------------------------------------------
-- Privilegios mínimos
-- ---------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE           ON usuarios       TO conciliarh_web;
GRANT SELECT, INSERT, UPDATE           ON roles          TO conciliarh_web;
GRANT SELECT                           ON permisos       TO conciliarh_web;
GRANT SELECT, INSERT, DELETE           ON rol_permiso    TO conciliarh_web;
GRANT SELECT, INSERT, DELETE           ON usuario_rol    TO conciliarh_web;
GRANT SELECT, INSERT, UPDATE, DELETE   ON sesiones       TO conciliarh_web;
GRANT SELECT, INSERT, DELETE           ON intentos_login TO conciliarh_web;
GRANT SELECT, UPDATE                   ON configuracion  TO conciliarh_web;

-- El worker no accede a usuarios, sesiones ni credenciales.
GRANT SELECT ON configuracion TO conciliarh_worker;
