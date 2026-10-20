# Retired Go business backend — decommissioning

The original `backend/` business source and `Shop-Go-master.zip` were removed
at the user's explicit request after extracting the required contracts and
infrastructure tools. Yii2 still owns every business operation; the independent
Go SDUI BFF remains HTTP-only. This is source/tooling decommissioning, with no
business/API/runtime behavior change.

## Preserved artifacts

| Current location | Preservation boundary |
| --- | --- |
| [`backend-yii2/contracts/openapi.yaml`](../backend-yii2/contracts/openapi.yaml) | Canonical OpenAPI moved unchanged; development Swagger serves this file |
| [`backend-yii2/contracts/routes.json`](../backend-yii2/contracts/routes.json) | Frozen 54 original operation names/methods/policies: 15 public, 32 protected, 7 admin |
| [`tools/ops/migrations/`](../tools/ops/migrations/) | All 22 original 001–011 up/down SQL files, exact bytes; immutable `SHA256SUMS` baseline |
| [`tools/ops/`](../tools/ops/README.md) | Independent Go 1.26 module `shopkmp/ops`, pinned pgx v5.10.0; checksum migrator and standard-library HTTP probe with their tests |
| [`deploy/migrate.Dockerfile`](../deploy/migrate.Dockerfile) | One-shot `cmd/migrate` image, build context `tools/ops` |
| [`deploy/keycloak/`](../deploy/keycloak/) | Official Keycloak 26.3.3 image plus `cmd/healthcheck`; unchanged `shopkmp-realm.json` and realm checksum baseline |
| [`API_PARITY_REPORT.md`](API_PARITY_REPORT.md), [`API_PARITY_REVIEW.md`](API_PARITY_REVIEW.md), `deploy/evidence/api-parity*` | Historical 194-pair migration evidence and reviews; no new comparison pass is claimed |

Compose Dockerfile paths are relative to the `tools/ops` context:
`../../deploy/migrate.Dockerfile` and `../../deploy/keycloak/Dockerfile`. The realm
mount source is `deploy/keycloak/shopkmp-realm.json`. Existing realm/client
configuration, migration names and SQL histories remain unchanged.

The migrator uses **the existing `schema_migrations` table** and validates stored
migration names and SHA-256 checksums. Preserve all version/name/checksum/applied-at
values and business data; do not reset history, establish a new baseline or edit
SQL to make validation pass. Yii uses separate `yii2_migration_history`.
The retained down SQL is history, not an automated rollback command.

## Verification after removal

```sh
go -C tools/ops test ./...
go -C tools/ops vet ./...
make check
make integration-up
make integration-check
```

`make test` includes standalone Docker PHP unit/lint and both Go modules;
`test-race`/`vet` check BFF and ops. `make check` also validates root Compose.
`make test-php` builds `shopkmp-php-tests` (`PHP_TEST_IMAGE` override) and runs
without `.env`, host PHP or a running database. `make e2e` runs the Python CARD
journey against `${SHOPKMP_E2E_URL:-http://127.0.0.1:8080}` with `--disposable`.

The root verification runner checks the frozen route set, scheduler, seed,
initial reindex, edge CARD, live BFF race/vet, ops test/vet, all retained PHP
business/API/concurrency/security suites through the edge with a provider fixture,
and runtime probes. Only fixture support and Yii verification-network access
remain in the test overlay. Normal local/integration origins remain 8080/58095.

Removed checks are tied to the retired implementation: original Go unit/SQL/E2E
suites, Go/Yii differential and bidirectional comparison harnesses, parity scripts,
reference-writer/test profiles and the Go business/financial 75% coverage gates.
These are removed comparisons, **not removed business features**. No equivalent
PHP coverage percentage is claimed. Optional `make coverage` reports only BFF
coverage; the KMP 70% gate and Android/iOS workflows remain unchanged. Historical
reports keep their original counts/results and commands as evidence, not current
rerun instructions.

## Snapshot-only history and actual rollback

`repository_metadata.legacy_import_status=required` still blocks readiness for
old snapshot-only databases. There is no bundled Go snapshot importer. Use a
separately retained immutable release and its controlled offline import procedure
on a restored copy, validate normalized data/status, then continue the documented
cutover. Do not clear the marker manually or reset migrations/data.

Actual rollback requires an **immutable external prior release**, its deployment
configuration and verified business/identity database backups. Drain the current
writer and workers, reconcile in-flight provider outcomes, verify schema/data
compatibility and restore the prior release as sole writer through its own
deployment. Backup restore/PITR is an explicit incident procedure, not migration
down or an overwrite of newer writes. See [deployment stages](DEPLOYMENT.md).

The preserved reports establish historical test-provider/simulator outcomes;
decommissioning does not establish production-provider settlement, physical-device
security or a production cutover/restore rehearsal.

## Executed post-removal checks — 2026-10-20

* `backend/` and `Shop-Go-master.zip` are absent. The retired, stopped reference
  container was removed; no database/identity volume was removed.
* `make check` passed: standalone PHP7 unit tests/58 syntax checks, BFF and ops
  unit/race/vet, development Compose configuration.
* Root Compose built migrator, Keycloak and PHP from their retained contexts.
  The extracted migrator ran against the existing test database and reported
  `database schema is up to date`; no history/schema/data reset was performed.
  Keycloak, PHP and edge were recreated healthy with the relocated realm/Swagger.
* `make integration-check` passed after deletion: route contract, scheduler, seed
  preservation, initial reindex, shared-edge CARD journey, live BFF race/vet,
  ops tests/vet, **73 PHP business/API/security/concurrency tests**, and private
  probes plus successful graceful shutdown/restart. All54 business routes still
  have exercised successes and protected/admin denials. Four retired Go-comparison
  cases account for the change from77 to73; no business test was replaced by a skip.
* `make test-infra PRODUCTION_TEST_ENV=<disposable-test-PKI-env>` passed:
  **12 scheduler regressions**, development/production resolved model checks,
  seven unsafe-production rejections and **5 command-boundary tests**.
  The obsolete assertion that standalone `test-php` must read/reject `ENV_FILE`
  was observed failing, then replaced by explicit network/environment isolation
  assertions; lifecycle production guards remain intact.
* Original SQL/realm checksums still match their immutable baselines. Before ZIP
  removal the relocated OpenAPI was compared with its archived bytes:
  SHA256 `0ccb20a41fc09164ba72c23515ad5eb03587c76c77e6a37fbc505a8c163731fb`.

New execution evidence is in `deploy/evidence/`; the prior Go comparison captures
remain historical. Native client code was not changed or rebuilt for this
decommissioning. The integrated test endpoint remains `http://127.0.0.1:58095`.
