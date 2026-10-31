# Task 6: complete reads and explicit physical-index lifecycle

Status: implemented in the search-owned files; coordinator wiring and full
source-bound deployment acceptance remain required. Baseline `6f002de`.

## Read contract

`search()` only reads the selected physical index metadata and issues a metadata-
only search. It never creates, repairs, reindexes, changes mappings or switches
configuration. PostgreSQL batch hydration remains authoritative for prices, stock,
images, visibility and output DTOs, preserving canonical first-hit UUID rank,
full/card projection and exact upstream totals. A stale/inactive SQL row may still
shorten hydration: completeness of a search response is not a synchronous freshness
guarantee. Totals are not replaced by the number of hydrated items.

`SearchResponse` rejects HTTP/error envelopes, missing/non-false `timed_out`,
missing/inconsistent shard totals/successes, failed shards, invalid skipped/failure
metadata, missing/negative/noninteger totals, non-`eq` relation, malformed hit lists
and any present `terminated_early` other than boolean false. Absent early-
termination metadata is valid. Object-vs-list JSON distinctions are retained.
Search requests, initial inventory searches and the capability probe explicitly
send `allow_partial_search_results=false`; scroll continuations are validated too.
Public failures retain the fixed diagnostic and `SEARCH_UNAVAILABLE` / 503.

`ready()` is a **search capability** check: selected-index metadata plus a strict
size-zero search, under one READINESS_TIMEOUT budget. The coordinator must not make
local identity, payments, or SQL-only catalog capability depend on its success.

## Physical-index state and APIs

The existing `OPENSEARCH_PRODUCTS_INDEX` selection/default and index-name grammar
are retained. No alias migration, broker, dual-write system or SQL schema change.

`mappings._meta.shopkmp_products` stores version 1, a random build identifier and
state. Creation writes `building` in the same create-index request. Publication
adds `ready`, the physical UUID, verified document count and canonical identity
digest. This is metadata, never a sentinel product counted in search results.
JSON property order is irrelevant; marker value types remain strict.

- `bootstrap(int $limit=100000, int|float $seconds=900): array`: explicit initial
  build of the selected missing index. An already-ready index receives a bounded
  inventory and `created:false`; unmarked/building targets are refused, not adopted.
  This no-op branch does not claim a new SQL-vs-index identity verification.
- `stage(string $target, int $limit=100000, int|float $seconds=900): array`: fresh
  private physical name, different from the selected source, strict create-only,
  bounded active SQL IDs, SQL-derived documents, one explicit final refresh,
  complete inventory/count/digest verification, ready publication and read-back.
  Always `switched:false`; neither source nor target is deleted or selected.
- `reindex(int $limit=100000, int|float $seconds=900): int`: reconcile an already-
  ready selected index. Fixed initial UUID high watermark, <=100 IDs per SQL page,
  row and elapsed-time bounds, active PUTs and inactive DELETEs. It cannot create
  or publish a target. Over-bound work fails rather than reporting completion.
- `inventory(int $limit=100000, int|float $seconds=120): array`: read-only diagnosis
  of any existing target, including legacy/unready indices; an absent target is
  explicitly `exists:false`. Constant exact totals and final equality are required.
- `index(string $id): void`: selected target must already be verified ready;
  metadata is rechecked after writer admission. It cannot create/publish an index.

Builds require `exists:true` even for an empty SQL dataset, stable physical UUID
and build marker, canonical identity digest and zero invalid/noncanonical/duplicate
IDs. A failed/replaced/partial target cannot pass by looking like a healthy empty.
Missing/unmarked/building target reads and ordinary writes fail closed.

## Bounds and coordination

Document/row limit: 1..1,000,000. Work budget: >0..1,800 seconds, default 900 for
maintenance; keep it below the parent's scheduler deadline with cleanup headroom.
Normal single-document work uses 20 seconds. Public OpenSearch metadata+search
uses 5 seconds; existing SQL hydration limits/batch budgets remain unchanged.
HTTP calls receive the smaller of remaining work time and 5 seconds, with the
existing 8 MiB response ceiling. SQL statements get transaction-local remaining
time capped at 10 seconds. Category ancestry is cycle-checked and <=256 nodes.

Inventory uses 500-hit pages, a one-minute scroll lifetime, bounded canonical/raw
identity sets (raw keys are fixed-size hashes), immediate excess/repeated-document
rejection, and an explicit one-second best-effort clear in `finally`. Even a valid
scroll ID returned inside an error envelope is captured for cleanup. A cleanup
warning does not turn an incomplete scan into a successful one; surviving contexts
remain TTL bounded. SQL maintenance mutex cleanup uses a separate one-second SQL
budget. Driver connection/commit/rollback follow the existing Database policy;
these work budgets are not a claim of hard real-time OS scheduling.

One database-scoped session advisory mutex serializes maintenance commands. It is
acquired/released in short transactions with local settings. Live reconciliation
does not accumulate one transaction lock per product across an entire catalog.
On any failed maintenance/cleanup, exit nonzero and discard the Database/session;
never reconnect or replay automatically. A quarantined Database retains the
original error through the existing transaction policy.

Ordinary/reconcile product writes take a per-physical-index/canonical-UUID
**transaction-scoped** advisory lock before loading SQL, through the document
write, with bounded wait. `SET LOCAL` and transaction completion release limits and
locks even after failure, avoiding a failed cleanup leaking search-specific SQL
timeouts into a later login/payment request. These transactions contain search
reads and the search HTTP effect, not business SQL mutations or financial replay.

New builds own a transaction and obtain `NOWAIT` SHARE locks on categories,
products, product_images and reviews. Existing conflicting DML aborts admission;
new DML cannot change the verified SQL dataset during population/verification.
Private build writes omit per-document refresh waits and use one final refresh
before strict inventory; ordinary updates preserve `refresh=wait_for` visibility.

The production OpenSearch config disables implicit product-index creation with
`action.auto_create_index: "-shopkmp-products*,+*"`. This closes the HEAD/document-
PUT race after removal; explicit create-index operations remain allowed. The same
policy must be wired into development orchestration by its owner. Single-node
refresh totals include unassigned replicas; refresh validation checks success/no
failures, then inventory establishes strict search-primary completeness.

## Drain, cutover, recovery and rollback

Deploy canonical writers first. Reserve a maintenance window; drain ingress,
application writers and all workers/imports/admin/external SQL writers. Respect
existing payment settlement/recovery procedures. The SQL fence is a build-time
safeguard, **not** an online cutover protocol: it ends when the command completes.
Keep the operational write fence through verified target selection and recreation
of every PHP role with the same managed configuration.

Failed/terminated builds remain retained and unready for inspection. Do not resume
or adopt them implicitly; use a fresh target. No retention/deletion automation is
introduced. A lost ready-marker acknowledgement is ambiguous: read back the same
physical UUID and reverify under the fence before authorizing a config change.
Never interpret a nonzero exit as proof that OpenSearch made no change.

Before any SQL/catalog mutation resumes, a failed cutover can restore a retained,
compatible previously verified target. An unmarked legacy target is not compatible
with the new strict readiness contract. After **any** new SQL/catalog mutation,
drain again and build a fresh verified rollback target from current PostgreSQL.
Admin smoke writes and restarted maintenance/recovery workers cross that boundary
even while public ingress remains closed. Never blindly select a stale retained
index or roll back the canonical UUID/isActive fixes.

## Required coordinator wiring (outside this task's file ownership)

1. **MaintenanceController:** add `actionSearchBootstrap($limit=100000,
   $seconds=900)` constructing Database/ShopRepository/OpenSearch and printing
   `bootstrap()` JSON. Expose/pass bounds on existing reindex, search-stage and
   search-inventory actions; propagate exceptions/nonzero exits, never retry them.
   Defaults preserve existing callers, except reindex intentionally no longer
   initializes. Pass limit/seconds in the method order listed above.
2. **Startup/Make/Compose:** initial one-shot job calls
   `php yii maintenance/search-bootstrap` before serving search. Scheduled
   `maintenance/reindex` stays reconciliation-only. Preserve `prod-reindex` as an
   explicit reconciliation command if the service's default command becomes
   bootstrap. One-shot maintenance uses `--no-deps`; keep all writers drained.
3. **Core readiness:** catch/report optional search capability failure separately
   from core DB/local-auth readiness. Do not remove the existing SQL catalog
   `catalog-cards-v1` capability or gate login/payments when OpenSearch is absent,
   building, partial or unavailable. Query-backed search still returns its 503.
4. **Environment/orchestration:** preserve common `OPENSEARCH_PRODUCTS_INDEX` for
   all PHP roles, document it in examples, and apply the namespace auto-create
   restriction in development too (do not mount production TLS/security config
   into a development node). Verify resolved dev/prod/test models.
5. **Suite registration:** add `round5_search_protocol.php` to Composer unit/all
   checks. Run `round5_search_native.php` and `round5_search_regressions.php` as
   explicitly opted-in owned-disposable jobs with real PostgreSQL/OpenSearch.
   Workers/helper files are support code, not standalone unit entrypoints. The
   native opt-in is currently `ROUND5_SEARCH_DISPOSABLE=shopkmp-round5-e2e`;
   coordinate any CI ownership generalization instead of silently bypassing it.
6. **Final acceptance:** rebuild a source-bound PHP image containing all tasks,
   run the complete native suite/actual HTTP optional-search failure matrix,
   coordinated multi-role config cutover and both rollback branches. The focused
   bind-mounted baseline-image checks do not establish that final deployment gate.
