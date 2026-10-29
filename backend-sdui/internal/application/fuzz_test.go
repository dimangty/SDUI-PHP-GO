package application

import (
	"encoding/json"
	"testing"
)

func FuzzStrictCommandJSON(f *testing.F) {
	for _, raw := range []string{
		`{"command":"AddToCart","input":{"productId":"22222222-2222-4222-8222-222222222222","quantity":1}}`,
		`{"command":"CancelOrder","input":{"orderId":"33333333-3333-4333-8333-333333333333","confirmed":true}}`,
		`{"command":"UpdateQuantity","command":"POST","input":{}}`,
		`{"command":"UpdateProfile","input":null}`,
		`{"command":"RemoveFromCart","input":{"productId":".."}}`,
		`{"COMMAND":"Checkout","input":{"url":"http://evil.test"}}`,
	} {
		f.Add([]byte(raw))
	}
	f.Fuzz(func(t *testing.T, raw []byte) {
		if len(raw) > 32<<10 {
			return
		}
		var request CommandRequest
		if StrictJSON(raw, &request) != nil {
			return
		}
		if !json.Valid(raw) {
			t.Fatal("invalid JSON passed strict decoding")
		}
		if _, err := prepareCommand(request, "fuzz-key"); err == nil {
			switch request.Command {
			case "AddToCart", "UpdateQuantity", "RemoveFromCart", "CancelOrder", "UpdateProfile", "RevokeDevice":
			default:
				t.Fatal("executable/unknown command accepted")
			}
		}
	})
}
