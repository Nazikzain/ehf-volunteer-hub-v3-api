<?php

declare(strict_types=1);

namespace Ehf\Api\Repositories;

use Ehf\Api\Infrastructure\Database\Database;

/**
 * Roles, permissions, role assignments, extra grants and sessions (Database V1.4 §5.1–5.2).
 */
final readonly class AccessRepository
{
    public function __construct(private Database $db) {}

    /**
     * @return list<array<string, scalar|null>>
     */
    public function roles(): array
    {
        return $this->db->all('SELECT code, name, is_ordinary FROM roles ORDER BY code');
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function role(string $code): ?array
    {
        return $this->db->one('SELECT code, name, is_ordinary FROM roles WHERE code = ?', [$code]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function permissions(): array
    {
        return $this->db->all('SELECT code, category, action_type, is_field_code, grantable, exception_eligible, description FROM permissions ORDER BY code');
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function permission(string $code): ?array
    {
        return $this->db->one('SELECT code, category, action_type, is_field_code, grantable, exception_eligible FROM permissions WHERE code = ?', [$code]);
    }

    public function projectExists(int $projectId): bool
    {
        return $this->db->value('SELECT COUNT(*) FROM projects WHERE id = ?', [$projectId]) > 0;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function roleAssignments(int $userId): array
    {
        return $this->db->all(
            'SELECT r.id, r.user_id, r.role_code, r.scope_type, r.scope_project_id, r.reason, r.granted_by_user_id, r.granted_at,
                    r.revoked_at, r.revoked_by_user_id, r.revoke_reason, a.owner_user_id AS authorised_by_owner
             FROM user_roles r LEFT JOIN owner_authorisations a ON a.id = r.owner_authorisation_id
             WHERE r.user_id = ? ORDER BY r.id',
            [$userId],
        );
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function roleAssignment(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one('SELECT id, user_id, role_code, scope_type, scope_project_id, revoked_at FROM user_roles WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    public function activeRoleCount(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
    }

    public function holdsActiveRole(int $userId, string $role): bool
    {
        return $this->db->value('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_code = ? AND revoked_at IS NULL', [$userId, $role]) > 0;
    }

    public function insertRole(int $userId, string $role, string $scopeType, ?int $projectId, string $reason, ?int $grantedBy, ?int $ownerAuthorisationId, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO user_roles (user_id, role_code, scope_type, scope_project_id, reason, granted_by_user_id, granted_at, owner_authorisation_id, active_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $role, $scopeType, $projectId, $reason, $grantedBy, $now, $ownerAuthorisationId, self::roleKey($userId, $role, $scopeType, $projectId)],
        );
    }

    public function revokeRole(int $id, int $revokedBy, string $reason, ?int $revokeAuthorisationId, string $now): void
    {
        $this->db->execute(
            'UPDATE user_roles SET revoked_at = ?, revoked_by_user_id = ?, revoke_reason = ?, revoke_owner_authorisation_id = ?, active_key = NULL WHERE id = ? AND revoked_at IS NULL',
            [$now, $revokedBy, $reason, $revokeAuthorisationId, $id],
        );
    }

    public function activeRoleKeyExists(int $userId, string $role, string $scopeType, ?int $projectId): bool
    {
        return $this->db->value('SELECT COUNT(*) FROM user_roles WHERE active_key = ?', [self::roleKey($userId, $role, $scopeType, $projectId)]) > 0;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function grants(int $userId): array
    {
        return $this->db->all(
            'SELECT id, user_id, permission_code, scope_type, scope_project_id, reason, expires_at, granted_by_user_id, granted_at, revoked_at, revoked_by_user_id, revoke_reason
             FROM user_permission_grants WHERE user_id = ? ORDER BY id',
            [$userId],
        );
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function grant(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one('SELECT id, user_id, permission_code, revoked_at FROM user_permission_grants WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    public function activeGrantKeyExists(int $userId, string $code, string $scopeType, ?int $projectId): bool
    {
        return $this->db->value('SELECT COUNT(*) FROM user_permission_grants WHERE active_key = ?', [self::grantKey($userId, $code, $scopeType, $projectId)]) > 0;
    }

    public function insertGrant(int $userId, string $code, string $scopeType, ?int $projectId, string $reason, ?string $expiresAt, int $grantedBy, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO user_permission_grants (user_id, permission_code, scope_type, scope_project_id, reason, expires_at, granted_by_user_id, granted_at, active_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $code, $scopeType, $projectId, $reason, $expiresAt, $grantedBy, $now, self::grantKey($userId, $code, $scopeType, $projectId)],
        );
    }

    public function revokeGrant(int $id, int $revokedBy, string $reason, string $now): void
    {
        $this->db->execute(
            'UPDATE user_permission_grants SET revoked_at = ?, revoked_by_user_id = ?, revoke_reason = ?, active_key = NULL WHERE id = ? AND revoked_at IS NULL',
            [$now, $revokedBy, $reason, $id],
        );
    }

    /**
     * Active sessions (metadata only; never the session identifier).
     *
     * @return list<array<string, scalar|null>>
     */
    public function activeSessions(?int $userId, string $now): array
    {
        $sql = 'SELECT s.id_hash, s.user_id, u.display_name, s.created_at, s.last_seen_at, s.ip_prefix, s.agent_summary
                FROM sessions s JOIN users u ON u.id = s.user_id
                WHERE s.revoked_at IS NULL AND s.absolute_expires_at > ? AND s.session_version_at_issue = u.session_version';
        $params = [$now];
        if ($userId !== null) {
            $sql .= ' AND s.user_id = ?';
            $params[] = $userId;
        }

        return $this->db->all($sql . ' ORDER BY s.last_seen_at DESC', $params);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function session(string $idHash): ?array
    {
        return $this->db->one('SELECT id_hash, user_id, revoked_at FROM sessions WHERE id_hash = ?', [$idHash]);
    }

    private static function roleKey(int $userId, string $role, string $scopeType, ?int $projectId): string
    {
        return "{$userId}:{$role}:{$scopeType}" . ($projectId === null ? '' : ":{$projectId}");
    }

    private static function grantKey(int $userId, string $code, string $scopeType, ?int $projectId): string
    {
        return "{$userId}:{$code}:{$scopeType}" . ($projectId === null ? '' : ":{$projectId}");
    }
}
