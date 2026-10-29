package bff

import (
	"shopkmp/backend-sdui/internal/application"
	"testing"
)

func FuzzScreenQuery(f *testing.F) {
	for _, q := range []string{"q=lamp&page=1&size=10", "q=a&q=b", "url=http://evil", "q=%ff", "page=-1", "size=999999999999999999999"} {
		f.Add(q)
	}
	f.Fuzz(func(t *testing.T, raw string) {
		if len(raw) > 8192 {
			return
		}
		q, err := parseQuery(raw)
		if err != nil || validateKeys("catalog", q) != nil {
			return
		}
		if len(raw) > 4096 {
			t.Fatal("oversize query accepted")
		}
		_, _ = application.ParseCatalogQuery(q)
		_, _ = application.ParseCollectionQuery(q, "categoryPage", "categorySize", 50)
	})
}
