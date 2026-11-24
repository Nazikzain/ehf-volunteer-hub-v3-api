# Phase 5: approved decisions and what stays pending

Phase 5 (API V1.3.1 §18) covers volunteer records, portal account provisioning and `/me/profile`.
The project owner approved **W1, R6, R12, R13, R18 and AC-6 (Option 1)**. Nothing else was
approved; every other decision keeps its interim behaviour or stays disabled.

## How each approved decision is applied

| Decision | Implementation | Where |
|---|---|---|
| **W1** statuses | Volunteer: `draft → accepted` (`POST /volunteers/{id}/accept`, a separate event), `accepted → active`, `active ↔ inactive`, `accepted/active/inactive → ended`, `draft → withdrawn`; ended and withdrawn are final. Account statuses unchanged from Phase 4 (no reactivation of deactivated accounts) | `VolunteerService::TRANSITIONS` |
| **W1** eligibility | Portal accounts only for `accepted` or `active` volunteers without an account | `ProvisioningService::ELIGIBLE` |
| **W1** validation | Full name required, plus an email or a phone number (on create and after every edit) | `VolunteerService` |
| **W1** duplicates | Same contact email, ignoring case (stored in lower case) → `409 duplicate`; a duplicate name alone is not refused (manual check) | `VolunteerRepository::emailInUse` |
| **W1** ended volunteers | Ending or withdrawing never changes a linked account | No code path touches the other side |
| **R6** | Migration S02 seeds the eight `fields.sensitive.<column>` codes, catalogues the eight columns as `sensitive`, and adds the eight codes plus `confidential.volunteer_notes.view`/`.create` to the Full Admin bundle | `database/reference/phase5_field_access.php` |
| **R12** | Volunteers may edit: preferred name, phone, address lines 1–2, town, postcode, emergency contact name, phone and relationship. Anything else → `422 field_not_editable` | `FieldPolicy::SELF_EDITABLE` (the schema has no place to store it; no new code is needed) |
| **R13** | S02 adds `fields.contact.email`/`.phone` to the Monitoring Admin bundle. The resolver marks codes that come *only* from these bundle entries as context-only; they count inside a decision context and never on volunteer record endpoints. An explicit grant still works as the approved "explicit authorisation" | `FieldPolicy::DECISION_CONTEXT_ONLY`, `PermissionResolver`, `VolunteerService::decisionContext()` |
| **R18** | Candidates list shows the invitation email in full, only to holders of `accounts.provision` | `ProvisioningService::candidates` |
| **AC-6** Option 1 | `invitation_email` in candidates is the recorded contact email or `null`; provisioning requires `invitation_email` when it is `null` (`422 validation_failed`). Recorded in `openapi/openapi.yaml` (`ProvisioningCandidate`) and `openapi/CHANGELOG.md` 0.5.0 only; the approved API text is unchanged | `ProvisioningService` |

Also applied (REC, approved earlier): `version` on every PATCH and action request; reading
confidential notes is audited (`note.viewed`); a user may not accept or change the status of a
volunteer record linked to their own account (Workflow WF-03 SoD).

## Still pending or disabled

| Item | State in Phase 5 |
|---|---|
| **A5, A11** (token lifetimes, email) | Provisioning works with `send_invitation: false`. `send_invitation: true` (the default) returns `503 maintenance` and creates nothing. `POST /users/{id}/invitation` stays unregistered. Activation by token works once a token exists |
| **AC-2** (role at provisioning) | Not applied: the `volunteer` role is assigned separately with `POST /users/{id}/roles` |
| **R14** (Monitoring Admin browsing) | Not granted: Monitoring Admin has no volunteer record endpoint |
| **R17** (review and decision notes) | Not granted |
| Decision contexts | The projection exists (`decisionContext()`) and is tested; the endpoints that return it (hours, task reports, reviews) arrive in Phases 7–9 |
| "Flag for the Technical Manager" when a volunteer ends (W1) | No account change is made. Surfacing the flag needs the technical dashboard or notifications (Phase 11, A11) |
| A3, A4, A6, A7, RS4, R7 | Unchanged from Phase 4: sign-in stays off in any real deployment |
