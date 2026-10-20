package migrations

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// The baseline was recorded from the original SQL before extraction. It must
// remain usable without the old source tree or a database connection.
func TestSQLMatchesOriginalChecksumBaseline(t *testing.T) {
	baseline, err := os.ReadFile("SHA256SUMS")
	if err != nil {
		t.Fatal(err)
	}
	expected := make(map[string]string)
	for _, line := range strings.Split(strings.TrimSpace(string(baseline)), "\n") {
		fields := strings.Fields(line)
		if len(fields) != 2 {
			t.Fatalf("invalid checksum baseline line: %q", line)
		}
		checksum, name := fields[0], fields[1]
		decoded, err := hex.DecodeString(checksum)
		if err != nil || len(decoded) != sha256.Size {
			t.Fatalf("invalid SHA-256 baseline for %s: %q", name, checksum)
		}
		if _, duplicate := expected[name]; duplicate {
			t.Fatalf("duplicate checksum baseline for %s", name)
		}
		expected[name] = checksum
		t.Run(name, func(t *testing.T) {
			body, err := os.ReadFile(name)
			if err != nil {
				t.Fatal(err)
			}
			sum := sha256.Sum256(body)
			if got := hex.EncodeToString(sum[:]); got != checksum {
				t.Fatalf("SQL bytes changed: SHA-256 = %s, original = %s", got, checksum)
			}
		})
	}
	if len(expected) == 0 {
		t.Fatal("empty checksum baseline")
	}
	names, err := filepath.Glob("*.sql")
	if err != nil {
		t.Fatal(err)
	}
	if len(names) != len(expected) {
		t.Fatalf("found %d SQL files, baseline records %d", len(names), len(expected))
	}
	for _, name := range names {
		if _, ok := expected[name]; !ok {
			t.Errorf("SQL file missing from checksum baseline: %s", name)
		}
	}

	loaded, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	wantUp := 0
	for name := range expected {
		if strings.HasSuffix(name, ".up.sql") {
			wantUp++
		}
	}
	if len(loaded) != wantUp {
		t.Fatalf("embedded %d up migrations, baseline records %d", len(loaded), wantUp)
	}
	for _, migration := range loaded {
		t.Run("embedded/"+migration.Name, func(t *testing.T) {
			checksum, ok := expected[migration.Name]
			if !ok || !strings.HasSuffix(migration.Name, ".up.sql") {
				t.Fatalf("embedded migration missing from up baseline: %s", migration.Name)
			}
			if migration.Checksum != checksum {
				t.Fatalf("embedded checksum = %s, original = %s", migration.Checksum, checksum)
			}
			sum := sha256.Sum256([]byte(migration.SQL))
			if got := hex.EncodeToString(sum[:]); got != checksum {
				t.Fatalf("embedded SQL bytes changed: SHA-256 = %s, original = %s", got, checksum)
			}
		})
	}
}
