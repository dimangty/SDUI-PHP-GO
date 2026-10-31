# Task 5 — R9-01 order summary execution

Starting HEAD: `6f002deb391146195cb63790e0b70e7f1001e8c4`.
Scope: order-only PHP/Go/contracts/tests in the current shared worktree.

## Execution decisions

- Use this requested report as the Task 5 execution ledger; the user explicitly
  selected the shared worktree and forbade commits and unrelated edits.
- Preserve `Client.Orders()` and full wire types; introduce the plan's separate
  `OrderSummaries()` application port. Projection is request-local.
- PHP summary uses eight fields, exactly two SELECTs, and no order_items SQL.
- Keep existing separate count/page snapshot semantics; no new transaction/locks.
- Fixed summary-v1 wire rejects extra fields; legacy full DTO forward compatibility
  remains unchanged. No readiness/auth changes: PHP-first deployment is required.

## Verification

- RED: `go -C backend-sdui test ./internal/bff -run '^TestR9OrderSummary' -count=1`
  exited 1. Missing projection query at all 0/1/10/20/100 sizes; populated
  summaries returned 502; full pages were incorrectly accepted; foreign summary
  could not reach ownership validation. Concurrent list/detail test also failed.
- RED: native Docker command (same mounts/environment as `bash
  backend-yii2/tests/r9_order_native.sh`) exited 1: **9 tests, 9 failures**.
  Missing summary marker at every cardinality, unsupported projection accepted,
  summary mapper absent. All SQL ran on real PostgreSQL/native PDO.
- Native image: `shopkmp-round5-baseline-php`, image ID
  `sha256:e3a5ce15c6bd56b62c23568718f070e7bfa6f4f8f9acd19fe3fc90db70ba6665`.
  Network: `shopkmp-round5-e2e_data`; database/user: `shopkmp`. Password inherited
  from `.env.example` inside the container, never printed or put in host argv.
- `go env GOVERSION GOTOOLCHAIN` inside the module: Go **1.27.3**, auto.

### GREEN/native checks

- `bash backend-yii2/tests/r9_order_native.sh`: **9 tests / 0 failures**,
  including default/detail/create/idempotent-replay compatibility, owner/status
  predicates before LIMIT, UUID case, tied timestamps, page boundaries, pure
  mapping and rejection of all present unsupported projections before SQL.
- `bash backend-yii2/tests/r9_order_native.sh --legacy-reads`: **27 tests /
  0 failures** from the unchanged baseline-image `tests/round3_reads.php`, using
  the current three order production files mounted read-only. Full order pages
  still use 2 SELECTs empty / 3 populated and retain every historical child.
  This is order compatibility evidence, not current shared-worktree search/auth
  verification: their production sources are intentionally not mounted.
- Native focused suite rerun after test cleanup: **9 / 0**, with these actual
  byte measurements (200 items per order, each product name 255 emoji code points
  = 1,020 UTF-8 bytes; full 2,000-code-point emoji comments):

| Orders | Summary SELECTs | SQL mentioning order_items | Summary bytes | Full bytes |
|---:|---:|---:|---:|---:|
| 0 | 2 | 0 | 99 | 62 |
| 1 | 2 | 0 | 2,382 | 235,844 |
| 10 | 2 | 0 | 22,945 | 2,357,898 |
| 20 | 2 | 0 | 45,799 | 4,715,742 |
| 100 | 2 | 0 | 228,600 | 23,578,463 |

Statement capture is actual `PDOStatement::execute()` and `queryString`, not
source inspection or a repository mock. Every captured summary statement must
start with SELECT and contain no order_items reference, including subqueries.
Summary SQL explicitly selects only eight columns; no child hydration occurs.
The two-statement assertion also covers empty, foreign-owner and past-last pages.

### Actual native bytes consumed by Go

The following commands exported native production service/mapping output from
PostgreSQL, then exercised the Go HTTP adapter, ownership checks, builders and
independent schemas with that exact JSON. Scratch contains synthetic data only.

```bash
bash backend-yii2/tests/r9_order_native.sh --export > /var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-native.json
ruby -rjson -ryaml -e 'puts JSON.generate(YAML.load_file(ARGV[0]))' backend-yii2/contracts/openapi.yaml > /var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-openapi.json
R9_ORDER_OPENAPI_JSON=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-openapi.json R9_ORDER_NATIVE_FIXTURE=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-native.json go -C backend-sdui test ./internal/bff ./internal/yii -run '^TestR9' -count=1 -v
```

Result: **9 top-level R9 Go tests passed**, including all 39 protocol/ownership
negative cases, five cardinalities, concurrent two-user summary/detail reads,
unchanged full Orders/Order/CancelOrder methods, unchanged required nullable
detail fields, security-status/no-retry behavior, logical failure telemetry,
and actual OpenAPI summary/default response validation. Empty pages require the
marker. Protocol tests use direct servers, bypassing the legacy fixture adapter.

| Native orders | Exported summary bytes | Final SDUI bytes |
|---:|---:|---:|
| 0 | 99 | 1,895 |
| 1 | 2,382 | 4,247 |
| 10 | 22,939 | 26,766 |
| 20 | 45,779 | 51,786 |
| 100 | 228,500 | 251,947 |

That export's full 20×200 page is **4,715,722 bytes**. `Client.Orders()` rejects
it specifically with `body_limit` at its unchanged default **2,097,152 bytes**;
`OrderSummaries()` succeeds. Later native rerun byte differences above are only
the increasing BIGSERIAL order-number digit widths. The exported fixture has not
been rewritten. Final SDUI remains below the unchanged **1,048,576-byte** budget.

### Broader verification

Commands run from the repository root:

```bash
env -u YII_INTEGRATION_ORIGIN -u ROUND3_LIVE_ORIGIN go -C backend-sdui test ./internal/application/... ./internal/yii ./internal/bff -count=1
env -u YII_INTEGRATION_ORIGIN -u ROUND3_LIVE_ORIGIN go -C backend-sdui test -race ./internal/application/... ./internal/yii ./internal/bff -count=1
env -u YII_INTEGRATION_ORIGIN -u ROUND3_LIVE_ORIGIN R9_ORDER_NATIVE_FIXTURE=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-native.json go -C backend-sdui test ./... -count=1
go -C backend-sdui vet ./internal/application/... ./internal/yii ./internal/bff
git diff --check
```

All commands above exited **0**. Package suites include unchanged published
goldens, independent SDUI schemas, detail item preservation and atomic failure,
required nullable fields, cancellation and integer boundaries. No goldens were
regenerated. Optional deployed live tests were disabled explicitly.

Native syntax command, exit **0**, PHP **8.3.36**:

```bash
docker run --rm --network none --mount type=bind,src="$PWD/backend-yii2",dst=/src,readonly shopkmp-round5-baseline-php sh -c 'php -v && php -l /src/repositories/OrderReadRepository.php && php -l /src/repositories/OrderDtoMapper.php && php -l /src/application/CommerceService.php && php -l /src/tests/r9_order_summary.php'
bash -n backend-yii2/tests/r9_order_native.sh
```

OpenAPI parsed successfully with system Ruby YAML and still has **54 operations**.
Host Python validator discovery (`python3 -c 'import yaml, jsonschema; ...'`)
failed because PyYAML is absent; no packages were installed. Ruby conversion plus
the existing Go JSON Schema compiler supplied the successful native schema check.

### Final verification after the last test additions

Both environment variables below were set in the commands, so neither native-byte
nor OpenAPI validation was skipped:

```bash
env -u YII_INTEGRATION_ORIGIN -u ROUND3_LIVE_ORIGIN R9_ORDER_OPENAPI_JSON=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-openapi.json R9_ORDER_NATIVE_FIXTURE=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-native.json go -C backend-sdui test -race ./internal/application/... ./internal/yii ./internal/bff -count=1
env -u YII_INTEGRATION_ORIGIN -u ROUND3_LIVE_ORIGIN R9_ORDER_OPENAPI_JSON=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-openapi.json R9_ORDER_NATIVE_FIXTURE=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/r9-order-native.json go -C backend-sdui test ./... -count=1
go -C backend-sdui vet ./internal/application/... ./internal/yii ./internal/bff
git diff --check
```

Final results: **all exited 0**. Race package durations: application 2.098s,
yii 5.566s, bff 22.756s. Full Go suite passed all seven tested packages
(application, bff, domain, observe, runtime, sdui, yii);
application/model and cmd/server have no tests. No race reported. `gofmt -l`
on all nine owned Go files and `bash -n` on the runner also exited 0 with no output.

## PHP-first rollout and remaining gates

1. Deploy the PHP projection to every PHP replica first. Confirm an authenticated
   empty/nonempty `/api/v1/orders?projection=sdui-order-summary-v1` has the marker
   and eight fields, while an unprojected request remains full.
2. Deploy Go. Its list port negotiates the fixed projection internally; public
   SDUI queries/actions do not acquire a caller-controlled projection field.
3. Roll back Go before rolling PHP back to a version without the projection.
   Old PHP returns an unmarked full page; new Go deliberately fails closed instead
   of retrying/falling back. Readiness capability changes belong to coordination.

**NOT RUN:** deployed PHP HTTP → Go → edge with native auth/session validation,
fresh Compose/full-stack E2E, whole PHP auth/payment/concurrency suites, production
deployment and remote CI. Exported native PHP JSON was served through a Go HTTP
test server; this is deliberately not described as a deployed full-stack test.
The focused suite is invoked explicitly; no Composer/all.php registration changes
were made, per Task 5 ownership. The coordinator must include it in final gates.

## Scoped review

Final review: **self-review (no subagent tool)** against Task 5 and ADR R9 reads.
Reviewed the owned production diff and focused tests for ownership, full-DTO
requiredness, pure snapshot mapping, mutable shared state, query fan-out, marker
failure telemetry, empty-page oneOf ambiguity and unchanged limits/goldens.
No Critical/Important implementation issue found. Independent whole-change review
and the deployed full-stack gate remain coordinator checks.

Scope observations: other workers' auth/search/deployment changes appeared during
execution. No edits, reverts, staging or commits were made to those files.

## Owned file inventory

Production:
- `backend-yii2/repositories/OrderReadRepository.php`
- `backend-yii2/repositories/OrderDtoMapper.php`
- `backend-yii2/application/CommerceService.php`
- `backend-yii2/contracts/openapi.yaml` (order sections only)
- `backend-sdui/internal/application/model/models.go`
- `backend-sdui/internal/application/application.go`
- `backend-sdui/internal/application/components.go`
- `backend-sdui/internal/application/screens.go`
- `backend-sdui/internal/yii/order_summary.go` (new)
- `backend-sdui/internal/yii/response.go`

Focused tests/evidence:
- `backend-yii2/tests/r9_order_summary.php` (new)
- `backend-yii2/tests/r9_order_native.sh` (new)
- `backend-sdui/internal/bff/order_summary_test.go` (new)
- `backend-sdui/internal/yii/order_summary_test.go` (new)
- `backend-sdui/internal/bff/projection_fixture_test.go` (order fixture adapter only)
- `docs/backend-fixes/round-5-local-auth/ORDER_RESULTS.md` (this report)
