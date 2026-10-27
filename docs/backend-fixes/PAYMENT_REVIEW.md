# Independent B1–B4 payment review

**Date:** 2026-10-26

**Baseline:** `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`

**Verdict:** **Request changes — 3 High and 2 Medium findings. No Critical finding confirmed.**

## Scope and method

Reviewed the current uncommitted implementation, including the untracked files directly, against `PLAN.md`, `PAYMENTS.md`, and `PAYMENT_TEST_RESULTS.md`. Inspected `PaymentOperations`, the payment facade/gateway, Commerce locking/stock restoration, Maintenance integration, migration 012 in both directions, and all five new payment test/fixture files. Also traced the baseline payment implementation, database transaction wrapper, HTTP adapter, repository DTOs, and API authentication/webhook boundary.

This was an independent code review with no subagents. The only repository file written by this review is this report. Shared recovery/deployment/test-runner integration is concurrently being completed; its outstanding wiring is not reported as a payment defect here.

The five findings below were reproduced using the actual application classes and migration SQL in an isolated PostgreSQL schema inside one outer transaction. A PDO savepoint wrapper allowed application transactions to execute without committing the review fixtures. A deterministic gateway double supplied authorization results, uncertainty, and controlled ordering. Source, migrations, and vendor were mounted read-only. The outer transaction was rolled back, and a subsequent query confirmed zero leftover review schemas and the original 573 payment rows.

These reproductions establish the state-machine defects; they do **not** constitute a live-provider test, a new crash-durability test, or a rerun of the full payment/integration suites.

## Findings

### R1 — High: migration treats an old lost authorization response as proof that no request was sent

**Primary location:** `tools/ops/migrations/012_payment_operations.up.sql:40–45`

**Related paths:** `backend-yii2/application/PaymentOperations.php:25–42`, `:56–67`, `:162`; `backend-yii2/tests/payment-migration.php:40–45`

The migration only imports an authorization operation when the legacy payment is not `PENDING`, or has a saved gateway URL/transaction. However, the implementation at baseline `01d84b3` performs the provider POST **inside** its database transaction. An executed authorization followed by a lost response, process death, or failed result commit leaves exactly `PENDING / gateway_url=NULL / transaction_id=NULL` after rollback. This is indistinguishable from an untouched payment in the old schema.

Migration 012 skips that row. A subsequent confirmation therefore creates a new `READY` authorization and sends another POST. The stable key is unchanged, but the provider has no documented replay guarantee. This reintroduces the duplicate-effect risk precisely at upgrade. Alternatively, cancellation regards the payment as uninitiated and completes locally; subsequent capture callbacks are rejected as uninitiated despite the old provider effect.

**Reproduction:** executed the actual baseline `PaymentService` with a gateway double that recorded the authorization and then threw a lost-response error. Its transaction rolled back to the three legacy values above. Applied 012 and confirmed with the current service:

```text
before:                 [PENDING, null, null]
imported operation:     null
provider mutation calls: [authorize, authorize]
```

**Recommendation:** quarantine pre-existing remote `PENDING` payments unless there is independent evidence they were never sent. Absence of local response fields is not such evidence. Keep post-upgrade newly created, provably unsent payments on the normal `READY` path. Do not rely on the key or invent a provider lookup/replay guarantee.

**Regression:** extend `tests/payment-migration.php` with a provider-executed/baseline-rolled-back authorization before applying 012. After upgrade, confirmation, cancellation, and recovery must not submit another authorization or silently classify the remote effect as absent. Assert POST attempts, not merely one fixture journal row: the current provider fixture itself deduplicates effects by key. The existing `$unsent` fixture assumes, rather than proves, that the indistinguishable legacy row is unsent.

### R2 — High: a refund request blocked by another operation is acknowledged without retaining its intent

**Primary location:** `backend-yii2/application/PaymentOperations.php:46–52`

**Related locations:** `:25–29`, `:95–99`, `:218–225`, `:235–240`; `backend-yii2/application/PaymentService.php:21`

`reverse(..., 'refund')` only calls `queue()`. If another operation is live, `queue()` returns `null`. Unlike void/cancellation, refund does not persist a business-intent marker. The facade then returns the current payment DTO as a successful API response, with no durable record that a refund was requested.

A supported upgrade case makes this observable: a legacy `CAPTURED` payment with missing/duplicate correlation is imported with an `UNKNOWN` authorization. An administrator requests a refund while that operation is under review. Correctly, no competing HTTP request is sent. But after the operator verifies and reconciles the authorization as `CAPTURED`, neither reconciliation nor recovery schedules the refund: `compensate()` requires `cancel_requested_at`, which was never set. The customer's funds remain captured until somebody discovers the lost request and submits it again.

**Reproduction:** migrated a legacy captured payment with missing transaction correlation, requested refund, reconciled its authorization with independent capture evidence, and ran recovery:

```text
refund API reply status: CAPTURED
final payment status:    CAPTURED
cancellation intent:     null
refund operation:        null
provider calls:          []
```

**Recommendation:** persist the requested refund independently of whether a send can currently be queued. Reconciliation/recovery must materialize it exactly once when the blocking operation is resolved. Preserve the single-live-operation fence and the completed-order stock policy; do not solve this by allowing competing sends.

**Regression:** add a lifecycle test starting with a legacy captured payment and an uncertain authorization, request **only** an admin refund, then reconcile the authorization and recover. Assert one eventual refund, retained request intent across restart, no competing send while uncertain, and once-only stock handling. Include a completed order so intent persistence cannot accidentally cancel/restock completed purchases.

### R3 — High: an unsent void is still dispatched after capture makes refund the required operation

**Primary location:** `backend-yii2/application/PaymentOperations.php:111–120`

**Related locations:** `:95–99`, `:183–192`, `:211–215`; `backend-yii2/tests/payments.php:189–193`

The send claim checks only that the operation is `READY`; it does not revalidate the operation kind against the now-locked payment. A void can be durably queued, then remain unsent after a crash/claim failure. If a correlated `CAPTURED` callback wins before the claim, it correctly records capture, but the live `READY` void prevents a refund from being queued. Recovery then sends that obsolete void against the captured payment.

A provider can legitimately decline a void of captured funds. That converts a fully known **unsent** case into `UNKNOWN` and blocks the owed refund. The current reconciliation action only accepts `REVERSED` for a reversal; independently verifying that this void was declined does not provide a supported way to close it and release the refund. This is different from capture during an already-sent void, where retaining uncertainty is necessary.

**Reproduction:** failed the void claim before commit, retaining its committed `READY` intent; delivered a correlated capture; recovered with a gateway double that declined void of captured funds:

```text
void before capture/send: READY
provider calls:           [authorize, void]
void after recovery:      UNKNOWN
refund operation:         null
```

**Recommendation:** revalidate financial facts at claim time. If capture has superseded a provably unsent void, abort/supersede that void and persist the refund intent atomically under the aggregate lock. Continue treating `SENT`/`UNKNOWN` voids conservatively; never infer their remote outcome from capture alone.

**Regression:** add the missing boundary case alongside the existing “capture during void HTTP” test: pause after void-intent commit **before** send claim, deliver capture, restart/recover, and assert no void POST, exactly one refund POST, and once-only stock restoration. Exercise both customer cancellation and admin void origins.

### R4 — Medium: recovery can fence a late result and also discard the provider facts needed for reconciliation

**Primary location:** `backend-yii2/application/PaymentOperations.php:125–129`

**Related locations:** `:244`; `backend-yii2/tests/payments.php:231–235`

When a successful provider response returns after lease expiry, the application correctly refuses to apply it as an owned result. However, its diagnostic `UPDATE ... result=...` is restricted to `state='SENT'`. If recovery has already changed the operation to `UNKNOWN`, that update affects zero rows and the response is discarded entirely.

For authorization, this loses the returned transaction ID and structured outcome. Those are valuable reconciliation facts, especially when an early callback is quarantined and the provider has no lookup endpoint. Fencing stale **business application** should not erase an observed response. If reconciliation has meanwhile completed, a late observation must also not overwrite the operator's evidence.

**Reproduction:** expired the authorization lease and ran recovery before returning a valid structured authorization response to the actual dispatcher:

```text
state:      UNKNOWN
result:     null
last_error: worker lease expired; do not replay
```

**Recommendation:** retain late responses as audit observations associated with the original operation/token, even after recovery fences application. Keep them separate from authoritative operator evidence if necessary. Do not advance financial state, unblock another send, or replay solely because a stale response was observed.

**Regression:** strengthen the existing expired-lease test to assert that transaction ID, outcome, observation time, and original ownership remain durably inspectable while financial state stays unchanged. Also cover a late response arriving after manual reconciliation, retaining both the operator evidence and the late observation without overwriting either.

### R5 — Medium: the down migration has no safety gate before deleting outstanding send fences and callback evidence

**Primary location:** `tools/ops/migrations/012_payment_operations.down.sql:1–6`

**Related location:** `backend-yii2/tests/payment-migration.php:47`

The drain/reconcile requirement is only a comment. The down migration succeeds with live `READY`/`SENT`/`UNKNOWN` operations and unresolved callback inbox entries, deletes all operation/correlation/callback audit, and removes the cancellation/stock guards. Reapplying 012 cannot reconstruct the deleted facts. In particular, a sent authorization whose response was never applied becomes an apparently untouched pending payment and can be sent again.

**Reproduction:** created a `SENT` authorization plus an unresolved capture inbox entry and executed the actual down/up SQL. Both migrations succeeded:

```text
authorization after down/up: null
callback inbox count:        0
```

This is an operational rollback defect, rather than a normal request-path race, hence Medium. The written rollout warning is useful but does not make the provided rollback fail closed. Even a `SUCCEEDED` authorization with public `PENDING` status needs special care: the old application's confirmation path can resend it.

**Recommendation:** refuse downgrade while unresolved operations/callbacks or incompatible initiated pending payments exist; require a verified offline procedure for old-writer compatibility. Preserve/archive sent-operation evidence and correlations before removing their schema, or make rollback explicitly roll-forward-only where those facts cannot safely survive. A stock/cancellation fence must not be removed while writers can still use it.

**Regression:** replace row-count-only down/up confidence with tests that downgrade rejects each unsafe state without changing any data. Include an unresolved early capture, a sent-but-unapplied authorization, cancellation intent, and an initiated `PENDING` authorization. A permitted drained rehearsal must verify the agreed audit-preservation strategy, not merely a recreated number of rows.

## Specification compliance and implementation quality

| Area | Assessment |
| --- | --- |
| PHP business ownership and existing API | Preserved structurally. `PaymentService` remains the facade; user ownership and admin enforcement remain at the existing PHP boundary. No new public payment enum, monetary field, or webhook field was introduced. |
| Monetary representation | Preserved: existing `Money` integer-cent arithmetic, exact request `amountCents`, and string-valued DTO amounts remain in use. No float-based payment calculation was found. |
| Commit/send/apply and lock ownership | Strong core structure: committed intent, committed `SENT` claim, random ownership token/lease, HTTP outside the application transaction, and guarded result application. Explicit order → payment → operation locking and the one-live-operation index prevent competing ownership. Claim-time business revalidation and late-result audit retention remain incomplete (R3/R4). |
| Unknown outcome/no blind POST replay | Correct for newly recorded `SENT`/`UNKNOWN` operations: recovery escalates rather than resetting to `READY`. No invented lookup endpoint was added. Upgrade and downgrade bypass this protection (R1/R5). |
| Cancellation, expiry, reversal, stock | Common coordinator and persistent stock guard substantially improve the baseline. Expiry rechecks eligibility under the aggregate lock; completed-order stock remains consumed. Blocked refund intent and capture-before-void-send remain gaps (R2/R3). |
| Webhook correlation and early/late events | Explicit transaction ownership, order-payment linkage checks, early-event quarantine, post-lock duplicate checking, and atomic event/stock commits are sound design choices. Late capture compensation is retained rather than rejected. Early correlation still depends on preserving available response evidence (R4); unimported legacy sends undermine initiation checks (R1). |
| Manual reconciliation | Independent evidence, `UNKNOWN`-only transitions, and the instruction to quiesce stale senders are appropriate without provider lookup. Manual review itself is not a defect. The requested business effect must survive waiting, and observed facts must remain available (R2/R4). |
| Legacy/rollback safety | Not ready for signoff: R1 and R5. The documented need to audit historical terminal settlements remains legitimate; a terminal public status alone is not proof of provider reversal. |
| Shared recovery/deployment integration | Concurrent work acknowledged. Payment maintenance/recovery entry points are present. This review does not treat unfinished shared monitoring, runner, manifest, or deployment integration as independent findings. |

The implementation is a substantial improvement over the baseline and the tests use meaningful failure mechanisms: deferred commit failures, killed workers, real timeout behavior, signed HTTP callbacks, and database-backed stock guards. The remaining defects are concentrated at boundaries between durable financial facts and desired business intent. In particular, a silent `queue() -> null` conflates “an equivalent effect already exists” with “a different effect blocks a newly requested action.”

The existing reported green tests do not cover the five cases above. Their specific blind spots are the assumed-unsent legacy fixture, capture **during** rather than **before** void send, stale-result assertions that omit the saved result, no blocked admin-refund continuation, and down/up tests that assert counts rather than preservation/refusal. Keep explicit POST-attempt assertions: the fixture's one-effect-per-key behavior is stronger than the documented production contract.

## Verification performed for this review

| Check | Fresh result |
| --- | --- |
| Rollback-only isolated-schema harness using actual baseline/current classes and migrations, deterministic gateway double | **Five defect reproductions confirmed**, exit 0. This exit indicates successful reproduction, not payment-suite correctness. |
| Post-harness `BEGIN READ ONLY` database inspection | **0** leftover `review_payment_%` schemas; **573** payment rows, matching the pre-review count. |
| PHP 8.3 lint through read-only Docker mounts | **11 files**, no syntax errors, exit 0: six payment implementation/controller files and five payment test/fixture files. |
| `python3 scripts/route_matrix.py --check` | **54 exact operations/policies; eight screens/six commands match**, exit 0. |
| `git diff --check` | Exit 0 before report creation; repeated after report creation. |

The previously documented 99 lifecycle, four HTTP, two migration, and existing-suite results were read as historical workstream evidence, not represented as fresh executions by this review. The full shared backend/deployment verification was not rerun here.

### Reviewed core snapshot

Hashes identify the reviewed payment snapshot independently of concurrent shared integration changes:

```text
9c41d674a6c58b9c9fdf80ab6f86c14a348ad537e4a921af92cdbe577714fa40  backend-yii2/application/PaymentOperations.php
2383dbbe4a8b4835606a71463cbf899a944e7092deb40d02fc58af8b3fb74c4c  backend-yii2/application/PaymentService.php
970a2b52e07aa87b53f29f601c28bf97b955cb642f510cde2eb4e97284cb416d  backend-yii2/infrastructure/PaymentGateway.php
d134e6d0ac0af752e189ae63d4c3200c2007e4b5331bad545be5ffcde0fd3f06  backend-yii2/application/CommerceService.php
cb99ca1803f9ffa321823ffd693b211d533f57e16e6a26ba6920d21c970634bf  backend-yii2/application/MaintenanceService.php
b2aedfffa82e452eceb97dec1b25a053fcbb1c7c796a6d55e2bb13bcf18deab1  tools/ops/migrations/012_payment_operations.up.sql
0949afdeba9881bceeb738ad724563cac52d3cf13f46d838e2014ee77ce13a28  tools/ops/migrations/012_payment_operations.down.sql
```
