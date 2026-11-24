# OpenAPI contract changelog

Contract changes per phase (API V1.3.1 §12). Additive changes bump the minor version;
breaking changes need `/api/v4` and the project owner's approval.

## 0.5.0 — Phase 5 (volunteer records, provisioning, own profile)
- 13 operations: `GET`/`POST /volunteers`, `GET`/`PATCH /volunteers/{id}`, `POST /volunteers/{id}/accept`,
  `POST /volunteers/{id}/status-changes`, `GET /volunteers/{id}/status-history`,
  `GET`/`POST /volunteers/{id}/notes`, `GET /portal-provisioning/candidates`,
  `POST /volunteers/{id}/portal-account`, `GET`/`PATCH /me/profile` (55 operations in total).
- Schemas `Volunteer` (each field tagged `x-field-set`), `ProvisioningCandidate`, `PortalAccountResult`.
- **AC-6 Option 1 clarification (approved; recorded here only, the approved API V1.3.1 text is
  unchanged):** in `GET /portal-provisioning/candidates`, `invitation_email` is nullable. It is
  populated only from the volunteer's recorded contact email (shown in full, R18) and is `null`
  otherwise. When it is `null`, `POST /volunteers/{id}/portal-account` must include
  `invitation_email`, or it is refused with `422 validation_failed`. No endpoint, permission,
  guard or audit event changes.
- `PortalAccountResult.invitation` is `null` when no invitation is sent (`send_invitation: false`).
  Sending needs A5 and A11, so `send_invitation: true` returns `503 maintenance` for now.

## 0.4.0 — Phase 4 (authentication, permissions, governance, audit)
- 41 operations for authentication and sessions, accounts, roles, grants, sessions, System Owner
  governance, operational exceptions, audit logs and system health (42 with `/health`).
- `SuccessResponse` schema and the `sessionCookie` security scheme. The contract test checks
  that every route's declared permission equals its `x-permission`.
- Endpoints blocked by pending decisions are not included (docs/phase-4-decisions.md).

## 0.2.0 — Phase 2 (backend foundation)
- `GET /health` (public).
- Shared components: `RequestId`, `Meta`, `ErrorCode` (exactly the API §4.3 list),
  `ErrorDetail`, `ErrorResponse`, the `Error` response and the `X-Request-Id` header.
- Per-operation annotations (`x-action-type`, `x-permission`, `x-scope`, `x-context`,
  `x-field-sets`, `x-guards`, `x-workflow-dependency`, `x-audit`) are required on every
  operation and checked by `tests/Contract`.
- Single file for now; split into `paths/` and `components/` files as modules are added.
