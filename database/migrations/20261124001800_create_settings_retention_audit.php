<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M18 — settings, retention_policies, audit_logs (Database V1.4 §5.12).
 * Settings never hold secrets [A]. Retention stays empty until EHF approves (S4) — no automatic
 * deletion while empty. Audit rows are append-only (P4) with a hash chain (D4).
 */
final class CreateSettingsRetentionAudit extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('settings', <<<SQL
            setting_key VARCHAR(100) NOT NULL,
            category VARCHAR(20) NOT NULL,
            value_json JSON NULL,
            value_type VARCHAR(20) NOT NULL,
            description VARCHAR(255) NOT NULL,
            {$this->std('settings')},
            CONSTRAINT pk_settings PRIMARY KEY (setting_key),
            CONSTRAINT ck_settings_category CHECK (category IN ('technical', 'operational', 'certificate', 'review'))
            SQL);

        $this->createTable('retention_policies', <<<SQL
            data_category VARCHAR(60) NOT NULL,
            retention_days INT UNSIGNED NULL,
            action VARCHAR(20) NOT NULL,
            {$this->std('retention_policies')},
            CONSTRAINT pk_retention_policies PRIMARY KEY (data_category),
            CONSTRAINT ck_retention_policies_action CHECK (action IN ('delete', 'anonymise'))
            SQL);

        $this->createTable('audit_logs', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            category VARCHAR(20) NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            acting_role VARCHAR(40) NULL,
            action VARCHAR(100) NOT NULL,
            resource_type VARCHAR(60) NULL,
            resource_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            outcome VARCHAR(10) NOT NULL,
            request_id CHAR(26) NULL,
            ip_prefix VARCHAR(45) NULL,
            details JSON NULL,
            prev_hash CHAR(64) NOT NULL,
            hash CHAR(64) NOT NULL,
            CONSTRAINT pk_audit_logs PRIMARY KEY (id),
            CONSTRAINT uq_audit_logs_hash UNIQUE (hash),
            CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_audit_logs_category CHECK (category IN ('security', 'technical', 'operational')),
            CONSTRAINT ck_audit_logs_outcome CHECK (outcome IN ('success', 'denied')),
            INDEX ix_audit_logs_category_occurred (category, occurred_at),
            INDEX ix_audit_logs_action (action),
            INDEX ix_audit_logs_resource (resource_type, resource_id)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('audit_logs', 'retention_policies', 'settings');
    }
}
