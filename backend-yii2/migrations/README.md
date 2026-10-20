# Schema ownership

Business schema is **exactly** the preserved migrations 001–011 in
[`../../tools/ops/migrations/`](../../tools/ops/migrations/). All 22 `.up.sql` and
`.down.sql` files were extracted byte-for-byte and are pinned by `SHA256SUMS`.
The independent Go 1.26 module `shopkmp/ops` provides the checksum-validating
forward migrator; no retired business source or archive is required.

From the repository root, `make migrate` runs the ops job and then the separate
Yii job. With Go 1.26 and an explicitly configured `DATABASE_URL`, the first job
can also be run as `go -C tools/ops run ./cmd/migrate`. See
[ops tools](../../tools/ops/README.md) for tests, image builds and checksum checks.

Preserve the existing **`schema_migrations`** table, including every version,
name, checksum and applied-at value. Do not reset/truncate/rebaseline history,
rename migrations, edit historical SQL bytes or reset business data. The
`.down.sql` files are historical artifacts; the ops command applies forward
migrations only and does not provide a destructive rollback procedure.

`php yii migrate --interactive=0` initializes **yii2_migration_history**, never
the business migration history table. Future additive Yii migrations belong here.
Application startup never migrates, imports legacy snapshots, or seeds data.
If `repository_metadata.legacy_import_status=required`, readiness fails. This is
an old snapshot-only installation, not an instruction to reset history. No Go
snapshot importer is bundled now: use a separately retained immutable release
and its controlled offline import procedure on a restored copy, verify the
normalized data/import status and then resume migration/cutover. Actual rollback
uses external immutable releases and verified business/identity backups; see
[deployment](../../docs/DEPLOYMENT.md) and
[decommissioning](../../docs/LEGACY_REMOVAL.md).
