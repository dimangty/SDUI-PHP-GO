# Production Go SDUI Refactoring Implementation Plan

> **For agentic workers:** Use executing-plans to implement inline task-by-task.

**Goal:** Harden the real Yii-backed BFF while preserving all existing wire contracts.

**Architecture:** Incremental separation inside current packages; independent domain
contracts, application orchestration, thin HTTP adapter, bounded Yii HTTP client.
Use standard-library telemetry and existing immutable SDUI models.

**Tech Stack:** Go 1.27.3, net/http, slog, sync/atomic, existing jsonschema/v6
test validator; pinned golangci-lint v2 and govulncheck tools.

**Spec:** [03-ARCHITECTURE.md](03-ARCHITECTURE.md).

## Global Constraints

* All changes exclusively inside `backend-sdui/`; preserve existing user work.
* Eight screens, six commands, schemaVersion=1; no fixture/schema regeneration.
* Yii owns business logic, identity and data; HTTP only, no SQL or fake fallback.
* Preserve mutations as single-attempt and exact validated Yii error envelopes.
* Native execution, independent final review; final commit/push after checks.

## Review Focus

* Stale pooled connections must not replay a mutation with an idempotency key.
* A failed parallel dependency must not hide a sibling's 401/403.
* Malicious request/trace IDs and URLs must not leak tokens/PII or create labels.
* Domain extraction must not change money/query JSON or stable screen revisions.
* Cancellation must stop network/backoff work and server shutdown must drain.

## Task 1: Freeze behavior and measurements

Files: add `internal/bff/baseline_contract_test.go`,
`internal/bff/benchmark_test.go`, `internal/yii/benchmark_test.go`.
Consumes: existing `New(*yii.Client, Config)`, schemas, fixtureUpstream.
Produces: exact route/error regression matrix, six schema-valid command roundtrips,
`BenchmarkHomeHTTP`, `BenchmarkClientRead` and repeatable workload metrics.

- [x] Add passing characterization tests for status/code/Allow/cache and all commands.
- [x] Run `go test ./... -count=1 -cover` and explicit contract tests.
- [x] Run benchmarks with `-benchmem`, retain results in 09 before code changes.
- [x] Run existing opt-in live test against 58098 using local current BFF.
- [x] Check `git diff --name-only` against allowed prefix.

## Task 2: Separate contracts and application transport

Files: add `internal/domain/{values,query}.go`, application/port files in
`internal/bff`; modify sdui model, yii aliases/query and bff HTTP/actions/builders.
Consumes: Task 1 immutable contracts. Produces consumer-owned backend interfaces,
`Application.Screen(ctx, id, name, resource, q) (sdui.Screen, error)` and
`Application.Execute(ctx, id, command, key) (CommandResponse, error)`;
existing `New` remains the HTTP construction entry point.

- [x] Add domain value/direct application tests RED; verify import boundary with go list.
- [x] Extract values/query validation without changing JSON; retain yii aliases.
- [x] Extract command preparation/execution and screen authentication from HTTP.
- [x] Keep strict JSON/query parsing, headers and error serialization in transport.
- [x] Run full tests and golden/schema tests; check scope and diff.

## Task 3: Secure reusable Yii read transport

Files: `internal/yii/client.go`, new transport/response helpers and hardening tests.
Produces `CloseIdleConnections()` and bounded read connection pool; all mutations
remain single-attempt on a non-reusing transport. No public generic URL API.

- [x] Add failing read reuse tests; cleanup and stale/bodyless mutation regressions.
- [x] Implement pooled reads (64 total idle, 16 idle/host, 64 connections/host,
  90s idle) and dedicated non-reusing mutation transport with existing deadlines.
- [x] Extract response classification retaining status, body limits and envelopes.
- [x] Add TLS verification, cancel/backoff, oversize/redirect/cookie isolation tests.
- [x] Run focused tests, race tests and same baseline benchmarks; check diff.

## Task 4: Safe observability and lifecycle

Files: add `internal/observe/`; modify bff, yii, runtime and main constructors.
Produces constructor-injected recorder with finite labels, JSON slog, private
`GET /metrics`, validated trace correlation and no extra background workers.

- [x] Add redaction, finite-label, concurrent metric and admin-isolation tests RED.
- [x] Implement request/upstream/render duration/status/timeout/retry/reuse records.
- [x] Wire recorder at runtime, close idle upstream connections on shutdown.
- [x] Preserve existing probe contracts; add metrics/probe observations privately.
- [x] Run tests/race and inspect representative safe logs; check scope.

## Task 5: Toolchain, reproducible build and security checks

Files: go.mod/go.sum, Dockerfile/.dockerignore, `.golangci.yml`,
`scripts/check.sh`, fuzz tests, README and documents 02,05–09,11.
Produces Go 1.27.3 build, current compatible test dependencies and backend-only
checks; no unused x/sync, x/time or OpenTelemetry dependency.

- [x] Update only verified stable module versions; run tidy and module verify.
- [x] Add useful query/strict-command fuzz targets and run bounded smoke sessions.
- [x] Pin Docker builder patch/digests, retain distroless nonroot, add build cache.
- [x] Run strict format, vet, unit/integration/contract/race, build, lint, govulncheck.
- [x] Run real PHP full journey, build/run Docker probes and shutdown, scan image.
- [x] Document actual evidence, before/after limits, residual risks and rollback.

## Task 6: Independent final review and delivery

Files: `docs/refactoring/{10-FINAL_REVIEW,FINAL_REVIEW}.md`, findings-driven fixes.

- [x] Independent read-only review of full diff against spec and user requirements.
- [x] Reproduce/fix both Medium findings; no Critical/High; full post-fix checks.
- [ ] Final `git status --short`, `git diff --check`, scope and secret review.
- [ ] Stage only owned backend files, inspect cached diff/check/stat.
- [ ] Commit with the exact requested message; push current feature branch.
- [ ] Compare HEAD with origin branch SHA and report actual remote URL/status.

## Plan self-review

All work maps to the audit/spec; no schema/fixture change is planned. Tests cover
the five Review Focus cases. Native execution and one final independent reviewer
follow the supplied end-to-end instruction. Documentation progress/evidence is
updated as tasks execute; unavailable checks are recorded as blockers, not passes.
