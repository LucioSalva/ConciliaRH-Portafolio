<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Engine\Files\ContainerVerifier;
use ConciliaRH\Engine\Files\Limits;
use ConciliaRH\Engine\Files\StoredOriginal;
use ConciliaRH\Engine\Files\Verdict;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Worker\WorkerQueue;
use ConciliaRH\Tests\Fixtures\ExcelFixtures;
use ConciliaRH\Tests\Support\EngineTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class VerifyFileTest extends EngineTestCase
{
    private const LOT_SCRIPT = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$cfg = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$db = new ConciliaRH\Core\Database($cfg['db']);
$db->fetchValue("SELECT fn_sesion_vincular(:t, :u, NULL, 'phpunit')", ['t' => bin2hex(random_bytes(32)), 'u' => $cfg['user']]);
$db->pdo()->beginTransaction();
touch($cfg['dir'] . '/ready-' . $cfg['n']);
$until = microtime(true) + 30;
while (!file_exists($cfg['dir'] . '/go') && microtime(true) < $until) {
    usleep(5000);
}
$n = $db->fetchValue("SELECT numero FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $cfg['periodo'], 'u' => $cfg['user']]);
$db->pdo()->commit();
echo (int) $n;
PHP;

    /** @var array{user:int,periodo:int,lote:int}|null */
    private ?array $lote = null;

    protected function tearDown(): void
    {
        if ($this->lote !== null) {
            $this->cleanLots();
            $this->lote = null;
        }
        parent::tearDown();
    }

    private function cleanLots(): void
    {
        self::cleanIdentity();
        $o = self::owner();
        $o->execute('DELETE FROM archivos_importados');
        $o->execute('DELETE FROM lotes_importacion');
        $o->execute('DELETE FROM periodos_nomina');
    }

    /** @return array{user:int,periodo:int,lote:int} */
    private function lote(): array
    {
        $o = self::owner();
        $user = (int) $o->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.tester', 'Tester PHPUnit', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN' ON CONFLICT DO NOTHING", ['u' => $user]);
        $this->cleanLots();
        $per = (int) $o->fetchValue(
            "INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
             VALUES (2040, 1, 1, '2040-01-01', '2040-01-15', 'PERIODO PHPUNIT', :u) RETURNING id", ['u' => $user]);
        $lote = (int) $o->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $per, 'u' => $user]);
        return $this->lote = ['user' => $user, 'periodo' => $per, 'lote' => $lote];
    }

    private static function fixture(string $name): string
    {
        return ExcelFixtures::dir() . '/' . $name;
    }

    /** @param array{user:int,periodo:int,lote:int} $lote */
    private function register(array $lote, string $name, string $ext): int
    {
        $src = self::fixture($name);
        $uid = self::uuid4();
        $rel = "imports/{$lote['periodo']}/{$lote['lote']}/$uid/original.$ext";
        $dst = self::storage() . '/' . $rel;
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0775, true);
        }
        copy($src, $dst);
        chmod($dst, 0440);
        $data = (string) file_get_contents($src);
        $o = self::owner();
        $tipo = (int) $o->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'OTRO'");
        $origen = (int) $o->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
        $aid = (int) $o->fetchValue(
            "INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension,
                 mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id)
             VALUES (:uuid, :lote, :tipo, :origen, :nombre, :ext, 'application/octet-stream', :firma, :tam, :sha, :rel, :usr) RETURNING id",
            ['uuid' => $uid, 'lote' => $lote['lote'], 'tipo' => $tipo, 'origen' => $origen, 'nombre' => $name, 'ext' => $ext,
             'firma' => $ext === 'xls' ? 'OLE2' : 'OOXML', 'tam' => strlen($data), 'sha' => hash('sha256', $data), 'rel' => $rel,
             'usr' => $lote['user']]);
        $job = (int) $o->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('VERIFICAR_ARCHIVO', CAST(:p AS jsonb), 2) RETURNING id",
            ['p' => Json::encode(['archivo_id' => $aid])]);
        $o->execute('UPDATE archivos_importados SET proceso_id = :j WHERE id = :id', ['j' => $job, 'id' => $aid]);
        return $aid;
    }

    /** @return array<string,mixed> */
    private function archivo(int $aid): array
    {
        return self::owner()->fetchOne('SELECT * FROM archivos_importados WHERE id = :id', ['id' => $aid]) ?? [];
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function verify(string $path, string $sha, int $size, string $ext, ?Limits $limits = null, ?callable $check = null): Verdict
    {
        return (new ContainerVerifier())->verify($path, $sha, $size, $ext, $limits ?? new Limits(), static function (float $p, string $m): void {
        }, $check ?? static function (): void {
        });
    }

    /** @return array<string,array{string,string,bool,?string}> */
    public static function inspectorCases(): array
    {
        return [
            'valid.xlsx' => ['valid.xlsx', 'xlsx', true, null],
            'valid.xlsm' => ['valid.xlsm', 'xlsm', true, null],
            'valid.xls' => ['valid.xls', 'xls', true, null],
            'corrupt.xlsx' => ['corrupt.xlsx', 'xlsx', false, 'ARCHIVO_CORRUPTO'],
            'corrupt.xls' => ['corrupt.xls', 'xls', false, 'ARCHIVO_CORRUPTO'],
            'encrypted.xls' => ['encrypted.xls', 'xls', false, 'CONTENEDOR_PELIGROSO'],
            'zip-as.xls' => ['zip-as.xls', 'xls', false, 'FIRMA_INVALIDA'],
            'fake-extension.xlsx' => ['fake-extension.xlsx', 'xlsx', false, 'FIRMA_INVALIDA'],
            'zipbomb.xlsx' => ['zipbomb.xlsx', 'xlsx', false, 'CONTENEDOR_PELIGROSO'],
            'xxe.xlsx' => ['xxe.xlsx', 'xlsx', false, 'CONTENEDOR_PELIGROSO'],
            'xxe-utf16.xlsx' => ['xxe-utf16.xlsx', 'xlsx', false, 'CONTENEDOR_PELIGROSO'],
            'xxe-otra-extension.xlsx' => ['xxe-otra-extension.xlsx', 'xlsx', false, 'CONTENEDOR_PELIGROSO'],
            'traversal.xlsx' => ['traversal.xlsx', 'xlsx', false, 'CONTENEDOR_PELIGROSO'],
            'many-entries.xlsx' => ['many-entries.xlsx', 'xlsx', false, 'CONTENEDOR_PELIGROSO'],
            'macros-as.xlsx' => ['macros-as.xlsx', 'xlsx', false, 'EXTENSION_NO_COINCIDE'],
            'not-excel.xlsx' => ['not-excel.xlsx', 'xlsx', false, 'EXTENSION_NO_COINCIDE'],
        ];
    }

    #[DataProvider('inspectorCases')]
    public function testInspectorVerdicts(string $name, string $ext, bool $ok, ?string $code): void
    {
        $p = self::fixture($name);
        $data = (string) file_get_contents($p);
        $v = self::verify($p, hash('sha256', $data), strlen($data), $ext);
        self::assertSame([$ok, $code], [$v->ok, $v->code], (string) $v->message);
        if ($ok) {
            self::assertSame($ext === 'xlsm', $v->hasMacros);
        }
    }

    public function testRealDecompressionLimitDoesNotTrustDeclaredSizes(): void
    {
        $p = self::fixture('valid.xlsx');
        $data = (string) file_get_contents($p);
        $v = self::verify($p, hash('sha256', $data), strlen($data), 'xlsx', new Limits(maxUncompressed: 1000));
        self::assertSame([false, 'CONTENEDOR_PELIGROSO'], [$v->ok, $v->code]);
    }

    public function testIntegrityMismatch(): void
    {
        $p = self::fixture('valid.xlsx');
        $v = self::verify($p, str_repeat('0', 64), (int) filesize($p), 'xlsx');
        self::assertSame([false, 'INTEGRIDAD_FALLIDA'], [$v->ok, $v->code]);
        $v = self::verify($p, str_repeat('0', 64), 1, 'xlsx');
        self::assertSame('INTEGRIDAD_FALLIDA', $v->code);
    }

    public function testResolveOriginalConfinement(): void
    {
        $root = sys_get_temp_dir() . '/crh-resolve-' . bin2hex(random_bytes(6));
        mkdir($root . '/imports', 0775, true);
        try {
            $good = 'imports/1/2/00000000-0000-4000-8000-000000000000/original.xlsx';
            self::assertSame((string) realpath($root) . '/' . $good, StoredOriginal::resolve($root, $good));
            foreach (['../etc/passwd', 'imports/1/2/../../../etc/original.xlsx', '/etc/passwd', 'imports/1/2/x/original.xlsx'] as $bad) {
                try {
                    StoredOriginal::resolve($root, $bad);
                    self::fail("Se esperaba rechazo de $bad");
                } catch (RuntimeException $e) {
                    self::assertNotSame('', $e->getMessage());
                }
            }
            $link = $root . '/imports/1/2/00000000-0000-4000-8000-000000000001';
            mkdir($link, 0775, true);
            symlink('/etc/hostname', $link . '/original.xlsx');
            $this->expectException(RuntimeException::class);
            StoredOriginal::resolve($root, 'imports/1/2/00000000-0000-4000-8000-000000000001/original.xlsx');
        } finally {
            @unlink($root . '/imports/1/2/00000000-0000-4000-8000-000000000001/original.xlsx');
            @rmdir($root . '/imports/1/2/00000000-0000-4000-8000-000000000001');
            @rmdir($root . '/imports/1/2');
            @rmdir($root . '/imports/1');
            @rmdir($root . '/imports');
            @rmdir($root);
        }
    }

    /** @return array<string,array{string,string,string,?string,bool}> */
    public static function workerCases(): array
    {
        return [
            'valid.xlsx' => ['valid.xlsx', 'xlsx', 'VERIFICADO', null, false],
            'valid.xlsm' => ['valid.xlsm', 'xlsm', 'VERIFICADO', null, true],
            'valid.xls' => ['valid.xls', 'xls', 'VERIFICADO', null, false],
            'zipbomb.xlsx' => ['zipbomb.xlsx', 'xlsx', 'RECHAZADO', 'CONTENEDOR_PELIGROSO', false],
            'corrupt.xls' => ['corrupt.xls', 'xls', 'RECHAZADO', 'ARCHIVO_CORRUPTO', false],
        ];
    }

    #[DataProvider('workerCases')]
    public function testWorkerVerifiesFiles(string $name, string $ext, string $estado, ?string $code, bool $macros): void
    {
        $aid = $this->register($this->lote(), $name, $ext);
        self::assertSame(1, self::worker(null, 'phpunit-verif')->run(true));
        $a = $this->archivo($aid);
        self::assertSame([$estado, $code, $macros], [$a['estado'], $a['error_codigo'], $a['tiene_macros']]);
        self::assertNotNull($a['verificado_at']);
        $verif = (int) self::owner()->fetchValue(
            "SELECT id FROM procesos WHERE tipo = 'VERIFICAR_ARCHIVO' AND parametros->>'archivo_id' = :a", ['a' => (string) $aid]);
        $job = self::jobRow($verif);
        self::assertSame($estado === 'VERIFICADO' ? 'COMPLETADO' : 'COMPLETADO_CON_INCIDENCIAS', $job['estado']);
        $next = self::jobRow((int) $a['proceso_id']);
        if ($estado === 'VERIFICADO') {
            self::assertSame(['ANALIZAR_ARCHIVO', 'PENDIENTE'], [$next['tipo'], $next['estado']]);
        } else {
            self::assertSame($verif, $next['id'], 'Un archivo rechazado no se analiza');
        }
        $audit = self::owner()->fetchOne(
            "SELECT origen, accion FROM auditoria WHERE entidad = 'archivos_importados' AND entidad_id = :id ORDER BY secuencia DESC LIMIT 1",
            ['id' => (string) $aid]);
        self::assertNotNull($audit);
        self::assertSame('WORKER', $audit['origen']);
        self::assertSame($estado === 'VERIFICADO' ? 'archivos.verificar' : 'archivos.rechazar_verificacion', $audit['accion']);
        self::assertSame($a['sha256'], hash_file('sha256', self::storage() . '/' . $a['ruta_relativa']));
    }

    public function testTamperedOriginalIsRejected(): void
    {
        $aid = $this->register($this->lote(), 'valid.xlsx', 'xlsx');
        $stored = self::storage() . '/' . $this->archivo($aid)['ruta_relativa'];
        chmod($stored, 0640);
        $fh = fopen($stored, 'r+b');
        self::assertIsResource($fh);
        fseek($fh, 100);
        fwrite($fh, 'X');
        fclose($fh);
        self::worker(null, 'phpunit-tamper')->run(true);
        $a = $this->archivo($aid);
        self::assertSame(['RECHAZADO', 'INTEGRIDAD_FALLIDA'], [$a['estado'], $a['error_codigo']]);
    }

    public function testMissingOriginalMarksErrorViaJobTrigger(): void
    {
        $aid = $this->register($this->lote(), 'valid.xlsx', 'xlsx');
        $stored = self::storage() . '/' . $this->archivo($aid)['ruta_relativa'];
        chmod($stored, 0640);
        unlink($stored);
        self::worker(null, 'phpunit-missing')->run(true);
        $a = $this->archivo($aid);
        self::assertSame(['ERROR', 'ORIGINAL_AUSENTE'], [$a['estado'], $a['error_codigo']]);
        self::assertSame('ERROR', self::jobRow((int) $a['proceso_id'])['estado']);
    }

    public function testReaperErrorOnReceivedFileDoesNotPoisonTheReaper(): void
    {
        $aid = $this->register($this->lote(), 'valid.xlsx', 'xlsx');
        $job = (int) $this->archivo($aid)['proceso_id'];
        self::owner()->execute('UPDATE procesos SET max_intentos = 1 WHERE id = :id', ['id' => $job]);
        $q = new WorkerQueue(self::settings()->connect(), 'phpunit-muerto');
        self::assertNotNull($q->claim(['VERIFICAR_ARCHIVO']));
        self::owner()->execute("UPDATE procesos SET heartbeat_at = now() - interval '1 hour' WHERE id = :id", ['id' => $job]);
        self::assertSame(['ERROR'], array_column($q->reap(60), 'estado'));
        $a = $this->archivo($aid);
        self::assertSame(['ERROR', 'WORKER_ABANDONADO'], [$a['estado'], $a['error_codigo']]);
    }

    public function testCancelledJobLeavesFileReprocessable(): void
    {
        $aid = $this->register($this->lote(), 'valid.xlsx', 'xlsx');
        self::owner()->execute("UPDATE procesos SET estado = 'CANCELADO', finalizado_at = now() WHERE id = :id",
            ['id' => (int) $this->archivo($aid)['proceso_id']]);
        $a = $this->archivo($aid);
        self::assertSame(['ERROR', 'VERIFICACION_CANCELADA'], [$a['estado'], $a['error_codigo']]);
    }

    public function testAnnulledFileIsSkippedIdempotently(): void
    {
        $lote = $this->lote();
        $aid = $this->register($lote, 'valid.xlsx', 'xlsx');
        self::owner()->execute("UPDATE archivos_importados SET estado = 'ANULADO', anulado_por = :u, anulado_at = now() WHERE id = :id",
            ['u' => $lote['user'], 'id' => $aid]);
        self::worker(null, 'phpunit-anulado')->run(true);
        $a = $this->archivo($aid);
        self::assertSame('ANULADO', $a['estado']);
        $result = (array) Json::decode((string) self::jobRow((int) $a['proceso_id'])['resultado']);
        self::assertArrayHasKey('omitido', $result);
    }

    public function testWorkerCannotTouchIdentityOrInsertFiles(): void
    {
        $aid = $this->register($this->lote(), 'valid.xlsx', 'xlsx');
        $db = self::settings()->connect();
        foreach (["UPDATE archivos_importados SET sha256 = repeat('a', 64) WHERE id = :id",
                  "UPDATE archivos_importados SET ruta_relativa = 'x' WHERE id = :id",
                  'DELETE FROM archivos_importados WHERE id = :id'] as $sql) {
            try {
                $db->execute($sql, ['id' => $aid]);
                self::fail("Se esperaba InsufficientPrivilege: $sql");
            } catch (PDOException $e) {
                self::assertSame('42501', (string) ($e->errorInfo[0] ?? $e->getCode()), $e->getMessage());
            }
        }
    }

    public function testConcurrentLotCreationYieldsUniqueConsecutiveNumbers(): void
    {
        $lote = $this->lote();
        $dir = sys_get_temp_dir() . '/crh-lotes-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $script = $dir . '/crear.php';
        file_put_contents($script, self::LOT_SCRIPT);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $web = \ConciliaRH\Tests\Support\DatabaseTestCase::dbConfig('DB_USERNAME', 'DB_PASSWORD', 'conciliarh_web');
        $procs = [];
        $results = [];
        $errors = [];
        try {
            for ($n = 0; $n < 10; $n++) {
                $arg = json_encode(['db' => $web, 'user' => $lote['user'], 'periodo' => $lote['periodo'], 'dir' => $dir, 'n' => $n], JSON_THROW_ON_ERROR);
                $proc = proc_open([PHP_BINARY, $script, $autoload, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($proc);
                $procs[] = [$proc, $pipes];
            }
            $until = microtime(true) + 30;
            while (count(glob($dir . '/ready-*') ?: []) < 10 && microtime(true) < $until) {
                usleep(5000);
            }
            touch($dir . '/go');
            foreach ($procs as [$proc, $pipes]) {
                $out = (string) stream_get_contents($pipes[1]);
                $err = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code !== 0) {
                    $errors[] = "código $code: $err";
                } else {
                    $results[] = (int) $out;
                }
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        self::assertSame([], $errors);
        sort($results);
        self::assertSame(range(2, 11), $results);
        $names = array_column(self::owner()->fetchAll('SELECT nombre FROM lotes_importacion WHERE periodo_id = :p ORDER BY numero',
            ['p' => $lote['periodo']]), 'nombre');
        self::assertSame(['Carga 001', 'Carga 002'], array_slice($names, 0, 2));
        self::assertSame('Carga 011', $names[count($names) - 1]);
        self::assertSame(11, self::owner()->fetchValue('SELECT ultimo_numero_lote FROM periodos_nomina WHERE id = :p', ['p' => $lote['periodo']]));
    }

    public function testVerifyInvokesCooperativeCheckDuringDecompression(): void
    {
        $p = self::fixture('zipbomb.xlsx');
        $data = (string) file_get_contents($p);
        $stop = new class extends RuntimeException {
        };
        try {
            self::verify($p, hash('sha256', $data), strlen($data), 'xlsx', new Limits(maxUncompressed: 10 ** 10, maxRatio: 10 ** 9),
                static function () use ($stop): void {
                    throw $stop;
                });
            self::fail('Se esperaba que el chequeo cooperativo detuviera la verificación');
        } catch (RuntimeException $e) {
            self::assertSame($stop, $e);
        }
    }
}
