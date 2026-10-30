# Round 4 — controlled rollout and rollback

Publication remains gated by `FINAL_REVIEW.md`. These are operator procedures,
not a record of a production deployment. No database migration is required.

## Catalog index identities

1. Deploy the canonical identity fix to **all** PHP writers/reconcilers. The
   default `OPENSEARCH_PRODUCTS_INDEX=shopkmp-products` preserves existing routing.
   Existing upper-case documents are still present: a new canonical PUT alone
   neither removes their duplicate hits nor repairs `totalItems`.
2. Run `php yii maintenance/search-inventory` in the normal protected CLI
   environment. This does not create/change an index. It reports exact document,
   noncanonical, duplicate-by-value and invalid counts plus the sorted canonical
   identity SHA256; no document bodies, credentials or raw product IDs are printed.
   The default bound is 100,000 documents; an explicit limit may be at most
   1,000,000. Exceeding a limit, incomplete totals, timeout or shard failure aborts
   the inventory. A scroll context is bounded and cleared.
3. Reserve a maintenance window. Drain ingress, FPM/business writers and **all**
   workers, including reconcile, maintenance and payment recovery, using the
   existing `make prod-drain`. Stop independently managed imports/admin writers
   too. Wait for their in-flight work and provider callbacks to settle under the
   existing payment/recovery procedures. Do not interrupt/automatically replay a
   payment or interpret a lost commit confirmation as rollback. Keep PostgreSQL,
   Keycloak and OpenSearch running. Ensure no external SQL/catalog writer can
   change products, categories, images or ratings during staging and cutover.
   This operational fence is required: the CLI does not claim to lock out writers.
4. Choose a fresh private name, for example `shopkmp-products-r4-20261030`.
   Run `php yii maintenance/search-stage shopkmp-products-r4-20261030` from a
   one-shot PHP container (`--no-deps`), with the same canonical PostgreSQL and
   search credentials/configuration. It refuses existing targets, indexes only
   the bounded active SQL ID set, and verifies exact active count/identity digest
   and zero invalid/noncanonical/duplicate IDs. Prices, stock and category paths
   are reconstructed from SQL, never copied from stale search `_source`.
   A failed/partial stage is left for inspection; rerun with another fresh name.
   Neither the live nor staged index is deleted or switched by this command.
5. With writers still drained, set `OPENSEARCH_PRODUCTS_INDEX` to the verified
   target in the managed backend environment; recreate PHP application/worker
   containers so every reader/writer uses that same target. Use the existing
   `prod-app`, `prod-workers`, readiness and trust verification procedures. Keep
   public ingress closed while checking lower/upper/mixed route updates,
   category terms, full/card pages, rank/sorts, page/size/totals, inactive/create
   visibility and deactivation/delete. Compare totals to the active SQL dataset.
   Run inventory on the selected target again. Resume ingress only after all
   checks pass, then retain the old index through the agreed observation window.

This uses a staged physical index and coordinated configuration cutover, rather
than deleting the legacy concrete `shopkmp-products` index to obtain an alias of
the same name. It requires a write-drain window; no broker, dual-write protocol,
database reconstruction or arbitrary document-ID deletion is introduced.

### Index rollback

Before cutover, leave the live configuration/index unchanged. Before reopening
writers, a failed cutover can restore the previous configuration with the old
index retained. After new business writes, **never switch blindly to the retained
stale index**. Drain again and build a fresh verified rollback target from current
PostgreSQL using the canonical implementation, then point every PHP role at that
target. This preserves current data and gives the same count/digest checks.
Do not roll back to the known UUID/isActive defects as an operational shortcut.
No automatic deletion/retention cleanup of production search indices is shipped.

## Transaction failures

Work runs once. Failed cleanup or an ambiguous commit quarantines that Database
instance; later calls rethrow the original throwable rather than silently
reconnecting with lost session settings/locks. Start an explicitly fresh Database
for subsequent independent work. Consult durable payment intents/observations and
the existing recovery workflow before deciding any remote effect's disposition.
Definite PostgreSQL commit rejections (validated SQLSTATE 23xxx, 40xxx except
40003, or P0001) retain the session only when state/cleanup succeeds. They still
throw the original exception and never trigger closure replay.

## Tracing and dependency rollback

`sdui_trace_export_failures_total` is an unlabeled local counter of failed export
batches/shutdown calls, not a count of spans. It is zero when tracing is off and
available only on the existing operational listener. Alert outside readiness;
collector outage must not gate business availability. Queue/drop gauges are not
invented from private SDK fields. Disable `SDUI_TRACE_ENABLED` for export rollback.
The grpc update is isolated in go.mod/go.sum; any dependency rollback must retain
Go/security floors and rerun tidy/verify, contracts, OTel/race and advisory checks.

## Disposable verification environment

Only `shopkmp-round4-e2e` resources created in this run may be removed. Its private
test ranges are ingress `172.30.191.0/24` (.2 edge/.3 Yii HTTP/.4 SDUI), FPM
`172.30.190.0/24` (.3 proxy, dynamic `.128/25`), and payment fixtures
`10.245.194.0/24` (.2 provider/.3 HTTP/.4 CLI); edge port 58341. Set matching
`EDGE_*`, `YII_HTTP_IP`, `SDUI_IP`, `TRUSTED_BFF_IPS`, `YII_FPM_*` and
`PAYMENT_TEST_*` variables when reproducing. Existing `shopkmp-migrated` services,
volumes and networks are unrelated and must remain untouched.
