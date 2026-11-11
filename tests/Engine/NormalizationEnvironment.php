<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use PDOException;

trait NormalizationEnvironment
{
    private int $user = 0;
    /** @var array<string,int> */
    private array $periodos = [];

    private static function cleanNormalization(): void
    {
        $o = self::owner();
        self::cleanIdentity();
        $o->execute('UPDATE archivos_importados SET analisis_vigente_id = NULL');
        foreach (['staging_registros', 'controles_archivo', 'archivo_hojas', 'analisis_archivo', 'archivos_importados', 'lotes_importacion',
                  'periodos_nomina'] as $t) {
            $o->execute("DELETE FROM $t");
        }
    }

    private function startEnvironment(): void
    {
        $o = self::owner();
        $this->periodos = [];
        $this->user = (int) $o->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.identidad', 'Tester Identidad', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT ?, id FROM roles WHERE codigo = 'ADMIN' ON CONFLICT DO NOTHING",
            [$this->user]);
    }

    private function lote(int $mes = 1, int $q = 1): int
    {
        $key = "$mes-$q";
        if (!isset($this->periodos[$key])) {
            [$ini, $fin] = $q === 1 ? [sprintf('2042-%02d-01', $mes), sprintf('2042-%02d-15', $mes)]
                : [sprintf('2042-%02d-16', $mes), sprintf('2042-%02d-28', $mes)];
            $this->periodos[$key] = (int) self::owner()->fetchValue(
                "INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
                 VALUES (2042, ?, ?, ?, ?, 'PERIODO IDENTIDAD', ?) RETURNING id", [$mes, $q, $ini, $fin, $this->user]);
        }
        return (int) self::owner()->fetchValue("SELECT id FROM fn_crear_lote(?, '', '', '', '', ?)", [$this->periodos[$key], $this->user]);
    }

    private function periodo(int $mes, int $q): int
    {
        return $this->periodos["$mes-$q"];
    }

    private function add(string $data, string $ext, string $tipo, ?int $lote = null, bool $drain = true): int
    {
        $o = self::owner();
        $lote ??= $this->lote();
        $uid = self::uuid4();
        $per = (int) $o->fetchValue('SELECT periodo_id FROM lotes_importacion WHERE id = ?', [$lote]);
        $rel = "imports/$per/$lote/$uid/original.$ext";
        $dst = self::storage() . '/' . $rel;
        mkdir(dirname($dst), 0775, true);
        file_put_contents($dst, $data);
        chmod($dst, 0440);
        $tid = (int) $o->fetchValue('SELECT id FROM tipos_documento WHERE codigo = ?', [$tipo]);
        $oid = (int) $o->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
        $aid = (int) $o->fetchValue(
            "INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension,
                 mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id, tiene_macros)
             VALUES (?, ?, ?, ?, ?, ?, 'application/octet-stream', ?, ?, ?, ?, ?, false) RETURNING id",
            [$uid, $lote, $tid, $oid, "ficticio.$ext", $ext, $ext === 'xls' ? 'OLE2' : 'OOXML', strlen($data), hash('sha256', $data), $rel,
             $this->user]);
        $job = (int) $o->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('VERIFICAR_ARCHIVO', CAST(? AS jsonb), 2) RETURNING id",
            [Json::encode(['archivo_id' => $aid])]);
        $o->execute('UPDATE archivos_importados SET proceso_id = ? WHERE id = ?', [$job, $aid]);
        if ($drain) {
            $this->drain();
        }
        return $aid;
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function drain(int $n = 30): void
    {
        $w = self::worker(self::settings(), 'phpunit-identidad');
        for ($i = 0; $i < $n; $i++) {
            if (self::owner()->fetchValue("SELECT 1 FROM procesos WHERE estado = 'PENDIENTE' AND intentos < max_intentos") === null) {
                break;
            }
            $w->run(true);
        }
        $bad = self::owner()->fetchAll("SELECT tipo, error_codigo, error_detalle FROM procesos WHERE estado = 'ERROR'");
        self::assertSame([], $bad, Json::encode($bad));
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, mixed ...$args): ?array
    {
        return self::owner()->fetchOne($sql, $args);
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql, mixed ...$args): array
    {
        return self::owner()->fetchAll($sql, $args);
    }

    private function intValue(string $sql, mixed ...$args): int
    {
        return (int) self::owner()->fetchValue($sql, $args);
    }

    /** @return array<string,mixed>|null */
    private function imp(int $aid): ?array
    {
        return $this->one("SELECT * FROM importaciones_normalizadas WHERE archivo_id = ? AND estado = 'VIGENTE'", $aid);
    }

    /** @return list<array<string,mixed>> */
    private function regs(int $aid, string $table = 'registros_rrhh'): array
    {
        return $this->all("SELECT r.* FROM $table r JOIN importaciones_normalizadas n ON n.id = r.importacion_id
                           WHERE r.archivo_id = ? AND n.estado = 'VIGENTE' ORDER BY r.fila_origen", $aid);
    }

    /** @return array<string,mixed>|null */
    private function emp(string $numero): ?array
    {
        return $this->one('SELECT * FROM empleados WHERE numero_empleado = ?', $numero);
    }

    private function empId(string $numero): int
    {
        return (int) ($this->emp($numero)['id'] ?? 0);
    }

    private function web(?int $asUser = null): Database
    {
        $conn = self::webDb();
        self::bindActor($conn, $asUser ?? $this->user);
        return $conn;
    }

    /** @return array<string,mixed> */
    private static function jsonField(mixed $v): array
    {
        return is_string($v) ? (array) json_decode($v, true, 512, JSON_THROW_ON_ERROR) : (array) $v;
    }

    /** @param list<array<string,mixed>> $people */
    private static function tab(array $people, bool $hiddenRows = false): string
    {
        return FX::tabulado($people, $hiddenRows);
    }

    /**
     * @param list<array<string,mixed>> $people
     * @param array<string,string> $cuentas
     */
    private static function sant(array $people, array $cuentas = [], bool $pension = false, ?string $overrideNum = null): string
    {
        $rows = [];
        foreach ($people as $p) {
            [$pat, $mat, $nom] = explode(' ', (string) $p['nombre']);
            $rows[] = [$overrideNum ?? $p['num'], $pat, $mat, $nom, $cuentas[$p['num']] ?? sprintf('600%08d', (int) $p['num']), '5000.00'];
        }
        return FX::santander($rows, $pension);
    }

    private function requeue(int $aid, bool $completa): void
    {
        self::owner()->execute("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('NORMALIZAR_REGISTROS', CAST(? AS jsonb), 2)",
            [Json::encode(['archivo_id' => $aid, 'completa' => $completa])]);
        $this->drain();
    }

    private static function sqlState(callable $fn): ?string
    {
        try {
            $fn();
        } catch (PDOException $e) {
            return (string) ($e->errorInfo[0] ?? $e->getCode());
        }
        return null;
    }
}
