<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Database;

use Ehf\Api\Config\ConfigurationException;
use Ehf\Api\Config\DatabaseSettings;

/**
 * Creates PDO connections that behave the same on MySQL 8.0.16+ and MariaDB 10.6+
 * (decision A2; Database V1.4 §2.2–2.3).
 *
 * - Real prepared statements only (no emulation), exceptions on error (API §15.1).
 * - utf8mb4 / utf8mb4_unicode_ci on the connection, matching every table.
 * - UTC session time zone (all timestamps are stored in UTC).
 * - Strict SQL mode, so invalid data is refused instead of silently adjusted.
 *
 * Phase 2 opens no connection: nothing calls this factory yet. Repositories use it from Phase 3.
 */
final readonly class ConnectionFactory
{
    public const string CHARSET = 'utf8mb4';

    public const string COLLATION = 'utf8mb4_unicode_ci';

    /** The MySQL 8 default modes, with strict mode applied to all tables. Supported by both engines. */
    public const string SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    public function __construct(private ?DatabaseSettings $settings) {}

    public function create(): \PDO
    {
        if ($this->settings === null) {
            throw new ConfigurationException('Database settings (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD) are not configured.');
        }

        $dsn = \sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->settings->host,
            $this->settings->port,
            $this->settings->name,
            self::CHARSET,
        );

        $pdo = new \PDO($dsn, $this->settings->user, $this->settings->password(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $pdo->exec(\sprintf('SET NAMES %s COLLATE %s', self::CHARSET, self::COLLATION));
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec(\sprintf("SET SESSION sql_mode = '%s'", self::SQL_MODE));

        return $pdo;
    }
}
