<?php

declare(strict_types=1);

use ConciliaRH\Core\Env;

return static function (Env $env, string $basePath): array {
    $storage = $env->string('STORAGE_PATH', $basePath . '/storage');

    return [
        'app' => [
            'name' => 'ConciliaRH',
            'full_name' => 'Sistema Integral de Conciliación de Nómina y Pagos',
            'institution' => 'Tesorería Municipal',
            'env' => $env->string('APP_ENV', 'production'),
            'debug' => $env->bool('APP_DEBUG', false),
            'url' => rtrim($env->string('APP_URL', 'http://localhost'), '/'),
            'timezone' => $env->string('APP_TIMEZONE', 'America/Mexico_City'),
            'key' => $env->string('APP_KEY'),
            'hmac_key' => $env->string('ACCOUNT_HMAC_KEY'),
            'hmac_keys' => $env->string('ACCOUNT_HMAC_KEYS'),
            'enc_key_id' => $env->string('ACCOUNT_ENC_KEY_ID', 'k1'),
            'enc_keys' => $env->string('ACCOUNT_ENC_KEYS'),
            'report_enc_key_id' => $env->string('REPORT_ENC_KEY_ID', 'r1'),
            'report_enc_keys' => $env->string('REPORT_ENC_KEYS'),
            'version' => is_file($basePath . '/VERSION') ? trim((string) file_get_contents($basePath . '/VERSION')) : '0.0.0',
        ],
        'db' => [
            'host' => $env->string('DB_HOST', '127.0.0.1'),
            'port' => $env->int('DB_PORT', 5432),
            'database' => $env->string('DB_DATABASE', 'conciliarh'),
            'username' => $env->string('DB_USERNAME', 'conciliarh_web'),
            'password' => $env->string('DB_PASSWORD'),
            'sslmode' => $env->string('DB_SSLMODE', 'prefer'),
            'application_name' => 'conciliarh_web',
        ],
        'db_migrate' => [
            'username' => $env->string('DB_MIGRATE_USERNAME', 'conciliarh_owner'),
            'password' => $env->string('DB_MIGRATE_PASSWORD'),
        ],
        'db_test_database' => $env->string('DB_TEST_DATABASE', 'conciliarh_test'),
        'session' => [
            'name' => $env->bool('SESSION_SECURE', true) ? '__Host-conciliarh_sid' : 'conciliarh_sid',
            'secure' => $env->bool('SESSION_SECURE', true),
            'idle_minutes' => $env->int('SESSION_IDLE_MINUTES', 30),
            'absolute_hours' => $env->int('SESSION_ABSOLUTE_HOURS', 10),
        ],
        'storage' => [
            'path' => rtrim($storage, '/'),
            'logs' => rtrim($storage, '/') . '/logs',
        ],
        'upload' => [
            'max_size' => $env->int('MAX_UPLOAD_SIZE', 26214400),
        ],
        'worker' => [
            'heartbeat_timeout' => $env->int('WORKER_HEARTBEAT_TIMEOUT', 120),
            'poll_interval' => $env->int('WORKER_POLL_INTERVAL', 5),
            'username' => $env->string('WORKER_DB_USERNAME', 'conciliarh_worker'),
            'password' => $env->string('WORKER_DB_PASSWORD'),
        ],
        'scheduler' => [
            'username' => $env->string('SCHEDULER_DB_USERNAME', 'conciliarh_scheduler'),
            'password' => $env->string('SCHEDULER_DB_PASSWORD'),
        ],
    ];
};
