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
| Database | Phinx migrations (Phase 3) | Integrity constraints as a second line of defence | — |

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
| 5 | Session | Phase 4 (A3, A4, D4) |
| 6 | Origin and CSRF check | Phase 4 |
| 7 | Authentication | Phase 4 |
| 8 | Rate limiting | Phase 4 (A7) |
| — | Routing → controller | Phase 2 ✔ |

Steps 5–8 are not stubbed: a placeholder that "passes" would look like protection without being
it. No route needs them yet; the only route is the public health check.

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

## Not in Phase 2 (by instruction)

No database tables or migrations; no role bundles or permission seeds; no sessions,
authentication, CSRF or rate limiting; no volunteer, project, task, hours, review or certificate
workflows. Phinx, PhpSpreadsheet, mPDF and PHPMailer (approved under A1) are added in the phase
that first uses each, so that no unused code is shipped.
