# OpenAPI contract changelog

Contract changes per phase (API V1.3.1 §12). Additive changes bump the minor version;
breaking changes need `/api/v4` and the project owner's approval.

## 0.2.0 — Phase 2 (backend foundation)
- `GET /health` (public).
- Shared components: `RequestId`, `Meta`, `ErrorCode` (exactly the API §4.3 list),
  `ErrorDetail`, `ErrorResponse`, the `Error` response and the `X-Request-Id` header.
- Per-operation annotations (`x-action-type`, `x-permission`, `x-scope`, `x-context`,
  `x-field-sets`, `x-guards`, `x-workflow-dependency`, `x-audit`) are required on every
  operation and checked by `tests/Contract`.
- Single file for now; split into `paths/` and `components/` files as modules are added.
