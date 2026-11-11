<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Domain\Identity\HmacRotator;
use ConciliaRH\Engine\Worker\JobFailure;
use ConciliaRH\Engine\Worker\WorkerSettings;

final class AccountCrypto
{
    /** @return array{0:string,1:string} [key_id, secreto] */
    public static function activeHmac(Database $db, WorkerSettings $settings): array
    {
        $kid = (string) $db->fetchValue('SELECT fn_hmac_key_activa()');
        if ($settings->hmacRing === []) {
            throw JobFailure::permanent('Falta la llave HMAC del motor (ACCOUNT_HMAC_KEYS / ACCOUNT_HMAC_KEY).', 'CONFIGURACION_INCOMPLETA');
        }
        if (!isset($settings->hmacRing[$kid])) {
            throw JobFailure::retriable("La llave HMAC activa ($kid) no está en el anillo del procesador; actualice ACCOUNT_HMAC_KEYS y reinicie.",
                'LLAVE_HMAC_NO_DISPONIBLE');
        }
        if (!(bool) $db->fetchValue('SELECT fn_hmac_verificar_kcv(:k, :c)', ['k' => $kid, 'c' => HmacRotator::kcv($settings->hmacRing[$kid])])) {
            throw JobFailure::retriable("La llave HMAC $kid del procesador no coincide con la registrada; corrija ACCOUNT_HMAC_KEYS y reinicie.",
                'LLAVE_HMAC_INCORRECTA');
        }
        return [$kid, $settings->hmacRing[$kid]];
    }

    public static function stagingKey(string $secret): string
    {
        if ($secret === '') {
            throw JobFailure::permanent('Falta la llave HMAC del motor (ACCOUNT_HMAC_KEY).', 'CONFIGURACION_INCOMPLETA');
        }
        return hash_hmac('sha256', 'conciliarh:staging:v1', $secret, true);
    }

    public static function accountHmac(string $secret, string $digits): string
    {
        if ($secret === '') {
            throw JobFailure::permanent('Falta la llave HMAC de cuentas del motor.', 'CONFIGURACION_INCOMPLETA');
        }
        return hash_hmac('sha256', 'cuenta:' . $digits, $secret);
    }

    public static function requireEncryption(WorkerSettings $settings): void
    {
        if (!isset($settings->encRing[$settings->encKeyId])) {
            throw JobFailure::permanent('Falta la llave de cifrado de cuentas del motor (ACCOUNT_ENC_KEYS / ACCOUNT_ENC_KEY_ID).', 'CONFIGURACION_INCOMPLETA');
        }
    }

    public static function encrypt(WorkerSettings $settings, string $digits): string
    {
        self::requireEncryption($settings);
        return AccountVault::encrypt($digits, $settings->encKeyId, $settings->encRing[$settings->encKeyId]);
    }
}
