<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Auth;

final class CurrentUser
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $nombre,
        public readonly bool $mustChangePassword,
        public readonly array $roles,
        private readonly array $permissions,
    ) {
    }

    public function isGod(): bool
    {
        return in_array('GOD', $this->roles, true);
    }

    public function can(string $permission): bool
    {
        return $this->isGod() || in_array($permission, $this->permissions, true);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function rolesLabel(): string
    {
        return implode(',', $this->roles);
    }

    public function initials(): string
    {
        $nombre = class_exists(\Normalizer::class) ? (\Normalizer::normalize($this->nombre, \Normalizer::FORM_C) ?: $this->nombre) : $this->nombre;
        preg_match_all('/[\p{L}\p{M}]+/u', $nombre, $m);
        $parts = $m[0];
        $ini = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $ini !== '' ? $ini : mb_strtoupper(mb_substr($this->username, 0, 2));
    }
}
