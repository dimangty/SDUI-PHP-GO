# Contributing to ShopKMP

> Use [`README.md`](README.md) for every build, run and verification command.
> All contributor-facing links must resolve inside the Git repository.

## Language convention

- Source-code identifiers, exported API names, wire error codes, and user-facing
  protocol fields are written in English.
- Comments may be written in Russian when they explain course-specific context
  or non-obvious business rules. New public package/type/function documentation
  should use one language consistently within a file.
- User-facing mobile text is Russian for the current product locale.
- Commit messages and pull-request summaries are written in English.

## Required checks

Run `make check-all` before submitting a change. Backend-only changes may use
`make check`; Kotlin/Compose changes must also pass `make frontend-check`.

`make check` runs Docker PHP unit tests/lint, BFF and ops tests/race detection/vet,
and root Compose validation. `make test-php` builds `shopkmp-php-tests` (override
`PHP_TEST_IMAGE`) and runs without `.env`, host PHP or running business services.
Use `make integration-up` followed by `make integration-check` for the complete
PHP business/API/concurrency/security suites and live shared-edge/BFF checks on
the disposable stack. CI runs these checks plus runtime image builds, canonical
contract validation and the existing Android/iOS jobs. The KMP 70% coverage gate
is unchanged; `make coverage` is an informational BFF-only report.

## Business ownership and contract compatibility

Yii2 owns business rules and PostgreSQL transactions. Keep controllers thin;
validation, money and state transitions belong in `backend-yii2/domain/` and
`backend-yii2/application/`, with SQL and aggregate locks in its repositories.
Changed business behavior needs focused PHP regression coverage; transaction or
concurrency changes need real isolated PostgreSQL checks. The Go BFF consumes
canonical HTTP DTOs only and must not gain database/identity credentials.

The canonical [OpenAPI](backend-yii2/contracts/openapi.yaml) and frozen
[route manifest](backend-yii2/contracts/routes.json) preserve all 54 business
operations and their public/protected/admin policies. Run
`python3 scripts/route_matrix.py --check` for registry/documentation drift. Keep
exact decimal-string money, error envelopes, ownership, authentication, replay
and persistence guarantees covered by the PHP/API suites. Retained Go/Yii parity
reports are historical evidence; current checks do not require a reference server.

## Migration history

`tools/ops` is an independent Go 1.26 infrastructure module, not a business
runtime. Its 22 historical SQL up/down files and checksum baseline are immutable.
Preserve the existing `schema_migrations` table and all version/name/checksum/
applied-at values; never reset history to make a migration pass. Future additive
Yii migrations use the separate `yii2_migration_history` table. See
[schema ownership](backend-yii2/migrations/README.md) and
[decommissioning](docs/LEGACY_REMOVAL.md) for snapshot-import/rollback boundaries.
