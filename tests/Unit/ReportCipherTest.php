<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use ConciliaRH\Domain\Reports\ReportCipher;
use ConciliaRH\Domain\Reports\ReportCipherException;
use PHPUnit\Framework\TestCase;

/** Cifrado en reposo de reportes (docs/REPORT_ENCRYPTION.md): ida y vuelta y rechazo de TODA manipulación. Datos ficticios. */
final class ReportCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crh-cipher-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private static function cipher(string $ring = '', string $active = 'r1'): ReportCipher
    {
        return ReportCipher::fromConfig($ring ?: 'r1:' . str_repeat('1', 64) . ',r2:' . str_repeat('2', 64), $active) ?? self::fail('anillo');
    }

    private function enc(string $plain, string $ctx = 'g7', ?ReportCipher $c = null): string
    {
        file_put_contents($in = $this->dir . '/in-' . bin2hex(random_bytes(3)), $plain);
        ($c ?? self::cipher())->encryptFile($in, $out = $in . '.enc', $ctx);
        unlink($in);
        return $out;
    }

    private function assertRejected(string $code, string $file, string $ctx = 'g7', ?ReportCipher $c = null): void
    {
        $leaked = '';
        try {
            // Verificación sin destino: así es como la descarga comprueba antes de transmitir
            ($c ?? self::cipher())->decrypt($file, $ctx);
            self::fail("Debió rechazarse ($code)");
        } catch (ReportCipherException $e) {
            self::assertSame($code, $e->codigo);
            self::assertStringNotContainsString(str_repeat('1', 16), $e->getMessage(), 'El error no revela la llave');
        }
        self::assertSame('', $leaked);
    }

    public function testRoundTripSmallEmptyAndMultiSegment(): void
    {
        foreach (['', 'Número,Nombre' . "\n90001,PERSONA FICTICIA", random_bytes(200000)] as $plain) {
            $f = $this->enc($plain);
            self::assertStringStartsWith("CRHRPT1\n", (string) file_get_contents($f));
            self::assertSame(0, $plain === '' ? 0 : substr_count((string) file_get_contents($f), substr($plain, 0, 12)), 'No hay claro en el archivo');
            $out = '';
            self::assertSame(strlen($plain), self::cipher()->decrypt($f, 'g7', static function (string $x) use (&$out): void {
                $out .= $x;
            }));
            self::assertSame($plain, $out);
            self::assertSame('r1', ReportCipher::keyIdOf($f));
        }
    }

    public function testNonceIsUniquePerEncryption(): void
    {
        $a = (string) file_get_contents($this->enc('mismo contenido ficticio'));
        $b = (string) file_get_contents($this->enc('mismo contenido ficticio'));
        self::assertNotSame($a, $b, 'Encabezado/nonce aleatorio: dos cifrados del mismo claro difieren');
        self::assertNotSame(substr($a, 0, 51), substr($b, 0, 51));
    }

    public function testTamperingIsAlwaysRejected(): void
    {
        $plain = str_repeat('90001,PERSONA FICTICIA,8500.00' . "\n", 5000);   // > 1 segmento
        $orig = (string) file_get_contents($f = $this->enc($plain));
        $hdr = 8 + 1 + 2 + 16;                                                // magia + len + "r1" + KCV
        $w = static fn (string $d) => file_put_contents($f, $d);
        // Nonce/encabezado secretstream alterado
        $d = $orig; $d[$hdr + 3] = chr(ord($d[$hdr + 3]) ^ 1); $w($d);
        $this->assertRejected('AUTENTICACION_FALLIDA', $f);
        // Etiqueta (último byte del primer segmento) alterada
        $first = unpack('N', substr($orig, $hdr + 24, 4))[1];
        $d = $orig; $p = $hdr + 24 + 4 + $first - 1; $d[$p] = chr(ord($d[$p]) ^ 0x80); $w($d);
        $this->assertRejected('AUTENTICACION_FALLIDA', $f);
        // Texto cifrado alterado en medio
        $d = $orig; $p = $hdr + 24 + 4 + 100; $d[$p] = chr(ord($d[$p]) ^ 0x01); $w($d);
        $this->assertRejected('AUTENTICACION_FALLIDA', $f);
        // Truncado: sin el segmento final, o cortado a la mitad de un segmento
        $w(substr($orig, 0, $hdr + 24 + 4 + $first));
        $this->assertRejected('TRUNCADO', $f);
        $w(substr($orig, 0, strlen($orig) - 10));
        $this->assertRejected('TRUNCADO', $f);
        // Basura después del segmento final
        $w($orig . pack('N', 40) . random_bytes(40));
        $this->assertRejected('SEGMENTOS_INVALIDOS', $f);
        // Magia alterada
        $w('X' . substr($orig, 1));
        $this->assertRejected('FORMATO_INVALIDO', $f);
        // Archivo de OTRO reporte (contexto distinto): no se acepta aunque la llave sea la misma
        $w($orig);
        $this->assertRejected('AUTENTICACION_FALLIDA', $f, 'g8');
    }

    public function testWrongOrMissingKeyIsDistinguishedFromTampering(): void
    {
        $f = $this->enc('contenido ficticio');
        // Mismo id «r1» con OTRO secreto (servidor mal configurado): 503 en la descarga, sin marcar el reporte como alterado
        $this->assertRejected('LLAVE_INCORRECTA', $f, 'g7', self::cipher('r1:' . str_repeat('9', 64)));
        // La llave ya no está en el anillo
        $this->assertRejected('LLAVE_NO_DISPONIBLE', $f, 'g7', self::cipher('r3:' . str_repeat('3', 64), 'r3'));
        // Cambiar el id de llave del encabezado a otra llave existente tampoco funciona
        $d = (string) file_get_contents($f);
        $d[10] = '2';
        file_put_contents($f, $d);
        $this->assertRejected('LLAVE_INCORRECTA', $f);
    }

    public function testRotationReencryptsWithoutPlaintextOnDisk(): void
    {
        $plain = str_repeat('90002,PERSONA FICTICIA DOS' . "\n", 4000);
        $f = $this->enc($plain);
        $r2 = self::cipher('', 'r2');
        self::assertSame('r2', $r2->reencryptFile($f, $g = $f . '.r2', 'g7'));
        self::assertSame('r2', ReportCipher::keyIdOf($g));
        self::assertSame([$f, $g], glob($this->dir . '/*') ?: [], 'Sólo archivos cifrados: nunca un claro temporal');
        $out = '';
        $r2->decrypt($g, 'g7', static function (string $x) use (&$out): void {
            $out .= $x;
        });
        self::assertSame($plain, $out);
        // Re-cifrar un archivo manipulado falla y no deja salida
        file_put_contents($f, substr((string) file_get_contents($f), 0, -5));
        try {
            $r2->reencryptFile($f, $h = $f . '.bad', 'g7');
            self::fail('Re-cifró un archivo truncado');
        } catch (ReportCipherException) {
            self::assertFileDoesNotExist($h);
        }
    }

    public function testInvalidRingsAreRejected(): void
    {
        self::assertNull(ReportCipher::fromConfig('', 'r1'));
        self::assertNull(ReportCipher::fromConfig('r1:corta', 'r1'));
        self::assertNull(ReportCipher::fromConfig('r1:' . str_repeat('1', 64), 'r2'));
    }
}
