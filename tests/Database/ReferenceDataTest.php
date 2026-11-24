<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Database;

use Ehf\Api\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The reference data seeded by S01 matches the approved decisions (D1, D3, AC-4, REC) and keeps
 * every pending, interim and recommended item out.
 */
final class ReferenceDataTest extends TestCase
{
    private static ?TestDatabase $db = null;

    public static function setUpBeforeClass(): void
    {
        self::$db = TestDatabase::create();
        self::$db?->migrate();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->drop();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('DB_TEST_* not set: reference data tests need a throwaway MySQL or MariaDB server.');
        }
    }

    public function testPermissionCatalogueMatchesTheReferenceFile(): void
    {
        /** @var list<array{code: string, category: string, action_type: string, is_field_code: int, grantable: int}> $expected */
        $expected = require \dirname(__DIR__, 2) . '/database/reference/permissions.php';

        $rows = $this->db()->rows('SELECT code, category, action_type, is_field_code, grantable, exception_eligible, basis FROM permissions ORDER BY code');
        self::assertCount(\count($expected), $rows);
        self::assertCount(148, $rows, 'API §6.4 has 146 codes; AC-4 adds the two contact field codes.');

        $byCode = array_column($rows, null, 'code');
        foreach ($expected as $permission) {
            $row = $byCode[$permission['code']] ?? null;
            self::assertNotNull($row, $permission['code']);
            self::assertSame($permission['category'], $row['category'], $permission['code']);
            self::assertSame($permission['action_type'], $row['action_type'], $permission['code']);
            self::assertSame($permission['is_field_code'], (int) $row['is_field_code'], $permission['code']);
            self::assertSame(0, (int) $row['exception_eligible'], 'No code is exception-eligible until R19 (IFC).');
            self::assertSame('approved', $row['basis']);
        }
    }

    public function testCategoriesFollowApiSection64(): void
    {
        foreach ($this->db()->rows('SELECT code, category, grantable FROM permissions') as $row) {
            $code = (string) $row['code'];
            $category = (string) $row['category'];

            if (str_starts_with($code, 'self.')) {
                self::assertSame('self', $category, $code);
            } elseif (preg_match('/^(owners|exceptions)\.|^roles\.technical_manager\./', $code) === 1) {
                self::assertSame('governance', $category, $code);
                self::assertSame(0, (int) $row['grantable'], "Governance codes are never grantable (G8): {$code}");
            } elseif (preg_match('/^(accounts|invitations|grants|sessions|retention|settings\.technical)\./', $code) === 1) {
                self::assertSame('technical', $category, $code);
            }
        }

        self::assertSame(10, (int) $this->db()->scalar("SELECT COUNT(*) FROM permissions WHERE category = 'governance'"));
    }

    public function testOnlyTheAc4FieldCodeInstancesAreSeeded(): void
    {
        self::assertSame(
            ['fields.contact.email', 'fields.contact.phone'],
            array_column($this->db()->rows("SELECT code FROM permissions WHERE code LIKE 'fields.%' ORDER BY code"), 'code'),
        );
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM permissions WHERE code LIKE 'fields.sensitive.%'"), 'Sensitive field codes are pending (R6/D3).');
    }

    public function testRoleBundlesMatchTheReferenceFileExactly(): void
    {
        /** @var array<string, list<string>> $bundles */
        $bundles = require \dirname(__DIR__, 2) . '/database/reference/role_permissions.php';

        foreach ($bundles as $role => $codes) {
            $actual = array_column($this->db()->rows('SELECT permission_code FROM role_permissions WHERE role_code = ? ORDER BY permission_code', [$role]), 'permission_code');
            sort($codes);
            self::assertSame($codes, $actual, $role);
        }

        self::assertSame(120, (int) $this->db()->scalar('SELECT COUNT(*) FROM role_permissions'));
    }

    public function testBundlesRespectRoleBoundaries(): void
    {
        $db = $this->db();

        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.code = rp.permission_code WHERE p.category = 'governance'"), 'Governance codes come only from System Owner membership.');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.code = rp.permission_code WHERE rp.role_code = 'technical_manager' AND p.category = 'operational'"), 'No operational code for Technical Managers by role.');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.code = rp.permission_code WHERE rp.role_code = 'volunteer' AND p.category <> 'self'"), 'Volunteers hold self-service codes only.');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.code = rp.permission_code WHERE rp.role_code IN ('full_admin', 'monitoring_admin') AND p.category = 'technical'"), 'No technical authority for operational roles.');
    }

    public function testAc4ContactCodesBelongToFullAdminOnly(): void
    {
        self::assertSame(
            [['role_code' => 'full_admin', 'permission_code' => 'fields.contact.email'], ['role_code' => 'full_admin', 'permission_code' => 'fields.contact.phone']],
            $this->db()->rows("SELECT role_code, permission_code FROM role_permissions WHERE permission_code LIKE 'fields.%' ORDER BY role_code, permission_code"),
        );
    }

    public function testPendingAndRecommendedEntriesAreNotInAnyBundle(): void
    {
        $absent = [
            ['full_admin', 'dashboard.monitoring'],        // [R] in API §6.5
            ['full_admin', 'review_criteria.view'],        // [R]
            ['monitoring_admin', 'review_criteria.view'],  // [R]
            ['full_admin', 'reviews.view_submitted'],      // [R] (R4/R5)
            ['full_admin', 'reviews.approve'],             // R5
            ['full_admin', 'hours.approved.correct'],      // W3 (IFC)
            ['monitoring_admin', 'hours.approved.void'],   // R9 (IFC)
            ['full_admin', 'periods.reopen'],              // W6 (IFC)
            ['full_admin', 'exports.format.csv'],          // A12
            ['monitoring_admin', 'exports.format.csv'],    // A12
            ['full_admin', 'audit.operational.view'],      // R10
            ['monitoring_admin', 'volunteers.browse'],     // R14
            ['monitoring_admin', 'certificates.view'],     // R15
            ['monitoring_admin', 'reports.hours.view'],    // R16
            ['full_admin', 'confidential.volunteer_notes.view'], // R6
            ['full_admin', 'reviews.create'],              // R4
            ['full_admin', 'settings.operational.edit'],   // S1
            ['full_admin', 'certificate_templates.create'], // S2
            ['technical_manager', 'retention.edit'],       // S4
        ];

        foreach ($absent as [$role, $code]) {
            self::assertSame(0, (int) $this->db()->scalar('SELECT COUNT(*) FROM role_permissions WHERE role_code = ? AND permission_code = ?', $role, $code), "{$role} must not hold {$code}");
        }

        foreach (['reviews.create', 'reviews.edit', 'reviews.submit', 'reviews.view_drafts', 'reviews.withdraw', 'reviews.history.view', 'exports.format.csv', 'periods.reopen', 'hours.approved.correct', 'hours.approved.void', 'audit.operational.view'] as $code) {
            self::assertSame(0, (int) $this->db()->scalar('SELECT COUNT(*) FROM role_permissions WHERE permission_code = ?', $code), "{$code} is held by no role");
        }
    }

    public function testMonitoringAdminKeepsTheApprovedExportCapability(): void
    {
        foreach (['exports.create', 'exports.format.xlsx', 'exports.format.pdf', 'dashboard.monitoring', 'reviews.approve', 'reviews.publish'] as $code) {
            self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM role_permissions WHERE role_code = 'monitoring_admin' AND permission_code = ?", $code), $code);
        }
    }

    public function testStatusCatalogueInactiveValuesAreExactlyTheInterimAndProposedOnes(): void
    {
        $inactive = array_column($this->db()->rows("SELECT CONCAT(domain, '.', code) AS v FROM status_catalogue WHERE active = 0"), 'v');
        sort($inactive);
        self::assertSame(
            ['hours.voided', 'hours.withdrawn', 'hours_decision.void', 'hours_revision_kind.staff_correction', 'period.cancelled', 'period_event.cancelled', 'period_event.reopened'],
            $inactive,
        );
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM status_catalogue WHERE basis IN ('interim', 'proposed') AND active = 1"));
    }

    public function testEveryCatalogueBackedDefaultExistsAndIsActive(): void
    {
        // Database V1.4 §5.0 register: table, column, domain, default.
        $register = [
            ['users', 'status', 'account', 'invited'], ['projects', 'status', 'project', 'planned'],
            ['operational_exceptions', 'status', 'exception', 'pending'], ['volunteers', 'status', 'volunteer', 'draft'],
            ['volunteering_periods', 'status', 'period', 'open'], ['volunteer_assignments', 'status', 'assignment', 'active'],
            ['tasks', 'status', 'task', 'not_started'], ['tasks', 'priority', 'task_priority', 'medium'],
            ['task_reports', 'status', 'task_report', 'draft'], ['hours_entries', 'status', 'hours', 'submitted'],
            ['performance_reviews', 'status', 'review', 'draft'], ['performance_review_versions', 'kind', 'review_version_kind', 'draft'],
            ['certificates', 'status', 'certificate', 'issued'], ['email_outbox', 'status', 'outbox', 'queued'],
            ['exports', 'status', 'export', 'ready'],
        ];

        foreach ($register as [$table, $column, $domain, $default]) {
            self::assertSame($default, $this->db()->scalar('SELECT TRIM(BOTH \'\\\'\' FROM COLUMN_DEFAULT) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', $this->db()->name, $table, $column), "{$table}.{$column} default");
            self::assertSame(1, (int) $this->db()->scalar('SELECT active FROM status_catalogue WHERE domain = ? AND code = ?', $domain, $default), "{$domain}.{$default}");
        }
    }

    public function testFieldCatalogueCoversPersonalAndConfidentialColumnsExceptPendingSensitiveOnes(): void
    {
        $db = $this->db();
        $catalogued = array_column($db->rows("SELECT CONCAT(table_name, '.', column_name) AS f FROM field_catalogue"), 'f');

        foreach (['volunteers.reference', 'volunteers.full_name', 'volunteers.preferred_name', 'volunteers.status', 'volunteers.email', 'volunteers.phone',
            'volunteer_notes.body', 'performance_review_internal_notes.note', 'task_report_decision_notes.note', 'hours_decision_notes.note'] as $field) {
            self::assertContains($field, $catalogued);
        }

        // Every sensitive column is deliberately uncatalogued (pending R6/D3) and so treated as sensitive.
        $sensitive = array_column($db->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'volunteer_sensitive_details' AND COLUMN_NAME NOT IN ('volunteer_id', 'created_at', 'created_by_user_id', 'updated_at', 'updated_by_user_id', 'version')", [$db->name]), 'COLUMN_NAME');
        self::assertCount(8, $sensitive);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM field_catalogue WHERE table_name = 'volunteer_sensitive_details' OR field_set = 'sensitive'"));

        self::assertSame(
            [['column_name' => 'email', 'access_code' => 'fields.contact.email', 'write_code' => 'fields.contact.email', 'classification_status' => 'approved'],
                ['column_name' => 'phone', 'access_code' => 'fields.contact.phone', 'write_code' => 'fields.contact.phone', 'classification_status' => 'approved']],
            $db->rows("SELECT column_name, access_code, write_code, classification_status FROM field_catalogue WHERE field_set = 'contact' ORDER BY column_name"),
        );
    }

    public function testReviewScaleAndCriteriaAreTheFrontendValues(): void
    {
        self::assertSame(
            ['Needs Significant Improvement', 'Needs Improvement', 'Meets Expectations', 'Good', 'Excellent'],
            array_column($this->db()->rows('SELECT label FROM review_rating_labels ORDER BY rating'), 'label'),
        );
        self::assertSame(
            ['Attendance and reliability', 'Communication', 'Teamwork', 'Task completion', 'Initiative'],
            array_column($this->db()->rows('SELECT name FROM review_criteria WHERE active = 1 ORDER BY sort_order'), 'name'),
        );
    }

    public function testPendingValuesAreEmptyAndNothingElseIsSeeded(): void
    {
        $db = $this->db();

        self::assertNull($db->scalar("SELECT value_json FROM settings WHERE setting_key = 'certificate.eligibility_threshold_minutes'"), 'W7 threshold stays empty, so issuance stays blocked.');
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM settings'));
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM retention_policies'), 'Retention stays empty until EHF approves (S4).');

        // No bootstrap owner or Technical Manager (RS4/R7 pending), and no records of any kind.
        foreach (['users', 'system_owner_memberships', 'owner_authorisations', 'user_roles', 'user_permission_grants', 'operational_exceptions',
            'volunteers', 'projects', 'certificates', 'audit_logs', 'files'] as $table) {
            self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM {$table}"), $table);
        }
    }

    private function db(): TestDatabase
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }
}
