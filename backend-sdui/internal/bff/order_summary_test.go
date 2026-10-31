package bff

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"

	"github.com/santhosh-tekuri/jsonschema/v6"
)

func summaryFixtureItem() map[string]any {
	return map[string]any{"id": oid, "number": 42, "userId": uid, "status": "COMPLETED", "totalAmount": "9999999999.99", "deliveryAddress": "Исторический адрес 😀", "paymentMethod": "CARD", "createdAt": "2026-10-30T00:00:00Z"}
}

func summaryFixturePage(count int) map[string]any {
	items := make([]any, 0, count)
	for i := 0; i < count; i++ {
		item := summaryFixtureItem()
		item["id"] = fmt.Sprintf("%08x-3333-4333-8333-333333333333", i)
		items = append(items, item)
	}
	pages := 0
	if count > 0 {
		pages = 1
	}
	return map[string]any{"projection": "sdui-order-summary-v1", "items": items, "page": 0, "size": 100, "totalItems": count, "totalPages": pages}
}

// A decoder tied to the full DTO fails here, even though every card field exists.
func TestR9OrderSummaryPagesAndQuery(t *testing.T) {
	for _, count := range []int{0, 1, 10, 20, 100} {
		t.Run(strconv.Itoa(count), func(t *testing.T) {
			var calls atomic.Int32
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/users/me" {
					fmt.Fprint(w, userJSON)
					return
				}
				calls.Add(1)
				if r.URL.Path != "/api/v1/orders" || r.URL.RawQuery != "page=0&projection=sdui-order-summary-v1&size=100&status=COMPLETED" {
					t.Errorf("wrong summary target %s", r.URL)
				}
				if r.Header.Get("Authorization") != "Bearer valid" {
					t.Error("lost bearer")
				}
				_ = json.NewEncoder(w).Encode(summaryFixturePage(count))
			}))
			defer s.Close()
			h := round3Handler(t, s.URL)
			w := request(h, "GET", "/api/v1/screens/orders?page=0&size=100&status=COMPLETED", "", "valid")
			if w.Code != 200 || calls.Load() != 1 {
				t.Fatalf("summary failed: HTTP %d calls=%d %s", w.Code, calls.Load(), w.Body)
			}
			schemaValidate(t, compileSchema(t, "screen"), w.Body.Bytes())
			if strings.Count(w.Body.String(), `"type":"OrderCard"`) != count || w.Header().Get("Cache-Control") != "no-store" {
				t.Fatal("lost cards or privacy")
			}
			if count > 0 && (!strings.Contains(w.Body.String(), "Исторический адрес 😀") || !strings.Contains(w.Body.String(), `"total":"9999999999.99"`)) {
				t.Fatal("lost snapshot values")
			}
		})
	}
}

func TestR9OrderSummaryProtocolAndOwnership(t *testing.T) {
	cases := map[string]func(map[string]any){
		"marker missing":       func(p map[string]any) { delete(p, "projection") },
		"marker wrong":         func(p map[string]any) { p["projection"] = "future-v2" },
		"marker null":          func(p map[string]any) { p["projection"] = nil },
		"empty missing marker": func(p map[string]any) { delete(p, "projection"); p["items"] = []any{} },
		"empty wrong marker":   func(p map[string]any) { p["projection"] = "future-v2"; p["items"] = []any{} },
		"marker case alias":    func(p map[string]any) { p["Projection"] = p["projection"] },
		"items null":           func(p map[string]any) { p["items"] = nil },
		"null entry":           func(p map[string]any) { p["items"] = []any{nil} },
		"full instead":         func(p map[string]any) { delete(p, "projection"); p["items"] = []any{followupObject(t, orderJSON)} },
		"full with marker":     func(p map[string]any) { p["items"] = []any{followupObject(t, orderJSON)} },
		"foreign owner":        func(p map[string]any) { p["items"].([]any)[0].(map[string]any)["userId"] = aid },
		"invalid money":        func(p map[string]any) { p["items"].([]any)[0].(map[string]any)["totalAmount"] = "1.001" },
		"number overflow":      func(p map[string]any) { p["items"].([]any)[0].(map[string]any)["number"] = int64(2147483648) },
		"item case alias":      func(p map[string]any) { p["items"].([]any)[0].(map[string]any)["UserId"] = uid },
		"item wrong scalar":    func(p map[string]any) { p["items"].([]any)[0].(map[string]any)["number"] = "42" },
	}
	for _, field := range []string{"id", "number", "userId", "status", "totalAmount", "deliveryAddress", "paymentMethod", "createdAt"} {
		cases["missing "+field] = func(p map[string]any) { delete(p["items"].([]any)[0].(map[string]any), field) }
		cases["null "+field] = func(p map[string]any) { p["items"].([]any)[0].(map[string]any)[field] = nil }
	}
	for _, field := range []string{"page", "size", "totalPages", "totalItems"} {
		cases["missing "+field] = func(p map[string]any) { delete(p, field) }
		cases["null "+field] = func(p map[string]any) { p[field] = nil }
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			page := summaryFixturePage(1)
			mutate(page)
			var calls atomic.Int32
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/users/me" {
					fmt.Fprint(w, userJSON)
					return
				}
				calls.Add(1)
				_ = json.NewEncoder(w).Encode(page)
			}))
			defer s.Close()
			w := request(round3Handler(t, s.URL), "GET", "/api/v1/screens/orders", "", "valid")
			if w.Code != 502 || calls.Load() != 1 || strings.Contains(w.Body.String(), `"components"`) {
				t.Fatalf("invalid summary published/retried: %d calls=%d %s", w.Code, calls.Load(), w.Body)
			}
			if name == "foreign owner" && !strings.Contains(w.Body.String(), "BFF_UPSTREAM_ACCOUNT_MISMATCH") {
				t.Fatal("ownership check not reached")
			}
		})
	}
}

func TestR9NativePHPOrderBytesAtUnchangedBodyLimit(t *testing.T) {
	path := os.Getenv("R9_ORDER_NATIVE_FIXTURE")
	if path == "" {
		t.Skip("set R9_ORDER_NATIVE_FIXTURE to native r9_order_summary.php --export output")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var fixture struct {
		Cases   map[string]json.RawMessage `json:"cases"`
		MaxFull json.RawMessage            `json:"maxFull"`
	}
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	if len(fixture.MaxFull) <= 2<<20 {
		t.Fatal("native 20x200 maximum Unicode full page did not exceed 2MiB")
	}
	for _, count := range []int{0, 1, 10, 20, 100} {
		t.Run(strconv.Itoa(count), func(t *testing.T) {
			body := fixture.Cases[strconv.Itoa(count)]
			var page struct {
				Items []struct {
					UserID string `json:"userId"`
				} `json:"items"`
			}
			if err := json.Unmarshal(body, &page); err != nil || len(page.Items) != count {
				t.Fatal("native fixture missing rows", err)
			}
			owner := uid
			if count > 0 {
				owner = page.Items[0].UserID
			}
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				switch r.URL.Path {
				case "/api/v1/users/me":
					fmt.Fprint(w, strings.ReplaceAll(userJSON, uid, owner))
				case "/api/v1/orders":
					if r.URL.Query().Get("projection") == "sdui-order-summary-v1" {
						w.Write(body)
					} else {
						w.Write(fixture.MaxFull)
					}
				default:
					t.Errorf("unexpected N+1 request %s", r.URL.Path)
					w.WriteHeader(500)
				}
			}))
			defer s.Close()
			w := request(round3Handler(t, s.URL), "GET", "/api/v1/screens/orders?size=100&status=COMPLETED", "", "valid")
			if w.Code != 200 || strings.Count(w.Body.String(), `"type":"OrderCard"`) != count {
				t.Fatalf("native summary failed HTTP %d %s", w.Code, w.Body)
			}
			schemaValidate(t, compileSchema(t, "screen"), w.Body.Bytes())
			c, err := yii.New(yii.Config{Origin: s.URL, Timeout: 2 * time.Second})
			if err != nil {
				t.Fatal(err)
			}
			defer c.CloseIdleConnections()
			_, err = c.Orders(t.Context(), yii.Identity{Bearer: "valid"}, yii.OrderQuery{Size: 100})
			var failure *yii.TransportError
			if !errors.As(err, &failure) || failure.Kind != "body_limit" {
				t.Fatalf("oversized full page must fail at unchanged body cap: %v", err)
			}
			t.Logf("native orders=%d summary_bytes=%d sdui_bytes=%d full_20x200_bytes=%d", count, len(body), w.Body.Len(), len(fixture.MaxFull))
		})
	}
}

func TestR9OrderSummaryAndDetailConcurrentIdentity(t *testing.T) {
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		owner := uid
		if r.Header.Get("Authorization") == "Bearer other" {
			owner = aid
		}
		switch r.URL.Path {
		case "/api/v1/users/me":
			fmt.Fprint(w, strings.ReplaceAll(userJSON, uid, owner))
		case "/api/v1/orders":
			if r.URL.Query().Get("projection") != "sdui-order-summary-v1" {
				t.Error("lost summary projection")
			}
			p := summaryFixturePage(1)
			p["items"].([]any)[0].(map[string]any)["userId"] = owner
			_ = json.NewEncoder(w).Encode(p)
		case "/api/v1/orders/" + oid:
			if r.URL.Query().Has("projection") {
				t.Error("projection leaked into detail")
			}
			fmt.Fprint(w, strings.ReplaceAll(orderJSON, uid, owner))
		}
	}))
	defer s.Close()
	h := round3Handler(t, s.URL)
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for _, token := range []string{"valid", "other"} {
				for _, screen := range []string{"orders", "order/" + oid} {
					w := request(h, "GET", "/api/v1/screens/"+screen, "", token)
					if w.Code != 200 {
						t.Errorf("concurrent %s HTTP %d", screen, w.Code)
					}
				}
			}
		}()
	}
	wg.Wait()
}

func TestR9NativeOrderOpenAPIContract(t *testing.T) {
	contractPath, fixturePath := os.Getenv("R9_ORDER_OPENAPI_JSON"), os.Getenv("R9_ORDER_NATIVE_FIXTURE")
	if contractPath == "" || fixturePath == "" {
		t.Skip("set R9_ORDER_OPENAPI_JSON and R9_ORDER_NATIVE_FIXTURE for native schema validation")
	}
	raw, err := os.ReadFile(contractPath)
	if err != nil {
		t.Fatal(err)
	}
	doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	compiler := jsonschema.NewCompiler()
	const origin = "https://shopkmp.local/r9-openapi.json"
	if err := compiler.AddResource(origin, doc); err != nil {
		t.Fatal(err)
	}
	summary, err := compiler.Compile(origin + "#/components/schemas/OrderSummaryPage")
	if err != nil {
		t.Fatal(err)
	}
	response, err := compiler.Compile(origin + "#/paths/~1orders/get/responses/200/content/application~1json/schema")
	if err != nil {
		t.Fatal(err)
	}
	raw, err = os.ReadFile(fixturePath)
	if err != nil {
		t.Fatal(err)
	}
	var fixture struct {
		Cases   map[string]json.RawMessage `json:"cases"`
		MaxFull json.RawMessage            `json:"maxFull"`
	}
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	for _, count := range []string{"0", "1", "10", "20", "100"} {
		schemaValidate(t, summary, fixture.Cases[count])
		schemaValidate(t, response, fixture.Cases[count])
	}
	schemaValidate(t, response, fixture.MaxFull)
	for _, marker := range []any{nil, "wrong"} {
		var page map[string]any
		if err := json.Unmarshal(fixture.Cases["0"], &page); err != nil {
			t.Fatal(err)
		}
		if marker == nil {
			delete(page, "projection")
		} else {
			page["projection"] = marker
		}
		if err := summary.Validate(page); err == nil {
			t.Fatal("summary schema accepted absent/wrong marker on empty page")
		}
		if marker != nil && response.Validate(page) == nil {
			t.Fatal("response oneOf accepted unsupported projection")
		}
	}
}
