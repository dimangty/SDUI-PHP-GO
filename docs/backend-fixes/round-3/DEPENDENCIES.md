# Round 3 — dependency decisions

Native BFF toolchain remains **Go 1.27.3**; launcher Go 1.25.3 automatically selects
it. PHP/Composer are Docker **8.3.36 / 2.10.3**; no PHP lockfile update. Runtime
builders/digests are captured in build logs and scanner provenance. Ops source
uses its existing Go 1.26 module; delivered ops binaries use 1.26.10.

| Dependency | Baseline → final | Reason / decision |
|---|---|---|
| Go | 1.27.3 → 1.27.3 | Official stable metadata confirmed; no downgrade/update claim |
| jsonschema/v6 | v6.0.3 → v6.0.3 | Independent local-schema contract tests; no runtime/network loader |
| x/text | indirect v0.43.0 → same | Existing graph retained |
| x/sync | absent → absent | Home fan-out is already bounded at three; explicit optional/security cancellation retained |
| OTel core/trace/SDK/OTLP HTTP | absent → v1.47.0 | Actual injected server/build/outbound spans; stdlib has no SDK/exporter |
| otelhttp | absent → absent | Reviewed v0.72.0, Go ≥1.26 / OTel 1.47; manual bounded HTTP instrumentation avoids default URL attributes and transport wrapping |
| x/net | absent → indirect v0.62.0 | OTel initially resolved v0.59.0: five reachable advisories; v0.60.0 still had two raw module advisories, GO-2026-6860/6866; targeted v0.62.0 clears raw source/binary findings |
| x/sys | absent → indirect v0.49.0 | Required by selected x/net; no manually invented version |
| golangci-lint | pinned v2.15.0 → same | Now actually invoked in backend CI; local check uses tidy `-diff` |

Graph/update snapshots **before changes**: `baseline-modules`,
`baseline-module-updates`, `baseline-dependency-graph`. Final module selections
and complete license/minimum-Go inventory are in OBSERVABILITY_IMPLEMENTATION.md
and the native metadata receipts. OTel requires Go ≥1.26, Apache-2.0 plus bundled
Go BSD notice. Its 21 added requirements include six direct requirements (two
OTLP wire-test imports) and fifteen indirect; transitive licenses are Apache,
BSD and MIT. Later security selection changes x/net/x/sys to the values above.

Official proxy/release metadata was queried on 2026-10-29 UTC. The bulk Python
release-fetch attempt failed host CA verification; curl then hit GitHub HTTP 403.
Those failed receipts are retained, not called complete release verification.
Individual official Go/OTel/otelhttp/linter metadata and module metadata succeeded.

Fresh scanner run: Composer, both Go source modules and all four delivered Go
binaries have **zero raw findings**. Image findings remain; see SECURITY.md.
The resolved graph is not claimed universally vulnerability-free.

Alternative/rollback: retain bounded metrics with tracing disabled. Set
`SDUI_TRACE_ENABLED=false` for immediate export rollback; app-image rollback removes
the SDK dependency. Never revert x/net to a known vulnerable resolution. HTTP
transports, API/CMP schemas and metric names are retained.
