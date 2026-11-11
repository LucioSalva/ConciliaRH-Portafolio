<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Database;
use ConciliaRH\Tests\Support\HttpTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;

final class QuincenaPendingHttpTest extends HttpTestCase
{
    use IdentityScenario;

    private function as(string $role): int
    {
        $user = strtolower($role) . '.qp' . bin2hex(random_bytes(2));
        $id = $this->createUser($user, [$role]);
        $this->loginAs($user);
        return $id;
    }

    private function db(): Database
    {
        return $this->committedOwner(static function (): void {
        });
    }

    /** @param array<string,mixed> $s */
    private function workOnScenarioLot(array $s): void
    {
        $this->db()->execute('UPDATE lotes_importacion SET es_oficial = false, declarado_oficial_por = NULL, declarado_oficial_at = NULL
                               WHERE periodo_id = :p AND id <> :l', ['l' => $s['lote'], 'p' => $s['periodo']]);
        $this->db()->execute('UPDATE lotes_importacion SET es_oficial = true, declarado_oficial_por = creado_por, declarado_oficial_at = now() WHERE id = :l',
            ['l' => $s['lote']]);
    }

    public function testLotsCardIsShownToAdminButNotToConsulta(): void
    {
        $s = $this->identityScenario();
        $this->as('ADMIN');
        self::assertStringContainsString('<h2 class="table-card__title">Lotes de carga</h2>', $this->get('/periodos/' . $s['periodo'])->body());
        $this->as('CONSULTA');
        $page = $this->get('/periodos/' . $s['periodo']);
        self::assertSame(200, $page->status());
        self::assertStringNotContainsString('Lotes de carga', $page->body());
    }

    public function testAmountDifferentFromRhNetIsNeverConfirmedInBulk(): void
    {
        $s = $this->identityScenario();
        $this->workOnScenarioLot($s);
        $this->as('ADMIN');
        $page = $this->get('/periodos/' . $s['periodo'] . '/pendientes');
        self::assertSame(200, $page->status());
        $body = $page->body();
        self::assertStringContainsString('No hay pagos que cumplan las dos condiciones.', $body);
        self::assertStringNotContainsString('name="items[]"', $body);
        self::assertStringContainsString('/asociaciones/tesoreria/' . $s['sug'], $body, 'Queda para revisarse uno por uno');
    }

    public function testExactNameAndAmountIsConfirmedInBulkAndTheAccountIsRemembered(): void
    {
        $s = $this->identityScenario();
        $this->workOnScenarioLot($s);
        $db = $this->db();
        $db->execute("UPDATE registros_tesoreria SET importe = 8500.00 WHERE id = :id", ['id' => $s['sug']]);
        $db->execute("UPDATE registros_tesoreria SET asociacion_estado = 'NO_IDENTIFICADO', asociacion_evidencia = '{}'::jsonb WHERE id = :id", ['id' => $s['conf']]);
        $this->as('ADMIN');
        $body = $this->get('/periodos/' . $s['periodo'] . '/pendientes')->body();
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $body, $t));
        self::assertSame(1, preg_match_all('/name="items\[\]" value="([^"]+)"/', $body, $items));
        self::assertSame([$s['sug'] . ':'], array_map(static fn ($i) => substr(html_entity_decode($i), 0, strlen((string) $s['sug']) + 1), $items[1]));
        $r = $this->post('/periodos/' . $s['periodo'] . '/pendientes/confirmar', ['token' => html_entity_decode($t[1]),
            'items' => array_map('html_entity_decode', $items[1]), 'motivo' => 'Coinciden nombre e importe']);
        self::assertSame(303, $r->status());
        $web = self::web();
        $row = $web->fetchOne('SELECT asociacion_estado, empleado_id FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]);
        self::assertSame(['asociacion_estado' => 'ASOCIADO_MANUAL', 'empleado_id' => $s['emp1']], $row);
        self::assertSame(1, (int) $web->fetchValue("SELECT count(*) FROM cuentas_bancarias WHERE empleado_id = :e AND cuenta_ultimos4 = '2345'", ['e' => $s['emp1']]));
        self::assertSame(1, (int) $web->fetchValue("SELECT count(*) FROM acciones_masivas WHERE accion = 'CONFIRMAR_PROPUESTA' AND motivo = 'Coinciden nombre e importe'"));
    }

    public function testConsultaSeesButCannotConfirm(): void
    {
        $s = $this->identityScenario();
        $this->as('CONSULTA');
        self::assertSame(200, $this->get('/periodos/' . $s['periodo'] . '/pendientes')->status());
        self::assertSame(403, $this->post('/periodos/' . $s['periodo'] . '/pendientes/confirmar', ['token' => 'x', 'items' => ['1:1:1'], 'motivo' => 'intento'])->status());
        self::assertSame(403, $this->post('/periodos/' . $s['periodo'] . '/cotejar', [])->status());
        self::assertSame(403, $this->post('/periodos/' . $s['periodo'] . '/archivos', ['columna' => 'PAGOS', 'poblacion' => 'POLICIA'])->status());
    }
}
