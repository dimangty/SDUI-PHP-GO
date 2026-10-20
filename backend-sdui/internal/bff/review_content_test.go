package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"unicode/utf8"
)

type reviewNode struct {
	ID       string         `json:"id"`
	Type     string         `json:"type"`
	Props    map[string]any `json:"props"`
	Children []reviewNode   `json:"children"`
}
type reviewDocument struct {
	Components []reviewNode     `json:"components"`
	Actions    []map[string]any `json:"actions"`
	State      struct {
		Fields         map[string]string                                           `json:"fields"`
		CartItemsCount int                                                         `json:"cartItemsCount"`
		Collections    map[string]struct{ Page, Size, TotalPages, TotalItems int } `json:"collections"`
	} `json:"state"`
}

func reviewNodes(t *testing.T, raw []byte) (reviewDocument, []reviewNode) {
	t.Helper()
	var d reviewDocument
	if err := json.Unmarshal(raw, &d); err != nil {
		t.Fatal(err)
	}
	nodes := []reviewNode{}
	var visit func([]reviewNode, int)
	visit = func(items []reviewNode, depth int) {
		if depth > 16 {
			t.Fatal("depth limit exceeded")
		}
		for _, n := range items {
			nodes = append(nodes, n)
			visit(n.Children, depth+1)
		}
	}
	visit(d.Components, 1)
	if len(raw) > 1<<20 || len(nodes) > 500 || len(d.Actions) > 500 {
		t.Fatalf("wire budget exceeded: bytes=%d nodes=%d actions=%d", len(raw), len(nodes), len(d.Actions))
	}
	return d, nodes
}
func reviewProduct(t *testing.T, description string) map[string]any {
	t.Helper()
	var p map[string]any
	if err := json.Unmarshal([]byte(productJSON), &p); err != nil {
		t.Fatal(err)
	}
	p["description"] = description
	return p
}

func TestReviewI2ValidBusinessDescriptionIsPreviewedAndPreservedInFull(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, n := range []int{8192, 8193, 10000} {
		t.Run(fmt.Sprint(n), func(t *testing.T) {
			alphabet := []rune("Ж🌟<>&")
			runes := make([]rune, n)
			for i := range runes {
				runes[i] = alphabet[i%len(alphabet)]
			}
			description := string(runes)
			p := reviewProduct(t, description)
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				switch r.URL.Path {
				case "/api/v1/products/" + pid:
					json.NewEncoder(w).Encode(p)
				case "/api/v1/products/popular":
					json.NewEncoder(w).Encode([]any{p})
				case "/api/v1/products":
					json.NewEncoder(w).Encode(map[string]any{"items": []any{p}, "page": 0, "size": 20, "totalPages": 1, "totalItems": 1})
				default:
					fixtureUpstream(w, r)
				}
			})
			for _, path := range []string{"product/" + pid, "catalog", "home"} {
				t.Run(path, func(t *testing.T) {
					w := request(h, "GET", "/api/v1/screens/"+path, "", "")
					if w.Code != 200 {
						t.Fatalf("valid %d-character description broke screen: HTTP %d", n, w.Code)
					}
					_, nodes := reviewNodes(t, w.Body.Bytes())
					schemaValidate(t, schema, w.Body.Bytes())
					full := strings.Builder{}
					cards := 0
					for _, node := range nodes {
						if node.Type == "Text" && strings.HasPrefix(node.ID, "product.description") {
							s, _ := node.Props["text"].(string)
							if !utf8.ValidString(s) || utf8.RuneCountInString(s) > 8192 {
								t.Fatal("unsafe description chunk")
							}
							full.WriteString(s)
						}
						if node.Type == "ProductCard" {
							cards++
							preview, _ := node.Props["description"].(string)
							if utf8.RuneCountInString(preview) > 256 || preview != string(runes[:255])+"…" || node.Props["descriptionTruncated"] != true {
								t.Fatalf("unbounded or ambiguous card preview: chars=%d truncated=%v", utf8.RuneCountInString(preview), node.Props["descriptionTruncated"])
							}
						}
					}
					if strings.HasPrefix(path, "product/") {
						if full.String() != description {
							t.Fatalf("full description lost: got %d chars, want %d", utf8.RuneCountInString(full.String()), n)
						}
					} else if cards != 1 {
						t.Fatalf("expected one real product preview, got %d", cards)
					}
				})
			}
		})
	}
}

func TestReviewStableImageIDsSurviveInsertionReorderingAndDuplicates(t *testing.T) {
	images := []string{"/images/products/a.png", "/images/products/b.png"}
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/products/"+pid {
			p := reviewProduct(t, "Details")
			p["imageUrls"] = images
			json.NewEncoder(w).Encode(p)
			return
		}
		fixtureUpstream(w, r)
	})
	load := func() map[string]string {
		w := request(h, "GET", "/api/v1/screens/product/"+pid, "", "")
		if w.Code != 200 {
			t.Fatalf("%d %s", w.Code, w.Body)
		}
		_, nodes := reviewNodes(t, w.Body.Bytes())
		ids := map[string]string{}
		for _, n := range nodes {
			if n.Type == "Image" {
				source := n.Props["source"].(string)
				if _, ok := ids[source]; ok {
					t.Fatal("duplicate media rendered twice")
				}
				ids[source] = n.ID
			}
		}
		return ids
	}
	before := load()
	images = []string{"/images/products/z.png", "/images/products/b.png", "/images/products/a.png", "/images/products/a.png"}
	after := load()
	for source, id := range before {
		if after[source] != id {
			t.Errorf("image identity moved for %s: %s -> %s", source, id, after[source])
		}
	}
	if len(after) != 3 {
		t.Fatalf("got %d images, want 3 distinct media", len(after))
	}
}

func TestReviewDetailRetainsRatingAndUnratedState(t *testing.T) {
	for _, rating := range []any{4.5, nil} {
		t.Run(fmt.Sprint(rating), func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/products/"+pid {
					p := reviewProduct(t, "Details")
					p["rating"] = rating
					json.NewEncoder(w).Encode(p)
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/product/"+pid, "", "")
			_, nodes := reviewNodes(t, w.Body.Bytes())
			found := ""
			for _, n := range nodes {
				if n.ID == "product.rating" {
					found, _ = n.Props["text"].(string)
				}
			}
			want := "4.5"
			if rating == nil {
				want = "Нет оценок"
			}
			if !strings.Contains(found, want) || !strings.Contains(found, "2") {
				t.Fatalf("lost rating/review count: %q", found)
			}
		})
	}
}

func TestReviewSearchFieldPublishesUTF8ByteLimit(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	for _, path := range []string{"home", "catalog"} {
		w := request(h, "GET", "/api/v1/screens/"+path, "", "")
		_, nodes := reviewNodes(t, w.Body.Bytes())
		found := false
		for _, n := range nodes {
			if n.Type == "SearchField" {
				found = true
				if n.Props["maxUtf8Bytes"] != float64(200) {
					t.Fatalf("missing canonical UTF-8 byte limit: %+v", n.Props)
				}
			}
		}
		if !found {
			t.Fatal("no search control")
		}
	}
}
