<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Database;

use Ehf\Api\Database\PhinxConfig;
use Ehf\Api\Tests\Support\SchemaSnapshot;
use Ehf\Api\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Builds the schema from an empty database, rolls it back and rebuilds it (Phase 3 tasks 7–8).
 * Runs on every engine in CI (MySQL 8.0 / 8.4, MariaDB 10.6 / 11.4).
 *
 * The committed snapshot tests/Database/fixtures/schema.json is the reviewed shape of the schema.
 * Any change to a migration that alters the schema fails this test until the snapshot is
 * regenerated (UPDATE_SCHEMA_SNAPSHOT=1) and the diff reviewed. Because the same snapshot is
 * compared on every engine, it also proves the engines build identical schemas.
 */
final class MigrationLifecycleTest extends TestCase
{
    /** Database V1.4 §4: 56 tables from the data dictionary + the migration log = 57. */
    public const array EXPECTED_TABLES = [
        'status_catalogue', 'roles', 'permissions', 'role_permissions', 'field_catalogue',
        'users', 'system_owner_memberships', 'owner_authorisations', 'projects',
        'user_roles', 'user_permission_grants', 'operational_exceptions',
        'auth_tokens', 'sessions', 'rate_limit_counters', 'idempotency_keys', 'files',
        'volunteers', 'volunteer_sensitive_details', 'volunteer_availability_days', 'volunteer_status_history', 'volunteer_notes',
        'volunteering_periods', 'volunteering_period_history',
        'project_activities', 'project_staff', 'volunteer_assignments',
        'tasks', 'task_assignees', 'task_events',
        'task_reports', 'task_report_versions', 'task_report_attachments', 'task_report_decisions', 'task_report_decision_notes',
        'hours_entries', 'hours_entry_revisions', 'hours_decisions', 'hours_decision_notes',
        'review_criteria', 'review_rating_labels', 'performance_reviews', 'performance_review_versions',
        'performance_review_ratings', 'performance_review_internal_notes', 'performance_review_events',
        'certificate_templates', 'certificates', 'certificate_hours', 'certificate_events',
        'notifications', 'email_outbox', 'exports',
        'settings', 'retention_policies', 'audit_logs',
        PhinxConfig::MIGRATION_TABLE,
    ];

    private const string SNAPSHOT = __DIR__ . '/fixtures/schema.json';

    private const int MIGRATION_COUNT = 20;

    private ?TestDatabase $db = null;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create();
        if ($this->db === null) {
            self::markTestSkipped('DB_TEST_* not set: migration tests need a throwaway MySQL or MariaDB server.');
        }
    }

    protected function tearDown(): void
    {
        $this->db?->drop();
    }

    public function testFreshMigrationCreatesExactlyThe57SpecifiedTables(): void
    {
        $db = $this->db();
        self::assertSame([], $db->tables(), 'The test database must start empty.');

        $db->migrate();

        $expected = self::EXPECTED_TABLES;
        sort($expected);
        self::assertCount(57, $expected);
        self::assertSame($expected, $db->tables());
        self::assertCount(self::MIGRATION_COUNT, $db->appliedVersions());
    }

    public function testEveryTableIsInnoDbUtf8mb4AndEveryForeignKeyRestricts(): void
    {
        $db = $this->db();
        $db->migrate();

        foreach (SchemaSnapshot::capture($db) as $table => $definition) {
            self::assertSame('InnoDB', $definition['engine'], $table);
            if ($table !== PhinxConfig::MIGRATION_TABLE) {
                self::assertSame('utf8mb4_unicode_ci', $definition['collation'], $table);
            }

            foreach ($definition['foreign_keys'] as $name => $fk) {
                self::assertSame('RESTRICT/RESTRICT', $fk['rules'], "{$table}.{$name}");
            }
        }
    }

    public function testMigrateAgainChangesNothing(): void
    {
        $db = $this->db();
        $db->migrate();
        $before = SchemaSnapshot::capture($db);

        $db->migrate();

        self::assertCount(self::MIGRATION_COUNT, $db->appliedVersions());
        self::assertSame($before, SchemaSnapshot::capture($db));
    }

    public function testFullRollbackThenRemigrateRebuildsTheIdenticalSchema(): void
    {
        $db = $this->db();
        $db->migrate();
        $built = SchemaSnapshot::capture($db);
        $referenceRows = $this->referenceRowCount($db);

        $db->rollbackAll();
        self::assertSame([PhinxConfig::MIGRATION_TABLE], $db->tables(), 'Only the migration log remains after a full rollback.');
        self::assertSame([], $db->appliedVersions());

        $db->migrate();
        self::assertSame($built, SchemaSnapshot::capture($db));
        self::assertSame($referenceRows, $this->referenceRowCount($db));
    }

    public function testEachMigrationRollsBackOneAtATime(): void
    {
        $db = $this->db();
        $db->migrate();

        $previousTables = \count($db->tables());
        for ($remaining = self::MIGRATION_COUNT; $remaining > 0; --$remaining) {
            $db->rollbackOne();
            self::assertCount($remaining - 1, $db->appliedVersions());
            $tables = \count($db->tables());
            self::assertLessThanOrEqual($previousTables, $tables);
            $previousTables = $tables;
        }

        self::assertSame([PhinxConfig::MIGRATION_TABLE], $db->tables());
    }

    public function testSchemaMatchesTheCommittedSnapshot(): void
    {
        $db = $this->db();
        $db->migrate();
        $actual = SchemaSnapshot::capture($db);
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        if (getenv('UPDATE_SCHEMA_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, $json);
            self::markTestIncomplete('Schema snapshot written; review the diff and commit it.');
        }

        self::assertFileExists(self::SNAPSHOT);
        self::assertSame((string) file_get_contents(self::SNAPSHOT), $json, 'The schema differs from tests/Database/fixtures/schema.json.');
    }

    private function db(): TestDatabase
    {
        self::assertNotNull($this->db);

        return $this->db;
    }

    private function referenceRowCount(TestDatabase $db): int
    {
        $total = 0;
        foreach (['status_catalogue', 'roles', 'permissions', 'role_permissions', 'field_catalogue', 'review_rating_labels', 'review_criteria', 'settings'] as $table) {
            $total += (int) $db->scalar("SELECT COUNT(*) FROM {$table}");
        }

        return $total;
    }
}
