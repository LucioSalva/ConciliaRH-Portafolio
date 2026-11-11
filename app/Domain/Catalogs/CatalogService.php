<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Catalogs;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Catálogos configurables: tipos documentales y orígenes administrativos.
 * Sólo GOD (catalogos.administrar) crea o activa/desactiva entradas. Las entradas de sistema
 * pueden desactivarse salvo las que son valores por omisión (SIN_DEFINIR, OTRO).
 * No hay borrado: un archivo siempre conserva su clasificación histórica.
 */
final class CatalogService
{
    public const CATALOGS = [
        'tipos' => ['table' => 'tipos_documento', 'label' => 'Tipos documentales'],
        'origenes' => ['table' => 'origenes_administrativos', 'label' => 'Orígenes administrativos'],
    ];
    private const PROTECTED_CODES = ['SIN_DEFINIR', 'OTRO'];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(string $catalog): array
    {
        $t = self::table($catalog);
        $fk = $catalog === 'tipos' ? 'tipo_documento_id' : 'origen_administrativo_id';
        return $this->db->fetchAll(
            "SELECT c.*, (SELECT count(*) FROM archivos_importados a WHERE a.$fk = c.id) AS usos FROM $t c ORDER BY c.orden, c.nombre"
        );
    }

    /** @return list<array<string,mixed>> */
    public function profiles(): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, t.codigo AS tipo_codigo FROM perfiles_importacion p JOIN tipos_documento t ON t.id = p.tipo_documento_id ORDER BY t.orden, p.codigo'
        );
    }

    public function create(CurrentUser $actor, string $catalog, string $codigo, string $nombre, string $descripcion): int
    {
        $this->assertAdmin($actor);
        $t = self::table($catalog);
        $codigo = strtoupper(trim($codigo));
        $nombre = trim($nombre);
        $errors = [];
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $codigo)) {
            $errors['codigo'] = 'Use mayúsculas, números y guion bajo (2 a 60).';
        }
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
            $errors['nombre'] = 'El nombre debe tener entre 2 y 120 caracteres.';
        }
        if (mb_strlen(trim($descripcion)) > 500) {
            $errors['descripcion'] = 'Máximo 500 caracteres.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transaction(function () use ($t, $catalog, $codigo, $nombre, $descripcion): int {
            try {
                $id = (int) $this->db->fetchValue(
                    "INSERT INTO $t (codigo, nombre, descripcion) VALUES (:c, :n, :d) RETURNING id",
                    ['c' => $codigo, 'n' => $nombre, 'd' => trim($descripcion) === '' ? null : trim($descripcion)]
                );
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationException(['codigo' => 'El código ya existe.']);
                }
                throw $e;
            }
            $this->audit->record('catalogos.crear', $t, $id, null, ['catalogo' => $catalog, 'codigo' => $codigo, 'nombre' => $nombre]);
            return $id;
        });
    }

    public function setActive(CurrentUser $actor, string $catalog, int $id, bool $active): void
    {
        $this->assertAdmin($actor);
        $t = self::table($catalog);
        $row = $this->db->fetchOne("SELECT id, codigo, activo FROM $t WHERE id = :id", ['id' => $id]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        if (!$active && in_array($row['codigo'], self::PROTECTED_CODES, true)) {
            throw new ValidationException(['activo' => 'Este valor por omisión no puede desactivarse.']);
        }
        if ($row['activo'] === $active) {
            return;
        }
        $this->db->transaction(function () use ($t, $id, $active, $row): void {
            $this->db->execute("UPDATE $t SET activo = :a WHERE id = :id", ['a' => $active, 'id' => $id]);
            $this->audit->record('catalogos.' . ($active ? 'activar' : 'desactivar'), $t, $id, ['activo' => !$active], ['activo' => $active], ['codigo' => $row['codigo']]);
        });
    }

    private function assertAdmin(CurrentUser $actor): void
    {
        if (!$actor->can('catalogos.administrar')) {
            throw HttpException::forbidden();
        }
    }

    /** Nombre de tabla sólo desde la lista blanca (nunca desde la entrada del usuario). */
    private static function table(string $catalog): string
    {
        if (!isset(self::CATALOGS[$catalog])) {
            throw HttpException::notFound();
        }
        return self::CATALOGS[$catalog]['table'];
    }
}
