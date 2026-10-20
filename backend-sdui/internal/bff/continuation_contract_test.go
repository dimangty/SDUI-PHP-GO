package bff

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"shopkmp/backend-sdui/internal/sdui"
)

func TestPublishedContinuationAndLongDescriptionFixtures(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/categories":
			json.NewEncoder(w).Encode(reviewCategoryItems(4, false))
		case "/api/v1/cart":
			json.NewEncoder(w).Encode(map[string]any{"id": aid, "userId": uid, "items": reviewCartItems(5), "totalAmount": "5.00"})
		case "/api/v1/products/" + pid:
			json.NewEncoder(w).Encode(reviewProduct(t, strings.Repeat("Ж🌟<>&", 2000)))
		case "/api/v1/products":
			p := reviewProduct(t, "Category continuation example")
			p["categoryId"] = reviewItemID(0)
			other := reviewProduct(t, "Second product")
			other["id"] = oid
			other["categoryId"] = reviewItemID(0)
			json.NewEncoder(w).Encode(map[string]any{"items": []any{p, other}, "page": 1, "size": 2, "totalPages": 3, "totalItems": 5})
		default:
			fixtureUpstream(w, r)
		}
	})
	schema := compileSchema(t, "screen")
	for _, tc := range []struct{ name, path string }{{"cart-continuation", "cart?page=1&size=2"}, {"home-continuation", "home?deviceId=test-device&categoryPage=1&categorySize=2"}, {"catalog-continuation", "catalog?page=1&size=2&categoryPage=1&categorySize=2&categoryId=" + reviewItemID(0)}, {"product-long-description", "product/" + pid}} {
		t.Run(tc.name, func(t *testing.T) {
			w := request(h, "GET", "/api/v1/screens/"+tc.path, "", "valid")
			if w.Code != 200 {
				t.Fatalf("%d %s", w.Code, w.Body)
			}
			reviewNodes(t, w.Body.Bytes())
			schemaValidate(t, schema, w.Body.Bytes())
			var formatted bytes.Buffer
			if err := json.Indent(&formatted, w.Body.Bytes(), "", "  "); err != nil {
				t.Fatal(err)
			}
			formatted.WriteByte('\n')
			path := filepath.Join("../../contracts/fixtures", tc.name+".json")
			if os.Getenv("UPDATE_FIXTURES") == "1" {
				if err := os.WriteFile(path, formatted.Bytes(), 0644); err != nil {
					t.Fatal(err)
				}
			}
			raw, err := os.ReadFile(path)
			if err != nil {
				t.Fatal(err)
			}
			if !bytes.Equal(raw, formatted.Bytes()) {
				t.Fatal("continuation fixture drift")
			}
			schemaValidate(t, schema, raw)
		})
	}
}

func TestReviewCollectionDestinationsAreFiniteAndScreenSpecific(t *testing.T) {
	schema := compileSchema(t, "action-response")
	for _, tc := range []struct {
		destination string
		valid       bool
	}{
		{`{"screen":"home","categories":{"page":1,"size":50}}`, true},
		{`{"screen":"catalog","catalog":{"page":2,"size":100},"categories":{"page":7,"size":50}}`, true},
		{`{"screen":"cart","cart":{"page":3,"size":100}}`, true},
		{`{"screen":"cart","categories":{"page":1,"size":50}}`, false},
		{`{"screen":"profile","cart":{"page":1,"size":50}}`, false},
		{`{"screen":"orders","categories":{"page":1,"size":50}}`, false},
		{`{"screen":"home","categories":{"page":1,"size":51}}`, false},
		{`{"screen":"cart","cart":{"page":-1,"size":50}}`, false},
	} {
		t.Run(tc.destination, func(t *testing.T) {
			var dest sdui.Destination
			if err := json.Unmarshal([]byte(tc.destination), &dest); err != nil {
				t.Fatal(err)
			}
			if (dest.Validate() == nil) != tc.valid {
				t.Fatal("Go destination validation disagrees")
			}
			var wire any
			raw := fmt.Sprintf(`{"schemaVersion":1,"command":"RemoveFromCart","outcome":"applied","refresh":%s}`, tc.destination)
			json.Unmarshal([]byte(raw), &wire)
			if (schema.Validate(wire) == nil) != tc.valid {
				t.Fatal("independent schema disagrees")
			}
		})
	}
}
