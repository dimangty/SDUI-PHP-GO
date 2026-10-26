package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
)

func TestReviewFixR2RequiredNullableAndCaseAliases(t *testing.T) {
	for _, tc := range []struct{ name, endpoint, screen, body string }{
		{"discount-omitted", "/api/v1/products/" + pid, "product/" + pid, strings.Replace(productJSON, `"discountPrice":null,`, "", 1)},
		{"user-nullable-omitted", "/api/v1/users/me", "profile", strings.Replace(userJSON, `"avatarUrl":null,`, "", 1)},
		{"order-comment-omitted", "/api/v1/orders/" + oid, "order/" + oid, strings.Replace(orderJSON, `,"comment":null`, "", 1)},
		{"cart-alias", "/api/v1/cart", "cart", strings.TrimSuffix(cartJSON, "}") + `,"Items":null}`},
		{"nested-alias", "/api/v1/cart", "cart", strings.Replace(cartJSON, `"quantity":2`, `"quantity":2,"Quantity":null`, 1)},
		{"biometric-algorithm-omitted", "/api/v1/auth/biometric/devices", "devices", `[{"deviceId":"phone","deviceName":"Phone","createdAt":"now","lastUsedAt":"now","lockedUntil":null}]`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == tc.endpoint {
					fmt.Fprint(w, tc.body)
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/"+tc.screen, "", "valid")
			if w.Code != 502 {
				t.Fatalf("invalid success published: HTTP%d", w.Code)
			}
		})
	}
	for _, body := range []string{strings.TrimSuffix(cartJSON, "}") + `,"Items":null}`, strings.Replace(userJSON, `"avatarUrl":null,`, "", 1)} {
		h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
			if r.Method != "GET" {
				fmt.Fprint(w, body)
				return
			}
			fixtureUpstream(w, r)
		})
		command, input := "AddToCart", `{"productId":"`+pid+`","quantity":1}`
		var fields map[string]json.RawMessage
		_ = json.Unmarshal([]byte(body), &fields)
		if fields["email"] != nil {
			command, input = "UpdateProfile", `{"fullName":"Buyer","phone":"+79991234567"}`
		}
		w := postCommand(h, `{"command":"`+command+`","input":`+input+`}`, "key")
		if w.Code != 502 {
			t.Fatalf("invalid mutation acknowledged: %d %s", w.Code, w.Body)
		}
	}
}

func TestReviewFixR2UnexpectedSuccessIsNeverApplied(t *testing.T) {
	for _, tc := range []struct{ command, input string }{
		{"AddToCart", `{"productId":"` + pid + `","quantity":1}`},
		{"UpdateQuantity", `{"productId":"` + pid + `","quantity":1}`},
		{"RemoveFromCart", `{"productId":"` + pid + `"}`},
		{"CancelOrder", `{"orderId":"` + oid + `","confirmed":true}`},
		{"UpdateProfile", `{"fullName":"Buyer","phone":"+79991234567"}`},
		{"RevokeDevice", `{"deviceId":"phone","deviceKind":"pin","confirmed":true}`},
	} {
		for _, status := range []int{200, 202, 204} {
			t.Run(fmt.Sprintf("%s/%d", tc.command, status), func(t *testing.T) {
				var mutations atomic.Int32
				h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
					if r.Method == "GET" {
						fixtureUpstream(w, r)
						return
					}
					mutations.Add(1)
					w.WriteHeader(status)
					if status != 204 {
						fmt.Fprint(w, `{"state":"pending"}`)
					}
				})
				w := postCommand(h, `{"command":"`+tc.command+`","input":`+tc.input+`}`, "review-key")
				want := 502
				if tc.command == "RevokeDevice" && status == 204 {
					want = 200
				}
				if w.Code != want || mutations.Load() != 1 || want == 502 && strings.Contains(w.Body.String(), `"applied"`) {
					t.Fatalf("false acknowledgement/replay: HTTP%d %s mutations=%d", w.Code, w.Body, mutations.Load())
				}
			})
		}
	}
}

func TestReviewFixR2CollectionsRequireExpectedStatusAndShape(t *testing.T) {
	for _, tc := range []struct{ endpoint, screen, valid string }{
		{"/api/v1/categories", "catalog", `[]`},
		{"/api/v1/products", "catalog", `{"items":[],"page":0,"size":20,"totalPages":0,"totalItems":0}`},
		{"/api/v1/auth/pin/devices", "devices", `[]`},
	} {
		for _, body := range []string{"", `null`, `{}`, `[null]`, `{"items":null}`, tc.valid} {
			for _, status := range []int{200, 204} {
				t.Run(fmt.Sprintf("%s/%d/%s", tc.endpoint, status, body), func(t *testing.T) {
					h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
						if r.URL.Path == tc.endpoint {
							w.WriteHeader(status)
							fmt.Fprint(w, body)
							return
						}
						fixtureUpstream(w, r)
					})
					w := request(h, "GET", "/api/v1/screens/"+tc.screen, "", "valid")
					want := 502
					if status == 200 && body == tc.valid {
						want = 200
					}
					if w.Code != want {
						t.Fatalf("expected %d, got HTTP%d %s", want, w.Code, w.Body)
					}
				})
			}
		}
	}
}
