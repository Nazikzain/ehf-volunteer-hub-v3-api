<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M12 — tasks, task_assignees, task_events (Database V1.4 §5.6).
 * Several assignees are allowed by the schema; one active assignee per task is an interim
 * application rule (W2). UNIQUE (id, project_id) is the target of the hours task/project
 * foreign keys (V1.4).
 */
final class CreateTasks extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('tasks', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            instructions TEXT NULL,
            deliverables TEXT NULL,
            {$this->catalogued('tasks', 'priority', 'task_priority', 'medium')},
            start_date DATE NULL,
            due_date DATE NULL,
            {$this->catalogued('tasks', 'status', 'task', 'not_started')},
            progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            completed_at DATETIME(6) NULL,
            {$this->std('tasks')},
            CONSTRAINT pk_tasks PRIMARY KEY (id),
            CONSTRAINT uq_tasks_id_project UNIQUE (id, project_id),
            CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_tasks_dates CHECK (due_date IS NULL OR start_date IS NULL OR due_date >= start_date),
            CONSTRAINT ck_tasks_progress CHECK (progress_percent <= 100),
            INDEX ix_tasks_project_status (project_id, status),
            INDEX ix_tasks_due_status (due_date, status)
            SQL);

        $this->createTable('task_assignees', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            assigned_by_user_id BIGINT UNSIGNED NOT NULL,
            assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            unassigned_at DATETIME(6) NULL,
            unassigned_by_user_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NULL,
            active_key TINYINT NULL,
            CONSTRAINT pk_task_assignees PRIMARY KEY (id),
            CONSTRAINT uq_task_assignees_active UNIQUE (task_id, volunteer_id, active_key),
            CONSTRAINT fk_task_assignees_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_assignees_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_assignees_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_assignees_unassigned_by FOREIGN KEY (unassigned_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_task_assignees_active CHECK ((unassigned_at IS NULL AND active_key IS NOT NULL AND active_key = 1) OR (unassigned_at IS NOT NULL AND active_key IS NULL))
            SQL);

        $this->createTable('task_events', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('task_events', 'event_type', 'task_event', null, 'event_domain')},
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NULL,
            progress_percent TINYINT NULL,
            note VARCHAR(1000) NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_task_events PRIMARY KEY (id),
            CONSTRAINT fk_task_events_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_task_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            INDEX ix_task_events_task_occurred (task_id, occurred_at)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('task_events', 'task_assignees', 'tasks');
    }
}
