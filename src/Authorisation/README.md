# Authorisation policies layer

**Status:** reserved; implemented in Phase 4 (API V1.3.1 §6, §18).

**Responsibility (API §2.11):** decide, for each action on each record, the permission, the
scope or decision context, separation of duties (guards G1–G10), and field-level visibility
for both the fields written and the fields returned.

**Must never:** change data.

Nothing is implemented here in Phase 2, deliberately:

- Role bundles and permission seeds wait for Phase 3 (database) and Phase 4.
- Several Phase 4 inputs are still pending: R2/R3 (or confirmation of the interim G10), R7,
  R7-C, R8, R19 and RS4.

No placeholder policy grants or denies anything.
