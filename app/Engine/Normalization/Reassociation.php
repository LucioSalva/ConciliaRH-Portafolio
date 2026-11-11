<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\Beneficiaries;
use ConciliaRH\Engine\Identity\Evidence;
use ConciliaRH\Engine\Identity\History;
use ConciliaRH\Engine\Identity\Matcher;
use ConciliaRH\Engine\Identity\PersonIndex;
use ConciliaRH\Engine\Jobs\WorkerAudit;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Worker\JobContext;

final class Reassociation
{
    public const LOCK_KEY = 4850181374600825153;
    public const MATCHING_VERSION = 1;

    /** @param list<int> $imports @return array<string,int> */
    public static function run(JobContext $ctx, array $imports): array
    {
        if ($imports === []) {
            return [];
        }
        return $ctx->db->transaction(static function (Database $db) use ($ctx, $imports): array {
            $changed = [];
            $bump = static function (string $k, int $n = 1) use (&$changed): void {
                $changed[$k] = ($changed[$k] ?? 0) + $n;
            };
            $db->fetchValue('SELECT pg_advisory_xact_lock(:k)', ['k' => self::LOCK_KEY]);
            [$idx] = IndexLoader::load($db);
            $imps = $db->fetchAll(
                "SELECT n.id, n.tipo_registro, n.periodo_id, n.lote_id, pe.fecha_fin::text AS fecha_fin, pi.codigo AS perfil, pi.familia
                   FROM importaciones_normalizadas n
                   JOIN perfiles_importacion pi ON pi.id = n.perfil_id
                   JOIN periodos_nomina pe ON pe.id = n.periodo_id AND pe.estado = 'ABIERTO'
                   JOIN lotes_importacion l ON l.id = n.lote_id AND l.estado <> 'ANULADO'
                  WHERE n.id = ANY(CAST(:ids AS bigint[])) AND n.estado = 'VIGENTE' ORDER BY n.id", ['ids' => BatchInsert::arrayLiteral($imports)]);
            $pend = BatchInsert::arrayLiteral(Matcher::PENDIENTES);
            foreach ($imps as $imp) {
                $ctx->check();
                $tipo = (string) $imp['tipo_registro'];
                $incs = [];
                $hist = [];
                if ($tipo === 'RRHH') {
                    $regs = $db->fetchAll(
                        'SELECT id, asociacion_estado, asociacion_version, numero_empleado, numero_valido, rfc, rfc_valido, curp, curp_valida,
                                nombre_normalizado, rechazados FROM registros_rrhh
                          WHERE importacion_id = :i AND asociacion_estado = ANY(CAST(:p AS text[])) ORDER BY hoja_indice, fila_origen, id',
                        ['i' => (int) $imp['id'], 'p' => $pend]);
                } elseif ($tipo === 'TESORERIA') {
                    $regs = $db->fetchAll(
                        'SELECT id, asociacion_estado, asociacion_version, numero_empleado, numero_valido, rfc, rfc_valido, NULL AS curp,
                                false AS curp_valida, nombre_normalizado, rechazados, cuenta_hmac, categoria, periodo_id, asociacion_evidencia
                           FROM registros_tesoreria WHERE importacion_id = :i AND asociacion_estado = ANY(CAST(:p AS text[]))
                          ORDER BY hoja_indice, fila_origen, id', ['i' => (int) $imp['id'], 'p' => $pend]);
                } else {
                    continue;
                }
                $table = $tipo === 'RRHH' ? 'registros_rrhh' : 'registros_tesoreria';
                $accounts = [];
                foreach ($regs as $r) {
                    $pension = ($r['categoria'] ?? null) === 'PENSION';
                    $ev = Evidence::of($r['numero_empleado'], (bool) $r['numero_valido'], $r['rfc'], (bool) $r['rfc_valido'], $r['curp'],
                        (bool) $r['curp_valida'], $r['nombre_normalizado'], $r['cuenta_hmac'] ?? null, $pension, Beneficiaries::intArray($r['rechazados']));
                    $m = $tipo === 'RRHH' ? Matcher::matchRrhh($ev, $idx) : Matcher::match($ev, $idx);
                    $m ??= Matcher::match($ev, $idx);
                    $evPrev = Json::decode($r['asociacion_evidencia'] ?? null) ?? [];
                    if ($pension && $m->estado === 'NO_IDENTIFICADO' && in_array($r['asociacion_estado'], Beneficiaries::EDITABLES, true)
                        && in_array($evPrev['motivo'] ?? null, Beneficiaries::MOTIVOS_PROPIOS, true)) {
                        continue;
                    }
                    if ($m->estado === $r['asociacion_estado'] && $m->estado !== 'ASOCIADO_AUTOMATICO') {
                        $db->execute("UPDATE $table SET asociacion_evidencia = CAST(:e AS jsonb) WHERE id = :id AND asociacion_version = :v",
                            ['e' => Json::encode(Json::obj($m->evidencia)), 'id' => (int) $r['id'], 'v' => (int) $r['asociacion_version']]);
                        continue;
                    }
                    $upd = $db->fetchOne(
                        "UPDATE $table SET empleado_id = :e, asociacion_estado = :s, asociacion_metodo = :m, asociacion_confianza = :c,
                                asociacion_evidencia = CAST(:ev AS jsonb), asociacion_version = asociacion_version + 1
                          WHERE id = :id AND asociacion_version = :v AND asociacion_estado = ANY(CAST(:p AS text[])) RETURNING id",
                        ['e' => $m->empleadoId, 's' => $m->estado, 'm' => $m->metodo, 'c' => $m->confianza, 'ev' => Json::encode(Json::obj($m->evidencia)),
                         'id' => (int) $r['id'], 'v' => (int) $r['asociacion_version'], 'p' => $pend]);
                    if ($upd === null) {
                        continue;
                    }
                    $bump($r['asociacion_estado'] . '->' . $m->estado);
                    $hist[] = [$tipo, (int) $r['id'], 'REASOCIACION', $r['asociacion_estado'], $m->estado, null, $m->empleadoId, $m->metodo, $m->confianza,
                        null, $ctx->job->id, null];
                    $db->execute("UPDATE incidencias_identidad SET estado = 'RESUELTA', resuelto_at = now(),
                                         resolucion = 'Re-asociación automática: la evidencia cambió.'
                                   WHERE registro_tipo = :t AND registro_id = :r AND estado = 'ABIERTA'", ['t' => $tipo, 'r' => (int) $r['id']]);
                    array_push($incs, ...IdentityIncident::fromMatch($m, $tipo, (int) $r['id']));
                    if ($tipo === 'TESORERIA' && $m->estado === 'ASOCIADO_AUTOMATICO' && ($r['cuenta_hmac'] ?? '') !== '') {
                        $accounts[] = [$r, $m, $pension];
                    }
                }
                foreach ($accounts as [$r, $m, $pension]) {
                    $uso = $pension ? 'PENSION_BENEFICIARIO' : 'NOMINA';
                    $row = $db->fetchOne("SELECT nombre_original, banco AS banco_cuenta, tipo_cuenta, cuenta_hmac, cuenta_ultimos4,
                                                 encode(cuenta_cifrada, 'hex') AS cuenta_cifrada_hex, cuenta_key_id
                                            FROM registros_tesoreria WHERE id = :id", ['id' => (int) $r['id']]);
                    $row['cuenta_cifrada'] = $row['cuenta_cifrada_hex'] !== null ? hex2bin((string) $row['cuenta_cifrada_hex']) : null;
                    [$res] = AccountRegistry::register($db, (int) $r['id'], (int) $m->empleadoId, $uso, (int) $r['periodo_id'], (string) $imp['fecha_fin'], $row);
                    if ($res === 'NUEVA') {
                        $idx->accounts[PersonIndex::accountKey((string) $r['cuenta_hmac'], $uso)] = (int) $m->empleadoId;
                        $bump('cuentas_nuevas');
                    } elseif ($res === 'CONFLICTO') {
                        AccountRegistry::toConflict($db, (int) $r['id'], (int) $m->empleadoId, 'CUENTA_DE_OTRA_PERSONA');
                        $incs[] = new IdentityIncident('CLABE_CONFLICTO', 'ALTA', ['uso' => $uso, 'empleado' => (int) $m->empleadoId], 'TESORERIA',
                            (int) $r['id'], (int) $m->empleadoId);
                        $hist[] = ['TESORERIA', (int) $r['id'], 'REASOCIACION', 'ASOCIADO_AUTOMATICO', 'CONFLICTO', (int) $m->empleadoId, null, null,
                            'NINGUNA', null, $ctx->job->id, 'La cuenta del registro ya pertenece a otra persona.'];
                        $bump('ASOCIADO_AUTOMATICO->CONFLICTO');
                    }
                }
                if ($tipo === 'TESORERIA') {
                    $ben = Beneficiaries::link($db, (int) $imp['id']);
                    if (($ben['enlazados'] ?? 0) > 0) {
                        $bump('beneficiarios_enlazados', $ben['enlazados']);
                    }
                }
                IdentityIncident::save($db, (int) $imp['id'], $incs);
                History::insert($db, $hist);
                $prevJson = $db->fetchValue('SELECT metricas FROM importaciones_normalizadas WHERE id = :id', ['id' => (int) $imp['id']]);
                $prev = is_string($prevJson) ? (array) json_decode($prevJson, false, 512, JSON_THROW_ON_ERROR) : [];
                $db->execute('UPDATE importaciones_normalizadas SET metricas = CAST(:m AS jsonb) WHERE id = :id',
                    ['m' => Json::encode(Json::obj(ImportMetrics::compute($db, (int) $imp['id'], $tipo, $prev))), 'id' => (int) $imp['id']]);
            }
            $lots = array_values(array_unique(array_map(static fn ($i) => (int) $i['lote_id'], $imps)));
            sort($lots);
            foreach ($lots as $lote) {
                foreach (Beneficiaries::suggest($db, $lote, $ctx->job->id) as $k => $v) {
                    $bump('pension:' . $k, $v);
                }
            }
            if ($changed !== []) {
                WorkerAudit::record($db, 'asociaciones.reasociar', 'importaciones_normalizadas', mb_substr(implode(',', $imports), 0, 200), null,
                    Json::obj($changed), ['proceso_id' => $ctx->job->id, 'matching_version' => self::MATCHING_VERSION]);
            }
            return $changed;
        });
    }
}
