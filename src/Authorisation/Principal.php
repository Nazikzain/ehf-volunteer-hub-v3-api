<?php

declare(strict_types=1);

namespace Ehf\Api\Authorisation;

/**
 * The signed-in user of the current request, with the effective permissions the server computed
 * for this request (API §6.2). Never built from anything the client sends.
 */
final readonly class Principal
{
    public const string SYSTEM_OWNER = 'system_owner';

    /**
     * @param list<string>                                 $roles       active role codes
     * @param array<string, 'organisation'|list<int>>      $permissions code => scope
     */
    public function __construct(
        public int $userId,
        public string $email,
        public string $displayName,
        public array $roles,
        public array $permissions,
        public ?int $ownerMembershipId,
        public ?int $volunteerId,
        public string $sessionIdHash,
        public string $csrfToken,
    ) {}

    public function can(string $code): bool
    {
        return isset($this->permissions[$code]);
    }

    public function holdsRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function isOwner(): bool
    {
        return $this->ownerMembershipId !== null;
    }

    /**
     * The role recorded in audit records. With one role per account (G10, interim) it is that role.
     */
    public function actingRole(): ?string
    {
        return \count($this->roles) === 1 ? $this->roles[0] : null;
    }
}
