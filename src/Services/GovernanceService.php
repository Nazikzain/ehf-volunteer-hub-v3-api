<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Auth\PasswordHasher;
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
use Ehf\Api\Repositories\GovernanceRepository;

/**
 * System Owner governance (API §7.17, §6.8–6.10).
 *
 * - Only an active System Owner grants or revokes `technical_manager` (AR-13, G4), never for
 *   themselves (G3), through a single-use owner authorisation for the same user (enforced again
 *   by the database). One role per account applies (G10, interim).
 * - R7-C is pending: an owner who also holds `technical_manager` is refused on both endpoints
 *   (API §6.10 interim).
 * - Owner and exception actions require password re-entry (`password_confirmation` in the
 *   approved request; RS4 pending — the stricter reading is applied).
 * - Exceptions: requested by owners only (R8 interim), never by a Technical Manager [A]; one exact
 *   eligible operational code (R19 interim: none eligible, so every request is refused); the
 *   recipient never authorises; effective only while authorised and unexpired.
 * - Adding owners needs R7's eligibility rule and is not available; removal keeps a final owner (G7).
 */
final readonly class GovernanceService
{
    public function __construct(
        private Database $db,
        private GovernanceRepository $governance,
        private AccessRepository $access,
        private AccountRepository $accounts,
        private SessionService $sessions,
        private FinalOwnerGuard $finalOwner,
        private PasswordHasher $hasher,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function owners(): array
    {
        return array_map(static fn(array $o): array => [
            'user_id' => Row::int($o, 'user_id'),
            'display_name' => Row::string($o, 'display_name'),
            'email' => Row::string($o, 'email'),
            'account_status' => Row::string($o, 'status'),
            'added_via' => Row::string($o, 'added_via'),
            'added_by' => Row::intOrNull($o, 'added_by_user_id'),
            'added_at' => Row::isoOrNull($o, 'added_at'),
        ], $this->governance->activeOwners());
    }

    public function removeOwner(Principal $actor, int $userId, string $reason, string $password, AuditContext $context): void
    {
        $this->guarded($context, 'owner.removed', $userId, function () use ($actor, $userId, $reason, $password, $context): void {
            if ($userId === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own owner status.');
            }
            $this->confirmPassword($actor, $password);

            $this->db->transaction(function () use ($actor, $userId, $reason, $context): void {
                $this->finalOwner->assertSurvives($userId);
                $membership = $this->governance->activeMembershipId($userId) ?? throw new ApiException(ErrorCode::NotFound);
                $this->governance->removeMembership($membership, $this->membership($actor), $actor->userId, $reason, $this->now());
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'owner.removed', 'user', $userId);
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function grantTechnicalManager(Principal $actor, int $userId, string $reason, string $password, AuditContext $context): array
    {
        return $this->guarded($context, 'role.technical_manager.granted', $userId, function () use ($actor, $userId, $reason, $password, $context): array {
            $this->refuseOwnerWhoIsTechnicalManager($actor);
            if ($userId === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own access.');
            }
            $this->confirmPassword($actor, $password);

            return $this->db->transaction(function () use ($actor, $userId, $reason, $context): array {
                $user = $this->accounts->find($userId, true) ?? throw new ApiException(ErrorCode::NotFound);
                if (Row::string($user, 'status') === 'deactivated') {
                    throw new ApiException(ErrorCode::InvalidTransition, 'The account is deactivated.');
                }
                if ($this->access->activeRoleCount($userId) > 0) {
                    throw new ApiException(ErrorCode::CombinationNotAllowed, 'This account already holds a role. One role per account applies while multiple roles are undecided (R2/R3).');
                }

                $now = $this->now();
                $authorisation = $this->governance->insertOwnerAuthorisation($this->membership($actor), $actor->userId, $userId, 'grant_technical_manager', $reason, $now);
                $id = $this->access->insertRole($userId, PermissionResolver::TECHNICAL_MANAGER, 'organisation', null, $reason, $actor->userId, $authorisation, $now);
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'role.technical_manager.granted', 'user_role', $id, ['user_id' => $userId, 'owner_authorisation_id' => $authorisation]);

                foreach ($this->access->roleAssignments($userId) as $row) {
                    if (Row::int($row, 'id') === $id) {
                        return Views::roleAssignment($row);
                    }
                }

                throw new \LogicException('The new assignment was not found.');
            });
        });
    }

    public function revokeTechnicalManager(Principal $actor, int $assignmentId, string $reason, string $password, AuditContext $context): void
    {
        $assignment = $this->access->roleAssignment($assignmentId);
        if ($assignment === null || Row::string($assignment, 'role_code') !== PermissionResolver::TECHNICAL_MANAGER) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $userId = Row::int($assignment, 'user_id');

        $this->guarded($context, 'role.technical_manager.revoked', $userId, function () use ($actor, $assignmentId, $userId, $reason, $password, $context): void {
            $this->refuseOwnerWhoIsTechnicalManager($actor);
            if ($userId === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot change your own access.');
            }
            $this->confirmPassword($actor, $password);

            $this->db->transaction(function () use ($actor, $assignmentId, $userId, $reason, $context): void {
                $current = $this->access->roleAssignment($assignmentId, true);
                if ($current === null || $current['revoked_at'] !== null) {
                    throw new ApiException(ErrorCode::InvalidTransition, 'This role has already been revoked.');
                }

                $now = $this->now();
                $authorisation = $this->governance->insertOwnerAuthorisation($this->membership($actor), $actor->userId, $userId, 'revoke_technical_manager', $reason, $now);
                $this->access->revokeRole($assignmentId, $actor->userId, $reason, $authorisation, $now);
                $this->sessions->revokeAllForUser($userId);
                $this->audit->success($context, 'role.technical_manager.revoked', 'user_role', $assignmentId, ['user_id' => $userId, 'owner_authorisation_id' => $authorisation]);
            });
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exceptions(?string $status, ?int $userId): array
    {
        return array_map(Views::exception(...), $this->governance->exceptions($status, $userId));
    }

    /**
     * @return array<string, mixed>
     */
    public function createException(Principal $actor, int $recipient, string $code, string $scopeType, ?int $projectId, string $reason, ?\DateTimeImmutable $expiresAt, AuditContext $context): array
    {
        return $this->guarded($context, 'exception.created', $recipient, function () use ($actor, $recipient, $code, $scopeType, $projectId, $reason, $expiresAt, $context): array {
            if ($actor->holdsRole(PermissionResolver::TECHNICAL_MANAGER)) {
                throw new ApiException(ErrorCode::Forbidden, 'A Technical Manager cannot request an operational exception.');
            }
            if ($recipient === $actor->userId) {
                throw new ApiException(ErrorCode::SelfGrantForbidden, 'You cannot request an exception for yourself.');
            }
            $this->assertEligible($code);
            if (($scopeType === 'project') !== ($projectId !== null) || ($projectId !== null && !$this->access->projectExists($projectId))) {
                throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('scope', 'invalid', 'A project scope needs an existing project_id; an organisation scope must not have one.')]);
            }
            if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
                throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('expires_at', 'past', 'Must be in the future.')]);
            }

            return $this->db->transaction(function () use ($actor, $recipient, $code, $scopeType, $projectId, $reason, $expiresAt, $context): array {
                if (!$this->access->holdsActiveRole($recipient, PermissionResolver::TECHNICAL_MANAGER)) {
                    throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('recipient_user_id', 'not_technical_manager', 'The recipient must hold the Technical Manager role.')]);
                }

                $id = $this->governance->insertException($recipient, $code, $scopeType, $projectId, $reason, $expiresAt === null ? null : Database::timestamp($expiresAt), $actor->userId, $this->now());
                $this->audit->success($context, 'exception.created', 'operational_exception', $id, ['recipient_user_id' => $recipient, 'permission' => $code]);

                return Views::exception($this->governance->exception($id) ?? throw new \LogicException('Missing exception.'));
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function authoriseException(Principal $actor, int $id, int $version, string $password, AuditContext $context): array
    {
        return $this->decide($actor, $id, 'exception.authorised', $context, function (array $exception) use ($actor, $id, $version, $password): void {
            if (Row::int($exception, 'recipient_user_id') === $actor->userId) {
                throw new ApiException(ErrorCode::SelfDecisionForbidden, 'You cannot authorise an exception for yourself.');
            }
            $this->confirmPassword($actor, $password);
            $this->assertEligible(Row::string($exception, 'permission_code'));
            $this->assertState($exception, 'pending', $version);
            $this->governance->authoriseException($id, $version, $this->membership($actor), $actor->userId, $this->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectException(Principal $actor, int $id, int $version, string $reason, AuditContext $context): array
    {
        return $this->decide($actor, $id, 'exception.rejected', $context, function (array $exception) use ($actor, $id, $version, $reason): void {
            $this->assertState($exception, 'pending', $version);
            $this->governance->rejectException($id, $version, $this->membership($actor), $actor->userId, $reason, $this->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeException(Principal $actor, int $id, int $version, string $reason, AuditContext $context): array
    {
        return $this->decide($actor, $id, 'exception.revoked', $context, function (array $exception) use ($actor, $id, $version, $reason): void {
            $this->assertState($exception, 'authorised', $version);
            $this->governance->revokeException($id, $version, $this->membership($actor), $actor->userId, $reason, $this->now());
        });
    }

    /**
     * @param callable(array<string, scalar|null>): void $change
     *
     * @return array<string, mixed>
     */
    private function decide(Principal $actor, int $id, string $event, AuditContext $context, callable $change): array
    {
        $exception = $this->governance->exception($id) ?? throw new ApiException(ErrorCode::NotFound);
        $recipient = Row::int($exception, 'recipient_user_id');

        return $this->guarded($context, $event, $recipient, function () use ($id, $event, $context, $change, $recipient): array {
            return $this->db->transaction(function () use ($id, $event, $context, $change, $recipient): array {
                $current = $this->governance->exception($id, true) ?? throw new ApiException(ErrorCode::NotFound);
                $change($current);
                // Exceptions change effective permissions: end the recipient's sessions (API §5).
                $this->sessions->revokeAllForUser($recipient);
                $this->audit->success($context, $event, 'operational_exception', $id, ['recipient_user_id' => $recipient]);

                return Views::exception($this->governance->exception($id) ?? throw new \LogicException('Missing exception.'));
            });
        });
    }

    /**
     * @param array<string, scalar|null> $exception
     */
    private function assertState(array $exception, string $status, int $version): void
    {
        if (Row::string($exception, 'status') !== $status) {
            throw new ApiException(ErrorCode::InvalidTransition);
        }
        if (Row::int($exception, 'version') !== $version) {
            throw new ApiException(ErrorCode::StaleVersion);
        }
    }

    private function assertEligible(string $code): void
    {
        $permission = $this->access->permission($code);
        if ($permission === null || Row::string($permission, 'category') !== 'operational' || Row::int($permission, 'exception_eligible') !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('permission', 'not_eligible', 'This permission is not eligible for an operational exception (R19: no code is eligible yet).')]);
        }
    }

    private function refuseOwnerWhoIsTechnicalManager(Principal $actor): void
    {
        if ($actor->holdsRole(PermissionResolver::TECHNICAL_MANAGER)) {
            throw new ApiException(ErrorCode::Forbidden, 'A System Owner who also holds the Technical Manager role cannot grant or revoke that role while decision R7-C is pending.');
        }
    }

    private function confirmPassword(Principal $actor, string $password): void
    {
        $user = $this->accounts->find($actor->userId);
        $hash = $user === null ? null : Row::stringOrNull($user, 'password_hash');
        if ($password === '' || $hash === null || !$this->hasher->verify($password, $hash)) {
            throw new ApiException(ErrorCode::PasswordConfirmationRequired);
        }
    }

    private function membership(Principal $actor): int
    {
        return $this->governance->activeMembershipId($actor->userId) ?? throw new ApiException(ErrorCode::OwnerAuthorityRequired);
    }

    /**
     * Audits a refused governance action outside the rolled-back transaction (WF-18).
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
            if ($e->httpStatus() === 403 || \in_array($e->errorCode, [ErrorCode::CombinationNotAllowed, ErrorCode::ValidationFailed], true)) {
                $this->audit->denied($context, $event, 'user', $userId, ['reason' => $e->errorCode->value]);
            }

            throw $e;
        }
    }

    private function now(): string
    {
        return Database::timestamp($this->clock->now());
    }
}
