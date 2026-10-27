# B5–B7 session and transport-trust security review

Date: 2026-10-26. Baseline and HEAD: `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`.
Reviewed the current uncommitted implementation, including untracked source, migrations and tests read directly. This is a separate review; no earlier dispatch report was assumed.

## Verdict

- **Critical: 0. High: 0. Medium: 1 confirmed.**
- **Specification:** B5 and B7 substantially match the documented security boundaries. B6 has an incomplete provider-error contract on the normal service-account authentication path, described below. The documented offline-JWT lifetime and forced legacy opaque-token reauthentication are consistent with the implementation.
- **Quality:** sound core revocation/ownership and forwarding design, supported by meaningful concurrency and failure-injection tests. **Accept with the Medium correction below**, subject to the coordinator's ongoing combined integration verification. Shared integration/recovery-job completion is not counted as a finding.

## Finding

### M1 — Service-account authentication failures escape logout as user-credential failures

**Severity: Medium.**

**Locations:**

- `backend-yii2/application/AuthService.php:60–63`
- `backend-yii2/infrastructure/Keycloak.php:43–46`, especially line 45
- Error classification originates at `backend-yii2/infrastructure/Keycloak.php:30–34`.

The new authenticated-user logout uses the admin API. When `KEYCLOAK_ADMIN_TOKEN` is absent, `Keycloak::admin()` first obtains a service-account token through the shared `tokens()` method. That method classifies every token-endpoint HTTP 400/401 as `INVALID_CREDENTIALS` or `ACCOUNT_LOCKED`, including an OAuth `invalid_client` response for an expired/rotated backend client secret. `AuthService::logout()` rethrows that `ApiError` unchanged.

Consequently, a valid authenticated user's logout can return **401 `INVALID_CREDENTIALS`** instead of the documented `IDENTITY_PROVIDER_*` failure. Local opaque cleanup has already committed, but remote revocation was never attempted. This misattributes a backend-provider credential failure to the caller and breaks error handling that identifies incomplete provider logout by its documented error codes. This is an error-contract/reliability defect, not an ownership bypass or a false successful logout.

**Independent reproduction:** with current `Keycloak.php`, no admin-token override, and a loopback HTTP fixture returning `401 {"error":"invalid_client","error_description":"Invalid client credentials"}` from the service-account token endpoint, calling `logoutUser()` produced:

```json
{"code":"INVALID_CREDENTIALS","status":401,"message":"invalid credentials"}
```

The probe ran in a network-isolated, read-only PHP container and needed no database writes. The production Compose configuration supplies client credentials, not an admin-token override, so this is a deployment-relevant path.

**Why existing tests miss it:** `backend-yii2/tests/SessionProviderFixture.php:63` always sets `KEYCLOAK_ADMIN_TOKEN=fixture-admin`. The logout regressions therefore exercise failures of the admin logout request but bypass service-account token acquisition. The invalid-configuration regression also does not exercise an OAuth `invalid_client` response.

**Recommended correction:** classify service-account token acquisition failures at the admin/provider boundary as `IDENTITY_PROVIDER_ERROR` or `IDENTITY_PROVIDER_UNAVAILABLE`, preserving the user-password grant's existing credential-error behavior and sanitizing provider bodies.

**Regression recommendation:** unset `KEYCLOAK_ADMIN_TOKEN`; return HTTP 400 and 401 with `invalid_client` from `grant_type=client_credentials`; invoke authenticated logout with a provider refresh-token input. Assert an `IDENTITY_PROVIDER_*` response, committed revocation of both local token types for the authenticated user, another user's sessions unaffected, no admin revocation request after the failed grant, and no provider body/client secret/token leakage in errors or logs. Retain successful service-account acquisition and existing wrong-user-password regressions.

## Security checks and strengths

| Area | Assessment of reviewed implementation |
| --- | --- |
| User/credential/family ownership | PIN ownership is rechecked after the user lock; biometric linkage comes from the verified server credential UUID. Issuance validates credential ownership. Composite session-family foreign keys bind token user and family user. Credential revocation predicates include user, kind and credential UUID. |
| Revocation, login and refresh | User-row serialization covers issuance, refresh, credential revocation and user-wide revocation. Refresh revalidates expiry/version/revocation after locking and consumes the refresh digest once while retaining its family. The dedicated process-based tests queue both credential-delete/password-fence orderings. No additional concrete revocation bypass or newly introduced lock-order cycle was established. |
| Password partial outcomes | A committed user-wide opaque revocation/fence precedes remote reset. The advisory lock covers live reset and recovery without an SQL transaction across HTTP. Unknown reset outcomes require operator confirmation; accepted resets permit safe subject-based revocation retry. Local completion and unblocking share a transaction. Completed jobs avoid re-revoking later sessions. |
| Secret handling | Token tables contain digests. Recovery rows contain identifiers/state/allowlisted error codes, not passwords or refresh-token plaintext. The changed provider/recovery paths sanitize failures rather than persisting/logging arbitrary exception text or provider bodies. No new plaintext-secret persistence/logging path was found. |
| Logout and provider ownership | Local user-wide cleanup precedes provider work. Remote logout uses the authenticated subject, never the owner of an arbitrary supplied refresh token. Exact 204 confirmation and the documented consent-absence 404 case are handled explicitly. M1 concerns the earlier service-account grant's error classification. |
| JWT and upgrade policy | Offline signature verification remains valid until JWT expiry, explicitly separate from immediate opaque revocation. Migration 013 discards unlinked legacy tokens, requires family linkage for new writers, and prevents downgrade with pending password recovery. |
| Exact peer trust | Go uses exact canonical socket-peer matches, rejects ambiguous trusted-edge claims, and forwards typed rate context through the Yii adapter. PHP accepts the Nginx-owned context only for exact BFF peers. Legacy subnet/XFF trust is removed. Public forwarding/identity headers are reconstructed through finite allowlists. |
| FastCGI admission | Current Compose pins the dedicated Nginx/FPM peer; FPM's admission configuration is retained in the production overlay. Direct untrusted FastCGI parameters do not constitute an authenticated peer. The supplied live evidence includes an actual refused forged FastCGI request. |
| IPv4/IPv6 and rate identity | Canonical/mapped-address handling and IPv4 `/24` / IPv6 `/64` aggregation are preserved. Rate context is independent of bearer authentication and cannot supply user/admin identity. |
| Webhook source | Nginx clears BFF rate context for the webhook path. Source-CIDR checks continue to use the direct edge-resolved source or actual internal peer, with original-body HMAC verification. No concrete source-allowlist weakening was found in the reviewed chain. |

## Review evidence

Read `SESSIONS.md`, `TRUST_AND_RATE_LIMITS.md`, `PLAN.md`, and all available `*_TEST_RESULTS.md` documents. Inspected the complete session/Auth/Credential/password-recovery/Keycloak/013 implementations and their focused tests; Go BFF/runtime/Yii forwarding and tests; PHP Network/Nginx/FPM/Compose wiring, trusted-client/rate tests and the live harness. Also inspected API routing/authentication/webhook use of client context and the currently available shared recovery integration for relevant interactions.

Fresh checks in this review:

| Check | Result |
| --- | --- |
| `git diff --check` | Exit 0 before report creation; repeated after report creation. |
| `go -C backend-sdui test ./internal/bff ./internal/runtime ./internal/yii -count=1` | Exit 0. |
| `go -C backend-sdui test ./... -count=1` | Exit 0; all tested packages passed. The optional live-Yii integration case remains environment-gated. |
| `php tests/trusted_clients.php` in `shopkmp-php-tests`, current infrastructure/domain/tests mounted read-only, `--network none --read-only --cap-drop=ALL` | Exit 0; 4 tests, 0 failures. |
| Service-account `invalid_client` loopback probe using current adapter and `SocketUpstream` | Exit 0; confirmed the erroneous 401 `INVALID_CREDENTIALS` classification above. |
| Production Compose `config --format json` with `.env.prod.example` and placeholder required values | Exit 0; confirmed exact BFF/edge peers, FPM allowed-client environment, FPM configuration mount, dedicated FPM network and ingress-only BFF networking survive the overlay merge. |

The supplied session concurrency/provider/migration and dual-stack/live-FastCGI results were inspected as existing evidence, not represented as fresh executions by this reviewer. Database-mutating suites and the full live stack were not rerun against the coordinator's active shared integration resources.

Only this report was written by the review. Findings describe the reviewed working-tree snapshot; the separate combined integration gate remains owned by the active coordinator.
