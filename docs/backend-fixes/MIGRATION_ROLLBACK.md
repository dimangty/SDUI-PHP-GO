# V6 migration, rollout and rollback

This is operator guidance, not evidence of a production cutover. The controlling
identity design is [round-5-local-auth/ADR.md](round-5-local-auth/ADR.md); actual
deployment-subset commands/status are in
[RUNTIME_RESULTS.md](round-5-local-auth/RUNTIME_RESULTS.md). Original 012–014
procedures remain byte-for-byte [historical evidence](local-auth/history/MIGRATION_ROLLBACK.pre-v6.md).

## Preserve data and make identity explicit

1. Retain the immutable prior release/config, verified business and retired
   identity backups, provider settlement evidence and existing audit. Stop old
   business writers and workers before migration; no mixed old/new auth writers.
2. Rehearse on a restored copy. Preserve every original migration byte and
   `schema_migrations` version/name/checksum/applied-at value. Apply only new
   migrations with the existing runner; Yii history stays separate.
3. Existing identities start unreviewed. Preserve UUIDs/business/device links;
   independently review effective roles/disabled state and execute individually
   ticketed console attestation/reset. Empty password hashes do not authenticate.
   No default passwords, email-based admin registration or bulk unblocking.
4. Resolve only explicitly selected historical password-session decisions through
   the reviewed local identity workflow. Do not delete audit rows or clear fences
   directly. Preserve unrelated disabled/recovery-blocked accounts.
5. Retired identity volumes are outside the new graph, physically retained. No
   volume prune, destructive teardown or automatic identity-data deletion.

## Production-aware stages

Keep exact edge/BFF/FPM peers, the dedicated FPM network, TLS/CA mounts and
provider webhook CIDRs. Use the production wrapper; never supply production
variables to the development/verification overlay.

```sh
make prod-config PROD_ENV_FILE=.env.prod
make prod-build PROD_ENV_FILE=.env.prod
make prod-drain PROD_ENV_FILE=.env.prod
make prod-infra PROD_ENV_FILE=.env.prod
make prod-migrate PROD_ENV_FILE=.env.prod
# Complete reviewed existing-account actions before exposing authenticated traffic.
make prod-reindex PROD_ENV_FILE=.env.prod
make prod-app PROD_ENV_FILE=.env.prod
make prod-workers PROD_ENV_FILE=.env.prod
make prod-edge PROD_ENV_FILE=.env.prod
```

`prod-infra` starts PostgreSQL and search. The retained `reindex` one-shot invokes
`maintenance/search-bootstrap` and verifies a complete built target; recurring
`reconcile` remains `maintenance/reindex`. General PHP readiness/startup does not
wait on search initialization. A search failure remains a search rollout failure,
even when general auth/payments are available. Do not advertise search readiness
from mere cluster health or an incomplete target.

## Payment/maintenance behavior stays bounded

| Service | Command | Cadence/default job budget |
| --- | --- | --- |
| maintenance | maintenance/run | 60s / 120s |
| reconcile | maintenance/reindex | 300s / 1800s |
| payment-recovery | maintenance/recover | 30s / 120s |

Retain independent workers, monotonic deadlines, SQL lock/statement/transaction
bounds, durable payment intents, crash leases, count-only diagnostics and
non-overlapping scheduled children. A worker is ready only after a successful
child, fresh heartbeat/success and an in-budget active job. Manual work can remain
even while a scan succeeds; alert on manual counts as well as worker readiness.

Never replay financial `SENT`/`UNKNOWN` work because a lease expired. Quiesce stale
senders and obtain independent provider evidence before trusted reconciliation.
Keep callback/observation/stock-restoration evidence and verified `NO_EFFECT`
manual-refund handling. See [PAYMENTS.md](PAYMENTS.md). Identity retirement does not
remove payment uncertainty or authorize invented provider lookups/retries.

## Roll-forward boundary

Prefer a compatible roll-forward image with schema/audit intact. Old binaries
cannot enforce the new local identity/review/session guarantees. Stop all new
writers before considering an immutable old release, and independently establish
schema/data and remote-financial-effect compatibility first. Restoring a backup
cannot undo provider effects or overwrite newer business writes silently.

No production rollback or account migration is established by small-fixture tests.
The existing 012 financial downgrade refusal and 013/014 pending-operation
protections remain unchanged. Do not rewrite migration history or run historical
down SQL to clear an identity cutover problem.
