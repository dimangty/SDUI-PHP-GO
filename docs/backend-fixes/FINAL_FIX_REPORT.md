# Final coordinated corrections F1–F3 — 2026-10-27

**Final functional status: PASS.** The rebuilt affected suites passed and the
actual full root command on the final coordinated source exited **0** in
**1,867.87 seconds**. The earlier 1,308.67-second pass predates this wave and is
not used as its proof. **Security finding A1 remains open**; this report does
not authorize a commit, push, merge or production release.

The coordinator completed the production corrections before this verification.
This verification read/froze those bytes, rebuilt only the owned root PHP image,
restarted the affected root services without dependency/Keycloak churn, and ran
the affected suites. It did not edit core code, migrations or test expectations.
No subagents, commits or pushes were used.

## Corrections and regression coverage

| Finding | Final behavior | Executed affected coverage |
| --- | --- | --- |
| **F1: final no-effect reversal could not be truthfully reconciled** | Console-only `NO_EFFECT` accepts a sent `UNKNOWN` void/refund with original transaction ownership and independent evidence. It records audited `ABORTED`/`result.outcome=NO_EFFECT`, retaining send/token/lease/observations. A failed void can atomically release one owed refund; a failed refund remains `CAPTURED`/manual with its unique key retained and no automatic replay. | CARD/SBP void/capture/disposition/refund, commit-failure atomicity, actual console, wrong/missing evidence/transaction, repeated disposition, paid/completed refund stock policy, authorization rejection and combined-worker manual count. |
| **F2: advisory-busy head starved later due sessions** | A materialized, ordered window contains at most 25 due rows. One bounded SQL statement tries their session locks until the first available one; only one actual attempt is reserved. Busy rows consume no attempt/lease/fence change. A full 25-busy window defers later rows; it is not an unrestricted fairness guarantee. | Two real worker replicas across three scans with held head; later healthy job progress, held-row preservation, bounded window and absence of leaked advisory locks on a reused connection. |
| **F3: maintenance could starve retention behind payment HTTP** | Retention commits first, up to 1,000 eligible rows per each of seven tables. At most 100 expiry candidates are admitted under a monotonic budget; expiry commits local cancellation/stock/intent only. No HTTP or trailing recovery scan occurs in scheduled maintenance. The dedicated recovery worker dispatches intents. | Actual maintenance scheduler with a delayed-payment backlog; bounded cleanup progress/remaining intents/no provider calls; SQL transaction timeout preserving committed retention and rolling back local expiry; synchronous direct-application expiry compatibility. |

F1 reuses existing `ABORTED` and JSON audit fields; F2 uses existing scheduling
columns; F3 uses existing tables/intent logic. **No schema, migration, manifest,
public status enum or HTTP/SDUI contract change is part of this wave.**

`NO_EFFECT` is an operator attestation, never an inference from 4xx, timeout,
missing observation or lease expiry. Quiesce every original sender/request and
independently establish final absence of the original effect before using it.
No-effect refunds require an exclusive manual provider/accounting settlement
case; this application cannot relabel the original failed refund as that distinct
manual effect. See [PAYMENTS.md](PAYMENTS.md) for the complete audit policy.

## RED provenance — attributed, not reconstructed

The available pre-fix evidence is:

- **F1:** FINAL_CODE_REVIEW.md's independent real-PostgreSQL/application probe,
  with only the gateway boundary doubled, reported `INVALID_TRANSACTION_ID`,
  payment `CAPTURED`, void `UNKNOWN`, no refund intent and attempts
  `[authorize, void]` after attempted verified-failed-void reconciliation. Its
  process **exit 0 means the defect probe completed**, not that the behavior was
  correct or a payment suite passed.
- **F2:** that review's real-PG/live-loopback-provider probe held only the older
  job's advisory lock. Three actual scans reported no progress, attempts `[0,0]`;
  excluding that head via a real reservation let the second job complete with
  attempt 1. Probe exit 0 confirmed the starvation and positive control.
- **Original RR1 backlog:** RR1_FIX_REPORT.md records the actual pre-fix scheduler
  timeout after **90.702s**, healthy job still `REVOKE_PENDING`, attempts 0,
  assertion `Expected success, got timeout`, exit **1**. This is a combined-worker
  reproduction, not a newly executed pre-F3 maintenance test.
- **F3 review:** the review established the old expiry/recovery-before-retention
  ordering and runtime-bound failure path. It explicitly did **not** execute a
  new 120-second maintenance outage probe. No separate fix-agent RED transcript
  is available here, so no such execution/exit is invented.

These attributions retain their original scope. This verifier did not run the
old implementation or claim authorship of an undocumented RED→GREEN cycle.

## Coordinator current-source evidence and setup classification

The coordinator supplied these fresh current-source results: scheduling **11/0**,
payments **113/0**, existing recovery **9/0**, migration **13/0**, real payment
HTTP **4/0**. It also reported an integration invocation with fixture gateway
settings inherited accidentally: **11 cases / 1 failure**. The coordinator's
separate correction reran integration without a gateway URL. That was a setup
error, not an application RED or permission to change the assertions.

The coordinator-owned `shopkmp-f123-provider` and `shopkmp-f123-http` were only
inspected, never stopped/recreated. Their image, commands, readonly mounts,
network, DB name and origins are recorded without credentials in
`backend-live-final-wave-coordinator-fixture-context.log`. Raw ephemeral
coordinator test-client argv are not persisted in the workspace; this report
does not synthesize them. The independently executed rebuilt invocations below
provide exact, retained argv and matching final-source results.

## Fresh rebuilt six-suite verification

Image build:

```sh
docker build --pull=false -t shopkmp-backend-fixes-e2e-yii2 backend-yii2
```

Exit **0**, 4.51s. Only this root project's PHP image was rebuilt. The same
environment as LIVE_TEST_RESULTS.md was supplied, including ingress
`172.31.220.0/24`, FPM `172.31.219.0/24` (dynamic upper /25), exact peers and port
58196. Existing normal 001–014 data/history were retained. The own app/proxy/SQL
workers were paused/restarted with `--no-deps`; Keycloak, migrate and other
projects were not restarted or rebuilt.

The exact arrays are printed as `EXACT FINAL SUITE ARGV` in
`deploy/evidence/backend-live-final-wave-affected.log`. Equivalent shell rendering
of those recorded arguments, from the repository root:

```sh
ROOT=/Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO
verify() {
  docker compose --env-file .env.example -p shopkmp-backend-fixes-e2e \
    -f "$ROOT/docker-compose.yml" -f "$ROOT/deploy/compose.verify.yml" "$@"
}
for suite in payment-migration.php payments.php payment-http.php recovery.php recovery-scheduling.php; do
  verify run --rm --no-deps \
    -e PAYMENT_GATEWAY_URL=http://payment-fixture-gateway:8080 \
    -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
    -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
    -e PAYMENT_TEST_HTTP_ORIGIN=http://payment-fixture-http:8080 \
    yii2 php "tests/$suite" || exit "$?"
done
# Ordinary integration must retain its nonproduction local/demo contract.
verify run --rm --no-deps -e PAYMENT_GATEWAY_URL= -e PAYMENT_GATEWAY_API_KEY= \
  yii2 php tests/integration.php
```

Fixture services were started with verification profile, `up -d --no-deps --wait
--wait-timeout 30 payment-provider payment-http`, and stopped in `finally`.
The task-owned workers were then restored with `up -d --no-deps`. No coordinator
standalone fixture or unrelated service was touched.

| Suite | Cases / failures | Exit | Actual duration |
| --- | --- | ---: | ---: |
| payment-migration.php | 13 / 0 | 0 | 2.931s |
| payments.php | 113 / 0 | 0 | 50.020s |
| payment-http.php | 4 / 0 | 0 | 2.325s |
| recovery.php | 9 / 0 | 0 | 4.275s |
| recovery-scheduling.php | 11 / 0 | 0 | 33.415s |
| integration.php, gateway URL/key explicitly empty | 11 / 0 | 0 | 4.835s |

**161 passing cases**, combined wrapper exit **0**, 135.30s including own
restart/fixtures. Existing strict deadlines and assertions were retained. The
transaction test observed termination at **5.053s**; the mixed-backlog scheduler
reported success/healthy-session COMPLETE, attempts 1, after **8.776s**.

## Frozen final source identity

```text
a28fc2baab6516a564462144c77d363264256e8717101211f5217a920fc1e426  application/PaymentOperations.php
3d18e0bc02859bd6220f61f8d2b1562b3c1aec716ddcb5cebbbf5b4a61fda337  application/RecoveryService.php
e744ffa7f74a8d2da35f1aa41fc3ece3135beb6520ea034a1b1e4154543aa240  application/MaintenanceService.php
c157bbc98541003cca391e17fed030a79eda4089474688cf4614769468947325  tests/payments.php
2340a82bd13c4c6a3b395d48b610a0be72a0a4e4a7aea0337eae9378577e49f2  tests/recovery-scheduling.php
```

All paths above are under `backend-yii2/`. The final running-image comparison,
normal checksum-history check and full root result are appended when finished.

## Final-source full root gate

Started exactly once under the detached bounded supervisor:

```sh
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
```

Actual command/status: `backend-live-final-wave-root.log` and
`backend-live-final-wave-root.status.log`, ignored under `deploy/evidence/`.
Supervisor bound **5,400s** accommodates sequential catalog reindex and the full
unchanged suites without confusing a terminal tool timeout with command exit.
The documented scheduler fixture scale remains **3**. No business deadline or
assertion was relaxed. At report creation the root result is **pending**; earlier
headroom-pass phase logs were archived as `backend-live-pre-final-wave-*.log`.

Progress checkpoint: the final-source root has passed route/schema checks, all
**18** scheduler cases (244.387s), seed preservation/reindex, signed CARD flow,
full Go race/live checks, BFF vet, ops tests/vet, shared PHP **154/0** plus **79**
syntax files, and the complete focused phase **150/0** (13+113+4+9+11). The root
is running its last automatic runtime/shutdown/restart phase; its final exit is
not yet claimed. The new root scheduling observations were 5.038s transaction
termination and 8.826s successful mixed-backlog session completion.

## Final rebuilt root result and provenance

```text
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
actual command exit: 0
actual duration: 1867.87 seconds
supervisor state: finished; exit: 0; elapsed: 1867.946 seconds
```

The detached supervisor captured an actual normal exit; no terminal timeout or
earlier-source pass is substituted. Final evidence under ignored
`deploy/evidence/`:

- `backend-live-final-wave-root.log` / `.status.log`: complete root command and exit.
- `php-all.log`: **154/0**, 79-file PHP syntax.
- `backend-fixes.log`: **150/0** (13 migration + 113 lifecycle + 4 HTTP + 9 recovery
  + 11 scheduling).
- `scheduler-regression.log`: **18/0**, 244.387s, documented fixture scale 3.
- Root route/schema, seed preservation, reindex, signed CARD journey, full Go
  race/live contracts, BFF vet, ops tests/vet and runtime phases all exit **0**.
- `runtime.json` / `runtime.log`: all seven stopped app/worker services exit 0;
  maintenance, search reconciliation and combined recovery are live/ready with
  last child exit 0 both before and after restart; schema history count 14.
- `backend-live-final-wave-proof.log`: source/image equality, normal checksum
  history, final container inventory and unstaged generated-artifact inventory.

This is **304 passing PHP cases** within the coherent root run. The separate
161-case affected preflight is not added again to inflate that total.

The running root PHP image is
`sha256:3de5a2092cfa0a3ebc7a6c6bd09a2bdee6fd4899ea1eab801f0e463d1c06d277`.
Hashes of all three production files and both affected suites were read inside
the running container and exactly matched the frozen worktree identities above.
Root `shopkmp` and external `trust_final` both retain normal source-matching
001–014 histories; 22 original SQL files remain byte-identical to HEAD.

After the root run, both real recovery replicas were made ready, replica 1 was
restarted, and both again reported healthy successful children: exit **0**,
12.34s (`backend-live-final-wave-replicas.log`). All owned running app services
and workers are healthy. Own test fixture services are stopped. The coordinator's
two standalone f123 services were independently confirmed still running; the TLS
project remained stopped and unrelated stacks were untouched.

The final inventory contains **36 tracked generated evidence diffs** and **eight
untracked category-continuation JSON captures**. These are test output, not source;
they remain unstaged for coordinator restoration review. The index is empty,
and `git diff --check` passes. Prior ignored logs remain archived rather than
being rewritten as green.

No further core fix or test/deadline weakening was needed by this verification.
Functional rebuilt verification is complete; **A1 and the coordinator's final
aggregate/separate-review/release decisions remain outstanding**. No subagents,
commits or pushes were used.

Security A1, final separate review, remote CI and any production release decision
remain coordinator-owned. Generated evidence JSON is not source and is retained
unstaged for the coordinator's restoration review.
