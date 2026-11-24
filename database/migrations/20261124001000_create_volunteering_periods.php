<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M10 — volunteering_periods, volunteering_period_history (Database V1.4 §5.4).
 * Several periods per volunteer [A]; completed periods never overwritten [A]; no overlap
 * constraint (W6, application rule).
 */
final class CreateVolunteeringPeriods extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('volunteering_periods', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            {$this->catalogued('volunteering_periods', 'status', 'period', 'open')},
            completed_at DATETIME(6) NULL,
            completed_by_user_id BIGINT UNSIGNED NULL,
            approved_minutes_snapshot INT UNSIGNED NULL,
            {$this->std('volunteering_periods')},
            CONSTRAINT pk_volunteering_periods PRIMARY KEY (id),
            CONSTRAINT uq_volunteering_periods_id_volunteer UNIQUE (id, volunteer_id),
            CONSTRAINT fk_volunteering_periods_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_volunteering_periods_completed_by FOREIGN KEY (completed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_volunteering_periods_dates CHECK (end_date IS NULL OR end_date >= start_date),
            CONSTRAINT ck_volunteering_periods_completion CHECK ((status = 'completed' AND end_date IS NOT NULL AND completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL AND approved_minutes_snapshot IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL AND approved_minutes_snapshot IS NULL)),
            INDEX ix_volunteering_periods_volunteer_start (volunteer_id, start_date)
            SQL);

        $this->createTable('volunteering_period_history', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('volunteering_period_history', 'event', 'period_event')},
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NULL,
            data_before JSON NULL,
            data_after JSON NULL,
            reason VARCHAR(500) NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_volunteering_period_history PRIMARY KEY (id),
            CONSTRAINT fk_volunteering_period_history_period FOREIGN KEY (period_id) REFERENCES volunteering_periods (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_volunteering_period_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('volunteering_period_history', 'volunteering_periods');
    }
}
