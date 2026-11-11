<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;

final class SourceSelector
{
    /** @var array<string,mixed> */
    private array $inp;

    public function __construct(private readonly State $st)
    {
        $this->inp = $st->inp;
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>,3:list<array<string,mixed>>,4:list<int>} */
    public function select(): array
    {
        $byFileRrhh = [];
        foreach ($this->inp['rrhh'] as $r) {
            $byFileRrhh[(int) $r['archivo_id']][] = $r;
        }
        $byFileTes = [];
        foreach ($this->inp['tesoreria'] as $t) {
            $byFileTes[(int) $t['archivo_id']][] = $t;
        }
        $utilizables = [];
        $porId = [];
        foreach ($this->inp['archivos'] as $a) {
            $porId[(int) $a['archivo_id']] = $a;
            if (in_array($a['estado_archivo'], ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true) && $a['importacion_id'] !== null && $a['tipo_registro'] !== null) {
                $utilizables[(int) $a['archivo_id']] = true;
            }
        }
        $versionAmbigua = static function (array $a) use ($porId, $utilizables): bool {
            $r = $a['relacionado_id'] !== null ? ($porId[(int) $a['relacionado_id']] ?? null) : null;
            return $a['clasificacion'] === 'POSIBLE_VERSION' && $a['relacionado_mismo_lote'] && $r !== null && isset($utilizables[(int) $r['archivo_id']])
                && $r['tipo_registro'] === $a['tipo_registro'] && !($r['tipo_registro'] === 'RRHH' && $r['derivado'])
                && ($a['tipo_registro'] !== 'RRHH' || ($r['grupo'] ?? 'SIN_GRUPO') === ($a['grupo'] ?? 'SIN_GRUPO'));
        };
        $candidatosRrhh = [];
        $incluidos = [];
        $archivos = $this->inp['archivos'];
        usort($archivos, static fn ($x, $y) => $x['archivo_id'] <=> $y['archivo_id']);
        foreach ($archivos as $a) {
            $aid = (int) $a['archivo_id'];
            $role = static fn (string $rol, ?string $motivo = null, int $registros = 0, ?string $total = null) => new FileRole($aid, $a['importacion_id'],
                $a['tipo_registro'], $rol, $a['grupo'], $a['banco'], $a['categoria'], $registros, $total, $a['total_declarado'], $motivo);
            if ($a['estado_archivo'] === 'ANULADO') {
                $this->st->roles[$aid] = $role('EXCLUIDO_ANULADO', 'Archivo anulado');
                continue;
            }
            if (!isset($utilizables[$aid])) {
                $this->st->roles[$aid] = $role('EXCLUIDO_NO_NORMALIZADO', 'No se terminó de leer (' . mb_strtolower(status_label((string) $a['estado_archivo'])) . ')');
                $this->st->incs[] = new Incident("EXCLUIDO:$aid", 'ARCHIVO_EXCLUIDO_NO_NORMALIZADO', 'ADVERTENCIA', 0,
                    'Un archivo de la quincena no se terminó de leer y no se usa en el cotejo.',
                    detalle: ['archivo' => $aid, 'estado' => $a['estado_archivo']]);
                continue;
            }
            if (in_array($a['clasificacion'], ['DUPLICADO_BINARIO', 'DUPLICADO_CONTENIDO'], true) && $a['relacionado_mismo_lote']) {
                if ($a['relacionado_id'] !== null && isset($utilizables[(int) $a['relacionado_id']])) {
                    $this->st->roles[$aid] = $role('EXCLUIDO_DUPLICADO', "{$a['clasificacion']} del archivo #{$a['relacionado_id']} del mismo lote");
                    $this->st->incs[] = new Incident("EXCLUIDO:$aid", 'ARCHIVO_DUPLICADO_EXCLUIDO', 'INFO', 0,
                        'Un archivo duplicado del lote se excluyó para no contar dos veces el mismo dinero.',
                        detalle: ['archivo' => $aid, 'original' => $a['relacionado_id']]);
                    continue;
                }
                $this->st->incs[] = new Incident("DUPLICADO_SIN_ORIGINAL:$aid", 'DUPLICADO_SIN_ORIGINAL_VIGENTE', 'ADVERTENCIA', 0,
                    'El original de este duplicado no participa (anulado o sin normalizar); se usó el duplicado.',
                    detalle: ['archivo' => $aid, 'original' => $a['relacionado_id']]);
            }
            if ($a['tipo_registro'] === 'RRHH' && $a['derivado']) {
                $this->st->roles[$aid] = $role('EXCLUIDO_DERIVADO', 'Copia filtrada o con filas ocultas: no es la nómina de RH', count($byFileRrhh[$aid] ?? []));
                continue;
            }
            if ($versionAmbigua($a)) {
                $this->st->roles[$aid] = $role('EXCLUIDO_VERSION_AMBIGUA', "Posible versión del archivo #{$a['relacionado_id']} en el mismo lote");
                $this->st->incs[] = new Incident("VERSION_AMBIGUA:$aid", 'VERSION_AMBIGUA_EN_LOTE', 'CRITICA', 0,
                    'El lote contiene dos versiones del mismo documento; se usó la primera y se requiere decisión (anule la versión que no corresponde y concilie de nuevo).',
                    detalle: ['archivo' => $aid, 'relacionado' => $a['relacionado_id'], 'tipo' => $a['tipo_registro']]);
                continue;
            }
            if ($a['tipo_registro'] === 'RRHH') {
                $candidatosRrhh[$a['grupo'] ?? 'SIN_GRUPO'][] = $a;
                continue;
            }
            $incluidos[$aid] = true;
            if ($a['tipo_registro'] === 'TESORERIA') {
                $regs = $byFileTes[$aid] ?? [];
                $det = '0';
                foreach ($regs as $t) {
                    if ($t['importe'] !== null && $t['importe_estado'] === 'OK') {
                        $det = Dec::add($det, (string) $t['importe']);
                    }
                }
                $this->st->roles[$aid] = $role($a['categoria'] === 'PENSION' ? 'TESORERIA_PENSION' : 'TESORERIA_NOMINA', null, count($regs), $det);
            } elseif ($a['tipo_registro'] === 'POLIZA_GLOBAL') {
                $this->st->roles[$aid] = $role('POLIZA_GLOBAL');
            } else {
                $this->st->roles[$aid] = $role('POLIZA_EGRESO');
            }
        }
        $rrhhSrc = [];
        ksort($candidatosRrhh, SORT_STRING);
        foreach ($candidatosRrhh as $grupo => $arch) {
            $grupo = (string) $grupo;
            $pob = [];
            foreach ($arch as $a) {
                $set = [];
                foreach ($byFileRrhh[(int) $a['archivo_id']] ?? [] as $r) {
                    $set[$r['empleado_id'] !== null ? 'E' . $r['empleado_id'] : 'N:' . $r['numero']] = true;
                }
                $pob[(int) $a['archivo_id']] = $set;
            }
            $orden = $arch;
            usort($orden, static fn ($x, $y) => [-count($pob[(int) $x['archivo_id']]), $x['archivo_id']] <=> [-count($pob[(int) $y['archivo_id']]), $y['archivo_id']]);
            $elegidos = [];
            foreach ($orden as $a) {
                $aid = (int) $a['archivo_id'];
                $p = $pob[$aid];
                $sup = null;
                foreach ($elegidos as $e) {
                    if (array_diff_key($p, $pob[(int) $e['archivo_id']]) === []) {
                        $sup = $e;
                        break;
                    }
                }
                $regsN = count($byFileRrhh[$aid] ?? []);
                if ($sup !== null) {
                    $this->st->roles[$aid] = new FileRole($aid, $a['importacion_id'], 'RRHH', 'EXCLUIDO_SUBCONJUNTO', $grupo, registros: $regsN,
                        motivo: "Su población está contenida en el tabulado #{$sup['archivo_id']}");
                    $this->st->incs[] = new Incident("EXCLUIDO:$aid", 'TABULADO_SUBCONJUNTO_EXCLUIDO', 'INFO', 0,
                        'Una nómina cuyas personas ya están en la nómina de RH del grupo no se usó, para no contarlas dos veces.', grupo: $grupo,
                        detalle: ['archivo' => $aid, 'fuente' => (int) $sup['archivo_id']]);
                    continue;
                }
                $traslape = [];
                foreach ($elegidos as $e) {
                    if (array_intersect_key($p, $pob[(int) $e['archivo_id']]) !== []) {
                        $traslape[] = (int) $e['archivo_id'];
                    }
                }
                if ($traslape !== []) {
                    $this->st->roles[$aid] = new FileRole($aid, $a['importacion_id'], 'RRHH', 'EXCLUIDO_SUBCONJUNTO', $grupo, registros: $regsN,
                        motivo: 'Comparte personas con #[' . implode(', ', $traslape) . '] sin estar contenido: requiere decisión');
                    $this->st->incs[] = new Incident("FUENTE_RRHH_AMBIGUA:$grupo:$aid", 'FUENTE_RRHH_AMBIGUA', 'CRITICA', 1,
                        'Dos nóminas de RH del mismo grupo comparten personas; no se sumaron para no contarlas dos veces.', grupo: $grupo,
                        detalle: ['archivo' => $aid, 'traslape_con' => $traslape]);
                    continue;
                }
                $elegidos[] = $a;
            }
            foreach ($elegidos as $a) {
                $aid = (int) $a['archivo_id'];
                $regs = $byFileRrhh[$aid] ?? [];
                array_push($rrhhSrc, ...$regs);
                $total = '0';
                foreach ($regs as $r) {
                    if ($r['neto'] !== null) {
                        $total = Dec::add($total, (string) $r['neto']);
                    }
                }
                $this->st->roles[$aid] = new FileRole($aid, $a['importacion_id'], 'RRHH', 'FUENTE_RRHH', $grupo, registros: count($regs), totalDetalle: $total,
                    motivo: 'Nómina de RH del grupo' . (count($elegidos) > 1 ? ' (complementaria)' : ''));
            }
        }
        $tesNom = array_values(array_filter($this->inp['tesoreria'], static fn ($t) => isset($incluidos[(int) $t['archivo_id']]) && $t['categoria'] === 'NOMINA'));
        $tesPen = array_values(array_filter($this->inp['tesoreria'], static fn ($t) => isset($incluidos[(int) $t['archivo_id']]) && $t['categoria'] === 'PENSION'));
        $globales = array_values(array_filter($this->inp['globales'], static fn ($g) => isset($incluidos[(int) $g['archivo_id']])));
        $egresoIds = [];
        foreach (array_keys($incluidos) as $aid) {
            if ($this->st->roles[$aid]->rol === 'POLIZA_EGRESO') {
                $egresoIds[] = $aid;
            }
        }
        if ($rrhhSrc === []) {
            $this->st->incs[] = new Incident('LOTE_SIN_RRHH', 'LOTE_SIN_FUENTE_RRHH', 'CRITICA', 0,
                'Falta una nómina de RH que se pueda usar: no se puede calcular el neto de cada persona.');
        }
        if ($tesNom === []) {
            $this->st->incs[] = new Incident('LOTE_SIN_TESORERIA', 'LOTE_SIN_DISPERSION', 'ERROR', 0, 'El lote no contiene dispersiones de nómina de Tesorería.');
        }
        return [$rrhhSrc, $tesNom, $tesPen, $globales, $egresoIds];
    }
}
