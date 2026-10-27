# Scoped payment re-review — R1–R5

**Date:** 2026-10-26

**Baseline:** `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`; current uncommitted revision identified by the hashes below.

**Specification verdict: PASS for the R1–R5 fixes.**

**Quality verdict: ACCEPTABLE for this revision. All five findings are ADDRESSED. No remaining or fix-introduced Critical, High, or Medium finding confirmed within the scoped review.**

## Scope and evidence

Read `PAYMENT_FIX_REPORT.md`, the original `PAYMENT_REVIEW.md`, current migration 012 up/down, `PaymentOperations.php`, the updated payment migration/lifecycle tests, baseline-confirm fixture, provider fixture, and fixture helpers. Cross-checked the revised payment documentation and test-results evidence. Inspected the coordinator's readiness additions, associated regressions, and 012 checksum entries.

This re-review is limited to whether R1–R5 were corrected and whether those corrections introduce new regressions. It is not a renewed review of unrelated shared work or a standalone-stack deployment signoff. No implementation changes or subagents were used. Only this report was written.

As requested, no full suites were rerun. The agent's reported executions are identified below as **reported evidence**, rather than fresh executions by this reviewer. Source inspection and snapshot/checksum checks were sufficient; no additional database proof was necessary and no database was modified in this re-review.

## Per-finding verdicts

| Original finding | Verdict | Current implementation | Regression coverage inspected |
| --- | --- | --- | --- |
| **R1 — High:** legacy lost authorization response can be replayed after upgrade | **ADDRESSED** | `tools/ops/migrations/012_payment_operations.up.sql:51–61` | `backend-yii2/tests/payment-migration.php:53–79` |
| **R2 — High:** blocked admin refund loses business intent | **ADDRESSED** | `backend-yii2/application/PaymentOperations.php:44–55`, `:96–100`, `:248–253`; `tools/ops/migrations/012_payment_operations.up.sql:5–7` | `backend-yii2/tests/payments.php:269–288` |
| **R3 — High:** capture before send does not supersede an unsent void | **ADDRESSED** | `backend-yii2/application/PaymentOperations.php:112–127` | `backend-yii2/tests/payments.php:289–305` |
| **R4 — Medium:** recovery discards a returning provider response | **ADDRESSED** | `backend-yii2/application/PaymentOperations.php:134–147`; `tools/ops/migrations/012_payment_operations.up.sql:29–38` | `backend-yii2/tests/payments.php:306–329` |
| **R5 — Medium:** downgrade destroys send fences and audit facts | **ADDRESSED** | `tools/ops/migrations/012_payment_operations.down.sql:1–10` | `backend-yii2/tests/payment-migration.php:80–111` |

### R1 — ADDRESSED

The migration no longer interprets missing response fields as proof of an unsent legacy request. It imports every pre-existing non-`ON_DELIVERY` payment and makes every legacy external `PENDING` authorization `UNKNOWN`, including rows with saved correlation. The imported send fence causes confirmation to encounter an existing operation and cancellation to retain unresolved financial state. Recovery cannot reset that uncertainty to `READY` or replay the POST. Post-upgrade newly created payments still have the normal genuinely-unsent path.

The new CARD/SBP tests reproduce the relevant baseline transaction rollback through actual HTTP, assert `[PENDING, null, null]`, migrate, reconnect, and exercise confirm/cancel/recover. They assert **one POST attempt**, retained financial uncertainty, durable capture quarantine, and normal authorization/void for a newly created payment. The third case checks null and saved-correlation legacy pending shapes, while excluding cash payments.

Fresh source comparison confirmed that the frozen `PaymentBaselineConfirm::confirm()` body in `backend-yii2/tests/payment-baseline-confirm.php:18–31` exactly matches the baseline method. The test's attempt assertion avoids relying on the fixture's stronger-than-production effect deduplication.

**Reported evidence:** R1 filter **3 failures → 3 passes**. The conservative legacy quarantine is intentional and consistent with the absence of a provider lookup/replay guarantee; it is not a new regression.

### R2 — ADDRESSED

A valid refund request now persists `refund_requested_at` inside the aggregate transaction **before** attempting to queue an operation. An unrelated live operation can still block sending, but cannot erase the requested business effect. `compensate()` recognizes refund intent independently of cancellation, and recovery selects unblocked captured payments carrying either intent. Reconciliation re-reads the payment and invokes that same compensation path.

The tests cover CARD/SBP × PAID/COMPLETED legacy captured payments with uncertain authorization. They request only the admin refund, continue on fresh connections, confirm that uncertainty blocks any competing send, independently reconcile capture, and recover exactly one refund. Assertions include integer **2046 cents**, one attempt, persisted request intent, once-only restoration for ordinary orders, and preserved completed status/consumed stock for completed orders.

The internal column is nullable and the migration invents no historical refund requests. The existing public facade/DTO remains unchanged.

**Reported evidence:** R2 filter **4 failures → 4 passes**.

### R3 — ADDRESSED

Claim-time revalidation occurs after acquiring the aggregate and operation locks and after requiring `READY`. When capture has superseded a void, the same transaction aborts the unsent void, persists refund intent, and queues compensation. The partial unique live-operation constraint remains intact because the void becomes terminal before the refund is queued. No void POST occurs on this path.

The guard is deliberately unavailable to `SENT`/`UNKNOWN` operations, preserving the distinction between a provably unsent effect and an uncertain remote effect. `dispatchReady()` can continue to the replacement refund; a direct dispatch returning after supersession also leaves a durable refund for recovery.

Both customer-cancellation and admin-void tests use a deferred claim-COMMIT failure to leave a committed `READY` void, then deliver capture and recover from a fresh connection. The provider is configured to decline an obsolete void, and assertions require **zero void POSTs**, one refund attempt, an `ABORTED` void, and once-only stock restoration. Existing in-flight and uncertain-void cases remain in the suite.

**Reported evidence:** R3 filter **2 failures → 2 passes**, with existing sent/unknown cases reported green.

### R4 — ADDRESSED

A successful normalized adapter response is now appended to `payment_operation_observations` in an independent autocommit write before attempting financial application. Each observation retains its original operation, lease token, server observation time, and JSON result; a confirmed empty reversal is represented by JSON `null`. The unique operation/token pair makes the append idempotent.

The audit insert is not conditional on the operation still being `SENT`. Subsequent financial application still requires the original token and an unexpired `SENT` lease. A fenced return does not update authoritative `payment_operations.result`, transaction linkage, or financial state. An operator's previously recorded evidence is therefore retained separately from the late response.

The new tests use a response-release barrier rather than relying on a short timing window. They cover recovery before return, with and without intervening reconciliation, and assert original ownership, transaction/status/time, byte-for-byte authoritative-result preservation, unchanged payment rows, one POST attempt, and continued quarantine when no independent reconciliation occurred. The provider barrier explicitly holds no SQL transaction (`backend-yii2/tests/payment-provider.php:30–36`).

**Reported evidence:** R4 filter **2 failures → 2 passes**. The observation is an investigation fact, not a new authority or permission to replay.

### R5 — ADDRESSED

The down migration is now unconditionally roll-forward-only: its first statement raises SQLSTATE `0A000`, with no preceding destructive DDL/DML. This protects both unresolved and fully settled audit, including initiated pending payments that the old application could resend even after a nominal drain. There is no longer a purportedly safe empty/drained downgrade path.

The regression matrix checks EMPTY, READY, SENT, UNKNOWN, early capture, cancellation intent, initiated pending, and settled-refund states. It verifies the expected refusal and complete payment/audit row snapshots. The fix report also supplies direct populated **autocommit** `psql` evidence with seven unchanged table-content snapshots, complementing the transaction/savepoint tests. The documentation now explicitly requires compatible roll-forward fixes.

**Reported evidence:** R5 filter **8 failures → 8 passes**; direct down execution **expected exit 3 / SQLSTATE 0A000**, with audit/history unchanged.

## Fix-introduced regressions and specification/quality assessment

**None confirmed at Critical, High, or Medium severity in the reviewed fixes.**

- **Financial ownership/API:** PHP remains the business owner; `PaymentService`, `PaymentGateway`, and Commerce hashes still match the original reviewed snapshot. No public route, payment status enum, webhook requirement, money representation, or provider replay/lookup capability was added by these fixes.
- **Durability and concurrency:** refund intent is separate from send availability; stale-void replacement is atomic under the existing lock order; observations survive separately from authoritative application. The single-live-operation fence, stable logical keys, ownership leases, and no-HTTP-under-transaction send boundary remain intact.
- **Cancellation/stock policy:** the new intent is not treated as permission to restore completed stock. Ordinary admin refunds still apply cancellation/restoration on confirmed refund; existing customer-cancellation behavior remains distinct.
- **Unknown/manual outcomes:** independent reconciliation remains necessary where facts are unavailable. The fixes retain requested effects and observed responses without turning uncertainty into permission for another POST.
- **Migration/deployment contract:** revising unreleased 012 is consistent with the stated worktree stage. Current 012 manifest checksums match the revised bytes. Coordinator readiness checks include `refund_requested_at` and all new observation columns at `backend-yii2/infrastructure/ApiKernel.php:180–185`; absent-table/column regressions are present at `backend-yii2/tests/recovery.php:67–81`.
- **Test quality:** the additions directly close the original review's gaps: actual baseline response rollback, new-connection refund continuation, capture before rather than during send, deterministic fenced-response evidence retention, and explicit downgrade refusal. They assert attempts and business/financial outcomes rather than only fixture effect counts.

**Scoped approval:** the R1–R5 correction set meets the reviewed specification and quality requirements. Remaining Critical: **0**; High: **0**; Medium: **0** within this re-review's scope.

## Verification provenance

**Reported execution evidence inspected:** `PAYMENT_FIX_REPORT.md:17–34` and `PAYMENT_TEST_RESULTS.md:7–41` record **158 passing cases**: migration 13, lifecycle 107, HTTP 4, recovery 9, integration 11, concurrency 7, and domain 7. They also record lint, real 001–014 migration/history validation, route checks, and direct downgrade refusal. These were not rerun or claimed as fresh executions here.

**Fresh checks performed by this reviewer:**

1. Matched the current coordinator/012 hashes to the fix report and current migration manifest; confirmed the facade, gateway, and Commerce hashes remain unchanged from the original review.
2. Compared the frozen baseline confirmation method body directly with `git show 01d84b3:backend-yii2/application/PaymentService.php`: exact match.
3. Checked the 001–011 migration diff against baseline: unchanged.
4. Ran `git diff --check` and checked this untracked report separately for whitespace errors.

### Core snapshot

```text
e369d2303a5ca0d1c416bd75d786ea77067e146445b87e67bbba8bff4f58774c  backend-yii2/application/PaymentOperations.php
35bd9b19a7a6411c6ae461dd07b3303c638093931979122abc4f4eb3e498552c  tools/ops/migrations/012_payment_operations.up.sql
6851006fc72c76c5d8b7f9017910f40f03a978cd7f82e7b6fdb943d033459ee7  tools/ops/migrations/012_payment_operations.down.sql
```
