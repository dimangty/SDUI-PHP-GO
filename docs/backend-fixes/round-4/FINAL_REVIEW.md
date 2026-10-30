# Round 4 — addressed source defects; publication BLOCKED

Baseline: `f8f5f33a9174daa56226ea35eb360e4936491097`, initially clean working tree
and index. Branch `refactor/backend-production-hardening`, GitHub `origin`, remote
default `master`; the working branch was verified non-protected. No default branch
or unrelated project was modified.

## Findings and delivered changes

| Finding | Location / before → after | Evidence and remaining risk |
|---|---|---|
| **R8-01** UUID catalog identity | `CatalogReadRepository::byIds`: case-sensitive keys lost/reordered UUID-value identities → validate all IDs before SQL, canonical first-hit dedup and batch ordered hydration. `OpenSearch::index/search`: canonical writes/deletes/category terms; malformed/missing hit IDs fail integration. | Native lower/upper/mixed, rank/dedup, inactive/missing, malformed, full/card, uppercase admin update/category filter and delete tests. Legacy duplicate docs require the separate controlled remediation. |
| **R8-02** explicit inactive create | `CatalogService::writeProduct`: SQL literal true → bind the validated boolean; absent default remains true. | Native admin DTO/DB/public/details/search/popular visibility, activation/deactivation and non-boolean HTTP negatives. No intermediate publication or auth contract change. |
| **R8-03** primary transaction error | `Database::transaction`: cleanup could replace primary error → explicit ownership/commit phase, guarded cleanup, original Throwable/SQLSTATE, quarantine uncertain sessions, no replay. Definite server COMMIT rejection is separated from lost confirmation. | Deterministic cleanup/state/commit faults and native timeout, terminated connection, deferred commit/payment/recovery tests. A quarantined Database needs an explicitly fresh instance; unknown financial outcome still uses durable recovery. |
| **A8-01** search transfer | `OpenSearch::search`: full `_source` transfer → `_source:false`, preserving exact total/hit metadata, sort/page and SQL authority. | Constructed-request test and native integration; same-data bytes/memory/raw timing samples in TEST_RESULTS.md. PHP→Go compact payload and SQL count unchanged. |
| **A8-02** exporter health | Runtime Recorder now binds `Tracing.ExportFailures()` to protected `sdui_trace_export_failures_total`. | Off/on, failed collector, privacy, TLS, bounded deadline/queue/drain/shutdown/race tests; real runtime wiring and readiness/business isolation. No unsupported SDK queue/drop metric or unbounded label. |
| PHP read architecture | Existing pure product/order conversions moved to stateless DTO mappers; read repositories no longer require ShopRepository or construct each other. | Existing native 0/1/20/100-row equality/ownership/snapshot/query budgets and zero-SQL mapping assertions. Go application ports/models/wire split and import guards are pre-existing and retained. |
| Dependencies | grpc indirect 1.83.2→1.85.0; Composer 2.10.3→2.11.0 official digest after fresh advisory verification. Listed current Go/JSON Schema/OTel/protobuf/lint and x/* floors unchanged. | Before/after graphs, executable metadata, native compatibility checks, official release/advisory/range/license sources in DEPENDENCIES.md. No unsupported claim that old grpc was vulnerable. |

## Independent review and verification

Independent reviewer session `ses_e74978d0bffeuHAr0oJMDcPsAq` initially identified
two P2 defects in the new maintenance path: partial inventory acceptance and an
unbounded inactive-history rebuild. Both were reproduced RED and corrected with
multi-page/partial-response and bounded native-query tests. Follow-up: both
resolved, **no new actionable scoped finding**. Reviewer independently ran focused
Go race and diff checks; it did not rerun the native PHP stack or approve release.
A final Composer-pin review found no source regression, verified source/image
receipt consistency, and requested two documentation clarifications. Final
receipt/benchmark statuses and the user-authorized checkpoint boundary are now
explicit; neither clarification changes or approves the failed release gate.

Native original baseline and final source-bound Composer 2.11 full stack passed actual HTTP
8-screen/6-command, PHP, payment/recovery/concurrency and graceful restored-stack
checks. `final-bound-acceptance` exited 0, final receipt is `tested:true`, and
`final-live-trust` exited 0. Final native PHP reports 268/0, separate payment/recovery
174/0. An additional 51-check actual-edge R8 replay passes.
Standalone native Go formatting/tidy/verify/vet/test/race/build/contracts/pinned
lint/source+binary govulncheck, PHP platform/unit/lint/audit and fail-closed policy
checks have successful receipts. All RED/intermediate failures remain available.

## Publication boundary

Fresh final exact-image arm64 policy (`final-image-security`, scanner exit 1 / make
exit 2, 07:18:24 UTC) remains blocked: Keycloak 3 HIGH, OpenSearch 19 HIGH,
distroless BFF/migrate 4 UNKNOWN each and PostgreSQL roles 1 UNKNOWN each. Source,
delivered Go binaries, Composer audit and PHP/Nginx image policies pass. This does
not erase the failed complete image gate;
native amd64/remote CI acceptance is not established. Official current compatible
vendor image metadata resolves to the same pinned Keycloak/OpenSearch/distroless
digests; no supported replacement clearing those findings was established.
OpenSearch 3.9.0 is a major migration, not an untested image-pin shortcut. No
arbitrary JAR/JDK swap, scanner exception, accepted-risk self-approval or weaker
gate was introduced. This is **incomplete/BLOCKED**, not production-ready.

The user explicitly permits a local incomplete checkpoint when accepted in this
repository; existing round-3 commit `1dc35c6` contains its BLOCKED final review.
A local checkpoint here records only that explicitly incomplete state, not a final
release commit or approval. **No automatic push,
publication, merge or deploy is permitted by the failed mandatory gate.** A final
local SHA/remote comparison is reported from Git, never invented here. Existing
CI supports PR/default pushes/manual dispatch; ordinary feature push does not
prove CI execution. Remote CI remains NOT_RUN unless a run for the exact SHA is
actually verified.

## Invariants and rollout

Actual inventory remains **54 business operations / 8 screens / 6 commands**.
CMP/core/feature/composeApp/iosApp/UIKit, mobile dependencies/CI jobs, public
routes/JSON/schema and all **34** pre-existing migration files are unchanged.
`composer.lock` is unchanged; no new database migration was necessary. PHP owns
business/PostgreSQL; Go uses real PHP HTTP and preserves optional Home partial
results, auth cancellation, transport limits/no-replay, decimal money and existing
security/payment/session guarantees. See ADR.md and ROLLOUT.md for the write-drain,
staged index validation and safe post-write rollback boundary. No production
volumes, indices or data were removed; disposable cleanup is project-specific.
