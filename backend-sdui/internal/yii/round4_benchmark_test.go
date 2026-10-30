package yii

import (
	"context"
	"os"
	"testing"
	"time"
	"unicode/utf8"
)

// Opt-in real HTTP -> PHP -> OpenSearch/PostgreSQL benchmark. No fixture server
// or payload replacement: the supplied origin must run the disposable PHP API.
func BenchmarkRound4NativeCards(b *testing.B) {
	origin, category := os.Getenv("ROUND4_PERF_ORIGIN"), os.Getenv("ROUND4_PERF_CATEGORY")
	if origin == "" || category == "" {
		b.Skip("native disposable PHP performance fixture required")
	}
	c, err := New(Config{Origin: origin, Timeout: 3 * time.Second})
	if err != nil {
		b.Fatal(err)
	}
	defer c.CloseIdleConnections()
	query := CatalogQuery{Query: "Round4Performance", CategoryID: category, Size: 100}
	read := func() {
		b.Helper()
		page, err := c.Products(context.Background(), Identity{}, query)
		if err != nil || len(page.Items) != 100 || page.TotalItems != 100 {
			b.Fatalf("native page lost rows: count=%d total=%d error=%v", len(page.Items), page.TotalItems, err)
		}
		for _, p := range page.Items {
			if utf8.RuneCountInString(p.Description) != 257 || len(p.ImageURLs) != 1 || len(p.ImageURLs[0]) != 2048 {
				b.Fatal("maximum-size compact projection changed")
			}
		}
	}
	for range 3 {
		read()
	}
	b.ReportAllocs()
	b.ResetTimer()
	for b.Loop() {
		read()
	}
}
