<?php

declare(strict_types=1);

/**
 * Ancla externa de la auditoría (docs/SECURITY.md §5, docs/OPERATIONS_RUNBOOK.md). SÓLO CLI y SÓLO LECTURA.
 *
 *   sudo conciliarh-run audit-anchor > ancla-AAAAMMDD.json
 *
 * Imprime el mínimo necesario para fijar la cadena de la auditoría en un punto: la secuencia y el hash del ÚLTIMO registro,
 * el total de registros, la versión instalada y la fecha. No contiene usuarios, acciones, direcciones IP ni ningún dato
 * personal, y NO envía nada a ninguna parte: el operador sella el archivo fuera (sello de tiempo, firma, acta, otro medio).
 *
 * Verificación posterior: el registro con esa secuencia debe conservar ese hash Y la cadena debe ser íntegra (las dos cosas:
 * el ancla fija un registro; la cadena liga a todos los anteriores con él). Si alguien reescribe la cadena desde un punto
 * anterior, el hash deja de coincidir con el ancla guardada fuera del servidor.
 *   sudo conciliarh-run audit-anchor --verificar < ancla-AAAAMMDD.json   (lee el ancla por la entrada estándar; no modifica nada)
 */

use ConciliaRH\Core\BuildInfo;
use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);
$base = dirname(__DIR__);

$verificar = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verificar') {
        $verificar = true;
    } else {
        fwrite(STDERR, "Uso: audit-anchor [--verificar < ancla.json]\n");
        exit(1);
    }
}

try {
    $db = $container->get(Database::class);
    $db->execute('SET default_transaction_read_only = on');
    if ($verificar) {
        $raw = stream_get_contents(STDIN, 4097);
        $a = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
        $sec = $a['auditoria']['ultima_secuencia'] ?? null;
        $hash = $a['auditoria']['ultimo_hash'] ?? null;
        if (is_array($a) && ($a['formato'] ?? null) === 'conciliarh-ancla-auditoria/1' && array_key_exists('ultima_secuencia', $a['auditoria'] ?? []) && $sec === null) {
            fwrite(STDERR, "El ancla se tomó con la auditoría vacía: no fija ningún registro.\n");
            exit(1);
        }
        if (!is_int($sec) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            fwrite(STDERR, "La entrada no es un ancla válida.\n");
            exit(1);
        }
        $actual = $db->fetchValue('SELECT hash FROM auditoria WHERE secuencia = :s', ['s' => $sec]);
        if (!is_string($actual) || !hash_equals($hash, $actual)) {
            fwrite(STDOUT, "NO COINCIDE: el registro $sec " . (is_string($actual) ? 'tiene otro hash' : 'no existe') . ". O la cadena se alteró después del ancla,"
                . " o el ancla es de otra base de datos (por ejemplo, anterior a una restauración).\n");
            exit(3);
        }
        // El ancla fija UN registro; que los anteriores sigan encadenados hasta él lo dice la verificación de la cadena
        $v = $db->fetchOne('SELECT integra, registros, primer_secuencia_invalida FROM fn_auditoria_verificar()');
        if ($v === null || $v['integra'] !== true) {
            fwrite(STDOUT, "NO COINCIDE: el registro $sec conserva el hash del ancla, pero la cadena NO es íntegra"
                . (isset($v['primer_secuencia_invalida']) ? " (primer registro inválido: {$v['primer_secuencia_invalida']})" : '') . ".\n");
            exit(3);
        }
        fwrite(STDOUT, "COINCIDE: el registro $sec conserva el hash del ancla y la cadena es íntegra ({$v['registros']} registros).\n");
        exit(0);
    }
    $u = $db->fetchOne('SELECT secuencia, hash, hash_version, (SELECT count(*) FROM auditoria) AS total FROM auditoria ORDER BY secuencia DESC LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo leer la auditoría (falla de la base de datos).\n");
    exit(2);
}

$build = BuildInfo::read($base, trim((string) @file_get_contents($base . '/VERSION')));
$out = [
    'formato' => 'conciliarh-ancla-auditoria/1',
    'producto' => 'ConciliaRH',
    'version' => $build['version'],
    'commit' => $build['commit'],
    'entorno' => (string) $config->get('app.env'),
    'generado_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'auditoria' => [
        'registros' => $u === null ? 0 : (int) $u['total'],
        'ultima_secuencia' => $u === null ? null : (int) $u['secuencia'],
        'ultimo_hash' => $u === null ? null : (string) $u['hash'],
        'hash_version' => $u === null ? null : (int) $u['hash_version'],
    ],
];
fwrite(STDOUT, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
