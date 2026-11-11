<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Database;
use ConciliaRH\Tests\Support\DatabaseTestCase;

final class TresRolesMigrationTest extends DatabaseTestCase
{
    private const OTORGADOS = ['empleados.fusionar_conflictos', 'empleados.fusionar_conflictos_aprobar', 'empleados.separar',
        'lotes.declarar_oficial', 'lotes.reabrir', 'periodos.anular', 'periodos.reabrir'];
    private const LIBERADOS = ['empleados.separar', 'lotes.declarar_oficial', 'lotes.reabrir', 'periodos.anular', 'periodos.reabrir'];

    private static function sql(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file);
    }

    /** @return list<string> */
    private static function adminGrants(Database $db, array $codes): array
    {
        return array_column($db->fetchAll(
            "SELECT p.codigo FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id
              WHERE r.codigo = 'ADMIN' AND p.codigo = ANY(CAST(:c AS text[])) ORDER BY p.codigo", ['c' => '{' . implode(',', $codes) . '}']), 'codigo');
    }

    public function testFreshDatabaseHasOnlyGodAdminAndConsulta(): void
    {
        $db = self::web();
        self::assertSame(['ADMIN', 'CONSULTA', 'GOD'], array_column($db->fetchAll('SELECT codigo FROM roles ORDER BY codigo'), 'codigo'));
        self::assertSame(self::OTORGADOS, self::adminGrants($db, self::OTORGADOS));
        self::assertSame([], self::adminGrants($db, ['auditoria.ver', 'auditoria.verificar']), 'ADMIN ya no consulta la auditoría');
        self::assertSame([], array_column($db->fetchAll(
            'SELECT codigo FROM permisos WHERE solo_god AND codigo = ANY(CAST(:c AS text[]))', ['c' => '{' . implode(',', self::LIBERADOS) . '}']), 'codigo'));
        self::assertSame(count(self::LIBERADOS), (int) $db->fetchValue(
            'SELECT count(*) FROM permisos WHERE codigo = ANY(CAST(:c AS text[]))', ['c' => '{' . implode(',', self::LIBERADOS) . '}']));
        self::assertSame(1, (int) $this->owner()->fetchValue("SELECT count(*) FROM schema_migraciones WHERE version = '0026'"));
    }

    public function testMigrationMovesAnalistaAndAprobadorToAdminAndAuditsEachPerson(): void
    {
        $o = $this->owner();
        $o->pdo()->exec(self::sql('0026_tres_roles.down.sql'));
        self::assertSame(['ADMIN', 'ANALISTA', 'APROBADOR', 'CONSULTA', 'GOD'], array_column($o->fetchAll('SELECT codigo FROM roles ORDER BY codigo'), 'codigo'));
        self::assertSame([], self::adminGrants($o, self::OTORGADOS));
        $users = [];
        foreach (['mig.analista' => ['ANALISTA'], 'mig.aprobador' => ['APROBADOR', 'CONSULTA'], 'mig.doble' => ['ADMIN', 'APROBADOR'],
                  'mig.consulta' => ['CONSULTA']] as $username => $roles) {
            $id = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, 'Persona ficticia', repeat('x', 30)) RETURNING id",
                ['u' => $username]);
            foreach ($roles as $code) {
                $o->execute('INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :c', ['u' => $id, 'c' => $code]);
            }
            $users[$username] = $id;
        }
        $o->pdo()->exec(self::sql('0026_tres_roles.up.sql'));
        $roles = static fn (int $u): array => array_column($o->fetchAll(
            'SELECT r.codigo FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :u ORDER BY r.codigo', ['u' => $u]), 'codigo');
        self::assertSame(['ADMIN'], $roles($users['mig.analista']));
        self::assertSame(['ADMIN', 'CONSULTA'], $roles($users['mig.aprobador']));
        self::assertSame(['ADMIN'], $roles($users['mig.doble']));
        self::assertSame(['CONSULTA'], $roles($users['mig.consulta']));
        self::assertSame(['ADMIN', 'CONSULTA', 'GOD'], array_column($o->fetchAll('SELECT codigo FROM roles ORDER BY codigo'), 'codigo'));
        self::assertSame(self::OTORGADOS, self::adminGrants($o, self::OTORGADOS));
        self::assertSame([], self::adminGrants($o, ['auditoria.ver', 'auditoria.verificar']));
        $audit = $o->fetchAll("SELECT entidad_id, usuario_id, origen, valor_anterior, valor_nuevo, contexto FROM auditoria
                                WHERE accion = 'usuarios.roles_consolidados' AND entidad_id = ANY(CAST(:ids AS text[])) ORDER BY entidad_id::bigint",
            ['ids' => '{' . implode(',', $users) . '}']);
        self::assertSame([(string) $users['mig.analista'], (string) $users['mig.aprobador'], (string) $users['mig.doble']], array_column($audit, 'entidad_id'),
            'Una fila por persona que tenía ANALISTA o APROBADOR; CONSULTA sola no se toca');
        $byId = array_column($audit, null, 'entidad_id');
        $pair = static fn (array $r): array => [json_decode((string) $r['valor_anterior'], true)['roles'], json_decode((string) $r['valor_nuevo'], true)['roles']];
        self::assertSame([['ANALISTA'], ['ADMIN']], $pair($byId[(string) $users['mig.analista']]));
        self::assertSame([['APROBADOR', 'CONSULTA'], ['ADMIN', 'CONSULTA']], $pair($byId[(string) $users['mig.aprobador']]));
        self::assertSame([['ADMIN', 'APROBADOR'], ['ADMIN']], $pair($byId[(string) $users['mig.doble']]));
        foreach ($audit as $r) {
            self::assertSame([null, 'SISTEMA', '0026'], [$r['usuario_id'], $r['origen'], json_decode((string) $r['contexto'], true)['migracion']]);
        }
    }
}
