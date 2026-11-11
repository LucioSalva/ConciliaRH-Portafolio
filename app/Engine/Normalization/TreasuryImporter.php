<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Engine\Identity\Evidence;
use ConciliaRH\Engine\Identity\Ident;
use ConciliaRH\Engine\Identity\Matcher;
use ConciliaRH\Engine\Identity\PersonIndex;
use ConciliaRH\Engine\Identity\Records;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;

final class TreasuryImporter
{
    public function __construct(private readonly ImportContext $c)
    {
    }

    public static function accountType(string $banco): string
    {
        return $banco === 'INTERBANCARIA' ? 'CLABE' : 'CUENTA';
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $c = $this->c;
        $f = $c->file;
        $pension = str_contains((string) ($f['perfil'] ?? ''), 'PENSION');
        $banco = Records::BANCO_POR_FAMILIA[(string) $f['familia']] ?? 'INTERBANCARIA';
        $ids = Sequence::ids($c->db, 'registros_tesoreria', count($c->staging));
        $regRows = [];
        $pending = [];
        foreach ($c->staging as $i => $s) {
            if ($i % 500 === 0) {
                $c->job->check();
            }
            $rid = $ids[$i];
            $t = Records::tesoreriaRow($banco, $s['datos']);
            /** @var Ident $idn */
            $idn = $t['ident'];
            [$prevD, $rech] = $c->popCarry((string) $s['hash_registro'], $s, $idn);
            $ev = Evidence::of($idn->numero, $idn->numeroValido, $idn->rfc, $idn->rfcValido, null, false, $idn->nombreKey, $s['cuenta_hmac'], $pension, $rech);
            [$m, $manual] = $c->applyCarry($prevD, Matcher::match($ev, $c->idx));
            if ($m->estado === 'ASOCIADO_AUTOMATICO' && ($s['cuenta_hmac'] ?? '') !== '') {
                $pending[] = [$rid, $m, $s, $t];
            }
            array_push($c->incs, ...IdentityIncident::fromMatch($m, 'TESORERIA', $rid));
            if ($s['importe_estado'] === 'INVALIDO') {
                $c->incs[] = new IdentityIncident('IMPORTE_INVALIDO', 'ADVERTENCIA', [], 'TESORERIA', $rid);
            }
            $c->histAuto('TESORERIA', $rid, $m, $manual);
            $regRows[] = [$rid, $c->importacionId, (int) $f['id'], (int) $f['analisis_vigente_id'], (int) $f['periodo_id'], (int) $f['lote_id'],
                (int) $f['perfil_id'], $pension ? 'PENSION' : 'NOMINA', $banco, (int) $s['hoja_id'], (int) $s['hoja_indice'],
                Py::sub((string) $s['hoja_nombre'], 255), (int) $s['fila_origen'], (bool) $s['oculta'], $s['hash_registro'], $idn->numeroOriginal,
                $idn->numero, $idn->numeroValido, $idn->nombreOriginal, $idn->nombreKey, $t['apellido_paterno'], $t['apellido_materno'], $t['nombres'],
                $idn->rfcOriginal, $idn->rfc, $idn->rfcValido, ($s['cuenta_hmac'] ?? '') !== '' ? self::accountType($banco) : null, $s['cuenta_hmac'],
                $s['cuenta_ultimos4'], $s['cuenta_cifrada'], $s['cuenta_key_id'], $s['cuenta_hmac_key_id'], $t['banco_receptor'], $t['concepto'],
                $t['referencia'], $s['importe'], $s['importe_estado'], $m->empleadoId, $m->estado, $m->metodo, $m->confianza, Json::obj($m->evidencia),
                $rech, $manual !== null ? $manual['asociado_por'] : null, $manual !== null ? $manual['asociado_at'] : null];
        }
        BatchInsert::run($c->db, 'registros_tesoreria', ['id', 'importacion_id', 'archivo_id', 'analisis_id', 'periodo_id', 'lote_id', 'perfil_id',
            'categoria', 'banco', 'hoja_id', 'hoja_indice', 'hoja_nombre', 'fila_origen', 'oculta', 'hash_registro', 'numero_original', 'numero_empleado',
            'numero_valido', 'nombre_original', 'nombre_normalizado', 'apellido_paterno', 'apellido_materno', 'nombres', 'rfc_original', 'rfc', 'rfc_valido',
            'tipo_cuenta', 'cuenta_hmac', 'cuenta_ultimos4', 'cuenta_cifrada', 'cuenta_key_id', 'cuenta_hmac_key_id', 'banco_receptor', 'concepto',
            'referencia', 'importe', 'importe_estado', 'empleado_id', 'asociacion_estado', 'asociacion_metodo', 'asociacion_confianza',
            'asociacion_evidencia', 'rechazados', 'asociado_por', 'asociado_at'], $regRows,
            ['cuenta_cifrada' => 'bytea', 'asociacion_evidencia' => 'jsonb', 'rechazados' => 'bigint[]', 'asociado_at' => 'timestamptz']);
        [$nuevas, $vistas, $sinCifrar] = $this->accounts($pending, $pension, $banco);
        return ['registros' => count($regRows), 'banco' => $banco, 'categoria' => $pension ? 'PENSION' : 'NOMINA', 'cuentas_nuevas' => $nuevas,
                'cuentas_vistas' => $vistas, 'cuentas_sin_cifrado' => $sinCifrar];
    }

    /**
     * @param list<array{0:int,1:\ConciliaRH\Engine\Identity\MatchResult,2:array<string,mixed>,3:array<string,mixed>}> $items
     * @return array{0:int,1:int,2:int}
     */
    private function accounts(array $items, bool $pension, string $banco): array
    {
        $c = $this->c;
        $f = $c->file;
        $uso = $pension ? 'PENSION_BENEFICIARIO' : 'NOMINA';
        $nuevas = $vistas = $sinCifrar = 0;
        foreach ($items as [$rid, $m, $s, $t]) {
            $row = [...$s, 'nombre_original' => $t['ident']->nombreOriginal, 'tipo_cuenta' => self::accountType($banco), 'banco_cuenta' => $banco];
            [$res, $had] = AccountRegistry::register($c->db, $rid, (int) $m->empleadoId, $uso, (int) $f['periodo_id'], (string) $f['fecha_fin'], $row);
            if ($res === 'NUEVA') {
                $c->idx->accounts[PersonIndex::accountKey((string) $s['cuenta_hmac'], $uso)] = (int) $m->empleadoId;
                $nuevas++;
                if ($had) {
                    $c->incs[] = new IdentityIncident('CUENTA_NUEVA', 'INFO', ['uso' => $uso, 'cuentas_activas_previas' => $had], 'TESORERIA', $rid,
                        (int) $m->empleadoId);
                }
            } elseif ($res === 'VISTA') {
                $vistas++;
            } elseif ($res === 'SIN_CIFRADO') {
                $sinCifrar++;
            } else {
                AccountRegistry::toConflict($c->db, $rid, (int) $m->empleadoId, 'CUENTA_DE_OTRA_PERSONA');
                $c->incs[] = new IdentityIncident('CLABE_CONFLICTO', 'ALTA', ['uso' => $uso, 'empleado' => (int) $m->empleadoId], 'TESORERIA', $rid,
                    (int) $m->empleadoId);
                $c->hist[] = ['TESORERIA', $rid, 'REASOCIACION', 'ASOCIADO_AUTOMATICO', 'CONFLICTO', (int) $m->empleadoId, null, null, 'NINGUNA', null,
                    $c->jobId(), 'La cuenta del registro ya pertenece a otra persona.'];
            }
        }
        return [$nuevas, $vistas, $sinCifrar];
    }
}
