# Round 4 — native checks and retained failures

Execution: **2026-10-30 UTC**, native Go **1.27.3 darwin/arm64**, Docker Linux
arm64 PHP **8.3.36**, PostgreSQL **17.11**, OpenSearch **2.19.7**, Keycloak
**26.8.1**. Docker **29.6.2**, Compose **5.3.1**, shared VM **10 CPUs /
8,321,515,520 bytes**. PHP/Composer/psql are unavailable on the host; real native
containers were used. No downgraded module, excluded production test, fake HTTP
upstream in production, or fake PDO substitute for required native checks.

Top-level labels below resolve to `evidence/<label>.json` (argv/cwd/UTC/raw exit)
and `.log`. Full runs additionally have individual raw suite logs. Existing
verification tools sometimes print expected negative-fixture errors; their suite
assertions and process exits remain authoritative for that exact scope.

## Baseline and regressions

| Check | Observed result | Evidence |
|---|---|---|
| Initial Git | Clean index/worktree, branch `refactor/backend-production-hardening`, baseline `f8f5f33a9174daa56226ea35eb360e4936491097`; GitHub origin; branch not protected | PLAN.md; initial tool observations; baseline-integrity.json |
| Original Go `go test -count=1 ./...` | Exit 0 | `baseline-go` |
| Original `make test-php` | Initial exit 2: Docker helper absent from PATH; restored installed Docker helper, exit 0 | `baseline-php`, `baseline-php-path-fixed` |
| Full original PHP/Go runtime images, original suites and restored stack | Exit **0**, including actual HTTP 8 screens/6 commands, native PHP, recovery 14/14, graceful shutdown/restart | `baseline-full-acceptance`, `baseline-full/` |
| UUID rank/dedup, malformed hits, isActive=false, native termination | Expected RED, then GREEN | `catalog-native-red-independent`, `catalog-native-green`, `native-catalog-final` |
| Transaction cleanup/ownership/uncertain commit | 4/6 failures before fix, 0 after | `transactions-red`, `transactions-green`; later unit/full suites |
| Definite deferred COMMIT rejection | Expected RED after overbroad quarantine; corrected classification GREEN, preserving original payment assertions | `payment-recovery-current-network-fixed`, `definitive-commit-red`, `native-catalog-final` |
| PDO HTTP error log privacy | Correct PDO fault RED, then GREEN | `primary-error-log-valid-red`, `final-php-unit-build` |
| `_source:false` query | Expected RED, then GREEN | `source-projection-red`, `native-pure-query-green`; final unit/full suite |
| Exporter metric | RED: missing binding API; GREEN unit/runtime/race | `exporter-metrics-red`, `exporter-metrics-green`, `runtime-metrics-native` |
| Partial inventory / staging history budget | Expected RED, then native GREEN | `inventory-partial-red`, `staging-budget-bounded-red`, `native-staging-final-fixed-fixture` |

One early `staging-budget-red` terminal invocation exceeded **240 seconds** while
the original staging implementation reconciled unrelated active history. Its raw
log is retained, without inventing a completed exit receipt. The exact owned test
container was stopped. A four-statement native fixture budget then gave a bounded
RED before the fix. Another intermediate stage fixture mistakenly rolled back
before checking SQL rehydration; its failure is retained and the fixture corrected.
No production timeout, expected business result or concurrency assertion was
relaxed. An invalid FPM subnet range and an occupied default payment-test subnet
were corrected only in this project's environment; the failed receipts remain.

## Current source and Composer update

| Command/check | Result | Evidence |
|---|---|---|
| `sh scripts/check.sh` in backend-sdui | Exit **0**: tidy-diff, verify, nonzero formatting guard, vet, test, race, build, JSON/golden/semantic/import boundaries, pinned lint 2.15.0, govulncheck 1.9.0 | `final-go-check-fixed-batching` |
| Native runtime metric/privacy/TLS/deadline/drain tests | Exit 0 | Included above; `runtime-metrics-native`; independent reviewer also ran focused race |
| Native PHP unit/lint build with Composer 2.11.0 | Exit **0** | `composer-211-native-unit` |
| Composer 2.11 platform requirements and locked production audit | Both exit **0** | `composer-211-platform`, `composer-211-locked-audit` |
| Complete pre-Composer source-bound build and native acceptance | Both exit **0**, receipt `tested:true` | `current-bound-build`, `current-bound-acceptance`, `current-artifacts.json`, `current-full/` |
| Live multi-replica Nginx/FPM/transport-source trust | Exit **0** | `current-live-trust` |
| Final `make integration-up` / `make integration-check` after Composer pin | Both exit **0**, receipt `tested:true`; PHP lint **99 files**, **268 tests / 0 failures**, separate payment/recovery **174 / 0**, restored shutdown/restart | `final-bound-build`, `final-bound-acceptance`, `final-artifacts.json`, `final-full/` |
| Final `make test-trust-live ENV_FILE=.env.example` | Exit **0**; IPv4/IPv6, two BFF replicas, FPM/peer and signed webhook source boundaries | `final-live-trust` |
| Real edge R8 HTTP replay (no mocked upstream) | Exit **0**, **51** HTTP checks: absent/true/false creates, full lists, details, uppercase updates/deletes, three category spellings, card search and invalid booleans | `final-r8-live-http`; `R8_HTTP_REPRO.log` |
| Actual protected operational metric, tracing disabled | Exit **0**, `sdui_trace_export_failures_total 0` | `final-internal-exporter-metric` |
| Real executable module/package graph and native binary govulncheck | Exit **0**; no findings | `executable-build`, `executable-module-metadata`, `executable-packages`, `executable-binary-govulncheck` |
| Fail-closed security policy / Compose integration tests / config | All exit **0** | `backend-policy-regressions`, `backend-compose-regressions`, `compose-validation` |
| Protected contract/client/old migration bytes | PASS; **34** original migration files | baseline-integrity.json / final-integrity.json |

An intermediate runtime metric test assumed exactly one export batch for a
request; under race, two spans were split into two batches and the metric correctly
reported 2. The test now checks equality with actual failed collector HTTP calls,
not a batching assumption. Its RED (`final-native-go-check`) and corrected full
GREEN are retained; production batching/metrics logic was not weakened.

## Performance: same dataset and query, measured raw samples

Fixture: 100 native SQL products, **10,000 emoji code points** each, 20 ordered
image URLs each of **2,048 bytes**. Native PHP 8.3.36 / PG 17.11 / OpenSearch 2.19.7.
Alternate full-source control versus metadata-only on the **same** index/dataset;
three warmups and 15 measured iterations per variant. First observations are
retained, not claimed as flushed OS/JVM-cold caches. `final-perf-native-samples`
contains every final full-stack sample and the exact output SHA256 equality check;
the earlier `perf-native-samples` observations are also retained.

| Median measured value | Full-source control | Metadata-only |
|---|---:|---:|
| OpenSearch → PHP bytes | 16,165,361 | 17,661 |
| HTTP + JSON decode time | 47.918 ms | 9.607 ms |
| PHP search/decode peak memory delta | 25,729,280 bytes | 118,816 bytes |
| PHP → Go serialized compact page | 542,591 bytes | 542,591 bytes |
| Rehydrate + encode time | 12.903 ms | 12.793 ms |
| Native SQL executions | 2 | 2 |

The control deliberately uses a **benchmark-only 64 MiB** response ceiling to
measure source transfer. Production's 8 MiB OpenSearch limit is unchanged; this
does not assert a previously observed production 502 or a failing search server.
Full and compact API values still come from batched PostgreSQL reads. Native
0/1/20/100-row catalog/orders/popular budgets also pass (`native-read-mapper-green`
and the complete PHP suite); pure mapper execution remains zero SQL.

The first Go HTTP allocation benchmark exceeded the normal **30 searches/minute**
limit. A later multi-repetition process exited zero while printing failed
repetitions: `perf-go-native-http-rate-bounded` is **not accepted as PASS** despite
its generic command receipt's process status. Successful individual samples are
not substituted for a complete run. The final bounded run
`final-perf-go-native-http` **passes completely**: 3 warmups, 20 measured actual
Go→PHP HTTP iterations (`-benchtime=20x -count=1`), **42.746 ms/op, 2,340,090 B/op,
3,194 allocs/op**, including HTTP, decode and conversion. It is not an isolated
JSON-decoder or production-latency percentile measurement. No production rate
limit is disabled/increased; repetitions fit the normal 30-search/minute policy.

## Runtime and publication

Original/current same supported infrastructure images/configuration and fresh
synthetic seed were used in the same disposable project. Baseline and current
resource observations show memory PSI **0.00**, available headroom and no swapped
memory in the observed window; per-role CPU/memory/network/block-I/O samples and
PG timeout/wait snapshots are retained. Server timeout defaults remain 0; ordinary
application 10s statement/8s lock and recovery 1s/800ms/5s budgets are unchanged.
The previous restored-stack starvation/readiness failure was **not reproduced**
here; no functional deadlock fix or blanket SLA increase is claimed.

Current recovery diagnostics compare the propagated PDO object to the **actual
first failed native statement**, preserving SQLSTATE/driver diagnosis. Observed
transaction timeout is HY000/driver 7 with primary FATAL termination, rather than
the old cleanup `no connection` message. NOWAIT contention remains native 55P03;
durable-state, exact call-count, lease and no-replay assertions remain in place.

Fresh pre-Composer `security-arm64` scan exited **1** (make exit 2): Keycloak 3
HIGH, OpenSearch 19 HIGH, BFF/migrate 4 UNKNOWN each and both PostgreSQL roles 1
UNKNOWN each. Reports/SBOMs/image digests/DB versions and raw exits are retained;
these are today's measurements, not copied round-3 counts. Final post-Composer
scan completed **07:18:24 UTC**, scanner exit **1** / make exit **2**, with the
same unresolved image policy counts. `final-image-security` and
`security-final-arm64/gate.json` retain the failure; artifact inventory and the
successful source/build/test binding were accepted. PHP/Nginx image policies,
Composer locked audit, both Go source scans and all four **actual delivered Linux
Go binaries** have zero raw findings. Trivy **0.76.1** DB v2 updated **2026-10-30
01:01:11 UTC**, Java DB **00:58:38 UTC**, both refreshed and content-hashed; exact
image config/digests/SBOMs are retained. No responsible-owner disposition exists.

## Evidence privacy / Git boundary

Gitleaks **8.30.1**, digest-pinned, scanned intended source/docs with redaction.
Raw exit **1**, one finding: unchanged `KC_DB_URL` service DNS/port string, with
no userinfo/password. `source-secret-triage-proof` checks the exact line against
baseline; `source-secret-triage.json` records classification, zero unresolved
credentials, and the raw exit remains failed. Archive review is recorded beside
the packaged evidence; no scanner configuration or vulnerability policy is waived.
Initial archive scan: raw exit **1**, **392** findings; the existing narrow
classifier accounts for **388** public checksums/advisory references, and exact
manual review accounts for four more (an immutable image digest, two Go filename
metadata arrays and the same JDBC reference). `archive-secret-review-proof`
validates these structures with **zero unresolved credentials**. The raw failed
scan and the automated classifier's four unresolved entries remain available next
to the separate manual conclusion. Packaging verifies every indexed member hash.

The disposable project was removed with project-specific `down --volumes` only
after evidence preservation; `coexisting-project-health` confirms the pre-existing
eleven-service project remains healthy. Generated root deploy/evidence files were
restored to their initial bytes, with this run's copies kept in round-4 evidence.

NOT_RUN: native amd64 full-stack/security acceptance, remote CI, production
rollout/provider settlement and production legacy remediation. Image publication
remains **BLOCKED** until every mandatory architecture/security gate is accepted.
