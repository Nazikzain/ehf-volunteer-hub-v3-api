<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * S01 — reference data (Database V1.4 §6.3 S01, §12: decisions are recorded by reference-data
 * migrations). Loads database/reference/*.php:
 *
 * - status catalogue (§5.1.1), roles (§5.1.2);
 * - permission catalogue: API V1.3.1 §6.4 codes (D1) plus the AC-4 contact field codes;
 * - role bundles: API §6.5 entries marked [A] only, plus AC-4 for Full Admin;
 * - field catalogue (D3, AC-4); review rating labels and the initial five criteria (REC);
 * - setting keys whose values are pending (seeded empty).
 *
 * Not seeded: sensitive field-code instances (R6/D3), retention policies (S4), the bootstrap
 * System Owner and any Technical Manager grant (RS4/R7, pending), and any user, volunteer or
 * other record. Rolling back removes exactly the rows inserted here.
 */
final class SeedReferenceData extends SchemaMigration
{
    public function up(): void
    {
        $this->table('status_catalogue')->insert($this->reference('status_catalogue'))->saveData();
        $this->table('roles')->insert($this->reference('roles'))->saveData();
        $this->table('permissions')->insert($this->reference('permissions'))->saveData();

        $bundles = [];
        foreach ($this->bundles() as $role => $codes) {
            foreach ($codes as $code) {
                $bundles[] = ['role_code' => $role, 'permission_code' => $code];
            }
        }
        $this->table('role_permissions')->insert($bundles)->saveData();

        $this->table('field_catalogue')->insert($this->reference('field_catalogue'))->saveData();

        $scale = $this->reviewScale();
        $this->table('review_rating_labels')->insert($scale['labels'])->saveData();
        $this->table('review_criteria')->insert($scale['criteria'])->saveData();

        $this->table('settings')->insert($this->reference('settings'))->saveData();
    }

    public function down(): void
    {
        foreach ($this->reference('settings') as $row) {
            $this->execute('DELETE FROM settings WHERE setting_key = ?', [$row['setting_key']]);
        }

        $scale = $this->reviewScale();
        foreach ($scale['criteria'] as $row) {
            $this->execute('DELETE FROM review_criteria WHERE name = ?', [$row['name']]);
        }
        foreach ($scale['labels'] as $row) {
            $this->execute('DELETE FROM review_rating_labels WHERE rating = ?', [$row['rating']]);
        }

        foreach ($this->reference('field_catalogue') as $row) {
            $this->execute('DELETE FROM field_catalogue WHERE table_name = ? AND column_name = ?', [$row['table_name'], $row['column_name']]);
        }

        foreach ($this->bundles() as $role => $codes) {
            foreach ($codes as $code) {
                $this->execute('DELETE FROM role_permissions WHERE role_code = ? AND permission_code = ?', [$role, $code]);
            }
        }

        foreach ($this->reference('permissions') as $row) {
            $this->execute('DELETE FROM permissions WHERE code = ?', [$row['code']]);
        }
        foreach ($this->reference('roles') as $row) {
            $this->execute('DELETE FROM roles WHERE code = ?', [$row['code']]);
        }
        foreach ($this->reference('status_catalogue') as $row) {
            $this->execute('DELETE FROM status_catalogue WHERE domain = ? AND code = ?', [$row['domain'], $row['code']]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reference(string $name): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require dirname(__DIR__) . '/reference/' . $name . '.php';

        return $rows;
    }

    /**
     * @return array<string, list<string>>
     */
    private function bundles(): array
    {
        /** @var array<string, list<string>> $bundles */
        $bundles = require dirname(__DIR__) . '/reference/role_permissions.php';

        return $bundles;
    }

    /**
     * @return array{labels: list<array{rating: int, label: string}>, criteria: list<array{name: string, sort_order: int}>}
     */
    private function reviewScale(): array
    {
        /** @var array{labels: list<array{rating: int, label: string}>, criteria: list<array{name: string, sort_order: int}>} $scale */
        $scale = require dirname(__DIR__) . '/reference/review_scale.php';

        return $scale;
    }
}
