# B5–B6: opaque session revocation and Keycloak logout

## Implemented boundary

Owned production files:

- `backend-yii2/application/AuthService.php`
- `backend-yii2/application/SessionService.php`
- `backend-yii2/application/CredentialService.php`
- `backend-yii2/application/PasswordSessionRecovery.php`
- `backend-yii2/infrastructure/Keycloak.php`
- `tools/ops/migrations/013_session_revocation.up.sql`
- `tools/ops/migrations/013_session_revocation.down.sql`

Dedicated tests and execution evidence are listed in [SESSION_TEST_RESULTS.md](SESSION_TEST_RESULTS.md).

## Session identity and revocation scope

Every newly issued opaque access/refresh pair belongs to a `session_families` UUID. The family records its user, the user's security version, and either a verified server-side PIN/biometric credential UUID or `internal` identity. Token tables store SHA-256 digests only. Rotation consumes a refresh digest exactly once and issues the next pair in the **same family**; it cannot erase credential linkage by creating an unlinked family.

PIN login resolves the credential from the server's `device_pins` row. Biometric login resolves it from the server-generated `credentialId` and verifies the one-use signature. An input `deviceId` never determines the biometric session's family identity. Issuance also checks the credential UUID belongs to that user while holding the relevant locks.

| Operation | Revocation scope | Other devices/users |
|---|---|---|
| Delete PIN / terminal PIN lockout | All families issued through that exact PIN credential UUID, including rotated descendants | Other PIN devices and biometric credentials remain valid |
| Delete biometric / terminal biometric lockout | All families issued through that exact biometric credential UUID | Same-device PIN and another user's same-device biometric remain valid |
| Password reset | Every prior opaque family for the user, including trusted internal families | All the user's devices; other users unaffected |
| Local logout | Every opaque family for the authenticated user, regardless of the supplied refresh token | All the user's devices; other users unaffected |

Temporary credential lockouts keep existing sessions; permanent/terminal lockout removes the credential and revokes its families in one transaction. A successful PIN change is not credential deletion. Re-enrollment after deletion receives a different credential UUID and cannot resurrect an old family.

`SessionService::issue($user)` remains compatible with trusted internal/test callers, including callers already inside a transaction. It creates a version-fenced `internal` family. Neither PIN nor biometric production login uses this unlinked compatibility form. This is an internal PHP API, not an HTTP issuance endpoint.

## Atomicity and lock order

The lock order is **user row → credential row → family/session/challenge rows**. An initial unlocked credential/token read locates the user only; the authoritative state is re-read after acquiring the user lock. A PIN device that changes owner between reads is rejected.

Issuance, refresh consumption/rotation, user revocation and credential revocation serialize on the user row. Refresh rechecks family revocation, expiry and security version after acquiring that lock. Authentication requires a live family with the current user version and an unblocked user. Credential deletion and terminal lockout revoke families and delete both token types atomically.

For a login/refresh racing revocation:

- If issuance commits first, the subsequent revocation removes the newly committed pair as well.
- If credential deletion commits first, the login cannot issue against the removed credential; refresh cannot consume a revoked family.
- Password-reset fencing also blocks **new** opaque issuance and refresh while remote work/recovery is pending. Taking a fresh security version after the fence cannot bypass `sessions_blocked`.

Ordinary logout establishes a revocation point; a genuinely later successful credential login may start a new family. Password-reset recovery deliberately keeps issuance blocked until completion. Already authorized, in-flight business requests are not rolled back by session revocation.

## Password reset: durable fence before remote effects

1. Validate the new password and verify the old password against Keycloak. Verify the returned subject matches the authenticated application user.
2. Under the user lock, reject stale password verification if the security version changed in the meantime, or another recovery is pending.
3. In one committed PostgreSQL transaction: increment the security version, revoke/delete all prior opaque sessions, set `sessions_blocked=true`, and create a `password_session_operations` job in `RESET_PENDING`.
4. Only after that commit, call Keycloak reset-password. An exact empty HTTP 204 is required.
5. Persist `REVOKE_PENDING`, then revoke online sessions and the configured client's offline tokens/consent by the job's stored user UUID.
6. Only after confirmed provider cleanup, atomically mark the job `COMPLETE` and clear `sessions_blocked`.

No SQL transaction is held open across provider HTTP requests. A session-level PostgreSQL advisory lock, acquired before publishing the job, prevents another recovery worker from completing an active remote reset. Recovery takes the same lock; process/connection death releases it. Use a direct or session-affine PostgreSQL connection for this operation, not transaction-pooling across connections.

The durable job contains only operation UUID, user UUID, security version, state, timestamps and an allowlisted error code. It contains no old/new password, opaque token, provider refresh token, arbitrary provider body or exception text.

### Failure and recovery states

| State | Meaning | Recovery policy |
|---|---|---|
| `RESET_PENDING` | Fence committed; reset may have failed, succeeded remotely, timed out, or succeeded before a crash/SQL failure could record acceptance | Do not replay the password reset. Resolve the password outcome independently; trusted operator confirmation is required before recovery may finish |
| `REVOKE_PENDING` | Reset accepted; online/offline provider cleanup or local completion remains incomplete | Retry safe user-UUID revocation, then commit completion |
| `COMPLETE` | Provider cleanup confirmed and local fence released | Repeated recovery is a no-op; it must not log out newly created sessions |

After any failure following the fence, `changePassword` returns an existing `IDENTITY_PROVIDER_ERROR` or `IDENTITY_PROVIDER_UNAVAILABLE` code with:

```json
{"recoveryRequired":"true","recoveryOperationId":"<operation UUID>"}
```

The fence/job remains durable even when SQL completion fails or the API's SQL connection is lost after the remote reset. If the response itself is lost, find the pending operation by the safe authenticated user UUID. Do not clear `sessions_blocked` manually or delete the job to make a retry appear successful.

Trusted recovery integration:

```php
$recovery = new \Shop\application\PasswordSessionRecovery(
    $db, $sessions, new \Shop\infrastructure\Keycloak()
);
$recovery->recover($operationId); // automatic path: REVOKE_PENDING only

// Only after an operator has independently resolved RESET_PENDING's outcome:
$recovery->recover($operationId, true);
```

The operation ID selects a durable row; the provider subject comes exclusively from that row. No HTTP request should control the operator-confirmation flag. Automatic tooling can query `password_session_operations WHERE state='REVOKE_PENDING'`, retry with backoff, and report `RESET_PENDING` for operator attention. If the provider or SQL is unavailable, the user stays blocked and the job remains recoverable.

### Bounded worker selection and advisory-busy owners

The integrated `maintenance/recover` worker considers at most **25 due rows** in
`next_retry_at,id` order, materialized before its lock filter. Within a single
one-second SQL statement it skips advisory-busy rows and acquires only the first
available operation's session lock. It then reserves **one actual session
attempt**, before admitting at most one payment candidate under the monotonic
deadline. SQL transactions do not span provider HTTP.

An active password/reset/manual owner keeps the same advisory lock as automatic
recovery. Skipping that owner changes neither its attempts, claim/retry time,
state nor user fence. The worker releases its own acquired lock even when the
remaining admission budget prevents a reservation. The reservation update
rechecks eligibility; another owner cannot be completed or unblocked merely
because it was seen in the candidate window.

This is a bounded look-ahead policy, not an unbounded queue-fairness promise:
**25 busy heads can defer row 26**. Monitor prolonged owners rather than bypassing
their locks. The actual work cap remains one attempted session per scan, including
provider failure; five attempts/backoff and the 120-second crash lease remain.
`RESET_PENDING` still requires independent confirmation and the trusted ticketed
console path. Exact manual counts do not imply successful cleanup or clear a
fence. Current F2 replica/window regressions and rebuilt results are recorded in
[FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md), superseding RR1's earlier unrestricted
rank-based fairness wording.

## Logout and the Keycloak 26.3.3 contract

`AuthService::logout` commits local user-wide opaque revocation **before** provider work. For a supplied provider refresh-token value it uses authenticated-user admin logout and configured-client consent/offline-token removal, not that arbitrary refresh token. A caller therefore cannot submit another user's provider refresh token to revoke their provider session. Opaque-only or empty-token logout retains the local-only API behavior and needs no Keycloak connection.

Provider failures are surfaced as compatible `IDENTITY_PROVIDER_*` errors after local cleanup; they are not logged and silently reported as global success. Ordinary logout does not enqueue a retry containing a raw refresh token. A provider failure means remote logout is unconfirmed; callers/operators need to retry authenticated user-based cleanup or sign in again as appropriate.

When `KEYCLOAK_ADMIN_TOKEN` is absent, `Keycloak::admin()` first obtains a `client_credentials` token. Service-account authentication failures are classified at this admin boundary: HTTP 400/401 (including `invalid_client` and account-disabled responses), HTTP 503 and malformed token responses become `IDENTITY_PROVIDER_ERROR` (502); transport failures/timeouts retain `IDENTITY_PROVIDER_UNAVAILABLE` (503). They never escape logout as a caller's `INVALID_CREDENTIALS`/`ACCOUNT_LOCKED` error. A rejected grant prevents the admin request entirely. Password and refresh-token grants retain their existing user-error behavior. The optional `HttpClient` constructor injection remains supported. See the TDD correction for review M1 in [SESSION_FIX_REPORT.md](SESSION_FIX_REPORT.md).

Verified against the official **26.3.3 tag**, rather than a newer Keycloak release:

- [LogoutEndpoint.java](https://github.com/keycloak/keycloak/blob/26.3.3/services/src/main/java/org/keycloak/protocol/oidc/endpoints/LogoutEndpoint.java), `logoutToken()`: refresh-token logout returns 204 when successful, including an already-absent session. Token verification errors return a JSON OAuth error (normally 400; certificate verification can be 401). A 400 `invalid_grant` is **not** blanket idempotent success.
- [UserResource.java](https://github.com/keycloak/keycloak/blob/26.3.3/services/src/main/java/org/keycloak/services/resources/admin/UserResource.java), `logout()`, `revokeConsent()` and `resetPassword()`: all require `auth.users().requireManage(user)` and use 204 on success. Consent revocation addresses **client ID**, revokes consent/offline tokens, and returns 404 if the client or consent/offline token is absent.

`Keycloak::logout` now accepts only an empty 204; 503, unexpected 2xx/other statuses and malformed/unexpected responses produce `IDENTITY_PROVIDER_ERROR`. Transport failures/timeouts produce `IDENTITY_PROVIDER_UNAVAILABLE`. Safe-ID admin calls also require exact empty 204; only the existing consent-removal 404 case is accepted as absence. A forbidden admin call is an explicit failure, never success.

The checked-in `deploy/keycloak/shopkmp-realm.json` enables the backend service account and grants `realm-management` roles `manage-users` and `view-users`. Deployment must provision equivalent effective permissions (or the appropriate fine-grained user-manage permission). The dedicated fixture verifies correct user targeting, 403 handling and the HTTP contract; deployment-specific live realm permissions still need coordinator verification.

### Offline JWT limitation

**Logout does not instantly invalidate an offline-validated Keycloak JWT.** The existing `OidcVerifier` validates signature/issuer/audience/type/expiry without provider introspection or a local JWT denylist. Already-issued JWT access tokens can therefore remain accepted until their expiry; the configured 300-second access-token lifetime is a separate limit. Provider refresh/session revocation prevents continued refresh but is not an immediate offline JWT revocation mechanism. This is also distinct from the immediate database-backed opaque-token fence. The tests explicitly preserve this boundary rather than assert unsupported immediate JWT invalidation.

## Migration and rollout

Migration 013 is independent of payment migration 012 and upgrades the canonical 001–011 schema directly. It adds user security state, families and durable recovery jobs, then removes **all legacy opaque sessions**. Legacy sessions have no trustworthy credential linkage: they must reauthenticate; no device is invented from a user ID. Family ownership is enforced by composite foreign keys, and new opaque rows require a family ID.

Coordinate rollout by draining old application replicas/security endpoints, applying 013 transactionally and starting the new code. Old binaries cannot keep writing legacy unlinked sessions. Never run old password-reset code concurrently with this rollout. Existing PINs/biometric credentials and user/business data remain; only legacy opaque sessions are discarded.

The down migration refuses to run with pending password-session recovery. Even a permitted downgrade discards opaque sessions; it does not resurrect revoked credentials/tokens. Migration manifest generation, migration-runner integration and populated full-stack rollout belong to the coordinator.

## Coordinator integrations required

This list is the original session-workstream handoff. Shared runner, console,
scheduler, readonly migration mounts and isolated live-realm verification are
now integrated; current final-source evidence is in
[LIVE_TEST_RESULTS.md](LIVE_TEST_RESULTS.md) and
[FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md). Production realm permissions and the
remaining security/release gate remain deployment responsibilities.

1. Add both 013 migration checksums to `tools/ops/migrations/SHA256SUMS`; run canonical migration/checksum/parallel-upgrade checks with 012 and 013 together.
2. Add `session_revocation.php`, `session_password_recovery.php`, `session_concurrency.php`, `logout_regression.php` and `session_upgrade.php` to the shared PHP runner. Mount canonical migrations read-only at `/migrations` for `session_upgrade.php` (or coordinate an equivalent configured path).
3. Wire trusted console/scheduler recovery around `PasswordSessionRecovery::recover`: automatic `REVOKE_PENDING`, alert/manual resolution for `RESET_PENDING`, backoff on failure/busy lock. Preserve safe error details through existing API/error reporting.
4. Verify the deployed Keycloak 26.3.3 service-account permissions, online and offline token revocation, and existing `tests/auth.php` against the isolated full-stack realm. No controller, shared Compose, scheduler or shared test-runner files were edited by this work.
5. Include these documents and owned diff in the coordinator's complete review/verification gate. Dedicated verification here used only the disposable `session_fixes` database and test-owned HTTP processes.
