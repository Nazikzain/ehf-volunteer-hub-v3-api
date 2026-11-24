<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Database;

/**
 * Typed access to database rows.
 */
final class Row
{
    private function __construct() {}

    /**
     * @param array<string, scalar|null> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if ($value === null || !is_numeric($value)) {
            throw new \UnexpectedValueException("Column {$column} is not an integer.");
        }

        return (int) $value;
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function intOrNull(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            throw new \UnexpectedValueException("Column {$column} is null.");
        }

        return (string) $value;
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function stringOrNull(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Database timestamp (UTC) → ISO 8601 with Z, as the API returns timestamps (API §4.1).
     *
     * @param array<string, scalar|null> $row
     */
    public static function isoOrNull(array $row, string $column): ?string
    {
        $value = self::stringOrNull($row, $column);
        if ($value === null) {
            return null;
        }

        return self::time($value)->format('Y-m-d\TH:i:s\Z');
    }

    public static function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
