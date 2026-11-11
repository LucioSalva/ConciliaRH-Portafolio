<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Domain\Identity\AssociationService;
use ConciliaRH\Domain\Identity\EmployeeService;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Lots\LotRelationService;
use ConciliaRH\Domain\Payroll\ConceptService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;

/** Capa de identidades (FASE 4) desde la web: asociación manual, cuentas, maestro, relaciones de lotes. Datos ficticios. */
final class IdentityServiceTest extends DatabaseTestCase
{
    use IdentityScenario;

    private CurrentUser $operador;
    private CurrentUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operador = $this->actor($this->createUser('operador.idn', ['ADMIN']));
        $this->admin = $this->actor($this->createUser('admin.idn', ['ADMIN']));
    }

    private function config(): Config
    {
        $env = self::env();
        return new Config(['app' => ['enc_keys' => $env->string('ACCOUNT_ENC_KEYS'), 'enc_key_id' => $env->string('ACCOUNT_ENC_KEY_ID', 'k1')]]);
    }

    private function employees(CurrentUser $actor): EmployeeService
    {
        return new EmployeeService(self::web(), $this->audit($actor), new AccountVault($this->config()));
    }

    private function assoc(CurrentUser $actor): AssociationService
    {
        $db = self::web();
        $audit = $this->audit($actor);
        $settings = new SettingsService($db, $audit);
        return new AssociationService($db, $audit, new JobQueue($db, $audit, $settings), $settings, $this->employees($actor));
    }

    /** @return array<string,mixed> */
    private function tes(int $id): array
    {
        return (array) self::web()->fetchOne(
            'SELECT id, empleado_id, asociacion_estado, asociacion_metodo, asociacion_version, rechazados, asociado_por, asociacion_evidencia
               FROM registros_tesoreria WHERE id = :id', ['id' => $id]);
    }

    private function version(int $id): int
    {
        return (int) $this->tes($id)['asociacion_version'];
    }

    public function testCase13To15ManualAssociationConfirmsClabeWithoutPhpSeeingIt(): void
    {
        $s = $this->identityScenario();
        $this->assoc($this->operador)->associate($this->operador, 'TESORERIA', $s['sug'], $s['emp1'], $this->version($s['sug']),
            'Coincide con el expediente ficticio', true);
        $r = $this->tes($s['sug']);
        self::assertSame(['ASOCIADO_MANUAL', $s['emp1'], 'MANUAL'], [$r['asociacion_estado'], (int) $r['empleado_id'], $r['asociacion_metodo']]);
        $c = self::web()->fetchOne("SELECT id, origen, tipo, cuenta_ultimos4, confirmado_por, uso FROM cuentas_bancarias WHERE empleado_id = :e", ['e' => $s['emp1']]);
        self::assertSame(['CONFIRMACION_MANUAL', 'CLABE', '2345', $this->operador->id, 'NOMINA'],
            [$c['origen'], $c['tipo'], $c['cuenta_ultimos4'], (int) $c['confirmado_por'], $c['uso']]);
        $hist = array_column(self::web()->fetchAll("SELECT accion FROM asociaciones_historial WHERE registro_tipo = 'TESORERIA' AND registro_id = :r ORDER BY id",
            ['r' => $s['sug']]), 'accion');
        self::assertSame(['ASOCIAR', 'CONFIRMAR_CUENTA'], $hist);
        $audit = json_encode([$this->auditRows('asociaciones.asociar'), $this->auditRows('cuentas.confirmar')]);
        self::assertStringNotContainsString(self::CLABE, (string) $audit, 'Nunca la CLABE completa en auditoría');
        self::assertCount(1, $this->auditRows('cuentas.confirmar'));
        self::assertSame(self::CLABE, $this->employees($this->admin)->revealAccount($this->admin, $s['emp1'], (int) $c['id']));
        self::assertStringNotContainsString(self::CLABE, (string) json_encode($this->auditRows('cuentas.revelar')));
        $consulta = $this->actor($this->createUser('consulta.revela', ['CONSULTA']));
        try {
            $this->employees($consulta)->revealAccount($consulta, $s['emp1'], (int) $c['id']);
            self::fail('CONSULTA no tiene cuentas.ver_sensible');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testConfirmingAnAccountActiveForAnotherPersonIsRejected(): void
    {
        $s = $this->identityScenario();
        // La cuenta del registro automático (emp2) no puede confirmarse para emp1
        self::web()->execute("UPDATE registros_tesoreria SET empleado_id = NULL, asociacion_estado = 'CONFLICTO', asociacion_metodo = NULL,
                               asociacion_confianza = 'NINGUNA' WHERE id = :id", ['id' => $s['auto']]);
        try {
            $this->assoc($this->operador)->associate($this->operador, 'TESORERIA', $s['auto'], $s['emp1'], $this->version($s['auto']), 'Prueba de conflicto', true);
            self::fail('Debió rechazar');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirmar_cuenta', $e->errors());
        }
    }

    public function testStaleVersionAndClosedPeriodAreRejected(): void
    {
        $s = $this->identityScenario();
        try {
            $this->assoc($this->operador)->associate($this->operador, 'TESORERIA', $s['sug'], $s['emp1'], $this->version($s['sug']) + 1, 'Motivo suficiente', false);
            self::fail('Versión vieja');
        } catch (ValidationException $e) {
            self::assertStringContainsString('cambió', implode(' ', $e->errors()));
        }
        $this->actingAs($this->admin);
        self::web()->execute("UPDATE periodos_nomina SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now() WHERE id = :p",
            ['u' => $this->admin->id, 'p' => $s['periodo']]);
        try {
            $this->assoc($this->operador)->associate($this->operador, 'TESORERIA', $s['sug'], $s['emp1'], $this->version($s['sug']), 'Motivo suficiente', false);
            self::fail('Periodo cerrado');
        } catch (ValidationException $e) {
            self::assertStringContainsString('cerrado', implode(' ', $e->errors()));
        }
    }

    public function testConfirmRequiresPermissionAndTreasuryRecord(): void
    {
        $s = $this->identityScenario();
        $consulta = $this->actor($this->createUser('consulta.idn', ['CONSULTA']));
        $this->expectException(HttpException::class);
        $this->assoc($consulta)->associate($consulta, 'TESORERIA', $s['sug'], $s['emp1'], $this->version($s['sug']), 'Motivo suficiente', true);
    }

    public function testRejectSuggestionIsRememberedAndEnqueuesReassociation(): void
    {
        $s = $this->identityScenario();
        $this->assoc($this->operador)->reject($this->operador, 'TESORERIA', $s['sug'], $s['emp1'], $this->version($s['sug']), 'No es la misma persona');
        $r = $this->tes($s['sug']);
        self::assertSame('NO_IDENTIFICADO', $r['asociacion_estado']);
        self::assertSame([$s['emp1']], AssociationService::pgArray($r['rechazados']));
        self::assertSame([], AssociationService::json($r['asociacion_evidencia'])['candidatos']);
        self::assertSame(1, (int) self::web()->fetchValue(
            "SELECT count(*) FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS' AND parametros->>'archivo_id' = :a AND parametros->>'completa' = 'false'",
            ['a' => (string) $s['archivo_t']]));
        // Un empleado que no es candidato no puede "rechazarse"
        $this->expectException(ValidationException::class);
        $this->assoc($this->operador)->reject($this->operador, 'TESORERIA', $s['conf'], 999999, $this->version($s['conf']), 'Motivo suficiente');
    }

    public function testConflictResolutionClosesIncidentsAndMarkUnidentified(): void
    {
        $s = $this->identityScenario();
        $this->assoc($this->operador)->associate($this->operador, 'TESORERIA', $s['conf'], $s['emp1'], $this->version($s['conf']), 'El número es correcto; RFC mal capturado', false);
        self::assertSame('RESUELTA', self::web()->fetchValue("SELECT estado FROM incidencias_identidad WHERE registro_tipo = 'TESORERIA' AND registro_id = :r", ['r' => $s['conf']]));
        $a = $this->auditRows('asociaciones.asociar');
        self::assertTrue(json_decode((string) end($a)['contexto'], true)['resuelve_conflicto']);
        $this->assoc($this->operador)->markUnidentified($this->operador, 'TESORERIA', $s['pen'], $this->version($s['pen']), 'Beneficiaria sin trabajador identificable');
        self::assertSame('NO_IDENTIFICADO_CONFIRMADO', $this->tes($s['pen'])['asociacion_estado']);
        // No se puede marcar no identificado un registro asociado automáticamente
        $this->expectException(ValidationException::class);
        $this->assoc($this->operador)->markUnidentified($this->operador, 'TESORERIA', $s['auto'], $this->version($s['auto']), 'Motivo suficiente');
    }

    public function testAssociateByEmployeeNumberAndPensionToWorker(): void
    {
        $s = $this->identityScenario();
        $svc = $this->assoc($this->operador);
        $emp = $svc->employeeIdByNumber(' 90002 ');
        self::assertSame($s['emp2'], $emp);
        $svc->associate($this->operador, 'TESORERIA', $s['pen'], $emp, $this->version($s['pen']), 'Trabajador según oficio ficticio', false);
        self::assertSame($s['emp2'], (int) $this->tes($s['pen'])['empleado_id']);
        $this->expectException(ValidationException::class);
        $svc->employeeIdByNumber('NO EXISTE');
    }

    public function testEmployeeCorrectionKeepsHistoryMasksAuditAndRejectsDuplicates(): void
    {
        $s = $this->identityScenario();
        $svc = $this->employees($this->admin);
        $n = $svc->update($this->admin, $s['emp1'], ['rfc' => 'fict-000099 ab9', 'apellido_paterno' => 'FICTICIA', 'activo' => '1'], 'Corrección con documento ficticio');
        self::assertSame(2, $n);
        $e = self::web()->fetchOne('SELECT rfc, rfc_valido, apellido_paterno FROM empleados WHERE id = :id', ['id' => $s['emp1']]);
        self::assertSame(['FICT000099AB9', true, 'FICTICIA'], [$e['rfc'], (bool) $e['rfc_valido'], $e['apellido_paterno']]);
        $h = self::web()->fetchAll("SELECT campo, valor_anterior, fuente, usuario_id FROM empleado_historial WHERE empleado_id = :e AND fuente = 'MANUAL' ORDER BY id", ['e' => $s['emp1']]);
        self::assertSame(['apellido_paterno', 'rfc'], array_column($h, 'campo'));
        self::assertSame(self::fakeRfc(1), $h[1]['valor_anterior']);
        $audit = (string) json_encode($this->auditRows('empleados.editar'));
        self::assertStringNotContainsString('FICT000099AB9', $audit, 'El RFC completo no va a la auditoría');
        foreach ([['rfc' => self::fakeRfc(2)], ['curp' => 'MAL'], ['rfc' => 'XYZ']] as $bad) {
            try {
                $svc->update($this->admin, $s['emp1'], $bad, 'Intento inválido');
                self::fail('Debió rechazar ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
        // El número y el origen son inmutables también en BD
        $this->assertSqlState('42501', self::web(), static fn ($db) => $db->execute("UPDATE empleados SET numero_empleado = '1' WHERE id = :id", ['id' => $s['emp1']]));
    }

    public function testSearchRulesAndMasking(): void
    {
        $s = $this->identityScenario();
        $consulta = $this->actor($this->createUser('consulta.busca', ['CONSULTA']));
        $f = ['q' => '', 'grupo' => '', 'estado' => '', 'incidencias' => false];
        $svcC = $this->employees($consulta);
        $rows = $svcC->paginate($consulta, ['q' => 'ficticia uno'] + $f, 1, 25)['rows'];
        self::assertCount(1, $rows);
        self::assertStringStartsWith('FICT', (string) $rows[0]['rfc']);
        self::assertStringContainsString('•', (string) $rows[0]['rfc'], 'CONSULTA ve el RFC enmascarado');
        self::assertSame([], $svcC->paginate($consulta, ['q' => self::fakeRfc(1)] + $f, 1, 25)['rows'], 'Sin permiso sensible no se busca por RFC');
        $res = $this->employees($this->operador)->paginate($this->operador, ['q' => self::fakeRfc(1)] + $f, 1, 25);
        self::assertSame(['IDENTIFICADOR', self::fakeRfc(1)], [$res['criterio'], $res['rows'][0]['rfc']]);
        self::assertSame('NUMERO', $this->employees($this->operador)->paginate($this->operador, ['q' => '9000'] + $f, 1, 25)['criterio']);
        $this->expectException(ValidationException::class);
        $svcC->paginate($consulta, ['q' => 'ab'] + $f, 1, 25);
    }

    public function testAccountIdorAndDeactivation(): void
    {
        $s = $this->identityScenario();
        $svc = $this->employees($this->admin);
        try {
            $svc->revealAccount($this->admin, $s['emp1'], $s['cuenta2']);
            self::fail('La cuenta pertenece a otro empleado');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
        self::assertSame(self::CUENTA_SANT, $svc->revealAccount($this->admin, $s['emp2'], $s['cuenta2']));
        $svc->deactivateAccount($this->admin, $s['emp2'], $s['cuenta2'], 'Cuenta cancelada (ficticio)');
        self::assertSame('INACTIVA', self::web()->fetchValue('SELECT estado FROM cuentas_bancarias WHERE id = :c', ['c' => $s['cuenta2']]));
        // Una cuenta inactiva no se reactiva y la web no puede leer el HMAC
        $this->assertSqlState('23514', self::web(), static fn ($db) => $db->execute("UPDATE cuentas_bancarias SET estado = 'ACTIVA', desactivado_at = NULL WHERE id = :c", ['c' => $s['cuenta2']]));
        $this->assertSqlState('42501', self::web(), static fn ($db) => $db->fetchAll('SELECT cuenta_hmac FROM cuentas_bancarias'));
        $this->assertSqlState('42501', self::web(), static fn ($db) => $db->fetchAll('SELECT cuenta_cifrada FROM registros_tesoreria'));
    }

    public function testCase24LotRelations(): void
    {
        $s = $this->identityScenario();
        $svc = new LotRelationService(self::web(), $this->audit($this->admin));
        $id = $svc->create($this->admin, $s['lote2'], $s['lote'], 'CORRIGE', 'El lote 2 corrige errores del lote 1');
        self::assertCount(1, $svc->forLot($s['lote']));
        foreach ([[$s['lote2'], $s['lote'], 'CORRIGE'], [$s['lote'], $s['lote2'], 'SUSTITUYE'], [$s['lote'], $s['lote'], 'RELACIONADO']] as [$o, $d, $t]) {
            try {
                $svc->create($this->admin, $o, $d, $t, 'Relación inválida de prueba');
                self::fail("Debió rechazar $t");
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
        $svc->create($this->admin, $s['lote'], $s['lote2'], 'COMPLEMENTA', 'Complemento de prueba');
        $svc->annul($this->admin, $s['lote'], $id, 'Relación declarada por error');
        self::assertSame('ANULADA', self::web()->fetchValue('SELECT estado FROM lotes_relaciones WHERE id = :id', ['id' => $id]));
        self::assertCount(1, $this->auditRows('lotes.anular_relacion'));
        $this->assertSqlState('42501', self::web(), static fn ($db) => $db->execute('DELETE FROM lotes_relaciones'));
    }

    public function testConceptCatalogConfiguration(): void
    {
        $svc = new ConceptService(self::web(), $this->audit($this->admin));
        $id = (int) self::web()->fetchValue("SELECT id FROM conceptos_nomina WHERE codigo = '044'");
        try {
            $svc->update($this->admin, $id, 'PERCEPCION', true, 'Prueba inválida');
            self::fail('Una pensión debe ser deducción');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('tipo', $e->errors());
        }
        $svc->update($this->admin, $id, 'DEDUCCION', false, 'Reclasificación de prueba');
        self::assertFalse((bool) self::web()->fetchValue('SELECT es_pension FROM conceptos_nomina WHERE id = :id', ['id' => $id]));
        self::assertCount(1, $this->auditRows('conceptos.editar'));
    }

    public function testNormalizationRequestRules(): void
    {
        $s = $this->identityScenario();
        $svc = $this->assoc($this->operador);
        $job = $svc->requestNormalization($this->operador, $s['archivo_t'], true);
        self::assertSame(['NORMALIZAR_REGISTROS', true], [self::web()->fetchValue('SELECT tipo FROM procesos WHERE id = :id', ['id' => $job]),
            json_decode((string) self::web()->fetchValue('SELECT parametros FROM procesos WHERE id = :id', ['id' => $job]), true)['completa']]);
        try {
            $svc->requestNormalization($this->operador, $s['archivo_t'], false);
            self::fail('Ya hay una en curso');
        } catch (ImportException $e) {
            self::assertSame('ESTADO_INVALIDO', $e->errorCode());
        }
        $n = $svc->normalization($s['archivo_t']);
        self::assertSame(['TESORERIA', 'VIGENTE', 4], [$n['tipo_registro'], $n['estado'], (int) $n['registros']]);
    }

    public function testAutomaticAssociationCanBeCorrectedAndItsAccountConfirmed(): void
    {
        $s = $this->identityScenario();
        $svc = $this->assoc($this->operador);
        // Confirmar la cuenta del registro automático para su mismo empleado ⇒ pasa a manual; la cuenta ya existía (evidencia fuerte)
        $svc->associate($this->operador, 'TESORERIA', $s['auto'], $s['emp2'], $this->version($s['auto']), 'Confirmo la cuenta con el oficio', true);
        self::assertSame('ASOCIADO_MANUAL', $this->tes($s['auto'])['asociacion_estado']);
        self::assertSame(1, (int) self::web()->fetchValue('SELECT count(*) FROM cuentas_bancarias WHERE empleado_id = :e', ['e' => $s['emp2']]));
        // Corregir una asociación automática equivocada
        self::web()->execute("UPDATE registros_tesoreria SET asociacion_estado = 'ASOCIADO_AUTOMATICO', asociacion_metodo = 'NUMERO_EMPLEADO',
                               asociacion_confianza = 'ALTA', asociado_por = NULL, asociado_at = NULL WHERE id = :id", ['id' => $s['auto']]);
        $svc->associate($this->operador, 'TESORERIA', $s['auto'], $s['emp1'], $this->version($s['auto']), 'Número mal capturado', false);
        $a = $this->auditRows('asociaciones.asociar');
        self::assertTrue(json_decode((string) end($a)['contexto'], true)['corrige_automatica']);
    }

    public function testEditingIdentifiersRequiresSensitivePermission(): void
    {
        $s = $this->identityScenario();
        $roleId = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('EDITOR_IDN', 'Editor sin sensible') RETURNING id"));
        foreach (['empleados.ver', 'empleados.editar'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $roleId, 'p' => $p]));
        }
        $editor = $this->actor($this->createUser('editor.idn', ['EDITOR_IDN']));
        try {
            $this->employees($editor)->update($editor, $s['emp1'], ['rfc' => self::fakeRfc(50)], 'Intento a ciegas');
            self::fail('Sin empleados.ver_sensible no se escriben identificadores');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        self::assertSame(1, $this->employees($editor)->update($editor, $s['emp1'], ['rfc' => '', 'nombres' => 'NOMBRE FICTICIO'], 'Sólo el nombre'));
        self::assertSame(self::fakeRfc(1), self::web()->fetchValue('SELECT rfc FROM empleados WHERE id = :id', ['id' => $s['emp1']]));
    }

    public function testClosedPeriodBlocksReassociationAndLotRelations(): void
    {
        $s = $this->identityScenario();
        $this->actingAs($this->admin);
        self::web()->execute("UPDATE periodos_nomina SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now() WHERE id = :p",
            ['u' => $this->admin->id, 'p' => $s['periodo']]);
        try {
            $this->assoc($this->operador)->requestNormalization($this->operador, $s['archivo_t'], false);
            self::fail('Periodo cerrado');
        } catch (ImportException $e) {
            self::assertSame('LOTE_NO_ABIERTO', $e->errorCode());
        }
        try {
            (new LotRelationService(self::web(), $this->audit($this->admin)))->create($this->admin, $s['lote2'], $s['lote'], 'RELACIONADO', 'Periodo cerrado');
            self::fail('Periodo cerrado');
        } catch (ValidationException $e) {
            self::assertStringContainsString('abierto', implode(' ', $e->errors()));
        }
    }

    /** Matriz funcional: cerrar una incidencia de identidad (con motivo, una sola vez, auditado). */
    public function testCloseIdentityIncident(): void
    {
        $this->identityScenario();
        $an = $this->actor($this->createUser('operador.inc', ['ADMIN']));
        $inc = (int) self::web()->fetchValue("SELECT id FROM incidencias_identidad WHERE estado = 'ABIERTA' ORDER BY id LIMIT 1");
        try {
            $this->assoc($an)->closeIncident($an, $inc, 'BORRADA', 'Motivo suficiente');
            self::fail('Estado inválido');
        } catch (ValidationException) {
        }
        try {
            $this->assoc($an)->closeIncident($an, $inc, 'RESUELTA', '');
            self::fail('Exige motivo');
        } catch (ValidationException) {
        }
        $this->assoc($an)->closeIncident($an, $inc, 'RESUELTA', 'Se revisó contra el expediente');
        self::assertSame(['RESUELTA', $an->id], array_values(self::web()->fetchOne(
            'SELECT estado, resuelto_por FROM incidencias_identidad WHERE id = :id', ['id' => $inc])));
        self::assertCount(1, $this->auditRows('incidencias.cerrar'));
        try {
            $this->assoc($an)->closeIncident($an, $inc, 'DESCARTADA', 'Segundo intento de cierre');
            self::fail('Ya cerrada');
        } catch (ValidationException) {
        }
    }
}
