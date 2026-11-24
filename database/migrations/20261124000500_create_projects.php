<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M05 — projects (Database V1.4 §5.2.4).
 */
final class CreateProjects extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('projects', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            objectives TEXT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            {$this->catalogued('projects', 'status', 'project', 'planned')},
            {$this->std('projects')},
            CONSTRAINT pk_projects PRIMARY KEY (id),
            CONSTRAINT uq_projects_name UNIQUE (name),
            CONSTRAINT ck_projects_dates CHECK (end_date IS NULL OR end_date >= start_date),
            INDEX ix_projects_status (status)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('projects');
    }
}
