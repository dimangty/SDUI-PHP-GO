package yii

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"testing"
)

func round3WireCard(description string, complete bool) map[string]any {
	return map[string]any{"id": productID, "categoryId": productID, "name": "Card", "price": "50.01", "discountPrice": nil, "stock": 9, "rating": nil, "isActive": true, "reviewsCount": 2, "imageUrl": nil, "descriptionSnippet": description, "descriptionComplete": complete}
}

func TestRound3F1ProjectionProtocol(t *testing.T) {
	for _, popular := range []bool{false, true} {
		for _, tc := range []struct {
			name, marker, description string
			complete                  bool
			missing                   string
			wantOK                    bool
		}{
			{"ascii", "sdui-card-v1", strings.Repeat("a", 256), true, "", true},
			{"cyrillic", "sdui-card-v1", strings.Repeat("Ж", 257), true, "", true},
			{"unicode257", "sdui-card-v1", strings.Repeat("😀", 257), false, "", true},
			{"empty-description", "sdui-card-v1", "", true, "", true},
			{"no-marker", "", "", true, "", false},
			{"wrong-marker", "future-v2", "", true, "", false},
			{"short-incomplete", "sdui-card-v1", strings.Repeat("Ж", 256), false, "", false},
			{"long-complete", "sdui-card-v1", strings.Repeat("😀", 258), true, "", false},
			{"full-instead", "sdui-card-v1", strings.Repeat("😀", 10000), true, "", false},
			{"missing-completeness", "sdui-card-v1", "", true, "descriptionComplete", false},
			{"missing-snippet", "sdui-card-v1", "", true, "descriptionSnippet", false},
			{"missing-image", "sdui-card-v1", "", true, "imageUrl", false},
		} {
			t.Run(fmt.Sprintf("popular=%v/%s", popular, tc.name), func(t *testing.T) {
				c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
					if r.URL.Query().Get("projection") != "sdui-card-v1" {
						t.Error("fixed projection missing")
					}
					card := round3WireCard(tc.description, tc.complete)
					delete(card, tc.missing)
					out := map[string]any{"items": []any{card}, "page": 0, "size": 20, "totalItems": 1, "totalPages": 1}
					if popular {
						delete(out, "page")
						delete(out, "size")
						delete(out, "totalItems")
						delete(out, "totalPages")
						if r.URL.Query().Get("limit") != "10" {
							t.Error("popular limit changed")
						}
					}
					if tc.marker != "" {
						out["projection"] = tc.marker
					}
					json.NewEncoder(w).Encode(out)
				}, 2<<20)
				var err error
				if popular {
					_, err = c.Popular(context.Background(), Identity{})
				} else {
					_, err = c.Products(context.Background(), Identity{}, CatalogQuery{Size: 20})
				}
				if (err == nil) != tc.wantOK {
					t.Fatalf("projection acceptance=%v want=%v err=%v", err == nil, tc.wantOK, err)
				}
			})
		}
	}
}

func TestRound3F1EmptyLastPagesAndQueries(t *testing.T) {
	for _, tc := range []struct {
		name                string
		query               CatalogQuery
		path                string
		want                url.Values
		total, pages, count int
	}{
		{"empty", CatalogQuery{Page: 0, Size: 20}, "/api/v1/products", url.Values{"page": {"0"}, "size": {"20"}, "projection": {"sdui-card-v1"}}, 0, 0, 0},
		{"last-sorted-filtered", CatalogQuery{Page: 2, Size: 50, Sort: "price_desc", CategoryID: productID, PriceMin: "50.01", PriceMax: "100.00"}, "/api/v1/products", url.Values{"page": {"2"}, "size": {"50"}, "sort": {"price_desc"}, "categoryId": {productID}, "priceMin": {"50.01"}, "priceMax": {"100.00"}, "projection": {"sdui-card-v1"}}, 101, 3, 1},
		{"search-past-last", CatalogQuery{Query: "лампа & свет", Page: 3, Size: 100, CategoryID: productID, PriceMin: "50.01"}, "/api/v1/products/search", url.Values{"q": {"лампа & свет"}, "page": {"3"}, "size": {"100"}, "categoryId": {productID}, "priceMin": {"50.01"}, "projection": {"sdui-card-v1"}}, 201, 3, 0},
	} {
		t.Run(tc.name, func(t *testing.T) {
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Query().Encode() != tc.want.Encode() {
					t.Errorf("query drift got %s want %s", r.URL.Query(), tc.want)
				}
				if r.URL.Path != tc.path {
					t.Error("search route drift")
				}
				items := []any{}
				if tc.count == 1 {
					items = append(items, round3WireCard("Last product", true))
				}
				json.NewEncoder(w).Encode(map[string]any{"projection": "sdui-card-v1", "items": items, "page": tc.query.Page, "size": tc.query.Size, "totalItems": tc.total, "totalPages": tc.pages})
			}, 2<<20)
			p, err := c.Products(context.Background(), Identity{}, tc.query)
			if err != nil || len(p.Items) != tc.count || p.Page != tc.query.Page || p.Size != tc.query.Size || p.TotalItems != tc.total || p.TotalPages != tc.pages {
				t.Fatalf("page drift %+v %v", p, err)
			}
			if tc.count == 1 && (p.Items[0].ID != productID || p.Items[0].Description != "Last product") {
				t.Fatal("last page product lost during conversion")
			}
		})
	}
	for _, marker := range []string{"sdui-card-v1", "", "wrong"} {
		c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
			json.NewEncoder(w).Encode(map[string]any{"projection": marker, "items": []any{}})
		}, 4096)
		p, err := c.Popular(context.Background(), Identity{})
		if (err == nil) != (marker == "sdui-card-v1") || err == nil && len(p) != 0 {
			t.Fatalf("empty popular marker=%q %v", marker, err)
		}
	}
}

func TestRound3F1ReadinessRequiresCapability(t *testing.T) {
	for _, body := range []string{`{"status":"ok"}`, `{"status":"ok","capabilities":[]}`, `{"status":"ok","capabilities":["other"]}`, `{"status":"unavailable","capabilities":["catalog-cards-v1"]}`, `{"status":"ok","capabilities":["other","catalog-cards-v1"]}`} {
		t.Run(body, func(t *testing.T) {
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) { fmt.Fprint(w, body) }, 4096)
			err := c.Ready(context.Background())
			want := body == `{"status":"ok","capabilities":["other","catalog-cards-v1"]}`
			if (err == nil) != want {
				t.Fatalf("readiness acceptance=%v want=%v: %v", err == nil, want, err)
			}
		})
	}
}
