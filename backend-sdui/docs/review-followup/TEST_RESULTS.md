# Go SDUI follow-up — verification results

Executed 2026-10-26 on macOS arm64, from `backend-sdui/` unless specified.
All Go executions use the original **Go 1.27.3** toolchain and the real
`github.com/santhosh-tekuri/jsonschema/v6 v6.0.3` validator. No compatibility
patches, version downgrades, excluded schema tests or fixture regeneration.

## Baseline

From the repository root, these commands all exited 0:

```sh
git status --short --branch
git status --porcelain=v1 --untracked-files=all
git branch --show-current
git branch -vv
git remote -v
git rev-parse HEAD
git diff --cached --stat
git diff --cached
git diff --stat
git log --oneline -10
git ls-remote origin refs/heads/refactor/backend-production-hardening
git diff origin/master...HEAD -- .golangci.yml
git log --oneline origin/master..HEAD -- .golangci.yml
```

Clean baseline: `7415d59adf3b0bce7e3f2d83b4f759d18397c065`, existing feature
branch `refactor/backend-production-hardening`, tracking the same origin branch.
Remote: `https://github.com/dimangty/SDUI-PHP-GO.git`; remote SHA agreed. No
pre-existing staged/unstaged/untracked files to preserve. The root
`.golangci.yml` newline difference is already committed in baseline HEAD and
was not edited by this task. This is a newer checkout containing prior R1–R4.

`go version`: exit 0, `go version go1.27.3 darwin/arm64`.
`go test ./... -count=1` before changes: exit 0, six test-bearing packages pass.

## RED → FIX → GREEN

The review's attached follow-up test file was absent. Equivalent HTTP/model
regressions were added using existing helpers, canonical complete JSON DTOs and
independent published-schema validation.

Initial **RED** command, before any production edits:

```sh
go test ./internal/bff ./internal/sdui -run 'TestFollowupN[123]|TestReviewFixR1AdjacentCollectionBoundaries' -count=1
```

Exit **1**, with assertion failures reproducing all three findings:

- N1: uppercase/mixed product/order requests return 502 transport errors;
  CancelOrder returns account mismatch with **zero POSTs**. Case-equivalent
  account ownership is rejected, category options duplicate the same UUID or
  lose the real label, and default address falls back to the first address.
  Lowercase resource control passes.
- N2: 99 devices / 487 addresses pass; 100/150/249 devices and
  488/1000 addresses return 502 invalid document, including rich UTF-8/escaped
  values. Updated old adjacent boundary expectations also fail at 100/488.
- N3: 2148 valid quantities totaling **2147483648** incorrectly publish 200;
  **2147483647** passes. A hidden negative quantity reduces the published count.
  Session count, page totals, stock/reviews and order number above MaxInt32
  incorrectly publish 200. Model Encode also accepts MaxInt32+1 / Go MaxInt.

Focused **GREEN** commands after each respective minimal fix (all exit 0):

```sh
go test ./internal/bff -run 'TestFollowupN1|TestPublished|TestAllowlisted|TestCancelCannot' -count=1
go test ./internal/bff -run 'TestFollowupN2|TestReviewFixR1|TestPublished' -count=1 -v
go test ./internal/bff ./internal/sdui -run TestFollowupN3 -count=1
go test ./... -count=1
```

Following actual reviewer feedback, additional mutation-account and per-device
label/button association/identity checks were added. These protect cart commands
and both cancellation ownership phases, preserve case-sensitive device IDs, and
compare action/button IDs across the 99→100 compact transition:

```sh
go test ./internal/bff ./internal/domain -run 'TestFollowupN[12]|TestEqualID' -count=1
```

Exit 0. Domain tests additionally require two strictly valid ASCII UUIDs,
including negative identical-invalid, empty, newline and non-ASCII cases.

## Mandatory native checks

All commands below were executed in order and **each exited 0 on the final run**:

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

| Check | Observed final result |
|---|---|
| Go version | go1.27.3 darwin/arm64 |
| tidy-diff / verify | No diff; all modules verified |
| formatting / vet / build | PASS |
| full tests / race | PASS, all six test-bearing packages; cmd/server has no tests |
| check.sh | PASS: repeated native checks, independent schema/golden contract tests, pinned golangci-lint **0 issues**, govulncheck **No vulnerabilities found** |

An initial mandatory run reached check.sh's linter and exited **1**:
`staticcheck QF1003` requested a tagged switch in a new test's command dispatch.
Changed only that test's if/else to a switch. The complete mandatory sequence
above was then repeated successfully, including the linter and vulnerability
check. No suppression, validator relaxation or hook bypass was used.

Published fixtures for all eight screens, continuation/primitive fixtures,
independent negative-schema tests and all six commands pass through the complete
suite and check.sh. Existing R1–R4 regressions also pass: 164/165/200 order rows,
comment/snapshot/total/cancel, escaped body rejection, exact revoke success,
unexpected 204 collections, required/nullable/aliases, Retry-After allowlist and
timing, no mutation retries, damaged/oversize 401/403, final-attempt telemetry.

Measured final boundary command (exit 0):

```sh
go test ./internal/bff -run 'TestFollowupN2DevicesPreserveEveryControlAndLabel|TestFollowupN2ProfilePreservesAllAddressesInOrder|TestFollowupN3CartCountFitsClientIntWithoutLosingMoney|^TestLiveYiiEightScreensAndActions$' -count=1 -v
```

| Input | Nodes | Actions | Encoded SDUI bytes |
|---|---:|---:|---:|
| 99 devices, short / rich | 497 | 100 | 88,654 / 262,894 |
| 100 devices, short / rich | 202 | 101 | 57,649 / 233,649 |
| 150 devices, short / rich | 302 | 151 | 86,149 / 350,149 |
| 249 devices, short / rich | 500 | 250 | 142,573 / 580,813 |
| 250 devices | — | — | Atomic 502 BFF_INVALID_DOCUMENT |
| 487 addresses, short / rich | 500 | 10 | 64,333 / 451,011 |
| 488 addresses, short | 15 | 10 | 15,315 |
| 488 addresses, rich | 48 | 10 | 405,188 |
| 1000 addresses, short | 16 | 10 | 28,187 |
| 1000 addresses, rich | 85 | 10 | 827,217 |
| Cart count MaxInt32, 2148 rows, 20 visible | — | — | 19,834, exact total "21474836.47" |
| Cart count MaxInt32+1 | — | — | Atomic 502 BFF_INVALID_DOCUMENT |

0/1 device and address cases pass too. Every displayed value, sequence, revoke
tuple/confirmation and button association is checked. Rich cases include Cyrillic,
emoji, quotes, backslashes and HTML-sensitive JSON escaping. Actual escaped-body
and excessive-string failures remain atomic; profile ownership mismatch remains
fail-closed. These measurements are examples, not unlimited collection guarantees.

## Docker build

```sh
PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" docker build -t shopkmp-go-sdui-followup:local .
docker image inspect --format '{{.Id}}' shopkmp-go-sdui-followup:local
```

Both exit **0**, including the final build after test-only lint adjustment.
Unchanged Dockerfile, pinned Go 1.27.3 base, no infrastructure/config edits.
Final local image ID:
`sha256:ecb1f368b3b27ed90e70d29173adaddc2b719ddd83d867761a2143cadffa2cb8`.
This proves image build, not a newly deployed container journey.

## Actual live Yii journey

Read-only Docker discovery identified already-running project
`shopkmp-backend-modern-e2e`, its verify-fixture service using
`deploy/compose.verify.yml`, Yii `APP_ENV=development`, and edge published on
`127.0.0.1:58108`. `curl --fail --silent --show-error
http://127.0.0.1:58108/health/ready` exited 0: PostgreSQL/Keycloak/OpenSearch up.
No new stack was provisioned and no production endpoint was used.

```sh
YII_INTEGRATION_ORIGIN=http://127.0.0.1:58108 go test -race ./internal/bff -run '^TestLiveYiiEightScreensAndActions$' -count=1 -v
```

Exit **0**, test **4.20 s**, **68 recorded HTTP steps**. Actual current BFF
handler talks HTTP to real disposable Yii: eight screens, six commands, both
device kinds, exact money, cart replay/conflict, checkout/payment/cancel, stock
reservation/restoration, cross-account denials, native credentials/logout.
No evidence-dir override or fixture regeneration was used. This is a host Go
HTTP journey; it does not claim the freshly built Docker container was exercised.
Only a test switch was adjusted afterward; production code remained identical.

The default command above separately shows **SKIP** for the opt-in live test
without YII_INTEGRATION_ORIGIN. Default suite success alone is not live evidence.

## Review and excluded claims

Actual read-only reviewer session `ses_e89ad360fffeeKu1dpZ5vtxs2H` inspected
tracked and untracked code and ran targeted native contracts/R1–R4 tests and
format/scope checks. It found no blocking production defect and two test gaps;
both were addressed and the full mandatory sequence rerun. Final review details
are in FINAL_REVIEW.md. Implementer also performed self-review of scope/docs.

Re-review confirmed both gaps closed and code readiness approved, with only a
wording correction in FINAL_REVIEW.md (Screen.Encode covers screen documents).
That correction was applied. The reviewer actually reran this command, exit 0:

```sh
UPDATE_FIXTURES=0 go test -mod=readonly -race ./internal/bff ./internal/domain ./internal/sdui -run 'Test(FollowupN[123]|EqualID|Published|Baseline|CommandSchema|IndependentSchema)' -count=1 -v
```

It also checked diff whitespace and the local Docker image ID. It did not rerun
the complete mandatory sequence, build, or live journey; those results above
are the implementer's executions.

**NOT RUN:** Android/iOS builds, device/visual tests, remote CI, root monorepo
`make check` (PHP/ops/Compose; outside this backend-only verification scope).
No success for these checks is claimed. Commit/push and SHA confirmation occur
after the final staged review and are reported from real Git output in the final
handoff, rather than predicting a commit SHA in this file.
