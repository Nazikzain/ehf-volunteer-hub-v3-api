<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M13 — task_reports and their versions, attachments, decisions and confidential decision notes
 * (Database V1.4 §5.7). Drafts are private to the volunteer; submitted versions are frozen (P4);
 * every decision is bound to the version decided.
 *
 * task_report_versions holds the report fields of API §7.8 as frozen at submission. A title is
 * required at submission (API §7.8 `title (1–200)`); the other fields keep the draft's optionality.
 */
final class CreateTaskReports extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('task_reports', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('task_reports', 'status', 'task_report', 'draft')},
            current_version_no INT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(200) NULL,
            work_summary TEXT NULL,
            activities TEXT NULL,
            results TEXT NULL,
            challenges TEXT NULL,
            comments VARCHAR(2000) NULL,
            progress_percent TINYINT UNSIGNED NULL,
            reported_minutes INT UNSIGNED NULL,
            {$this->std('task_reports')},
            CONSTRAINT pk_task_reports PRIMARY KEY (id),
            CONSTRAINT fk_task_reports_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_reports_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_task_reports_progress CHECK (progress_percent IS NULL OR progress_percent <= 100),
            INDEX ix_task_reports_task (task_id),
            INDEX ix_task_reports_volunteer_status (volunteer_id, status),
            INDEX ix_task_reports_status (status)
            SQL);

        $this->createTable('task_report_versions', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_report_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            work_summary TEXT NULL,
            activities TEXT NULL,
            results TEXT NULL,
            challenges TEXT NULL,
            comments VARCHAR(2000) NULL,
            progress_percent TINYINT UNSIGNED NULL,
            reported_minutes INT UNSIGNED NULL,
            submitted_by_user_id BIGINT UNSIGNED NOT NULL,
            submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_task_report_versions PRIMARY KEY (id),
            CONSTRAINT uq_task_report_versions_number UNIQUE (task_report_id, version_no),
            CONSTRAINT uq_task_report_versions_id_report UNIQUE (id, task_report_id),
            CONSTRAINT fk_task_report_versions_report FOREIGN KEY (task_report_id) REFERENCES task_reports (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_report_versions_submitted_by FOREIGN KEY (submitted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_task_report_versions_progress CHECK (progress_percent IS NULL OR progress_percent <= 100)
            SQL);

        $this->createTable('task_report_attachments', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_report_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NULL,
            file_id BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            removed_at DATETIME(6) NULL,
            CONSTRAINT pk_task_report_attachments PRIMARY KEY (id),
            CONSTRAINT fk_task_report_attachments_report FOREIGN KEY (task_report_id) REFERENCES task_reports (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_report_attachments_file FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_report_attachments_version FOREIGN KEY (version_id, task_report_id) REFERENCES task_report_versions (id, task_report_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_task_report_attachments_frozen CHECK (version_id IS NULL OR removed_at IS NULL)
            SQL);

        $this->createTable('task_report_decisions', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_report_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('task_report_decisions', 'decision', 'task_report_decision')},
            feedback TEXT NULL,
            decided_by_user_id BIGINT UNSIGNED NOT NULL,
            decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_task_report_decisions PRIMARY KEY (id),
            CONSTRAINT fk_task_report_decisions_version FOREIGN KEY (version_id, task_report_id) REFERENCES task_report_versions (id, task_report_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_report_decisions_decided_by FOREIGN KEY (decided_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_task_report_decisions_feedback CHECK (decision <> 'request_correction' OR feedback IS NOT NULL),
            INDEX ix_task_report_decisions_report_decided (task_report_id, decided_at)
            SQL);

        $this->createTable('task_report_decision_notes', <<<SQL
            decision_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            {$this->cre('task_report_decision_notes')},
            CONSTRAINT pk_task_report_decision_notes PRIMARY KEY (decision_id),
            CONSTRAINT fk_task_report_decision_notes_decision FOREIGN KEY (decision_id) REFERENCES task_report_decisions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('task_report_decision_notes', 'task_report_decisions', 'task_report_attachments', 'task_report_versions', 'task_reports');
    }
}
