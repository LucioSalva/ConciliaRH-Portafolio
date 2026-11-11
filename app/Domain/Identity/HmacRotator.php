<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use RuntimeException;

/**
 * Rotación de la llave HMAC de cuentas/CLABE (docs/HMAC_ROTATION.md). SÓLO por CLI y con el rol propietario.
 *
 * El HMAC es una CLAVE DE BÚSQUEDA que se compara por igualdad entre tablas: no puede cambiar fila por fila sobre la
 * columna viva. Se rota en dos fases:
 *  1. PREPARACIÓN (reanudable, lotes transaccionales): para cada fila se descifra la cuenta, se VERIFICA que la huella
 *     viva corresponde a ese valor con la llave anterior (dato consistente) y se escribe la huella con la llave nueva en la
 *     columna sombra `cuenta_hmac_nueva`. La columna viva no cambia: el matching sigue igual durante toda la preparación.
 *     Una fila con huella y sin valor cifrado toma la sombra de otra fila con la MISMA huella anterior (correspondencia
 *     comprobada); si no existe, se informa y el corte se niega (salvo --descartar-sin-cifrado, explícito y auditado).
 *  2. CORTE (una transacción): candado global del worker + bloqueo de ESCRITURA de las tres tablas (las lecturas siguen),
 *     completa lo pendiente, exige que la correspondencia anterior→nueva sea BIYECTIVA en las tres tablas juntas (ninguna
 *     colisión ni inconsistencia), cambia la llave activa y copia la sombra a la columna viva. Antes del commit todos ven
 *     las huellas anteriores; después, todas las nuevas: nunca hay una mezcla visible.
 * Nunca imprime, registra ni audita llaves, huellas ni valores: sólo tablas, key_id, conteos e ids de fila.
 */
final class HmacRotator
{
    /** Candado consultivo global que toma el worker al normalizar (misma llave que fn_confirmar_cuenta y la revisión masiva) */
    public const WORKER_LOCK = 4850181374600825153;
    /** Candado de sesión: una sola CLI de rotación HMAC a la vez */
    public const CLI_LOCK = 4850181374600825201;

    /** tabla => [huella, key_id de la huella, sombra, cifrado, key_id del cifrado] — las cuentas primero (siempre cifradas) */
    public const TABLES = [
        'cuentas_bancarias' => ['cuenta_hmac', 'hmac_key_id', 'cuenta_hmac_nueva', 'cuenta_cifrada', 'key_id'],
        'registros_tesoreria' => ['cuenta_hmac', 'cuenta_hmac_key_id', 'cuenta_hmac_nueva', 'cuenta_cifrada', 'cuenta_key_id'],
        'staging_registros' => ['cuenta_hmac', 'cuenta_hmac_key_id', 'cuenta_hmac_nueva', 'cuenta_cifrada', 'cuenta_key_id'],
    ];

    /**
     * @param array<string,string> $hmacRing key_id => secreto HMAC
     * @param array<string,string> $encRing  key_id => llave AES de 32 bytes (para descifrar y recalcular)
     */
    public function __construct(private readonly Database $db, private readonly array $hmacRing, private readonly array $encRing)
    {
        foreach ($hmacRing as $id => $secret) {
            if (!preg_match('/^[a-z0-9_-]{1,16}$/', (string) $id) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
                throw new RuntimeException('Anillo HMAC inválido (cada secreto: 64 hexadecimales).');
            }
        }
    }

    /** Valor de verificación de una llave (no revela el secreto): detecta el mismo id con otro secreto. */
    public static function kcv(string $secret): string
    {
        return hash_hmac('sha256', 'conciliarh:kcv:v1', $secret);
    }

    public function active(): string
    {
        return (string) $this->db->fetchValue('SELECT key_id_activa FROM hmac_llaves_estado WHERE id = 1');
    }

    /** @return array{id:int,key_origen:string,key_destino:string}|null */
    public function inProgress(): ?array
    {
        $r = $this->db->fetchOne("SELECT id, key_origen, key_destino FROM hmac_rotaciones WHERE estado = 'PREPARANDO'");
        return $r === null ? null : ['id' => (int) $r['id'], 'key_origen' => (string) $r['key_origen'], 'key_destino' => (string) $r['key_destino']];
    }

    /**
     * @return array{activa:string,en_curso:?array,por_tabla:array<string,array<string,int>>,pendientes_sombra:array<string,int>,
     *               workers:list<array{id:string,llaves:list<string>}>,retirables:list<string>}
     */
    public function status(int $heartbeatTimeout = 120): array
    {
        $por = [];
        $pend = [];
        foreach (self::TABLES as $t => [$h, $k, $n]) {
            $por[$t] = [];
            foreach ($this->db->fetchAll("SELECT $k AS k, count(*) AS n FROM $t WHERE $h IS NOT NULL GROUP BY 1 ORDER BY 1") as $r) {
                $por[$t][(string) $r['k']] = (int) $r['n'];
            }
            $pend[$t] = (int) $this->db->fetchValue("SELECT count(*) FROM $t WHERE $h IS NOT NULL AND $n IS NULL");
        }
        $used = [];
        foreach ($por as $byKey) {
            foreach ($byKey as $id => $n) {
                $used[$id] = true;
            }
        }
        $active = $this->active();
        return [
            'activa' => $active,
            'en_curso' => $this->inProgress(),
            'por_tabla' => $por,
            'pendientes_sombra' => $pend,
            'workers' => $this->liveWorkers($heartbeatTimeout),
            'retirables' => array_values(array_filter(array_keys($this->hmacRing), static fn ($id) => $id !== $active && !isset($used[$id]))),
        ];
    }

    /**
     * Simulación completa sin escribir: verifica cada huella contra su valor cifrado, calcula la nueva y comprueba que la
     * correspondencia sea biyectiva (colisiones o datos inconsistentes = errores).
     * @return array{total:int,por_key:array<string,array<string,int>>,necesitan:array<string,int>,sin_cifrado:int,
     *               sin_cifrado_mapeables:int,errores:list<array{tabla:string,id:int,motivo:string}>,colisiones:int,
     *               workers_sin_destino:list<string>}
     */
    public function dryRun(string $to, int $batch = 1000, int $heartbeatTimeout = 120): array
    {
        $from = $this->assertRotation($to);
        $map = [];         // anterior => nueva
        $rev = [];         // nueva => anterior
        $noCipher = [];    // huellas anteriores sin valor cifrado
        $errors = [];
        $colisiones = 0;
        $total = 0;
        $need = [];
        foreach (self::TABLES as $t => [$h, $k, , $c, $ek]) {
            $need[$t] = 0;
            $last = 0;
            do {
                $rows = $this->db->fetchAll(
                    "SELECT id, $h AS h, $k AS k, $c AS cifrada, $ek AS key_id, cuenta_ultimos4 FROM $t
                      WHERE $h IS NOT NULL AND id > :last ORDER BY id LIMIT :n", ['last' => $last, 'n' => $batch]);
                foreach ($rows as $r) {
                    $last = (int) $r['id'];
                    $total++;
                    if ((string) $r['k'] === $to) {
                        continue;
                    }
                    $need[$t]++;
                    if ((string) $r['k'] !== $from) {
                        $errors[] = ['tabla' => $t, 'id' => $last, 'motivo' => 'LLAVE_HMAC_NO_ES_LA_ACTIVA'];
                        continue;
                    }
                    if ($r['cifrada'] === null) {
                        $noCipher[(string) $r['h']] = true;
                        continue;
                    }
                    try {
                        $new = $this->recompute($r, $from, $to);
                    } catch (RuntimeException $e) {
                        $errors[] = ['tabla' => $t, 'id' => $last, 'motivo' => $e->getMessage()];
                        continue;
                    }
                    $old = (string) $r['h'];
                    if ((isset($map[$old]) && $map[$old] !== $new) || (isset($rev[$new]) && $rev[$new] !== $old)) {
                        $colisiones++;
                        $errors[] = ['tabla' => $t, 'id' => $last, 'motivo' => 'CORRESPONDENCIA_NO_BIYECTIVA'];
                        continue;
                    }
                    $map[$old] = $new;
                    $rev[$new] = $old;
                }
            } while (count($rows) === $batch);
        }
        // Sombras que ya existen (preparación en curso) también cuentan como correspondencia conocida
        $mapeables = 0;
        foreach (array_keys($noCipher) as $old) {
            if (isset($map[$old]) || $this->shadowFor($old) !== null) {
                $mapeables++;
            }
        }
        $sinCifrado = 0;
        foreach (self::TABLES as $t => [$h, $k, , $c]) {
            $sinCifrado += (int) $this->db->fetchValue("SELECT count(*) FROM $t WHERE $h IS NOT NULL AND $c IS NULL AND $k IS DISTINCT FROM :to", ['to' => $to]);
        }
        return [
            'total' => $total,
            'por_key' => $this->status($heartbeatTimeout)['por_tabla'],
            'necesitan' => $need,
            'sin_cifrado' => $sinCifrado,
            'sin_cifrado_mapeables' => $mapeables,
            'sin_cifrado_huellas' => count($noCipher),
            'errores' => $errors,
            'colisiones' => $colisiones,
            'workers_sin_destino' => $this->workersWithout($to, $heartbeatTimeout),
        ];
    }

    /** Inicia (o retoma) la rotación hacia $to. Una sola en curso: otra con destino distinto se rechaza. */
    public function start(string $to, string $osUser): int
    {
        $from = $this->assertRotation($to);
        $cur = $this->inProgress();
        if ($cur !== null) {
            if ($cur['key_destino'] !== $to) {
                throw new RuntimeException("Ya hay una rotación en curso hacia {$cur['key_destino']}; reanúdela o cancélela.");
            }
            return $cur['id'];
        }
        return (int) $this->db->fetchValue(
            "INSERT INTO hmac_rotaciones (key_origen, key_destino, iniciada_por, kcv_destino) VALUES (:f, :t, :u, :k) RETURNING id",
            ['f' => $from, 't' => $to, 'u' => preg_replace('/[^A-Za-z0-9._-]/', '_', mb_substr($osUser !== '' ? $osUser : 'cli', 0, 64)),
             'k' => self::kcv($this->hmacRing[$to])]);
    }

    /**
     * Fase 1: calcula la sombra por lotes (cada lote una transacción, FOR UPDATE SKIP LOCKED). Idempotente y reanudable.
     * @param callable(string,int,int):void|null $progress
     * @return array{preparadas:array<string,int>,lotes:int,completa:bool,restantes:int,sin_mapa:int}
     */
    public function prepare(int $batch = 500, ?int $maxBatches = null, ?callable $progress = null): array
    {
        $rot = $this->inProgress() ?? throw new RuntimeException('No hay una rotación en curso (use --to=ID).');
        [$from, $to] = [$rot['key_origen'], $rot['key_destino']];
        $this->assertRotation($to);
        $done = array_fill_keys(array_keys(self::TABLES), 0);
        $batches = 0;
        // Primero las filas con valor cifrado (cuentas, registros, staging) y después las que sólo tienen huella
        foreach ([true, false] as $withCipher) {
            foreach (self::TABLES as $t => [$h, $k, $n, $c, $ek]) {
                $last = 0;
                while (true) {
                    if ($maxBatches !== null && $batches >= $maxBatches) {
                        return ['preparadas' => $done, 'lotes' => $batches, 'completa' => false, 'restantes' => $this->remaining(), 'sin_mapa' => 0];
                    }
                    [$had, $count, $last] = $this->db->transaction(function () use ($t, $h, $k, $n, $c, $ek, $from, $to, $batch, $withCipher, $last): array {
                        $cond = $withCipher ? "$c IS NOT NULL" : "$c IS NULL";
                        $rows = $this->db->fetchAll(
                            "SELECT id, $h AS h, $k AS k, $c AS cifrada, $ek AS key_id, cuenta_ultimos4 FROM $t
                              WHERE $h IS NOT NULL AND $n IS NULL AND $cond AND id > :last ORDER BY id LIMIT :n FOR UPDATE SKIP LOCKED",
                            ['last' => $last, 'n' => $batch]);
                        $written = 0;
                        foreach ($rows as $r) {
                            $last = (int) $r['id'];
                            if ((string) $r['k'] !== $from) {
                                throw new RuntimeException("La fila $t #{$r['id']} no usa la llave activa; ejecute --dry-run.");
                            }
                            $new = $withCipher ? $this->recompute($r, $from, $to) : $this->shadowFor((string) $r['h']);
                            if ($new === null) {
                                continue;            // sin valor cifrado ni correspondencia: se informa en el corte
                            }
                            $written += $this->db->execute("UPDATE $t SET $n = :v WHERE id = :id AND $h = :old AND $n IS NULL",
                                ['v' => $new, 'id' => $last, 'old' => $r['h']]);
                        }
                        return [count($rows) > 0, $written, $last];
                    });
                    if (!$had) {
                        break;
                    }
                    $done[$t] += $count;
                    $batches++;
                    if ($progress !== null) {
                        $progress($t, $count, $done[$t]);
                    }
                }
            }
        }
        $rest = $this->remaining();
        return ['preparadas' => $done, 'lotes' => $batches, 'completa' => $rest === 0, 'restantes' => $rest, 'sin_mapa' => $this->unmappable()];
    }

    /** Huellas sin sombra, sin valor cifrado y sin otra fila con la misma huella anterior (sólo se resuelven con una decisión). */
    public function unmappable(): int
    {
        $n = 0;
        foreach (['registros_tesoreria', 'staging_registros'] as $t) {
            $n += (int) $this->db->fetchValue("SELECT count(*) FROM $t x WHERE x.cuenta_hmac IS NOT NULL AND x.cuenta_hmac_nueva IS NULL AND x.cuenta_cifrada IS NULL
                AND NOT EXISTS (SELECT 1 FROM cuentas_bancarias c WHERE c.cuenta_hmac = x.cuenta_hmac AND c.cuenta_hmac_nueva IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM registros_tesoreria r WHERE r.cuenta_hmac = x.cuenta_hmac AND r.cuenta_hmac_nueva IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM staging_registros s WHERE s.cuenta_hmac = x.cuenta_hmac AND s.cuenta_hmac_nueva IS NOT NULL)");
        }
        return $n;
    }

    /**
     * Fase 2: corte atómico. Nunca deja metadatos de «completa» si queda una sola huella con la llave anterior.
     * @return array{cambiadas:array<string,int>,descartadas:int,destino:string}
     */
    public function cutover(bool $discardUnmappable = false, int $heartbeatTimeout = 120): array
    {
        return $this->db->transaction(function () use ($discardUnmappable, $heartbeatTimeout): array {
            // No bloquear indefinidamente a la normalización detrás de una transacción larga: si no se obtienen los candados, se reintenta
            $this->db->execute("SET LOCAL lock_timeout = '30s'");
            $this->db->fetchValue('SELECT pg_advisory_xact_lock(:k)', ['k' => self::WORKER_LOCK]);
            $this->db->execute('LOCK TABLE cuentas_bancarias, registros_tesoreria, staging_registros IN SHARE ROW EXCLUSIVE MODE');
            $rot = $this->db->fetchOne("SELECT id, key_origen, key_destino FROM hmac_rotaciones WHERE estado = 'PREPARANDO' FOR UPDATE")
                ?? throw new RuntimeException('No hay una rotación en curso.');
            [$from, $to] = [(string) $rot['key_origen'], (string) $rot['key_destino']];
            $this->assertRotation($to);
            if (($w = $this->workersWithout($to, $heartbeatTimeout)) !== []) {
                throw new RuntimeException('Workers activos sin la llave destino en su anillo: ' . implode(', ', $w)
                    . '. Actualice ACCOUNT_HMAC_KEYS y reinícielos antes del corte.');
            }
            // Lo que llegó durante la preparación (con las tablas ya bloqueadas para escritura, nada nuevo puede entrar)
            foreach ([true, false] as $withCipher) {
                foreach (self::TABLES as $t => [$h, $k, $n, $c, $ek]) {
                    $cond = $withCipher ? "$c IS NOT NULL" : "$c IS NULL";
                    foreach ($this->db->fetchAll("SELECT id, $h AS h, $k AS k, $c AS cifrada, $ek AS key_id, cuenta_ultimos4 FROM $t
                                                   WHERE $h IS NOT NULL AND $n IS NULL AND $cond ORDER BY id") as $r) {
                        if ((string) $r['k'] !== $from) {
                            throw new RuntimeException("La fila $t #{$r['id']} no usa la llave activa.");
                        }
                        $new = $withCipher ? $this->recompute($r, $from, $to) : $this->shadowFor((string) $r['h']);
                        if ($new !== null) {
                            $this->db->execute("UPDATE $t SET $n = :v WHERE id = :id", ['v' => $new, 'id' => (int) $r['id']]);
                        }
                    }
                }
            }
            $discarded = 0;
            $rest = $this->remaining();
            if ($rest > 0) {
                if (!$discardUnmappable) {
                    throw new RuntimeException("$rest huellas sin valor cifrado ni correspondencia: no se pueden recalcular. "
                        . 'Reanalice esos archivos o repita con --descartar-sin-cifrado (quita la huella de esas filas; auditado).');
                }
                foreach (['registros_tesoreria', 'staging_registros'] as $t) {
                    $discarded += $this->db->execute("UPDATE $t SET cuenta_hmac = NULL, cuenta_hmac_key_id = NULL
                                                        WHERE cuenta_hmac IS NOT NULL AND cuenta_hmac_nueva IS NULL AND cuenta_cifrada IS NULL");
                }
                if ($this->remaining() > 0) {
                    throw new RuntimeException('Quedan huellas sin recalcular en cuentas registradas; no se hace el corte.');
                }
            }
            $this->assertBijective();
            $this->db->execute('UPDATE hmac_llaves_estado SET key_id_activa = :t, kcv_activa = (SELECT kcv_destino FROM hmac_rotaciones WHERE id = :r) WHERE id = 1',
                ['t' => $to, 'r' => (int) $rot['id']]);
            // La caché por transacción de la llave activa (triggers de huellas) pasa a la nueva en esta misma transacción
            $this->db->fetchValue("SELECT set_config('conciliarh.hmac_activa_tx', :t, true)", ['t' => $to]);
            $changed = [];
            foreach (self::TABLES as $t => [$h, $k, $n]) {
                $changed[$t] = $this->db->execute("UPDATE $t SET $h = $n, $k = :t, $n = NULL WHERE $h IS NOT NULL", ['t' => $to]);
            }
            foreach (self::TABLES as $t => [$h, $k]) {
                if ((int) $this->db->fetchValue("SELECT count(*) FROM $t WHERE $h IS NOT NULL AND $k IS DISTINCT FROM :t", ['t' => $to]) !== 0) {
                    throw new RuntimeException("Verificación final fallida en $t; se revierte el corte.");
                }
            }
            $this->db->execute("UPDATE hmac_rotaciones SET estado = 'COMPLETADA', completada_at = now(), resumen = CAST(:r AS jsonb) WHERE id = :id",
                ['r' => json_encode(['cambiadas' => $changed, 'descartadas' => $discarded], JSON_THROW_ON_ERROR), 'id' => (int) $rot['id']]);
            return ['cambiadas' => $changed, 'descartadas' => $discarded, 'destino' => $to];
        });
    }

    /** Cancela la rotación en curso: borra la sombra; la llave activa no cambió nunca. */
    public function cancel(): int
    {
        return $this->db->transaction(function (): int {
            $rot = $this->db->fetchOne("SELECT id FROM hmac_rotaciones WHERE estado = 'PREPARANDO' FOR UPDATE")
                ?? throw new RuntimeException('No hay una rotación en curso.');
            $this->db->fetchValue('SELECT pg_advisory_xact_lock(:k)', ['k' => self::WORKER_LOCK]);
            foreach (self::TABLES as $t => [$h, , $n]) {
                $this->db->execute("UPDATE $t SET $n = NULL WHERE $n IS NOT NULL");
            }
            $this->db->execute("UPDATE hmac_rotaciones SET estado = 'CANCELADA', cancelada_at = now() WHERE id = :id", ['id' => (int) $rot['id']]);
            return (int) $rot['id'];
        });
    }

    public function remaining(): int
    {
        $n = 0;
        foreach (self::TABLES as $t => [$h, , $s]) {
            $n += (int) $this->db->fetchValue("SELECT count(*) FROM $t WHERE $h IS NOT NULL AND $s IS NULL");
        }
        return $n;
    }

    /** Colisión o dato inconsistente: dos huellas anteriores → una nueva, o una anterior → dos nuevas (en las tres tablas). */
    public function assertBijective(): void
    {
        $r = $this->db->fetchOne(
            "WITH u AS (SELECT cuenta_hmac AS o, cuenta_hmac_nueva AS n FROM cuentas_bancarias
                        UNION ALL SELECT cuenta_hmac, cuenta_hmac_nueva FROM registros_tesoreria WHERE cuenta_hmac IS NOT NULL
                        UNION ALL SELECT cuenta_hmac, cuenta_hmac_nueva FROM staging_registros WHERE cuenta_hmac IS NOT NULL)
             SELECT count(DISTINCT o) AS o, count(DISTINCT n) AS n, count(DISTINCT (o, n)) AS p, count(*) FILTER (WHERE n IS NULL) AS nulos FROM u");
        if ((int) $r['nulos'] !== 0 || (int) $r['o'] !== (int) $r['n'] || (int) $r['o'] !== (int) $r['p']) {
            throw new RuntimeException(sprintf('Correspondencia no biyectiva (anteriores=%d, nuevas=%d, pares=%d, sin sombra=%d): '
                . 'posible colisión o dato inconsistente. No se hace el corte.', $r['o'], $r['n'], $r['p'], $r['nulos']));
        }
    }

    /** @return list<string> ids de workers vivos que no declaran la llave destino */
    public function workersWithout(string $to, int $heartbeatTimeout = 120): array
    {
        return array_values(array_map(static fn ($w) => $w['id'], array_filter($this->liveWorkers($heartbeatTimeout),
            static fn ($w) => !in_array($to, $w['llaves'], true))));
    }

    /** @return list<array{id:string,llaves:list<string>}> */
    private function liveWorkers(int $heartbeatTimeout): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT id, coalesce(array_to_json(hmac_key_ids), '[]') AS k FROM workers
                                       WHERE detenido_at IS NULL AND ultimo_latido_at > now() - make_interval(secs => :t) ORDER BY id",
                     ['t' => max(30, $heartbeatTimeout)]) as $w) {
            $out[] = ['id' => (string) $w['id'], 'llaves' => array_values(array_map('strval', json_decode((string) $w['k'], true) ?: []))];
        }
        return $out;
    }

    private function assertRotation(string $to): string
    {
        if (!isset($this->hmacRing[$to])) {
            throw new RuntimeException('El key_id destino no está en ACCOUNT_HMAC_KEYS.');
        }
        $from = $this->inProgress()['key_origen'] ?? $this->active();
        if ($from === $to) {
            throw new RuntimeException('La llave destino ya es la activa.');
        }
        if (!isset($this->hmacRing[$from])) {
            throw new RuntimeException("La llave activa ($from) no está en ACCOUNT_HMAC_KEYS: no se puede verificar la correspondencia.");
        }
        // El mismo id con OTRO secreto calcularía huellas incorrectas: se verifica contra el valor registrado (primer uso = registro)
        if (!(bool) $this->db->fetchValue('SELECT fn_hmac_verificar_kcv(:k, :v)', ['k' => $from, 'v' => self::kcv($this->hmacRing[$from])])) {
            throw new RuntimeException("La llave $from del anillo no coincide con la registrada (valor de verificación distinto). No se modificó nada.");
        }
        $cur = $this->inProgress();
        if ($cur !== null && $cur['key_destino'] === $to
            && !(bool) $this->db->fetchValue('SELECT fn_hmac_verificar_kcv(:k, :v)', ['k' => $to, 'v' => self::kcv($this->hmacRing[$to])])) {
            throw new RuntimeException("La llave destino $to del anillo no es la misma con la que se inició la rotación.");
        }
        return $from;
    }

    /** Huella nueva de una fila con valor cifrado, tras verificar que la huella actual corresponde a ese valor. */
    private function recompute(array $r, string $from, string $to): string
    {
        $blob = is_resource($r['cifrada']) ? (string) stream_get_contents($r['cifrada']) : (string) $r['cifrada'];
        $keyId = (string) $r['key_id'];
        if (!isset($this->encRing[$keyId])) {
            throw new RuntimeException('LLAVE_DE_CIFRADO_NO_CONFIGURADA');
        }
        try {
            $plain = AccountVault::decrypt($blob, $keyId, $this->encRing[$keyId]);
        } catch (RuntimeException) {
            throw new RuntimeException('NO_DESCIFRABLE');
        }
        if (!hash_equals((string) $r['h'], hash_hmac('sha256', 'cuenta:' . $plain, $this->hmacRing[$from]))) {
            unset($plain);
            throw new RuntimeException('HUELLA_NO_CORRESPONDE_AL_VALOR');
        }
        $new = hash_hmac('sha256', 'cuenta:' . $plain, $this->hmacRing[$to]);
        unset($plain);
        return $new;
    }

    /** Sombra ya calculada para la MISMA huella anterior en cualquiera de las tablas (fila sin valor cifrado). */
    private function shadowFor(string $old): ?string
    {
        $v = $this->db->fetchValue(
            "SELECT n FROM (SELECT cuenta_hmac_nueva AS n FROM cuentas_bancarias WHERE cuenta_hmac = :a AND cuenta_hmac_nueva IS NOT NULL
                            UNION ALL SELECT cuenta_hmac_nueva FROM registros_tesoreria WHERE cuenta_hmac = :b AND cuenta_hmac_nueva IS NOT NULL
                            UNION ALL SELECT cuenta_hmac_nueva FROM staging_registros WHERE cuenta_hmac = :c AND cuenta_hmac_nueva IS NOT NULL) x LIMIT 1",
            ['a' => $old, 'b' => $old, 'c' => $old]);
        return $v === null || $v === false ? null : (string) $v;
    }
}
