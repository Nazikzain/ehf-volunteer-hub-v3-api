<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

use Ehf\Api\Authorisation\PermissionResolver;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;

/**
 * Server-side sessions in `sessions` (D4).
 *
 * - The cookie carries 256 random bits; only their SHA-256 is stored.
 * - The CSRF token is derived from the session identifier (HMAC), and only its hash is stored, so
 *   it can be returned by GET /auth/session without the server keeping a secret.
 * - A session is valid only while: not revoked; before its absolute expiry; used within the idle
 *   timeout; the account is active; and the account's session_version is unchanged (signing out
 *   everywhere — API §5).
 */
final readonly class SessionService
{
    public function __construct(
        private Database $db,
        private Clock $clock,
        private AuthFeature $feature,
        private PermissionResolver $permissions,
    ) {}

    /**
     * @return array{id: string, id_hash: string}
     */
    public function start(int $userId, ?string $ipPrefix, string $agent): array
    {
        $now = $this->clock->now();
        $id = self::base64url(random_bytes(32));
        $version = $this->db->value('SELECT session_version FROM users WHERE id = ?', [$userId]);

        $this->db->execute(
            'INSERT INTO sessions (id_hash, user_id, csrf_hash, session_version_at_issue, acting_role, created_at, last_seen_at, absolute_expires_at, ip_prefix, agent_summary)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)',
            [
                self::hashId($id), $userId, hash('sha256', self::csrfToken($id)), (int) $version,
                Database::timestamp($now), Database::timestamp($now),
                Database::timestamp($now->modify("+{$this->feature->settings()->sessionAbsoluteMinutes} minutes")),
                $ipPrefix ?? '', mb_substr($agent, 0, 120),
            ],
        );

        return ['id' => $id, 'id_hash' => self::hashId($id)];
    }

    /**
     * Resolves the cookie to a Principal, or null when the session is missing or no longer valid.
     */
    public function resolve(string $sessionId): ?Principal
    {
        $settings = $this->feature->settings();
        $now = $this->clock->now();
        $idHash = self::hashId($sessionId);
        $row = $this->db->one(
            'SELECT s.user_id, s.csrf_hash, s.session_version_at_issue, s.last_seen_at, s.absolute_expires_at, s.revoked_at,
                    u.email, u.display_name, u.status, u.session_version
             FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id_hash = ?',
            [$idHash],
        );

        if ($row === null
            || $row['revoked_at'] !== null
            || Row::time(Row::string($row, 'absolute_expires_at')) <= $now
            || Row::time(Row::string($row, 'last_seen_at'))->modify("+{$settings->sessionIdleMinutes} minutes") <= $now
            || Row::string($row, 'status') !== 'active'
            || Row::int($row, 'session_version') !== Row::int($row, 'session_version_at_issue')
            || !hash_equals(Row::string($row, 'csrf_hash'), hash('sha256', self::csrfToken($sessionId)))) {
            return null;
        }

        $this->db->execute('UPDATE sessions SET last_seen_at = ? WHERE id_hash = ?', [Database::timestamp($now), $idHash]);

        $userId = Row::int($row, 'user_id');
        $resolved = $this->permissions->resolve($userId, $now);
        $volunteer = $this->db->value('SELECT id FROM volunteers WHERE user_id = ?', [$userId]);

        return new Principal(
            userId: $userId,
            email: Row::string($row, 'email'),
            displayName: Row::string($row, 'display_name'),
            roles: $resolved['roles'],
            permissions: $resolved['permissions'],
            ownerMembershipId: $resolved['owner_membership_id'],
            volunteerId: $volunteer === null ? null : (int) $volunteer,
            sessionIdHash: $idHash,
            csrfToken: self::csrfToken($sessionId),
            contextOnly: $resolved['context_only'],
        );
    }

    public function revoke(string $idHash): bool
    {
        return $this->db->execute('UPDATE sessions SET revoked_at = ? WHERE id_hash = ? AND revoked_at IS NULL', [Database::timestamp($this->clock->now()), $idHash]) > 0;
    }

    /**
     * Ends every session of the user (API §5 "signing out everywhere"): bumps session_version, so
     * every session issued before becomes invalid, and marks open sessions revoked.
     */
    public function revokeAllForUser(int $userId, ?string $exceptIdHash = null): void
    {
        $this->db->execute('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        $now = Database::timestamp($this->clock->now());
        $this->db->execute('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id_hash <> ?', [$now, $userId, $exceptIdHash ?? '']);

        if ($exceptIdHash !== null) {
            // Keep the current session valid after a password change (API §7.1: other sessions end).
            $this->db->execute('UPDATE sessions SET session_version_at_issue = (SELECT session_version FROM users WHERE id = ?) WHERE id_hash = ?', [$userId, $exceptIdHash]);
        }
    }

    public static function hashId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public static function csrfToken(string $sessionId): string
    {
        return self::base64url(hash_hmac('sha256', 'ehf-csrf-token', $sessionId, true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
