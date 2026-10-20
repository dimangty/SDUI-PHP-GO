# Task 1 execution ledger

Plan: `docs/IMPLEMENTATION_PLAN.md`, Task 1. Spec: `docs/MIGRATION_PLAN.md`.

Ruling: use the supplied unborn workspace and keep evidence here, then in the
requested report; do not create worktrees, commits, subagents, or root artifacts.
The user explicitly owns and authorizes this scope.

Shared interface: Task 1 produces the unchanged 55-operation API consumed by
Tasks 2/4. PostgreSQL migrations 001–011 and OpenAPI remain canonical.

Test-first batch 1: exact money, Go-compatible Argon2id encoding, PIN rules,
strict JSON, complete route policy; PostgreSQL checkout rollback/snapshots and
credential failure persistence/opaque refresh tests written before services.

Ruling: the original `apiRoutes` has 54 operations (15 public, 32 protected,
7 admin), not the plan's 55. Preserve the exact registry and expose health/media/
demo separately. Cost if wrong: a missing source operation must be identified,
not invented under a new business URL.

Docker build initially rejected firebase/php-jwt 6.x due to PKSA-y2cr-5h3j-g3ys.
Resolved to stable 7.2.1; Yii2 resolved to stable 2.0.55. Advisories remain enabled.
RED: `docker run --rm shopkmp-yii2-task1 php tests/run.php` → 6 missing-feature failures.
RED: PostgreSQL integration command → missing Database/service classes.
GREEN: 7 PostgreSQL tests, including both biometric algorithms, payment/refund,
profile ownership, completed-purchase reviews; real isolated Keycloak → 3 tests.
JSONB object-key order is not a wire guarantee; assertions normalize object keys
while preserving strict value types and array order.

Further RED→GREEN assertions: Go-compatible OUT_OF_STOCK/message, RFC3339Nano
fractional formatting, OpenSearch Basic-auth URL support, wrong-kind opaque
refresh isolation. Maintenance's missing service was observed before adding it.
The Go Argon2 vector was generated with the original x/crypto implementation.

Final review: self-review (user prohibited subagents). Reviewed auth failure
commit paths, all 54 access policies, owner checks, transaction/lock order,
external response bounds, no mutation retries, raw webhook HMAC and image limits.
The new service has no Go upstream and performs real SQL writes.

Ruling: reject deletion of addresses referenced by historical orders as
ADDRESS_IN_USE, preserving the schema/FK rather than returning a database error.
Cost if wrong: clients see a stable conflict for an otherwise impossible delete.

Ruling: keep UTC for differential comparison. The first host-Go comparison used
the host's +07:00 local timezone; launching the reference with TZ=UTC made the
same instants byte-compatible without weakening timestamp assertions.

Final review fix: READINESS_TIMEOUT was listed but provider probes still used a
fixed timeout. A real slow-provider elapsed-time assertion failed first; wired
identity/search probe timeouts to configuration and observed it pass.

Final full run: `composer test:all` inside the built PHP image without mounting
application source, with isolated PostgreSQL,
real Keycloak, OpenSearch, Nginx/PHP-FPM, provider protocol fixture and live Go
read-only reference: **50 tests, 0 failures; 50 PHP files linted**. All 54
canonical routes have a successful HTTP response and the complete route/policy
set matches Go. Original unchanged `go test ./...` and original Go e2e against
Yii2 passed. Exact commands and limits are in the final report.

## Review follow-up

Read the full independent `docs/YII2_REVIEW.md` and checked its concrete findings
against Go. I1 boundary regressions initially failed 6/6, I2 controlled actual
service races failed 2/2 with SQLSTATE 40P01, I3 slow-header/cumulative deadline
regressions failed 4/4, M2 returned 0 rather than 2 whitespace-search products,
and M3 parsed 500ms as 500 rather than 0.5. All observed RED→GREEN.

Fixed canonical network/user policy ordering, bucket distinctions, CIDR
aggregation and webhook exemption; user KEY SHARE precedes cart/address locks;
profile address updates use parent-first transactions; ext-curl total deadlines
cover headers and body with no redirects/retry; milliseconds retain actual units;
whitespace search uses match_all. Corrected gateway evidence by appending an
explicit erratum: inherited PAYMENT_GATEWAY_UNAVAILABLE HTTP 400 is retained,
and is now asserted at the API boundary with one provider attempt.

Supplemented signed OIDC wrong-claim/realm-role coverage and actual bidirectional
Go/PHP cart/order replay, PIN hash, opaque refresh/logout lifecycle tests using
only the disposable test DB and a temporary original Go test writer.

Final rebuilt image: 6a2deb4485b224e1ab440d23fa99853cf1866ecfb48d176d876db4a4888cd5f6.
Fresh FPM container: shopkmp-yii2-task1-fpm-reviewed, no source mount. Complete
composer test:all: 69 tests, zero failures, 58 PHP files linted; unchanged original
Go E2E, Composer validate/audit and FPM/Nginx checks passed. Report append contains
exact commands, measured deadlines and remaining verification limits.
