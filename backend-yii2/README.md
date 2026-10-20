# ShopKMP Yii2 business API

Independent PHP 8.3 / Yii2 application using the original PostgreSQL schema.
Business requests execute PHP application services and SQL repositories.
Keycloak owns passwords/JWTs; PIN and biometric sessions use hashed opaque
application tokens. No Go service is called by this application.

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
* `infrastructure/`: PDO, bounded Keycloak/JWKS, gateway and OpenSearch adapters.

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
Yii2 2.0.55, firebase/php-jwt 7.2.1. Browser assets, Yii debug/Gii and a frontend
template are not required. `vendor/` and runtime artifacts are ignored.

Supply environment variables with `docker run --env-file ...`. The repository's
`.env.example` supplies development configuration; `.env` is not implicitly loaded
by the PHP process when running the image directly.
There is no local-password auth fallback. The app requires its canonical SQL
schema and the configured Keycloak realm for password flows.

PHP-FPM listens on **9000**, entry script **`/app/web/index.php`**. An internal
HTTP edge can use `config/nginx.conf` (upstream DNS alias **`yii2`**, port 9000;
HTTP listener 8080). Do not publish FPM directly. The root Compose model owns the
public edge, TLS, internal-only operational endpoints and production orchestration.

### Schema and lifecycle

1. Stop/drain the old business writer for a real cutover.
2. Apply the preserved checksum-validating migrations 001–011 using the independent
   `tools/ops` module. From this directory, with the intended `DATABASE_URL`:

   ```sh
   go -C ../tools/ops run ./cmd/migrate
   ```

   All 22 up/down SQL files are unchanged and pinned by
   `tools/ops/migrations/SHA256SUMS`. Preserve the existing `schema_migrations`
   versions/names/checksums/applied-at values; never reset history or customer data.
   The root `make migrate` runs this one-shot job followed by the Yii job.
3. Run `php yii migrate --interactive=0` in the PHP container. It uses the
   separate **`yii2_migration_history`** table. There are currently no additional
   business migrations.
4. Run `php yii maintenance/reindex` to reconcile the existing catalog to
   `shopkmp-products`, then start PHP-FPM and check `/health/ready`.
5. Schedule `php yii maintenance/run` every minute and
   `php yii maintenance/reindex` every five minutes. Maintenance expires pending
   reservations and cleans expired sessions, replay entries, challenges and
   rate-limit records. Commands are safe to run from multiple replicas.

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
* OIDC verifies RS256 signatures, JWKS, issuer, audience, payload `typ=Bearer`,
  `azp`, expiration and subject/profile binding. JWKS discovery URLs must remain
  on the configured issuer origin. TLS verification is enabled; redirects are
  not followed. JWKS are cached for five minutes and refreshed for key rotation.
* Opaque values have `app_access_` / `app_refresh_` prefixes. Only binary SHA256
  hashes of the full values are stored. Refresh consumption is atomic.
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
  Explicit refund/void operations use the configured provider; outbound mutations
  are never automatically retried. The development provider uses real SQL order
  and payment records and a secret-bound local demo-payment capability.
* Protected resources enforce owner identity. Every admin route checks verified
  realm roles or the persisted admin flag.
* Curl enforces a whole-request deadline across DNS/connect/TLS, uploads, response
  headers and body. Responses and headers are bounded, redirects disabled and
  mutations single-attempt. Durations such as `500ms` retain their real unit.

## Tests

Composer commands inside the PHP image:

```sh
composer test                 # unit / wire / manifest / fixed compatibility hash vector
composer test:integration     # real PostgreSQL, requires DATABASE_URL
composer lint
composer test:all             # all suites below, requires isolated services
```

`tests/all.php` runs unit, SQL integration, real Keycloak, security, process-level
concurrency, HTTP API, real OpenSearch and bounded-provider protocol tests.
The root `make integration-check` supplies `API_ORIGIN=http://edge:8080` and
`UPSTREAM_FIXTURE_ORIGIN=http://fixture:8080` using the disposable verification
overlay. Its provider fixture and Yii verification-network access do not add a
business implementation. No reference origin or source mount is needed.
`throttling.php` exercises default/low policy ceilings at the real kernel;
`address_race.php` uses SQL barriers and independent connections for both orderings
of checkout versus deletion. `deadlines.php` sends continuously arriving raw HTTP
headers. Signed claim/realm-role tests use a cryptographic JWKS fixture and SQL
profiles. The Go differential and cross-runtime comparison harnesses were removed;
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

Known legacy limitations retained: opaque sessions are not device-linked;
Keycloak password changes do not revoke opaque sessions; hardware attestation
is not claimed or performed. Verification is evidence for the listed cases,
not authorization for production cutover.

## Integrated root deployment (Task4)

From the repository root: `cp .env.example .env && make run`. Only the shared
edge is published. `make seed` explicitly invokes `php yii development-seed/run`;
it refuses production and leaves any populated catalog unchanged. Startup never
calls it. `make reindex`, `make migrate`, `make integration-up integration-check`
and the [deployment guide](../docs/DEPLOYMENT.md) provide the supported lifecycle.
The root internal Nginx config resolves only the fixed edge IP into REMOTE_ADDR;
PHP's forwarded-header trust list is empty. Production TLS uses `PGSSLROOTCERT`
and the mounted PHP curl/OpenSSL CA bundle, without adapter verification bypasses.
