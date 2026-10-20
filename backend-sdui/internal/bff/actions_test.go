package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
)

func postCommand(h http.Handler, body, key string) *httptest.ResponseRecorder {
	r := httptest.NewRequest("POST", "/api/v1/sdui/actions", strings.NewReader(body))
	r.Header.Set("Authorization", "Bearer buyer-token")
	r.Header.Set("Content-Type", "application/json")
	r.Header.Set("Idempotency-Key", key)
	r.Header.Set("X-User-Id", oid)
	r.Header.Set("X-Role", "admin")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	return w
}
func TestAllowlistedCommandsUseCanonicalMethodsAndTypedInputs(t *testing.T) {
	for _, tc := range []struct{ command, input, method, path, response, key string }{
		{"AddToCart", `{"productId":"` + pid + `","quantity":2}`, "POST", "/api/v1/cart/items", cartJSON, "original-key"},
		{"RemoveFromCart", `{"productId":"` + pid + `"}`, "DELETE", "/api/v1/cart/items/" + pid, cartJSON, ""},
		{"UpdateQuantity", `{"productId":"` + pid + `","quantity":3}`, "PATCH", "/api/v1/cart/items/" + pid, cartJSON, ""},
		{"CancelOrder", `{"orderId":"` + oid + `","confirmed":true}`, "POST", "/api/v1/orders/" + oid + "/cancel", strings.ReplaceAll(orderJSON, "CREATED", "CANCELLED"), ""},
		{"UpdateProfile", `{"fullName":"New buyer","phone":"+79991234567"}`, "PUT", "/api/v1/users/me", strings.ReplaceAll(userJSON, "Buyer", "New buyer"), ""},
		{"RevokeDevice", `{"deviceId":"test-device","deviceKind":"pin","confirmed":true}`, "DELETE", "/api/v1/auth/pin/devices/test-device", "", ""},
		{"RevokeDevice", `{"deviceId":"test-device","deviceKind":"biometric","confirmed":true}`, "DELETE", "/api/v1/auth/biometric/devices/test-device", "", ""},
	} {
		t.Run(tc.command+tc.path, func(t *testing.T) {
			var auth, mutations atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.Header.Get("X-Role") != "" || r.Header.Get("X-User-Id") != "" {
					t.Error("spoof identity propagated")
				}
				if r.Method == "GET" {
					if r.URL.Path == "/api/v1/users/me" {
						auth.Add(1)
					}
					fixtureUpstream(w, r)
					return
				}
				mutations.Add(1)
				if auth.Load() != 1 || r.Method != tc.method || r.URL.Path != tc.path || r.Header.Get("Authorization") != "Bearer buyer-token" || r.Header.Get("Idempotency-Key") != tc.key {
					t.Errorf("wrong command target %s %s %v", r.Method, r.URL, r.Header)
				}
				if r.Method == "POST" && tc.command == "AddToCart" {
					var in yiiAddInput
					json.NewDecoder(r.Body).Decode(&in)
					if in.ProductID != pid || in.Quantity != 2 {
						t.Errorf("input lost %+v", in)
					}
				}
				if tc.response == "" {
					w.WriteHeader(204)
				} else {
					fmt.Fprint(w, tc.response)
				}
			})
			w := postCommand(h, `{"command":"`+tc.command+`","input":`+tc.input+`}`, tc.key)
			if w.Code != 200 || mutations.Load() != 1 || !strings.Contains(w.Body.String(), `"outcome":"applied"`) {
				t.Fatalf("%d %s mutations=%d", w.Code, w.Body, mutations.Load())
			}
		})
	}
}

type yiiAddInput struct {
	ProductID string `json:"productId"`
	Quantity  int    `json:"quantity"`
}

func TestCommandsRejectRemoteExecutionSecretsAndInvalidInputsBeforeMutation(t *testing.T) {
	var mutations atomic.Int32
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "GET" {
			mutations.Add(1)
		}
		fixtureUpstream(w, r)
	})
	for _, body := range []string{
		`{"COMMAND":"AddToCart","input":{"productId":"` + pid + `","quantity":1}}`,
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1,"Quantity":2}}`,
		`{"command":"AddToCart","input":{"ProductId":"` + pid + `","quantity":1}}`,
		`{"command":"RevokeDevice","input":{"deviceId":"..","deviceKind":"pin","confirmed":true}}`,
		`{"command":"RevokeDevice","input":{"deviceId":".","deviceKind":"pin","confirmed":true}}`,
		`{"command":"POST","input":{"url":"http://evil.test"}}`,
		`{"command":"Checkout","input":{}}`,
		`{"command":"Logout","input":{"refreshToken":"secret"}}`,
		`{"command":"SetPin","input":{"pin":"825496"}}`,
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":0}}`,
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1000001}}`,
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1.2}}`,
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1,"userId":"` + oid + `"}}`,
		`{"command":"AddToCart","input":{"productId":"../users/me","quantity":1}}`,
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1,"quantity":2}}`,
		`{"command":"UpdateQuantity","input":{"productId":"` + pid + `"}}`,
		`{"command":"CancelOrder","input":{"orderId":"` + oid + `","confirmed":false}}`,
		`{"command":"RevokeDevice","input":{"deviceId":"../../me","deviceKind":"pin","confirmed":true}}`,
		`{"command":"UpdateProfile","input":{"fullName":"Buyer","phone":"+79991234567","password":"secret"}}`,
		`{"command":"UpdateProfile","input":{"fullName":"","phone":"x"}}`,
		`{"command":"UpdateProfile","input":null}`,
		`{"command":"RemoveFromCart","input":{"productId":"` + pid + `"},"method":"DELETE"}`,
		`{"command":"RemoveFromCart","input":{"productId":"` + pid + `"}} {}`,
	} {
		w := postCommand(h, body, "test-key")
		if w.Code != 400 {
			t.Errorf("accepted %s: %d %s", body, w.Code, w.Body)
		}
	}
	if mutations.Load() != 0 {
		t.Fatal("invalid command reached mutation")
	}
}
func TestActionRequiresBearerContentTypeBoundedBodyAndIdempotency(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	body := `{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1}}`
	if w := request(h, "POST", "/api/v1/sdui/actions", body, ""); w.Code != 401 {
		t.Fatalf("no bearer %d %s", w.Code, w.Body)
	}
	if w := postCommand(h, body, ""); w.Code != 400 {
		t.Fatalf("no key %d %s", w.Code, w.Body)
	}
	if w := postCommand(h, strings.Repeat("x", (32<<10)+1), "key"); w.Code != 413 {
		t.Fatalf("oversized %d %s", w.Code, w.Body)
	}
	r := httptest.NewRequest("POST", "/api/v1/sdui/actions", strings.NewReader(body))
	r.Header.Set("Authorization", "Bearer valid")
	r.Header.Set("Content-Type", "text/plain")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 415 {
		t.Fatalf("wrong content-type %d", w.Code)
	}
}
func TestActionUpstreamErrorPreservedAndNoMutationRetry(t *testing.T) {
	var mutations atomic.Int32
	body := `{"error":{"code":"DATABASE_UNAVAILABLE","message":"temporarily unavailable","traceId":"yii-write","details":{"retry":"manual"}}}`
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" {
			fixtureUpstream(w, r)
			return
		}
		mutations.Add(1)
		w.WriteHeader(503)
		fmt.Fprint(w, body)
	})
	w := postCommand(h, `{"command":"AddToCart","input":{"productId":"`+pid+`","quantity":2}}`, "same-key")
	if w.Code != 503 || w.Body.String() != body || mutations.Load() != 1 {
		t.Fatalf("mutation replay/envelope %d %s calls=%d", w.Code, w.Body, mutations.Load())
	}
}
func TestCancelCannotUseSpoofedOwnership(t *testing.T) {
	var mutations atomic.Int32
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "GET" {
			mutations.Add(1)
		}
		if r.URL.Path == "/api/v1/orders/"+oid {
			fmt.Fprint(w, strings.ReplaceAll(orderJSON, uid, pid))
			return
		}
		fixtureUpstream(w, r)
	})
	w := postCommand(h, `{"command":"CancelOrder","input":{"orderId":"`+oid+`","confirmed":true}}`, "")
	if w.Code != 502 || mutations.Load() != 0 {
		t.Fatalf("ownership ignored %d %s", w.Code, w.Body)
	}
}
