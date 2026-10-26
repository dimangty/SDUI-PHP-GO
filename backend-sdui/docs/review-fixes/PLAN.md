# Review fixes implementation plan

**Goal:** address confirmed R1–R4 within `backend-sdui/`, preserving SDUI v1,
eight screens, six commands, PHP ownership and single-attempt mutations.

**Baseline:** clean `refactor/backend-production-hardening`, HEAD
`a92babd491acbe9ceb5b40a6957033adbd9d33ab`; origin
`https://github.com/dimangty/SDUI-PHP-GO.git`, no branch upstream. Comparison
base: `origin/master` (`ad95258`), parent of the refactoring commits.
Go selected by go.mod: 1.27.3. Existing independent-review test attachments
are absent from this checkout; add equivalent executable regressions.

## Execution (inline, RED → minimal fix → GREEN)

- [x] R1: `internal/bff/review_fixes_order_test.go`: HTTP/schema tests for
  1/164/165/200 items, UTF-8/escaped long names, comments, exact decimal prices,
  totals and cancellation. Keep normal rows when the full document fits;
  otherwise bounded multiline Text blocks. Test explicit size failure and
  profile/devices growth boundaries. Add ADR.
- [x] R2: `internal/yii/response.go`, `client.go`, response-contract helper
  and regression tests; enforce expected 200 JSON / device-revoke 204 empty
  contracts. Reject missing required scenario data, null/wrong shapes and
  unexpected success statuses with typed protocol errors. Check all commands
  end-to-end, unchanged security failures, and single mutation attempts.
- [x] R3: typed allowlisted Retry-After metadata, strict single-value parser,
  429/503 forwarding, deadline-bounded GET retry waiting; regressions for date,
  seconds, invalid/duplicate/large values and cancellation.
- [x] R4: reset final-attempt status only when starting a new HTTP attempt;
  real httptest server regressions for network failure/success, security body
  failure, cancellation during backoff and final metrics/log snapshots.
- [x] Review complete diff, correct related defects, run all requested checks,
  Docker build and disposable live Yii integration when available. Record exact
  outcomes, skips and limitations in TEST_RESULTS.md and FINAL_REVIEW.md.
- [ ] Only after successful mandatory checks: inspect and stage explicit task
  paths, commit with requested message, push this working branch to origin,
  compare local HEAD to ls-remote SHA. Never claim unobserved CI results.

## Constraints and review focus

Do not change schemas, golden fixtures, global bounds or dependencies/toolchain.
Do not add navigation parameters or components. Preserve every represented item
and PHP totals; reject unrepresentable documents atomically. Maintain pooled GET
wire-attempt budget, fail-closed 401/403 and no mutation retry. No automatic
fixture regeneration. Root `.golangci.yml` has a pre-existing final blank-line
deletion versus origin/master; record but do not edit it.

Skills actually loaded: systematic-debugging, brainstorming, writing-plans,
test-driven-development, requesting-code-review, receiving-code-review and
verification-before-completion (also using-superpowers). A separate read-only
reviewer reviewed the diff and re-reviewed both confirmed R2 follow-ups.
User explicitly requested
implementation after a short plan; no additional design approval round.
