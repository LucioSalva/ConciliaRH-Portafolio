<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use RuntimeException;

/**
 * Rotación de la llave de cifrado de cuentas/CLABE (docs/KEY_ROTATION.md). SÓLO por CLI y con el rol propietario.
 *
 * - Recorre las tres tablas con cuentas cifradas; cada lote es UNA transacción (FOR UPDATE SKIP LOCKED):
 *   descifra con la llave anterior, cifra con la nueva (nonce aleatorio, AAD con el nuevo key_id), VERIFICA descifrando
 *   el resultado y comparando los últimos 4 dígitos, y sólo entonces escribe. Una caída revierte el lote en curso.
 * - Reanudable por construcción: lo ya rotado tiene el key_id destino y no se vuelve a tocar.
 * - Cualquier fila no descifrable detiene la rotación sin escribir ese lote (llave faltante o incorrecta).
 * - Nunca imprime, registra ni audita llaves ni valores en claro: sólo tablas, key_id, conteos e ids de fila.
 * - El HMAC (ACCOUNT_HMAC_KEY) NO cambia: es independiente del cifrado (ver KEY_ROTATION.md §HMAC).
 */
final class KeyRotator
{
    /** tabla => [columna cifrada, columna key_id] */
    public const TABLES = [
        'cuentas_bancarias' => ['cuenta_cifrada', 'key_id'],
        'registros_tesoreria' => ['cuenta_cifrada', 'cuenta_key_id'],
        'staging_registros' => ['cuenta_cifrada', 'cuenta_key_id'],
    ];

    /** @param array<string,string> $ring key_id => llave binaria de 32 bytes */
    public function __construct(private readonly Database $db, private readonly array $ring)
    {
        foreach ($ring as $id => $key) {
            if (!preg_match('/^[a-z0-9_-]{1,16}$/', (string) $id) || strlen($key) !== 32) {
                throw new RuntimeException('Anillo de llaves inválido.');
            }
        }
    }

    /** @return array<string,array<string,int>> tabla => key_id => filas */
    public function status(): array
    {
        $out = [];
        foreach (self::TABLES as $t => [$c, $k]) {
            $out[$t] = [];
            foreach ($this->db->fetchAll("SELECT $k AS key_id, count(*) AS n FROM $t WHERE $c IS NOT NULL GROUP BY 1 ORDER BY 1") as $r) {
                $out[$t][(string) $r['key_id']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /**
     * Simulación completa sin escribir: descifra cada fila pendiente con su llave y verifica que se podría re-cifrar.
     * @return array{pendientes:array<string,int>,errores:list<array{tabla:string,id:int,motivo:string}>,por_key:array<string,array<string,int>>}
     */
    public function dryRun(string $to, int $batch = 1000): array
    {
        $this->assertTarget($to);
        $pend = [];
        $errors = [];
        foreach (self::TABLES as $t => [$c, $k]) {
            $pend[$t] = 0;
            $last = 0;
            do {
                $rows = $this->db->fetchAll(
                    "SELECT id, $c AS cifrada, $k AS key_id, cuenta_ultimos4 FROM $t WHERE $c IS NOT NULL AND $k IS DISTINCT FROM :to AND id > :last ORDER BY id LIMIT :n",
                    ['to' => $to, 'last' => $last, 'n' => $batch]);
                foreach ($rows as $r) {
                    $last = (int) $r['id'];
                    $pend[$t]++;
                    $err = $this->check($r);
                    if ($err !== null) {
                        $errors[] = ['tabla' => $t, 'id' => (int) $r['id'], 'motivo' => $err];
                    }
                }
            } while (count($rows) === $batch);
        }
        return ['pendientes' => $pend, 'errores' => $errors, 'por_key' => $this->status()];
    }

    /**
     * Rotación real (idempotente y reanudable). $maxBatches limita los lotes (pruebas de interrupción).
     * @param callable(string,int,int):void|null $progress
     * @return array{rotadas:array<string,int>,lotes:int,completa:bool}
     */
    public function rotate(string $to, int $batch = 500, ?int $maxBatches = null, ?callable $progress = null): array
    {
        $this->assertTarget($to);
        $key = $this->ring[$to];
        $done = array_fill_keys(array_keys(self::TABLES), 0);
        $batches = 0;
        foreach (self::TABLES as $t => [$c, $k]) {
            while (true) {
                if ($maxBatches !== null && $batches >= $maxBatches) {
                    return ['rotadas' => $done, 'lotes' => $batches, 'completa' => false];
                }
                $n = $this->db->transaction(function () use ($t, $c, $k, $to, $key, $batch): int {
                    $rows = $this->db->fetchAll(
                        "SELECT id, $c AS cifrada, $k AS key_id, cuenta_ultimos4 FROM $t WHERE $c IS NOT NULL AND $k IS DISTINCT FROM :to
                          ORDER BY id LIMIT :n FOR UPDATE SKIP LOCKED",
                        ['to' => $to, 'n' => $batch]);
                    foreach ($rows as $r) {
                        $plain = $this->decrypt($r);
                        $new = AccountVault::encrypt($plain, $to, $key);
                        // Verificación antes de escribir: el nuevo valor descifra al mismo número y coincide con los últimos 4
                        $back = $this->decrypt(['id' => $r['id'], 'cifrada' => $new, 'key_id' => $to, 'cuenta_ultimos4' => $r['cuenta_ultimos4']]);
                        if (!hash_equals($plain, $back)) {
                            throw new RuntimeException("Verificación fallida en $t #{$r['id']}.");
                        }
                        $ok = $this->db->execute("UPDATE $t SET $c = decode(:v, 'hex'), $k = :to WHERE id = :id AND $k IS NOT DISTINCT FROM :old",
                            ['v' => bin2hex($new), 'to' => $to, 'id' => (int) $r['id'], 'old' => $r['key_id']]);
                        if ($ok !== 1) {
                            throw new RuntimeException("La fila $t #{$r['id']} cambió durante la rotación; reintente.");
                        }
                        unset($plain, $back);
                    }
                    return count($rows);
                });
                if ($n === 0) {
                    break;
                }
                $done[$t] += $n;
                $batches++;
                if ($progress !== null) {
                    $progress($t, $n, $done[$t]);
                }
            }
        }
        $restantes = 0;
        foreach (self::TABLES as $t => [$c, $k]) {
            $restantes += (int) $this->db->fetchValue("SELECT count(*) FROM $t WHERE $c IS NOT NULL AND $k IS DISTINCT FROM :to", ['to' => $to]);
        }
        // Filas bloqueadas por otra transacción (SKIP LOCKED) quedan para la siguiente ejecución: la rotación NO está completa
        return ['rotadas' => $done, 'lotes' => $batches, 'completa' => $restantes === 0, 'restantes' => $restantes];
    }

    /** key_ids que ya no cifran ninguna fila (se pueden retirar del anillo). @return list<string> */
    public function retirables(): array
    {
        $used = [];
        foreach ($this->status() as $byKey) {
            foreach ($byKey as $id => $n) {
                if ($n > 0) {
                    $used[$id] = true;
                }
            }
        }
        return array_values(array_diff(array_keys($this->ring), array_keys($used)));
    }

    private function assertTarget(string $to): void
    {
        if (!isset($this->ring[$to])) {
            throw new RuntimeException('El key_id destino no está en ACCOUNT_ENC_KEYS.');
        }
    }

    /** @param array<string,mixed> $r */
    private function check(array $r): ?string
    {
        try {
            $this->decrypt($r);
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /** @param array<string,mixed> $r */
    private function decrypt(array $r): string
    {
        $blob = is_resource($r['cifrada']) ? (string) stream_get_contents($r['cifrada']) : (string) $r['cifrada'];
        $keyId = (string) $r['key_id'];
        if (!isset($this->ring[$keyId])) {
            throw new RuntimeException('LLAVE_NO_CONFIGURADA');
        }
        try {
            $plain = AccountVault::decrypt($blob, $keyId, $this->ring[$keyId]);
        } catch (RuntimeException) {
            throw new RuntimeException('NO_DESCIFRABLE');
        }
        if ($r['cuenta_ultimos4'] !== null && substr($plain, -strlen((string) $r['cuenta_ultimos4'])) !== (string) $r['cuenta_ultimos4']) {
            throw new RuntimeException('ULTIMOS4_NO_COINCIDEN');
        }
        return $plain;
    }
}
