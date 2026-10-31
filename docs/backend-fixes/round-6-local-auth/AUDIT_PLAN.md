# V7 backend hardening — implementation plan

**Goal:** repair repeat password lockouts and enforce production SQL privilege separation while preserving local identity and the 54 PHP operations, eight SDUI screens and six commands.
**Architecture:** PHP remains the identity/business/database authority. Go remains an HTTP BFF. Use the existing user-first transaction lock, explicit auth constructor dependencies, separate runtime/owner/operator credentials, and bounded startup privilege verification.
**Spec:** user-supplied V7, 31 October 2026; this plan records implementation decisions, not approval of production cutover.
**Execution:** inline, existing feature branch as requested; independent reviews recorded below. Initial commit/push policy required passing gates; the later explicit user instruction **"Make commit"** authorizes a local commit while release/push gates remain unchanged.

## Initial state

- HEAD `b33f15bcec6350c095c5a5be2b6eb1b9eb41751e`; branch `refactor/backend-production-hardening`; remote name `origin`; upstream `origin/refactor/backend-production-hardening`.
- `git status --porcelain=v1`: only `?? deploy/evidence/local-auth-runtime.json` and `?? deploy/evidence/shopkmp-round5-e2e-artifacts-final.json`.
- Staged and unstaged tracked file lists empty; those two receipts are preexisting untracked work and excluded from this task.
- `route_matrix.py --check` confirms 54 / 8 / 6 against registries and source.

## Global constraints

No client/mobile/Gradle changes, no external identity runtime, no SQL in BFF, no token/password-format changes, no rewrite of SQL migrations 001–016/checksums, no production execution or foreign-container stop, no weakened publication gates.

## Sequential tasks

### 1. Baseline and root causes
- [x] Capture safe Git inventory and inspect auth, SQL, Compose, native harness and prior reports.
- [x] Run initial Go tests, Compose rendering, route and runtime-graph guards.
- [x] Run PHP baseline in the actual image and an owned disposable PostgreSQL.

### 2. F1 — durable repeat lockout (RED → GREEN)
Files: `application/LoginThrottle.php`, native `tests/local_auth_relock_native.php`, `tests/local_auth_concurrency.php`, suite registration and focused-runner guard.
- [x] Test three cycles with literal counts 1,2,3,4,5 and active-lock correct-password denial on PostgreSQL; watch cycle two fail.
- [x] Replace stale-marker UPSERT with a single-time-source atomic transition that clears expired markers once, never extends active lock, uses <= at expiry/window boundaries.
- [x] Test successful unlock, window expiry, exact microsecond policy boundary, changePassword shared budget, concurrent failures and credential-change races. Native persistence uses PostgreSQL clock_timestamp; the exact frozen equality case is a supplemental pure policy test.
- [x] Run affected native suites and preserve counter commit-before-API-error behavior.

### 3. F2 — production SQL roles (RED → GREEN)
Files: `deploy/` provisioning, production Compose, production validator, PHP database configuration/preflight and operator root; native role test and CI runner.
- [x] Prove actual merged production config selects the bootstrap owner before the fix.
- [x] Provision fixed project roles idempotently: owner/migrator, runtime, append-only identity operator and distinct worker duties; explicit table/column grants, no membership escalation, no runtime journal access.
- [x] Inject credentials per service; owner/operator secrets absent from web/workers; missing runtime secrets fail closed.
- [x] Run bounded catalog audit at process preflight and lightweight readiness; reject unsafe actual current/session roles.
- [x] Exercise SQL denials and real auth/operator operations against disposable production-like TLS PostgreSQL; upgrade seeded existing data without volume reset.

### 4. Boundaries and dependencies
- [x] Inject credentials/throttle/hasher into AuthService at one existing composition root; retain native test helpers.
- [x] Verify upstream releases, module update listings/graph/why; targeted gateway 2.33.0 and newer verified pgx 5.12.0 plus tidy/checksums and real ops checks.
- [x] Verify Composer 2.11.1 and PHP 8.5.12 official digests; complete separate compatibility stage, fix native cleanup diagnostics, validate arm64/amd64 images and promote the verified 8.5.12 default. Supported 8.3 was not called EOL.

### 5. Verification and review
- [x] Run canonical Go checks, complete native PHP harness/fault/role gates, both PHP architecture images, final arm64 source-bound full-stack/live Go acceptance, live trust, and all seven exact-image/SBOM/binary scans. See TEST_RESULTS for modes and real policy failures.
- [ ] Resolve OpenSearch HIGH findings and authoritative Go DB freshness; complete native amd64 publication CI and native platform rollout gates.
- [x] Add invoked focused-runner guards for mandatory lockout suite and actual production-role runner; register F1 in the complete PHP harness.
- [x] Independent SQL/F1 review and later independent runtime-modernization review; material fixes and affected native suites verified, statuses separated. Runtime reviewer found no new Critical/Important defect; its Minor fixture-lifetime finding was resolved in continuation.
- [x] Inspect full intended diff, secrets and scope; prepare a local thematic commit under the user's later explicit "Make commit" instruction.
- [ ] Only with mandatory release gates green: explicit verified remote feature-branch push and SHA comparison.

## Review focus

1. An expired marker must reset only once, including after a failure committed with an API error.
2. Correct and incorrect password races must obey the same user-first lock as password changes.
3. An overlay or inherited PGPASSWORD must not silently restore owner credentials to runtime.
4. Membership/ownership/SECURITY DEFINER and PUBLIC privileges must not bypass the journal boundary.
5. Provisioning must preserve seeded identities, audit triggers, business rows and migration checksums.

## Execution ledger / rulings

- Initial Docker socket unavailable; starting installed Docker Desktop restored it. Existing restart-policy containers started automatically; none were stopped, changed or removed by this task. Shared VM has 8 GiB, so full-stack capacity must be checked before adding services.
- Ruling: follow V7's explicit short-plan-then-execute instruction and existing feature branch instead of adding design approval rounds or a documentation-only commit. Cost: implementation remains uncommitted if required gates are blocked.
- Ruling: select verified pgx 5.12.0 rather than the supplied 5.11.0 candidate because the newly published release fixes two security defects. Cost: additional API/DSN compatibility surface; real TLS migrations and Go checks passed.
- Ruling: initially keep supported 8.3 while evaluating 8.5 separately; after actual two-image compatibility and cleanup fixes, select pinned 8.5.12. Cost: new minor-runtime behavior requires the preserved extension/TLS/FPM/native/whole-stack gates; passing results are recorded.
- Ruling: give one-shot index bootstrap its own catalog UPDATE capability because PostgreSQL SHARE table locking requires it; recurring search keeps SELECT only. Cost: compromise of the bootstrap login can alter catalog rows, so it remains a controlled one-shot secret.
- Independent review: completed, verdict not-ready-to-merge. Material findings and subsequent native fixes are recorded in FINAL_REVIEW; no second independent approval asserted.
- Runtime-phase independent review: completed, no new Critical/Important source defect; release held for actual outstanding gates. This was a separate phase review, not a second opinion on the earlier SQL fix pass.
- Initial review: minor (initially deferred, resolved in continuation below): SocketUpstream's 400 ms idle lifetime could false-fail the two-request TLS fixture under runner load; no production deadline or false-PASS exception was involved.
- Functional gate: final PHP 8.5.12 canonical source-bound acceptance PASSED, tested:true; live trust seven groups PASSED. Exact-image security FAILED for current OpenSearch HIGH findings and stale authoritative Go DB.
- At the original gate stop, staging/commit/push were held for the outstanding conditions. Later the user explicitly authorized a local commit; release/push remains blocked.

## Continuation — search security and deterministic fixture lifecycle

1. Official stable OpenSearch **3.9.0**, index digest
   `sha256:3b55f6b6249cfdb92b52ee59adc8a266203ba9ef9f5d82544fad6f1af2f3609e`,
   is a candidate, not yet the production default. Compare the same optional-plugin
   pruning and truststore policy, scan the actual candidate, and measure remaining
   findings before choosing a major upgrade.
2. If the candidate improves the boundary, test actual PHP REST/search contracts,
   verified TLS/security-plugin behavior, persisted restart and snapshot/restore.
   Use a separate new cluster/volume; retain the 2.x source and snapshot. Never
   reuse a 3.x-written data directory with 2.x or copy node directories as backup.
   Official compatibility is based on index creation version (3.x reads 2.x), not
   merely the source node's version. Exclude the security index/global state from
   business-index restore; use reviewed security configuration. No production cutover.
3. Reproduce the 400 ms fixture idle cutoff, then use explicit parent-owned shutdown
   for its bounded lifecycle. Preserve application HTTP/read/socket deadlines and
   register regressions in the existing invoked runtime suite.
4. Recheck the authoritative Go DB and existing platform prerequisites, then run
   affected source-bound checks against the final source snapshot. The earlier
   functional receipt remains historical evidence if runtime source changes.

Primary references checked: OpenSearch publisher 3.9.0 release; official
`migrate-or-upgrade` and `tuning-your-cluster/availability-and-recovery/snapshots/snapshot-restore`.
The authoritative Go DB still reports `2026-10-27T21:16:18Z`; its 72h gate is unchanged.

### Continuation results

- Candidate 3.9.0 build and raw scans completed: unpruned upstream **82 HIGH**,
  same project pruning/truststore **32 HIGH**, current 2.19.7 **19 HIGH**. Mandatory
  security-plugin/shaded dependencies remain affected. Ruling: do not promote this
  failed candidate or run a risky migration just to change version strings. Cost:
  the known release blocker remains until a qualified vendor fix/owner decision.
- Former deferred fixture finding resolved: **2 RED**, parent stop/EOF implementation,
  invoked runtime **5/5 GREEN** and Composer on both PHP architectures. No production
  timeout changes. Full current canonical acceptance **PASSED** at 13:39:53Z;
  continuation receipt `tested:true` and all 12 image bindings revalidated.
- Current exact seven-image/SBOM/source/binary gate **FAILED** at 13:44:38Z: same
  19 OpenSearch HIGH and stale authoritative Go DB. Official index rechecked with
  verified system curl/no-cache: unchanged `2026-10-27T21:16:18Z`; no TLS bypass or
  freshness relaxation. An ad-hoc host-Python check lacked its CA trust and was
  diagnostic only, not substituted for canonical Go/curl results.
- Original `testRealCardJourney` built and executed on a new owned iOS 26.2 simulator:
  eight native milestones passed, then **FAILED safari_payment_completed**, exit65,
  no skips, edge 2 demo GET / 0 POST, SQL AUTHORIZED/CREATED. No UI/Gradle source
  changes, assertion removal, tap replay or hidden payment mutation. Raw credential-
  bearing XCTest data was private and deleted after safe summary extraction.
- No outstanding deferred fixture minor remains. OpenSearch, authoritative Go DB,
  native Safari and unrun native amd64 publication still prevent release/push.
- Local-commit handoff: subsequent user instruction "Make commit" authorizes a
  thematic local commit with the failed gates preserved in these reports. Only
  task sources/tests/lockfiles/docs are staged; preexisting untracked receipts and
  private evidence/credentials remain excluded. No hook or gate is bypassed.
