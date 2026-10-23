package bff

import (
	"encoding/json"
	"testing"
)

func FuzzStrictCommandJSON(f *testing.F) {
	for _, raw := range []string{
		`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1}}`,
		`{"command":"CancelOrder","input":{"orderId":"` + oid + `","confirmed":true}}`,
		`{"command":"UpdateQuantity","command":"POST","input":{}}`,
		`{"command":"UpdateProfile","input":null}`,
		`{"command":"RemoveFromCart","input":{"productId":".."}}`,
		`{"COMMAND":"Checkout","input":{"url":"http://evil.test"}}`,
	} {
		f.Add([]byte(raw))
	}
	f.Fuzz(func(t *testing.T, raw []byte) {
		if len(raw) > MaxActionBody {
			return // transport enforces the body ceiling before decoding
		}
		var request CommandRequest
		if strictJSON(raw, &request) != nil {
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

func FuzzScreenQuery(f *testing.F) {
	for _, q := range []string{"q=lamp&page=1&size=10", "q=a&q=b", "url=http://evil", "q=%ff", "page=-1", "size=999999999999999999999"} {
		f.Add(q)
	}
	f.Fuzz(func(t *testing.T, raw string) {
		if len(raw) > 8192 {
			return
		}
		q, err := parseQuery(raw)
		if err != nil || validateKeys("catalog", q) != nil {
			return
		}
		if len(raw) > 4096 {
			t.Fatal("oversize query accepted")
		}
		_, _ = catalogQuery(q)
		_, _ = collectionQuery(q, "categoryPage", "categorySize", 50)
	})
}
