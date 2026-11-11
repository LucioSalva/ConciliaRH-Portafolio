<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Files\ContainerVerifier;
use ConciliaRH\Engine\Files\Limits;
use ConciliaRH\Engine\Files\StoredOriginal;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobFailure;
use ConciliaRH\Engine\Worker\JobHandler;
use RuntimeException;

final class VerifyFileJob implements JobHandler
{
    public function run(JobContext $ctx): array
    {
        $id = (int) $ctx->params()['archivo_id'];
        $db = $ctx->db;
        $row = $db->fetchOne('SELECT id, estado, ruta_relativa, sha256, tamano_bytes, extension FROM archivos_importados WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw JobFailure::permanent('El archivo a verificar no existe.', 'ARCHIVO_INEXISTENTE');
        }
        if ($row['estado'] === 'ANULADO') {
            return ['archivo_id' => $id, 'omitido' => 'El archivo fue anulado.'];
        }
        if (!in_array($row['estado'], ['RECIBIDO', 'VERIFICANDO'], true)) {
            return ['archivo_id' => $id, 'omitido' => "El archivo ya está en estado {$row['estado']}."];
        }
        $ctx->check();
        $db->execute("UPDATE archivos_importados SET estado = 'VERIFICANDO' WHERE id = :id AND estado = 'RECIBIDO'", ['id' => $id]);
        $ctx->progress(5, 'VALIDANDO', 'Verificando el original almacenado');
        try {
            $path = StoredOriginal::resolve($ctx->settings->storagePath, (string) $row['ruta_relativa']);
        } catch (RuntimeException $e) {
            throw JobFailure::permanent('La ruta registrada del archivo no es válida.', 'ALMACENAMIENTO_INVALIDO', $e->getMessage());
        }
        if (!file_exists($path)) {
            throw JobFailure::permanent('El original no se encuentra en el almacenamiento.', 'ORIGINAL_AUSENTE');
        }
        $verdict = (new ContainerVerifier())->verify($path, (string) $row['sha256'], (int) $row['tamano_bytes'], (string) $row['extension'],
            Limits::fromDatabase($db), static fn (float $p, string $m) => $ctx->progress($p, 'VALIDANDO', $m), $ctx->check(...));
        $ctx->check();
        $state = $verdict->ok ? 'VERIFICADO' : 'RECHAZADO';
        $done = $db->transaction(function (Database $db) use ($id, $state, $verdict, $ctx): bool {
            $updated = $db->fetchOne(
                "UPDATE archivos_importados
                    SET estado = :s, tiene_macros = :m, contenedor = coalesce(contenedor, '{}'::jsonb) || CAST(:c AS jsonb),
                        verificado_at = now(), error_codigo = :ec, error_resumen = :er
                  WHERE id = :id AND estado = 'VERIFICANDO' RETURNING id",
                ['s' => $state, 'm' => $verdict->hasMacros, 'c' => Json::encode(['verificacion' => Json::obj($verdict->metrics)]),
                 'ec' => $verdict->code, 'er' => $verdict->message, 'id' => $id]);
            if ($updated === null) {
                return false;
            }
            WorkerAudit::record($db, $verdict->ok ? 'archivos.verificar' : 'archivos.rechazar_verificacion', 'archivos_importados', $id,
                ['estado' => 'VERIFICANDO'], ['estado' => $state],
                ['codigo' => $verdict->code, 'worker' => $ctx->workerId, 'metricas' => Json::obj($verdict->metrics)]);
            return true;
        });
        if (!$done) {
            return ['archivo_id' => $id, 'omitido' => 'El estado del archivo cambió durante la verificación.'];
        }
        $ctx->progress(100, 'FINALIZANDO', 'Verificación terminada');
        return ['archivo_id' => $id, 'estado' => $state, 'codigo' => $verdict->code, 'tiene_macros' => $verdict->hasMacros,
                'metricas' => Json::obj($verdict->metrics), '_estado' => $verdict->ok ? 'COMPLETADO' : 'COMPLETADO_CON_INCIDENCIAS'];
    }
}
