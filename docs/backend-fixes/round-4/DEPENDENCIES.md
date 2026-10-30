# Round 4 — dependency verification, 2026-10-30 UTC

| Dependency/tool | Before → after | Decision / graph / license |
|---|---|---|
| Go BFF | 1.27.3 → 1.27.3 | **Unchanged**, official stable Go download metadata. Host launcher 1.27.2 selects native 1.27.3; no rewritten go.mod or compatibility shim. BSD-3-Clause. |
| jsonschema/v6 | 6.0.3 → 6.0.3 | **Unchanged**, current official release; direct offline schema validation dependency. Apache-2.0. |
| OTel core/SDK/trace/metric/log/exporters | 1.47.0 → 1.47.0 | **Unchanged**, aligned release train. Existing HTTP exporter/manual privacy instrumentation retained. Apache-2.0. |
| protobuf-go | 1.36.12 → 1.36.12 | **Unchanged**, current official release; direct OTLP wire-test dependency and exporter graph. BSD-3-Clause. |
| grpc-go | indirect 1.83.2 → **1.85.0** | Official stable release published 2026-10-27. Go minimum 1.26.0; protobuf 1.36.12, compatible with selected toolchain/graph. Apache-2.0. Targeted update only; not an unsupported vulnerability claim about 1.83.2. |
| golangci-lint | 2.15.0 → 2.15.0 | **Unchanged**, current official release; local script/CI use the same pinned `go run` tool. GPL-3.0 tooling, not shipped in application. |
| x/net / x/sys / x/text | 0.62.0 / 0.49.0 / 0.43.0 → same | Existing security floors retained; no broad `go get -u`. BSD-3-Clause. |
| PHP | 8.3.36 → 8.3.36 | **Unchanged**, current supported 8.3 security release (official PHP metadata, 2026-10-21); required pdo_pgsql, intl, mbstring, sodium, openssl, gd, curl retained. Host PHP absent. |
| Composer | 2.10.3 → **2.11.0** | Current stable, published 2026-10-29; exact official multi-platform image digest pinned. GHSA-c968-33g3-wh7p affected range includes 2.10.3, patched 2.11.0; local untrusted installed.json prerequisite is not claimed exploited here. GHSA-fm2w-xgf2-f9fh and release security fixes also reviewed. MIT tool, not a new application dependency; composer.lock unchanged. |
| Yii2 / php-jwt | locked 2.0.55 / 7.2.1 → same | Composer lock bytes unchanged; current locked production audit returned zero advisories/abandoned packages. BSD-3-Clause. |

Sources are official GitHub releases APIs (`grpc/grpc-go`, `open-telemetry/
opentelemetry-go`, `santhosh-tekuri/jsonschema`, `protocolbuffers/protobuf-go`,
`golangci/golangci-lint`) and `https://go.dev/dl/?mode=json`, fetched again today.
Raw responses/dated command exits are `evidence/releases-*.{json,log}`.
`final-module-updates-retractions` records Go proxy selections/retractions;
`grpc-update` and `grpc-tidy` record the only module update. Tidy changes exactly
one go.mod requirement and its two go.sum entries. No OTel train/security floor
change, framework, errgroup, Redis or broker was added.

Composer was rechecked against `getcomposer.org/versions` and the official
`composer/composer` release and repository security-advisory APIs. The global
advisory API initially returned 404; the repository advisory endpoint succeeded
and supplies affected ranges. `composer-211-image-metadata` records index digest
`278dfc7b…`; only `/usr/bin/composer` is copied into the unchanged PHP 8.3.36
runtime. The 2.11 removal of dist-to-source fallback is intentional: pinned locked
dist installs must succeed without that fallback. Native install/autoload,
platform requirements, locked audit and full API/payment/SDUI parity are required
again after the pin; final receipts are in TEST_RESULTS.md.

The full module graph reports further transitive/tool/test updates, including
grpc-gateway/proto/otlp and upstream test-only OTel modules. They were not promoted
by a bulk update: real executable metadata retains aligned OTel 1.47.0 and the
selected exporter dependency set. No retracted selected module was reported.

## Executable graph versus reachability

`baseline-grpc-why` / `final-grpc-why`: application observe → OTLP HTTP exporter →
internal otlpconfig → grpc. Full before/after module graphs and the real server's
package graph are retained. Inclusion is not proof that an application gRPC RPC
path executes: runtime export remains HTTP/protobuf with native wire, TLS,
deadline/cancellation, no-retry, privacy and shutdown tests. Binary/source advisory
results and the built executable's module metadata are separate evidence, not an
inference from `go.mod` membership.

`native-go-check` passed native Go 1.27.3 tidy-diff, verify, gofmt nonzero guard,
vet, all unit/race tests, build, independent contracts, pinned lint and source
govulncheck after the grpc update. Subsequent test-only additions are covered by
the final receipts in TEST_RESULTS.md. Composer production audit exit 0 is
`composer-production-audit`; PHP extensions/version receipts are adjacent.

## Vendor images and rollback

Fresh official vendor metadata still identifies Keycloak **26.8.1** and OpenSearch
**2.19.7** on the supported 2.x line (3.9.0 is a major upgrade). This is release
metadata, **not** a clean security scan or waiver of previous findings. Exact
current image scans/SBOMs, freshness and source/build/test provenance determine
the final publication state. No arbitrary JDK/JAR substitution or unsupported
major migration was made to clear a counter.

Rollback: revert only the grpc requirement/checksum change to its saved prior
selection if compatibility requires it, keeping Go and x/* security floors;
rerun the complete module/OTel/security checks. Operational tracing can be
disabled independently. Neither option waives an image/runtime publication gate.
Composer rollback must not silently restore a version within the confirmed
advisory range; use a supported fixed tool/image and revalidate the locked build.
