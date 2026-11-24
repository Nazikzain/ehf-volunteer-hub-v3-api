<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M17 — notifications, email_outbox, exports (Database V1.4 §5.11).
 * The export format CHECK allows only the approved formats xlsx and pdf; CSV stays excluded until
 * A12 is decided.
 */
final class CreateNotificationsEmailExports extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('notifications', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(60) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body VARCHAR(1000) NOT NULL,
            link_route VARCHAR(200) NOT NULL,
            link_params JSON NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            read_at DATETIME(6) NULL,
            CONSTRAINT pk_notifications PRIMARY KEY (id),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            INDEX ix_notifications_user_read_created (user_id, read_at, created_at)
            SQL);

        $this->createTable('email_outbox', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            to_user_id BIGINT UNSIGNED NULL,
            to_email VARCHAR(254) NOT NULL,
            template VARCHAR(80) NOT NULL,
            payload JSON NOT NULL,
            {$this->catalogued('email_outbox', 'status', 'outbox', 'queued')},
            attempts SMALLINT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            next_attempt_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            sent_at DATETIME(6) NULL,
            CONSTRAINT pk_email_outbox PRIMARY KEY (id),
            CONSTRAINT fk_email_outbox_user FOREIGN KEY (to_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            INDEX ix_email_outbox_status_next (status, next_attempt_at)
            SQL);

        $this->createTable('exports', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requested_by_user_id BIGINT UNSIGNED NOT NULL,
            report_type VARCHAR(60) NOT NULL,
            format VARCHAR(10) NOT NULL,
            filters JSON NOT NULL,
            fields JSON NOT NULL,
            row_count INT UNSIGNED NOT NULL,
            file_id BIGINT UNSIGNED NULL,
            {$this->catalogued('exports', 'status', 'export', 'ready')},
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            expires_at DATETIME(6) NOT NULL,
            downloaded_at DATETIME(6) NULL,
            deleted_at DATETIME(6) NULL,
            CONSTRAINT pk_exports PRIMARY KEY (id),
            CONSTRAINT fk_exports_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_exports_file FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_exports_format CHECK (format IN ('xlsx', 'pdf')),
            INDEX ix_exports_requested_created (requested_by_user_id, created_at)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('exports', 'email_outbox', 'notifications');
    }
}
