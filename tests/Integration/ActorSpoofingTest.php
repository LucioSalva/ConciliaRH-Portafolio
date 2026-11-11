<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Tests\Support\DatabaseTestCase;

/**
 * Actor no suplantable (0012, docs/SECURITY.md §2e). Con el MISMO rol de base de datos que la aplicación
 * (conciliarh_web), ninguna escritura puede atribuirse a otra persona que el usuario de la sesión autenticada,
 * aunque PHP enviara otro id (p. ej. un parámetro HTTP).
 */
final class ActorSpoofingTest extends DatabaseTestCase
{
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createUser('actor.a', ['ADMIN']);
        $this->b = $this->createUser('actor.b', ['ADMIN']);
        $this->actingAs($this->actor($this->a));
    }

    private function actorNow(): ?int
    {
        $v = self::web()->fetchValue('SELECT fn_actor_actual()');
        return $v === null ? null : (int) $v;
    }

    public function testActorComesFromTheAuthenticatedSessionOnly(): void
    {
        self::assertSame($this->a, $this->actorNow());
        // Conocer el HASH almacenado no sirve: la BD exige el token crudo
        $hash = (string) self::web()->fetchValue('SELECT id FROM sesiones WHERE usuario_id = :u LIMIT 1', ['u' => $this->a]);
        self::web()->bindActor($hash);
        self::assertNull($this->actorNow());
        // Un token inventado o con formato inválido tampoco (y no hay concatenación posible)
        foreach ([bin2hex(random_bytes(32)), "x'); DROP TABLE auditoria; --", ''] as $t) {
            self::web()->bindActor($t);
            self::assertNull($this->actorNow());
        }
        // set_config directo con un token ajeno desconocido tampoco da identidad
        self::web()->query("SELECT set_config('conciliarh.actor_token', :t, false)", ['t' => str_repeat('a', 64)]);
        self::assertNull($this->actorNow());
    }

    public function testCannotAttributeWritesToAnotherUser(): void
    {
        $cases = [
            ['INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
              VALUES (2097, 3, 1, \'2097-03-01\', \'2097-03-15\', \'PERIODO SUPLANTADO\', :u)', []],
            ['INSERT INTO procesos (tipo, parametros, creado_por) VALUES (\'DIAGNOSTICO\', \'{}\', :u)', []],
            ['UPDATE configuracion SET actualizado_por = :u WHERE clave = \'conciliacion.tolerancia_monetaria\'', []],
            ['SELECT fn_crear_lote((SELECT id FROM periodos_nomina WHERE estado = \'ABIERTO\' ORDER BY id LIMIT 1), \'\', \'\', \'\', \'\', :u)', []],
        ];
        $periodo = (int) self::web()->fetchValue("INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
            VALUES (2097, 4, 1, '2097-04-01', '2097-04-15', 'PERIODO DEL ACTOR', :u) RETURNING id", ['u' => $this->a]);
        self::assertGreaterThan(0, $periodo, 'Con el actor correcto sí se escribe');
        foreach ($cases as [$sql]) {
            $this->assertSqlState('42501', self::web(), fn ($db) => $db->query($sql, ['u' => $this->b]));
        }
        // Sin sesión (conexión anónima) tampoco se atribuye a nadie
        $this->actingAs(null);
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->query(
            "UPDATE periodos_nomina SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now() WHERE id = :p", ['u' => $this->a, 'p' => $periodo]));
    }

    public function testAuditActorNameAndRolesComeFromTheDatabase(): void
    {
        self::web()->execute(
            "INSERT INTO auditoria (usuario_id, username, roles, origen, accion, hash) VALUES (:u, 'otro.nombre', 'GOD', 'WEB', 'prueba.actor', repeat('0', 64))",
            ['u' => $this->a]);
        $row = self::web()->fetchOne("SELECT usuario_id, username, roles FROM auditoria WHERE accion = 'prueba.actor' ORDER BY secuencia DESC LIMIT 1");
        self::assertSame([$this->a, 'actor.a', 'ADMIN'], [(int) $row['usuario_id'], $row['username'], $row['roles']], 'Nombre y roles no los decide PHP');
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->query(
            "INSERT INTO auditoria (usuario_id, origen, accion, hash) VALUES (:u, 'WEB', 'prueba.actor', repeat('0', 64))", ['u' => $this->b]));
        $this->assertSqlState('42501', self::web(), static fn ($db) => $db->query(
            "INSERT INTO auditoria (origen, accion, hash) VALUES ('WORKER', 'prueba.actor', repeat('0', 64))"));
    }

    public function testSessionBindingRules(): void
    {
        // La web no vincula sesiones escribiendo usuario_id directamente
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->query(
            'INSERT INTO sesiones (id, usuario_id) VALUES (:id, :u)', ['id' => hash('sha256', 'x'), 'u' => $this->b]));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->query(
            'UPDATE sesiones SET usuario_id = :u WHERE usuario_id = :a', ['u' => $this->b, 'a' => $this->a]));
        // Una sesión ya vinculada no se re-vincula a otra persona
        $token = bin2hex(random_bytes(32));
        self::web()->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $token, 'u' => $this->a, 'ua' => 'x']);
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)',
            ['t' => $token, 'u' => $this->b, 'ua' => 'x']));
        // Una sesión revocada deja de ser actor
        self::web()->bindActor($token);
        self::assertSame($this->a, $this->actorNow());
        self::web()->execute('UPDATE sesiones SET revocada_at = now(), revocada_por = :u WHERE id = :h', ['u' => $this->a, 'h' => hash('sha256', $token)]);
        self::assertNull($this->actorNow());
        // La vinculación queda registrada por la propia BD
        self::assertNotEmpty($this->auditRows('auth.sesion_vinculada'));
    }

    public function testInactiveUserIsNoActor(): void
    {
        $token = bin2hex(random_bytes(32));
        self::web()->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $token, 'u' => $this->b, 'ua' => 'x']);
        self::assertSame($this->b, $this->actorNow());
        $this->actingAs($this->actor($this->a));
        self::web()->execute('UPDATE usuarios SET activo = false WHERE id = :u', ['u' => $this->b]);
        self::web()->bindActor($token);
        self::assertNull($this->actorNow());
    }
}
