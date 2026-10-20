package yii

import (
	"strings"
	"testing"
)

func TestReviewSearchLengthIs200UTF8Bytes(t *testing.T) {
	for _, tc := range []struct {
		name, q string
		valid   bool
	}{{"ascii200", strings.Repeat("a", 200), true}, {"ascii201", strings.Repeat("a", 201), false}, {"cyrillic200bytes", strings.Repeat("я", 100), true}, {"cyrillic202bytes", strings.Repeat("я", 101), false}, {"emoji200bytes", strings.Repeat("🌟", 50), true}, {"emoji204bytes", strings.Repeat("🌟", 51), false}, {"invalidUTF8", string([]byte{0xff}), false}} {
		t.Run(tc.name, func(t *testing.T) {
			err := (CatalogQuery{Query: tc.q, Size: 20}).Validate()
			if (err == nil) != tc.valid {
				t.Fatalf("bytes=%d valid=%v error=%v", len(tc.q), tc.valid, err)
			}
		})
	}
}
