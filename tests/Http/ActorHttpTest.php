<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\HttpTestCase;

/** Actor no suplantable por HTTP: parámetros como usuario_id/creado_por se ignoran; el actor es la sesión. */
final class ActorHttpTest extends HttpTestCase
{
    public function testPostedActorFieldsAreIgnored(): void
    {
        $victima = $this->createUser('victima.http', ['GOD']);
        $admin = $this->createUser('admin.http', ['ADMIN']);
        $this->loginAs('admin.http');
        $r = $this->post('/periodos', ['anio' => '2096', 'mes' => '5', 'quincena' => '1',
            'creado_por' => (string) $victima, 'usuario_id' => (string) $victima, 'actor' => (string) $victima]);
        self::assertSame(303, $r->status());
        $p = self::web()->fetchOne("SELECT creado_por FROM periodos_nomina WHERE anio = 2096 AND mes = 5");
        self::assertSame($admin, (int) $p['creado_por']);
        $a = self::web()->fetchOne("SELECT usuario_id, username FROM auditoria WHERE accion = 'periodos.crear' ORDER BY secuencia DESC LIMIT 1");
        self::assertSame([$admin, 'admin.http'], [(int) $a['usuario_id'], $a['username']]);
        self::assertSame($admin, (int) self::web()->fetchValue('SELECT fn_actor_actual()'), 'La conexión actúa como la sesión');
    }

    public function testLogoutLeavesNoActor(): void
    {
        $this->createUser('sale.http', ['CONSULTA']);
        $this->loginAs('sale.http');
        $this->post('/logout');
        self::assertNull(self::web()->fetchValue('SELECT fn_actor_actual()'));
    }
}
