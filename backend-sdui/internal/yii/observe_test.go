package yii

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
)

func TestUpstreamTraceAndRetryMetrics(t *testing.T) {
	const parent = "00-11111111111111111111111111111111-2222222222222222-01"
	var calls atomic.Int32
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Traceparent") != parent || r.Header.Get("X-Request-Id") != "request-1" {
			t.Error("validated correlation not propagated")
		}
		if calls.Add(1) == 1 {
			w.WriteHeader(503)
			fmt.Fprint(w, `{"error":{"code":"DOWN","message":"down","traceId":"up"}}`)
			return
		}
		fmt.Fprint(w, emptyCartJSON)
	}))
	defer s.Close()
	recorder := observe.New(nil)
	c, err := New(Config{Origin: s.URL, Timeout: time.Second, Backoff: time.Millisecond, Observer: recorder})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	ctx := observe.WithCorrelation(context.Background(), "request-1", parent)
	if _, err := c.Cart(ctx, Identity{RequestID: "request-1"}); err != nil {
		t.Fatal(err)
	}
	w := httptest.NewRecorder()
	recorder.ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
	if !strings.Contains(w.Body.String(), `sdui_retries_total{kind="upstream",operation="cart"} 1`) || !strings.Contains(w.Body.String(), `sdui_reused_connections_total{kind="upstream",operation="cart"} 1`) {
		t.Fatalf("retry/reuse metrics lost: %s", w.Body)
	}
}
