# Shared integration verification — 2026-10-26

Continuation of the partially implemented shared integration on
`refactor/backend-production-hardening`, baseline HEAD `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`.
No subagents, commits or pushes. Core PaymentOperations/SessionService/
PasswordSessionRecovery/Keycloak and migrations 012/013 were read, not edited by
this continuation. Keycloak's optional HttpClient constructor is retained.
Manifest checksums and final complete review/results remain coordinator-owned.

## Scope completed

- Kept exact edge/BFF peers, deterministic BFF egress, dedicated FPM network,
  admission environment and FPM mount across development/production overlays.
- Shared PHP runner includes focused session/logout/upgrade and trust/rate tests.
  Separate focused runner covers payment migration/lifecycle/HTTP and recovery,
  with child-only gateway configuration and read-only migration mount. Fixtures
  have exact source allowlists, test-network-only DNS aliases and startup probes.
- Added the independent `payment-recovery` scheduler (`maintenance/recover`),
  distinct from search `reconcile`; included it in production drain/start and
  runtime readiness/restart checks. Scheduler fixtures accept its actual command.
- Completed durable bounded session cleanup around the existing recovery API:
  automatic `REVOKE_PENDING` only; five attempts/backoff; crash reservations;
  count-only output; explicit manual reset-outcome confirmation plus ticket;
  first ticket preserved on retries/completed no-op.
- Readiness now requires the combined 012–014 shape. Updated backend/deployment
  documentation, CI config checks and [migration/rollback](MIGRATION_ROLLBACK.md).
- Focused runner pauses only currently running maintenance/recovery workers and
  restores them even on failure; the full verifier also pauses them during PHP
  suites. This prevents normal/demo schedulers consuming injected fixture intents.

## Environment and database isolation

- PHP **8.3.36**, baseline image `shopkmp-php-tests`; source bind-mounted at `/app`.
  The existing image-seeded `shopkmp-payment-vendor-20261026` volume covers
  `/app/vendor`, avoiding the host PHP >=8.5 generated vendor tree. Locks/vendor
  were not modified. Dedicated read/glob tools failed with `Bun is not defined`;
  shell/Python reads were used and all workspace edits used apply_patch.
- Existing disposable PostgreSQL **17.10**: `shopkmp-backend-fixes-pg`, network
  `shopkmp-backend-fixes-20261026`, host port **55493**.
- Source-mounted behavioral suites used **only `integration_fixes`**. It already
  existed with payment/session tables and every 014 column. Its history still
  listed 001–011 because predecessor setup applied new SQL directly. This
  continuation did not reapply those files or rewrite that history. Migration
  tests use temporary schemas/transactions inside this database and roll back.
- `backend_fixes`, `payment_fixes`, `session_fixes`, `trust_fixes` and existing
  application projects were not mutated. Full-stack startup below creates only
  the explicitly requested new project's own databases/volumes.

## Observed RED and setup failures

These are actual failures observed during this continuation, not reconstructed
claims about the predecessor's implementation sequence.

| Invocation / case | Exit | Observation and cause |
| --- | ---: | --- |
| `scripts/test_scheduler.py --image shopkmp-php-tests --service payment-recovery` | 1 | 6 cases, 5 failures: fixture rejected `maintenance/recover` before recording a child. Fixed the fixture allowlist; this was an integration-fixture failure, not evidence of a scheduler timeout bug. |
| Initial `php tests/recovery.php`, no payment gateway fixture | 1 | 5 cases, 2 failures. Forked child shutdown terminated the inherited local provider, so two workers observed zero logout calls. Separately, missing external payment fixture meant no journal. Fixed fixture ownership by PID and configured the payment provider; missing gateway was setup failure, not production RED. |
| Recovery suite with readiness regression, before readiness change | 1 | Expected **503**, got **200** after hiding `payment_operations`. Valid schema-readiness RED. Same run's isolated migration test failed because its private search path lacked the public uuid-extension function; fixed test setup without exposing public tables. |
| Recovery suite with repeated manual ticket regression | 1 | 8 cases, 1 failure: expected original `OPS-123`, got overwritten `OPS-456`. Fixed first-evidence preservation. |
| Shared runner command-boundary regressions before worker pausing | 1 | 5 cases, 2 failures: focused runner neither paused background consumers nor restored them on failure. Added current-running-worker capture, pause and finally restoration. |
| Initial isolated Compose build | 1 | Docker registry metadata `DeadlineExceeded` for Composer/Go/Dockerfile frontend. Environment failure, not application RED. Standalone PHP build and subsequent full build succeeded. |

Predecessor payment/session/trust RED/GREEN artifacts were read from
[PAYMENT_TEST_RESULTS.md](PAYMENT_TEST_RESULTS.md),
[SESSION_TEST_RESULTS.md](SESSION_TEST_RESULTS.md) and
[TRUST_TEST_RESULTS.md](TRUST_TEST_RESULTS.md). They are attributed workstream
evidence, not newly executed full-stack/security/TLS evidence from this task.

## Final GREEN: source-mounted behavioral suites

The final sequential Python loop executed Docker commands with the argument set
below for each suite. It printed every individual exit and returned **0**.
Helpers below reproduce those argument sets; fixture secrets are disposable only.

```sh
export PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH
ROOT=/Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO
NET=shopkmp-backend-fixes-20261026
DB_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/integration_fixes?sslmode=disable'

phpcheck() {
  docker run --rm --network "$NET" --ip 172.29.0.230 \
    -e DATABASE_URL="$DB_URL" \
    -v "$ROOT/backend-yii2:/app" \
    -v shopkmp-payment-vendor-20261026:/app/vendor \
    -v "$ROOT/tools/ops/migrations:/migrations:ro" -w /app "$@"
}
focused() {
  phpcheck -e PAYMENT_GATEWAY_URL=http://shopkmp-integration-provider-20261026:8080 \
    -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
    -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
    -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-integration-http-20261026:8080 \
    shopkmp-php-tests php "tests/$1"
}
```

| Command tail | Cases / failures | Exit |
| --- | --- | ---: |
| `focused payment-migration.php` | 2 / 0 | 0 |
| `focused payments.php` | 99 / 0 | 0 |
| `focused payment-http.php` | 4 / 0 | 0 |
| `focused recovery.php` | 9 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/session_revocation.php` | 7 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/session_password_recovery.php` | 10 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/session_concurrency.php` | 11 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/logout_regression.php` | 15 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/session_upgrade.php` | 2 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/trusted_clients.php` | 4 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/trusted_rate_limits.php` | 5 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/run.php` | 7 / 0 | 0 |
| `phpcheck shopkmp-php-tests php tests/lint.php` | 76 PHP files syntactically valid | 0 |

**175 passing PHP cases**, plus syntax checks. Existing test expectations were
not reset to make failures pass. Recovery was rerun after the session concurrency
suite, with its deliberately pending reset fixtures still present: **9/0, exit
0**. Its count assertions measure each test-owned job's increment and retain
direct state/attempt/provider-call assertions, rather than assuming an empty DB.
The final rerun also upgraded a populated pre-014 recovery operation, verified
the fence/state survived, checked pending-down refusal and completed down/up.

Recovery cases include real PostgreSQL/provider HTTP outside transactions,
fresh-service restart, exponential retries/exhaustion, no automatic
`RESET_PENDING`, manual ticket/confirmation, two forked workers with one effect,
expired claim handling, an actual SIGKILL after the provider records logout,
fresh-worker retry after reservation expiry, unsent payment dispatch/no UNKNOWN
replay, count-only console JSON and actual console validation/completion.
Expected SQL errors from deliberately hidden schema elements and expected
negative gateway responses/timeouts are asserted test outcomes.

### Disposable standalone provider arguments

Both named fixture containers used the source/vendor mounts above, `/app`
working directory, `$NET`, `$DB_URL` and image `shopkmp-php-tests`:

- `shopkmp-integration-provider-20261026`, IP `172.29.0.232`, command
  `php -S 0.0.0.0:8080 tests/payment-provider.php`, environment
  `PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-integration-http-20261026:8080`.
- `shopkmp-integration-http-20261026`, IP `172.29.0.231`, command
  `php -S 0.0.0.0:8080 -t web web/router.php`, environment gateway URL/key and HMAC
  secret as above, `PHP_CLI_SERVER_WORKERS=4`,
  `PAYMENT_WEBHOOK_ALLOWED_CIDRS=172.29.0.232/32,172.29.0.230/32`.

Network inspection verified those addresses were free before allocation. No
provider CIDR or production payment setting was relaxed. These two task-owned
containers are removed at handoff; the database/network/vendor volume remain.

## Config, scheduler and compatibility checks

| Actual command | Exit / result |
| --- | --- |
| `python3 scripts/test_backend_integration.py` | Final **6 cases, 0 failures**, exit 0; resolved dev/prod/verification trust, FPM mount, TLS config retention, separate worker/search, exact fixture source routing, child-only env, failure cleanup and prior-running-state preservation. |
| `python3 scripts/test_scheduler.py --image shopkmp-backend-fixes-e2e-yii2 --service payment-recovery` | **6 cases, 0 failures**, exit 0; current built business CLI with unreachable dependencies, first success, repeated failure/recovery, deadlines/TERM/KILL, stale/frozen parent, late completion and shutdown. Earlier green baseline-image run also 6/0; current image run is the relevant final evidence. |
| `make compose-config` | 0 |
| `python3 scripts/route_matrix.py --check` | 0; 54 exact operations/policies, eight screens, six commands. |
| Python `ast.parse` for seven changed integration/deployment scripts | 0 |
| Python comparison of all 22 SQL files numbered 001–011 against `git show HEAD:<path>` | 0; byte-for-byte unchanged. |
| `git diff --check` | 0 |

The config tests render the production model using fake config-only TLS paths;
they do not establish live trusted-CA TLS, certificates or production validation.
Runner tests replace only the subprocess side-effect boundary; the actual focused
PHP behaviors were independently executed above. The complete live
`scripts/backend_fixes.py` phase is left to the coordinator's full-stack gate.

## Isolated full-stack preparation and worker restart

The following environment was supplied on **every** Compose command:

```sh
export PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH
export COMPOSE_PROJECT_NAME=shopkmp-backend-fixes-e2e EDGE_PORT=58196
export EDGE_SUBNET=172.31.220.0/24 EDGE_IP=172.31.220.2
export YII_HTTP_IP=172.31.220.3 SDUI_IP=172.31.220.4 TRUSTED_BFF_IPS=172.31.220.4
export YII_FPM_SUBNET=172.31.219.0/24 YII_FPM_HTTP_IP=172.31.219.3
```

Actual commands/results:

1. Full `scripts/compose.py --env .env.example --project shopkmp-backend-fixes-e2e
   build`: first exit **1** on registry metadata timeout. Then
   `docker build --pull=false -t shopkmp-backend-fixes-e2e-yii2 backend-yii2`:
   exit **0**. Retried full build with `COMPOSE_PARALLEL_LIMIT=1`: exit **0**.
2. `python3 scripts/compose.py --env .env.example --project
   shopkmp-backend-fixes-e2e up -d --no-build --wait --wait-timeout 240`:
   exit **0**. All long-running services healthy; migrate, yii-migrate and reindex
   exited **0**. SQL inspection of this project's DB listed **001–014**. This
   uses the real embedded up runner, independently of the still-pending manifest
   additions; it is not a completed manifest/parallel-upgrade gate.
3. Same wrapper, `up -d --no-deps --scale payment-recovery=2 --wait
   --wait-timeout 60 payment-recovery`: exit **0**.
4. `docker restart shopkmp-backend-fixes-e2e-payment-recovery-1`: exit **0**;
   bounded polling of both exact container names returned healthy for each.
   `docker exec <each> php /opt/scheduler/scheduler-health.php status`: both
   exit **0**, `live=true`, `ready=true`, `lastExitCode=0`, `lastResult=success`,
   cadence 30, budget 120, success max age 155. These are real scan successes on
   an empty project queue; nonempty concurrency/crash effects are covered by the
   PostgreSQL recovery suite above.
5. `docker exec shopkmp-backend-fixes-e2e-yii2-http-1 wget -q -T 8 -O /dev/null
   <URL>` for Yii HTTP live/ready and BFF port-9090 live/ready: all exit **0**.
   Probes execute from Nginx on ingress, matching the corrected runtime checker.
6. Project-label-filtered `docker ps -a`: all long-running services healthy,
   both recovery replicas healthy, three one-shots exit 0; **only edge** published,
   at `127.0.0.1:58196`. The stack/volumes remain for the coordinator.

No seed, full business journey, live Keycloak revocation, full `integration-check`,
production/TLS smoke, dependency/image scan or independent final review was run
by this continuation. The stack was only prepared/probed; no production service
or real payment provider was used.

## Coordinator handoff / remaining commands

Keep the exported isolated environment above, add the new manifest checksums,
rebuild the final reviewed source, then:

```sh
make integration-up E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
# Optional focused rerun on that migrated disposable stack:
make test-backend-fixes E2E_PROJECT=shopkmp-backend-fixes-e2e
```

The full verifier runs scheduler tests, the shared PHP runner and the separate
focused phase; logs go to `deploy/evidence/`. The existing live trust harness,
final Go/ops/manifest/parallel-upgrade checks, real Keycloak online/offline
revocation, trusted-CA production smoke/security scans and complete independent
review remain coordinator gates. No active local blocker remains for this
assigned integration slice. Final snapshot/CI results must be reported after
those commands; the green focused results here are not that claim.
