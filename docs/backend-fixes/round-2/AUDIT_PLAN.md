# Round 2 — audit and implementation plan

**Goal:** correct UUID identity and webhook CIDR handling, reproduce bounded payment
queue contention on PostgreSQL, and enforce artifact security policy in CI.

**Architecture:** PHP retains business ownership; Go uses the real HTTP API.
Use existing application services and test fixtures. Compare stored UUID identity,
canonicalize validated advisory-lock identifiers, parse the entire CIDR list before
matching, and preserve financial/recovery state machines and bounded scheduling.

**Tech stack:** repository-pinned PHP/Composer Docker image; PostgreSQL 17;
Go versions in each module/Dockerfile; actual JSON Schema Go dependency.

**Spec:** the user's R1–R4 requirements in this session, including preservation
requirements and conditional publication gate.

## Initial audit (2026-10-29)

- Initial HEAD `ebea663fd22daf6b1e44e05aa6d2ac6bf933f077`;
  feature branch `refactor/backend-production-hardening`, upstream
  `origin/refactor/backend-production-hardening`, initially +0/-0.
- Index, worktree and untracked set initially empty. Existing client work is
  committed history, not attributable to this task. No mobile writes authorized.
- Configured origin `https://github.com/dimangty/SDUI-PHP-GO.git` (no embedded credential).
- Docker 29.6.1 / Compose 5.3.0, linux/arm64 daemon; host Go 1.25.3,
  Python 3.14.3; PHP/Composer absent from host. Use container toolchains.
- Named review/probe/log attachments are not present in the checkout. User-provided
  probe descriptions are modeled-PDO evidence; adapted Go results are not native
  verification. Historical A1 report is not a fresh scan.
- Use a new disposable project and distinct networks. Do not operate unrelated
  running stacks or production volumes.

## Global constraints

No client/schema/route/error/money/header changes, no Go business-DB access, no
old migration edits, no diagnostic validator/toolchain substitutions. Never
normalize opaque tokens, signatures, device IDs, provider IDs or request bodies
used for existing idempotency fingerprints. Publication requires all mandatory
checks and security disposition; unresolved vendor findings remain blockers.

## Review focus

1. Case-insensitive identity must retain authorization and resource linkage.
2. Recovery advisory locks must serialize worker/console and balance nesting;
   old generation completion must not clear a newer fence.
3. Invalid CIDR anywhere in a list must fail closed before a matching entry wins.
4. Busy rows must not become financial failures or hide unexpected SQL errors.
5. Missing/stale scanner DB, reports or platform artifacts must fail the gate.

## Task 1 — baseline

- [x] Record original tests, migration hashes and factual route/screen/command matrix.
- [x] Build original images and start isolated PostgreSQL/Keycloak/search/fixtures.
- [x] Run original unit/API/payment/session/trust/recovery/Go/integration checks;
  record command, toolchain, raw exit and evidence, including blockers.

## Task 2 — R1 (Ids, PaymentOperations, CredentialService, recovery services)

- [x] Add native case variants for confirm/refund/void/webhook and EdDSA/ES256
  challenge login, including authorization and negative cases; observe RED.
- [x] Compare actual stored UUID identities without rewriting request bodies.
- [x] Add two-connection advisory-lock and generation tests; observe RED, then
  canonicalize validated recovery IDs and condition fence completion on generation.
- [x] Run new tests and existing payment/session suites.

## Task 3 — R2 (Network, ApiKernel, security/readiness/trust-live tests)

- [x] Table-driven malformed CIDRs and mixed lists, readiness and signed webhook
  negatives first; observe RED.
- [x] Strict parser/whole-list validation; bare IP is exact, mapped IPv6 retains
  IPv6 family for CIDR matching. Readiness and protected request fail closed.
- [x] Run native/live Nginx source/signature/spoofing regressions.

## Task 4 — R3 (PaymentOperations, recovery-scheduling tests)

- [x] Two expired SENT payments A<B; block A's order from another connection;
  repeated bounded scans/two workers must progress B without replay. Observe RED
  or retain a genuine counterexample if risk is disproved.
- [x] If reproduced, use bounded candidate selection and order-first nonblocking
  claim with payment/operation locks in the existing order; preserve budget and
  error propagation. Release A, verify progress and no HTTP replay.

## Task 5 — R4 (scripts, Makefile, existing backend CI)

- [x] Verify installed/official scanner versions before pinning. Test policy with
  clean, blocking, failed/missing scan and explicit approved-disposition fixtures.
- [x] Scan Composer production lock, Go source and delivered binaries, all runtime
  image artifacts/platforms; retain raw JSON, SBOM, DB/version/time and immutable IDs.
- [x] Wire local command and existing CI to scan the same artifacts used by live
  tests; fail closed and upload evidence even on rejection.
- [x] Investigate fresh findings against supported vendors; no unilateral waivers.

## Task 6 — verification/review/publication

- [x] Full available regression, old migration checksums, fresh/upgrade/rollback,
  Compose variants and API/CMP compatibility checks.
- [x] Record decisions, command results/security matrix, limitations and final review.
- [ ] Only if required gates permit: stage owned files, inspect secrets/mobile
  exclusion, commit, push feature branch and verify exact remote SHA. Otherwise
  preserve local work and report NOT PUSHED/BLOCKED.

Publication condition is **not satisfied**: fresh image policy is FAILED. The
user subsequently requested **“Make commit”**, explicitly authorizing a local
checkpoint. Push/publication remains BLOCKED; exact blockers are preserved in
FINAL_REVIEW.md. amd64/remote CI and a new production TLS run are NOT RUN, not
implicitly checked off as successful verification.

Execution is inline as requested, using installed executing-plans and TDD skills.
Commit steps are deferred until the user's explicit verification/publication gate.
