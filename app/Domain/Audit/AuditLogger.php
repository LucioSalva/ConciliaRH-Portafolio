<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Audit;

use ConciliaRH\Core\Database;

/**
 * Registra acciones en la tabla `auditoria` (append-only, encadenada por hash en la BD).
 * Los valores se redactan: nunca se guardan contraseñas, hashes, tokens ni cuentas completas.
 */
final class AuditLogger
{
    private const SENSITIVE_KEY = '/pass|token|secret|hash|clabe|cuenta_completa|cookie|curp|rfc/i';

    public function __construct(private readonly Database $db, private readonly AuditContext $ctx)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed>|null $context
     */
    public function record(
        string $action,
        ?string $entity = null,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
    ): void {
        $this->db->execute(
            'INSERT INTO auditoria (usuario_id, username, roles, origen, ip, user_agent, accion, entidad, entidad_id,
                                    valor_anterior, valor_nuevo, contexto, hash)
             VALUES (:uid, :username, :roles, :origen, CAST(:ip AS inet), :ua, :accion, :entidad, :entidad_id,
                     CAST(:antes AS jsonb), CAST(:despues AS jsonb), CAST(:contexto AS jsonb), :hash)',
            [
                'uid' => $this->ctx->userId,
                'username' => $this->ctx->username,
                'roles' => $this->ctx->roles,
                'origen' => $this->ctx->origin,
                'ip' => $this->ctx->ip,
                'ua' => $this->ctx->userAgent,
                'accion' => $action,
                'entidad' => $entity,
                'entidad_id' => $entityId === null ? null : (string) $entityId,
                'antes' => $before === null ? null : self::json(self::redact($before)),
                'despues' => $after === null ? null : self::json(self::redact($after)),
                'contexto' => $context === null ? null : self::json(self::redact($context)),
                // Marcador: el trigger fn_auditoria_encadenar calcula el hash real.
                'hash' => str_repeat('0', 64),
            ]
        );
    }

    /** @param array<mixed> $data @return array<mixed> */
    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE_KEY, $k)) {
                $out[$k] = '[REDACTADO]';
            } elseif (\ConciliaRH\Core\Logger::isDigest($k, $v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $out[$k] = \ConciliaRH\Core\Logger::maskText($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
