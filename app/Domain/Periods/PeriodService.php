<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Periods;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\ValidationException;
use DateTimeImmutable;
use PDOException;

/**
 * Periodos de nómina (año, mes, quincena, tipo).
 * Ciclo de vida: ABIERTO → CERRADO (cierra también sus lotes abiertos) → ABIERTO sólo por
 * reapertura excepcional (periodos.reabrir, con motivo). ANULADO sólo si no
 * tiene archivos vigentes. Año, mes, quincena y tipo son inmutables tras la creación.
 */
final class PeriodService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    public function create(CurrentUser $actor, int $anio, int $mes, int $quincena, string $tipo = 'ORDINARIA',
                           string $descripcion = '', string $inicio = '', string $fin = ''): int
    {
        $errors = [];
        if ($anio < 2000 || $anio > 2100) {
            $errors['anio'] = 'Año fuera de rango (2000–2100).';
        }
        if ($mes < 1 || $mes > 12) {
            $errors['mes'] = 'Mes inválido.';
        }
        if (!in_array($quincena, [1, 2], true)) {
            $errors['quincena'] = 'La quincena debe ser 1 o 2.';
        }
        if (!in_array($tipo, ['ORDINARIA', 'EXTRAORDINARIA'], true)) {
            $errors['tipo'] = 'Tipo de nómina inválido.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        [$defInicio, $defFin] = PeriodCalendar::defaultDates($anio, $mes, $quincena);
        $inicio = $inicio !== '' ? $inicio : $defInicio;
        $fin = $fin !== '' ? $fin : $defFin;
        $this->validateDates($anio, $mes, $quincena, $inicio, $fin);
        $descripcion = trim($descripcion) !== '' ? trim($descripcion) : PeriodCalendar::label($anio, $mes, $quincena, $tipo);
        if (mb_strlen($descripcion) < 3 || mb_strlen($descripcion) > 150) {
            throw new ValidationException(['descripcion' => 'La descripción debe tener entre 3 y 150 caracteres.']);
        }

        return $this->db->transaction(function () use ($actor, $anio, $mes, $quincena, $tipo, $descripcion, $inicio, $fin): int {
            try {
                $id = (int) $this->db->fetchValue(
                    'INSERT INTO periodos_nomina (anio, mes, quincena, tipo, fecha_inicio, fecha_fin, descripcion, creado_por)
                     VALUES (:a, :m, :q, :t, CAST(:i AS date), CAST(:f AS date), :d, :u) RETURNING id',
                    ['a' => $anio, 'm' => $mes, 'q' => $quincena, 't' => $tipo, 'i' => $inicio, 'f' => $fin, 'd' => $descripcion, 'u' => $actor->id]
                );
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationException(['periodo' => 'Ya existe ese periodo (año, mes, quincena y tipo).']);
                }
                throw $e;
            }
            $this->audit->record('periodos.crear', 'periodos_nomina', $id, null, [
                'anio' => $anio, 'mes' => $mes, 'quincena' => $quincena, 'tipo' => $tipo,
                'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'descripcion' => $descripcion,
            ]);
            return $id;
        });
    }

    public function update(CurrentUser $actor, int $id, string $descripcion, string $inicio, string $fin): void
    {
        $p = $this->find($id);
        $this->assertState($p, 'ABIERTO', 'Sólo puede editarse un periodo abierto.');
        $descripcion = trim($descripcion);
        if (mb_strlen($descripcion) < 3 || mb_strlen($descripcion) > 150) {
            throw new ValidationException(['descripcion' => 'La descripción debe tener entre 3 y 150 caracteres.']);
        }
        $this->validateDates((int) $p['anio'], (int) $p['mes'], (int) $p['quincena'], $inicio, $fin);
        $before = ['descripcion' => $p['descripcion'], 'fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin']];
        $after = ['descripcion' => $descripcion, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin];
        if ($before === $after) {
            return;
        }
        $this->db->transaction(function () use ($id, $after, $before): void {
            $n = $this->db->execute(
                'UPDATE periodos_nomina SET descripcion = :d, fecha_inicio = CAST(:i AS date), fecha_fin = CAST(:f AS date)
                  WHERE id = :id AND estado = \'ABIERTO\'',
                ['d' => $after['descripcion'], 'i' => $after['fecha_inicio'], 'f' => $after['fecha_fin'], 'id' => $id]
            );
            if ($n === 0) {
                throw new ValidationException(['estado' => 'El periodo cambió de estado mientras se editaba; recargue la página.']);
            }
            $this->audit->record('periodos.editar', 'periodos_nomina', $id, $before, $after);
        });
    }

    /** Cierra el periodo y sus lotes abiertos: ya no admite nuevas cargas. */
    public function close(CurrentUser $actor, int $id, string $motivo = ''): void
    {
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            $p = $this->lockForUpdate($id);
            $this->assertState($p, 'ABIERTO', 'Sólo puede cerrarse un periodo abierto.');
            $lotes = array_column($this->db->fetchAll(
                "UPDATE lotes_importacion SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now(),
                        motivo_estado = 'Cierre del periodo'
                  WHERE periodo_id = :p AND estado = 'ABIERTO' RETURNING numero",
                ['u' => $actor->id, 'p' => $id]
            ), 'numero');
            $this->db->execute(
                "UPDATE periodos_nomina SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now(), motivo_estado = :m WHERE id = :id",
                ['u' => $actor->id, 'm' => self::optionalReason($motivo), 'id' => $id]
            );
            $this->audit->record('periodos.cerrar', 'periodos_nomina', $id, ['estado' => 'ABIERTO'], ['estado' => 'CERRADO'], [
                'lotes_cerrados' => $lotes, 'motivo' => self::optionalReason($motivo),
            ]);
        });
    }

    /** Reapertura excepcional (periodos.reabrir): requiere motivo y queda auditada. Los lotes siguen cerrados. */
    public function reopen(CurrentUser $actor, int $id, string $motivo): void
    {
        if (!$actor->can('periodos.reabrir')) {
            throw HttpException::forbidden();
        }
        $motivo = self::requiredReason($motivo);
        $this->db->transaction(function () use ($id, $motivo): void {
            $p = $this->lockForUpdate($id);
            $this->assertState($p, 'CERRADO', 'Sólo puede reabrirse un periodo cerrado.');
            $this->db->execute(
                "UPDATE periodos_nomina SET estado = 'ABIERTO', cerrado_por = NULL, cerrado_at = NULL, motivo_estado = :m WHERE id = :id",
                ['m' => $motivo, 'id' => $id]
            );
            $this->audit->record('periodos.reabrir', 'periodos_nomina', $id,
                ['estado' => 'CERRADO', 'cerrado_por' => $p['cerrado_por'], 'cerrado_at' => $p['cerrado_at']],
                ['estado' => 'ABIERTO'], ['motivo' => $motivo]);
        });
    }

    /** Anulación (periodos.anular): únicamente sin archivos vigentes. Anula también sus lotes. */
    public function annul(CurrentUser $actor, int $id, string $motivo): void
    {
        if (!$actor->can('periodos.anular')) {
            throw HttpException::forbidden();
        }
        $motivo = self::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            $p = $this->lockForUpdate($id);
            if ($p['estado'] === 'ANULADO') {
                throw new ValidationException(['estado' => 'El periodo ya está anulado.']);
            }
            // Bloquea los lotes antes de contar: una carga en curso (que toma FOR SHARE del periodo) ya
            // terminó o esperará, así que el conteo no puede quedar obsoleto.
            $this->db->execute('SELECT id FROM lotes_importacion WHERE periodo_id = :p FOR UPDATE', ['p' => $id]);
            $vigentes = (int) $this->db->fetchValue(
                "SELECT count(*) FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id
                  WHERE l.periodo_id = :p AND a.estado <> 'ANULADO'",
                ['p' => $id]
            );
            if ($vigentes > 0) {
                throw new ValidationException(['estado' => 'El periodo tiene archivos vigentes; anúlelos primero o ciérrelo.']);
            }
            $this->db->execute(
                "UPDATE lotes_importacion SET estado = 'ANULADO', anulado_por = :u, anulado_at = now(), motivo_estado = :m,
                        es_oficial = false, declarado_oficial_por = NULL, declarado_oficial_at = NULL,
                        cerrado_por = NULL, cerrado_at = NULL
                  WHERE periodo_id = :p AND estado <> 'ANULADO'",
                ['u' => $actor->id, 'm' => 'Anulación del periodo', 'p' => $id]
            );
            $this->db->execute(
                "UPDATE periodos_nomina SET estado = 'ANULADO', anulado_por = :u, anulado_at = now(), motivo_estado = :m,
                        cerrado_por = NULL, cerrado_at = NULL WHERE id = :id",
                ['u' => $actor->id, 'm' => $motivo, 'id' => $id]
            );
            $this->audit->record('periodos.anular', 'periodos_nomina', $id, ['estado' => $p['estado']], ['estado' => 'ANULADO'], ['motivo' => $motivo]);
        });
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $p = $this->db->fetchOne(
            'SELECT p.*, u.username AS creado_por_username,
                    (SELECT count(*) FROM lotes_importacion l WHERE l.periodo_id = p.id) AS total_lotes,
                    (SELECT count(*) FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id
                      WHERE l.periodo_id = p.id AND a.estado <> \'ANULADO\') AS total_archivos
               FROM periodos_nomina p JOIN usuarios u ON u.id = p.creado_por WHERE p.id = :id',
            ['id' => $id]
        );
        if ($p === null) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /**
     * @param array{anio?:int|null,mes?:int|null,quincena?:int|null,estado?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $f, int $page, int $perPage): array
    {
        $where = ['TRUE'];
        $p = [];
        foreach (['anio', 'mes', 'quincena'] as $k) {
            if (($f[$k] ?? null) !== null) {
                $where[] = "p.$k = :$k";
                $p[$k] = (int) $f[$k];
            }
        }
        if (in_array($f['estado'] ?? '', ['ABIERTO', 'CERRADO', 'ANULADO'], true)) {
            $where[] = 'p.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        $sql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM periodos_nomina p WHERE $sql", $p);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $this->db->fetchAll(
            "SELECT p.*, (SELECT count(*) FROM lotes_importacion l WHERE l.periodo_id = p.id AND l.estado <> 'ANULADO') AS lotes,
                    (SELECT count(*) FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id
                      WHERE l.periodo_id = p.id AND a.estado <> 'ANULADO') AS archivos
               FROM periodos_nomina p WHERE $sql
              ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, p.tipo LIMIT :limit OFFSET :offset",
            [...$p, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return list<int> */
    public function years(): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT DISTINCT anio FROM periodos_nomina ORDER BY anio DESC'), 'anio'));
    }

    /** @return array<string,mixed> */
    private function lockForUpdate(int $id): array
    {
        $p = $this->db->fetchOne('SELECT * FROM periodos_nomina WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($p === null) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /** @param array<string,mixed> $p */
    private function assertState(array $p, string $expected, string $message): void
    {
        if ($p['estado'] !== $expected) {
            throw new ValidationException(['estado' => $message]);
        }
    }

    private function validateDates(int $anio, int $mes, int $quincena, string $inicio, string $fin): void
    {
        $i = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
        if ($i === false || $f === false || $i->format('Y-m-d') !== $inicio || $f->format('Y-m-d') !== $fin) {
            throw new ValidationException(['fechas' => 'Fechas inválidas (formato AAAA-MM-DD).']);
        }
        $ym = sprintf('%04d-%02d', $anio, $mes);
        if ($i->format('Y-m') !== $ym || $f->format('Y-m') !== $ym || $f < $i) {
            throw new ValidationException(['fechas' => 'Las fechas deben pertenecer al mes del periodo y el fin no puede ser anterior al inicio.']);
        }
        if (($quincena === 1 && (int) $f->format('d') > 15) || ($quincena === 2 && (int) $i->format('d') < 16)) {
            throw new ValidationException(['fechas' => 'Las fechas no corresponden a la quincena seleccionada.']);
        }
    }

    public static function requiredReason(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            throw new ValidationException(['motivo' => 'Indique el motivo (5 a 500 caracteres).']);
        }
        return $motivo;
    }

    private static function optionalReason(string $motivo): ?string
    {
        $motivo = trim($motivo);
        return $motivo === '' ? null : mb_substr($motivo, 0, 500);
    }
}
