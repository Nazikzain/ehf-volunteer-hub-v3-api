# Changelog

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
