<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Reports;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Conciliation\ConciliationComparison;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;

final class ReportService
{
    public const AGREGADOS = ['resumen', 'bancos', 'grupos'];

    public static function requiresEncryption(string $tipo, bool $sensible): bool
    {
        return $sensible || !in_array($tipo, self::AGREGADOS, true);
    }

    public const TIPOS = [
        'resumen' => ['Resumen de la quincena', 'Lo que RH autorizó contra lo que Tesorería pagó: Policías y Bomberos, bancos, pensiones y lo que queda por atender.', true],
        'empleados' => ['Lista de personas', 'Una fila por persona: neto de RH, lo pagado en cada banco, la diferencia y el resultado.', false],
        'diferencias' => ['Personas con diferencias', 'Personas cuyo pago no coincide con lo autorizado por RH, o que quedan por revisar.', false],
        'no_pagados' => ['Personas no pagadas', 'RH autorizó un neto y no se encontró ningún pago para la persona.', false],
        'pagados_mas' => ['Personas pagadas de más', 'Se pagó más de lo autorizado por RH (incluye posibles pagos duplicados).', false],
        'pagados_menos' => ['Personas pagadas de menos', 'Se pagó menos de lo autorizado por RH.', false],
        'pendientes' => ['Pagos pendientes de identificar', 'Pagos de Tesorería que aún no se relacionan con una persona y quiénes podrían ser.', false],
        'pensiones' => ['Pensiones alimenticias', 'Lo que RH retuvo y lo que Tesorería dispersó, por grupo y por persona.', true],
        'bancos' => ['Por banco', 'Lo dispersado en cada banco, lo identificado, lo pendiente y la diferencia.', true],
        'grupos' => ['Policías y Bomberos', 'RH contra Tesorería por grupo: totales, diferencia, correctos, pendientes y no pagados.', true],
        'incidencias' => ['Incidencias', 'Lo que el cotejo encontró por atender y cómo se resolvió.', true],
        'comparativo' => ['Comparativo entre cotejos', 'Qué cambió entre dos cotejos de la misma quincena y por qué.', false],
    ];
    private const EMP_COLS = [['Número', 'text'], ['Nombre', 'text'], ['Grupo', 'text'], ['Área', 'text'], ['Neto RH', 'money'], ['Santander', 'money'],
        ['BBVA', 'money'], ['Interbancaria', 'money'], ['Otros', 'money'], ['Total pagado', 'money'], ['Diferencia', 'money'], ['Estado', 'text'], ['Alerta', 'text']];

    public function __construct(
        private readonly Database $db,
        private readonly ConciliationService $conc,
        private readonly ConciliationComparison $cmp,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return list<array<string,mixed>> conciliaciones terminadas (el lote oficial primero dentro del periodo) */
    public function conciliations(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.numero, c.estado, c.lote_oficial, p.descripcion AS periodo, l.numero AS lote_numero
               FROM conciliaciones c JOIN periodos_nomina p ON p.id = c.periodo_id JOIN lotes_importacion l ON l.id = c.lote_id
              WHERE c.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA')
              ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, c.lote_oficial DESC, l.numero, c.numero DESC LIMIT 100");
    }

    /**
     * @param array{sensible:bool,otra:?int} $op
     * @return array<string,mixed>
     */
    public function build(CurrentUser $user, string $tipo, int $conciliacionId, array $op): array
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw HttpException::notFound();
        }
        $c = $this->conc->find($conciliacionId);
        if (!in_array($c['estado'], ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA'], true)) {
            throw new ValidationException(['conciliacion' => 'El reporte requiere una conciliación terminada (no invalidada).']);
        }
        if ($op['sensible'] && !self::canExportIds($user)) {
            throw HttpException::forbidden();
        }
        if (!$user->can('conciliaciones.ver') || ($tipo === 'pendientes' && !$user->can('asociaciones.ver'))) {
            throw HttpException::forbidden();
        }
        [$titulo, $desc, $pdf] = self::TIPOS[$tipo];
        $secciones = match ($tipo) {
            'resumen' => $this->resumen($c, $user->can('incidencias.ver')),
            'empleados' => [$this->empleados($c, null, $op['sensible'])],
            'diferencias' => [$this->empleados($c, "ce.estado NOT IN ('CORRECTO', 'NO_APLICA')", $op['sensible'])],
            'no_pagados' => [$this->empleados($c, "ce.estado = 'NO_PAGADO'", $op['sensible'])],
            'pagados_mas' => [$this->empleados($c, "ce.estado IN ('PAGO_MAYOR', 'POSIBLE_PAGO_DUPLICADO')", $op['sensible'])],
            'pagados_menos' => [$this->empleados($c, "ce.estado = 'PAGO_MENOR'", $op['sensible'])],
            'pendientes' => $this->pendientes($c),
            'pensiones' => $this->pensiones($c),
            'bancos' => [$this->bancos($c)],
            'grupos' => [$this->grupos($c)],
            'incidencias' => [$this->incidencias($c, $user)],
            'comparativo' => $this->comparativo($c, $op['otra']),
        };
        $data = ['tipo' => $tipo, 'titulo' => $titulo, 'descripcion' => $desc, 'pdf' => $pdf, 'secciones' => $secciones,
                 'sensible' => $op['sensible'], 'conciliacion' => [
                     'id' => (int) $c['id'], 'numero' => (int) $c['numero'], 'estado' => $c['estado'], 'periodo' => $c['periodo'],
                     'lote' => (int) $c['lote_numero'], 'lote_oficial' => (bool) $c['lote_oficial'], 'motor_version' => (int) $c['motor_version'],
                     'tolerancia' => $c['tolerancia'], 'terminada_at' => $c['terminada_at'], 'huella_entradas' => $c['huella_entradas']]];
        $data['filas'] = array_sum(array_map(static fn ($s) => count($s['filas']), $secciones));
        if ($data['filas'] > $this->settings->int('reportes.max_filas', 50000)) {
            throw new ValidationException(['reporte' => 'El reporte excede el máximo de filas permitido; use filtros o CSV.']);
        }
        $data['huella'] = hash('sha256', json_encode([$tipo, $data['conciliacion'], $secciones], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $data;
    }

    public static function seal(array $c): string
    {
        if (!$c['lote_oficial']) {
            return 'LOTE NO DECLARADO OFICIAL';
        }
        return $c['estado'] === 'CERRADA' ? 'CONCILIACIÓN CERRADA' : 'EN REVISIÓN';
    }

    public static function canExportIds(CurrentUser $user): bool
    {
        return $user->can('reportes.exportar_sensible') && $user->can('empleados.ver_sensible');
    }

    private static function sec(string $titulo, array $cols, array $filas, ?array $totales = null, ?string $nota = null): array
    {
        return ['titulo' => $titulo, 'columnas' => $cols, 'filas' => $filas, 'totales' => $totales, 'nota' => $nota];
    }

    /** @return list<array<string,mixed>> */
    private function resumen(array $c, bool $conIncidencias = true): array
    {
        $t = $c['totales']['TOTAL'] ?? [];
        $rs = $c['resumen'];
        $cifras = self::sec('Cifras principales', [['Concepto', 'text'], ['Valor', 'text']], [
            ['Total RH (neto autorizado)', money_plain($t['rrhh']['neto'] ?? null)],
            ['Total Tesorería (dispersado de nómina)', money_plain($t['tesoreria_nomina']['total'] ?? null)],
            ['Diferencia (pagado − RH)', money_plain($t['diferencia_global_nomina'] ?? null)],
            ['Identificado', money_plain($t['tesoreria_nomina']['identificado'] ?? null)],
            ['Pendiente de identificar', money_plain($t['tesoreria_nomina']['pendiente_identidad'] ?? null)],
            ['% conciliado (personas)', ($rs['pct_conciliado'] ?? '—') . ' %'],
            ['% pendiente de identidad (personas)', ($rs['pct_pendiente_identidad'] ?? '—') . ' %'],
            ['% del neto esperado en personas correctas', ($rs['pct_monto_conciliado'] ?? '—') . ' %'],
            ['Tolerancia', money_plain($c['tolerancia'])],
        ], null, '% conciliado = personas pagadas correctamente ÷ personas evaluables (se excluyen sólo las que no tenían pago que realizar); los pendientes de identificar no cuentan como correctos ni como error.');
        $estados = [];
        foreach ($rs['por_estado'] ?? [] as $k => $n) {
            $estados[] = [status_label((string) $k), (int) $n];
        }
        $inc = $this->db->fetchAll(
            "SELECT severidad, count(*) FILTER (WHERE estado IN ('ABIERTA', 'EN_REVISION')) AS abiertas,
                    count(*) FILTER (WHERE estado = 'ACEPTADA_JUSTIFICADA') AS justificadas, count(*) FILTER (WHERE estado = 'RESUELTA') AS resueltas
               FROM conciliacion_incidencias WHERE conciliacion_id = :id GROUP BY severidad
              ORDER BY CASE severidad WHEN 'CRITICA' THEN 0 WHEN 'ERROR' THEN 1 WHEN 'ADVERTENCIA' THEN 2 ELSE 3 END", ['id' => $c['id']]);
        [$pensRes] = $this->pensiones($c, false);
        $out = [
            $cifras,
            $this->grupos($c),
            $this->bancos($c),
            $pensRes,
            self::sec('Personas por estado', [['Estado', 'text'], ['Personas', 'int']], $estados),
        ];
        if (!$conIncidencias) {
            return $out;
        }
        return [...$out,
            self::sec('Incidencias', [['Severidad', 'text'], ['Abiertas o en revisión', 'int'], ['Aceptadas (justificadas)', 'int'], ['Resueltas', 'int']],
                array_map(static fn ($r) => [status_label('SEV_' . $r['severidad']), (int) $r['abiertas'], (int) $r['justificadas'], (int) $r['resueltas']], $inc)),
        ];
    }

    private function empleados(array $c, ?string $where, bool $sensible): array
    {
        $cols = self::EMP_COLS;
        if ($sensible) {
            $cols[] = ['RFC', 'text'];
            $cols[] = ['CURP', 'text'];
        }
        $rows = $this->db->fetchAll(
            'SELECT ce.numero_empleado, e.nombre_completo, ce.grupo, ce.area, ce.neto_esperado, ce.pagado_santander, ce.pagado_bbva, ce.pagado_interbancaria,
                    ce.pagado_otros, ce.pagado_total, ce.diferencia, ce.estado, ce.alerta, e.rfc, e.curp
               FROM conciliacion_empleados ce JOIN empleados e ON e.id = ce.empleado_id
              WHERE ce.conciliacion_id = :id' . ($where ? " AND $where" : '') . ' ORDER BY ce.grupo, ce.numero_empleado', ['id' => $c['id']]);
        $tot = ['0', '0', '0', '0', '0', '0', '0'];
        $filas = [];
        foreach ($rows as $r) {
            $f = [$r['numero_empleado'], $r['nombre_completo'], self::grupo($r['grupo']), $r['area'], $r['neto_esperado'], $r['pagado_santander'], $r['pagado_bbva'],
                  $r['pagado_interbancaria'], $r['pagado_otros'], $r['pagado_total'], $r['diferencia'], status_label((string) $r['estado']),
                  $r['alerta'] ? status_label((string) $r['alerta']) : null];
            foreach ([4, 5, 6, 7, 8, 9, 10] as $i => $col) {
                $tot[$i] = bcadd($tot[$i], (string) $f[$col], 2);
            }
            if ($sensible) {
                $f[] = $r['rfc'];
                $f[] = $r['curp'];
            }
            $filas[] = $f;
        }
        $totales = ['Totales', count($filas) . ' persona(s)', null, null, ...$tot, null, null];
        if ($sensible) {
            array_push($totales, null, null);
        }
        return self::sec('Personas', $cols, $filas, $totales);
    }

    /** @return list<array<string,mixed>> */
    private function pendientes(array $c): array
    {
        $pagos = $this->db->fetchAll(
            "SELECT f.tipo, r.id, r.banco, r.nombre_original, r.cuenta_ultimos4, f.importe, r.asociacion_estado, r.asociacion_confianza, a.nombre_original AS archivo, r.fila_origen
               FROM conciliacion_fuentes f JOIN registros_tesoreria r ON r.id = f.registro_id JOIN archivos_importados a ON a.id = f.archivo_id
              WHERE f.conciliacion_id = :id AND f.tipo IN ('PENDIENTE_NOMINA', 'PENDIENTE_PENSION') ORDER BY f.tipo, r.banco, f.importe DESC, r.id", ['id' => $c['id']]);
        $tot = '0';
        $filas = [];
        foreach ($pagos as $p) {
            $tot = bcadd($tot, (string) $p['importe'], 2);
            $filas[] = [$p['tipo'] === 'PENDIENTE_PENSION' ? 'Pensión' : 'Nómina', $p['banco'], '#' . $p['id'], $p['nombre_original'],
                        AccountVault::mask($p['cuenta_ultimos4']), $p['importe'], status_label((string) $p['asociacion_estado']),
                        status_label((string) $p['asociacion_confianza']), $p['archivo'] . ' · fila ' . $p['fila_origen']];
        }
        $personas = $this->empleados($c, "ce.estado IN ('PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD')", false);
        $personas['titulo'] = 'Personas cuyo faltante explicaría un pago pendiente (sin asignarlo)';
        return [
            self::sec('Pagos dispersados sin persona identificada', [['Tipo', 'text'], ['Banco', 'text'], ['Registro', 'text'], ['Nombre en el archivo', 'text'],
                ['Cuenta', 'text'], ['Importe', 'money'], ['Estado', 'text'], ['Confianza', 'text'], ['Origen', 'text']], $filas,
                ['Total', null, null, count($filas) . ' pago(s)', null, $tot, null, null, null],
                'Estos importes cuentan en el total de su banco pero no se asignan a ninguna persona hasta confirmar su identidad.'),
            $personas,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function pensiones(array $c, bool $detalle = true): array
    {
        $filas = [];
        $noDisp = array_column($this->db->fetchAll(
            "SELECT grupo, sum(retenido - dispersado) AS s FROM conciliacion_pensiones WHERE conciliacion_id = :id AND estado = 'NO_DISPERSADA' GROUP BY grupo",
            ['id' => $c['id']]), 's', 'grupo');
        foreach ($c['totales'] as $g => $b) {
            $p = $b['pensiones'] ?? [];
            $nd = $g === 'TOTAL' ? (string) array_reduce($noDisp, static fn ($acc, $v) => bcadd((string) $acc, (string) $v, 2), '0') : (string) ($noDisp[$g] ?? '0');
            $filas[] = [self::grupo($g), $p['retenido'] ?? null, $p['dispersado'] ?? null, $p['identificado'] ?? null, $p['pendiente_identidad'] ?? null, $nd,
                        isset($p['retenido'], $p['dispersado']) ? bcsub((string) $p['dispersado'], (string) $p['retenido'], 2) : null];
        }
        $res = self::sec('Pensiones por grupo', [['Grupo', 'text'], ['Retenido', 'money'], ['Dispersado', 'money'], ['Identificado', 'money'], ['Pendiente de identificar', 'money'],
            ['No dispersado (identificable)', 'money'], ['Diferencia (dispersado − retenido)', 'money']], $filas, null,
            'La pensión se concilia por separado del neto de nómina. «No dispersado» suma el faltante de las personas en estado NO DISPERSADA.');
        if (!$detalle) {
            return [$res];
        }
        $rows = $this->db->fetchAll(
            'SELECT cp.numero_empleado, e.nombre_completo, cp.grupo, cp.retenido, cp.dispersado, cp.diferencia, cp.estado, cp.alerta
               FROM conciliacion_pensiones cp JOIN empleados e ON e.id = cp.empleado_id WHERE cp.conciliacion_id = :id
              ORDER BY (cp.estado = \'CORRECTA\'), cp.grupo, cp.numero_empleado', ['id' => $c['id']]);
        return [$res, self::sec('Detalle por persona', [['Número', 'text'], ['Nombre', 'text'], ['Grupo', 'text'], ['Retenido', 'money'], ['Dispersado', 'money'],
            ['Diferencia', 'money'], ['Estado', 'text'], ['Alerta', 'text']],
            array_map(static fn ($r) => [$r['numero_empleado'], $r['nombre_completo'], self::grupo($r['grupo']), $r['retenido'], $r['dispersado'], $r['diferencia'],
                status_label((string) $r['estado']), $r['alerta'] ? status_label((string) $r['alerta']) : null], $rows))];
    }

    private function bancos(array $c): array
    {
        $t = $c['totales']['TOTAL']['tesoreria_nomina'] ?? [];
        $poliza = array_column($this->db->fetchAll(
            "SELECT detalle->>'banco_dispersion' AS banco, sum(valor_a) AS s FROM conciliacion_controles
              WHERE conciliacion_id = :id AND codigo = 'POLIZA_EGRESO_VS_DISPERSION' AND categoria = 'NOMINA' AND detalle->>'banco_dispersion' IS NOT NULL
              GROUP BY 1", ['id' => $c['id']]), 's', 'banco');
        $filas = [];
        $tot = ['0', '0', '0', '0'];
        $difPol = '0';
        $dispPol = '0';
        foreach (['SANTANDER', 'BBVA', 'INTERBANCARIA', 'OTROS'] as $b) {
            $disp = (string) ($t['por_banco'][$b] ?? '0');
            $ident = (string) ($t['por_banco_identificado'][$b] ?? '0');
            if (bccomp($disp, '0', 2) === 0 && !isset($poliza[$b])) {
                continue;
            }
            $pol = isset($poliza[$b]) ? (string) $poliza[$b] : null;
            $pend = bcsub($disp, $ident, 2);
            $filas[] = [bank_label($b), $pol, $disp, $ident, $pend, $pol !== null ? bcsub($pol, $disp, 2) : null];
            foreach ([$pol ?? '0', $disp, $ident, $pend] as $i => $v) {
                $tot[$i] = bcadd($tot[$i], $v, 2);
            }
            if ($pol !== null) {
                $difPol = bcadd($difPol, bcsub($pol, $disp, 2), 2);
            }
        }
        return self::sec('Nómina por banco', [['Banco', 'text'], ['Póliza de egreso', 'money'], ['Dispersión', 'money'], ['Identificado', 'money'], ['Pendiente', 'money'],
            ['Diferencia (póliza − dispersión)', 'money']], $filas, ['Total', ...$tot, $difPol],
            'La póliza de egreso es la hoja relacionada con cada dispersión (nivel 3); sin relación demostrable queda vacía. '
            . 'La diferencia total suma sólo los bancos con póliza relacionada.');
    }

    private function grupos(array $c): array
    {
        $filas = [];
        foreach ($c['totales'] as $g => $b) {
            $e = $b['empleados'] ?? [];
            $filas[] = [self::grupo($g), $b['rrhh']['neto'] ?? null, $b['tesoreria_nomina']['total'] ?? null, $b['diferencia_global_nomina'] ?? null,
                        (int) ($e['CORRECTO'] ?? 0), (int) (($e['PENDIENTE_IDENTIDAD'] ?? 0) + ($e['CONFLICTO_IDENTIDAD'] ?? 0)), (int) ($e['NO_PAGADO'] ?? 0),
                        (int) ($e['PAGO_MENOR'] ?? 0), (int) (($e['PAGO_MAYOR'] ?? 0) + ($e['POSIBLE_PAGO_DUPLICADO'] ?? 0))];
        }
        return self::sec('RH contra Tesorería por grupo', [['Grupo', 'text'], ['RH', 'money'], ['Tesorería', 'money'], ['Diferencia', 'money'], ['Correctos', 'int'],
            ['Pendientes de identidad', 'int'], ['No pagados', 'int'], ['Pagos menores', 'int'], ['Pagos mayores', 'int']], $filas);
    }

    private function incidencias(array $c, CurrentUser $user): array
    {
        if (!$user->can('incidencias.ver')) {
            throw HttpException::forbidden();
        }
        $rows = $this->db->fetchAll(
            "SELECT i.severidad, i.nivel, i.tipo, e.numero_empleado, e.nombre_completo, i.grupo, i.banco, i.importe, i.estado, i.mensaje, i.comentario
               FROM conciliacion_incidencias i LEFT JOIN empleados e ON e.id = i.empleado_id WHERE i.conciliacion_id = :id
              ORDER BY CASE i.severidad WHEN 'CRITICA' THEN 0 WHEN 'ERROR' THEN 1 WHEN 'ADVERTENCIA' THEN 2 ELSE 3 END, i.nivel, abs(coalesce(i.importe, 0)) DESC, i.id",
            ['id' => $c['id']]);
        return self::sec('Incidencias', [['Severidad', 'text'], ['Nivel', 'int'], ['Tipo', 'text'], ['Persona', 'text'], ['Grupo', 'text'], ['Banco', 'text'],
            ['Importe', 'money'], ['Estado', 'text'], ['Descripción', 'text'], ['Comentario', 'text']],
            array_map(static fn ($r) => [status_label('SEV_' . $r['severidad']), (int) $r['nivel'], status_label((string) $r['tipo']),
                $r['numero_empleado'] ? $r['numero_empleado'] . ' ' . $r['nombre_completo'] : null, $r['grupo'] ? self::grupo($r['grupo']) : null, $r['banco'],
                $r['importe'], status_label((string) $r['estado']), $r['mensaje'], $r['comentario']], $rows));
    }

    /** @return list<array<string,mixed>> */
    private function comparativo(array $c, ?int $otra): array
    {
        if ($otra === null) {
            throw new ValidationException(['otra' => 'Elija la ejecución con la que se compara.']);
        }
        $b = $this->conc->find($otra);
        if ((int) $b['lote_id'] !== (int) $c['lote_id']) {
            throw HttpException::notFound();
        }
        $r = $this->cmp->compare($c, $b);
        $va = 'v' . $r['a']['numero'];
        $vb = 'v' . $r['b']['numero'];
        $est = [];
        foreach ($r['estados'] as $k => [$x, $y]) {
            $est[] = [status_label((string) $k), (int) ($x ?? 0), (int) ($y ?? 0), (int) ($y ?? 0) - (int) ($x ?? 0)];
        }
        $mon = [];
        foreach ($r['metricas'] as $l => [$x, $y]) {
            $mon[] = [$l, $x, $y, $x !== null && $y !== null ? bcsub((string) $y, (string) $x, 2) : null];
        }
        return [
            self::sec('Personas por estado', [['Estado', 'text'], [$va, 'int'], [$vb, 'int'], ['Cambio', 'int']], $est),
            self::sec('Montos', [['Concepto', 'text'], [$va, 'money'], [$vb, 'money'], ['Cambio', 'money']], $mon),
            self::sec('Personas que cambiaron', [['Número', 'text'], ['Nombre', 'text'], ["Estado $va", 'text'], ["Diferencia $va", 'money'], ["Estado $vb", 'text'],
                ["Diferencia $vb", 'money'], ['Qué cambió', 'text']],
                array_map(static fn ($x) => [$x['numero_empleado'], $x['nombre_completo'], $x['estado_a'] ? status_label((string) $x['estado_a']) : null, $x['dif_a'],
                    $x['estado_b'] ? status_label((string) $x['estado_b']) : null, $x['dif_b'], implode(' ', $x['motivos'])], $r['cambios']),
                null, $r['total_cambios'] > $r['limite'] ? 'Se muestran las primeras ' . $r['limite'] . ' de ' . $r['total_cambios'] . ' personas.' : null),
        ];
    }

    private static function grupo(?string $g): string
    {
        return match ($g) {
            'TOTAL' => 'Total', 'POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos', null => '—', default => ucfirst(strtolower($g)),
        };
    }
}
