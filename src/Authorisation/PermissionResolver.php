<?php

declare(strict_types=1);

namespace Ehf\Api\Authorisation;

use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;

/**
 * Computes a user's effective permissions for one request (API §6.2, §6.5, §6.11):
 * the union of
 *  - the default bundles of the user's active roles,
 *  - active, unexpired extra grants,
 *  - authorised, unexpired operational exceptions — only for a holder of technical_manager,
 *  - every governance code, while the user holds an active System Owner membership.
 *
 * Nothing is cached between requests, so revocation and expiry take effect immediately.
 * No code implies another (API §6.3): a code is held only if it appears in one of these sources.
 *
 * R13: codes that come only from a bundle entry listed in FieldPolicy::DECISION_CONTEXT_ONLY are
 * reported as `context_only`; they count inside a decision context and nowhere else.
 */
final readonly class PermissionResolver
{
    public const string TECHNICAL_MANAGER = 'technical_manager';

    public function __construct(private Database $db) {}

    /**
     * @return array{roles: list<string>, permissions: array<string, 'organisation'|list<int>>, owner_membership_id: ?int, context_only: list<string>}
     */
    public function resolve(int $userId, \DateTimeImmutable $now): array
    {
        $at = Database::timestamp($now);
        /** @var array<string, 'organisation'|list<int>> $permissions */
        $permissions = [];
        /** @var array<string, true> $unrestricted codes held from at least one source that is not context-only */
        $unrestricted = [];

        $roles = [];
        foreach ($this->db->all('SELECT role_code, scope_type, scope_project_id FROM user_roles WHERE user_id = ? AND revoked_at IS NULL ORDER BY id', [$userId]) as $role) {
            $code = Row::string($role, 'role_code');
            $roles[] = $code;
            $contextOnly = FieldPolicy::DECISION_CONTEXT_ONLY[$code] ?? [];
            foreach ($this->db->all('SELECT permission_code FROM role_permissions WHERE role_code = ?', [$code]) as $bundle) {
                $permission = Row::string($bundle, 'permission_code');
                self::add($permissions, $permission, $role);
                if (!\in_array($permission, $contextOnly, true)) {
                    $unrestricted[$permission] = true;
                }
            }
        }
        $roles = array_values(array_unique($roles));

        foreach ($this->db->all('SELECT permission_code, scope_type, scope_project_id FROM user_permission_grants WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)', [$userId, $at]) as $grant) {
            self::add($permissions, Row::string($grant, 'permission_code'), $grant);
            $unrestricted[Row::string($grant, 'permission_code')] = true;
        }

        if (\in_array(self::TECHNICAL_MANAGER, $roles, true)) {
            foreach ($this->db->all("SELECT permission_code, scope_type, scope_project_id FROM operational_exceptions WHERE recipient_user_id = ? AND status = 'authorised' AND (expires_at IS NULL OR expires_at > ?)", [$userId, $at]) as $exception) {
                self::add($permissions, Row::string($exception, 'permission_code'), $exception);
                $unrestricted[Row::string($exception, 'permission_code')] = true;
            }
        }

        $membership = $this->db->value('SELECT id FROM system_owner_memberships WHERE user_id = ? AND removed_at IS NULL', [$userId]);
        if ($membership !== null) {
            foreach ($this->db->all("SELECT code FROM permissions WHERE category = 'governance'") as $governance) {
                $permissions[Row::string($governance, 'code')] = 'organisation';
                $unrestricted[Row::string($governance, 'code')] = true;
            }
        }

        ksort($permissions);

        $contextOnly = array_values(array_diff(array_keys($permissions), array_keys($unrestricted)));
        sort($contextOnly);

        return ['roles' => $roles, 'permissions' => $permissions, 'owner_membership_id' => $membership === null ? null : (int) $membership, 'context_only' => $contextOnly];
    }

    /**
     * @param array<string, 'organisation'|list<int>> $permissions
     * @param array<string, scalar|null>               $source      row with scope_type and scope_project_id
     */
    private static function add(array &$permissions, string $code, array $source): void
    {
        if (($permissions[$code] ?? null) === 'organisation') {
            return;
        }

        if (Row::string($source, 'scope_type') === 'organisation') {
            $permissions[$code] = 'organisation';

            return;
        }

        $projects = $permissions[$code] ?? [];
        \assert(\is_array($projects));
        $projects[] = Row::int($source, 'scope_project_id');
        sort($projects);
        $permissions[$code] = array_values(array_unique($projects));
    }

    /**
     * Field sets the user may read and write (API §6.7), for GET /auth/session `field_access`.
     * Context-only codes (R13) are listed separately under `decision_context`, because they never
     * apply to volunteer record endpoints.
     *
     * @param array<string, 'organisation'|list<int>> $permissions
     * @param list<string>                            $contextOnly
     *
     * @return array<string, true|list<string>|array<string, list<string>>>
     */
    public static function fieldAccess(array $permissions, array $contextOnly = []): array
    {
        $access = ['basic' => true];
        $context = [];
        foreach (array_keys($permissions) as $code) {
            if (preg_match('/^fields\.(contact|sensitive)\.([a-z0-9_]+)$/', $code, $m) === 1) {
                if (\in_array($code, $contextOnly, true)) {
                    $context[$m[1]][] = $m[2];

                    continue;
                }
                $fields = $access[$m[1]] ?? [];
                \assert(\is_array($fields));
                $fields[] = $m[2];
                $access[$m[1]] = $fields;
            } elseif (preg_match('/^confidential\.([a-z_]+)\.view$/', $code, $m) === 1) {
                $access['confidential.' . $m[1]] = true;
            }
        }
        if ($context !== []) {
            $access['decision_context'] = $context;
        }

        return $access;
    }
}
