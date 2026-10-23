# Go SDUI audit

Baseline: `ad95258` on `refactor/backend-production-hardening`; clean index and
working tree. Remote: `https://github.com/dimangty/SDUI-PHP-GO.git`. Only
`backend-sdui/` is owned by this task. Audit date: 2026-10-23.

## Existing contract and strengths

Eight GET screens under `/api/v1/screens/`: home, catalog, product/{UUID}, cart,
orders, order/{UUID}, profile, devices. POST `/api/v1/sdui/actions` accepts exactly
AddToCart, UpdateQuantity, RemoveFromCart, CancelOrder, UpdateProfile, RevokeDevice.
SDUI schemaVersion=1, 13 published fixtures, three Draft 2020-12 schemas. Public
anonymous access is limited to home/catalog/product. Bearer authentication is
verified by Yii `/api/v1/users/me`, including on every command; opaque tokens
are supported. Yii owns authorization, money, stock, totals and business rules.

Already implemented: bounded JSON bodies, duplicate-key rejection, fixed upstream
origin/routes, redirect/proxy/cookie restrictions, typed DTOs, account checks,
concurrent bounded home aggregation, security fail-closed behavior, cancellation,
separate health listener, server timeouts, graceful shutdown, nonroot image.
No SQL, invented business fallback, mutable request cache or unbounded goroutine
fan-out was found. No demonstrated data race or goroutine leak in baseline tests.

## Findings

| ID / severity | File / function | Cause and risk | Recommended change | Required evidence |
|---|---|---|---|---|
| A01 High | `internal/bff/actions.go:handleAction` | Parsing, validation, authentication, ownership and mutation orchestration in one 139-line HTTP handler; transport changes can alter write sequencing | Extract HTTP-independent command preparation/execution; retain one authentication and one mutation | All six command request/response schemas, method/path/header assertions, ownership and no-replay tests |
| A02 Medium | `internal/bff/http.go:Service`, screen builders | Application directly stores concrete `*yii.Client`; HTTP and orchestration share responsibility | Consumer-owned backend ports; separate application service and HTTP adapter using constructors | Existing golden screens and direct application tests |
| A03 Medium | `internal/sdui/model.go` | Domain imports outbound HTTP adapter for Money, query validation and identifiers | Move shared scalar/query contracts to independent domain package; preserve aliases and exact JSON | Money/query unit tests; all goldens byte-identical; import-boundary test |
| A04 Medium | `internal/yii/client.go:New` | All requests disable keepalive, so each read incurs TCP/TLS setup. Existing rationale correctly protects mutation replay | Pool bounded read connections; keep mutations on single-use transport, preserving explicit no-replay policy | Connection-count benchmark, reused/stale connection and mutation tests |
| A05 Medium | `internal/yii/client.go:call` | Long function combines request construction, retry and response classification; harder to maintain security status preservation | Extract request/response helpers and structured observations without changing envelope/status mapping | Existing malformed 401/403, body limits, redirects, cancellation and retry tests |
| A06 Medium | `internal/runtime/server.go`, `cmd/server/main.go` | Plain log messages, no request/upstream/render timing, error/timeout/retry metrics | slog JSON, bounded-cardinality in-process metrics on admin listener, validated correlation/trace IDs | Log redaction, concurrent metrics, admin isolation tests |
| A07 Medium | `go.mod`, `Dockerfile` | Go 1.26.0 and old test-only x/text; mutable minor-only build tag | Verify current releases, update compatible Go/dependencies, pin builder patch and image digests | Downloaded toolchain, module verification, full tests, govulncheck, Docker build |
| A08 Medium | Test/tooling configuration | No reproducible lint/vulnerability/format gate, benchmarks or fuzz targets | Backend-local check script and golangci-lint v2 config; deterministic benchmark/fuzz harness | Run tools, race detector, fuzz smoke and before/after measurements |
| A09 Low | `internal/bff/http.go:parseQuery` | Size check follows URL parsing, permitting avoidable allocations before rejecting input | Check raw query length before parsing | Oversized-query test/fuzz; preserve 400 envelope |
| A10 Low | `internal/runtime/server.go:Run` | No explicit idle upstream pool cleanup when lifecycle ends (becomes relevant after A04) | Close idle connections through lifecycle ownership | Shutdown/drain tests and client pool cleanup |
| A11 Low | `internal/bff/screens.go:catalog,devices` | Independent reads sequential; latency equals sum | Measure then consider bounded pair loading; preserve error priority/security semantics | Deterministic overlap/cancellation tests and latency benchmark |

## Security and external boundaries

No new JWT parser is appropriate: PHP accepts JWT and opaque native sessions.
Do not trust X-User-Id, X-Role, Cookie, X-Forwarded-For or arbitrary trace headers
as authorization. Existing generic action allowlist and fixed API paths stay.
Per-user/IP rate limiting at the BFF cannot safely rely on forwarded client IPs
without edge trust configuration. Avoid changing valid-request status contracts
with an unsolicited global limiter; document the edge requirement. Keep admin
metrics private. Preserve exact validated Yii business error bodies; never log
their messages, bodies, user IDs, paths containing IDs or query values.

## Baseline verification

`go test ./...`, `go test -race ./...`, `go vet ./...` succeeded on the original
Go 1.26.0 module toolchain (initial results were cached). Fresh baseline, coverage,
real-PHP integration and benchmark results are recorded in 08/09. Docker Desktop
is reachable; existing disposable edge on 127.0.0.1:58098 reports readiness OK.
