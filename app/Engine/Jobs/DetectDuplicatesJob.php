<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\Duplicates;
use ConciliaRH\Engine\Normalization\Reassociation;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobHandler;

final class DetectDuplicatesJob implements JobHandler
{
    public function run(JobContext $ctx): array
    {
        $ctx->progress(10, 'VALIDANDO', 'Buscando posibles duplicados');
        $res = $ctx->db->transaction(static function (Database $db) use ($ctx): array {
            $db->fetchValue('SELECT pg_advisory_xact_lock(:k)', ['k' => Reassociation::LOCK_KEY]);
            return Duplicates::detect($db, $ctx->job->id);
        });
        return [...$res, '_estado' => 'COMPLETADO'];
    }
}
