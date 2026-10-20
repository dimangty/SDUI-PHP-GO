# Independent review: Task 1 Yii2 business backend

> **Historical review — before decommissioning.** Results and source/line references
> below describe the reviewed milestone; the retired Go reference tree and its
> comparison harnesses are no longer bundled. No new review pass is claimed.
> See [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md) for the
> preserved contracts, current checks and external-release rollback procedure.

Date: 2026-10-18

Scope: `backend-yii2/**` against the canonical `backend/` implementation.

## Verdict

**Specification verdict: partially compliant; Task 1 acceptance requires changes.**
The independent PHP business implementation, canonical route/access manifest,
money representation, credential/session persistence and principal commerce
transactions are present. Three Important defects remain in request throttling,
checkout/address lock ordering and external-call deadlines.

**Quality verdict: changes required.** There are meaningful integration and
process-concurrency tests, but they miss the failure paths identified below.
Successful route coverage and the documented green suites do not establish the
required security/concurrency parity.

**Findings: 0 Critical, 3 Important.** Three minor findings are listed separately.
No new bearer-verification bypass, cross-user resource access or duplicate stock
restoration was identified in the inspected paths. This is a bounded review, not
a claim that every possible input/interleaving has been verified.

## Basis and method

Read `docs/MIGRATION_PLAN.md`, Task 1 plus the global constraints/review focus in
`docs/IMPLEMENTATION_PLAN.md`, and `docs/YII2_IMPLEMENTATION_REPORT.md`.
Compared the actual PHP implementation directly with the original HTTP registry,
handlers, identity adapters, domain rules, PostgreSQL commands, search/payment
adapters and relevant schema/migrations. Inspected the PHP tests and runtime
configuration. The repository is unborn and all supplied files are untracked;
there is no meaningful commit-range diff or SHA baseline.

The route-count correction is justified: the original registry contains **54
operations: 15 public, 32 protected, 7 admin**, and the PHP method/path/access set
matches it (`backend/internal/transport/http/router.go:26–83`;
`backend-yii2/config/routes.php:7–60`). There is no identified missing 55th
operation.

The already documented suites were **not rerun**. New, narrowly targeted probes
were used only to establish concrete defects. They executed in the existing
`shopkmp-yii2-task1-fpm-verified` container, whose inspected image ID is the same
as the report's final image:

`sha256:049edce1d8b77cb95028f8c938b5c022e9ef879be5ecc96c58a70dfe11fe5424`.

The database probes used reads/row locks, not data mutations; lock transactions
were rolled back. Application files, tests, configuration and original source
were not edited. This review document is the sole requested output file.

## Important findings

### I1 — Failed protected authentication bypasses request throttling; the remaining budgets also diverge

**Locations:** `backend-yii2/infrastructure/ApiKernel.php:60–65,102–105,152–158`.

`handle()` authenticates protected requests, and checks the admin flag, before
calling `rateLimit()`. An absent/invalid/expired bearer throws directly to the
error response. Neither the network bucket nor any other failed-authentication
budget is consulted. Rejected non-admin calls also exit before throttling.

**Failure scenario:** repeatedly request `GET /api/v1/users/me` from the same
source with a missing or invalid bearer. Even when `READ_RATE_LIMIT=1`, every
request receives 401, never 429. Invalid opaque access values still perform a
session-table lookup on every attempt. JWT verification is similarly reached
before any failed-authentication request budget.

**Observed:** a three-request probe using `Bearer app_access_invalid`, a real
database connection explicitly set to `default_transaction_read_only=on`, and
`READ_RATE_LIMIT=1` returned:

```json
{"read_limit":1,"responses":[401,401,401],"database_mode":"read-only"}
```

A separate missing-bearer probe also returned `[401,401,401]`. These calls made
no rate-limit writes. This is not merely a test of `RateLimiter` in isolation:
it exercises the real `ApiKernel` ordering.

**Canonical comparison:** `backend/internal/transport/http/routes.go:34–52`
retains the authentication result, applies `AllowProtectedNetwork()` even on
failure, and only then returns the authentication error. A forged bearer cannot
obtain the authenticated network multiplier.

There are additional concrete policy differences in the same PHP boundary:

| Operation / principal | Original policy | PHP policy with defaults |
| --- | --- | --- |
| Password refresh | Separate 60-request network bucket | Shares the 20-request sensitive bucket with login, registration, PIN and biometric operations |
| Registration | At most 10 requests | 20 requests in that shared bucket |
| Create order / create review | Separate sensitive ceilings of at most 10 | Ordinary mutation ceiling of 120 |
| Payment confirm/refund/void | Sensitive-mutation ceiling of 20 | Ordinary mutation ceiling of 120 |
| Payment webhook | Exempt from this limiter | Ordinary mutation ceiling of 120, before HMAC/source validation |
| Network principal | IPv4 /24 or IPv6 /64 aggregation | Individual client-IP string |

The references for these differences are
`backend/internal/transport/middleware/rate_limit.go:162–196,227–236,303–325`.
In particular, rotating addresses within one IPv6 /64 now obtains independent
credential-request budgets, and normal refreshes can be rejected after other
credential operations exhaust their newly shared bucket.

**Required correction:** preserve failed-authentication network accounting and
the canonical route/bucket/principal policies. Add boundary tests for invalid
bearers, the unverified/verified multiplier, network aggregation and the distinct
refresh/sensitive-commerce budgets. The existing direct limiter test does not
cover this integration defect.

### I2 — Checkout and address deletion have an actual user/address lock inversion

**Locations:** `backend-yii2/application/CommerceService.php:60–74`;
`backend-yii2/application/ProfileService.php:55–60`.

Checkout takes `FOR KEY SHARE` on the owned address at line 61, then inserts an
order at line 74. The order's `user_id` foreign key must acquire a key-share lock
on the user. Address deletion does the reverse: `user(..., true)` takes
`FOR UPDATE` on the user before taking `FOR UPDATE` on the address.

**Failure scenario, for an established user/cart aggregate:**

1. Checkout holds the address key-share lock.
2. Concurrent address deletion holds the user update lock and waits for that
   address.
3. Checkout reaches the order insert and its user-FK check waits for that user.
4. PostgreSQL detects the cycle and aborts one operation. The API maps the
   `PDOException` to `503 DATABASE_UNAVAILABLE`, rather than completing checkout
   or returning the intended address-domain result.

Relevant supporting code/schema:

* User locking: `backend-yii2/repositories/ShopRepository.php:11–12`.
* The aggregate's existing-row path does not establish a user-first lock order:
  `backend-yii2/repositories/ShopRepository.php:24–27`.
* User/address order foreign keys:
  `backend/migrations/001_init.up.sql:25`.
* PDO exception mapping:
  `backend-yii2/infrastructure/ApiKernel.php:83–87`.

**Observed:** reproduced the exact conflicting row-lock graph on an existing
disposable-test user/address, using two independent connections and no INSERT,
UPDATE or DELETE. Both transactions were rolled back. The checkout-side probe
reported:

```text
SQLSTATE 40P01 ERROR: deadlock detected
Process ... waits for ShareLock on transaction ...; blocked by process ...
Process ... waits for ShareLock on transaction ...; blocked by process ...
CONTEXT: while locking tuple ... in relation "users"
```

The reproducing sequence is:

```sql
-- Connection A: checkout's address lock
BEGIN;
SELECT id FROM addresses WHERE id = :address FOR KEY SHARE;

-- Connection B: deleteAddress's parent lock
BEGIN;
SELECT id FROM users WHERE id = :user FOR UPDATE;

-- Run concurrently, after the first two locks are held:
-- A: equivalent to the order INSERT's user-FK lock
SELECT id FROM users WHERE id = :user FOR KEY SHARE;
-- B:
SELECT id FROM addresses WHERE id = :address FOR UPDATE;

-- Roll back both connections after deadlock resolution.
```

This probe proves the lock cycle; it did not execute a business mutation or
claim to be a complete HTTP checkout race test. The cycle is newly introduced:
the original checkout reads the address without the new key-share lock
(`backend/internal/shop/postgres/relational_orders.go:68–80`), and the original
address deletion does not first lock the user
(`backend/internal/shop/postgres/profile_commands.go:178–204`).

**Required correction:** establish one user/address lock order across checkout,
profile/default-address operations and address deletion. Add a barrier-controlled
checkout-versus-address-delete regression, checking the domain result and all
order/payment/cart/stock/replay tables. Existing checkout-vs-checkout tests do
not exercise this interleaving. Rollback protects data here; the finding is a
reproducible concurrency/availability defect, not evidence of partial checkout
commit or stock loss.

### I3 — The external HTTP timeout is not an absolute deadline during response headers

**Location:** `backend-yii2/infrastructure/HttpClient.php:20–28`.

The stream context's `timeout` is a blocking-I/O timeout. `fopen()` reads the HTTP
response headers before returning, while the elapsed-time check is only inside
the subsequent body loop. An upstream that keeps sending header lines within
each I/O timeout can keep the call occupied beyond the configured overall
timeout. The later elapsed-time exception does not undo the excess wait.

**Observed:** a local in-memory socket fixture, created and closed within one
PHP invocation, sent the status line followed by eight header lines 300 ms
apart, then a two-byte body. Calling the actual `HttpClient(1)` produced:

```json
{"configured_timeout_seconds":1,"elapsed_seconds":2.44,"result":"Upstream response limit or timeout exceeded"}
```

No fixture file, external-service configuration or application source was
changed. This is a new slow-header probe, not a rerun of the documented silent
`/slow` provider test.

**Failure scenario:** Keycloak, search, readiness or a payment provider drips
response headers while remaining active. Calls exceed their intended deadline.
For payment confirmation/reversal, the PHP service holds the order/payment row
locks throughout the provider call
(`backend-yii2/application/PaymentService.php:19–29,51–56,68–74`), so other state
transitions wait beyond the intended provider budget as well.

**Canonical comparison:** the original adapters use `http.Client.Timeout`,
which covers the whole request, including response headers:
`backend/internal/paymentgateway/gateway.go:66`,
`backend/internal/search/opensearch_searcher.go:61`, and
`backend/internal/identity/keycloak_client.go:77`. The new body-size bound and
no-automatic-retry behavior are present, but do not supply this missing deadline.

**Required correction:** enforce one absolute elapsed-time budget through
connection establishment, response headers and body reads, including the
configured readiness budget. Add a slow-header regression and verify that a
failed payment attempt remains single-attempt; do not solve this by retrying the
mutation.

## Minor findings

### M1 — The report's gateway-503 claim is not supported; 400 is inherited

`backend-yii2/domain/ApiError.php:20–27` omits
`PAYMENT_GATEWAY_UNAVAILABLE`, so the exception raised by
`backend-yii2/infrastructure/PaymentGateway.php:27` maps to **400**. A direct
probe confirmed `gateway_error_status=400`.

`docs/YII2_IMPLEMENTATION_REPORT.md:203–205` describes an asserted 503/no-retry
scenario. In fact, `backend-yii2/tests/adapters.php:23–24` checks only the error
code and provider attempt count, not an HTTP status.

This is **not a new migration-parity regression**: the original payment code
also raises a domain error, and
`backend/internal/transport/http/server.go:641–660` likewise maps that unlisted
code to 400. Correct the evidence claim; changing the status requires an explicit
compatibility ruling and a boundary test rather than silently assuming 503.

### M2 — Whitespace-only full-text search no longer behaves as an empty query

`backend-yii2/application/CatalogService.php:20–26` sends a nonempty whitespace
string to OpenSearch, and `backend-yii2/infrastructure/OpenSearch.php:68` always
constructs `multi_match`, even after trimming the query to `""`.

The original builder uses `match_all` when the trimmed query is empty
(`backend/internal/search/opensearch_searcher.go:363–367`). A read-only probe of
the new service with `q="   "` returned `totalItems=0` while the test database
contained 144 active products. Normalize this case before choosing the query
clause and cover it with a result assertion. It is a catalog/search contract
difference, not a permission leak.

### M3 — Accepted `ms` durations are interpreted as seconds

`backend-yii2/infrastructure/Env.php:11–12` accepts the `ms` suffix but uses the
same multiplier as seconds. A direct probe showed
`READINESS_TIMEOUT=500ms` parsing to **500 seconds**, not 0.5 seconds. The
original configuration uses Go duration parsing
(`backend/internal/config/config.go:114,383–388`). This is a concrete
configuration-parity defect; the supplied default/example durations do not use
`ms`. Preserve the actual unit, or reject an unsupported unit explicitly rather
than accepting it with a different meaning.

## Security and invariant assessment

The following are source-review results. Supporting suite results are those
documented by the implementation author, not independently rerun results.

| Area | Assessment |
| --- | --- |
| Keycloak/OIDC | PHP owns no replacement JWT issuer. RS256/JWKS verification, issuer, audience, payload `typ`, `azp`, expiry and subject/profile lookup are present (`OidcVerifier.php:24–47`, `AuthService.php:59–68`). JWKS origins are constrained; outbound TLS verification and redirect rejection are present. Registration/bootstrap, login/refresh/logout and password reset/session/consent calls are implemented. I1 and I3 affect the surrounding boundary. |
| Opaque sessions | Explicit access/refresh namespaces, random values and binary SHA256 persistence match the original format. Refresh consumes the old row and issues the replacement pair in one transaction (`SessionService.php:13–36`). No raw opaque-token persistence was found. |
| PIN | Legacy Argon2id parameters/hex encoding and weak/birthday PIN checks are present. Failed attempts return an error value from the transaction and throw after commit; active lockouts, repeated-lock revocation and successful reset are implemented (`CredentialService.php:20–64`). This avoids the rollback-on-error counter defect highlighted by the plan. |
| Biometrics | Ed25519 SPKI and P-256/ES256 DER verification are implemented. Credentials/challenges are locked, challenges last 120 seconds and are consumed once, failures commit counters, and revocation deletes credentials. Rejecting a mismatched credential without consuming the other credential's challenge is an explicit documented correction (`CredentialService.php:79–135`). |
| Owner/admin boundaries | Actor identity originates at bearer authentication. All seven admin routes are marked and enforced; persisted admin flags and verified realm roles are used. Address/order/payment/credential operations are owner-scoped; review deletion and avatar download have explicit owner/admin checks. Protected responses default to `no-store`; no personalized shared response cache was found. |
| Money and idempotency | Application money calculations use integer kopecks with checked line/aggregate limits. Money JSON remains decimal strings. The `(user,endpoint,key)` scope, `cart`/`orders` fingerprints and existing JSONB envelope fields match the original (`ShopRepository.php:56–68`; original `persistence_types.go:8–15`). Replays return stored successful DTOs rather than recomputed state. |
| Cart/checkout | Increment versus replacement semantics, cart aggregate locking, sorted cart/product row locks, current-price calculation, address ownership and stock/activity/quantity checks are present. Order/items/payment, stock reservation, cart clear and replay are transactional. I2 prevents accepting the complete concurrent-operation boundary as correct. |
| Payments/webhooks | HMAC covers timestamp, ID and original raw bytes; source/time checks precede JSON dispatch. Dedup insertion and payment/order/stock changes share one transaction, and rejected transitions roll back the marker. Order/payment locks and sorted restoration prevent a second successful restoration through these paths. Explicit refund/void call the provider; outbound mutations have no automatic retry. I1 changes webhook/sensitive-payment throttling and I3 affects provider deadlines. |
| Reviews/profile/avatar | Completed-purchase and unique-review requirements, product-locked rating recomputation, ownership, default-address missing/null handling, bounded upload size/pixels, image normalization and protected download are implemented. The broader historical-order address-deletion rejection is an expressly documented correction. |
| Routes/errors/DTOs | The canonical 54-operation policy set matches. Main DTO fields, pagination, money/null output and error envelope are implemented directly. The documented live comparison covers representative GETs, not the full input/error matrix. M1/M2 are concrete error/evidence and search differences. |
| Ownership/schema/lifecycle | Business requests execute PHP/SQL without proxying Go. Yii uses a separate migration-history table; no copied/destructive business migrations or application-startup catalog seeding were found. Maintenance/reindex are explicit CLI operations. |

Inherited behaviors were not mislabeled as new migration defects: opaque
sessions remain device-independent; Keycloak password changes do not revoke
them; already-issued locally verified JWTs are not instantly invalidated by
logout; hardware attestation is not verified. User cancellation and reservation
expiry also cancel an authorized payment locally without calling provider void
in both implementations (`PaymentService.php:72–74`,
`MaintenanceService.php:20–21`; original `relational_orders.go:218–245`). Provider
settlement parity is consequently not proven by the SQL/demo-payment tests.

Where original code accepts inputs beyond its OpenAPI constraints, the PHP code
sometimes follows the published schema instead: examples are the 10,000-character
description / 20-image bounds and the 2,000-character order comment limit. Those
bounds are in the original OpenAPI but not all are enforced by the original Go
rules. This is a source/OpenAPI discrepancy, not a newly invented PHP limit; an
exhaustive parity ruling still needs to account for already-stored values that
the old writer accepted.

## Test quality and remaining verification gaps

### Meaningful existing coverage

The suites contain substantial behavioral assertions, not just implementation
mirrors:

* Exact-money and a fixed Go Argon2 vector.
* Checkout repricing/snapshot replay and a deliberately injected mid-checkout SQL
  failure verifying rollback across tables (`tests/integration.php:26–48,147–157`).
* Committed PIN/signature failures, both biometric algorithms, expiry/replay and
  third-lock revocation (`tests/integration.php:49–88,132–145`).
* Independent-process last-stock/same-key checkout, refresh consumption, PIN
  counters and biometric replay (`tests/concurrency.php:24–61`).
* HTTP unauthenticated/admin negatives and representative cross-owner resources
  (`tests/api.php:39–44,63–83,98–106`).
* Real identity/search flows, raw webhook HMAC checks, outbound response bounds
  and provider attempt-count assertions.

### Concrete gaps exposed by inspection

1. **Rate-limit integration:** `tests/security.php:21–24` tests the limiter
   directly. It cannot detect I1. The documented full run raises all four rate
   limits to 10,000 (`docs/YII2_IMPLEMENTATION_REPORT.md:183–184`), so normal
   bucket policy is not exercised by successful-route coverage.
2. **Mixed-operation races:** no address-delete-versus-checkout test covers I2.
   Same-key cart addition, same-cart checkouts with different keys, cart mutation
   versus checkout, and payment capture/cancel/reversal races also lack dedicated
   interleaving assertions. The test named “concurrent duplicate webhooks and
   cancellations” actually races two copies of the same FAILED webhook, with no
   cancellation operation (`tests/concurrency.php:44–47`).
3. **Whole-request provider deadlines:** existing adapter/readiness timeout tests
   use a provider that stays silent before its response. They do not cover I3's
   continuously arriving headers (`tests/adapters.php:11–14`,
   `tests/readiness.php:5–10`).
4. **Cross-runtime persistence compatibility:** differential fixtures are created
   through PHP/direct SQL and read by both runtimes. The original reference is a
   read-only business role. This is useful DTO evidence, but does not demonstrate
   replay of Go-produced cart/order JSONB records through PHP, or both directions
   of opaque/hash lifecycle compatibility (`tests/differential.php:16–26`). The
   formats were compared in source, not exercised bidirectionally here.
5. **Signed OIDC negatives/admin roles:** the real-realm test corrupts a signature;
   issuer/audience/type/azp/expiry negatives call `validateClaims()` directly
   (`tests/auth.php:18–28`). Real signed wrong-claim tokens, realm-role-only admin
   authorization, registration compensation and JWKS rotation/outage behavior
   are not established by those tests.
6. **Exhaustive error/DTO parity:** the live differential suite compares 14 GET
   paths and three errors. Successful exercise of all 54 routes is valuable but
   does not cover malformed/nullable/legacy inputs, all transitions, all errors or
   failed gateway HTTP responses. M1 illustrates why an exception-code assertion
   must not be described as an HTTP-status assertion.
7. **Acknowledged external scope:** real production-provider settlement,
   deployment/cutover and sustained-volume reconciliation were not independently
   exercised. The implementation report already identifies these limits. They
   are evidence gaps, not additional speculative defect findings in this review.

## Independent probe ledger

| Probe executed during this review | Actual result |
| --- | --- |
| Invalid opaque bearer, three requests, read limit 1, read-only DB session | 401 / 401 / 401; missing throttling confirmed |
| Missing bearer, three requests, read limit 1 | 401 / 401 / 401 |
| Two-connection user/address row-lock graph, rollback only | PostgreSQL `40P01 deadlock detected`; no data writes |
| One-second HTTP client with response header lines every 300 ms | Failed only after 2.44 seconds; overall deadline exceeded |
| Direct gateway error-status mapping | 400, matching the original fallback but contradicting the report's 503 claim |
| Whitespace full-text query with read-only SQL connection | Zero results despite 144 active SQL products; original query builder uses `match_all` |
| Parse `READINESS_TIMEOUT=500ms` | 500 seconds |

No original Go suite, Composer full suite, build, migration, existing HTTP flow or
business mutation was rerun for this review. No fixes were implemented. Address
I1–I3 and add the focused regression evidence before accepting Task 1's security
and concurrency guarantees as complete.

---

## Scoped re-review — I1/I2/I3 and M1/M2/M3 fixes (2026-10-18)

**Scoped verdict: all six prior findings are addressed. No new Critical or
Important breakage introduced by these fixes was identified.** The fixes meet
the reviewed requirements, and their focused regression coverage is meaningful.
This supersedes the earlier blocking disposition for these six findings only.

### Evidence and scope

Read the follow-up in `docs/YII2_IMPLEMENTATION_REPORT.md:300–584` and inspected
the relevant current implementation, duration callers, dependency/build changes
and regression tests. The report records **69 tests, zero failures, and 58 PHP
syntax checks** against the rebuilt image. Those are documented implementation
results; no documented suite or prior probe was rerun during this re-review.

A metadata-only inspection of `shopkmp-yii2-task1-fpm-reviewed` confirmed its
image ID matches the follow-up artifact:

`sha256:6a2deb4485b224e1ab440d23fa99853cf1866ecfb48d176d876db4a4888cd5f6`.

### Finding dispositions

#### I1 — Addressed

`backend-yii2/infrastructure/ApiKernel.php:60–68,156–162` now retains an
authentication failure, accounts for the request at the unverified network
ceiling, then returns that failure. Verified network/per-user accounting also
precedes admin rejection. The multiplier depends on successful authentication.

`backend-yii2/infrastructure/RatePolicy.php:8–34` restores the canonical separate
credential/refresh and sensitive-commerce buckets and webhook exemption.
`backend-yii2/infrastructure/Network.php:7–13` restores /24 and /64 aggregation,
including IPv4-mapped normalization; `RateLimiter.php:11–18` uses compatible
bucket/advisory scope names.

`backend-yii2/tests/throttling.php:25–89` exercises the actual HTTP kernel and SQL
counters at low/default limits: missing/invalid/expired bearers, the verified
multiplier, admin rejection, network sharing, independent credential/refresh and
commerce ceilings, webhook exemption and a lowered sensitive ceiling. These
assertions directly cover the prior failure instead of relying on the full
suite's high-limit successful-route configuration.

#### I2 — Addressed

`backend-yii2/repositories/ShopRepository.php:24–32` acquires the user's
`FOR KEY SHARE` lock before cart metadata, the checkout address and product
locks. This removes the address-first/user-FK inversion for both established
and lazily initialized cart aggregates. The shared parent lock is compatible
with other user-FK key-share locks. Profile/address operations retain the
parent-first order; address update now does so explicitly as well
(`backend-yii2/application/ProfileService.php:17–25,40–54,58–63`).

`backend-yii2/tests/address_race.php:10–20,33–83` uses statement barriers around
actual service calls and independently connected processes. It verifies a real
blocking relationship before release, tests both checkout-first and delete-first
orderings, and checks orders/items/payments, stock, cart, replay and address
state. Expected outcomes are respectively `ADDRESS_IN_USE` after one coherent
checkout and `ADDRESS_NOT_FOUND` with no partial checkout. This is stronger than
the original review's lock-graph-only reproduction.

#### I3 — Addressed

`backend-yii2/infrastructure/HttpClient.php:23–60` replaces stream I/O timeouts
with libcurl's total millisecond deadline, including connect and response-header
reading. Redirects remain disabled, TLS verification is enabled, response bodies
and headers are bounded, and there is no application retry loop.
`backend-yii2/composer.json:14` and `backend-yii2/Dockerfile:2–4` declare/install
the curl extension.

`backend-yii2/tests/deadlines.php:13–39` covers continuous headers, cumulative
headers/body time, 500 ms readiness propagation and a single payment-provider
attempt at the ten-second budget. The follow-up records elapsed times of
1.007 s, 1.006 s, 0.515 s and 10.005 s respectively. Inspection of
`backend-yii2/tests/SocketUpstream.php:9–35` confirms the fixture actually drips
raw HTTP headers and counts accepted requests. Existing adapter assertions still
cover redirect rejection and oversized responses.

#### M1 — Addressed

`docs/YII2_IMPLEMENTATION_REPORT.md:309–321` explicitly corrects the unsupported
503 claim and retains the canonical inherited HTTP 400 mapping.
`backend-yii2/tests/adapters.php:27–40` now asserts that status through
`ApiKernel`, the precise error code, one provider attempt, unchanged
`PENDING`/`CREATED` payment/order states and the intact stock reservation.

#### M2 — Addressed

`backend-yii2/infrastructure/OpenSearch.php:64–70` emits `match_all: {}` for the
reported query that becomes empty after trimming, preserving filters and sort.
`backend-yii2/tests/search.php:14–15` checks a whitespace/tab-only query against
real indexed fixtures, asserting both result count and exact-price ordering.

#### M3 — Addressed

`backend-yii2/infrastructure/Env.php:8–14` now converts milliseconds to fractional
seconds. Timeout signatures and callers preserve those values
(`HttpClient.php:8,26`, `OpenSearch.php:12,23`, `OidcVerifier.php:11,49`). Session
expiry and SQL interval parameters retain fractional durations while the token
DTO's `expiresIn` remains integer seconds (`application/SessionService.php:16–19`).
PIN lockout comparisons/timestamps, maintenance rechecks and Retry-After handling
were adapted without removing the existing transaction/counter safeguards
(`application/CredentialService.php:20–42`,
`application/MaintenanceService.php:14–30`, `infrastructure/ApiKernel.php:89`).

`backend-yii2/tests/durations.php:5–7` checks 1/500/1000/1500 ms and existing
second/minute/hour units. The 500 ms slow-header readiness case supplies the
end-to-end timeout propagation assertion that the earlier parser-only behavior
lacked.

### Newly introduced Critical/Important findings

**None identified in the scoped fixes.** The reviewed changes close I1–I3 and
M1–M3; no additional blocking finding is raised by this re-review. This is a
scoped source/test assessment using the appended execution evidence, not a new
full-system test run or production-cutover verdict.
