# V6 local identity and opaque sessions

The controlling design is [round-5-local-auth/ADR.md](round-5-local-auth/ADR.md).
Execution/gaps are recorded separately in
[RUNTIME_RESULTS.md](round-5-local-auth/RUNTIME_RESULTS.md). The predecessor's
distributed-provider saga description is archived byte-for-byte in
[history](local-auth/history/SESSIONS.pre-v6.md), not a current operator procedure.

## Identity and revocation scope

- PHP owns local password hashes, account-disabled/review state and persisted
  reviewed administrator privileges. Registration creates ordinary users only.
- Password, PIN and biometric login retain the mobile token DTO and opaque
  `app_access_` / `app_refresh_` prefixes. Only SHA256 verifiers are stored.
- Password families are independent of device credential families. Deleting or
  terminally locking one PIN/biometric credential revokes its own descendants,
  not another device/user or a password family.
- Logout revokes every family of the authenticated user, regardless of supplied
  refresh-token text. Password change verifies the old password then updates the
  hash and revokes prior families in one SQL transaction.
- Refresh is single-use and constrained by family expiry, user security version
  and current disabled/review/recovery state. A lost refresh response requires
  sign-in; it is not answered by storing/replaying raw tokens.

## Concurrency and existing accounts

Authoritative checks serialize on the user row before credential/family/token
locks. Password verification performed outside SQL must be revalidated against
the locked hash/security-version/account snapshot before issuing or changing a
password. Failed attempts must commit their counters before returning an error.

Existing accounts require explicit per-user attestation/reset. Preserve UUIDs,
commerce links, device credentials and historical audit. Empty local hashes do
not become default passwords. Unreviewed, disabled or recovery-blocked users
cannot bypass restrictions via preserved fast-login credentials.

Historical `password_session_operations` remain evidence/manual decisions. New
password changes do not enqueue remote reset/revocation work. Never bulk-clear
`sessions_blocked` or infer roles/bans from the archived policy. Trusted console
actions require independently reviewed identity/roles/disabled state and a ticket;
secrets belong in protected TTY input, not argv, environment dumps or logs.

## Gates

Retain real SQL password/session/credential races, cross-user isolation, replay,
expiry, disabled/review-state matrix, hash/rollback/uncertain-commit tests, API
policy coverage and no-secret diagnostics. The active graph guard has no
live-source exceptions; coordinator-owned adapters/Composer/tests must be removed
or replaced before the integration gate can pass. See
[migration/rollback](MIGRATION_ROLLBACK.md) for the operational boundary.
