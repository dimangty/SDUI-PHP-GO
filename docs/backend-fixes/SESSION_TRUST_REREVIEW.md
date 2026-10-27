# Session/Keycloak and shared recovery security re-review

Date: 2026-10-26. Baseline/HEAD: `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`; current uncommitted working tree, including untracked files.

## Verdict

- **Prior M1: CLOSED.** Service-account authentication errors now have provider-error semantics without changing password/refresh grant behavior.
- **New findings: 0 Critical, 0 High, 1 Medium** in the expanded shared-recovery scope.
- **Specification:** session ownership, fail-closed recovery, manual confirmation, secret handling, schema readiness and finite transport trust remain aligned with the documented boundaries. Automatic recovery has the runtime/fairness gap below.
- **Quality: request the Medium correction before final recovery-integration signoff.** The M1 fix is appropriately scoped, and the shared integration has meaningful PostgreSQL race/crash and scheduler evidence. That evidence does not cover a mixed backlog exceeding the combined worker's runtime budget.

## M1 closure and regression assessment

`backend-yii2/infrastructure/Keycloak.php:45–55` catches service-account acquisition `ApiError`s at the admin boundary. Transport unavailability remains `IDENTITY_PROVIDER_UNAVAILABLE`; other acquisition failures become a generic `IDENTITY_PROVIDER_ERROR`. Provider bodies and credential values are not included in the new error. The password/refresh parser at `Keycloak.php:27–42` is unchanged.

Freshly ran **17 selected existing regressions** from `logout_regression.php`: nine direct admin service-account cases and eight password/refresh credential-error cases. All passed. Cases include HTTP 400/401 `invalid_client`, account-disabled responses, 503, timeout, malformed JSON/field types, and successful acquisition without an admin-token override. Failed grants make no admin request; successful acquisition uses the returned service token. Password and refresh grants retain `INVALID_CREDENTIALS` / `ACCOUNT_LOCKED` as appropriate.

The added authenticated-logout tests at `backend-yii2/tests/logout_regression.php:82–101` assert both local token types revoked across two families, another user's access/refresh validity, safe subject targeting, no admin request after a failed grant, and absence of client secret, service token, provider body, supplied refresh value and opaque tokens from errors/logs/counters. Inspected these assertions and the **41/0** real-PG result in `SESSION_FIX_REPORT.md`; that database suite was not repeated here.

`AuthService`, `SessionService`, `CredentialService` and `PasswordSessionRecovery` retain the prior reviewed bytes. The new admin error mapping does not change user/credential/family selection or revocation ordering. No ownership or new plaintext-secret persistence/logging regression was found.

## New finding

### RR1 — Medium: payment recovery can consume the whole worker budget before any session recovery

**Primary location:** `backend-yii2/application/RecoveryService.php:19–21`.

**Related locations:** `backend-yii2/application/PaymentOperations.php:241–259`; `docker-compose.yml:249–266`; `deploy/php/scheduler.php:32–39`; `backend-yii2/infrastructure/HttpClient.php:8,26–35`.

The combined worker synchronously finishes `PaymentOperations::recover()` before selecting any session jobs. That payment pass can process **1,000 candidates**, including sequential provider HTTP calls. The deployed combined worker's default child budget is **120 seconds**, while each payment HTTP call can legitimately consume its **10-second** deadline. The application pass has no elapsed-time budget or reserved session slice.

A payment-provider outage with enough queued unsent effects therefore causes the scheduler to terminate the child before it reaches a due `REVOKE_PENDING` session operation, even when Keycloak is healthy. For example, **13 consecutive ten-second payment timeouts require at least 130 seconds**, exceeding the default budget before session selection. Continued payment backlog can repeat this across scans; the separate maintenance worker also drains payments, not sessions, so it does not guarantee session progress.

**Impact:** password-change users can remain blocked and remote session cleanup can remain pending because of an unrelated payment backlog. The opaque fence remains intact, and sent payment effects remain fenced; this is recovery availability/liveness, not an opaque-authentication bypass or permission escalation. The scheduler correctly reports its timeout, but detecting the failure does not make the deferred session work progress.

**Evidence:** independently rendered the current Compose model and checked the actual call ordering and timeout/batch sources:

```text
command:                    maintenance/recover, cadence 30s
child budget:               120s
payment candidate cap:      1000
payment HTTP deadline:      10s
payment pass before sessions: true
session batch cap:          25
```

This is a source-confirmed runtime-bound failure path, not a claim of a newly executed 120-second outage/kill reproduction. The existing recovery suite exercises small queues, individual failures, claims and crashes. The scheduler suite proves deadline enforcement, primarily with deterministic replacement children; neither tests mixed-backlog fairness. A count cap of 25 sessions also does not by itself bound the duration of their sequential provider calls.

**Recommended correction:** make the combined scan budget-aware and reserve a bounded session-recovery slice before draining payment work. Stop claiming additional work with enough remaining time for its bounded provider calls and local completion; leave the remainder durably due for the next tick. Preserve the existing session advisory lock/lease, bounded attempts, and payment no-replay policy.

**Regression recommendation:** add one mixed-backlog test using a reduced supported scheduler budget and proportionally delayed payment responses, plus a due session job backed by healthy Keycloak. Keep enough payment work pending across ticks. Assert the session job is actually attempted/completed within a bounded number of ticks, successful scans can finish before the parent deadline, failed session cleanup retains the fence, and no `SENT`/`UNKNOWN` payment is replayed. Use the real worker/application path rather than only a replacement child that sleeps.

## Expanded integration checks

| Area | Assessment |
| --- | --- |
| Recovery state/ownership | Automatic selection is restricted to due `REVOKE_PENDING`, with five attempts, exponential backoff and durable crash reservations. The worker and live password flow use the same operation advisory lock. Provider subjects come from the durable operation row. Completion and user unblocking remain atomic. RR1 concerns scheduling progress before these guards run. |
| Manual recovery/controller | `MaintenanceController.php:33–36` requires the exact confirmation argument. `RecoveryService.php:41–53` validates UUID/ticket, preserves the first confirmation/evidence, serializes with automatic/live recovery, and leaves completed jobs as provider no-ops. No HTTP route exposes the operator confirmation flag. |
| Migration 014 | Retry range, due index and scalar operator/scheduling metadata are appropriate. Existing operation state/fence is retained on upgrade; down refuses pending recovery. No password/provider-refresh field was introduced. |
| Scheduler wiring | Recovery is a separate service from search, included in production worker startup/drain and runtime health/restart checks. Production TLS mounts/settings survive the overlay. Deadline/TERM/KILL and stale-success logic remain present; the problem is application work sizing/fairness under that deadline. |
| Readiness | `ApiKernel.php:180–189` checks the new payment/session/recovery shape, including the revised `refund_requested_at` and observation table. Missing-schema regressions use rolled-back DDL and require 503. No new secret-bearing readiness response was found. |
| Finite transport trust | Exact edge/BFF peer configuration, ingress-only BFF egress, dedicated FPM admission/mount, `NET_RAW` drops, finite HTTP/FastCGI headers and webhook rate-context exclusion remain intact. PHP Network and the Nginx trust template retain the prior reviewed bytes. No trust broadening or webhook source regression was found. |
| Test-runner controls | Production/verification mixing is rejected by `compose.command`; focused runners require explicit disposable/e2e intent. Gateway values are child-only, fixture aliases route over exact allowlisted test interfaces, and migrations are mounted read-only. Focused runner restores only previously running SQL workers in `finally`; failure propagation and cleanup are covered. |

## Evidence and scope

Read `SESSION_FIX_REPORT.md`, updated session evidence, `INTEGRATION_TEST_RESULTS.md`, `MIGRATION_ROLLBACK.md` and the relevant payment fix/schema handoff. Inspected the current implementations/tests for Keycloak/Auth, RecoveryService/PasswordSessionRecovery, 014, console/scheduler, readiness, trust/Compose, runner controls and CI wiring.

Fresh, targeted checks:

- **17 existing admin/password/refresh regressions: 17 passed, exit 0.** Selected in memory from the current test file; source unchanged. Ran PHP 8.3 in a network-isolated read-only container with read-only source mounts and temporary fixture storage. Database cases were excluded from this targeted run.
- **`python3 -B scripts/test_backend_integration.py`: 6 passed, exit 0.** Real Compose rendering plus mocked subprocess-boundary runner checks; no application services were stopped or started.
- **Resolved-model/source budget check: exit 0**, confirming the RR1 numbers and payment-before-session order above.
- **`git diff --check`: exit 0** at report completion, plus explicit whitespace checking of this untracked report.

The reported real PostgreSQL races, killed-worker/claim recovery, 014/readiness regressions, actual console tests and six recovery-scheduler tests were evaluated as supplied integration evidence. They are not represented as fresh broad reruns by this reviewer. This scoped verdict does not replace the coordinator's final rebuilt-stack/live-Keycloak/TLS verification.

Snapshot identifiers: Keycloak `d5b11f76ca9ecbfaafae36ee051ac6fd8b504a0eb0bc91a9d4cc8f91c0e11df3`; RecoveryService `5df3fa1865eaedcb7cdc05217207f94d0a90a347ca13f5e15f5d59ed2abaa279`; PaymentOperations `e369d2303a5ca0d1c416bd75d786ea77067e146445b87e67bbba8bff4f58774c` (SHA-256).

Only this re-review report was written. No subagents or commits were used.
