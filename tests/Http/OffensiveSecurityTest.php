<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\ConciliationScenario;
use ConciliaRH\Tests\Support\HttpTestCase;

/**
 * Seguridad ofensiva sobre las funciones de la FASE 6: inyección SQL (booleana, UNION, temporal, por error y de segundo
 * orden), XSS almacenado, IDOR y escalamiento de privilegios en /reportes, /beneficiarios, /revision-masiva y el comparativo.
 */
final class OffensiveSecurityTest extends HttpTestCase
{
    use ConciliationScenario;

    private const SQLI = [
        "' OR '1'='1", '1 OR 1=1', "1' OR '1'='1' --", "' UNION SELECT username, password_hash FROM usuarios--",
        '1 UNION SELECT NULL,NULL,NULL--', "1; SELECT pg_sleep(3)--", "'||(SELECT pg_sleep(3))||'", "1) OR pg_sleep(3) IS NULL--",
        "1' AND 1=CAST((SELECT version()) AS int)--", "%' AND 1=1 AND '%'='", "\\'; DROP TABLE usuarios;--", "1e999", '-1', "0x41", '9223372036854775808',
    ];

    private function as(string $role): int
    {
        $user = strtolower($role) . '.sec' . bin2hex(random_bytes(2));
        $id = $this->createUser($user, [$role]);
        $this->loginAs($user);
        return $id;
    }

    /** $payload: lo que el atacante envió (puede reflejarse ESCAPADO en el formulario de búsqueda; eso no es una fuga). */
    private static function assertSafe(string $label, float $t0, int $status, string $body, string $payload = ''): void
    {
        self::assertLessThan(2.5, microtime(true) - $t0, "$label: sin retardo (pg_sleep no se ejecutó)");
        self::assertNotSame(500, $status, "$label: sin error interno");
        foreach (['SQLSTATE', 'syntax error', 'PostgreSQL', '$argon2', 'password_hash', 'pg_sleep'] as $leak) {
            if (!str_contains($payload, $leak)) {
                self::assertStringNotContainsString($leak, $body, "$label: no filtra «{$leak}»");
            }
        }
        self::assertStringNotContainsString('$argon2', $body, "$label: nunca un hash de contraseña");
    }

    public function testCase25SqlInjectionOnNewRoutesIsNotReproducible(): void
    {
        $s = $this->conciliationScenario();
        $this->as('ADMIN');
        $users = (int) self::web()->fetchValue('SELECT count(*) FROM usuarios');
        foreach (self::SQLI as $p) {
            $q = rawurlencode($p);
            foreach (["/revision-masiva?q=$q", "/revision-masiva?estado=$q&categoria=$q&banco=$q&grupo=$q", "/revision-masiva?periodo=$q&lote=$q&min=$q&max=$q",
                      "/revision-masiva?grupo_cuenta=$q&confianza=$q&orden=$q", "/beneficiarios?q=$q", "/beneficiarios?estado=$q&origen=$q&banco=$q",
                      "/beneficiarios/1?tab=$q", "/reportes/ver?tipo=$q&conciliacion={$s['conc']}", "/reportes/ver?tipo=resumen&conciliacion=$q",
                      "/reportes/ver?tipo=comparativo&conciliacion={$s['conc']}&otra=$q", "/conciliaciones/{$s['conc']}/empleados?q=$q&area=$q&min=$q"] as $url) {
                $t0 = microtime(true);
                $r = $this->get($url);
                self::assertSafe("GET $url", $t0, $r->status(), $r->body(), $p);
                // Booleana: un OR siempre verdadero NO debe ampliar resultados
                if (str_starts_with($url, '/beneficiarios?q=')) {
                    self::assertStringNotContainsString('BEN-0', $r->body(), 'La búsqueda con inyección no devuelve beneficiarios');
                }
            }
            foreach ([['/reportes/exportar', ['tipo' => $p, 'conciliacion' => (string) $s['conc'], 'formato' => 'csv']],
                      ['/reportes/exportar', ['tipo' => 'grupos', 'conciliacion' => $p, 'formato' => $p]],
                      ['/revision-masiva/previsualizar', ['accion' => $p, 'items' => [$p, "{$s['sug']}:$p"]]],
                      ['/revision-masiva/ejecutar', ['accion' => 'MARCAR_NO_IDENTIFICADO', 'items' => ["{$s['sug']}:0:$p"], 'token' => $p, 'motivo' => $p]]] as [$url, $body]) {
                $t0 = microtime(true);
                $r = $this->post($url, $body);
                self::assertSafe("POST $url", $t0, $r->status(), $r->body(), $p);
            }
        }
        self::assertSame($users, (int) self::web()->fetchValue('SELECT count(*) FROM usuarios'), 'Nada se borró ni se creó');
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM acciones_masivas'));
    }

    public function testCase25SecondOrderAndCase26StoredXss(): void
    {
        $s = $this->conciliationScenario();
        $this->as('ADMIN');
        $xss = '<script>alert(1)</script><img src=x onerror=alert(2)>"\'><svg/onload=alert(3)>';
        $sqli = "'); DELETE FROM usuarios; --";
        // Motivo de incidencia (se muestra en incidencias y en reportes)
        $this->post('/conciliaciones/' . $s['conc'] . '/incidencias/' . $s['inc_menor'], ['estado' => 'EN_REVISION', 'motivo' => $xss . $sqli]);
        // Nombre del beneficiario / observaciones (el nombre viene del archivo: se simula uno malicioso)
        $db = $this->committedOwner(static function (): void {
        });
        $db->execute("UPDATE empleados SET nombre_completo = :n WHERE id = :e", ['n' => $xss, 'e' => $s['emp2']]);
        foreach (['/conciliaciones/' . $s['conc'] . '/incidencias', '/conciliaciones/' . $s['conc'] . '/empleados', '/reportes/ver?tipo=incidencias&conciliacion=' . $s['conc'],
                  '/reportes/ver?tipo=empleados&conciliacion=' . $s['conc'], '/revision-masiva', '/'] as $url) {
            $b = $this->get($url)->body();
            self::assertStringNotContainsString('<script>alert(1)', $b, $url);
            self::assertStringNotContainsString('onerror=alert(2)>', $b, $url);
            self::assertStringNotContainsString('<svg/onload', $b, $url);
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->get('/reportes/ver?tipo=empleados&conciliacion=' . $s['conc'])->body());
        // El texto guardado se exporta neutralizado y no se re-ejecuta como SQL (segundo orden)
        $r = $this->post('/reportes/exportar', ['tipo' => 'incidencias', 'conciliacion' => (string) $s['conc'], 'formato' => 'csv']);
        self::assertSame(200, $r->status());
        @unlink((string) $r->streamPath());
        self::assertGreaterThan(0, (int) self::web()->fetchValue('SELECT count(*) FROM usuarios'));
    }

    public function testCase27IdorAndPrivilegeEscalation(): void
    {
        $s = $this->conciliationScenario();
        $this->as('CONSULTA');
        foreach (['/revision-masiva/previsualizar', '/revision-masiva/ejecutar', '/beneficiarios/1/observaciones', '/beneficiarios/1/trabajadores/1/desactivar',
                  '/beneficiarios/1/cuentas/1/revelar', '/conciliaciones/' . $s['conc'] . '/cerrar', '/conciliaciones/' . $s['conc'] . '/invalidar'] as $url) {
            self::assertSame(403, $this->post($url, ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => [], 'motivo' => 'escalamiento'])->status(), $url);
        }
        // Rutas inexistentes de rotación de llaves / administración de cifrado: nunca web
        foreach (['/rotar-llave', '/cuentas/rotar', '/admin/keys', '/api/rotate-account-key'] as $url) {
            self::assertSame(404, $this->post($url)->status(), $url);
        }
        $this->as('ADMIN');
        self::assertSame(404, $this->get('/reportes/ver?tipo=resumen&conciliacion=999999')->status());
        self::assertSame(404, $this->get('/conciliaciones/' . $s['conc'] . '/comparar/999999')->status());
        self::assertSame(404, $this->get('/revision-masiva/acciones/999999')->status());
        self::assertSame(404, $this->get('/beneficiarios/999999')->status());
        // Un reporte de incidencias exige también incidencias.ver
        $role = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('SOLO_REPORTES', 'Sólo reportes') RETURNING id"));
        foreach (['dashboard.ver', 'conciliaciones.ver', 'reportes.ver', 'reportes.exportar'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $role, 'p' => $p]));
        }
        $this->createUser('solo.reportes', ['SOLO_REPORTES']);
        $this->loginAs('solo.reportes');
        self::assertSame(403, $this->get('/reportes/ver?tipo=incidencias&conciliacion=' . $s['conc'])->status());
        self::assertSame(403, $this->post('/reportes/exportar', ['tipo' => 'incidencias', 'conciliacion' => (string) $s['conc'], 'formato' => 'pdf'])->status());
    }
}
