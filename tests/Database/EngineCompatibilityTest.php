<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Database;

use Ehf\Api\Config\DatabaseSettings;
use Ehf\Api\Config\Environment;
use Ehf\Api\Infrastructure\Database\ConnectionFactory;
use Ehf\Api\Infrastructure\Database\EngineInfo;
use PHPUnit\Framework\TestCase;

/**
 * Decision A2: the same code must behave identically on MySQL 8.0.16+ and MariaDB 10.6+.
 *
 * Runs against a throwaway test server configured through DB_TEST_HOST, DB_TEST_PORT,
 * DB_TEST_NAME, DB_TEST_USER and DB_TEST_PASSWORD (CI runs it on MySQL 8.0 and 8.4 and on
 * MariaDB 10.6 and 11.4). Skipped when those are not set. Never point it at a real database.
 *
 * It creates only TEMPORARY tables, which disappear with the connection: no domain tables,
 * no migrations. It checks the engine behaviours the Database Architecture V1.4 relies on
 * (§2.2 compatibility, §2.6 NULL-safe CHECK rule).
 */
final class EngineCompatibilityTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $settings = DatabaseSettings::fromEnvironment(Environment::fromGlobals(), 'DB_TEST_');
        if ($settings === null) {
            self::markTestSkipped('DB_TEST_* not set: database compatibility tests need a throwaway MySQL or MariaDB server.');
        }

        $this->pdo = (new ConnectionFactory($settings))->create();
    }

    public function testEngineMeetsTheSupportedMinimum(): void
    {
        $info = EngineInfo::detect($this->pdo);

        self::assertTrue($info->meetsMinimum(), \sprintf('%s %s is below the supported minimum.', $info->engine, $info->version));
    }

    public function testSessionIsUtf8mb4UtcAndStrict(): void
    {
        $row = $this->statement('SELECT @@character_set_connection AS cs, @@collation_connection AS co, @@session.time_zone AS tz, @@session.sql_mode AS mode')->fetch();
        self::assertIsArray($row);

        self::assertSame('utf8mb4', $row['cs']);
        self::assertSame('utf8mb4_unicode_ci', $row['co']);
        self::assertSame('+00:00', $row['tz']);
        self::assertIsString($row['mode']);
        foreach (['STRICT_ALL_TABLES', 'ONLY_FULL_GROUP_BY', 'NO_ENGINE_SUBSTITUTION'] as $mode) {
            self::assertStringContainsString($mode, $row['mode']);
        }
    }

    public function testPreparedStatementsAreNative(): void
    {
        self::assertFalse((bool) $this->pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES)); // pdo_mysql reports 0

        $statement = $this->pdo->prepare('SELECT ? + 1 AS n');
        $statement->execute([41]);

        self::assertSame(42, (int) $statement->fetchColumn());
    }

    public function testCheckConstraintsAreEnforced(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_check (minutes INT NULL, CONSTRAINT ehf_tmp_minutes_positive CHECK (minutes > 0))');

        $this->pdo->exec('INSERT INTO ehf_tmp_check (minutes) VALUES (5)');
        $this->assertRefused('INSERT INTO ehf_tmp_check (minutes) VALUES (0)');
    }

    public function testCheckConstraintPassesOnNullAsDatabaseSection26Assumes(): void
    {
        // The premise of the NULL-safe CHECK rule: a CHECK that evaluates to NULL passes.
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_null (minutes INT NULL, CONSTRAINT ehf_tmp_null_positive CHECK (minutes > 0))');

        $this->pdo->exec('INSERT INTO ehf_tmp_null (minutes) VALUES (NULL)');
        self::assertSame(1, (int) $this->statement('SELECT COUNT(*) FROM ehf_tmp_null')->fetchColumn());
    }

    public function testAllOrNothingPairCheckIsEnforced(): void
    {
        // The §2.6 pattern for nullable composite keys: (a IS NULL) = (b IS NULL).
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_pair (a INT NULL, b INT NULL, CONSTRAINT ehf_tmp_pair_complete CHECK ((a IS NULL) = (b IS NULL)))');

        $this->pdo->exec('INSERT INTO ehf_tmp_pair (a, b) VALUES (NULL, NULL), (1, 2)');
        $this->assertRefused('INSERT INTO ehf_tmp_pair (a, b) VALUES (1, NULL)');
        $this->assertRefused('INSERT INTO ehf_tmp_pair (a, b) VALUES (NULL, 2)');
    }

    public function testJsonColumnsRejectInvalidJson(): void
    {
        // MySQL has a native JSON type; on MariaDB JSON is LONGTEXT with an automatic validity check.
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_json (doc JSON NULL)');

        $this->pdo->exec('INSERT INTO ehf_tmp_json (doc) VALUES (\'{"a":1}\')');
        $this->assertRefused('INSERT INTO ehf_tmp_json (doc) VALUES (\'{not json\')');
    }

    public function testMicrosecondTimestampsWithDefault(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_ts (id INT NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))');
        $this->pdo->exec('INSERT INTO ehf_tmp_ts (id) VALUES (1)');

        $value = $this->statement('SELECT created_at FROM ehf_tmp_ts')->fetchColumn();
        self::assertIsString($value);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $value);
    }

    public function testStrictModeRefusesTruncation(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_len (name VARCHAR(3) NOT NULL)');

        $this->assertRefused("INSERT INTO ehf_tmp_len (name) VALUES ('abcd')");
    }

    public function testUnicodeRoundTrip(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE ehf_tmp_text (name VARCHAR(20) NOT NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $statement = $this->pdo->prepare('INSERT INTO ehf_tmp_text (name) VALUES (?)');
        $statement->execute(['Zoë 👋 عربي']);

        self::assertSame('Zoë 👋 عربي', $this->statement('SELECT name FROM ehf_tmp_text')->fetchColumn());
    }

    private function statement(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }

    private function assertRefused(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail('The database accepted: ' . $sql);
    }
}
