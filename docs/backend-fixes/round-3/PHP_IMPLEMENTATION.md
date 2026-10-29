# PHP implementation — F1/F2/F3

Implemented in the current checkout. Focused PHP/native PostgreSQL checks pass;
the complete external-service integration gate remains with the main agent.

## Changed files

- `backend-yii2/repositories/{CatalogReadRepository,OrderReadRepository}.php`:
  parent-first reads, one child batch, rank/order preservation.
- `repositories/ShopRepository.php`: pure product/order DTO mappings receiving
  preloaded collections; full descriptions, images and order snapshots retained.
- `application/{CatalogService,CommerceService}.php`, `infrastructure/OpenSearch.php`:
  bounded reads and opt-in card projection, including SQL search and search-hit
  rehydration. SQL truncates snippets to 257 code points and selects one ordered
  image; PHP adds no ellipsis. Unknown nonempty projections return 400.
- `domain/Rules.php`, `application/CredentialService.php`,
  `infrastructure/ApiKernel.php`: enrollment/address validation separation,
  query-based legacy owner revocation, rejection of raw/encoded dot path segments,
  preserved user → credential → session-family locking; readiness capability.
- `controllers/MaintenanceController.php` and additive
  `migrations/m261029_000001_credential_remediation_audit.php`: read-only counts and
  explicit addressed apply, with transactional audit and rollback on audit failure.
- `tests/{round3_reads,round3_devices,round3_devices_unit,Round3HttpFixture}.php`,
  `tests/{all,search,session_concurrency}.php`: regressions and suite registration.

## RED/GREEN receipts

Every listed label has `evidence/<label>.json` (exact argv/cwd/timestamps/raw exit)
and `.log` (raw output). Initial RED runs preceded production changes.

| Evidence label | Result |
|---|---|
| `php-reads-red-final` | Original image: missing projection/capability, unbounded reads |
| `php-devices-red`, `php-devices-pg-red-serial` | Original image: dot enrollment accepted, CLI absent |
| `php-unsafe-path-red` | Before boundary fix: unsafe dot paths returned 204 |
| `php-reads-red-pg17` | Original-image replay on PG17 reproduces query counts with final fixtures |
| `php-final-unit-lint` | 7 existing unit tests + enrollment matrix; syntax 89 files; exit 0 |
| `php-final-pg17` | Reads 23, credentials 8, business 11, session concurrency 15; zero failures |
| `php-pg17-regressions-mounted` | 41 additional migration/concurrency/security/transport tests; exit 0 |
| `php-pg17-regressions-green` | Session revocation 7, password recovery 10, logout 41 passed; then missing `/migrations` mount stopped upgrade tests, corrected above |
| `php-final-diff-check` | `git diff --check`; exit 0 |

Exact representative commands, from the repository root, using the isolated
network/database that existed during execution:

```sh
E=docs/backend-fixes/round-3/evidence
DB='postgres://php_test@php-pg/php_test?sslmode=disable'
N=shopkmp-r3-php-private-1029
# RED: original application bytes, only new tests mounted.
python3 scripts/backend_check.py --evidence "$E" --label php-reads-red-final -- docker run --rm --network "$N" -e "DATABASE_URL=$DB" -v "$PWD/backend-yii2/tests:/app/tests:ro" shopkmp-round3-php php tests/round3_reads.php
python3 scripts/backend_check.py --evidence "$E" --label php-devices-pg-red-serial -- docker run --rm --network "$N" -e "DATABASE_URL=$DB" -v "$PWD/backend-yii2/tests:/app/tests:ro" shopkmp-round3-php php tests/round3_devices.php
# GREEN: current source, original image's installed dependencies retained.
python3 scripts/backend_check.py --evidence "$E" --label php-final-unit-lint -- docker run --rm --network none -v "$PWD/backend-yii2:/app:ro" -v /app/vendor shopkmp-round3-php sh -c 'composer test && php tests/round3_devices_unit.php && composer lint'
python3 scripts/backend_check.py --evidence "$E" --label php-final-pg17 -- docker run --rm --network "$N" -e "DATABASE_URL=$DB" -v "$PWD/backend-yii2:/app:ro" -v "$PWD/tools/ops/migrations:/migrations:ro" -v /app/vendor -v /app/runtime shopkmp-round3-php sh -c 'for suite in round3_reads round3_devices integration session_concurrency; do php "tests/$suite.php" || exit $?; done'
```

## Native PostgreSQL measurements

`PDO::ATTR_STATEMENT_CLASS` counts actual executions with native prepared
statements. PG17.10/aarch64 version receipt: `php-pg17-version`.

| Items | Catalog full/card GREEN | Orders GREEN | Popular full/card GREEN |
|---:|---:|---:|---:|
| 0 | 2 | 2 | 1 |
| 1 | 3 | 3 | 3 |
| 20 | 3 | 3 | 3 |
| 100 | 3 | 3 | 3 |

Same PG17 fixture/original-image counts at 100: catalog **102**, orders **202**,
popular **201**. Pure mappings execute **0** statements. Search-hit rehydration
executes **2** statements for 20 hits (one inactive); HTTP search responses are a
protocol fixture, not a live OpenSearch measurement.

100 products with 10,000-code-point descriptions: UTF-8 unescaped JSON is
**2,276,453 bytes full → 86,381 bytes projected**. These are serialized DTO sizes,
not end-to-end HTTP latency measurements.

## Operator use and integration concerns

Inside the PHP container at `/app`, run the ordinary additive Yii migration job:
`php yii migrate --interactive=0`. Read-only dry run:
`php yii maintenance/legacy-devices`. Explicit single-record apply:
`php yii maintenance/legacy-device-apply USER_UUID pin . OPS-123`
(or `biometric ..`). Audit is stored in `credential_remediation_audit`; ticket
syntax matches existing operator-recovery conventions. API legacy revocation uses
`DELETE /api/v1/auth/pin?deviceId=.` or the biometric query endpoint.

- `php-full-suite-pg17`: all suites through business integration passed; `auth.php`
  has three failures because no Keycloak realm/issuer exists in this PG-only
  environment. Main agent must run the complete stack gate, including the added
  real-OpenSearch assertions in `tests/search.php`.
  Failed test names: “Keycloak registration login refresh JWT authentication and
  logout use real realm”; “registration bootstrap restrictions and live password
  change revoke prior opaque families”; “live Keycloak user logout removes online
  and offline sessions while signed JWT lasts until expiry”.
- Initial PG16 full-suite attempt failed on existing `transaction_timeout` usage;
  PG17 rerun passed that business test. Failed setup/mount attempts remain in the
  evidence directory. No modeled DB-double counts are reported.
- Isolated DB and network were removed (`php-isolation-cleanup`). Rechecks need
  a newly migrated disposable database; never point these fixtures at production.
