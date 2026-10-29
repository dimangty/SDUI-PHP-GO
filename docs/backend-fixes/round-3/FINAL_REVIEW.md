# Round 3 — source changes verified; publication BLOCKED

Baseline/head before checkpoint: `30a882952f0e92e369fd7f31f192aecbbffd6b62`,
branch `refactor/backend-production-hardening`, configured GitHub upstream
`origin/refactor/backend-production-hardening`. Initial worktree/index was clean.

## Delivered

- **F1:** opt-in PHP data projection for catalog/search/popular; 257 raw code points,
  explicit completeness and one ordered image. Go retains the sole 256-point
  ellipsis algorithm. Fixed allowlist/capability negotiation fails explicitly.
  Full public lists/details and page identities/totals remain intact. Actual
  100-product edge body: **4,028,163 → 134,191 upstream bytes**, SDUI ≈**159,545**.
- **F2:** enrollment excludes whole dot segments; opaque case/dots are preserved.
  Owner-addressed legacy query revocation retains user/credential/family locks;
  controlled dry-run/single-record remediation has transactional audit. Legacy
  rows remain visible in valid SDUI without unsafe CMP actions. Foreign revocation
  and linked-session effects were exercised through the real edge.
- **F3:** read repositories batch images/order items; mappings perform zero SQL.
  At 100 rows, catalog/orders/popular **102/202/201 → 3/3/3** statements. Same-data
  output equality, snapshots/ownership and representative PG plans are retained.
- **Architecture:** application now owns ports, identity, models and errors; Yii
  explicitly converts independent wire DTOs. Transport and builders have real
  boundaries, guarded by direct/transitive import tests. SDUI definitions,
  encoding, limits and semantic/structural validators are split by responsibility.
- **Observability:** actual OTel server/build/outbound spans, local-root sampling,
  bounded queue/deadlines, safe attributes and collector failure isolation. Existing
  metrics and business HTTP no-replay transports remain. Native exporter/privacy/
  cancellation/race tests pass. Exact dependencies and security updates are recorded.
- Backend CI now invokes pinned golangci-lint and verifies modules. Public
  **54 operations / 8 screens / 6 commands**, schemaVersion/CMP capabilities,
  all existing migration bytes and mobile CI jobs are preserved.

## Independent review

Fresh reviewer session `ses_e77c04dfeffeiCJNuWaMFX4ouq` reviewed production changes,
then the corrected snapshot. Initial: no Critical, one Important and three Minor.
All four were addressed with tests (empty projection; semantic span errors; escaped
collector paths; fuzz command). Follow-up: **all addressed, no new scoped finding**.
The reviewer also inspected the new native benchmark, live-fixture safety,
ownership cleanup and scheduler phase acknowledgement. No mobile/security/release
approval was inferred. Later diagnostic-only recovery and partial-scan evidence
changes are covered by their own native tests and author review, not that earlier
reviewer's blanket approval. A separate scoped reviewer
`ses_e760e0d38ffegA51SdIpsHk5zC` then inspected the partial-observation gate change:
no fail-open/provenance finding; strict rejection remains. Deferred nonblocking
coverage comment: add a mocked orchestration test for writing partial observations
and continuing scans (existing unit negatives cover retention and failed gate).

## Remaining blockers

1. Complete restored-stack recovery scheduling/readiness is red; immutable baseline
   reproduces statement timeouts under measured memory/I/O pressure. Isolated green
   results are not full acceptance. Reconcile readiness remains the final runtime
   blocker. Co-tenant projects were untouched.
2. Fresh image policy remains blocked: Keycloak **3 High**, OpenSearch **19 High**,
   distroless and PostgreSQL **Unknown** findings. No approved disposition/waiver.
3. No final successful source/build/test receipt; native amd64 and remote CI
   remain NOT_RUN. Full graceful shutdown/trust rerun is not claimed.

No production-ready, complete-security or green-CI claim. A local checkpoint, if
created, preserves work only; **automatic push/publication is prohibited by the
failed mandatory gate**. No PR, merge, deployment or remote/identity change.
The containing checkpoint SHA is obtained from Git, not invented in this report.
Remote was rechecked at 23:21 UTC and still points to the task baseline; no remote
CI is claimed. This workflow triggers branch pushes only for main/master (or PR /
manual invocation), so an ordinary feature push would not itself prove CI ran.

## Scope and decisions

CMP/core/feature/composeApp/iosApp/UIKit/Gradle/mobile dependencies and mobile CI
jobs are unchanged. Existing SQL migrations are byte-identical; one additive
operator-audit migration is new. PHP remains the business/PostgreSQL owner; Go has
no business DB access or production mocks. Raw evidence is shipped as an indexed
portable archive under `evidence/`.

Rulings: reject an explicitly empty projection rather than silently choose full
data (cost: callers of this new parameter must omit it for the public DTO); use
manual bounded OTel instrumentation instead of default URL-bearing middleware
(cost: HTTP conventions are maintained explicitly); preserve failed gate evidence
and withhold publication rather than waive runtime/vendor blockers (cost: release
requires a healthy dedicated run and supported fixes/approved dispositions).
