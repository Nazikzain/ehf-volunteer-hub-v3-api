<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Auth\SessionService;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;
use Ehf\Api\Repositories\AccountRepository;

/**
 * Account administration for Technical Managers (API §7.2; Workflow V1.4 WF-01, WF-16).
 *
 * Status transitions (Workflow §3.1, approved with REC): active ↔ suspended;
 * invited, active, suspended → deactivated; deactivated accounts are not reactivated.
 * Suspension and deactivation end every session and are refused when they would leave no System
 * Owner (G7). A Technical Manager may not suspend or deactivate their own account (WF-16, REC).
 */
final readonly class AccountService
{
    public function __construct(
        private Database $db,
        private AccountRepository $accounts,
        private SessionService $sessions,
        private FinalOwnerGuard $finalOwner,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(?string $query, ?string $status, ?string $role, int $page, int $perPage): array
    {
        $items = array_map(
            fn(array $user): array => $this->view($user),
            $this->accounts->search($query, $status, $role, $perPage, ($page - 1) * $perPage),
        );

        return ['items' => $items, 'total' => $this->accounts->count($query, $status, $role)];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        return $this->view($this->existing($id));
    }

    /**
     * Creates a staff account in the invited state. No invitation is sent: sending needs email (A11)
     * and token lifetimes (A5), both pending.
     *
     * @return array<string, mixed>
     */
    public function create(string $email, string $displayName, AuditContext $context, Principal $actor): array
    {
        return $this->db->transaction(function () use ($email, $displayName, $context, $actor): array {
            if ($this->accounts->emailExists($email)) {
                throw new ApiException(ErrorCode::Duplicate, 'An account with this email address already exists.', [new ErrorDetail('email', 'duplicate', 'Already in use.')]);
            }
            $id = $this->accounts->create($email, $displayName, $actor->userId);
            $this->audit->success($context, 'account.created', 'user', $id);

            return $this->get($id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function update(int $id, ?string $displayName, bool $emailSent, int $version, AuditContext $context, Principal $actor): array
    {
        if ($emailSent) {
            // Changing a sign-in email needs the re-verification flow, which is not approved (API §7.2 [R]).
            throw new ApiException(ErrorCode::FieldNotEditable, details: [new ErrorDetail('email', 'field_not_editable', 'The sign-in email cannot be changed yet.')]);
        }

        return $this->db->transaction(function () use ($id, $displayName, $version, $context, $actor): array {
            $user = $this->existing($id, true);
            if ($displayName !== null && $displayName !== Row::string($user, 'display_name')) {
                $this->assertVersion($user, $version);
                $this->accounts->updateDisplayName($id, $displayName, $version, $actor->userId, $this->now());
                $this->audit->success($context, 'account.updated', 'user', $id, ['fields' => ['display_name']]);
            }

            return $this->get($id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function suspend(int $id, string $reason, int $version, AuditContext $context, Principal $actor): array
    {
        return $this->changeStatus($id, ['active'], 'suspended', $reason, $version, 'account.suspended', $context, $actor, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(int $id, ?string $reason, int $version, AuditContext $context, Principal $actor): array
    {
        return $this->changeStatus($id, ['suspended'], 'active', $reason, $version, 'account.reactivated', $context, $actor, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(int $id, string $reason, int $version, AuditContext $context, Principal $actor): array
    {
        return $this->changeStatus($id, ['invited', 'active', 'suspended'], 'deactivated', $reason, $version, 'account.deactivated', $context, $actor, true);
    }

    public function cancelInvitation(int $id, AuditContext $context): void
    {
        $this->db->transaction(function () use ($id, $context): void {
            $user = $this->existing($id, true);
            if (Row::string($user, 'status') !== 'invited') {
                throw new ApiException(ErrorCode::InvalidTransition, 'Only invitations to invited accounts can be cancelled.');
            }
            $this->accounts->revokeTokens($id, 'invitation', $this->now());
            $this->audit->success($context, 'invitation.cancelled', 'user', $id);
        });
    }

    /**
     * @param list<string> $from
     *
     * @return array<string, mixed>
     */
    private function changeStatus(int $id, array $from, string $to, ?string $reason, int $version, string $event, AuditContext $context, Principal $actor, bool $endsAccess): array
    {
        if ($endsAccess && $id === $actor->userId) {
            $this->audit->denied($context, $event, 'user', $id);

            throw new ApiException(ErrorCode::Forbidden, 'You cannot suspend or deactivate your own account.');
        }

        try {
            return $this->db->transaction(function () use ($id, $from, $to, $reason, $version, $event, $context, $actor, $endsAccess): array {
                $user = $this->existing($id, true);
                if (!\in_array(Row::string($user, 'status'), $from, true)) {
                    throw new ApiException(ErrorCode::InvalidTransition);
                }
                $this->assertVersion($user, $version);
                if ($endsAccess) {
                    $this->finalOwner->assertSurvives($id);
                }

                $this->accounts->setStatus($id, $to, $reason, $version, $actor->userId, $this->now());
                if ($endsAccess) {
                    $this->sessions->revokeAllForUser($id);
                    $this->accounts->revokeTokens($id, 'invitation', $this->now());
                    $this->accounts->revokeTokens($id, 'password_reset', $this->now());
                }
                $this->audit->success($context, $event, 'user', $id);

                return $this->get($id);
            });
        } catch (ApiException $e) {
            if ($e->errorCode === ErrorCode::FinalOwnerProtected) {
                $this->audit->denied($context, $event, 'user', $id, ['reason' => 'final_owner_protected']);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function existing(int $id, bool $forUpdate = false): array
    {
        return $this->accounts->find($id, $forUpdate) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @param array<string, scalar|null> $user
     */
    private function assertVersion(array $user, int $version): void
    {
        if (Row::int($user, 'version') !== $version) {
            throw new ApiException(ErrorCode::StaleVersion);
        }
    }

    /**
     * @param array<string, scalar|null> $user
     *
     * @return array<string, mixed>
     */
    private function view(array $user): array
    {
        $id = Row::int($user, 'id');

        return Views::account($user, $this->accounts->activeRoles($id), $this->accounts->invitationStatus($id, $this->now()), $this->accounts->volunteerReference($id));
    }

    private function now(): string
    {
        return Database::timestamp($this->clock->now());
    }
}
