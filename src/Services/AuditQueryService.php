<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;

/**
 * Reading the audit log (API §7.18). The security view (security and technical categories) needs
 * `audit.security.view`; the operational view needs `audit.operational.view`, which no role holds
 * until R10 is decided. Records are never edited or deleted.
 */
final readonly class AuditQueryService
{
    public const array VIEW_CODE = [
        'security' => 'audit.security.view',
        'technical' => 'audit.security.view',
        'operational' => 'audit.operational.view',
    ];

    public function __construct(
        private Database $db,
        private AuditLogger $logger,
    ) {}

    /**
     * @param list<string> $categories
     * @param array{actor?: ?int, action?: ?string, resource_type?: ?string, resource_id?: ?int, outcome?: ?string, from?: ?string, to?: ?string} $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $categories, array $filters, int $page, int $perPage): array
    {
        $conditions = ['category IN (' . implode(', ', array_fill(0, \count($categories), '?')) . ')'];
        $params = $categories;
        foreach (['actor' => 'actor_user_id = ?', 'action' => 'action = ?', 'resource_type' => 'resource_type = ?', 'resource_id' => 'resource_id = ?', 'outcome' => 'outcome = ?', 'from' => 'occurred_at >= ?', 'to' => 'occurred_at <= ?'] as $key => $sql) {
            if (($filters[$key] ?? null) !== null) {
                $conditions[] = $sql;
                $params[] = $filters[$key];
            }
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM audit_logs {$where}", $params);
        $rows = $this->db->all("SELECT * FROM audit_logs {$where} ORDER BY id DESC LIMIT ? OFFSET ?", [...$params, $perPage, ($page - 1) * $perPage]);

        return ['items' => array_map($this->view(...), $rows), 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id, Principal $principal): array
    {
        $row = $this->db->one('SELECT * FROM audit_logs WHERE id = ?', [$id]);
        if ($row === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if (!$principal->can(self::VIEW_CODE[Row::string($row, 'category')] ?? '')) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return $this->view($row);
    }

    /**
     * @return array{intact: bool, checked: int, first_break_id: ?int}
     */
    public function verify(?string $from, ?string $to): array
    {
        return $this->logger->verify($from, $to);
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    private function view(array $row): array
    {
        $details = Row::stringOrNull($row, 'details');

        return [
            'id' => Row::int($row, 'id'),
            'occurred_at' => Row::isoOrNull($row, 'occurred_at'),
            'category' => Row::string($row, 'category'),
            'actor' => ['id' => Row::intOrNull($row, 'actor_user_id'), 'acting_role' => Row::stringOrNull($row, 'acting_role')],
            'action' => Row::string($row, 'action'),
            'resource' => ['type' => Row::stringOrNull($row, 'resource_type'), 'id' => Row::intOrNull($row, 'resource_id')],
            'outcome' => Row::string($row, 'outcome'),
            'request_id' => Row::stringOrNull($row, 'request_id'),
            'details' => $details === null ? null : json_decode($details, true, 64, JSON_THROW_ON_ERROR),
        ];
    }
}
