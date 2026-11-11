<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Json;

final class WorkerAudit
{
    public static function record(Database $db, string $action, string $entity, int|string $entityId, mixed $before, mixed $after,
                                  mixed $context): void
    {
        $db->execute(
            "INSERT INTO auditoria (origen, accion, entidad, entidad_id, valor_anterior, valor_nuevo, contexto, hash)
             VALUES ('WORKER', :a, :e, :i, CAST(:b AS jsonb), CAST(:n AS jsonb), CAST(:c AS jsonb), repeat('0', 64))",
            ['a' => $action, 'e' => $entity, 'i' => (string) $entityId, 'b' => $before === null ? null : Json::encode($before),
             'n' => $after === null ? null : Json::encode($after), 'c' => Json::encode($context)]);
    }
}
