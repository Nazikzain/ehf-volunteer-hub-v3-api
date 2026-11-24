<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Repositories\AccountRepository;
use Ehf\Api\Repositories\VolunteerRepository;

/**
 * Portal account provisioning for Technical Managers (API §7.2; Workflow V1.4 WF-01).
 *
 * - Candidates (W1): volunteers that are accepted or active and have no linked account. Minimum
 *   data only: volunteer id, reference, name and `invitation_email`.
 * - AC-6 Option 1: `invitation_email` is the volunteer's contact email when one is recorded, else
 *   null. R18: it is shown in full, only here, only to holders of accounts.provision.
 * - Provisioning creates an `invited` account with the given (or recorded) address as its sign-in
 *   email and links it to the volunteer. The volunteer's data and status are not changed, and the
 *   address is never written back to the volunteer record.
 * - The volunteer role is assigned separately with POST /users/{id}/roles (AC-2 pending).
 * - Sending the invitation needs token lifetimes (A5) and email delivery (A11), both pending:
 *   `send_invitation: true` is refused with `503 maintenance` and nothing is created.
 */
final readonly class ProvisioningService
{
    /** W1: statuses eligible for a portal account. */
    public const array ELIGIBLE = ['accepted', 'active'];

    public function __construct(
        private Database $db,
        private VolunteerRepository $volunteers,
        private AccountRepository $accounts,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function candidates(?string $query, int $page, int $perPage): array
    {
        $items = array_map(static fn(array $row): array => [
            'volunteer_id' => Row::int($row, 'id'),
            'volunteer_reference' => Row::string($row, 'reference'),
            'name' => Row::string($row, 'full_name'),
            'invitation_email' => Row::stringOrNull($row, 'email'),
        ], $this->volunteers->candidates(self::ELIGIBLE, $query, $perPage, ($page - 1) * $perPage));

        return ['items' => $items, 'total' => $this->volunteers->countCandidates(self::ELIGIBLE, $query)];
    }

    /**
     * @return array{user: array{id: int, email: string, status: string}, invitation: null}
     */
    public function provision(Principal $actor, int $volunteerId, ?string $invitationEmail, bool $sendInvitation, AuditContext $context): array
    {
        if ($sendInvitation) {
            throw new ApiException(ErrorCode::Maintenance, 'Sending invitations is not available yet. Provision with send_invitation set to false.', [
                new ErrorDetail('send_invitation', 'not_available', 'Invitation emails need decisions A5 and A11.'),
            ]);
        }

        return $this->db->transaction(function () use ($actor, $volunteerId, $invitationEmail, $context): array {
            $volunteer = $this->volunteers->find($volunteerId, true) ?? throw new ApiException(ErrorCode::NotFound);
            if (!\in_array(Row::string($volunteer, 'status'), self::ELIGIBLE, true)) {
                throw new ApiException(ErrorCode::NotEligibleForProvisioning);
            }
            if (Row::intOrNull($volunteer, 'user_id') !== null) {
                throw new ApiException(ErrorCode::AccountExists);
            }

            $recorded = Row::stringOrNull($volunteer, 'email');
            $email = $invitationEmail ?? $recorded ?? throw new ApiException(ErrorCode::ValidationFailed, details: [
                new ErrorDetail('invitation_email', 'required', 'No email address is recorded for this volunteer: supply invitation_email.'),
            ]);
            if ($this->accounts->emailExists($email)) {
                throw new ApiException(ErrorCode::Duplicate, 'An account with this email address already exists.', [new ErrorDetail('invitation_email', 'duplicate', 'Already in use.')]);
            }

            $userId = $this->accounts->create($email, mb_substr(Row::string($volunteer, 'full_name'), 0, 120), $actor->userId);
            $this->volunteers->linkAccount($volunteerId, $userId);
            $this->audit->success($context, 'account.provisioned', 'user', $userId, [
                'volunteer_id' => $volunteerId,
                'email_source' => $invitationEmail === null ? 'volunteer_record' : 'supplied',
                'invitation_sent' => false,
            ]);

            return ['user' => ['id' => $userId, 'email' => mb_strtolower($email), 'status' => 'invited'], 'invitation' => null];
        });
    }
}
