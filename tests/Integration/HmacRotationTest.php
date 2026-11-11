<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Config;
use ConciliaRH\Domain\Identity\HmacRotator;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;
use PDOException;
use RuntimeException;

/** Rotación de la llave HMAC de cuentas (FASE 7): dry-run, preparación reanudable, corte atómico, colisiones y matching. */
final class HmacRotationTest extends DatabaseTestCase
{
    use IdentityScenario;

    private const H2 = 'h2test';

    /** @return array<string,string> */
    private function hmacRing(): array
    {
        return ['h1' => self::env()->string('ACCOUNT_HMAC_KEY'), self::H2 => hash('sha256', 'hmac-ficticia-de-prueba-h2')];
    }

    private function rotator(): HmacRotator
    {
        return new HmacRotator($this->owner(), $this->hmacRing(), Config::parseKeyRing(self::env()->string('ACCOUNT_ENC_KEYS')));
    }

    private static function mac(string $digits, string $secret): string
    {
        return hash_hmac('sha256', 'cuenta:' . $digits, $secret);
    }

    /** Todas las huellas con su key_id y su sombra. @return array<string,array{h:?string,k:?string,n:?string}> */
    private function snapshot(): array
    {
        $out = [];
        foreach (HmacRotator::TABLES as $t => [$h, $k, $n]) {
            foreach ($this->owner()->fetchAll("SELECT id, $h AS h, $k AS k, $n AS n FROM $t WHERE $h IS NOT NULL ORDER BY id") as $r) {
                $out["$t#{$r['id']}"] = ['h' => $r['h'], 'k' => $r['k'], 'n' => $r['n']];
            }
        }
        return $out;
    }

    /**
     * Qué filas «coinciden» entre sí por cuenta (lo que usa el matching): partición de las filas por huella viva.
     * @return list<list<string>>
     */
    private function matchingPartition(): array
    {
        $groups = [];
        foreach ($this->snapshot() as $row => $v) {
            $groups[(string) $v['h']][] = $row;
        }
        $out = array_values($groups);
        sort($out);
        return $out;
    }

    /** Un registro de Tesorería extra con cuenta cifrada (como lo dejaría el motor) para tener grupos de misma cuenta. */
    private function extraPayment(string $digits): int
    {
        $s = $this->identityScenario();
        [$h, $c, $k] = self::protect($digits);
        $id = (int) $this->owner()->fetchValue(
            "INSERT INTO registros_tesoreria (importacion_id, archivo_id, analisis_id, periodo_id, lote_id, perfil_id, categoria, banco, hoja_id,
                 hoja_indice, hoja_nombre, fila_origen, hash_registro, nombre_original, nombre_normalizado, tipo_cuenta, cuenta_hmac,
                 cuenta_ultimos4, cuenta_cifrada, cuenta_key_id, cuenta_hmac_key_id, importe, importe_estado, asociacion_estado,
                 asociacion_confianza, asociacion_evidencia)
             SELECT importacion_id, archivo_id, analisis_id, periodo_id, lote_id, perfil_id, 'NOMINA', 'SANTANDER', hoja_id, 0, hoja_nombre,
                    900 + (SELECT count(*) FROM registros_tesoreria), md5(random()::text) || md5(random()::text), 'PERSONA EXTRA', 'PERSONA EXTRA',
                    'CUENTA', :h, right(:d, 4), CAST(:c AS bytea), :k, fn_hmac_key_activa(), 10.00, 'OK', 'NO_IDENTIFICADO', 'NINGUNA', '{}'::jsonb
               FROM registros_tesoreria WHERE id = :r RETURNING id",
            ['h' => $h, 'd' => $digits, 'c' => $c, 'k' => $k, 'r' => $s['auto']]);
        return $id;
    }

    public function testCase1DryRunReportsWithoutWriting(): void
    {
        $this->identityScenario();
        $this->extraPayment(self::CUENTA_SANT);
        $before = $this->snapshot();
        self::assertNotEmpty($before);
        $dry = $this->rotator()->dryRun(self::H2);
        self::assertSame([], $dry['errores']);
        self::assertSame(0, $dry['colisiones']);
        self::assertSame(count($before), $dry['total']);
        self::assertSame(count($before), array_sum($dry['necesitan']));
        self::assertSame($before, $this->snapshot(), 'El dry-run no escribe');
        self::assertSame('h1', $this->rotator()->active());
    }

    public function testCase2To5RealRotationResumesAndMatchingIsIdenticalBeforeDuringAndAfter(): void
    {
        $s = $this->identityScenario();
        $this->extraPayment(self::CUENTA_SANT);          // misma cuenta que el registro 'auto' y la cuenta registrada
        $this->extraPayment('60099990000');
        $before = $this->matchingPartition();
        $rot = $this->rotator();
        $rot->start(self::H2, 'prueba');
        // 3. Interrupción tras un lote: la llave activa NO cambió y el matching es idéntico (la columna viva no se tocó)
        $part = $rot->prepare(1, 1);
        self::assertFalse($part['completa']);
        self::assertSame('h1', $rot->active());
        self::assertSame($before, $this->matchingPartition(), '5. Matching durante la preparación');
        // 4. Llave vieja + nueva conviven: una carga nueva DURANTE la preparación se escribe con la activa (h1) y funciona
        $during = $this->extraPayment(self::CUENTA_SANT);
        self::assertSame(self::mac(self::CUENTA_SANT, $this->hmacRing()['h1']),
            $this->owner()->fetchValue('SELECT cuenta_hmac FROM registros_tesoreria WHERE id = :id', ['id' => $during]));
        $during_partition = $this->matchingPartition();
        // Reanudación (idempotente): completa la sombra; después el corte
        $all = $rot->prepare(2);
        self::assertTrue($all['completa'] || $all['restantes'] === 0);
        self::assertSame('h1', $rot->active(), 'Preparar no cambia la llave activa');
        $cut = $rot->cutover();
        self::assertSame(self::H2, $cut['destino']);
        self::assertSame(self::H2, $rot->active());
        // Todas las filas con la llave nueva y la huella correcta; la sombra queda vacía
        foreach ($this->snapshot() as $row => $v) {
            self::assertSame(self::H2, $v['k'], $row);
            self::assertNull($v['n'], $row);
        }
        self::assertSame($during_partition, $this->matchingPartition(), '5. Matching idéntico después del corte');
        // Una búsqueda exacta con la llave nueva encuentra la cuenta registrada (aprendizaje de CLABE intacto)
        self::assertSame($s['cuenta2'], (int) $this->owner()->fetchValue("SELECT id FROM cuentas_bancarias WHERE cuenta_hmac = :h AND estado = 'ACTIVA'",
            ['h' => self::mac(self::CUENTA_SANT, $this->hmacRing()[self::H2])]));
        self::assertSame(0, (int) $this->owner()->fetchValue('SELECT count(*) FROM cuentas_bancarias WHERE cuenta_hmac = :h',
            ['h' => self::mac(self::CUENTA_SANT, $this->hmacRing()['h1'])]), 'Nada queda con la huella anterior');
        self::assertContains('h1', $rot->status()['retirables']);
        self::assertSame('COMPLETADA', $this->owner()->fetchValue('SELECT estado FROM hmac_rotaciones ORDER BY id DESC LIMIT 1'));
        // Un trabajo que calculó con la llave anterior y escribe DESPUÉS del corte falla (se reintenta), nunca mezcla llaves
        try {
            $this->owner()->execute("UPDATE registros_tesoreria SET cuenta_hmac = repeat('a', 64), cuenta_hmac_key_id = 'h1' WHERE id = :id", ['id' => $s['pen']]);
            self::fail('Se aceptó una huella con la llave retirada');
        } catch (PDOException $e) {
            self::assertSame('40001', $e->errorInfo[0]);
        }
    }

    public function testCase6LogicalCollisionOrInconsistentDataIsNeverSilent(): void
    {
        $s = $this->identityScenario();
        $extra = $this->extraPayment('60099990000');
        // Dato inconsistente: la huella no corresponde al valor cifrado de la fila
        $this->owner()->execute('UPDATE registros_tesoreria SET cuenta_cifrada = (SELECT cuenta_cifrada FROM registros_tesoreria WHERE id = :a),
                                        cuenta_key_id = (SELECT cuenta_key_id FROM registros_tesoreria WHERE id = :a2) WHERE id = :id',
            ['a' => $s['auto'], 'a2' => $s['auto'], 'id' => $extra]);
        $dry = $this->rotator()->dryRun(self::H2);
        self::assertContains('HUELLA_NO_CORRESPONDE_AL_VALOR', array_column($dry['errores'], 'motivo'));
        $rot = $this->rotator();
        $rot->start(self::H2, 'prueba');
        try {
            $rot->prepare(50);
            self::fail('La preparación aceptó una huella inconsistente');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HUELLA_NO_CORRESPONDE_AL_VALOR', $e->getMessage());
        }
        self::assertSame('h1', $rot->active());
        // Colisión lógica: dos huellas anteriores distintas con la MISMA sombra → el corte se niega y nada cambia
        $rot->cancel();
        $this->owner()->execute("UPDATE registros_tesoreria SET cuenta_cifrada = NULL, cuenta_key_id = NULL WHERE id = :id", ['id' => $extra]);
        $rot->start(self::H2, 'prueba');
        $rot->prepare(50);
        $this->owner()->execute('UPDATE registros_tesoreria SET cuenta_hmac_nueva = (SELECT cuenta_hmac_nueva FROM registros_tesoreria WHERE id = :a) WHERE id = :id',
            ['a' => $s['auto'], 'id' => $extra]);
        $before = $this->snapshot();
        try {
            $rot->cutover();
            self::fail('El corte aceptó una correspondencia no biyectiva');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no biyectiva', $e->getMessage());
        }
        self::assertSame($before, $this->snapshot(), 'El corte fallido no escribe nada');
        self::assertSame('h1', $rot->active());
    }

    public function testUnmappableFingerprintNeedsAnExplicitDecisionAndConcurrencyRules(): void
    {
        $s = $this->identityScenario();
        // Una huella sin valor cifrado y sin otra fila con la misma huella: no se puede recalcular
        $this->owner()->execute("UPDATE registros_tesoreria SET cuenta_hmac = repeat('b', 64), cuenta_hmac_key_id = 'h1', cuenta_ultimos4 = '0000'
                                  WHERE id = :id", ['id' => $s['pen']]);
        $rot = $this->rotator();
        self::assertSame(1, $rot->dryRun(self::H2)['sin_cifrado']);
        $rot->start(self::H2, 'prueba');
        // Dos rotaciones: una segunda hacia otro destino se rechaza (una sola en curso)
        $other = new HmacRotator($this->owner(), $this->hmacRing() + ['h3test' => str_repeat('c', 64)],
            Config::parseKeyRing(self::env()->string('ACCOUNT_ENC_KEYS')));
        try {
            $other->start('h3test', 'prueba');
            self::fail('Se aceptaron dos rotaciones simultáneas');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Ya hay una rotación en curso', $e->getMessage());
        }
        $res = $rot->prepare(50);
        self::assertFalse($res['completa']);
        self::assertSame(1, $res['restantes']);
        self::assertSame(1, $res['sin_mapa'], 'La CLI detecta que sólo quedan huellas irrecuperables y pasa al corte (que exige decidir)');
        try {
            $rot->cutover();
            self::fail('El corte ocurrió con una huella sin recalcular');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('sin valor cifrado', $e->getMessage());
        }
        self::assertSame('h1', $rot->active(), 'Nunca «completa» con valores viejos');
        $cut = $rot->cutover(true);
        self::assertSame(1, $cut['descartadas']);
        self::assertNull($this->owner()->fetchValue('SELECT cuenta_hmac FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]));
        // Un segundo corte no encuentra rotación en curso
        $this->expectException(RuntimeException::class);
        $rot->cutover();
    }

    public function testWebAndWorkerCannotTouchKeysOrFingerprints(): void
    {
        $s = $this->identityScenario();
        foreach (["UPDATE hmac_llaves_estado SET key_id_activa = 'h9'", "INSERT INTO hmac_rotaciones (key_origen, key_destino, iniciada_por) VALUES ('h1', 'h9', 'x')",
                  "UPDATE registros_tesoreria SET cuenta_hmac = repeat('a', 64) WHERE id = {$s['auto']}",
                  "SELECT cuenta_hmac FROM registros_tesoreria LIMIT 1"] as $sql) {
            self::web()->execute('SAVEPOINT intento');
            try {
                self::web()->execute($sql);
                self::fail("La web ejecutó: $sql");
            } catch (PDOException $e) {
                self::assertSame('42501', $e->errorInfo[0], $sql);
            }
            self::web()->execute('ROLLBACK TO SAVEPOINT intento');
        }
        self::assertSame('h1', self::web()->fetchValue('SELECT fn_hmac_key_activa()'));
    }

    public function testReviewSameKeyIdWithAnotherSecretIsRejectedBeforeWriting(): void
    {
        $this->identityScenario();
        $this->rotator()->status();
        self::assertNotNull($this->rotator()->dryRun(self::H2)['total']);          // registra el valor de verificación de h1
        $bad = new HmacRotator($this->owner(), ['h1' => hash('sha256', 'otro secreto'), self::H2 => $this->hmacRing()[self::H2]],
            Config::parseKeyRing(self::env()->string('ACCOUNT_ENC_KEYS')));
        $before = $this->snapshot();
        try {
            $bad->start(self::H2, 'prueba');
            self::fail('Se aceptó una llave activa con otro secreto');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no coincide con la registrada', $e->getMessage());
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(0, (int) $this->owner()->fetchValue("SELECT count(*) FROM hmac_rotaciones WHERE estado = 'PREPARANDO'"));
    }
}
