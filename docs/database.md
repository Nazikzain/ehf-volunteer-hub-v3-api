# Database: creation, migrations and tests

The V3 database is **new and independent** (API V1.3.1 §2.3, Database V1.4 §2.1). Nothing is
imported from the old system: no schema, no SQL dump, no records, no password hashes, no
credentials. Migrations build the schema in an **empty** database.

> No production or staging database has been created or contacted during Phase 3. Everything
> below was run only against disposable local test servers.

## 1. What the migrations build

19 Phinx migrations in `database/migrations/`, in the order of Database V1.4 §6.3:

| Migration | Contents |
|---|---|
| M01 `CreateReferenceCatalogues` | `status_catalogue`, `roles`, `permissions` |
| M02 `CreatePermissionBundlesAndFieldCatalogue` | `role_permissions`, `field_catalogue` |
| M03 `CreateUsers` | `users` |
| M04 `CreateSystemOwnerGovernance` | `system_owner_memberships`, `owner_authorisations` |
| M05 `CreateProjects` | `projects` |
| M06 `CreateRolesGrantsAndExceptions` | `user_roles`, `user_permission_grants`, `operational_exceptions` |
| M07 `CreateSecurityTables` | `auth_tokens`, `sessions`, `rate_limit_counters`, `idempotency_keys` |
| M08 `CreateFiles` | `files` |
| M09 `CreateVolunteers` | `volunteers`, `volunteer_sensitive_details`, `volunteer_availability_days`, `volunteer_status_history`, `volunteer_notes` |
| M10 `CreateVolunteeringPeriods` | `volunteering_periods`, `volunteering_period_history` |
| M11 `CreateProjectActivitiesStaffAssignments` | `project_activities`, `project_staff`, `volunteer_assignments` |
| M12 `CreateTasks` | `tasks`, `task_assignees`, `task_events` |
| M13 `CreateTaskReports` | `task_reports`, `task_report_versions`, `task_report_attachments`, `task_report_decisions`, `task_report_decision_notes` |
| M14 `CreateHours` | `hours_entries`, `hours_entry_revisions`, `hours_decisions`, `hours_decision_notes` |
| M15 `CreatePerformanceReviews` | `review_criteria`, `review_rating_labels`, `performance_reviews`, `performance_review_versions`, `performance_review_ratings`, `performance_review_internal_notes`, `performance_review_events` |
| M16 `CreateCertificates` | `certificate_templates`, `certificates`, `certificate_hours`, `certificate_events` |
| M17 `CreateNotificationsEmailExports` | `notifications`, `email_outbox`, `exports` |
| M18 `CreateSettingsRetentionAudit` | `settings`, `retention_policies`, `audit_logs` |
| S01 `SeedReferenceData` | Reference data (section 4) |

Result: **57 tables** (56 + `schema_migrations`, the Phinx log), 177 foreign keys (all
`ON DELETE RESTRICT ON UPDATE RESTRICT`), 99 named CHECK constraints, InnoDB and
`utf8mb4_unicode_ci` throughout. The exact shape is recorded in
`tests/Database/fixtures/schema.json`.

Conventions: constraints are named `pk_`, `uq_`, `ix_`, `fk_`, `ck_` + table. The helpers in
`src/Database/SchemaMigration.php` are part of released migrations and must never change what they
produce.

## 2. Create the empty database and its two users (on the host, by the owner)

Do this in the host's control panel or with an administrator account. **Choose new names and
strong, newly generated passwords; never reuse the old database's.** Placeholders below are in
angle brackets.

```sql
CREATE DATABASE <v3_database> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Two users (API §15.2):

| User | Used by | Privileges on `<v3_database>` |
|---|---|---|
| Migration user | Command line only (`MIGRATION_DB_*`) | `CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE` |
| Runtime user | The web application (`DB_*`) | `SELECT, INSERT, UPDATE, DELETE` only. Where the host allows table-level grants, give the immutable tables (P4, list below) `SELECT, INSERT` only |

```sql
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON <v3_database>.* TO '<migration_user>'@'<host>';
GRANT SELECT, INSERT, UPDATE, DELETE ON <v3_database>.* TO '<runtime_user>'@'<host>';
```

Immutable (P4) tables, for table-level runtime grants where supported:
`volunteering_period_history`, `task_events`, `task_report_versions`, `task_report_decisions`,
`task_report_decision_notes`, `hours_entry_revisions`, `hours_decisions`, `hours_decision_notes`,
`certificate_hours`, `certificate_events`, `performance_review_events`,
`volunteer_status_history`, `volunteer_notes`, `audit_logs`. (`performance_review_versions` rows are
immutable only once frozen, so they keep normal grants and the rule stays in the service.) Whether
the host supports table-level grants is hosting-check item 5.

Shared hosting may only offer a single user per database through its panel; if so, record that
in the hosting checklist — it is a known limitation, not something to work around silently.

## 3. Run the migrations (command line only)

The release package contains `phinx.php`, `database/` and `vendor/bin/phinx`, so no Composer is
needed on the server (decision H1). Put the migration user's details in the private `.env` next to
`public/` (or in real environment variables):

```
MIGRATION_DB_HOST=<host>
MIGRATION_DB_PORT=3306
MIGRATION_DB_NAME=<v3_database>
MIGRATION_DB_USER=<migration_user>
MIGRATION_DB_PASSWORD=<migration_password>
```

Then:

```bash
vendor/bin/phinx status -e target
```

```bash
vendor/bin/phinx migrate -e target
```

```bash
vendor/bin/phinx status -e target
```

(`composer db:status` and `composer db:migrate` do the same where Composer is available.)

Without SSH on the host, migrations can only be run from a machine that can reach the database
(for example a host's "remote MySQL" allowlist) — confirm with the hosting checks before relying
on it. Remove the migration credentials from the server `.env` once migrations have run if they
are not needed for the runtime.

### Rollback

`vendor/bin/phinx rollback -e target` undoes the most recent migration; `-t 0` undoes all of them.
Every migration has a tested `down()`.

- **Before go-live** (no real data): rollback and re-run are safe and are tested in CI.
- **After real data exists**: do not roll back schema migrations. All foreign keys are `RESTRICT`,
  so a rollback that would discard referenced data fails rather than deleting it; a dropped table
  is still lost. Take a backup first (Database V1.4 §13; A13) and fix forward with a new
  migration instead.
- Rolling back S01 removes exactly the rows S01 inserted; it fails if any of them is in use.

## 4. Reference data (S01)

Seeded from `database/reference/*.php` (part of a released migration: change reference data only
with a new migration).

| Data | Rows | Basis |
|---|---|---|
| Status catalogue | 94 values in 22 domains | Database V1.4 §5.1.1 with each value's basis. Interim/proposed values are seeded **inactive**: `hours.withdrawn`, `hours.voided`, `hours_revision_kind.staff_correction`, `hours_decision.void`, `period.cancelled`, `period_event.reopened`, `period_event.cancelled` |
| Roles | 4 | [A] |
| Permissions | 148 | API §6.4 codes (D1) + `fields.contact.email`, `fields.contact.phone` (AC-4) |
| Role bundles | 120 (Volunteer 23, Full Admin 58, Monitoring Admin 13, Technical Manager 26) | API §6.5 entries marked [A] only, + AC-4 for Full Admin |
| Field catalogue | 15 | D3, AC-4 |
| Review rating labels / criteria | 5 / 5 | Frontend values (REC) |
| Settings | 1 key, empty | `certificate.eligibility_threshold_minutes` (W7 pending → issuance blocked) |

**Deliberately not seeded** (pending, interim or recommended only):

- Sensitive field-code instances and their field-catalogue rows (R6/D3); uncatalogued personal
  columns are treated as sensitive.
- [R] bundle entries of API §6.5: Full Admin `dashboard.monitoring`, `review_criteria.view` (Full
  Admin and Monitoring Admin), Full Admin `reviews.view_submitted`.
- Codes held by no role while pending: confidential notes (R6/R17), review authoring (R4/R5/R20),
  approved-hours correction/void (W3/R9), `periods.reopen` (W6), `exports.format.csv` (A12),
  operational audit (R10), Monitoring Admin report types/browsing/certificates (R13–R16),
  operational/certificate/review-criteria settings and retention (S1–S4).
- `exception_eligible` is 0 for every code (R19).
- Retention policies (S4) — empty until EHF approves.
- The bootstrap System Owner and any Technical Manager grant (RS4/R7) — the `bin/console owner:create`
  path is Phase 4 and still needs those decisions.
- Any user, volunteer, project or other record.

## 5. Tests

The database tests need a **throwaway** MySQL or MariaDB server. They create a randomly named
database (`ehf_test_…`) for each run and drop it afterwards, so the test user must be able to create
databases. Never point `DB_TEST_*` at a real server.

```bash
docker run -d --name ehf-dbtest -p 127.0.0.1:33061:3306 -e MYSQL_ROOT_PASSWORD=throwaway -e MYSQL_DATABASE=ehf_ci mysql:8.0
```

```bash
DB_TEST_HOST=127.0.0.1 DB_TEST_PORT=33061 DB_TEST_NAME=ehf_ci DB_TEST_USER=root DB_TEST_PASSWORD=throwaway composer test:database
```

```bash
docker rm -f ehf-dbtest
```

| Test | Checks |
|---|---|
| `EngineCompatibilityTest` | Supported engine version; utf8mb4/UTC/strict session; native prepared statements; CHECK behaviour the design relies on |
| `MigrationLifecycleTest` | Empty → 57 tables; InnoDB/utf8mb4; every FK RESTRICT; `migrate` again changes nothing; full rollback leaves only `schema_migrations`; re-migrate rebuilds an identical schema; every migration rolls back one at a time; schema equals `fixtures/schema.json` |
| `ReferenceDataTest` | Seeded data matches D1, D3, AC-4 and REC; nothing pending is seeded; bundles respect role boundaries |
| `IntegrityTest` | The database refuses data that breaks an approved rule (catalogue domains, D2 formats, System Owner and Technical Manager governance, exceptions, periods, active keys, hours traceability, one active certificate per period, same-volunteer chain, review periods, export formats, RESTRICT deletes). Each refusal must be a genuine integrity error |

CI runs all of them on MySQL 8.0, MySQL 8.4, MariaDB 10.6 and MariaDB 11.4, after migrating,
checking, fully rolling back and re-migrating through the `phinx` command line.

### Changing the schema later

Add a new migration (never edit a released one), run the tests, then regenerate the snapshot and
review its diff:

```bash
UPDATE_SCHEMA_SNAPSHOT=1 DB_TEST_HOST=… composer test:database -- --filter Snapshot
```

## 6. Implementation notes (choices inside the specification)

| Topic | Choice |
|---|---|
| D2 formats | Enforced by CHECK: `volunteers.reference` `^V-[0-9]{6}$`; `certificates.certificate_number` `^EHF-[0-9]{4}-[0-9]{5}$` |
| `roles.is_ordinary` | CHECK: 0 for `technical_manager` only ([A]) |
| `task_report_versions` | Holds the API §7.8 report fields frozen at submission; `title` required, other fields keep the draft's optionality (the specification says "content fields frozen at submission") |
| Columns listed without an explicit NULL mark | NOT NULL (for example `sessions.ip_prefix`, `notifications.link_route`, `project_staff.added_by_user_id`) |
| Permission `action_type` | From each code's endpoint in API Appendix A; field codes (read and write, API §6.7) recorded as `view` |
| Migration log | `schema_migrations` (Phinx), created by the first `migrate` |
| No self-replacement of certificates | Service check (a CHECK would reference the AUTO_INCREMENT id, which MySQL refuses — §2.2) |
