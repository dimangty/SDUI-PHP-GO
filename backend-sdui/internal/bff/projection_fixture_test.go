package bff

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
)

// projectedFixture serializes the existing full-product/order fixtures using PHP's
// opt-in wire contracts. Original assertions, invalid values, required-member
// omissions, statuses and error bytes remain intact. Protocol-specific tests use
// direct servers instead so no marker/capability can be synthesized for them.
func projectedFixture(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/orders" && r.URL.Query().Get("projection") == "sdui-order-summary-v1" {
			projectedOrderFixture(next, w, r)
			return
		}
		if r.URL.Query().Get("projection") != "sdui-card-v1" || (r.URL.Path != "/api/v1/products" && r.URL.Path != "/api/v1/products/search" && r.URL.Path != "/api/v1/products/popular") {
			next(w, r)
			return
		}
		recorded := httptest.NewRecorder()
		next(recorded, r)
		raw := recorded.Body.Bytes()
		if recorded.Code == 200 {
			if r.URL.Path == "/api/v1/products/popular" {
				var items []json.RawMessage
				if len(bytes.TrimSpace(raw)) > 0 && bytes.TrimSpace(raw)[0] == '[' && json.Unmarshal(raw, &items) == nil {
					for i := range items {
						items[i] = projectFixtureProduct(items[i])
					}
					raw, _ = json.Marshal(map[string]any{"projection": "sdui-card-v1", "items": items})
				}
			} else {
				var page map[string]json.RawMessage
				if json.Unmarshal(raw, &page) == nil && page != nil && page["projection"] == nil {
					var items []json.RawMessage
					if list, ok := page["items"]; ok && len(bytes.TrimSpace(list)) > 0 && bytes.TrimSpace(list)[0] == '[' && json.Unmarshal(list, &items) == nil {
						for i := range items {
							items[i] = projectFixtureProduct(items[i])
						}
						page["items"], _ = json.Marshal(items)
					}
					page["projection"] = json.RawMessage(`"sdui-card-v1"`)
					raw, _ = json.Marshal(page)
				}
			}
		}
		for k, values := range recorded.Header() {
			for _, v := range values {
				w.Header().Add(k, v)
			}
		}
		w.WriteHeader(recorded.Code)
		w.Write(raw)
	}
}

func projectedOrderFixture(next http.HandlerFunc, w http.ResponseWriter, r *http.Request) {
	recorded := httptest.NewRecorder()
	next(recorded, r)
	raw := recorded.Body.Bytes()
	var page map[string]json.RawMessage
	if recorded.Code == 200 && json.Unmarshal(raw, &page) == nil && page != nil && page["projection"] == nil {
		var items []json.RawMessage
		list := bytes.TrimSpace(page["items"])
		if len(list) > 0 && list[0] == '[' && json.Unmarshal(list, &items) == nil {
			for i, item := range items {
				var order map[string]json.RawMessage
				if json.Unmarshal(item, &order) == nil && order != nil {
					for _, key := range []string{"items", "deliveryAddressId", "paymentId", "comment"} {
						delete(order, key)
					}
					items[i], _ = json.Marshal(order)
				}
			}
			page["items"], _ = json.Marshal(items)
		}
		page["projection"] = json.RawMessage(`"sdui-order-summary-v1"`)
		raw, _ = json.Marshal(page)
	}
	for key, values := range recorded.Header() {
		for _, value := range values {
			w.Header().Add(key, value)
		}
	}
	w.WriteHeader(recorded.Code)
	w.Write(raw)
}

func projectFixtureProduct(raw json.RawMessage) json.RawMessage {
	var p map[string]json.RawMessage
	if json.Unmarshal(raw, &p) != nil || p == nil {
		return raw
	}
	if _, projected := p["descriptionSnippet"]; projected {
		return raw
	}
	if value, ok := p["description"]; ok {
		var description string
		if len(bytes.TrimSpace(value)) > 0 && bytes.TrimSpace(value)[0] == '"' && json.Unmarshal(value, &description) == nil {
			runes := []rune(description)
			p["descriptionSnippet"], _ = json.Marshal(string(runes[:min(257, len(runes))]))
			p["descriptionComplete"], _ = json.Marshal(len(runes) <= 257)
		}
	}
	if value, ok := p["imageUrls"]; ok {
		var images []json.RawMessage
		if len(bytes.TrimSpace(value)) > 0 && bytes.TrimSpace(value)[0] == '[' && json.Unmarshal(value, &images) == nil {
			p["imageUrl"] = json.RawMessage(`null`)
			if len(images) > 0 {
				p["imageUrl"] = images[0]
			}
		}
	}
	delete(p, "description")
	delete(p, "imageUrls")
	out, err := json.Marshal(p)
	if err != nil {
		return raw
	}
	return out
}
