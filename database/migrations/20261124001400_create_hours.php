<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M14 — hours_entries, hours_entry_revisions, hours_decisions, hours_decision_notes
 * (Database V1.4 §5.8). Integer minutes > 0 [A]; every decision is bound to the exact revision
 * decided; a task, when given, belongs to the entry's project (V1.4). No maximum duration (W3).
 */
final class CreateHours extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('hours_entries', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            task_id BIGINT UNSIGNED NULL,
            activity_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            crosses_midnight TINYINT(1) NULL,
            duration_minutes INT UNSIGNED NOT NULL,
            description VARCHAR(2000) NOT NULL,
            location VARCHAR(200) NULL,
            {$this->catalogued('hours_entries', 'status', 'hours', 'submitted')},
            current_revision_no INT UNSIGNED NOT NULL DEFAULT 1,
            {$this->std('hours_entries')},
            CONSTRAINT pk_hours_entries PRIMARY KEY (id),
            CONSTRAINT uq_hours_entries_id_volunteer UNIQUE (id, volunteer_id),
            CONSTRAINT fk_hours_entries_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_hours_entries_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_hours_entries_task FOREIGN KEY (task_id, project_id) REFERENCES tasks (id, project_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_hours_entries_duration CHECK (duration_minutes > 0),
            INDEX ix_hours_entries_volunteer_status_date (volunteer_id, status, activity_date),
            INDEX ix_hours_entries_status_date (status, activity_date),
            INDEX ix_hours_entries_project_date (project_id, activity_date)
            SQL);

        $this->createTable('hours_entry_revisions', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hours_entry_id BIGINT UNSIGNED NOT NULL,
            revision_no INT UNSIGNED NOT NULL,
            {$this->catalogued('hours_entry_revisions', 'kind', 'hours_revision_kind')},
            project_id BIGINT UNSIGNED NOT NULL,
            task_id BIGINT UNSIGNED NULL,
            activity_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            crosses_midnight TINYINT(1) NULL,
            duration_minutes INT UNSIGNED NOT NULL,
            description VARCHAR(2000) NOT NULL,
            location VARCHAR(200) NULL,
            reason VARCHAR(1000) NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_hours_entry_revisions PRIMARY KEY (id),
            CONSTRAINT uq_hours_entry_revisions_number UNIQUE (hours_entry_id, revision_no),
            CONSTRAINT uq_hours_entry_revisions_snapshot UNIQUE (id, hours_entry_id, revision_no, duration_minutes, activity_date, project_id),
            CONSTRAINT fk_hours_entry_revisions_entry FOREIGN KEY (hours_entry_id) REFERENCES hours_entries (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_hours_entry_revisions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_hours_entry_revisions_task FOREIGN KEY (task_id, project_id) REFERENCES tasks (id, project_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_hours_entry_revisions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_hours_entry_revisions_duration CHECK (duration_minutes > 0),
            CONSTRAINT ck_hours_entry_revisions_reason CHECK (kind <> 'staff_correction' OR reason IS NOT NULL)
            SQL);

        $this->createTable('hours_decisions', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hours_entry_id BIGINT UNSIGNED NOT NULL,
            revision_no INT UNSIGNED NOT NULL,
            {$this->catalogued('hours_decisions', 'decision', 'hours_decision')},
            reason VARCHAR(1000) NULL,
            decided_by_user_id BIGINT UNSIGNED NOT NULL,
            decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_hours_decisions PRIMARY KEY (id),
            CONSTRAINT uq_hours_decisions_target UNIQUE (id, hours_entry_id, revision_no, decision),
            CONSTRAINT fk_hours_decisions_revision FOREIGN KEY (hours_entry_id, revision_no) REFERENCES hours_entry_revisions (hours_entry_id, revision_no) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_hours_decisions_decided_by FOREIGN KEY (decided_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_hours_decisions_reason CHECK (decision NOT IN ('reject', 'request_correction', 'void') OR reason IS NOT NULL),
            INDEX ix_hours_decisions_entry_decided (hours_entry_id, decided_at)
            SQL);

        $this->createTable('hours_decision_notes', <<<SQL
            decision_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            {$this->cre('hours_decision_notes')},
            CONSTRAINT pk_hours_decision_notes PRIMARY KEY (decision_id),
            CONSTRAINT fk_hours_decision_notes_decision FOREIGN KEY (decision_id) REFERENCES hours_decisions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('hours_decision_notes', 'hours_decisions', 'hours_entry_revisions', 'hours_entries');
    }
}
