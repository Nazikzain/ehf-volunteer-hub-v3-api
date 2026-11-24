# EHF Volunteer Hub V3 — Backend API

The new, independent PHP backend for the Ethnic Health Forum Volunteer Hub V3: a REST API under
`/api/v3`, built with Slim 4 and php-di.

| | |
|---|---|
| **Status** | **Phase 3 — database migrations** (on top of the Phase 2 foundation). Schema and reference data only; no authentication or business workflows yet |
| **Specifications** | Backend API Architecture V1.3.1 (approved) · MySQL Database Architecture V1.4 · Workflow & Business Rules V1.4 · Final Architecture Decision Package |
| **Approved decisions used** | **A1** Slim 4 + php-di, Phinx, PhpSpreadsheet, mPDF, PHPMailer · **A2** MySQL 8.0.16+ and MariaDB 10.6+ compatibility · **H1** Git/SSH deployment preferred, FTP with checksums otherwise; dependencies built in CI and shipped · **REC, D1–D5, AC-4** (Phase 3) |
| **Frontend** | Separate repository `ehf-volunteer-hub-v3-frontend` |

Nothing from the old backend, database, SQL, endpoints, authentication, configuration or secrets
is reused (API §2.4).

## What Phase 3 adds

- **Phinx migrations** that build the complete 57-table schema of MySQL Database Architecture
  V1.4 in an empty database: 177 foreign keys (all `RESTRICT`), 99 named CHECK constraints,
  InnoDB / `utf8mb4_unicode_ci` throughout.
- **Reference data** as a versioned migration: status catalogue, roles, the API §6.4 permission
  codes (D1) plus the AC-4 contact codes, the approved ([A]) role bundles, the field catalogue (D3),
  review scale and criteria, and empty pending settings. Nothing pending, interim or merely
  recommended is seeded.
- **Database tests** run on MySQL 8.0 / 8.4 and MariaDB 10.6 / 11.4: migrations from empty,
  idempotent re-run, full and step-by-step rollback, identical rebuild, a committed schema
  snapshot (engine parity), reference-data rules and integrity rules.
- See **[docs/database.md](docs/database.md)** for creating the database and running migrations.

## What Phase 2 contains

- Layered project structure: routes, middleware, controllers, validators, services,
  authorisation policies (reserved), repositories (reserved), configuration and tests
  ([docs/architecture.md](docs/architecture.md)).
- The request pipeline in the order of API §2.12: request ID → security headers → CORS →
  central error handling → JSON parsing → routing. Session, Origin/CSRF, authentication and
  rate limiting have fixed places in the order and arrive in Phase 4.
- Standard JSON success and error formats (API §4.2–4.3), with every error code from §4.3.
- `GET /api/v3/health` (public).
- Environment-based configuration that fails closed, with secrets only in a private `.env`.
- Database connection factory and engine detection, ready for Phase 3 (no connection is opened).
- OpenAPI 3.1 contract skeleton ([openapi/openapi.yaml](openapi/openapi.yaml)) with contract tests.
- Quality tooling: PHPUnit, PHPStan (max level, strict rules), PHP-CS-Fixer, `composer audit`.
- CI: quality checks, database compatibility on four engines, and a deployment-ready release package.

## Requirements

- PHP **8.3** or later with `pdo_mysql`, `mbstring`, `json`
- Composer 2
- For the database compatibility tests only: Docker, or any throwaway MySQL/MariaDB server

## Local setup

```bash
composer install
cp .env.example .env
composer serve
```

Then open <http://127.0.0.1:8080/api/v3/health>:

```json
{"data":{"status":"ok"},"meta":{"request_id":"01JD8…"}}
```

`.env` is never committed. In `.env.example`, `APP_ENV=local` turns HSTS off and allows `http://`
CORS origins for local development. **An unset `APP_ENV` means `production`** (strictest rules).

### Configuration

| Variable | Meaning | Default |
|---|---|---|
| `APP_ENV` | `local`, `testing`, `staging` or `production` | `production` |
| `APP_BASE_PATH` | Path prefix if the API is not at the root of its host (e.g. `/backend`) | empty |
| `CORS_ALLOWED_ORIGINS` | Exact frontend origins, comma-separated; no wildcards; `https` only in staging/production | none (no cross-origin access) |
| `HSTS_ENABLED` | Send `Strict-Transport-Security` (cannot be off in staging/production) | on in staging/production |
| `MAX_JSON_BODY_BYTES` | Largest JSON request body | `1048576` |
| `LOG_PATH`, `LOG_LEVEL` | Application log file (JSON lines) and level | `storage/logs/app.log`, `info` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Runtime database user (Phase 3 onwards) | unset |

Invalid configuration stops the application at start-up with a generic `500` response; the
reason (the variable name, never its value) goes to the PHP error log.

Settings that depend on pending decisions do not exist yet: session and token lifetimes (A4, A5),
password policy (A6), rate limits (A7), domains and `SameSite` (A3), email (A11).

## Database

The schema is created only by migrations, from the command line, by a separate migration user
(API §15.2). Nothing is imported from the old system. Full instructions — creating the empty
database and its two users, running and checking migrations, rollback rules, reference data and
tests — are in [docs/database.md](docs/database.md).

```bash
vendor/bin/phinx migrate -e target
```

## Tests and quality checks

```bash
composer test            # unit, HTTP and OpenAPI contract tests
composer analyse         # PHPStan, max level + strict rules
composer cs:check        # PHP-CS-Fixer (dry run); composer cs:fix to apply
composer security        # composer audit --locked
composer check           # all of the above
```

### Database compatibility tests

They need a **throwaway** server and create only temporary tables. Never point them at a real
database. For example, with Docker:

```bash
docker run -d --name ehf-dbtest -p 127.0.0.1:33061:3306 -e MARIADB_ROOT_PASSWORD=throwaway -e MARIADB_DATABASE=ehf_ci mariadb:10.6
```

```bash
DB_TEST_HOST=127.0.0.1 DB_TEST_PORT=33061 DB_TEST_NAME=ehf_ci DB_TEST_USER=root DB_TEST_PASSWORD=throwaway composer test:database
```

```bash
docker rm -f ehf-dbtest
```

CI runs them on `mysql:8.0`, `mysql:8.4`, `mariadb:10.6` and `mariadb:11.4`.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

1. **quality** — `composer validate --strict`, code style, PHPStan, tests, `composer audit`.
2. **database** — on each of the four engines: migrate from empty, roll everything back and migrate
   again through the `phinx` command line, then run the database test suite.
3. **build** — after both pass, builds the release package and uploads it as an artifact.

## Release package and deployment preparation

```bash
bin/build-release.sh
```

This produces `dist/ehf-v3-api-<version>.zip`, containing production dependencies, a per-file
`CHECKSUMS.sha256` and a `RELEASE` file. It refuses to build if dev tools, tests or `.env` would
be shipped, and it smoke-tests `GET /api/v3/health` from the package itself. See
[docs/deployment.md](docs/deployment.md).

**No deployment has been made.** Deployment needs separate approval, and the read-only hosting
checks in [docs/hosting-compatibility-checklist.md](docs/hosting-compatibility-checklist.md)
have not yet been run.

## Project layout

```
public/            index.php (the only web-served file) and .htaccess
config/            container definitions, middleware order, routes
database/          Phinx migrations and reference data (Phase 3)
phinx.php          Phinx configuration (command line only)
src/
  Bootstrap/       application assembly, fallback error response
  Config/          environment loading and validated settings
  Http/            controllers, middleware, errors, JSON responder
  Validation/      validator base (unknown-field rejection)
  Services/        business services
  Authorisation/   policies (Phase 4)
  Repositories/    data access (Phase 3+)
  Infrastructure/  request IDs, logging, database connections
  Database/        migration base class and Phinx configuration
openapi/           OpenAPI 3.1 contract and its changelog
tests/             Unit, Http, Contract, Database
docs/              architecture notes, deployment, hosting checklist
bin/               release build script
deploy/            files placed into the release (root .htaccess)
storage/logs/      private application logs (not committed)
```

## Phase boundary

Phase 3 stops here. Authentication, sessions and the permission engine (Phase 4) start only after
approval. See the remaining blockers in [CHANGELOG.md](CHANGELOG.md).
