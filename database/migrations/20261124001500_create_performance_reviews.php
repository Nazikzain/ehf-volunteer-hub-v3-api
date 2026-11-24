<?php

declare(strict_types=1);

use Ehf\Api\Database\SchemaMigration;

/**
 * M15 — review criteria, rating labels, performance reviews, versions, ratings, internal notes and
 * events (Database V1.4 §5.9). A review's period, when given, belongs to the reviewed volunteer
 * (V1.4). The version pointers on performance_reviews are added after the versions table exists
 * (cycle C3, §6.2).
 */
final class CreatePerformanceReviews extends SchemaMigration
{
    public function up(): void
    {
        $this->createTable('review_criteria', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            {$this->std('review_criteria')},
            CONSTRAINT pk_review_criteria PRIMARY KEY (id),
            CONSTRAINT uq_review_criteria_name UNIQUE (name)
            SQL);

        $this->createTable('review_rating_labels', <<<'SQL'
            rating TINYINT NOT NULL,
            label VARCHAR(60) NOT NULL,
            CONSTRAINT pk_review_rating_labels PRIMARY KEY (rating),
            CONSTRAINT ck_review_rating_labels_rating CHECK (rating BETWEEN 1 AND 5)
            SQL);

        $this->createTable('performance_reviews', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NULL,
            period_id BIGINT UNSIGNED NULL,
            review_date DATE NOT NULL,
            {$this->catalogued('performance_reviews', 'status', 'review', 'draft')},
            author_user_id BIGINT UNSIGNED NOT NULL,
            submitted_version_id BIGINT UNSIGNED NULL,
            published_version_id BIGINT UNSIGNED NULL,
            approved_by_user_id BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            published_by_user_id BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            acknowledged_at DATETIME(6) NULL,
            {$this->std('performance_reviews')},
            CONSTRAINT pk_performance_reviews PRIMARY KEY (id),
            CONSTRAINT fk_performance_reviews_volunteer FOREIGN KEY (volunteer_id) REFERENCES volunteers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_reviews_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_reviews_period FOREIGN KEY (period_id, volunteer_id) REFERENCES volunteering_periods (id, volunteer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_reviews_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_reviews_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_reviews_published_by FOREIGN KEY (published_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_performance_reviews_published CHECK ((published_at IS NULL) = (published_version_id IS NULL)),
            INDEX ix_performance_reviews_volunteer_status (volunteer_id, status),
            INDEX ix_performance_reviews_status (status)
            SQL);

        $this->createTable('performance_review_versions', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            review_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            {$this->catalogued('performance_review_versions', 'kind', 'review_version_kind', 'draft')},
            strengths TEXT NULL,
            improvements TEXT NULL,
            comments TEXT NULL,
            recommendations TEXT NULL,
            frozen_at DATETIME(6) NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            draft_key TINYINT NULL,
            CONSTRAINT pk_performance_review_versions PRIMARY KEY (id),
            CONSTRAINT uq_performance_review_versions_number UNIQUE (review_id, version_no),
            CONSTRAINT uq_performance_review_versions_id_review UNIQUE (id, review_id),
            CONSTRAINT uq_performance_review_versions_draft UNIQUE (review_id, draft_key),
            CONSTRAINT fk_performance_review_versions_review FOREIGN KEY (review_id) REFERENCES performance_reviews (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_review_versions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT ck_performance_review_versions_draft CHECK ((frozen_at IS NULL AND draft_key IS NOT NULL AND draft_key = 1) OR (frozen_at IS NOT NULL AND draft_key IS NULL)),
            CONSTRAINT ck_performance_review_versions_kind CHECK ((kind = 'draft' AND frozen_at IS NULL) OR (kind = 'submitted' AND frozen_at IS NOT NULL))
            SQL);

        $this->execute(<<<'SQL'
            ALTER TABLE performance_reviews
                ADD CONSTRAINT fk_performance_reviews_submitted_version FOREIGN KEY (submitted_version_id, id) REFERENCES performance_review_versions (id, review_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                ADD CONSTRAINT fk_performance_reviews_published_version FOREIGN KEY (published_version_id, id) REFERENCES performance_review_versions (id, review_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('performance_review_ratings', <<<'SQL'
            version_id BIGINT UNSIGNED NOT NULL,
            criterion_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT NOT NULL,
            CONSTRAINT pk_performance_review_ratings PRIMARY KEY (version_id, criterion_id),
            CONSTRAINT fk_performance_review_ratings_version FOREIGN KEY (version_id) REFERENCES performance_review_versions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_review_ratings_criterion FOREIGN KEY (criterion_id) REFERENCES review_criteria (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_review_ratings_label FOREIGN KEY (rating) REFERENCES review_rating_labels (rating) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('performance_review_internal_notes', <<<SQL
            version_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            {$this->cre('performance_review_internal_notes')},
            CONSTRAINT pk_performance_review_internal_notes PRIMARY KEY (version_id),
            CONSTRAINT fk_performance_review_internal_notes_version FOREIGN KEY (version_id) REFERENCES performance_review_versions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);

        $this->createTable('performance_review_events', <<<SQL
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            review_id BIGINT UNSIGNED NOT NULL,
            {$this->catalogued('performance_review_events', 'event', 'review_event')},
            version_id BIGINT UNSIGNED NULL,
            reason VARCHAR(1000) NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT pk_performance_review_events PRIMARY KEY (id),
            CONSTRAINT fk_performance_review_events_review FOREIGN KEY (review_id) REFERENCES performance_reviews (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_review_events_version FOREIGN KEY (version_id, review_id) REFERENCES performance_review_versions (id, review_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_performance_review_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        $this->dropTables('performance_review_events', 'performance_review_internal_notes', 'performance_review_ratings');
        $this->execute('ALTER TABLE performance_reviews DROP FOREIGN KEY fk_performance_reviews_submitted_version, DROP FOREIGN KEY fk_performance_reviews_published_version');
        $this->dropTables('performance_review_versions', 'performance_reviews', 'review_rating_labels', 'review_criteria');
    }
}
