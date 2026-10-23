# ShopKMP independent Go SDUI BFF

Working HTTP-only BFF over the Yii2 business API. Go **1.27.3**; runtime uses only
the standard library. No database driver, database configuration, SQL access,
identity-provider credentials or business-data mock fallback.

## Layout

* `internal/yii`: bounded typed HTTP client and canonical commerce DTOs.
* `internal/domain`: independent money, identifier and query wire contracts.
* `internal/sdui`: sealed component props, finite actions, validation/revisions.
* `internal/bff`: HTTP adapter, constructor-injected application, eight screen
  builders and six reauthenticated commands.
* `internal/observe`: JSON slog correlation and bounded in-process metrics.
* `internal/runtime`, `cmd/server`: configuration, HTTP lifecycle and healthcheck.
* `contracts`: three Draft 2020-12 schemas and 13 deterministic shared fixtures.
* `tests/evidence`: actual live HTTP screen/business payloads and step manifest.

Wire names, UIKit mapping, controlled forms and native action semantics:
[`../docs/SDUI_CONTRACT.md`](../docs/SDUI_CONTRACT.md).
Execution evidence: [`../docs/SDUI_IMPLEMENTATION_REPORT.md`](../docs/SDUI_IMPLEMENTATION_REPORT.md).

## Run

From `backend-sdui/`:

```sh
YII_ORIGIN=http://127.0.0.1:58088 go run ./cmd/server
```

Public SDUI routes are on 8080. Internal `GET /health/live` and `/health/ready`
are on **9090**, not on the application handler. Readiness checks Yii's fixed
`/health/ready` route, which checks its business dependencies; liveness is
process-only. SIGTERM/SIGINT close listeners and drain in-flight requests within
the shutdown deadline. HTTP connections have header/read/write/idle timeouts and
a 16 KiB header ceiling. The Docker image is nonroot, shell-free distroless,
with CA certificates and the server's own minimal liveness healthcheck.

```sh
docker build -t shopkmp-sdui-task2 .
docker run --detach --name shopkmp-sdui-task2 \
  --network shopkmp-migration-test \
  --publish 127.0.0.1:58091:8080 \
  --publish 127.0.0.1:58092:9090 \
  --env YII_ORIGIN=http://shopkmp-yii2-task1-http:8080 \
  shopkmp-sdui-task2
curl http://127.0.0.1:58092/health/ready
curl http://127.0.0.1:58091/api/v1/screens/home
```

These loopback publications are for the disposable test stack. Deployment's
internal network should expose only SDUI routes through its public edge and
keep the operational listener internal. Task4 owns that orchestration. Host
58089 is used by the existing reference service in the tested environment.

## Environment

Environment is read directly; no `.env` autoload. Defaults:

| Variable | Default | Meaning |
| --- | --- | --- |
| `YII_ORIGIN` | `http://yii2-http:8080` | Operator-fixed HTTP(S) origin, no credentials/path/query/fragment |
| `LISTEN_ADDR` | `:8080` | Public SDUI listener |
| `ADMIN_ADDR` | `:9090` | Separate internal listener; port must differ |
| `UPSTREAM_TIMEOUT` | `3s` | Total deadline of a Yii operation including its retry/backoff |
| `UPSTREAM_RETRY_BACKOFF` | `50ms` | Cancellable GET backoff, maximum 1s |
| `MAX_UPSTREAM_BODY` | `2097152` | Maximum upstream body bytes, configurable 1..8388608 |
| `REQUEST_TIMEOUT` | `8s` | Total BFF request context deadline |
| `READ_HEADER_TIMEOUT` | `3s` | HTTP request headers |
| `READ_TIMEOUT` | `10s` | HTTP request/body read |
| `WRITE_TIMEOUT` | `12s` | HTTP response write |
| `IDLE_TIMEOUT` | `30s` | HTTP client keepalive idle |
| `SHUTDOWN_TIMEOUT` | `10s` | Graceful drain deadline |

Durations must be positive, at most 2 minutes. Configuration requires
upstream < request < write and read-header ≤ read. Fixed non-configurable wire
limits: document 1 MiB, 500 nodes/actions, depth 16, text 8192 Unicode characters;
command body 32 KiB. Unknown query/body members do not configure the client.

Yii connections ignore proxy environment, have no cookie jar and never follow
redirects. GET uses a bounded keepalive pool (64 connections/host, 16 idle/host,
90s idle). A shared connection-acquisition budget counts transparent stale-read
retries together with the explicit one-retry policy. Mutations use a separate
non-reusing transport and remain single-attempt, including empty POSTs and
Idempotency-Key requests. AddToCart passes the original
idempotency key. No sensitive credentials occur in generic SDUI fields/actions.

## Tests

```sh
go test ./...
go test -race ./...
go vet ./...
docker build -t shopkmp-sdui-task2 .
```

Complete backend-local checks (formatting fails on any unformatted Go file):

```sh
sh scripts/check.sh
go test ./internal/bff -run '^$' -fuzz '^FuzzStrictCommandJSON$' -fuzztime=10s -parallel=4
go test ./internal/bff -run '^$' -fuzz '^FuzzScreenQuery$' -fuzztime=10s -parallel=4
go test ./internal/bff ./internal/yii -run '^$' -bench 'Benchmark(HomeHTTP|HomeHTTPObserved|ClientRead)$' -benchmem -benchtime=2s -count=3
```

The script pins golangci-lint v2.15.0 and govulncheck v1.9.0 separately from
server dependencies. First execution needs network/module-cache access.
See [audit](docs/refactoring/01-AUDIT.md),
[architecture](docs/refactoring/03-ARCHITECTURE.md),
[verification](docs/refactoring/08-TEST_RESULTS.md),
[benchmarks](docs/refactoring/09-BENCHMARK.md) and
[rollback](docs/refactoring/11-ROLLBACK.md).

The independent schema validator is imported only by `_test.go` files. It and
its transitive module dependency are not linked into the server. Tests exercise
real BFF/client implementations over HTTP test servers, cancellation and
security boundaries. Shared fixtures are checked for exact builder drift and
independently validated. To deliberately regenerate after a reviewed wire change:

```sh
UPDATE_FIXTURES=1 go test ./internal/bff -run TestPublished
```

### Live Yii/Keycloak/SQL journey

Requires the already-running **disposable** Task1 stack at 58088. The test
registers/logs in random valid emails through PHP, then uses native opaque
sessions, native credential/checkout/payment HTTP boundaries and real BFF
commands. No SQL driver or direct SQL/credential provisioning is used.

```sh
YII_INTEGRATION_ORIGIN=http://127.0.0.1:58088 \
BFF_INTEGRATION_ORIGIN=http://127.0.0.1:58091 \
SDUI_EVIDENCE_DIR=../../tests/evidence \
go test -race ./internal/bff -run TestLiveYiiEightScreensAndActions -v -count=1
```

`SDUI_EVIDENCE_DIR` is relative to the `internal/bff` test working directory.
Without `BFF_INTEGRATION_ORIGIN`, the test starts the actual BFF handler in an
HTTP test server with the same real Yii upstream. Without
`YII_INTEGRATION_ORIGIN`, this single opt-in live test reports SKIP. Default
tests require no business stack. Auth/PIN/password/key requests and token
responses are never saved. Test accounts, addresses, replay records and cancelled
orders remain in the disposable stack; order cancellation restores stock.

Native checkout verification drives the existing canonical HTTP boundary; it
does not launch CMP or claim platform-renderer/device-hardware verification.

## Bounded collection/content handoff

* Cart supports `page`/`size` (default0/50, maximum100). All rows are reachable
  through explicit Navigate continuation; every nonempty page keeps update/remove,
  full-cart total/count and native checkout. After mutation reload the source cart
  page; out-of-range collection pages normalize to the last existing page.
* Home/catalog support independent `categoryPage`/`categorySize` (default0/50,
  maximum50). Category paging retains the catalog product query; product paging
  retains the category window. `state.collections` and finite destination
  `categories`/`cart` objects publish the exact coordinates/counts. A 100-product
  request never loses products to category action expansion.
* Cards use at most256-code-point description previews with an explicit truncated
  flag. Detail preserves valid10k descriptions in ≤4096-code-point Text blocks.
  Image IDs derive from media content; detail includes actual rating/unrated state.
* Search input is ≤200 **UTF-8 bytes**, published as `maxUtf8Bytes:200`; phone
  validation is the canonical mandatory-plus/nonzero-first 8–15-digit rule.
* Raw upstream401/403 remains a security failure even for malformed/oversize bodies;
  home cancels siblings and returns a sanitized failure with that status.

The shared contract documents required local Back navigation and the inherited
native `retryOnceIfIndeterminate` payment-confirmation behavior that Task3 must
reconcile. BFF single-attempt guarantees do not silently extend to that native code.

## Root shared-edge deployment (Task4)

`cp .env.example .env && make run` from the repository root builds both services
and publishes only the edge. `make integration-up integration-check` exercises
this BFF and Yii through `http://127.0.0.1:58095`, including all eight independent
schema checks and six commands. BFF→Yii stays `http://yii2-http:8080`; its
operational listener and all persistence/identity credentials remain private.
See [deployment](../docs/DEPLOYMENT.md) and [actual Task4 evidence](../docs/VERIFICATION_REPORT.md).

The reviewed Task3 remediation has now removed the inherited blind native
payment-confirmation retry described in the historical handoff above; its
regression/platform evidence is in `docs/CMP_IMPLEMENTATION_REPORT.md`.

## Observability

Runtime emits JSON slog to stdout. Records contain finite route/operation labels,
status, durations and safe error kinds. Request/trace correlation is logged only
as process-keyed HMAC fingerprints (`request_id_hash`, `trace_id_hash`): even a
syntactically valid client ID might contain a credential or personal identifier.
No token, personal data, body, raw URL or query logging. Wire request IDs and
validated W3C v00 traceparent remain unchanged; correlation never grants authority.

Private `GET /metrics` is available on ADMIN_ADDR (9090), alongside health.
Prometheus-text counters/histograms cover HTTP, Yii operations, SDUI validation/
revision/serialization, errors, timeouts, retries, connection reuse and last probe
status. Labels use finite allowlists; no per-account/token/request-ID metric labels.
Metrics reset at restart. No collector or infrastructure stack is required.
Keep the admin listener private; public metrics/health remain 404.
See [client policy](docs/refactoring/05-HTTP_CLIENT.md) and
[security boundaries](docs/refactoring/07-SECURITY.md).
