<?php

declare(strict_types=1);

namespace Ehf\Api\Authorisation;

use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;

/**
 * Field-level visibility for volunteer data (API V1.3.1 §6.7, Appendix B), read from the central
 * field catalogue (`field_catalogue`, Database V1.4 §5.1.6):
 *
 * - `basic` fields: anyone who may see the record.
 * - `contact` and `sensitive` fields: only with the field's own code, which controls both reading
 *   and writing. A personal column that is not catalogued is treated as sensitive and is
 *   inaccessible (fail closed).
 * - Confidential notes are never part of a volunteer record response.
 *
 * Approved Phase 5 decisions applied here:
 * - R12: the fields a volunteer may edit on their own record (SELF_EDITABLE).
 * - R13: Monitoring Admin's contact codes count only inside a decision context
 *   (DECISION_CONTEXT_ONLY), never on volunteer record endpoints.
 * - R6 is reference data (the Full Admin bundle), not code.
 */
final class FieldPolicy
{
    /** API field => [table, column]. */
    public const array VOLUNTEER_FIELDS = [
        'full_name' => ['volunteers', 'full_name'],
        'preferred_name' => ['volunteers', 'preferred_name'],
        'email' => ['volunteers', 'email'],
        'phone' => ['volunteers', 'phone'],
        'skills' => ['volunteers', 'skills'],
        'languages' => ['volunteers', 'languages'],
        'interests' => ['volunteers', 'interests'],
        'availability' => ['volunteers', 'availability_type'],
        'address_line1' => ['volunteer_sensitive_details', 'address_line1'],
        'address_line2' => ['volunteer_sensitive_details', 'address_line2'],
        'town' => ['volunteer_sensitive_details', 'town'],
        'postcode' => ['volunteer_sensitive_details', 'postcode'],
        'nationality' => ['volunteer_sensitive_details', 'nationality'],
        'emergency_contact_name' => ['volunteer_sensitive_details', 'emergency_contact_name'],
        'emergency_contact_phone' => ['volunteer_sensitive_details', 'emergency_contact_phone'],
        'emergency_contact_relationship' => ['volunteer_sensitive_details', 'emergency_contact_relationship'],
    ];

    /** R12 (approved): fields a volunteer may change on their own record. */
    public const array SELF_EDITABLE = [
        'preferred_name', 'phone',
        'address_line1', 'address_line2', 'town', 'postcode',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
    ];

    /** R13 (approved): role bundle entries that apply only inside a decision context. */
    public const array DECISION_CONTEXT_ONLY = [
        'monitoring_admin' => ['fields.contact.email', 'fields.contact.phone'],
    ];

    /** @var array<string, array{set: string, code: ?string, write: ?string}>|null */
    private ?array $catalogue = null;

    public function __construct(private readonly Database $db) {}

    /**
     * 'basic', 'contact' or 'sensitive'.
     */
    public function fieldSet(string $field): string
    {
        return $this->entry($field)['set'];
    }

    /**
     * May read the field on volunteer record endpoints.
     */
    public function canRead(Principal $principal, string $field): bool
    {
        $entry = $this->entry($field);

        return $entry['set'] === 'basic' || ($entry['code'] !== null && $principal->canOnRecords($entry['code']));
    }

    /**
     * May write the field on volunteer record endpoints (the endpoint's own code is checked by the route).
     */
    public function canWrite(Principal $principal, string $field): bool
    {
        $entry = $this->entry($field);

        return $entry['set'] === 'basic' || ($entry['write'] !== null && $principal->canOnRecords($entry['write']));
    }

    /**
     * May read the field inside a decision context (API §6.6 level 1), where R13 codes count.
     */
    public function canReadInDecisionContext(Principal $principal, string $field): bool
    {
        $entry = $this->entry($field);

        return $entry['set'] === 'basic' || ($entry['code'] !== null && $principal->can($entry['code']));
    }

    /**
     * @return array{set: string, code: ?string, write: ?string}
     */
    private function entry(string $field): array
    {
        [$table, $column] = self::VOLUNTEER_FIELDS[$field] ?? throw new \LogicException("Unknown volunteer field {$field}.");
        if ($this->catalogue === null) {
            $this->catalogue = [];
            foreach ($this->db->all('SELECT table_name, column_name, field_set, access_code, write_code FROM field_catalogue') as $row) {
                $this->catalogue[Row::string($row, 'table_name') . '.' . Row::string($row, 'column_name')] = [
                    'set' => Row::string($row, 'field_set'),
                    'code' => Row::stringOrNull($row, 'access_code'),
                    'write' => Row::stringOrNull($row, 'write_code'),
                ];
            }
        }

        return $this->catalogue["{$table}.{$column}"] ?? ['set' => 'sensitive', 'code' => null, 'write' => null];
    }
}
