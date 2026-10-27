# Payment review fixes R1–R5

**Date:** 2026-10-26. **Baseline:** `01d84b3`. Read `PAYMENT_REVIEW.md` in full; all five reproduced findings are addressed. No subagents, commits or pushes. Shared integration files, `RecoveryService`, migration 014, and migrations 001–011 were not edited.

## Findings and fixes

| Finding | Production change | Meaningful regression / observed RED → GREEN |
| --- | --- | --- |
| **R1 High — legacy lost authorization response replay** | 012 imports **all** pre-existing external payments. Every external `PENDING` is `UNKNOWN`, including null response fields and saved transaction cases. Only post-upgrade new payments start without an imported fence. | Frozen baseline `confirm` transaction path executes real provider HTTP, loses the usable response, and rolls back to `[PENDING,null,null]`. CARD/SBP upgrade/confirm/cancel/recover retain **one POST attempt**, unresolved capture is quarantined, and new post-upgrade payments still authorize/void automatically. A third case covers every legacy pending shape. **3 failures / exit 1 → 3 passes / exit 0.** |
| **R2 High — blocked refund intent lost** | `payments.refund_requested_at` persists a valid admin refund request even when a different live/uncertain operation prevents `queue()`. Reconciliation and recovery use it independently of customer cancellation. | CARD/SBP × PAID/COMPLETED legacy captured payments with unknown authorization: request only refund, restart on new connections, independently reconcile capture, recover one refund. No competing send, exact 2046 cents, one attempt, ordinary stock restored once, completed stock retained. **4 failures / exit 1 → 4 passes / exit 0.** |
| **R3 High — READY void sent after capture** | Under order/payment/operation locks, send claim detects capture superseding an unsent `READY` void; atomically marks it `ABORTED`, persists refund intent and queues refund. `SENT`/`UNKNOWN` are not superseded. | Real deferred COMMIT failure pauses after void intent but before send claim. Capture wins; fresh recovery must make **zero void POSTs and one refund POST**, for customer cancellation and admin void. Fixture explicitly declines void with 409 so accidental void cannot masquerade as successful compensation. **2 failures / exit 1 → 2 passes / exit 0.** Existing in-flight/unknown void tests remain green. |
| **R4 Medium — fenced response discarded / operator evidence at risk** | Append-only `payment_operation_observations` saves normalized successful adapter responses with original operation, token and observation time **before** the apply transaction. Late responses remain facts without becoming authority; operator `result`/evidence and financial state are unchanged. | Deterministic HTTP response-release barrier allows recovery to fence the owner first, with and without subsequent manual reconciliation. Both cases retain transaction/status/time/original token; unreconciled capture stays quarantined; operator evidence survives byte-for-byte; no extra POST or stale financial apply. **2 failures / exit 1 → 2 passes / exit 0.** |
| **R5 Medium — downgrade destroys audit/fences** | 012 down is **unconditionally roll-forward-only**, raising `0A000` before any DDL/DML. | Empty, READY, SENT, UNKNOWN, unresolved early capture, cancellation intent, initiated pending, and settled refund cases all refuse downgrade and preserve complete row snapshots. **8 failures / exit 1 → 8 passes / exit 0.** Direct populated autocommit `psql` down also exits **3**, retaining hashes of all seven payment/audit/history tables. |

The baseline fixture is test-only (`payment-baseline-confirm.php`): the authorize path from `01d84b3` is copied verbatim except the class name and omission of unrelated methods. It uses real PDO transactions and the real HTTP gateway, not a savepoint/gateway double. R1 asserts **attempts**, not just the provider's idempotently deduplicated effect count. Migration/lifecycle legacy schemas are committed, allow genuine restart/new-connection behavior, then are dropped. Post-verification inspection found **zero leftover payment schemas and zero idle-in-transaction sessions**.

## Fresh verification

| Command / suite | Result |
| --- | --- |
| `php tests/payment-migration.php` | **13 tests, 0 failures**, exit 0 |
| `php tests/payments.php` | **107 tests, 0 failures**, exit 0 |
| `php tests/payment-http.php` | **4 tests, 0 failures**, exit 0, real PHP HTTP server / signed webhooks |
| `php tests/recovery.php` | **9 tests, 0 failures**, exit 0, unchanged shared RecoveryService integration |
| `php tests/integration.php` | **11 tests, 0 failures**, exit 0 |
| `php tests/concurrency.php` | **7 tests, 0 failures**, exit 0 |
| `php tests/run.php` | **7 tests, 0 failures**, exit 0 |
| `php tests/lint.php` | **77 PHP files**, exit 0 |
| Actual migrator on recreated empty `payment_fixes` | Applies **001–014**, exit 0; second invocation exit 0 with real stored checksums |
| `python3 scripts/route_matrix.py --check` | **54 operations/policies, eight screens/six commands**, exit 0 |
| `git diff --check`; unchanged 001–011 diff | exit 0 for both |
| Actual `psql -v ON_ERROR_STOP=1` executing 012 down, autocommit | **Expected exit 3**, SQLSTATE **0A000**; seven table snapshots unchanged |

**158 passing cases** across the seven test suites. Expected negative-test gateway/timeouts and deliberate readiness-failure logs are present. Fresh results do not claim a rebuild/rerun of the coordinator-owned standalone stack; that stack must be recreated with revised 012. Exact Docker commands and filtered RED/GREEN invocations are recorded in `PAYMENT_TEST_RESULTS.md`.

## Exact schema drift from the reviewed 012

There is **no new migration version**. 012–014 are unreleased; only 012 changed here.

1. **New nullable column:** `payments.refund_requested_at timestamptz`, default null. Existing rows do not acquire invented refund requests. Application writes the first valid requested-refund time with `COALESCE`.
2. **New table/sequence/indexes:**

   ```sql
   CREATE TABLE payment_operation_observations (
       id bigserial PRIMARY KEY,
       operation_id uuid NOT NULL REFERENCES payment_operations(id),
       lease_token uuid NOT NULL,
       observed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
       result jsonb NOT NULL,
       UNIQUE(operation_id,lease_token)
   );
   ```

   `result` is the normalized adapter response; JSON `null` represents a confirmed empty 204. The application inserts once per original ownership token, never updates/deletes observations, and does not use an observation to bypass reconciliation. `payment_operations.result` remains the authoritative applied/operator result.
3. **Changed data migration:** the former predicate skipped legacy `PENDING/null/null`; 012 now imports every non-ON_DELIVERY payment. Every pre-existing external `PENDING` is `UNKNOWN` regardless of saved correlation. Other uniquely correlated legacy payments remain `SUCCEEDED`; missing/duplicate correlation remains `UNKNOWN`. Transaction links, public statuses, money strings, and cancelled-order restoration backfill are preserved.
4. **Changed down semantics:** former destructive DROP/ALTER statements are replaced by unconditional SQLSTATE `0A000` refusal. Even empty/drained downgrade is unsupported. No payment/callback/observation/correlation/intent/stock audit can be deleted by this file.

| File | Reviewed old SHA-256 | Revised SHA-256 |
| --- | --- | --- |
| 012 up | `b2aedfffa82e452eceb97dec1b25a053fcbb1c7c796a6d55e2bb13bcf18deab1` | `35bd9b19a7a6411c6ae461dd07b3303c638093931979122abc4f4eb3e498552c` |
| 012 down | `0949afdeba9881bceeb738ad724563cac52d3cf13f46d838e2014ee77ce13a28` | `6851006fc72c76c5d8b7f9017910f40f03a978cd7f82e7b6fdb943d033459ee7` |

Revised `PaymentOperations.php`: `e369d2303a5ca0d1c416bd75d786ea77067e146445b87e67bbba8bff4f58774c`. PaymentService, PaymentGateway and CommerceService hashes still match the review snapshot.

## Database provenance and coordinator handoff

- Only the payment workstream's **`payment_fixes`** DB was replaced. Its old 012 test schema was not patched in place, and no historical checksum was edited. With this workstream's two fixture containers stopped, ran `dropdb --force payment_fixes`, `createdb payment_fixes`, then the **actual Go migrator** against `127.0.0.1:55493/payment_fixes`. It recorded all 14 migrations normally; a second run validated that history. Other databases/containers were not altered.
- Coordinator should recreate the disposable standalone stack with revised SQL and rebuilt migration/PHP/test-fixture images. **Do not UPDATE `schema_migrations.checksum` to pretend old 012 installed this schema.** Refresh only the new/revised manifest entries using the repository's normal process. No 001–011 edit and no 013/014 change is required.
- Existing shared runner already runs the modified migration/lifecycle files and mounts `/migrations`; no additional suite registration is needed. The new test-only baseline fixture must be included in the rebuilt PHP image. `tests/payment-migration.php` now needs the same provider fixture env as the other focused suites; `scripts/backend_fixes.py` already supplies it.
- Extend coordinator-owned readiness drift checks for **`payments.refund_requested_at`** and **`payment_operation_observations(operation_id,lease_token,observed_at,result)`**, with the equivalent absent-column/table regressions in `tests/recovery.php`. Those shared files were intentionally not edited here. Fresh-stack migration and all existing recovery/readiness tests pass, but the old readiness query alone does not detect those two newly added requirements.
- Existing count-only console/scheduler interfaces remain intact. Detailed provider observations are operator SQL/application audit data, not new public DTO fields or console log payloads. No provider lookup or automatic sent-request replay was introduced.
- Downgrade is now explicitly unsupported; use compatible roll-forward application/schema fixes. Recreating a disposable DB is a test setup operation, not a supported financial-ledger rollback procedure.

## Files changed by this review revision

```text
backend-yii2/application/PaymentOperations.php
backend-yii2/tests/payment-baseline-confirm.php     (new, frozen baseline fixture)
backend-yii2/tests/payment-fixture.php
backend-yii2/tests/payment-provider.php
backend-yii2/tests/payment-migration.php
backend-yii2/tests/payments.php
tools/ops/migrations/012_payment_operations.up.sql
tools/ops/migrations/012_payment_operations.down.sql
docs/backend-fixes/PAYMENTS.md
docs/backend-fixes/PAYMENT_TEST_RESULTS.md
docs/backend-fixes/PAYMENT_FIX_REPORT.md            (new)
```

No current-review fix is deferred. Independent re-review and coordinator stack recreation/manifest/readiness integration remain separate work. The payment test DB and isolated vendor volume are retained for inspection; only this workstream's two review fixture containers are removed after verification.
