# Round 3 — real-stack payload and performance evidence

## Execution plan and scope

Spec: `ARCHITECTURE_ADR.md` and the focused F1/F2/F3 real-stack brief.
Execution is inline in the current checkout, without source changes or Git publication.

- [x] Create `backend-yii2/tests/round3_edge_fixture.php`: explicit disposable
  opt-in, deterministic run-scoped synthetic users/category/products/credentials,
  opaque sessions returned only to the test process, addressed cleanup.
- [x] Create `backend-sdui/internal/bff/round3_live_test.go`: real edge HTTP,
  full/card/SDUI byte receipts, exact product identities/totals/Unicode/detail,
  independent JSON Schema and CMP wire checks, mixed legacy-device evidence,
  owner/foreign query revocation and rejected refresh families. Unset env skips.
- [x] Create `backend-yii2/tests/round3_performance.php` and
  `scripts/backend_round3_performance.py`: original-image/current-mounted-source
  comparison on one isolated PG dataset, 5 warmups + 30 measured iterations,
  actual PDO executes and execution elapsed, wall p50/p95, full-output equality,
  representative actual-query EXPLAIN ANALYZE/BUFFERS JSON, finally drop only the
  uniquely named benchmark database.
- [x] Record command receipts using `scripts/backend_check.py`, finish independent
  cases after failures, document measured results and exact repeat commands here.

Review focus: missing/duplicated rows and totals; valid 10,000-code-point UTF-8
descriptions; 2,048-byte valid images; legacy dot IDs without invalid actions;
owner-scoped cleanup/revocation; timing/count separation; baseline/current data
identity; no token/password/real PII in receipts. No E2E lock-proof claim.

## Results and evidence index

Executed **2026-10-29**, exclusively in project `shopkmp-round3-final-e2e`.
Focused edge and performance checks pass. Final rebuild, combined integration,
security and source/artifact alignment remain the coordinating main task's gates.

All paths below are relative to this directory. Each command label has a
`evidence/<label>.json` receipt (exact argv/cwd/UTC times/raw exit) and `.log`.

| Label / artifacts | Actual result |
|---|---|
| `edge-live-unset` | Exit 0, explicit **SKIP**, no fixture execution |
| `edge-live-initial` | Exit 0, 24 subtests; initial absolute-URL fixture was PHP-valid but CMP intentionally filtered its images |
| `edge-live-final` | Exit 0, **24/24 subtests**, real edge, CMP-safe ordinary and 2,048-byte media paths preserved |
| `edge-payloads-final/edge-*.json` | Exact captured business/card/SDUI HTTP bodies, plus `edge-manifest.json` with 88 request receipts, body bytes, safe hashes and successful cleanup |
| `perf-comparison-initial` | Exit 0; first complete same-data timing/count/output comparison, retained at `evidence/perf-*-{samples,plans,outputs}.json` |
| `perf-comparison-final` | Exit 0; **960 measured service calls**, 5 warmups per case, 12/12 full-output comparisons equal, isolated DB dropped |
| `perf-final/perf-*-samples.json` | Every raw timing/count/memory sample, PHP/PG version and native-prepare assertion |
| `perf-final/perf-*-outputs.json` | Full original/current public DTOs, including all descriptions, images and order snapshots |
| `perf-final/perf-*-plans.json` | Actual SQL and bindings captured from PDO, native `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` results |
| `perf-final/perf-lifecycle.json`, `perf-summary.json` | Per-command exits, fixture lifecycle, image IDs, mounted-source hashes, hardware, summary metrics |
| `perf-plan-analysis` | Exit 0, compact 100-row representative-plan analysis |
| `edge-perf-php-lint`, `edge-go-vet`, `edge-go-format` | Exit 0, two PHP tools syntax-valid; focused Go vet clean; gofmt output empty |
| `edge-perf-toolchains` | Exit 0, Go **1.27.3 darwin/arm64** |
| `perf-python-toolchain` | Exit 0, Python **3.14.3** |
| `edge-perf-fixture-optin` | Exit 0 wrapper; both PHP tools reject missing opt-in with exit 2, network disabled |
| `edge-perf-evidence-audit` | Exit 0; verifies 88 edge receipts, both cleanups, 12 output comparisons, 960 measured calls / 160 warmups; no opaque token values or password-bearing PostgreSQL URLs detected in the 189 scoped evidence files scanned |
| `perf-optin-unset` → `perf-optin-unset-fixed` | Initial runner indentation error (exit 1) corrected; repeat verifies expected runner rejection exit 2, wrapper exit 0. No DB operation occurred in either negative attempt. |

### Toolchains and hardware

- Both PHP variants: **PHP 8.3.36, aarch64**, native PDO PostgreSQL prepared
  statements (`ATTR_EMULATE_PREPARES=false`). PostgreSQL **17.11**.
- Docker Desktop **29.6.1**, LinuxKit **6.12.76**, aarch64, **14 virtual CPUs**,
  **8,320,954,368 bytes** VM memory. Host: macOS 26.1 arm64. Python runner:
  host **Python 3.14.3**; Go's selected module toolchain is recorded above.
- Original application image `shopkmp-round3-php`:
  `sha256:7cd01f175f75b0213c74aca42840c282c2598f76f1a2042dd685293a02b65b83`.
- Current PHP image providing the runtime/dependencies:
  `sha256:0226bb887df71039c2db346fa54218a616e196065e90f155b5ed94c847cae675`.
  The benchmark mounts **current checkout PHP source read-only** over `/app`,
  retaining image-installed `/app/vendor` and a disposable `/app/runtime` volume.
  Thus the current benchmark includes the strict projection follow-up despite
  the running application image predating that follow-up. Source SHA256 values
  are in the lifecycle receipt. The original benchmark mounts only the new tool.

## F1 — real Nginx → Go → Yii2 → PostgreSQL payloads

The edge fixture creates one fresh category and **100 distinct UUID products**,
all active, stock 7, exact price **50.01**, each description exactly **10,000
emoji / 40,000 UTF-8 bytes**, one ordinary `/images/products/round3.png` image.
The same populated category is read at sizes 1/20/50/100, using `priceMin=50.01`,
`priceMax=50.01` and both `price_asc` / `price_desc`. All prices are intentionally
equal, so the expected tie-breaker is ascending UUID in both sorts.

Actual **complete identity-encoded HTTP entity-body bytes**, excluding HTTP
headers, framing and TLS, from `edge-live-final`:

| Visible products (total 100) | Full Yii public list | Projected Yii list | SDUI ASC | SDUI DESC |
|---:|---:|---:|---:|---:|
| 1 | 40,344 | 1,432 | 7,041 | 7,047 |
| 20 | 805,682 | 26,910 | 36,382 | 36,388 |
| 50 | 2,014,112 | 67,140 | 82,702 | 82,708 |
| 100 | **4,028,163** | **134,191** | **159,540** | **159,545** |

The 100-item full public response really exceeds the unchanged Go **2 MiB**
upstream default. Its projected counterpart and every resulting SDUI document
fit their existing limits. Every expected product UUID, order, total, page count,
money string, raw snippet/completeness and first image is checked; no false empty
fallback or truncated product collection is accepted.

Independent maximum valid image case: the fixture replaces that sole image with
a **2,048-byte CMP-accepted product path**, leaving all 100 products and their
10,000 emoji unchanged. Actual bytes: **4,230,263 full → 336,291 projected →
361,645 SDUI**. The test asserts the image survives exactly in SDUI as well as
full/projected Yii output. Relative product paths are intentional: absolute URLs
are filtered by the existing CMP media policy. No image network download or
mobile rendering performance is claimed.

Additional executed cases:

- Last page `size=33&page=3`: one exact final UUID, total 100, four pages.
- Past end `size=20&page=5`: zero rows, total 100, five pages, HTTP 200.
- Empty price filter `priceMin=50.02`: zero rows/totals/pages through full,
  projected and SDUI HTTP, HTTP 200.
- Public full detail: **40,280 bytes**, description unchanged at 10,000 emoji and
  full image list. SDUI detail **42,022 bytes**; concatenated description text
  nodes equal the complete original description, without dropped code points.
- ASCII 256/257, Cyrillic 257, four-byte Unicode 257/10,000: PHP raw snippet is
  exactly the first 257 code points with correct completeness; Go preview is
  unchanged at 256 code points, using first 255 plus `…` when truncated.
- Every SDUI response passes the independent embedded JSON Schema compiler plus
  independent UTF-8, 1 MiB body, 500-node/action, 16-depth, 8,192-code-point text,
  preview, media-path and action-reference checks. Device addressability is also
  checked independently. This is wire-consumer validation, not a mobile run.

Reads use a stable, valid opaque PIN-linked session belonging to the synthetic
owner. Rate limits/security configuration are not changed. Individual edge
request elapsed times are recorded for diagnostics only, not used as p50/p95
benchmarks. This focused fixture does not index OpenSearch or measure search.

## F2 — mixed legacy devices and address-safe revocation

The same synthetic owner has an ordinary device plus **`.` and `..` in each of
PIN and biometric credentials**, six records total. Each legacy credential has
two separately issued linked session families. `GET /api/v1/screens/devices`
returns **HTTP 200**, **4,285 bytes**, **16 nodes / 3 actions**, valid schema/CMP
wire constraints. All four legacy rows retain their kind/name/ID and the Russian
explanation directing revocation/remediation through account, API or operator.
Neither legacy ID has a `RevokeDevice`; both ordinary-kind actions remain.

For each of the four kind/ID tuples, through the real edge:

1. Foreign user's `DELETE /api/v1/auth/{pin|biometric}?deviceId={.|..}` returns
   **404**; both owner-linked access sessions still authenticate afterward.
2. Owner's same address-safe query returns **204**.
3. Both linked refresh tokens then return **401 INVALID_REFRESH_TOKEN**, and
   both linked access sessions return 401.
4. The unrelated owner PIN session and foreign session remain valid (HTTP 200).

These sequential E2E cases demonstrate ownership/addressability and family
revocation effects. They **do not prove concurrent locking**; the existing
focused native session-concurrency suite owns that evidence.

## F3 — original versus current on the same native PG data

The runner creates `round3_perf_<16 random hex>` only inside this project's
PostgreSQL container, migrates it using the **existing checksum-validating ops
migration image**, then runs current additive Yii migrations. It never drops or
clears the live `shopkmp` DB. The final run used
`round3_perf_1221c7bc91e3ea41`, successfully dropped in `finally`.

One populated dataset is shared by both variants: four categories/users for
0/1/20/100 cases, **121 products, 363 images, 121 completed orders, 242 order
items, 121 captured payments**. Products retain 10,000 emoji and price 50.01;
three ordered images include an equal-sort-order tie. Orders have two complete
snapshot items, exact money and immutable address/comment. Only the selected
case's products are active during a measurement so popular has exactly the same
0/1/20/100 input set. Both variants see the same IDs/timestamps/payment rows.
Full catalog, orders and popular outputs are deeply equal in **all 12 cases**;
each iteration also checks its output hash outside the timed call.

Each operation/size/variant has **5 warmups + 30 measured iterations**. Measured
operations are real `CatalogService::products`, `CommerceService::orders` and
`CatalogService::popular`, not modeled calls. Current card variants are measured
separately; there is no fictitious original card implementation.

### Actual native PDOStatement executions per service call

Every measured sample counts `PDOStatement::execute()`; setup/migration/EXPLAIN
and output serialization are outside these counts. Counts were identical across
all 30 samples of each case.

| Items | Catalog original/current full | Orders original/current | Popular original/current full | Current catalog/popular card |
|---:|---:|---:|---:|---:|
| 0 | 2 / 2 | 2 / 2 | 1 / 1 | 2 / 1 |
| 1 | 3 / 3 | 4 / 3 | 3 / 3 | 3 / 3 |
| 20 | 22 / 3 | 42 / 3 | 41 / 3 | 3 / 3 |
| 100 | **102 / 3** | **202 / 3** | **201 / 3** | **3 / 3** |

### Service-call wall latency (milliseconds)

Timed from immediately before the service call until it returns, including
prepare/execute/fetch/mapping. Excludes container/process startup, migrations,
fixtures, warmups, EXPLAIN and JSON serialization/hash verification.

| Operation | Items | Original p50 | Original p95 | Current p50 | Current p95 |
|---|---:|---:|---:|---:|---:|
| Catalog full | 0 | 1.581 | 3.421 | 1.308 | 3.440 |
| Catalog full | 1 | 2.043 | 4.527 | 1.402 | 3.489 |
| Catalog full | 20 | 8.313 | 13.765 | 2.729 | 4.626 |
| Catalog full | 100 | **36.798** | **234.646** | **6.592** | **11.754** |
| Orders | 0 | 0.786 | 5.868 | 1.025 | 2.592 |
| Orders | 1 | 1.341 | 2.116 | 0.929 | 1.846 |
| Orders | 20 | 13.556 | 19.897 | 1.181 | 2.967 |
| Orders | 100 | **42.345** | **55.555** | **1.831** | **4.062** |
| Popular full | 0 | 0.220 | 0.493 | 0.531 | 1.087 |
| Popular full | 1 | 2.108 | 4.210 | 1.380 | 2.283 |
| Popular full | 20 | 20.802 | 28.307 | 2.842 | 6.271 |
| Popular full | 100 | **51.009** | **670.690** | **6.469** | **10.188** |

### PDO execute elapsed (milliseconds), distinct from wall latency

Sum of native `PDOStatement::execute()` elapsed per service call. This is
**client-observed SQL execution roundtrip elapsed**, including native prepare /
protocol / server / scheduling, not pure server CPU and not whole-call fetch /
mapping time. Server execution time is separately available in EXPLAIN JSON.

| Operation | Items | Original p50 | Original p95 | Current p50 | Current p95 |
|---|---:|---:|---:|---:|---:|
| Catalog full | 0 | 1.128 | 2.182 | 0.918 | 3.012 |
| Catalog full | 1 | 1.447 | 3.388 | 1.127 | 2.837 |
| Catalog full | 20 | 6.095 | 10.111 | 2.224 | 4.014 |
| Catalog full | 100 | 26.077 | 144.333 | 4.998 | 9.879 |
| Orders | 0 | 0.548 | 3.775 | 0.638 | 1.976 |
| Orders | 1 | 0.892 | 1.370 | 0.595 | 1.467 |
| Orders | 20 | 9.184 | 14.186 | 0.817 | 1.356 |
| Orders | 100 | 28.673 | 38.572 | 1.037 | 2.253 |
| Popular full | 0 | 0.198 | 0.435 | 0.401 | 0.647 |
| Popular full | 1 | 1.587 | 3.284 | 1.062 | 1.916 |
| Popular full | 20 | 14.150 | 19.787 | 2.227 | 5.220 |
| Popular full | 100 | 39.124 | 479.159 | 5.151 | 7.877 |

Current card measurements, also 30 samples each:

| Operation | Items | Wall p50 / p95 ms | PDO execute p50 / p95 ms |
|---|---:|---:|---:|
| Catalog card | 0 | 0.911 / 2.218 | 0.665 / 1.391 |
| Catalog card | 1 | 1.434 / 2.628 | 1.060 / 1.798 |
| Catalog card | 20 | 2.209 / 3.394 | 1.693 / 2.368 |
| Catalog card | 100 | 6.077 / 12.038 | 4.953 / 10.946 |
| Popular card | 0 | 0.449 / 1.564 | 0.340 / 1.293 |
| Popular card | 1 | 0.603 / 1.863 | 0.518 / 1.407 |
| Popular card | 20 | 2.276 / 5.591 | 1.741 / 3.797 |
| Popular card | 100 | 4.903 / 7.785 | 4.075 / 6.895 |

### Memory and plans

Maximum incremental PHP **used-memory peak during the service call**, sampled
before JSON serialization, at 100 items (bytes):

| Operation | Original | Current |
|---|---:|---:|
| Catalog full | 4,327,128 | 4,428,312 |
| Orders | 317,656 | 480,176 |
| Popular full | 4,293,168 | 4,478,248 |
| Catalog card | N/A | 504,912 |
| Popular card | N/A | 554,848 |

Batch loading trades some orders/full-output memory for fewer roundtrips. Raw
files also include baseline/allocated memory and harness peaks; the latter may
include serialization and must not be confused with service-only deltas. These
are PHP allocator measurements, not whole-container RSS or PostgreSQL memory.

Representative current 100-item server plans, from the **actual captured SQL**:

| Query | Returned rows | Server execution ms | Shared hit blocks |
|---|---:|---:|---:|
| Full catalog parent page | 100 | 0.183 | 72 |
| Full catalog image batch | 300 | 0.105 | 5 |
| Orders parent page | 100 | 0.044 | 6 |
| Order-item batch | 200 | 0.055 | 3 |
| Popular ranking | 100 | 0.233 | 75 |
| Popular product batch | 100 | 0.077 | 70 |
| Card catalog parent page | 100 | 2.218 | 72 |
| Card first-image batch (`DISTINCT ON`) | 100 | 0.127 | 5 |

These representative plans show **zero shared read blocks and zero temp writes**.
The small tables use several sequential scans and in-memory sorts; original
child plans return 3 images / 2 items per repeated query, whereas current batch
queries return 300 / 200 once. There is no evidence here justifying a new index.
Card substring/character-length work costs server time on long UTF-8 descriptions
while avoiding the large full-description transfer. An EXPLAIN of one original
child query is representative, not a sum over its repeated executions.

### Limitations

- Docker VM and PostgreSQL host are shared with the running disposable stack;
  CPU pinning, load isolation and cold-cache control were not applied. The
  variants alternate order by case but are not randomly interleaved. Heavy tails
  are retained (e.g. original 100-item popular p95 670.690 ms); no outliers were
  removed. Query counts are deterministic; latency is environment-sensitive.
- p50/p95 use nearest-rank percentiles of 30 samples (15th/29th sorted values),
  not confidence intervals or production SLO estimates. No multiplicative
  speedup claim is made. Zero-row current reads are not uniformly faster.
- Service timings exclude Nginx/Go/FPM/HTTP serialization. F1 records real HTTP
  payload sizes and individual elapsed times; it does not manufacture an HTTP
  latency distribution from these service samples.
- Both variants use the same counting/timing PDOStatement wrapper. Its clock,
  counter and binding-bookkeeping overhead is included; these are instrumented
  service measurements, not an uninstrumented production benchmark. Memory
  deltas also include this bookkeeping. Persisted expected DTOs and warmed
  caches are retained across samples in each process.
- Dataset is small and synthetic, with equal-price/sales ties and no reviews.
  Results do not establish large-production-cardinality plans, search performance,
  concurrent throughput, lock correctness, mobile execution or final-image parity.

## Exact repeat procedure

From the repository root, with the already-running disposable project only.
For any compose operation the required override set is:

```sh
export COMPOSE_PROJECT_NAME=shopkmp-round3-final-e2e
export EDGE_SUBNET=10.238.201.0/24 EDGE_IP=10.238.201.2
export YII_HTTP_IP=10.238.201.3 SDUI_IP=10.238.201.4
export TRUSTED_BFF_IPS=10.238.201.4
export YII_FPM_SUBNET=10.238.200.0/24 YII_FPM_HTTP_IP=10.238.200.3
export YII_FPM_DYNAMIC_RANGE=10.238.200.128/25 EDGE_PORT=58221
export ROUND3_DISPOSABLE=shopkmp-round3-final-e2e
E=docs/backend-fixes/round-3/evidence
```

Actual final edge command (main can change the two evidence labels/paths for its
post-rebuild repeat):

```sh
ROUND3_LIVE_ORIGIN=http://127.0.0.1:58221 \
ROUND3_EDGE_EVIDENCE="$PWD/$E/edge-payloads-final" \
python3 scripts/backend_check.py --evidence "$E" --label edge-live-final -- \
  go -C backend-sdui test ./internal/bff -run '^TestRound3LiveEdge$' -count=1 -v
```

The Go test verifies the predictable PHP container's compose-project label, then
streams the **new fixture source** to `docker exec -i ... php /dev/stdin`.
Seeding is atomic; no existing rows are adopted. Private seed output contains
short-lived opaque sessions and is held only in memory, never written/logged.
Cleanup runs with `t.Cleanup`, validates exact run/category/email markers and
deletes only that run's 100 product UUIDs and two synthetic owners, with dependent
rows cascading. Normal failures still run cleanup and write the manifest.
Expiring request-accounting rows are left to normal rate-limit expiry. Legacy
`.`/`..` credentials are globally unique: a conflicting existing row fails seed
atomically rather than modifying another fixture. Unset live origin skips.

Actual final performance command:

```sh
python3 scripts/backend_check.py --evidence "$E" --label perf-comparison-final -- \
  python3 scripts/backend_round3_performance.py --evidence "$E/perf-final"
```

This verifies both containers' project labels; obtains the connection environment
privately via captured Docker inspect output; creates/migrates/seeds a uniquely
named database; runs all four sizes in both images; saves full outputs, actual
plans, raw samples and source/image receipts; compares complete public outputs;
and drops only that generated DB in `finally`. All Docker child argv and exits
are recorded in `perf-lifecycle.json`; database passwords never enter argv or
logs. A failed measurement case is recorded and later cases still execute.
No exposed DB port, other stack, application-source reset or lowered toolchain is
needed. Use a fresh `perf-*` evidence directory for another run.
