<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * S02 — Phase 5 field access (R6, R13; reference data only, no schema change). Loads
 * database/reference/phase5_field_access.php. Rolling back removes exactly the rows inserted here.
 */
final class SeedPhase5FieldAccess extends SchemaMigration
{
    public function up(): void
    {
        $data = $this->data();
        $this->table('permissions')->insert($data['permissions'])->saveData();
        $this->table('field_catalogue')->insert($data['field_catalogue'])->saveData();

        $bundles = [];
        foreach ($data['role_permissions'] as $role => $codes) {
            foreach ($codes as $code) {
                $bundles[] = ['role_code' => $role, 'permission_code' => $code];
            }
        }
        $this->table('role_permissions')->insert($bundles)->saveData();
    }

    public function down(): void
    {
        $data = $this->data();
        foreach ($data['role_permissions'] as $role => $codes) {
            foreach ($codes as $code) {
                $this->execute('DELETE FROM role_permissions WHERE role_code = ? AND permission_code = ?', [$role, $code]);
            }
        }
        foreach ($data['field_catalogue'] as $row) {
            $this->execute('DELETE FROM field_catalogue WHERE table_name = ? AND column_name = ?', [$row['table_name'], $row['column_name']]);
        }
        foreach ($data['permissions'] as $row) {
            $this->execute('DELETE FROM permissions WHERE code = ?', [$row['code']]);
        }
    }

    /**
     * @return array{permissions: list<array<string, mixed>>, field_catalogue: list<array<string, mixed>>, role_permissions: array<string, list<string>>}
     */
    private function data(): array
    {
        /** @var array{permissions: list<array<string, mixed>>, field_catalogue: list<array<string, mixed>>, role_permissions: array<string, list<string>>} $data */
        $data = require dirname(__DIR__) . '/reference/phase5_field_access.php';

        return $data;
    }
}
