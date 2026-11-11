<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Env;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * Base para pruebas contra PostgreSQL. Cada prueba corre en una transacción revertida.
 * web(): rol conciliarh_web (el de la aplicación). owner()/worker(): otros roles, para
 * verificar privilegios. Las contraseñas de prueba usan un hash precalculado (Argon2id real)
 * para no pagar el coste del hash en cada prueba.
 */
abstract class DatabaseTestCase extends TestCase
{
    public const PASSWORD = 'Prueba-Segura-2026';

    private static ?Database $web = null;
    private static ?string $hash = null;
    /** @var array<string,Database> */
    private array $extra = [];
    private static ?Database $ownerCommit = null;
    /** @var list<int> usuarios GOD confirmados por el propietario (se eliminan al terminar) */
    private array $committedGods = [];
    /** @var list<callable(Database):void> limpiezas de datos confirmados por el propietario */
    private array $committedCleanups = [];

    protected function setUp(): void
    {
        if (!CONCILIARH_DB_AVAILABLE) {
            self::markTestSkipped('PostgreSQL de pruebas no disponible');
        }
        self::web()->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$web !== null && self::$web->pdo()->inTransaction()) {
            self::$web->pdo()->rollBack();
        }
        $this->actorTokens = [];
        $this->currentActor = null;
        if (self::$web !== null) {
            self::$web->bindActor(null);
        }
        foreach ($this->extra as $db) {
            if ($db->pdo()->inTransaction()) {
                $db->pdo()->rollBack();
            }
        }
        $this->extra = [];
        foreach ($this->committedGods as $id) {
            $o = self::ownerCommit();
            $o->execute('DELETE FROM usuario_rol WHERE usuario_id = :u', ['u' => $id]);
            $o->execute('DELETE FROM usuarios WHERE id = :u', ['u' => $id]);
        }
        $this->committedGods = [];
        $this->fixtureGodId = null;
        foreach (array_reverse($this->committedCleanups) as $fn) {
            $fn(self::ownerCommit());
        }
        $this->committedCleanups = [];
    }

    /**
     * Conexión del propietario en autocommit para preparar estados que sólo el motor puede fijar
     * (p. ej. RECHAZADO). Cada dato creado debe registrar su limpieza con $cleanup.
     * @param callable(Database):void $cleanup
     */
    protected function committedOwner(callable $cleanup): Database
    {
        $this->committedCleanups[] = $cleanup;
        return self::ownerCommit();
    }

    /** Conexión del propietario en modo autocommit (sólo para preparar datos que la web no puede crear). */
    private static function ownerCommit(): Database
    {
        return self::$ownerCommit ??= new Database(self::dbConfig('DB_MIGRATE_USERNAME', 'DB_MIGRATE_PASSWORD', 'conciliarh_owner'));
    }

    public static function env(): Env
    {
        return Env::load(dirname(__DIR__, 2) . '/.env');
    }

    /** @return array<string,mixed> */
    public static function dbConfig(string $userKey, string $passKey, string $defaultUser): array
    {
        $env = self::env();
        return [
            'host' => $env->string('DB_HOST', '127.0.0.1'),
            'port' => $env->int('DB_PORT', 5432),
            'database' => CONCILIARH_TEST_DB,
            'username' => $env->string($userKey, $defaultUser),
            'password' => $env->string($passKey),
            'application_name' => 'conciliarh_tests',
        ];
    }

    public static function web(): Database
    {
        return self::$web ??= new Database(self::dbConfig('DB_USERNAME', 'DB_PASSWORD', 'conciliarh_web'));
    }

    protected function owner(): Database
    {
        return $this->extra['owner'] ??= $this->startTx(new Database(self::dbConfig('DB_MIGRATE_USERNAME', 'DB_MIGRATE_PASSWORD', 'conciliarh_owner')));
    }

    protected function scheduler(): Database
    {
        return $this->extra['scheduler'] ??= $this->startTx(new Database(self::dbConfig('SCHEDULER_DB_USERNAME', 'SCHEDULER_DB_PASSWORD', 'conciliarh_scheduler')));
    }

    protected function worker(): Database
    {
        return $this->extra['worker'] ??= $this->startTx(new Database(self::dbConfig('WORKER_DB_USERNAME', 'WORKER_DB_PASSWORD', 'conciliarh_worker')));
    }

    private function startTx(Database $db): Database
    {
        $db->pdo()->beginTransaction();
        return $db;
    }

    protected static function passwordHash(): string
    {
        return self::$hash ??= password_hash(self::PASSWORD, PASSWORD_ARGON2ID);
    }

    /** Crea un usuario con roles (por código) y devuelve su id. */
    /**
     * Ejecuta la preparación de la prueba como el GOD de apoyo (como lo haría la pantalla de roles o de configuración):
     * desde 0023 la BD exige un actor con permiso para tocar roles, permisos, asignaciones y configuración.
     * @template T @param callable():T $fn @return T
     */
    protected function asFixtureGod(callable $fn): mixed
    {
        self::web()->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => bin2hex(random_bytes(32)), 'u' => $this->fixtureGod(), 'ua' => 'phpunit']);
        try {
            return $fn();
        } finally {
            $prev = $this->currentActor;
            $this->currentActor = null;
            $prev !== null ? $this->actingAs($prev) : self::web()->bindActor(null);
        }
    }

    protected function createUser(string $username, array $roles, bool $active = true, bool $mustChange = false): int
    {
        if (in_array('GOD', $roles, true)) {
            // El rol GOD sólo lo asigna el propietario del esquema (trigger de la migración 0005):
            // se crea confirmado y se elimina en tearDown.
            $o = self::ownerCommit();
            $id = (int) $o->fetchValue(
                'INSERT INTO usuarios (username, nombre, password_hash, activo, debe_cambiar_password) VALUES (:u, :n, :h, :a, :m) RETURNING id',
                ['u' => $username, 'n' => 'Usuario ' . ucfirst($username), 'h' => self::passwordHash(), 'a' => $active, 'm' => $mustChange]
            );
            foreach ($roles as $code) {
                $o->execute('INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :c', ['u' => $id, 'c' => $code]);
            }
            $this->committedGods[] = $id;
            return $id;
        }
        $db = self::web();
        $id = (int) $db->fetchValue(
            'INSERT INTO usuarios (username, nombre, password_hash, activo, debe_cambiar_password) VALUES (:u, :n, :h, :a, :m) RETURNING id',
            ['u' => $username, 'n' => 'Usuario ' . ucfirst($username), 'h' => self::passwordHash(), 'a' => $active, 'm' => $mustChange]
        );
        // Asignar roles exige en la BD un actor con usuarios.crear/editar (0023) y, para los PROTEGIDOS (ADMIN), un actor
        // GOD (0020): la prueba actúa como un GOD de apoyo sólo para esta asignación, igual que lo haría la pantalla de usuarios.
        $needGod = $roles !== [];
        if ($needGod) {
            self::web()->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $tok = bin2hex(random_bytes(32)), 'u' => $this->fixtureGod(), 'ua' => 'phpunit']);
        }
        foreach ($roles as $code) {
            $db->execute('INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :c', ['u' => $id, 'c' => $code]);
        }
        if ($needGod) {
            $prev = $this->currentActor;
            $this->currentActor = null;
            $prev !== null ? $this->actingAs($prev) : self::web()->bindActor(null);
        }
        return $id;
    }

    private ?int $fixtureGodId = null;

    /** GOD de apoyo (confirmado; se elimina en tearDown) para asignar roles protegidos en las pruebas. */
    private function fixtureGod(): int
    {
        if ($this->fixtureGodId === null) {
            $o = self::ownerCommit();
            $this->fixtureGodId = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, 'GOD de apoyo', :h) RETURNING id",
                ['u' => 'god.apoyo' . bin2hex(random_bytes(3)), 'h' => self::passwordHash()]);
            $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD'", ['u' => $this->fixtureGodId]);
            $this->committedGods[] = $this->fixtureGodId;
        }
        return $this->fixtureGodId;
    }

    /** CurrentUser construido desde la BD (roles y permisos reales). */
    protected function actor(int $id): CurrentUser
    {
        $db = self::web();
        $row = $db->fetchOne('SELECT id, username, nombre, debe_cambiar_password FROM usuarios WHERE id = :id', ['id' => $id]);
        $roles = array_column($db->fetchAll('SELECT r.codigo FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :id', ['id' => $id]), 'codigo');
        $perms = array_column($db->fetchAll(
            'SELECT DISTINCT p.codigo FROM usuario_rol ur JOIN rol_permiso rp ON rp.rol_id = ur.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE ur.usuario_id = :id',
            ['id' => $id]
        ), 'codigo');
        return new CurrentUser((int) $row['id'], $row['username'], $row['nombre'], (bool) $row['debe_cambiar_password'], $roles, $perms);
    }

    /** @var array<int,string> token de sesión de prueba por usuario (la conexión web actúa con ese actor) */
    private array $actorTokens = [];

    /**
     * La conexión web ACTÚA como el usuario indicado: igual que en producción, se vincula una sesión real
     * (fn_sesion_vincular) y la BD deriva el actor del token. null = conexión anónima.
     */
    protected function actingAs(?CurrentUser $actor): void
    {
        if ($actor === null) {
            self::web()->bindActor(null);
            return;
        }
        $token = $this->actorTokens[$actor->id] ??= bin2hex(random_bytes(32));
        self::web()->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $token, 'u' => $actor->id, 'ua' => 'phpunit']);
        $this->currentActor = $actor;
    }

    private ?CurrentUser $currentActor = null;

    protected function actorTokenFor(CurrentUser $actor): string
    {
        $this->actingAs($actor);
        return $this->actorTokens[$actor->id];
    }

    /**
     * Crea una sesión ABIERTA de otro usuario por la vía legítima (fn_sesion_vincular) sin cambiar el actor de
     * la prueba. Devuelve el hash almacenado (id de `sesiones`).
     */
    protected function openSessionFor(int $userId, ?string $token = null): string
    {
        $token ??= bin2hex(random_bytes(32));
        self::web()->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $token, 'u' => $userId, 'ua' => 'phpunit']);
        $current = $this->currentActor;
        $this->currentActor = null;
        $this->actingAs($current);
        return hash('sha256', $token);
    }

    protected function audit(?CurrentUser $actor = null): AuditLogger
    {
        $ctx = new AuditContext();
        if ($actor !== null) {
            $ctx->setActor($actor->id, $actor->username, $actor->rolesLabel());
            $this->actingAs($actor);
        }
        return new AuditLogger(self::web(), $ctx);
    }

    /** @return list<array<string,mixed>> */
    protected function auditRows(string $action): array
    {
        return self::web()->fetchAll('SELECT * FROM auditoria WHERE accion = :a ORDER BY secuencia', ['a' => $action]);
    }

    /** Ejecuta $fn sobre $db dentro de un SAVEPOINT y exige el SQLSTATE indicado. */
    protected function assertSqlState(string $state, Database $db, callable $fn): void
    {
        $db->pdo()->exec('SAVEPOINT sqlstate_check');
        try {
            $fn($db);
        } catch (\PDOException $e) {
            $db->pdo()->exec('ROLLBACK TO SAVEPOINT sqlstate_check');
            self::assertSame($state, $e->getCode(), $e->getMessage());
            return;
        }
        $db->pdo()->exec('RELEASE SAVEPOINT sqlstate_check');
        self::fail("Se esperaba un error SQLSTATE $state");
    }
}
