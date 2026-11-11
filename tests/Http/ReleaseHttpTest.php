<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Maintenance;
use ConciliaRH\Tests\Support\ConciliationScenario;
use ConciliaRH\Tests\Support\HttpTestCase;

/** Preparación de la Release Candidate (FASE 9): modo mantenimiento e identificación del build. Datos ficticios. */
final class ReleaseHttpTest extends HttpTestCase
{
    use ConciliationScenario;

    public function testMaintenanceModeBlocksEverythingButPublicHealth(): void
    {
        $this->createUser('god.mant', ['GOD']);
        $this->loginAs('god.mant');
        self::assertSame(200, $this->get('/')->status());
        Maintenance::enable($this->storageRoot, 'Prueba de actualización');
        try {
            foreach (['/', '/login', '/periodos', '/usuarios', '/api/notificaciones/resumen'] as $p) {
                $r = $this->get($p);
                self::assertSame(503, $r->status(), $p);
                self::assertStringNotContainsString('Prueba de actualización', $r->body(), 'El motivo interno no se publica');
            }
            // Una escritura también se detiene (antes de tocar datos)
            self::assertSame(503, $this->post('/periodos', ['_token' => 'x'])->status());
            $h = $this->get('/api/health');
            self::assertSame(503, $h->status());
            self::assertStringContainsString('MANTENIMIENTO', $h->body());
        } finally {
            Maintenance::disable($this->storageRoot);
        }
        self::assertSame(200, $this->get('/')->status());
        self::assertStringContainsString('"ok"', $this->get('/api/health')->body());
    }

    public function testCorruptMaintenanceFileStillMeansMaintenance(): void
    {
        file_put_contents(Maintenance::path($this->storageRoot), '{no es json');
        try {
            self::assertSame(503, $this->get('/login')->status(), 'Falla cerrada: un archivo ilegible no reabre el sistema');
        } finally {
            Maintenance::disable($this->storageRoot);
        }
    }

    public function testBuildIdentifierIsOnlyForHealthAndNeverRaw(): void
    {
        $this->createUser('god.build', ['GOD']);
        $this->loginAs('god.build');
        $html = $this->get('/sistema/salud')->body();
        self::assertStringContainsString('build de desarrollo', $html, 'Sin BUILD.json se informa desarrollo');
        $this->post('/logout');
        $this->createUser('consulta.build', ['CONSULTA']);
        $this->loginAs('consulta.build');
        self::assertSame(403, $this->get('/sistema/salud')->status(), 'El usuario normal no ve detalles técnicos');
    }

    /**
     * FASE 9 §39: límites por usuario en operaciones caras o sensibles. Se cuentan desde la auditoría: se simula la ráfaga
     * con registros del propio usuario y la siguiente petición responde 429 ANTES de hacer el trabajo.
     */
    public function testRateLimitsOnExpensiveOrSensitiveActions(): void
    {
        $s = $this->conciliationScenario();
        $god = $this->createUser('god.limites', ['GOD']);
        $this->loginAs('god.limites');
        $casos = [
            ['cuentas.revelar', 20, 'POST', '/empleados/' . $s['emp2'] . '/cuentas/' . $s['cuenta2'] . '/revelar', []],
            ['archivos.rechazar_carga', 30, 'POST', '/importaciones', ['lote_id' => (string) $s['lote']]],
            ['conciliaciones.solicitar', 10, 'POST', '/conciliaciones', ['lote_id' => (string) $s['lote'], 'motivo' => 'Prueba de límite']],
            ['archivos.descargar_original', 30, 'POST', '/importaciones/' . $s['archivo_r'] . '/descargar', []],
            ['conciliaciones.exportar', 10, 'POST', '/conciliaciones/' . $s['conc'] . '/exportar', ['formato' => 'csv']],
        ];
        foreach ($casos as [$accion, $max, $metodo, $ruta, $campos]) {
            $this->burst($god, $accion, $max);
            $r = $this->post($ruta, $campos);
            self::assertSame(429, $r->status(), "$ruta tras $max registros de $accion");
        }
        // El límite es por usuario: otro usuario no se ve afectado
        $this->post('/logout');
        $this->createUser('god.otro', ['GOD']);
        $this->loginAs('god.otro');
        self::assertNotSame(429, $this->post('/conciliaciones/' . $s['conc'] . '/exportar', ['formato' => 'csv'])->status());
    }

    private function burst(int $userId, string $accion, int $n): void
    {
        $this->actingAs($this->actor($userId));
        for ($i = 0; $i < $n; $i++) {
            self::web()->execute("INSERT INTO auditoria (usuario_id, origen, accion, hash) VALUES (:u, 'WEB', :a, repeat('0', 64))",
                ['u' => $userId, 'a' => $accion]);
        }
    }

    /**
     * FASE 9 §62: el actor no se falsifica aunque una conexión se reutilice (pooler en modo sesión o un proceso que atiende
     * varias peticiones). La BD sólo atribuye con el token CRUDO de una sesión vigente; nunca con el hash guardado, un
     * token inventado, una sesión revocada ni un usuario desactivado; una sesión nueva reemplaza a la anterior.
     */
    public function testActorCannotBeForgedOnReusedConnections(): void
    {
        $a = $this->createUser('actor.a', ['ADMIN']);
        $b = $this->createUser('actor.b', ['CONSULTA']);
        $db = self::web();                                           // la MISMA conexión para todo el escenario
        $tokA = bin2hex(random_bytes(32));
        $tokB = bin2hex(random_bytes(32));
        foreach ([[$tokA, $a], [$tokB, $b]] as [$t, $u]) {
            $db->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $t, 'u' => $u, 'ua' => 'phpunit']);
        }
        $db->bindActor($tokA);
        self::assertSame($a, (int) $db->fetchValue('SELECT fn_actor_actual()'));
        $db->bindActor($tokB);
        self::assertSame($b, (int) $db->fetchValue('SELECT fn_actor_actual()'), 'La sesión siguiente reemplaza al actor anterior');
        $db->bindActor(null);
        self::assertNull($db->fetchValue('SELECT fn_actor_actual()'), 'Petición anónima en conexión reutilizada: sin actor');
        // Falsificaciones: token inventado, el hash que guarda la tabla, un token con formato inválido
        $hashA = (string) $db->fetchValue('SELECT fn_token_hash(:t)', ['t' => $tokA]);
        foreach ([bin2hex(random_bytes(32)), $hashA, "' OR 1=1 --", str_repeat('0', 64)] as $falso) {
            $db->bindActor($falso);
            self::assertNull($db->fetchValue('SELECT fn_actor_actual()'), 'Token falso no atribuye');
        }
        // El actor no se fija con SET directo a otro nombre de usuario ni por parámetro: sólo por el token
        $db->execute("SELECT set_config('conciliarh.actor_id', :u, false)", ['u' => (string) $a]);
        self::assertNull($db->fetchValue('SELECT fn_actor_actual()'));
        // Sesión revocada y usuario desactivado dejan de atribuir en la misma conexión
        $db->bindActor($tokA);
        $db->execute('UPDATE sesiones SET revocada_at = now() WHERE id = fn_token_hash(:t)', ['t' => $tokA]);
        self::assertNull($db->fetchValue('SELECT fn_actor_actual()'), 'Sesión revocada');
        $db->bindActor($tokB);
        self::assertSame($b, (int) $db->fetchValue('SELECT fn_actor_actual()'));
        $db->execute('UPDATE usuarios SET activo = false WHERE id = :u', ['u' => $b]);
        self::assertNull($db->fetchValue('SELECT fn_actor_actual()'), 'Usuario desactivado');
        // Sin conexiones persistentes: cada petición HTTP abre la suya
        self::assertFalse((bool) $db->pdo()->getAttribute(\PDO::ATTR_PERSISTENT), 'La web no usa conexiones persistentes');
    }

    /** bin/maintenance.php se usa como «on --motivo=…» (el motivo DESPUÉS del subcomando) y su estado sale en el código. */
    public function testMaintenanceCliParsesReasonAfterSubcommand(): void
    {
        $dir = sys_get_temp_dir() . '/crh-mant-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $run = static function (array $args) use ($dir): array {
            $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/maintenance.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                null, ['STORAGE_PATH' => $dir, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + getenv());
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return [proc_close($p), $out];
        };
        try {
            self::assertSame(1, $run(['on'])[0], 'Sin motivo no se activa');
            self::assertSame(0, $run(['on', '--motivo=Actualización de prueba'])[0]);
            [$code, $out] = $run(['status']);
            self::assertSame(3, $code);
            self::assertStringContainsString('Actualización de prueba', $out);
            self::assertSame(0, $run(['off'])[0]);
            self::assertSame(0, $run(['status'])[0]);
        } finally {
            @unlink($dir . '/' . Maintenance::FILE);
            @rmdir($dir);
        }
    }
}
