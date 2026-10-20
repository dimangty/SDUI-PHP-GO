package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"sync/atomic"
	"testing"
)

func TestReviewProfilePhoneMatchesCanonicalRule(t *testing.T) {
	for _, tc := range []struct {
		phone string
		valid bool
	}{{"+12345678", true}, {"+123456789012345", true}, {"123456789", false}, {"+012345678", false}, {"+1234567", false}, {"+1234567890123456", false}} {
		t.Run(tc.phone, func(t *testing.T) {
			var writes atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.Method == "PUT" {
					writes.Add(1)
					var input struct{ FullName, Phone string }
					json.NewDecoder(r.Body).Decode(&input)
					if input.Phone != tc.phone {
						t.Errorf("phone changed: %q", input.Phone)
					}
					var user map[string]any
					json.Unmarshal([]byte(userJSON), &user)
					user["phone"] = input.Phone
					json.NewEncoder(w).Encode(user)
					return
				}
				fixtureUpstream(w, r)
			})
			body := fmt.Sprintf(`{"command":"UpdateProfile","input":{"fullName":"Buyer","phone":%q}}`, tc.phone)
			w := postCommand(h, body, "")
			want := 400
			if tc.valid {
				want = 200
			}
			if w.Code != want {
				t.Fatalf("%q: HTTP %d, want %d", tc.phone, w.Code, want)
			}
			if !tc.valid && writes.Load() != 0 {
				t.Fatal("invalid phone reached mutation")
			}
			var value any
			json.Unmarshal([]byte(body), &value)
			schemaErr := compileSchema(t, "action-request").Validate(value)
			if (schemaErr == nil) != tc.valid {
				t.Fatalf("schema disagrees with canonical phone: %q error=%v", tc.phone, schemaErr)
			}
		})
	}
}
