# ShopKMP Yii2 business API

Independent PHP 8.3 / Yii2 application using the original PostgreSQL schema.
Business requests execute PHP application services and SQL repositories.
PHP owns passwords and account state; password, PIN and biometric sessions use
hashed opaque application tokens. No Go service is called by this application.

## Contract and layout

The unchanged [canonical OpenAPI](contracts/openapi.yaml) and frozen
[route manifest](contracts/routes.json) define **54** business operations:
15 public, 32 authenticated, 7 admin. The plans' reference to 55 was a counting
discrepancy. `config/routes.php` retains every method/path/access policy;
`python3 scripts/route_matrix.py --check` from the repository root checks the
exact set. The HTTP suite exercises all 54 operations. No retired source tree or
reference server is needed for current checks.

* `controllers/ApiController.php`: thin Yii HTTP adapter, bounded input stream.
* `infrastructure/ApiKernel.php`: routes, bearer authorization, strict wire types,
  error envelopes, request IDs, body limits, CORS, distributed rate limits.
* `application/`: auth, credentials, catalog/reviews, profile, avatar, commerce,
  payments and scheduled maintenance.
* `repositories/ShopRepository.php`: canonical SQL reads, DTO conversion,
  aggregate locks and compatible JSONB replay envelopes.
* `domain/`: integer money, compatible Argon2id hashes, IDs and validation rules.
* `infrastructure/`: PDO, bounded gateway and OpenSearch adapters.

Additional routes: `GET /health`, `/health/live`, `/health/ready`; protected
`/uploads/users/{userID}/{fileID}`; catalog-backed `/images/products/*` and
`/icons/*`; development-only `GET|POST /demo/payments/{paymentID}?token=...`.
Media placeholders match the original generated-media behavior; uploaded images
are validated, pixel-bounded, resized to at most 512 pixels, stripped of metadata
and stored as PNG in the existing `avatar_files` table.

## Build / run

From this directory:

```sh
docker build -t shopkmp-php-tests .
docker run --rm --network none shopkmp-php-tests composer test
docker run --rm --network none shopkmp-php-tests composer lint
docker run --rm --network none shopkmp-php-tests php-fpm -t
```

From the repository root, `make test-php` performs the standalone image build and
Composer unit/lint checks (`PHP_TEST_IMAGE` override); no `.env` or host PHP is
required. `cp .env.example .env` followed by `make run` starts the full application.

The image includes Composer and `pdo_pgsql`, `intl`, `mbstring`, `sodium`,
`openssl`, `gd`, `curl`, plus `pcntl` for concurrency tests. Dependencies are locked:
Yii2 2.0.55; the release lockfile is authoritative. Browser assets, Yii debug/Gii and a frontend
template are not required. `vendor/` and runtime artifacts are ignored.

Supply environment variables with `docker run --env-file ...`. The repository's
`.env.example` supplies development configuration; `.env` is not implicitly loaded
by the PHP process when running the image directly.
The app requires its canonical SQL schema and reviewed local identity state.
Registration always creates ordinary users; trusted console operations manage
reviewed administrator/disabled state. Existing users need an explicit identity
review/reset, not a seeded default password. See the [V6 design](../docs/backend-fixes/round-5-local-auth/ADR.md).

PHP-FPM listens on **9000**, entry script **`/app/web/index.php`**. An internal
HTTP edge can use `config/nginx.conf` (upstream DNS alias **`yii2`**, port 9000;
HTTP listener 8080). Do not publish FPM directly. The root Compose model owns the
public edge, TLS, internal-only operational endpoints and production orchestration.

### Schema and lifecycle

1. Stop/drain the old business writer for a real cutover.
2. Apply migrations **001–014** using the independent checksum-validating
   `tools/ops` module. From this directory, with the intended `DATABASE_URL`:

   ```sh
   go -C ../tools/ops run ./cmd/migrate
   ```

   The original 22 up/down SQL files for 001–011 remain immutable. New migrations
   012 (payment intents/callback correlation), 013 (session families/revocation)
   and 014 (bounded recovery scheduling/operator tickets) must be included in
   `tools/ops/migrations/SHA256SUMS` before release. Preserve existing `schema_migrations`
   versions/names/checksums/applied-at values; never reset history or customer data.
   The root `make migrate` runs this one-shot job followed by the Yii job.
3. Run `php yii migrate --interactive=0` in the PHP container. It uses the
   separate **`yii2_migration_history`** table. There are currently no additional
   Yii-managed business migrations; 012–014 belong to the independent SQL runner.
4. Explicitly run `php yii maintenance/search-bootstrap` for initial search
   construction/verification. PHP-FPM/general readiness waits on SQL, not search;
   missing/unready search is a search capability failure.
5. Schedule `php yii maintenance/run` every minute,
   `php yii maintenance/reindex` every five minutes, and
   **`php yii maintenance/recover` every 30 seconds**. Compose provides separate
   `maintenance`, search `reconcile`, and `payment-recovery` services. Maintenance expires pending
   reservations and cleans expired sessions, replay entries, challenges and
    rate-limit records. Recovery dispatches unsent payment intents; uncertain
    financial effects and historical identity decisions require manual review.
   Commands coordinate through PostgreSQL and support multiple worker replicas.

   Scheduled maintenance commits retention first (up to 1,000 expired rows per
   each of seven tables), then admits at most 100 local expiries. It queues
   durable reversal intents without provider HTTP or a trailing recovery pass;
   keep the dedicated recovery worker enabled to dispatch those intents.

Readiness requires the 012–014 schema as well as healthy dependencies. Migration
013 deliberately removes unlinked legacy opaque tokens: clients must sign in
again. Drain every old writer before applying it. See the detailed
[migration/rollback procedure](../docs/backend-fixes/MIGRATION_ROLLBACK.md) and
[integration evidence](../docs/backend-fixes/INTEGRATION_TEST_RESULTS.md).

Application startup never migrates, imports snapshots, seeds or overwrites a
catalog. Historical `legacy_import_status=required` blocks readiness for old
snapshot-only data. There is no bundled Go importer: use a separately retained
immutable release's controlled offline import procedure on a restored copy before
cutover, then validate normalized data/status. The test fixture scripts are
**only** for disposable test databases; see [decommissioning](../docs/LEGACY_REMOVAL.md).

### Authentication and commerce guarantees

* Cookie/session authentication and CSRF middleware are disabled. Only a verified
  `Authorization: Bearer` identifies the caller; forwarded identity/role headers
  are ignored. Native clients require no CORS origin.
* Opaque values have `app_access_` / `app_refresh_` prefixes. Only binary SHA256
  hashes of the full values are stored. Refresh consumption is atomic and stays
  in the same server-derived credential family. Credential deletion/terminal
  lockout revokes that credential's descendants. Password change updates the
  local hash and revokes prior families in one SQL transaction. Logout revokes
  all families for the authenticated user. Disabled/unreviewed/recovery-blocked
  identities cannot bypass policy via fast login or refresh; see
  [sessions](../docs/backend-fixes/SESSIONS.md).
* Failed protected authentication consumes the unverified /24 IPv4 or /64 IPv6
  network budget. Only verified users receive the authenticated multiplier;
  per-user budgets are applied before admin rejection. Auth, refresh, checkout,
  reviews and payment mutations retain the original distinct ceilings and bucket
  names. Webhooks are exempt from this general limiter.
* PIN hashes use `argon2id$v=19$m=19456,t=2,p=1$<hex salt>$<hex digest>`.
  Failed PIN/signature counters commit before the error response. Third lockout
  revokes the credential. ES256 DER and Ed25519 signatures are supported;
  120-second challenges are one-use under row locks.
* Money is integer kopecks in PHP and decimal JSON strings, never float money.
  Checkout takes a key-scoped advisory lock, the cart aggregate lock, then
  cart/product rows in product-ID order under READ COMMITTED. Order, items,
  payment, stock, cart clear and replay are one transaction. Checkout uses
  current prices; idempotent retries return the original successful snapshot.
  The user is key-share locked before the cart aggregate/address. Profile/address
  writers follow the same parent-first order, preventing inversion against
  checkout's later user-FK check.
* Webhook HMAC covers `timestamp:id:originalRawBody`. Timestamp/source checks,
  dedup marker, payment/order transitions and stock restoration are enforced.
  Durable intents commit before provider HTTP, with no transaction spanning it.
  Confirm/cancel/expiry/refund/callback share one lifecycle and a once-only stock
  guard. Recovery dispatches unsent intents; `SENT`/`UNKNOWN` effects are never
  blindly replayed. The development provider uses real SQL order
  and payment records and a secret-bound local demo-payment capability.
* Protected resources enforce owner identity. Every admin route checks the
  persisted reviewed admin flag; caller claims/headers cannot grant roles.
* Curl enforces a whole-request deadline across DNS/connect/TLS, uploads, response
  headers and body. Responses and headers are bounded, redirects disabled and
  mutations single-attempt. Durations such as `500ms` retain their real unit.

## Tests

Composer commands inside the PHP image:

```sh
composer test                 # offline unit / wire / manifest / search protocol / hash vector
composer test:integration     # PostgreSQL integration + local_auth_core; requires DATABASE_URL
composer lint
composer test:all             # all suites below, requires isolated services
```

`tests/all.php` runs unit, local identity SQL integration, security, process-level
concurrency, HTTP API, real OpenSearch and bounded-provider protocol tests.
It includes the focused session/logout/upgrade and original-client/rate suites.
The Composer `test:all` script runs `tests/local_auth_core.php` and
`tests/round5_search_protocol.php` through `tests/all.php`. The local-auth core suite uses real PostgreSQL
and is deliberately registered only in `test:integration` and `test:all`;
`composer test` remains usable with `--network none`.
`scripts/verify.py` additionally runs `scripts/backend_fixes.py` for the isolated
payment provider, real payment HTTP, payment migration and recovery suites.
Its gateway settings apply only to those child commands, preserving the ordinary
demo/API/search environment. `make test-backend-fixes E2E_PROJECT=<name>-e2e`
runs this focused phase on an already migrated disposable stack; it mounts SQL
read-only at `/migrations`, pauses running payment/maintenance schedulers so they
cannot consume fault-injection intents, and restores their prior running state
after stopping fixtures on success or failure. The shared verification wrapper
also pauses those workers around the SQL/auth PHP suites.
The root `make integration-check` supplies `API_ORIGIN=http://edge:8080` and
`UPSTREAM_FIXTURE_ORIGIN=http://fixture:8080` using the disposable verification
overlay. Its provider fixture and Yii verification-network access do not add a
business implementation. No reference origin or source mount is needed.
`throttling.php` exercises default/low policy ceilings at the real kernel;
`address_race.php` uses SQL barriers and independent connections for both orderings
of checkout versus deletion. `deadlines.php` sends continuously arriving raw HTTP
headers. Opaque bearer and persisted-role tests retain real SQL profiles and
cross-user authorization checks. The Go differential and cross-runtime comparison harnesses were removed;
their saved migration evidence remains historical. PHP business/API/concurrency/
security tests remain active.
Tests create unique SQL fixtures and real identities; use a **disposable** DB.
`integration.php` temporarily installs/removes a narrowly scoped failure trigger
to verify rollback after order/items have already been inserted.

Run `make integration-up` then `make integration-check` from the repository root
for the isolated shared edge at 58095 (`shopkmp-migrated-e2e`); normal `make run`
uses 8080. Earlier Task1 provisioning commands, RED→GREEN observations and live
differential results in the
[implementation report](../docs/YII2_IMPLEMENTATION_REPORT.md) are historical,
not rerunnable instructions for the removed reference source.

### Intentional compatibility corrections

Deleting an address referenced by **any** historical order returns
`ADDRESS_IN_USE`, instead of hitting the original historical-order FK error.
A cross-credential challenge cannot delete another credential's challenge.
Wrong-kind opaque refresh values are rejected locally rather than sent to the
identity provider. UTC timestamps use Go-compatible RFC3339 fractional trimming.
Search uses `id.keyword` for stable ordering rather than the provider-restricted
`_id` field; returned stock/rating is rehydrated from canonical SQL.

Biometric signature verification does not claim hardware attestation. Password
changes performed outside this application's fenced flow need an explicit
integration to revoke local opaque families. Verification covers the listed
cases; production cutover follows the rollout procedure.

## Integrated root deployment (Task4)

From the repository root: `cp .env.example .env && make run`. Only the shared
edge is published. `make seed` explicitly invokes `php yii development-seed/run`;
it refuses production and leaves any populated catalog unchanged. Startup never
calls it. `make reindex`, `make migrate`, `make integration-up integration-check`
and the [deployment guide](../docs/DEPLOYMENT.md) provide the supported lifecycle.
The root internal Nginx resolves only the fixed edge IP into REMOTE_ADDR. PHP
accepts original-client rate context only from exact `TRUSTED_BFF_IPS`; Go trusts
only the exact edge. BFF egress is ingress-only, and FPM admits only Nginx's exact
dedicated-network address plus its local probe. Production retains these
networks, environment values and FPM-config mount. See
[trust wiring](../docs/backend-fixes/TRUST_AND_RATE_LIMITS.md). TLS uses `PGSSLROOTCERT`
and the mounted PHP curl/OpenSSL CA bundle, without adapter verification bypasses.
Keep `YII_FPM_DYNAMIC_RANGE` inside the FPM subnet and outside the fixed Nginx
peer address, so a restarted PHP/CLI container cannot allocate that stopped
peer's address (examples use the upper /25).

### Recovery operations

`maintenance/recover` emits count-only JSON. Expected per-operation provider
failures retain durable fences and increase `sessionsFailed`; a successfully
completed scan exits 0 even with manual work. Alert on `paymentsManual`,
`callbacksManual`, `sessionsManual`, repeated failures and overdue pending work
separately from scheduler readiness. Unexpected worker/SQL failures exit nonzero.
`maintenance/payments` emits `dispatched`/`manual` counts and exits 2 when payment
review exists. Inspect operation IDs through authorized SQL tooling.

The combined scan considers at most 25 due `REVOKE_PENDING` candidates in one
1-second SQL selection, skipping advisory-busy owners without consuming their
attempts or changing their fences. A full 25-busy window can defer later jobs.
It reserves at most one actual session attempt first, then at most one payment
candidate, with monotonic admission deadlines. Session reservations have
a 120-second crash lease and at most five attempts. Backoff starts at
30 seconds and doubles. `RESET_PENDING` is never automatically retried: resolve
the password outcome independently, then use the trusted console with a ticket
reference (no password/token/provider response):

```sh
php yii maintenance/session-recover "$OPERATION_UUID" independently-confirmed OPS-123
```

The first ticket/confirmation timestamp is retained across retries. This command
only performs safe stored-user revocation; it never replays a password reset.
Run `php /opt/scheduler/scheduler-health.php status` in `payment-recovery` for
last result/success/heartbeat. A new worker becomes ready only after a successful
child; failed, stale or timed-out jobs make it unready. `RECOVERY_JOB_TIMEOUT_SECONDS`
defaults to 120 and blank `RECOVERY_SUCCESS_MAX_AGE_SECONDS` defaults to 155.
Combined recovery requires a runtime budget of at least 90 seconds. It scopes
its SQL connection to 1-second statements, 800ms lock waits and 5-second
transactions, restoring prior settings afterward. Timeout failures retain the
committed fence/send evidence and fail worker readiness; they are not silently
reported as successful cleanup. See [RR1](../docs/backend-fixes/RR1_FIX_REPORT.md)
for the bounded-work envelope and latency tradeoffs.

`maintenance/run` commits bounded retention slices first (up to 1,000 rows per
table), then locally expires at most 100 reservations within its admission
deadline. It cancels/restores stock once and queues reversal intents, with no
provider HTTP or trailing recovery scan. The dedicated recovery worker sends
those intents. Maintenance scopes the same SQL limits, supports a runtime budget
of at least 40 seconds, and keeps the default/blank 120-second budget. Direct
application `PaymentOperations::expire()` callers retain synchronous dispatch.

Trusted payment reconciliation additionally accepts `NO_EFFECT` for a sent,
`UNKNOWN` void/refund only after independent final provider verification and
sender quiescence. It stores an audited `ABORTED` disposition without claiming
reversal success. A failed void releases the unique owed refund; a failed refund
remains manual, visible in payment counts, and cannot be blindly replayed. See
[payment operator policy](../docs/backend-fixes/PAYMENTS.md) and the final
[F1–F3 fix report](../docs/backend-fixes/FINAL_FIX_REPORT.md).

The final report distinguishes attributed pre-fix review probes, coordinator
source-mounted results and the fresh rebuilt full-root exit. An earlier passing
snapshot is not evidence for a later source wave. Security A1 and the final
coordinator review remain separate release gates.
