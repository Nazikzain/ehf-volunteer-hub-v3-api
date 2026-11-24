# Phase 4: how unresolved decisions are handled

Phase 4 (API V1.3.1 §18) covers authentication, sessions, CSRF/Origin protection, the permission
engine, System Owner and Technical Manager governance, audit and security controls. Its
decisions are listed in API §17. **None of them has been approved.** The code implements only
approved rules ([A]) and the interim behaviour the approved API itself defines ([IFC]); where
neither exists, the feature is disabled.

| Decision | What is pending | How Phase 4 handles it | Feature state |
|---|---|---|---|
| **A3** | Frontend/API domains; `SameSite` | `AUTH_SESSION_SAMESITE` must be set by configuration; no default | Sign-in **off** until set |
| **A4** | Session idle and absolute timeouts | `AUTH_SESSION_IDLE_MINUTES`, `AUTH_SESSION_ABSOLUTE_MINUTES`; no defaults | Sign-in **off** until set |
| **A5** | Invitation and reset token lifetimes | Needed only to **issue** tokens; issuing also needs email (A11) | Sending invitations / reset emails **off**. Token **redemption** (activate, reset) is implemented: it uses the expiry stored on the token |
| **A6** | Password policy | `AUTH_PASSWORD_MIN_LENGTH`, `AUTH_PASSWORD_MAX_LENGTH`; no defaults. No complexity or leaked-password rules are applied (not approved) | Sign-in, activation, reset and password change **off** until set |
| **A7** | Rate-limit values | `AUTH_RATE_LOGIN_ACCOUNT_MAX`, `AUTH_RATE_LOGIN_IP_MAX`, `AUTH_RATE_LOGIN_WINDOW_MINUTES`, `AUTH_RATE_TOKEN_IP_MAX`, `AUTH_RATE_TOKEN_WINDOW_MINUTES`; no defaults. Progressive slow-down ([R]) not implemented | Sign-in and token checks **off** until set |
| **RS4** | Password re-entry for owner and exception actions; command-line creation of the first owner | Password re-entry: applied, because `password_confirmation` is part of the approved request of every owner/exception endpoint (API §7.17); this is the stricter reading. **The first-owner command is not implemented** | Bootstrap **blocked** |
| **R7** | Number of owners; which role or access owners must hold | Final-owner protection (G7, [A]) guards the access recorded today: an active membership on an active account. Adding owners needs R7's eligibility rule, so `POST /system-owners` is **not registered** | Adding owners **blocked**; removal protected by G7 |
| **R7-C** | May an owner who also holds `technical_manager` grant/revoke it for others? | API §6.10 interim [IFC]: such an owner is **refused** on both endpoints | Implemented as the interim block |
| **R2 / R3** | Several roles per account; allowed combinations | API §6.11 interim [IFC]: **one active role per account (G10)**; the schema and permission engine already support several roles | Implemented as the interim rule |
| **R8** | Who besides owners may request or view exceptions | API interim [IFC]: owners only (governance codes come only from membership) | Implemented as the interim rule |
| **R19** | Which codes are exception-eligible; expiry rules | API interim [IFC]: no code is eligible (`permissions.exception_eligible` = 0 everywhere), so every new exception is refused (`422 validation_failed`). Authorise/reject/revoke are implemented and tested | Creating exceptions **effectively off** |
| **AC-6** | Source of `invitation_email` in the provisioning candidates list | Provisioning is Phase 5 (API §18) | Not in Phase 4 |
| **A11** (not a Phase 4 decision, but needed) | Email provider and emailed events | No email transport exists | `POST /users/{id}/invitation`, `POST /users/{id}/password-reset` and `POST /auth/password/forgot` are **not registered** |
| **A8** | Two-factor sign-in | Not included (API §17) | Not implemented |

Consequence: **in a real deployment nobody can sign in yet.** Sign-in needs the A3, A4, A6 and A7
values; a first account needs either the bootstrap (RS4/R7) or an invitation (A5/A11).
Everything is exercised end to end by the automated tests, which use clearly labelled test-only
configuration values.

## Recommendations used, and where they come from
Only items approved with REC (Workflow/Database V1.4 [R]) or fixed by the approved API are used:

- Status transitions for accounts (Workflow §3.1, REC): `invited → active` (activation),
  `active ↔ suspended`, `invited/active/suspended → deactivated`; no reactivation of deactivated
  accounts.
- A Technical Manager may not suspend or deactivate their own account (WF-16, REC).
- Lost-update protection with `version` and `Idempotency-Key` on creates (Workflow §2.2, REC).
- Refused attempts on sensitive endpoints are audited separately (WF-18, REC).
- Audit hash chain and verification (Database V1.4 `audit_logs`, D4).

API [R] items **not** adopted (not approved): leaked-password check, progressive slow-down,
writing `exception.expired` when expiry is noticed (an expired exception simply stops counting,
which is [A]), changing a sign-in email with re-verification (email changes are refused with
`422 field_not_editable` until decided), password hash cost parameters (PHP defaults are used).
