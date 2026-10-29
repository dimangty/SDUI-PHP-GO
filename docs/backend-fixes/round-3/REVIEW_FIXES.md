# Round 3 — scoped review fixes

Executed inline in the current checkout after reading the round-3 ADR, audit plan
and Go/PHP/observability implementation reports. Native toolchain:
**Go 1.27.3 darwin/arm64** (`evidence/review-go-toolchain`). No subagents, Git
staging, commits or pushes.

## Findings fixed

1. **Strict PHP projection allowlist.**
   `CatalogReadRepository::projected()` distinguishes an absent key with
   `array_key_exists`: absent means the existing full DTO; the only accepted
   present value is the string `sdui-card-v1`. Empty, null, non-string and unknown
   values produce **400 INVALID_PROJECTION**. Catalog, search and popular tests
   verify validation precedes native PDO execution; search uses a closed local
   endpoint so an accidental dispatch would return the wrong error. The former
   empty-string fallback assertion now checks the genuinely absent parameter.

2. **Card protocol validation completes inside outbound telemetry.**
   `yii/response.go` explicitly checks the two private card envelope types after
   JSON decoding, using the existing marker/UTF-8/257-code-point/completeness
   rules extracted into `validCards`. Conversion in `cards.go` and `query.go` is
   now a pure mapping of validated data. Invalid markers and incomplete snippets
   return a fixed `protocol` failure with HTTP status 200 retained, before
   `call()` finalizes its single client span and observer event. This uses the
   existing allowlisted error type, adds no retries/spans, and does not change
   the general response-shape reflection rules or introduce runtime schemas.
   Real SDK in-memory exporter tests cover valid, wrong-marker and short-
   incomplete responses for catalog, search and popular. Assertions include
   error status/type, observer log/error metric, one HTTP call/span, and absence
   of raw marker/snippet/query sentinels.

3. **Reject collector paths the exporter cannot preserve.**
   `trace_config.go` rejects nonempty `RawPath` or a percent-containing
   `EscapedPath`, including canonical escapes whose `RawPath` is empty, before
   exporter construction. The error is fixed:
   `SDUI_TRACE_ENDPOINT requires an unescaped path`. Native collector tests
   assert actual `RequestURI` for root, custom, trailing-slash and ordinary
   punctuation paths. Encoded slash, unreserved characters, space, percent,
   query-delimiter and Unicode paths are rejected without collector I/O.

4. **Correct strict-command fuzz documentation.**
   README invocation now targets `./internal/application`. The documented
   10-second command was executed successfully with four workers.

## Additional behavior coverage

- Private production exporter, real HTTP fixtures: **307 and 308** each cause
  exactly one collector request, zero redirected requests and one safe failure
  count. HTTP and HTTPS exports ignore upper/lowercase proxy environment
  variables and reach the intended collector, with zero proxy requests. These
  tests use a non-loopback hostname and remap dialing to the fixture, avoiding
  the standard library's automatic loopback proxy bypass; the HTTPS positive
  case trusts only the fixture certificate and verifies its hostname.
- Default-root TLS rejects the untrusted collector: one handshake connection,
  zero HTTP export requests, no retry, one safe failure count. These cases also
  run under the existing fresh-process SDK stderr capture, checking sanitized
  diagnostics rather than installing a global error handler.
- Rolled-back native PostgreSQL fixture: popularity ranks recent CAPTURED totals
  **18 > 10 > 4**, with a LIMIT of two, despite much larger old-date,
  non-CAPTURED and inactive distractors. Full/card results preserve the same rank.
- Orders: two newer foreign-owner orders cannot consume a size-one page; exact
  owner totals, both pages, snapshots, past-end behavior and foreign detail
  rejection are checked. A newer same-owner wrong-status order also cannot
  consume the filtered LIMIT. Both measured owner pages use three native PDO
  executions; both measured ranked popular variants also use three.

## RED → GREEN and verification receipts

Each label below has `evidence/<label>.json` (argv, cwd, timestamps, raw exit) and
`.log` (raw output), recorded with `scripts/backend_check.py`.

| Check | Evidence labels | Result |
| --- | --- | --- |
| PHP empty projection before production fix | `review-php-projection-red` → `review-php-projection-green` | RED: catalog/popular accept empty, search reaches I/O; GREEN: all 25 tests pass |
| Final card telemetry before production fix | `review-card-telemetry-red` → `review-card-telemetry-green` | RED: six invalid cases falsely have successful spans/events; GREEN: all nine cases pass |
| Escaped endpoints before production fix | `review-endpoint-red` → `review-endpoint-green` | RED: seven escaped paths accepted; GREEN: rejected, plain collector URIs preserved |
| Redirect/proxy/TLS and subprocess diagnostics | `review-exporter-coverage` | PASS, existing production policies needed only coverage |
| Complete native Go suite | `review-go-native` | `go -C backend-sdui test -count=1 ./...`, PASS |
| Focused native race suite | `review-go-focused-race` | observe + yii, PASS, no race reports |
| Focused vet | `review-go-vet` | observe + yii, PASS |
| Documented strict-command fuzz command | `review-readme-fuzz` | PASS, 338,438 executions |
| Final expanded PHP reads | `review-php-reads-green` | **27 tests, 0 failures** |
| Touched PHP syntax | `review-php-lint` | PASS |
| Formatting | `review-format` | PASS, empty output |
| Tidy verification | `review-go-tidy-diff` → `review-go-tidy-diff-green` | Initial stale sums detected; final PASS, empty diff |
| Isolated DB lifecycle | `review-php-db-setup`, `review-php-db-cleanup` | PASS; zero product/order/payment rows after rollback, then database dropped |

The intermediate `review-php-reads-coverage` failure was a test-fixture setup
error: the newly inserted foreign user omitted required `phone`. Added that
fixture field; `review-php-reads-green` is the passing rerun. The intentional RED
and intermediate failure receipts are retained.

## Exact incremental scope and handoff

Edited:

- `backend-sdui/internal/observe/{trace_config,trace_test}.go`
- `backend-sdui/internal/yii/{cards,query,response,trace_test}.go`
- `backend-sdui/README.md` — only the strict-command fuzz invocation in this pass
- `backend-yii2/repositories/CatalogReadRepository.php`
- `backend-yii2/tests/round3_reads.php`
- This report and `evidence/review-*`

Under the permitted inevitable-tidy exception, removed only the two obsolete
`x/net v0.59.0` checksum lines from `backend-sdui/go.sum` after the coordinator's
`v0.60.0` upgrade. No dependency selection or `go.mod` change in this pass.

PHP execution used the existing `shopkmp-round3-final-e2e-yii2` image in temporary
containers attached only to `shopkmp-round3-final-e2e_data`, with only the touched
repository/test files mounted read-only for GREEN (test only for RED).
`review_reads_1029` was a new, empty, schema-only copy on the disposable stack's
PostgreSQL instance. All fixture writes stayed in that isolated database and
rolled back; it was subsequently dropped. Connection credentials were obtained
in memory from the existing container configuration and not printed in receipts.

No unresolved scoped implementation failure remains. The running application
image still predates these fixes: no source/artifact receipt was rebuilt here.
Final combined native/full-stack/security checks, source/artifact alignment,
performance/report corrections and scoped rereview remain the main coordinator's
handoff work; these focused receipts do not claim those gates passed.
