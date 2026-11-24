<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M03 — users (Database V1.4 §5.2.1). Account status only; never the volunteer's operational
 * status [A]. created_by / updated_by are self-references (cycle C1, §6.2).
 */
final class CreateUsers extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('users', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(254) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            password_hash VARCHAR(255) NULL,
            {$this->catalogued('users', 'status', 'account', 'invited')},
            status_reason VARCHAR(500) NULL,
            session_version INT UNSIGNED NOT NULL DEFAULT 1,
            last_login_at DATETIME(6) NULL,
            email_verified_at DATETIME(6) NULL,
            {$this->std('users')},
            CONSTRAINT pk_users PRIMARY KEY (id),
            CONSTRAINT uq_users_email UNIQUE (email),
            INDEX ix_users_status (status)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('users');
    }
}
