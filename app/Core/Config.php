<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use RuntimeException;

/** Acceso de sólo lectura a la configuración con notación punto: get('app.env'). */
final class Config
{
    /** @param array<string,mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function isProduction(): bool
    {
        return $this->get('app.env') === 'production';
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('app.debug') && !$this->isProduction();
    }

    /**
     * Verificaciones de arranque. En producción, una configuración insegura
     * impide iniciar la aplicación (falla cerrada).
     */
    /**
     * Anillo de llaves "k1:hex,k2:hex" → [id => bytes]. null si el formato es inválido.
     * @return array<string,string>|null
     */
    public static function parseKeyRing(string $spec): ?array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            if (!preg_match('/^([a-z0-9_-]{1,16}):([0-9a-f]{64})$/', $part, $m) || isset($out[$m[1]])) {
                return null;                                     // formato inválido o id repetido
            }
            $out[$m[1]] = (string) hex2bin($m[2]);
        }
        return $out === [] ? null : $out;
    }

    /**
     * Anillo HMAC "h1:secreto,h2:secreto" → [id => secreto] (el secreto se usa tal cual, como la llave única histórica).
     * Sin anillo, ACCOUNT_HMAC_KEY es la llave «h1». null si el formato es inválido.
     * @return array<string,string>|null
     */
    public static function parseHmacRing(string $spec, string $legacy = ''): ?array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            if (!preg_match('/^([a-z0-9_-]{1,16}):([0-9a-f]{64})$/', $part, $m) || isset($out[$m[1]])) {
                return null;
            }
            $out[$m[1]] = $m[2];
        }
        if ($out === [] && $legacy !== '') {
            $out = preg_match('/^[0-9a-f]{64}$/', $legacy) ? ['h1' => $legacy] : [];
        }
        return $out === [] ? null : $out;
    }

    public function assertSecure(): void
    {
        $errors = [];
        if (!preg_match('/^[0-9a-f]{64}$/', (string) $this->get('app.key'))) {
            $errors[] = 'APP_KEY debe tener 64 caracteres hexadecimales';
        }
        // La web no usa la llave HMAC de cuentas; si está configurada, debe ser válida (la usan las CLI del mismo entorno)
        $hk = (string) $this->get('app.hmac_key');
        if ($hk !== '' && !preg_match('/^[0-9a-f]{64}$/', $hk)) {
            $errors[] = 'ACCOUNT_HMAC_KEY debe tener 64 caracteres hexadecimales';
        }
        if ((string) $this->get('app.hmac_keys') !== '' && self::parseHmacRing((string) $this->get('app.hmac_keys')) === null) {
            $errors[] = 'ACCOUNT_HMAC_KEYS debe ser "id:64hex[,id:64hex]"';
        }
        $ring = self::parseKeyRing((string) $this->get('app.enc_keys'));
        if ($ring === null || !isset($ring[(string) $this->get('app.enc_key_id')])) {
            $errors[] = 'ACCOUNT_ENC_KEYS debe ser "id:64hex[,id:64hex]" e incluir ACCOUNT_ENC_KEY_ID';
        }
        $rring = self::parseKeyRing((string) $this->get('app.report_enc_keys'));
        if ($rring === null || !isset($rring[(string) $this->get('app.report_enc_key_id')])) {
            $errors[] = 'REPORT_ENC_KEYS debe ser "id:64hex[,id:64hex]" e incluir REPORT_ENC_KEY_ID';
        } else {
            // Separación de llaves: ningún secreto de reportes puede repetir el de cuentas, HMAC o APP_KEY
            $otros = [...array_values($ring ?? []), ...array_map('hex2bin', array_filter([(string) $this->get('app.key'), (string) $this->get('app.hmac_key')],
                static fn ($h) => preg_match('/^[0-9a-f]{64}$/', $h) === 1))];
            foreach (self::parseHmacRing((string) $this->get('app.hmac_keys')) ?? [] as $h) {
                $otros[] = preg_match('/^[0-9a-f]{64}$/', $h) ? hex2bin($h) : $h;
            }
            foreach ($rring as $k) {
                foreach ($otros as $o) {
                    if (hash_equals((string) $o, $k)) {
                        $errors[] = 'REPORT_ENC_KEYS no puede reutilizar la llave de cuentas, HMAC ni APP_KEY';
                        break 2;
                    }
                }
            }
        }
        if (!in_array($this->get('app.env'), ['development', 'testing', 'production'], true)) {
            $errors[] = 'APP_ENV inválido';
        }
        if ($this->isProduction()) {
            if ($this->get('app.debug')) {
                $errors[] = 'APP_DEBUG debe ser false en producción';
            }
            if (str_starts_with((string) $this->get('app.url'), 'https://') && !$this->get('session.secure')) {
                $errors[] = 'SESSION_SECURE debe ser true cuando APP_URL usa HTTPS';
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Configuración insegura o incompleta: ' . implode('; ', $errors));
        }
    }

    /**
     * Arranque del scheduler (FASE 9 §34): su entorno propio (scheduler.env) no lleva APP_KEY ni llaves de cuentas, pero SÍ
     * el anillo de reportes y su rol de BD. Falta algo → no arranca (nunca genera reportes sin cifrar a medias).
     */
    public function assertScheduler(): void
    {
        $errors = [];
        if (!in_array($this->get('app.env'), ['development', 'testing', 'production'], true)) {
            $errors[] = 'APP_ENV inválido';
        }
        if ($this->isProduction() && $this->get('app.debug')) {
            $errors[] = 'APP_DEBUG debe ser false en producción';
        }
        if ((string) $this->get('scheduler.password') === '') {
            $errors[] = 'SCHEDULER_DB_PASSWORD vacío (rol conciliarh_scheduler)';
        }
        $rring = self::parseKeyRing((string) $this->get('app.report_enc_keys'));
        if ($rring === null || !isset($rring[(string) $this->get('app.report_enc_key_id')])) {
            $errors[] = 'REPORT_ENC_KEYS debe ser "id:64hex[,id:64hex]" e incluir REPORT_ENC_KEY_ID';
        }
        if (!is_dir((string) $this->get('storage.path'))) {
            $errors[] = 'STORAGE_PATH no existe';
        }
        if ($errors !== []) {
            throw new RuntimeException('Configuración incompleta para el scheduler: ' . implode('; ', $errors));
        }
    }
}
