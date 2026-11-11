<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Config;
use RuntimeException;

final class AccountVault
{
    public function __construct(private readonly Config $config)
    {
    }

    public function reveal(string $ciphertext, string $keyId): string
    {
        $ring = Config::parseKeyRing((string) $this->config->get('app.enc_keys'));
        $key = $ring[$keyId] ?? null;
        if ($key === null) {
            throw new RuntimeException('Cuenta no descifrable con las llaves configuradas.');
        }
        return self::decrypt($ciphertext, $keyId, $key);
    }

    public static function decrypt(string $ciphertext, string $keyId, string $key): string
    {
        if (strlen($ciphertext) < 1 + 12 + 16 || $ciphertext[0] !== "\x01" || strlen($key) !== 32) {
            throw new RuntimeException('Cuenta no descifrable con las llaves configuradas.');
        }
        $nonce = substr($ciphertext, 1, 12);
        $tag = substr($ciphertext, -16);
        $body = substr($ciphertext, 13, -16);
        $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'conciliarh:cuenta:v1:' . $keyId);
        if ($plain === false || !preg_match('/^\d{6,20}$/', $plain)) {
            throw new RuntimeException('Cuenta no descifrable con las llaves configuradas.');
        }
        return $plain;
    }

    public static function encrypt(string $plain, string $keyId, string $key): string
    {
        if (!preg_match('/^\d{6,20}$/', $plain) || strlen($key) !== 32) {
            throw new RuntimeException('Valor o llave inválidos para cifrar.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'conciliarh:cuenta:v1:' . $keyId, 16);
        if ($ct === false) {
            throw new RuntimeException('No se pudo cifrar.');
        }
        return "\x01" . $nonce . $ct . $tag;
    }

    public static function mask(?string $last4): string
    {
        return $last4 === null || $last4 === '' ? '—' : '••••' . $last4;
    }

    public static function maskId(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return mb_substr($value, 0, 4) . str_repeat('•', max(3, mb_strlen($value) - 4));
    }
}
