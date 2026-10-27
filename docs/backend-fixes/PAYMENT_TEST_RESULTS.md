# Payment verification — 2026-10-26

## Current revision: independent review R1–R5

Read `PAYMENT_REVIEW.md` in full and addressed **all 3 High + 2 Medium findings**. Detailed fix analysis, exact old/new schema hashes and coordinator handoff are in [PAYMENT_FIX_REPORT.md](PAYMENT_FIX_REPORT.md). No shared integration source, migration 013/014, old migration 001–011, runner, Compose or checksum manifest was edited in this revision.

### RED then GREEN commands and exits

Each new regression was executed against the unfixed behavior before the corresponding production fix. Commands used the Docker argument set below, adding `-e PAYMENT_TEST_FILTER=R1|R2|R3|R4|R5` as appropriate.

| Filter / suite | Observed RED | Observed GREEN | Defect demonstrated |
| --- | --- | --- | --- |
| `R1`, `tests/payment-migration.php` | **3 tests, 3 failures, exit 1** | **3/0, exit 0** | Actual baseline transaction executes provider POST, loses usable response, rolls back response fields; upgrade confirm makes **2 attempts** instead of 1. Legacy pending quarantine count is 0 instead of 3. |
| `R2`, `tests/payments.php` | **4 tests, 4 failures, exit 1** | **4/0, exit 0** | Blocked admin refund disappears for CARD/SBP paid/completed legacy payments: expected eventual refund POST, got none after independent capture reconciliation. |
| `R3`, `tests/payments.php` | **2 tests, 2 failures, exit 1** | **2/0, exit 0** | Capture after READY void commit but before send still produces `[authorize, void]`, not `[authorize, refund]`, for both customer cancellation and admin void. |
| `R4`, `tests/payments.php` | **2 tests, 2 failures, exit 1** | **2/0, exit 0** | Late normalized provider response is absent after recovery or manual reconciliation. Deterministic response barrier verifies durable observation while authority/financial state remain unchanged. |
| `R5`, `tests/payment-migration.php` | **8 tests, 8 failures, exit 1** | **8/0, exit 0** | Old down SQL accepts empty, live, sent, uncertain, inbox, cancellation, initiated-pending and settled-refund states rather than refusing. |

R5's unsafe-down RED was run before adding the R4 observation-table FK, so its failures demonstrated the destructive down being accepted, not an incidental FK refusal. R4 and R5 then separately reached GREEN. Expected provider error logs are intentional fault assertions.

### Fresh full verification after fixes

| Check | Result / exit |
| --- | --- |
| `tests/payment-migration.php` | **13 tests, 0 failures**, 0 |
| `tests/payments.php` | **107 tests, 0 failures**, 0 |
| `tests/payment-http.php` | **4 tests, 0 failures**, 0 |
| Unchanged shared `tests/recovery.php` | **9 tests, 0 failures**, 0 |
| `tests/integration.php` | **11 tests, 0 failures**, 0 |
| `tests/concurrency.php` | **7 tests, 0 failures**, 0 |
| `tests/run.php` | **7 tests, 0 failures**, 0 |
| `tests/lint.php` | **77 files**, 0 |
| Real Go migrator, empty recreated DB / second invocation | **001–014 applied / history validated**, 0 / 0 |
| Route matrix / diff whitespace / unchanged 001–011 diff | **54 operations, eight screens, six commands / clean / unchanged**, 0 / 0 / 0 |
| Direct populated `psql` 012 down, **autocommit** | **Expected 3**, SQLSTATE **0A000**, roll-forward-only refusal |

The seven green test suites total **158 passing cases**. Main lifecycle suite includes the original crash/commit/timeout/race cases; new upgrade cases use the real journaled HTTP provider and committed predecessor schemas. R1 asserts POST attempts, not only deduplicated effect rows. R2 tests new-connection continuation and no completed-order restock. R3 verifies no obsolete void POST; existing sent/unknown void cases remain intact. R4 checks transaction/status/time/original token and exact preservation of operator result/evidence and financial rows. R5 compares complete audit/guard rows across refusal, including when no unresolved work remains.

For direct autocommit downgrade, captured row-content hashes for `orders`, `payments`, `payment_operations`, `payment_transaction_links`, `payment_callback_inbox`, `payment_operation_observations`, and `schema_migrations`; executed actual down SQL through `psql -v ON_ERROR_STOP=1 -v VERBOSITY=verbose` with no outer transaction; verified exit 3, `0A000` and **all seven snapshots unchanged**.

Post-run inspection: **0** leftover `payment_legacy_%`/`payment_upgrade_%` schemas, **0** idle-in-transaction sessions in `payment_fixes`, and **14** genuine migration-history rows. Shared integration now uses `RecoveryService`/014 and was exercised by its unchanged nine-test suite. Historical missing-Keycloak results below are not a current integration blocker; the coordinator's standalone stack rebuild/re-review is separate from these isolated fresh runs.

### Current reproduction setup

The old `payment_fixes` schema was replaced rather than patched or given forged migration history. Only this workstream's DB and its own fixture containers were changed:

```sh
export PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH
docker stop shopkmp-payment-review-provider-20261026 shopkmp-payment-review-http-20261026
docker exec shopkmp-backend-fixes-pg dropdb -U backend_fixes --force payment_fixes
docker exec shopkmp-backend-fixes-pg createdb -U backend_fixes payment_fixes
DATABASE_URL='postgres://backend_fixes:disposable_backend_fixes_only@127.0.0.1:55493/payment_fixes?sslmode=disable' \
  go -C tools/ops run ./cmd/migrate
docker start shopkmp-payment-review-provider-20261026 shopkmp-payment-review-http-20261026
```

Every command exited **0**. The migrator was actually invoked with `tools/ops` as its working directory; `go -C` is the equivalent reproduction. A second migrator invocation also exited 0 and validated the newly recorded 001–014 history, with revised 012 up hash `35bd9b19a7a6411c6ae461dd07b3303c638093931979122abc4f4eb3e498552c`.

Fixture containers used the same isolated network, PHP 8.3 image and image-seeded vendor volume as the first pass. Equivalent creation/test commands (all gateway keys/secrets here are test-only):

```sh
ROOT=/Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO
NET=shopkmp-backend-fixes-20261026
DB_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/payment_fixes?sslmode=disable'
docker run -d --name shopkmp-payment-review-provider-20261026 --network "$NET" \
  -e DATABASE_URL="$DB_URL" \
  -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-payment-review-http-20261026:8080 \
  -v "$ROOT/backend-yii2:/app" -v shopkmp-payment-vendor-20261026:/app/vendor \
  -w /app shopkmp-php-tests php -S 0.0.0.0:8080 tests/payment-provider.php
docker run -d --name shopkmp-payment-review-http-20261026 --network "$NET" \
  -e DATABASE_URL="$DB_URL" \
  -e PAYMENT_GATEWAY_URL=http://shopkmp-payment-review-provider-20261026:8080 \
  -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
  -e PAYMENT_WEBHOOK_ALLOWED_CIDRS=0.0.0.0/0 -e PHP_CLI_SERVER_WORKERS=4 \
  -v "$ROOT/backend-yii2:/app" -v shopkmp-payment-vendor-20261026:/app/vendor \
  -w /app shopkmp-php-tests php -S 0.0.0.0:8080 -t web web/router.php

payreview() {
  docker run --rm --network "$NET" -e DATABASE_URL="$DB_URL" \
    -e PAYMENT_GATEWAY_URL=http://shopkmp-payment-review-provider-20261026:8080 \
    -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
    -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-payment-review-http-20261026:8080 \
    -e PAYMENT_TEST_FILTER="${PAYMENT_TEST_FILTER:-}" \
    -v "$ROOT/backend-yii2:/app" -v "$ROOT/tools/ops/migrations:/migrations:ro" \
    -v shopkmp-payment-vendor-20261026:/app/vendor -w /app shopkmp-php-tests php "$@"
}
PAYMENT_TEST_FILTER=R1 payreview tests/payment-migration.php  # RED 3/3 → GREEN 3/0
PAYMENT_TEST_FILTER=R2 payreview tests/payments.php           # RED 4/4 → GREEN 4/0
PAYMENT_TEST_FILTER=R3 payreview tests/payments.php           # RED 2/2 → GREEN 2/0
PAYMENT_TEST_FILTER=R4 payreview tests/payments.php           # RED 2/2 → GREEN 2/0
PAYMENT_TEST_FILTER=R5 payreview tests/payment-migration.php  # RED 8/8 → GREEN 8/0

# Actual final four-suite execution was sequential, stopping on any nonzero exit:
payreview tests/payment-migration.php
payreview tests/payments.php
payreview tests/payment-http.php
payreview tests/recovery.php
```

Existing integration/concurrency/unit/lint commands used the same DB/source/vendor argument set **without** fixture gateway env, as in the historical command block below. Gateway error/timeouts, three real ten-second deadlines, and deliberate readiness failure logs are expected. The response-release and per-void-status controls are test-only fixture columns; no production provider endpoint/guarantee was invented.

### Current coordinator handoff

- Revised 012 adds nullable `payments.refund_requested_at` and `payment_operation_observations` (including its serial sequence, PK, FK and `(operation_id,lease_token)` uniqueness). It also changes legacy pending backfill and makes down unconditionally roll-forward-only. **No new migration version and no 013/014 change.** Exact DDL and old/new hashes: `PAYMENT_FIX_REPORT.md`.
- New down hash: `6851006fc72c76c5d8b7f9017910f40f03a978cd7f82e7b6fdb943d033459ee7`. Refresh coordinator-owned checksums and rebuild/recreate old-012 disposable stacks/images; **never rewrite applied history/checksums in place**.
- Existing focused runner already includes the modified suites and `/migrations` mount. Rebuild includes new test-only `payment-baseline-confirm.php`; no new suite registration is needed.
- Extend shared readiness checks/regressions for the new refund-intent column and observation table; those shared files were left to the coordinator. Shared worker/console count contracts are unchanged and their nine tests passed.
- Review revision changed only the eleven paths listed in `PAYMENT_FIX_REPORT.md`. No current-review case is deferred. Independent re-review and coordinator rebuild remain separate.

## Historical first-pass evidence — superseded by R1–R5 above

The remainder records the original implementation run, its then-current environment limitations and old migration hashes. Its down/up behavior was the defect fixed by R5 and is **not supported** by revised 012. Use the current revision and handoff above for integration.

Baseline: `01d84b3` on the existing branch. No commits/push. All source edits used `apply_patch`; dedicated read/glob tools failed with `Bun is not defined`, so inspection used Python/shell fallback.

### Historical first-pass results

| Check | Result |
| --- | --- |
| `tests/payments.php` | **99 tests, 0 failures**, exit 0 |
| `tests/payment-http.php` | **4 tests, 0 failures**, exit 0; real PHP HTTP server, bearer ownership/admin checks and signed webhook |
| `tests/payment-migration.php` | **2 tests, 0 failures**, exit 0; real 001–011 then 012, clean/populated schemas, constraints, down/up, rollback isolation |
| Existing `tests/integration.php` | **11 tests, 0 failures**, exit 0 |
| Existing `tests/concurrency.php` | **7 tests, 0 failures**, exit 0 |
| Existing `tests/run.php` | **7 tests, 0 failures**, exit 0 |
| PHP lint | **73 files**, exit 0 (shared worktree snapshot at verification) |
| `python3 scripts/route_matrix.py --check` | **54 exact operations/policies; eight screens/six commands match**, exit 0 |
| `git diff --check` | exit 0 |
| Actual `php yii maintenance/payments` | expected **exit 2**, `dispatched: 0`, **206 UNKNOWN review rows** from deliberate failure/legacy fixtures; JSON parsed and every review row checked as `UNKNOWN` |
| `php yii help maintenance/payment-reconcile` | exit 0, all four required parameters registered |

The six green suites above total **130 passing cases**. The broader existing API suite was also attempted: **9 tests, 3 failures**, due to absent Keycloak issuer/client/realm configuration (health/ready, registration/auth flow, and consequent full-route-coverage assertion). Its payment/commerce round trip and demo browser payment cases passed. This is **not** a claim that `tests/all.php`, the full Keycloak stack, Go tests or deployment verification passed; those remain coordinator integration work.

Expected gateway error logs in negative tests are deliberate assertions, including three real ten-second timeout cases. No live payment provider or production service was used.

### Historical TDD evidence

Before production changes, wrote the journaled provider fixture and 47 application/HTTP scenarios, then ran the payment suite:

- **RED: 47 tests, 31 failures, exit 1.** Examples: provider observed an open SQL transaction (`Expected 0, got 1`); initiated `PENDING` callbacks rejected; cancellation/expiry omitted void; arbitrary 2xx reversal marked terminal; lost response confirmation replayed; late captures rejected. New recovery API absence also failed the new tests.
- Initial implementation: 47 tests, 2 failures. Corrected the test's expected successful webhook status to the existing **204** contract. Removed the global “no other transaction anywhere” assertion from the multi-worker race, where unrelated workers may legitimately have short transactions; isolated HTTP-boundary cases still assert zero idle-in-transaction sessions and provider-visible committed `SENT` intent.
- Expanded to **73 tests, 1 failure** (manual reconciliation not implemented), implemented evidence-based reconciliation.
- Expanded to **83 tests, 1 failure** (`FAILED` after `REFUNDED` must retain historical status rejection); fixed the terminal-state check.
- Separate new **RED 1/1** for confirming after cancellation exposing an old redirect; moved cancellation-intent rejection before the authorized/captured fast path.
- Separate new **RED 1/1** for a 1,001-entry manual backlog starving a committed unsent intent; changed recovery selection to exclude blocked manual cases.
- Separate new **RED 1/1** for omitted-transaction `FAILED` after refund returning the wrong historical error; preserved status-error precedence.
- Existing concurrency suite **RED 7 tests, 1 failure** exposed duplicate callbacks waiting on the aggregate lock and then being revalidated before deduplication. Added the post-lock dedup check; **GREEN 7/0**.
- Existing integration initially failed a reused constant `test-txn`, already owned by another fixture in the cloned populated database. Updated only payment transaction fixtures in `tests/integration.php` and `tests/api.php` to include their payment UUID. The new uniqueness/correlation rule was retained. **GREEN integration 11/0**.
- Final focused suite **GREEN 99/0**, plus HTTP **4/0** and migration **2/0**.

`PAYMENT_TEST_FILTER` selects an exact name substring in the focused suite; unmatched filters fail instead of producing a zero-test success.

### Historical coverage and fault mechanisms

- CARD/SBP authorization as `AUTHORIZED` and `PENDING`; exact integer cents, maximum NUMERIC-boundary cents, bearer authorization and stable operation key.
- Provider fixture journals an effect once per key under a PostgreSQL advisory lock and returns the same stored response on repeated requests. A deliberate repeated-key adapter test observes two attempts but one effect row. Application recovery tests observe one attempt for uncertain operations; they do not rely on production idempotency guarantees.
- Provider sees committed `SENT` intent/key during HTTP. Sequential send/timeout scenarios observe zero idle-in-transaction callers. Racing scenarios verify operation sequencing, one request per effect and no concurrent reversal dispatch while another send is live.
- Cancellation matrix: CARD, SBP, ON_DELIVERY × unstarted, authorized, pending, captured. Expiry matrix: all three methods' eligible reservations, exclusion of CARD/SBP terminal/paid states and ON_DELIVERY completed purchases.
- Reversal matrix for both void/refund: empty 204; 202 pending; unexpected 200/201; invalid JSON; 400/500. Uncertain effects retain the prior financial status.
- Malformed authorization status/URL/transaction types, unsafe URLs, foreign payment/order response fields and a transaction already owned by another payment.
- **Actual transport deadlines:** fixture records execution, delays response 11 seconds, client times out at 10 seconds. Authorize, void and refund remain `UNKNOWN` across recovery with no replay.
- **Intent/claim/apply commit faults:** PostgreSQL deferred constraint triggers fail at actual COMMIT. Intent failure produces no HTTP; claim failure leaves `READY` and a new service recovers it; result-commit failure leaves `SENT` for review after lease expiry.
- **Actual process restart fault:** forked sender is `SIGKILL`ed after the provider journal records the request. A fresh service/recovery instance escalates the expired `SENT` operation and does not send again.
- Stale returning result after lease expiry is fenced. Reconcile cannot override a live sender. Manual evidence resolves only `UNKNOWN` with matching transaction, persists evidence and drains verified early capture before compensation.
- Two confirm workers; two refund workers; duplicate callbacks; concurrent cancel/capture/refund/expiry; cancellation during authorization; capture during void. Late capture after `FAILED` or confirmed cancellation queues one refund. Capture behind an uncertain void remains visible for manual review.
- Stock failure trigger verifies atomic rollback of restoration guard and webhook marker; retry restores once. Completed-order refund preserves consumed stock.
- **Genuine HTTP:** PHP CLI web server + real curl requests through `web/index.php`/Yii/ApiKernel. Valid HMAC callbacks, invalid signature, stale timestamp, unknown and foreign transaction, duplicate 409 `ALREADY_PROCESSED`, early callback quarantine/correlation, ownership 404, unauthenticated 401, nonadmin 403 and unchanged money strings/status DTOs.
- Early callback returns 400 while unverified; after the authorize response binds its transaction, the durable inbox is applied and its retry is 409. If the response is lost, a real operator-evidence path is exercised instead of invented lookup.

### Historical environment and reproducible commands

- PostgreSQL **17.10**, existing container `shopkmp-backend-fixes-pg`, network `shopkmp-backend-fixes-20261026`.
- Created only this workstream's DB with:

```sh
export PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH
docker exec shopkmp-backend-fixes-pg createdb -U backend_fixes -T backend_fixes payment_fixes
```

- Applied 012 using Python to feed its bytes to `docker exec -i ... psql -v ON_ERROR_STOP=1 -U backend_fixes -d payment_fixes` (stdin). Baseline database and `session_fixes` were not changed.
- Main lifecycle tests first passed against 001–012. To test the current shared branch's session-backed HTTP ownership/admin flow and existing integration/concurrency suites, also applied the session owner's **013** to **payment_fixes only**, using the same stdin command. No session-owned source or migration file was edited.
- PHP **8.3.36**, image `shopkmp-php-tests`, image ID `sha256:4d146841f0fbb4479bd5778820fda63246b1441cf812e11ac348b41ac66304d4`.
- Initial source bind-mount attempt failed **before tests** because host `vendor` required PHP >=8.5.11. Fixed by seeding a separate Docker vendor volume from the baseline PHP 8.3 image and mounting it over `/app/vendor`. Host vendor/dependency locks were not changed.

Commands below reproduce the actual Docker argument sets (shell helper avoids repeating them):

```sh
ROOT=/Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO
NET=shopkmp-backend-fixes-20261026
DB_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/payment_fixes?sslmode=disable'

# Seed once from the image, before mounting current source.
docker run --rm -v shopkmp-payment-vendor-20261026:/app/vendor \
  shopkmp-php-tests php -r 'require "/app/vendor/autoload.php";'

docker run -d --name shopkmp-payment-provider-20261026 --network "$NET" \
  -e DATABASE_URL="$DB_URL" \
  -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-payment-http-20261026:8080 \
  -v "$ROOT/backend-yii2:/app" -v shopkmp-payment-vendor-20261026:/app/vendor \
  -w /app shopkmp-php-tests php -S 0.0.0.0:8080 tests/payment-provider.php

docker run -d --name shopkmp-payment-http-20261026 --network "$NET" \
  -e DATABASE_URL="$DB_URL" \
  -e PAYMENT_GATEWAY_URL=http://shopkmp-payment-provider-20261026:8080 \
  -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
  -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
  -e PAYMENT_WEBHOOK_ALLOWED_CIDRS=0.0.0.0/0 -e PHP_CLI_SERVER_WORKERS=4 \
  -v "$ROOT/backend-yii2:/app" -v shopkmp-payment-vendor-20261026:/app/vendor \
  -w /app shopkmp-php-tests php -S 0.0.0.0:8080 -t web web/router.php

payrun() {
  docker run --rm --network "$NET" -e DATABASE_URL="$DB_URL" \
    -e PAYMENT_GATEWAY_URL=http://shopkmp-payment-provider-20261026:8080 \
    -e PAYMENT_GATEWAY_API_KEY=payment-fixture-key \
    -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
    -e PAYMENT_TEST_HTTP_ORIGIN=http://shopkmp-payment-http-20261026:8080 \
    -v "$ROOT/backend-yii2:/app" -v shopkmp-payment-vendor-20261026:/app/vendor \
    -w /app shopkmp-php-tests "$@"
}

payrun php tests/payments.php       # final: 99/0; initial RED: 47/31
payrun php tests/payment-http.php   # 4/0; requires shared session migration 013
```

Provider scenarios and journal tables are test-only, created by the focused suites. Run these suites sequentially: the deliberate slow-response provider is single-worker. The fixture's permissive source CIDR is test-only.

For the filtered RED runs, the same `docker run` command additionally used, respectively:

```sh
-e PAYMENT_TEST_FILTER='confirm after cancellation'
-e PAYMENT_TEST_FILTER='does not starve'
-e PAYMENT_TEST_FILTER='omitted transaction'
```

Migration check (same source/vendor mounts, additional read-only migration mount):

```sh
docker run --rm --network "$NET" -e DATABASE_URL="$DB_URL" \
  -v "$ROOT/backend-yii2:/app" -v "$ROOT/tools/ops/migrations:/migrations:ro" \
  -v shopkmp-payment-vendor-20261026:/app/vendor -w /app \
  shopkmp-php-tests php tests/payment-migration.php
```

In the first pass it executed the immutable 001–011 SQL in a new transaction-local schema with no public-table search path, then tested the original 012; every schema change rolled back. A populated down/up rehearsal was also run within `BEGIN`/`ROLLBACK` against `payment_fixes`: exit 0, **249** legacy operations imported in that snapshot, rollback completed. **This old down/up behavior is superseded: revised 012 refuses all downgrades; the current suite tests refusal and full audit preservation instead.**

Existing suites were run without a gateway URL (demo contract):

```sh
for suite in integration concurrency; do
  docker run --rm --network "$NET" -e DATABASE_URL="$DB_URL" \
    -e PAYMENT_WEBHOOK_SECRET=payment-test-secret \
    -v "$ROOT/backend-yii2:/app" -v shopkmp-payment-vendor-20261026:/app/vendor \
    -w /app shopkmp-php-tests php "tests/$suite.php"
done
docker run --rm -v "$ROOT/backend-yii2:/app" \
  -v shopkmp-payment-vendor-20261026:/app/vendor -w /app \
  shopkmp-php-tests php tests/run.php
docker run --rm -v "$ROOT/backend-yii2:/app" \
  -v shopkmp-payment-vendor-20261026:/app/vendor -w /app \
  shopkmp-php-tests php tests/lint.php
python3 scripts/route_matrix.py --check
git diff --check
```

The broader `tests/api.php` attempt used the same demo argument set plus `RATE_LIMIT_READ=10000`, `RATE_LIMIT_WRITE=10000`, `RATE_LIMIT_AUTH=10000`; no `KEYCLOAK_ISSUER`/client credentials were available for that run. Coordinator should rerun it with the real disposable Keycloak fixture/realm.

### Historical first-pass owned paths

```text
backend-yii2/application/PaymentService.php
backend-yii2/application/PaymentOperations.php                  (new)
backend-yii2/application/CommerceService.php                    (stock guard / lock order only)
backend-yii2/application/MaintenanceService.php
backend-yii2/infrastructure/PaymentGateway.php
backend-yii2/controllers/MaintenanceController.php
backend-yii2/tests/payment-fixture.php                         (new)
backend-yii2/tests/payment-provider.php                        (new)
backend-yii2/tests/payments.php                                (new)
backend-yii2/tests/payment-http.php                            (new)
backend-yii2/tests/payment-migration.php                       (new)
backend-yii2/tests/integration.php                             (payment fixture ID only)
backend-yii2/tests/api.php                                     (payment fixture ID only)
tools/ops/migrations/012_payment_operations.up.sql              (new)
tools/ops/migrations/012_payment_operations.down.sql            (new)
docs/backend-fixes/PAYMENTS.md                                 (new)
docs/backend-fixes/PAYMENT_TEST_RESULTS.md                      (new)
```

Other changes visible in the shared worktree belong to the session/trust workstreams. No mobile code, old migration, Compose file, shared runner or checksum manifest was edited by this workstream.

### Historical coordinator requests — superseded by current handoff

1. Add **only the new** migration entries to `tools/ops/migrations/SHA256SUMS` using the project's manifest generator. Current hashes:

   ```text
   b2aedfffa82e452eceb97dec1b25a053fcbb1c7c796a6d55e2bb13bcf18deab1  012_payment_operations.up.sql
   0949afdeba9881bceeb738ad724563cac52d3cf13f46d838e2014ee77ce13a28  012_payment_operations.down.sql
   ```

2. Wire `tests/payments.php` and `tests/payment-http.php` into shared CI as **separate child commands with the fixture gateway/HTTP env only for those commands**. Existing demo/API suites must not inherit the fixture gateway URL. Wire `tests/payment-migration.php` with the additional read-only migration-directory mount. Apply 012 and the session workstream's final 013 before session-backed HTTP tests.
3. Add isolated test-only provider/HTTP services equivalent to the commands above; supply their `DATABASE_URL`, fixture URL/key and HMAC secret. Do not configure these fixtures in production. Start focused suites sequentially because timeout cases deliberately occupy the single-worker provider.
4. Existing `maintenance/run` already invokes payment recovery. Add monitoring/polling of `maintenance/payments` (exit 2 / `review` rows) and unresolved `payment_callback_inbox`; alerts must distinguish manual uncertainty from a failed worker heartbeat. Readiness/deployment integration should verify 012 tables exist before enabling new writers.
5. Drain old writers for rollout; audit historical terminal remote payments against settlement. Combine session/trust changes, run the full Keycloak-backed API stack, migration manifest/parallel migration checks, Go contract/regression checks and deployment checks. This workstream did not execute those shared gates or change their files.
6. Provider limitations remain real: no lookup endpoint, no documented safe POST replay, unknown response/settlement cannot be resolved automatically. Unsent intents recover; sent ambiguity requires independent evidence. See the reconciliation procedure in `PAYMENTS.md`.

Disposable resources: `payment_fixes` and the image-seeded `shopkmp-payment-vendor-20261026` volume are retained for coordinator inspection. The two workstream fixture containers are removed after verification; the existing PostgreSQL container/network and other workstreams' containers/databases are untouched.
