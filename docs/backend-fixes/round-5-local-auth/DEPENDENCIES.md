# V6 Task 7 — backend dependency audit

## Execution and evidence boundary

Executed **2026-10-30 UTC / 2026-10-31 UTC+07**, against baseline
`6f002deb391146195cb63790e0b70e7f1001e8c4` and the shared, uncommitted V6 tree.
Publisher metadata was fetched at 17:22–17:23 UTC; observations below are dated
checks, not predictions of future releases. The compact machine-readable record
is [DEPENDENCY_EVIDENCE.json](DEPENDENCY_EVIDENCE.json).

Full graphs, package JSON, scanner streams, binaries and logs are local-only in
`deploy/evidence/round5-dependencies/`, ignored by that directory's `.gitignore`.
They contain public package metadata, synthetic test results and build metadata;
no environment dumps, database URLs, production identities, passwords or tokens.
They are not substitutes for the coordinator's exact-image security receipts.

Before any dependency edit, `git archive 6f002de backend-sdui` extracted the
**original module and sources** into an isolated temporary directory. The exact
manifests were also captured with `git show 6f002de:backend-sdui/go.mod` and
`git show 6f002de:backend-sdui/go.sum`. No alternate project module or `-modfile`
was used. Both before and after captures include:

```sh
GOTOOLCHAIN=go1.27.3 go mod graph
GOTOOLCHAIN=go1.27.3 go mod why -m all
GOTOOLCHAIN=go1.27.3 go list -mod=readonly -m -json all
GOTOOLCHAIN=go1.27.3 go list -mod=readonly -deps -json ./...
GOTOOLCHAIN=go1.27.3 go list -mod=readonly -test -deps -json ./...
```

`before-*` describes the baseline source; `after-*` describes the shared V6
source at capture time. Native package counts are 404→405 (448→449 including
test variants). The only added runtime package path is protobuf's `emptypb`,
introduced through grpc-gateway. Module graph edges are 252→258. Module selection,
package imports and linked binary modules are distinct evidence layers.

## Go decisions

| Dependency | Before | After | Official observation / decision |
| --- | --- | --- | --- |
| Go directive / executed toolchain | 1.27.3 | 1.27.3 | [Go release feed](https://go.dev/dl/?mode=json) lists stable 1.27.3 and 1.26.10; retain 1.27.3. |
| JSON Schema v6 | 6.0.3 | 6.0.3 | [Publisher release](https://github.com/santhosh-tekuri/jsonschema/releases/tag/v6.0.3); latest stable returned by publisher API. Test-only, retained for independent schema checks. |
| OTel API, trace, metric, log, SDK, trace exporter and HTTP exporter | 1.47.0 | 1.47.0 | [Publisher release](https://github.com/open-telemetry/opentelemetry-go/releases/tag/v1.47.0); family remains aligned. |
| Generated OTLP Go bindings | 1.11.0 | **1.11.1** | [Patch release](https://github.com/open-telemetry/opentelemetry-proto-go/releases/tag/v1.11.1), including descriptor coexistence fix [#707](https://github.com/open-telemetry/opentelemetry-proto-go/pull/707). Narrow generated-binding maintenance update; not presented as a vulnerability fix. |
| grpc-gateway/v2 | 2.30.0 | **2.31.0** | Required by the [OTLP 1.11.1 manifest](https://raw.githubusercontent.com/open-telemetry/opentelemetry-proto-go/v1.11.1/otlp/go.mod). Publisher's newest stable is [2.33.0](https://github.com/grpc-ecosystem/grpc-gateway/releases/tag/v2.33.0); an independent minor upgrade is unnecessary for this patch. |
| gRPC | 1.85.0 | 1.85.0 | [Publisher release](https://github.com/grpc/grpc-go/releases/tag/v1.85.0); retain baseline security floor above OTLP's minimum 1.84.0. |
| protobuf | 1.36.12 | 1.36.12 | [Publisher release](https://github.com/protocolbuffers/protobuf-go/releases/tag/v1.36.12); matches generated binding requirements. |
| genproto API and RPC | 20260928230214-8a89bd6388cc | same | Both stay at `v0.0.0-20260928230214-8a89bd6388cc`, meeting OTLP requirements and preserving a coherent pair. Official proxy [API](https://proxy.golang.org/google.golang.org/genproto/googleapis/api/@latest) / [RPC](https://proxy.golang.org/google.golang.org/genproto/googleapis/rpc/@latest) both return newer `v0.0.0-20261026165753-2ffab32bce7e`; no required API or advisory justifies advancing generated APIs independently here. |
| x/net | 0.62.0 | 0.62.0 | [Official proxy metadata](https://proxy.golang.org/golang.org/x/net/@latest), with upstream tag provenance. Retain. |
| x/sys | 0.49.0 | 0.49.0 | [Official proxy metadata](https://proxy.golang.org/golang.org/x/sys/@latest). Retain. |
| x/text | 0.43.0 | 0.43.0 | [Official proxy metadata](https://proxy.golang.org/golang.org/x/text/@latest). Retain. |
| govulncheck | 1.9.0 | 1.9.0 | [Official proxy metadata](https://proxy.golang.org/golang.org/x/vuln/@latest), upstream `go.googlesource.com/vuln` tag. Stable pin retained in backend checks/security script. |
| golangci-lint/v2 | 2.15.0 | 2.15.0 | [Publisher release](https://github.com/golangci/golangci-lint/releases/tag/v2.15.0). Existing v2 config passes; no pin/config adjustment needed. |

Exact unchanged helper requirements are recorded in the JSON alongside every
direct/indirect `go.mod` requirement. These were not independently upgraded.
The only update command was:

```sh
# backend-sdui/
GOTOOLCHAIN=go1.27.3 go get go.opentelemetry.io/proto/otlp@v1.11.1
GOTOOLCHAIN=go1.27.3 go mod tidy
```

No `go get -u`, major migration, tool dependencies in the application module,
security-floor downgrade, replacement or exclusion was introduced.

### Runtime versus test closure

JSON Schema imports are confined to `_test.go` files. The production package
graph and `go version -m` output exclude that module, while the test graph and
`go mod why` retain it. Go does not have a separate `require-dev` section.
Removing it would remove independent contract/schema validation.

`internal/observe` imports the OTel SDK and OTLP/HTTP exporter. The
[exporter manifest](https://raw.githubusercontent.com/open-telemetry/opentelemetry-go/v1.47.0/exporters/otlp/otlptrace/otlptracehttp/go.mod)
requires the aligned OTel 1.47.0 family. Generated OTLP collector files import
gateway runtime and gRPC types even with HTTP transport, so gRPC/gateway/protobuf
are real runtime dependencies. Protobuf is also directly exercised by trace
tests; a test-rooted shortest `why` path does not make its runtime closure
test-only. Likewise x/text participates in runtime x/net/IDNA paths despite a
shortest schema-test path. All three selected x/* versions exceed the updated
OTLP minimums (net 0.59.0, sys 0.48.0, text 0.42.0). Selected genproto satisfies
the OTLP/gateway/gRPC requirements without forcing their revisions backwards.

## PHP decisions and JWT retirement

| Package / platform | Before | After | Official source |
| --- | --- | --- | --- |
| PHP 8.3 line | 8.3.36 | 8.3.36 | [PHP release metadata](https://www.php.net/releases/index.php?json&version=8.3): 21 Oct 2026 security release, current 8.3 patch returned by PHP.net. |
| Yii | 2.0.55 | 2.0.55 | [Yii release](https://github.com/yiisoft/yii2/releases/tag/2.0.55). Keep major 2 and `^2.0.53` constraint with the exact lock. |
| yii2-composer | 2.0.11 | 2.0.11 | [Publisher release](https://github.com/yiisoft/yii2-composer/releases/tag/2.0.11). |
| HTML Purifier | 4.19.1 | 4.19.1 | [Publisher release](https://github.com/ezyang/htmlpurifier/releases/tag/v4.19.1). |
| cebe/markdown | 1.2.1 | 1.2.1 | [Publisher release](https://github.com/cebe/markdown/releases/tag/1.2.1). |
| firebase/php-jwt | 7.2.1 | **removed** | [Publisher release](https://github.com/googleapis/php-jwt/releases/tag/v7.2.1) verified before retirement; removed because its consumers were retired, not downgraded to an older JWT line. |
| Composer used by native PHP image | 2.11.0 | 2.11.0 | [Official version feed](https://getcomposer.org/versions) now reports 2.11.1. [2.11.1 release notes](https://getcomposer.org/changelog/2.11.1) concern WSL/cache, GitLab auth, malformed extension names/configuration and PHP 8.6; no applicable platform/audit failure was found here. Image pin retained. |

All four retained Composer packages matched the checked publisher stable release
and `composer outdated --locked --direct` returned an empty list before removal.
Composer `why firebase/php-jwt --locked` identified only the root requirement.
Baseline source use was limited to `infrastructure/Keycloak.php`,
`infrastructure/OidcVerifier.php` and OIDC tests `auth.php`,
`logout_regression.php`, `signed_oidc.php`. These were replaced/retired by the
identity agent. A fresh source search found no remaining Firebase/JWT package
consumer before removal. Native `ext-sodium`/`ext-openssl` requirements remain for
the application's separate crypto uses.

The exact removal command, executed only after that condition was met, was:

```sh
docker run --rm \
  -v "$PWD/backend-yii2:/app" -w /app \
  shopkmp-round5-baseline-php \
  composer remove firebase/php-jwt --no-interaction --no-progress
```

Composer reported **0 installs, 0 updates, 1 removal**, regenerated the lock and
autoload files, and found no security advisories. The lock's plugin API metadata
changed 2.9.0→2.11.0 to reflect the actual Composer used; retained package versions
and PHP/extension constraints did not change. No audit suppression or ignored
platform requirement was added.

Composer registration now keeps `round5_search_protocol.php` in offline `test`.
`local_auth_core.php` opens real PostgreSQL and runs only under `test:integration`
or `test:all`. The latter delegates to the identity/search agents' updated
`tests/all.php`, avoiding duplicate execution of either suite. Account migration
classes/tests and their registration are owned by the separate migration agent.

### Native password hashing sources

Primary documentation checked on the execution date:

- [PHP password_hash](https://www.php.net/manual/en/function.password-hash.php):
  native `PASSWORD_ARGON2ID` requires Argon2 support; `memory_cost` is in KiB;
  generated salt, algorithm and costs are carried in the PHC string.
- [PHP password_verify](https://www.php.net/manual/en/function.password-verify.php):
  verifies the encoded parameters and is timing-attack safe.
- [PHP password_needs_rehash](https://www.php.net/manual/en/function.password-needs-rehash.php):
  detects algorithm/cost drift; it is not password verification.
- [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html#argon2id):
  Argon2id minimum `m=19456 KiB`, `t=2`, `p=1` (19 MiB), with target-platform tuning.

The native image advertises `argon2id` and successfully hashes/verifies a public
synthetic fixture with exactly these options, rejects the wrong value, and
reports `needs_rehash=false`. No new password-hashing Composer package is needed.
This checks availability and semantics, not production latency or load capacity.

## Verification and integration handoff

Actual platform: host Go bootstrap 1.25.3; **executed Go 1.27.3 darwin/arm64** via
automatic toolchain selection. PHP/Composer ran natively in **linux/arm64**, image
`sha256:e3a5ce15c6bd56b62c23568718f070e7bfa6f4f8f9acd19fe3fc90db70ba6665`,
PHP 8.3.36, Composer 2.11.0, libsodium 1.0.22, OpenSSL 3.5.9. Mounted working-tree
source and Composer-installed ignored `vendor/` were used for PHP tests; this is
not a claim that the old image contains the final V6 source.

| Check | Observed result |
| --- | --- |
| `GOTOOLCHAIN=go1.27.3 sh scripts/check.sh` in backend-sdui | PASS: tidy diff, checksum verify, gofmt, vet, all tests, race tests, build, focused schema checks, lint (0 issues), source govulncheck. The script is invoked via `sh` because it is not executable in this checkout. |
| Before/after native Go build and `go version -m` | PASS; both list Go 1.27.3 and the expected linked modules, without JSON Schema. |
| Pinned govulncheck 1.9.0, before/after native source and binary | No vulnerabilities found. JSON retained; database `https://vuln.go.dev`, last modified `2026-10-27T21:16:18Z`. |
| Before/after `govulncheck -test` | No vulnerabilities found, including the test-only JSON Schema closure; JSON retained. |
| After linux/amd64 source and binary scans | No vulnerabilities found. CGO-disabled cross-build uses Dockerfile-equivalent `-mod=readonly -trimpath -buildvcs=false -ldflags='-s -w'`; this is a local binary, not an extracted production image. Host-native pinned scanner loads the Linux package graph. |
| tools/ops source govulncheck 1.9.0 | No vulnerabilities found; ops dependencies were not changed. |
| tools/ops `go test ./... -count=1` | PASS on rerun. Initial concurrent snapshot failed SQL file count (32 versus 30); migration owner's later changes resolved it. |
| Composer `validate --strict` | PASS after removal; manifest and lock agree. |
| Composer `audit --locked --format=json` | Before/after: no advisories, no abandoned packages, empty filter/cooldown lists. |
| Composer `check-platform-reqs --lock --format=json` | PASS: PHP, plugin API, ctype/curl/gd/intl/mbstring/openssl/pdo_pgsql/sodium and PCRE. |
| Composer `test` with `--network none` | PASS: 7 unit + 7 transaction + 1 search-query + 17 inventory + 32 search-protocol tests = **64 tests**, 0 failures. Expected fail-closed fixture logs are not failures. |
| Composer `lint` with `--network none` | PASS: 115 PHP files at final dependency-validation snapshot. |

The scanner executable was installed separately from the application module:

```sh
# audit is the absolute deploy/evidence/round5-dependencies path.
GOTOOLCHAIN=go1.27.3 GOBIN="$audit/tools" \
  go install golang.org/x/vuln/cmd/govulncheck@v1.9.0
# Run in the baseline module and then the working module, using distinct outputs.
GOTOOLCHAIN=go1.27.3 go build -trimpath -o "$audit/after-server" ./cmd/server
GOTOOLCHAIN=go1.27.3 go version -m "$audit/after-server"
GOTOOLCHAIN=go1.27.3 "$audit/tools/govulncheck" -json ./...
GOTOOLCHAIN=go1.27.3 "$audit/tools/govulncheck" -test -json ./...
GOTOOLCHAIN=go1.27.3 "$audit/tools/govulncheck" -mode=binary -json "$audit/after-server"
```

For Linux source scanning, the already-built host-native scanner was invoked
with `GOOS=linux GOARCH=amd64 CGO_ENABLED=0`; cross-compiling the scanner with
`go run` would try to execute a Linux program on Darwin.

Integration gaps at handoff:

- `composer test:integration` / `test:all`, native auth/concurrency/account migration
  suites and fresh-stack E2E require the coordinator's disposable services and
  final combined source. They were not run by this dependency-only task.
- The final runtime graph guard run retained 14 findings: OpenAPI retirement prose,
  the historical signed-token rejection test's name/registration, and current
  plan prose, plus this audit's explicit retirement evidence. It remains a
  coordinator gate; documentation was not rewritten to
  erase the word Keycloak or bypass the guard.
- Final rebuilt PHP and Go images must include these manifests. Mandatory
  whole-tree/exact-image scans, SBOM/receipt binding, trust/fault/payment recovery,
  native platform checks for deployment targets, and final combined-tree checks
  remain coordinator-owned. Local clean scans do not mark those gates complete.

Review: self-review of the owned dependency diff and captured outputs; no fresh
reviewer was available in this task. No commit was created.

## Final runtime follow-up — 31 October 2026

The coordinator completed fresh native image scans/SBOM and primary-advisory
analysis after this dependency-only handoff. See SECURITY_RESULTS for exact
before/after policy results, sources and remaining blockers.

| Runtime dependency | Before | After | Reason / verification |
| --- | --- | --- | --- |
| SDUI + migrator static nonroot base | Distroless Debian 12 `afa5c872…57f7ab` | Supported Debian 13 `18f4cc26…2f82c99` | Publisher deprecates other tags; current Debian 12 tag was unchanged. CGO-disabled static binaries/nonroot retained; native build/start/migration, edge CARD and 65-step live BFF/race pass; both final image policies clean |
| gosu x/sys | 0.1.0 | 0.49.0 | Actual helper build uses normal pinned Go module tooling, same gosu source checksum/Go 1.26.10/PostgreSQL 17.11. Native UID/GID checks and extracted binary metadata verified; final PostgreSQL image policy clean. CNA CVE-2026-39824 is Windows-only: no claim of a reachable Linux fix |
| OpenSearch | 2.19.7 | 2.19.7 | Latest compatible 2.x confirmed by official publisher; 19 HIGH instances persist. No JAR/JVM swap or unverified major update |

Final source/binary govulncheck execution still reports zero raw findings, but
the authoritative Go DB timestamp is outside the fixed 72h policy. Final security
acceptance is BLOCKED, not inherited from this earlier task's passing raw scans.
