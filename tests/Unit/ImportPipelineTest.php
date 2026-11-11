<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use ConciliaRH\Domain\Imports\ImportService;
use PHPUnit\Framework\TestCase;

/** Canal de una importación (FASE 8 §61): cada estado del archivo se ubica en el paso correcto. */
final class ImportPipelineTest extends TestCase
{
    /** @return array<string,string> clave del paso => estado */
    private static function states(string $e, bool $verif, ?int $registros = null, int $conc = 0): array
    {
        return array_column(ImportService::pipelineSteps($e, $verif, $registros, $conc), 'estado', 'clave');
    }

    public function testHappyPathAndCurrentStep(): void
    {
        self::assertSame(['RECIBIDO' => 'current', 'VERIFICADO' => 'pending', 'ANALIZADO' => 'pending', 'NORMALIZADO' => 'pending', 'CONCILIADO' => 'pending'],
            self::states('RECIBIDO', false));
        self::assertSame('current', self::states('VERIFICANDO', false)['VERIFICADO']);
        self::assertSame(['done', 'done', 'current'], array_slice(array_values(self::states('VERIFICADO', true)), 0, 3));
        self::assertSame('current', self::states('ANALIZADO', true)['NORMALIZADO']);
        self::assertSame('current', self::states('ANALIZADO', true, 6)['CONCILIADO']);
        self::assertSame(array_fill_keys(['RECIBIDO', 'VERIFICADO', 'ANALIZADO', 'NORMALIZADO', 'CONCILIADO'], 'done'), self::states('ANALIZADO', true, 6, 1));
    }

    public function testErrorsLandOnTheStepThatFailed(): void
    {
        $verifFailed = self::states('ERROR', false);
        self::assertSame(['error', 'pending'], [$verifFailed['VERIFICADO'], $verifFailed['ANALIZADO']]);
        $analysisFailed = self::states('ERROR', true);
        self::assertSame(['done', 'error'], [$analysisFailed['VERIFICADO'], $analysisFailed['ANALIZADO']], 'Verificado y luego falló el análisis');
        self::assertSame('error', self::states('RECHAZADO', true)['VERIFICADO'], 'Rechazado nunca cuenta como verificado');
    }

    public function testManualReviewIsADecisionNotAnError(): void
    {
        self::assertSame('warning', self::states('REVISION_MANUAL', true)['ANALIZADO']);
    }

    public function testReanalysisHidesStaleNormalizationAndConciliation(): void
    {
        $s = self::states('ANALIZANDO', true, 6, 2);
        self::assertSame(['current', 'pending', 'pending'], [$s['ANALIZADO'], $s['NORMALIZADO'], $s['CONCILIADO']]);
    }

    public function testAnnulledFileStopsTheChannel(): void
    {
        $steps = ImportService::pipelineSteps('ANULADO', true, 6, 3);
        $s = array_column($steps, 'estado', 'clave');
        self::assertSame(['done', 'done', 'stopped', 'stopped', 'stopped'], array_values($s));
        self::assertSame('Archivo anulado', $steps[0]['detalle']);
        self::assertNotContains('pending', $s);
    }
}
