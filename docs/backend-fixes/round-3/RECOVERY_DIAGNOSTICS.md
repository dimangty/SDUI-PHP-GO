# Recovery scheduling diagnostics — 2026-10-29 UTC

## Outcome: NOT PASS in the restored shared environment

The four failures in `evidence/complete-live/backend-fixes.log` are real failures.
They are not changed to successes by this investigation. No application fix was
demonstrated or applied. The safe source change is additional test diagnostics.

Both immutable PHP images pass **14/14 scheduling tests** with this project's
unneeded services paused. After restoring those services, the unchanged current
image again fails **2/14** tests. Consequently, isolated green results are **not**
a replacement for the failed complete-live integration or a release approval.

### Native images and database

| Item | Actual value |
| --- | --- |
| Disposable project | `shopkmp-round3-final-e2e` |
| Baseline | `shopkmp-round3-php`, `sha256:7cd01f175f75b0213c74aca42840c282c2598f76f1a2042dd685293a02b65b83` |
| Current | `shopkmp-round3-final-e2e-yii2`, `sha256:996d5b51e56e95f4749b332f696c5285426f138208762460a66c26891a62040a` |
| Both PHP runtimes | PHP **8.3.36**, native PDO `pgsql` |
| Database | PostgreSQL **17.11**, aarch64, this project's existing PostgreSQL container |
| Server defaults | `statement_timeout=0`, `lock_timeout=0`, `transaction_timeout=0`, `max_connections=100` |
| Ordinary `Database` connection | `statement_timeout=10s`, `lock_timeout=8s` |
| Recovery/maintenance scope | `statement_timeout=1s`, `lock_timeout=800ms`, `transaction_timeout=5s` |
| Scheduling test parent budgets | Recovery **90s**, maintenance **40s**, unchanged |

`recovery-inventory` and `recovery-source-toolchains` contain versions, immutable
image IDs, and SHA-256 comparisons. RecoveryService, PaymentOperations,
PaymentService, SessionService, PasswordSessionRecovery, MaintenanceService,
Database, HttpClient and PaymentGateway are byte-identical between baseline,
current image and workspace. The original scheduling test and payment fixture
are also identical in both images. CommerceService/ShopRepository differ in the
main-owned read refactor; their changes were inspected. The baseline reproduces
the timeout failure without those changes.

## What actually failed

Historical server records were obtained from the **same PostgreSQL container**,
not inferred from elapsed time. See `recovery-original-pg-errors.log`.

| Original failure | Actual evidence and interpretation |
| --- | --- |
| Long payment apply, original line 101, old PID still present | At **21:46:43.202**, backend **23822** reports `canceling statement due to statement timeout` in `scheduling_slow_stock()` during `UPDATE products SET stock=stock+$1 WHERE id=$2`. It was a statement cancellation, **not** the transaction-timeout FATAL required by this assertion. A rolled-back but still connected backend is consistent with that error. Catching every PDOException had hidden the distinction. |
| Payment scheduler, original line 150, `failed` at 11.824s | Backend **23853** reports statement cancellation at **21:46:54.139**, then a second timeout while restoring `set_config(...)` at **21:46:58.554**. The first record has no STATEMENT attached. Reproduction exposes child stderr: `SQLSTATE[57014] ... canceling statement due to statement timeout`, followed by scheduler child exit **1**, not parent-budget timeout **124**. The unmodified baseline reproduces the scheduler failure; its server log also catches a timeout on `SELECT pg_advisory_unlock(hashtextextended($1,0))`. |
| Maintenance scheduler, original line 286, failed at 2.481s | At **21:47:24.125**, backend **24271** cancels `UPDATE orders SET stock_restored_at=now() ...` with statement timeout. CONTEXT is the FK check taking `FOR KEY SHARE` on the test schema's `addresses` row. This is not a 40-second parent timeout or a payment-provider HTTP request. |
| READY claim, original line 367 | The original PHP trace identifies **SQLSTATE 57014**, in `PaymentOperations::link()`'s transaction-link INSERT **after dispatch**, not the pre-HTTP NOWAIT claim. Backend **24514** reports statement timeout at **21:47:31.755**; no STATEMENT is attached to that server record. In the controlled runs the barrier still generates actual `55P03` on the busy order's `FOR UPDATE NOWAIT`, another candidate completes, and the blocked candidate is dispatched once after release. |

Do not conflate `57014` statement cancellation with `55P03` lock contention or
with a transaction-timeout FATAL. In successful isolated runs, the slow-stock
test logs `HY000` / native driver **7**, `FATAL: terminating connection due to
transaction timeout`, followed by `no connection to the server` during cleanup.
PDO rollback can replace the first exception; the statement observer now records
the first error before that happens.

### Connections and locks

Business fixtures use fresh, committed, test-owned `payment_legacy_<uuid>`
schemas created by the original helpers and migrations 001–014. The provider
journal remains in the original public fixture tables. Baseline/current use
the same database, provider containers, network aliases and fixture environment;
schemas are independently created and dropped for every test.

The native watcher in `recovery-baseline-observed.json` uses independent `psql`
connections to inspect `pg_stat_activity` / `pg_blocking_pids`. It observes the
deliberate user-row blocker, and `PgSleep` with **no blockers** during slow stock
updates. There was no unexpected persistent blocker observed. Historical
blocking-PID snapshots do not exist for the four original failure instants, so
the historical FK-check record alone does not establish a lock owner or prove
the absence of a transient wait.

No PDO results were mocked. The existing real PDO statement barrier is retained;
its parent now logs/rethrows failures and still delegates SQL execution to PDO.
The race, durable-state, exact call-count, no-replay and lock-release assertions
all remain intact.

## Measured environment constraint and controlled comparison

`recovery-resource-observation.log` measures Docker Desktop at **14 CPUs** and
**8,320,954,368 bytes** of VM memory. Before isolation:

- VM memory PSI `full avg10=19.57`, I/O PSI `full avg10=22.56`;
- this project's OpenSearch already had **13 restarts**;
- PostgreSQL had no cgroup CPU quota throttling (`nr_throttled=0`) or recorded
  cgroup OOM kills.

After pausing only our own unneeded services, memory and I/O PSI `full avg10`
both reached **0.00**. `/proc/meminfo` still showed only **248 kB free out of
1,048,572 kB swap**, making the shared environment's limited headroom explicit.
After restoration, memory PSI `full avg60=17.28` and I/O `full avg60=12.52`
returned, together with renewed statement-timeout failures and search restarts.
No production time/memory budgets were changed. No other project's container was
stopped, restarted, reconfigured or removed.

This establishes a resource-sensitive execution failure reproduced by immutable
baseline code, not a demonstrated regression in the main-owned read mappings or
credential work. It does **not** identify a particular foreign project's causal
share, establish why OpenSearch exits, or justify raising production budgets.

## Exact verification results

Every completed check below has an argv/timestamp/exit receipt from
`scripts/backend_check.py` in `evidence/recovery-<label>.json`, and raw output in
the adjacent `.log`.

| Evidence label | Result |
| --- | --- |
| `baseline-original` | Unmodified baseline test/image: **14 tests, 1 failure**, payment scheduler. Exit 1. |
| `current-original` | Unmodified current test/image: **14 tests, 2 failures**, payment scheduler and local maintenance timeout timing. Exit 1. |
| `scheduler-diagnostic-compare` | Same diagnostic test mounted over baseline/current: baseline **1/1 failed**, current **1/1 passed**. Combined exit 1. Child stderr preserved. |
| `baseline-observed` | Baseline with diagnostics and independent PG watcher: **14 tests, 1 failure**, local maintenance timeout timing. The failing statements include a product-row lock query and timeout-setting restoration, not a hidden success. Exit 1. |
| `isolated-compare` | Same diagnostic test, production images unchanged: baseline **14/14**, current **14/14**, combined exit 0. |
| `independent-suites` | Current native image: migrations **13/0**, payments **113/0**, payment HTTP **4/0**, UUID identity **16/0**, UUID recovery **5/0**, recovery **9/0**. **160 tests, 0 failures**, exit 0. |
| `restored-current-original` | Current immutable image, original unmounted test, own search/Keycloak/reconcile resumed: **14 tests, 2 failures**. Original line 101 again fails; local-expiry timing at line 240 also fails. Payment and maintenance schedulers and READY contention pass in this run. Exit 1. |
| `test-lint` | Diagnostic test passes native PHP 8.3.36 lint, exit 0. |

In `isolated-compare`, baseline/current long-apply durations are **5.030/5.031s**,
local maintenance timeout durations **5.011/5.014s**, payment scheduler completion
**8.811/8.820s**, and maintenance scheduler completion **0.505/0.504s**. The exact
original bounds and required durable state are checked by the suite itself.

`recovery-pg-errors-final.log` records renewed stock UPDATE statement cancellations
at **22:04:19.016** and **22:04:41.477** in the restored-environment run. The
isolated result must not be represented as fixing those remaining failures.

## Change made

Only `backend-yii2/tests/recovery-scheduling.php` was changed in source:

- redact configured database/provider secrets from diagnostic text;
- log native PDO SQLSTATE, driver code, primary error and SQL template on failure;
- print the observed old backend state after the bounded apply;
- expose scheduler state, stdout and stderr **before** assertions;
- print timeout exception/duration diagnostics before timing assertions.

There is no new application behavior, retry, timeout relaxation, skipped test,
changed expected result, or extra production budget. No application repair is
claimed; therefore there is no fabricated product-fix RED/GREEN claim. The
recorded failing and passing runs are environmental comparisons with immutable
production code.

## Lifecycle / restoration record

1. `fixture-start`: stop our `maintenance` / `payment-recovery`, and start only
   our `payment-provider` / `payment-http` through the verification overlay and
   verification profile, as `scripts/backend_fixes.py` does.
2. `own-isolation`: additionally stop our `reconcile`, `opensearch`, `keycloak`
   for the controlled measurement. No other services/projects are stopped.
3. `restore-search` and `restore-keycloak`: restart search and Keycloak and wait
   for their configured health checks. Both commands initially exit 0.
4. `restore-reconcile`: Compose `start --wait reconcile` unexpectedly traverses
   dependencies, reruns existing migration initialization (exit 0), and reruns
   existing reindex initialization, which fails against unavailable search.
   This exit 1 is retained. Subsequent restoration uses exact container names.
   The Keycloak `start` command's recorded environment contained a mistyped
   `SDUI_IP`; `start` did not recreate/reconfigure SDUI. The actual network IPs
   are independently recorded in the final inventory.
5. `fixture-stop` is an argparse failure before Docker (missing remainder
   separator for the profile flag), retained as exit 2. `fixture-cleanup` uses
   the original `compose.command(..., verify=True)` helper, stops both fixtures
   correctly and restarts the original maintenance/recovery containers.
6. `restore-reindex` and `restore-reindex-ready`: unsuccessful attempts are
   retained, not hidden. `restore-initialization` temporarily stops only our
   Keycloak again, waits for native search health, and starts the original
   reindex job. Its enclosing harness command times out at **240s**, so it has
   raw output but no completed backend_check JSON receipt. The native job
   continues. `initialization-progress` verifies **300 products / 298 active**,
   **259** indexed documents, no PG blockers, and the running native reindex.
   `restoration-final` then waits for the native reindex's actual **exit 0**,
   restarts reconcile and starts Keycloak. No timeout/budget is increased.

Final runtime health and initialization/fixture states are recorded separately
in `recovery-final-health` and `recovery-health-complete` evidence. **Healthy
restoration is not fully achieved**: all stopped runtime services are restarted,
both payment fixtures are stopped, and all three initialization jobs have their
actual exit 0 restored. At the last check (**22:19:43 UTC**), all runtime health
checks except reconcile are healthy. Reconcile is running but still in Docker's
`starting` grace state; its actual scheduler state is `waiting`, `lastResult`
**failed**, child exit **1**, with no last success. OpenSearch restarts from
counter **5 to 6** during that observation window. Its final health is healthy,
but stable search/reconcile recovery remains blocked in the restored shared
environment. Both health observations exit **1**; neither is represented as a
successful restoration. Further retries or changes to budgets/co-tenant stacks
are not justified by the recovery-scheduling task.

## Handoff / rebuild requirement

Modified paths owned by this task:

- `backend-yii2/tests/recovery-scheduling.php`
- `docs/backend-fixes/round-3/RECOVERY_DIAGNOSTICS.md`
- `docs/backend-fixes/round-3/evidence/recovery-*` diagnostic logs/receipts

No production PHP/Go/deployment source is changed, and no git staging, commit,
push, subagent, or image rebuild was performed. However, **the retained test-file
change is included in `backend_artifacts.py`'s source snapshot**. The old receipt
cannot be used unchanged for final scans: main must rebuild/retest/rebind through
the existing artifact workflow before claiming a source-bound final acceptance.
`recovery-receipt-impact.log` verifies this drift against `final-artifacts.json`
directly (receipt test SHA-256 `2e492e60...`, current `bb9bf491...`).
No new application binary fix itself requires a rebuild; the source-receipt gate
still requires its normal rebuild/retest. The original complete-live outcome and
the restored current scheduling run remain NOT PASS.
