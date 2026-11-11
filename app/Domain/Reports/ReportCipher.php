<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Reports;

use RuntimeException;

/**
 * Cifrado en reposo de reportes generados con datos de personas (docs/REPORT_ENCRYPTION.md).
 *
 * Primitiva: libsodium crypto_secretstream_xchacha20poly1305 (AEAD por segmentos). No se inventa criptografía:
 *  - cada segmento lleva su etiqueta Poly1305; el nonce lo gestiona secretstream (sin reutilización posible);
 *  - el último segmento lleva TAG_FINAL: un archivo truncado o con segmentos de más se rechaza;
 *  - los datos asociados de CADA segmento incluyen la versión, el id de la llave y el contexto (id del reporte): mover el
 *    archivo de un reporte a otro o cambiar el id de llave del encabezado invalida la autenticación.
 * Formato: "CRHRPT1\n" | len(id) (1 byte) | id de llave | KCV (16 bytes) | encabezado secretstream (24 bytes) | { len (4 bytes BE) | segmento }*
 * KCV = HMAC-SHA256(llave, "conciliarh:report-kcv:v1")[0..16]: distingue «llave equivocada en este servidor» (503, el reporte no se
 * marca alterado) de «archivo manipulado» (409). No revela la llave.
 * La llave (REPORT_ENC_KEYS) es DISTINTA de ACCOUNT_ENC_KEYS, ACCOUNT_HMAC_KEYS y APP_KEY (Config::assertSecure lo exige).
 */
final class ReportCipher
{
    private const MAGIC = "CRHRPT1\n";
    private const CHUNK = 65536;

    /** @param array<string,string> $ring id => llave binaria de 32 bytes */
    private function __construct(private array $ring, private readonly string $activeId)
    {
        if (!isset($this->ring[$activeId])) {
            throw new RuntimeException('REPORT_ENC_KEY_ID no está en REPORT_ENC_KEYS.');
        }
    }

    /** @param string $ring "r1:64hex[,r2:64hex]" */
    public static function fromConfig(string $ring, string $activeId): ?self
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $ring))) as $pair) {
            if (!preg_match('/^([a-z0-9_-]{1,16}):([0-9a-f]{64})$/', $pair, $m) || isset($out[$m[1]])) {
                return null;                                     // formato inválido o id repetido (no gana «el último» en silencio)
            }
            $out[$m[1]] = sodium_hex2bin($m[2]);
        }
        return $out === [] || !isset($out[$activeId]) ? null : new self($out, $activeId);
    }

    public function activeKeyId(): string
    {
        return $this->activeId;
    }

    public function hasKey(string $id): bool
    {
        return isset($this->ring[$id]);
    }

    /** Cifra $in en $out (nuevo, 0640). Devuelve el id de la llave usada. */
    public function encryptFile(string $in, string $out, string $context): string
    {
        $src = @fopen($in, 'rb');
        if ($src === false) {
            throw new RuntimeException('No se pudo leer el reporte a cifrar.');
        }
        try {
            $dst = @fopen($out, 'xb');
            if ($dst === false) {
                throw new RuntimeException('No se pudo crear el reporte cifrado.');
            }
            @chmod($out, 0640);
            try {
                $this->pump($src, $this->writer($dst, $this->activeId, $context));
                self::durable($dst);
            } finally {
                fclose($dst);
            }
        } catch (\Throwable $e) {
            @unlink($out);
            throw $e;
        } finally {
            fclose($src);
        }
        return $this->activeId;
    }

    /** Vuelve a cifrar con la llave activa SIN escribir el claro en disco (rotación). Devuelve el id de la llave nueva. */
    public function reencryptFile(string $in, string $out, string $context): string
    {
        $dst = @fopen($out, 'xb');
        if ($dst === false) {
            throw new RuntimeException('No se pudo crear el reporte re-cifrado.');
        }
        @chmod($out, 0640);
        try {
            $w = $this->writer($dst, $this->activeId, $context);
            $this->decrypt($in, $context, static function (string $plain) use ($w): void {
                $w($plain, false);
            });
            $w('', true);
            self::durable($dst);
        } catch (\Throwable $e) {
            fclose($dst);
            @unlink($out);
            throw $e;
        }
        fclose($dst);
        return $this->activeId;
    }

    /**
     * Descifra y entrega el claro por segmentos a $sink (null = sólo verificar). Devuelve los bytes en claro.
     * Cualquier alteración (encabezado, llave, nonce, etiqueta, truncado, basura al final) ⇒ ReportCipherException.
     * IMPORTANTE: quien entrega a un cliente verifica PRIMERO (sink null) y sólo después transmite.
     */
    public function decrypt(string $in, string $context, ?callable $sink = null): int
    {
        $f = @fopen($in, 'rb');
        if ($f === false) {
            throw new ReportCipherException('ARCHIVO_ILEGIBLE');
        }
        try {
            if (fread($f, 8) !== self::MAGIC) {
                throw new ReportCipherException('FORMATO_INVALIDO');
            }
            $n = ord((string) fread($f, 1));
            $keyId = $n > 0 && $n <= 16 ? (string) fread($f, $n) : '';
            if (!preg_match('/^[a-z0-9_-]{1,16}$/', $keyId)) {
                throw new ReportCipherException('FORMATO_INVALIDO');
            }
            if (!isset($this->ring[$keyId])) {
                throw new ReportCipherException('LLAVE_NO_DISPONIBLE');
            }
            if (!hash_equals(self::kcv($this->ring[$keyId]), (string) fread($f, 16))) {
                throw new ReportCipherException('LLAVE_INCORRECTA');
            }
            $header = (string) fread($f, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new ReportCipherException('FORMATO_INVALIDO');
            }
            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->ring[$keyId]);
            } catch (\SodiumException) {
                throw new ReportCipherException('FORMATO_INVALIDO');
            }
            $ad = self::ad($keyId, $context);
            $total = 0;
            $final = false;
            while (true) {
                $lenRaw = (string) fread($f, 4);
                if ($lenRaw === '') {
                    break;                                           // EOF: sólo es válido si ya llegó el segmento final
                }
                if ($final || strlen($lenRaw) !== 4) {
                    throw new ReportCipherException('SEGMENTOS_INVALIDOS');
                }
                $len = unpack('N', $lenRaw)[1];
                if ($len < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $len > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new ReportCipherException('SEGMENTOS_INVALIDOS');
                }
                $c = (string) fread($f, $len);
                if (strlen($c) !== $len) {
                    throw new ReportCipherException('TRUNCADO');
                }
                $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c, $ad);
                if ($r === false) {
                    throw new ReportCipherException('AUTENTICACION_FALLIDA');
                }
                [$plain, $tag] = $r;
                $total += strlen($plain);
                if ($sink !== null && $plain !== '') {
                    $sink($plain);
                }
                if ($plain !== '') {
                    sodium_memzero($plain);
                }
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (!$final) {
                throw new ReportCipherException('TRUNCADO');
            }
            return $total;
        } finally {
            fclose($f);
        }
    }

    /** Id de la llave con la que está cifrado un archivo (sin descifrarlo). */
    public static function keyIdOf(string $path): ?string
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return null;
        }
        try {
            if (fread($f, 8) !== self::MAGIC) {
                return null;
            }
            $n = ord((string) fread($f, 1));
            $id = $n > 0 && $n <= 16 ? (string) fread($f, $n) : '';
            return preg_match('/^[a-z0-9_-]{1,16}$/', $id) ? $id : null;
        } finally {
            fclose($f);
        }
    }

    /** @param resource $dst @return callable(string,bool):void escribe claro; con $last=true cierra con TAG_FINAL */
    private function writer($dst, string $keyId, string $context): callable
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->ring[$keyId]);
        $this->write($dst, self::MAGIC . chr(strlen($keyId)) . $keyId . self::kcv($this->ring[$keyId]) . $header);
        $ad = self::ad($keyId, $context);
        $buf = '';
        $done = false;
        return function (string $plain, bool $last) use (&$state, &$buf, &$done, $dst, $ad): void {
            if ($done) {
                throw new RuntimeException('El flujo cifrado ya se cerró.');
            }
            $buf .= $plain;
            while (strlen($buf) > self::CHUNK) {
                $piece = substr($buf, 0, self::CHUNK);
                $buf = substr($buf, self::CHUNK);
                $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, $piece, $ad, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                sodium_memzero($piece);
                $this->write($dst, pack('N', strlen($c)) . $c);
            }
            if ($last) {
                $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, $buf, $ad, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
                if ($buf !== '') {
                    sodium_memzero($buf);
                }
                $buf = '';
                $done = true;
                $this->write($dst, pack('N', strlen($c)) . $c);
            }
        };
    }

    /** @param resource $src */
    private function pump($src, callable $w): void
    {
        while (!feof($src)) {
            $chunk = fread($src, self::CHUNK);
            if ($chunk === false) {
                throw new RuntimeException('No se pudo leer el reporte a cifrar.');
            }
            $w($chunk, false);
        }
        $w('', true);
    }

    /** Asegura el archivo en disco antes de que la BD lo registre (una caída no deja una fila apuntando a un archivo vacío). */
    private static function durable($fh): void
    {
        if (!fflush($fh) || (function_exists('fsync') && !fsync($fh))) {
            throw new RuntimeException('No se pudo asegurar el reporte cifrado en disco.');
        }
    }

    private static function kcv(string $key): string
    {
        return substr(hash_hmac('sha256', 'conciliarh:report-kcv:v1', $key, true), 0, 16);
    }

    private static function ad(string $keyId, string $context): string
    {
        return 'conciliarh:report:v1|' . $keyId . '|' . $context;
    }

    /** @param resource $dst */
    private function write($dst, string $data): void
    {
        if (fwrite($dst, $data) !== strlen($data)) {
            throw new RuntimeException('No se pudo escribir el reporte cifrado (disco lleno o sin permisos).');
        }
    }
}
