package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"unicode/utf8"
)

func reviewOrder(t *testing.T, count int, name string, comment bool) map[string]any {
	t.Helper()
	var order map[string]any
	if err := json.Unmarshal([]byte(orderJSON), &order); err != nil {
		t.Fatal(err)
	}
	items := make([]any, count)
	for i := range items {
		items[i] = map[string]any{"productId": reviewItemID(i), "productName": fmt.Sprintf("%04d %s", i, name), "quantity": i + 1, "priceSnapshot": []string{"0.01", "50.01", "9999999999.99"}[i%3]}
	}
	order["items"] = items
	// Deliberately not the sum of the test rows: the BFF must use PHP's total.
	order["totalAmount"] = "9876543210.09"
	if comment {
		order["comment"] = "Комментарий 🌟 <&>"
	}
	return order
}

func TestReviewFixR1OrderPreservesAllItemsWithinV1(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, count := range []int{1, 164, 165, 200} {
		for _, long := range []bool{false, true} {
			for _, comment := range []bool{false, true} {
				t.Run(fmt.Sprintf("%d/long=%v/comment=%v", count, long, comment), func(t *testing.T) {
					name := "Лампа"
					if long {
						name = strings.Repeat("🌟<&Ж", 62) + "ab" // 255 code points including prefix
					}
					order := reviewOrder(t, count, name, comment)
					h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
						if r.URL.Path == "/api/v1/orders/"+oid {
							_ = json.NewEncoder(w).Encode(order)
							return
						}
						fixtureUpstream(w, r)
					})
					w := request(h, "GET", "/api/v1/screens/order/"+oid, "", "valid")
					if w.Code != 200 {
						t.Fatalf("valid order: HTTP %d %s", w.Code, w.Body)
					}
					schemaValidate(t, schema, w.Body.Bytes())
					doc, nodes := reviewNodes(t, w.Body.Bytes())
					var texts strings.Builder
					prices := map[string]string{}
					rows := 0
					for _, n := range nodes {
						if n.Type == "Text" {
							v := n.Props["text"].(string)
							if !utf8.ValidString(v) || utf8.RuneCountInString(v) > 8192 {
								t.Fatal("invalid UTF-8 or unbounded text")
							}
							texts.WriteString(v + "\n")
						}
						if n.Type == "Row" {
							rows++
						}
						if n.Type == "Price" {
							prices[n.ID] = n.Props["amount"].(string)
						}
					}
					for _, raw := range order["items"].([]any) {
						item := raw.(map[string]any)
						label := fmt.Sprintf("%s × %d", item["productName"], item["quantity"])
						if strings.Count(texts.String(), label+"\n")+strings.Count(texts.String(), label+" · ") != 1 {
							t.Fatalf("item missing/repeated: %q", label)
						}
						if rows == count {
							if prices["order.item."+item["productId"].(string)+".price"] != item["priceSnapshot"] {
								t.Fatal("snapshot changed")
							}
						} else if !strings.Contains(texts.String(), label+" · "+item["priceSnapshot"].(string)+" RUB") {
							t.Fatal("compact snapshot missing")
						}
					}
					if count <= 164 && rows != count {
						t.Fatal("small-order UI changed")
					}
					cancel := false
					for _, a := range doc.Actions {
						cancel = cancel || a["type"] == "CancelOrder" && a["orderId"] == oid
					}
					if !cancel || prices["order.total"] != order["totalAmount"] || comment && !strings.Contains(texts.String(), order["comment"].(string)) {
						t.Fatal("lost cancellation, total or comment")
					}
					t.Logf("items=%d bytes=%d nodes=%d", count, w.Body.Len(), len(nodes))
				})
			}
		}
	}
}

func TestReviewFixR1UnrepresentableOrderFailsAtomically(t *testing.T) {
	// PHP's 255-character names, escaped to six JSON bytes per character.
	// Upstream fits 2 MiB, but the complete representation exceeds 1 MiB.
	order := reviewOrder(t, 700, strings.Repeat("&", 250), true)
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/orders/"+oid {
			_ = json.NewEncoder(w).Encode(order)
			return
		}
		fixtureUpstream(w, r)
	})
	w := request(h, "GET", "/api/v1/screens/order/"+oid, "", "valid")
	if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_INVALID_DOCUMENT") || strings.Contains(w.Body.String(), "order.items") {
		t.Fatalf("unrepresentable document leaked/truncated: %d %s", w.Code, w.Body)
	}
}

func TestReviewFixR1AdjacentCollectionBoundaries(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, tc := range []struct {
		screen        string
		count, status int
	}{{"profile", 487, 200}, {"profile", 488, 200}, {"devices", 99, 200}, {"devices", 100, 200}} {
		t.Run(fmt.Sprintf("%s/%d", tc.screen, tc.count), func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				switch r.URL.Path {
				case "/api/v1/addresses":
					items := make([]any, tc.count)
					for i := range items {
						items[i] = map[string]any{"id": reviewItemID(i), "userId": uid, "city": "City", "street": "Street", "house": "1", "apartment": "2", "comment": nil}
					}
					_ = json.NewEncoder(w).Encode(items)
				case "/api/v1/auth/pin/devices":
					items := make([]any, tc.count)
					for i := range items {
						items[i] = map[string]any{"deviceId": fmt.Sprintf("device-%d", i), "deviceName": "Phone", "createdAt": "now", "lastUsedAt": "now", "lockedUntil": nil}
					}
					_ = json.NewEncoder(w).Encode(items)
				case "/api/v1/auth/biometric/devices":
					fmt.Fprint(w, `[]`)
				default:
					fixtureUpstream(w, r)
				}
			})
			w := request(h, "GET", "/api/v1/screens/"+tc.screen, "", "valid")
			if w.Code != tc.status {
				t.Fatalf("boundary changed: %d %s", w.Code, w.Body)
			}
			if w.Code == 200 {
				schemaValidate(t, schema, w.Body.Bytes())
			}
		})
	}
}
