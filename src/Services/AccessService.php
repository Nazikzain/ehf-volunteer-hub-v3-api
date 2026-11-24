<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Auth\SessionService;
use Ehf\Api\Authorisation\PermissionResolver;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;
use Ehf\Api\Repositories\AccessRepository;
use Ehf\Api\Repositories\AccountRepository;

/**
 * Ordinary roles, extra grants, the role/permission catalogues and session administration
 * (API §7.2), with guards:
 *  - G3: nobody changes their own roles or grants (`403 self_grant_forbidden`);
 *  - G4: `technical_manager` only through the System Owner endpoints (`403 owner_authority_required`);
 *  - G5: no operational role or code for a Technical Manager (`422 use_operational_exception`);
 *  - G8: governance codes are never granted (`403 owner_authority_required`);
 *  - G10 (interim, R2/R3): one active role per account (`422 combination_not_allowed`).
 * Every role or grant change ends the target's sessions, so new permissions apply at once.
 */
final readonly class AccessService
{
    public function __construct(
        private Database $db,
        private AccessRepository $access,
        private AccountRepository $accounts,
        private SessionService $sessions,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function roleCatalogue(): array
    {
        return array_map(static fn(array $r): array => [
            'code' => Row::string($r, 'code'),
            'name' => Row::string($r, 'name'),
            'ordinary' => Row::int($r, 'is_ordinary') === 1,
        ], $this->access->roles());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function permissionCatalogue(): array
    {
        return array_map(static fn(array $p): array => [
            'code' => Row::string($p, 'code'),
            'category' => Row::string($p, 'category'),
            'action_type' => Row::string($p, 'action_type'),
            'field_code' => Row::int($p, 'is_field_code') === 1,
            'grantable' => Row::int($p, 'grantable') === 1,
            'exception_eligible' => Row::int($p, 'exception_eligible') === 1,
            'description' => Row::string($p, 'description'),
        ], $this->access->permissions());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function roles(int $userId): array
    {
        $this->existingUser($userId);

        return array_map(Views::roleAssignment(...), $this->access->roleAssignments($userId));
    }

    /**
     * @return array<string, mixed>
     */
    public function assignRole(Principal $actor, int $userId, string $role, string $scopeType, ?int $projectId, string $reason, AuditContext $context): array
    {
        return $this->guarded($context, 'role.assigned', $userId, function () use ($actor, $userId, $role, $scopeType, $projectId, $reason, $context): array {
            if ($this->access->role($role) === null) {
                throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('role', 'not_allowed', 'Unknown role.')]);
            }
            if ($role === PermissionResolver::TECHNICAL_MANAGER) {
                throw new ApiException(ErrorCode::OwnerAuthorityRequired, 'Only a System Owner can grant or revoke the Technical Manager role.');
            }
            if ($userId === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own access.');
            }
            $this->checkScope($scopeType, $projectId);

            return $this->db->transaction(function () use ($actor, $userId, $role, $scopeType, $projectId, $reason, $context): array {
                $this->existingUser($userId, true);
                if ($this->access->holdsActiveRole($userId, PermissionResolver::TECHNICAL_MANAGER)) {
                    throw new ApiException(ErrorCode::UseOperationalException);
                }
                if ($this->access->activeRoleCount($userId) > 0) {
                    throw new ApiException(ErrorCode::CombinationNotAllowed, 'This account already holds a role. One role per account applies while multiple roles are undecided (R2/R3).');
                }

                $id = $this->access->insertRole($userId, $role, $scopeType, $projectId, $reason, $actor->userId, null, $this->now());
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'role.assigned', 'user_role', $id, ['user_id' => $userId, 'role' => $role]);

                return $this->assignment($userId, $id);
            });
        });
    }

    public function removeRole(Principal $actor, int $userId, int $assignmentId, string $reason, AuditContext $context): void
    {
        $this->guarded($context, 'role.removed', $userId, function () use ($actor, $userId, $assignmentId, $reason, $context): void {
            $this->db->transaction(function () use ($actor, $userId, $assignmentId, $reason, $context): void {
                $assignment = $this->access->roleAssignment($assignmentId, true);
                if ($assignment === null || Row::int($assignment, 'user_id') !== $userId) {
                    throw new ApiException(ErrorCode::NotFound);
                }
                if (Row::string($assignment, 'role_code') === PermissionResolver::TECHNICAL_MANAGER) {
                    throw new ApiException(ErrorCode::OwnerAuthorityRequired, 'Only a System Owner can grant or revoke the Technical Manager role.');
                }
                if ($userId === $actor->userId) {
                    throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own access.');
                }
                if ($assignment['revoked_at'] !== null) {
                    throw new ApiException(ErrorCode::InvalidTransition, 'This role has already been removed.');
                }

                $this->access->revokeRole($assignmentId, $actor->userId, $reason, null, $this->now());
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'role.removed', 'user_role', $assignmentId, ['user_id' => $userId, 'role' => Row::string($assignment, 'role_code')]);
            });
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grants(int $userId): array
    {
        $this->existingUser($userId);

        return array_map(Views::grant(...), $this->access->grants($userId));
    }

    /**
     * @return array<string, mixed>
     */
    public function createGrant(Principal $actor, int $userId, string $code, string $scopeType, ?int $projectId, string $reason, ?\DateTimeImmutable $expiresAt, AuditContext $context): array
    {
        return $this->guarded($context, 'permission.granted', $userId, function () use ($actor, $userId, $code, $scopeType, $projectId, $reason, $expiresAt, $context): array {
            $permission = $this->access->permission($code)
                ?? throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('permission', 'not_allowed', 'Unknown permission code.')]);
            if (Row::string($permission, 'category') === 'governance' || Row::int($permission, 'grantable') !== 1) {
                throw new ApiException(ErrorCode::OwnerAuthorityRequired, 'Governance permissions come only from System Owner membership.');
            }
            if ($userId === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own access.');
            }
            $this->checkScope($scopeType, $projectId);
            if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
                throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('expires_at', 'past', 'Must be in the future.')]);
            }

            return $this->db->transaction(function () use ($actor, $userId, $code, $permission, $scopeType, $projectId, $reason, $expiresAt, $context): array {
                $this->existingUser($userId, true);
                if (Row::string($permission, 'category') === 'operational' && $this->access->holdsActiveRole($userId, PermissionResolver::TECHNICAL_MANAGER)) {
                    throw new ApiException(ErrorCode::UseOperationalException);
                }
                if ($this->access->activeGrantKeyExists($userId, $code, $scopeType, $projectId)) {
                    throw new ApiException(ErrorCode::Duplicate, 'This grant already exists.');
                }

                $id = $this->access->insertGrant($userId, $code, $scopeType, $projectId, $reason, $expiresAt === null ? null : Database::timestamp($expiresAt), $actor->userId, $this->now());
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'permission.granted', 'user_permission_grant', $id, ['user_id' => $userId, 'permission' => $code]);

                $grant = array_values(array_filter($this->access->grants($userId), static fn(array $g): bool => Row::int($g, 'id') === $id));

                return Views::grant($grant[0]);
            });
        });
    }

    public function revokeGrant(Principal $actor, int $userId, int $grantId, string $reason, AuditContext $context): void
    {
        $this->guarded($context, 'permission.revoked', $userId, function () use ($actor, $userId, $grantId, $reason, $context): void {
            if ($userId === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own access.');
            }

            $this->db->transaction(function () use ($actor, $userId, $grantId, $reason, $context): void {
                $grant = $this->access->grant($grantId, true);
                if ($grant === null || Row::int($grant, 'user_id') !== $userId) {
                    throw new ApiException(ErrorCode::NotFound);
                }
                if ($grant['revoked_at'] !== null) {
                    throw new ApiException(ErrorCode::InvalidTransition, 'This grant has already been revoked.');
                }

                $this->access->revokeGrant($grantId, $actor->userId, $reason, $this->now());
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'permission.revoked', 'user_permission_grant', $grantId, ['user_id' => $userId, 'permission' => Row::string($grant, 'permission_code')]);
            });
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sessions(?int $userId): array
    {
        return array_map(static fn(array $s): array => [
            'id' => Row::string($s, 'id_hash'),
            'user_id' => Row::int($s, 'user_id'),
            'display_name' => Row::string($s, 'display_name'),
            'created_at' => Row::isoOrNull($s, 'created_at'),
            'last_seen_at' => Row::isoOrNull($s, 'last_seen_at'),
            'ip_prefix' => Row::stringOrNull($s, 'ip_prefix'),
            'agent' => Row::stringOrNull($s, 'agent_summary'),
        ], $this->access->activeSessions($userId, $this->now()));
    }

    public function revokeSession(string $id, AuditContext $context): void
    {
        $this->db->transaction(function () use ($id, $context): void {
            $session = $this->access->session($id);
            if ($session === null || $session['revoked_at'] !== null || !$this->sessions->revoke($id)) {
                throw new ApiException(ErrorCode::NotFound);
            }
            $this->audit->success($context, 'session.revoked', 'user', Row::int($session, 'user_id'));
        });
    }

    public function revokeAllSessions(int $userId, AuditContext $context): void
    {
        $this->db->transaction(function () use ($userId, $context): void {
            $this->existingUser($userId, true);
            $this->sessions->revokeAllForUser($userId);
            $this->audit->success($context, 'session.revoked', 'user', $userId, ['all' => 1]);
        });
    }

    /**
     * Runs $work; a refusal by a guard (403, or the G5/G10 422s) is also audited as denied, outside
     * the rolled-back transaction (WF-18).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guarded(AuditContext $context, string $event, int $userId, callable $work): mixed
    {
        try {
            return $work();
        } catch (ApiException $e) {
            if ($e->httpStatus() === 403 || \in_array($e->errorCode, [ErrorCode::UseOperationalException, ErrorCode::CombinationNotAllowed], true)) {
                $this->audit->denied($context, $event, 'user', $userId, ['reason' => $e->errorCode->value]);
            }

            throw $e;
        }
    }

    private function checkScope(string $scopeType, ?int $projectId): void
    {
        if (($scopeType === 'project') !== ($projectId !== null)) {
            throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('scope', 'invalid', 'A project scope needs project_id; an organisation scope must not have one.')]);
        }
        if ($projectId !== null && !$this->access->projectExists($projectId)) {
            throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('scope.project_id', 'not_found', 'Unknown project.')]);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function existingUser(int $userId, bool $forUpdate = false): array
    {
        return $this->accounts->find($userId, $forUpdate) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @return array<string, mixed>
     */
    private function assignment(int $userId, int $id): array
    {
        foreach ($this->access->roleAssignments($userId) as $row) {
            if (Row::int($row, 'id') === $id) {
                return Views::roleAssignment($row);
            }
        }

        throw new ApiException(ErrorCode::NotFound);
    }

    private function now(): string
    {
        return Database::timestamp($this->clock->now());
    }
}
