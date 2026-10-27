# RR1 — bounded, fair combined recovery

Date: 2026-10-26. Scope: RR1 in [SESSION_TRUST_REREVIEW.md](SESSION_TRUST_REREVIEW.md).

**Final production source is ready for rebuilt live verification.** Fresh final
behavioral results: **139 cases passed** (6 scheduling, 9 existing recovery,
107 payment lifecycle, 13 payment migration, 4 payment HTTP), all suite exits 0.
PHP syntax: 79 files passed, exit 0. This is source-mounted standalone evidence;
the coordinator owns rebuilt live-stack verification.

## Changes

Only these files were written by this task:

- `backend-yii2/application/RecoveryService.php`
- `backend-yii2/application/PaymentOperations.php`
- `backend-yii2/tests/recovery-scheduling.php`
- `backend-yii2/tests/recovery-scheduling-provider.php`
- `docs/backend-fixes/RR1_FIX_REPORT.md`

The combined scan now admits **one session candidate first**, followed by **one
payment candidate**, with monotonic admission deadlines. This bounds both sides;
moving the old 25-job session loop before payments would not have been sufficient.
An unclaimed candidate remains durably eligible. The existing session advisory
lock, autocommit reservation, five attempts, backoff, crash lease, manual
confirmation, and completion/fence transaction are retained.

Payment recovery has backward-compatible optional arguments:

```php
$operations->recover(); // existing 1000 candidates, chained dispatch, full review
$operations->recover(1, $monotonicDeadline, false); // combined-worker bounded mode
```

The deadline uses the `hrtime(true) / 1e9` clock, not Unix time. Bounds are checked
before selection and before each candidate. Bounded mode sends **at most one
effect for that candidate**, deferring any newly queued `READY` compensation to
a later scan. `dispatch($id)` keeps its existing chained default; its optional
`$followUp=false` is used only by bounded recovery. Claim revalidation, refund
intent, late-result observations, reconciliation, financial application, stock
policy, and the `SENT`/`UNKNOWN` no-replay policy retain their existing logic.

The combined worker suppresses the full manual-review array. Its existing JSON
keys now obtain exact counts in one SQL statement. Candidate queries and counts
can still inspect a large database, but have a finite statement deadline rather
than materializing an unbounded result list in PHP.

No migration, Auth/Session/Credential/PasswordSessionRecovery/Keycloak,
PaymentGateway, Commerce, controller, Compose, scheduler, or shared-runner source
was changed. No subagents, commits, pushes, or production test hooks were used.

## Runtime envelope and latency

The existing payment HTTP deadline remains **10 seconds**. The ordinary
service-account Keycloak path needs **four HTTP calls**, up to **40 seconds**:
grant + logout, grant + consent removal. The tests use that real adapter path,
without an admin-token override.

`RecoveryService::run()` temporarily scopes its own PostgreSQL connection to:

| Limit | Automatic combined scan | Existing connection default, restored afterward |
| --- | ---: | ---: |
| Statement | 1 second | 10 seconds |
| Lock | 800 milliseconds | 8 seconds |
| Whole transaction | 5 seconds | 0 (PostgreSQL default) |

The transaction-wide limit is PostgreSQL **17** `transaction_timeout`, matching
the deployed database major version. It bounds existing multi-statement stock
and callback-inbox loops; per-statement limits alone would not bound those loops.
No transaction crosses HTTP. Exceeding this limit terminates the worker's SQL
connection and fails the scan. The existing committed send/claim fences survive;
a fresh worker handles the expired lease without resending an uncertain payment.
Normal completion and ordinary errors restore the previous connection settings;
if SQL has disconnected, restoration does not replace the original scan failure.

The admission envelope is:

| Work | Reserved bound |
| --- | ---: |
| Session selection, one reservation, four HTTP calls, local completion/failure and unlocks | 55 seconds |
| Payment selection, one candidate, at most one HTTP call, up to three local transactions and observation | 30 seconds |
| Final counts and SQL-setting restoration | 3 seconds |
| CLI startup/exit and parent-polling headroom | 5 seconds |

Session local work has at most nine one-second autocommit statements plus one
five-second transaction, including the failure-recording path: **40 + 9 + 5 =
54 seconds**, inside its 55-second allowance. The payment path has at most four
one-second autocommit statements, three five-second transactions, and one HTTP
call: **4 + 15 + 10 = 29 seconds**, inside its 30-second allowance. Lock waits
are included in SQL time, not added again.

Before scoped limits are installed, allow 3 seconds for connection establishment
and up to 10 seconds each for saving/installing SQL settings: **23 seconds**.
Thus the default 120-second job can accommodate **23 + 55 + 30 + 3 = 111 seconds**
of application work, plus five seconds of headroom (**116 seconds**). The
monotonic clock starts before connection setup. These are operational bounds
under the configured HTTP/PostgreSQL timers, not a promise against arbitrary OS
process suspension; the unchanged parent remains the outer hard deadline.

The combined command accepts the existing `SCHEDULER_JOB_TIMEOUT_SECONDS` in
**90..86400 seconds**, with absent/empty values defaulting to **120**. Smaller
values fail explicitly before claiming work. At the reduced 90-second setting,
session progress is reserved, but a slow session/SQL phase can leave insufficient
room for a payment's full 30-second allowance; that payment is deferred. The
default 120-second budget fits both worst-case slices. Increasing the parent
budget does not increase either candidate cap.

At the normal **30-second scheduler cadence**, a light scan attempts one session
and one eligible payment each tick. A session at rank `q` in the due, unlocked
queue is considered within `q+1` successful scans; there is deliberately no
25-session burst. When scans exceed the cadence, the scheduler starts the next
child after the current child finishes, rather than overlapping it. A payment's
chained compensation can require another scan (normally another 30-second tick).
The SQL-driven ordering/backoff and busy advisory locks still govern eligibility;
provider/SQL failure is not reported as successful cleanup. A session failure
keeps the user blocked; crash reservations still expire after 120 seconds.

## Real RED and final GREEN

The new scheduler test starts the **actual** `/opt/scheduler/scheduler.php`, which
launches the **actual** `/app/yii maintenance/recover`. It seeds fifty `READY`
payments with two-second responses (**100 seconds** of payment backlog), a failed
due session followed by a healthy due session, and previously sent/unknown
payments with real provider journal entries. The parent budget is **90 seconds**.

**Before production edits:** the parent timed out at **90.702 seconds**. The
healthy session remained `REVOKE_PENDING`, **recovery_attempts=0**. The test failed
`Expected 'success', got 'timeout'`, exit **1**. This is executed scheduler/SQL/HTTP
evidence, not a source-only calculation or replacement sleeping child.

**Final source:** the same test observed `lastResult=success`, `lastExitCode=0`,
and healthy session `COMPLETE`, **recovery_attempts=1**, after **10.002 seconds**.
It checks several successful scan reports, each with at most one session attempt
and one payment dispatch, at least 45 payments still `READY`, the failed user's
fence and issuance rejection, and exactly one original provider attempt for each
`SENT`/`UNKNOWN` sentinel. Test-owned schemas keep assertions independent of other
payment/session fixtures and global manual counters.

Other new cases check symmetric session-backlog fairness; one-effect payment
dispatch/deferred compensation; expired admission deadlines; legacy full-review
API compatibility; SQL row-lock failure/fence retention and setting restoration;
and empty/undersized scheduler budgets.

The whole-transaction test uses ten real stock updates, each delayed 600ms in a
test-schema-only trigger. Each fits the statement deadline, but together they
exceed the transaction deadline. Final observed termination: **5.681 seconds**.
It checks that the old SQL backend is gone, the session already completed, local
payment/stock apply rolled back, the refund response observation survived, and
fresh-worker lease expiry produces `UNKNOWN` with **one refund POST attempt**.

## Exact reproducible command argument sets

All commands below use the already existing PHP **8.3.36** image and the supplied
disposable **`payment_fixes`** database only. No other project's services were
stopped/restarted. The standalone DB's history was confirmed as **001–014** before
and after testing. New scheduling schemas were dropped; final namespace query
returned zero `payment_legacy_%` schemas.

```sh
ROOT=/Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO
NET=shopkmp-backend-fixes-20261026
DB_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/payment_fixes?sslmode=disable'

rr1php() {
  docker run --rm --network "$NET" \
    -e DATABASE_URL="$DB_URL" \
    -e PAYMENT_GATEWAY_URL=http://shopkmp-final-provider:8080 \
    -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
    -v "$ROOT/backend-yii2:/app:ro" \
    -v shopkmp-payment-vendor-20261026:/app/vendor \
    -v "$ROOT/tools/ops/migrations:/migrations:ro" -w /app "$@"
}

# Real RED, before production changes: exit 1, scheduler timeout at 90.702s.
rr1php -e 'RECOVERY_TEST_FILTER=real scheduler' \
  -v "$ROOT/deploy/php:/opt/scheduler:ro" --tmpfs /app/runtime \
  shopkmp-php-tests php tests/recovery-scheduling.php

# Other pre-fix REDs, each exit 1.
rr1php -e 'RECOVERY_TEST_FILTER=session backlog' shopkmp-php-tests php tests/recovery-scheduling.php
rr1php -e 'RECOVERY_TEST_FILTER=bounded payment scan' shopkmp-php-tests php tests/recovery-scheduling.php
rr1php -e 'RECOVERY_TEST_FILTER=SQL contention' shopkmp-php-tests php tests/recovery-scheduling.php

# Final sequential source-mounted checks (each exit 0).
for suite in recovery-scheduling.php payment-migration.php recovery.php; do
  rr1php -v "$ROOT/deploy/php:/opt/scheduler:ro" --tmpfs /app/runtime \
    shopkmp-php-tests php "tests/$suite" || exit "$?"
done
```

The last loop is the equivalent command argument set of the executed Python
subprocess loop (which also initially ran `payments.php`; see setup failure
below). Results: **6/0**, **13/0**, **9/0**, each subprocess exit **0**.

The supplied `shopkmp-final-provider` has no callback HTTP origin/server. For the
complete existing payment lifecycle/HTTP regressions, the following task-owned
fixtures supplied that missing dependency. Network inspection first confirmed
the three exact addresses were free. No host ports were published.

```sh
docker run -d --rm --name shopkmp-rr1-payment-provider \
  --network "$NET" --ip 172.29.0.232 \
  -e DATABASE_URL="$DB_URL" \
  -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-rr1-payment-http:8080 \
  -v "$ROOT/backend-yii2:/app:ro" \
  -v shopkmp-payment-vendor-20261026:/app/vendor -w /app \
  shopkmp-php-tests php -S 0.0.0.0:8080 tests/payment-provider.php

docker run -d --rm --name shopkmp-rr1-payment-http \
  --network "$NET" --ip 172.29.0.231 \
  -e DATABASE_URL="$DB_URL" \
  -e PAYMENT_GATEWAY_URL=http://shopkmp-rr1-payment-provider:8080 \
  -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
  -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
  -e PAYMENT_WEBHOOK_ALLOWED_CIDRS=172.29.0.232/32,172.29.0.230/32 \
  -e PHP_CLI_SERVER_WORKERS=4 \
  -v "$ROOT/backend-yii2:/app:ro" \
  -v shopkmp-payment-vendor-20261026:/app/vendor --tmpfs /app/runtime -w /app \
  shopkmp-php-tests php -S 0.0.0.0:8080 -t web web/router.php

for suite in payments.php payment-http.php; do
  docker run --rm --network "$NET" --ip 172.29.0.230 \
    -e DATABASE_URL="$DB_URL" \
    -e PAYMENT_GATEWAY_URL=http://shopkmp-rr1-payment-provider:8080 \
    -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
    -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
    -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-rr1-payment-http:8080 \
    -v "$ROOT/backend-yii2:/app:ro" \
    -v shopkmp-payment-vendor-20261026:/app/vendor \
    -v "$ROOT/tools/ops/migrations:/migrations:ro" --tmpfs /app/runtime -w /app \
    shopkmp-php-tests php "tests/$suite" || exit "$?"
done

docker rm -f shopkmp-rr1-payment-http shopkmp-rr1-payment-provider

docker run --rm --network none \
  -v "$ROOT/backend-yii2:/app:ro" \
  -v shopkmp-payment-vendor-20261026:/app/vendor -w /app \
  shopkmp-php-tests php tests/lint.php

git diff --check
docker exec shopkmp-backend-fixes-pg psql -U backend_fixes -d payment_fixes \
  -c 'SELECT version FROM schema_migrations ORDER BY version;' \
  -c "SELECT nspname FROM pg_namespace WHERE nspname LIKE 'payment_legacy_%';"
```

Both fixture starts, both full suites (**107/0**, **4/0**), fixture cleanup,
syntax (**79 files**), whitespace and final DB inspection exited **0**.
The original supplied provider remains running and unmodified.

### Observed setup/test-development failures

- Initial bounded-payment test lacked its `PaymentOperations` import: exit 1,
  not a production RED. After fixing the import, the actual RED was two POSTs
  (`authorize`, `void`) where one was required; exit 1.
- Initial session-backlog RED completed 6 jobs where 1 was required; exit 1.
  Initial lock-contention RED exceeded the three-second test bound with the
  ordinary eight-second lock timeout; exit 1.
- Empty-budget compatibility case initially failed with `InvalidArgumentException`,
  exit 1. Empty values now follow the scheduler's default behavior.
- The long-transaction test initially asserted one PDO message spelling. Backend
  termination can expose the subsequent rollback's `no connection` error instead.
  Those test runs exited 1. The final test verifies elapsed time, backend death,
  durable observations and financial state, without depending on error wording.
- An intermediate all-scheduling run exceeded the terminal tool's **40,000ms**
  harness timeout after its first four passes; no container exit was captured.
  A subsequent complete run exposed the PDO wording assertion above. The final
  complete run used a 240,000ms outer tool limit and passed all six cases.
- Initial `payments.php` against the supplied provider only: **107 cases / 5
  failures**, exit 1. Four failures traced to missing callback/HTTP fixture
  configuration; one existing 10.8-second wall-time assertion also exceeded its
  margin. The enclosing four-suite subprocess loop exited 1. With the complete
  isolated fixtures above, the unchanged suite passed **107/0**, exit 0.
- The first local HTTP warm-up probe used `new HttpClient(2)` and exited 255 on
  timeout; the same probe with `new HttpClient(10)` returned HTTP 200, exit 0.
  No adapter/test deadline was loosened in source.

Dedicated read/glob tools returned `Bun is not defined`; shell/Python reads were
used as fallback. Every workspace edit used `apply_patch`.

## Final source snapshot

```text
6a06fb880af738907bfc8fd93a6505ffc5f2ed0e33795fd409cafad49833dc2b  backend-yii2/application/RecoveryService.php
03a402f44568cc2b2d58f31d360dc6a36766f853ca54b8d56f4ec4b29df64093  backend-yii2/application/PaymentOperations.php
daf7f837c4c392e0280279bf081d256a35223e69b076d7580223b288692d1daa  backend-yii2/tests/recovery-scheduling.php
75cd2fe229a11d1448d30c40dccf424bb66a6f160adace40a4ddde6f0ab877d1  backend-yii2/tests/recovery-scheduling-provider.php
```

The new scheduling suite needs the read-only `/opt/scheduler` mount and a writable
temporary `/app/runtime` shown above. Its explicit command is provided because
this narrow task did not change the shared runner or Compose mounts. Rebuild the
live worker from these production bytes, then run the coordinator's live recovery
verification. The main project and security/TLS agent's stacks were not operated
by this task.
