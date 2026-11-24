<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M01 — status_catalogue, roles, permissions (Database V1.4 §5.1.1–5.1.3).
 */
final class CreateReferenceCatalogues extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('status_catalogue', <<<'SQL'
            domain VARCHAR(40) NOT NULL,
            code VARCHAR(40) NOT NULL,
            label VARCHAR(80) NOT NULL,
            is_initial TINYINT(1) NOT NULL DEFAULT 0,
            is_terminal TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            basis VARCHAR(12) NOT NULL DEFAULT 'recommended',
            sort_order SMALLINT NOT NULL DEFAULT 0,
            CONSTRAINT pk_status_catalogue PRIMARY KEY (domain, code),
            CONSTRAINT ck_status_catalogue_basis CHECK (basis IN ('approved', 'recommended', 'interim', 'proposed'))
            SQL);

        $this->createTable('roles', <<<'SQL'
            code VARCHAR(40) NOT NULL,
            name VARCHAR(80) NOT NULL,
            is_ordinary TINYINT(1) NOT NULL DEFAULT 1,
            CONSTRAINT pk_roles PRIMARY KEY (code),
            CONSTRAINT ck_roles_is_ordinary CHECK ((code = 'technical_manager' AND is_ordinary = 0) OR (code <> 'technical_manager' AND is_ordinary = 1))
            SQL);

        $this->createTable('permissions', <<<'SQL'
            code VARCHAR(100) NOT NULL,
            category VARCHAR(20) NOT NULL,
            action_type VARCHAR(20) NOT NULL,
            is_field_code TINYINT(1) NOT NULL DEFAULT 0,
            exception_eligible TINYINT(1) NOT NULL DEFAULT 0,
            grantable TINYINT(1) NOT NULL DEFAULT 1,
            basis VARCHAR(12) NOT NULL DEFAULT 'approved',
            description VARCHAR(255) NOT NULL,
            CONSTRAINT pk_permissions PRIMARY KEY (code),
            CONSTRAINT uq_permissions_code_category UNIQUE (code, category),
            CONSTRAINT ck_permissions_category CHECK (category IN ('self', 'operational', 'technical', 'governance')),
            CONSTRAINT ck_permissions_action_type CHECK (action_type IN ('view', 'create', 'edit', 'submit', 'decide', 'publish', 'manage', 'issue', 'revoke', 'replace', 'deactivate')),
            CONSTRAINT ck_permissions_exception_eligible CHECK (exception_eligible = 0 OR category = 'operational'),
            CONSTRAINT ck_permissions_grantable CHECK (category <> 'governance' OR grantable = 0),
            CONSTRAINT ck_permissions_basis CHECK (basis IN ('approved', 'proposed'))
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('permissions', 'roles', 'status_catalogue');
    }
}
