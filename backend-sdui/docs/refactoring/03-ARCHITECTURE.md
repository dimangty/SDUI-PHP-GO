# Architecture and design brief

## Intent and invariants

Harden the existing Go SDUI BFF for maintainability, secure HTTP integration and
measurable operation, without recreating the application. Only `backend-sdui/`
may change. Yii remains the sole business/authorization/persistence authority.
Eight screens, six commands, all routes, methods, statuses, DTO JSON, error
envelopes, schemaVersion=1, component/action/navigation types and published
fixtures remain compatible. No hardcoded production business data.

The user requested end-to-end autonomous execution including audit, plan, actual
implementation, independent final review, commit and push, explicitly without
stopping after planning. Execution is inline, with one independent final reviewer.

## Alternatives considered

1. **Recommended: incremental boundaries in the current packages.** Keep existing
   builders and schemas; separate application orchestration from HTTP, introduce
   small consumer-owned ports and move shared value/query types into domain.
   Lowest contract-drift risk and no gratuitous directory churn.
2. Full hexagonal directory migration: more visible layers but large mechanical
   churn, little benefit for eight existing builders. Rejected.
3. HTTP-client-only patch: lowest implementation cost but leaves mixed command
   orchestration and domain-to-adapter dependency. Insufficient for this brief.

## Target responsibilities

* `internal/domain`: framework-independent identifier, money and query contracts;
  no HTTP client, logging, database or SDUI builder dependency.
* `internal/sdui`: typed finite v1 wire model, validation, deterministic revision.
* `internal/bff`: constructor-injected application orchestrator, screen builders,
  command validation/execution; thin existing HTTP entry point parses/serializes.
  Ports reflect actual read/auth/write use; no repository/service locator.
* `internal/yii`: canonical upstream DTOs and HTTP adapter. Old shared type aliases
  preserve internal consumers while DTO-to-SDUI mapping stays explicit.
* `internal/observe`: slog and bounded, concurrency-safe process metrics; no SDK,
  exporter, storage, background goroutines or infrastructure stack.
* `internal/runtime`, `cmd/server`: configuration, composition, private health and
  metrics, lifecycle and signal handling.

## HTTP and concurrency

Fixed operator-configured HTTP(S) origin, verified TLS, no environment proxy,
redirect following or cookies. Pool GET connections with explicit idle/host caps;
mutations remain single-use/no-replay, even with Idempotency-Key. Read and decode
only bounded responses. Preserve raw 401/403 independently of response decoding.
Keep request-scoped context deadlines and cancellable backoff. Home preserves
its existing bounded 2–3 independent reads and partial/security semantics.
Any further parallelization must prove error precedence and cancellation.

HTTP authentication syntax, routes/methods/query validation and error mapping
stay in transport; authentication against Yii and command mutation sequencing
belong to application. Preserve response/error ordering established by baseline
tests. No speculative data caching or business calculations.

## Observability and security

JSON slog request/upstream/render records contain process-keyed HMAC fingerprints
of validated request/trace IDs (not their client-controlled raw values),
finite operation/route labels, durations, status and safe error kinds. No raw
URLs, token/body/header dumps, user/device/product IDs or personal data. Trace
context is correlation only, not identity. Admin-only metrics expose counters,
latency summaries/histograms, timeouts, retries, connection reuse and probe state.
No public /metrics or pprof; no unbounded label maps. Do not add rate limits based
on untrusted forwarding headers or alter existing request status contracts.

Final-review hardening: HTTP/1.1 is explicitly selected. The read retry budget
synchronously closes an over-budget assigned connection before any request write
and rejects over-budget detached dials; context cancellation alone is insufficient
when cancellation races with delivery of an already idle connection.

## Validation and rollout

Freeze baseline via current 13 byte-exact goldens plus new route/error and all-six
action schema tests before changes. Add meaningful regression tests for pooling,
mutation replay, TLS/redirect isolation, contexts, telemetry redaction, boundaries
and validation fuzzing. Measure identical before/after local HTTP workloads with
connection/request counts, latency quantiles, throughput, allocations and memory.
No production performance claim from loopback benchmarks.

Run fresh tests, race, vet, strict format, lint v2, govulncheck, module verify,
build, schema tests, real-PHP journey, Docker build/run/probes/shutdown and image
scan where available. Review independently; fix all in-scope Critical/High.
Rollback is the previous backend image/commit, with no database migration.
