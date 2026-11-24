<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M08 — files (Database V1.4 §5.2.9). Private storage keys only; no public URL column [A].
 */
final class CreateFiles extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('files', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            storage_key CHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_detected VARCHAR(100) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            purpose VARCHAR(30) NOT NULL,
            uploaded_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            deleted_at DATETIME(6) NULL,
            CONSTRAINT pk_files PRIMARY KEY (id),
            CONSTRAINT uq_files_storage_key UNIQUE (storage_key),
            CONSTRAINT fk_files_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_files_purpose CHECK (purpose IN ('report_attachment', 'certificate_pdf', 'template_background', 'template_signature', 'export'))
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('files');
    }
}
