<?php

declare(strict_types=1);

/*
 * The four roles [A] (Database V1.4 §5.1.2). technical_manager is not an ordinary role [A]:
 * only a System Owner grants or revokes it (AR-13).
 *
 * Part of a released migration: never edit. Change reference data with a new migration.
 *
 * @return list<array{code: string, name: string, is_ordinary: int}>
 */

return [
    ['code' => 'volunteer', 'name' => 'Volunteer', 'is_ordinary' => 1],
    ['code' => 'full_admin', 'name' => 'Full Admin', 'is_ordinary' => 1],
    ['code' => 'monitoring_admin', 'name' => 'Monitoring Admin', 'is_ordinary' => 1],
    ['code' => 'technical_manager', 'name' => 'Technical Manager', 'is_ordinary' => 0],
];
