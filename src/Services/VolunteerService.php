<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Authorisation\FieldPolicy;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;
use Ehf\Api\Repositories\VolunteerRepository;

/**
 * Volunteer operational records (API §7.4), the volunteer's own profile (API §7.3) and the
 * volunteer part of a decision context (API §6.6 level 1). Workflow V1.4 WF-03 with W1 approved:
 *
 * - Statuses (Workflow §3.2): draft → accepted (separate acceptance event) → active ↔ inactive;
 *   accepted, active, inactive → ended; draft → withdrawn. Ended and withdrawn are final.
 * - Required: full name, and an email address or a phone number.
 * - Duplicate: the same contact email, ignoring case → `409 duplicate`.
 * - Ending or withdrawing never changes a linked account (no account endpoint or volunteer
 *   endpoint writes the other side); and account activation never changes the volunteer status.
 * - REC (Workflow WF-03 SoD): a user whose own account is linked to the record may not accept it or
 *   change its status.
 *
 * Field access comes from Authorisation\FieldPolicy (field catalogue, R6, R12, R13). A field the
 * caller may not read is left out of the response; sending one they may not write is refused
 * with `403 field_not_permitted`. Notes are never part of a volunteer record or profile.
 */
final readonly class VolunteerService
{
    /** Workflow §3.2 transitions through POST /volunteers/{id}/status-changes. */
    public const array TRANSITIONS = [
        'draft' => ['withdrawn'],
        'accepted' => ['active', 'ended'],
        'active' => ['inactive', 'ended'],
        'inactive' => ['active', 'ended'],
    ];

    public const array STATUSES = ['draft', 'accepted', 'active', 'inactive', 'ended', 'withdrawn'];

    public function __construct(
        private Database $db,
        private VolunteerRepository $volunteers,
        private FieldPolicy $fields,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int, field_sets: list<string>}
     */
    public function search(Principal $principal, ?string $query, ?string $status, ?int $projectId, string $sort, int $page, int $perPage): array
    {
        $searchEmail = $this->fields->canRead($principal, 'email');
        $items = [];
        $sets = ['basic'];
        foreach ($this->volunteers->search($query, $searchEmail, $status, $projectId, $sort, $perPage, ($page - 1) * $perPage) as $row) {
            [$item, $itemSets] = $this->record($principal, $row);
            $items[] = $item;
            $sets = array_merge($sets, $itemSets);
        }

        return ['items' => $items, 'total' => $this->volunteers->count($query, $searchEmail, $status, $projectId), 'field_sets' => self::sets($sets)];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>} record and field sets
     */
    public function get(Principal $principal, int $id): array
    {
        return $this->record($principal, $this->existing($id));
    }

    /**
     * @param list<string>         $sent   volunteer fields present in the request
     * @param array<string, mixed> $values validated values (VolunteerInput)
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function create(Principal $principal, array $sent, array $values, AuditContext $context): array
    {
        $id = $this->db->transaction(function () use ($principal, $values, $context): int {
            self::assertContactable($values['email'] ?? null, $values['phone'] ?? null);
            $this->assertNoDuplicate($values['email'] ?? null, null);
            [$main, $sensitive, $days] = self::split($values);

            $now = $this->now();
            $id = $this->insertWithReference($main, $principal->userId);
            $this->volunteers->saveSensitive($id, $sensitive, $principal->userId, $now);
            if ($days !== null) {
                $this->volunteers->replaceDays($id, $days);
            }
            $this->volunteers->recordStatus($id, null, 'draft', null, $principal->userId, $now);
            $this->audit->success($context, 'volunteer.created', 'volunteer', $id, ['fields' => self::fieldNames($values)]);

            return $id;
        });

        return $this->get($principal, $id);
    }

    /**
     * Refuses fields the caller may not write (`403 field_not_permitted`, audited as refused).
     *
     * @param list<string> $sent
     */
    public function assertWritable(Principal $principal, array $sent, string $event, ?int $volunteerId, AuditContext $context): void
    {
        $refused = array_values(array_filter($sent, fn(string $field): bool => !$this->fields->canWrite($principal, $field)));
        if ($refused === []) {
            return;
        }
        $this->audit->denied($context, $event, 'volunteer', $volunteerId, ['reason' => 'field_not_permitted', 'fields' => $refused]);

        throw new ApiException(ErrorCode::FieldNotPermitted, details: array_map(static fn(string $f): ErrorDetail => new ErrorDetail($f, 'field_not_permitted', 'You may not change this field.'), $refused));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function update(Principal $principal, int $id, array $values, int $version, AuditContext $context): array
    {
        $this->db->transaction(function () use ($principal, $id, $values, $version, $context): void {
            $this->applyChanges($id, $values, $version, $principal->userId, $context, 'volunteer.updated');
        });

        return $this->get($principal, $id);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function accept(Principal $principal, int $id, ?string $note, int $version, AuditContext $context): array
    {
        $this->transition($principal, $id, 'accepted', $note, $version, $context, 'volunteer.accepted', ['draft']);

        return $this->get($principal, $id);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function changeStatus(Principal $principal, int $id, string $to, string $reason, int $version, AuditContext $context): array
    {
        $from = array_keys(array_filter(self::TRANSITIONS, static fn(array $targets): bool => \in_array($to, $targets, true)));
        $this->transition($principal, $id, $to, $reason, $version, $context, 'volunteer.status_changed', $from);

        return $this->get($principal, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $id): array
    {
        $this->existing($id);

        return array_map(static fn(array $row): array => [
            'from' => Row::stringOrNull($row, 'from_status'),
            'to' => Row::string($row, 'to_status'),
            'by' => Row::int($row, 'changed_by_user_id'),
            'at' => Row::isoOrNull($row, 'changed_at'),
            'reason' => Row::stringOrNull($row, 'reason'),
        ], $this->volunteers->history($id));
    }

    /**
     * Confidential notes (R6). Reading is audited (`note.viewed`, REC).
     *
     * @return list<array<string, mixed>>
     */
    public function notes(int $id, AuditContext $context): array
    {
        $this->existing($id);
        $notes = array_map(self::note(...), $this->volunteers->notes($id));
        $this->audit->success($context, 'note.viewed', 'volunteer', $id, ['count' => \count($notes)]);

        return $notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function addNote(Principal $principal, int $id, string $body, AuditContext $context): array
    {
        return $this->db->transaction(function () use ($principal, $id, $body, $context): array {
            $this->existing($id, true);
            $note = $this->volunteers->addNote($id, $body, $principal->userId, $this->now());
            $this->audit->success($context, 'note.created', 'volunteer', $id, ['note_id' => Row::int($note, 'id')]);

            return self::note($note);
        });
    }

    /**
     * The volunteer's own record (API §7.3): every own field including sensitive ones, never notes.
     *
     * @return array<string, mixed>
     */
    public function ownProfile(Principal $principal): array
    {
        $id = $principal->volunteerId ?? throw new ApiException(ErrorCode::NotFound);
        $row = $this->existing($id);

        return self::shape($row, $this->volunteers->sensitive($id), $this->volunteers->days($id), static fn(): bool => true)
            + ['editable_fields' => FieldPolicy::SELF_EDITABLE];
    }

    /**
     * R12: only FieldPolicy::SELF_EDITABLE may change (`422 field_not_editable` otherwise).
     *
     * @param list<string>         $sent
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function updateOwnProfile(Principal $principal, array $sent, array $values, int $version, AuditContext $context): array
    {
        $this->assertSelfEditable($principal, $sent);
        $id = $principal->volunteerId;
        \assert($id !== null);

        $this->db->transaction(function () use ($principal, $id, $values, $version, $context): void {
            $this->applyChanges($id, $values, $version, $principal->userId, $context, 'volunteer.self_updated');
        });

        return $this->ownProfile($principal);
    }

    /**
     * The caller has a volunteer record (else `404`) and every sent field is self-editable under R12
     * (else `422 field_not_editable`).
     *
     * @param list<string> $sent
     */
    public function assertSelfEditable(Principal $principal, array $sent): void
    {
        if ($principal->volunteerId === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $refused = array_values(array_diff($sent, FieldPolicy::SELF_EDITABLE));
        if ($refused !== []) {
            throw new ApiException(ErrorCode::FieldNotEditable, details: array_map(static fn(string $f): ErrorDetail => new ErrorDetail($f, 'field_not_editable', 'You cannot change this field.'), $refused));
        }
    }

    /**
     * The volunteer part of a decision context (API §6.6 level 1, §8): basic fields, plus the contact
     * fields the decider may read there — which includes Monitoring Admin's R13 contact codes.
     * Used by the decision endpoints of later phases (hours, task reports, reviews).
     *
     * @return array<string, mixed>
     */
    public function decisionContext(Principal $principal, int $id): array
    {
        $row = $this->existing($id);
        $context = ['id' => $id, 'reference' => Row::string($row, 'reference'), 'name' => Row::string($row, 'full_name'), 'status' => Row::string($row, 'status')];
        foreach (['email', 'phone'] as $field) {
            if ($this->fields->canReadInDecisionContext($principal, $field)) {
                $context[$field] = Row::stringOrNull($row, $field);
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function applyChanges(int $id, array $values, int $version, int $userId, AuditContext $context, string $event): void
    {
        $row = $this->existing($id, true);
        if (Row::int($row, 'version') !== $version) {
            throw new ApiException(ErrorCode::StaleVersion);
        }
        $current = $this->current($row);
        $changed = array_keys(array_filter($values, static fn(mixed $value, string $field): bool => $value !== $current[$field], ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }
        $changes = array_intersect_key($values, array_flip($changed));

        self::assertContactable(\array_key_exists('email', $changes) ? $changes['email'] : $current['email'], \array_key_exists('phone', $changes) ? $changes['phone'] : $current['phone']);
        if (($changes['email'] ?? null) !== null) {
            $this->assertNoDuplicate($changes['email'], $id);
        }

        [$main, $sensitive, $days] = self::split($changes);
        $now = $this->now();
        if (!$this->volunteers->update($id, $main, $version, $userId, $now)) {
            throw new ApiException(ErrorCode::StaleVersion);
        }
        $this->volunteers->saveSensitive($id, $sensitive, $userId, $now);
        if ($days !== null) {
            $this->volunteers->replaceDays($id, $days);
        }
        $this->audit->success($context, $event, 'volunteer', $id, ['fields' => self::fieldNames($changes)]);
    }

    /**
     * @param list<string> $from statuses this target may be reached from
     */
    private function transition(Principal $principal, int $id, string $to, ?string $reason, int $version, AuditContext $context, string $event, array $from): void
    {
        if (Row::intOrNull($this->existing($id), 'user_id') === $principal->userId) {
            // The link only changes at provisioning, which never targets an account's own record.
            $this->audit->denied($context, $event, 'volunteer', $id, ['reason' => 'own_record']);

            throw new ApiException(ErrorCode::Forbidden, 'You cannot change the status of your own volunteer record.');
        }

        $this->db->transaction(function () use ($principal, $id, $to, $reason, $version, $context, $event, $from): void {
            $row = $this->existing($id, true);
            $status = Row::string($row, 'status');
            if (!\in_array($status, $from, true)) {
                throw new ApiException(ErrorCode::InvalidTransition, details: [new ErrorDetail('to_status', 'invalid_transition', "Not allowed from {$status}.")]);
            }
            if (Row::int($row, 'version') !== $version) {
                throw new ApiException(ErrorCode::StaleVersion);
            }

            $now = $this->now();
            if (!$this->volunteers->setStatus($id, $to, $version, $principal->userId, $now, $to === 'accepted')) {
                throw new ApiException(ErrorCode::StaleVersion);
            }
            $this->volunteers->recordStatus($id, $status, $to, $reason, $principal->userId, $now);
            $this->audit->success($context, $event, 'volunteer', $id, ['from' => $status, 'to' => $to]);
        });
    }

    /**
     * @param array<string, scalar|null> $main
     */
    private function insertWithReference(array $main, int $userId): int
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->volunteers->insert($this->volunteers->nextReference(), $main, $userId);
            } catch (\PDOException $e) {
                // A simultaneous creation took the same reference (unique key): take the next one.
                if ($attempt >= 3 || !str_contains($e->getMessage(), 'uq_volunteers_reference')) {
                    throw $e;
                }
            }
        }
    }

    private static function assertContactable(mixed $email, mixed $phone): void
    {
        if ($email === null && $phone === null) {
            throw new ApiException(ErrorCode::ValidationFailed, details: [
                new ErrorDetail('email', 'required', 'An email address or a phone number is required.'),
                new ErrorDetail('phone', 'required', 'An email address or a phone number is required.'),
            ]);
        }
    }

    private function assertNoDuplicate(mixed $email, ?int $exceptId): void
    {
        if (\is_string($email) && $this->volunteers->emailInUse($email, $exceptId)) {
            throw new ApiException(ErrorCode::Duplicate, 'A volunteer with this email address already exists.', [new ErrorDetail('email', 'duplicate', 'Already used by another volunteer record.')]);
        }
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function record(Principal $principal, array $row): array
    {
        $id = Row::int($row, 'id');
        $sensitive = array_filter(VolunteerRepository::SENSITIVE_COLUMNS, fn(string $f): bool => $this->fields->canRead($principal, $f)) === []
            ? array_fill_keys(VolunteerRepository::SENSITIVE_COLUMNS, null)
            : $this->volunteers->sensitive($id);
        $readable = fn(string $field): bool => $this->fields->canRead($principal, $field);
        $record = self::shape($row, $sensitive, $this->volunteers->days($id), $readable);

        $sets = ['basic'];
        foreach (array_keys(FieldPolicy::VOLUNTEER_FIELDS) as $field) {
            if (\array_key_exists($field, $record)) {
                $sets[] = $this->fields->fieldSet($field);
            }
        }

        return [$record, self::sets($sets)];
    }

    /**
     * @param array<string, scalar|null> $row
     * @param array<string, scalar|null> $sensitive
     * @param list<int>                  $days
     * @param callable(string): bool     $readable
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row, array $sensitive, array $days, callable $readable): array
    {
        $type = Row::stringOrNull($row, 'availability_type');
        $values = [
            'full_name' => Row::string($row, 'full_name'),
            'preferred_name' => Row::stringOrNull($row, 'preferred_name'),
            'email' => Row::stringOrNull($row, 'email'),
            'phone' => Row::stringOrNull($row, 'phone'),
            'skills' => Row::stringOrNull($row, 'skills'),
            'languages' => Row::stringOrNull($row, 'languages'),
            'interests' => Row::stringOrNull($row, 'interests'),
            'availability' => $type === null ? null : ['type' => $type, 'days' => $days],
        ];
        foreach (VolunteerRepository::SENSITIVE_COLUMNS as $column) {
            $values[$column] = Row::stringOrNull($sensitive, $column);
        }

        $record = ['id' => Row::int($row, 'id'), 'reference' => Row::string($row, 'reference'), 'status' => Row::string($row, 'status')];
        foreach ($values as $field => $value) {
            if ($readable($field)) {
                $record[$field] = $value;
            }
        }

        return $record + [
            'accepted_at' => Row::isoOrNull($row, 'accepted_at'),
            'created_at' => Row::isoOrNull($row, 'created_at'),
            'updated_at' => Row::isoOrNull($row, 'updated_at'),
            'version' => Row::int($row, 'version'),
        ];
    }

    /**
     * Current values in the same normalised form VolunteerInput produces.
     *
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    private function current(array $row): array
    {
        $id = Row::int($row, 'id');

        return self::shape($row, $this->volunteers->sensitive($id), $this->volunteers->days($id), static fn(): bool => true);
    }

    /**
     * Splits field values into volunteers columns, sensitive-details columns and availability days.
     *
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, scalar|null>, 1: array<string, scalar|null>, 2: ?list<int>}
     */
    private static function split(array $values): array
    {
        $main = [];
        $sensitive = [];
        $days = null;
        foreach ($values as $field => $value) {
            [$table, $column] = FieldPolicy::VOLUNTEER_FIELDS[$field];
            if ($field === 'availability') {
                $main['availability_type'] = \is_array($value) && \is_string($value['type'] ?? null) ? $value['type'] : null;
                $days = \is_array($value) && \is_array($value['days'] ?? null) ? array_values(array_filter($value['days'], \is_int(...))) : [];

                continue;
            }
            \assert($value === null || \is_string($value));
            if ($table === 'volunteers') {
                $main[$column] = $value;
            } else {
                $sensitive[$column] = $value;
            }
        }

        return [$main, $sensitive, $days];
    }

    /**
     * Audit details record field names only, never values (Workflow WF-03 history).
     *
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private static function fieldNames(array $values): array
    {
        $names = array_keys($values);
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $sets
     *
     * @return list<string>
     */
    private static function sets(array $sets): array
    {
        return array_values(array_intersect(['basic', 'contact', 'sensitive'], $sets));
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    private static function note(array $row): array
    {
        return ['id' => Row::int($row, 'id'), 'body' => Row::string($row, 'body'), 'created_by' => Row::intOrNull($row, 'created_by_user_id'), 'created_at' => Row::isoOrNull($row, 'created_at')];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function existing(int $id, bool $forUpdate = false): array
    {
        return $this->volunteers->find($id, $forUpdate) ?? throw new ApiException(ErrorCode::NotFound);
    }

    private function now(): string
    {
        return Database::timestamp($this->clock->now());
    }
}
