// Файл проверяет непрерывную нумерацию и контрольные суммы встроенных миграций.
package migrations

import "testing"

func TestEmbeddedMigrationsAreContiguousAndHaveChecksums(t *testing.T) {
	migrations, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if len(migrations) < 10 {
		t.Fatalf("loaded %d migrations, want at least 10", len(migrations))
	}
	for index, migration := range migrations {
		if migration.Version != int64(index+1) {
			t.Fatalf("migration[%d] version=%d", index, migration.Version)
		}
		if migration.Name == "" || migration.SQL == "" || len(migration.Checksum) != 64 {
			t.Fatalf("invalid embedded migration: %+v", migration)
		}
	}
}
