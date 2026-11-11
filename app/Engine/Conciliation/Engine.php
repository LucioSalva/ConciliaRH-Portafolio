<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;

final class Engine
{
    public const VERSION = 2;
    public const ASOCIADO = ['ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL'];
    public const BANCOS = ['SANTANDER', 'BBVA', 'INTERBANCARIA'];
    public const GRUPOS = ['POLICIA', 'BOMBEROS'];
    private const UTILIZABLES = ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'];

    private State $st;

    /** @param array<string,mixed> $inp */
    public function __construct(array $inp)
    {
        $this->st = new State($inp);
    }

    /** @param array<string,mixed> $inp */
    public static function execute(array $inp): Result
    {
        return (new self($inp))->run();
    }

    private function run(): Result
    {
        $st = $this->st;
        [$rrhh, $tesNom, $tesPen, $globales, $egresoIds] = (new SourceSelector($st))->select();
        $grupoTes = $this->treasuryGroups([...$tesNom, ...$tesPen]);
        $policies = new PolicyLevels($st);
        $policies->level1($rrhh, $globales);
        $rel = $policies->level3($tesNom, $tesPen, $grupoTes, $egresoIds);
        $policies->level2($globales, $rel);
        $empleados = (new PayrollLevel($st))->run($rrhh, $tesNom);
        $pensiones = (new PensionLevel($st))->run($rrhh, $tesPen, $grupoTes);
        [$totales, $resumen] = (new Totals($st))->build($rrhh, $tesNom, $tesPen, $grupoTes, $empleados, $pensiones);
        if ($rrhh !== []) {
            PersonIncidents::add($st, $empleados, $pensiones);
        }
        $sev = [];
        foreach ($st->incs as $i) {
            $sev[$i->severidad] = true;
        }
        unset($sev['INFO']);
        $estado = isset($sev['CRITICA']) ? 'REVISION_MANUAL' : ($sev !== [] ? 'COMPLETADA_CON_INCIDENCIAS' : 'COMPLETADA');
        $roles = $st->roles;
        ksort($roles);
        return new Result($estado, self::VERSION, array_values($roles), $st->controles, $empleados, $pensiones, $st->fuentes, $st->incs,
            $totales, $resumen);
    }

    /**
     * @param list<array<string,mixed>> $regs
     * @return array<int,string>
     */
    private function treasuryGroups(array $regs): array
    {
        $cnt = [];
        $files = [];
        foreach ($regs as $t) {
            $files[(int) $t['archivo_id']] = true;
            if ($t['empleado_id'] !== null && in_array($t['asociacion'], self::ASOCIADO, true)) {
                $g = (string) $this->st->employee((int) $t['empleado_id'])['grupo'];
                $cnt[(int) $t['archivo_id']][$g] = ($cnt[(int) $t['archivo_id']][$g] ?? 0) + 1;
            }
        }
        $declarada = [];
        foreach ($this->st->inp['archivos'] as $a) {
            if (in_array($a['poblacion'] ?? null, self::GRUPOS, true)) {
                $declarada[(int) $a['archivo_id']] = (string) $a['poblacion'];
            }
        }
        $out = [];
        foreach (array_keys($files) as $aid) {
            $k = $cnt[$aid] ?? [];
            if ($k === []) {
                $out[$aid] = $declarada[$aid] ?? 'SIN_GRUPO';
                if (isset($declarada[$aid], $this->st->roles[$aid])) {
                    $this->st->roles[$aid]->grupo = $out[$aid];
                }
                continue;
            }
            $g = null;
            $n = -1;
            foreach ($k as $grp => $c) {
                if ($c > $n) {
                    [$g, $n] = [(string) $grp, $c];
                }
            }
            $out[$aid] = $n >= 0.9 * array_sum($k) ? (in_array($g, self::GRUPOS, true) ? $g : 'SIN_GRUPO') : 'MIXTO';
            if (isset($declarada[$aid]) && $out[$aid] !== $declarada[$aid]) {
                $this->st->inc("POBLACION_ARCHIVO:$aid", 'POBLACION_ARCHIVO_NO_COINCIDE', 'ADVERTENCIA', 0,
                    'El archivo se cargó en un espacio de una población, pero las personas identificadas en él pertenecen a otra.',
                    grupo: $declarada[$aid], detalle: ['archivo' => $aid, 'declarada' => $declarada[$aid], 'identificada' => $out[$aid]]);
            }
            if (isset($this->st->roles[$aid])) {
                $this->st->roles[$aid]->grupo = $out[$aid];
            }
        }
        return $out;
    }

    public static function cmp(?string $a, ?string $b, string $tol): string
    {
        if ($a === null || $b === null) {
            return 'NO_DETERMINABLE';
        }
        return Dec::within($a, $b, $tol) ? 'CUADRA' : 'NO_CUADRA';
    }

    public static function bankKey(?string $banco): string
    {
        return in_array($banco, self::BANCOS, true) ? (string) $banco : 'OTROS';
    }
}
