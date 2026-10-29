# Go F1/F2 and application boundary implementation

Spec: `ARCHITECTURE_ADR.md`; execution: inline in the current feature checkout.

## Implementation sequence

1. Add native HTTP F1 regressions for 1/20/50/100 unique products, full public
   detail, projection protocol negatives, Unicode previews and preserved queries;
   save RED before implementing bounded card negotiation and capability readiness.
2. Add native HTTP F2 regressions for ordinary and legacy device identifiers,
   independent schema and consumer checks; save RED before changing device rows.
3. Add AST/package dependency guards, observe RED, extract consumer-owned
   application models/identity/errors/ports and orchestration, explicitly convert
   adapter wire DTOs, retain HTTP mapping in bff and wire constructors at bootstrap.
   Verify application behavior with in-memory ports and the existing HTTP negatives.
4. Split typed SDUI components/actions, encoding/revision, structural and semantic
   validation, and limits while retaining all existing validation and goldens.
5. Run gofmt, tidy diff, vet, race suite and build using native module toolchain;
   save command receipts with `scripts/backend_check.py` under `evidence/go-*`.

## Constraints and review focus

- Preserve eight screens, six commands, schema version 1, CMP validation, limits,
  default 2 MiB upstream budget, timeouts, read quotas and mutation no-replay.
- Missing projection marker/capability fails closed. Incomplete snippets require
  exactly 257 code points; complete snippets allow at most 257. Go owns ellipsis.
- Legacy `.`/`..` records stay visible with Russian remediation guidance and no
  invalid action. All other invalid device identifiers remain fail-closed.
- Preserve upstream error status/body/Retry-After and malformed 401/403 handling,
  including aggregate cancellation versus non-security partial home sections.
- Only allowed Go source/tests and this report/evidence are edited. No dependency
  changes, staging, commits or subagents.

## Results

Implemented and natively verified with **Go 1.27.3 darwin/arm64**
(`evidence/go-toolchain.{json,log}`). The final production snapshot passes tidy
diff, vet, race tests and build. All receipts below were produced from the
repository root by `scripts/backend_check.py`; each label has both a `.json`
receipt (argv/cwd/UTC times/exit) and a raw `.log`.

### F1: bounded catalog negotiation

- Catalog/search always request `projection=sdui-card-v1` while preserving the
  original search/filter/sort/page/size values. Popular requests retain `limit=10`
  and add the same fixed projection. Detail remains unprojected.
- Private wire DTOs require the marker, snippet, completeness and nullable first
  image. Missing/wrong markers fail even on empty responses. A complete snippet
  permits 0–257 Unicode code points; an incomplete snippet requires exactly 257.
  No extra business fetch or fallback to an empty collection is introduced.
- Go's existing preview algorithm is unchanged: up to 256 code points, or the
  first 255 plus `…`. PHP's raw snippet is passed through that sole algorithm.
- `Ready` requires status `ok` and capability `catalog-cards-v1`. The two existing
  positive runtime readiness fixtures now intentionally advertise the capability;
  missing/empty/unknown capabilities and unavailable status have explicit negatives.
- Default upstream MaxBody remains 2 MiB. Request timeouts, the bounded GET retry/
  connection quota, and mutation no-replay machinery are unchanged.

`TestRound3F1LargeCatalogHTTPProjection` uses the real Yii HTTP client and SDUI
HTTP handler against a native upstream HTTP fixture. Each page has 1/20/50/100
distinct UUID products with `strings.Repeat("😀", 10000)`. Ordered media use two
URLs within PHP's 2,048-byte limit. The first historical stress run used invalid
oversized images; it is superseded by the native replay against immutable baseline
source (`go-valid-native-red`) and corrected GREEN (`go-valid-native-green`).
Valid 50/100-item pages exceed 2 MiB with these long URLs. These HTTP-fixture
measurements are separate from the actual full-stack evidence in PERFORMANCE.md.

| Products | Unprojected page bytes | Projected upstream bytes | SDUI bytes |
| ---: | ---: | ---: | ---: |
| 1 | 44,403 | 3,440 | 5,250 |
| 20 | 886,922 | 67,130 | 33,926 |
| 50 | 2,217,212 | 167,690 | 79,196 |
| 100 | 4,434,364 | 335,292 | 154,651 |

The fixture's unprojected public detail is 44,343 bytes and retains all 10,000
emoji and media. Existing real Go detail-description tests independently retain
every code point at 8,192/8,193/10,000 characters. New tests cover ASCII 256/257,
Cyrillic 257, Unicode 257/10,000, first-image ordering, null image, empty pages,
a nonempty last page, search past the last page, and literal expected query
parameters. Missing-marker HTTP tests require catalog 502 or an explicit partial
popular warning on home, never a false empty section.

### F2: legacy devices

Exactly `.` and `..` retain their record/name/identifier/kind/last-used details and
show: “Устройство использует устаревший идентификатор; требуется отзыв или
исправление через аккаунт, API или оператора.” No `RevokeDevice` action or dangling
button is created for those values. Ordinary opaque/case-sensitive identifiers
retain their existing actions, confirmations and layout.

Native HTTP regression cases cover both credential kinds in ordinary and compact
layouts, including mixed ordinary and legacy rows. They require HTTP 200, both
distinct legacy records, the Russian explanation, valid independent JSON Schema,
consumer node/depth/body/action budgets and independently checked CMP-compatible
device addressability. Other invalid upstream IDs still return 502; command and
domain negatives for `.`/`..` remain unchanged. This validates the wire consumer
constraints in Go; no mobile binary/runtime execution is claimed here.

### Application and SDUI boundaries

- `internal/application` owns screen/command orchestration, transport-neutral
  application error kinds and the `Auth`, `CatalogReads`, `AccountReads`,
  `CartCommands`, `AccountCommands` ports. Constructor parameters use those small
  interfaces independently; the aggregate `Backend` exists for facade compatibility.
- `internal/application/model` owns identity, read/input models and remote error
  metadata. Shared UUID/device/money/query constraints remain domain-owned. Shared
  bearer/key/correlation validation moved unchanged to `internal/domain/identity.go`.
- Yii decodes independent private wire DTOs and explicitly maps fields/nested
  items into the consumer models, including command inputs. Old exported Yii DTO,
  identity and error names are compatibility aliases to consumer-owned types.
  This is real conversion, not application aliases pointing back to adapter DTOs.
- HTTP routing, headers, deadlines, request-body limits, serialization and public
  status/body/Retry-After mapping stay in bff. The transport itself now has no Yii
  dependency. Runtime constructs the adapter, application and HTTP handler through
  their constructors. Existing malformed 401/403, aggregate cancellation, ownership,
  retry metadata and no-replay negatives pass.
- Component/content/collection builders perform no I/O. New in-memory port tests
  exercise authentication, profile composition, validation before any I/O,
  single-write acknowledgement and foreign-owner rejection without `httptest`.
- AST imports and transitive `go list` guards reject application/domain/sdui
  dependencies on the Yii adapter or HTTP transport, and reject transport-to-Yii
  coupling. Test-only HTTP/schema dependencies are excluded from the production graph.
- `internal/sdui/model.go` is split into `types.go`, `components.go`, `actions.go`,
  `encoding.go`, `validation.go`, `component_validation.go`,
  `action_validation.go`, and `limits.go`. Typed rules, limits, revisions and wire
  fields are retained; no runtime schema compiler/loader was introduced.

All eight screen golden fixtures, continuation/long-description fixtures,
primitive fixture, six-command tests and existing negative matrices pass with
their original assertions. A test-only projection serializer adapts existing
upstream fixtures while preserving invalid fields, required-member omissions,
statuses and error bytes. Protocol-negotiation regressions bypass that serializer.

### RED/GREEN evidence

| Concern | RED receipt/log (exit 1) | Observed RED | GREEN receipt/log (exit 0) |
| --- | --- | --- | --- |
| F1 native large-page HTTP | `go-f1-red` | All four page sizes return 502 `BFF_UPSTREAM_TRANSPORT` | `go-f1-green`, `go-f1-final-green`, final `go-race-verified` |
| F1 protocol/capability | `go-f1-protocol-red` | Missing projection query; valid projected data rejected; old readiness falsely accepted | `go-f1-protocol-green`, `go-f1-final-green`, final `go-race-verified` |
| F2 mixed legacy devices | `go-f2-red` | Both kinds/layouts return 502 `BFF_INVALID_DOCUMENT` | `go-f2-green`, `go-f2-final-green`, final `go-race-verified` |
| Application boundary | `go-boundary-red` | Consumer-owned application package/graph not yet present | `go-boundary-green`, `go-boundary-final-green` |
| HTTP transport boundary | `go-transport-boundary-red` | Transport production graph still contains concrete Yii adapter | `go-boundary-final-green` |

RED receipts are intentional failing regressions and remain visible. Final
`go-race-verified.log` includes the strengthened last-page/query assertions, exact
legacy-record checks, final dependency guards and all preservation tests. Earlier
successful integration-of-refactor attempts are retained as
`go-refactor-suite-initial`, `go-race-final` and `go-race-final-snapshot`.

### Final native checks

| Command | Receipt/log | Result |
| --- | --- | --- |
| `go -C backend-sdui version` | `go-toolchain` | Go 1.27.3 darwin/arm64 |
| `gofmt -w` on changed Go directories/files | `go-gofmt`, `go-gofmt-final`, `go-gofmt-boundary-final` | PASS |
| `go -C backend-sdui mod tidy -diff` | `go-tidy-final` | PASS, empty diff |
| `go -C backend-sdui vet ./...` | `go-vet-final` | PASS |
| `go -C backend-sdui test -race -count=1 ./... -v` | `go-race-verified` | PASS, no race reports |
| `go -C backend-sdui build ./...` | `go-build-final` | PASS |
| `gofmt -l` on Go source directories | `go-format-check` | PASS, empty output |
| Scoped `git diff --check` | `go-diff-check-final` | PASS |
| Protected module/schema/golden/observability/device-validator diff | `go-protected-paths` | PASS, empty diff |

### Changed files

Paths below are relative to `backend-sdui/`:

- New application: `internal/application/{application,errors,query,screens,components,
  collections,product_content,order_content,profile_content,commands}.go` and
  `internal/application/model/{models,boundary}.go`. Screen/build/command files are
  extracted from their former bff locations.
- New/moved application tests: `internal/application/{application_test,
  product_content_test,primitives_contract_test,schema_test,fuzz_test}.go`.
  Primitive assertions and strict-command fuzz cases are preserved at their new home.
- Transport/facade: `internal/bff/{http,actions_http,application}.go`;
  test changes in `internal/bff/{screens_test,fuzz_test}.go`; new
  `internal/bff/{projection_fixture_test,round3_boundary_test,round3_http_test,
  round3_regression_test}.go`.
- Adapter: `internal/yii/{client,dto,query,response}.go`; new
  `internal/yii/{wire,cards,round3_projection_test}.go`; updated projection wire
  fixture in `internal/yii/client_test.go`.
- Bootstrap/readiness fixtures: `internal/runtime/{server,runtime_test}.go`.
- Shared identity values: `internal/domain/identity.go`.
- SDUI split: the eight files listed above replace `internal/sdui/model.go`.
- This report and `docs/backend-fixes/round-3/evidence/go-*` receipts/logs.

### Remaining verification and handoff

No known unresolved Go implementation failure remains in the native checks.
`TestLiveYiiEightScreensAndActions` is explicitly **SKIPPED / NOT_RUN** because
`YII_INTEGRATION_ORIGIN` is unset; its reason appears in `go-race-verified.log`.
Disposable PHP/PostgreSQL/full-stack runs, actual mobile execution, CI,
dependency/OTel evaluation, mandatory security gate and independent review remain
the coordinating main agent's stages as assigned. The synthetic HTTP stress
measurements above must not be presented as those stages' results.

No Go module/dependency files, observability implementation, PHP, scripts, CI,
mobile code, public schemas or published golden fixture bytes were edited by this
work. `domain.ValidDevice` is unchanged. No Git staging, commits or pushes were
performed.
