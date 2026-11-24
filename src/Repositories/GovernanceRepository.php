<?php

declare(strict_types=1);

namespace Ehf\Api\Repositories;

use Ehf\Api\Infrastructure\Database\Database;

/**
 * System Owner memberships, owner authorisations and operational exceptions
 * (Database V1.4 §5.2.2, §5.2.3, §5.2.7).
 */
final readonly class GovernanceRepository
{
    public function __construct(private Database $db) {}

    /**
     * Active memberships with their account status, locked for the final-owner check (G7).
     *
     * @return list<array<string, scalar|null>>
     */
    public function lockActiveOwners(): array
    {
        return $this->db->all(
            'SELECT m.id, m.user_id, u.status FROM system_owner_memberships m JOIN users u ON u.id = m.user_id
             WHERE m.removed_at IS NULL ORDER BY m.id FOR UPDATE',
        );
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function activeOwners(): array
    {
        return $this->db->all(
            'SELECT m.id, m.user_id, u.display_name, u.email, u.status, m.added_via, m.added_by_user_id, m.added_at
             FROM system_owner_memberships m JOIN users u ON u.id = m.user_id WHERE m.removed_at IS NULL ORDER BY m.id',
        );
    }

    public function activeMembershipId(int $userId): ?int
    {
        $id = $this->db->value('SELECT id FROM system_owner_memberships WHERE user_id = ? AND removed_at IS NULL', [$userId]);

        return $id === null ? null : (int) $id;
    }

    public function removeMembership(int $membershipId, int $removedByMembershipId, int $removedByUserId, string $reason, string $now): void
    {
        $this->db->execute(
            'UPDATE system_owner_memberships SET removed_at = ?, removed_by_membership_id = ?, removed_by_user_id = ?, remove_reason = ?, active_key = NULL
             WHERE id = ? AND removed_at IS NULL',
            [$now, $removedByMembershipId, $removedByUserId, $reason, $membershipId],
        );
    }

    public function insertOwnerAuthorisation(int $ownerMembershipId, int $ownerUserId, int $subjectUserId, string $action, string $reason, string $now): int
    {
        return $this->db->insert(
            "INSERT INTO owner_authorisations (kind, owner_membership_id, owner_user_id, subject_user_id, action, reason, password_confirmed_at, created_at, created_by_user_id)
             VALUES ('owner', ?, ?, ?, ?, ?, ?, ?, ?)",
            [$ownerMembershipId, $ownerUserId, $subjectUserId, $action, $reason, $now, $now, $ownerUserId],
        );
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function exceptions(?string $status, ?int $userId): array
    {
        $conditions = [];
        $params = [];
        if ($status !== null) {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }
        if ($userId !== null) {
            $conditions[] = 'recipient_user_id = ?';
            $params[] = $userId;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return $this->db->all("SELECT * FROM operational_exceptions {$where} ORDER BY id", $params);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function exception(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one('SELECT * FROM operational_exceptions WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    public function insertException(int $recipient, string $code, string $scopeType, ?int $projectId, string $reason, ?string $expiresAt, int $requestedBy, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO operational_exceptions (recipient_user_id, permission_code, scope_type, scope_project_id, reason, expires_at, requested_by_user_id, created_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$recipient, $code, $scopeType, $projectId, $reason, $expiresAt, $requestedBy, $now, $requestedBy],
        );
    }

    public function authoriseException(int $id, int $version, int $membershipId, int $userId, string $now): bool
    {
        return $this->db->execute(
            "UPDATE operational_exceptions SET status = 'authorised', authorised_by_membership_id = ?, authorised_by_user_id = ?, authorised_at = ?, version = version + 1
             WHERE id = ? AND version = ? AND status = 'pending'",
            [$membershipId, $userId, $now, $id, $version],
        ) > 0;
    }

    public function rejectException(int $id, int $version, int $membershipId, int $userId, string $reason, string $now): bool
    {
        return $this->db->execute(
            "UPDATE operational_exceptions SET status = 'rejected', rejected_by_membership_id = ?, rejected_by_user_id = ?, rejected_at = ?, reject_reason = ?, version = version + 1
             WHERE id = ? AND version = ? AND status = 'pending'",
            [$membershipId, $userId, $now, $reason, $id, $version],
        ) > 0;
    }

    public function revokeException(int $id, int $version, int $membershipId, int $userId, string $reason, string $now): bool
    {
        return $this->db->execute(
            "UPDATE operational_exceptions SET status = 'revoked', revoked_by_membership_id = ?, revoked_by_user_id = ?, revoked_at = ?, revoke_reason = ?, version = version + 1
             WHERE id = ? AND version = ? AND status = 'authorised'",
            [$membershipId, $userId, $now, $reason, $id, $version],
        ) > 0;
    }
}
