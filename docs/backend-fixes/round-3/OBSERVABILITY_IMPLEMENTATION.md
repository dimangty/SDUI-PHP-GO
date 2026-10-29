# Go distributed tracing implementation

Approved brief: the focused observability task and `ARCHITECTURE_ADR.md`.
Executed inline in the current feature checkout; no subagents, staging, commits
or pushes. Read the round-3 ADR/Go implementation and current production request,
adapter, observability and runtime files before changing them.

## Implementation and boundaries

- `internal/observe/trace.go` provides an allowlisted manual instrumentation API
  and accepts a constructor-injected `trace.TracerProvider`. No process-global
  tracer/meter provider, propagator, error handler or service locator is installed.
- `trace_config.go` validates dedicated trusted operator settings;
  `trace_provider.go` owns the real **OTel 1.47.0 SDK + OTLP HTTP exporter**,
  batch processor, private exporter HTTP client and lifecycle.
- Runtime constructs one tracing object and injects it through `bff.Config.Tracing`
  and `yii.Config.Tracing`. Disabled mode returns nil and creates no SDK/exporter,
  goroutine or collector connection.
- BFF starts `sdui.http.<operation>` server spans, then
  `sdui.render.<screen>` internal spans around `app.Screen` and document encoding.
  Yii starts `sdui.upstream.<operation>` client spans around each logical call,
  including its existing read-attempt/backoff sequence. Concurrent application
  work carries the real parent context. Commands have server → outbound spans.
- Screen-build instrumentation stays in transport: application/domain/SDUI do
  not import observe/OTel or gain transitive `net/http` dependencies. Existing
  boundary guards pass. Existing metric names/labels/implementation are retained.
- No business `http.Client` or `RoundTripper` is wrapped/replaced. Existing separate
  read/mutation pools, `GetBody=nil`, TLS/redirect policy, two-connection read
  dispatch quota, retries, request deadlines and mutation no-replay remain intact.

### Trust and privacy policy

Enabled public requests always start a **new local root** using `WithNewRoot`,
even if another middleware put a sampled remote span into the context. Incoming
`traceparent`/`tracestate` are neither parent nor link; baggage is never extracted,
captured or propagated. Root sampling is `TraceIDRatioBased(local ratio)`;
children inherit the local root decision. Outbound `traceparent` contains the
actual client span context, including the locally chosen sampled flag. Disabled
mode preserves the previous validated v00 correlation forwarding contract.

Span names/attributes use finite allowlists: `sdui.operation`,
`http.request.method`, server `http.route`, `http.response.status_code`, and
`error.type`. Product/order routes use `/{id}`; unknown routes/methods/operations/
error kinds collapse to `other` (existing `not_found` remains a finite operation).
The static resource is only `service.name=backend-sdui`. No headers, host, raw
URL/query, resource/correlation IDs, bearer, cookies, PIN/password, phone/email,
body, complete error, status description, exception event or panic payload is
recorded. Production SDK limits: eight attributes, 128 characters/value, zero
events/links, panic recording disabled. The instrumented API accepts no raw errors
or arbitrary attribute maps. Existing HMAC log correlation is unchanged.

OTel's exporter/SDK parse ambient `OTEL_*` values **before** explicit options and
can log malformed URLs/certificate paths. Enabled tracing rejects any nonempty
`OTEL_*` setting with a fixed error before calling those constructors. This avoids
environment mutation/global logger interception and also disables ambient resource,
sampler, baggage, exporter headers/TLS/proxy and experimental SDK metric settings.
Disabled tracing ignores ambient OTel variables. This deliberate dedicated-env
policy is part of the operator configuration contract.

Collector response errors, partial-success messages, network errors and exporter
initialization errors are never logged/returned verbatim. The private exporter
discards raw export failures and increments an atomic local `ExportFailures()`
diagnostic (failed batches, not spans). No second metrics pipeline is registered.
`ForceFlush` is best effort: collector rejection is not an application error;
deadline errors have fixed safe text. A fresh-process test captures actual default
SDK stderr, including async exports and malformed ambient configuration.

### Resource/deadline guarantees

Span completion uses the SDK's **nonblocking drop-on-full queue**, never its
blocking option. Defaults retain at most 256 queued spans plus the current batch
of at most 64; export requests are capped at 1 MiB and responses at 4 KiB. The
exporter's separate verified-TLS HTTP client has one connection, bounded headers,
no proxy-environment lookup, no cookie jar, no redirects and no exporter retries.
It does not probe the collector during startup.

Collector down/slow/rejecting cannot change business results or insert a collector
wait into the business request path. SDK/span/serialization bookkeeping still
consumes CPU, allocations and scheduler time. This is not a zero-overhead or
wall-clock-isolation guarantee. Sampled traces can be lost on queue overflow,
collector failure or shutdown deadline; there is no replay/persistence of telemetry.

Shutdown follows both listener drains and receives the **remaining shared runtime
shutdown context**, capped again by the trace shutdown timeout. On expiry, the
exporter lifetime is canceled so the SDK's background queue drain cannot continue
network work through its own per-batch deadlines. Explicit flush and each export
have separate bounded timeouts. Request cancellation remains attached to the
business client context; exporter I/O uses an independent lifecycle context.

## Operator environment / compose handoff

Fields are exposed in `runtime.Config.Tracing` and read explicitly by `config.go`.
The coordinating main agent owns compose passthrough.

| Environment | Default | Valid range / meaning |
| --- | --- | --- |
| `SDUI_TRACE_ENABLED` | `false` | Boolean opt-in |
| `SDUI_TRACE_ENDPOINT` | empty | Required when enabled; absolute HTTP(S) full collector URL, no userinfo/query/fragment; e.g. `http://collector:4318/v1/traces` |
| `SDUI_TRACE_SAMPLE_RATIO` | `0.1` | Finite 0..1; independently chosen local root ratio |
| `SDUI_TRACE_QUEUE_SIZE` | `256` | 1..8192 completed spans, nonblocking overflow drops |
| `SDUI_TRACE_BATCH_SIZE` | `64` | 1..512 and ≤ queue size |
| `SDUI_TRACE_BATCH_TIMEOUT` | `1s` | Positive, ≤30s |
| `SDUI_TRACE_EXPORT_TIMEOUT` | `1s` | Positive, ≤30s, no exporter retry |
| `SDUI_TRACE_FLUSH_TIMEOUT` | `1s` | Positive, ≤30s |
| `SDUI_TRACE_SHUTDOWN_TIMEOUT` | `2s` | Positive, ≤30s and bounded by remaining `SHUTDOWN_TIMEOUT` |

Endpoint path is used as provided; no `/v1/traces` suffix is appended. Operator
URLs may contain a path but are never span attributes. Invalid configuration
returns fixed messages without echoing URLs or supplied values. There is no
user-input route/header/query/body mechanism for changing these settings.

## Exact dependency delta, minimum Go and licenses

The module stays **Go 1.27.3**; installed OTel 1.47.0 metadata requires **Go
1.26.0**. Existing `jsonschema/v6 v6.0.3` and `x/text v0.43.0` are unchanged.
There are **21 added go.mod requirements**: six direct (including two direct test
wire-decoding imports) and fifteen indirect. No `-u`/mass updates, replacements,
vendoring or `otelhttp` imports/direct requirements were added.

`go list -m -json all`, `go mod graph`, `go mod verify` and production package
dependencies are captured in `otel-modules`, `otel-module-graph`,
`otel-module-verify` and `otel-production-dependencies`. The full selected module
graph also includes dependency-test/tool references not linked into the server
(including an upstream `otelhttp v0.70.0` reference); it should not be mistaken
for the production import graph. The official HTTP exporter's configuration and
generated OTLP code do bring gRPC/gateway packages into the production graph even
though actual export uses HTTP. No metric SDK is linked or initialized.

| Added module | Version | Direct? | Minimum Go | License in module cache |
| --- | --- | --- | --- | --- |
| `go.opentelemetry.io/otel` | `v1.47.0` | yes | 1.26.0 | Apache-2.0 + bundled Go BSD-3-Clause notice |
| `go.opentelemetry.io/otel/trace` | `v1.47.0` | yes | 1.26.0 | Apache-2.0 + Go BSD-3-Clause notice |
| `go.opentelemetry.io/otel/sdk` | `v1.47.0` | yes | 1.26.0 | Apache-2.0 + Go BSD-3-Clause notice |
| `go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp` | `v1.47.0` | yes | 1.26.0 | Apache-2.0 + Go BSD-3-Clause notice |
| `go.opentelemetry.io/proto/otlp` | `v1.11.0` | yes, test decoding | 1.25.0 | Apache-2.0 |
| `google.golang.org/protobuf` | `v1.36.12` | yes, test decoding | 1.23 | BSD-3-Clause |
| `go.opentelemetry.io/otel/exporters/otlp/otlptrace` | `v1.47.0` | no | 1.26.0 | Apache-2.0 + Go BSD-3-Clause notice |
| `go.opentelemetry.io/otel/log` | `v1.47.0` | no | 1.26.0 | Apache-2.0 + Go BSD-3-Clause notice |
| `go.opentelemetry.io/otel/metric` | `v1.47.0` | no | 1.26.0 | Apache-2.0 + Go BSD-3-Clause notice |
| `go.opentelemetry.io/auto/sdk` | `v1.2.1` | no | 1.24.0 | Apache-2.0 |
| `github.com/cenkalti/backoff/v5` | `v5.0.3` | no | 1.23 | MIT |
| `github.com/cespare/xxhash/v2` | `v2.3.0` | no | 1.11 | MIT |
| `github.com/go-logr/logr` | `v1.4.4` | no | 1.18 | Apache-2.0 |
| `github.com/go-logr/stdr` | `v1.2.2` | no | 1.16 | Apache-2.0 |
| `github.com/google/uuid` | `v1.6.0` | no | no go directive | BSD-3-Clause |
| `github.com/grpc-ecosystem/grpc-gateway/v2` | `v2.30.0` | no | 1.25.0 | BSD-3-Clause |
| `golang.org/x/net` | `v0.59.0` | no | 1.26.0 | BSD-3-Clause |
| `golang.org/x/sys` | `v0.48.0` | no | 1.26.0 | BSD-3-Clause |
| `google.golang.org/genproto/googleapis/api` | `v0.0.0-20260928230214-8a89bd6388cc` | no | 1.26.0 | Apache-2.0 |
| `google.golang.org/genproto/googleapis/rpc` | `v0.0.0-20260928230214-8a89bd6388cc` | no | 1.26.0 | Apache-2.0 |
| `google.golang.org/grpc` | `v1.83.2` | no | 1.25.0 | Apache-2.0 |

License texts were read from each listed module's `LICENSE` (`xxhash` uses
`LICENSE.txt`). OTel 1.47.0 license files retain the additional Go BSD notice;
these are not represented as Apache-only. This inventory is implementation
metadata, not the coordinating release/security review.

`go.sum` adds 57 lines without removing existing sums: 28 new module versions
with zip/mod pairs, plus the historical `logr v1.2.2/go.mod` checksum. The seven
new sum-only versions beyond the table are dependency-test dependencies:
`github.com/golang/protobuf v1.5.4` (BSD-3-Clause),
`github.com/google/go-cmp v0.7.0` (BSD-3-Clause),
`github.com/stretchr/testify v1.12.1` (MIT),
`go.opentelemetry.io/otel/sdk/metric v1.47.0` (Apache-2.0 + Go BSD notice),
`go.uber.org/goleak v1.3.0` (MIT), `go.yaml.in/yaml/v3 v3.0.5`
(MIT and Apache-2.0 for different files), and `gonum.org/v1/gonum v0.17.0`
(BSD-3-Clause). Their license files were also inspected. They are not new server
imports. Full MVS selections, including unloaded dependency graphs, are retained
verbatim in the metadata receipts rather than rewritten into this table.

## TDD and native verification receipts

All named receipts/logs live in `evidence/` and were saved using
`scripts/backend_check.py` from the repository root. Toolchain: Go 1.27.3,
darwin/arm64, Apple M4 Pro.

| Check | Evidence label | Result |
| --- | --- | --- |
| Missing-span behavior before implementation | `otel-missing-span-red` | Intentional RED: outgoing header was exactly the untrusted incoming sampled parent |
| SDK chain/propagation/sampling/privacy | `otel-chain-green-initial`, `otel-focused-green` | PASS |
| Operator config and drain-before-export lifecycle | `otel-runtime-green` | PASS |
| Final native complete suite | `otel-native-tests-final` | PASS |
| Full native race suite | `otel-race-final` | PASS, no race reports |
| Added independent export-timeout regression under race | `otel-export-timeout-race` | PASS |
| `go vet ./...` | `otel-vet` | PASS |
| `go build ./...` | `otel-build` | PASS |
| `go mod tidy -diff` | `otel-tidy-diff` | PASS, empty diff |
| Module checksum verification | `otel-module-verify` | PASS |
| Formatting / scoped whitespace | `otel-format-check`, `otel-diff-check` | PASS |
| Protected schema/fixture/metric/transport paths | `otel-protected-paths` | Empty diff |

Coverage includes exact server → build → client parent IDs and exported W3C
header; sampled incoming header **and preinstalled sampled remote context** at
local ratio zero; privacy sentinels in headers/query/valid profile command/invalid
PIN-password command/upstream error/resource IDs/personal response fields;
attributes/events/status descriptions and real decoded OTLP wire output; fresh
process stderr capture; disabled/up/unreachable/blocked collector business
requests; cancellation reaching the real upstream; pool reuse; three warmed idle
connections with at most two failing read dispatches; all six mutation calls
receiving a simulated committed-write/lost-response exactly once; one span per
logical operation; queue overflow and export/flush/shutdown cancellation.

The queue test holds one export open, completes 10,000 more spans with queue=2 /
batch=1, then confirms **exactly three spans exported** (one in flight + two
queued). This verifies drop-on-full rather than merely testing that requests
return. The deadline tests verify actual collector HTTP request cancellation.

One test-fixture failure was diagnosed and fixed: its collector notification
channel blocked after 16 exports under race. `otel-focused-race` preserves the
failing stack/receipt; the earlier outer-harness timeout left only `otel-race.log`
(no completed JSON receipt). Notification is now nonblocking; the full final race
suite passes. No production workaround was added for this fixture bug.

`TestLiveYiiEightScreensAndActions` reports **SKIP** because
`YII_INTEGRATION_ORIGIN` is unset. Existing native architecture, schema/golden,
projection, device, error/retry and deadline/no-replay checks pass. No public
schema, golden/mobile bytes or concurrent PHP/application architecture files were
edited by this work.

## Same-fixture overhead benchmark

`otel-benchmarks`: `go -C backend-sdui test ./internal/bff -run '^$'
-bench '^BenchmarkTracingProductHTTP$' -benchmem -benchtime=2s -count=3`.
Each mode uses the identical public product fixture, handler and real business
HTTP client. Enabled modes use the production batch processor/OTLP HTTP exporter
against a local empty-success collector. Medians of three runs:

| Mode | ns/op | B/op | allocs/op | Time vs disabled |
| --- | ---: | ---: | ---: | ---: |
| Disabled | 124,539 | 35,341 | 258 | baseline |
| Enabled, ratio=0 | 127,567 | 38,581 | 298 | +2.4% |
| Enabled, ratio=1 | 136,746 | 49,200 | 361 | +9.8% |

Individual disabled times: 123,933–128,642 ns/op; enabled ratio=0:
127,133–129,174; enabled ratio=1: 135,574–139,288. This is local serial HTTP
microbenchmark evidence including asynchronous exporter CPU/allocations. Batch
queue drops are permitted and not measured by this benchmark; it is not a durable
delivery, tail-latency, production-load, PHP/PostgreSQL or whole-stack performance
claim. Repeat on deployment hardware for capacity decisions.

## Changed files and coordinating handoff

Production: `internal/observe/trace{,_config,_provider}.go`,
`internal/bff/http.go`, `internal/yii/client.go`,
`internal/runtime/{config,server}.go`, `go.mod`, `go.sum`, `README.md`.
Tests: new `internal/{observe,bff,yii,runtime}/trace_test.go` and
`internal/bff/trace_benchmark_test.go`. This report and `evidence/otel-*` are the
documentation/evidence deliverables.

Compose passthrough, real collector deployment/whole-stack integration, dependency
security scanning, mandatory security gate and independent review remain the
coordinating main agent's assigned stages. This report claims only the focused
native Go implementation and checks above, not whole-integration/security completion.
