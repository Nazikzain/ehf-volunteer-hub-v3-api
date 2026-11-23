<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Database;

/**
 * Identifies the database engine and checks it against the supported minimums
 * (decision A2: MySQL 8.0.16+ or MariaDB 10.6+ until Hostinger's engine is confirmed).
 */
final readonly class EngineInfo
{
    public const string MYSQL = 'mysql';

    public const string MARIADB = 'mariadb';

    public const array MINIMUM_VERSIONS = [self::MYSQL => '8.0.16', self::MARIADB => '10.6.0'];

    public function __construct(
        public string $engine,
        public string $version,
        public string $rawVersion,
    ) {}

    public static function fromVersionString(string $raw): self
    {
        $engine = stripos($raw, 'mariadb') !== false ? self::MARIADB : self::MYSQL;

        // Some MariaDB builds report a "5.5.5-" compatibility prefix before the real version.
        $pattern = $engine === self::MARIADB ? '/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)/' : '/^(\d+\.\d+\.\d+)/';
        if (preg_match($pattern, $raw, $m) !== 1) {
            throw new \UnexpectedValueException('Unrecognised database version string.');
        }

        return new self($engine, $m[1], $raw);
    }

    public static function detect(\PDO $pdo): self
    {
        $statement = $pdo->query('SELECT VERSION()');
        $raw = $statement === false ? false : $statement->fetchColumn();
        if (!\is_string($raw)) {
            throw new \UnexpectedValueException('Could not read the database version.');
        }

        return self::fromVersionString($raw);
    }

    public function meetsMinimum(): bool
    {
        return version_compare($this->version, self::MINIMUM_VERSIONS[$this->engine], '>=');
    }
}
