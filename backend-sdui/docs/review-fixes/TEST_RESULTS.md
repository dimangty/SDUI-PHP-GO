# Review fixes — verification results

Executed 2026-10-26 on macOS arm64, in `backend-sdui/` unless noted.
No compatibility substitutions, dependency downgrades or fixture regeneration.

## Baseline

`git status --short --branch`, `git status --porcelain=v1 --untracked-files=all`,
`git rev-parse HEAD`, `git remote -v`, `git branch -vv`, `git diff --stat`,
`git diff --cached --stat`, `git log --oneline -10`:
clean branch `refactor/backend-production-hardening`,
HEAD `a92babd491acbe9ceb5b40a6957033adbd9d33ab`; no staged, unstaged or untracked
files. Only remote is origin, `https://github.com/dimangty/SDUI-PHP-GO.git`.
`git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}'` reported no
configured upstream. `git ls-remote origin` for this branch confirmed the same
starting SHA. `git merge-base origin/master HEAD` is
`ad9525847982c75d12cb987e81d7b480d5e98cc2`.

Shell-default Go at repository root is 1.27.2; **all project checks automatically
select Go 1.27.3 from go.mod**, with no source or dependency adaptation. Baseline
tidy-diff, module verification, formatting, vet, test, race, build and
`sh scripts/check.sh` all passed. The default test invocation skips live Yii
without env; that baseline is not full-stack evidence.

## RED → GREEN evidence

| Finding | Executed RED command and observed failure | GREEN / neighboring verification |
|---|---|---|
| R1 | `go test ./internal/bff -run TestReviewFixR1 -count=1`: all 165/200 cases returned 502/BFF_INVALID_DOCUMENT; 1/164 passed | Same command passed after bounded composition; `go test ./internal/bff -count=1` passed. 200 long-name items + comment: 232,295 bytes, 22 nodes; 164 + comment: 500 nodes. Every row/name/quantity/snapshot, PHP total and cancel checked. |
| R2 | `go test ./internal/bff -run TestReviewFixR2 -count=1`: revoke 200/202 falsely applied; categories/devices 204 falsely published. `go test ./internal/yii -run TestReviewFixR2 -count=1`: incomplete Cart bodies accepted | Both packages passed after explicit contracts/required shape validation. All six valid command contracts and malformed/empty/null/204 collections checked. Existing positive test payloads were completed to PHP shapes; no negative assertions or golden fixtures removed. |
| R3 | `go test ./internal/yii ./internal/bff -run TestReviewFixR3 -count=1`: missing metadata; 503 retried in milliseconds despite seconds/date/huge delay; cancellation lost to early retry | Same command and both package suites passed. Real HTTP delay of at least 1 second, canceled wait, deadline refusal, single mutation, preserved body/status/no-store, header allowlist and invalid/duplicate/overlength cases checked. |
| R4 | `go test ./internal/yii -run TestReviewFixR4 -count=1`: EOF, timeout and canceled second attempts logged stale status 503 | Same command and Yii/observe suites passed. Real server snapshots check 503→EOF=0/network, 503→200, security headers→broken body=401/403, second-attempt cancellation=0, backoff cancellation=503, retry/reuse counters and redaction. |

Separate reviewer found two additional R2 gaps. Before fixing them,
`go test ./internal/bff -run TestReviewFixR2RequiredNullableAndCaseAliases -count=1`
failed for omitted required nullable fields, missing biometric keyAlgorithm,
case-fold root/nested aliases, and a false mutation acknowledgement. After
fixing requiredness independently of nullability and rejecting known-field
case aliases, the same regressions and the complete suite passed. Re-review
confirmed both issues resolved, with no remaining important code findings.

## Final mandatory checks

The following exact sequence completed with exit 0 after the review fixes:

```sh
go version
go mod tidy -diff
go mod verify
test -z "$(gofmt -l .)"
go vet ./...
go test ./... -count=1
go test -race ./... -count=1
go build ./...
sh scripts/check.sh
```

| Check | Observed result |
|---|---|
| Go version | `go version go1.27.3 darwin/arm64` |
| tidy-diff / verify | no diff / all modules verified |
| gofmt / vet / build | PASS |
| tests / race | all six test-bearing packages PASS; cmd/server has no tests |
| scripts/check.sh | PASS including pinned golangci-lint: **0 issues**, govulncheck: **No vulnerabilities found** |
| `go test ./internal/bff -run 'Test(Published\|Baseline\|CommandSchema\|IndependentSchema\|ReviewFix)' -count=1 -v` | PASS: actual eight screens/goldens, continuation/primitive fixtures, six commands, new BFF regressions, negative independent-schema tests |
| `go test ./internal/yii -run 'Test(ReviewFix\|ReadsReuse\|ReadBudget\|CloseIdle\|StaleRead\|TLSVerification\|Security\|GET\|Transient)' -count=1 -v` | PASS: focused regressions and actual-transport pooling/budget/TLS tests |
| full suite security cases | PASS: malformed, oversized and read-error 401/403 remain fail-closed |

The regex alternation characters in the two displayed table commands are plain
`|` when executing in a shell (escaped here only for Markdown table rendering).
The independent validator is the real installed
`github.com/santhosh-tekuri/jsonschema/v6 v6.0.3`; no substitute validator used.
`git diff --exit-code HEAD -- backend-sdui/contracts backend-sdui/go.mod backend-sdui/go.sum`
(from repository root) passed: schemas, golden fixtures and dependencies unchanged.

## Docker and real Yii integration

Initial `docker build -t shopkmp-go-sdui-review-fixes:local .` failed because
Docker's configured credential helper was absent from shell PATH. Verified the
installed helper exists, then executed successfully without changing config:

```sh
PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" docker build -t shopkmp-go-sdui-review-fixes:local .
```

Image ID `sha256:63ce771cef6bd3693413374dbc1f27ab37cc9a911640b602c40cd82b9c95248a`;
build uses the unchanged pinned Go 1.27.3 image. Root `docker compose ps` without
env failed interpolation of PAYMENT_WEBHOOK_SECRET; no Compose/config changes
were made. `docker ps` and selected `docker inspect` fields identified the
already-running **disposable** `shopkmp-backend-modern-e2e` stack, with verify
fixture service, APP_ENV=development and loopback edge port 58108. Readiness
returned ok for PostgreSQL, Keycloak and OpenSearch.

```sh
YII_INTEGRATION_ORIGIN=http://127.0.0.1:58108 go test -race ./internal/bff -run '^TestLiveYiiEightScreensAndActions$' -count=1 -v
```

**PASS**, test 4.07 seconds / process 5.593 seconds, **68 recorded HTTP steps**.
This runs the current Go code in an httptest HTTP server against real test Yii,
not the older Go container on that edge. Covered all eight screens and six
commands, both device kinds, exact decimal money, actual stock reservation and
restoration, checkout/payment/cancel, idempotency/conflict, cross-account denial,
native credentials and logout. No PHP/config/shared infrastructure was changed;
mutations were confined to this test environment. No mobile visual run occurred.

Explicitly demonstrated default skip separately:

```sh
go test ./internal/bff -run '^TestLiveYiiEightScreensAndActions$' -count=1 -v
```

**SKIP**: `set YII_INTEGRATION_ORIGIN to the disposable Task1 HTTP stack`.
Default `go test`/check.sh success alone does not prove live integration; the
env-enabled run above is the separate evidence. Remote CI status was not queried
and is not claimed.
