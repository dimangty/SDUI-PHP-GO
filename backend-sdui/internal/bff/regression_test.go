package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

func TestRealCatalogWithMoreThan100CategoriesRemainsUsable(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/categories" {
			items := []map[string]any{}
			for i := 0; i < 150; i++ {
				items = append(items, map[string]any{"id": fmt.Sprintf("%08x-1111-4111-8111-111111111111", i), "name": fmt.Sprintf("Category %d", i), "iconUrl": "", "sortOrder": i, "parentId": nil})
			}
			json.NewEncoder(w).Encode(items)
			return
		}
		fixtureUpstream(w, r)
	})
	for _, path := range []string{"home", "catalog"} {
		seen := map[string]bool{}
		for page := 0; page < 3; page++ {
			w := request(h, "GET", fmt.Sprintf("/api/v1/screens/%s?categoryPage=%d&categorySize=50", path, page), "", "")
			if w.Code != 200 {
				t.Fatalf("large category continuation failed: %d %s", w.Code, w.Body)
			}
			_, nodes := reviewNodes(t, w.Body.Bytes())
			for _, n := range nodes {
				if n.Type == "CategoryList" {
					for _, raw := range n.Props["items"].([]any) {
						seen[raw.(map[string]any)["id"].(string)] = true
					}
				}
			}
			schemaValidate(t, compileSchema(t, "screen"), w.Body.Bytes())
		}
		if len(seen) != 150 {
			t.Fatalf("category paging lost rows: %d", len(seen))
		}
	}
}

func TestHomeSecurityFailureCancelsSiblingRequestsAndPreserves403(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/users/me":
			fixtureUpstream(w, r)
		case "/api/v1/categories":
			w.WriteHeader(403)
			fmt.Fprint(w, `{"error":{"code":"FORBIDDEN","message":"denied","traceId":"yii-security"}}`)
		default:
			<-r.Context().Done()
		}
	})
	started := time.Now()
	w := request(h, "GET", "/api/v1/screens/home?deviceId=test-device", "", "valid")
	if w.Code != 403 || !strings.Contains(w.Body.String(), "yii-security") || time.Since(started) > 500*time.Millisecond {
		t.Fatalf("security failure delayed or overwritten: %d %s duration=%s", w.Code, w.Body, time.Since(started))
	}
}

func TestOrdersContainDeliveryAndPaymentForExistingUIKitOrderRow(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	w := request(h, "GET", "/api/v1/screens/orders", "", "valid")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"deliveryAddress":"Moscow, Light, 1"`) || !strings.Contains(w.Body.String(), `"paymentMethod":"ON_DELIVERY"`) {
		t.Fatalf("UIKit order row missing data: %d %s", w.Code, w.Body)
	}
}
func TestCheckoutFormExactlyMatchesExistingNativeUseCaseInputs(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	w := request(h, "GET", "/api/v1/screens/cart", "", "valid")
	var doc struct {
		State struct {
			Fields map[string]string `json:"fields"`
		} `json:"state"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &doc); err != nil {
		t.Fatal(err)
	}
	expected := map[string]string{"checkout.city": "Moscow", "checkout.street": "Light", "checkout.house": "1", "checkout.apartment": "2", "checkout.paymentMethod": "CARD"}
	if len(doc.State.Fields) != 5 {
		t.Fatalf("checkout exposes inputs unavailable to CheckoutUseCase: %v", doc.State.Fields)
	}
	for k, v := range expected {
		if doc.State.Fields[k] != v {
			t.Errorf("%s=%q expected %q", k, doc.State.Fields[k], v)
		}
	}
	if !strings.Contains(w.Body.String(), `"type":"Checkout"`) {
		t.Fatal("checkout not owned by native adapter")
	}
}
func TestHomeOffersControlledCatalogSearch(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	w := request(h, "GET", "/api/v1/screens/home", "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"type":"SearchField"`) || !strings.Contains(w.Body.String(), `"formId":"catalog"`) || !strings.Contains(w.Body.String(), `"catalog.q":""`) {
		t.Fatalf("home cannot search: %d %s", w.Code, w.Body)
	}
}
