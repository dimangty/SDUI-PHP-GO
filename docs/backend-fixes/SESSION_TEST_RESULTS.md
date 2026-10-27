# B5–B6 session/logout verification

Date: 2026-10-26. Baseline HEAD: `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`.

Review follow-up: M1's service-account authentication error correction and its additional RED/GREEN evidence are recorded in [SESSION_FIX_REPORT.md](SESSION_FIX_REPORT.md). The counts below describe the original B5–B6 verification snapshot; the follow-up expands `logout_regression.php` from 15 to 41 tests.

At start, tracked files matched baseline; `docs/backend-fixes/PLAN.md` was already present as coordinator-owned untracked work. Other agents subsequently changed their payment/trust files. This work edited only the assigned session/Keycloak files, new focused tests/recovery class, 013 migrations and these two documents.

## Environment and reproducible commands

- PHP runtime: existing `shopkmp-php-tests`, PHP **8.3.36**. No dependency/runtime upgrades.
- PostgreSQL: existing disposable `shopkmp-backend-fixes-pg`, network `shopkmp-backend-fixes-20261026`.
- This task's database: **`session_fixes`** only. It was cloned from the baseline 001–011 database; migration 012 was deliberately absent.
- No baseline/other-agent database or running application services were changed.
- Dedicated `read` tools failed with `Bun is not defined`; Python reads were used. All workspace edits used `apply_patch`.

Setup, exit **0**:

```sh
PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH docker exec \
  shopkmp-backend-fixes-pg createdb -U backend_fixes -T backend_fixes session_fixes
```

Apply only migration 013, through psql stdin in one transaction, exit **0**:

```sh
python3 -c 'import subprocess; from pathlib import Path; r=subprocess.run(["/Applications/Docker.app/Contents/Resources/bin/docker","exec","-i","shopkmp-backend-fixes-pg","psql","-U","backend_fixes","-d","session_fixes","-v","ON_ERROR_STOP=1","--single-transaction"],input=Path("tools/ops/migrations/013_session_revocation.up.sql").read_bytes()); raise SystemExit(r.returncode)'
```

The first attempted tests used the host `backend-yii2/vendor` bind mount and exited **255** before any test: that preexisting generated autoloader required PHP >=8.5.11. This was an environment failure, **not RED evidence**. The unchanged image contains compatible production dependencies. All valid runs added `-v /app/vendor`, an anonymous Docker volume initialized from that image, to avoid the host vendor tree. No vendor/lock/runtime files were edited.

All valid suite commands use this exact shape (substitute the listed relative test path):

```sh
PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH docker run --rm \
  --network shopkmp-backend-fixes-20261026 \
  -v /Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO/backend-yii2:/app \
  -v /app/vendor \
  -v /Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO/tools/ops/migrations:/migrations:ro \
  -w /app \
  -e 'DATABASE_URL=postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/session_fixes?sslmode=disable' \
  shopkmp-php-tests php tests/session_revocation.php
```

The initial RED commands used the same invocation without the `/migrations` mount (unneeded except for the upgrade test).

## Observed TDD RED evidence

| Cycle / command tail | Exit | Observed behavior before the corresponding implementation |
|---|---:|---|
| `php tests/session_revocation.php` against baseline services | **1** | 6 tests, 6 failures: PIN/bio deletion, both terminal lockouts and password change left access valid; logout left other refresh tokens valid |
| `php tests/logout_regression.php` against baseline services | **1** | 9 tests, 6 failures: 503, unexpected 200 and invalid-token errors were all accepted; AuthService suppressed provider failures |
| `php tests/session_password_recovery.php` after applying 013, before password fencing | **1** | 4 tests, 4 behavior failures: remote reset probe could issue opaque tokens; partial remote outcomes had no explicit durable recovery signal. Earlier missing-schema/fixture errors were corrected and are not used as RED evidence |
| `php tests/session_password_recovery.php` with recovery-lock probe and stale-verification regression | **1** | 7 tests, 2 failures: live reset did not own the recovery lock; stale pre-revocation password verification could still reset |
| `php tests/logout_regression.php` with local-only compatibility regression | **1** | 12 tests, 1 failure: new safe-ID logout wrongly required Keycloak for an opaque-only logout |
| `php tests/session_upgrade.php` before down-migration recovery guard | **1** | 2 tests, 2 failures: downgrade accepted pending password recovery |
| `php tests/session_password_recovery.php` with post-reset SQL-disconnection regression | **1** | 9 tests, 1 failure: advisory-unlock cleanup replaced the recovery signal with a PDO connection error |
| `php tests/logout_regression.php` with invalid-provider-configuration regression | **1** | 13 tests, 1 failure: configuration exception escaped as a generic runtime error instead of `IDENTITY_PROVIDER_UNAVAILABLE` |

The expected failures were observed before their fixes; existing unsupported expectations were not rewritten. Subsequent cases expanded concurrency, malicious-device, unexpected-reset-response, digest/restart, secret-handling and offline-JWT boundary coverage.

## Final GREEN evidence

The final verification invoked each of these files with the Docker command above, recorded its subprocess return code, and returned overall exit **0**:

| Exact repository test path | Result | Exit |
|---|---|---:|
| `backend-yii2/tests/session_revocation.php` | **7 tests, 0 failures** | **0** |
| `backend-yii2/tests/session_password_recovery.php` | **10 tests, 0 failures** | **0** |
| `backend-yii2/tests/session_concurrency.php` | **11 tests, 0 failures** | **0** |
| `backend-yii2/tests/logout_regression.php` | **15 tests, 0 failures** | **0** |
| `backend-yii2/tests/session_upgrade.php` | **2 tests, 0 failures** | **0** |
| `backend-yii2/tests/run.php` (unchanged) | **7 tests, 0 failures** | **0** |
| `backend-yii2/tests/signed_oidc.php` (unchanged) | **2 tests, 0 failures** | **0** |
| `backend-yii2/tests/durations.php` (unchanged) | **1 test, 0 failures** | **0** |
| `backend-yii2/tests/lint.php` (unchanged) | **71 PHP files passed syntax check** at verification time | **0** |

Totals: **45 dedicated tests + 10 unchanged regression tests passed**. `git diff --check` also returned **0**. Syntax count includes files concurrently present from other agents and can increase with their work.

Coordinator checksum handoff (`shasum -a 256 tools/ops/migrations/013_session_revocation.up.sql tools/ops/migrations/013_session_revocation.down.sql`, exit **0**):

```text
711c980aaf6aa906b5cbcb902846cb82522a1dfbb5bb68ff2954537dfaaab200  tools/ops/migrations/013_session_revocation.up.sql
70b47f3ee15dc600c3740b8ee83051d6c6955c8271a77de5b36b935d58912666  tools/ops/migrations/013_session_revocation.down.sql
```

### What was exercised

- Successful reset rejects **all old opaque access/refresh pairs**, including other devices and internal issuance, while another user remains valid.
- Exact credential-family revocation for PIN/bio deletion, terminal lockout and rotated descendants. Another user's tokens, other devices and same-device different credential types remain valid.
- Biometric assertions with a supplied **other user's actual device ID** cannot change server-derived credential linkage. Two users may have biometric credentials with the same device ID.
- Both token kinds contain digests only; new production PIN/bio families contain the real credential UUID. Fresh service instances can authenticate/rotate valid tokens; revoked tokens remain rejected.
- PostgreSQL **real independent PHP worker processes**, no mocked DB: PIN login, biometric login and refresh versus credential deletion in both explicitly queued user-lock orders; refresh replay yields exactly one committed winner.
- Refresh/PIN login versus password fence in both explicitly queued lock orders. A timed-out reset leaves a durable blocked user even for post-fence issuance. Queue synchronization observes `pg_stat_activity.wait_event_type='Lock'`, not a guessed race sleep.
- Provider-side probe during reset sees opaque issuance blocked before remote effects; recovery cannot acquire the active reset's advisory lock.
- Remote reset accepted then logout/consent 503; reset timeout; unexpected 202; SQL completion trigger failure; and test-owned API SQL connection termination after remote reset. All preserve the durable fence/job. Recovery uses a fresh connection/service instance and never replays a raw password.
- Unknown reset outcome needs explicit trusted operator confirmation; known accepted reset can recover automatically; completed recovery is idempotent.
- Logout exact 204, repeat/already-absent-session success, 503, timeout, connection refusal, unexpected 200, documented 400 `invalid_grant`, admin 403 and absent consent 404. Local cleanup always commits before remote failure.
- Logout targets the authenticated user's admin path even with an arbitrary other-user provider refresh input. Error/log checks reject leakage of provider body, provider refresh value and opaque tokens.
- A real signed RS256 access JWT stays accepted after local opaque logout until expiry; an expired signed JWT is rejected. This preserves the documented offline-verification limitation.
- Migration 013 applied independently to isolated schemas reconstructed from **unchanged 001–011**, both with no legacy tokens and with populated valid legacy access/refresh rows. Old tokens require reauthentication; legacy unlinked writers fail; pending jobs prevent unsafe downgrade. Test schemas are removed afterward.

The test-only HTTP fixture is `backend-yii2/tests/SessionProviderFixture.php`. It runs in a separate PHP process, validates safe user targeting, has no secret journal, and avoids inheriting a live PDO connection. The SQL-disconnection fixture terminates only the session test's uniquely named backend in `session_fixes`; it does not restart the shared disposable PostgreSQL container. Restart coverage means fresh application/service/worker processes and SQL connections, not a shared database-container restart.

## Contract verification and remaining integration gate

Official Keycloak **26.3.3** `LogoutEndpoint.logoutToken` and `UserResource.logout/revokeConsent/resetPassword` sources were fetched and inspected. The checked-in realm's service-account `manage-users`/`view-users` assignment was inspected. URLs, semantics and required permission are recorded in [SESSIONS.md](SESSIONS.md).

This task did not run the full shared PHP/Compose suite or mutate an existing live Keycloak realm. The coordinator still needs shared runner/migration-manifest wiring, recovery console/scheduler wiring, an isolated live 26.3.3 realm permission/online/offline revocation check, and the combined 012+013 full-stack upgrade/regression gate. No shared runner/controller/Compose/checksum-manifest changes were made here.
