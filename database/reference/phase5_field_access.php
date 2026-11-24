<?php

declare(strict_types=1);

/*
 * Phase 5 field access (migration S02), approved by the project owner:
 *
 * - R6: the eight volunteer_sensitive_details columns get their per-field codes
 *   fields.sensitive.<column> (D3 pattern, API §6.4), are catalogued as 'sensitive', and the Full
 *   Admin bundle gains all eight plus confidential.volunteer_notes.view / .create.
 * - R13: the Monitoring Admin bundle gains fields.contact.email and fields.contact.phone. The
 *   application honours these two bundle entries only inside a decision context
 *   (Authorisation\FieldPolicy::DECISION_CONTEXT_ONLY), never on volunteer record endpoints.
 * - R12 (volunteer self-edit) is not stored in the database: the schema has no place for it, and it
 *   needs no new code (self.profile.edit is already in the volunteer bundle). The editable fields
 *   are listed in Authorisation\FieldPolicy::SELF_EDITABLE.
 *
 * Part of a released migration once merged: never edit. Change reference data with a new migration.
 *
 * @return array{
 *     permissions: list<array{code: string, category: string, action_type: string, is_field_code: int, grantable: int, description: string}>,
 *     field_catalogue: list<array{table_name: string, column_name: string, field_set: string, access_code: string, write_code: string, classification_status: string, description: string}>,
 *     role_permissions: array<string, list<string>>
 * }
 */

$sensitive = [
    'address_line1' => 'Address line 1',
    'address_line2' => 'Address line 2',
    'town' => 'Town',
    'postcode' => 'Postcode',
    'nationality' => 'Nationality',
    'emergency_contact_name' => 'Emergency contact name',
    'emergency_contact_phone' => 'Emergency contact phone',
    'emergency_contact_relationship' => 'Emergency contact relationship',
];

$permissions = [];
$catalogue = [];
foreach ($sensitive as $column => $label) {
    $code = 'fields.sensitive.' . $column;
    $permissions[] = ['code' => $code, 'category' => 'operational', 'action_type' => 'view', 'is_field_code' => 1, 'grantable' => 1, 'description' => "Field access (R6): volunteer {$label} — read and write"];
    $catalogue[] = ['table_name' => 'volunteer_sensitive_details', 'column_name' => $column, 'field_set' => 'sensitive', 'access_code' => $code, 'write_code' => $code, 'classification_status' => 'approved', 'description' => "{$label} (R6)"];
}

return [
    'permissions' => $permissions,
    'field_catalogue' => $catalogue,
    'role_permissions' => [
        'full_admin' => [...array_column($permissions, 'code'), 'confidential.volunteer_notes.view', 'confidential.volunteer_notes.create'],
        'monitoring_admin' => ['fields.contact.email', 'fields.contact.phone'],
    ],
];
