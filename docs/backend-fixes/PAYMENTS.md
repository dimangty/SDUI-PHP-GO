# Payments B1–B4

Implemented against baseline `01d84b3`. PHP owns payment decisions; PostgreSQL owns durable operation/callback state. `PaymentService` remains the HTTP-facing facade. `PaymentOperations` coordinates confirmation, cancellation, expiry, reversals, callbacks and recovery.

Review findings R1–R5 are addressed in the revised, **unreleased** migration 012 and application coordinator. See [PAYMENT_FIX_REPORT.md](PAYMENT_FIX_REPORT.md) for exact schema drift, RED/GREEN evidence and coordinator handoff.

The final coordinated F1–F3 wave adds the audited negative-reversal disposition
and bounded local-only maintenance described below, using the existing final
001–014 schema. The exact final source hashes, attributed review reproductions,
rebuilt 113-case payment suite and full-root result are recorded in
[FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md). These application corrections do not
authorize a new SQL downgrade or clear the separate image-security gate.

## Transaction and provider boundaries

1. Lock **order → payment → operation**, save a unique intent and stable `paymentId:authorize|void|refund` key, commit.
2. In a short transaction claim a `READY` operation as `SENT`, recording a random ownership token, send timestamp and 30-second lease; commit.
3. Perform the provider HTTP request with **no SQL transaction or row locks**. The existing HTTP client has a hard 10-second total deadline and no mutation retries.
4. Save the adapter-confirmed response in `payment_operation_observations` with the original operation/token and observation time, in an independent short autocommit write. Then re-lock the aggregate and apply only for the same `SENT` operation/ownership token and an unexpired lease. Callback decisions take precedence over stale authorization/void responses. Commit the authoritative result and any compensation intent together.

`payment_operations` has a unique `(payment_id, kind)`, a unique operation key, and a partial unique index allowing only one `READY`, `SENT` or `UNKNOWN` operation per payment. Different workers can claim only one effect. A capture during a void can queue a refund only after the void's send has finished; an ambiguous void blocks that refund for review.

The send claim also revalidates the locked financial state: capture supersedes a **`READY` (unsent) void**, atomically aborting it and retaining/queuing the required refund. No void POST is made in that case. This supersession never applies to `SENT`/`UNKNOWN` voids.

| Internal state | Meaning / restart behavior |
| --- | --- |
| `READY` | Committed intent, not claimed for send. Recovery dispatches automatically. Cancellation can abort an unsent authorization locally. |
| `SENT` | Send boundary committed. A worker may be sending or may have crashed before/after sending. Expired leases become `UNKNOWN`, never `READY`. |
| `UNKNOWN` | Provider outcome cannot safely be inferred. Blocks competing effects; operator reconciliation is required. |
| `SUCCEEDED` | Adapter-confirmed initiation/reversal or independently verified operator outcome. |
| `ABORTED` | Unsent authorization cancelled locally, unsent void superseded by capture, **or a sent reversal independently reconciled as final `NO_EFFECT`**. Inspect `sent_at` and structured `result.outcome`; this state alone does not prove an operation was unsent. No further request for this operation is permitted. |

An intent-commit failure sends nothing. A claim-commit failure leaves the prior `READY` intent recoverable. A result-commit failure leaves durable `SENT`; expiry escalates it, while the separately committed response observation remains available. Even the crash window between committing `SENT` and entering HTTP is conservatively uncertain.

A late response is retained even if recovery has already fenced the operation as `UNKNOWN`, or an operator has reconciled it as `SUCCEEDED`. Observations never overwrite `payment_operations.result`/operator evidence, bind a transaction, advance financial state, release another effect or justify replay by themselves. An empty adapter-confirmed reversal is recorded as JSON `null` with its original ownership token/time.

**There is no provider lookup endpoint and no documented production replay guarantee.** A stable idempotency key alone is not a reason to replay. Recovery never repeats `SENT`/`UNKNOWN` POSTs. It excludes blocked manual cases from its 1,000-payment dispatch batch so a large review backlog cannot starve unsent work. No provider GET/status endpoint has been added.

## Explicit adapter contract

The request paths, bearer authorization and integer `amountCents` remain unchanged.

- Authorize: HTTP 200/201 with a JSON object containing `status: AUTHORIZED|PENDING`, a nonempty string `transactionId` of at most 255 characters, and a string `paymentUrl` with a host and an allowed HTTP(S) scheme. Production requires HTTPS. URL credentials and fragments are rejected. If `paymentId`/`orderId` are supplied, they must be strings matching the request. HTTP 202 is accepted **only as a structured `PENDING` initiation**, not as completed funds movement.
- Reversal: **only an empty HTTP 204** confirms this adapter's synchronous void/refund contract. HTTP 202 `PENDING`, arbitrary 200/201 bodies, malformed JSON, 4xx/5xx and transport/deadline failures remain uncertain. They never mark the payment `CANCELLED`/`REFUNDED` as a successful reversal.
- A transaction already linked to another payment makes the authorization operation `UNKNOWN`; it cannot overwrite the existing linkage or expose the foreign redirect.

The nonproduction demo adapter remains local. Its capability-protected demo form can bind its synthetic transaction on first callback. This exception requires both nonproduction mode and an empty gateway URL; external-provider callbacks require verified transaction linkage.

## Cancellation, expiry and stock

Cancellation records `cancel_requested_at`, cancels the local order and restores its reservation once. Remote work may finish later. Thus a cancelled order can temporarily retain payment status `PENDING`, `AUTHORIZED` or `CAPTURED`; these describe unresolved financial state, not a confirmed reversal. An uncertain synchronous attempt retains the inherited `PAYMENT_GATEWAY_UNAVAILABLE` HTTP 400. Confirmation after cancellation intent is rejected, including when the old redirect still exists in storage.

An admin refund independently persists `refund_requested_at` **before** trying to queue a send. If another operation is live/uncertain, that request survives caller/worker restart and is materialized exactly once after reconciliation unblocks it. It does not depend on customer cancellation. A completed purchase remains completed and consumes its stock; an ordinary admin refund applies local cancellation/restoration only when the refund is confirmed.

| Payment situation | Remote action |
| --- | --- |
| Post-upgrade CARD/SBP `PENDING`, no send initiated | Local cancellation; abort a `READY` authorization. |
| Pre-upgrade external `PENDING`, including null URL/transaction | Quarantine as `UNKNOWN`; absence of saved response fields does not prove no POST was made. Local order cancellation retains unresolved financial state and waits for reconciliation. |
| CARD/SBP initiated `PENDING` / `AUTHORIZED` | Durable void intent, or wait behind the active authorization/uncertain operation. |
| CARD/SBP `CAPTURED` | Durable refund intent, or wait for the active uncertain operation to be reconciled. |
| `ON_DELIVERY` | Local authorization/cancellation/cash refund state only; no gateway call or remote transaction. |
| Late capture after cancellation or `FAILED` | Keep the capture, retain cancellation, queue one compensation refund; if another effect is uncertain, expose it for manual review. |

Expiry uses the same lifecycle, rechecking age/status under the aggregate lock. It applies only to `CREATED` reservations in `PENDING`/`AUTHORIZED`; paid, failed, refunded, cancelled and completed orders are not newly expired.

Scheduled expiry is **local-only**: `maintenance/run` commits cancellation, once-only stock restoration and any reversal intent, then leaves provider dispatch to `payment-recovery`. Internal application callers of `PaymentOperations::expire($order,$pending,$authorized)` keep synchronous dispatch by default; maintenance explicitly supplies the fourth argument `false`.

`orders.stock_restored_at` is a persistent guard updated in the same transaction as sorted product updates. Callback/reversal/cancellation/expiry races cannot restore twice, and a stock-update failure rolls back the guard and webhook marker. **Completed orders retain their consumed stock and `COMPLETED` status on refund**, matching existing policy.

## Webhooks and early callbacks

The existing HTTP boundary still checks source CIDRs, timestamp age and HMAC-SHA256 over `timestamp:webhookId:rawBody`. Normal success remains HTTP **204**. A processed duplicate remains HTTP **409 `ALREADY_PROCESSED`**, including duplicates that waited for another worker's order lock. No new public route or webhook JSON field is required.

`payment_transaction_links` binds a provider transaction to exactly one initiated payment. The aggregate check also verifies the payment is still the order's linked payment. Both real initiated `PENDING` and `AUTHORIZED` payments accept correlated `CAPTURED`/`FAILED` events. Uninitiated, unknown and foreign transactions cannot advance financial state.

If a valid signed callback arrives **before the authorization response has established its transaction ID**, it is saved in `payment_callback_inbox` against the durable sent authorization, but returns HTTP **400 `INVALID_TRANSACTION_ID`**. It is not trusted merely because it names an initiated payment. The response-apply transaction drains only inbox events whose transaction matches its verified provider result. Their processed-webhook marker and business effects commit together. A subsequent retry receives `ALREADY_PROCESSED`.

If that response is lost, the callback remains quarantined and the operation remains uncertain. Independently verified operator correlation can drain it; otherwise there is no automatic financial transition. Unmatched inbox entries are retained for investigation:

```sql
SELECT i.*, op.state, op.last_error
FROM payment_callback_inbox i
JOIN payment_operations op ON op.id=i.operation_id
WHERE i.resolved_at IS NULL
ORDER BY i.received_at;
```

## Recovery and operator reconciliation

`maintenance/run` first commits up to 1,000 expired rows per retention table, then expires at most 100 reservations locally, subject to its monotonic admission deadline. It makes **no provider calls and no trailing payment-recovery pass**. The dedicated `RecoveryService`/`maintenance/recover` scheduler dispatches safe payment work and reports payment/callback/session counts. Keep that worker enabled for queued compensation to progress. See [FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md) for F1–F3 bounds and verification.

```sh
php yii maintenance/payments
```

This command runs recovery and prints count-only JSON with `dispatched` and `manual`. Exit **0** means no manual payment cases; exit **2** means manual review exists. Manual cases include `UNKNOWN` operations and verified `NO_EFFECT` refunds (`ABORTED`), which still need external resolution. Internal `PaymentOperations::recover()` retains `{dispatched, review}`; review rows include cancellation/refund intent and financial context. Public console summaries do not print those sensitive rows. `maintenance/run` retains its expired-order-count contract.

For manual reconciliation, first **quiesce/terminate stale senders and establish the final provider facts** using the provider portal, settlement export or support. Lease expiry fences local result application; it does not prove that a paused process or a remote request has finished. Do not manually unblock an operation while an old sender could still issue/complete its request.

```sh
# Authorization: verified provider transaction and current outcome.
php yii maintenance/payment-reconcile "$OPERATION_UUID" PENDING "$TRANSACTION_ID" \
  "PAY-123: provider export verified; old sender terminated"

# Void/refund: verified successful reversal of the original transaction.
php yii maintenance/payment-reconcile "$OPERATION_UUID" REVERSED "$TRANSACTION_ID" \
  "PAY-124: provider settlement confirms reversal"

# Sent void/refund: independently verified FINAL absence of that reversal's effect.
# The evidence ticket must establish both final provider facts and sender quiescence.
php yii maintenance/payment-reconcile "$OPERATION_UUID" NO_EFFECT "$TRANSACTION_ID" \
  "PAY-125: final no-effect confirmed by settlement/support; old sender terminated"

php yii maintenance/payments
```

The console-only reconcile action accepts only `UNKNOWN` operations and requires nonempty evidence (stored in `result` with outcome, transaction and timestamp). Authorization outcomes are `AUTHORIZED`, `PENDING`, `CAPTURED`, `FAILED`; `NO_EFFECT` is **not** an authorization `FAILED` outcome. Reversals require `REVERSED` or `NO_EFFECT` and the original, still-owned transaction. `NO_EFFECT` additionally requires a recorded send. Foreign links and active/completed/aborted operations are rejected. Reconciliation itself sends no HTTP; the next recovery dispatches any newly unblocked compensation.

**`NO_EFFECT` is an explicit operator attestation, never an inference from HTTP 4xx, timeout, missing response/observation, or an expired lease.** Independently confirm that the original reversal has finally failed without effect, that it cannot later settle, and that every original sender/request is quiesced. If any of these facts is unconfirmed, leave the operation `UNKNOWN`. Evidence must identify the authorized operator's ticket and the verified final facts; do not store secrets or arbitrary raw provider responses. Console access is the trust boundary; neither an HTTP request nor automatic recovery supplies this disposition.

For a verified no-effect **void**, reconciliation atomically sets the operation to `ABORTED` with `result.outcome=NO_EFFECT` and materializes an owed refund if capture/cancellation or refund intent already requires one. It leaves the captured payment unchanged until a refund actually succeeds. The old key, send timestamp/token/lease and observations survive. Commit failure rolls back both the disposition and new intent; repeats are rejected and never send. A later capture can also materialize the unique refund. Stock remains once-only; completed purchases retain consumed stock.

For a verified no-effect **refund**, the original refund becomes `ABORTED`, while the payment remains `CAPTURED` and the refund request remains recorded. The unique refund kind/key is retained: automatic recovery, repeated admin refund and direct dispatch cannot issue another refund for it. Both payment review and `paymentsManual` continue to flag this case. Do **not** mark that unsuccessful refund `REVERSED`, reset/delete it, or reuse its key to make another mutation.

Resolve such a refund through an authorized, exclusive **manual settlement case** with the provider/accounting team. Before any separate provider-side refund/adjustment, independently establish the original final no-effect fact and check that no other compensation has occurred or is pending; retain the original operation and ticket. Record any separately authorized manual effect under its own provider reference and evidence. If that manual effect's outcome is uncertain, stop and investigate settlement; never blindly repeat it. This application has no command to relabel the failed original operation as that separate manual effect. Its `CAPTURED`/manual alert remains until a compatible, separately reviewed forward reconciliation records the distinct settlement; the original no-effect audit must survive. This is manual-only resolution, not an automatic retry path or a provider replay guarantee.

Available response observations can support independent investigation; they are not automatically authoritative:

```sql
SELECT op.id, op.state, op.result AS authoritative_result,
       observation.lease_token, observation.observed_at,
       observation.result AS provider_observation
FROM payment_operations op
JOIN payment_operation_observations observation ON observation.operation_id=op.id
WHERE op.id = :operation_id
ORDER BY observation.observed_at, observation.id;
```

If the provider cannot establish an outcome or transaction, keep the operation blocked and escalate externally. There is deliberately no reset-to-`READY`, forced POST retry, or invented “not received” guarantee. Already-sent audit rows and unmatched callbacks are not removed by ordinary idempotency-key retention.

## Migration and integration

Apply `012_payment_operations.up.sql` before this application code, with old payment writers drained. The migration preserves public statuses/money values, marks historical cancelled reservations as already restored, imports unambiguous transaction links, and marks legacy missing/duplicate transaction correlation `UNKNOWN`. **Every pre-existing external `PENDING` payment is quarantined**, including null URL/transaction and saved-correlation cases; this schema has no independent proof of an unsent legacy request. The baseline could execute HTTP then roll back all response fields. Newly created post-upgrade payments retain the normal unsent path. The migration does not fabricate evidence that historical voids/refunds occurred. Historical terminal payments affected by the old bugs still need provider/settlement audit.

**012 is roll-forward-only.** Its down SQL unconditionally raises SQLSTATE `0A000` before any DDL/DML, including for an empty or fully settled database. There is no supported down/up rehearsal or downgrade to old unsafe payment writers. Send/callback/observation audit, correlations and cancellation/refund/stock guards must survive. A rollback requires a compatible forward fix, not removal of those fences.

Migration 012 remains unreleased and its bytes/schema changed during review. Recreate disposable databases/images built with the old 012, apply 001–014 through the real migrator and refresh the coordinator-owned checksums. Do not forge `schema_migrations` history or change 001–011. Shared integration is already in place; the precise rebuild/readiness handoff is in [PAYMENT_FIX_REPORT.md](PAYMENT_FIX_REPORT.md), with fresh command results in [PAYMENT_TEST_RESULTS.md](PAYMENT_TEST_RESULTS.md).
