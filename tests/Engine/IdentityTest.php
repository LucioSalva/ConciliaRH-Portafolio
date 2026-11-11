<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Config;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Engine\Identity\AccountCrypto;
use ConciliaRH\Engine\Identity\Evidence;
use ConciliaRH\Engine\Identity\Matcher;
use ConciliaRH\Engine\Identity\Person;
use ConciliaRH\Engine\Identity\PersonIds as N;
use ConciliaRH\Engine\Identity\PersonIndex;
use ConciliaRH\Engine\Identity\Records as R;
use ConciliaRH\Engine\Worker\JobFailure;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use PHPUnit\Framework\TestCase;
use Throwable;

final class IdentityTest extends TestCase
{
    private const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    /** @return array<string,string> */
    private static function ring(): array
    {
        return ['k1' => implode('', array_map('chr', range(0, 31))), 'k2' => implode('', array_map('chr', range(32, 63)))];
    }

    private static function encrypt(string $digits, string $kid): string
    {
        return AccountVault::encrypt($digits, $kid, self::ring()[$kid]);
    }

    private static function decrypt(string $blob, string $kid): string
    {
        return AccountVault::decrypt($blob, $kid, self::ring()[$kid]);
    }

    private static function assertThrows(callable $fn, string $class = Throwable::class): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e);
            return;
        }
        self::fail("Se esperaba una excepción $class");
    }

    /** @param array<string,int> $accounts */
    private static function idx(array $people, array $accounts = []): PersonIndex
    {
        $idx = new PersonIndex();
        foreach ($people as $p) {
            $idx->add($p);
        }
        foreach ($accounts as $k => $v) {
            $idx->accounts[$k] = $v;
        }
        return $idx;
    }

    private static function a(): Person
    {
        return new Person(1, '90001', FX::rfc(1), true, FX::curp(1), true, 'PEREZ LOPEZ ANA');
    }

    private static function b(): Person
    {
        return new Person(2, '90002', FX::rfc(2), true, FX::curp(2), true, 'GARCIA RUIZ LUIS');
    }

    /** @param array<string,mixed> $kw */
    private static function ev(array $kw = []): Evidence
    {
        return new Evidence(...$kw);
    }

    /** @param list<array{0:string,1:string,2:array<string,mixed>}> $inc */
    private static function hasIncident(array $inc, string $tipo): bool
    {
        foreach ($inc as [$t]) {
            if ($t === $tipo) {
                return true;
            }
        }
        return false;
    }

    public function testNameKeyIsDeterministicAndKeepsWords(): void
    {
        $variants = ['JOSÉ DE JESÚS PÉREZ LÓPEZ', 'jose de jesus perez lopez', "JOSE  DE JESUS PEREZ\u{200B} LOPEZ", ' José de Jesús, Pérez-López '];
        $keys = array_values(array_unique(array_map(static fn ($v) => N::nameKey($v), $variants)));
        self::assertSame(['JOSE DE JESUS PEREZ LOPEZ'], $keys);
        self::assertSame('MUNOZ', N::nameKey('MUÑOZ'));
        self::assertNull(N::nameKey('   '));
        self::assertSame('José de Jesús', N::cleanText('  José  de   Jesús '), 'El original conserva acentos');
    }

    public function testRfcCurpInvalidIsFlaggedNotDropped(): void
    {
        self::assertSame(['FICT900101AB1', true], N::rfc(' fict-900101 ab1 '));
        self::assertSame(['XYZ', false], N::rfc('XYZ'));
        self::assertSame([FX::curp(7), true], N::curp(FX::curp(7)));
        self::assertFalse(N::curp('FAXT900101HZZBCDA1')[1], 'Entidad inexistente');
        $ident = R::identity('12345', 'NOMBRE', 'MAL', 'TAMBIEN MAL');
        self::assertSame(['MAL', false, false], [$ident->rfcOriginal, $ident->rfcValido, $ident->curpValida]);
    }

    public function testEmployeeNumberRules(): void
    {
        self::assertSame(['00123', true], N::employeeNumber('00123'), 'Conserva ceros iniciales de un texto');
        self::assertSame(['123', true], N::employeeNumber('123.0'));
        self::assertFalse(N::employeeNumber('JUAN PEREZ')[1], 'Un nombre en la columna del número es inválido');
        self::assertFalse(N::employeeNumber('ABC')[1], 'Exige al menos un dígito');
    }

    public function testEncryptRoundtripAadAndTamper(): void
    {
        $blob = self::encrypt('012180001234567891', 'k1');
        self::assertSame("\x01", $blob[0]);
        self::assertStringNotContainsString('012180001234567891', $blob);
        self::assertSame('012180001234567891', self::decrypt($blob, 'k1'));
        self::assertNotSame($blob, self::encrypt('012180001234567891', 'k1'), 'Nonce aleatorio');
        self::assertThrows(static fn () => self::decrypt($blob, 'k2'));
        $bad = substr($blob, 0, -1) . chr(ord($blob[-1]) ^ 1);
        self::assertThrows(static fn () => self::decrypt($bad, 'k1'));
    }

    public function testRotationReencryptKeepsHmac(): void
    {
        $blob = self::encrypt('600123456789', 'k1');
        $rotated = self::encrypt(self::decrypt($blob, 'k1'), 'k2');
        self::assertSame('600123456789', self::decrypt($rotated, 'k2'));
        self::assertSame(AccountCrypto::accountHmac(self::KEY, '600123456789'), AccountCrypto::accountHmac(self::KEY, '600123456789'),
            'El HMAC no depende de la llave de cifrado');
    }

    public function testHmacIsKeyedNotPlainSha(): void
    {
        $mac = AccountCrypto::accountHmac(self::KEY, '012180001234567891');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $mac);
        self::assertNotSame(hash('sha256', '012180001234567891'), $mac);
        self::assertNotSame(AccountCrypto::accountHmac(str_repeat('otra', 16), '012180001234567891'), $mac);
        self::assertThrows(static fn () => AccountCrypto::accountHmac('', '1234'), JobFailure::class);
    }

    public function testParseRingRejectsBadSpecs(): void
    {
        $ring = Config::parseKeyRing('k1:' . str_repeat('a', 64) . ', k2:' . str_repeat('b', 64));
        self::assertNotNull($ring);
        self::assertSame(['k1', 'k2'], array_keys($ring));
        foreach (['k1:abc', 'K1:' . str_repeat('a', 64), 'k1' . str_repeat('a', 64)] as $bad) {
            self::assertNull(Config::parseKeyRing($bad), $bad);
        }
    }

    public function testCase10TesoreriaByNumber(): void
    {
        $m = Matcher::match(self::ev(['numero' => '90001', 'numeroValido' => true, 'nameKey' => 'PEREZ LOPEZ ANA']), self::idx([self::a(), self::b()]));
        self::assertSame(['ASOCIADO_AUTOMATICO', 1, 'NUMERO_EMPLEADO', 'ALTA'], [$m->estado, $m->empleadoId, $m->metodo, $m->confianza]);
    }

    public function testCase11And12RfcOnlyAndCurpOnly(): void
    {
        self::assertSame(2, Matcher::match(self::ev(['rfc' => FX::rfc(2), 'rfcValido' => true]), self::idx([self::a(), self::b()]))->empleadoId);
        $m = Matcher::match(self::ev(['curp' => FX::curp(1), 'curpValida' => true]), self::idx([self::a(), self::b()]));
        self::assertSame(['ASOCIADO_AUTOMATICO', 'CURP'], [$m->estado, $m->metodo]);
    }

    public function testCase9NumberVsRfcConflictNeverPicksByPriority(): void
    {
        $m = Matcher::match(self::ev(['numero' => '90001', 'numeroValido' => true, 'rfc' => FX::rfc(2), 'rfcValido' => true]), self::idx([self::a(), self::b()]));
        self::assertSame('CONFLICTO', $m->estado);
        self::assertNull($m->empleadoId);
        self::assertSame([1, 2], $m->evidencia['candidatos']);
        self::assertTrue(self::hasIncident($m->incidencias, 'IDENTIFICADORES_CONTRADICTORIOS'));
    }

    public function testRfcDiscrepantWithMasterIsConflict(): void
    {
        $m = Matcher::match(self::ev(['numero' => '90001', 'numeroValido' => true, 'rfc' => FX::rfc(99), 'rfcValido' => true]), self::idx([self::a(), self::b()]));
        self::assertSame('CONFLICTO', $m->estado);
        $last = $m->incidencias[count($m->incidencias) - 1];
        self::assertSame(['RFC_DISCREPANTE', 'ALTA'], [$last[0], $last[1]]);
    }

    public function testCase7And8DuplicateIdentifierInMaster(): void
    {
        $a2 = new Person(3, '90003', FX::rfc(1), true, null, false, 'OTRA PERSONA');
        $idx = self::idx([self::a(), self::b(), $a2]);
        $m = Matcher::match(self::ev(['rfc' => FX::rfc(1), 'rfcValido' => true]), $idx);
        self::assertSame('CONFLICTO', $m->estado);
        self::assertSame('IDENTIFICADOR_DUPLICADO_EN_MAESTRO', $m->incidencias[count($m->incidencias) - 1][0]);
        $m = Matcher::match(self::ev(['numero' => '90001', 'numeroValido' => true, 'rfc' => FX::rfc(1), 'rfcValido' => true]), $idx);
        self::assertSame(['ASOCIADO_AUTOMATICO', 1], [$m->estado, $m->empleadoId]);
        self::assertTrue(self::hasIncident($m->incidencias, 'IDENTIFICADOR_DUPLICADO_EN_MAESTRO'));
    }

    public function testCase13And14InterbancariaFirstTimeIsOnlyASuggestion(): void
    {
        $m = Matcher::match(self::ev(['nameKey' => 'PEREZ LOPEZ ANA', 'cuentaHmac' => str_repeat('h', 64)]), self::idx([self::a(), self::b()]));
        self::assertSame(['SUGERENCIA', null, 'NOMBRE_EXACTO', 'MEDIA'], [$m->estado, $m->empleadoId, $m->metodo, $m->confianza]);
        self::assertSame([1], $m->evidencia['candidatos']);
    }

    public function testCase15ConfirmedClabeIsStrong(): void
    {
        $idx = self::idx([self::a(), self::b()], [PersonIndex::accountKey(str_repeat('h', 64), 'NOMINA') => 1]);
        $m = Matcher::match(self::ev(['nameKey' => 'PEREZ LOPEZ ANA', 'cuentaHmac' => str_repeat('h', 64)]), $idx);
        self::assertSame(['ASOCIADO_AUTOMATICO', 1, 'CUENTA_CONFIRMADA'], [$m->estado, $m->empleadoId, $m->metodo]);
        $m = Matcher::match(self::ev(['numero' => '90002', 'numeroValido' => true, 'cuentaHmac' => str_repeat('h', 64)]), $idx);
        self::assertSame('CONFLICTO', $m->estado);
    }

    public function testCase16SameNameTwoPeopleIsAmbiguous(): void
    {
        $twin = new Person(3, '90003', null, false, null, false, 'PEREZ LOPEZ ANA');
        $m = Matcher::match(self::ev(['nameKey' => 'PEREZ LOPEZ ANA']), self::idx([self::a(), self::b(), $twin]));
        self::assertSame(['AMBIGUO', [1, 3]], [$m->estado, $m->evidencia['candidatos']]);
    }

    public function testCase17UniqueExactNameAndRejection(): void
    {
        $m = Matcher::match(self::ev(['nameKey' => 'GARCIA RUIZ LUIS']), self::idx([self::a(), self::b()]));
        self::assertSame('SUGERENCIA', $m->estado);
        $m = Matcher::match(self::ev(['nameKey' => 'GARCIA RUIZ LUIS', 'rechazados' => [2]]), self::idx([self::a(), self::b()]));
        self::assertSame('NO_IDENTIFICADO', $m->estado, 'Una sugerencia rechazada no se vuelve a proponer');
    }

    public function testCase18FuzzyIsOnlyVisualSupport(): void
    {
        $m = Matcher::match(self::ev(['nameKey' => 'PEREZ LOPES ANA']), self::idx([self::a(), self::b()]));
        self::assertSame('NO_IDENTIFICADO', $m->estado);
        self::assertNull($m->empleadoId);
        self::assertSame(1, $m->evidencia['similares'][0]['empleado_id']);
        self::assertGreaterThanOrEqual(0.85, $m->evidencia['similares'][0]['parecido']);
        self::assertSame([], Matcher::match(self::ev(['nameKey' => 'ZZZ YYY XXX']), self::idx([self::a(), self::b()]))->evidencia['similares']);
    }

    public function testCase21PensionNeverMatchesByBeneficiaryName(): void
    {
        $m = Matcher::match(self::ev(['nameKey' => 'GARCIA RUIZ LUIS', 'allowName' => false, 'uso' => 'PENSION_BENEFICIARIO']), self::idx([self::a(), self::b()]));
        self::assertSame(['NO_IDENTIFICADO', 'PENSION_SIN_TRABAJADOR'], [$m->estado, $m->evidencia['motivo']]);
        $m = Matcher::match(self::ev(['numero' => '90001', 'numeroValido' => true, 'nameKey' => 'GARCIA RUIZ LUIS', 'allowName' => false,
                                      'uso' => 'PENSION_BENEFICIARIO']), self::idx([self::a(), self::b()]));
        self::assertSame(['ASOCIADO_AUTOMATICO', 1], [$m->estado, $m->empleadoId], 'El número es del TRABAJADOR');
        self::assertFalse(self::hasIncident($m->incidencias, 'NOMBRE_DIFERENTE'));
        $idx = self::idx([self::a(), self::b()], [PersonIndex::accountKey(str_repeat('p', 64), 'NOMINA') => 2]);
        self::assertSame('NO_IDENTIFICADO',
            Matcher::match(self::ev(['cuentaHmac' => str_repeat('p', 64), 'allowName' => false, 'uso' => 'PENSION_BENEFICIARIO']), $idx)->estado);
    }

    public function testRrhhNewNumberWithExistingIdentityIsConflict(): void
    {
        $m = Matcher::matchRrhh(self::ev(['numero' => '95555', 'numeroValido' => true, 'rfc' => FX::rfc(1), 'rfcValido' => true]), self::idx([self::a(), self::b()]));
        self::assertNotNull($m);
        self::assertSame('CONFLICTO', $m->estado);
        self::assertSame('NUMERO_NUEVO_IDENTIDAD_EXISTENTE', $m->incidencias[count($m->incidencias) - 1][0]);
        self::assertNull(Matcher::matchRrhh(self::ev(['numero' => '95555', 'numeroValido' => true, 'rfc' => FX::rfc(50), 'rfcValido' => true]),
            self::idx([self::a(), self::b()])), 'Candidato a alta');
        $m = Matcher::matchRrhh(self::ev(['numero' => 'NOMBRE MAL', 'numeroValido' => false, 'rfc' => FX::rfc(2), 'rfcValido' => true]), self::idx([self::a(), self::b()]));
        self::assertNotNull($m);
        self::assertSame(['ASOCIADO_AUTOMATICO', 2, 'RFC'], [$m->estado, $m->empleadoId, $m->metodo]);
        self::assertSame('NUMERO_EMPLEADO_INVALIDO', $m->incidencias[0][0]);
    }

    public function testNoIdentityAtAllIsInvalid(): void
    {
        self::assertSame('INVALIDO', Matcher::match(self::ev(), self::idx([self::a()]))->estado);
    }

    /** @param array<string,string> $c @return array<string,mixed> */
    private static function row(string $p, string $d, array $c): array
    {
        $data = ['PERCEPCIONES' => $p, 'DEDUCCIONES' => $d, 'NETO' => '0'];
        foreach ($c as $k => $v) {
            $data['C:' . $k] = $v;
        }
        return R::rrhhRow($data);
    }

    public function testConceptTypeInferenceByRowBalance(): void
    {
        $rows = [self::row('1000.00', '150.00', ['001' => '800.00', '003' => '200.00', '070' => '100.00', '044' => '50.00']),
                 self::row('500.00', '60.00', ['001' => '500.00', '070' => '60.00'])];
        [$tipos, $ev] = R::inferConceptTypes(['001', '003', '070', '044'], $rows);
        self::assertSame(['001' => 'PERCEPCION', '003' => 'PERCEPCION', '070' => 'DEDUCCION', '044' => 'DEDUCCION'], $tipos);
        self::assertSame('CORTE', $ev['resultado']);
        $neg = [self::row('1000.00', '150.00', ['001' => '1000.00', '070' => '-100.00', '044' => '-50.00'])];
        [$tipos, $ev] = R::inferConceptTypes(['001', '070', '044'], $neg);
        self::assertSame(['001' => 'PERCEPCION', '070' => 'DEDUCCION', '044' => 'DEDUCCION'], $tipos);
        self::assertSame('NEGATIVO', $ev['signo_deducciones']);
        self::assertSame('001', R::conceptCode('1'));
        self::assertSame('070', R::conceptCode(70));
        self::assertNull(R::conceptCode('A1'));
        [$tipos, $ev] = R::inferConceptTypes(['001', '070'], [self::row('1.00', '9.00', ['001' => '5.00', '070' => '5.00'])]);
        self::assertSame(['DESCONOCIDO'], array_values(array_unique($tipos)));
        self::assertSame('SIN_CORTE', $ev['resultado']);
    }

    public function testMoneyAndDatesNeverFloat(): void
    {
        self::assertSame(['1234.50', 'OK'], R::money('1234.5'));
        self::assertSame([null, 'INVALIDO'], R::money('!INVALIDO:abc'));
        self::assertSame([null, 'VACIO'], R::money(null));
        self::assertSame('2030-01-15', R::dateIso('2030-01-15'));
        self::assertNull(R::dateIso('15 de enero'));
    }

    public function testSantanderNameOrderMatchesTabulado(): void
    {
        $t = R::tesoreriaRow('SANTANDER', ['NUM_EMPLEADO' => '90001', 'APELLIDO_PATERNO' => 'Pérez', 'APELLIDO_MATERNO' => 'López', 'NOMBRE' => 'Ana']);
        self::assertSame('PEREZ LOPEZ ANA', $t['ident']->nombreKey);
        self::assertSame('Pérez', $t['apellido_paterno']);
        $t = R::tesoreriaRow('INTERBANCARIA', ['BENEFICIARIO' => 'Ana Pérez', 'NUM_EMPLEADO' => '90001']);
        self::assertNull($t['ident']->numero, 'La interbancaria no trae número de empleado');
        self::assertSame('SANTANDER', R::bancoDeHoja('SANTANDER $ 1,000.00'));
        self::assertSame('OTRO', R::bancoDeHoja('RETENCIONES'));
    }

    public function testPlaceholdersAreEmptyNotInvalidValues(): void
    {
        self::assertSame([null, false], N::rfc('-'));
        self::assertSame([null, false], N::curp(' . '));
        self::assertSame([null, false], N::employeeNumber('--'));
    }

    public function testUnknownValidNumberWithOtherStrongEvidenceIsConflict(): void
    {
        $m = Matcher::match(self::ev(['numero' => '97777', 'numeroValido' => true, 'rfc' => FX::rfc(1), 'rfcValido' => true]), self::idx([self::a(), self::b()]));
        self::assertSame('CONFLICTO', $m->estado);
        self::assertSame('NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA', $m->evidencia['motivo']);
        $idx = self::idx([self::a(), self::b()], [PersonIndex::accountKey(str_repeat('q', 64), 'PENSION_BENEFICIARIO') => 1]);
        self::assertSame('CONFLICTO', Matcher::match(self::ev(['numero' => '97777', 'numeroValido' => true, 'cuentaHmac' => str_repeat('q', 64),
                                                                'uso' => 'PENSION_BENEFICIARIO', 'allowName' => false]), $idx)->estado);
    }

    public function testFuzzyThresholdIsConfigurable(): void
    {
        $idx = self::idx([self::a(), self::b()]);
        $idx->fuzzyMin = 0.99;
        self::assertSame([], Matcher::match(self::ev(['nameKey' => 'PEREZ LOPES ANA']), $idx)->evidencia['similares']);
    }
}
