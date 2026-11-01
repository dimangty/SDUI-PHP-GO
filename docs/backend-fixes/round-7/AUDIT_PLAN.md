# Backend V8 — targeted hardening implementation plan

> Implementers: use `executing-plans` / `test-driven-development`; independent domains may use `dispatching-parallel-agents`. Initial commit/push policy required passing gates. The user's subsequent explicit **"Make commit"** authorizes a local commit; release/push gates are unchanged.

**Goal:** eliminate false core-readiness failures, isolate external credentials and repair signed demo payment identities while preserving local identity and the CMP contract.
**Architecture:** Yii2 remains the business/PostgreSQL authority; Go remains an HTTP BFF with application-owned models and typed SDUI. Core readiness performs mandatory bounded SQL/configuration probes only. Search diagnostics and privileged search administration are explicit separate capabilities.
**Tech stack:** pinned PHP 8.5.12 / Composer 2.11.1 / Yii2 2.0.55; BFF Go 1.27.3; PostgreSQL and TLS-enabled OpenSearch 2.19.7. These versions predate this task.
**Spec:** user-supplied Backend V8 assignment (independent review 2026-10-31), executed 2026-11-01. Its explicit plan-then-source direction governs execution without additional approval/documentation-only commits.

## Baseline

- Branch `refactor/backend-production-hardening`, upstream `origin/refactor/backend-production-hardening`.
- HEAD and independently queried target remote ref: `160d2a5a535791c75c36c2f70a90515153339e87`.
- Remote `origin`: `https://github.com/dimangty/SDUI-PHP-GO.git`.
- No staged/unstaged tracked work. Preexisting untracked `deploy/evidence/local-auth-runtime.json` and `deploy/evidence/shopkmp-round5-e2e-artifacts-final.json` are excluded.
- No repository AGENTS.md found. Read all six round-6-local-auth reports.
- Host Go 1.25.3; BFF-selected actual Go 1.27.3; initial `go test ./...` PASSED without exclusions. Host PHP absent; use actual PHP/Composer images.
- Docker Desktop 4.80.0 / Engine 29.6.1 / Compose 5.3.0 available after starting installed Desktop. Foreign restart-policy stacks started automatically; do not manage them. Test capacity is shared.
- `python3 scripts/route_matrix.py --check`: independently counts 54 exact operations/policies, eight screens, six commands. Preserve route registry, OpenAPI, JSON Schema and golden contracts byte-for-byte unless a nonbreaking documentation correction is proved necessary.

## Constraints

Only backend, backend deployment/scripts/CI/tests/docs changes. No CMP/platform-source modifications, external-provider identity, SQL in Go, destructive migrations, real-password resets, production rotation/rollout, foreign lifecycle commands, volume/index removal, or rewrites of applied migrations. Preserve money strings, ownership, durable intent, anti-replay and mutation no-retry. Historical author-reported gates are not current verification.

## Design / decisions

1. **F1:** remove optional search transport entirely from core readiness. A configured search component is `unknown` (not measured); disabled search is omitted. Keep `catalog-cards-v1` negotiation. Extract mandatory probes and result policy from ApiKernel; inject clock/probe dependencies, name and validate budgets. SQL queries share a deadline with bounded connect/statement/lock work; reserve time for FPM queue, HTTP and serialization. Do not increase the two-second BFF total. No cross-worker in-memory cache or background fan-out. Liveness remains I/O-free.
2. **F2:** split nonsecret settings from service credentials in base AND prod. Runtime catalog needs metadata/search + document writes/deletes, not index/security/cluster administration. Reconcile gets only existing-index reconciliation; explicit index bootstrap/stage receives scoped index lifecycle. Recovery needs provider API but no search/webhook credential; maintenance, migration and identity operator need neither external API. Seed is development-only. A dedicated, explicitly invoked security provisioner owns fresh/upgrade operations; ordinary starts never rewrite an existing security index. Preserve independent operator TLS access and supply check/rotation/rollback instructions.
3. **F3:** use `Ids::canonical()` on the verified database payment ID for returned identity/path/HMAC; canonicalize UUID only at demo verification. Never normalize opaque tokens, signatures, nonces or provider IDs. Preserve HTTP DTOs and ownership checks.
4. **Tooling:** separate dev-tools Composer lock to retain runtime PHP >=8.3. Verify official PHPStan/PHPUnit releases and compatibility before pinning; scope analysis to changed readiness/payment/adapter code with explicit shapes, no new-error baseline. Keep custom/native suites. Add small dependency/configuration guards.

Rejected readiness alternatives: larger global timeouts still couple optional failure to availability; shared PHP memory cache is not shared across FPM workers; file/DB health caches add coordination and staleness without benefit when core has no search dependency.

## Review focus

- Slow TLS/connect/read/established blackholes must trigger zero optional I/O in core probes; concurrent probes must have bounded connections/work.
- Required database/schema/role/config failures must remain 503; request cancellation and SQL locks must not leave unbounded work.
- Compose overlay deletion must not restore secrets from the base; command startup must not require unused secrets.
- Actual OpenSearch actions, not role names, must deny security/cluster/foreign-index access while allowing catalog writes.
- Mixed UUID confirm must sign the same stored identity and remain idempotent, with forbidden tampering and closed production demo paths.

## Task 1 — baseline and RED

Files: existing test harnesses; `tests/readiness.php`, new focused backend regression/policy tests.
- [x] Capture Git, source/toolchain, round-6 context and route counts.
- [x] Record lock/source hashes, dependency graphs and available platform prerequisites.
- [x] Add native F1 slow/blackhole regression, run unchanged production implementation and record failing budget.
- [x] Add real DB/HTTP lower/upper/mixed F3 regression, record original forbidden link.
- [x] Assert actual merged production credential capability matrix; record inherited admin/payment-secret failures without printing values.

## Task 2 — readiness

Files: `backend-yii2/infrastructure/{ApiKernel,Database,Readiness*,CoreReadinessProbe}.php`, BFF `internal/runtime/{config,server}*`, focused tests and deployment budgets.
Interface: readiness result has HTTP status and `{status,components,capabilities?}`; core policy accepts explicit callable probe and monotonic clock. `Config.ReadinessTimeout` supplies Go's health deadline.
- [x] Run RED core probe with optional timeout/TLS/blackhole endpoints.
- [x] Extract mandatory checks; remove optional I/O; enforce typed validated shared budget and short SQL work.
- [x] Verify healthy, required failures, cancellation, repeated/concurrent probes and I/O-free live.
- [x] Run actual Go → Nginx/FPM → PHP → PostgreSQL with search fault and edge startup dependency; measure distributions.

## Task 3 — service privileges

Files: base/prod Compose, search provisioning/start scripts, command-role configuration, production validators/tests and `SERVICE_PRIVILEGES.md`.
Interface: per-service explicit SQL/search/provider/webhook environment; dedicated search security bootstrap and upgrade command; scoped runtime/reconcile/indexer users.
- [x] Failing merged-model tests first.
- [x] Define narrowly scoped roles/actions and credential injection with explicit command requirements.
- [x] Implement idempotent fresh install and explicitly invoked existing-security-index upgrade; preserve operator access.
- [x] Test real TLS OpenSearch allowed operations and denied security, cluster, foreign index and runtime lifecycle actions; repeat provisioning and document credential revocation/rollback.

## Task 4 — demo identity

Files: `application/PaymentService.php`, demo boundary in `ApiKernel.php`, new native tests.
Interface: unchanged confirm DTO; canonical payment UUID used for paymentId/path/HMAC.
- [x] Native RED lower/upper/mixed confirm/link chain.
- [x] Minimal canonical identity fix.
- [x] GREEN GET/POST, repeat confirm/provider count, signature/ID tampering and disabled/production denial.

## Task 5 — tooling/dependencies

Files: separate `backend-yii2/dev-tools/` manifests, scoped PHPStan config, test-stage Dockerfile/Makefile/backend CI and small architecture guards; dependency reports.
- [x] Recheck official releases and each module's graph/why; preserve existing versions unless an evidenced targeted update is needed.
- [x] Select verified compatible analysis tools, generate real locks, run strict scoped analysis and meaningful regressions.
- [x] Run Composer validate/audit/platform/lint and canonical BFF/ops full test/race/vet/build/lint.

## Task 6 — full verification / publication

- [x] Run full native PHP/auth/SQL roles/payment/search suites and source-bound backend acceptance; preserve existing required gates.
- [x] Recheck authoritative advisory freshness, source/binary govulncheck, exact image digest/SBOM scans and secret/config negative policies. Executed; security FAIL, not waived.
- [x] Run unchanged platform gates where available; report current failures/NOT RUN separately. Executed Safari/keychain failures retained; Android installed also executed in continuation and FAILED after successful UI payment, at its post-logout verifier read.
- [x] Independent whole-diff review; fix material findings and rerun affected checks.
- [x] Populate READINESS, SERVICE_PRIVILEGES, DEPENDENCIES, TEST_RESULTS, FINAL_REVIEW with actual commands/exits/source bindings.
- [x] Inspect intended diff/secrets/scope and current remote ref. Subsequent user instruction **"Make commit"** explicitly authorizes the task-only local commit despite the recorded release blockers; no historical permission is relied upon.
- [ ] Only after mandatory release gates pass: explicit nonforce push of the feature branch, then compare the exact target-ref SHA. Current failed gates remain binding.

## Execution closeout

- Intentional code/config/test diff, new source inventory, secrets and scope reviewed; index was empty at verification closeout. Remote target independently re-queried before the requested local commit: unchanged `160d2a5a535791c75c36c2f70a90515153339e87`.
- Current continuation receipt: **503 inputs**, **12 image bindings**, **tested:true**, source map `c3db40d2b43556578453aa0ccd3f525c2aa92201852f2ec184e81540bb9e9242`. Only the dev-tools README changed since the prior accepted source map; full canonical/tools/security were freshly executed.
- Functional acceptance PASSED; final security FAILED; platform FAILED / partially NOT RUN. Commit and push were initially **NOT RUN**. The user's later **"Make commit"** now explicitly permits a local commit; push/publication remains **NOT RUN** and blocked. This permission does not turn a failed or unrun gate into PASS.
- Independent review found and closed three F2 issues, then two final integration issues. The remaining README scope-list minor was closed in continuation and the new input binding verified.
- Continuation executed the unchanged Android installed journey: one test / one failure / zero skips. Real UI payment succeeded CAPTURED/PAID; a later verifier read after global logout got the required 401. Client/test/security-version behavior was preserved. Vendor/advisory follow-up still establishes no supported complete security remediation today.
- Ruling: use the selected existing feature checkout, per user branch instruction, without an extra worktree/approval round. Cost: work remains in this checkout; preexisting unrelated receipts were preserved.
- Ruling: use native pgsql/sockets only for bounded core readiness because synchronous PDO cannot meet the agreed connect/TLS deadline. Cost: two required native extensions and a narrowly scoped adapter; actual packaged timeout/cancellation/backpressure regressions passed.
- Ruling: cross-FPM admission uses two immutable local-file inodes; ordinary PHP memory is not shared. Cost: deployment must retain local per-container inodes, not NFS/SMB; readonly www-data and rapid cancellation verified.
- Ruling: do not promote an unqualified search major or waive security/platform failures. Cost: release remains blocked despite functional fixes.
