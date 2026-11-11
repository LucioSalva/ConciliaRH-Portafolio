<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Auth;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Settings\SettingsService;

final class LoginThrottle
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly string $hmacKey,
    ) {
    }

    public function key(string $subject, string $scope = 'login'): string
    {
        return hash_hmac('sha256', $scope . ':' . mb_strtolower(trim($subject)), $this->hmacKey);
    }

    public function windowMinutes(): int
    {
        return $this->settings->int('seguridad.login_ventana_minutos', 15);
    }

    public function lockSubject(string $subject, string $scope = 'login'): void
    {
        $this->db->execute('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => $this->key($subject, $scope)]);
    }

    public function isLocked(string $subject, ?string $ip, string $scope = 'login'): bool
    {
        $k = $this->key($subject, $scope);
        $w = $this->windowMinutes();
        $row = $this->db->fetchOne(
            "WITH ultimo_ok AS (
                 SELECT coalesce(max(creado_at), '-infinity') AS t FROM intentos_login WHERE username_hmac = :k1 AND exito
             )
             SELECT count(*) FILTER (WHERE ip IS NOT DISTINCT FROM CAST(:ip AS inet)) AS por_pareja,
                    count(*) AS global
               FROM intentos_login, ultimo_ok
              WHERE username_hmac = :k2 AND NOT exito
                AND creado_at > now() - make_interval(mins => :w) AND creado_at > ultimo_ok.t",
            ['k1' => $k, 'k2' => $k, 'ip' => $ip, 'w' => $w]
        );
        if ($scope === 'cambio_password') {
            return (int) $row['global'] >= $this->settings->int('seguridad.cambio_password_max_intentos', 5);
        }
        if ((int) $row['por_pareja'] >= $this->settings->int('seguridad.login_max_intentos_usuario', 5)
            || (int) $row['global'] >= $this->settings->int('seguridad.login_max_intentos_usuario_global', 20)) {
            return true;
        }
        if ($ip === null) {
            return false;
        }
        $failuresIp = (int) $this->db->fetchValue(
            "SELECT count(*) FROM intentos_login
              WHERE ip = CAST(:ip AS inet) AND ambito = 'login' AND NOT exito AND creado_at > now() - make_interval(mins => :w)",
            ['ip' => $ip, 'w' => $w]
        );
        return $failuresIp >= $this->settings->int('seguridad.login_max_intentos_ip', 30);
    }

    public function record(string $subject, ?string $ip, bool $success, string $scope = 'login'): bool
    {
        $wasLocked = $success ? false : $this->isLocked($subject, $ip, $scope);
        $this->db->execute(
            'INSERT INTO intentos_login (username_hmac, ip, exito, ambito) VALUES (:k, CAST(:ip AS inet), :ok, :ambito)',
            ['k' => $this->key($subject, $scope), 'ip' => $ip, 'ok' => $success, 'ambito' => $scope]
        );
        return !$success && !$wasLocked && $this->isLocked($subject, $ip, $scope);
    }
}
