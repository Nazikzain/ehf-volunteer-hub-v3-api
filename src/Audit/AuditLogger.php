<?php

declare(strict_types=1);

namespace Ehf\Api\Audit;

use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;

/**
 * Writes audit records (API §11; Database V1.4 `audit_logs`, D4).
 *
 * - Each record stores the previous record's hash and its own SHA-256 over the previous hash and
 *   its fields, forming a chain that `verify()` checks.
 * - Success records are written inside the action's transaction: if the action fails, no success
 *   record exists. Refused attempts are written separately (WF-18).
 * - `details` hold only identifiers and the names of changed fields: never note bodies,
 *   passwords, tokens or personal values.
 * - Records are never updated or deleted by the application.
 */
final readonly class AuditLogger
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private Database $db,
        private Clock $clock,
    ) {}

    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    public function success(AuditContext $context, string $action, ?string $resourceType = null, ?int $resourceId = null, array $details = []): void
    {
        $this->write($context, $action, 'success', $resourceType, $resourceId, $details);
    }

    /**
     * Records a refused attempt. Call it outside the refused action's transaction (or the record
     * would be rolled back with it).
     *
     * @param array<string, scalar|list<scalar>|null> $details
     */
    public function denied(AuditContext $context, string $action, ?string $resourceType = null, ?int $resourceId = null, array $details = []): void
    {
        $this->write($context, $action, 'denied', $resourceType, $resourceId, $details);
    }

    public static function category(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'setting.'), str_starts_with($action, 'retention.') => 'technical',
            preg_match('/^(auth|session|account|invitation|role|permission|owner|exception)\./', $action) === 1 => 'security',
            default => 'operational',
        };
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    private function write(AuditContext $context, string $action, string $outcome, ?string $resourceType, ?int $resourceId, array $details): void
    {
        $this->db->transaction(function () use ($context, $action, $outcome, $resourceType, $resourceId, $details): void {
            $previous = $this->db->value('SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $previousHash = $previous === null ? self::GENESIS : (string) $previous;

            $record = [
                'occurred_at' => Database::timestamp($this->clock->now()),
                'category' => self::category($action),
                'actor_user_id' => $context->actorUserId,
                'acting_role' => $context->actingRole,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'project_id' => null,
                'outcome' => $outcome,
                'request_id' => $context->requestId,
                'ip_prefix' => $context->ipPrefix,
                'details' => $details === [] ? null : self::canonical($details),
            ];

            $this->db->insert(
                'INSERT INTO audit_logs (occurred_at, category, actor_user_id, acting_role, action, resource_type, resource_id, project_id, outcome, request_id, ip_prefix, details, prev_hash, hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $record['occurred_at'], $record['category'], $record['actor_user_id'], $record['acting_role'], $record['action'],
                    $record['resource_type'], $record['resource_id'], $record['project_id'], $record['outcome'], $record['request_id'],
                    $record['ip_prefix'], $record['details'] === null ? null : json_encode($record['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $previousHash, self::hash($previousHash, $record),
                ],
            );
        });
    }

    /**
     * Checks the hash chain in id order (API §7.18 GET /audit-logs/verify).
     *
     * @return array{intact: bool, checked: int, first_break_id: ?int}
     */
    public function verify(?string $from = null, ?string $to = null): array
    {
        $conditions = [];
        $params = [];
        if ($from !== null) {
            $conditions[] = 'occurred_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'occurred_at <= ?';
            $params[] = $to;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $rows = $this->db->all("SELECT * FROM audit_logs {$where} ORDER BY id", $params);
        $checked = 0;
        $expectedPrevious = null;
        if ($rows !== []) {
            $before = $this->db->value('SELECT hash FROM audit_logs WHERE id < ? ORDER BY id DESC LIMIT 1', [Row::int($rows[0], 'id')]);
            $expectedPrevious = $before === null ? self::GENESIS : (string) $before;
        }

        foreach ($rows as $row) {
            ++$checked;
            $details = Row::stringOrNull($row, 'details');
            $decoded = $details === null ? null : json_decode($details, true, 64, JSON_THROW_ON_ERROR);
            $record = [
                'occurred_at' => Row::time(Row::string($row, 'occurred_at'))->format(Database::TIMESTAMP_FORMAT),
                'category' => Row::string($row, 'category'),
                'actor_user_id' => Row::intOrNull($row, 'actor_user_id'),
                'acting_role' => Row::stringOrNull($row, 'acting_role'),
                'action' => Row::string($row, 'action'),
                'resource_type' => Row::stringOrNull($row, 'resource_type'),
                'resource_id' => Row::intOrNull($row, 'resource_id'),
                'project_id' => Row::intOrNull($row, 'project_id'),
                'outcome' => Row::string($row, 'outcome'),
                'request_id' => Row::stringOrNull($row, 'request_id'),
                'ip_prefix' => Row::stringOrNull($row, 'ip_prefix'),
                'details' => \is_array($decoded) ? self::canonical($decoded) : null,
            ];

            $previous = Row::string($row, 'prev_hash');
            if ($previous !== $expectedPrevious || !hash_equals(self::hash($previous, $record), Row::string($row, 'hash'))) {
                return ['intact' => false, 'checked' => $checked, 'first_break_id' => Row::int($row, 'id')];
            }
            $expectedPrevious = Row::string($row, 'hash');
        }

        return ['intact' => true, 'checked' => $checked, 'first_break_id' => null];
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function hash(string $previousHash, array $record): string
    {
        return hash('sha256', $previousHash . "\n" . json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Key-sorted copy, so the hash does not depend on how an engine stores JSON.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    public static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
