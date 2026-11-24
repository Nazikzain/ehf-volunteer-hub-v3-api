<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M06 — user_roles, user_permission_grants, operational_exceptions (Database V1.4 §5.2.5–5.2.7).
 *
 * - No uniqueness on user_roles.user_id: one role per account (G10) is an interim application rule
 *   while R2/R3 are pending (API §6.11).
 * - A technical_manager assignment and its revocation must each reference a single-use owner
 *   authorisation for the same user and the matching action (AR-13).
 * - Exceptions name one operational permission; every actor pair is complete, so the owner
 *   membership foreign key is always enforced (§2.6, DB-V13-1).
 */
final class CreateRolesGrantsAndExceptions extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('user_roles', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            role_code VARCHAR(40) NOT NULL,
            scope_type VARCHAR(12) NOT NULL DEFAULT 'organisation',
            scope_project_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NOT NULL,
            granted_by_user_id BIGINT UNSIGNED NULL,
            granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            owner_authorisation_id BIGINT UNSIGNED NULL,
            grant_auth_action VARCHAR(30) NOT NULL DEFAULT 'grant_technical_manager',
            revoked_at DATETIME(6) NULL,
            revoked_by_user_id BIGINT UNSIGNED NULL,
            revoke_reason VARCHAR(500) NULL,
            revoke_owner_authorisation_id BIGINT UNSIGNED NULL,
            revoke_auth_action VARCHAR(30) NOT NULL DEFAULT 'revoke_technical_manager',
            active_key VARCHAR(120) NULL,
            CONSTRAINT pk_user_roles PRIMARY KEY (id),
            CONSTRAINT uq_user_roles_owner_authorisation UNIQUE (owner_authorisation_id),
            CONSTRAINT uq_user_roles_revoke_authorisation UNIQUE (revoke_owner_authorisation_id),
            CONSTRAINT uq_user_roles_active UNIQUE (active_key),
            CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_roles_role FOREIGN KEY (role_code) REFERENCES roles (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_roles_scope_project FOREIGN KEY (scope_project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_roles_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_roles_revoked_by FOREIGN KEY (revoked_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_roles_grant_authorisation FOREIGN KEY (owner_authorisation_id, user_id, grant_auth_action) REFERENCES owner_authorisations (id, subject_user_id, action) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_roles_revoke_authorisation FOREIGN KEY (revoke_owner_authorisation_id, user_id, revoke_auth_action) REFERENCES owner_authorisations (id, subject_user_id, action) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_user_roles_scope_type CHECK (scope_type IN ('organisation', 'project')),
            CONSTRAINT ck_user_roles_scope CHECK ((scope_type = 'project') = (scope_project_id IS NOT NULL)),
            CONSTRAINT ck_user_roles_grant_pin CHECK (grant_auth_action = 'grant_technical_manager'),
            CONSTRAINT ck_user_roles_revoke_pin CHECK (revoke_auth_action = 'revoke_technical_manager'),
            CONSTRAINT ck_user_roles_tm_grant CHECK (role_code <> 'technical_manager' OR owner_authorisation_id IS NOT NULL),
            CONSTRAINT ck_user_roles_ordinary CHECK (role_code = 'technical_manager' OR (owner_authorisation_id IS NULL AND revoke_owner_authorisation_id IS NULL)),
            CONSTRAINT ck_user_roles_granter CHECK ((granted_by_user_id IS NOT NULL AND granted_by_user_id <> user_id) OR (granted_by_user_id IS NULL AND owner_authorisation_id IS NOT NULL)),
            CONSTRAINT ck_user_roles_revocation CHECK ((revoked_at IS NULL AND revoked_by_user_id IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revoked_by_user_id <> user_id AND revoke_reason IS NOT NULL)),
            CONSTRAINT ck_user_roles_tm_revoke CHECK (role_code <> 'technical_manager' OR revoked_at IS NULL OR revoke_owner_authorisation_id IS NOT NULL),
            CONSTRAINT ck_user_roles_active CHECK ((revoked_at IS NULL AND active_key IS NOT NULL) OR (revoked_at IS NOT NULL AND active_key IS NULL))
            SQL);

        $this->createTable('user_permission_grants', <<<'SQL'
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            permission_code VARCHAR(100) NOT NULL,
            scope_type VARCHAR(12) NOT NULL DEFAULT 'organisation',
            scope_project_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NOT NULL,
            expires_at DATETIME(6) NULL,
            granted_by_user_id BIGINT UNSIGNED NOT NULL,
            granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            revoked_at DATETIME(6) NULL,
            revoked_by_user_id BIGINT UNSIGNED NULL,
            revoke_reason VARCHAR(500) NULL,
            active_key VARCHAR(160) NULL,
            CONSTRAINT pk_user_permission_grants PRIMARY KEY (id),
            CONSTRAINT uq_user_permission_grants_active UNIQUE (active_key),
            CONSTRAINT fk_user_permission_grants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_permission_grants_permission FOREIGN KEY (permission_code) REFERENCES permissions (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_permission_grants_scope_project FOREIGN KEY (scope_project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_permission_grants_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_user_permission_grants_revoked_by FOREIGN KEY (revoked_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_user_permission_grants_scope_type CHECK (scope_type IN ('organisation', 'project')),
            CONSTRAINT ck_user_permission_grants_scope CHECK ((scope_type = 'project') = (scope_project_id IS NOT NULL)),
            CONSTRAINT ck_user_permission_grants_not_self CHECK (granted_by_user_id <> user_id),
            CONSTRAINT ck_user_permission_grants_revocation CHECK ((revoked_at IS NULL AND revoked_by_user_id IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revoked_by_user_id <> user_id AND revoke_reason IS NOT NULL)),
            CONSTRAINT ck_user_permission_grants_active CHECK ((revoked_at IS NULL AND active_key IS NOT NULL) OR (revoked_at IS NOT NULL AND active_key IS NULL))
            SQL);

        $this->createTable('operational_exceptions', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient_user_id BIGINT UNSIGNED NOT NULL,
            permission_code VARCHAR(100) NOT NULL,
            permission_category VARCHAR(20) NOT NULL DEFAULT 'operational',
            scope_type VARCHAR(12) NOT NULL DEFAULT 'organisation',
            scope_project_id BIGINT UNSIGNED NULL,
            reason VARCHAR(1000) NOT NULL,
            expires_at DATETIME(6) NULL,
            {$this->catalogued('operational_exceptions', 'status', 'exception', 'pending')},
            requested_by_user_id BIGINT UNSIGNED NOT NULL,
            authorised_by_membership_id BIGINT UNSIGNED NULL,
            authorised_by_user_id BIGINT UNSIGNED NULL,
            authorised_at DATETIME(6) NULL,
            rejected_by_membership_id BIGINT UNSIGNED NULL,
            rejected_by_user_id BIGINT UNSIGNED NULL,
            rejected_at DATETIME(6) NULL,
            reject_reason VARCHAR(1000) NULL,
            revoked_by_membership_id BIGINT UNSIGNED NULL,
            revoked_by_user_id BIGINT UNSIGNED NULL,
            revoked_at DATETIME(6) NULL,
            revoke_reason VARCHAR(1000) NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            {$this->cre('operational_exceptions')},
            CONSTRAINT pk_operational_exceptions PRIMARY KEY (id),
            CONSTRAINT fk_operational_exceptions_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_operational_exceptions_permission FOREIGN KEY (permission_code, permission_category) REFERENCES permissions (code, category) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_operational_exceptions_scope_project FOREIGN KEY (scope_project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_operational_exceptions_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_operational_exceptions_authorised_by FOREIGN KEY (authorised_by_membership_id, authorised_by_user_id) REFERENCES system_owner_memberships (id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_operational_exceptions_rejected_by FOREIGN KEY (rejected_by_membership_id, rejected_by_user_id) REFERENCES system_owner_memberships (id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_operational_exceptions_revoked_by FOREIGN KEY (revoked_by_membership_id, revoked_by_user_id) REFERENCES system_owner_memberships (id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_operational_exceptions_category CHECK (permission_category = 'operational'),
            CONSTRAINT ck_operational_exceptions_scope_type CHECK (scope_type IN ('organisation', 'project')),
            CONSTRAINT ck_operational_exceptions_scope CHECK ((scope_type = 'project') = (scope_project_id IS NOT NULL)),
            CONSTRAINT ck_operational_exceptions_requester CHECK (requested_by_user_id <> recipient_user_id),
            CONSTRAINT ck_operational_exceptions_not_recipient CHECK (authorised_by_user_id IS NULL OR authorised_by_user_id <> recipient_user_id),
            CONSTRAINT ck_operational_exceptions_authoriser_pair CHECK ((authorised_by_membership_id IS NULL) = (authorised_by_user_id IS NULL)),
            CONSTRAINT ck_operational_exceptions_rejecter_pair CHECK ((rejected_by_membership_id IS NULL) = (rejected_by_user_id IS NULL)),
            CONSTRAINT ck_operational_exceptions_revoker_pair CHECK ((revoked_by_membership_id IS NULL) = (revoked_by_user_id IS NULL)),
            CONSTRAINT ck_operational_exceptions_status CHECK (
                (status = 'pending'
                    AND authorised_by_membership_id IS NULL AND authorised_by_user_id IS NULL AND authorised_at IS NULL
                    AND rejected_by_membership_id IS NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND reject_reason IS NULL
                    AND revoked_by_membership_id IS NULL AND revoked_by_user_id IS NULL AND revoked_at IS NULL AND revoke_reason IS NULL)
                OR (status = 'authorised'
                    AND authorised_by_membership_id IS NOT NULL AND authorised_by_user_id IS NOT NULL AND authorised_at IS NOT NULL
                    AND rejected_by_membership_id IS NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND reject_reason IS NULL
                    AND revoked_by_membership_id IS NULL AND revoked_by_user_id IS NULL AND revoked_at IS NULL AND revoke_reason IS NULL)
                OR (status = 'rejected'
                    AND rejected_by_membership_id IS NOT NULL AND rejected_by_user_id IS NOT NULL AND rejected_at IS NOT NULL AND reject_reason IS NOT NULL
                    AND authorised_by_membership_id IS NULL AND authorised_by_user_id IS NULL AND authorised_at IS NULL
                    AND revoked_by_membership_id IS NULL AND revoked_by_user_id IS NULL AND revoked_at IS NULL AND revoke_reason IS NULL)
                OR (status = 'revoked'
                    AND authorised_by_membership_id IS NOT NULL AND authorised_by_user_id IS NOT NULL AND authorised_at IS NOT NULL
                    AND revoked_by_membership_id IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revoked_at IS NOT NULL AND revoke_reason IS NOT NULL
                    AND rejected_by_membership_id IS NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND reject_reason IS NULL)
                OR (status = 'expired'
                    AND authorised_by_membership_id IS NOT NULL AND authorised_by_user_id IS NOT NULL AND authorised_at IS NOT NULL AND expires_at IS NOT NULL
                    AND rejected_by_membership_id IS NULL AND rejected_by_user_id IS NULL AND rejected_at IS NULL AND reject_reason IS NULL
                    AND revoked_by_membership_id IS NULL AND revoked_by_user_id IS NULL AND revoked_at IS NULL AND revoke_reason IS NULL)
            ),
            INDEX ix_operational_exceptions_recipient_status (recipient_user_id, status)
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('operational_exceptions', 'user_permission_grants', 'user_roles');
    }
}
