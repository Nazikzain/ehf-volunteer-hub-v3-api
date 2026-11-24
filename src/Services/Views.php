<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Infrastructure\Database\Row;

/**
 * Response shapes. Account responses contain account data only (API §7.2): never volunteer
 * contact or sensitive fields, notes or operational status.
 */
final class Views
{
    private function __construct() {}

    /**
     * @param array<string, scalar|null> $user
     * @param list<string>               $roles
     *
     * @return array<string, mixed>
     */
    public static function account(array $user, array $roles, string $invitationStatus, ?string $volunteerReference): array
    {
        return [
            'id' => Row::int($user, 'id'),
            'display_name' => Row::string($user, 'display_name'),
            'email' => Row::string($user, 'email'),
            'status' => Row::string($user, 'status'),
            'roles' => $roles,
            'last_login_at' => Row::isoOrNull($user, 'last_login_at'),
            'invitation_status' => $invitationStatus,
            'volunteer_reference' => $volunteerReference,
            'created_at' => Row::isoOrNull($user, 'created_at'),
            'version' => Row::int($user, 'version'),
        ];
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    public static function roleAssignment(array $row): array
    {
        return [
            'id' => Row::int($row, 'id'),
            'user_id' => Row::int($row, 'user_id'),
            'role' => Row::string($row, 'role_code'),
            'scope' => self::scope($row),
            'reason' => Row::string($row, 'reason'),
            'granted_by' => Row::intOrNull($row, 'granted_by_user_id'),
            'authorised_by_owner' => Row::intOrNull($row, 'authorised_by_owner'),
            'granted_at' => Row::isoOrNull($row, 'granted_at'),
            'revoked_at' => Row::isoOrNull($row, 'revoked_at'),
            'revoked_by' => Row::intOrNull($row, 'revoked_by_user_id'),
        ];
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    public static function grant(array $row): array
    {
        return [
            'id' => Row::int($row, 'id'),
            'user_id' => Row::int($row, 'user_id'),
            'permission' => Row::string($row, 'permission_code'),
            'scope' => self::scope($row),
            'reason' => Row::string($row, 'reason'),
            'expires_at' => Row::isoOrNull($row, 'expires_at'),
            'granted_by' => Row::int($row, 'granted_by_user_id'),
            'granted_at' => Row::isoOrNull($row, 'granted_at'),
            'revoked_at' => Row::isoOrNull($row, 'revoked_at'),
            'revoked_by' => Row::intOrNull($row, 'revoked_by_user_id'),
        ];
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    public static function exception(array $row): array
    {
        return [
            'id' => Row::int($row, 'id'),
            'recipient_user_id' => Row::int($row, 'recipient_user_id'),
            'permission' => Row::string($row, 'permission_code'),
            'scope' => self::scope($row),
            'reason' => Row::string($row, 'reason'),
            'expires_at' => Row::isoOrNull($row, 'expires_at'),
            'status' => Row::string($row, 'status'),
            'requested_by' => Row::int($row, 'requested_by_user_id'),
            'authorised_by' => Row::intOrNull($row, 'authorised_by_user_id'),
            'authorised_at' => Row::isoOrNull($row, 'authorised_at'),
            'rejected_by' => Row::intOrNull($row, 'rejected_by_user_id'),
            'rejected_at' => Row::isoOrNull($row, 'rejected_at'),
            'revoked_by' => Row::intOrNull($row, 'revoked_by_user_id'),
            'revoked_at' => Row::isoOrNull($row, 'revoked_at'),
            'created_at' => Row::isoOrNull($row, 'created_at'),
            'version' => Row::int($row, 'version'),
        ];
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array{type: string, project_id: ?int}
     */
    private static function scope(array $row): array
    {
        return ['type' => Row::string($row, 'scope_type'), 'project_id' => Row::intOrNull($row, 'scope_project_id')];
    }
}
