# Changelog

## Phase 3 — Database migrations (awaiting approval)

Implements MySQL Database Architecture V1.4 with approved decisions REC, D1–D5 and AC-4
(and A1, A2, H1 from Phase 2).

### Added
- Phinx 0.16 (A1) as a runtime dependency; `phinx.php` with a `target` environment for the
  separate migration user (`MIGRATION_DB_*`) and a `test` environment (`DB_TEST_*`).
  Migrations run through the application's connection factory (utf8mb4, UTC, strict mode).
- 18 schema migrations (M01–M18, Database V1.4 §6.3) building all 56 tables plus the
  `schema_migrations` log: 57 tables, 607 columns, 273 indexes, 177 foreign keys (all RESTRICT),
  99 named CHECK constraints. Every `down()` is implemented.
- S01 reference-data migration: 94 status values (interim/proposed values inactive), 4 roles,
  148 permissions (API §6.4 + AC-4), 120 role-bundle entries ([A] only), 15 field-catalogue rows,
  review scale and criteria from the frontend, and one empty pending setting (W7).
- D2 formats enforced by CHECK (`V-` + 6 digits; `EHF-YYYY-` + 5 digits).
- Database tests: migration lifecycle (empty → 57 tables, idempotent re-run, full and stepwise
  rollback, identical rebuild, committed schema snapshot), reference data, and 17 integrity tests
  that must be refused with a genuine integrity error.
- CI: on MySQL 8.0 / 8.4 and MariaDB 10.6 / 11.4, migrate → status → full rollback → migrate via
  the `phinx` command line, then the full database suite. Releases now include `database/`,
  `phinx.php` and `vendor/bin/phinx`.
- docs/database.md: database creation (placeholders only), the two users, migrations, rollback
  rules, reference data and tests.

### Not included (pending or out of scope)
Sensitive field codes (R6/D3), [R] bundle entries of API §6.5, retention policies (S4), the
bootstrap System Owner and Technical Manager grant (RS4/R7), and all Phase 4 work
(authentication, sessions, permission engine).

### Remaining blockers for Phase 4
1. Project-owner approval of Phase 3.
2. Phase 4 decisions still open: RS4, R7, R7-C, R2/R3 (or confirm the interim G10), R8, R19,
   A3–A7, and AC-6 for the provisioning contract.
3. Read-only hosting checks (docs/hosting-compatibility-checklist.md), at least items 4–6
   (engine and version, separate users, CHECK support) before any real database is created.

## Phase 2 — Backend foundation (awaiting approval)

Implements the Phase 2 scope of Backend API Architecture V1.3.1 §18, using approved decisions
A1, A2 and H1.

### Added
- Slim 4 + php-di application with the layered structure of API §2.5 / §2.11.
- Request pipeline in the order of API §2.12: request ID (ULID), security headers (§15.2),
  CORS with an exact allowlist, central JSON error handling, JSON body parsing, routing.
- Standard success and error formats (§4.2–4.3); `ErrorCode` covering exactly the §4.3 table.
- `GET /api/v3/health` (public, Appendix A.1).
- Environment-based settings that fail closed; private `.env`; secret redaction in logs.
- Database connection factory (utf8mb4, UTC, strict mode, native prepared statements) and
  engine detection for MySQL 8.0.16+ / MariaDB 10.6+ (A2). No connection is opened in Phase 2.
- OpenAPI 3.1 contract skeleton with contract tests (route coverage, required `x-*`
  annotations, response schemas, error-code enum).
- PHPUnit, PHPStan (max level, strict rules), PHP-CS-Fixer and `composer audit`.
- CI: quality job, database compatibility matrix (MySQL 8.0, 8.4; MariaDB 10.6, 11.4) and a
  release build with production dependencies (H1).
- Documentation: README, architecture notes, deployment preparation, hosting checklist.

### Not included (by instruction)
Database tables and migrations, role bundles and permission seeds, sessions, authentication,
CSRF, rate limiting, and all business workflows.

### Remaining blockers for Phase 3
1. Project-owner approval of Phase 2.
2. Decisions D1–D5 and AC-4 (field-code seeds), and approval of the bundled recommendations
   (REC) in the decision package.
3. Read-only hosting checks, at least the database engine and version, database user
   privileges and CHECK-constraint support (docs/hosting-compatibility-checklist.md items 4–6).
4. Phinx (approved under A1) to be added when migrations begin.
