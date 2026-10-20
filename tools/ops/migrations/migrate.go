// Пакет migrations встраивает и применяет упорядоченные миграции схемы
// PostgreSQL, поставляемые вместе с образом backend.
package migrations

import (
	"context"
	"crypto/sha256"
	"embed"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

const advisoryLockID int64 = 6002424246314344528

//go:embed *.up.sql
var files embed.FS

// Migration описывает одну неизменяемую прямую миграцию, встроенную в binary.
type Migration struct {
	Version  int64
	Name     string
	SQL      string
	Checksum string
}

// Load возвращает все встроенные прямые миграции в строго возрастающем порядке.
func Load() ([]Migration, error) {
	names, err := fs.Glob(files, "*.up.sql")
	if err != nil {
		return nil, fmt.Errorf("list embedded migrations: %w", err)
	}
	result := make([]Migration, 0, len(names))
	seen := make(map[int64]string, len(names))
	for _, name := range names {
		versionText, _, ok := strings.Cut(filepath.Base(name), "_")
		if !ok {
			return nil, fmt.Errorf("invalid migration name %q", name)
		}
		version, parseErr := strconv.ParseInt(versionText, 10, 64)
		if parseErr != nil || version <= 0 {
			return nil, fmt.Errorf("invalid migration version in %q", name)
		}
		if previous, exists := seen[version]; exists {
			return nil, fmt.Errorf("duplicate migration version %d: %q and %q", version, previous, name)
		}
		body, readErr := files.ReadFile(name)
		if readErr != nil {
			return nil, fmt.Errorf("read migration %q: %w", name, readErr)
		}
		sum := sha256.Sum256(body)
		result = append(result, Migration{
			Version: version, Name: name, SQL: string(body), Checksum: hex.EncodeToString(sum[:]),
		})
		seen[version] = name
	}
	sort.Slice(result, func(i, j int) bool { return result[i].Version < result[j].Version })
	for index, migration := range result {
		if migration.Version != int64(index+1) {
			return nil, fmt.Errorf("migration sequence has a gap before version %d", migration.Version)
		}
	}
	return result, nil
}

// Apply сериализует запуски мигратора advisory-блокировкой PostgreSQL, проверяет
// контрольные суммы уже применённых миграций и применяет каждый новый файл
// в отдельной транзакции.
func Apply(ctx context.Context, pool *pgxpool.Pool) error {
	migrations, err := Load()
	if err != nil {
		return err
	}
	connection, err := pool.Acquire(ctx)
	if err != nil {
		return fmt.Errorf("acquire migration connection: %w", err)
	}
	defer connection.Release()
	if _, err := connection.Exec(ctx, "SELECT pg_advisory_lock($1)", advisoryLockID); err != nil {
		return fmt.Errorf("acquire migration lock: %w", err)
	}
	defer func() {
		_, _ = connection.Exec(context.WithoutCancel(ctx), "SELECT pg_advisory_unlock($1)", advisoryLockID)
	}()
	if _, err := connection.Exec(ctx, `CREATE TABLE IF NOT EXISTS schema_migrations (
		version BIGINT PRIMARY KEY,
		name TEXT NOT NULL,
		checksum CHAR(64) NOT NULL,
		applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
	)`); err != nil {
		return fmt.Errorf("create schema_migrations: %w", err)
	}
	if err := baselineLegacySchema(ctx, connection, migrations); err != nil {
		return err
	}
	for _, migration := range migrations {
		if err := applyOne(ctx, connection, migration); err != nil {
			return err
		}
	}
	return nil
}

func baselineLegacySchema(ctx context.Context, connection *pgxpool.Conn, migrations []Migration) error {
	var recorded int
	if err := connection.QueryRow(ctx, "SELECT count(*) FROM schema_migrations").Scan(&recorded); err != nil {
		return fmt.Errorf("inspect migration history: %w", err)
	}
	if recorded != 0 {
		return nil
	}
	checks := []string{
		`SELECT to_regclass('users') IS NOT NULL AND to_regclass('products') IS NOT NULL`,
		`SELECT to_regclass('shop_state') IS NOT NULL`,
		`SELECT to_regclass('avatar_files') IS NOT NULL AND to_regclass('rate_limit_hits') IS NOT NULL`,
		`SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='shop_state' AND column_name='version')`,
		`SELECT to_regclass('cart_metadata') IS NOT NULL
			AND EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='date_of_birth')`,
		`SELECT to_regclass('repository_metadata') IS NOT NULL
			AND EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='payments' AND column_name='updated_at')`,
		`SELECT to_regclass('access_sessions') IS NOT NULL AND to_regclass('refresh_sessions') IS NOT NULL`,
		`SELECT to_regclass('device_biometrics') IS NOT NULL AND to_regclass('biometric_challenges') IS NOT NULL`,
		`SELECT EXISTS(SELECT 1 FROM information_schema.columns
				WHERE table_name='biometric_challenges' AND column_name='credential_id')
			AND EXISTS(SELECT 1 FROM information_schema.columns
				WHERE table_name='device_biometrics' AND column_name='lock_count')
			AND NOT EXISTS(SELECT 1 FROM information_schema.columns
				WHERE table_name='device_biometrics' AND column_name='secure_hardware')`,
		`SELECT to_regclass('login_attempts') IS NOT NULL`,
	}
	applied := 0
	for index, query := range checks {
		var exists bool
		if err := connection.QueryRow(ctx, query).Scan(&exists); err != nil {
			return fmt.Errorf("inspect legacy schema version %d: %w", index+1, err)
		}
		if !exists {
			break
		}
		applied = index + 1
	}
	if applied == 0 {
		return nil
	}
	if applied > len(migrations) {
		return fmt.Errorf("legacy schema version %d is newer than the migration binary", applied)
	}
	tx, err := connection.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin legacy migration baseline: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()
	for _, migration := range migrations[:applied] {
		if _, err := tx.Exec(ctx,
			"INSERT INTO schema_migrations(version,name,checksum) VALUES($1,$2,$3)",
			migration.Version, migration.Name, migration.Checksum,
		); err != nil {
			return fmt.Errorf("baseline legacy migration %d: %w", migration.Version, err)
		}
	}
	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("commit legacy migration baseline: %w", err)
	}
	return nil
}

func applyOne(ctx context.Context, connection *pgxpool.Conn, migration Migration) error {
	var name, checksum string
	err := connection.QueryRow(ctx,
		"SELECT name,checksum FROM schema_migrations WHERE version=$1", migration.Version,
	).Scan(&name, &checksum)
	switch {
	case err == nil:
		if name != migration.Name || checksum != migration.Checksum {
			return fmt.Errorf(
				"migration %d checksum mismatch: database has %q/%s, binary has %q/%s",
				migration.Version, name, checksum, migration.Name, migration.Checksum,
			)
		}
		return nil
	case !isNoRows(err):
		return fmt.Errorf("inspect migration %d: %w", migration.Version, err)
	}
	tx, err := connection.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin migration %d: %w", migration.Version, err)
	}
	defer func() { _ = tx.Rollback(ctx) }()
	if _, err := tx.Exec(ctx, migration.SQL); err != nil {
		return fmt.Errorf("apply migration %d (%s): %w", migration.Version, migration.Name, err)
	}
	if _, err := tx.Exec(ctx,
		"INSERT INTO schema_migrations(version,name,checksum) VALUES($1,$2,$3)",
		migration.Version, migration.Name, migration.Checksum,
	); err != nil {
		return fmt.Errorf("record migration %d: %w", migration.Version, err)
	}
	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("commit migration %d: %w", migration.Version, err)
	}
	return nil
}

func isNoRows(err error) bool {
	return errors.Is(err, pgx.ErrNoRows)
}
