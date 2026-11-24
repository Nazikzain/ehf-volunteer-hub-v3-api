<?php

declare(strict_types=1);

/*
 * Review rating scale 1–5 and the initial five criteria (Database V1.4 §5.9: "Initial five seeded
 * from the frontend [R]", approved with REC). Text exactly as in the frontend
 * (src/lib/performance-workflow.ts). Criteria management stays read-only until S3.
 *
 * Part of a released migration: never edit. Change reference data with a new migration.
 *
 * @return array{labels: list<array{rating: int, label: string}>, criteria: list<array{name: string, sort_order: int}>}
 */

return [
    'labels' => [
        ['rating' => 1, 'label' => 'Needs Significant Improvement'],
        ['rating' => 2, 'label' => 'Needs Improvement'],
        ['rating' => 3, 'label' => 'Meets Expectations'],
        ['rating' => 4, 'label' => 'Good'],
        ['rating' => 5, 'label' => 'Excellent'],
    ],
    'criteria' => [
        ['name' => 'Attendance and reliability', 'sort_order' => 10],
        ['name' => 'Communication', 'sort_order' => 20],
        ['name' => 'Teamwork', 'sort_order' => 30],
        ['name' => 'Task completion', 'sort_order' => 40],
        ['name' => 'Initiative', 'sort_order' => 50],
    ],
];
