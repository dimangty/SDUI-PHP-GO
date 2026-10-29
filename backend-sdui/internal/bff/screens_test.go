package bff

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

const uid = "11111111-1111-4111-8111-111111111111"
const pid = "22222222-2222-4222-8222-222222222222"
const oid = "33333333-3333-4333-8333-333333333333"
const aid = "44444444-4444-4444-8444-444444444444"
const userJSON = `{"id":"` + uid + `","email":"buyer@example.org","fullName":"Buyer","phone":"+79991234567","avatarUrl":null,"defaultAddressId":"` + aid + `","dateOfBirth":null,"createdAt":"2026-10-18T10:00:00Z"}`
const productJSON = `{"id":"` + pid + `","categoryId":"` + aid + `","name":"Real lamp","description":"Warm light","price":"50.01","discountPrice":null,"stock":9,"rating":4.5,"imageUrls":["/images/products/lamp.png"],"isActive":true,"reviewsCount":2}`
const orderJSON = `{"id":"` + oid + `","number":42,"userId":"` + uid + `","status":"CREATED","items":[{"productId":"` + pid + `","productName":"Real lamp","quantity":2,"priceSnapshot":"50.01"}],"totalAmount":"100.02","deliveryAddressId":"` + aid + `","deliveryAddress":"Moscow, Light, 1","paymentId":null,"paymentMethod":"ON_DELIVERY","createdAt":"2026-10-18T10:00:00Z","comment":null}`
const cartJSON = `{"id":"` + aid + `","userId":"` + uid + `","items":[{"productId":"` + pid + `","productName":"Real lamp","quantity":2,"priceSnapshot":"50.01","imageUrl":"/images/products/lamp.png","isAvailable":true,"stock":9}],"totalAmount":"100.02","updatedAt":"2026-10-18T10:00:00Z"}`
const addressJSON = `[{"id":"` + aid + `","userId":"` + uid + `","city":"Moscow","street":"Light","house":"1","apartment":"2","comment":null}]`

func fixtureUpstream(w http.ResponseWriter, r *http.Request) {
	projectedFixture(fullFixtureUpstream)(w, r)
}

func fullFixtureUpstream(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	switch r.URL.Path {
	case "/api/v1/users/me":
		fmt.Fprint(w, userJSON)
	case "/api/v1/categories":
		fmt.Fprint(w, `[{"id":"`+aid+`","name":"Lighting","parentId":null,"iconUrl":"/icons/light.svg","sortOrder":0}]`)
	case "/api/v1/products/popular":
		fmt.Fprint(w, `[`+productJSON+`]`)
	case "/api/v1/products", "/api/v1/products/search":
		fmt.Fprint(w, `{"items":[`+productJSON+`],"page":0,"size":20,"totalPages":2,"totalItems":21}`)
	case "/api/v1/products/" + pid:
		fmt.Fprint(w, productJSON)
	case "/api/v1/cart":
		fmt.Fprint(w, cartJSON)
	case "/api/v1/addresses":
		fmt.Fprint(w, addressJSON)
	case "/api/v1/orders":
		fmt.Fprint(w, `{"items":[`+orderJSON+`],"page":0,"size":20,"totalPages":2,"totalItems":21}`)
	case "/api/v1/orders/" + oid:
		fmt.Fprint(w, orderJSON)
	case "/api/v1/auth/pin/devices", "/api/v1/auth/biometric/devices":
		fmt.Fprint(w, `[{"deviceId":"test-device","deviceName":"Phone","keyAlgorithm":"ES256","createdAt":"2026-10-18T10:00:00Z","lastUsedAt":"2026-10-18T10:00:00Z","lockedUntil":null}]`)
	case "/api/v1/auth/session":
		fmt.Fprint(w, `{"user":`+userJSON+`,"device":{"pinEnabled":true,"biometricEnabled":false,"biometricCredentialId":null},"cartItemsCount":2}`)
	default:
		http.Error(w, "unexpected path "+r.URL.Path, 500)
	}
}
func newTestHandler(t *testing.T, fn http.HandlerFunc) http.Handler {
	t.Helper()
	s := httptest.NewServer(projectedFixture(fn))
	t.Cleanup(s.Close)
	c, err := yii.New(yii.Config{Origin: s.URL, Timeout: 2 * time.Second, Backoff: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	return New(c, Config{RequestTimeout: 3 * time.Second})
}
func request(h http.Handler, method, path, body, bearer string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, path, strings.NewReader(body))
	if bearer != "" {
		r.Header.Set("Authorization", "Bearer "+bearer)
	}
	if body != "" {
		r.Header.Set("Content-Type", "application/json")
	}
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	return w
}
func TestEightScreensContainRealShopComponentsAndStableRevisions(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	for _, tc := range []struct{ path, component, value string }{
		{"home?deviceId=test-device", "CategoryList", "Lighting"},
		{"catalog", "ProductGrid", "50.01"},
		{"product/" + pid, "QuantityStepper", "Warm light"},
		{"cart", "CartItem", "100.02"},
		{"orders", "OrderCard", "100.02"},
		{"order/" + oid, "Price", "Moscow, Light, 1"},
		{"profile", "ProfileHeader", "buyer@example.org"},
		{"devices", "Button", "test-device"},
	} {
		t.Run(tc.path, func(t *testing.T) {
			a := request(h, "GET", "/api/v1/screens/"+tc.path, "", "valid")
			b := request(h, "GET", "/api/v1/screens/"+tc.path, "", "valid")
			if a.Code != 200 {
				t.Fatalf("%d %s", a.Code, a.Body)
			}
			var x, y map[string]any
			json.Unmarshal(a.Body.Bytes(), &x)
			json.Unmarshal(b.Body.Bytes(), &y)
			if x["revision"] != y["revision"] || x["schemaVersion"] != float64(1) || !strings.Contains(a.Body.String(), `"type":"`+tc.component+`"`) || !strings.Contains(a.Body.String(), tc.value) {
				t.Fatalf("incomplete/unstable %s", a.Body)
			}
			if a.Header().Get("Cache-Control") != "no-store" {
				t.Fatal("personal data cacheable")
			}
		})
	}
}
func TestAnonymousPublicScreensAndProtectedNoBearer(t *testing.T) {
	var protected atomic.Int32
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/users/me" || r.URL.Path == "/api/v1/auth/session" {
			protected.Add(1)
		}
		fixtureUpstream(w, r)
	})
	for _, path := range []string{"home", "catalog", "product/" + pid} {
		w := request(h, "GET", "/api/v1/screens/"+path, "", "")
		if w.Code != 200 {
			t.Fatalf("%s %d %s", path, w.Code, w.Body)
		}
	}
	for _, path := range []string{"cart", "orders", "order/" + oid, "profile", "devices"} {
		w := request(h, "GET", "/api/v1/screens/"+path, "", "")
		if w.Code != 401 {
			t.Fatalf("anonymous %s %d", path, w.Code)
		}
	}
	if protected.Load() != 0 {
		t.Fatal("public request used invented session")
	}
}
func TestInvalidBearerFailsBeforeHomeAggregationAndSpoofHeadersNotForwarded(t *testing.T) {
	var dataCalls atomic.Int32
	body := `{"error":{"code":"UNAUTHORIZED","message":"invalid token","traceId":"yii-auth"}}`
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-User-Id") != "" || r.Header.Get("X-Role") != "" || r.Header.Get("Cookie") != "" {
			t.Error("spoof headers forwarded")
		}
		if r.URL.Path != "/api/v1/users/me" {
			dataCalls.Add(1)
		}
		w.WriteHeader(401)
		fmt.Fprint(w, body)
	})
	r := httptest.NewRequest("GET", "/api/v1/screens/home?deviceId=test-device", nil)
	r.Header.Set("Authorization", "Bearer invalid")
	r.Header.Set("X-User-Id", uid)
	r.Header.Set("X-Role", "admin")
	r.Header.Set("Cookie", "identity=admin")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 401 || w.Body.String() != body || dataCalls.Load() != 0 {
		t.Fatalf("auth downgrade %d %s calls=%d", w.Code, w.Body, dataCalls.Load())
	}
}
func TestHomeAggregatesConcurrentlyAndOnlyNonsecurityFailuresArePartial(t *testing.T) {
	for _, status := range []int{503, 403} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			var mu sync.Mutex
			started := 0
			all := make(chan struct{})
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/users/me" {
					fixtureUpstream(w, r)
					return
				}
				mu.Lock()
				started++
				if started == 3 {
					close(all)
				}
				mu.Unlock()
				select {
				case <-all:
				case <-r.Context().Done():
					return
				}
				if r.URL.Path == "/api/v1/categories" {
					w.WriteHeader(status)
					fmt.Fprint(w, `{"error":{"code":"CATEGORY_FAILURE","message":"no categories","traceId":"yii-partial"}}`)
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/home?deviceId=test-device", "", "valid")
			if status == 403 {
				if w.Code != 403 {
					t.Fatalf("security failure swallowed %d %s", w.Code, w.Body)
				}
			} else if w.Code != 200 || !strings.Contains(w.Body.String(), `"partial":true`) || !strings.Contains(w.Body.String(), `"type":"Error"`) || !strings.Contains(w.Body.String(), "Real lamp") {
				t.Fatalf("partial composition failed %d %s", w.Code, w.Body)
			}
		})
	}
}
func TestBuilderCancellationReachesConcurrentUpstreams(t *testing.T) {
	var started atomic.Int32
	done := make(chan struct{})
	var once sync.Once
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/users/me" {
			fixtureUpstream(w, r)
			return
		}
		if started.Add(1) == 3 {
			once.Do(func() { close(done) })
		}
		<-r.Context().Done()
	})
	ctx, cancel := context.WithCancel(context.Background())
	r := httptest.NewRequest("GET", "/api/v1/screens/home?deviceId=test-device", nil).WithContext(ctx)
	r.Header.Set("Authorization", "Bearer valid")
	finished := make(chan struct{})
	go func() { h.ServeHTTP(httptest.NewRecorder(), r); close(finished) }()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("requests not concurrent")
	}
	cancel()
	select {
	case <-finished:
	case <-time.After(time.Second):
		t.Fatal("handler ignored cancellation")
	}
}
func TestTrustedAccountOwnershipMismatchIsNotPublished(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/cart" {
			fmt.Fprint(w, strings.ReplaceAll(cartJSON, uid, oid))
			return
		}
		fixtureUpstream(w, r)
	})
	w := request(h, "GET", "/api/v1/screens/cart", "", "valid")
	if w.Code != 502 || strings.Contains(w.Body.String(), "Real lamp") {
		t.Fatalf("cross-account document %d %s", w.Code, w.Body)
	}
}
func TestScreenQueryRejectsUnknownDuplicateAndUnsafeValues(t *testing.T) {
	h := newTestHandler(t, fixtureUpstream)
	for _, q := range []string{"url=http://evil", "page=-1", "size=101", "q=a&q=b", "categoryId=../me", "priceMin=1.2", "sort=exec", "q=lamp&sort=rating"} {
		w := request(h, "GET", "/api/v1/screens/catalog?"+q, "", "")
		if w.Code != 400 {
			t.Errorf("accepted %s: %d %s", q, w.Code, w.Body)
		}
	}
}
