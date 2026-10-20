package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"sync"
	"testing"
)

func reviewItemID(i int) string { return fmt.Sprintf("%08x-1111-4111-8111-111111111111", i+1) }
func reviewCartItems(count int) []map[string]any {
	items := make([]map[string]any, 0, count)
	for i := 0; i < count; i++ {
		items = append(items, map[string]any{"productId": reviewItemID(i), "productName": fmt.Sprintf("Product %d", i), "quantity": 1, "priceSnapshot": "1.00", "imageUrl": nil, "isAvailable": true, "stock": 100})
	}
	return items
}
func reviewCategoryItems(count int, rich bool) []map[string]any {
	items := make([]map[string]any, 0, count)
	for i := 0; i < count; i++ {
		name, icon := fmt.Sprintf("Category %d", i), ""
		if rich {
			name = strings.Repeat("&", 255)
			icon = "/icons/" + strings.Repeat("a", 2041)
		}
		items = append(items, map[string]any{"id": reviewItemID(i), "name": name, "iconUrl": icon, "sortOrder": i, "parentId": nil})
	}
	return items
}

func TestReviewI3DefaultCartOverflowBoundariesKeepControls(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, count := range []int{164, 165, 166, 301} {
		t.Run(fmt.Sprint(count), func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/cart" {
					json.NewEncoder(w).Encode(map[string]any{"id": aid, "userId": uid, "items": reviewCartItems(count), "totalAmount": fmt.Sprintf("%d.00", count)})
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/cart", "", "valid")
			if w.Code != 200 {
				t.Fatalf("valid %d-row cart stranded: HTTP %d", count, w.Code)
			}
			doc, nodes := reviewNodes(t, w.Body.Bytes())
			schemaValidate(t, schema, w.Body.Bytes())
			collection := doc.State.Collections["cart"]
			if collection.TotalItems != count || collection.Size != 50 || doc.State.CartItemsCount != count {
				t.Fatalf("missing full-cart continuation/count: %+v count=%d", collection, doc.State.CartItemsCount)
			}
			rows := 0
			checkout, next := false, false
			refs := map[string]string{}
			for _, a := range doc.Actions {
				refs[a["id"].(string)] = a["type"].(string)
				checkout = checkout || a["type"] == "Checkout"
				if a["id"] == "collection.cart.next" {
					next = true
				}
			}
			for _, n := range nodes {
				if n.Type == "CartItem" {
					rows++
					if refs[n.Props["removeAction"].(string)] != "RemoveFromCart" || refs[n.Props["quantityAction"].(string)] != "UpdateQuantity" {
						t.Fatal("cart recovery controls missing")
					}
				}
			}
			if rows != 50 || !checkout || !next {
				t.Fatalf("rows=%d checkout=%v next=%v", rows, checkout, next)
			}
		})
	}
}

func TestReviewI3CartPagingHasNoLossAndAllowsLastPageMutations(t *testing.T) {
	schema := compileSchema(t, "screen")
	var mu sync.Mutex
	items := reviewCartItems(301)
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if strings.HasPrefix(r.URL.Path, "/api/v1/cart") {
			mu.Lock()
			defer mu.Unlock()
			if r.Method == "PATCH" || r.Method == "DELETE" {
				id := strings.TrimPrefix(r.URL.Path, "/api/v1/cart/items/")
				for i := range items {
					if items[i]["productId"] == id {
						if r.Method == "DELETE" {
							items = append(items[:i], items[i+1:]...)
						} else {
							var input struct {
								Quantity int `json:"quantity"`
							}
							json.NewDecoder(r.Body).Decode(&input)
							items[i]["quantity"] = input.Quantity
						}
						break
					}
				}
			}
			total := 0
			for _, item := range items {
				total += item["quantity"].(int)
			}
			json.NewEncoder(w).Encode(map[string]any{"id": aid, "userId": uid, "items": items, "totalAmount": fmt.Sprintf("%d.00", total)})
			return
		}
		fixtureUpstream(w, r)
	})
	seen := map[string]bool{}
	for page := 0; page < 4; page++ {
		w := request(h, "GET", fmt.Sprintf("/api/v1/screens/cart?page=%d&size=100", page), "", "valid")
		if w.Code != 200 {
			t.Fatalf("page %d: HTTP %d", page, w.Code)
		}
		doc, nodes := reviewNodes(t, w.Body.Bytes())
		schemaValidate(t, schema, w.Body.Bytes())
		if page == 0 {
			t.Logf("301-row cart, size100: bytes=%d nodes=%d actions=%d visibleRows=100", w.Body.Len(), len(nodes), len(doc.Actions))
		}
		if doc.State.Collections["cart"].Page != page {
			t.Fatal("wrong effective cart page")
		}
		for _, n := range nodes {
			if n.Type == "CartItem" {
				id := n.Props["productId"].(string)
				if seen[id] {
					t.Fatal("cart row repeated across pages")
				}
				seen[id] = true
			}
		}
	}
	if len(seen) != 301 {
		t.Fatalf("silently lost rows: got %d, want 301", len(seen))
	}
	last := reviewItemID(300)
	for _, command := range []string{"UpdateQuantity", "RemoveFromCart"} {
		input := map[string]any{"productId": last}
		if command == "UpdateQuantity" {
			input["quantity"] = 2
		}
		body, _ := json.Marshal(map[string]any{"command": command, "input": input})
		w := postCommand(h, string(body), "")
		if w.Code != 200 {
			t.Fatalf("%s unavailable: %d %s", command, w.Code, w.Body)
		}
		readback := request(h, "GET", "/api/v1/screens/cart?page=3&size=100", "", "valid")
		if readback.Code != 200 {
			t.Fatalf("mutation stranded current page: %d", readback.Code)
		}
		d, nodes := reviewNodes(t, readback.Body.Bytes())
		wantPage, wantCount := 3, 301
		if command == "RemoveFromCart" {
			wantPage, wantCount = 2, 300
		}
		if d.State.Collections["cart"].Page != wantPage || d.State.Collections["cart"].TotalItems != wantCount {
			t.Fatalf("last-page continuation not normalized: %+v", d.State.Collections["cart"])
		}
		checkout := false
		for _, a := range d.Actions {
			checkout = checkout || a["type"] == "Checkout"
		}
		if !checkout {
			t.Fatal("native checkout missing after last-page mutation")
		}
		foundLast := false
		for _, n := range nodes {
			if n.Type == "CartItem" && n.Props["productId"] == last {
				foundLast = true
				if command == "RemoveFromCart" || n.Props["quantity"] != float64(2) {
					t.Fatal("last-page mutation not reflected")
				}
			}
		}
		if foundLast != (command == "UpdateQuantity") {
			t.Fatal("last-page item visibility disagrees with mutation")
		}
	}
}

func TestReviewI3CategoriesAnd100ProductsShareBoundedBudgetWithoutLoss(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, rich := range []bool{false, true} {
		t.Run(fmt.Sprint("rich=", rich), func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				switch r.URL.Path {
				case "/api/v1/categories":
					json.NewEncoder(w).Encode(reviewCategoryItems(400, rich))
				case "/api/v1/products":
					products := make([]any, 100)
					for i := range products {
						p := reviewProduct(t, "Preview")
						p["id"] = fmt.Sprintf("%08x-2222-4222-8222-222222222222", i+1)
						if rich {
							p["name"] = strings.Repeat("&", 255)
							p["description"] = strings.Repeat("&", 256)
							p["imageUrls"] = []string{"/images/products/" + strings.Repeat("a", 2031)}
						}
						products[i] = p
					}
					json.NewEncoder(w).Encode(map[string]any{"items": products, "page": 2, "size": 100, "totalPages": 4, "totalItems": 400})
				default:
					fixtureUpstream(w, r)
				}
			})
			first := request(h, "GET", "/api/v1/screens/catalog?size=100", "", "")
			if first.Code != 200 {
				t.Fatalf("400 categories +100 products broke supported request: %d", first.Code)
			}
			seen := map[string]bool{}
			for page := 0; page < 8; page++ {
				w := request(h, "GET", fmt.Sprintf("/api/v1/screens/catalog?page=2&size=100&categoryPage=%d&categorySize=50", page), "", "")
				if w.Code != 200 {
					t.Fatalf("category continuation page %d: HTTP %d", page, w.Code)
				}
				d, nodes := reviewNodes(t, w.Body.Bytes())
				schemaValidate(t, schema, w.Body.Bytes())
				if page == 0 {
					t.Logf("400 categories/100 products, rich=%v: bytes=%d nodes=%d actions=%d visibleCategories=50", rich, w.Body.Len(), len(nodes), len(d.Actions))
				}
				collection := d.State.Collections["categories"]
				if collection.Page != page || collection.TotalItems != 400 || collection.TotalPages != 8 || collection.Size != 50 {
					t.Fatalf("missing category continuation: %+v", collection)
				}
				products := 0
				for _, n := range nodes {
					if n.Type == "ProductCard" {
						products++
					}
					if n.Type == "CategoryList" {
						for _, raw := range n.Props["items"].([]any) {
							id := raw.(map[string]any)["id"].(string)
							if seen[id] {
								t.Fatal("category repeated across pages")
							}
							seen[id] = true
						}
					}
				}
				if products != 100 {
					t.Fatalf("silently dropped products to fit action budget: %d", products)
				}
				for _, a := range d.Actions {
					if a["id"] == "collection.categories.next" {
						dest := a["destination"].(map[string]any)
						if dest["catalog"].(map[string]any)["page"] != float64(2) || dest["catalog"].(map[string]any)["size"] != float64(100) {
							t.Fatal("category paging lost product query")
						}
					}
				}
			}
			if len(seen) != 400 {
				t.Fatalf("silently lost categories: got %d, want 400", len(seen))
			}
		})
	}
}

func TestReviewI3CategorySelectionOutsideVisiblePageRemainsRepresented(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/categories" {
			json.NewEncoder(w).Encode(reviewCategoryItems(400, false))
			return
		}
		fixtureUpstream(w, r)
	})
	w := request(h, "GET", "/api/v1/screens/catalog?categoryId="+reviewItemID(399), "", "")
	if w.Code != 200 {
		t.Fatalf("%d %s", w.Code, w.Body)
	}
	d, nodes := reviewNodes(t, w.Body.Bytes())
	found := false
	for _, n := range nodes {
		if n.Props["fieldId"] == "catalog.categoryId" {
			for _, raw := range n.Props["options"].([]any) {
				found = found || raw.(map[string]any)["value"] == reviewItemID(399)
			}
		}
	}
	if !found || d.State.Fields["catalog.categoryId"] != reviewItemID(399) {
		t.Fatal("selected category disappeared from controlled filter")
	}
}

func TestReviewI3CollectionQueryRejectsUnsafeSizes(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	for _, path := range []string{"cart?page=-1", "cart?size=101", "cart?page=1000001", "catalog?categoryPage=-1", "catalog?categorySize=51", "home?categorySize=0", "home?categoryPage=1&categoryPage=2"} {
		w := request(h, "GET", "/api/v1/screens/"+path, "", "valid")
		if w.Code != 400 {
			t.Errorf("accepted invalid collection query %s: HTTP%d", path, w.Code)
		}
	}
}

func TestCollectionBudgetIgnoresFailedPopularSection(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/products/popular" {
			fmt.Fprint(w, "["+strings.Repeat(productJSON+",", 500)+`{"price":42}]`)
			return
		}
		fixtureUpstream(w, r)
	})
	w := request(h, "GET", "/api/v1/screens/home", "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"partial":true`) || !strings.Contains(w.Body.String(), `"popular_unavailable"`) {
		t.Fatalf("non-rendered failed section consumed budget: HTTP%d %s", w.Code, w.Body)
	}
}
