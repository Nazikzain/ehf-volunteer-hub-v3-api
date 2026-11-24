<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M11 — project_activities, project_staff, volunteer_assignments (Database V1.4 §5.5).
 * A person may serve on several projects (UNIQUE (project_id, active_key) only — DB-H2).
 */
final class CreateProjectActivitiesStaffAssignments extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('project_activities', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            {$this->std('project_activities')},
            CONSTRAINT pk_project_activities PRIMARY KEY (id),
            CONSTRAINT uq_project_activities_name UNIQUE (project_id, name),
            CONSTRAINT fk_project_activities_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('project_staff', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            project_role VARCHAR(40) NULL,
            added_by_user_id BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            removed_at DATETIME(6) NULL,
            removed_by_user_id BIGINT UNSIGNED NULL,
            remove_reason VARCHAR(500) NULL,
            active_key BIGINT UNSIGNED NULL,
            CONSTRAINT pk_project_staff PRIMARY KEY (id),
            CONSTRAINT uq_project_staff_active UNIQUE (project_id, active_key),
            CONSTRAINT fk_project_staff_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_project_staff_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_project_staff_added_by FOREIGN KEY (added_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_project_staff_removed_by FOREIGN KEY (removed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_project_staff_active CHECK ((removed_at IS NULL AND active_key IS NOT NULL AND active_key = user_id) OR (removed_at IS NOT NULL AND active_key IS NULL))
            SQL);

        $this->createTable('volunteer_assignments', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(100) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            {$this->catalogued('volunteer_assignments', 'status', 'assignment', 'active')},
            ended_by_user_id BIGINT UNSIGNED NULL,
            ended_at DATETIME(6) NULL,
            end_reason VARCHAR(500) NULL,
            active_key TINYINT NULL,
            {$this->std('volunteer_assignments')},
            CONSTRAINT pk_volunteer_assignments PRIMARY KEY (id),
            CONSTRAINT uq_volunteer_assignments_active UNIQUE (volunteer_id, project_id, active_key),
            CONSTRAINT fk_volunteer_assignments_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_volunteer_assignments_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_volunteer_assignments_ended_by FOREIGN KEY (ended_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_volunteer_assignments_dates CHECK (end_date IS NULL OR end_date >= start_date),
            CONSTRAINT ck_volunteer_assignments_active CHECK ((status = 'active' AND active_key IS NOT NULL AND active_key = 1) OR (status <> 'active' AND active_key IS NULL)),
            INDEX ix_volunteer_assignments_project_status (project_id, status),
            INDEX ix_volunteer_assignments_volunteer_dates (volunteer_id, start_date, end_date)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('volunteer_assignments', 'project_staff', 'project_activities');
    }
}
