<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Settings;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\ValidationException;

/**
 * Configuración funcional almacenada en BD (tabla `configuracion`).
 * Valores tipados y acotados por mínimo/máximo; cada cambio queda auditado.
 */
final class SettingsService
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->fetchAll('SELECT * FROM configuracion ORDER BY clave') as $row) {
                $this->cache[$row['clave']] = $row;
            }
        }
        return $this->cache;
    }

    public function string(string $key, string $default): string
    {
        return (string) ($this->all()[$key]['valor'] ?? $default);
    }

    public function int(string $key, int $default): int
    {
        $v = $this->all()[$key]['valor'] ?? null;
        return is_string($v) && preg_match('/^-?\d+$/', $v) ? (int) $v : $default;
    }

    public function update(CurrentUser $actor, string $key, string $value): void
    {
        $row = $this->all()[$key] ?? null;
        if ($row === null) {
            throw new ValidationException(['valor' => 'Parámetro inexistente.']);
        }
        if ($row['solo_god'] && !$actor->isGod()) {
            throw new ValidationException(['valor' => 'Sólo GOD puede modificar este parámetro.']);
        }
        $value = trim($value);
        $error = self::validate($row, $value);
        if ($error !== null) {
            throw new ValidationException(['valor' => $error]);
        }
        if ($value === $row['valor']) {
            return;
        }
        $this->db->transaction(function () use ($actor, $key, $value, $row): void {
            $this->db->execute(
                'UPDATE configuracion SET valor = :v, actualizado_por = :u WHERE clave = :k',
                ['v' => $value, 'u' => $actor->id, 'k' => $key]
            );
            $this->audit->record('configuracion.editar', 'configuracion', $key, ['valor' => $row['valor']], ['valor' => $value]);
        });
        $this->cache = null;
    }

    /** @param array<string,mixed> $row */
    public static function validate(array $row, string $value): ?string
    {
        $num = null;
        switch ($row['tipo']) {
            case 'entero':
                if (!preg_match('/^-?\d{1,9}$/', $value)) {
                    return 'Debe ser un número entero.';
                }
                $num = $value;
                break;
            case 'decimal':
                if (!preg_match('/^\d{1,9}(\.\d{1,4})?$/', $value)) {
                    return 'Debe ser un número decimal no negativo (máximo 4 decimales).';
                }
                $num = $value;
                break;
            case 'booleano':
                if (!in_array($value, ['true', 'false'], true)) {
                    return 'Debe ser true o false.';
                }
                break;
            default:
                if (mb_strlen($value) > 500) {
                    return 'Máximo 500 caracteres.';
                }
        }
        if ($num !== null) {
            if ($row['minimo'] !== null && bccomp($num, (string) $row['minimo'], 6) < 0) {
                return 'El valor mínimo permitido es ' . $row['minimo'] . '.';
            }
            if ($row['maximo'] !== null && bccomp($num, (string) $row['maximo'], 6) > 0) {
                return 'El valor máximo permitido es ' . $row['maximo'] . '.';
            }
        }
        return null;
    }
}
