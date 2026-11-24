<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Support;

use Ehf\Api\Config\DatabaseSettings;
use Ehf\Api\Config\Environment;
use Ehf\Api\Database\PhinxConfig;
use Ehf\Api\Infrastructure\Database\ConnectionFactory;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A disposable database on the throwaway test server (DB_TEST_*), created with a random name,
 * migrated with Phinx exactly as `phinx.php` does, and dropped afterwards.
 *
 * Never point DB_TEST_* at a real server: this class creates and drops databases.
 */
final class TestDatabase
{
    public const string PREFIX = 'ehf_test_';

    public readonly \PDO $pdo;

    public readonly string $name;

    private readonly \PDO $server;

    private readonly Manager $manager;

    private function __construct(private readonly DatabaseSettings $serverSettings)
    {
        $this->server = (new ConnectionFactory($serverSettings))->create();
        $this->name = self::PREFIX . bin2hex(random_bytes(6));
        $this->server->exec(\sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->name));

        $settings = new DatabaseSettings(
            $serverSettings->host,
            $serverSettings->port,
            $this->name,
            $serverSettings->user,
            $serverSettings->password(),
        );
        $this->pdo = (new ConnectionFactory($settings))->create();

        $config = PhinxConfig::build(\dirname(__DIR__, 2), ['test' => null]);
        $config['environments'] = [
            'default_migration_table' => PhinxConfig::MIGRATION_TABLE,
            'default_environment' => 'test',
            'test' => ['adapter' => 'mysql', 'name' => $this->name, 'connection' => $this->pdo, 'migration_table' => PhinxConfig::MIGRATION_TABLE],
        ];
        $this->manager = new Manager(new Config($config), new ArrayInput([]), new NullOutput());
    }

    public static function settings(): ?DatabaseSettings
    {
        return DatabaseSettings::fromEnvironment(Environment::fromGlobals(), 'DB_TEST_');
    }

    public static function create(): ?self
    {
        $settings = self::settings();

        return $settings === null ? null : new self($settings);
    }

    public function migrate(): void
    {
        $this->manager->migrate('test');
    }

    public function rollbackAll(): void
    {
        $this->manager->rollback('test', 0);
    }

    public function rollbackOne(): void
    {
        $this->manager->rollback('test');
    }

    public function drop(): void
    {
        $this->server->exec(\sprintf('DROP DATABASE IF EXISTS `%s`', $this->name));
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        $statement = $this->pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
        $statement->execute([$this->name]);

        /** @var list<string> $tables */
        $tables = $statement->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables); // engine-independent order (MariaDB and MySQL collate '_' differently)

        return $tables;
    }

    /**
     * @return list<int>
     */
    public function appliedVersions(): array
    {
        $statement = $this->pdo->query('SELECT version FROM ' . PhinxConfig::MIGRATION_TABLE . ' ORDER BY version');
        if ($statement === false) {
            return [];
        }

        $versions = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $version) {
            $versions[] = (int) (\is_scalar($version) ? $version : 0);
        }

        return $versions;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, string|int|float|null>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, string|int|float|null>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    public function scalar(string $sql, mixed ...$params): string|int|null
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }
}
