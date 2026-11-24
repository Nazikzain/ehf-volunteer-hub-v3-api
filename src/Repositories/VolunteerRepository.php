<?php

declare(strict_types=1);

namespace Ehf\Api\Repositories;

use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;

/**
 * Volunteer operational records (Database V1.4 §5.3): volunteers, volunteer_sensitive_details,
 * volunteer_availability_days, volunteer_status_history, volunteer_notes. Prepared SQL only; the
 * services decide which of these columns a caller may see.
 */
final readonly class VolunteerRepository
{
    private const string COLUMNS = 'id, reference, user_id, full_name, preferred_name, email, phone, status, accepted_at, accepted_by_user_id, skills, languages, interests, availability_type, created_at, updated_at, version';

    public const array SENSITIVE_COLUMNS = [
        'address_line1', 'address_line2', 'town', 'postcode', 'nationality',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
    ];

    /** Sort keys (API §4.1 allowlist) => SQL. */
    public const array SORTS = [
        'full_name' => 'v.full_name ASC, v.id ASC',
        '-full_name' => 'v.full_name DESC, v.id DESC',
        'reference' => 'v.reference ASC',
        '-reference' => 'v.reference DESC',
        'created_at' => 'v.created_at ASC, v.id ASC',
        '-created_at' => 'v.created_at DESC, v.id DESC',
    ];

    public function __construct(private Database $db) {}

    /**
     * @return array<string, scalar|null>|null
     */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one('SELECT ' . self::COLUMNS . ' FROM volunteers WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function sensitive(int $volunteerId): array
    {
        $row = $this->db->one('SELECT ' . implode(', ', self::SENSITIVE_COLUMNS) . ' FROM volunteer_sensitive_details WHERE volunteer_id = ?', [$volunteerId]);

        return $row ?? array_fill_keys(self::SENSITIVE_COLUMNS, null);
    }

    /**
     * @return list<int> ISO weekdays 1–7
     */
    public function days(int $volunteerId): array
    {
        return array_map(static fn(array $row): int => Row::int($row, 'weekday'), $this->db->all('SELECT weekday FROM volunteer_availability_days WHERE volunteer_id = ? ORDER BY weekday', [$volunteerId]));
    }

    /**
     * W1 duplicate criterion: the same contact email, ignoring case. Locks matching index entries so
     * that two simultaneous creations with one address cannot both pass.
     */
    public function emailInUse(string $email, ?int $exceptId = null): bool
    {
        $rows = $this->db->all('SELECT id FROM volunteers WHERE email = ? FOR UPDATE', [mb_strtolower($email)]);
        foreach ($rows as $row) {
            if (Row::int($row, 'id') !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Next free reference in the D2 format `V-` + 6 digits.
     */
    public function nextReference(): string
    {
        $last = $this->db->value('SELECT MAX(CAST(SUBSTRING(reference, 3) AS UNSIGNED)) FROM volunteers');

        return \sprintf('V-%06d', (int) $last + 1);
    }

    /**
     * @param array<string, scalar|null> $columns volunteers columns
     */
    public function insert(string $reference, array $columns, int $createdBy): int
    {
        $columns = ['reference' => $reference, 'created_by_user_id' => $createdBy] + $columns;

        return $this->db->insert(
            'INSERT INTO volunteers (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', array_fill(0, \count($columns), '?')) . ')',
            array_values($columns),
        );
    }

    /**
     * @param array<string, scalar|null> $columns volunteers columns
     *
     * @return bool false when the version did not match (lost update)
     */
    public function update(int $id, array $columns, int $expectedVersion, int $updatedBy, string $now): bool
    {
        $set = array_map(static fn(string $column): string => "{$column} = ?", array_keys($columns));

        return $this->db->execute(
            'UPDATE volunteers SET ' . implode(', ', [...$set, 'updated_at = ?', 'updated_by_user_id = ?', 'version = version + 1']) . ' WHERE id = ? AND version = ?',
            [...array_values($columns), $now, $updatedBy, $id, $expectedVersion],
        ) > 0;
    }

    /**
     * @param array<string, scalar|null> $columns volunteer_sensitive_details columns
     */
    public function saveSensitive(int $volunteerId, array $columns, int $userId, string $now): void
    {
        if ($columns === []) {
            return;
        }
        $exists = $this->db->value('SELECT COUNT(*) FROM volunteer_sensitive_details WHERE volunteer_id = ? FOR UPDATE', [$volunteerId]) > 0;
        if ($exists) {
            $set = array_map(static fn(string $column): string => "{$column} = ?", array_keys($columns));
            $this->db->execute(
                'UPDATE volunteer_sensitive_details SET ' . implode(', ', [...$set, 'updated_at = ?', 'updated_by_user_id = ?', 'version = version + 1']) . ' WHERE volunteer_id = ?',
                [...array_values($columns), $now, $userId, $volunteerId],
            );

            return;
        }

        $columns = ['volunteer_id' => $volunteerId, 'created_by_user_id' => $userId] + $columns;
        $this->db->execute(
            'INSERT INTO volunteer_sensitive_details (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', array_fill(0, \count($columns), '?')) . ')',
            array_values($columns),
        );
    }

    /**
     * @param list<int> $days
     */
    public function replaceDays(int $volunteerId, array $days): void
    {
        $this->db->execute('DELETE FROM volunteer_availability_days WHERE volunteer_id = ?', [$volunteerId]);
        foreach ($days as $day) {
            $this->db->execute('INSERT INTO volunteer_availability_days (volunteer_id, weekday) VALUES (?, ?)', [$volunteerId, $day]);
        }
    }

    public function recordStatus(int $volunteerId, ?string $from, string $to, ?string $reason, int $userId, string $now): void
    {
        $this->db->execute(
            'INSERT INTO volunteer_status_history (volunteer_id, from_status, to_status, reason, changed_by_user_id, changed_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$volunteerId, $from, $to, $reason, $userId, $now],
        );
    }

    public function setStatus(int $id, string $status, int $expectedVersion, int $userId, string $now, bool $accepting): bool
    {
        $acceptance = $accepting ? ', accepted_at = ?, accepted_by_user_id = ?' : '';
        $params = $accepting ? [$status, $now, $userId] : [$status];

        return $this->db->execute(
            "UPDATE volunteers SET status = ?{$acceptance}, updated_at = ?, updated_by_user_id = ?, version = version + 1 WHERE id = ? AND version = ?",
            [...$params, $now, $userId, $id, $expectedVersion],
        ) > 0;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function history(int $volunteerId): array
    {
        return $this->db->all('SELECT from_status, to_status, reason, changed_by_user_id, changed_at FROM volunteer_status_history WHERE volunteer_id = ? ORDER BY changed_at, id', [$volunteerId]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function notes(int $volunteerId): array
    {
        return $this->db->all('SELECT id, body, created_by_user_id, created_at FROM volunteer_notes WHERE volunteer_id = ? ORDER BY created_at, id', [$volunteerId]);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function addNote(int $volunteerId, string $body, int $userId, string $now): array
    {
        $id = $this->db->insert('INSERT INTO volunteer_notes (volunteer_id, body, created_by_user_id, created_at) VALUES (?, ?, ?, ?)', [$volunteerId, $body, $userId, $now]);
        $row = $this->db->one('SELECT id, body, created_by_user_id, created_at FROM volunteer_notes WHERE id = ?', [$id]);
        \assert($row !== null);

        return $row;
    }

    /**
     * @param bool $searchEmail only when the caller may read the contact email (API §4.1: q covers readable fields only)
     *
     * @return list<array<string, scalar|null>>
     */
    public function search(?string $query, bool $searchEmail, ?string $status, ?int $projectId, string $sort, int $limit, int $offset): array
    {
        [$where, $params] = self::filters($query, $searchEmail, $status, $projectId);

        return $this->db->all('SELECT ' . self::prefixed() . " FROM volunteers v {$where} ORDER BY " . self::SORTS[$sort] . " LIMIT {$limit} OFFSET {$offset}", $params);
    }

    public function count(?string $query, bool $searchEmail, ?string $status, ?int $projectId): int
    {
        [$where, $params] = self::filters($query, $searchEmail, $status, $projectId);

        return (int) $this->db->value("SELECT COUNT(*) FROM volunteers v {$where}", $params);
    }

    /**
     * Portal provisioning candidates (W1: accepted or active, no linked account). Reads only id,
     * reference, full name and, for AC-6 Option 1, the contact email.
     *
     * @param list<string> $eligible
     *
     * @return list<array<string, scalar|null>>
     */
    public function candidates(array $eligible, ?string $query, int $limit, int $offset): array
    {
        [$where, $params] = self::candidateFilters($eligible, $query);

        return $this->db->all("SELECT v.id, v.reference, v.full_name, v.email FROM volunteers v {$where} ORDER BY v.full_name, v.id LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /**
     * @param list<string> $eligible
     */
    public function countCandidates(array $eligible, ?string $query): int
    {
        [$where, $params] = self::candidateFilters($eligible, $query);

        return (int) $this->db->value("SELECT COUNT(*) FROM volunteers v {$where}", $params);
    }

    /**
     * Links the new account (the only volunteer column provisioning writes; Database V1.4 §5.3.1).
     */
    public function linkAccount(int $volunteerId, int $userId): void
    {
        $this->db->execute('UPDATE volunteers SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, $volunteerId]);
    }

    private static function prefixed(): string
    {
        return implode(', ', array_map(static fn(string $c): string => 'v.' . trim($c), explode(',', self::COLUMNS)));
    }

    /**
     * @return array{0: string, 1: list<scalar|null>}
     */
    private static function filters(?string $query, bool $searchEmail, ?string $status, ?int $projectId): array
    {
        $conditions = [];
        $params = [];
        if ($query !== null) {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $fields = ['v.full_name', 'v.preferred_name', 'v.reference'];
            if ($searchEmail) {
                $fields[] = 'v.email';
            }
            $conditions[] = '(' . implode(' OR ', array_map(static fn(string $f): string => "{$f} LIKE ?", $fields)) . ')';
            array_push($params, ...array_fill(0, \count($fields), $like));
        }
        if ($status !== null) {
            $conditions[] = 'v.status = ?';
            $params[] = $status;
        }
        if ($projectId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM volunteer_assignments a WHERE a.volunteer_id = v.id AND a.project_id = ?)';
            $params[] = $projectId;
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param list<string> $eligible
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    private static function candidateFilters(array $eligible, ?string $query): array
    {
        $conditions = ['v.user_id IS NULL', 'v.status IN (' . implode(', ', array_fill(0, \count($eligible), '?')) . ')'];
        $params = $eligible;
        if ($query !== null) {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $conditions[] = '(v.full_name LIKE ? OR v.reference LIKE ? OR v.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }
}
