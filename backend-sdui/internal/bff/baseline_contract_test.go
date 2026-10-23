package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Characterization tests intentionally run before structural changes. They pin
// the externally visible routing/error and all-six command schema contracts.
func TestBaselineHTTPErrorContracts(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	for _, tc := range []struct {
		method, path, bearer string
		status               int
		code, allow          string
	}{
		{"GET", "/missing", "", 404, "BFF_NOT_FOUND", ""},
		{"GET", "/health/live", "", 404, "BFF_NOT_FOUND", ""},
		{"POST", "/api/v1/screens/home", "", 405, "BFF_METHOD_NOT_ALLOWED", "GET"},
		{"HEAD", "/api/v1/screens/catalog", "", 405, "BFF_METHOD_NOT_ALLOWED", "GET"},
		{"GET", "/api/v1/sdui/actions", "", 405, "BFF_METHOD_NOT_ALLOWED", "POST"},
		{"GET", "/api/v1/screens/product/not-a-uuid", "", 400, "BFF_INVALID_REQUEST", ""},
		{"GET", "/api/v1/screens/catalog?q=a&q=b", "", 400, "BFF_INVALID_REQUEST", ""},
		{"GET", "/api/v1/screens/catalog?q=" + strings.Repeat("x", 4097), "", 400, "BFF_INVALID_REQUEST", ""},
		{"GET", "/api/v1/screens/cart", "", 401, "BFF_UNAUTHORIZED", ""},
		{"GET", "/api/v1/screens/home", "bad token", 401, "BFF_UNAUTHORIZED", ""},
	} {
		t.Run(tc.method+tc.path[:min(80, len(tc.path))], func(t *testing.T) {
			w := request(h, tc.method, tc.path, "", tc.bearer)
			var envelope struct {
				Error struct{ Code, Message, TraceID string } `json:"error"`
			}
			if err := json.Unmarshal(w.Body.Bytes(), &envelope); err != nil {
				t.Fatal(err)
			}
			if w.Code != tc.status || envelope.Error.Code != tc.code || envelope.Error.Message == "" || envelope.Error.TraceID != w.Header().Get("X-Request-Id") || envelope.Error.TraceID == "" || w.Header().Get("Allow") != tc.allow || w.Header().Get("Cache-Control") != "no-store" {
				t.Fatalf("contract changed: status=%d headers=%v body=%s", w.Code, w.Header(), w.Body)
			}
		})
	}
}

func TestBaselineAllSixCommandSchemasAndRefresh(t *testing.T) {
	in, out := compileSchema(t, "action-request"), compileSchema(t, "action-response")
	for _, tc := range []struct{ command, input, response, refresh string }{
		{"AddToCart", `{"productId":"` + pid + `","quantity":1}`, cartJSON, "cart"},
		{"UpdateQuantity", `{"productId":"` + pid + `","quantity":0}`, cartJSON, "cart"},
		{"RemoveFromCart", `{"productId":"` + pid + `"}`, cartJSON, "cart"},
		{"CancelOrder", `{"orderId":"` + oid + `","confirmed":true}`, orderJSON, "order"},
		{"UpdateProfile", `{"fullName":"Buyer","phone":"+79991234567"}`, userJSON, "profile"},
		{"RevokeDevice", `{"deviceId":"phone","deviceKind":"pin","confirmed":true}`, "", "devices"},
	} {
		t.Run(tc.command, func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.Method == http.MethodGet {
					fixtureUpstream(w, r)
					return
				}
				if tc.response == "" {
					w.WriteHeader(http.StatusNoContent)
					return
				}
				fmt.Fprint(w, tc.response)
			})
			raw := `{"command":"` + tc.command + `","input":` + tc.input + `}`
			schemaValidate(t, in, []byte(raw))
			w := postCommand(h, raw, "baseline-key")
			if w.Code != 200 {
				t.Fatalf("%d %s", w.Code, w.Body)
			}
			schemaValidate(t, out, w.Body.Bytes())
			var response CommandResponse
			if err := json.Unmarshal(w.Body.Bytes(), &response); err != nil {
				t.Fatal(err)
			}
			if response.SchemaVersion != 1 || response.Command != tc.command || response.Outcome != "applied" || response.Refresh.Screen != tc.refresh {
				t.Fatalf("unexpected response: %+v", response)
			}
		})
	}
}
