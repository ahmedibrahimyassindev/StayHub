<?php

namespace App\Security;

class AuthenticatedIdentity
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int $userId,
        private readonly array $roles,
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function canManageBookings(): bool
    {
        return count(array_intersect($this->roles, ['admin', 'manager'])) > 0;
    }
}
