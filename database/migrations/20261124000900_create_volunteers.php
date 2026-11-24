<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M09 — volunteers and their related tables (Database V1.4 §5.3).
 *
 * - The account link is optional and unique (AR-19); the operational status is never changed by
 *   account activation [A].
 * - email and phone are contact fields (AC-4); both optional at creation (W1).
 * - Sensitive personal fields live in their own table (P5).
 */
final class CreateVolunteers extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('volunteers', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(20) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(150) NOT NULL,
            preferred_name VARCHAR(100) NULL,
            email VARCHAR(254) NULL,
            phone VARCHAR(40) NULL,
            {$this->catalogued('volunteers', 'status', 'volunteer', 'draft')},
            accepted_at DATETIME(6) NULL,
            accepted_by_user_id BIGINT UNSIGNED NULL,
            skills TEXT NULL,
            languages TEXT NULL,
            interests TEXT NULL,
            availability_type VARCHAR(20) NULL,
            {$this->std('volunteers')},
            CONSTRAINT pk_volunteers PRIMARY KEY (id),
            CONSTRAINT uq_volunteers_reference UNIQUE (reference),
            CONSTRAINT uq_volunteers_user UNIQUE (user_id),
            CONSTRAINT fk_volunteers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_volunteers_accepted_by FOREIGN KEY (accepted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_volunteers_reference_format CHECK (reference REGEXP '^V-[0-9]{6}$'),
            CONSTRAINT ck_volunteers_acceptance CHECK ((accepted_at IS NULL) = (accepted_by_user_id IS NULL)),
            CONSTRAINT ck_volunteers_availability CHECK (availability_type IS NULL OR availability_type IN ('specific_days', 'flexible')),
            INDEX ix_volunteers_status (status),
            INDEX ix_volunteers_full_name (full_name),
            INDEX ix_volunteers_email (email)
            SQL);

        $this->createTable('volunteer_sensitive_details', <<<SQL
            volunteer_id BIGINT UNSIGNED NOT NULL,
            address_line1 VARCHAR(150) NULL,
            address_line2 VARCHAR(150) NULL,
            town VARCHAR(100) NULL,
            postcode VARCHAR(12) NULL,
            nationality VARCHAR(80) NULL,
            emergency_contact_name VARCHAR(150) NULL,
            emergency_contact_phone VARCHAR(40) NULL,
            emergency_contact_relationship VARCHAR(60) NULL,
            {$this->std('volunteer_sensitive_details')},
            CONSTRAINT pk_volunteer_sensitive_details PRIMARY KEY (volunteer_id),
            CONSTRAINT fk_volunteer_sensitive_details_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('volunteer_availability_days', <<<'SQL'
            volunteer_id BIGINT UNSIGNED NOT NULL,
            weekday TINYINT NOT NULL,
            CONSTRAINT pk_volunteer_availability_days PRIMARY KEY (volunteer_id, weekday),
            CONSTRAINT fk_volunteer_availability_days_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_volunteer_availability_days_weekday CHECK (weekday BETWEEN 1 AND 7)
            SQL);

        $this->createTable('volunteer_status_history', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NOT NULL,
            reason VARCHAR(500) NULL,
            changed_by_user_id BIGINT UNSIGNED NOT NULL,
            changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_volunteer_status_history PRIMARY KEY (id),
            CONSTRAINT fk_volunteer_status_history_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_volunteer_status_history_changed_by FOREIGN KEY (changed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            INDEX ix_volunteer_status_history_volunteer_changed (volunteer_id, changed_at)
            SQL);

        $this->createTable('volunteer_notes', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            {$this->cre('volunteer_notes')},
            CONSTRAINT pk_volunteer_notes PRIMARY KEY (id),
            CONSTRAINT fk_volunteer_notes_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('volunteer_notes', 'volunteer_status_history', 'volunteer_availability_days', 'volunteer_sensitive_details', 'volunteers');
    }
}
