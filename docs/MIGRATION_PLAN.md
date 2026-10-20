# ShopKMP: Yii2 + Go SDUI migration

> **Historical migration plan — superseded source-retention policy.** The user
> explicitly authorized removal of the original business source/archive after
> extracting essentials. Earlier local-retention instructions, reference-writer
> comparisons and importer commands below are historical, not current policy or
> rerun instructions. See [decommissioning](LEGACY_REMOVAL.md) and
> [deployment](DEPLOYMENT.md) for current checks and external-release rollback.

## Intent and status

The business owner becomes PHP 8 / Yii2; a separate Go HTTP service composes
versioned SDUI documents exclusively from the business API; commonMain renders
them using the existing UIKit. Existing API routes, data, exact decimal money,
authorization boundaries and transaction guarantees are compatibility requirements.

This document is an implementation ledger, not a claim of completed parity.
The original `backend/` remains the reference until the verification gates below
pass. No production cutover is authorized merely by adding a new service.

## Audited baseline (2026-10-18)

The workspace initially contained only `Shop-Go-master.zip`, with no Git commits.
The archive was extracted into the workspace without modifying its contents.
There are no AGENTS.md instructions. `go -C backend test ./...` passed before
implementation (PostgreSQL-dependent cases need TEST_DATABASE_URL).

Sources: `backend/internal/transport/rest/openapi.yaml`,
`backend/internal/transport/http/{router,route_handlers,commerce_handlers,server}.go`,
`backend/internal/{domain,auth,users,catalog,cart,orders,payments,reviews}`,
`backend/internal/shop/postgres`, `backend/internal/{identity,search,paymentgateway}`,
all migrations 001–011, Keycloak realm, Compose, README, architecture and
requirements traceability; Gradle modules, core network/navigation/UIKit and
the existing feature screens and tests.

### Existing functionality and ownership map

| Area | Existing source / data | New owner / implementation | Required parity evidence |
| --- | --- | --- | --- |
| Password registration/login/refresh/logout | identity Keycloak adapter; users | Yii2 application AuthService + Keycloak infrastructure | Real realm flow; JWT signature/iss/aud/azp/typ; negative tests |
| PIN | auth rules; device_pins | Yii2 credential service, compatible Argon2id | Existing hashes; weak-PIN validation; counters committed on failure |
| Biometric | postgres biometric; device_biometrics, biometric_challenges | Yii2 credential service, OpenSSL/sodium | ES256 DER + Ed25519; 120s one-time challenge; lockout/replay |
| Opaque sessions | access_sessions, refresh_sessions | Yii2 session repository | Binary SHA256; app_access_/app_refresh_; atomic refresh |
| Profile/avatar/addresses | users rules; users, addresses, avatar_files | Yii2 profile service/repository | Ownership; null semantics; upload type/pixel/body limits |
| Catalog/categories | catalog rules; categories, products, product_images | Yii2 catalog repository/service | Full DTOs, descendants, sorting, zero-based pagination |
| Search | OpenSearch shopkmp-products | Yii2 OpenSearch adapter | name^3 relevance, filters, 10000 result window, indexing |
| Cart | cart rules; cart_items, cart_metadata, idempotency_keys | Yii2 commerce service | Aggregate locks, increment vs replacement, response snapshots |
| Checkout | orders rules; orders, order_items, payments | Yii2 transactional commerce service | Concurrent checkout, current-price repricing, rollback, dedup |
| Payment | payments rules + gateway; processed_webhooks | Yii2 payment service/gateway | HMAC raw body, timestamp/source, dedup, transitions, refund/void |
| Reviews | reviews rules; reviews | Yii2 catalog/review service | Completed-purchase requirement, uniqueness, rating recomputation |
| Admin | canonical route policies, users.is_admin + realm roles | Yii2 bearer middleware + application services | Every admin mutation rejected for ordinary users |
| Screens | native feature ViewModels/builders | Go screens → commonMain SDUI modules | Eight document fixtures + real upstream + UIKit rendering |
| Native security/payment | common biometric, AuthService, CheckoutUseCase | Existing CMP adapters | No secret fields or executable/URL instructions in SDUI |

### Contracts and invariants

* All 54 canonical business operations retain `/api/v1`, methods, direct DTOs,
  decimal-string money and `{error:{code,message,traceId,details?}}` errors.
* Money is integer kopecks in application code, NUMERIC(12,2) in PostgreSQL;
  maximum 999999999999 kopecks. No float calculations.
* JWTs remain Keycloak-owned. Opaque tokens are independently verified by prefix;
  only binary SHA256 hashes of full opaque values are persisted.
* PIN format: `argon2id$v=19$m=19456,t=2,p=1$<hex salt>$<hex digest>`.
* Idempotency scope `(user,endpoint,key)`; endpoints `cart` and `orders`.
  Fingerprints respectively `productId:quantity` and `addressId:method:comment`.
  Read/write the existing JSONB envelopes and replay original successful DTOs.
* Checkout: READ COMMITTED; key-scoped advisory lock; lock cart/products in ID
  order; validate address ownership, quantity, activity, stock and exact totals;
  insert order/items/payment, reserve stock, clear cart and store replay atomically.
* Webhook signature is hex HMAC-SHA256 over `timestamp:id:originalRawBody`.
  The dedup marker and state/stock changes commit in one transaction.
* No rewrite of existing SQL/checksums or migration history. Yii history uses its
  own table. Legacy import remains an explicit maintenance operation.

### Client constraints

Kotlin 2.3.10, CMP 1.10.3, Ktor 3.5, Koin 4.2, Navigation3 and existing
build conventions remain. The existing RequestExecutor owns token refresh and
its mutex; expose a narrow JSON GET facade rather than creating another client.
UIKit states contain callbacks/Compose types and must be constructed locally.
Native PIN/biometric/checkout flows remain local semantic actions.
Catalog SQLDelight cache remains available to existing repositories.

## Approach decision

1. **Incremental parity migration (selected):** isolated PHP and BFF services,
   unchanged relational schema, differential tests, explicit deployment switch.
   More verification work, but supports rollback and avoids premature deletion.
2. Big-bang rewrite: simpler final routing, unacceptable unverified security and
   commerce behavior during implementation.
3. PHP proxy to Go: fast compatibility but violates PHP business ownership and
   is therefore not a valid completed architecture.

## Target design

Nginx is the public edge. `/api/v1/screens/*` and `/api/v1/sdui/*` go to Go;
other `/api/v1/*`, media and payment callbacks go to Yii2 through PHP-FPM.
Go calls only the configured internal Yii2 origin; it has no DB credentials.
Only allowlisted bearer/request-ID headers are propagated. Yii2 verifies auth
for every protected operation; BFF trusts no client-provided identity/role header.

The SDUI v1 envelope includes schemaVersion, screenId, revision, components,
actions, state, metadata. Nodes/actions use finite tagged types and stable IDs.
Unknown optional components render safe fallback; unsupported schema displays a
screen-level error. Enforce body, node/depth/string limits and no arbitrary
URLs/methods/code. Personalized responses are no-store.

commonMain modules: sdui-model (typed wire + validation), sdui-network (existing
executor facade), sdui-actions (finite commands/form state), sdui-renderer
(UIKit adapters). A feature/main host manages StateFlow, cancellation, one
mutation at a time, refresh and existing navigation/native semantic adapters.

## Implementation and verification stages

1. Audit: preserve original source, establish tests, document routes/schema/rules.
2. PHP: framework/bootstrap, compatible domain types; catalog/profile/auth and
   commerce services; full route dispatcher; PostgreSQL and API/contract tests.
3. BFF: bounded typed Yii2 client; schema; eight builders; actions and HTTP server;
   mock HTTP, auth, cancellation, aggregation and document compatibility tests.
4. CMP: four modules, typed tolerant decoding, UIKit registry, state and actions.
5. Integration: main-container destinations, safe navigation, existing checkout,
   profile/logout/biometric adapters; common tests and platform builds.
6. Infrastructure: independent Dockerfiles; shared edge/internal Yii HTTP;
   healthchecks, env, graceful shutdown, development and production configuration.
7. Verification/review: original regression suite; PHP unit/integration/API;
   Go BFF race/vet; differential contracts; Compose build/up and E2E; Android
   and iOS builds; update actual evidence and remaining gaps.

After each implementation stage review the changed boundary and run relevant
checks before extending it. Never label an unexecuted check as passing.

## Safe database upgrade and rollback

Back up the existing DB and record applied migration checksums. Stop the current
business writer, run the original checksum-validating forward migrator as a
one-shot job if needed, then Yii's additive migrations with a separate history
table. Do not seed or overwrite a populated catalog at application startup.
For cutover stop/drain legacy requests before routing business writes to Yii2.
Do not run both business implementations as writers on a production database.
Keep legacy binary/config and a pre-cutover backup. Roll back routing only after
stopping Yii2 writes and confirming schema/data compatibility. Retain additive
tables on rollback; never drop user/commerce data to undo application deployment.

## Toolchain observations

Docker Engine and Xcode 26.2 are available. Host Go 1.25.3 automatically selected
the required backend toolchain for the passing baseline. Host Java is 17.0.9;
the project recommends 21. PHP/Composer are not installed on the host; use the
PHP Docker build/test environment. Gradle wrapper 8.14.3 downloaded successfully.

## Evidence ledger

| Check | Actual result |
| --- | --- |
| Original `go -C backend test ./...` | PASS before implementation |
| `docker version` | Engine available |
| `./gradlew --version` | PASS, JVM 17.0.9; Gradle 8.14.3 |
| Migrated runtime bounded integration | PASS in isolated Task4 stack; see VERIFICATION_REPORT.md |
| Exhaustive parity / production cutover | Not completed or authorized |

Known source inconsistencies (not implicit migration fixes): opaque sessions are
not device-linked; Keycloak password changes do not currently revoke opaque
sessions; address deletion can conflict with historical order FK; biometric
hardware attestation is not server-verified. Preserve the trust boundary and
document/test any intentional correction rather than weakening it silently.

## Task4 deployment/integration ledger (2026-10-18)

The root default is now the single-edge Yii2 + HTTP-only Go BFF runtime, with
private PostgreSQL/Keycloak/separate identity DB/OpenSearch. Both independent
Dockerfiles are built. A separate deployment Dockerfile compiles only the
original checksum migrator; no legacy business executable is present in the
development or production model. The temporary reference writer exists only in
`deploy/compose.verify.yml`, on the explicitly disposable test DB, and is stopped
after compatibility checks. Original source/migrations/realm remain intact.

Actual canonical count is54 (15 public +32 protected +7 admin), not55. The
[route matrix](ROUTE_MATRIX.md) records every exact operation/policy and maps it
to PHP success/access/regression coverage, plus8 BFF screens/6 commands. The
generator verifies both source registries and documentation drift.

The Task4 run exercised normal security ceilings on a new isolated project,
`shopkmp-migrated-e2e`, through loopback58095. It includes real Keycloak
registration/login, eight independent schema validations, six BFF commands,
canonical checkout, CARD demo capture and orders/details/profile/devices/logout;
concurrent same-key/different-key checkout, access/spoof/body/timeouts; all54 PHP
successes; the original unchanged Go E2E; differential GET/errors and both
directions of replay/hash/session compatibility. Original Go tests and BFF
race/vet passed. Exact commands/results are in [VERIFICATION_REPORT.md](VERIFICATION_REPORT.md).

Development seeding is an explicit one-shot command, transactionally empty-only
and production-forbidden. Startup never seeds or overwrites a catalog. Initial
search reconciliation is a required one-shot dependency; independent workers run
maintenance every minute and reindex every five minutes. Yii migration history
is separate; the original migrator continues to validate its existing checksums.

Production overlay now has one HTTPS domain, verified internal DB/Keycloak/search
certificates and explicit PHP curl/OpenSSL provider trust. Keycloak remains a
private issuer because CMP password flows use PHP; issuer/discovery/JWKS use the
same origin. The edge strips untrusted proxy/identity headers; the internal
Nginx resolves only its fixed trusted edge hop into REMOTE_ADDR. PHP trusts no
forwarded chain. The actual edge-client IP/rate principal and a spoofed allowed
webhook source are verified through the runtime/TLS tests.

**Ruling:** preserve the reviewed business/BFF/CMP sources; no integration bug
required a business-code edit. Only the allowed narrow development seed console
command/registration/test was added to PHP. Cost: deployment must honor the
existing private-issuer and header-allowlist semantics, including BFF internal-IP
network budgets; they are documented rather than weakened for tests.

**Ruling:** use a temporary original writer on this disposable DB for the existing
bidirectional compatibility suites, never in the production model. Cost: these
tests leave real synthetic fixtures and require explicit reindex before reuse;
they must not be pointed at a real deployment.

Remaining release gates: exhaustive differential inputs/errors/state transitions
across the full matrix, real provider TLS/protocol/settlement and ambiguous outcomes,
physical credential/payment-app behavior,
restore/rollback rehearsal, sustained load/reconciliation/failover and full JWKS
rotation/outage behavior. The local TLS-mode stack uses throwaway test PKI; it is
not a production cutover. Keep the original reference until those gates pass.

## Final integration evidence (2026-10-20)

Installed Android and iOS authenticated CARD journeys now pass through the shared
edge, all eight SDUI destinations and real Chrome/Safari test-payment completion.
The native Keychain bridge/startup regressions discovered by actual iOS launch
were fixed without changing stored-value identity or biometric requirements.
See [mobile evidence](MOBILE_E2E_REPORT.md) and [final review](FINAL_REVIEW.md).

Direct sampled Go/Yii comparison now covers all54 operations:194 paired cases,
189 matches,5 documented corrections,0 unexpected mismatches;77 PHP regressions
pass. [API parity report](API_PARITY_REPORT.md) distinguishes these results from
exhaustive all-input/state parity. The obsolete refresh wording delta was removed.
The root runtime needs no legacy business server; the unchanged original remains
the offline migration/contract reference. [Handoff](COMPLETION_REPORT.md).
