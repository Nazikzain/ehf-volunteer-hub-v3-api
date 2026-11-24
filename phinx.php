<?php

declare(strict_types=1);

/*
 * Phinx configuration (decision A1). Run from the command line only, never from the web.
 *
 *   vendor/bin/phinx migrate -e target      apply all pending migrations
 *   vendor/bin/phinx status  -e target      list applied and pending migrations
 *   vendor/bin/phinx rollback -e target     undo the last migration (see docs/database.md first)
 *
 * Environments:
 *   target  The database being built (staging or production). Uses the separate migration user
 *           (API V1.3.1 §15.2): MIGRATION_DB_HOST, MIGRATION_DB_PORT, MIGRATION_DB_NAME,
 *           MIGRATION_DB_USER, MIGRATION_DB_PASSWORD — from the private .env or the process
 *           environment, never from Git.
 *   test    A throwaway test database: DB_TEST_HOST, DB_TEST_PORT, DB_TEST_NAME, DB_TEST_USER,
 *           DB_TEST_PASSWORD.
 *
 * Connections are created by ConnectionFactory, so migrations run with the same session settings
 * as the application: utf8mb4_unicode_ci, UTC, strict SQL mode, native prepared statements.
 * The migration log table is `schema_migrations` (Database V1.4 §4, table 57).
 */

use Ehf\Api\Config\DatabaseSettings;
use Ehf\Api\Config\Environment;
use Ehf\Api\Database\PhinxConfig;

require __DIR__ . '/vendor/autoload.php';

$env = Environment::load(__DIR__);

return PhinxConfig::build(__DIR__, [
    'target' => DatabaseSettings::fromEnvironment($env, 'MIGRATION_DB_'),
    'test' => DatabaseSettings::fromEnvironment($env, 'DB_TEST_'),
]);
