# V6 Local Auth and Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `executing-plans` or
> `subagent-driven-development`; implement and review each task with RED→GREEN.

**Goal:** Native PHP password/session identity without Keycloak, preserving CMP,
with bounded order lists and complete read-only search.

**Architecture:** Extend existing PHP application/session/repository boundaries.
Keep Go HTTP-only and introduce only the summary model/read port it needs.
Independent order/search work may run in parallel with identity implementation.

**Tech Stack:** PHP 8.3.36/Yii2 2.0.55, PostgreSQL, OpenSearch, Go 1.27.3;
versions are baseline observations, not claims of latest releases.

**Spec:** `ADR.md` and user V6 sections 1–14.

Execution status: functional tasks 1–6 completed with native evidence. Task 7
publication remains **BLOCKED**; checkmarks record performed actions, not a claim
that every release gate passed. See TEST_RESULTS/SECURITY_RESULTS/FINAL_REVIEW.

## Global constraints

- No mobile/core/feature/Gradle/iOS or mobile CI changes.
- Existing migrations are immutable; new SQL uses the existing runner/checksums.
- No JWT/OIDC fallback, external IdP, direct Go SQL, destructive data operations.
- Keep 2 MiB upstream, 1 MiB SDUI, 500 nodes, depth 16 and existing string budgets.
- No mutation replay, no secret-bearing evidence, no push with failed mandatory gates.

## Review focus

1. Stale successful password verification after password change: user-lock snapshot test.
2. Old disabled/recovery-blocked users logging in with preserved devices: credential matrix test.
3. Refresh races/lost response: one winner, bounded family, deterministic re-login policy.
4. A partial index mistaken for healthy empty: build marker and native failure test.
5. Large historical Unicode orders: native summary SQL budget and actual PHP→Go bytes.

## Task 1: baseline and contracts

Files: auth/session/credential services, contracts, round-4 rollout, scripts/check.sh.
- [x] Inspect Git/status/staged/remote/toolchain; preserve source point and user work.
- [x] Read ownership/API/client DTOs and existing security/recovery/schema boundaries.
- [x] Run baseline PHP unit/lint, Go/ops tests and route matrix.
- [x] Capture baseline dependency/package/binary graphs and fresh-stack limitations.

## Task 2: local password/session flow

Files: new domain/PasswordHasher.php, repositories/UserCredentialsRepository.php,
application/LoginThrottle.php; AuthService.php, SessionService.php;
new tools/ops/migrations/015_local_auth.*.sql; tests/local_auth*.php.
Interfaces: PasswordHasher hash/verify/needsRehash; SessionService issue(password),
lockUser/revokeUser; AuthService public signatures and token DTO remain compatible.
- [x] Add failing native register/login/hash/empty-hash/rotation/revocation tests.
- [x] Run and retain RED, implement bounded native hashing and snapshot revalidation.
- [x] Add new schema for password kind, identity state, family expiry and audit.
- [x] Enforce account/IP budgets, disabled state and fail-closed policy for all kinds.
- [x] Exercise wrong/malformed inputs, concurrency, SQL rollback/uncertain commit.
- [x] Run GREEN native tests and focused review.

## Task 3: existing-user operator workflow

Files: new application/LocalIdentityMigration.php, console AuthController.php,
console composition and tests/local_auth_migration.php; docs/backend-fixes/local-auth/.
Interfaces: inventory(): array; per-user attest/reset/disable with explicit ticket,
reviewed flags and idempotence; no HTTP reset endpoint.
- [x] RED: inventory is read-only; existing UUID/business links survive; rerun no-op.
- [x] Implement protected TTY secret input, per-user journal and atomic revocation.
- [x] Require selected pending-operation resolution, preserve other fences/bans.
- [x] Native populated/new DB tests; document cutover matrix and roll-forward boundary.

## Task 4: remove runtime provider and preserve recovery

Files: Compose/env/CI/backend scripts; PHP readiness/recovery; Composer manifests;
provider-specific tests and mixed recovery suites.
- [x] Replace provider auth tests with local equivalents before retiring adapters.
- [x] Separate payment recovery from historical manual identity decisions.
- [x] Remove active service/build/probe/env/JWKS/claims dependencies; archive realm policy.
- [x] Composer why/remove through Composer, keep native biometric crypto.
- [x] Add active dependency-graph guard, historical allowlist, fresh stack without IdP.

## Task 5: independent R9-01

Files: OrderReadRepository/OrderDtoMapper/CommerceService/OpenAPI;
Go model/ports/query/wire/order builders and focused tests.
Interface: `OrderSummaries(...)(Page[OrderSummary],error)`, fixed projection marker.
- [x] RED for overfetch/strict marker/ownership and absent order_items SQL.
- [x] Implement eight-field opt-in projection; preserve full DTO methods.
- [x] Native 0/1/10/20/100 orders, 20×200 Unicode, filters/sorts/UUID, payload budgets.
- [x] Go/race/golden tests and PHP-first rollout documentation.

## Task 6: independent R9-02/R9-03

Files: OpenSearch.php, small strict parser/lifecycle helper, maintenance commands,
search protocol/native fixtures, OpenSearch config and orchestration.
- [x] RED partial envelope and read-side creation tests.
- [x] Strict completeness parser and `allow_partial_search_results=false`.
- [x] Explicit building→verified-ready lifecycle, bounded mutex/build/writes.
- [x] Native missing/empty/partial/interrupted/ready and concurrent jobs/readers.
- [x] Preserve staged physical config; document pre/post-write rollback.

## Task 7: dependencies and final gates

Files: narrowly selected backend manifests/locks/tool pins and new reports.
- [x] Verify official releases/advisories on execution date, preserve before/after graphs.
- [x] Addressed upgrades only; exact Go/PHP platform/audit/security checks executed.
- [x] Go format/tidy/verify/vet/tests/race/build/schema/golden/import/lint/govulncheck executed; final freshness policy BLOCKED.
- [x] PHP syntax/unit/native/concurrency, route matrix/migration/client integrity checked; complete acceptance not GREEN.
- [x] Fresh exact-image Compose/E2E/payment recovery/trust/fault restoration/scans/SBOM executed; failed gates retained.
- [x] Independent whole-diff review; fix in-scope findings and rerun affected tests.
- [x] Write truthful results, budgets, source/image binding and NOT_RUN/BLOCKED gates.
- [ ] Only if required gates pass, stage intended files, inspect full diff, commit/push
      verified working branch, compare remote SHA and inspect exact-SHA CI.

Continuation: complete bound backend acceptance passed in user-authorized isolated
resources, including scheduler/recovery/deadline/trust fixes and a true tested
receipt. Remaining release work: resolve OpenSearch HIGHs under the existing
policy, obtain a fresh authoritative Go DB, and close full native Safari
completion. Production proofing/migration and target-platform checks remain
separate operator gates. All 53 temporarily stopped old containers were restored.

Plan self-review: every V6 area maps to tasks 1–7. No production account data or
role export is available; actual account migration remains an operator gate.
