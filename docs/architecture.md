# Backend architecture (implementation notes)

The authoritative specification is **Backend API Architecture V1.3.1 (approved)**, with
**MySQL Database Architecture V1.4** and **Workflow & Business Rules V1.4**. This file maps the
specification to the code; where the two ever differ, the specification wins.

## Layers (API §2.5, §2.11)

| Layer | Location | Responsibility | Must never |
|---|---|---|---|
| Routes | `config/routes.php` | Method + path → controller; one primary permission code per route (recorded in OpenAPI `x-permission`) | Contain logic |
| Middleware | `src/Http/Middleware/`, order in `config/middleware.php` | Request ID, security headers, CORS, JSON parsing; later session, Origin/CSRF, authentication, rate limiting | Make business decisions |
| Controllers | `src/Http/Controllers/` | HTTP request → service call → response format | Query the database or decide permissions |
| Validators | `src/Validation/` | Shapes, types, lengths; rejection of unknown fields | Check permissions |
| Services | `src/Services/` | Business rules, transactions, audit and notification events | Build SQL or read raw request data |
| Authorisation policies | `src/Authorisation/` (Phase 4) | Permission, scope, guards G1–G10, field visibility | Change data |
| Repositories | `src/Repositories/` (Phase 3+) | Prepared SQL, permitted columns only | Decide permissions |
| Database | `database/migrations/` (Phinx), reference data in `database/reference/` — see [database.md](database.md) | Integrity constraints as a second line of defence | — |

Supporting code: `src/Config/` (environment and validated settings), `src/Infrastructure/`
(request IDs, logging, database connections), `src/Bootstrap/` (application assembly).

## Request pipeline (API §2.12)

Outermost first, as defined in `config/middleware.php` and enforced by `tests/Unit/Bootstrap/PipelineTest`:

| # | Step | Status |
|---|---|---|
| 1 | Request ID (`X-Request-Id`, ULID, server-generated) | Phase 2 ✔ |
| 2 | Security headers (CSP, nosniff, Referrer-Policy, no-store, HSTS in staging/production) | Phase 2 ✔ |
| 3 | CORS (exact allowlist, credentials, no wildcards) | Phase 2 ✔ |
| — | Central error handling (wraps everything inside it) | Phase 2 ✔ |
| 4 | JSON parsing (JSON objects only, size limit, 415 for other types) | Phase 2 ✔ |
| — | Routing (before the session, so later steps can read the route's permission) | Phase 2 ✔ |
| 5 | Session (`SessionMiddleware`) | Phase 4 ✔ |
| 6 | Origin and CSRF check (`OriginCsrfMiddleware`) | Phase 4 ✔ |
| 7 | Authentication and the route's permission (`AuthenticationMiddleware`) | Phase 4 ✔ |
| 8 | Rate limiting (`RateLimitMiddleware` for token endpoints; sign-in limits in `AuthService`) | Phase 4 ✔ |
| — | Repeat protection for creates (`IdempotencyMiddleware`, API §4.1) | Phase 4 ✔ |

## Authentication, sessions and permissions (Phase 4)

Pending decisions are handled as described in [phase-4-decisions.md](phase-4-decisions.md).

| Topic | Implementation |
|---|---|
| Passwords | Argon2id (PHP default parameters); policy = configured length only (A6) |
| Sign-in | Same `401 invalid_credentials` for every failure; dummy hash when no account matches; failures limited per account and per IP (A7) → `429` with `Retry-After` |
| Sessions | Server-side (`sessions`). Cookie `__Host-ehf_session` (Secure, HttpOnly, Path=/, SameSite from A3) holds 256 random bits; only the SHA-256 is stored. Valid only while not revoked, within the idle and absolute limits (A4), the account is active and `session_version` is unchanged |
| Signing out everywhere | `session_version` is increased on password change or reset, suspension, deactivation, and any role, grant, exception or owner change |
| CSRF / Origin | Every POST, PUT, PATCH and DELETE needs an allowed `Origin`; signed in, also `X-CSRF-Token` (derived from the session ID by HMAC; returned by `GET /auth/session`) |
| Route permission | Each route declares exactly one code (`RouteAccess`, mirrored by OpenAPI `x-permission`, checked by the contract test); a route without one fails closed with 500 |
| Effective permissions | Union, per request, of role bundles + active unexpired grants + authorised unexpired exceptions (Technical Managers only) + governance codes from an active owner membership. No caching: revocation and expiry apply on the next request. No code implies another |
| Guards | G3, G4, G5, G7 (locked owner rows), G8, G10 (interim), R7-C (interim block), R8 and R19 interims — in `AccessService`, `GovernanceService`, `AccountService` |
| Audit | `AuditLogger`: success records inside the action's transaction; refusals (route gate, guards, sign-in failures) recorded separately; SHA-256 hash chain checked by `GET /audit-logs/verify`; shortened IP addresses; no secrets or personal values in details |
| Lost updates | `version` on account status actions and exception decisions (`409 stale_version`) |

## Volunteer data and field privacy (Phase 5)

See [phase-5-decisions.md](phase-5-decisions.md).

| Topic | Implementation |
|---|---|
| Field visibility | `Authorisation\FieldPolicy` reads `field_catalogue`: `basic` fields for anyone who may see the record; `contact` and `sensitive` fields only with their own code (read and write); uncatalogued personal columns are inaccessible. Unreadable fields are omitted; `meta.field_sets` lists the sets returned |
| Writes | Sending a field the caller may not write → `403 field_not_permitted` (audited as refused), before any value validation |
| Decision context (R13) | Bundle codes listed in `FieldPolicy::DECISION_CONTEXT_ONLY` are tracked as context-only by `PermissionResolver`; `Principal::canOnRecords()` ignores them, `VolunteerService::decisionContext()` honours them |
| Own profile (R12) | Record from the session only; every own field readable; only `FieldPolicy::SELF_EDITABLE` writable; never notes |
| Provisioning | Account data only; the link `volunteers.user_id` is the only volunteer column written; the address never goes back to the volunteer record |
| Audit | Field names only, never values |

## Response formats (API §4.2–4.3)

Success:

```json
{ "data": { "status": "ok" }, "meta": { "request_id": "01JD8…" } }
```

Error:

```json
{ "error": { "code": "not_found", "message": "The requested resource was not found.", "details": [], "request_id": "01JD8…" } }
```

- Error codes come only from `Http\Error\ErrorCode`, which is exactly the API §4.3 table
  (checked against an independent copy of the table and against the OpenAPI enum).
- Unexpected errors return `500 internal_error` and are logged with the request ID; stack traces,
  SQL, file paths and exception messages are never returned.
- An unknown path and an unsupported method on a known path both return `404 not_found`
  (API §4.3 has no 405 code; §4.4 prefers not revealing what exists).
- If the application cannot start (for example invalid configuration), `Bootstrap\FallbackResponse`
  returns the same `500` format with the same security headers.

## Implementation choices made in Phase 2 (not decisions)

These are technical defaults inside approved rules; none of them settles a pending decision.

| Choice | Value | Why |
|---|---|---|
| Request ID format | ULID (26 characters) | Matches `audit_logs.request_id CHAR(26)` (Database V1.4 §5.12); sortable by time |
| Client `X-Request-Id` | Ignored | Server-generated IDs can be trusted in logs and audit records |
| JSON body limit | 1 MiB default (`MAX_JSON_BODY_BYTES`) | Uploads (A9) use multipart and get their own limits in Phase 7 |
| Maximum JSON depth | 64 | Refuses pathological nesting |
| HSTS | `max-age=31536000; includeSubDomains`, on in staging/production | API §15.2 requires HSTS; no `preload` (would need a separate decision) |
| CORS preflight cache | 600 seconds | Short, so allowlist changes take effect quickly |
| `.env` loader | `vlucas/phpdotenv` (runtime dependency) | Reads the private `.env`; real environment variables take precedence |
| Logs | Monolog JSON lines, secrets redacted by key name | API §3, §11 |
| SQL session settings | utf8mb4 / utf8mb4_unicode_ci, UTC, strict mode, native prepared statements | Same behaviour on MySQL and MariaDB (A2) |

## Not yet implemented (by phase)

- Phase 3 added the schema and reference data (see [database.md](database.md)). Phinx is a runtime
  dependency so that migrations can run from a release without Composer (H1).
- Phase 4 added authentication, sessions, CSRF/Origin protection, the permission engine,
  governance and audit (see above).
- Phase 5 added volunteer records, provisioning and the own profile (see above).
- Still not implemented: the business workflows of Phases 6–11 and the endpoints blocked by
  pending decisions (phase-4-decisions.md, phase-5-decisions.md). PhpSpreadsheet, mPDF and PHPMailer (approved
  under A1) are added in the phase that first uses each.
