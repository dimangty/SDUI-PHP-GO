# F1 — bounded PHP core readiness

**Follow-up status (2026-11-01):** the original 13-test GREEN below missed genuine
pending TCP connects and did **not** establish the claimed connect bound.
Independent verification found that defect and the incompatible recovery
fixture. Both are corrected and verified in the [follow-up record](#follow-up--pending-connect-and-recovery-corrections-2026-11-01)
below. Earlier measurements, image IDs and outstanding-integration notes are
historical and superseded by the [latest integrated results](TEST_RESULTS.md).
This final fix wave changes runtime admission again; fresh source-bound canonical
and exact-artifact security verification is required for that new source.

## Scope and execution record

Implements Task 2 / F1 of `AUDIT_PLAN.md` in the existing shared checkout, per
the coordinator's explicit file ownership. No staging, commits, subagents,
foreign-stack lifecycle changes or demo-data resets.

### RED — unchanged production implementation

```sh
docker exec shopkmp-round7-demo-php php tests/readiness.php
```

Exit **1** on 2026-11-01: `core probe seconds=2.017`; established optional-search
blackhole assertion `< .7` failed. The existing schema test passed (2 tests,
1 failure). This independently reproduces the coordinator's 2.016s result.
The established-blackhole regression remains in `tests/readiness.php` and must
also assert `SocketUpstream::finish() === 0` after the fix.

### Design / rulings

- Required SQL, schema, production runtime identity and configuration remain
  mandatory. Optional search has no transport in this path: configured means
  `unknown`, disabled means absent. Healthy results retain `catalog-cards-v1`.
- Use a dedicated nonpersistent native `ext-pgsql` async session, never a PDO
  connection, fork, cache or background task. PDO/libpq `connect_timeout` has a
  two-second floor and cannot enforce a sub-700ms connect/TLS deadline.
- `ReadinessBudget` names the 700ms PHP core / 2000ms total allocation, including
  queue, HTTP and serialization reserves. Required operations share one absolute
  monotonic deadline; cleanup has its own margin within the core allocation.
- Policy receives explicit probe, monotonic clock and cancellation callbacks.
  The native adapter applies server statement/lock limits and always closes the
  owned session, including cancellation. Native diagnostics become fixed finite
  messages; raw libpq warnings, connection URIs and SQL are never logged.
- Root runtime PHP requirement stays `>=8.3`. The extension uses the existing
  libpq build/runtime dependency and adds no PHP library or framework.
- Dedicated readiness database only for committed schema faults; transaction
  locks roll back. Existing `demo_test` data is not modified by this work.

## ADR: bounded native SQL instead of synchronous PDO

**Decision:** keep PDO for business traffic; use `ReadinessSql` only for the core
probe. It uses `PGSQL_CONNECT_ASYNC | PGSQL_CONNECT_FORCE_NEW`, connect polling,
bounded `stream_select`, asynchronous send/flush, and input/busy polling before
fetching each result. No persistent connection, retry, fork, worker, pool, cache,
search object, provider call or fan-out is involved.

The default named allocation is:

| Allocation | ms |
|---|---:|
| BFF total | 2000 |
| PHP core maximum | 700 |
| Queue reserve | 700 |
| HTTP reserve | 400 |
| Serialization reserve | 200 |
| Cleanup margin **within** PHP core | 100 |
| Active shared PHP deadline | 600 |
| Per-statement server limit | 250 |
| Server lock limit | 100 |

Invalid/overcommitted allocations throw. Every native stage shares the same
absolute monotonic deadline; no per-statement resetting of the client deadline.
The session starts read-only with server-side statement/lock limits in libpq
startup `options`. All schema probes use `LIMIT 0` (permissions and column
existence, without business-row scans), followed by the required legacy-import
marker and runtime-role check. Production still requires exact active/login
`shopkmp_runtime`, all five unsafe role flags false, payment configuration and a
valid nonempty webhook CIDR list.
The extracted probe checks its own four runtime settings, so F1 does not depend
on the concurrent F2 command-configuration helper.

### Why both native extensions are necessary

1. **`ext-pgsql`:** PDO exposes neither pollable connect/TLS nor asynchronous
   query consumption. `connect_timeout` cannot represent this core deadline.
2. **`ext-sockets`:** PHP's `pg_close()` first drains `PQgetResult()`, which can
   block on a pending query even though connect/query submission was async.
   On incomplete work, import `pg_socket()` and `socket_shutdown(..., 2)` before
   closing. This makes the drain terminate immediately. Do not call
   `pg_cancel_query()`/`PQcancel`: the separate cancel TCP exchange can blackhole.
   The `finally` path always closes the libpq session and restores the caller's
   diagnostic handler. Server statement/lock limits bound remaining work if the
   server cannot observe disconnect immediately.

`sockets` needs `linux-headers` only during Alpine compilation; these remain in
the removed virtual build-dependency group. There is no new runtime library or
PHP package. The existing libpq runtime dependency is retained as before.

An additional native API trap was verified against the pinned PHP source:
`pg_send_query()` contains an unbounded flush loop unless libpq is already in
nonblocking mode. The `pg_socket()` stream blocking option forwards its boolean
directly to `PQsetnonblocking`, so `stream_set_blocking($stream, true)` is
intentionally used to enable libpq nonblocking mode. PHP `pg_flush()` maps pending
output to integer **0**, successful flush to **true**, failure to **false**. The
8MiB write-backpressure regression exercises this boundary; production SQL is
the small fixed schema batch.

Reference inspected: [`php-8.5.12/ext/pgsql/pgsql.c`](https://github.com/php/php-src/blob/php-8.5.12/ext/pgsql/pgsql.c),
specifically `pgsql_link_free`, `pg_send_query`, `php_pgsql_fd_set_option`, and
`pg_flush`.

### DNS is part of the deadline

`PQconnectStart`/`PQconnectPoll` can still perform synchronous DNS unless
`hostaddr` is supplied. A real UDP DNS-blackhole test reproduced an over-budget
failure before this was addressed. For hostnames, use the **existing** c-ares
libcurl resolver in multi/connection-only mode against the configured DB
host/port, with proxies disabled and the same deadline/cancellation polling.
It sends **zero application bytes / zero HTTP requests**. Release that preflight
connection and pass its numeric IP as `hostaddr` to libpq; preserve the original
hostname for `verify-full` TLS. At most one connection is owned at a time; a
hostname probe makes two sequential connections (empty preflight, SQL session).
Numeric hosts or an explicitly configured numeric `hostaddr` need only the SQL
connection. This is required-DB transport, never optional-service I/O.

The pinned runtime reports `CURL_VERSION_ASYNCHDNS=true`, c-ares **1.34.9**.
A runtime lacking c-ares fails closed for hostname readiness instead of falling
back to blocking DNS; it can use an explicit numeric `hostaddr`. PHP's minimum
version remains unchanged. No resolver library is newly installed.

### Diagnostics and cancellation

Native warnings are promoted to fixed finite exceptions, not suppressed with
`@` and not logged verbatim. The kernel emits only
`Readiness dependency unavailable`; no URI, password, SQL or driver details.
The outer strict warning handler is restored and tested. Policy accepts explicit
probe/clock/cancel callbacks, rejects cancelled/overdue work, and emits only the
existing readiness DTO. FPM supplies `connection_aborted()` as the cancellation
signal; the hard deadline remains effective even when PHP has not yet observed
a client disconnect. No Go deadline increase is needed.

## Additional RED findings during implementation

All used the native command below, with the source read-only via
`--volumes-from shopkmp-round7-demo-php` during iteration:

- `php tests/readiness_policy.php`: exit **1**, four failures before extraction
  (`ReadinessPolicy` / `ReadinessBudget` absent); later exit **0**.
- Native cancellation test: exit **1**, cancellation exceeded the asserted
  200ms bound because `pg_close()` waited for server statement timeout. Socket
  shutdown before close changed that to a passing ~55–58ms cancellation.
- Native DNS-blackhole regression: exit **1**, `<700ms` assertion failed with
  asynchronous libpq but synchronous hostname resolution. After bounded
  c-ares preflight, it returns 503 at ~601ms.
- Native write-backpressure regression: the initial adapter blocked inside
  `pg_send_query`; the tool terminated the `docker run` command at **20s**
  (no native test exit was produced). The one owned leftover CLI container
  `hungry_zhukovsky` was explicitly removed; exit **0**. After enabling actual
  libpq nonblocking mode, the regression passes under 700ms. The final fixture
  has a parent-process watchdog so a native regression becomes a test failure
  rather than leaving an indefinitely running child.
- The first sockets build exited **1** (inner compiler exit **2**) because
  `linux/sock_diag.h` was absent. Adding build-only `linux-headers` fixed it.
  Initial stale-lock / existing test-class PSR-4 build diagnostics were read;
  the root lock was then properly regenerated, not hand-edited.

## Native GREEN commands / evidence (2026-11-01)

The coordinator's task-owned PostgreSQL container is
`shopkmp-round7-demo-postgres`; existing F3 `demo_test` data was not reset or
modified. A new database was created with:

```sh
docker exec shopkmp-round7-demo-postgres psql -U demo_test -d demo_test \
  -v ON_ERROR_STOP=1 -c 'CREATE DATABASE readiness_test OWNER demo_test'
```

Exit **0**. The guarded fixture applied the existing numbered `/migrations/*.up.sql`
to this initially empty database. It creates a test-only runtime role in this
owned PostgreSQL instance, grants SELECT in `readiness_test`, and restores all
committed column/metadata/role/grant faults in `finally`; table-lock faults roll
back. No volume or database was deleted.

```sh
docker run --rm --entrypoint composer \
  -v "$PWD/backend-yii2:/app" shopkmp-round7-readiness-php \
  update --lock --no-install --no-scripts --no-interaction
docker build --quiet -t shopkmp-round7-readiness-php backend-yii2
```

Both exit **0**. The regenerated `composer.lock` changes only the content hash
and `ext-pgsql` / `ext-sockets` platform entries; **no package version changes**.
Composer reported no security advisories. Final built image ID:
`sha256:56910874fecb16617b82006a247d540ee575579e9a101247d4b97a60196ba512`.

Final native run uses the actual rebuilt image, its real vendor and packaged
source (no application-source replacement/mocks):

```sh
docker run --rm --name shopkmp-round7-readiness-tests \
  --network shopkmp-round7-demo-e2e --entrypoint php \
  -v "$PWD/tools/ops/migrations:/migrations:ro" \
  -e APP_ENV=development -e READINESS_TEST_DATABASE=readiness_test \
  -e DATABASE_URL="$(docker exec shopkmp-round7-demo-php php -r \
    'print getenv("DATABASE_URL");')" \
  -e READINESS_DATABASE_URL="$(docker exec shopkmp-round7-demo-php php -r \
    'print preg_replace("~/demo_test(\\?|$)~", "/readiness_test$1", getenv("DATABASE_URL"));')" \
  shopkmp-round7-readiness-php tests/readiness.php
docker run --rm --entrypoint php shopkmp-round7-readiness-php tests/readiness_policy.php
```

Exits **0 / 0**: **13 native / 4 deterministic policy tests**, zero failures.

| Observation | Actual |
|---|---:|
| Preserved optional-search established-blackhole regression | 9ms; HTTP 200; **0 attempts** |
| Required startup blackhole | 601ms; HTTP 503 |
| Required TLS handshake blackhole | 602ms; HTTP 503 |
| Required established query/read blackhole | 601ms; HTTP 503 |
| Required DNS blackhole | 601ms; HTTP 503 |
| Required `pg_sleep(5)` statement timeout | 265ms |
| Cancellation while SQL is executing | 51ms |
| Healthy 20-sample p50 / p95 / maximum | 6.91 / 17.81 / 47.11ms |
| Concurrent healthy probes with optional blackhole configured | 8 workers × 5; all 200; **0 optional accepts/bytes** |
| Repeated concurrent real-SQL cancellations | 4 workers × 3; all <200ms |

Assertions also cover healthy/fast-failing/slow-header/established-read/TLS/raw
optional endpoints with **zero TCP accepts and zero HTTP requests**, configured
search `unknown`, disabled search absent, `catalog-cards-v1`, I/O-free liveness,
committed missing schema columns, required legacy import, wrong production
identity, unsafe CREATEDB runtime flag, missing SELECT grants, all four missing
production settings, malformed CIDR, refusal, invalid SQL URL/configuration,
statement/lock timeout, cancellation during connect, write backpressure, finite
native diagnostics, and restored strict handlers. `pg_stat_activity` confirms
zero retained readiness sessions after timeouts/cancellation/repetition.

Other actual checks:

```sh
docker run --rm --entrypoint php \
  -v "$PWD/backend-yii2/infrastructure:/app/infrastructure:ro" \
  shopkmp-round7-tools-task5 /app/dev-tools/vendor/bin/phpstan analyse \
  -c /app/dev-tools/phpstan.neon --no-progress \
  /app/infrastructure/ReadinessBudget.php /app/infrastructure/ReadinessPolicy.php \
  /app/infrastructure/CoreReadinessProbe.php /app/infrastructure/ReadinessSql.php
docker run --rm --entrypoint composer shopkmp-round7-readiness-php validate --strict
docker run --rm --entrypoint composer shopkmp-round7-readiness-php check-platform-reqs
docker run --rm --entrypoint composer shopkmp-round7-readiness-php lint
docker run --rm --volumes-from shopkmp-round7-demo-php \
  --entrypoint composer shopkmp-round7-readiness-php test
```

All exit **0**: PHPStan **max**, PHP target **80300**, all four readiness files,
zero errors or added suppressions; valid Composer manifest/lock; every platform
requirement satisfied; PHP syntax **142 files**; existing Composer native suite
**74 tests**, zero failures. With the same dedicated PostgreSQL URL/network,
`composer test:integration` exited **0**: **22 tests**, zero failures. The unit
and integration commands used the current source RO and real vendor from the
task-owned PHP container; readiness's final run above used packaged image source.
`git diff --check` on owned tracked changes exited **0**.

### Known integration failure outside ownership

Using the same dedicated PostgreSQL URL/network:

```sh
docker run --rm --volumes-from shopkmp-round7-demo-php \
  --network shopkmp-round7-demo-e2e --entrypoint php -e APP_ENV=development \
  -e DATABASE_URL="$(docker exec shopkmp-round7-demo-php php -r \
    'print preg_replace("~/demo_test(\\?|$)~", "/readiness_test$1", getenv("DATABASE_URL"));')" \
  shopkmp-round7-readiness-php tests/deadlines.php
```

Exit **1**, **4 tests / 1 failure**. The stale test
`500ms optional search deadline interrupts slow headers without blocking core readiness`
expects search `down` and one optional request (`tests/deadlines.php:28–29`).
The observed final assertion is `Expected 1, got 0`; its `finally` masks the
preceding obsolete component assertion. Coordinator must update it to `unknown`
and **0** requests. Other three deadline tests passed (1.003s header,
1.002s headers+body, 10.008s single-attempt payment). This file was not edited.

## Coordinator integration / remaining gates

1. Integrate `composer.lock` with the two native extension requirements and
   rebuild the final runtime/tools images; root PHP remains `>=8.3`. Both
   `pgsql` and `sockets` must be present at runtime.
2. Add `ReadinessSql.php` to the normal scoped PHPStan paths; the explicit
   four-file analysis above already passed at max. Add standalone
   `tests/readiness_policy.php` to the coordinator-owned test invocation.
3. Update the stale optional-search test in `tests/deadlines.php` as above.
4. `tests/readiness.php` is intentionally guarded: use a dedicated database
   named `readiness_test`, `READINESS_TEST_DATABASE=readiness_test`, and migrations
   mounted at `/migrations` for first initialization. `READINESS_DATABASE_URL`
   can select it only inside the readiness child process of `tests/all.php`,
   without changing the main suite's `DATABASE_URL`. The DNS fixture needs a
   disposable root CLI container (UDP loopback port 53 and restoration of that
   container's `/etc/resolv.conf`); it must not run inside the shared FPM container.
5. Coordinator owns the full Go → edge/Nginx → FPM → PHP → PostgreSQL chain,
   edge-startup dependency check, Go timeout config and end-to-end distributions.
   **NOT RUN here**; kernel/DB native evidence is not substituted for that gate.
   The broad `test:all` chain was not claimed green while the stale deadline test
   remains red. No Go, F2 or deployment files were edited by this task.

Final review: **self-review** against F1 constraints (no subagents, per request).
Deadline paths, optional zero-I/O, production role/config failure, diagnostics,
fixture isolation and resource cleanup were checked. Coordinator whole-diff
review and full-chain gates remain required; no merge/publication claim.

## Source binding (SHA-256)

Initial implementation binding (superseded for changed files by the follow-up).

```text
062b8f77d3c901d2e6b2daa2209028f76671c9fb10961bce0dc092aefdfd6454  infrastructure/ReadinessBudget.php
aea3ccb9c56a5b723301210c62f4d4e873a86c678b3c7d3a753a4b5ea5aa8c5e  infrastructure/ReadinessPolicy.php
a0061639bf94fc34247cd30a02bf177fe0adf620d6eed977bfc1ac97602faccf  infrastructure/CoreReadinessProbe.php
f884cd5f396573e5811ff6907b507d69584dbb002bb571a11541980af3dfb893  infrastructure/ReadinessSql.php
75e7bfb16e37cfe59c41290f6d93b1e7d204f131392bd625e33e43cad0ac0a93  infrastructure/ApiKernel.php
e4c1a67f42821660909f3eff14d11d2be2edcbe05f89a1ae26afd584b351c68c  tests/readiness.php
0346ac796fb10f57798791772b6918f326b97668d6c45f972e96ec7c86a7c851  tests/readiness_policy.php
8a6c3149981b1707af842e3a27bf30aee102d4b6f5f2f0ec81ece4a092d60cab  tests/ReadinessFixture.php
b7c4565b551f9c84f2b41d5e1d6f2e745d2e85a012d9a2fcf627dff5e4cc4bdd  Dockerfile
e306f37d1dda7adc8752511a3c8e0d1a293e012d544bbcc5bc8ba58f47038c39  composer.json
b37709145a61a8683f5712c4875972348ef58737e692d8594b81387c0afbc411  composer.lock
```

## Follow-up — pending-connect and recovery corrections (2026-11-01)

### Findings reproduced, and why the old tests missed them

Read `TEST_RESULTS.md` and the coordinator's private
`round7-verification/{connect-diagnostic-results,connect-trace-results}.json`.
Its six packaged probes took 3051–3101ms, including 3066ms inside the second
`pg_connect_poll()`. All original native blackholes had **accepted** TCP before
stalling startup, TLS or query data. Those 13 tests therefore never exercised a
socket still waiting for its TCP handshake to complete. Their GREEN was genuine
for those scenarios but insufficient evidence for F1's connect deadline.

New private evidence is retained at:

```sh
E=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round7-f1-followup
```

The old canonical image remains available, unchanged:
`shopkmp-round7-acceptance-e2e-yii2`, ID
`sha256:2bf9a23c225432d0e7ca13ed6157f682df0a2d2778d72130d4fcdbea7930c007`.

```sh
python3 "$E/connect-evidence.py" connect-red
python3 "$E/connect-evidence.py" connect-methods-red --trace
python3 "$E/run-recovery.py" readiness-pending-red \
  --suite tests/readiness.php --readiness --pending-tests
python3 "$E/run-recovery.py" recovery-red
```

All four commands exited **1**, as expected for RED:

- Six real packaged kernel probes to the exact numeric addresses
  `10.252.227.250:15432` and `10.252.227.10:15432` on the preserved owned
  application network took **3058.699–3101.970ms**, violating the unchanged
  **700ms** assertion. No source or response was replaced in these probes.
- Forwarding instrumentation called the **real native builtins**, recording
  return values as well as timings. The saved RED trace shows:
  `pg_connect` **0.100ms** → first `pg_connect_poll` **0.002ms**, result
  `PGSQL_POLLING_WRITING` → `stream_select` **10.465ms**, result **0** → second
  `pg_connect_poll` **3087.191ms**. `pg_socket` took **0.001ms**; `pg_close`
  **0.030ms**. Raw methods are in `connect-methods-red.json`; there is no mock
  PostgreSQL connection or substituted native return value.
- The new portable pending-connect fixture fills a real loopback listener's
  one-entry backlog, proves that two connections complete and the next socket
  remains non-writable, and never calls `accept()`. Both pending-deadline and
  pending-cancellation regressions failed with a 3s **test-only watchdog**.
  The application assertions remain `<700ms` and `<200ms`; the watchdog is
  not an application timeout. The other **13/13** tests still passed on the
  broken packaged implementation. This command mounts only the three new/current
  test files over the old image, leaving its production implementation intact.
- The unchanged packaged recovery suite reproduced **9 tests / 1 failure**:
  `tests/recovery.php:76`, expected 503, got 200. Its private PDO transaction and
  private schema were invisible to the independent native readiness session.

### Proven root cause and minimal production fix

`ReadinessSql::wait()` treated a 10ms `stream_select()` timeout as permission to
advance libpq. It also polled immediately after `PQconnectStart` without the
initial write-ready wait. This violates libpq's documented socket-state contract.

The exact packaged versions are PHP **8.5.12**, libpq **18.6** (`php --ri pgsql`).
Pinned sources inspected:

- [`php-8.5.12/ext/pgsql/pgsql.c`](https://github.com/php/php-src/blob/php-8.5.12/ext/pgsql/pgsql.c):
  `pg_connect(...ASYNC)` forwards to `PQconnectStart`; `pg_connect_poll` forwards
  to `PQconnectPoll`; `pg_socket` exposes the libpq descriptor through the stream
  cast. Its close operation does not close the connection or change readiness.
- [`REL_18_6/src/interfaces/libpq/fe-connect.c`](https://github.com/postgres/postgres/blob/REL_18_6/src/interfaces/libpq/fe-connect.c):
  `pqConnectDBStart` lines 2751–2759 leaves the caller waiting for write readiness;
  `CONNECTION_STARTED` lines 3521–3622 **assumes** write readiness and advances
  through `getsockopt(SO_ERROR)` to `CONNECTION_MADE`; lines 3673–3685 explicitly
  say the SSL-request send could block but should not because the caller has
  already observed write readiness. `pqPacketSend` lines 5397–5428 calls
  `pqFlush` and explicitly documents that it may block.
- [libpq 18 connection contract](https://www.postgresql.org/docs/18/libpq-connect.html#LIBPQ-PQCONNECTSTARTPARAMS):
  wait for the indicated socket event before **every** `PQconnectPoll`, including
  write readiness before the first poll. Async mode alone is not sufficient.

The production change is confined to `ReadinessSql.php`:

1. Wait for write readiness before the first native connect poll.
2. Keep waiting on select result **0**, rebuilding the mutated select arrays and
   checking the same deadline/cancel callback every iteration. Only result `>0`
   allows the native state machine to advance; failure still fails closed.

There is no guessed FD-flag workaround, TLS replacement, timeout increase,
statement-limit change, fork, background work or new dependency. Existing
socket-shutdown cleanup remains necessary and unchanged.

### Recovery assertion repair: real durable required-schema failures

`ReadinessFixture::inOwnedDatabase()` validates the existing uniquely named,
committed recovery fixture schema: its name must match the guarded fixture
pattern, it must be the real connection's current schema, and PostgreSQL must
confirm that the active user owns it. Production is rejected. It then derives
the separate `readiness_test` database, creates it only if absent, and runs the
guarded fixture with `PGOPTIONS` set to public. Original environment and the
recovery-private search path are restored afterward.

Invocation review caught an intermediate guard defect before handoff: canonical
`backend_fixes.py` does **not** export `ROUND5_SEARCH_DISPOSABLE` to recovery.
Removing the helper's extra test-runner marker reproduced **9 tests / 1 failure**
(`recovery-canonical-env-red.json`, exit **1**). The real owned-schema guard above
replaced that new invocation requirement. The final packaged recovery run uses
`--canonical-env` (no search-lane marker) and passes **9/9**. No orchestrator edit
or new environment variable is required.

The recovery test now commits each of its **same eight** required schema faults
in that dedicated database: five required tables and three required columns.
For each fault it asserts a real native **503**, restores the object in `finally`,
then asserts **200**. No transaction-private fault or mock-only policy result is
used. The suite's worker/payment/migration assertions remain active.
`recovery-final-green.log` records all eight `503 -> restored 200` transitions.
A final real SQL check found **0** hidden tables, **0** hidden columns and **0**
retained readiness sessions. Canonical/F3 application data was not reset.

### Rebuilt package and native GREEN

```sh
docker build --quiet -t shopkmp-round7-readiness-php backend-yii2
python3 "$E/connect-evidence.py" connect-final-unused-green --image shopkmp-round7-readiness-php
python3 "$E/connect-evidence.py" connect-methods-final-green \
  --image shopkmp-round7-readiness-php --trace
python3 "$E/run-recovery.py" readiness-final-green \
  --image shopkmp-round7-readiness-php --suite tests/readiness.php --readiness
python3 "$E/run-recovery.py" recovery-final-green \
  --image shopkmp-round7-readiness-php --canonical-env
python3 "$E/run-recovery.py" deadlines-final-green \
  --image shopkmp-round7-readiness-php --suite tests/deadlines.php --canonical-env
docker run --rm --entrypoint php shopkmp-round7-readiness-php tests/readiness_policy.php
```

Every command exited **0**. Final package ID:
`sha256:989e9b67107b5f66cd21958189de71cd6aae77da8947578d025892cd1906e02c`.
These GREEN runs use **packaged application source and real packaged vendor**;
there are no application-source mounts. The helper forwards the retained owned
container's environment privately, adds the disposable/provider settings, and
mounts the real migrations/scheduler files. Resolved credentials are not printed.

| Current packaged observation | Result |
|---|---|
| Exact six unused-IP kernel probes | All 503, **603.142–603.378ms**, each `<700ms` |
| Forwarded raw native trace | 503, **605.680ms**; select stays 0; **no premature `pg_connect_poll` call** |
| Trace cleanup | `socket_shutdown` true, **0.006ms**; `pg_close` true, **0.037ms** |
| Native readiness suite | **15/15**, including both new pending-connect regressions |
| Backlog-pending deadline / cancellation | **601ms / 54ms** |
| Preserved optional-search regression | **14ms**, zero attempts |
| Healthy 20-sample p50 / p95 / max | **7.79 / 10.49 / 10.74ms**; 8×5 healthy concurrency also passed |
| Required statement / executing-SQL cancellation | **256ms / 59ms** |
| Recovery suite | **9/9**, eight durable schema transitions verified |
| Existing deadline suite (coordinator-integrated assertions) | **4/4**, core readiness **8ms**, zero optional requests |
| Policy suite | **4/4** |

The definitive unused-IP run was performed after the live peer was stopped.
An earlier concurrent diagnostic (`connect-final-green.json`) overlapped the
live peer at `.10` and recorded three prompt authentication failures there;
those are not used as pending-connect evidence. Raw intermediate results are
retained rather than overwritten.

Also executed against the rebuilt package:

- `composer test`: exit **0**, **74/74**.
- `composer test:integration`: exit **0**, **22/22**, with the dedicated
  `readiness_test` URL on the task-owned demo PostgreSQL network.
- `composer lint`: exit **0**, **142 PHP files**.
- `composer validate --strict` and `composer check-platform-reqs`: both exit **0**.
- Existing `shopkmp-round7-tools-task5` PHPStan max / PHP target 80300, with the
  current infrastructure directory mounted read-only and all four readiness files
  explicitly selected: exit **0**, no errors or new suppressions.
- `git diff --check` for the follow-up scope: exit **0**; Git index is empty.

### Exact live transport retest

```sh
python3 "$E/live-connect.py" --label live-connect-final
```

Exit **0**; raw methods/HTTP bodies/timings in `live-connect-final-results.json`,
transport counters in `chain-live-connect-final/stats.json`, lifecycle output in
`live-connect-final-commands.log`. The helper resumes only the retained owned F1
Go → Nginx/FPM → packaged PHP → actual PostgreSQL fixture. It uses the original
FPM/Nginx configuration, original packaged BFF, unchanged **2s** BFF deadline,
retained `f1_chain_final` database and original real-PG forwarding fault peer.
Only the PHP service's package and the private counter directory are changed.
Historical coordinator evidence is not overwritten.

It starts edge through the real healthy dependencies with optional search
blackholed, then disconnects **only the owned fault peer's application interface**
to reproduce the exact pending numeric connect scenario from independent
verification. Strict assertions remain `<700ms` at direct PHP and `<2000ms` at BFF.

| Live observation | Samples | min–max ms |
|---|---:|---:|
| Healthy startup with optional search blackhole | 3 | 10.120–16.014 |
| Required accepted-startup blackhole, PHP+BFF | 6 | 604.068–606.876 |
| Required TLS blackhole, PHP+BFF | 6 | 604.238–607.432 |
| Required authenticated read blackhole, PHP+BFF | 6 | 605.334–609.854 |
| Required real-PG query blackhole, PHP+BFF | 6 | 605.144–607.813 |
| **Required genuinely pending TCP, BFF** | **5** | **604.138–613.267** |
| **Required genuinely pending TCP, direct PHP** | **5** | **604.221–606.877** |
| Go/PHP liveness during pending TCP | 2 | 2.007–4.087 |
| Pending TCP, 24 concurrent / eight callers | 24 | 1.710–609.736 |
| Required DB reconnected, all healthy 200 | 20 | 8.931–12.639 |

All required faults returned 503; liveness returned 200. Concurrent prompt
overload responses are the existing BFF admission policy. There were **zero
optional accepts, zero optional application bytes, zero retained SQL readiness
sessions**. The saved live report is `PASSED`; it does not promote or rewrite
the failed full canonical acceptance receipt.

### Retained work, source binding and handoff

Changed in this follow-up only: `infrastructure/ReadinessSql.php`,
`tests/{readiness.php,ReadinessFixture.php,ReadinessPendingConnect.php,recovery.php}`
and this report. The coordinator's integrated budget/configuration/native hooks,
payment/search/deployment changes were preserved. No new Composer/Dockerfile,
Go or deployment edit was made in this follow-up. No stage, commit, push or
child agent was used. Self-review checked the native polling contract and the
committed schema-fixture boundary.

The resumed F1 transport services, canonical test provider and canonical
PostgreSQL were stopped again; their containers, networks and volumes are
retained. PHP/BFF/Nginx/PostgreSQL exit codes were 0; the raw fault/provider CLI
fixtures required force-stop and retain exit 137, separately from application
shutdown. Existing demo containers were left running. The new packaged image and
private follow-up scripts/raw evidence remain available for the coordinator.
The whole canonical acceptance and exact-artifact security gates still require
the coordinator's fresh source-bound rerun; `TEST_RESULTS.md` was not edited.

SHA-256 values below were independently compared between checkout and the
packaged image (all equal):

```text
a58c0a8c498168cc3bd5075a85781f47870ad706873bc3f892b1ff87c9de836f  infrastructure/ReadinessSql.php
2595423cbc52750da8b6705a8c1282624784012e08b8a29f6c169a2e6d1b8988  tests/readiness.php
cddb497055ba1afeac540267fd44a2d609ebe2cea52c5f4708435b637b536929  tests/ReadinessFixture.php
2cc0d44f04bc746b79c0c45ff4f7eae88e746d927f1dd7ad3cd9121d64d2b84c  tests/ReadinessPendingConnect.php
a354f21f9c19c246b2939bfc0be3565bf0e7a86ab15c610714d6f330bc9270a1  tests/recovery.php
```

## Final review — actual-work admission (2026-11-01)

The previous cancellation fixture's 750ms gaps let every 600ms PHP probe end
before replacements arrived. It did not establish a downstream concurrency cap.
The new 80-request full-chain regression cancels at **50ms** and replaces
immediately, with **no drain gaps**. The old package failed: peak **13** native
connections and PHP liveness **2604ms**. The final canonical package passes:
peak native connections **2**, peak observed PostgreSQL readiness sessions **2**,
PHP/Go liveness maxima **2.755/2.555ms**, zero optional traffic, zero retained
sessions, and 20/20 recovered ready responses 200.

`ReadinessAdmission` acquires one of two precreated, read-only application lock
files with nonblocking local Linux `flock` **before** the required probe runs.
It is shared across FPM workers; policy closes the lease in `finally` only after
native cleanup. It neither trusts disconnect visibility nor creates lock files
as root/CLI. Saturation is 503 with zero required/optional transport; liveness
bypasses admission. The native healthy concurrency case is now two admitted
callers × five requests; arbitrary eight-caller fan-out is deliberately overload.
No deadline, FPM pool or native pending-connect polling change was made.

Both findings, exact commands, RED/GREEN and fresh source-bound canonical receipt
are recorded in [FINAL_FIXES.md](FINAL_FIXES.md). Earlier evidence above remains
historical. Exact-artifact security and independent platform release blockers
remain separate from this backend functional result.
