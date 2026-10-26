package bff

import (
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"strings"
	"testing"
)

func TestFollowupN3CartCountFitsClientIntWithoutLosingMoney(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, total := range []int{0, 3, math.MaxInt32, math.MaxInt32 + 1} {
		t.Run(fmt.Sprint(total), func(t *testing.T) {
			items := reviewCartItems(0)
			remaining := total
			for remaining > 0 {
				q := min(1000000, remaining)
				row := reviewCartItems(1)[0]
				row["productId"], row["quantity"], row["stock"], row["priceSnapshot"] = reviewItemID(len(items)), q, q, "0.01"
				items = append(items, row)
				remaining -= q
			}
			money := fmt.Sprintf("%d.%02d", total/100, total%100)
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/cart" {
					_ = json.NewEncoder(w).Encode(map[string]any{"id": aid, "userId": uid, "items": items, "totalAmount": money, "updatedAt": "2026-10-18T10:00:00Z"})
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/cart?size=20", "", "valid")
			if total > math.MaxInt32 {
				if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_INVALID_DOCUMENT") || strings.Contains(w.Body.String(), `"components"`) {
					t.Fatalf("unrepresentable count published: %d %s", w.Code, w.Body)
				}
				return
			}
			if w.Code != 200 {
				t.Fatalf("representable cart: %d %s", w.Code, w.Body)
			}
			schemaValidate(t, schema, w.Body.Bytes())
			d, nodes := reviewNodes(t, w.Body.Bytes())
			if d.State.CartItemsCount != total {
				t.Fatalf("count=%d want=%d", d.State.CartItemsCount, total)
			}
			if total > 0 {
				found := false
				for _, n := range nodes {
					found = found || n.ID == "cart.total" && n.Props["amount"] == money
				}
				if !found {
					t.Fatal("exact PHP total changed")
				}
			}
			t.Logf("sum=%d items=%d body=%d", total, len(items), w.Body.Len())
		})
	}
}

func TestFollowupN3NegativeAndOverflowingHiddenQuantities(t *testing.T) {
	for _, quantity := range []int{-1, math.MaxInt} {
		t.Run(fmt.Sprint(quantity), func(t *testing.T) {
			items := reviewCartItems(21)
			items[20]["quantity"] = quantity
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/cart" {
					_ = json.NewEncoder(w).Encode(map[string]any{"id": aid, "userId": uid, "items": items, "totalAmount": "20.00", "updatedAt": "2026-10-18T10:00:00Z"})
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/cart?size=20", "", "valid")
			if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_INVALID_DOCUMENT") {
				t.Fatalf("hidden invalid quantity accepted: %d %s", w.Code, w.Body)
			}
		})
	}
}

func TestFollowupN3UnrepresentableOutputFieldsFailBeforeSuccess(t *testing.T) {
	for _, tc := range []struct{ name, screen, path, field string }{
		{"session count", "home?deviceId=Phone-A", "/api/v1/auth/session", "cartItemsCount"},
		{"pagination items", "catalog", "/api/v1/products", "totalItems"},
		{"pagination pages", "orders", "/api/v1/orders", "totalPages"},
		{"product stock", "catalog", "/api/v1/products", "stock"},
		{"product reviews", "catalog", "/api/v1/products", "reviewsCount"},
		{"cart stock", "cart", "/api/v1/cart", "stock"},
		{"order number", "order/" + oid, "/api/v1/orders/" + oid, "number"},
	} {
		for _, value := range []int{-1, math.MaxInt32, math.MaxInt32 + 1} {
			t.Run(fmt.Sprintf("%s/%d", tc.name, value), func(t *testing.T) {
				h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
					if r.URL.Path != tc.path {
						fixtureUpstream(w, r)
						return
					}
					var obj map[string]any
					switch tc.path {
					case "/api/v1/auth/session":
						obj = map[string]any{"user": followupObject(t, userJSON), "device": map[string]any{"pinEnabled": false, "biometricEnabled": false, "biometricCredentialId": nil}, "cartItemsCount": value}
					case "/api/v1/products":
						p := followupObject(t, productJSON)
						obj = map[string]any{"items": []any{p}, "page": 0, "size": 20, "totalPages": 1, "totalItems": 1}
						if tc.field == "stock" || tc.field == "reviewsCount" {
							p[tc.field] = value
						} else {
							obj[tc.field] = value
						}
					case "/api/v1/orders":
						obj = map[string]any{"items": []any{followupObject(t, orderJSON)}, "page": 0, "size": 20, "totalPages": value, "totalItems": 1}
					case "/api/v1/cart":
						obj = followupObject(t, cartJSON)
						obj["items"].([]any)[0].(map[string]any)[tc.field] = value
					default:
						obj = followupObject(t, orderJSON)
						obj[tc.field] = value
					}
					_ = json.NewEncoder(w).Encode(obj)
				})
				w := request(h, "GET", "/api/v1/screens/"+tc.screen, "", "valid")
				if value == math.MaxInt32 {
					if w.Code != 200 || !strings.Contains(w.Body.String(), "2147483647") {
						t.Fatalf("boundary changed: %d %s", w.Code, w.Body)
					}
				} else if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_INVALID_DOCUMENT") || strings.Contains(w.Body.String(), `"components"`) {
					t.Fatalf("invalid Int published: %d %s", w.Code, w.Body)
				}
			})
		}
	}
}

func TestFollowupN3InternalNumbersNotSerializedAsIntRemainLegal(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/products/"+pid {
			p := followupObject(t, productJSON)
			p["stock"], p["reviewsCount"] = math.MaxInt32+1, math.MaxInt32+1
			_ = json.NewEncoder(w).Encode(p)
			return
		}
		if r.URL.Path == "/api/v1/categories" {
			items := reviewCategoryItems(1, false)
			items[0]["sortOrder"] = math.MaxInt32 + 1
			_ = json.NewEncoder(w).Encode(items)
			return
		}
		fixtureUpstream(w, r)
	})
	for _, screen := range []string{"product/" + pid, "catalog"} {
		w := request(h, "GET", "/api/v1/screens/"+screen, "", "valid")
		if w.Code != 200 {
			t.Fatalf("internal/text-only number rejected: %s %d %s", screen, w.Code, w.Body)
		}
	}
}
