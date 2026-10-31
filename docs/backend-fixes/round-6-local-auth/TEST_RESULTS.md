# V7 verification record — 31 October 2026

## Status

**Complete source-bound backend functional acceptance on PHP 8.5.12: PASSED.
Artifact-security/release/publication: BLOCKED. The user subsequently requested
a local commit explicitly; no remote push/publication is approved by this result.**
`acceptance-artifacts-continuation.json` records `tested:true` only after the complete
`backend_artifacts.py --phase test -- scripts/verify.py ...` command exited 0 and
source/image comparisons passed. Existing round-5 receipts are not these results.
Final read-only receipt revalidation matched all **12 service images** to current
sources; source-snapshot SHA256
`41c878a8b36cc0412976752f2e9059247e7ec3980d02b0a5b0f80ea6edd8592b`.
Earlier PHP 8.5 receipt/fingerprint `61c4ccb8…f32d8` remains historical evidence;
the fixture-lifecycle continuation supersedes it for current runtime sources.

Safe initial Git inventory is in AUDIT_PLAN. Work stayed on
`refactor/backend-production-hardening`, baseline HEAD
`b33f15bcec6350c095c5a5be2b6eb1b9eb41751e`. Two preexisting untracked receipts were
left untouched. No client/mobile/Gradle, SQL 001–016/checksum or mobile CI edits.

## This session's execution

| Check | Result / mode |
| --- | --- |
| Initial `make compose-config`, `make test-runtime-graph`, Go tests in both modules | PASSED; initial Docker daemon unavailable, then installed Desktop started successfully |
| `make test-php PHP_TEST_IMAGE=shopkmp-round6-php-tests` baseline | PASSED after Docker startup: real pinned build/Composer install, unit harness and lint; initial daemon failure retained as environment failure |
| `route_matrix.py --check` | PASSED: verified 54 PHP operations / eight screens / six commands, exact policies/contracts |
| F1 `local_auth_relock_native.php` before fix | **RED on real PostgreSQL 17.11**, second cycle expected 2, got 1 in login and change-password |
| Same native F1 test after fix | **3/3 PASSED**: three five-error cycles, no tokens with correct active-lock password, durable counters, successful unlock, mixed password-change/login budget and rolling window |
| `login_lockout_policy.php` | PASSED exact microsecond equality/just-before/after policy boundaries; supplements native PG, not a replacement for it |
| `local_auth_concurrency.php` | **8/8 PASSED**, real processes/PG: registration, hash-generation races, five concurrent failures, both good/bad-login orderings and stale wrong-password change/login |
| Existing session suites | Native revocation 7/7, password fault/cleanup 4/4, concurrency 27/27 PASSED; historical recovery fences retained |
| `local_auth_migration.php` post-review | Before: two constructor ArgumentCountErrors. After: **21/21 PASSED**, including existing least-privileged operator reset and concurrent/failed audit paths |
| `scripts/database_roles_native.py` final on PHP 8.5 | **PASSED**, actual merged production-like TLS Compose, role suite **10/10**, repeat lockout **3/3**, operator CLI inventory; `php85-roles-https-worker-green` receipt |
| Role coverage | Journal/DDL/SET ROLE, column grants, cross-schema PUBLIC views, memberships/definers, effective future-function ACL, preexisting business/audit preservation, auth/checkout/summary/password/logout and operator reset/remediation. Distinct workers deny identity and financial amount/price rewrites. Recovery AND runtime send real verified HTTPS authorizations; provider sees committed SENT and no idle SQL transaction. Observation-sequence permission reproduced RED, then explicit USAGE fixed it |
| Full PHP `tests/native_suites.php core all.php` | **PASSED** with actual owned PG + OpenSearch, mounted real migrations/scheduler and explicit disposable opt-ins, 08:57:24–08:58:54Z. Includes real search lifecycle suites. API tests in this mode include in-process kernel calls; this is not a live PHP→Go/edge acceptance claim |
| Native payment suites | payment migration 13/13, payments 113/113, payment HTTP 4/4, UUID 16/16, UUID recovery 5/5, recovery 9/9 PASSED; real builtin HTTP payment adapter and PG, no mocked business answers |
| Native recovery scheduling | Initial run: two failures because this invocation omitted `/opt/scheduler` mount. Corrected canonical mount: **14/14 PASSED**, same assertions/deadlines; transaction timeout approximately 5.02 seconds |
| Docker scheduler suite | PASSED at 09:02:33Z, real image and existing timing policy; no time-scale/deadline relaxation |
| Python discovery / merged configuration | **45/45 PASSED**; actual Compose renderer tests plus mocked lifecycle tests explicitly distinguished. Infrastructure/deployment checks PASSED, including explicit disposable networks and real read-only merged JSON rendering |
| BFF canonical check script | PASSED on Go 1.27.3: formatting, tidy-diff, mod verify, vet, test, race, build, schema, lint, source govulncheck |
| Ops checks | PASSED race/vet/build/lint/mod verification; govulncheck initially failed on host-selected 1.26.0 standard library, passed on selected patched 1.26.10 |
| Composer 2.11.1 / PHP 8.5.12 | Real install-by-lock, validate, audit, platform, unit and lint PASSED; Yii 2.0.55, native Argon2id and required extensions; 132 PHP files linted |
| Live BFF/Yii and complete source-bound scripts | **PASSED** again on current PHP 8.5.12 snapshot, **13:32:46–13:39:53Z**, after parent-controlled fixture shutdown. All canonical phases: image gate, scheduler, seed, reconciliation, edge CARD, BFF race/live/vet/schema, round-3 edge, ops, complete PHP/search, max order HTTP, focused payments/roles/recovery, worker readiness and shutdown/drain/restart. Current receipt `tested:true`; earlier 8.3/8.5 stages separately recorded |
| PHP 8.5 architecture compatibility | Actual arm64/amd64 images built; runtime/extensions/TLS/FPM/pruning **5/5 each**, unit and complete native PostgreSQL/search PHP suites PASSED each. amd64 executes under Docker emulation, not native capacity proof; complete amd64 publication CI NOT RUN here |
| Live trust | **Seven groups PASSED** on final PHP 8.5: IPv4/IPv6, spoofing, budgets, webhook transport source, exact FastCGI peer, both real Go replicas. Separate owned trust_ DB/containers cleaned up |
| Native iOS/Safari journey | **FAILED**, unchanged `InstalledJourneyTests/testRealCardJourney` on a newly owned iPhone 17 Pro/iOS 26.2 simulator; build succeeded, no compiler errors, exit **65**, one failed test, zero skips, **567.188 s**. Eight milestones passed through native checkout, then `safari_payment_completed` failed. Edge saw **2 demo GET / 0 demo POST**, SQL remained `CREATED` / `AUTHORIZED`. No hidden POST, replay, client edit or weakened assertion |
| Android journey / native amd64 publication | **NOT RUN**; not replaced by HTTP, emulated PHP or the failed iOS attempt |
| Fixture lifecycle regression | Two failures reproduced the 400 ms cutoff and idle shutdown delay. Parent stop/EOF fixes both: runtime **5/5 PASSED**, Composer passed on arm64/amd64, and current canonical acceptance passed. Connection/read/application deadlines unchanged |

Detailed task-owned logs/exit/timestamp JSON are outside Git under the approved
temporary directory's `round6-native/`: `roles-final`, `php-all-services`,
`php-payment`, `scheduler-native`, `scheduler-container`, then final
`canonical-acceptance-85`, `acceptance-85/`, `php85-roles-https-worker-green`,
`php85-amd-native-all`, `trust-live-85`, then `continuation-acceptance`,
`acceptance-continuation/`, `security-continuation/`, `continuation-amd-unit`,
`native-ios-current.json` and `native-ios-http-counts.json`. Earlier failures remain
visible: missing initial Yii audit migration; repeated legacy-dot fixture collision
on reused databases; missing search service; missing scheduler mount; constructor
regressions, preflight/default-ACL/audit-owner failures. None are called successful
acceptance runs. Native fixtures were then provisioned correctly and rerun.
Later diagnostic failures also remain: the first PHP 8.5 build was cancelled
during unnecessary server-development dependencies; cleanup deprecations failed
three strict tests; CLI preflight contaminated machine stdout; an OPcache `.so`
count assumption was invalid on 8.5; an isolated test invocation used a different
index name than its default-only protocol fixtures; and the real TLS role test
found missing observation-sequence USAGE. These were corrected and affected gates
rerun without suppressing runtime warnings or changing production deadlines.

## Performance observations

Actual PHP 8.3.36 Linux/aarch64 target hash options: 19,456 KiB / t=2 / p=1.
Four sequential hash samples **11.86–12.97 ms**, verify **11.43–11.93 ms**;
peak process RSS **44,504 KiB**. Two worker probes completed in **113.36 ms**.
This is a shared-host sample, not production capacity or a speedup claim.

PHP 8.5.12 on the same shared arm64 VM: four hash samples **12.52–13.33 ms**, verify
**12.02–12.92 ms**, peak RSS **46,136 KiB**. A separate actual-image batch of ten
hashes plus ten verifications took **360.66 ms**, **295.12 ms user CPU** and
**64.56 ms system CPU**, peak RSS **45,108 KiB**, same 19,456/t=2/p=1 policy.
Current real-edge auth/profile/refresh/password-change/revocation/logout HTTP
latencies are recorded in `auth-http-latency-85.log`, with only finite aggregate
operation labels; identities/tokens/bodies were not emitted. Different VM load
and emulation make these observations unsuitable for a cross-version speed claim.
Five-sample medians: login **33.35 ms**, profile SDUI screen **25.35 ms**, refresh
**13.82 ms**; one password change **49.66 ms**. The flow verified 401 for revoked
access/refresh and access after logout, and successful new-password login.

Native maximum-Unicode order test: **two SELECTs**, **zero order_items SELECTs** for
summaries. Observed 20-order summary **55,999 bytes** versus full **4,725,942**;
100-order summary **279,600** versus full **23,629,463** in one recorded fixture.
UUID/number representation can change the last bytes across generated fixtures.
No new comparable live SDUI/SQL latency benchmark or acceleration claim.

## Artifact security boundary

The canonical `backend_security.py` completed exact-image scans, CycloneDX SBOMs,
binary extraction/scans, package inventory, lock hashes, archive hashes, database
content stability and functional-receipt binding for all seven arm64 runtime
services, repeated for the continuation source snapshot. An earlier ad-hoc
OpenSearch timeout/cache-lock run is superseded, not counted as clean. These IDs
describe the earlier 11:30Z PHP 8.5 stage; full current IDs/archive hashes/config
digests are in `security-continuation/artifacts.json`:

- PHP `sha256:3104bbc0a59b3cba791fe8351e2c1efd3c0e98a3263b0bed5cb3a41c9ae40c6b`
- BFF `sha256:a0b2fbc23d786da34aa2938c9332f449cdf5e374841b81987243f4c854010429`
- Migrator `sha256:224938a8bc214c26e9c30df1f81040190a4e7686d79531fe468e3c95c7bbbb12`
- PostgreSQL `sha256:dea10c5b358114cdfdca88fdaeefa8efe3ce18c7e27d0308bd231dfd3f24aeca`
- OpenSearch `sha256:aef4b840a35821c586f63c789d5dd1f293a6ce60c98173102f31bdabc98af3a3`
- Both Nginx services `sha256:dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7`

`security-85/artifacts.json` separately records OCI index IDs, archive SHA256 and
actual config digests, avoiding containerd index/config confusion. For example,
PHP config is `c18018fa…8b72a5`, OpenSearch config `be1e4ba3…ffa7582`.
After the fixture-only continuation, current PHP index is
`sha256:c3fafb30783031b7c4a0115aa8ce03020e075206fce596af2d01d81344ca95ae`,
config `sha256:9e4dbf28005d9c12dc67cc81e92845722b8ff1f5e53271dd24af5eadaa5b8130`.

Final continuation security gate **FAILED**, exit 1 at **13:44:38Z** (same policy
failures as the earlier 11:30:43Z stage):

- Composer production audit and six image policies **PASSED**; no dispositions.
- OpenSearch 2.19.7: **19 HIGH instances** freshly reproduced, including Jackson
  core 2.18.9 (CVE-2026-89407/89425), databind 2.18.9 (CVE-2026-68497/91776/91777),
  lz4-java 1.11.1, Guava 32.1.3/33.4.0 and snappy-java 1.1.10.7. Exact jar paths,
  IDs and config digest are in `opensearch.policy.json`. Official 2.19.8 image
  lookup returned **not found**. No unverified JAR replacement or major migration.
- Source and delivered-binary Go raw scans report no findings, but authoritative
  DB timestamp **2026-10-27T21:16:18Z** exceeds the unchanged **72h** policy.
- Trivy DB is current: UpdatedAt **2026-10-31T07:07:33Z**; Java DB UpdatedAt
  **2026-10-31T01:06:44Z**. Both content hashes were stable throughout scanning.
- Unchanged native iOS journey built/run on an owned simulator and FAILED; Android
  and native amd64 publication/security CI remain NOT RUN. Explicit amd64 emulation
  is not a replacement for those deployment gates.

Continuation candidate check is a separate **rejected upgrade**, not a deployed
image: official stable OpenSearch 3.9.0, matched pruning/truststore handling, actual
arm64 scan **32 HIGH**. Mandatory security-plugin libraries/shaded copies remain
affected. Candidate API/persistence/restore success is not claimed because its
image-security prerequisite failed. Production default remains 2.19.7.

OpenSearch is reachable by the PHP search/indexing paths on the internal service
network, with production TLS/security-plugin configuration and no public port.
That limits exposure but does not establish the affected methods unreachable or
waive findings. **Decision owner:** backend security and search operations; obtain
a verified supported-image fix or separately approved disposition through the
existing policy. Go DB freshness belongs to the security pipeline/publisher; do
not extend its age limit. No exception was approved by this agent.

Shared Desktop is 8 GiB with many unrelated running stacks and exhausted default
network pools. Task-owned networks used explicit subnets; only owned fixtures were
managed. The owned canonical stack used explicit 10.252.188–192 subnets via an
opt-in network-only overlay; no application scenario was replaced. Foreign stacks
were never stopped. Backend functional acceptance and platform/publication remain
separate: no ignored HIGH/UNKNOWN findings or freshness increases were made.

After final receipt revalidation, only task-owned running fixtures/canonical
services were stopped; named volumes and private evidence were retained. No task
container remains running. The historical tracked `deploy/evidence/integration.json`
overwritten by the canonical runner was preserved as temporary `integration-85.json`
and restored to its clean baseline; both preexisting untracked receipts were left
untouched. At the end of verification the index was unstaged and no commit/push
had been attempted. The subsequent explicit user instruction **"Make commit"**
authorizes committing only task sources/tests/lockfiles/docs locally. It does not
change any failed gate, approve release, or include runtime-evidence blobs.
