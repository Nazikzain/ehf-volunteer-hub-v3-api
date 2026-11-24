<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M04 — system_owner_memberships, owner_authorisations (Database V1.4 §5.2.2–5.2.3).
 *
 * At most one bootstrap membership and one bootstrap authorisation can ever exist. Every other
 * owner was added by a different person holding a membership (G3), and every Technical Manager
 * role change is authorised by an owner who is not the subject (AR-13). No bootstrap row is
 * created here: the bootstrap is pending RS4/R7.
 */
final class CreateSystemOwnerGovernance extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('system_owner_memberships', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            added_via VARCHAR(12) NOT NULL DEFAULT 'owner',
            added_by_membership_id BIGINT UNSIGNED NULL,
            added_by_user_id BIGINT UNSIGNED NULL,
            bootstrap_key TINYINT NULL,
            added_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            add_reason VARCHAR(500) NOT NULL,
            removed_at DATETIME(6) NULL,
            removed_by_membership_id BIGINT UNSIGNED NULL,
            removed_by_user_id BIGINT UNSIGNED NULL,
            remove_reason VARCHAR(500) NULL,
            active_key BIGINT UNSIGNED NULL,
            CONSTRAINT pk_system_owner_memberships PRIMARY KEY (id),
            CONSTRAINT uq_system_owner_memberships_id_user UNIQUE (id, user_id),
            CONSTRAINT uq_system_owner_memberships_bootstrap UNIQUE (bootstrap_key),
            CONSTRAINT uq_system_owner_memberships_active UNIQUE (active_key),
            CONSTRAINT fk_system_owner_memberships_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_system_owner_memberships_added_by_user FOREIGN KEY (added_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_system_owner_memberships_removed_by_user FOREIGN KEY (removed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_system_owner_memberships_added_by FOREIGN KEY (added_by_membership_id, added_by_user_id) REFERENCES system_owner_memberships (id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_system_owner_memberships_removed_by FOREIGN KEY (removed_by_membership_id, removed_by_user_id) REFERENCES system_owner_memberships (id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_system_owner_memberships_added_via CHECK (added_via IN ('bootstrap', 'owner')),
            CONSTRAINT ck_system_owner_memberships_origin CHECK ((added_via = 'bootstrap' AND added_by_membership_id IS NULL AND added_by_user_id IS NULL AND bootstrap_key IS NOT NULL AND bootstrap_key = 1) OR (added_via = 'owner' AND added_by_membership_id IS NOT NULL AND added_by_user_id IS NOT NULL AND added_by_user_id <> user_id AND bootstrap_key IS NULL)),
            CONSTRAINT ck_system_owner_memberships_removal CHECK ((removed_at IS NULL AND removed_by_membership_id IS NULL AND removed_by_user_id IS NULL) OR (removed_at IS NOT NULL AND removed_by_membership_id IS NOT NULL AND removed_by_user_id IS NOT NULL AND removed_by_user_id <> user_id)),
            CONSTRAINT ck_system_owner_memberships_active CHECK ((removed_at IS NULL AND active_key IS NOT NULL AND active_key = user_id) OR (removed_at IS NOT NULL AND active_key IS NULL))
            SQL);

        $this->createTable('owner_authorisations', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(12) NOT NULL DEFAULT 'owner',
            owner_membership_id BIGINT UNSIGNED NULL,
            owner_user_id BIGINT UNSIGNED NULL,
            subject_user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            bootstrap_key TINYINT NULL,
            reason VARCHAR(500) NOT NULL,
            password_confirmed_at DATETIME(6) NULL,
            {$this->cre('owner_authorisations')},
            CONSTRAINT pk_owner_authorisations PRIMARY KEY (id),
            CONSTRAINT uq_owner_authorisations_target UNIQUE (id, subject_user_id, action),
            CONSTRAINT uq_owner_authorisations_bootstrap UNIQUE (bootstrap_key),
            CONSTRAINT fk_owner_authorisations_owner FOREIGN KEY (owner_membership_id, owner_user_id) REFERENCES system_owner_memberships (id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_owner_authorisations_subject FOREIGN KEY (subject_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_owner_authorisations_kind CHECK (kind IN ('bootstrap', 'owner')),
            CONSTRAINT ck_owner_authorisations_action CHECK (action IN ('grant_technical_manager', 'revoke_technical_manager')),
            CONSTRAINT ck_owner_authorisations_authority CHECK ((kind = 'owner' AND owner_membership_id IS NOT NULL AND owner_user_id IS NOT NULL AND owner_user_id <> subject_user_id AND bootstrap_key IS NULL) OR (kind = 'bootstrap' AND owner_membership_id IS NULL AND owner_user_id IS NULL AND action = 'grant_technical_manager' AND bootstrap_key IS NOT NULL AND bootstrap_key = 1))
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('owner_authorisations', 'system_owner_memberships');
    }
}
