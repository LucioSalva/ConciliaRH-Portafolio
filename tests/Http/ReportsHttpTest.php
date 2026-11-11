<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Reports\ReportRenderer;
use ConciliaRH\Domain\Reports\ReportService;
use ConciliaRH\Tests\Support\ConciliationScenario;
use ConciliaRH\Tests\Support\HttpTestCase;
use Dompdf\Dompdf;

/** Reportes, exportaciones, comparativo y cambios de identidad (FASE 6). Datos FICTICIOS. */
final class ReportsHttpTest extends HttpTestCase
{
    use ConciliationScenario;

    private function as(string $role): int
    {
        $user = strtolower($role) . '.rep' . bin2hex(random_bytes(2));
        $id = $this->createUser($user, [$role]);
        $this->loginAs($user);
        return $id;
    }

    private function owner0(): Database
    {
        return $this->committedOwner(static function (): void {
        });
    }

    private function export(int $c, string $tipo, string $formato, array $extra = []): Response
    {
        return $this->post('/reportes/exportar', ['tipo' => $tipo, 'conciliacion' => (string) $c, 'formato' => $formato] + $extra);
    }

    private static function content(Response $r): string
    {
        self::assertSame(200, $r->status(), substr(strip_tags($r->body()), 0, 300));
        $p = (string) $r->streamPath();
        self::assertTrue($r->isTempStream());
        self::assertFileExists($p);
        self::assertStringNotContainsString('/public/', $p, 'Los reportes nunca se generan en public/');
        $c = (string) file_get_contents($p);
        @unlink($p);
        return $c;
    }

    /** @return array<string,string> */
    private static function unzip(string $data): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $data);
        $z = new \ZipArchive();
        $z->open($tmp);
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $out[$z->getNameIndex($i)] = (string) $z->getFromIndex($i);
        }
        $z->close();
        unlink($tmp);
        return $out;
    }

    public function testCase11_12_13_14XlsxCsvPdfAndFormulaInjection(): void
    {
        $s = $this->conciliationScenario();
        // Un nombre malicioso que una hoja de cálculo interpretaría como fórmula
        $this->owner0()->execute("UPDATE empleados SET nombre_completo = '=HYPERLINK(\"http://x\",\"clic\")' WHERE id = :e", ['e' => $s['emp1']]);
        $this->as('ADMIN');
        self::assertStringContainsString('<h1>Lista de personas</h1>', $this->get('/reportes/ver?tipo=empleados&conciliacion=' . $s['conc'])->body());
        $r = $this->export($s['conc'], 'empleados', 'xlsx');
        self::assertStringStartsWith('application/vnd.openxmlformats', (string) $r->header('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="conciliarh-empleados-c\d+-v1-\d{8}-\d{6}\.xlsx"$/', (string) $r->header('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $r->header('Cache-Control'));
        $files = self::unzip(self::content($r));
        $sheet = $files['xl/worksheets/sheet1.xml'];
        self::assertStringNotContainsString('<f>', $sheet, 'Ninguna celda es fórmula');
        self::assertMatchesRegularExpression('/(\'|&apos;|&#039;)=HYPERLINK/', $sheet . ($files['xl/sharedStrings.xml'] ?? ''), 'Texto neutralizado');
        self::assertStringContainsString('ySplit', $sheet, 'Encabezados congelados');
        self::assertStringContainsString('<cols>', $sheet, 'Anchos de columna');
        self::assertStringContainsString('numFmtId="4"', $files['xl/styles.xml'], 'Formato monetario #,##0.00 (formato integrado 4 de Excel)');
        self::assertStringContainsString('Tesorería Municipal', $sheet, 'Encabezado institucional');
        self::assertArrayNotHasKey('xl/vbaProject.bin', $files, 'Sin macros');
        // CSV: UTF-8 con BOM, CRLF, neutralizado
        $csv = self::content($this->export($s['conc'], 'empleados', 'csv'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("\r\n", $csv);
        self::assertStringContainsString("\"'=HYPERLINK(\"\"http://x\"\",\"\"clic\"\")\"", $csv);
        self::assertStringContainsString(',-500.00,', $csv, 'Los importes negativos siguen siendo números');
        // PDF: resumen ejecutivo real (dompdf local)
        $pdf = self::content($this->export($s['conc'], 'resumen', 'pdf'));
        self::assertStringStartsWith('%PDF-', $pdf);
        // Un reporte sin versión PDF no se genera en PDF
        $this->export($s['conc'], 'empleados', 'pdf');
        self::assertStringContainsString('no tiene versión PDF', strip_tags($this->get('/reportes')->body()));
        $a = $this->auditRows('reportes.exportar');
        self::assertCount(3, $a);
        $ctx = json_decode((string) $a[0]['contexto'], true);
        self::assertSame(['empleados', 'xlsx', false], [$ctx['reporte'], $ctx['formato'], $ctx['sensible']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ctx['huella']);
        self::assertStringNotContainsString('HYPERLINK', json_encode($a), 'La auditoría no guarda el contenido');
    }

    public function testCase15_16PermissionsMaskingCsrfAndLimits(): void
    {
        $s = $this->conciliationScenario();
        $this->as('CONSULTA');
        self::assertSame(200, $this->get('/reportes/ver?tipo=empleados&conciliacion=' . $s['conc'])->status(), 'CONSULTA ve reportes');
        self::assertSame(403, $this->export($s['conc'], 'empleados', 'csv')->status(), 'CONSULTA no exporta (mínimo privilegio)');
        self::assertSame(403, $this->export($s['conc'], 'empleados', 'csv', ['sensible' => '1'])->status(), 'CONSULTA nunca exporta RFC/CURP');
        $role = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('SOLO_EXPORTA', 'Exporta sin datos sensibles') RETURNING id"));
        foreach (['dashboard.ver', 'conciliaciones.ver', 'reportes.ver', 'reportes.exportar'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $role, 'p' => $p]));
        }
        $this->as('SOLO_EXPORTA');
        $csv = self::content($this->export($s['conc'], 'empleados', 'csv'));
        self::assertStringNotContainsString(self::fakeRfc(1), $csv, 'Sin identificadores por omisión');
        self::assertSame(403, $this->export($s['conc'], 'empleados', 'csv', ['sensible' => '1'])->status(), 'Sin reportes.exportar_sensible no hay RFC/CURP');
        self::assertSame(405, $this->get('/reportes/exportar')->status());
        self::assertSame(403, $this->request('POST', '/reportes/exportar', ['tipo' => 'empleados', 'conciliacion' => (string) $s['conc'], 'formato' => 'csv'], [], false)->status(), 'CSRF');
        self::assertSame(404, $this->export($s['conc'], '../../etc/passwd', 'csv')->status());
        self::assertSame(404, $this->export($s['conc'], 'empleados', 'exe')->status());
        self::assertSame(404, $this->export($s['conc'] + 999, 'empleados', 'csv')->status());
        $this->as('ADMIN');
        $csv = self::content($this->export($s['conc'], 'empleados', 'csv', ['sensible' => '1']));
        self::assertStringContainsString(self::fakeRfc(1), $csv);
        $a = $this->auditRows('reportes.exportar');
        self::assertTrue(json_decode((string) end($a)['contexto'], true)['sensible']);
        // Límite por usuario y minuto
        $this->owner0()->execute("UPDATE configuracion SET valor = '2' WHERE clave = 'reportes.max_por_minuto'");
        try {
            self::content($this->export($s['conc'], 'grupos', 'csv'));
            self::assertSame(429, $this->export($s['conc'], 'grupos', 'csv')->status());
        } finally {
            $this->owner0()->execute("UPDATE configuracion SET valor = '10' WHERE clave = 'reportes.max_por_minuto'");
        }
    }

    public function testCase21_22_23SealsAndExecutiveContent(): void
    {
        $s = $this->conciliationScenario();
        self::assertSame('LOTE NO DECLARADO OFICIAL', ReportService::seal(['lote_oficial' => false, 'estado' => 'CERRADA']));
        self::assertSame('CONCILIACIÓN CERRADA', ReportService::seal(['lote_oficial' => true, 'estado' => 'CERRADA']));
        self::assertSame('EN REVISIÓN', ReportService::seal(['lote_oficial' => true, 'estado' => 'COMPLETADA_CON_INCIDENCIAS']));
        $this->as('ADMIN');
        $v = $this->get('/reportes/ver?tipo=resumen&conciliacion=' . $s['conc'])->body();
        self::assertStringContainsString('LOTE NO DECLARADO OFICIAL', $v);
        self::assertStringContainsString('Resultado provisional: el cotejo no es todavía el oficial de la quincena.', $v);
        self::assertStringContainsString('cotejo n.º 1', $v);
        foreach (['Cifras principales', 'RH contra Tesorería por grupo', 'Nómina por banco', 'Pensiones por grupo', 'Personas por estado'] as $t) {
            self::assertStringContainsString($t, $v);
        }
        self::assertStringNotContainsStringIgnoringCase('fraude', $v);
        $csv = self::content($this->export($s['conc'], 'resumen', 'csv'));
        self::assertStringContainsString('LOTE NO DECLARADO OFICIAL', $csv);
        self::assertStringContainsString('Huella del reporte', $csv);
    }

    public function testCase29PdfNeverFetchesRemoteOrLocalResources(): void
    {
        $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($srv);
        stream_set_blocking($srv, false);
        $port = (int) substr((string) stream_socket_get_name($srv, false), strrpos((string) stream_socket_get_name($srv, false), ':') + 1);
        $work = sys_get_temp_dir() . '/crh-pdf-' . bin2hex(random_bytes(4));
        mkdir($work);
        $html = '<html><body><p>ssrf</p><img src="http://127.0.0.1:' . $port . '/x.png"><img src="https://127.0.0.1:' . $port . '/y.png">'
            . '<img src="file:///etc/passwd"><img src="ftp://127.0.0.1:' . $port . '/z"><link rel="stylesheet" href="http://127.0.0.1:' . $port . '/c.css">'
            . '<div style="background:url(http://127.0.0.1:' . $port . '/bg.png)">x</div></body></html>';
        $pdf = new Dompdf(ReportRenderer::pdfOptions($work));
        $pdf->loadHtml($html);
        @$pdf->render();
        $out = $pdf->output();
        self::assertStringStartsWith('%PDF-', $out);
        self::assertFalse(@stream_socket_accept($srv, 0.5), 'dompdf no abrió ninguna conexión');
        self::assertStringNotContainsString('root:', $out, 'Nada del sistema de archivos en el PDF');
        fclose($srv);
        array_map('unlink', glob($work . '/*') ?: []);
        @rmdir($work);
        // Y el contenido del usuario en el PDF se escapa (no se interpreta como HTML)
        $s = $this->conciliationScenario();
        $this->as('ADMIN');
        $this->post('/conciliaciones/' . $s['conc'] . '/incidencias/' . $s['inc_menor'],
            ['estado' => 'EN_REVISION', 'motivo' => '<img src="http://127.0.0.1:' . $port . '/k.png"> revisión']);
        self::assertStringStartsWith('%PDF-', self::content($this->export($s['conc'], 'incidencias', 'pdf')));
    }

    public function testCase9_10IdentityChangesAndComparisonExplainsWhatChanged(): void
    {
        $s = $this->conciliationScenario();
        $db = $this->owner0();
        // v1: el pago interbancario del registro "sug" era PENDIENTE (fuente PENDIENTE_NOMINA)
        $this->as('ADMIN');
        self::assertStringNotContainsString('pago(s) con identificación nueva', $this->get('/conciliaciones/' . $s['conc'])->body());
        $v = (int) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]);
        $this->post('/asociaciones/tesoreria/' . $s['sug'] . '/asociar', ['numero_empleado' => '90001', 'version' => (string) $v, 'motivo' => 'Transferencia confirmada con Tesorería']);
        self::assertSame('ASOCIADO_MANUAL', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]));
        $page = $this->get('/conciliaciones/' . $s['conc'])->body();
        self::assertStringContainsString('Hubo cambios después de este cotejo', $page);
        self::assertStringContainsString('1 pago(s) con identificación nueva (1 persona(s))', $page);
        self::assertStringContainsString('Volver a cotejar', $page);
        // v2 (lo escribe el "motor"): la persona pasa de NO_PAGADO a CORRECTO porque ese pago ya es suyo
        $c2 = $this->secondRun($s);
        $cmp = $this->get('/conciliaciones/' . $s['conc'] . '/comparar/' . $c2)->body();
        self::assertStringContainsString('Se confirmó una transferencia previamente pendiente de identidad', $cmp);
        self::assertStringContainsString('asociación manual', $cmp);
        self::assertStringContainsString('Cotejo n.º 1 contra n.º 2', $cmp);
        // Exportación del comparativo; otra ejecución de OTRO lote → 404
        $csv = self::content($this->export($s['conc'], 'comparativo', 'csv', ['otra' => (string) $c2]));
        self::assertStringContainsString('Personas que cambiaron', $csv);
        self::assertSame(404, $this->get('/conciliaciones/' . $s['conc'] . '/comparar/999999')->status());
    }

    public function testReportScreenListsAndOptionsFollowPermissions(): void
    {
        $s = $this->conciliationScenario();
        $this->as('ADMIN');
        $r = $this->get('/reportes/ver?tipo=lista&lista=no_pagados&conciliacion=' . $s['conc']);
        self::assertSame(200, $r->status());
        self::assertStringContainsString('<h1>Personas no pagadas</h1>', $r->body());
        self::assertStringContainsString('<h1>Lista de personas</h1>', $this->get('/reportes/ver?tipo=lista&lista=empleados&conciliacion=' . $s['conc'])->body());
        foreach (['resumen', 'incidencias', 'comparativo', '../empleados', ''] as $lista) {
            self::assertSame(404, $this->get('/reportes/ver?tipo=lista&lista=' . rawurlencode($lista) . '&conciliacion=' . $s['conc'])->status(), "lista=$lista");
        }
        $index = $this->get('/reportes')->body();
        self::assertStringContainsString('<option value="pendientes">', $index);
        self::assertStringContainsString('name="tipo" value="incidencias"', $index);
        $role = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('SOLO_CIFRAS', 'Sólo cifras') RETURNING id"));
        foreach (['dashboard.ver', 'conciliaciones.ver', 'reportes.ver'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $role, 'p' => $p]));
        }
        $this->as('SOLO_CIFRAS');
        $index = $this->get('/reportes')->body();
        self::assertStringContainsString('<option value="no_pagados">', $index);
        self::assertStringNotContainsString('<option value="pendientes">', $index, 'Sin asociaciones.ver no se ofrece la lista de pagos pendientes de identificar');
        self::assertStringNotContainsString('name="tipo" value="incidencias"', $index, 'Sin incidencias.ver no se ofrece el reporte de incidencias');
        self::assertStringContainsString('name="tipo" value="pensiones"', $index);
        self::assertSame(403, $this->get('/reportes/ver?tipo=incidencias&conciliacion=' . $s['conc'])->status());
    }

    /** Segunda ejecución del mismo lote escrita como la dejaría el motor (v2). */
    private function secondRun(array $s): int
    {
        $db = $this->owner0();
        $u = $this->scn['u'];
        $c = (int) $db->fetchValue("INSERT INTO conciliaciones (periodo_id, lote_id, numero, estado, tolerancia, lote_oficial, solicitado_por)
                                    VALUES (:p, :l, 2, 'PENDIENTE', 0.01, false, :u) RETURNING id", ['p' => $s['periodo'], 'l' => $s['lote'], 'u' => $u]);
        $db->execute("UPDATE conciliaciones SET estado = 'EJECUTANDO', motor_version = 1 WHERE id = :id", ['id' => $c]);
        foreach ([[$s['emp1'], '90001', $s['rrhh1'], '8500.00', '0.00', 'CORRECTO', '8000.00'], [$s['emp2'], '90002', $s['rrhh2'], '8500.00', '0.00', 'CORRECTO', null]] as [$e, $n, $r, $pag, $dif, $st, $x]) {
            $db->execute("INSERT INTO conciliacion_empleados (conciliacion_id, empleado_id, grupo, numero_empleado, registro_rrhh_id, neto_esperado, pagado_santander,
                              pagado_interbancaria, pagado_total, diferencia, pagos, estado)
                          VALUES (:c, :e, 'POLICIA', :n, :r, 8500, :s, :i, :t, :d, 1, :st)",
                ['c' => $c, 'e' => $e, 'n' => $n, 'r' => $r, 's' => $x ?? '8500.00', 'i' => $x ? '500.00' : '0.00', 't' => $pag, 'd' => $dif, 'st' => $st]);
            $db->execute("INSERT INTO conciliacion_fuentes (conciliacion_id, empleado_id, tipo, registro_id, archivo_id, importe) VALUES (:c, :e, 'RRHH', :r, :a, 8500)",
                ['c' => $c, 'e' => $e, 'r' => $r, 'a' => $s['archivo_r']]);
        }
        $db->execute("INSERT INTO conciliacion_fuentes (conciliacion_id, empleado_id, tipo, registro_id, archivo_id, importe) VALUES (:c, :e, 'TESORERIA_NOMINA', :r, :a, 700)",
            ['c' => $c, 'e' => $s['emp1'], 'r' => $s['sug'], 'a' => $s['archivo_t']]);
        $bloque = json_encode(['TOTAL' => ['rrhh' => ['neto' => '17000.00', 'registros' => 2], 'tesoreria_nomina' => ['total' => '17000.00', 'identificado' => '17000.00',
            'pendiente_identidad' => '0.00', 'por_banco' => []], 'pensiones' => ['retenido' => '0.00', 'dispersado' => '0.00', 'pendiente_identidad' => '0.00'],
            'diferencia_global_nomina' => '0.00', 'empleados' => ['CORRECTO' => 2]]]);
        $db->execute("UPDATE conciliaciones SET estado = 'COMPLETADA', huella_entradas = :h, totales = CAST(:t AS jsonb), resumen = CAST(:r AS jsonb), terminada_at = now()
                      WHERE id = :id", ['h' => str_repeat('b', 64), 't' => $bloque,
            'r' => json_encode(['por_estado' => ['CORRECTO' => 2], 'pct_conciliado' => '100.00', 'correctos' => 2, 'evaluables' => 2]), 'id' => $c]);
        return $c;
    }
}
