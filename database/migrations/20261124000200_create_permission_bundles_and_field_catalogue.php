<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M02 — role_permissions, field_catalogue (Database V1.4 §5.1.5–5.1.6).
 */
final class CreatePermissionBundlesAndFieldCatalogue extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('role_permissions', <<<'SQL'
            role_code VARCHAR(40) NOT NULL,
            permission_code VARCHAR(100) NOT NULL,
            CONSTRAINT pk_role_permissions PRIMARY KEY (role_code, permission_code),
            CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_code) REFERENCES roles (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_code) REFERENCES permissions (code) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('field_catalogue', <<<'SQL'
            table_name VARCHAR(64) NOT NULL,
            column_name VARCHAR(64) NOT NULL,
            field_set VARCHAR(40) NOT NULL,
            access_code VARCHAR(100) NULL,
            write_code VARCHAR(100) NULL,
            classification_status VARCHAR(12) NOT NULL DEFAULT 'interim',
            description VARCHAR(255) NOT NULL,
            CONSTRAINT pk_field_catalogue PRIMARY KEY (table_name, column_name),
            CONSTRAINT fk_field_catalogue_access FOREIGN KEY (access_code) REFERENCES permissions (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_field_catalogue_write FOREIGN KEY (write_code) REFERENCES permissions (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_field_catalogue_field_set CHECK (field_set IN ('basic', 'contact', 'sensitive', 'confidential.volunteer_notes', 'confidential.review_notes', 'confidential.decision_notes')),
            CONSTRAINT ck_field_catalogue_classification CHECK (classification_status IN ('interim', 'approved')),
            CONSTRAINT ck_field_catalogue_codes CHECK ((field_set = 'basic' AND access_code IS NULL AND write_code IS NULL) OR (field_set <> 'basic' AND access_code IS NOT NULL AND write_code IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('field_catalogue', 'role_permissions');
    }
}
