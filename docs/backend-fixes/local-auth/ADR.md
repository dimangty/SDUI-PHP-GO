# Local identity: operator migration decision

Status: implemented for disposable verification; **production migration NOT_EXECUTED**.
Controlling design: [round-5 ADR](../round-5-local-auth/ADR.md), task 3 of its
[plan](../round-5-local-auth/PLAN.md).

## Decision

PHP owns account identity and the existing opaque session store. An existing
`users.id` is never replaced, merged or inferred from an email. Schema 015 leaves
preexisting users `local_identity_ready=false`; future trusted inserts default to
ready. That default makes draining every old writer a cutover prerequisite.

`LocalIdentityMigration` supplies read-only `inventory()` and three console-only
selected-user operations: `attest`, `resetPassword`, and `setDisabled`. Activation
requires an explicit admin **and** disabled-state attestation, with a ticket. Local
SQL roles and bans are authoritative after review; there is no trustworthy realm
export in this task, and neither an old email nor an old device proves ownership.

Schema 016 adds exactly one table, `local_identity_migration_journal`. Existing
session/recovery tables already hold revocation truth. A durable journal is needed
to bind each operator ticket to one UUID, action, and nonsecret reviewed request,
and to distinguish a committed reset from a retry after an uncertain response.
It records final security version, role, ready/disabled/fence flags, SQL login and
time. It has no password, password hash, token, email, or password-derived request
fingerprint. UPDATE, DELETE and TRUNCATE are rejected by an append-only trigger.
The user FK preserves evidence rather than cascading its deletion.

## Concurrency and idempotence

- Every mutation owns a transaction and locks the user first through
  `SessionService::lockUser`, then the ticket advisory lock and selected recovery
  operation, then families/tokens. No remote calls occur.
- Password validation/hash computation happens outside SQL transactions. The
  locked user must still match the prepared password/security/account snapshot,
  email, role and password-change timestamp. Otherwise the action is rejected.
- Credential replacement, all-family revocation, any selected historical
  completion/fence change, and the journal insertion commit together. Audit
  failure rolls back the entire action.
- A ticket is globally unique for **one action on one UUID**. Repeating identical
  reviewed metadata returns `already_applied` without changing any state.
  Different UUID/action/role/state/operation/outcome with that ticket is rejected.
- A reset retry is a **receipt check**, not another password assignment. Password
  material is excluded from the journal. The supplied secret must verify against
  the existing normal credential, outside SQL, and the user's security version
  must still match that receipt. A different secret is a ticket conflict; a later
  security mutation makes the ticket superseded. A locked snapshot recheck also
  covers receipt verification races. Setting another password needs a new ticket;
  an old retry can never overwrite a newer reset.

## Recovery and bans

Attestation does not clear `sessions_blocked`. A reset without a selected operation
does not clear it either. Historical recovery can be completed only by a reset
that selects the operation UUID, verifies its user, independently confirms that
identity, and records the reviewed remote reset outcome. Only that operation is
completed. The current fence is cleared only when no unresolved operations remain.
An orphan fence requires a separate investigation and explicit
`--orphan=confirmed-investigated` owner-verified password reset. Under the user
lock this refuses every unresolved historical operation, replaces the verifier,
revokes sessions, clears that fence and journals the decision atomically. Ordinary
resets/attestations never implicitly clear an orphan fence.

## Provider responsibility transfer

| Previous function | Local implementation / storage | Regression | Migration |
| --- | --- | --- | --- |
| Registration / password grant | AuthService, PasswordHasher, UserCredentialsRepository; users + cart metadata + existing sessions | local_auth_core, auth, api | 015 |
| Password hashing / policy | Native Argon2id PHC; users.password_hash; proven bounded legacy adapter only | run, local_auth_core, password_budget | No hash conversion by SQL |
| Temporary brute-force lock | Trusted-IP RatePolicy + 4,096 account slots before hash work; durable login_attempts | throttling, trusted_rate_limits, local_auth_core | Existing 010 tables |
| Disabled account / role grants | users.account_disabled, local_identity_ready, is_admin; selected operator journal | opaque_authorization, local_auth_migration, session_concurrency | 015–016 |
| Access / refresh / logout | Existing SessionService; hashed secrets, security versions and absolute family expiry | session_revocation, session_concurrency, logout_regression, session_retention | 015 extends 013 |
| Password update / session revocation | One local SQL transaction, no distributed password saga | session_password_recovery, local_auth_concurrency | Historical 013–014 evidence retained |
| Pending identity recovery | Explicit selected-operation or investigated-orphan owner reset, never scheduled remote calls | local_auth_migration, local_recovery | 016 audit |
| PIN / biometric sessions | Existing CredentialService/Secret protocol, shared SessionService; admin PIN denied under user lock | api, uuid_identity, session_concurrency | Existing keys/PINs retained |

The archived realm actually supplied password policy (length 8, upper/lower/digit,
notUsername), brute-force threshold 5, 900-second increments / 3,600-second maximum,
enabled-state and realm application roles. It specified access TTL 300 seconds,
online idle 1,800 and online maximum 43,200 seconds; password grants requested
offline access, so these online values are not an opaque-session equivalence.
The local policy uses a fixed temporary 900-second lock after five failures,
plus network/account admission, and bounded 300-second access / 30-day refresh
and absolute family defaults. Reviewed roles/bans replace realm claims explicitly;
they have not been imported from real users in this task.

`account_disabled` is independent of recovery. Attestation/reset cannot lift an
existing ban. A separate, ticketed `set-disabled --disabled=no` decision is
required. A role re-attestation also cannot silently change reviewed disabled state.

## Rejected alternatives

Bulk readiness/unblock, copying guessed external roles, automatic email conflict
merges, default passwords, a second session store, and replaying historical remote
password operations cannot preserve the required trust/evidence boundaries.

See [user migration](USER_MIGRATION.md), [security](SECURITY_MODEL.md),
[API compatibility](API_COMPATIBILITY.md), [rollout](ROLLOUT_ROLLBACK.md), and
[native evidence](../round-5-local-auth/MIGRATION_RESULTS.md).
