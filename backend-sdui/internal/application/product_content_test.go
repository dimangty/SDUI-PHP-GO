package application

import (
	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/sdui"
	"strings"
	"testing"
)

func TestCardPreviewRemainsConsumerBoundedForProjectedAndFullDescriptions(t *testing.T) {
	for _, tc := range []struct {
		name, description, want string
		truncated               bool
	}{
		{"empty", "", "", false},
		{"ascii256", strings.Repeat("a", 256), strings.Repeat("a", 256), false},
		{"ascii257", strings.Repeat("a", 257), strings.Repeat("a", 255) + "…", true},
		{"cyrillic257", strings.Repeat("Ж", 257), strings.Repeat("Ж", 255) + "…", true},
		{"unicode257", strings.Repeat("😀", 257), strings.Repeat("😀", 255) + "…", true},
		{"unicode10000", strings.Repeat("😀", 10000), strings.Repeat("😀", 255) + "…", true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			d := screenBase("catalog", "Каталог", nil)
			n := productCard(&d, model.Product{ID: unitProductID, Name: "Card", Description: tc.description, Price: "50.01", ImageURLs: []string{"/images/products/first.png", "/images/products/second.png"}})
			p := n.Props.(sdui.ProductProps)
			if p.Description != tc.want || p.DescriptionTruncated != tc.truncated || p.Image == nil || *p.Image != "/images/products/first.png" {
				t.Fatalf("card preview/ordered image changed: %+v", p)
			}
			root(&d, n)
			raw, err := d.Encode()
			if err != nil {
				t.Fatal(err)
			}
			schemaValidate(t, compileSchema(t, "screen"), raw)
		})
	}
}
