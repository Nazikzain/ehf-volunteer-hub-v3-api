<?php

declare(strict_types=1);

namespace Ehf\Api\Repositories;

use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;

/**
 * User accounts and their single-use tokens (Database V1.4 §5.2.1, §5.2.8). Account data only:
 * never the volunteer record's operational or personal fields.
 */
final readonly class AccountRepository
{
    private const string COLUMNS = 'id, email, display_name, password_hash, status, status_reason, session_version, last_login_at, email_verified_at, version, created_at';

    public function __construct(private Database $db) {}

    /**
     * @return array<string, scalar|null>|null
     */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT ' . self::COLUMNS . ' FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    public function emailExists(string $email): bool
    {
        return $this->db->value('SELECT COUNT(*) FROM users WHERE email = ?', [mb_strtolower($email)]) > 0;
    }

    public function create(string $email, string $displayName, int $createdBy): int
    {
        return $this->db->insert('INSERT INTO users (email, display_name, created_by_user_id) VALUES (?, ?, ?)', [mb_strtolower($email), $displayName, $createdBy]);
    }

    /**
     * @return bool false when the version did not match (lost update)
     */
    public function updateDisplayName(int $id, string $displayName, int $expectedVersion, int $updatedBy, string $now): bool
    {
        return $this->db->execute(
            'UPDATE users SET display_name = ?, updated_at = ?, updated_by_user_id = ?, version = version + 1 WHERE id = ? AND version = ?',
            [$displayName, $now, $updatedBy, $id, $expectedVersion],
        ) > 0;
    }

    public function setStatus(int $id, string $status, ?string $reason, int $expectedVersion, int $updatedBy, string $now): bool
    {
        return $this->db->execute(
            'UPDATE users SET status = ?, status_reason = ?, updated_at = ?, updated_by_user_id = ?, version = version + 1 WHERE id = ? AND version = ?',
            [$status, $reason, $now, $updatedBy, $id, $expectedVersion],
        ) > 0;
    }

    public function setPassword(int $id, string $hash, string $now): void
    {
        $this->db->execute('UPDATE users SET password_hash = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$hash, $now, $id]);
    }

    public function activate(int $id, string $hash, string $now): void
    {
        $this->db->execute(
            "UPDATE users SET password_hash = ?, status = 'active', email_verified_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND status = 'invited'",
            [$hash, $now, $now, $id],
        );
    }

    public function recordLogin(int $id, string $now): void
    {
        $this->db->execute('UPDATE users SET last_login_at = ? WHERE id = ?', [$now, $id]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function search(?string $query, ?string $status, ?string $role, int $limit, int $offset): array
    {
        [$where, $params] = self::filters($query, $status, $role);
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->all('SELECT ' . self::COLUMNS . " FROM users u {$where} ORDER BY id LIMIT ? OFFSET ?", $params);
    }

    public function count(?string $query, ?string $status, ?string $role): int
    {
        [$where, $params] = self::filters($query, $status, $role);

        return (int) $this->db->value("SELECT COUNT(*) FROM users u {$where}", $params);
    }

    /**
     * @return list<string>
     */
    public function activeRoles(int $userId): array
    {
        return array_map(
            static fn(array $row): string => Row::string($row, 'role_code'),
            $this->db->all('SELECT role_code FROM user_roles WHERE user_id = ? AND revoked_at IS NULL ORDER BY role_code', [$userId]),
        );
    }

    public function volunteerReference(int $userId): ?string
    {
        $reference = $this->db->value('SELECT reference FROM volunteers WHERE user_id = ?', [$userId]);

        return $reference === null ? null : (string) $reference;
    }

    public function invitationStatus(int $userId, string $now): string
    {
        $row = $this->db->one(
            "SELECT expires_at FROM auth_tokens WHERE user_id = ? AND type = 'invitation' AND used_at IS NULL AND revoked_at IS NULL ORDER BY id DESC LIMIT 1",
            [$userId],
        );
        if ($row === null) {
            return 'none';
        }

        return Row::string($row, 'expires_at') > $now ? 'pending' : 'expired';
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function findToken(string $tokenHash, string $type, bool $forUpdate = false): ?array
    {
        return $this->db->one(
            'SELECT id, user_id, type, expires_at, used_at, revoked_at FROM auth_tokens WHERE token_hash = ? AND type = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$tokenHash, $type],
        );
    }

    public function useToken(int $id, string $now): void
    {
        $this->db->execute('UPDATE auth_tokens SET used_at = ? WHERE id = ?', [$now, $id]);
    }

    public function revokeTokens(int $userId, string $type, string $now): int
    {
        return $this->db->execute('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL AND revoked_at IS NULL', [$now, $userId, $type]);
    }

    /**
     * @return array{0: string, 1: list<scalar|null>}
     */
    private static function filters(?string $query, ?string $status, ?string $role): array
    {
        $conditions = [];
        $params = [];
        if ($query !== null) {
            $conditions[] = '(u.display_name LIKE ? OR u.email LIKE ?)';
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== null) {
            $conditions[] = 'u.status = ?';
            $params[] = $status;
        }
        if ($role !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role_code = ? AND r.revoked_at IS NULL)';
            $params[] = $role;
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }
}
