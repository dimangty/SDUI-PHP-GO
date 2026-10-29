# Round 3 — backend hardening

Task baseline: `30a882952f0e92e369fd7f31f192aecbbffd6b62`, branch
`refactor/backend-production-hardening`, upstream `origin` on GitHub. Initial
index/worktree/untracked set: empty. Remote feature ref matched baseline.

## Scope and execution

PHP retains business authorization, payments and PostgreSQL ownership. Go consumes
PHP over HTTP and builds the existing SDUI contract. Client source, mobile jobs,
public contracts and existing migration bytes are protected. Work proceeds in the
current feature checkout as requested; no worktree switch or production operation.

1. Record baseline, toolchains, contracts/migration hashes and native checks.
2. Reproduce F1/F2/F3 with correct-behavior regression tests before fixes.
3. Implement bounded PHP catalog/order reads and explicit internal card projection;
   separate new device enrollment from owner-authorized legacy revocation.
4. Introduce consumer-owned Go ports/read models, isolate HTTP transport and split
   SDUI validation by responsibility while preserving wire semantics.
5. Evaluate dependency metadata and tracing; align local and CI backend checks.
6. Run disposable integration, contract, performance and fail-closed security gates.
7. Independent review, final-snapshot checks, scoped Git checkpoint; publication
   only after mandatory gates pass.

## Implementation tasks

Execution: native coordination with independent PHP read, PHP credential and Go
boundary work; whole-snapshot independent review after integration. Apply TDD to
each defect before implementation. Spec: `ARCHITECTURE_ADR.md`.

- [x] PHP reads: add `repositories/CatalogReadRepository.php` and
  `OrderReadRepository.php`, pure mappings; modify CatalogService, CommerceService,
  OpenSearch and ApiKernel readiness. Test native PostgreSQL page counts
  0/1/20/100, snapshots, ownership, projection Unicode and full detail preservation.
- [x] Credentials: Rules enrollment/address distinction, CredentialService legacy
  revoke and explicit console remediation; tests both credential kinds, invalid ID
  matrix, foreign ownership and family revocation. No existing migration changes.
- [x] Go: consumer-owned models/ports/application, Yii explicit conversion and fixed
  projection negotiation, transport boundary, SDUI responsibility split; large-page
  RED/GREEN tests, independent schema/consumer checks and package import tests.
- [x] Dependencies/observability: record complete module graph/update metadata before
  changes, evaluate exact releases and security, pin real instrumentation only if
  privacy/no-replay tests pass. Enforce golangci-lint in backend CI.
- [ ] Integration: build isolated artifacts, all existing PHP/Go/concurrency suites,
  exact payload sizes, query plans/counts and same-fixture latency; retain raw logs.
- [ ] Security/review/Git: source/binary/image scans and gate negatives; fresh reviewer,
  final checks, secret scan, explicit staging and publication decision.

Functional source work and independent reviews are recorded in the implementation
reports. Integration/security execution is finished but acceptance remains blocked
by the exact failures in TEST_RESULTS.md/SECURITY.md; unchecked acceptance items
are not promises of green results. Publication is withheld. Only a local checkpoint
preserves the reviewed work; it is not security approval or release completion.

Review focus: old PHP capability failure, empty projected pages, 257-code-point
snippet completeness, foreign legacy credential deletion, home partial failures
versus 401/403 cancellation. Each belongs to its respective task's regression set.

## Evidence policy

New command receipts and logs live in `evidence/` alongside this document (paths
are repository-relative and portable). `scripts/backend_check.py` records argv,
cwd, UTC times and raw exits. Prior round-2 reports are historical only. Failed
attempts remain visible; missing execution is NOT_RUN. No secrets/PII in logs.

Host launcher Go is 1.25.3; module toolchain selection must be verified independently.
Host PHP/Composer are unavailable; Docker is available (linux/arm64 daemon).
