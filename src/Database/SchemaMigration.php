<?php

declare(strict_types=1);

namespace Ehf\Api\Database;

use Phinx\Migration\AbstractMigration;

/**
 * Base class for the V3 schema migrations (MySQL Database Architecture V1.4).
 *
 * Every table is InnoDB, utf8mb4 / utf8mb4_unicode_ci (§2.2). Every foreign key is
 * ON DELETE RESTRICT ON UPDATE RESTRICT (§2.3, §6.1). Constraints are named explicitly
 * (`pk_`, `uq_`, `ix_`, `fk_`, `ck_` + table) so that schema tests and error messages are stable on
 * both engines.
 *
 * The helpers below are part of released migrations: never change what an existing helper
 * produces. Add a new helper instead.
 */
abstract class SchemaMigration extends AbstractMigration
{
    public const string TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    protected function createTable(string $table, string $definition): void
    {
        $this->execute(\sprintf("CREATE TABLE `%s` (\n%s\n) %s", $table, trim($definition), self::TABLE_OPTIONS));
    }

    protected function dropTables(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->execute(\sprintf('DROP TABLE `%s`', $table));
        }
    }

    /**
     * STD columns (§2.3): created/updated timestamps and users, and `version` for lost-update
     * protection (API §4.1).
     */
    protected function std(string $table): string
    {
        return <<<SQL
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            created_by_user_id BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NULL,
            updated_by_user_id BIGINT UNSIGNED NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            CONSTRAINT fk_{$table}_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_{$table}_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL;
    }

    /**
     * CRE columns (§2.3): creation timestamp and user only (immutable rows).
     */
    protected function cre(string $table): string
    {
        return <<<SQL
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            created_by_user_id BIGINT UNSIGNED NULL,
            CONSTRAINT fk_{$table}_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL;
    }

    /**
     * Pattern P1 (§2.4): a catalogue-backed column with its `_domain` companion, the pinning CHECK
     * and the composite foreign key to status_catalogue(domain, code).
     */
    protected function catalogued(string $table, string $column, string $domain, ?string $default = null, ?string $domainColumn = null): string
    {
        $defaultSql = $default === null ? '' : " DEFAULT '{$default}'";
        $domainColumn ??= $column . '_domain';

        return <<<SQL
            {$column} VARCHAR(40) NOT NULL{$defaultSql},
            {$domainColumn} VARCHAR(40) NOT NULL DEFAULT '{$domain}',
            CONSTRAINT ck_{$table}_{$domainColumn} CHECK ({$domainColumn} = '{$domain}'),
            CONSTRAINT fk_{$table}_{$column} FOREIGN KEY ({$domainColumn}, {$column}) REFERENCES status_catalogue (domain, code) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL;
    }

    /**
     * A single-column foreign key with the project-wide RESTRICT rule.
     */
    protected function fk(string $name, string $columns, string $target): string
    {
        return "CONSTRAINT {$name} FOREIGN KEY ({$columns}) REFERENCES {$target} ON DELETE RESTRICT ON UPDATE RESTRICT";
    }
}
