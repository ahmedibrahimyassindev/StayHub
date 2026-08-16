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

    public function canManagePayments(): bool
    {
        return count(array_intersect($this->roles, ['ADMIN', 'HOTEL_MANAGER', 'admin', 'manager'])) > 0;
    }
}
