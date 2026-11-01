# Backend V8 dependency research — 2026-11-01

## Final implementation addendum

The research below is retained with its original retrieval/command timestamps.
Implementation subsequently added **native `ext-pgsql` and `ext-sockets`** for the
bounded readiness adapter; business SQL remains PDO. No root Composer package or
Go module version changed. The added extension/deadline/cleanup rationale is in
READINESS.md; real packaged PHP 8.5.12 regression and image-security results are in
TEST_RESULTS.md. Runtime PHP stays **>=8.3**.

The separate dev-tools project was implemented and actually installed/tested with
**PHPStan 2.3.4 / PHPUnit 13.4.5**, tool-runner PHP **>=8.4.1**. Its generated lock
is independent of the application lock. Final source-bound maximum-level analysis
and PHPUnit **19 tests / 24 assertions** pass. Neither tool is shipped in the
production context.

Final SHA-256 (independently recalculated at closeout):

- Root Composer manifest: `e306f37d1dda7adc8752511a3c8e0d1a293e012d544bbcc5bc8ba58f47038c39`.
- Root Composer lock: `b37709145a61a8683f5712c4875972348ef58737e692d8594b81387c0afbc411`.
- Tools manifest: `12711a557e8dcd712a42341e7130aceafa4296ab2d8c39f240307eb72d8a9965`.
- Tools lock: `28e9b47d7ef0b3597d7a76ebc2048dff11aeda9e80b46bd2d945e72bfae79c85`.
- Both Go module/sum pairs retain the baseline hashes recorded below.

## Original research decision

**Keep the current runtime/toolchain/dependency baseline.** Today's independent
queries confirm PHP **8.5.12**, Composer **2.11.1**, Yii2 **2.0.55**, BFF Go
**1.27.3**, ops Go **1.26.10**, pgx **5.12.0**, grpc-gateway **2.33.0**, OTel
**1.47.0** and JSON Schema **6.0.3**. No evidenced runtime patch upgrade is
required by this research. Do not downgrade pgx to the older release returned by
GitHub's latest-release API, or align the two Go modules by broadly updating them.

For the planned development tools, use an **independent Composer manifest,
lock and vendor directory** with exact PHPStan **2.3.4** / PHPUnit **13.4.5**
pins and a tool-runner requirement of **PHP >=8.4.1**. Preserve the application
root requirement **`php: >=8.3`**.

**Security freshness remains BLOCKED:** the authoritative Go vulnerability DB
reports `2026-10-27T21:16:18Z`; at `2026-11-01T03:16:45.195778Z` its age was
**102.007554 hours**, outside the unchanged **72-hour** policy. Today's
toolchain-matched source scans both returned `No vulnerabilities found.` / exit
0; that does not satisfy the freshness gate or certify delivered images/binaries.

## Scope and provenance

- All network retrievals below were performed **2026-11-01 UTC**; release URLs
  returned as upstream metadata links are identified as such. The initial
  command clock was `2026-11-01T03:14:29Z`.
- HEAD queried independently: `160d2a5a535791c75c36c2f70a90515153339e87`.
- Read the actual `backend-sdui/go.mod`, `tools/ops/go.mod`,
  `backend-yii2/composer.json`, `backend-yii2/composer.lock`,
  `backend-yii2/Dockerfile`, `backend-sdui/scripts/check.sh`,
  `scripts/backend_security.py`, `scripts/backend_security_policy.py`, and
  `docs/backend-fixes/round-6-local-auth/DEPENDENCIES.md`. The round-6 statements
  were context, not this run's verification receipts.
- This research writes only this report. No dependency solver update/install,
  runtime build/pull, tool installation, container inspection/scan, child agent,
  environment dump or secret read was used. Go queries/scans may use normal
  external Go caches; the six dependency manifest/lock/sum files hashed below
  remained identical.
- Preexisting untracked evidence and `round-7/AUDIT_PLAN.md` were preserved. An
  unrelated `scripts/test_service_privileges.py` appeared during the research;
  it was not edited or used as dependency evidence.

Final verification: `git diff --check` and
`git diff --no-index --check /dev/null docs/backend-fixes/round-7/DEPENDENCIES.md`
each exited 0. A final repeat of the six-file SHA-256 command below matched the
initial values. `git status --short` also showed concurrent deployment/PHP/script
work outside this report; those files were not edited by this research, and no
whole-worktree-clean claim is made.

## 1. Official releases and supported runtimes

Successful fetch means the source content was actually retrieved today. A
release-tag page alone proves a version exists; latest indicators, release feeds
and module queries supply the separate latest-version checks.

| Component | Actual primary-source result | Recommendation |
| --- | --- | --- |
| Go | <https://go.dev/dl/?mode=json> returned stable `go1.27.3` and `go1.26.10`. <https://go.dev/doc/devel/release#policy> says releases are supported until two newer major releases exist; both patched releases are dated 2026-10-19 and contain `crypto/x509` security fixes. | Keep BFF `go 1.27.3`; keep ops `go 1.26.0` plus `toolchain go1.26.10`, and actually execute the patched toolchain. Host bootstrap 1.25.3 is outside the supported pair and is not the module-selected runtime. |
| PHP runtime | <https://www.php.net/releases/8_5_12.php> confirms **8.5.12 is a security release**. <https://www.php.net/releases/index.php?json&version=8.5> returns version `8.5.12`, release date `21 Oct 2026`, security tag. | Keep the existing pinned 8.5.12 FPM image; no downgrade or runtime change. |
| Composer executable | <https://getcomposer.org/changelog/2.11.1> confirms release **2026-10-30**; <https://getcomposer.org/versions> identifies **2.11.1** as current stable, minimum PHP **7.2.5**. Notes include GitLab registry authentication, invalid platform-extension names and configuration-key preservation fixes. | Keep existing Composer 2.11.1 pin. The lock's `plugin-api-version: 2.11.0` is not the installed Composer executable version. |
| Yii2 | <https://api.github.com/repos/yiisoft/yii2/releases/latest> returns `tag_name: 2.0.55`, `draft: false`, `prerelease: false`, published `2026-05-09T15:15:08Z`, and <https://github.com/yiisoft/yii2/releases/tag/2.0.55>. Notes include CVE-2026-39850 render-file parameter isolation and newer-PHP compatibility. | Keep lock 2.0.55 and existing compatible `^2.0.53` root constraint. Framework PHP minimum is 7.4, distinct from template requirements. |
| pgx v5 | <https://raw.githubusercontent.com/jackc/pgx/v5.12.0/CHANGELOG.md> starts with **5.12.0 (October 30, 2026)**. <https://proxy.golang.org/github.com/jackc/pgx/v5/@latest> returns **v5.12.0**, time `2026-10-31T01:11:21Z`, upstream tag `refs/tags/v5.12.0`, commit `b2ef08bbb951384e3936e45de60de60609f2d2ba`. Readonly module `@patch` agrees. | Keep 5.12.0, including its security fixes. |
| grpc-gateway | <https://github.com/grpc-ecosystem/grpc-gateway/releases/tag/v2.33.0> loads, is marked **Latest**, released October 27, and describes the security-related default header-matching change. Module update and `@patch` queries retain 2.33.0. | Keep 2.33.0; do not enable `WithLegacyHeaderMatching` to undo the safer default. |
| OTel | <https://github.com/open-telemetry/opentelemetry-go/releases/tag/v1.47.0> loads and is marked **Latest**; title `v1.47.0/v0.69.0/v0.23.0/v0.1.0`, released October 2. Notes include first stable Logs API/SDK, response-size configuration and attribute/propagation fixes. Selected stable OTel modules remain 1.47.0 in update queries. | Keep the aligned API/SDK/trace/exporter/log/metric family. Do not confuse separately versioned experimental modules with updates to these modules. |
| JSON Schema v6 | <https://github.com/santhosh-tekuri/jsonschema/releases/tag/v6.0.3> loads, is marked **Latest**, and lists fixes for locations, NaN/Inf, email quotes and number equality. Module update and `@patch` queries retain 6.0.3. | Keep 6.0.3; its application usage is test-only, as independently checked below. |
| PHPStan | <https://github.com/phpstan/phpstan/releases/tag/2.3.4> loads and is marked **Latest**, released October 28; <https://raw.githubusercontent.com/phpstan/phpstan/2.3.4/composer.json> requires **`^7.4\|^8.0`**, conflicts with `phpstan/phpstan-shim`, and declares `phpstan` / `phpstan.phar` executables. | Exact 2.3.4 pin in separate dev-tools project. PHPStan itself does not require PHP 8.4.1. |
| PHPUnit | <https://github.com/sebastianbergmann/phpunit/releases/latest> resolves to <https://github.com/sebastianbergmann/phpunit/releases/tag/13.4.5>, marked **Latest**, released October 27. <https://raw.githubusercontent.com/sebastianbergmann/phpunit/13.4.5/composer.json> requires **`php >=8.4.1`**, not PHP 8.3. | Exact 13.4.5 pin, separate tools lock and compatible real PHP runner. |

### PHP support is not the same as the application's minimum

<https://www.php.net/supported-versions.php> was retrieved today and explicitly
shows **Today: 1 Nov 2026**. Per-branch release metadata was independently fetched:

| PHP branch | Latest stable patch returned | Active support until | Security support until | Application relationship |
| --- | --- | --- | --- | --- |
| 8.2 | 8.2.35 — <https://www.php.net/releases/index.php?json&version=8.2> | 2024-12-31 | 2026-12-31 | Upstream-supported, below this application's minimum. |
| 8.3 | 8.3.36 — <https://www.php.net/releases/index.php?json&version=8.3> | 2025-12-31 | 2027-12-31 | Root compatibility floor remains `>=8.3`; **not EOL**. |
| 8.4 | 8.4.27 — <https://www.php.net/releases/index.php?json&version=8.4> | 2026-12-31 | 2028-12-31 | Patched 8.4 is a possible tool runner; PHPUnit minimum 8.4.1 is not a recommendation to run that old patch. |
| 8.5 | 8.5.12 — metadata above | 2027-12-31 | 2029-12-31 | Existing deployed-image baseline; satisfies the proposed tools' PHP minimum. |

All four metadata responses date their latest security release **21 Oct 2026**.
PHP 8.6 is not a confirmed stable release from these sources on this execution date.

### Source failures and disagreements, retained explicitly

| Exact URL | Observed result | What is and is not established |
| --- | --- | --- |
| <https://api.github.com/repos/jackc/pgx/releases/latest> | HTTP success, but returns **v5.11.0**, published 2026-09-07. | An older GitHub Release entry is not evidence to downgrade a newer stable upstream module tag. Raw tagged changelog, proxy origin and today's `go list` independently confirm 5.12.0. |
| <https://api.github.com/repos/sebastianbergmann/phpunit/releases/latest> | **403** | API unavailable to this fetch; the successful official latest HTML page and raw 13.4.5 manifest independently establish the version and PHP requirement. |
| <https://api.github.com/repos/ezyang/htmlpurifier/releases/latest> | **403** | API unavailable; successful <https://github.com/ezyang/htmlpurifier/releases/latest> resolves to latest **v4.19.1**, with PHP 8.5 compatibility fixes. |
| <https://github.com/go-yaml/yaml/releases/tag/v3.0.6> | **404** | Incorrect repository path, not proof that 3.0.6 is nonexistent. |
| <https://raw.githubusercontent.com/yaml/go-yaml/v3.0.6/CHANGELOG.md> | **404** | Changelog at that path was not verified. |
| <https://github.com/yaml/go-yaml/releases/tag/v3.0.6> | Success, upstream tag `5a62aba63b773192cd10ab954e21e143d7b00c7a`, tagged October 31. | Version existence confirmed. <https://proxy.golang.org/go.yaml.in/yaml/v3/@v/v3.0.6.info> identifies the same upstream/tag/hash, with module commit time `2026-10-13T18:50:51Z`; commit time and tag-publication time are distinct. No security-fix claim is inferred from missing notes. |

## 2. Local command results and immutability

Commands below were executed in this session. Every module query used
`GOFLAGS=-mod=readonly`; no `go get`, `go mod tidy`, Composer update/install or
manifest generation was performed.

| Working directory | Actual command | Exit/result |
| --- | --- | --- |
| Repository root | `date -u '+%Y-%m-%dT%H:%M:%SZ'` | 0; `2026-11-01T03:14:29Z`. |
| Repository root | `GOTOOLCHAIN=local go version` | 0; `go version go1.25.3 darwin/arm64`. |
| `backend-sdui` | `GOFLAGS=-mod=readonly go version` | 0; `go version go1.27.3 darwin/arm64`. |
| `tools/ops` | `GOFLAGS=-mod=readonly go version` | 0; `go version go1.26.10 darwin/arm64`. |
| Each Go module separately | `GOFLAGS=-mod=readonly go mod graph` | 0 each; actual requirement edges inspected, including version-selection examples below. |
| Each Go module separately | `GOFLAGS=-mod=readonly go mod why -m all` | 0 each; import paths, dependency-test paths and `main module does not need module` results inspected. |
| Each Go module separately | `GOFLAGS=-mod=readonly go mod verify` | 0 each; **`all modules verified`**. This verifies downloaded module contents/checksums, not advisory freshness. |
| Each Go module separately | `GOFLAGS=-mod=readonly go list -m -u all` | 0 each; full actual listings reproduced in section 5. |
| Each Go module separately | `GOFLAGS=-mod=readonly go list -deps -f '{{with .Module}}{{.Path}} {{.Version}}{{end}}' ./... \| sort -u` | 0 each; imported production-source module sets match the tables below, excluding JSON Schema in BFF. |
| Each Go module separately | `set -o pipefail && GOFLAGS=-mod=readonly go list -deps -test -f '{{with .Module}}{{.Path}} {{.Version}}{{end}}' ./... \| sort -u` | 0 each; BFF adds JSON Schema; ops set is unchanged. This lists packages; it does not execute tests. |
| `tools/ops` | `GOFLAGS=-mod=readonly go list -m -f '{{.Path}} {{.Version}} go={{.GoVersion}}' all` | 0; selected pgx/x-text/x-sync require Go 1.25.0; project's actual selected toolchain is patched 1.26.10. Blank `GoVersion` metadata on some graph-only modules is not a support guarantee. |
| Each Go module separately | `GOFLAGS=-mod=readonly go list -m -u=patch all` | **2 each**; `invalid boolean value "patch" for -u: parse error`. This is not a valid `go list` option. Correct explicit `module@patch` queries below succeeded instead; no dependency update was attempted. |

Before the queries, and again after queries/source scans, ran:

```sh
shasum -a 256 backend-sdui/go.mod backend-sdui/go.sum \
  tools/ops/go.mod tools/ops/go.sum \
  backend-yii2/composer.json backend-yii2/composer.lock
```

Both results were identical, exit 0:

```text
006d18cd012b3d4bcf16cc81dd7bbe668a46967a23ecac975cffacb4b1ce2274  backend-sdui/go.mod
8207f9097551b8791b2f632513c0fe292d50aafdd59e1dddd9be54dd2f70b330  backend-sdui/go.sum
68dac28e00b164f6eb52de9a37e48391f18c37116e8223d695f60437d319f1a4  tools/ops/go.mod
c9ec77321f398e347300b758d2bafc7c03983557f5e748c9c017c35c2301b52c  tools/ops/go.sum
26a32bb1d74c8d3e084204d6ec90044e6df70d42b71a5cd915e41c1755719cc5  backend-yii2/composer.json
b1243946d4605de892fc4f4cba9b2ab938fec56af56e06456a29c0aa61f004a7  backend-yii2/composer.lock
```

### Explicit latest-patch queries

The executed query form was:

```sh
GOFLAGS=-mod=readonly go list -m \
  -f '{{.Path}} {{.Version}} go={{.GoVersion}}' MODULE@patch ...
```

In `backend-sdui`, two invocations together queried **every required module**:

```text
github.com/santhosh-tekuri/jsonschema/v6@patch
go.opentelemetry.io/otel@patch
go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp@patch
go.opentelemetry.io/otel/sdk@patch
go.opentelemetry.io/otel/trace@patch
go.opentelemetry.io/proto/otlp@patch
google.golang.org/protobuf@patch
github.com/grpc-ecosystem/grpc-gateway/v2@patch
google.golang.org/grpc@patch
github.com/cenkalti/backoff/v5@patch
github.com/cespare/xxhash/v2@patch
github.com/go-logr/logr@patch
github.com/go-logr/stdr@patch
github.com/google/uuid@patch
go.opentelemetry.io/auto/sdk@patch
go.opentelemetry.io/otel/exporters/otlp/otlptrace@patch
go.opentelemetry.io/otel/log@patch
go.opentelemetry.io/otel/metric@patch
golang.org/x/net@patch
golang.org/x/sys@patch
golang.org/x/text@patch
google.golang.org/genproto/googleapis/api@patch
google.golang.org/genproto/googleapis/rpc@patch
```

Both exited 0. All retain the versions in section 3 except RPC genproto, whose
latest compatible pseudo-version is `v0.0.0-20261026165753-2ffab32bce7e`.
Pseudo-version movement is not a stable tagged security patch.

In `tools/ops`, the same form queried pgx, pgpassfile, pgservicefile, puddle,
x/sync and x/text with `@patch`, exit 0. **All retained current versions**.
In particular, `x/sync v0.24.0` and `x/text v0.43.0` are newer minor versions,
not missing patches to 0.22.x / 0.41.x.

One additional readonly metadata query from `tools/ops` for
`golang.org/x/text@v0.43.0`, `golang.org/x/sync@v0.24.0`,
`google.golang.org/genproto/googleapis/rpc@v0.0.0-20261026165753-2ffab32bce7e`
and `go.yaml.in/yaml/v3@v3.0.6` exited 0: their Go minima were respectively
**1.26.0 / 1.26.0 / 1.26.0 / 1.16**. It did not select them into ops' graph.

## 3. BFF graph, imports and transitives

`backend-sdui` declares `go 1.27.3`, no replacement/exclusion or pgx dependency.
The graph and package listings prove the following selected requirements.
`D` means direct **in the manifest**, not necessarily production-imported.
Paths below abbreviate the common prefix `shopkmp/backend-sdui/internal/observe`
as `observe` and `go.opentelemetry.io/otel` as `otel`.

| Required module | Selected version | Kind | Observed reason/import path |
| --- | --- | --- | --- |
| `github.com/santhosh-tekuri/jsonschema/v6` | v6.0.3 | D, application tests | `internal/application.test → jsonschema/v6`; absent from production `go list -deps`. Minimum Go 1.21. |
| `go.opentelemetry.io/otel` | v1.47.0 | D | `observe → otel/attribute`. |
| `go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp` | v1.47.0 | D | `observe → HTTP exporter`. |
| `go.opentelemetry.io/otel/sdk` | v1.47.0 | D | `observe → otel/sdk/resource` and trace SDK. |
| `go.opentelemetry.io/otel/trace` | v1.47.0 | D | `observe → otel/trace`. |
| `go.opentelemetry.io/proto/otlp` | v1.11.1 | D | HTTP exporter → `collector/trace/v1`. |
| `google.golang.org/protobuf` | v1.36.12 | D | `why`'s shortest path is `observe.test → encoding/protojson`; production dependency listing independently includes protobuf through OTLP/gRPC. Minimum Go 1.23. |
| `github.com/cenkalti/backoff/v5` | v5.0.3 | I | HTTP exporter → `internal/retry → backoff/v5`; Go 1.23. |
| `github.com/cespare/xxhash/v2` | v2.3.0 | I | `otel/attribute → attribute/internal/xxhash → xxhash/v2`; Go 1.11. |
| `github.com/go-logr/logr` | v1.4.4 | I | `otel/sdk/resource → otel → logr`; Go 1.18. |
| `github.com/go-logr/stdr` | v1.2.2 | I | trace SDK → `otel/internal/global → stdr`; Go 1.16. |
| `github.com/google/uuid` | v1.6.0 | I | `otel/sdk/resource → uuid`. |
| `github.com/grpc-ecosystem/grpc-gateway/v2` | v2.33.0 | I | HTTP exporter → OTLP `collector/trace/v1 → grpc-gateway/v2/runtime`; Go 1.26.0. |
| `go.opentelemetry.io/auto/sdk` | v1.2.1 | I | trace SDK → `otel/internal/global → auto/sdk`; Go 1.24.0. |
| `go.opentelemetry.io/otel/exporters/otlp/otlptrace` | v1.47.0 | I | HTTP exporter → shared trace exporter. |
| `go.opentelemetry.io/otel/log` | v1.47.0 | I | resource → `otel → otel/log`. |
| `go.opentelemetry.io/otel/metric` | v1.47.0 | I | HTTP exporter → `internal/observ → otel/metric`. |
| `golang.org/x/net` | v0.62.0 | I | HTTP exporter → `internal/otlpconfig → grpc → x/net/trace`. |
| `golang.org/x/sys` | v0.49.0 | I | resource → `x/sys/unix`. |
| `golang.org/x/text` | v0.43.0 | I | shortest `why` path is application tests → JSON Schema → `x/text/language`; also production-imported through gRPC/x-net. |
| `google.golang.org/genproto/googleapis/api` | v0.0.0-20261026165753-2ffab32bce7e | I | OTLP → gateway runtime → `api/httpbody`. |
| `google.golang.org/genproto/googleapis/rpc` | v0.0.0-20261019180522-ce908d29299b | I | OTLP → `grpc/status → rpc/status`. |
| `google.golang.org/grpc` | v1.85.0 | I | HTTP exporter → `internal/otlpconfig → grpc`, also OTLP bindings/status. |

The OTel 1.47.0 modules, OTLP, gRPC, genproto API/RPC and selected x/net/sys/text
all declare **Go 1.26.0**; BFF's actual 1.27.3 satisfies them.

### Why these versions win (actual graph edges)

- HTTP exporter 1.47.0 requires shared exporter 1.47.0, OTLP 1.11.0, gateway
  2.30.0, gRPC 1.83.2 and September genproto. Root OTLP **1.11.1** requires
  gateway **2.31.0**, gRPC **1.84.0** and protobuf **1.36.12**.
- Root gateway **2.33.0** requires x/text **0.43.0**, x/sys **0.49.0**, x/net
  **0.61.0**, genproto API **20261026165753-2ffab32bce7e**, RPC
  **20261019180522-ce908d29299b** and protobuf **1.36.12**. This explains the
  selected API/RPC pair rather than implying they must share a commit/date.
- Root gRPC **1.85.0** and x/net **0.62.0** remain above upstream requirements;
  minimum-version selection does not downgrade them to 1.84.0 / 0.61.0.
- JSON Schema requires x/text **0.14.0** and regexp2 **1.11.0**; selected x/text
  **0.43.0** wins. `regexp2` appears through **JSON Schema's own tests**, not
  BFF production or BFF's own `go list -deps -test` set.
- OTel / gateway requirement graphs also contain test/tool modules. Examples
  `otel/sdk/metric`, `google/go-cmp`, `testify`, `go.uber.org/goleak`,
  `go.yaml.in/yaml/v3`, `golang/protobuf` and `gonum` have dependency-test paths
  in `why`; the source/test package listings distinguish those from actual
  application imports.
- Most larger OpenAPI, xDS/Envoy, GCP detector and tool-module entries return
  **`(main module does not need module …)`** from `why`. A module graph edge or
  `go.sum` entry alone does not establish runtime use or reachable vulnerability.
- Gateway **runtime is production-imported** through generated OTLP bindings;
  this alone does not prove the application exposes a gateway ServeMux. The
  release's header-forwarding security behavior is therefore retained without
  inventing an exposed-gateway finding.

Primary tagged manifests independently checked:

- <https://raw.githubusercontent.com/grpc-ecosystem/grpc-gateway/v2.33.0/go.mod>
  (includes upstream tool declarations, explaining extra graph-only modules).
- <https://raw.githubusercontent.com/open-telemetry/opentelemetry-go/v1.47.0/go.mod>
  (its local monorepo `replace` directives are not root replacements here).
- <https://raw.githubusercontent.com/googleapis/go-genproto/2ffab32bce7e/googleapis/rpc/go.mod>
  (newer candidate RPC requires Go 1.26.0 and protobuf 1.36.12).

## 4. Ops graph, imports and transitives

`tools/ops` declares `go 1.26.0` and `toolchain go1.26.10`. Today's actual
selected executable is **go1.26.10**, not an assumption based on the minimum.

| Required module | Current version | Actual `why` result / production listing |
| --- | --- | --- |
| `github.com/jackc/pgx/v5` | v5.12.0 | `shopkmp/ops/migrations → pgx/v5`; `cmd/migrate → pgxpool`; direct. |
| `github.com/jackc/pgpassfile` | v1.0.0 | `cmd/migrate → pgxpool → pgconn → pgpassfile`. |
| `github.com/jackc/pgservicefile` | v0.0.0-20240606120523-5a60cdf6a761 | `cmd/migrate → pgxpool → pgconn → pgservicefile`. |
| `github.com/jackc/puddle/v2` | v2.2.3 | `cmd/migrate → pgxpool → puddle/v2`. |
| `golang.org/x/sync` | v0.22.0 | Shortest `why` path is pgx's own tests → `errgroup`; production listing independently includes x/sync through pooling. |
| `golang.org/x/text` | v0.41.0 | `cmd/migrate → pgxpool → pgconn → secure/precis`; production-imported. |

The root and pgx **5.12.0** requirement edges agree on all five indirect pins.
Puddle itself requires x/sync 0.1.0; pgx/root 0.22.0 wins. Tagged manifest
<https://raw.githubusercontent.com/jackc/pgx/v5.12.0/go.mod> confirms Go **1.25.0**
and these exact indirect requirements. x/sync 0.22.0 and x/text 0.41.0 also
require Go 1.25.0. No BFF dependency was added to ops or vice versa.

`go mod why -m all` also shows pgx dependency-test paths for `testify`,
`go-spew`, `go-difflib`, and `gopkg.in/yaml.v3`. `kr/pretty`, `objx`, x/mod,
x/tools and check.v1 have no needed application-module path. Neither application
production nor own-test package lists gained these dependency-test modules.

### Security/compatibility reasons to retain pgx 5.12.0

The successfully read raw 5.12.0 changelog independently documents:

- **GHSA-j38q-h3ph-jjpq**: simple-protocol `SendBatch` SQL injection before
  5.12.0 when a queued query ends in a `--` comment without newline and the next
  query starts with an attacker-controlled string argument. Extended protocol
  modes are unaffected; fix separates batched queries with newline.
- **GHSA-95fj-3fxw-g62g**: 5.9.0–5.11.0 could accept an impersonating server
  that skips authentication despite `require_auth` / `channel_binding=require`
  when server identity is not independently verified. The fix enforces completed
  authentication/channel binding; it does not imply a TLS certificate-verification
  bypass. Do not remove the existing verify-full policy.
- Custom `BatchResults` implementations/mocks need `Tx`; 5.11 added `Rows.TypeMap`.
  This research makes no new source-compatibility/test-pass claim for an update,
  since **5.12.0 is already the baseline**.

Those fixes were announced after the Go DB's reported modification time, making
the upstream release review important even when the stale DB scan has zero findings.

Newer x/text **0.43.0** / x/sync **0.24.0** metadata is compatible with ops'
Go minimum, independently confirmed by
<https://raw.githubusercontent.com/golang/text/v0.43.0/go.mod> and
<https://raw.githubusercontent.com/golang/sync/v0.24.0/go.mod>. Compatibility
alone is not a security/correctness reason to update them. Keep the selected,
already-fixed **0.41.0 / 0.22.0** pair; assess a future targeted update against
fresh advisories rather than broad `go get -u` or cosmetic alignment with BFF.

## 5. Actual complete update listings

These are today's **`GOFLAGS=-mod=readonly go list -m -u all`** results, exit 0
for each module. Brackets show available updates, **not applied updates**, and
not necessarily stable-patch/security fixes. Direct and indirect runtime pins
are accounted for above; graph-only and dependency-test updates are retained
here to avoid calling the entire graph latest when it is not.

### `backend-sdui`

```text
shopkmp/backend-sdui
cel.dev/expr v0.25.3
cloud.google.com/go/compute/metadata v0.9.0 [v0.10.0]
github.com/BurntSushi/toml v1.4.1-0.20240526193622-a339e1f7089c [v1.6.0]
github.com/GoogleCloudPlatform/opentelemetry-operations-go/detectors/gcp v1.35.0 [v1.38.0] (deprecated)
github.com/apapsch/go-jsonmerge/v2 v2.0.0
github.com/cenkalti/backoff/v5 v5.0.3
github.com/cespare/xxhash/v2 v2.3.0
github.com/cncf/xds/go v0.0.0-20260202195803-dba9d589def2
github.com/davecgh/go-spew v1.1.1
github.com/dlclark/regexp2 v1.11.0 [v1.12.0]
github.com/envoyproxy/go-control-plane v0.14.0
github.com/envoyproxy/go-control-plane/envoy v1.39.1-0.20260819172001-e6e3fd93e4be [v1.40.0]
github.com/envoyproxy/go-control-plane/ratelimit v0.1.0
github.com/envoyproxy/protoc-gen-validate v1.3.3
github.com/felixge/httpsnoop v1.1.0
github.com/go-jose/go-jose/v4 v4.1.4 [v4.1.7]
github.com/go-logr/logr v1.4.4
github.com/go-logr/stdr v1.2.2
github.com/go-openapi/analysis v1.0.1
github.com/go-openapi/errors v0.22.9
github.com/go-openapi/jsonpointer v1.0.2
github.com/go-openapi/jsonreference v1.0.3
github.com/go-openapi/loads v0.25.3
github.com/go-openapi/runtime v0.33.3 [v0.33.4]
github.com/go-openapi/runtime/server-middleware v0.33.3 [v0.33.4]
github.com/go-openapi/spec v1.0.1
github.com/go-openapi/strfmt v0.27.4
github.com/go-openapi/swag v0.29.2
github.com/go-openapi/swag/cmdutils v0.29.2
github.com/go-openapi/swag/conv v0.29.2
github.com/go-openapi/swag/fileutils v0.29.2
github.com/go-openapi/swag/jsonutils v0.29.2
github.com/go-openapi/swag/loading v0.29.2
github.com/go-openapi/swag/mangling v0.29.2
github.com/go-openapi/swag/netutils v0.29.2
github.com/go-openapi/swag/pools v0.29.2
github.com/go-openapi/swag/stringutils v0.29.2
github.com/go-openapi/swag/typeutils v0.29.2
github.com/go-openapi/swag/yamlutils v0.29.2
github.com/go-openapi/validate v1.0.1
github.com/go-viper/mapstructure/v2 v2.5.0
github.com/golang/glog v1.2.5
github.com/golang/protobuf v1.5.4 (deprecated)
github.com/google/go-cmp v0.7.0
github.com/google/uuid v1.6.0
github.com/grpc-ecosystem/grpc-gateway/v2 v2.33.0
github.com/kr/pretty v0.3.1
github.com/oapi-codegen/runtime v1.8.0
github.com/oklog/ulid/v2 v2.1.2
github.com/planetscale/vtprotobuf v0.6.1-0.20240319094008-0393e58bdf10
github.com/pmezard/go-difflib v1.0.0
github.com/rogpeppe/fastuuid v1.2.0
github.com/rogpeppe/go-internal v1.14.1 [v1.16.0]
github.com/santhosh-tekuri/jsonschema/v6 v6.0.3
github.com/spiffe/go-spiffe/v2 v2.8.1 [v2.9.0]
github.com/stretchr/testify v1.12.1
go.opentelemetry.io/auto/sdk v1.2.1
go.opentelemetry.io/contrib/detectors/gcp v1.45.0 [v1.47.0]
go.opentelemetry.io/contrib/instrumentation/google.golang.org/grpc/otelgrpc v0.72.0
go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp v0.72.0
go.opentelemetry.io/otel v1.47.0
go.opentelemetry.io/otel/exporters/otlp/otlptrace v1.47.0
go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp v1.47.0
go.opentelemetry.io/otel/exporters/stdout/stdouttrace v1.47.0
go.opentelemetry.io/otel/log v1.47.0
go.opentelemetry.io/otel/metric v1.47.0
go.opentelemetry.io/otel/sdk v1.47.0
go.opentelemetry.io/otel/sdk/metric v1.47.0
go.opentelemetry.io/otel/trace v1.47.0
go.opentelemetry.io/proto/otlp v1.11.1
go.uber.org/goleak v1.3.0
go.yaml.in/yaml/v3 v3.0.5 [v3.0.6]
golang.org/x/crypto v0.58.0
golang.org/x/exp v0.0.0-20261014143641-f0251eb4aa3e
golang.org/x/exp/typeparams v0.0.0-20231108232855-2478ac86f678 [v0.0.0-20261014143641-f0251eb4aa3e]
golang.org/x/mod v0.42.0
golang.org/x/net v0.62.0
golang.org/x/oauth2 v0.36.0 [v0.37.0]
golang.org/x/sync v0.24.0
golang.org/x/sys v0.49.0
golang.org/x/term v0.47.0
golang.org/x/text v0.43.0
golang.org/x/tools v0.52.0
gonum.org/v1/gonum v0.17.0
google.golang.org/genproto/googleapis/api v0.0.0-20261026165753-2ffab32bce7e
google.golang.org/genproto/googleapis/rpc v0.0.0-20261019180522-ce908d29299b [v0.0.0-20261026165753-2ffab32bce7e]
google.golang.org/grpc v1.85.0
google.golang.org/protobuf v1.36.12
gopkg.in/check.v1 v1.0.0-20201130134442-10cb98267c6c
gopkg.in/yaml.v3 v3.0.1
honnef.co/go/tools v0.8.2
```

### `tools/ops`

```text
shopkmp/ops
github.com/davecgh/go-spew v1.1.1
github.com/jackc/pgpassfile v1.0.0
github.com/jackc/pgservicefile v0.0.0-20240606120523-5a60cdf6a761
github.com/jackc/pgx/v5 v5.12.0
github.com/jackc/puddle/v2 v2.2.3
github.com/kr/pretty v0.3.0 [v0.3.1]
github.com/pmezard/go-difflib v1.0.0
github.com/stretchr/objx v0.1.0 [v0.8.0]
github.com/stretchr/testify v1.11.1 [v1.12.1]
golang.org/x/mod v0.38.0 [v0.42.0]
golang.org/x/sync v0.22.0 [v0.24.0]
golang.org/x/text v0.41.0 [v0.43.0]
golang.org/x/tools v0.48.0 [v0.52.0]
gopkg.in/check.v1 v1.0.0-20201130134442-10cb98267c6c
gopkg.in/yaml.v3 v3.0.1
```

**Targeted disposition:** retain RPC's currently selected upstream-compatible
pseudo-version, and retain dependency-test YAML 3.0.5 and graph-only OpenAPI/Jose
patch candidates for this task. There is no verified security/correctness reason
in this research to force new root indirect pins for unused packages or
dependency tests. Their available versions are recorded, not hidden. A fresh DB
or a specific upstream advisory may justify a separately tested targeted change.

## 6. PHP lock and development-tool policy

Only the application Composer manifest/lock existed at initial discovery;
`packages-dev` and `platform-dev` are empty. Root scripts are the custom/native
PHP suites and `php tests/lint.php`; declaring a PHPUnit development dependency
does not by itself convert or replace those suites.

| Locked package | Current requirement / reason | Today's publisher check |
| --- | --- | --- |
| `yiisoft/yii2 2.0.55` | Root `^2.0.53`; framework PHP `>=7.4.0`; ctype, mbstring, PCRE. | Official latest API above confirms 2.0.55. |
| `cebe/markdown 1.2.1` | Yii2 `~1.0.0 \| ~1.1.0 \| ~1.2.0`; PHP `>=5.4.0`. | <https://api.github.com/repos/cebe/markdown/releases/latest> returns stable **1.2.1**, published 2018-03-26. |
| `ezyang/htmlpurifier v4.19.1` | Yii2 `^4.17`; lock explicitly supports PHP minors through **8.5**. | Successful official latest HTML page above confirms v4.19.1; API 403 distinguished. |
| `yiisoft/yii2-composer 2.0.11` | Yii2 `~2.0.4`; plugin API `^1.0 \| ^2.0`; root allows this plugin specifically. | <https://api.github.com/repos/yiisoft/yii2-composer/releases/latest> returns stable **2.0.11**, published 2025-02-13. |

Root `replace` supplies the four Bower asset packages; they are not additional
installed PHP packages. Dependencies' own `require-dev` entries (including old
PHPUnit ranges inside markdown/yii2-composer metadata) are **not** the root's
installed development dependencies.

### Recommended future implementation policy, not changes performed here

1. Add a standalone **`backend-yii2/dev-tools/composer.json` and generated lock**
   with exact `phpstan/phpstan: 2.3.4` / `phpunit/phpunit: 13.4.5` pins and
   `php >=8.4.1`. Keep its vendor/autoloader separate from the application's
   root lock. Generate/review actual transitive locks with Composer on compatible
   PHP; no invented lockfile, platform-ignore or audit suppression.
2. Keep root `require.php` **`>=8.3`**, its current runtime package constraints,
   and its lock platform requirement. A tooling-only PHP minimum does not justify
   raising the production application's advertised floor.
3. Run tools on actual patched PHP **8.5.12** (current baseline), or patched
   **8.4.27** if a separate 8.4 runner is chosen. PHPUnit's tagged manifest
   requires `ext-dom`, `ext-filter`, `ext-json`, `ext-libxml`, `ext-mbstring` and
   `ext-xmlwriter`. Provide/check these in a development/test stage; do not
   assume the current FPM image already has every new requirement. Coverage
   drivers are separate from PHPUnit's basic test execution requirements.
4. PHPUnit's direct dependency ranges include deep-copy `^1.14.0`, phar-io
   manifest `^2.0.4` / version `^3.2.1`, code-coverage `^14.4.1`, file-iterator
   `^7.0.2`, invoker `^7.0.0`, text-template `^6.0.1`, timer `^9.0.0`, the
   tagged manifest's Sebastian packages and side-effects-detector `^1.0.5`.
   These are **requirements, not a verified solved dev-tools graph**. No solve,
   install, extension/platform check or PHPStan/PHPUnit execution was performed
   by this research, since host PHP/Composer are unavailable and manifests are
   outside its permitted write scope.
5. Scope strict PHPStan to the changed readiness/payment/adapter boundaries and
   provide real array/callable shapes. Retain the V8 plan's no-new-error-baseline
   policy. Explicitly target production compatibility **PHP 8.3**, rather than
   inheriting the tool runner's higher PHP version. The successfully retrieved
   <https://phpstan.org/config-reference#phpversion> documents `phpVersion` and
   inference from `config.platform.php`; use an explicit 80300 target or a
   deliberately tested supported range with minimum 80300. Static analysis does
   not replace native compatibility tests on supported runtime versions.
6. Retain custom/native PHP suites, root lint, integration and platform gates.
   Load application classes through the application's bootstrap/autoloader
   where needed, without mixing the two Composer dependency sets. Keep tooling
   and its vendor contents out of runtime delivery; existing Dockerfile
   `COPY . .` means a future implementation must make that separation explicit.

## 7. Actual tool availability and security freshness

### Tool discovery and version execution

Executed at repository root:

```sh
for tool in go php composer phpstan phpunit golangci-lint govulncheck trivy gitleaks; do
  command -v "$tool" || true
done
```

Exit 0; **only `/opt/homebrew/bin/go`** was returned. The `|| true` permits a
complete discovery loop, not a successful check for missing tools. In particular
PHP/Composer/PHPStan/PHPUnit and standalone golangci-lint/govulncheck/Trivy/Gitleaks
were not found on this shell's PATH.

Additional narrowly scoped discovery found an already-existing govulncheck binary:

```text
/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round6-native/security-85/tools/govulncheck
```

Its SHA-256, measured now, is
`27f7dee4829e8f7587344943c613e74f2c5c3403ed1a76f7a7870b2c7d8a4db6`.
`go version -m` exited 0 and identifies its build as **go1.27.3 darwin/arm64**,
module **golang.org/x/vuln v1.9.0**. Running this binary with `-version` at root
exited 0 and printed:

```text
Go: go1.25.3
Scanner: govulncheck@v1.9.0
DB: https://vuln.go.dev
DB updated: 2026-10-27 21:16:18 +0000 UTC
```

That first Go line is the ambient analyzer Go at root, **not** the binary's build
version or either module's actual scan toolchain. The source commands below
explicitly set and independently report the correct toolchain in config/SBOM.

- Existing `/Users/dmitrijbykov/go/bin/staticcheck -version`: exit 0,
  **`staticcheck 2025.1.1 (0.6.1)`**. It is not the pinned golangci-lint 2.15.0
  gate, and was not used to claim current lint success.
- No standalone golangci-lint executable was found in the repository, the
  preapproved audit temporary directory or the Go bin directory. The configured
  command in `backend-sdui/scripts/check.sh` is pinned `go run …@v2.15.0`.
- Attempted existing-cache-only version execution from `backend-sdui`:
  `GOTOOLCHAIN=go1.27.3 GOPROXY=off GOFLAGS=-mod=readonly go run github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0 version`.
  **Exit 1**: `loading deprecation for github.com/golangci/golangci-lint/v2: module lookup disabled by GOPROXY=off`.
  An initial offline probe additionally set command-local `GOSUMDB=off` and
  failed with **exit 1**, `verifying module: checksum database disabled by GOSUMDB=off`;
  the retry retained normal checksum verification. Neither installed a tool or
  established lint success; no configuration or persistent verification policy
  was changed. A source pin/cache directory is not an executable availability receipt.
- <https://proxy.golang.org/github.com/golangci/golangci-lint/v2/@latest>
  independently returns **v2.15.0**, upstream tag time `2026-10-16T10:51:54Z`.
  <https://proxy.golang.org/golang.org/x/vuln/@latest> returns **v1.9.0**, upstream
  `go.googlesource.com/vuln`, tag time `2026-10-11T03:39:53Z`. Keep both existing
  configured tool pins. Latest pin verification and local tool execution are
  separate findings.

### Today's source scans

With `GOVULN` denoting the existing absolute binary path above, executed:

```sh
# cwd: backend-sdui
GOTOOLCHAIN=go1.27.3 GOFLAGS=-mod=readonly "$GOVULN" -db https://vuln.go.dev -json ./...
GOTOOLCHAIN=go1.27.3 GOFLAGS=-mod=readonly "$GOVULN" -db https://vuln.go.dev ./...

# cwd: tools/ops
GOTOOLCHAIN=go1.26.10 GOFLAGS=-mod=readonly "$GOVULN" -db https://vuln.go.dev -json ./...
GOTOOLCHAIN=go1.26.10 GOFLAGS=-mod=readonly "$GOVULN" -db https://vuln.go.dev ./...
```

All four processes exited **0**. Text mode in each module printed exactly
**`No vulnerabilities found.`** JSON reports scanner **v1.9.0**, source/symbol
mode, and config/SBOM Go versions **go1.27.3** / **go1.26.10** respectively.
Both report DB **`2026-10-27T21:16:18Z`**. Fetched `osv` records in JSON are
advisory data, not automatically findings against these selected versions.

The harness retained raw JSON output outside the repository, with checksums
measured by `shasum -a 256` (exit 0):

| Module | Harness output under `/Users/dmitrijbykov/.local/share/opencode/tool-output/` | SHA-256 |
| --- | --- | --- |
| BFF | `tool_1951b4c7f001JljpMaJ026KYDK` | `bca1e855df2be2014423405e90c285f634096ebb10aaf55c271105bb9d3e5daa` |
| Ops | `tool_1951b4c140018X0bzdaERl7upp` | `19ed1f9ae415c28e63e584ff859a94b9d988b6d5ff184650592f859f4ca785ba` |

These local raw-output references are conveniences, not portable release
receipts. An attempted `rg --count-matches --include-zero '"finding":'` helper
failed **127** (`rg` unavailable); it was not treated as a zero-finding result.
Dedicated content search found no finding entries, and successful **text-mode
scan output**, rather than a JSON process exit alone, independently establishes
the zero-reported-finding observation.

### Authoritative DB age, independently fetched today

Successfully fetched <https://vuln.go.dev/index/db.json> through the web tool and
then independently executed:

```sh
curl --fail --silent --show-error --max-time 30 \
  -H 'Cache-Control: no-cache' -D - https://vuln.go.dev/index/db.json
```

Exit **0**, HTTP **200**; payload:

```json
{"modified":"2026-10-27T21:16:18Z"}
```

The HTTP response also had `last-modified: Sat, 31 Oct 2026 22:17:18 GMT`,
`cache-control: public, max-age=3600`, and `age: 2162`. These transport/cache
timestamps do **not** replace the authoritative payload timestamp. The scanner's
config agrees with the payload, so a fresh request/download cannot reset its age.

The executed Python UTC arithmetic printed (exit 0):

```text
checked_at=2026-11-01T03:16:45.195778+00:00
db_modified=2026-10-27T21:16:18+00:00
age_hours=102.007554
72h_deadline=2026-10-30T21:16:18+00:00
fresh_72h=False
```

`scripts/backend_security.py:44–59` requires scanner 1.9.0, matching source and
SBOM Go versions, **DB age <=72h**, and rejects timestamps more than five minutes
in the future. Today's source scans meet the observed toolchain/zero-finding
conditions but **fail the DB-age condition**. The script itself was not invoked,
because it would install a tool, write other outputs and scan runtime artifacts;
this report applies its unchanged timestamp condition to the observed data.

The same existing script separately refreshes official
`ghcr.io/aquasecurity/trivy-db:2` / `ghcr.io/aquasecurity/trivy-java-db:1`, checks
DB ages **48h / 96h** and `NextUpdate`, and pins Trivy
`aquasec/trivy:0.76.1@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c`.
Those are distinct existing scanner policies, not a reason to reinterpret the
Go **72h** limit. Trivy DB refreshes, image/SBOM scans, final-binary scans and
Composer advisory audits were **NOT RUN in this research**. No current security
qualification or prior OpenSearch finding count is inferred from historical
reports or the source-only results here.

## Recommended handoff

1. **Keep** current PHP/Composer/Yii2, Go toolchains, pgx/gateway/OTel/schema and
   selected runtime transitive pins. Latest-patch queries identify no required
   targeted runtime patch beyond the already-updated baseline.
2. Implement only the planned **separate, real dev-tools lock** with exact
   PHPStan 2.3.4 / PHPUnit 13.4.5 and actual compatible PHP/extensions; preserve
   root runtime `>=8.3` and native suites.
3. Treat **Go DB freshness as still blocked**, not waived by scanner exit 0,
   a newer HTTP timestamp or a repeated download. Obtain publisher-fresh
   authoritative DB metadata, then rerun the existing source/final-artifact
   security gates under the same 72h policy. No policy relaxation is recommended.
