# Yii2 Task 1 implementation and verification report

> **Historical implementation record — before decommissioning.** The source paths,
> comparison commands and test counts below describe the original milestone.
> The Go reference/importer and differential/cross-runtime harnesses are no longer
> bundled; these are not current rerun instructions or new passes. See
> [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md).

Date: 2026-10-18. Scope: `backend-yii2/**` plus this report.

## Status

Implemented an independent PHP 8.3 / stable Yii2 business application, with real
PostgreSQL repositories/application services, Keycloak password flows/JWT
verification, opaque sessions, PIN/biometric credentials, catalog/search,
profile/avatar/addresses, cart/checkout/orders, payments/webhooks and reviews.
No business request proxies Go. Runtime business data is not mocked.

**Canonical count correction:** the original Go `apiRoutes` registry contains
**54**, not 55, operations: **15 public + 32 protected + 7 admin**. The exact
method/path/access set matches the PHP manifest. All 54 have an exercised
successful HTTP response through **Nginx → PHP-FPM → Yii2 → PostgreSQL**. Health,
protected downloads, generated catalog media and development demo payments are
additional routes, not invented canonical operations.

**Final result: 50 tests, zero failures; 50 PHP files passed syntax checks.**
The unchanged original Go E2E also passed against the new PHP-FPM application.
These results do **not** establish exhaustive production parity or authorize
cutover.

## Delivered files

* `composer.json` / `composer.lock`: Yii2 **2.0.55**, firebase/php-jwt **7.2.1**,
  PSR-4 `Shop\\`, minimal API dependencies.
* `Dockerfile`: PHP 8.3 FPM, Composer, required PHP extensions, CLI testing.
* `web/`, `config/web.php`, `controllers/ApiController.php`: independent Yii
  bootstrap and thin bounded HTTP adapter, no cookie/CSRF auth.
* `config/routes.php`: complete canonical method/path/service/access manifest.
* `application/`: Auth, Session, Credential, Profile, Avatar, Catalog, Commerce,
  Payment and Maintenance services.
* `repositories/ShopRepository.php`: canonical SQL reads/DTOs, locks and replay.
* `domain/`: integer money, legacy Argon2id compatibility and validation rules.
* `infrastructure/`: PDO, strict JSON, bearer boundary, bounded HTTP,
  Keycloak/JWKS, OpenSearch, payment gateway, rate limits and CIDRs.
* `yii`, console configuration and `migrations/README.md`: separate
  `yii2_migration_history`; maintenance/reindex/routes commands.
* `config/nginx.conf`, `.env.example`, `README.md`: internal HTTP/FPM contract,
  legacy-compatible environment names and lifecycle handoff.
* `tests/`: unit, PostgreSQL, real identity/search, security, concurrency, HTTP,
  provider-protocol and original-Go differential suites.

Original backend source, SQL migration history/files 001–011, CMP and root
configuration were not edited. No commits, subagents or production switch.

## Sources actually inspected

Original `router.go`, `route_handlers.go`, `commerce_handlers.go`, `server.go`,
`http.go`, `routes.go`, canonical OpenAPI, domain model and money, auth/cart/
catalog/orders/payments/reviews/users rules, Keycloak client/OIDC verifier,
PostgreSQL normalized reads, auth/cart/profile/catalog/biometric/relational-order
commands, security and persistence formats, generated media, maintenance,
migrations **001–011**, Keycloak realm, environment examples and original E2E.

## Implemented invariants

* Exact decimal strings, checked integer cents with 999999999999-cent limit.
* RS256 JWKS signature and issuer/audience/typ/azp/expiry checks; subject bound
  to the application profile. Keycloak registration/login/refresh/logout and
  password reset/session/consent revocation. No replacement JWT issuer.
* `app_access_` / `app_refresh_`, binary SHA256 persistence, atomic refresh.
* Original Argon2id hex format; weak/birthday PIN rules; failure counters commit
  despite errors; lockout and third-lock revocation. ES256 DER and Ed25519;
  120-second one-use challenges and replay serialization.
* Cart increment versus replacement, aggregate serialization, original replay
  snapshots and fingerprints in existing JSONB envelopes.
* Checkout READ COMMITTED, key advisory lock, aggregate lock, sorted product/cart
  locks, owned address, active/stock/quantity/current-price validation; order,
  items, payment, stock, cart clear and replay in one transaction.
* Raw-body webhook HMAC, timestamp/source checks, transactional dedup and state
  transitions; sorted restoration, refund/void, ownership and admin policies.
* Completed-purchase/unique reviews with rating recomputation under product lock.
* Strict object JSON types/unknown keys/body limits, bearer-only authorization,
  request IDs, stable error envelope, CIDRs and shared PostgreSQL rate limits.
* Avatar file/type/pixel limits, metadata stripping and PNG normalization;
  protected download; development-only secret-bound demo payment route.
* Bounded, TLS-verifying external calls, no redirects, no automatic mutation
  retries, no request-provided outbound origin. OpenSearch name^3 relevance,
  descendant/price/sort filters, 10000 window, indexing/reindex command.
* Separate Yii migration history; application startup never seeds or overwrites
  data. Health/live/ready and explicit scheduled maintenance.

## Isolation and provisioning actually used

Pre-existing `moneytrack-*` resources were left alone. Created the dedicated
network **`shopkmp-migration-test`** after confirming it did not exist, and a
dedicated PostgreSQL container. No existing containers, networks or volumes
were removed.

```sh
docker network create shopkmp-migration-test
docker run -d --name shopkmp-yii2-task1-db --network shopkmp-migration-test \
  -p 127.0.0.1:55439:5432 \
  -e POSTGRES_USER=shopkmp_test -e POSTGRES_PASSWORD=task1_disposable \
  -e POSTGRES_DB=shopkmp_test postgres:17-alpine
```

Original migration executable, from `backend/`:

```sh
DATABASE_URL='postgres://shopkmp_test:task1_disposable@127.0.0.1:55439/shopkmp_test?sslmode=disable' go run ./cmd/migrate
```

Actual output: `database schema is up to date`. No SQL history was copied,
rewritten or checksum-bypassed. `php yii migrate --interactive=0` subsequently
created **only** the separate `yii2_migration_history` and found no new migrations.

Other dedicated test containers:

| Name | Purpose |
| --- | --- |
| `shopkmp-yii2-task1-keycloak` | Keycloak 26.4; original realm mounted read-only; `KEYCLOAK_CLIENT_SECRET=task1_realm_secret`; development import |
| `shopkmp-yii2-task1-search` | OpenSearch 2.19.1; single node, security plugin disabled only for this isolated test, 512 MiB heap |
| `shopkmp-yii2-task1-api` | Earlier PHP CLI HTTP verification server, source-mounted |
| `shopkmp-yii2-task1-fpm-verified` | Final built application image, no application source mount; network alias `yii2` (earlier own FPM container retained, stopped) |
| `shopkmp-yii2-task1-http` | Nginx 1.27 Alpine, supplied internal FPM config; host test port `127.0.0.1:58088` |
| `shopkmp-yii2-task1-upstream` | Test-only provider protocol fixture for timeout/limit/no-retry assertions |

The live original Go reference was built into ignored
`backend-yii2/runtime/reference-server` and run with `TZ=UTC`, host ports
127.0.0.1:58089/59089, `ALLOW_LOCAL_AUTH=1` and a dedicated **read-only business
DB role**. That role could write only `rate_limit_hits`, for legacy middleware.
The reference did not process business mutations. `tests/reference-setup.sql`
creates this test-only role and a bootstrap marker to prevent original automatic
catalog seeding. All business fixtures were created explicitly by tests.

## RED → GREEN evidence

* Initial `docker run --rm shopkmp-yii2-task1 php tests/run.php`: **6 failures**
  before Money/Secret/Rules/JsonInput/route manifest existed.
* Initial PostgreSQL `php tests/integration.php`: missing Database/service classes
  before application implementation. After the first implementation: checkout,
  rollback, committed PIN failures, both biometric algorithms and payment/profile
  tests passed.
* Initial real-identity `php tests/auth.php`: AuthService absent; subsequently
  **3 passing tests with real Keycloak**, including password change and corrupt
  signature rejection.
* `php tests/api.php`: ApiKernel absent before HTTP boundary implementation;
  subsequently all routes exercised successfully through real HTTP/FPM.
* Real-search suite: OpenSearch adapter absent before implementation; then an
  added Basic-auth URL regression failed `Invalid configured HTTP origin`, and
  passed after credentials were moved into an Authorization header.
* Canonical cart regression failed `INSUFFICIENT_STOCK` versus expected
  `OUT_OF_STOCK`; corrected code/message, then full SQL suite passed.
* Timestamp regression failed redundant `.000000Z`; Go-compatible trimming fixed
  it. Live original-Go comparison later passed exact parsed JSON equality.
* Wrong-kind opaque refresh regression failed **500 versus expected 401** when
  it attempted Keycloak; now rejected locally as `INVALID_REFRESH_TOKEN`.
* Maintenance test was observed failing with absent MaintenanceService, then
  passed with reservation expiry and retention cleanup implemented.
* Final review found readiness using a fixed external timeout despite
  `READINESS_TIMEOUT`. The slow-provider regression failed its elapsed-time
  assertion, then passed after wiring the configured readiness timeout into
  identity and search checks.
* `go run ../backend-yii2/tests/argon-reference.go` from `backend/` generated the
  exact x/crypto Argon2 vector used in PHP verification:
  `d6e888e081e7252f4945dfb1a356bf954208fdb9347ef8f44b1d1a9ec67494cf`.

The initial Composer build also correctly rejected vulnerable firebase/php-jwt
6.x (`PKSA-y2cr-5h3j-g3ys`). The dependency was upgraded to stable 7.2.1 rather
than disabling advisories.

## Final full verification command and results

Run from the repository workspace (the command below was executed against the
final built image, **without mounting PHP application source**):

```sh
docker run --rm --network shopkmp-migration-test \
  -e DATABASE_URL='postgres://shopkmp_test:task1_disposable@shopkmp-yii2-task1-db:5432/shopkmp_test?sslmode=disable' \
  -e KEYCLOAK_ISSUER=http://shopkmp-yii2-task1-keycloak:8080/realms/shopkmp \
  -e KEYCLOAK_CLIENT_ID=shopkmp-backend \
  -e KEYCLOAK_CLIENT_SECRET=task1_realm_secret \
  -e ADMIN_EMAILS=admin@test.example -e ADMIN_BOOTSTRAP_TOKEN=task1_bootstrap \
  -e PAYMENT_WEBHOOK_SECRET=task1_webhook_secret \
  -e OPENSEARCH_URL=http://shopkmp-yii2-task1-search:9200 \
  -e API_ORIGIN=http://shopkmp-yii2-task1-http:8080 \
  -e REFERENCE_ORIGIN=http://host.docker.internal:58089 \
  -e UPSTREAM_FIXTURE_ORIGIN=http://shopkmp-yii2-task1-upstream:8080 \
  -e SENSITIVE_RATE_LIMIT=10000 -e READ_RATE_LIMIT=10000 \
  -e SEARCH_RATE_LIMIT=10000 -e MUTATION_RATE_LIMIT=10000 \
  -v "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend:/reference:ro" \
  shopkmp-yii2-task1 composer test:all
```

| Suite | Actual result / scope |
| --- | --- |
| `tests/lint.php` | 50 PHP files, PASS |
| `tests/run.php` | 7 tests, PASS; exact money, Go Argon2 vector, PIN, JSON, routes, timestamps |
| `tests/integration.php` | 11 tests, PASS; SQL snapshots/rollback, both signature algorithms, lockout revocation, payments, profile/reviews, maintenance |
| `tests/auth.php` | 3 tests, PASS; real Keycloak registration/login/refresh/logout/password reset, claims and corrupt signature |
| `tests/security.php` | 6 tests, PASS; token domains, raw HMAC/time/source, rate limits, CIDRs, image bomb rejection, no cookie/header identity |
| `tests/concurrency.php` | 7 tests, PASS; independent PHP processes/connections, last-stock checkout, same-key replay, refresh, failure counters, webhook dedup, multi-product lock order, biometric replay |
| `tests/api.php` | 8 tests, PASS; all 54 successful routes through Nginx/FPM; all protected/admin negative policies, strict input, ownership, avatar and complete flows |
| `tests/search.php` | 2 tests, PASS; real OpenSearch ranking/filters/window/index deletion and Basic-auth config |
| `tests/adapters.php` | 3 tests, PASS; redirects/response bound/deadline, exact cents, provider protocol and single failed mutation attempt |
| `tests/readiness.php` | 1 test, PASS; configured provider-readiness timeout |
| `tests/differential.php` | 2 tests, PASS; exact route/policy set plus 14 live GET DTO comparisons and 3 representative error comparisons |

The provider test intentionally logs `gateway authorize failed: Gateway rejected
request` for its asserted 503/no-retry scenario; the readiness test intentionally
logs `Readiness: Configured upstream unavailable` for its timeout. Neither is a
suite failure.

Additional commands actually run:

```sh
# backend-yii2/ working directory:
docker build -q -t shopkmp-yii2-task1 .
# Final tested production-code image:
# sha256:049edce1d8b77cb95028f8c938b5c022e9ef879be5ecc96c58a70dfe11fe5424

docker exec shopkmp-yii2-task1-fpm-verified php-fpm -t
docker exec shopkmp-yii2-task1-http nginx -t

# In PHP image with backend-yii2 mounted at /app:
composer validate --strict
composer audit --locked

# backend/ working directory, original source unchanged:
go test ./...
go run ./cmd/e2e -base-url http://127.0.0.1:58088
```

Results: image built; FPM/Nginx syntax passed; Composer strict validation passed;
**no dependency vulnerability advisories**; original Go suite passed; original
Go E2E output:

```text
ShopKMP E2E passed: register, login, PIN, catalog, search, cart, checkout and demo payment
```

`tests/e2e-fixture.php` explicitly inserted the test-only “Тестовая лампа”
fixture for the unchanged E2E's Russian search term. It is not application seed
logic and is not executed at startup.

The image's actual PHP runtime is **8.3.35**. An executed extension check
confirmed `pdo_pgsql`, `intl`, `mbstring`, `sodium`, `openssl`, `gd` and `pcntl`
all loaded. A repeat original migration command after Yii history creation again
reported `database schema is up to date`.

## Review, rulings and deliberate corrections

Self-review only, as requested (no subagents). Reviewed failure-counter commit
paths, all access policies, resource ownership, transaction boundaries/lock order,
provider timeouts/no retry, raw webhook bytes, and upload memory limits.

1. Follow the actual **54-route** source registry. Cost if the plan count was
   intentional: identify the missing source operation rather than invent a URL.
2. Work in the supplied unborn workspace, without commits/worktrees or root
   changes. Record evidence within the permitted files.
3. Address deletion referenced by historical orders returns `ADDRESS_IN_USE`;
   the original could reach an FK/database error. Schema and history remain intact.
4. A mismatched biometric credential cannot consume another credential's nonce.
5. Wrong-kind `app_*` refresh values stay within the application trust domain.
6. Use UTC timestamps. A first host-reference run exposed its local +07:00
   timezone; rerunning the reference with `TZ=UTC` enabled exact comparisons.
7. OpenSearch stable sort uses `id.keyword`, avoiding restricted `_id` sorting;
   current stock/rating is read back from SQL. Reindex remains an explicit
   lifecycle/maintenance operation.

## Residual issues and unverified scope

* **Not exhaustive differential parity:** live comparison covered 14 GET DTOs,
  three representative errors, all route policies and the unchanged Go E2E.
  Successful coverage of every route is not an exhaustive input/state matrix.
* **Real production payment provider not exercised.** Development payment flow
  and bounded provider-protocol fixtures passed. Provider TLS/certificates,
  actual refund/void settlement and ambiguous external outcomes still require
  deployment/provider integration verification. Outbound operations are not
  blindly retried; true cross-system atomicity is not claimed.
* **Production infrastructure/cutover not performed.** Public edge/TLS,
  secret provisioning, backup/restore, production load and failover belong to
  deployment verification. Scheduled `maintenance/run` (1 minute) and reindex
  (5 minutes) must be wired by Task 4; they are implemented CLI operations.
* **Search reconciliation/load:** real ranking/indexing tests passed on test
  fixtures. Large-catalog full reconciliation, provider outages during reindex,
  and sustained-volume performance were not benchmarked. Run initial reindex
  before readiness/cutover and keep the scheduled reconciliation.
* **OIDC rotation/outage matrix:** real signature/claim checks passed; full JWKS
  rotation, long provider outage and revocation-latency matrices were not run.
* **Retained legacy limitations:** opaque sessions are not device-linked;
  Keycloak password changes do not revoke opaque sessions; no hardware
  attestation claim. A Keycloak logout does not instantly invalidate an already
  issued locally verified JWT before its normal expiration.
* **Self-review is not an independent audit.** No production certification or
  security audit is implied by this implementation report.

Dedicated disposable test containers/network are intentionally identifiable and
were not removed; no pre-existing resources were destroyed. The temporary
original reference process can be stopped independently by its exact runtime
binary path. Dependency installation (`vendor/`) and reference/runtime artifacts
are ignored and are not application source deliverables.

---

## Review follow-up — I1/I2/I3 and M1/M2/M3 (2026-10-18)

This section supersedes the earlier completion/evidence statements where noted.
Read `docs/YII2_REVIEW.md` in full and verified the findings against the original
Go middleware, protected-auth ordering, checkout/profile SQL, HTTP adapters,
OpenSearch query builder, duration configuration and domain error mapping.
The original review file was not edited. Changes are confined to
`backend-yii2/**` and this appended report. No commits or subagents were used.

### M1 evidence correction: gateway failures retain HTTP 400

The earlier phrase **“asserted 503/no-retry scenario” is incorrect**. The provider
fixture returned upstream HTTP 503, but the old test asserted only
`PAYMENT_GATEWAY_UNAVAILABLE` and a single provider attempt. It did not assert
an application HTTP 503 response. Both the original Go `domainHTTPStatus` and
PHP `ApiError::status()` inherit the unlisted-code fallback **HTTP 400**.

No error-status mapping was changed. A new `tests/adapters.php` boundary case
now asserts **API HTTP 400**, the precise error code, exactly one provider
attempt, payment still `PENDING`, order still `CREATED`, and the intact stock
reservation after a provider failure. The slow-header payment regression also
asserts the inherited 400 status. Readiness failures are separately HTTP 503.

### I1 — canonical failed-auth accounting and bucket policies

**Changed:** `infrastructure/{ApiKernel,RatePolicy,Network,RateLimiter}.php`.

* Protected authentication retains its success/error result, accounts the network
  request at the **unverified ceiling on failure**, and only then returns the
  authentication error. Only verified callers get the network multiplier.
* Verified per-user accounting precedes admin rejection, matching the Go
  `requireAuthentication` → `requireAdmin` order.
* `RatePolicy` follows the actual Go classifications: separate registration,
  login, refresh, PIN, biometric and management buckets; refresh ceiling 60;
  registration ceiling at most 10; separate create-order/create-review ceilings
  at most 10; sensitive payment mutations; ordinary mutations; read/search.
* Webhooks remain exempt from this general limiter. IPv4 /24 and IPv6 /64
  principals include IPv4-mapped-address normalization. Bucket names and advisory
  scopes match Go's `kind:network-...` / `kind:user-...` convention.

**Observed RED:** the first `tests/throttling.php` run had **6/6 failures**.
Missing/invalid/expired bearer probes returned `[401,401,401]` rather than
`[401,429,429]`; the forged multiplier, shared networks, registration/commerce
ceilings and webhook exemption assertions also failed.

**GREEN:** seven boundary tests now pass, including the added low-sensitive-limit
case. They run against the real `ApiKernel` and PostgreSQL counters with ordinary
or deliberately low limits, not only the high-limit successful-route setup.

### I2 — one parent/address order and actual controlled service races

**Changed:** `repositories/ShopRepository.php`,
`application/ProfileService.php`; new `tests/address_race.php`.

All cart writers/checkout acquire the **user `FOR KEY SHARE` before the cart
aggregate and owned address**. This covers both existing and lazily created cart
metadata and the later user-FK checks. The shared parent lock is compatible with
other user-FK checks; unnecessarily exclusive parent locks would introduce
contention/inversion opportunities against product/review writers. Profile,
address create/update/delete operations use the same parent-first ordering.

`address_race.php` uses test-only PDO statement barriers and independent processes
and database connections. It pauses actual services after a lock, confirms the
other worker is waiting through `pg_stat_activity`/`pg_blocking_pids`, then releases
the barrier. There is no production failpoint or substitute commerce logic.

**Observed RED:** both orderings reproduced **SQLSTATE `40P01`**:

* Checkout holding the address versus deletion waiting on it: deletion produced
  a deadlock exception instead of `ADDRESS_IN_USE`.
* Deletion holding the user versus checkout: checkout produced a deadlock in the
  order INSERT's user-FK check instead of `ADDRESS_NOT_FOUND`.

**GREEN:** both controlled actual-service races pass:

* Checkout first: one coherent order/item/payment/replay; stock 10→8; cart clear;
  address retained; deletion returns `ADDRESS_IN_USE`.
* Delete first: deletion succeeds; checkout returns `ADDRESS_NOT_FOUND`; no order,
  item, payment or order replay; stock stays 10 and cart quantity stays 2.

Both cases assert all requested tables, not just absence of a deadlock. Existing
checkout, multi-product, credential and webhook concurrency cases also pass.
The older duplicate-webhook test was renamed to accurately describe its two
FAILED-webhook operations; it no longer claims to race a cancellation operation.

### I3 — absolute whole-request deadline, including slow headers

**Changed:** `infrastructure/HttpClient.php`, Dockerfile, Composer manifest/lock.
Added required **`ext-curl`** and its Docker build dependency.

Replaced blocking HTTP streams with libcurl total `CURLOPT_TIMEOUT_MS`, covering
DNS/connect/TLS, upload, response headers and body with one budget. The tested
image has **libcurl 7.88.1 with asynchronous DNS enabled**. Redirects stay
disabled, HTTP/HTTPS protocols constrained, TLS verification enabled, response
bodies bounded and response headers capped at 64 KiB. Mutations are still
single-attempt. No retry was introduced to handle a timeout.

New `tests/SocketUpstream.php` is a raw test-only HTTP fixture able to continuously
drip headers. `tests/deadlines.php` exercises the actual client, readiness and
payment adapter.

**Observed RED:** all **4 deadline regressions failed** against the previous
stream implementation: slow headers, cumulative headers+body, 500ms readiness,
and the real payment adapter's fixed ten-second budget.

**Final GREEN measurements from the complete built-image run:**

| Case | Configured budget | Actual elapsed | Assertion |
| --- | --- | --- | --- |
| Continuous response headers | 1 s | **1.007 s** | <1.65 s |
| Headers plus delayed body | 1 s total | **1.006 s** | <1.35 s |
| Readiness with slow headers | 500 ms | **0.515 s** | <0.95 s; readiness HTTP 503 |
| Payment authorization with slow headers | 10 s | **10.005 s** | <10.90 s; one provider request; inherited HTTP 400 mapping |

Redirect/body-bound/silent-upstream/no-retry tests continue to pass. Expected
timeout/provider-rejection logs are asserted failure scenarios, not test failures.

### M2/M3 — concrete search and duration parity

* **M2:** OpenSearch uses `match_all: {}` when the query becomes empty after
  trimming, retaining filters and sort. The real OpenSearch regression first
  failed **expected 2 products, got 0**, then passed with both filtered products
  in exact-price order for a whitespace/tab-only query.
* **M3:** `Env::duration` preserves fractional seconds for `ms`; e.g. `500ms` is
  **0.5 seconds**, not 500 seconds. The direct parser regression failed
  **expected 0.5, got 500**, then passed. Nullable timeout signatures and callers
  accept that value; SQL session/rate/maintenance durations retain subsecond
  values, lockout timestamps preserve fractions, `expiresIn` remains the original
  integer-seconds DTO, and Retry-After remains a positive integer. The 500ms
  readiness slow-header case verifies propagation into a real HTTP call.

### Additional review-gap coverage completed

* `tests/signed_oidc.php`: validly **RS256-signed** wrong issuer, audience, payload
  type, authorized party, expiration and not-before tokens fail the actual bearer
  boundary. A signed `shopkmp-admin` realm role authorizes a stock mutation while
  the persisted user admin flag stays false. This uses a cryptographic JWKS
  fixture and real SQL profiles, supplementing the real Keycloak flow suite.
* `tests/cross_runtime.php`: actual **Go-produced cart/order JSONB records replay
  through PHP, and PHP-produced records replay through Go**, even after current
  order/product state changes. Final row counts/stock prove no second checkout.
* The same suite verifies both directions of PIN Argon2 hashing and hashed opaque
  access/refresh/logout interoperability, including consumed-refresh rejection
  by the opposite runtime and cross-runtime access revocation.

These cross-runtime tests used an additional original Go test writer only on
the existing isolated disposable DB, with unique fixture users. It was stopped
after verification. The production Yii application has no Go business upstream.
No original source/schema/migration was changed for these tests.

### Exact follow-up commands and artifact

All focused runs used the same isolated network/DB. The RED commands executed
before their respective fixes were:

```sh
# Working directory: repository root. At the RED stage the old tag referred to
# the previously reviewed image; PHP source was mounted to test the new regressions.
docker run --rm --network shopkmp-migration-test \
  -e DATABASE_URL='postgres://shopkmp_test:task1_disposable@shopkmp-yii2-task1-db:5432/shopkmp_test?sslmode=disable' \
  -v "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend-yii2:/app" \
  shopkmp-yii2-task1 php tests/throttling.php

# The identical Docker invocation was also executed with:
# php tests/address_race.php  -> 2 deadlock failures before I2 fix
# php tests/deadlines.php     -> 4 timing failures before I3/M3 fixes
# php tests/durations.php     -> expected 0.5 / got 500 before M3 fix

# Search RED used the new curl-capable review image but the uncorrected query:
docker run --rm --network shopkmp-migration-test \
  -e DATABASE_URL='postgres://shopkmp_test:task1_disposable@shopkmp-yii2-task1-db:5432/shopkmp_test?sslmode=disable' \
  -e OPENSEARCH_URL=http://shopkmp-yii2-task1-search:9200 \
  -v "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend-yii2:/app" \
  shopkmp-yii2-task1-review php tests/search.php
```

Covering GREEN runs subsequently executed `throttling.php`, `address_race.php`,
`search.php`, `durations.php`, `deadlines.php`, `adapters.php`, `signed_oidc.php`
and `cross_runtime.php` with the amended source. The final image was then rebuilt
and a fresh FPM container started from that immutable image, without a source
mount. The older own FPM container was retained stopped/disconnected; pre-existing
resources were not removed. Nginx was restarted to resolve the new `yii2` alias.

```sh
# backend-yii2/ working directory:
docker run --rm \
  -v "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend-yii2:/app" \
  shopkmp-yii2-task1 composer update --lock --no-install --no-interaction --ignore-platform-req=ext-curl
docker build -q -t shopkmp-yii2-task1-review .
```

The one-time lockfile-generation command used the old image without curl; the
new Docker build installed curl and ran normal Composer platform checks. It did
not bypass the final image's extension requirements.

Final tested image ID:
**`sha256:6a2deb4485b224e1ab440d23fa99853cf1866ecfb48d176d876db4a4888cd5f6`**.
Both `shopkmp-yii2-task1-review` and `shopkmp-yii2-task1` now name this image.
Active FPM: **`shopkmp-yii2-task1-fpm-reviewed`**. PHP **8.3.35**; all original
required extensions, `pcntl` and **curl** were confirmed loaded.

Actual fresh FPM creation command:

```sh
docker run -d --name shopkmp-yii2-task1-fpm-reviewed \
  --network shopkmp-migration-test --network-alias yii2 \
  -e DATABASE_URL='postgres://shopkmp_test:task1_disposable@shopkmp-yii2-task1-db:5432/shopkmp_test?sslmode=disable' \
  -e KEYCLOAK_ISSUER=http://shopkmp-yii2-task1-keycloak:8080/realms/shopkmp \
  -e KEYCLOAK_CLIENT_ID=shopkmp-backend -e KEYCLOAK_CLIENT_SECRET=task1_realm_secret \
  -e PAYMENT_WEBHOOK_SECRET=task1_webhook_secret \
  -e OPENSEARCH_URL=http://shopkmp-yii2-task1-search:9200 \
  -e SENSITIVE_RATE_LIMIT=10000 -e READ_RATE_LIMIT=10000 \
  -e SEARCH_RATE_LIMIT=10000 -e MUTATION_RATE_LIMIT=10000 \
  shopkmp-yii2-task1-review
docker restart shopkmp-yii2-task1-http
```

The additional writable original test reference was launched as follows, then
stopped after the cross-runtime/full-suite run:

```sh
env TZ=UTC \
  DATABASE_URL='postgres://shopkmp_test:task1_disposable@127.0.0.1:55439/shopkmp_test?sslmode=disable' \
  ALLOW_LOCAL_AUTH=1 HTTP_ADDR=127.0.0.1:58090 INTERNAL_HTTP_ADDR=127.0.0.1:59090 \
  ORDER_PENDING_TTL=720h ORDER_AUTHORIZED_TTL=720h IDEMPOTENCY_RETENTION=8760h \
  PAYMENT_WEBHOOK_SECRET=task1_webhook_secret READ_RATE_LIMIT=10000 \
  MUTATION_RATE_LIMIT=10000 SENSITIVE_RATE_LIMIT=10000 \
  nohup "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend-yii2/runtime/reference-server" \
  > "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend-yii2/runtime/reference-writer.log" 2>&1 &
```

**Final complete test command actually executed, without mounting PHP source:**

```sh
docker run --rm --network shopkmp-migration-test \
  -e DATABASE_URL='postgres://shopkmp_test:task1_disposable@shopkmp-yii2-task1-db:5432/shopkmp_test?sslmode=disable' \
  -e KEYCLOAK_ISSUER=http://shopkmp-yii2-task1-keycloak:8080/realms/shopkmp \
  -e KEYCLOAK_CLIENT_ID=shopkmp-backend -e KEYCLOAK_CLIENT_SECRET=task1_realm_secret \
  -e ADMIN_EMAILS=admin@test.example -e ADMIN_BOOTSTRAP_TOKEN=task1_bootstrap \
  -e PAYMENT_WEBHOOK_SECRET=task1_webhook_secret \
  -e OPENSEARCH_URL=http://shopkmp-yii2-task1-search:9200 \
  -e API_ORIGIN=http://shopkmp-yii2-task1-http:8080 \
  -e REFERENCE_ORIGIN=http://host.docker.internal:58089 \
  -e WRITABLE_REFERENCE_ORIGIN=http://host.docker.internal:58090 \
  -e UPSTREAM_FIXTURE_ORIGIN=http://shopkmp-yii2-task1-upstream:8080 \
  -e SENSITIVE_RATE_LIMIT=10000 -e READ_RATE_LIMIT=10000 \
  -e SEARCH_RATE_LIMIT=10000 -e MUTATION_RATE_LIMIT=10000 \
  -v "/Users/dmitrijbykov/Documents/GoLang/SDUI/backend:/reference:ro" \
  shopkmp-yii2-task1-review composer test:all
```

**Result: 69 tests, zero failures; 58 PHP files passed syntax checks. No optional
cross-runtime test was skipped in this run.** Counts: unit 7, durations 1, SQL
integration 11, real Keycloak 3, signed OIDC 2, security 6, throttling 7,
concurrency 7, controlled address races 2, HTTP API 8, real search 2, adapters 4,
readiness 1, whole-request deadlines 4, differential 2, cross-runtime 2.

Additional executed checks:

```sh
docker run --rm shopkmp-yii2-task1-review composer validate --strict
docker run --rm shopkmp-yii2-task1-review composer audit --locked
docker exec shopkmp-yii2-task1-fpm-reviewed php-fpm -t
docker exec shopkmp-yii2-task1-http nginx -t

# backend/ working directory, original E2E/source unchanged:
go run ./cmd/e2e -base-url http://127.0.0.1:58088
```

All passed; Composer reported no vulnerability advisories. Original E2E output:

```text
ShopKMP E2E passed: register, login, PIN, catalog, search, cart, checkout and demo payment
```

### Remaining evidence limits after this follow-up

I1/I2/I3 and M1/M2/M3 are addressed with the focused and full-suite evidence
above. Signed claim/role and bidirectional persistence gaps now have actual
coverage, but do not imply exhaustive state/claim/transition equivalence.
Real production gateway settlement/TLS, deployment/cutover, sustained load,
full JWKS rotation/outage and every mixed commerce race remain outside the
executed matrix. Previously documented inherited behavior remains unchanged.
The original independent review has not been rerun or rewritten to claim its
author accepted these fixes.
