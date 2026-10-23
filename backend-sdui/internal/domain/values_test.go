package domain

import (
	"encoding/json"
	"testing"
)

func TestMoneyAndQueriesPreserveWireValidation(t *testing.T) {
	for _, raw := range []string{`12.50`, `"01.00"`, `"-1.00"`, `"NaN"`, `null`} {
		var m Money
		if err := json.Unmarshal([]byte(raw), &m); err == nil {
			t.Fatalf("accepted noncanonical money %s", raw)
		}
	}
	var m Money
	if err := json.Unmarshal([]byte(`"9999999999.99"`), &m); err != nil || m != "9999999999.99" {
		t.Fatal("decimal string changed")
	}
	q := CatalogQuery{Query: "lamp & light", PriceMin: "1.00", Page: 2, Size: 10}
	if err := q.Validate(); err != nil || q.Values().Encode() != "page=2&priceMin=1.00&q=lamp+%26+light&size=10" {
		t.Fatalf("query encoding changed: %v %s", err, q.Values().Encode())
	}
	q.Sort = "rating"
	if q.Validate() == nil {
		t.Fatal("search sort restriction lost")
	}
}
