# Round 4 — targeted backend corrections

Baseline: `f8f5f33a9174daa56226ea35eb360e4936491097`, clean index/worktree,
`refactor/backend-production-hardening`, GitHub `origin`. The branch is not
protected; the remote default is `master`. Work stays in the requested folder.

## Design and execution

PHP retains business rules and PostgreSQL ownership. Preserve existing application
ports, independent wire DTOs, SDUI validators, compact cards, bounded transport and
OTel instrumentation. Changes are additive/targeted, with no mobile or public
contract edits and no old migration edits. User's round-4 specification authorizes
inline implementation following this short plan.

- [x] Record native baseline, protected-file hashes and current operation inventory;
  inspect round-3 evidence and reproduce defects against current sources.
- [x] R8-01: validate/canonicalize catalog UUID identities once before batch reads;
  preserve first-hit rank and deduplicate by UUID value. Canonicalize search writes,
  deletes and category terms. Test native PostgreSQL/OpenSearch and document a
  write-drained, rollbackable index replacement with a dry-run legacy inventory.
- [x] R8-02: bind the validated create boolean, retaining default true only for an
  absent field; exercise DB/admin/public/search visibility and invalid inputs.
- [x] R8-03: explicit transaction ownership; preserve the original throwable and
  SQLSTATE when cleanup fails, quarantine uncertain connections, never retry work.
  Test rollback/state/commit faults and native connection termination.
- [x] A8-01/02: request hit metadata without `_source`; expose bounded exporter
  failure count through existing protected runtime metrics. Keep business/readiness
  behavior independent from collector health. Extract pure PHP DTO mapping where
  touched reads currently depend on the write repository.
- [x] Recheck official dependencies and executable reachability; update only a
  verified compatible dependency, preserving toolchain and security floors.
- [x] Run native suites, actual HTTP/contract checks, formatting/static/race checks,
  disposable full-stack recovery/readiness and source-bound image security gates.
  Preserve raw RED/FAIL receipts, resource observations and exact blockers.
  Functional arm64 acceptance passed; image policy failed; amd64/remote CI NOT_RUN.
- [x] Independent review and bounded evidence/rollout with final diff/secret and
  invariance verification. A release commit/push requires all mandatory gates
  green. The user's explicit incomplete-checkpoint exception may preserve work
  locally, consistent with the existing round-3 checkpoint; it is labeled
  INCOMPLETE and **never pushed/published through the failed gate**.

## Initial constraints discovered

Host: Go 1.27.2 launcher, Docker 29.6.2/Compose 5.3.1; no host PHP, Composer,
PostgreSQL client, pinned linter, govulncheck or Trivy. Use native project Go
toolchain selection and Docker PHP/PostgreSQL; do not downgrade requirements.
The installed filesystem read/search tools currently fail with `Bun is not
defined`; shell/Python read-only fallback is necessary. Existing running
`shopkmp-migrated` containers belong to a different project and are untouched.
`BACKEND_SDUI_REVIEW_V4.md` and a separate round-4 repro are not present in the
workspace; the user's detailed findings are the available starting specification.
