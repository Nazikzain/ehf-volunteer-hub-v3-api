<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M07 — auth_tokens, sessions, rate_limit_counters, idempotency_keys (Database V1.4 §5.2.8; D4).
 * No other table references these, so expired rows can be purged.
 */
final class CreateSecurityTables extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('auth_tokens', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            CONSTRAINT pk_auth_tokens PRIMARY KEY (id),
            CONSTRAINT uq_auth_tokens_hash UNIQUE (token_hash),
            CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_auth_tokens_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_auth_tokens_type CHECK (type IN ('invitation', 'password_reset', 'email_change')),
            INDEX ix_auth_tokens_user_type (user_id, type)
            SQL);

        $this->createTable('sessions', <<<'SQL'
            id_hash CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            csrf_hash CHAR(64) NOT NULL,
            session_version_at_issue INT UNSIGNED NOT NULL,
            acting_role VARCHAR(40) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            last_seen_at DATETIME(6) NOT NULL,
            absolute_expires_at DATETIME(6) NOT NULL,
            ip_prefix VARCHAR(45) NOT NULL,
            agent_summary VARCHAR(120) NOT NULL,
            revoked_at DATETIME(6) NULL,
            CONSTRAINT pk_sessions PRIMARY KEY (id_hash),
            CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            INDEX ix_sessions_user_revoked (user_id, revoked_at)
            SQL);

        $this->createTable('rate_limit_counters', <<<'SQL'
            key_hash CHAR(64) NOT NULL,
            window_start DATETIME(6) NOT NULL,
            count INT UNSIGNED NOT NULL DEFAULT 0,
            CONSTRAINT pk_rate_limit_counters PRIMARY KEY (key_hash, window_start)
            SQL);

        $this->createTable('idempotency_keys', <<<'SQL'
            user_id BIGINT UNSIGNED NOT NULL,
            idem_key VARCHAR(100) NOT NULL,
            endpoint VARCHAR(150) NOT NULL,
            body_hash CHAR(64) NOT NULL,
            response_status SMALLINT NOT NULL,
            response_body JSON NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            expires_at DATETIME(6) NOT NULL,
            CONSTRAINT pk_idempotency_keys PRIMARY KEY (user_id, idem_key),
            CONSTRAINT fk_idempotency_keys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('idempotency_keys', 'rate_limit_counters', 'sessions', 'auth_tokens');
    }
}
