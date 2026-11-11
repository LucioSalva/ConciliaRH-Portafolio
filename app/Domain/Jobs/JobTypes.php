<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Jobs;

use ConciliaRH\Domain\ValidationException;

final class JobTypes
{
    public const CONTRACT_VERSION = 1;

    private const TYPES = [
        'DIAGNOSTICO' => [
            'eco' => 'text100',
            'duracion_segundos' => 'int0_60',
        ],
        'VERIFICAR_ARCHIVO' => [
            'archivo_id' => 'int_pos',
        ],
        'ANALIZAR_ARCHIVO' => [
            'archivo_id' => 'int_pos',
        ],
        'NORMALIZAR_REGISTROS' => [
            'archivo_id' => 'int_pos',
            'completa' => 'bool',
        ],
        'CONCILIAR_LOTE' => [
            'conciliacion_id' => 'int_pos',
        ],
        'DETECTAR_DUPLICADOS' => [],
        'GENERAR_REPORTE_PROGRAMADO' => [
            'reporte_generado_id' => 'int_pos',
        ],
        'LIMPIAR_REPORTES_EXPIRADOS' => [],
    ];

    public const PHP_TYPES = ['GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> parámetros saneados
     */
    public static function validate(string $type, array $params): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new ValidationException(['tipo' => 'Tipo de trabajo no admitido.']);
        }
        $spec = self::TYPES[$type];
        $clean = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || !isset($spec[$key])) {
                throw new ValidationException(['parametros' => 'Parámetro no admitido: ' . (is_string($key) ? $key : '?')]);
            }
            $clean[$key] = match ($spec[$key]) {
                'text100' => is_string($value) && mb_strlen($value) <= 100
                    ? $value : throw new ValidationException(['parametros' => "$key debe ser texto (máx. 100)."]),
                'int0_60' => is_int($value) && $value >= 0 && $value <= 60
                    ? $value : throw new ValidationException(['parametros' => "$key debe ser entero entre 0 y 60."]),
                'int_pos' => is_int($value) && $value > 0
                    ? $value : throw new ValidationException(['parametros' => "$key debe ser un entero positivo."]),
                'bool' => is_bool($value)
                    ? $value : throw new ValidationException(['parametros' => "$key debe ser verdadero o falso."]),
            };
        }
        return $clean;
    }
}
