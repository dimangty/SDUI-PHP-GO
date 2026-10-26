# Go SDUI follow-up implementation plan

**Goal:** fix confirmed N1–N3 within backend-sdui, preserving R1–R4 and the
unchanged CMP v1 contract. Execute inline, RED → minimal FIX → GREEN.

## Baseline and scope

- Clean checkout (no staged, unstaged or untracked files), HEAD
  `7415d59adf3b0bce7e3f2d83b4f759d18397c065` on existing feature branch
  `refactor/backend-production-hardening`. Remote/upstream: `origin`,
  `https://github.com/dimangty/SDUI-PHP-GO.git`; ls-remote agrees with HEAD.
- Current HEAD is newer than the prior review fix commit `c966fb7`.
  HEAD changes only root `.golangci.yml`'s terminal newline. That pre-existing
  committed difference belongs to the baseline and is outside this task.
- No applicable AGENTS.md/CLAUDE.md in the project/ancestor directories.
  Read CONTRIBUTING.md, backend README/check script, review-fixes docs and
  implementation/tests; read PHP IDs/repository/commerce/OpenAPI and CMP
  Screen/decoder/renderer/EpsText for compatibility only.
- Native `go version`: `go1.27.3 darwin/arm64`. Baseline
  `go test ./... -count=1`: exit 0, six test-bearing packages pass.
- The supplied `regression-tests/internal/bff/review_followup_test.go` is absent;
  create equivalent tests using existing HTTP helpers, fixtures and the actual
  jsonschema/v6 validator. File read/search tools report `Bun is not defined`;
  shell file reads/searches are the necessary fallback.
- Only backend-sdui Go/tests/docs may change. No dependencies, toolchain,
  schemas, routes, components/actions, money, global budgets or PHP rules change.

## Confirmed causes and design

N1: ValidID accepts ASCII hex of either case; resource, account, category and
default-address identity comparisons use literal strings. Compare strictly valid
UUIDs by value in a domain helper. Preserve request IDs/payloads verbatim,
especially AddToCart's fingerprint input and all opaque credentials/device IDs.
For category options retain the selected spelling as the option value so CMP's
literal controlled-field selection still works without duplicate UUID options.

N2: devices use 2 fixed + 5 nodes/device; profile uses 13 fixed + 1/address.
Keep ordinary composition through 99 devices / 487 addresses. Above those
thresholds flatten devices to bounded multiline Text + a distinct existing revoke
Button (stable action/button IDs, kind, device ID and confirmation), and pack
complete address lines into bounded multiline Text blocks. Final Encode validates
the entire document, including escaped bytes, before an HTTP body is sent.

N3: cart sums Go ints unchecked; final validation lacks CMP Int upper bounds.
Check each addition before arithmetic against 2,147,483,647, reject negative
quantities, and validate every numeric output field read as Kotlin Int. Keep large
internal values legal when only formatted as text or not emitted.

## Execution tasks

- [x] Add HTTP N1 tests for lower/upper/mixed resources and cancellation, exact
  once writes, mismatch/security/syntax, neighboring UUID comparisons and opaque
  value preservation. Run `go test ./internal/bff -run TestFollowupN1 -count=1` RED.
- [x] Add N2 tests for device 0/1/99/100/150 and actual compact boundary, mixed
  kinds/same ID, every label/action/button/confirmation; profile
  0/1/487/488/1000, UTF-8/escaping, ownership and actual unrepresentable data.
  Update R1 adjacent expectations at 100/488. Run N2/adjacent tests RED.
- [x] Add N3 HTTP and model tests for 0/normal/MaxInt32/MaxInt32+1/negative,
  summation including hidden rows, pagination, stock/reviews and order number.
  Run `go test ./internal/bff ./internal/sdui -run TestFollowupN3 -count=1` RED.
- [x] Implement `domain.EqualID(a, b string) bool`, replace confirmed UUID
  comparisons only, run N1 GREEN and retain opaque payload tests.
- [x] Implement focused collection composition in `internal/bff`, preserve
  golden small screens, run N2 and prior R1 GREEN. Document finite conditions.
- [x] Add explicit wire Int validation in `internal/sdui/model.go` and safe cart
  sum in screens.go; run N3 GREEN, including text-only/internal-number controls.
- [x] Request an actual read-only code review with installed skill; investigate
  findings, reproduce defects before fixes, and reverify affected checks.
- [x] Run all user-requested native commands, independent schema/golden and
  eight-screen/six-command tests; build unchanged Dockerfile. Identify the
  already-existing disposable environment before any opt-in live Yii test.
- [x] Complete TEST_RESULTS.md and FINAL_REVIEW.md with exact command outcomes,
  skips, compact limits and review attribution; re-review and address findings.

## Publication procedure (after verification)

Compare task diff with baseline, stage explicit task paths, review staged
diff/check, commit only after mandatory checks pass; push existing branch to
verified origin, compare rev-parse HEAD with ls-remote. Record any publication
blocker instead of bypassing checks. Actual commit/push status and matching SHAs
are supplied in the final Git-backed handoff, not predicted by this plan.
