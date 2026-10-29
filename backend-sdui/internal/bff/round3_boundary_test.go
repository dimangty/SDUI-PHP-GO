package bff_test

import (
	"encoding/json"
	"go/parser"
	"go/token"
	"io"
	"io/fs"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

func TestRound3ApplicationDependencyBoundary(t *testing.T) {
	for _, root := range []string{"../application", "../domain", "../sdui"} {
		err := filepath.WalkDir(root, func(path string, entry fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			if entry.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
				return nil
			}
			f, err := parser.ParseFile(token.NewFileSet(), path, nil, parser.ImportsOnly)
			if err != nil {
				return err
			}
			for _, imp := range f.Imports {
				p, err := strconv.Unquote(imp.Path.Value)
				if err != nil {
					return err
				}
				if strings.HasPrefix(p, "net/http") || strings.Contains(p, "/internal/yii") || strings.Contains(p, "/internal/bff") || strings.Contains(p, "/internal/runtime") {
					t.Errorf("%s imports forbidden transport/adapter %s", path, p)
				}
			}
			return nil
		})
		if err != nil {
			t.Errorf("consumer-owned package required: %v", err)
		}
	}
	// Check transitive production dependencies too: a neutral-looking helper must
	// not conceal an HTTP client or the concrete business adapter.
	out, err := exec.Command("go", "list", "-deps", "-json", "../application", "../domain", "../sdui").Output()
	if err != nil {
		t.Fatalf("list application dependency graph: %v", err)
	}
	d := json.NewDecoder(strings.NewReader(string(out)))
	for {
		var p struct{ ImportPath string }
		err = d.Decode(&p)
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		if strings.HasPrefix(p.ImportPath, "net/http") || strings.HasSuffix(p.ImportPath, "/internal/yii") || strings.HasSuffix(p.ImportPath, "/internal/bff") {
			t.Errorf("forbidden transitive dependency %s", p.ImportPath)
		}
	}
}

func TestRound3HTTPTransportUsesConsumerBoundary(t *testing.T) {
	// Even HTTP transport must use the consumer's identity/error contracts; only
	// the composition root chooses and constructs the concrete adapter.
	out, err := exec.Command("go", "list", "-f", `{{join .Deps "\n"}}`, ".").Output()
	if err != nil {
		t.Fatal(err)
	}
	for _, p := range strings.Fields(string(out)) {
		if strings.HasSuffix(p, "/internal/yii") {
			t.Errorf("HTTP transport depends on concrete adapter %s", p)
		}
	}
}
