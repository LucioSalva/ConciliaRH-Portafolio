<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\ConciliationScenario;
use ConciliaRH\Tests\Support\HttpTestCase;

/**
 * Contrato de la interfaz (docs/DESIGN_SYSTEM.md §11, §19, §21) sobre el HTML real que sirve la aplicación:
 *  · navegación: cada enlace del menú lateral responde 200 para quien lo ve; lo que el rol no puede usar ni se muestra ni se abre;
 *  · CSP: sin estilos ni scripts en línea ni manejadores on*; sólo recursos propios;
 *  · semántica: un <h1>, tablas con <caption> y encabezados con scope, tablas en tarjetas (.table-stack) con <thead>,
 *    imágenes con alt, sin ids duplicados, errores de campo enlazados;
 *  · estados: todo distintivo sale de status_badge() (icono + texto + title), nunca sólo color;
 *  · escape: los datos de las personas llegan escapados también a las tablas en tarjetas. Datos ficticios.
 */
final class UiContractHttpTest extends HttpTestCase
{
    use ConciliationScenario;

    /** @return list<string> */
    private function mainRoutes(array $s): array
    {
        $c = $s['conc'];
        return ['/', '/periodos', '/periodos/nuevo', '/periodos/' . $s['periodo'], '/lotes/' . $s['lote'], '/importaciones', '/importaciones/nueva',
            '/importaciones/' . $s['archivo_r'], '/empleados', '/empleados/' . $s['emp1'], '/empleados/' . $s['emp1'] . '?tab=rrhh',
            '/empleados/' . $s['emp1'] . '?tab=cuentas', '/asociaciones', '/asociaciones/rrhh/' . $s['rrhh1'], '/revision-masiva', '/beneficiarios',
            '/empleados/duplicados', '/empleados/fusiones', '/incidencias-identidad', '/conceptos', '/conciliaciones', '/conciliaciones/' . $c,
            '/conciliaciones/' . $c . '/empleados', '/conciliaciones/' . $c . '/empleados/' . $s['emp1'], '/conciliaciones/' . $c . '/incidencias',
            '/reportes', '/reportes/ver?conciliacion=' . $c . '&tipo=pensiones', '/reportes/programados', '/reportes/programados/nuevo',
            '/reportes/generados', '/usuarios', '/usuarios/nuevo', '/roles', '/auditoria', '/catalogos', '/procesos', '/configuracion', '/cuenta/password',
            // FASE 8
            '/notificaciones', '/revision-pensiones', '/empleados/solicitudes-fusion', '/sistema/salud', '/auditoria?resultado=FALLIDO&modulo=auth'];
    }

    public function testEveryMainScreenHonoursTheUiContract(): void
    {
        $s = $this->conciliationScenario();
        $this->createUser('god.ui', ['GOD']);
        $this->loginAs('god.ui');
        foreach ($this->mainRoutes($s) as $path) {
            $r = $this->get($path);
            self::assertSame(200, $r->status(), $path);
            $html = $r->body();
            $this->assertCspSafe($html, $path);
            $this->assertSemantics($html, $path);
            $this->assertBadgesComeFromHelper($html, $path);
        }
    }

    public function testSidebarNavigationMatchesPermissions(): void
    {
        $this->conciliationScenario();
        $this->createUser('god.nav', ['GOD']);
        $this->loginAs('god.nav');
        $links = $this->sidebarLinks($this->get('/')->body());
        self::assertGreaterThan(15, count($links), 'GOD ve todo el menú');
        foreach ($links as $href) {
            self::assertSame(200, $this->get($href)->status(), "Enlace del menú roto: $href");
        }
        preg_match('/<aside id="sidebar".*?<\/aside>/s', $this->get('/reportes/programados')->body(), $aside);
        self::assertSame(1, substr_count($aside[0], 'aria-current="page"'), 'Un solo elemento activo en el menú (el más específico)');

        $this->post('/logout');
        $this->createUser('consulta.nav', ['CONSULTA']);
        $this->loginAs('consulta.nav');
        $mine = $this->sidebarLinks($this->get('/')->body());
        foreach (['/usuarios', '/roles', '/configuracion'] as $admin) {
            self::assertNotContains($admin, $mine, "CONSULTA no ve $admin en el menú");
        }
        foreach (['/usuarios', '/roles', '/configuracion'] as $admin) {
            self::assertSame(403, $this->get($admin)->status(), "Ocultar el enlace no basta: $admin valida el permiso en el servidor");
        }
        foreach ($mine as $href) {
            self::assertSame(200, $this->get($href)->status(), "CONSULTA ve un enlace que no puede abrir: $href");
        }
    }

    /**
     * Revisión funcional (FASE 8 §89–90): cada enlace interno que aparece en las pantallas principales abre para GOD
     * (sin 404 ni 500). Sólo GET: los botones de acción son formularios POST y tienen sus propias pruebas.
     */
    public function testNoBrokenInternalLinksOnMainScreens(): void
    {
        $s = $this->conciliationScenario();
        $this->createUser('god.links', ['GOD']);
        $this->loginAs('god.links');
        $seen = $this->crawl($this->mainRoutes($s));
        self::assertGreaterThan(40, count($seen), 'Se recorrió una muestra suficiente de enlaces');
        foreach ($seen as $href => $from) {
            self::assertSame(200, $this->get($href)->status(), "Enlace roto o que redirige en $from: $href");
        }
        // Con permisos reducidos: todo enlace que se MUESTRA (menú, migas, tablas) abre; nada lleva a un 403
        $this->post('/logout');
        $this->createUser('consulta.links', ['CONSULTA']);
        $this->loginAs('consulta.links');
        $visible = array_values(array_filter($this->mainRoutes($s), fn (string $p): bool => $this->get($p)->status() === 200));
        self::assertGreaterThan(5, count($visible));
        foreach ($this->crawl($visible) as $href => $from) {
            self::assertSame(200, $this->get($href)->status(), "CONSULTA ve en $from un enlace que no puede abrir: $href");
        }
    }

    /** @param list<string> $pages @return array<string,string> enlace interno GET => página donde aparece */
    private function crawl(array $pages): array
    {
        $seen = [];
        foreach ($pages as $page) {
            preg_match_all('/<a\b[^>]*\shref="(\/[^"#]*)"/', $this->get($page)->body(), $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
                // Descargas y salida de sesión no se siguen: son POST en la interfaz o cambian el estado
                if (!isset($seen[$href]) && !preg_match('#^/(logout|assets/|favicon)|/descargar#', $href)) {
                    $seen[$href] = $page;
                }
            }
        }
        return $seen;
    }

    /** Las tablas en tarjetas (estrategia A) obtienen las etiquetas del <th>: el dato de la persona sigue escapado. */
    public function testResponsiveTablesKeepEscapingAndHeaders(): void
    {
        $s = $this->conciliationScenario();
        $this->createUser('god.xss', ['GOD']);
        $this->loginAs('god.xss');
        $results = $this->get('/conciliaciones/' . $s['conc'] . '/empleados')->body();
        self::assertMatchesRegularExpression('/<table[^>]*class="[^"]*table-stack[^"]*"[^>]*>\s*<caption/', $results);
        self::assertStringNotContainsString('data-label=', $results, 'Las etiquetas no se escriben a mano: las genera app.js desde el <th>');
        self::assertStringContainsString('<th scope="col" class="num">Diferencia</th>', $results);
        // Un dato hostil en una tabla en tarjetas (usuarios) sale escapado en la celda y en los atributos
        $this->createUser('ana.xss', ['CONSULTA']);
        self::web()->execute("UPDATE usuarios SET nombre = '<img src=x onerror=alert(1)>\"' WHERE username = 'ana.xss'");
        $html = $this->get('/usuarios')->body();
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;&quot;', $html);
        self::assertStringContainsString('table-stack', $html);
    }

    private function assertCspSafe(string $html, string $path): void
    {
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "$path: atributo style (CSP style-src 'self')");
        self::assertDoesNotMatchRegularExpression('/<style\b/i', $html, "$path: <style> en línea");
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "$path: manejador on* en línea");
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, "$path: script en línea");
        self::assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#i', $html, "$path: recurso externo (CDN, fuentes)");
        self::assertStringNotContainsString('fonts.googleapis', $html, $path);
    }

    private function assertSemantics(string $html, string $path): void
    {
        self::assertSame(1, preg_match_all('/<h1\b/', $html), "$path: exactamente un <h1>");
        preg_match_all('/<table\b[^>]*>(.*?)<\/table>/s', $html, $tables, PREG_SET_ORDER);
        foreach ($tables as [$full, $inner]) {
            $named = str_contains($full, 'aria-labelledby=') || preg_match('/^\s*<caption/', $inner) === 1 || str_contains($inner, '<caption');
            self::assertTrue($named, "$path: tabla sin <caption>: " . substr($full, 0, 120));
            preg_match_all('/<thead>.*?<\/thead>/s', $inner, $heads);
            foreach ($heads[0] as $thead) {
                self::assertSame(preg_match_all('/<th\b/', $thead), preg_match_all('/<th\b[^>]*scope="col"/', $thead), "$path: <th> sin scope");
            }
            if (str_contains(substr($full, 0, 300), 'table-stack')) {
                self::assertStringContainsString('<thead', $inner, "$path: .table-stack necesita <thead> (de ahí salen las etiquetas)");
            }
        }
        self::assertDoesNotMatchRegularExpression('/<img(?![^>]*\salt=)[^>]*>/', $html, "$path: <img> sin alt");
        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        $dups = array_keys(array_filter(array_count_values($ids[1]), static fn ($n) => $n > 1));
        self::assertSame([], $dups, "$path: ids duplicados");
        preg_match_all('/aria-describedby="([^"]+)"/', $html, $refs);
        foreach ($refs[1] as $list) {
            foreach (preg_split('/\s+/', trim($list)) as $ref) {
                self::assertContains($ref, $ids[1], "$path: aria-describedby apunta a un id inexistente ($ref)");
            }
        }
    }

    /** Cualquier distintivo .status lleva title e icono: es la salida de status_badge(), no HTML escrito a mano. */
    private function assertBadgesComeFromHelper(string $html, string $path): void
    {
        preg_match_all('/<span class="status status--[a-z]+"([^>]*)>(.{0,120})/', $html, $m, PREG_SET_ORDER);
        foreach ($m as [$tag, $attrs, $rest]) {
            self::assertStringContainsString('title="', $attrs, "$path: distintivo sin title: $tag");
            self::assertStringStartsWith('<i class="bi bi-', $rest, "$path: distintivo sin icono (depende sólo del color): $tag");
        }
    }

    /** @return list<string> */
    private function sidebarLinks(string $html): array
    {
        self::assertSame(1, preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $aside));
        preg_match_all('/<a class="sidebar__link[^"]*" href="([^"]+)"/', $aside[0], $m);
        return $m[1];
    }
}
