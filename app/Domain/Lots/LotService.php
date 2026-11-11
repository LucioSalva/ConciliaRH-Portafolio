<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Lots;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Lotes de importación ("Carga 001", "Carga 002"…) dentro de un periodo.
 * - La numeración la asigna fn_crear_lote bajo bloqueo de fila del periodo (segura ante concurrencia).
 * - Ciclo de vida almacenado: ABIERTO → CERRADO (→ ABIERTO con lotes.reabrir) | ANULADO.
 * - El estado de procesamiento se DERIVA de sus archivos (v_lotes_resumen).
 * - Los archivos de lotes distintos nunca se mezclan: cada archivo pertenece a un único lote.
 */
final class LotService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    public function create(CurrentUser $actor, int $periodoId, string $nombre = '', string $descripcion = '',
                           string $origen = '', string $observaciones = ''): int
    {
        if (trim($nombre) !== '' && mb_strlen(trim($nombre)) < 3) {
            throw new ValidationException(['nombre' => 'El nombre debe tener al menos 3 caracteres (o déjelo vacío para "Carga 00N").']);
        }
        $this->validateTexts($nombre, $descripcion, $origen, $observaciones);
        return $this->db->transaction(function () use ($actor, $periodoId, $nombre, $descripcion, $origen, $observaciones): int {
            try {
                $lote = $this->db->fetchOne(
                    'SELECT id, numero, nombre FROM fn_crear_lote(:p, :n, :d, :o, :obs, :u)',
                    ['p' => $periodoId, 'n' => $nombre, 'd' => $descripcion, 'o' => $origen, 'obs' => $observaciones, 'u' => $actor->id]
                );
            } catch (PDOException $e) {
                if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'lotes_nombre')) {
                    throw new ValidationException(['nombre' => 'El nombre debe tener entre 3 y 120 caracteres.']);
                }
                if ($e->getCode() === '23514') {
                    throw new ValidationException(['periodo' => 'El periodo no existe o no está abierto.']);
                }
                throw $e;
            }
            $this->audit->record('lotes.crear', 'lotes_importacion', (int) $lote['id'], null, [
                'periodo_id' => $periodoId, 'numero' => (int) $lote['numero'], 'nombre' => $lote['nombre'],
                'origen' => trim($origen) ?: null,
            ]);
            return (int) $lote['id'];
        });
    }

    public function update(CurrentUser $actor, int $id, string $nombre, string $descripcion, string $origen, string $observaciones): void
    {
        $lote = $this->find($id);
        if ($lote['estado'] !== 'ABIERTO') {
            throw new ValidationException(['estado' => 'Sólo puede editarse un lote abierto.']);
        }
        if (mb_strlen(trim($nombre)) < 3) {
            throw new ValidationException(['nombre' => 'El nombre debe tener al menos 3 caracteres.']);
        }
        $this->validateTexts($nombre, $descripcion, $origen, $observaciones);
        $after = ['nombre' => trim($nombre), 'descripcion' => self::nullable($descripcion), 'origen' => self::nullable($origen), 'observaciones' => self::nullable($observaciones)];
        $before = ['nombre' => $lote['nombre'], 'descripcion' => $lote['descripcion'], 'origen' => $lote['origen'], 'observaciones' => $lote['observaciones']];
        if ($before === $after) {
            return;
        }
        $this->db->transaction(function () use ($id, $before, $after): void {
            $n = $this->db->execute(
                "UPDATE lotes_importacion SET nombre = :n, descripcion = :d, origen = :o, observaciones = :obs WHERE id = :id AND estado = 'ABIERTO'",
                ['n' => $after['nombre'], 'd' => $after['descripcion'], 'o' => $after['origen'], 'obs' => $after['observaciones'], 'id' => $id]
            );
            if ($n === 0) {
                throw new ValidationException(['estado' => 'El lote cambió de estado mientras se editaba; recargue la página.']);
            }
            $this->audit->record('lotes.editar', 'lotes_importacion', $id, $before, $after);
        });
    }

    public function close(CurrentUser $actor, int $id, string $motivo = ''): void
    {
        $this->transition($id, 'ABIERTO', function (array $l) use ($actor, $id, $motivo): void {
            $this->db->execute(
                "UPDATE lotes_importacion SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now(), motivo_estado = :m WHERE id = :id",
                ['u' => $actor->id, 'm' => trim($motivo) ?: null, 'id' => $id]
            );
            $this->audit->record('lotes.cerrar', 'lotes_importacion', $id, ['estado' => 'ABIERTO'], ['estado' => 'CERRADO'], ['motivo' => trim($motivo) ?: null]);
        }, 'Sólo puede cerrarse un lote abierto.');
    }

    public function reopen(CurrentUser $actor, int $id, string $motivo): void
    {
        if (!$actor->can('lotes.reabrir')) {
            throw HttpException::forbidden();
        }
        $motivo = PeriodService::requiredReason($motivo);
        $this->transition($id, 'CERRADO', function (array $l) use ($id, $motivo): void {
            try {
                $this->db->execute(
                    "UPDATE lotes_importacion SET estado = 'ABIERTO', cerrado_por = NULL, cerrado_at = NULL, motivo_estado = :m WHERE id = :id",
                    ['m' => $motivo, 'id' => $id]
                );
            } catch (PDOException $e) {
                if ($e->getCode() === '23514') {
                    throw new ValidationException(['estado' => 'El periodo está cerrado: reabra primero el periodo.']);
                }
                throw $e;
            }
            $this->audit->record('lotes.reabrir', 'lotes_importacion', $id, ['estado' => 'CERRADO'], ['estado' => 'ABIERTO'], ['motivo' => $motivo]);
        }, 'Sólo puede reabrirse un lote cerrado.');
    }

    public function annul(CurrentUser $actor, int $id, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            $l = $this->lock($id);
            if ($l['estado'] === 'ANULADO') {
                throw new ValidationException(['estado' => 'El lote ya está anulado.']);
            }
            if ($l['es_oficial'] === true && !$actor->can('lotes.declarar_oficial')) {
                throw new ValidationException(['estado' => 'El lote es el oficial de la quincena: anularlo requiere el permiso para declarar el lote oficial.']);
            }
            $vigentes = (int) $this->db->fetchValue(
                "SELECT count(*) FROM archivos_importados WHERE lote_id = :id AND estado <> 'ANULADO'", ['id' => $id]);
            if ($vigentes > 0) {
                throw new ValidationException(['estado' => 'El lote tiene archivos vigentes; anúlelos primero o cierre el lote.']);
            }
            $this->db->execute(
                "UPDATE lotes_importacion SET estado = 'ANULADO', anulado_por = :u, anulado_at = now(), motivo_estado = :m,
                        cerrado_por = NULL, cerrado_at = NULL, es_oficial = false, declarado_oficial_por = NULL, declarado_oficial_at = NULL
                  WHERE id = :id",
                ['u' => $actor->id, 'm' => $motivo, 'id' => $id]
            );
            $this->audit->record('lotes.anular', 'lotes_importacion', $id, ['estado' => $l['estado'], 'es_oficial' => $l['es_oficial']],
                ['estado' => 'ANULADO'], ['motivo' => $motivo]);
        });
    }

    /** Declara el lote oficial del periodo (lotes.declarar_oficial). Retira la marca de cualquier otro lote del periodo. */
    public function declareOfficial(CurrentUser $actor, int $id, string $motivo): void
    {
        if (!$actor->can('lotes.declarar_oficial')) {
            throw HttpException::forbidden();
        }
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            // Orden de bloqueo periodo → lote (el mismo que el cierre del periodo) para evitar interbloqueos
            $periodoId = $this->db->fetchValue('SELECT periodo_id FROM lotes_importacion WHERE id = :id', ['id' => $id]);
            if ($periodoId === null) {
                throw HttpException::notFound();
            }
            $this->db->execute('SELECT id FROM periodos_nomina WHERE id = :p FOR UPDATE', ['p' => $periodoId]);
            $l = $this->lock($id);
            if ($l['estado'] === 'ANULADO') {
                throw new ValidationException(['estado' => 'Un lote anulado no puede ser oficial.']);
            }
            $previous = $this->db->fetchValue(
                'UPDATE lotes_importacion SET es_oficial = false, declarado_oficial_por = NULL, declarado_oficial_at = NULL
                  WHERE periodo_id = :p AND es_oficial AND id <> :id RETURNING id',
                ['p' => $l['periodo_id'], 'id' => $id]
            );
            $this->db->execute(
                'UPDATE lotes_importacion SET es_oficial = true, declarado_oficial_por = :u, declarado_oficial_at = now() WHERE id = :id',
                ['u' => $actor->id, 'id' => $id]
            );
            $this->audit->record('lotes.declarar_oficial', 'lotes_importacion', $id, ['es_oficial' => (bool) $l['es_oficial'], 'lote_oficial_anterior' => $previous],
                ['es_oficial' => true], ['motivo' => $motivo, 'periodo_id' => (int) $l['periodo_id']]);
        });
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $l = $this->db->fetchOne(
            'SELECT l.*, u.username AS creado_por_username, p.descripcion AS periodo_descripcion, p.estado AS periodo_estado,
                    p.anio, p.mes, p.quincena, r.archivos, r.en_proceso, r.correctos, r.con_advertencias, r.con_error,
                    r.duplicados_binarios, r.estado_procesamiento
               FROM lotes_importacion l
               JOIN periodos_nomina p ON p.id = l.periodo_id
               JOIN usuarios u ON u.id = l.creado_por
               JOIN v_lotes_resumen r ON r.lote_id = l.id
              WHERE l.id = :id',
            ['id' => $id]
        );
        if ($l === null) {
            throw HttpException::notFound();
        }
        return $l;
    }

    /** @return list<array<string,mixed>> */
    public function byPeriod(int $periodoId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, u.username AS creado_por_username, r.archivos, r.en_proceso, r.con_error, r.estado_procesamiento
               FROM lotes_importacion l JOIN usuarios u ON u.id = l.creado_por JOIN v_lotes_resumen r ON r.lote_id = l.id
              WHERE l.periodo_id = :p ORDER BY l.numero',
            ['p' => $periodoId]
        );
    }

    /** Lotes que pueden recibir archivos, agrupables por periodo (selector de carga). @return list<array<string,mixed>> */
    public function openForUpload(): array
    {
        return $this->db->fetchAll(
            "SELECT l.id, l.numero, l.nombre, p.id AS periodo_id, p.descripcion AS periodo_descripcion
               FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
              WHERE l.estado = 'ABIERTO' AND p.estado = 'ABIERTO'
              ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, l.numero"
        );
    }

    /** @param callable(array<string,mixed>):void $fn */
    private function transition(int $id, string $expected, callable $fn, string $message): void
    {
        $this->db->transaction(function () use ($id, $expected, $fn, $message): void {
            $l = $this->lock($id);
            if ($l['estado'] !== $expected) {
                throw new ValidationException(['estado' => $message]);
            }
            $fn($l);
        });
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        $l = $this->db->fetchOne('SELECT * FROM lotes_importacion WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($l === null) {
            throw HttpException::notFound();
        }
        return $l;
    }

    private function validateTexts(string $nombre, string $descripcion, string $origen, string $observaciones): void
    {
        $errors = [];
        if (mb_strlen(trim($nombre)) > 120) {
            $errors['nombre'] = 'Máximo 120 caracteres.';
        }
        if (mb_strlen(trim($descripcion)) > 1000) {
            $errors['descripcion'] = 'Máximo 1000 caracteres.';
        }
        if (mb_strlen(trim($origen)) > 200) {
            $errors['origen'] = 'Máximo 200 caracteres.';
        }
        if (mb_strlen(trim($observaciones)) > 2000) {
            $errors['observaciones'] = 'Máximo 2000 caracteres.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function nullable(string $v): ?string
    {
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
