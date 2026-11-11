<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\AnalysisScenario;
use ConciliaRH\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AnalysisHttpTest extends HttpTestCase
{
    use AnalysisScenario;

    public function testOperatorSeesFullAnalysisButNeverPersonData(): void
    {
        $s = $this->analyzedFile();
        $this->createUser('operador.ver', ['ADMIN']);
        $this->loginAs('operador.ver');
        $html = $this->get('/importaciones/' . $s['archivo'])->body();
        foreach (['Análisis del archivo', 'TESORERIA_BBVA', 'Confianza alta', 'Controles del archivo', 'TOTAL_IMPORTE', '3,000.50',
                  'Hojas del libro (2)', 'Version A', 'Columnas de la hoja principal', 'NUM_EMPLEADO', 'Decisión manual',
                  'Reprocesar análisis', 'Comparar con otro archivo'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        self::assertStringNotContainsString('PERSONA FICTICIA SECRETA', $html, 'Sin vista previa de datos de personas');
    }

    public function testConsultaSeesLimitedAnalysisOnly(): void
    {
        $s = $this->analyzedFile();
        $this->createUser('consulta.an', ['CONSULTA']);
        $this->loginAs('consulta.an');
        $html = $this->get('/importaciones/' . $s['archivo'])->body();
        self::assertStringContainsString('Análisis del archivo', $html);
        self::assertStringContainsString('Controles del archivo', $html);
        foreach (['Hojas del libro', 'Columnas de la hoja principal', 'Decisión manual', 'Reprocesar análisis', 'Comparar con otro archivo'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        $api = json_decode($this->get('/api/importaciones/' . $s['archivo'] . '/analisis', ['Accept' => 'application/json'])->body(), true);
        self::assertSame(['TESORERIA_BBVA', 'CUADRA'], [$api['data']['analisis']['perfil'], $api['data']['analisis']['controles'][0]['estado']]);
        self::assertStringNotContainsString('PERSONA', json_encode($api));
    }

    public function testManualReviewNoticeAndSheetSelectionFlow(): void
    {
        $s = $this->analyzedFile(['estado' => 'REVISION_MANUAL']);
        $this->createUser('operador.rev', ['ADMIN']);
        $this->loginAs('operador.rev');
        $html = $this->get('/importaciones/' . $s['archivo'])->body();
        self::assertStringContainsString('Requiere revisión manual', $html);
        self::assertStringContainsString('AMBIGUEDAD_HOJAS', $html);
        $r = $this->post('/importaciones/' . $s['archivo'] . '/analisis/hoja', ['hoja' => '1', 'motivo' => 'La hoja B es la vigente']);
        self::assertSame(303, $r->status());
        $f = self::web()->fetchOne('SELECT hoja_manual_nombre, p.tipo FROM archivos_importados a JOIN procesos p ON p.id = a.proceso_id WHERE a.id = :id', ['id' => $s['archivo']]);
        self::assertSame(['Version B', 'ANALIZAR_ARCHIVO'], [$f['hoja_manual_nombre'], $f['tipo']]);
        self::assertCount(1, $this->auditRows('analisis.seleccionar_hoja'));
        // Sin motivo suficiente se rechaza con mensaje
        $r = $this->post('/importaciones/' . $s['archivo'] . '/analisis/perfil', ['perfil' => 'auto', 'motivo' => '']);
        self::assertSame(303, $r->status());
    }

    public function testActionsRequireCsrf(): void
    {
        $s = $this->analyzedFile();
        $this->createUser('operador.csrf3', ['ADMIN']);
        $this->loginAs('operador.csrf3');
        self::assertSame(403, $this->request('POST', '/importaciones/' . $s['archivo'] . '/analisis/reprocesar', [], [], false)->status());
        self::assertCount(0, $this->auditRows('analisis.reprocesar'));
    }

    public function testComparePage(): void
    {
        $a = $this->analyzedFile(['staging' => ['r1', 'r2']]);
        $b = $this->analyzedFile(['staging' => ['r1', 'r2']]);
        $this->createUser('admin.cmp', ['ADMIN']);
        $this->loginAs('admin.cmp');
        $html = $this->get('/importaciones/' . $a['archivo'] . '/comparar?con=' . $b['archivo'])->body();
        self::assertStringContainsString('Registros en común', $html);
        self::assertStringContainsString('Misma', $html, 'Misma huella lógica');
        self::assertStringContainsString('Distinto', $html, 'Distinto binario');
        self::assertStringNotContainsString('PERSONA FICTICIA SECRETA', $html);
        self::assertStringContainsString('Elija un archivo distinto', $this->get('/importaciones/' . $a['archivo'] . '/comparar?con=' . $a['archivo'])->body());
        self::assertSame(404, $this->get('/importaciones/' . $a['archivo'] . '/comparar?con=999999999')->status());
        self::assertStringContainsString('entero positivo', $this->get('/importaciones/' . $a['archivo'] . '/comparar?con=abc')->body());
    }

    /** @return list<array{string,string,string,int}> */
    public static function matrix(): array
    {
        return [
            ['CONSULTA', 'POST', '/analisis/reprocesar', 403],
            ['CONSULTA', 'POST', '/analisis/hoja', 403],
            ['CONSULTA', 'POST', '/analisis/perfil', 403],
            ['CONSULTA', 'GET', '/comparar', 403],
            ['CONSULTA', 'GET', '/api-analisis', 200],
            ['ADMIN', 'POST', '/analisis/reprocesar', 303],
            ['ADMIN', 'POST', '/analisis/hoja', 303],
            ['ADMIN', 'POST', '/analisis/perfil', 303],
            ['ADMIN', 'GET', '/comparar', 200],
            ['GOD', 'GET', '/comparar', 200],
        ];
    }

    #[DataProvider('matrix')]
    public function testPermissionMatrix(string $role, string $method, string $suffix, int $expected): void
    {
        $s = $this->analyzedFile(['estado' => 'REVISION_MANUAL']);
        $user = strtolower($role) . '.amx';
        $this->createUser($user, [$role]);
        $this->loginAs($user);
        $path = $suffix === '/api-analisis' ? '/api/importaciones/' . $s['archivo'] . '/analisis' : '/importaciones/' . $s['archivo'] . $suffix;
        $perfil = (string) self::web()->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'TESORERIA_BBVA'");
        $r = $method === 'GET' ? $this->get($path, ['Accept' => 'application/json']) : $this->post($path, ['hoja' => '0', 'perfil' => $perfil, 'motivo' => 'Motivo de prueba suficiente']);
        self::assertSame($expected, $r->status(), "$role $method $suffix");
    }
}
