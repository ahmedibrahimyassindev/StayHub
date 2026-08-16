<?php

namespace App\Security;

class AuthenticatedIdentity
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $username,
        private readonly array $roles,
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function canManageUsers(): bool
    {
        return count(array_intersect($this->roles, ['ADMIN', 'HOTEL_MANAGER', 'admin', 'manager'])) > 0;
    }
}
