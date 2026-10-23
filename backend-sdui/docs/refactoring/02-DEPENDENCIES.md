# Toolchain and dependency decisions

Verified2026-10-23 using release metadata, not remembered versions.

| Item | Before | Selected / verified latest | Decision / compatibility |
|---|---|---|---|
| Go |1.26.0 module;1.25.3 host launcher|**1.27.3**|Official stable; downloaded toolchain, tests/build and Docker linux/arm64 verified|
| jsonschema/v6 |v6.0.2|**v6.0.3**|Existing test-only independent Draft2020-12 validator; Go≥1.21|
| x/text |v0.14.0|**v0.43.0**|Existing indirect validator dependency; Go≥1.26|
| golangci-lint/v2 |no config|**v2.15.0**|Tool-only, built with Go1.27.3; govet, staticcheck, ineffassign, unused, gofmt|
| govulncheck / x/vuln |no pinned command|**v1.9.0**|Tool-only; Go vulnerability scan|
| x/sync |no direct import|v0.24.0 evaluated|Not added directly: WaitGroup/context suffice for existing bounded home aggregation with deliberate partial/security semantics|
| x/time |none|v0.16.0 evaluated|Not added: no trusted edge IP configuration; new quotas alter status contracts|
| OpenTelemetry SDK |none|v1.47.0 evaluated|Not added: no collector/exporter configured; stdlib metrics and W3C correlation meet current need|
| Trivy |none|**v0.76.1**|Isolated image scanner, no application dependency|

Sources: [official Go downloads](https://go.dev/dl/?mode=json),
`go list -m -u -json all`, `go list -m -json MODULE@latest` for each evaluated
module; [Trivy release metadata](https://github.com/aquasecurity/trivy/releases/tag/v0.76.1).
`gh` is not installed; release API used as fallback.

**No new production third-party dependency.** `go list -deps ./cmd/server` shows
only standard library and this module. JSON Schema has no standard-library
implementation; keeping the existing independent test validator prevents builders
from validating themselves. Risks: third-party validation/Unicode parsing in
tests and downloaded tool code; pin versions/checksums and re-run security scans.
No runtime SDK/exporter or new goroutine lifecycle/support burden.

`go list -m all` also shows x/sync/x/tools/x/mod through updated x/text module
metadata; they are not directly imported or linked into server. Retained regexp2
v1.11.0 is dependency-test metadata, not server code. No unnecessary direct module
was added merely to update unreachable tests-of-dependencies.

`scripts/check.sh` pins tools using go run @version separately from go.mod,
checks formatting as a failing gate, verifies module checksums and supplies an
explicit toolchain default to avoid host/auto-toolchain coverage mismatch.

Docker builder: golang:1.27.3-alpine, index digest
`sha256:b8c9b82affbbb82a020bbcf998dad2ba9516944ac7e883524642a481a9505e9c`.
Runtime: distroless/static-debian12:nonroot, index digest
`sha256:afa5c872c891853ca7fcf1f12c3edb23f7eeef36189728842dd51042ff57f7ab`.
Build: GOTOOLCHAIN=local, -mod=readonly, -trimpath, -buildvcs=false, CGO=0,
BuildKit module/build caches. Digest pinning requires reviewed security updates.
