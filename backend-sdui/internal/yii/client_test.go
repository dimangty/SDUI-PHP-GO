package yii

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

const productID = "11111111-1111-4111-8111-111111111111"

func clientFor(t *testing.T, h http.HandlerFunc, limit int64) *Client {
	t.Helper()
	s := httptest.NewServer(h)
	t.Cleanup(s.Close)
	c, err := New(Config{Origin: s.URL, Timeout: time.Second, MaxBody: limit, Backoff: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func TestTypedProductPreservesMoneyAndOnlySafeHeaders(t *testing.T) {
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/products/"+productID || r.Method != "GET" {
			t.Errorf("unexpected target %s %s", r.Method, r.URL)
		}
		if r.Header.Get("Authorization") != "Bearer test-token" || r.Header.Get("X-Request-Id") != "trace-1" {
			t.Errorf("missing context headers: %v", r.Header)
		}
		for k := range r.Header {
			if k != "Authorization" && k != "X-Request-Id" && k != "Accept" && k != "Accept-Encoding" && k != "User-Agent" && k != "Connection" {
				t.Errorf("unexpected header %s", k)
			}
		}
		fmt.Fprintf(w, `{"id":%q,"name":"Lamp","price":"9999999999.99","discountPrice":"50.01","stock":5,"imageUrls":[],"isActive":true}`, productID)
	}, 4096)
	p, err := c.Product(context.Background(), Identity{Bearer: "test-token", RequestID: "trace-1"}, productID)
	if err != nil || p.Price != "9999999999.99" || p.DiscountPrice == nil || *p.DiscountPrice != "50.01" {
		t.Fatalf("%+v %v", p, err)
	}
}

func TestClientRejectsUnsafeOriginsAndIdentifiers(t *testing.T) {
	for _, origin := range []string{"https://user:pass@shop/", "https://shop/api", "https://shop?x=y", "https://shop#fragment", "file:///tmp/test", "//shop", "https://shop/../"} {
		if _, err := New(Config{Origin: origin, Timeout: time.Second}); err == nil {
			t.Errorf("accepted %q", origin)
		}
	}
	var calls atomic.Int32
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) { calls.Add(1) }, 4096)
	for _, id := range []string{"../users/me", "https://evil.test", productID + "?role=admin"} {
		if _, err := c.Product(context.Background(), Identity{}, id); err == nil {
			t.Errorf("accepted id %s", id)
		}
	}
	if _, err := c.Product(context.Background(), Identity{Bearer: "bad\r\nX-Role: admin"}, productID); err == nil {
		t.Error("accepted header injection")
	}
	if calls.Load() != 0 {
		t.Fatal("unsafe input reached upstream")
	}
}

func TestCorrelationSanitizedAndRedirectNeverFollowed(t *testing.T) {
	var leaked atomic.Int32
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { leaked.Add(1) }))
	defer target.Close()
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		id := r.Header.Get("X-Request-Id")
		if id == "" || strings.ContainsAny(id, "\r\n ") {
			t.Errorf("unsafe request id %q", id)
		}
		http.Redirect(w, r, target.URL, http.StatusTemporaryRedirect)
	}, 4096)
	_, err := c.Product(context.Background(), Identity{Bearer: "secret", RequestID: "bad id"}, productID)
	var transport *TransportError
	if !errors.As(err, &transport) || leaked.Load() != 0 {
		t.Fatalf("redirect followed or error lost: %v %d", err, leaked.Load())
	}
}

func TestUpstreamEnvelopeAndStatusPreservedWithoutRetry(t *testing.T) {
	for _, status := range []int{401, 403, 409, 422, 429} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			var calls atomic.Int32
			body := `{"error":{"code":"BUSINESS_DENIED","message":"denied","traceId":"yii-trace","details":{"items":"x"}}}`
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) { calls.Add(1); w.WriteHeader(status); fmt.Fprint(w, body) }, 4096)
			_, err := c.Cart(context.Background(), Identity{Bearer: "test"})
			var upstream *UpstreamError
			if !errors.As(err, &upstream) || upstream.Status != status || string(upstream.Body) != body || calls.Load() != 1 {
				t.Fatalf("%v calls=%d", err, calls.Load())
			}
		})
	}
}

func TestBoundedBodyMalformedJSONAndNumericMoney(t *testing.T) {
	for _, body := range []string{strings.Repeat("x", 1025), `{"price":12.50}`, `{"price":"12.50"} {}`, `{"price":"NaN"}`} {
		t.Run(fmt.Sprint(len(body), body[:min(10, len(body))]), func(t *testing.T) {
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) { fmt.Fprint(w, body) }, 1024)
			_, err := c.Product(context.Background(), Identity{}, productID)
			var transport *TransportError
			if !errors.As(err, &transport) {
				t.Fatalf("invalid payload not transport/protocol error: %v", err)
			}
		})
	}
}

func TestGETRetriesOnceButMutationNeverRetries(t *testing.T) {
	var calls atomic.Int32
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		n := calls.Add(1)
		if r.Method == "POST" {
			if r.Header.Get("Idempotency-Key") != "same-key" {
				t.Error("key lost")
			}
			w.WriteHeader(503)
			fmt.Fprint(w, `{"error":{"code":"DATABASE_UNAVAILABLE","message":"down","traceId":"x"}}`)
			return
		}
		if n == 1 {
			w.WriteHeader(503)
			fmt.Fprint(w, `{"error":{"code":"DATABASE_UNAVAILABLE","message":"down","traceId":"x"}}`)
			return
		}
		fmt.Fprint(w, `{"id":"cart","userId":"u","items":[],"totalAmount":"0.00"}`)
	}, 4096)
	if _, err := c.Cart(context.Background(), Identity{Bearer: "test"}); err != nil || calls.Load() != 2 {
		t.Fatalf("GET %v %d", err, calls.Load())
	}
	calls.Store(0)
	_, err := c.AddCart(context.Background(), Identity{Bearer: "test"}, AddCartInput{ProductID: productID, Quantity: 1}, "same-key")
	if err == nil || calls.Load() != 1 {
		t.Fatalf("mutation retry: %v %d", err, calls.Load())
	}
}

func TestGETRetryHasHardCeilingAndCancellableBackoff(t *testing.T) {
	var calls atomic.Int32
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(502)
		fmt.Fprint(w, `{"error":{"code":"UPSTREAM_DOWN","message":"down","traceId":"x"}}`)
	}, 4096)
	_, err := c.Cart(context.Background(), Identity{})
	if err == nil || calls.Load() != 2 {
		t.Fatalf("retry ceiling %v %d", err, calls.Load())
	}
	c.backoff = time.Second
	calls.Store(0)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()
	start := time.Now()
	_, err = c.Cart(ctx, Identity{})
	if err == nil || calls.Load() != 1 || time.Since(start) > 300*time.Millisecond {
		t.Fatalf("uncancellable backoff %v calls=%d", err, calls.Load())
	}
}

func TestDeadlineCancelsUpstream(t *testing.T) {
	canceled := make(chan struct{})
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) { <-r.Context().Done(); close(canceled) }, 4096)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()
	_, err := c.Cart(ctx, Identity{})
	if err == nil {
		t.Fatal("deadline ignored")
	}
	select {
	case <-canceled:
	case <-time.After(time.Second):
		t.Fatal("upstream context not canceled")
	}
}

func TestProductQueryUsesCanonicalSearchAndFilters(t *testing.T) {
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/products/search" || r.URL.Query().Get("q") != "лампа & свет" || r.URL.Query().Get("categoryId") != productID || r.URL.Query().Get("priceMin") != "50.01" || r.URL.Query().Get("page") != "2" {
			t.Errorf("query lost: %s", r.URL)
		}
		json.NewEncoder(w).Encode(map[string]any{"items": []any{}, "page": 2, "size": 10, "totalPages": 3, "totalItems": 21})
	}, 4096)
	_, err := c.Products(context.Background(), Identity{}, CatalogQuery{Query: "лампа & свет", CategoryID: productID, PriceMin: "50.01", Page: 2, Size: 10})
	if err != nil {
		t.Fatal(err)
	}
}

func TestTransientNetworkEOFGETRetriesButPOSTIsSingleAttempt(t *testing.T) {
	for _, method := range []string{"GET", "POST"} {
		t.Run(method, func(t *testing.T) {
			var calls atomic.Int32
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
				n := calls.Add(1)
				if n == 1 {
					conn, _, err := w.(http.Hijacker).Hijack()
					if err != nil {
						t.Error(err)
						return
					}
					conn.Close()
					return
				}
				fmt.Fprint(w, `{"id":"cart","userId":"u","items":[],"totalAmount":"0.00"}`)
			}, 4096)
			var err error
			if method == "GET" {
				_, err = c.Cart(context.Background(), Identity{})
			} else {
				_, err = c.AddCart(context.Background(), Identity{}, AddCartInput{ProductID: productID, Quantity: 1}, "do-not-retry")
			}
			if method == "GET" {
				if err != nil || calls.Load() != 2 {
					t.Fatalf("safe network retry missing: %v calls=%d", err, calls.Load())
				}
			} else {
				var network *TransportError
				if !errors.As(err, &network) || calls.Load() != 1 {
					t.Fatalf("mutation network error/replay: %v calls=%d", err, calls.Load())
				}
			}
		})
	}
}
