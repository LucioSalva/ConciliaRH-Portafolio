<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Files;

use ConciliaRH\Core\Database;

final class Limits
{
    public function __construct(
        public readonly int $maxEntries = 5000,
        public readonly int $maxUncompressed = 314_572_800,
        public readonly int $maxRatio = 150,
        public readonly int $maxXml = 209_715_200,
    ) {
    }

    public static function fromDatabase(Database $db): self
    {
        $rows = [];
        foreach ($db->fetchAll("SELECT clave, valor FROM configuracion WHERE clave LIKE 'importacion.%'") as $r) {
            $rows[(string) $r['clave']] = (string) $r['valor'];
        }
        $num = static fn (string $k, int $d): int => ctype_digit($rows[$k] ?? '') ? (int) $rows[$k] : $d;
        $mb = 1024 * 1024;
        return new self(
            $num('importacion.zip_max_entradas', 5000),
            $num('importacion.zip_max_descomprimido_mb', 300) * $mb,
            $num('importacion.zip_max_ratio', 150),
            $num('importacion.xml_max_mb', 200) * $mb,
        );
    }
}
