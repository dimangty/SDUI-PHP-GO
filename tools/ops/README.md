# ShopKMP infrastructure tools

Standalone Go module `shopkmp/ops`, using Go 1.26 and
`github.com/jackc/pgx/v5` v5.10.0. It contains only the PostgreSQL schema
migrator, its historical SQL files, and a standard-library HTTP health probe.
All source, embedded SQL, dependencies, and tests needed by these tools are
contained in this module; the retired application source tree is not required.

## Commands

Run these from the repository root with Go 1.26 (or automatic Go toolchain
selection enabled):

```sh
go -C tools/ops test -count=1 ./...
go -C tools/ops vet ./...
go -C tools/ops mod tidy -diff
```

Apply outstanding schema migrations to the database selected by `DATABASE_URL`:

```sh
DATABASE_URL='postgres://user:password@localhost:5432/shopkmp?sslmode=disable' \
  go -C tools/ops run ./cmd/migrate
```

The migrator embeds all `migrations/*.up.sql` files in version order. It exits
with status 2 for missing/invalid database configuration, 1 for migration
failure, and 0 when the schema is current. The `.down.sql` files are retained
byte-for-byte as historical schema artifacts; this command does not execute
rollbacks.

Run the generic HTTP probe:

```sh
HEALTHCHECK_URL='http://127.0.0.1:9000/health/ready' \
  go -C tools/ops run ./cmd/healthcheck
```

`HEALTHCHECK_URL` defaults to `http://127.0.0.1:9091/health/live`. The probe
performs a GET with a two-second timeout, reads at most 4 KiB, and exits 0 only
for HTTP 200 with successful body read/close; failure exits 1.

## Preserve existing migration history

Use the existing application database and **preserve `schema_migrations` and
all of its existing version, name, checksum, and applied-at values**. This is
an extraction of the original migrator, not a new history or schema baseline.
Do not reset/truncate history, renumber/rename historical migrations, or edit
their SQL (including comments, whitespace, and line endings).

The original behavior is preserved:

- PostgreSQL advisory lock `6002424246314344528` serializes migrator runs.
- Existing history must match both the migration filename and its SHA-256
  checksum; mismatches fail instead of overwriting history.
- Legacy-schema detection runs only when migration history is empty, recording
  the detected existing prefix in one transaction.
- Each outstanding migration's SQL and history insert commit atomically in
  their own transaction.

`migrations/SHA256SUMS` was recorded from all 22 original `.up.sql`/`.down.sql`
files **before copying**. `TestSQLMatchesOriginalChecksumBaseline` checks both
the on-disk files and the embedded up-migration SQL/checksums against that fixed
baseline, without reading the retired source tree or connecting to a database.
The original migration and probe tests are included unchanged. Keep historical
baseline entries immutable; add new entries only for new migration files.

For a manual checksum check, run `shasum -a 256 -c SHA256SUMS` with working
directory `tools/ops/migrations`. The realm has its own original-byte baseline
at `deploy/keycloak/SHA256SUMS`; run the same command from `deploy/keycloak`.

## Runtime images

Both Dockerfiles use **`tools/ops` as their build context**. From the repository
root:

```sh
docker build -f deploy/migrate.Dockerfile \
  -t shopkmp-ops-extract-migrate tools/ops
docker build -f deploy/keycloak/Dockerfile \
  -t shopkmp-ops-extract-keycloak tools/ops
```

The migrator retains `gcr.io/distroless/static-debian12:nonroot`, the nonroot
user, and entrypoint `/migrate`. Run it with the existing database connection
and the appropriate Docker network:

```sh
docker run --rm --network YOUR_APP_NETWORK \
  -e DATABASE_URL shopkmp-ops-extract-migrate
```

The Keycloak image retains official `quay.io/keycloak/keycloak:26.3.3` and adds
only the same probe at `/opt/keycloak/bin/shopkmp-healthcheck`. Keep the existing
Keycloak command, environment (including `HEALTHCHECK_URL`), and database.
Mount `deploy/keycloak/shopkmp-realm.json` read-only at
`/opt/keycloak/data/import/shopkmp-realm.json` for the existing realm import;
the copied realm still uses the `KEYCLOAK_CLIENT_SECRET` placeholder.

For Compose wiring, `build.context` points to `tools/ops`; relative to that
context, `build.dockerfile` is `../../deploy/migrate.Dockerfile` or
`../../deploy/keycloak/Dockerfile`. The realm bind-mount source points to
`deploy/keycloak/shopkmp-realm.json`, relative to the Compose project directory.

To run the complete test/vet suite with only this module visible (no retired
source tree), from the repository root:

```sh
docker run --rm \
  --mount "type=bind,source=$PWD/tools/ops,target=/src,readonly" \
  --workdir /src golang:1.26-alpine3.24 \
  sh -c 'go test -count=1 ./... && go vet ./...'
```
