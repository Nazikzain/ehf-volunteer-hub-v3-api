<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M16 — certificate_templates, certificates, certificate_hours, certificate_events
 * (Database V1.4 §5.10).
 *
 * - One active final certificate per period [A] (UNIQUE active_period_id with a NULL-safe CHECK).
 * - Period, certificate, replacement and every counted hours entry belong to the same volunteer.
 * - Every counted minute is traced to the exact approved revision and its approve decision.
 * - Certificate numbers follow D2: EHF-YYYY- followed by five digits.
 * - No self-replacement: a service check (a CHECK would reference the AUTO_INCREMENT id, §2.2).
 */
final class CreateCertificates extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('certificate_templates', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            signatory_name VARCHAR(150) NOT NULL,
            signatory_title VARCHAR(150) NOT NULL,
            background_file_id BIGINT UNSIGNED NOT NULL,
            signature_file_id BIGINT UNSIGNED NULL,
            layout JSON NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            {$this->std('certificate_templates')},
            CONSTRAINT pk_certificate_templates PRIMARY KEY (id),
            CONSTRAINT uq_certificate_templates_name UNIQUE (name),
            CONSTRAINT fk_certificate_templates_background FOREIGN KEY (background_file_id) REFERENCES files (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificate_templates_signature FOREIGN KEY (signature_file_id) REFERENCES files (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('certificates', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certificate_number VARCHAR(30) NOT NULL,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            period_id BIGINT UNSIGNED NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('certificates', 'status', 'certificate', 'issued')},
            active_period_id BIGINT UNSIGNED NULL,
            approved_minutes INT UNSIGNED NOT NULL,
            period_start_date DATE NOT NULL,
            period_end_date DATE NOT NULL,
            volunteer_name_snapshot VARCHAR(150) NOT NULL,
            signatory_name_snapshot VARCHAR(150) NOT NULL,
            signatory_title_snapshot VARCHAR(150) NOT NULL,
            pdf_file_id BIGINT UNSIGNED NOT NULL,
            issued_by_user_id BIGINT UNSIGNED NOT NULL,
            issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            revoked_by_user_id BIGINT UNSIGNED NULL,
            revoked_at DATETIME(6) NULL,
            revoke_reason VARCHAR(1000) NULL,
            replaces_certificate_id BIGINT UNSIGNED NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            {$this->cre('certificates')},
            CONSTRAINT pk_certificates PRIMARY KEY (id),
            CONSTRAINT uq_certificates_number UNIQUE (certificate_number),
            CONSTRAINT uq_certificates_id_volunteer UNIQUE (id, volunteer_id),
            CONSTRAINT uq_certificates_id_volunteer_period UNIQUE (id, volunteer_id, period_id),
            CONSTRAINT uq_certificates_active_period UNIQUE (active_period_id),
            CONSTRAINT uq_certificates_replaces UNIQUE (replaces_certificate_id),
            CONSTRAINT fk_certificates_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificates_period FOREIGN KEY (period_id, volunteer_id) REFERENCES volunteering_periods (id, volunteer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificates_template FOREIGN KEY (template_id) REFERENCES certificate_templates (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificates_pdf FOREIGN KEY (pdf_file_id) REFERENCES files (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificates_issued_by FOREIGN KEY (issued_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificates_revoked_by FOREIGN KEY (revoked_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificates_replaces FOREIGN KEY (replaces_certificate_id, volunteer_id, period_id) REFERENCES certificates (id, volunteer_id, period_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_certificates_number_format CHECK (certificate_number REGEXP '^EHF-[0-9]{4}-[0-9]{5}$'),
            CONSTRAINT ck_certificates_active_period CHECK ((status = 'issued' AND active_period_id IS NOT NULL AND active_period_id = period_id) OR (status <> 'issued' AND active_period_id IS NULL)),
            CONSTRAINT ck_certificates_revocation CHECK ((status = 'revoked' AND revoked_by_user_id IS NOT NULL AND revoked_at IS NOT NULL AND revoke_reason IS NOT NULL) OR (status <> 'revoked' AND revoked_at IS NULL))
            SQL);

        $this->createTable('certificate_hours', <<<'SQL'
            certificate_id BIGINT UNSIGNED NOT NULL,
            hours_entry_id BIGINT UNSIGNED NOT NULL,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            revision_no INT UNSIGNED NOT NULL,
            hours_revision_id BIGINT UNSIGNED NOT NULL,
            duration_minutes INT UNSIGNED NOT NULL,
            activity_date DATE NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            hours_decision_id BIGINT UNSIGNED NOT NULL,
            decision_code VARCHAR(40) NOT NULL DEFAULT 'approve',
            CONSTRAINT pk_certificate_hours PRIMARY KEY (certificate_id, hours_entry_id),
            CONSTRAINT fk_certificate_hours_revision FOREIGN KEY (hours_revision_id, hours_entry_id, revision_no, duration_minutes, activity_date, project_id) REFERENCES hours_entry_revisions (id, hours_entry_id, revision_no, duration_minutes, activity_date, project_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificate_hours_decision FOREIGN KEY (hours_decision_id, hours_entry_id, revision_no, decision_code) REFERENCES hours_decisions (id, hours_entry_id, revision_no, decision) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificate_hours_certificate FOREIGN KEY (certificate_id, volunteer_id) REFERENCES certificates (id, volunteer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificate_hours_entry FOREIGN KEY (hours_entry_id, volunteer_id) REFERENCES hours_entries (id, volunteer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_certificate_hours_decision CHECK (decision_code = 'approve')
            SQL);

        $this->createTable('certificate_events', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certificate_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('certificate_events', 'event', 'certificate_event')},
            reason VARCHAR(1000) NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_certificate_events PRIMARY KEY (id),
            CONSTRAINT fk_certificate_events_certificate FOREIGN KEY (certificate_id) REFERENCES certificates (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_certificate_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('certificate_events', 'certificate_hours', 'certificates', 'certificate_templates');
    }
}
