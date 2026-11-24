<?php

declare(strict_types=1);

/*
 * Setting keys seeded with no value (Database V1.4 §5.12: "pending values seeded empty").
 * Only keys whose existence follows from an approved document are seeded. A null value means
 * "not decided": certificate issuance stays blocked while the threshold is empty (W7 [IFC]).
 * Retention policies are not seeded at all (S4: empty until EHF approves).
 *
 * Part of a released migration: never edit. Change reference data with a new migration.
 *
 * @return list<array{setting_key: string, category: string, value_type: string, description: string}>
 */

return [
    [
        'setting_key' => 'certificate.eligibility_threshold_minutes',
        'category' => 'certificate',
        'value_type' => 'integer',
        'description' => 'Approved minutes needed for a final certificate (W7, pending; empty blocks issuance)',
    ],
];
