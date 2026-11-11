<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Audit;

/** Actor y origen de las acciones auditadas en la petición o comando actual. */
final class AuditContext
{
    public ?int $userId = null;
    public ?string $username = null;
    public ?string $roles = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public string $origin = 'WEB';

    public function setActor(?int $userId, ?string $username, ?string $roles): void
    {
        $this->userId = $userId;
        $this->username = $username;
        $this->roles = $roles;
    }
}
