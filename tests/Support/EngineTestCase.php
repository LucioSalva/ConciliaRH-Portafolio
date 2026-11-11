<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Logger;
use ConciliaRH\Engine\Jobs\JobRegistry;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Worker\Worker;
use ConciliaRH\Engine\Worker\WorkerSettings;
use PHPUnit\Framework\TestCase;

abstract class EngineTestCase extends TestCase
{
    private static ?Database $owner = null;
    private static ?string $storage = null;

    protected function setUp(): void
    {
        if (!CONCILIARH_DB_AVAILABLE) {
            self::markTestSkipped('PostgreSQL de pruebas no disponible');
        }
        self::truncateQueue(self::owner());
    }

    protected function tearDown(): void
    {
        if (self::$owner !== null) {
            self::truncateQueue(self::$owner);
        }
    }

    public static function owner(): Database
    {
        return self::$owner ??= new Database(DatabaseTestCase::dbConfig('DB_MIGRATE_USERNAME', 'DB_MIGRATE_PASSWORD', 'conciliarh_owner'));
    }

    public static function workerDb(): Database
    {
        return new Database(DatabaseTestCase::dbConfig('WORKER_DB_USERNAME', 'WORKER_DB_PASSWORD', 'conciliarh_worker'));
    }

    public static function webDb(): Database
    {
        return new Database(DatabaseTestCase::dbConfig('DB_USERNAME', 'DB_PASSWORD', 'conciliarh_web'));
    }

    public static function storage(): string
    {
        if (self::$storage === null) {
            self::$storage = sys_get_temp_dir() . '/crh-engine-' . getmypid() . '/storage';
            foreach (['imports', 'logs', 'trabajo', 'reportes'] as $d) {
                if (!is_dir(self::$storage . '/' . $d)) {
                    mkdir(self::$storage . '/' . $d, 0775, true);
                }
            }
        }
        return self::$storage;
    }

    /** @param array<string,mixed> $overrides */
    public static function settings(array $overrides = []): WorkerSettings
    {
        $env = DatabaseTestCase::env();
        $base = [
            'db' => [...DatabaseTestCase::dbConfig('WORKER_DB_USERNAME', 'WORKER_DB_PASSWORD', 'conciliarh_worker'), 'application_name' => 'conciliarh_worker'],
            'timezone' => 'America/Mexico_City',
            'heartbeatTimeout' => 120,
            'pollInterval' => 1,
            'storagePath' => self::storage(),
            'appEnv' => 'testing',
            'hmacRing' => \ConciliaRH\Core\Config::parseHmacRing($env->string('ACCOUNT_HMAC_KEYS'), $env->string('ACCOUNT_HMAC_KEY')) ?? [],
            'encRing' => \ConciliaRH\Core\Config::parseKeyRing($env->string('ACCOUNT_ENC_KEYS')) ?? [],
            'encKeyId' => $env->string('ACCOUNT_ENC_KEY_ID', 'k1'),
            'version' => 'pruebas',
        ];
        $v = [...$base, ...$overrides];
        return new WorkerSettings($v['db'], $v['timezone'], $v['heartbeatTimeout'], $v['pollInterval'], $v['storagePath'], $v['appEnv'], $v['hmacRing'],
            $v['encRing'], $v['encKeyId'], $v['version']);
    }

    public static function worker(?WorkerSettings $settings = null, ?string $id = null): Worker
    {
        return new Worker($settings ?? self::settings(), JobRegistry::handlers(), new Logger(self::storage() . '/logs'), $id);
    }

    public static function runOnce(?int $jobId = null, ?WorkerSettings $settings = null): int
    {
        return self::worker($settings)->run(true, $jobId);
    }

    public static function runAll(int $max = 200): int
    {
        $n = 0;
        while ($n < $max && self::runOnce() > 0) {
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $params */
    public static function enqueue(string $tipo = 'DIAGNOSTICO', array $params = [], int $prioridad = 100, int $maxIntentos = 3, int $timeout = 1800,
                                   int $delay = 0, int $contract = 1): int
    {
        return (int) self::owner()->fetchValue(
            'INSERT INTO procesos (tipo, parametros, prioridad, max_intentos, timeout_segundos, disponible_desde, contract_version)
             VALUES (:t, CAST(:p AS jsonb), :pr, :mi, :to, now() + make_interval(secs => :d), :c) RETURNING id',
            ['t' => $tipo, 'p' => Json::encode(Json::obj($params)), 'pr' => $prioridad, 'mi' => $maxIntentos, 'to' => $timeout, 'd' => $delay, 'c' => $contract]);
    }

    /** @return array<string,mixed> */
    public static function jobRow(int $id): array
    {
        return self::owner()->fetchOne('SELECT * FROM procesos WHERE id = :id', ['id' => $id]) ?? [];
    }

    public static function truncateQueue(Database $db): void
    {
        $db->execute("SET conciliarh.identidad_mantenimiento = 'on'");
        try {
            $db->execute('TRUNCATE procesos, proceso_eventos, workers RESTART IDENTITY CASCADE');
        } finally {
            $db->execute('RESET conciliarh.identidad_mantenimiento');
        }
    }

    public static function cleanIdentity(): void
    {
        $o = self::owner();
        $o->execute("SET conciliarh.identidad_mantenimiento = 'on'");
        try {
            $o->execute('TRUNCATE acciones_masivas, acciones_masivas_registros, beneficiarios_pension, beneficiario_trabajadores RESTART IDENTITY CASCADE');
            $o->execute('TRUNCATE hmac_rotaciones RESTART IDENTITY');
            $o->execute("UPDATE hmac_llaves_estado SET key_id_activa = 'h1', kcv_activa = NULL WHERE key_id_activa <> 'h1' OR kcv_activa IS NOT NULL");
            $o->execute('TRUNCATE conciliaciones, conciliacion_archivos, conciliacion_controles, conciliacion_empleados, conciliacion_pensiones,
                         conciliacion_fuentes, conciliacion_incidencias, conciliacion_incidencias_historial, relaciones_poliza_dispersion RESTART IDENTITY CASCADE');
            $o->execute('TRUNCATE importaciones_normalizadas, empleados, empleado_historial, registros_rrhh, registros_rrhh_conceptos, registros_tesoreria,
                         cuentas_bancarias, polizas_globales, polizas_globales_lineas, polizas_egreso_lineas, incidencias_identidad, asociaciones_historial,
                         lotes_relaciones RESTART IDENTITY CASCADE');
            $o->execute("DELETE FROM conceptos_nomina WHERE clasificacion = 'INFERIDA'");
        } finally {
            $o->execute('RESET conciliarh.identidad_mantenimiento');
        }
    }

    public static function bindActor(Database $conn, int $userId): void
    {
        $conn->fetchValue("SELECT fn_sesion_vincular(:t, :u, NULL, 'phpunit')", ['t' => bin2hex(random_bytes(32)), 'u' => $userId]);
    }
}
