package yii

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
)

func TestReviewFixR4FinalAttemptTelemetry(t *testing.T) {
	for _, tc := range []struct {
		name                   string
		status, calls, retries int
		kind                   string
	}{
		{"eof", 0, 2, 1, "network"},
		{"success", 200, 2, 1, ""},
		{"401-body", 401, 1, 0, "body"},
		{"403-body", 403, 1, 0, "body"},
		{"timeout", 0, 2, 1, "timeout"},
		{"cancel-attempt", 0, 2, 1, "canceled"},
		{"cancel-backoff", 503, 1, 0, "canceled"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var calls atomic.Int32
			var logs bytes.Buffer
			recorder := observe.New(slog.New(slog.NewJSONHandler(&logs, nil)))
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				n := calls.Add(1)
				if strings.HasSuffix(tc.name, "-body") {
					w.Header().Set("Content-Length", "1000")
					w.WriteHeader(tc.status)
					fmt.Fprint(w, "broken")
					return
				}
				if n == 1 {
					// Ensure attempt 2 uses a fresh connection: no hidden stale
					// pooled-connection retry can turn EOF into retry_budget.
					w.Header().Set("Connection", "close")
					w.WriteHeader(503)
					fmt.Fprint(w, `{"error":{"code":"DOWN","message":"down","traceId":"up"}}`)
					if tc.name == "cancel-backoff" {
						w.(http.Flusher).Flush()
						time.AfterFunc(30*time.Millisecond, cancel)
					}
					return
				}
				switch tc.name {
				case "eof":
					conn, _, err := w.(http.Hijacker).Hijack()
					if err != nil {
						t.Error(err)
						return
					}
					_ = conn.Close()
				case "timeout", "cancel-attempt":
					if tc.name == "cancel-attempt" {
						cancel()
					}
					<-r.Context().Done()
				default:
					fmt.Fprint(w, emptyCartJSON)
				}
			}))
			defer s.Close()
			backoff := time.Millisecond
			if tc.name == "cancel-backoff" {
				backoff = 200 * time.Millisecond
			}
			c, err := New(Config{Origin: s.URL, Timeout: time.Second, Backoff: backoff, Observer: recorder})
			if err != nil {
				t.Fatal(err)
			}
			defer c.CloseIdleConnections()
			if tc.name == "timeout" {
				var stop context.CancelFunc
				ctx, stop = context.WithTimeout(ctx, 100*time.Millisecond)
				defer stop()
			}
			_, err = c.Cart(ctx, Identity{Bearer: "secret-token", RequestID: "secret-request"})
			if (err == nil) != (tc.kind == "") {
				t.Fatalf("unexpected result %v", err)
			}
			var event map[string]any
			if err := json.Unmarshal(logs.Bytes(), &event); err != nil {
				t.Fatal(err)
			}
			if event["status"] != float64(tc.status) || event["error_kind"] != tc.kind || event["retries"] != float64(tc.retries) || event["reused_connections"] != float64(0) || calls.Load() != int32(tc.calls) {
				t.Fatalf("incorrect final-attempt snapshot: %s calls=%d", &logs, calls.Load())
			}
			if strings.Contains(logs.String(), "secret-token") || strings.Contains(logs.String(), "secret-request") {
				t.Fatal("sensitive log values")
			}
			w := httptest.NewRecorder()
			recorder.ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
			for _, line := range []string{
				fmt.Sprintf(`sdui_status_total{kind="upstream",operation="cart",class="%dxx"} 1`, tc.status/100),
				fmt.Sprintf(`sdui_retries_total{kind="upstream",operation="cart"} %d`, tc.retries),
				`sdui_events_total{kind="upstream",operation="cart"} 1`,
			} {
				if !strings.Contains(w.Body.String(), line+"\n") {
					t.Fatalf("metric snapshot missing %s: %s", line, w.Body)
				}
			}
		})
	}
}
