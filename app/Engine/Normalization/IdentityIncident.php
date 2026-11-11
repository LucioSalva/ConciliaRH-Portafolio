<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\MatchResult;
use ConciliaRH\Engine\Support\Json;

final class IdentityIncident
{
    /** @param array<string,mixed> $detalle */
    public function __construct(
        public readonly string $tipo,
        public readonly string $severidad,
        public readonly array $detalle,
        public readonly ?string $registroTipo = null,
        public readonly ?int $registroId = null,
        public readonly ?int $empleadoId = null,
    ) {
    }

    public function clave(): string
    {
        if ($this->registroId !== null) {
            return "{$this->tipo}:{$this->registroTipo}:{$this->registroId}";
        }
        $emps = array_map('intval', (array) ($this->detalle['empleados'] ?? []));
        sort($emps);
        $tail = $emps !== [] ? '[' . implode(', ', $emps) . ']' : ($this->empleadoId === null ? 'None' : (string) $this->empleadoId);
        return "{$this->tipo}:E{$tail}";
    }

    /** @return list<self> */
    public static function fromMatch(MatchResult $m, string $tipo, int $rid): array
    {
        $out = [];
        foreach ($m->incidencias as [$t, $sev, $det]) {
            $emp = isset($det['empleado']) && is_int($det['empleado']) ? $det['empleado'] : null;
            $out[] = new self($t, $sev, $det, $tipo, $rid, $emp);
        }
        return $out;
    }

    /** @param list<self> $incs */
    public static function save(Database $db, int $importacionId, array $incs): void
    {
        foreach ($incs as $i) {
            $db->execute(
                "INSERT INTO incidencias_identidad (clave, tipo, severidad, importacion_id, registro_tipo, registro_id, empleado_id, detalle)
                 VALUES (:c, :t, :s, :i, :rt, :ri, :e, CAST(:d AS jsonb))
                 ON CONFLICT (clave) DO UPDATE SET estado = 'ABIERTA', resuelto_at = NULL, resolucion = NULL, detalle = EXCLUDED.detalle,
                        importacion_id = EXCLUDED.importacion_id
                  WHERE incidencias_identidad.resuelto_por IS NULL
                    AND (incidencias_identidad.estado <> 'ABIERTA' OR incidencias_identidad.detalle IS DISTINCT FROM EXCLUDED.detalle)",
                ['c' => mb_substr($i->clave(), 0, 300), 't' => $i->tipo, 's' => $i->severidad, 'i' => $i->registroId !== null ? $importacionId : null,
                 'rt' => $i->registroTipo, 'ri' => $i->registroId, 'e' => $i->empleadoId, 'd' => Json::encode(Json::obj($i->detalle))]);
        }
    }
}
