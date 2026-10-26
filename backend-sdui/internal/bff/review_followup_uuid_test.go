package bff

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
)

const followupUUID = "abcdefab-cdef-4abc-8def-abcdefabcdef"
const followupMixedUUID = "AbCdEfAb-cDeF-4aBc-8DeF-aBcDeFaBcDeF"

func followupObject(t *testing.T, raw string) map[string]any {
	t.Helper()
	var value map[string]any
	if err := json.Unmarshal([]byte(raw), &value); err != nil {
		t.Fatal(err)
	}
	return value
}

func TestFollowupN1ResourceUUIDValueAndCancellation(t *testing.T) {
	screenSchema, commandSchema := compileSchema(t, "screen"), compileSchema(t, "action-response")
	for _, resource := range []string{followupUUID, strings.ToUpper(followupUUID), followupMixedUUID} {
		t.Run(resource, func(t *testing.T) {
			var writes atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				switch r.URL.Path {
				case "/api/v1/products/" + resource:
					fmt.Fprint(w, strings.ReplaceAll(productJSON, pid, followupUUID))
				case "/api/v1/orders/" + resource, "/api/v1/orders/" + resource + "/cancel":
					if r.Method == "POST" {
						writes.Add(1)
					}
					fmt.Fprint(w, strings.ReplaceAll(orderJSON, oid, followupUUID))
				default:
					fixtureUpstream(w, r)
				}
			})
			for _, screen := range []string{"product", "order"} {
				w := request(h, "GET", "/api/v1/screens/"+screen+"/"+resource, "", "valid")
				if w.Code != 200 {
					t.Errorf("%s: %d %s", screen, w.Code, w.Body)
					continue
				}
				schemaValidate(t, screenSchema, w.Body.Bytes())
			}
			w := postCommand(h, `{"command":"CancelOrder","input":{"orderId":"`+resource+`","confirmed":true}}`, "")
			if w.Code != 200 || writes.Load() != 1 {
				t.Fatalf("cancel: %d %s writes=%d", w.Code, w.Body, writes.Load())
			}
			schemaValidate(t, commandSchema, w.Body.Bytes())
		})
	}
}

func TestFollowupN1ResourceAndAccountMismatchStillFailClosed(t *testing.T) {
	for _, tc := range []struct {
		name, beforeID, beforeUser, afterID, afterUser string
		writes                                         int32
	}{
		{"different-preflight-resource", pid, uid, followupUUID, uid, 0},
		{"different-preflight-account", followupUUID, pid, followupUUID, uid, 0},
		{"different-result-resource", followupUUID, uid, pid, uid, 1},
		{"different-result-account", followupUUID, uid, followupUUID, pid, 1},
		{"malformed-resource", "not-a-uuid", uid, followupUUID, uid, 0},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var writes atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if strings.HasPrefix(r.URL.Path, "/api/v1/orders/") {
					id, user := tc.beforeID, tc.beforeUser
					if r.Method == "POST" {
						writes.Add(1)
						id, user = tc.afterID, tc.afterUser
					}
					fmt.Fprint(w, strings.ReplaceAll(strings.ReplaceAll(orderJSON, oid, id), uid, user))
					return
				}
				if strings.HasPrefix(r.URL.Path, "/api/v1/products/") {
					fmt.Fprint(w, strings.ReplaceAll(productJSON, pid, tc.beforeID))
					return
				}
				fixtureUpstream(w, r)
			})
			w := postCommand(h, `{"command":"CancelOrder","input":{"orderId":"`+followupMixedUUID+`","confirmed":true}}`, "")
			if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_UPSTREAM_ACCOUNT_MISMATCH") || writes.Load() != tc.writes {
				t.Fatalf("mismatch accepted: %d %s writes=%d", w.Code, w.Body, writes.Load())
			}
			if tc.writes == 0 {
				w = request(h, "GET", "/api/v1/screens/order/"+followupMixedUUID, "", "valid")
				if w.Code != 502 {
					t.Fatal("order mismatch published")
				}
				if tc.beforeID != followupUUID {
					w = request(h, "GET", "/api/v1/screens/product/"+followupMixedUUID, "", "")
					if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_UPSTREAM_TRANSPORT") {
						t.Fatal("product mismatch published")
					}
				}
			}
		})
	}
	var calls atomic.Int32
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) { calls.Add(1); fixtureUpstream(w, r) })
	for _, invalid := range []string{"abcdefab-cdef-4abc-8def-abcdefabcdeg", followupUUID + "x", "not-a-uuid"} {
		for _, screen := range []string{"product", "order"} {
			if w := request(h, "GET", "/api/v1/screens/"+screen+"/"+invalid, "", "valid"); w.Code != 400 {
				t.Fatalf("invalid resource: %d", w.Code)
			}
		}
		if w := postCommand(h, `{"command":"CancelOrder","input":{"orderId":"`+invalid+`","confirmed":true}}`, ""); w.Code != 400 {
			t.Fatalf("invalid cancel: %d", w.Code)
		}
	}
	if calls.Load() != 0 {
		t.Fatal("invalid UUID reached upstream")
	}
}

func TestFollowupN1NeighboringUUIDOwnership(t *testing.T) {
	for _, wrong := range []bool{false, true} {
		t.Run(fmt.Sprintf("different-account=%v", wrong), func(t *testing.T) {
			resourceUser := followupUUID
			if wrong {
				resourceUser = pid
			}
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/users/me" {
					user := strings.ToUpper(followupUUID)
					if r.Method == "PUT" {
						user = resourceUser
					}
					fmt.Fprint(w, strings.ReplaceAll(userJSON, uid, user))
					return
				}
				b := httptest.NewRecorder()
				fixtureUpstream(b, r)
				fmt.Fprint(w, strings.ReplaceAll(b.Body.String(), uid, resourceUser))
			})
			for _, screen := range []string{"home?deviceId=Phone-A", "cart", "profile", "orders", "order/" + oid} {
				w := request(h, "GET", "/api/v1/screens/"+screen, "", "valid")
				want := 200
				if wrong {
					want = 502
				}
				if w.Code != want {
					t.Errorf("%s: %d %s", screen, w.Code, w.Body)
				}
			}
			w := postCommand(h, `{"command":"UpdateProfile","input":{"fullName":"Buyer","phone":"+79991234567"}}`, "")
			want := 200
			if wrong {
				want = 502
			}
			if w.Code != want {
				t.Fatalf("profile write ownership: %d %s", w.Code, w.Body)
			}
		})
	}
}

func TestFollowupN1CategoryAndDefaultAddressSelection(t *testing.T) {
	for _, visible := range []bool{true, false} {
		t.Run(fmt.Sprintf("category-visible=%v", visible), func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				switch r.URL.Path {
				case "/api/v1/categories":
					items := reviewCategoryItems(51, false)
					i := 0
					if !visible {
						i = 50
					}
					items[i]["id"], items[i]["name"] = followupUUID, "Selected category"
					_ = json.NewEncoder(w).Encode(items)
				case "/api/v1/users/me":
					fmt.Fprint(w, strings.ReplaceAll(userJSON, aid, strings.ToUpper(followupUUID)))
				case "/api/v1/addresses":
					fmt.Fprint(w, strings.TrimSuffix(addressJSON, "]")+","+strings.ReplaceAll(strings.ReplaceAll(strings.Trim(addressJSON, "[]"), aid, followupUUID), "Moscow", "Chosen city")+"]")
				default:
					fixtureUpstream(w, r)
				}
			})
			w := request(h, "GET", "/api/v1/screens/catalog?categoryId="+followupMixedUUID, "", "")
			if w.Code != 200 {
				t.Fatalf("catalog: %d %s", w.Code, w.Body)
			}
			d, nodes := reviewNodes(t, w.Body.Bytes())
			selected, options := 0, 0
			for _, n := range nodes {
				if n.Type == "CategoryList" {
					for _, raw := range n.Props["items"].([]any) {
						if raw.(map[string]any)["selected"] == true {
							selected++
						}
					}
				}
				if n.Props["fieldId"] == "catalog.categoryId" {
					for _, raw := range n.Props["options"].([]any) {
						o := raw.(map[string]any)
						if strings.EqualFold(fmt.Sprint(o["value"]), followupUUID) {
							options++
							if o["value"] != d.State.Fields["catalog.categoryId"] || o["label"] != "Selected category" {
								t.Error("CMP controlled option lost selected spelling/label")
							}
						}
					}
				}
			}
			if options != 1 || visible && selected != 1 || !visible && selected != 0 {
				t.Errorf("category identity: options=%d selected=%d", options, selected)
			}
			w = request(h, "GET", "/api/v1/screens/cart", "", "valid")
			d, _ = reviewNodes(t, w.Body.Bytes())
			if w.Code != 200 || d.State.Fields["checkout.city"] != "Chosen city" {
				t.Fatalf("default address ignored: %d city=%q", w.Code, d.State.Fields["checkout.city"])
			}
		})
	}
}

func TestFollowupN1MutationAccountUUIDChecks(t *testing.T) {
	for _, command := range []string{"AddToCart", "UpdateQuantity", "RemoveFromCart", "CancelOrder"} {
		for _, account := range []string{followupUUID, pid, "not-a-uuid"} {
			for _, preflight := range []bool{false, true} {
				if preflight && command != "CancelOrder" {
					continue
				}
				t.Run(fmt.Sprintf("%s/%s/preflight=%v", command, account, preflight), func(t *testing.T) {
					var writes atomic.Int32
					h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
						if r.URL.Path == "/api/v1/users/me" {
							fmt.Fprint(w, strings.ReplaceAll(userJSON, uid, strings.ToUpper(followupUUID)))
							return
						}
						owner := followupUUID
						if r.Method != "GET" {
							writes.Add(1)
							if !preflight {
								owner = account
							}
						} else if preflight {
							owner = account
						}
						if command == "CancelOrder" {
							fmt.Fprint(w, strings.ReplaceAll(strings.ReplaceAll(orderJSON, oid, followupUUID), uid, owner))
						} else {
							fmt.Fprint(w, strings.ReplaceAll(cartJSON, uid, owner))
						}
					})
					input := `{"productId":"` + followupMixedUUID + `","quantity":1}`
					switch command {
					case "RemoveFromCart":
						input = `{"productId":"` + followupMixedUUID + `"}`
					case "CancelOrder":
						input = `{"orderId":"` + followupMixedUUID + `","confirmed":true}`
					}
					w := postCommand(h, `{"command":"`+command+`","input":`+input+`}`, "Exact-Key")
					want, wantWrites := 200, int32(1)
					if account != followupUUID {
						want = 502
						if preflight {
							wantWrites = 0
						}
					}
					if w.Code != want || writes.Load() != wantWrites || want == 502 && !strings.Contains(w.Body.String(), "BFF_UPSTREAM_ACCOUNT_MISMATCH") {
						t.Fatalf("account comparison: %d %s writes=%d want=%d", w.Code, w.Body, writes.Load(), wantWrites)
					}
				})
			}
		}
	}
}

func TestFollowupN1OpaqueValuesAndMutationPayloadRemainExact(t *testing.T) {
	for _, value := range []string{"Phone-AbC", "phone-abc"} {
		t.Run(value, func(t *testing.T) {
			var writes atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.Header.Get("Authorization") != "Bearer "+value {
					t.Error("opaque Bearer changed")
				}
				if r.URL.Path == "/api/v1/auth/session" && r.URL.Query().Get("deviceId") != value {
					t.Error("device ID changed")
				}
				if r.Method == "POST" {
					writes.Add(1)
					raw, _ := io.ReadAll(r.Body)
					if string(raw) != `{"productId":"`+followupMixedUUID+`","quantity":1}` || r.Header.Get("Idempotency-Key") != value {
						t.Error("fingerprint input or key changed")
					}
					fmt.Fprint(w, cartJSON)
					return
				}
				fixtureUpstream(w, r)
			})
			if w := request(h, "GET", "/api/v1/screens/home?deviceId="+value, "", value); w.Code != 200 {
				t.Fatalf("home: %d", w.Code)
			}
			r := httptest.NewRequest("POST", "/api/v1/sdui/actions", strings.NewReader(`{"command":"AddToCart","input":{"productId":"`+followupMixedUUID+`","quantity":1}}`))
			r.Header.Set("Authorization", "Bearer "+value)
			r.Header.Set("Content-Type", "application/json")
			r.Header.Set("Idempotency-Key", value)
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != 200 || writes.Load() != 1 {
				t.Fatalf("add: %d writes=%d", w.Code, writes.Load())
			}
		})
	}
}
