# Shared backend migration, rollout and rollback

This is the integration procedure for **012–014**, layered on unchanged SQL
001–011. Commands in this document are operator instructions, not claims that a
production migration or rollback was executed. Actual disposable commands and
results are in [INTEGRATION_TEST_RESULTS.md](INTEGRATION_TEST_RESULTS.md).

## Schema and compatibility

| Migration | Effect | Deployment consequence |
| --- | --- | --- |
| 012_payment_operations | Durable payment intents/leases, transaction linkage, early-callback inbox, stock-restoration and cancellation markers | Drain old payment writers. Legacy missing/duplicate provider linkage becomes `UNKNOWN`, never an invented transaction or replayable send. Audit historical settlement separately. |
| 013_session_revocation | User security version/fence, credential-linked families, password-session operations, required family FKs | Deletes legacy access/refresh rows whose credential identity cannot be established. Force reauthentication. Old unlinked session writers are incompatible. |
| 014_recovery_schedule | Retry count/due time/crash lease, partial due index, operator ticket/confirmation timestamp | Install before enabling the new recovery worker. Existing pending operations retain their state/fence; no password or provider refresh value is persisted. |

Keep all 001–011 bytes and recorded version/name/checksum/applied-at values.
The coordinator adds **only new** manifest entries with the canonical generator
and runs the migration-manifest/parallel-apply checks before release. Do not reset
`schema_migrations`. Yii's separate `yii2_migration_history` remains separate.
Public payment enums, decimal money strings, route and SDUI schemas are retained.

The final F1–F3 correction wave is **application-only**: `NO_EFFECT` is retained
in the existing JSON audit/`ABORTED` state, lock-aware selection uses existing
014 columns, and deferred maintenance uses existing intents/stock guards. It
adds no migration and changes no normal stored checksum/history. Use a compatible
forward image that understands the manual no-effect audit; there is no SQL
downgrade step for this wave. Final rebuilt-source proof is tracked in
[FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md), separately from the older integration
snapshot and pending security A1 disposition.

## Staged rollout

1. Rehearse on a restored disposable copy. Retain verified business and identity
   backups, prior immutable release/configuration, provider settlement evidence,
   and the new outbox/recovery audit. Do not mix old/new writers during cutover.
2. Configure exact peers together: `EDGE_IP`, `SDUI_IP` and every allocated BFF
   address in `TRUSTED_BFF_IPS`; `YII_FPM_HTTP_IP` on a nonoverlapping
   `YII_FPM_SUBNET`; keep `YII_FPM_DYNAMIC_RANGE` within that subnet but excluding
   the fixed Nginx IP. Retain Go's `TRUSTED_EDGE_IPS`, the FPM admission environment
   and `/usr/local/etc/php-fpm.d/zz-integration.conf` mount in production.
   Provision Keycloak's effective user-manage permission for online logout and
   configured-client offline/consent revocation. Existing realm import does not
   update an already-created realm automatically.
3. Validate/build the intended production overlay and drain business writes and
   all three workers. Keep data/identity/search services available:

   ```sh
   make prod-config PROD_ENV_FILE=.env.prod
   make prod-build PROD_ENV_FILE=.env.prod
   make prod-drain PROD_ENV_FILE=.env.prod
   ```

   This drains edge first, then BFF/internal HTTP, `maintenance`, search
   `reconcile`, `payment-recovery`, and PHP. Quiesce any external job runners too.
4. Apply the independent SQL migration job, then Yii history, then reindex:

   ```sh
   make prod-migrate PROD_ENV_FILE=.env.prod
   make prod-reindex PROD_ENV_FILE=.env.prod
   ```

   The SQL job applies 001–014, each in its own transaction under the shared
   migration lock; already
   applied original versions are preserved. Verify versions 12, 13 and 14 and
   the expected constraints/indexes. Do not use a development overlay with
   production variables. Never run test providers/seeds on this database.
5. Start the new application and workers, wait for actual readiness, then edge:

   ```sh
   make prod-app PROD_ENV_FILE=.env.prod
   make prod-workers PROD_ENV_FILE=.env.prod
   make prod-edge PROD_ENV_FILE=.env.prod
   ```

   `/health/ready` requires the combined payment/session/recovery schema and
   healthy dependencies. Each scheduler requires a completed successful child,
   fresh success/heartbeat and an in-budget job. A live parent alone is not ready.
   Inspect `scheduler-health.php status` separately in all three workers.
6. Verify forced legacy-token login, PIN/bio family revocation, fenced password
   changes and local logout, real Keycloak online/offline cleanup, signed
   callbacks, original-client accounting and operator/manual alerts. Release
   traffic only after the coordinator's final security/TLS/compatibility gate.

## Jobs, retries and manual resolution

| Compose service | Command | Cadence / default runtime budget |
| --- | --- | --- |
| maintenance | maintenance/run | 60s / 120s; bounded retention first, local reservation expiry/intent only; no provider HTTP |
| reconcile | maintenance/reindex | 300s / 1800s; search only |
| payment-recovery | maintenance/recover | 30s / 120s; payment recovery plus bounded safe session revocation |

The dedicated recovery service is independent of search. PostgreSQL ownership,
advisory locks and durable claims coordinate replicas. Each combined scan admits
one actual due session attempt before one payment candidate, using monotonic deadlines;
reserves before HTTP; holds no transaction across HTTP; retries
at most five times with 30/60/120/240/480-second delays; and leaves a 120-second
lease after a crash. A fresh worker may retry safe user-based revocation only
after that reservation expires. Session-affine/direct PostgreSQL connections are
required for session-level advisory locks.

Session selection considers at most 25 due rows in index order, skipping advisory-busy live owners within one 1-second SQL statement and acquiring only the first available lock. Busy rows consume no attempt, reservation or fence change. The one-attempt allowance is unchanged; 25 busy heads defer later rows until a subsequent scan sees an available slot. Monitor prolonged busy owners rather than bypassing their locks. See [FINAL_FIX_REPORT.md](FINAL_FIX_REPORT.md) for the precise bounded look-ahead policy, which supersedes the older unrestricted rank-based fairness wording in RR1.

Maintenance commits one <=1,000-row slice for each of seven retention tables before considering at most 100 expiry candidates. Its own connection uses 1-second statements, 800ms lock waits and PostgreSQL-17 5-second transactions, restored afterward. A monotonic deadline reserves the next whole local transaction and exit headroom. `SCHEDULER_JOB_TIMEOUT_SECONDS` supports 40..86400 seconds for maintenance (default/blank 120); combined recovery still requires at least 90 seconds. Committed expiry intents wait for the dedicated recovery worker. Local SQL failure fails the scan; earlier committed retention slices survive. No SQL transaction spans HTTP.

`REVOKE_PENDING` is safe for automatic cleanup. `RESET_PENDING` never triggers an
automatic reset or fence release. Independently resolve the outcome, then:

```sh
php yii maintenance/session-recover "$OPERATION_UUID" independently-confirmed OPS-123
```

Use the trusted container console, not HTTP. Evidence is an uppercase ticket ID
such as `OPS-123`, not free-form provider text, passwords or tokens. First
confirmation/ticket is preserved. A failed manual cleanup stays durably pending;
automatic attempts remain bounded. Never clear `sessions_blocked` or delete jobs
to bypass uncertainty. Completed operations do not revoke fresh sessions again.

Recovery stdout is count-only. Alert on nonzero manual callback/payment/session
counts, persistent provider failures and overdue pending work **in addition to**
worker readiness. A scan can succeed while manual work remains. Payment
`maintenance/payments` exits 2 for manual review; the scheduled combined scan
exits 0 after a successful scan, and nonzero on unexpected infrastructure failure.
Use authorized SQL inspection for IDs/evidence; older payment-workstream docs
describe the predecessor's detailed `review` stdout, superseded by these counts.

```sql
SELECT id, user_id, state, recovery_attempts, next_retry_at,
       recovery_claim_until, last_error, operator_evidence_ref
FROM password_session_operations WHERE state <> 'COMPLETE'
ORDER BY next_retry_at, id;
SELECT id, payment_id, kind, state, lease_until, last_error
FROM payment_operations WHERE state IN ('READY', 'SENT', 'UNKNOWN')
   OR (state='ABORTED' AND kind='refund' AND result->>'outcome'='NO_EFFECT');
SELECT id, operation_id, payment_id, received_at
FROM payment_callback_inbox WHERE resolved_at IS NULL;
```

Never replay a payment `SENT`/`UNKNOWN` request merely because its lease expired.
Quiesce stale senders and obtain independent provider evidence before using
`maintenance/payment-reconcile`; see [PAYMENTS.md](PAYMENTS.md). There is no
invented provider lookup/replay guarantee.

Final independently verified reversal **no-effect** can be recorded only through the trusted `NO_EFFECT` reconciliation disposition, retaining send/observation evidence and the original transaction. A failed void can then release one owed refund. A failed refund stays manual and cannot be automatically replayed or relabelled as a successful reversal; follow the exclusive manual-settlement policy in [PAYMENTS.md](PAYMENTS.md). F1–F3 reuse the existing schema/`ABORTED` state: no migration, checksum or public payment-enum change is required.

## Rollback boundary

**Preferred failure response: drain traffic/workers and roll forward with the
durable schema/audit intact.** An older binary is not a safe automatic fallback:
it bypasses payment intents and cannot enforce credential families/fences.

The migration CLI only applies up migrations; there is no supported automatic
`migrate down` command. Down SQL is for a separately approved, rehearsed offline
schema/data rollback after all writers are stopped, backups/audit exported, and
external outcomes reconciled. Order is **014 → 013 → 012**, each in an explicit
transaction with stop-on-error. Preserve migration history consistently with the
rehearsed release; do not simply delete history to silence the next up check.

- **014 down** refuses any non-`COMPLETE` password-session operation. It removes
  retry/lease/operator evidence columns; export that audit first.
- **013 down** independently refuses pending password recovery. It deletes both
  token tables again and removes families/fences. Revoked/legacy tokens are never
  resurrected; reauthentication remains required. Re-enabling old auth code loses
  the new revocation guarantees and requires explicit release review.
- **012 down always refuses with SQLSTATE `0A000`.** Financial intents,
  callbacks, observations and stock/cancellation/refund fences must survive.
  There is no supported schema downgrade below 012, including a drained queue.
  Restore service using a compatible roll-forward release. A terminal public
  payment status alone does not establish that a remote effect completed.

A database restore also needs reconciliation for provider effects that happened
after the backup; a restore cannot undo remote money movement. Keep business and
identity rollback coordinated. Do not claim production rollback safety from a
small-fixture down/up test; its precise coverage is in the integration results.
