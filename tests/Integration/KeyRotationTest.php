<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Config;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Domain\Identity\KeyRotator;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;

/** Rotación de la llave de cifrado de cuentas (FASE 6): dry-run, real, interrupción/reanudación, llave incorrecta. */
final class KeyRotationTest extends DatabaseTestCase
{
    use IdentityScenario;

    /** @return array<string,string> */
    private function ring(bool $wrongOld = false): array
    {
        $env = self::env();
        $ring = Config::parseKeyRing($env->string('ACCOUNT_ENC_KEYS'));
        $old = $env->string('ACCOUNT_ENC_KEY_ID', 'k1');
        if ($wrongOld) {
            $ring[$old] = str_repeat("\x07", 32);
        }
        return $ring + ['k2test' => hash('sha256', 'llave-ficticia-de-prueba', true)];
    }

    /** @return list<array{t:string,id:int,blob:string,key:string,l4:?string}> */
    private function rows(): array
    {
        $out = [];
        foreach (KeyRotator::TABLES as $t => [$c, $k]) {
            foreach ($this->owner()->fetchAll("SELECT id, $c AS blob, $k AS key, cuenta_ultimos4 AS l4 FROM $t WHERE $c IS NOT NULL ORDER BY id") as $r) {
                $out[] = ['t' => $t, 'id' => (int) $r['id'], 'blob' => is_resource($r['blob']) ? (string) stream_get_contents($r['blob']) : (string) $r['blob'],
                          'key' => (string) $r['key'], 'l4' => $r['l4']];
            }
        }
        return $out;
    }

    public function testDryRunRealRotationInterruptionAndResume(): void
    {
        $this->identityScenario();
        $ring = $this->ring();
        $before = $this->rows();
        self::assertNotEmpty($before);
        $plain = [];
        foreach ($before as $r) {
            $plain[$r['t'] . $r['id']] = AccountVault::decrypt($r['blob'], $r['key'], $ring[$r['key']]);
        }
        $rot = new KeyRotator($this->owner(), $ring);
        // 17. Dry-run: cuenta todo lo pendiente, sin errores, SIN escribir
        $dry = $rot->dryRun('k2test');
        self::assertSame([], $dry['errores']);
        self::assertSame(count($before), array_sum($dry['pendientes']));
        self::assertSame($before, $this->rows(), 'El dry-run no escribe');
        // 19. Interrupción: un lote de 1 fila y se detiene; lo rotado queda consistente
        $part = $rot->rotate('k2test', 1, 1);
        self::assertFalse($part['completa']);
        self::assertSame(1, array_sum($part['rotadas']));
        // 18. Reanudación hasta completar
        $all = $rot->rotate('k2test', 2);
        self::assertTrue($all['completa']);
        self::assertSame(count($before) - 1, array_sum($all['rotadas']), 'Lo ya rotado no se vuelve a tocar');
        foreach ($this->rows() as $r) {
            self::assertSame('k2test', $r['key']);
            self::assertSame($plain[$r['t'] . $r['id']], AccountVault::decrypt($r['blob'], 'k2test', $ring['k2test']), 'Mismo valor con la llave nueva');
            self::assertNotSame(substr($before[0]['blob'], 1, 12), substr($r['blob'], 1, 12), 'Nonce nuevo');
        }
        // El HMAC no cambia (la búsqueda exacta sigue funcionando)
        self::assertSame(1, (int) $this->owner()->fetchValue('SELECT count(*) FROM cuentas_bancarias WHERE cuenta_hmac = :h', ['h' => self::protect(self::CUENTA_SANT)[0]]));
        // La llave anterior ya no cifra nada: retirable
        self::assertContains(self::env()->string('ACCOUNT_ENC_KEY_ID', 'k1'), $rot->retirables());
        self::assertSame(0, array_sum($rot->dryRun('k2test')['pendientes']), 'Idempotente');
    }

    public function testWrongKeyStopsWithoutWriting(): void
    {
        $this->identityScenario();
        $before = $this->rows();
        $rot = new KeyRotator($this->owner(), $this->ring(wrongOld: true));
        $dry = $rot->dryRun('k2test');
        self::assertCount(count($before), $dry['errores']);
        self::assertSame('NO_DESCIFRABLE', $dry['errores'][0]['motivo']);
        try {
            $rot->rotate('k2test', 10);
            self::fail('Debió detenerse');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(self::CUENTA_SANT, $e->getMessage());
        }
        self::assertSame($before, $this->rows(), 'Nada se escribió');
        $this->expectException(\RuntimeException::class);
        (new KeyRotator($this->owner(), $this->ring()))->rotate('no-existe');
    }

    public function testKeyRotationIsNotReachableFromTheWeb(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/rotate-account-key.php');
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/config/routes.php');
        self::assertStringNotContainsStringIgnoringCase('rotat', $routes);
        self::assertStringNotContainsString('KeyRotator', $routes);
        // El rol web no puede reescribir el cifrado de las cuentas
        $this->assertSqlState('42501', self::web(), fn () => self::web()->execute("UPDATE cuentas_bancarias SET key_id = 'x' WHERE false"));
    }

    public function testRowsLockedByAnotherTransactionMeanRotationIsNotComplete(): void
    {
        $this->identityScenario();
        $ring = $this->ring();
        $other = new \ConciliaRH\Core\Database(self::dbConfig('DB_MIGRATE_USERNAME', 'DB_MIGRATE_PASSWORD', 'conciliarh_owner'));
        $other->pdo()->beginTransaction();
        $other->fetchValue('SELECT id FROM cuentas_bancarias ORDER BY id LIMIT 1 FOR UPDATE');
        try {
            $res = (new KeyRotator($this->owner(), $ring))->rotate('k2test', 50);
            self::assertFalse($res['completa'], 'Con una fila bloqueada (SKIP LOCKED) la rotación NO se declara completa');
            self::assertSame(1, $res['restantes']);
        } finally {
            $other->pdo()->rollBack();
        }
    }
}
