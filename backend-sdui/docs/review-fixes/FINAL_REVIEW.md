# Review fixes — final review

## Outcome

| Finding | Cause / change | Verification |
|---|---|---|
| R1 — addressed within fixed v1 bounds | 3 nodes per order item exceeded 500; preserve existing UI through 164 rows, then pack complete lines into bounded supported Text blocks. Exact snapshots and PHP total retained, cancellation/comment preserved. | RED at 165/200; GREEN for 1/164/165/200, long UTF-8/escaped names, comments, schema validation and unchanged golden. |
| R2 — addressed | Decoder accepted arbitrary 2xx or absent required fields. Explicit 200 JSON / revoke 204 empty policies, required/nullable shape checks and rejection of case-fold aliases. | RED/GREEN for pending revoke and malformed DTOs; all six success commands; correct empty lists; unchanged fail-closed security, mutation attempts and pooling budget. |
| R3 — addressed | Typed errors discarded Retry-After. Retain only a validated single bounded 429/503 value, forward it, and respect its delay inside the existing GET deadline/budget. | RED/GREEN seconds/date, huge delay, invalid/duplicate/overlength, cancellation, exact error body/status and no header leaks/mutation retries. |
| R4 — addressed | Logical-call status retained a preceding attempt's response. Reset immediately before a new attempt; retain response status when canceled during backoff. | RED/GREEN real HTTP 503→EOF/timeout/cancellation, 503→200, 401/403 body failure, log/metric snapshots. |

R1–R3 are pre-existing defects identified by the supplied review; this report
does **not** attribute them to the refactoring. R4 concerns the new telemetry
and corresponds to the previous report's deferred L1.

## Endpoint policy audit

Read-only sources: `backend-yii2/config/routes.php` and
`backend-yii2/contracts/openapi.yaml`; actual test Yii confirms compatibility.

- All used GET endpoints require **200 + JSON**: users/me, categories,
  products/popular, products, products/search, products/{id}, cart, addresses,
  auth/session, orders, orders/{id}, PIN/biometric devices and health/ready.
- POST cart/items, PATCH/DELETE cart/items/{id}, POST orders/{id}/cancel and
  PUT users/me require **200 + typed JSON**.
- DELETE auth/{pin,biometric}/devices/{id} requires **204 + empty body**.
- Required nullable fields must be present (null allowed). Only PIN
  keyAlgorithm and session biometricCredentialId are optional according to
  these DTOs' OpenAPI contracts. Unknown future members are accepted except
  aliases that would overwrite canonical known members during Go decoding.

No business totals or authorization rules moved to Go. DTOs, routes,
schemaVersion=1 and published SDUI schemas/fixtures remain unchanged.

## Code review

A separate read-only `general` reviewer was actually dispatched using installed
requesting-code-review instructions (review session
`ses_e8a0ae57effeTk0m9G3u80wDHR`). It identified two important R2 gaps:
required nullable fields treated as optional, and case-fold aliases overriding
validated data. Both were independently reproduced as RED, fixed and GREEN.
The same reviewer re-reviewed the follow-up and confirmed both resolved,
reporting no remaining critical/important code findings. Its own checks were
diff/format/scope; Go/Docker/live execution was performed by the implementing
agent and is documented separately in TEST_RESULTS.md. No unperformed checks
are credited to the reviewer. Documentation/scope were then self-reviewed.

## Scope and Git evidence

Starting checkout was clean at `a92babd`; only task hunks were introduced.
All new/modified paths for this task are under `backend-sdui/`. No changes to
PHP, CMP, tools/ops, deploy, root CI/Makefile/Compose/config/docs were made.
Production code changes are limited to order composition, upstream response
contracts/retry metadata/final-attempt status, BFF metadata forwarding and the
finite protocol-error observation label. Existing positive test data was made
contract-complete (e.g. updatedAt and biometric keyAlgorithm); negative tests
and golden files remain intact.

The ZIP observation about root `.golangci.yml` is confirmed by real Git:
`git diff origin/master...HEAD -- .golangci.yml` shows only a deleted terminal
blank line; `git log --oneline origin/master..HEAD -- .golangci.yml` attributes
it to pre-task commit `a92babd`. The comparison base is the verified merge-base
`ad9525847982c75d12cb987e81d7b480d5e98cc2`. The root file was not edited by this
task. The refactoring branch already differs from that base; this report claims
scope only for this task's diff, not repository identity with the base.

## Remaining limits

- Fixed v1 budgets remain finite: total encoded body <= 1 MiB, <= 500 nodes,
  <= 500 actions, depth <= 16, each string <= 8192 code points. Order compact
  blocks additionally use <= 8192 UTF-8 bytes, complete lines only. The default
  upstream cap remains 2 MiB. A complete order outside these conditions returns
  a safe atomic error; no rows are silently dropped. The 700-item escaped-name
  test proves the body-limit case remains an explicit restriction.
- Adjacent screens still have growth limits: 487 short profile addresses fit,
  488 fail; 99 total devices fit, 100 fail. Tests and ADR identify this existing
  limitation. No unsupported navigation or client changes were introduced.
- Retry-After is length-bounded to 128 bytes and must be one valid value.
  Invalid metadata is omitted. Syntactically valid huge numeric delays are
  retained but cannot trigger an overflowing wait or an early retry.
- Live integration is the current host Go HTTP server against disposable Yii;
  Docker build is separately verified. No mobile visual/production load test
  or remote CI success is claimed.

All mandatory local checks and available real Yii integration passed; see
TEST_RESULTS.md for exact commands, initial environment failures and explicit
default live SKIP. Commit/push verification is performed after final staged
scope review; publication SHA is reported from Git rather than prewritten here.
