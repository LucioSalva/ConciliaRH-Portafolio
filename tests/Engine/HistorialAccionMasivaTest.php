<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Tests\Support\EngineTestCase;

final class HistorialAccionMasivaTest extends EngineTestCase
{
    public function testWorkerRecordsHistoryWithGenericPlansWithoutReadingBulkActions(): void
    {
        $proceso = self::enqueue();
        $worker = self::workerDb();
        $worker->pdo()->beginTransaction();
        try {
            $worker->execute('SET LOCAL plan_cache_mode = force_generic_plan');
            self::assertFalse((bool) $worker->fetchValue("SELECT has_table_privilege('acciones_masivas', 'SELECT')"));
            for ($i = 1; $i <= 8; $i++) {
                $worker->execute("INSERT INTO asociaciones_historial (registro_tipo, registro_id, accion, estado_nuevo, proceso_id)
                                  VALUES ('TESORERIA', :r, 'TRASLADO', 'NO_IDENTIFICADO', :p)", ['r' => $i, 'p' => $proceso]);
            }
            self::assertSame(8, (int) $worker->fetchValue('SELECT count(*) FROM asociaciones_historial WHERE proceso_id = :p', ['p' => $proceso]));
        } finally {
            $worker->pdo()->rollBack();
            self::owner()->execute('DELETE FROM procesos WHERE id = :id', ['id' => $proceso]);
        }
    }
}
