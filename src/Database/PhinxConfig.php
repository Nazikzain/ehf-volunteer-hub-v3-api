<?php

declare(strict_types=1);

namespace Ehf\Api\Database;

use Ehf\Api\Config\DatabaseSettings;
use Ehf\Api\Infrastructure\Database\ConnectionFactory;

/**
 * Builds the Phinx configuration array. Used by phinx.php (command line) and by the database
 * tests, so both run migrations in exactly the same way.
 */
final class PhinxConfig
{
    public const string MIGRATION_TABLE = 'schema_migrations';

    private function __construct() {}

    /**
     * @param array<string, DatabaseSettings|null> $environments only configured environments are included
     *
     * @return array<string, mixed>
     */
    public static function build(string $projectRoot, array $environments): array
    {
        $configured = [
            'default_migration_table' => self::MIGRATION_TABLE,
        ];

        foreach ($environments as $name => $settings) {
            if ($settings === null) {
                continue;
            }

            $configured[$name] = self::environment($settings);
            $configured['default_environment'] ??= $name;
        }

        return [
            'paths' => [
                'migrations' => $projectRoot . '/database/migrations',
                'seeds' => $projectRoot . '/database/seeds',
            ],
            'environments' => $configured,
            'version_order' => 'creation',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function environment(DatabaseSettings $settings): array
    {
        return [
            'adapter' => 'mysql',
            'name' => $settings->name,
            'connection' => (new ConnectionFactory($settings))->create(),
            'charset' => ConnectionFactory::CHARSET,
            'collation' => ConnectionFactory::COLLATION,
            'migration_table' => self::MIGRATION_TABLE,
        ];
    }
}
