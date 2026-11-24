<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Support;

/**
 * An engine-neutral description of a schema: tables, columns, indexes, foreign keys and named
 * CHECK constraints, read from information_schema and normalised so that MySQL and MariaDB
 * produce the same result for the same migrations.
 *
 * Normalisation:
 * - MariaDB stores JSON as LONGTEXT plus an automatic json_valid() CHECK named after the column;
 *   such columns are reported as `json` and the automatic CHECK is dropped.
 * - Defaults: quotes removed, NULL/'NULL' → null, CURRENT_TIMESTAMP lower-cased.
 * - Names are sorted in PHP (the engines collate `_` differently).
 * - CHECK clauses are not compared (each engine rewrites them); their names are. Their behaviour
 *   is covered by the integrity tests.
 */
final class SchemaSnapshot
{
    private function __construct() {}

    /**
     * @return array<string, array{engine: string, collation: string, columns: array<string, array{type: string, nullable: bool, default: ?string, auto_increment: bool}>, indexes: array<string, array{unique: bool, columns: list<string>}>, foreign_keys: array<string, array{columns: list<string>, references: string, referenced_columns: list<string>, rules: ?string}>, checks: list<string>}>
     */
    public static function capture(TestDatabase $db): array
    {
        $schema = $db->name;

        $checks = [];
        $jsonColumns = [];
        foreach (self::checks($db) as $check) {
            $table = self::text($check['TABLE_NAME']);
            $name = self::text($check['CONSTRAINT_NAME']);
            if (!str_starts_with($name, 'ck_') && stripos(self::text($check['CHECK_CLAUSE']), 'json_valid') !== false) {
                $jsonColumns[$table][$name] = true; // MariaDB's automatic JSON check

                continue;
            }
            $checks[$table][] = $name;
        }

        $columns = [];
        foreach ($db->rows('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$schema]) as $c) {
            $table = self::text($c['TABLE_NAME']);
            $column = self::text($c['COLUMN_NAME']);
            $isJson = isset($jsonColumns[$table][$column]) || $c['DATA_TYPE'] === 'json';
            $type = $isJson ? 'json' : strtolower(self::text($c['DATA_TYPE']));
            if (str_contains(strtolower(self::text($c['COLUMN_TYPE'])), 'unsigned')) {
                $type .= ' unsigned';
            }
            if (!$isJson && $c['CHARACTER_MAXIMUM_LENGTH'] !== null && \in_array($type, ['varchar', 'char'], true)) {
                $type .= '(' . self::text($c['CHARACTER_MAXIMUM_LENGTH']) . ')';
            }
            if ($c['DATETIME_PRECISION'] !== null && \in_array($type, ['datetime', 'time'], true)) {
                $type .= '(' . self::text($c['DATETIME_PRECISION']) . ')';
            }

            $columns[$table][$column] = [
                'type' => $type,
                'nullable' => $c['IS_NULLABLE'] === 'YES',
                'default' => self::normaliseDefault($c['COLUMN_DEFAULT']),
                'auto_increment' => str_contains(strtolower(self::text($c['EXTRA'])), 'auto_increment'),
            ];
        }

        $indexes = [];
        foreach ($db->rows('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$schema]) as $i) {
            $table = self::text($i['TABLE_NAME']);
            $name = self::text($i['INDEX_NAME']);
            $indexes[$table][$name]['unique'] = (int) $i['NON_UNIQUE'] === 0;
            $indexes[$table][$name]['columns'][] = self::text($i['COLUMN_NAME']);
        }

        $rules = [];
        foreach ($db->rows('SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?', [$schema]) as $r) {
            $rules[self::text($r['CONSTRAINT_NAME'])] = self::text($r['DELETE_RULE']) . '/' . self::text($r['UPDATE_RULE']);
        }

        $foreignKeys = [];
        foreach ($db->rows('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', [$schema]) as $k) {
            $table = self::text($k['TABLE_NAME']);
            $name = self::text($k['CONSTRAINT_NAME']);
            $foreignKeys[$table][$name]['references'] = self::text($k['REFERENCED_TABLE_NAME']);
            $foreignKeys[$table][$name]['rules'] = $rules[$name] ?? null;
            $foreignKeys[$table][$name]['columns'][] = self::text($k['COLUMN_NAME']);
            $foreignKeys[$table][$name]['referenced_columns'][] = self::text($k['REFERENCED_COLUMN_NAME']);
        }

        $tables = [];
        foreach ($db->rows('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$schema]) as $t) {
            $table = self::text($t['TABLE_NAME']);
            $tableIndexes = $indexes[$table] ?? [];
            ksort($tableIndexes, SORT_STRING);
            $tableForeignKeys = [];
            foreach ($foreignKeys[$table] ?? [] as $name => $fk) {
                $tableForeignKeys[$name] = ['columns' => $fk['columns'], 'references' => $fk['references'], 'referenced_columns' => $fk['referenced_columns'], 'rules' => $fk['rules']];
            }
            ksort($tableForeignKeys, SORT_STRING);
            $tableChecks = $checks[$table] ?? [];
            sort($tableChecks, SORT_STRING);

            $tables[$table] = [
                'engine' => self::text($t['ENGINE']),
                'collation' => self::text($t['TABLE_COLLATION']),
                'columns' => $columns[$table] ?? [],
                'indexes' => $tableIndexes,
                'foreign_keys' => $tableForeignKeys,
                'checks' => $tableChecks,
            ];
        }
        ksort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * @return list<array<string, string|int|float|null>>
     */
    private static function checks(TestDatabase $db): array
    {
        return $db->rows(
            'SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
             FROM information_schema.TABLE_CONSTRAINTS tc
             JOIN information_schema.CHECK_CONSTRAINTS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.CONSTRAINT_TYPE = \'CHECK\'',
            [$db->name],
        );
    }

    private static function text(string|int|float|null $value): string
    {
        return (string) $value;
    }

    private static function normaliseDefault(string|int|float|null $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $value = (string) $default;
        if (strtoupper($value) === 'NULL') {
            return null;
        }

        if (\strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        return preg_match('/^current_timestamp(\(\d\))?$/i', $value) === 1 ? strtolower($value) : $value;
    }
}
