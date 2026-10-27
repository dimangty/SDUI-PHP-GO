# Reviewed-source live verification — 2026-10-26

**Current final-wave functional status: PASS — actual root exit 0 in 1,867.87s.**
This is the rebuilt coordinated F1–F3 source, independently matched to the running
image. The earlier **1,308.67-second exit 0** applies only to its older snapshot;
earlier blocked/timed-out attempts also remain below. Final-source details are
at the end and in FINAL_FIX_REPORT.md. **Security A1 remains unresolved**, so this
functional pass is not commit/push/merge/production-release authorization.
No subagents, commits or pushes. Read PAYMENT_FIX_REPORT.md and
SESSION_FIX_REPORT.md before rebuilding. Core payment/session implementations
and migrations are not edited by this verification work.

## Isolation and exact environment

Verified Compose labels on all existing containers, networks and three data
volumes before deleting **only** `shopkmp-backend-fixes-e2e`. Other stacks,
including TLS project port 58198, are untouched. Original command logs are
ignored `deploy/evidence/backend-live-*.log` (that directory ignores `*.log`).
The external command/logging helper is under the preapproved temporary opencode
directory, `backend_live_run.py`; it supplies:

```sh
export PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH
export COMPOSE_PROJECT_NAME=shopkmp-backend-fixes-e2e EDGE_PORT=58196
export EDGE_SUBNET=172.31.220.0/24 EDGE_IP=172.31.220.2
export YII_HTTP_IP=172.31.220.3 SDUI_IP=172.31.220.4 TRUSTED_BFF_IPS=172.31.220.4
export YII_FPM_SUBNET=172.31.219.0/24 YII_FPM_HTTP_IP=172.31.219.3
export YII_FPM_DYNAMIC_RANGE=172.31.219.128/25
# Disposable run only, after observed Docker OOM exits with the 512 MiB heap:
export OPENSEARCH_JAVA_OPTS='-Xms256m -Xmx256m -XX:MaxDirectMemorySize=128m'
# Test fixtures only: proportional scheduler deadlines/waits for loaded Docker VM.
export SCHEDULER_TEST_TIME_SCALE=3
```

## Actual attempts retained so far

Every Make invocation below included
`E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196`.

| Command / log suffix | Exit / observation |
| --- | --- |
| Wrapper `--verify --profile verification down --volumes --remove-orphans` (`recreate-down`) | 2, argparse rejected Docker flags before a positional command; no resources changed. |
| Wrapper `--verify down --volumes --remove-orphans` (`recreate-down-retry`) | 0; only the label-verified project/resources removed. |
| `make integration-up` (`integration-up`) | 2; rebuild succeeded, but Docker allocated dynamic data network `172.31.0.0/16` before exact ingress `/24`, causing an overlap. |
| Wrapper down (`failed-network-cleanup`) | 0; removed this attempt's two project networks only. |
| `make integration-up` (`integration-up-retry`) | 2; attempted `create --no-deps` is unsupported by Compose 5.3.1. Changed to supported `up --no-start --no-deps`. |
| `make integration-up` (`integration-up-fixed`) | 2; fixed pools allocated, migrations/reindex exited 0 and most services healthy, but OpenSearch became unhealthy/restarted. Docker subsequently recorded OOM and exit 137. |
| `make integration-up` (`integration-up-final`, `integration-up-ready`) | Both 2; rebuild recreated dependencies while old internal HTTP remained unhealthy. Subsequent direct readiness returned 200; old proxy health gated startup before recovery. |
| `make integration-up` (`integration-up-staged`) | **Interrupted by user**, no exit code/result claimed. Rebuild and migration/reindex completed; Keycloak was still warming. |
| `docker exec shopkmp-backend-fixes-pg createdb -U backend_fixes trust_final` (`trust-db-create`) | 0; new empty DB only, no old `trust_fixes` changes. |
| Real `go -C tools/ops run ./cmd/migrate` with host 55493 `/trust_final` URL (`trust-db-migrate`) | 0, normal 001–014 history, no forged checksum/history. |
| Trust harness against rebuilt PHP image and `/trust_final` (`trust-live`) | 1; real IPv4/IPv6 and same-subnet untrusted-peer checks passed, then curl hit its 10-second timeout in authenticated traffic while concurrent stack rebuild/load was active. Remaining checks **not completed** in this attempt. Harness cleanup ran. |
| Resume `up -d --no-build --wait --wait-timeout 240` (`resume-up`) | 1; actual FPM network inspection found dynamic PHP assigned `172.31.219.3`, the stopped Nginx peer's fixed address. Nginx could not start (`Address already in use`). |
| Real realm `run --rm --no-deps -e ADMIN_EMAILS=admin@test.example yii2 php tests/auth.php` (`live-auth-first`) | **0, 4 tests / 0 failures**, including new online/offline and opaque password-family checks. |
| Wrapper `down --remove-orphans` (`network-recreate-preserve-data`) | 0; recreated this project's networks/containers, preserving all three database/search volumes and normal history. |
| `make integration-up` (`integration-up-network-range`) | 2; temporary helper omitted the new range override, so the example range was outside the isolated subnet. Corrected helper environment; no database/history changes. |
| `make integration-up` (`integration-up-range-corrected`) | **0**, 107.27s; rebuilt reviewed source, normal 001–014 migrator/Yii/reindex, all readiness gates, seed and explicit reindex completed. |
| `make integration-check` (`integration-check-first`) | 2; route matrix passed, scheduler suite ran all 18 cases in 222.565s with five failures in 2–3-second fixture timing/paused-child waits. Pipeline stopped there: PHP/Go/focused/runtime were **not run by this attempt**. |
| Resolved config + exact current schema (`config-and-schema`) | 0; shared model tests 6/0, exact networks printed, SQL history versions/names/checksums 001–014 printed, source hashes recorded. |
| `make integration-check` with fixture timing scale 3 (`integration-check-scaled`) | 2; all 18 scheduler cases executed, two failures remained. Later diagnostics below found a real fixture file-sharing defect; timing alone was not the full explanation. |
| Paused-parent diagnostic (`scheduler-pause-diagnostic`) | 1; `docker top` proved only parent stopped, child initially running, then PHP child died on `trim(false)` after host mode-file rename was observed as ENOENT through shared filesystem. |
| Same diagnostic after container-side locked mode update/read (`scheduler-pause-green`) | 0, actual child completed while parent remained stopped. Production scheduler unchanged. |
| Full shared PHP, independently (`php-all-independent`) | 1; earlier suites including live auth/session/logout passed; both session-upgrade cases failed because `/migrations` resolved outside the repo and was empty. Remaining child suites were not reached. |
| Canonical migration-mount regression (`migration-mount-red`, `migration-mount-green`) | **1 → 0**, 6 cases with one mismatch → all 6 passed. Corrected override path relative to root Compose file, retained read-only mount. |
| Full Go race/live independently (`go-live-independent`) | 1; ordinary packages/contract regressions passed, but live journey tried reusing its PIN-issued token after deleting that PIN. Actual 401 correctly enforced the new family policy. |
| Corrected live journey (`go-live-regression-green`) | **0**; retains an explicit 401 assertion for the deleted PIN family, uses valid password JWT for biometric cleanup, then issues a fresh PIN family for checkout/logout and final logout rejection. |
| Final RR1-source rebuild/up (`integration-up-reviewed-worker`) | 2; build/migration succeeded, reindex exited 1 with actual upstream timeout. OpenSearch was healthy with zero restarts, but health requests were taking seconds under host load. No adapter deadline changed. |
| Bounded retry of actual `make integration-up` (`integration-up-reindex-retry`) | **0**, 117.61s. Includes final RR1 production files/new scheduling suite and expanded auth tests, normal migration validation, seed-preservation behavior and reindex. |
| Full actual `make integration-check` (`integration-check-reviewed`) | **Outer tool timeout at 1,200,000ms; no successful root exit.** Route matrix, all 18 scheduler cases, seed preservation, initial reindex, signed CARD edge journey, full Go race/live contracts, BFF vet, ops tests/vet and the entire shared PHP runner passed. Focused migration passed 13/0; payment lifecycle finished 107 cases with two failures (strict real-time bound and PostgreSQL connect timeout). Cleanup was interrupted; payment HTTP/recovery/fairness/runtime phases were not reached by this attempt. |
| Final trust harness (`trust-live-final`) | **0**, 56.87s; all seven live checks passed against rebuilt final PHP and normally migrated `trust_final`: IPv4, IPv6, two exact BFFs, user/multiplier accounting, webhook source, untrusted peer and raw FastCGI refusal. |
| Focused runner bounded retry (`focused-reviewed-retry`) | **1**, 157.05s; migration 13/0, payments 107/3. One actual `UPDATE payment_operations ... WHERE id=...` hit the existing 10-second statement timeout; two unchanged 10.8-second wall-time assertions failed. No payment/core/deadline expectation was weakened. |
| Remaining child suites independently (`focused-remaining`) | Payment HTTP **4/0**, existing recovery **9/0**, new RR1 scheduling **6/3**. Scheduling contention/transaction bounds and real scheduler success failed in this environment; actual scheduler reported failed after 21.239s, healthy session still REVOKE_PENDING with zero attempts. Cleanup stopped fixtures; restoration via Compose `start` failed because it unexpectedly reran a dependency reindex. Outer helper exit **1**. |

## Evidence-backed integration/test changes so far

- `Makefile integration-up` prepares proxy containers/network configuration with
  `up --no-start --no-deps yii2-http edge`, and stops this
  project's app/proxies/workers before rebuilding dependencies. The observed
  overlap and old-unhealthy-proxy failures above motivated these changes.
- `docker-compose.yml` accepts `OPENSEARCH_JAVA_OPTS`, preserving the existing
  512 MiB default. The isolated run selects a smaller heap following actual OOM
  events. No search/security behavior is disabled.
- The FPM network now accepts an optional dynamic allocation range excluding
  the static peer. This run uses `172.31.219.128/25`, leaving Nginx exactly `.3`;
  stopped/recreated PHP/CLI containers cannot take that static address. Existing
  data/history will be retained while recreating only this project's networks.
- Trust harness subprocess exceptions no longer echo argv containing disposable
  bearer tokens. Future command logging also redacts opaque token strings.
- `scripts/test_scheduler.py` now supports an explicit fixture-only proportional
  timing scale (1–5; default 1), following the observed loaded-VM timing failures.
  This run selects 3: cadence, fixture runtime budget, success age, health timeout
  and bounded waits scale together. Every failure/staleness/timeout/recovery/
  signal assertion remains enabled. Production scheduler/heartbeat is unchanged.
- Follow-up scheduler diagnosis found a concrete fixture defect beyond timing:
  mode changes now execute inside the container under LOCK_EX; the fixture reads
  under LOCK_SH, preventing Docker Desktop host-rename ENOENT/partial reads.
  The selected shutdown wait scales as well; all exit/signal assertions remain.
- Fixed `/migrations` source resolution in the verification overlay, with a
  real resolved-model regression. Added read-only deployed scheduler mount and
  `recovery-scheduling.php` to the focused runner for the RR1 agent's new real
  mixed-backlog/deadline suite. No agent-owned recovery source was edited here.
- Go live fixture now follows the real credential-family revocation contract,
  explicitly tests revoked-token rejection, and obtains a fresh opaque family
  before later mutations/logout. No Go application/security implementation changed.
- Cleanup/restoration now uses `up -d --no-deps` rather than Compose `start`:
  the actual remaining-suite attempt showed `start` rerunning the dependent
  one-shot reindex and failing restoration. The runner command-boundary
  regressions now require explicit no-dependency worker restoration.

### Resource/timing evidence, not a production-fix claim

The payment failures above are not dismissed as green. PostgreSQL logs confirmed
an ID-targeted SENT update canceled by the existing statement timeout. At the
inspection snapshot the main tables had only 225 payments/278 operations, fresh
auto-analyze timestamps and zero non-internal triggers; there was no stale
failure trigger or large-table/statistics explanation to erase. Other observed
failures include an actual DB connect timeout and strict wall-time overruns.
The RR1 suite's elapsed-time and scheduler-success assertions remain unchanged.
Its source-mounted producer report is separate evidence, not a substitute for
these rebuilt-environment failures. The root release verification is **blocked**
until the unchanged strict cases can complete on a sufficiently responsive
isolated environment or a meaningful production defect is demonstrated/fixed.
- `backend-yii2/tests/auth.php` adds real realm online/offline session inspection
  and revocation, checks the signed access JWT remains valid before its real
  300-second expiry, and strengthens password change with two real PIN-issued
  opaque families/rotation. **First live execution passed 4/0.** They
  reflect the reviewed Keycloak/user-revocation contract, not an immediate JWT
  revocation assumption. No core implementation changed to satisfy them.

## Historical interruption checkpoint

After interruption, exact-project inspection found Keycloak and OpenSearch
healthy (Keycloak 26.3.3, zero current restart count), three one-shots exit 0,
and app/worker containers Created or stopped. Continue from these resources;
do not recreate/forge the database again. A separate agent is implementing RR1
worker fairness; independent PHP/auth/Go verification can run before that final
worker snapshot is rebuilt.

## Final executed results and outstanding gates

- Actual `make integration-up` passed with the final RR1 source. Actual
  `make integration-check` was run in full, but did not return a successful exit.
- Shared PHP/live auth, full Go race/live contracts and final trust harness passed.
  Focused phases and runtime were additionally executed as documented below,
  including their failures. No failed phase is counted as passed.

Production TLS/security is handled by the other project/workstream and is not a
success claim here. Generated tracked `deploy/evidence` fixtures/JSON must not
be staged as source changes; coordinator reviews their restoration separately.

## Verification matrix before the resource-headroom follow-up

| Check | Final observed outcome | Evidence |
| --- | --- | --- |
| Mandatory actual `make integration-up E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196` | **PASS, exit 0**, final RR1-source rebuild; 117.61s final successful invocation | `backend-live-integration-up-reindex-retry.log` |
| Mandatory actual `make integration-check` with the same project/port | **NOT PASS**; last complete invocation exceeded the 1,200-second outer tool limit after reaching the focused phase; no root exit 0 | `backend-live-integration-check-reviewed.log` |
| Scheduler, actual deployed script/probes, all three commands | **18/0, exit 0**, fixture timing scale 3; no cases skipped | `scheduler-regression.log`, 325.481s |
| Seed preservation / initial reindex | **Both exit 0** | `seed-preservation.log`, `initial-reindex.log` |
| Real edge CARD journey / signed callback | **Exit 0** | `edge-card.log` |
| Full Go `test -race ./... -count=1 -v` with both live origins set | **Exit 0**, eight live screens/six commands and corrected native revocation/logout flow executed | `bff-race.log` |
| BFF vet / ops tests / ops vet | **Each exit 0** | `bff-vet.log`, `ops-tests.log`, `ops-vet.log` |
| Entire shared PHP `composer test:all` | **154 cases / 0 failures**, all 22 behavioral child suites and syntax **79 files**, exit 0 | `php-all.log` |
| Live Keycloak authentication additions | **4/0**, separately and within shared PHP | `backend-live-live-auth-first.log`, `php-all.log` |
| Payment migration focused | **13/0**, exit 0 in both focused attempts | `backend-fixes.log`, `backend-live-focused-reviewed-retry.log` |
| Payment lifecycle focused | **107/2** first attempt; **107/3**, exit 1 on bounded retry; **BLOCKED** | First result archived as `backend-live-pre-headroom-backend-fixes.log`; bounded retry remains `backend-live-focused-reviewed-retry.log`; original strict assertions unchanged |
| Payment real HTTP focused | **4/0, exit 0**, separate remaining-phase invocation | `backend-live-focused-remaining.log` |
| Existing recovery integration | **9/0, exit 0**, separate remaining-phase invocation | Same log |
| New RR1 mixed-backlog/deadline suite | **6/3, exit 1**, rebuilt image; **BLOCKED** | Same log |
| RR1 focused child diagnostic | **1/1, exit 1**; actual child stderr is SQLSTATE **57014**, statement timeout. This diagnostic run did complete the healthy session once, but the scan still failed | `backend-live-fairness-child-diagnostic.log` |
| Final live trust, two BFFs, IPv4/IPv6/FPM negative | **All seven checks, exit 0** | `backend-live-trust-live-final.log` |
| Two real recovery replicas, restart one, fresh ready/success | **PASS, exit 0**, 17.16s | `backend-live-recovery-replica-restart.log` |
| All-worker deployment wait | **Exit 1**, 185.40s; search reconcile not ready after upstream timeout; recovery replicas and maintenance were healthy | `backend-live-workers-two-up.log`, `backend-live-worker-health-diagnosis.log` |
| Existing automatic runtime/shutdown checker | **Exit 1**, 11.24s; failed honest `reconcile.ready=false` assertion before shutdown. Full app stop/restart portion **NOT EXECUTED** | `backend-live-runtime-attempt.log` |
| Final resolved integration model/runner regression | **6/0, exit 0** | `backend-live-final-config.log` |
| Original SQL / source shape / syntax / format | **22 old SQL files byte-identical; five Python AST checks; `git diff --check`; `gofmt -d` on changed Go test: exit 0** | `backend-live-final-snapshot.log`, `backend-live-final-whitespace.log` |

All `.log` paths above are under `deploy/evidence/`. A failed root run does not
become a pass because independently executed child phases passed. The final
worker successes do not replace the failed mixed-backlog regression.

### RR1 SQL diagnostics

The final producer source matches [RR1_FIX_REPORT.md](RR1_FIX_REPORT.md). No
changes to those core files were made here. The extra in-memory diagnostic added
only stderr printing to the test's evaluation; its checked-in bytes/hash did not
change. It reported `lastResult=failed` at **20.864s**, with the healthy session
`COMPLETE`, attempts 1, followed by the real child error:

```text
SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout
Scheduled maintenance/recover failed: 1
```

PostgreSQL log observations include timeout of the payment-candidate SELECT,
`INSERT INTO payment_operation_observations ... ON CONFLICT DO NOTHING`, and
subsequent setting restoration. The connection's new 1-second statement limits
are actually enforced. The earlier six-case run also failed the contention and
transaction elapsed-time assertions. These are recorded failures requiring a
responsive-environment rerun or further review; no deadlines, provider contracts,
financial assertions or production health checks were disabled to obtain green.

## Live Keycloak and trust coverage

The expanded `tests/auth.php` exercised the real **Keycloak 26.3.3** realm at
`http://keycloak:8080/realms/shopkmp`, without an admin-token override:

1. Issued distinct password-grant online (`Refresh`) and offline (`Offline`)
   refresh tokens. Queried live user sessions to derive the actual internal
   client UUID, verified both online/offline session lists were nonempty.
2. User-scoped logout removed both session lists and made online, offline and
   registration refresh tokens fail. Existing service-account roles sufficed.
3. The already-issued access JWT remained accepted after remote/local logout
   while unexpired. Its actual `exp-iat` was **300 seconds**. The test does not
   invent immediate offline-JWT invalidation and does not claim to have slept
   through a live five-minute expiry. Existing signed-JWT expired-token cases
   also ran in the shared suite.
4. A real successful password change rejected access/refresh values from two
   PIN-issued opaque families and a rotated descendant, completed the durable
   operation/unblocked the user, rejected the old password, accepted the new
   password and permitted fresh credential login.

Final trust command (using the same Docker PATH and isolated environment above):

```sh
TRUST_DATABASE_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/trust_final?sslmode=disable' \
TRUST_DB_NETWORK=shopkmp-backend-fixes-20261026 \
TRUST_PHP_IMAGE=shopkmp-backend-fixes-e2e-yii2 \
python3 backend-yii2/tests/trust-live/run.py
```

Exit **0**: independent real IPv4 /24 and IPv6 /64 clients; same client budget
across direct API/composed screens; actual two BFF upstreams; verified-user
ceiling/multiplier; rotating forged headers from an untrusted ingress peer;
independent webhook source/HMAC policy; and forged raw FastCGI rejected with zero
response bytes/no application execution. Its unique harness project cleaned up.
Only new `trust_final` was migrated/tested, leaving historical `trust_fixes`
untouched. Final normal migration checksum comparison passed for both this DB
and the root project's `shopkmp` DB, versions **001–014**; no history forgery.

## Snapshot before the resource-headroom follow-up

Observed versions: PHP **8.3.36**, PostgreSQL **17.10**, Keycloak **26.3.3**,
Docker client/engine **29.6.2**, Compose **5.3.1**, Python **3.14.7**, host Go
**1.27.2** (module toolchain auto-selection retained). The exact final images and
source checks are in `backend-live-final-snapshot.log`. Built PHP source hashes
were independently read inside the running container and match the worktree:

```text
6a06fb880af738907bfc8fd93a6505ffc5f2ed0e33795fd409cafad49833dc2b  RecoveryService.php
03a402f44568cc2b2d58f31d360dc6a36766f853ca54b8d56f4ec4b29df64093  PaymentOperations.php
9688dc2ed6e4a036ac3c1901909f34337727e477d5fea05872282960fde3ef56  tests/auth.php
daf7f837c4c392e0280279bf081d256a35223e69b076d7580223b288692d1daa  tests/recovery-scheduling.php
```

Final service inspection: both recovery replicas, maintenance, Yii/FPM/internal
HTTP, BFF, edge, both databases, Keycloak and OpenSearch were healthy. Search
reconcile remained not ready/failed after an upstream timeout. The one-shot
reindex had exit 1 because the old cleanup `start` path had rerun it; the actual
successful `make integration-up` reindex/seed logs are retained separately. The
corrected cleanup now uses `up -d --no-deps` and cannot rerun that dependency.
Only edge is published, `127.0.0.1:58196`. All three task fixture services were
stopped successfully at handoff (`backend-live-fixture-cleanup.log`, exit 0).
Stopped builtin-server fixture containers may have exit 137 after their normal
Compose stop timeout; that is not a successful graceful-app-stop claim.

The full root run updated **30 tracked BFF capture JSON files plus
`deploy/evidence/integration.json`**. `runtime.json` was not refreshed because
the runtime gate failed. Exact paths are inventoried in the ignored final
snapshot log. They remain unstaged for the coordinator's restoration review;
**the index contains no staged paths**. Logs are ignored, no new evidence JSON
paths are untracked. A disposable opaque token in the initial failure traceback
was redacted in that ignored log while preserving its actual command failure;
subsequent harness errors omit credential-bearing argv.

## Source changes attributable to this final verification

- `Makefile`: staged app/proxy stop and proxy preparation for isolated rebuilds.
- `docker-compose.yml`, `.env.example`, `.env.prod.example`: optional FPM dynamic
  range excluding exact peer addresses; configurable JVM heap with unchanged
  default, qualified smaller heap selected for this run.
- `deploy/compose.verify.yml`: corrected canonical migration path and added
  read-only deployed scheduler scripts for the new RR1 suite.
- `scripts/backend_fixes.py`, `scripts/verify.py`,
  `scripts/test_backend_integration.py`: register RR1 scheduling suite; preserve
  no-dependency worker restoration; actual path/command-boundary regressions.
- `scripts/test_scheduler.py`, `deploy/tests/scheduler-job.php`: container-side
  locked fixture controls, explicit fixture timing scale, proportional waits.
- `backend-yii2/tests/auth.php`: genuine live provider/opaque-revocation cases.
- `backend-sdui/internal/bff/live_integration_test.go`: preserve expected
  credential revocation and use a fresh session for the later native journey.
- `backend-yii2/tests/trust-live/run.py`: no bearer argv in failure tracebacks.
- `.github/workflows/ci.yml`: fix the observed wrapper-argument ordering in log/
  cleanup commands; remote CI itself **NOT RUN**.
- Backend README, migration/rollback guide and this live report: current RR1
  bounded-work policy, allocation guidance and actual results.

## Outstanding gates at the pre-headroom checkpoint

This checkpoint describes the earlier blocked state. The completed follow-up
below supersedes the root/runtime/focused failures; the original observations
and failure logs are retained.

The root release gate remains **BLOCKED** on strict focused payment timing/SQL
failures, rebuilt RR1 scheduling failures, and search-worker/runtime readiness.
On an appropriately responsive isolated environment, retain the source and
strict expectations and rerun the same exported environment plus:

```sh
make integration-up E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
```

For diagnosis, the exact independent remaining-suite argv sets and all actual
exits are retained in `backend-live-focused-remaining.log`; the normal focused
runner command is:

```sh
python3 scripts/backend_fixes.py --env .env.example \
  --project shopkmp-backend-fixes-e2e --disposable
python3 scripts/runtime_checks.py --env .env.example \
  --project shopkmp-backend-fixes-e2e --url http://127.0.0.1:58196 --shutdown
```

Final default-scale-1 full scheduler rerun, complete root success, full automatic
app shutdown/restart after all gates, live five-minute JWT expiry wait, production
cutover/rollback and remote CI were **NOT RUN / NOT COMPLETED** as applicable.
TLS/security/image/dependency scans belong to the separate workstream at port
58198; see SECURITY_TLS_RESULTS.md. They were **not rerun or claimed here**.
No unrelated stack/database, core payment/session implementation or migration
history was edited by this verification. No subagents, commits or pushes.

## Follow-up after resource headroom and ops dependency update

The coordinator stopped only its own TLS project's running containers, freeing
approximately 1.5 GiB, and updated ops `x/text` to **0.41.0** and `x/sync` to
**0.22.0** through normal tidy. Prior failures above remain part of the chronology.
This follow-up uses the existing root database/001–014 history and identical
strict payment/RR1 assertions; it makes no core/schema/deadline changes.

At the resume inspection there were no active non-idle business-DB queries or
lock waits. Recovery replicas and the application were healthy; search reconcile
was still reporting its earlier failed readiness. Planned bounded sequence:
rebuild migrate/Keycloak binaries, rerun strict payment 107 and RR1 scheduling 6,
stop unused fixtures, check search/runtime, then run the actual full root command
once if those children are stable. A detached command supervisor will preserve
the actual root exit in an ignored status log instead of conflating a terminal
tool time limit with the command's result. Results are appended below as run.

| Follow-up command | Actual outcome |
| --- | --- |
| `python3 scripts/compose.py --env .env.example --project shopkmp-backend-fixes-e2e build migrate keycloak` | **Exit 0**, 15.04s. Both actual Go build steps executed successfully with updated ops module inputs; no Dockerfile or dependency source edits here. |
| Strict existing `payments.php` with the same isolated provider/HTTP env | **107/0, exit 0**, 49.023s. Original 10-second provider deadlines and strict wall-time assertions unchanged. |
| Strict existing `recovery-scheduling.php` | **6/0, exit 0**, 18.809s. Actual transaction termination 5.043s; actual scheduler success/healthy session COMPLETE with attempt 1 after 8.774s. |
| Combined strict follow-up wrapper | **Exit 0**, 82.64s including fixture startup/cleanup. Paused this project's three background workers for independent strict checks, then restored them without dependencies. |

Evidence: `backend-live-headroom-ops-images-build.log` and
`backend-live-headroom-strict.log`. This clears the prior focused-suite failures
for this rerun; it does not erase the failures above or yet establish root success.

The follow-up `make integration-up` rebuild deployed the updated binaries and
normal migrate/Yii/reindex jobs exited 0, but its synchronous terminal call hit
the 600-second tool cap while waiting for search's first successful scheduled
reindex. There was no command exit to claim. Inspection showed the one-shot had
successfully reconciled **356 SQL products**, all other services healthy, and
the scheduler still running its first reindex (no failed result). This is a
bounded first-success wait, not the previous immediate timeout failure. The
existing data/history were retained. Search/runtime checking now runs under a
detached bounded supervisor (`backend-live-headroom-runtime.status.log`) so the
actual exit survives terminal call limits; no root rerun has started yet.

Search subsequently reported **live=true, ready=true, lastResult=success,
lastExitCode=0** before the automatic runtime/shutdown checker began. The checker
is now waiting for genuine post-restart worker success, including reindex of the
356-product fixture catalog, within its normal bounded deployment wait.

The rebuilt binaries were copied from this project's actual migrate and
Keycloak containers and inspected with `go version -m`, exit **0**:

- Both are built with **Go 1.26.10**, Linux/arm64, CGO disabled.
- Actual `/migrate` embeds **x/sync 0.22.0** and **x/text 0.41.0**.
- The actual Keycloak health binary is the stdlib-only `shopkmp/ops/cmd/healthcheck`.

Evidence: `backend-live-headroom-built-ops-metadata.log`. This verifies running/
applied image provenance after the dependency update, not merely a cached tag.

The bounded search/runtime follow-up has now **finished with actual exit 0**
(375.45s). It verified the genuine search success, all internal/FPM/BFF probes,
actual edge/rate principal, private ports, normal migration histories, and
graceful application/worker stop plus successful restart/readiness. This clears
the earlier runtime/search gate for this follow-up. Evidence:
`backend-live-headroom-runtime.log` and its finished `.status.log`.

With strict payment/scheduling and runtime children stable, launched the actual
full root command **once** under the detached supervisor:

```sh
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
```

Evidence/status: `backend-live-headroom-root.log` and
`backend-live-headroom-root.status.log`; supervisor bound 3,600 seconds. The
status records the actual exit, not a terminal timeout. Prior phase logs that
the root driver will replace were preserved under ignored
`backend-live-pre-headroom-*.log`, including the original focused **107/2**
failure log. Existing earlier uniquely named failure/diagnostic logs remain.

Root follow-up progress checkpoint: route matrix, all **18 scheduler cases**
(234.876s), seed preservation, initial reindex, signed CARD journey, full Go
race/live contracts, BFF vet, updated ops tests/vet and the entire shared PHP
runner have passed. The root is currently executing the focused backend phase;
there is still no final root exit to claim at this checkpoint.

Next root checkpoint: the complete focused backend phase also passed (all five
registered suites: migration, payment lifecycle, real payment HTTP, existing
recovery and RR1 scheduling). Fixtures were stopped and SQL workers restored
with `up -d --no-deps`. The root has reached its final automated runtime/shutdown/
restart phase; its actual result remains pending in the supervisor status.

## Final headroom follow-up result — actual full root exit 0

The single requested full root rerun **completed successfully**:

```text
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
actual command exit: 0
actual command duration: 1308.67 seconds
supervisor state: finished
supervisor recorded exit: 0
supervisor elapsed: 1308.73 seconds (includes wrapper overhead)
```

The terminal tool did not terminate this command. Its detached supervisor bound
was 3,600 seconds; the command finished normally. Both its actual command/footer
and finished status are retained:

- `deploy/evidence/backend-live-headroom-root.log`
- `deploy/evidence/backend-live-headroom-root.status.log`
- `deploy/evidence/backend-live-headroom-final-results.log`

### Complete final root matrix

| Root phase | Observed final result |
| --- | --- |
| Route matrix | Exit 0; canonical 54 operations/policies, eight screens/six commands |
| Scheduler regressions | **18/0**, exit 0; 234.876s, existing fixture timing scale 3 |
| Seed preservation and initial reindex | Each exit 0 |
| Real signed CARD edge journey | Exit 0 |
| Full Go race suite with live Yii/BFF origins | Exit 0; actual live eight-screen/six-command journey executed |
| BFF vet | Exit 0 |
| Ops tests/vet with updated module inputs | Each exit 0 |
| Entire shared PHP runner | **154/0**, exit 0, syntax **79 files** |
| Focused migration | **13/0**, exit 0 |
| Focused payment lifecycle | **107/0**, exit 0 |
| Focused real payment HTTP | **4/0**, exit 0 |
| Focused existing recovery | **9/0**, exit 0 |
| Focused RR1 scheduling | **6/0**, exit 0 |
| Final automatic runtime/shutdown/restart | Exit 0; every worker genuinely ready before/after restart |

Thus the root executed **293 passing PHP behavioral cases** (154 shared + 139
focused), plus 79-file syntax, all 18 scheduler cases, Go/ops checks and live
runtime checks. No failed suite was skipped or converted into a zero-test pass.
The final RR1 suite observed transaction-bound termination after **5.044s** and
successful scheduler/healthy-session completion after **8.786s**, attempt 1.
Original strict SQL/provider deadlines and all behavioral/timing assertions were
unchanged in this follow-up.

The fresh tracked `deploy/evidence/runtime.json` confirms:

```text
schemaMigrationCount = 14
yiiHistoryCount = 1
edge, maintenance, payment-recovery, reconcile, sdui, yii2, yii2-http:
    graceful stop exit = 0 for every service
maintenance, reconcile, payment-recovery, before and after restart:
    live = true; ready = true; lastExitCode = 0
```

### Final replicas, provenance and handoff state

After the root completed, scaled only the recovery service to **two** real
replicas, waited for successful readiness, restarted replica 1, and confirmed
both replicas healthy with real successful child results. Exit **0**, 12.18s;
`backend-live-headroom-final-replicas.log`. This left the final application,
search worker, maintenance and both recovery replicas healthy. The fixture
provider/HTTP/upstream services are stopped. Only edge is published at
`127.0.0.1:58196`. No unrelated stack was operated by this follow-up.

Final snapshot (`backend-live-headroom-final-snapshot.log`, exit 0) confirms both
root `shopkmp` and external `trust_final` retain normal **001–014** names and
source checksums, the 22 original SQL files remain byte-identical to HEAD, and
the running PHP image contains the exact final RR1 production/test source hashes
recorded earlier. No schema/history change or production/test assertion change
was made here. Current ops input hashes:

```text
1cc8e928a4aa27bbafbc95cafd17dd3d98b4e366bd0c862bf14b0ca54aac9346  tools/ops/go.mod
b90c1ce39e44b933baf4b2d6c8674e41f9d66d8b71b1d94e93923d3441c5f469  tools/ops/go.sum
```

The rebuilt migrate/Keycloak health binaries' Go 1.26.10 metadata and actual
migrate `x/text 0.41.0` / `x/sync 0.22.0` linkage are recorded in
`backend-live-headroom-built-ops-metadata.log`. Their image build succeeded with
the existing Dockerfiles; the stdlib-only health build needed no source change.

There are now **36 unstaged generated tracked evidence diffs**: 34 BFF capture
JSON files (additional category continuation pages reflect the retained test
catalog), `integration.json` and the newly successful `runtime.json`. Exact paths
are inventoried in the final snapshot log. They are retained for coordinator
restoration review and must not be included as noisy source changes. The Git
index is empty. Earlier failure evidence remains in uniquely named and archived
ignored logs; no failure was erased to obtain this result.

**No live integration blocker remains under the documented test environment.**
The earlier strict failures cleared after resource headroom was restored, with
no core edit or assertion/deadline weakening. This is functional verification
under the recorded heap/fixture settings, not a default production-capacity
sign-off. Separate whole-diff review, security/TLS release assessment, remote CI
and production cutover remain coordinator-owned; no subagents, commits or pushes
were used.

## Final coordinated F1–F3 source wave — 2026-10-27

The coordinator completed `NO_EFFECT` reversal audit/manual handling,
advisory-busy look-ahead selection and retention-first/deferred expiry maintenance
after the preceding root pass. That older pass is not used to claim this source
wave passed. No core, test expectation, business deadline or schema/history edit
was made by this verification continuation. Security **A1 remains open**.

Read the final source and FINAL_CODE_REVIEW.md; the available RED evidence is
explicitly attributed in [FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md), with no
invented fix-agent RED transcript. Only own image `shopkmp-backend-fixes-e2e-yii2`
was rebuilt, exit 0 (4.51s), and only this project's affected app/proxy/SQL worker
services were restarted with `--no-deps`. Existing migrate/Keycloak/databases,
normal final 001–014 history, stopped TLS project and unrelated stacks were not
recreated or changed. Main's `shopkmp-f123-provider` / `shopkmp-f123-http` remain
running and unmodified.

Fresh rebuilt affected-suite wrapper: **exit 0**, 135.30s. Exact suite argv,
source hashes, fixture start/stop and worker restoration are retained in
`backend-live-final-wave-affected.log`:

| Suite | Cases / failures | Actual exit |
| --- | --- | ---: |
| payment-migration.php | 13 / 0 | 0 |
| payments.php | 113 / 0 | 0 |
| payment-http.php | 4 / 0 | 0 |
| recovery.php | 9 / 0 | 0 |
| recovery-scheduling.php | 11 / 0 | 0 |
| integration.php, gateway URL/key explicitly empty | 11 / 0 | 0 |

The coordinator separately supplied matching current-source focused results and
reported/corrected an integration **11/1** setup run with accidental gateway
settings. This verifier's integration **11/0** deliberately used the demo/local
environment. It is not a rewritten assertion or fabricated pre-fix RED.

Final source hashes (under `backend-yii2/`):

```text
a28fc2baab6516a564462144c77d363264256e8717101211f5217a920fc1e426  application/PaymentOperations.php
3d18e0bc02859bd6220f61f8d2b1562b3c1aec716ddcb5cebbbf5b4a61fda337  application/RecoveryService.php
e744ffa7f74a8d2da35f1aa41fc3ece3135beb6520ea034a1b1e4154543aa240  application/MaintenanceService.php
```

Archived prior successful phase logs and runtime/Go manifest captures as ignored
`backend-live-pre-final-wave-*.log` before replacing generic phase logs. Started
the actual full root command exactly once for this final wave:

```sh
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
```

Supervisor limit **5,400s**; actual log/status are
`backend-live-final-wave-root.log` and
`backend-live-final-wave-root.status.log`. Same full isolated environment and
documented scheduler fixture scale **3**. At this checkpoint the root is running;
no final exit is yet claimed. Generated evidence JSON remains unstaged and
excluded from source changes; main owns final aggregate results/review docs.

Final-wave progress checkpoint: every phase through the complete focused backend
phase has passed on the new source. Shared PHP **154/0**, syntax **79 files**;
focused PHP **150/0** (migration 13, payments 113, HTTP 4, recovery 9, scheduling
11); all 18 scheduler cases; full Go race/live contracts and both vet checks;
ops checks and signed CARD journey. The root has entered its last runtime/
shutdown/restart phase. This checkpoint does not claim a final root exit.

### Completed final-wave root proof

The actual command finished normally with **exit 0**, **1,867.87s**. Supervisor
`backend-live-final-wave-root.status.log` records `finished`, exit 0, elapsed
1,867.946s; the complete command/footer is in `backend-live-final-wave-root.log`.
This replaces the old-source pass as the final functional source proof, without
erasing earlier failed or interrupted attempts.

Final root results:

- Shared PHP **154/0**, syntax **79 files**.
- Focused PHP **150/0**: migration **13**, payments **113**, payment HTTP **4**,
  recovery **9**, scheduling **11**.
- **304** PHP cases in the coherent root run; the separate preflight is not
  double-counted.
- All **18** scheduler cases, 244.387s, fixture scale 3.
- Route/schema matrix, seed preservation, initial reindex, signed CARD journey,
  full Go race/live contracts, BFF vet, ops tests/vet: every phase exit **0**.
- Final runtime/shutdown/restart: exit **0**. All seven app/worker stop exits
  are 0; all three workers are live/ready with successful last children before
  and after restart. Normal schema count **14**, separate Yii history count **1**.

`backend-live-final-wave-proof.log`, exit **0**, independently verifies exact
frozen worktree/running-image hashes for PaymentOperations, RecoveryService,
MaintenanceService and both affected test suites. Actual running PHP image:
`sha256:3de5a2092cfa0a3ebc7a6c6bd09a2bdee6fd4899ea1eab801f0e463d1c06d277`.
Both root `shopkmp` and external `trust_final` normal 001–014 names/checksums still
match source; all 22 original SQL files are unchanged. No schema/history rewrite
was performed for this wave.

Final actual recovery replica readiness/restart passed, exit **0**, 12.34s:
`backend-live-final-wave-replicas.log`. Both replicas and all owned running app/
worker services are healthy. Own fixtures are stopped; main's f123 provider/HTTP
remain running, TLS project remains stopped, unrelated stacks are untouched.
The inventory lists **36 tracked generated evidence diffs** and **eight untracked
category-page JSON captures**, all unstaged for coordinator restoration review.
No generated evidence is included as source; the Git index is empty.

See FINAL_FIX_REPORT.md for exact rebuilt suite argv, main-result attribution,
review RED provenance and the NO_EFFECT/busy-head/maintenance policies. Main owns
TEST_RESULTS.md and FINAL_REVIEW.md. **A1 remains open**; no subagents, commits,
pushes or release approval are part of this functional verification.
