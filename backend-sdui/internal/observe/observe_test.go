package observe

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestRecorderBoundsLabelsAndCorrelatesWithoutSensitiveValues(t *testing.T) {
	var logs bytes.Buffer
	recorder := New(slog.New(slog.NewJSONHandler(&logs, nil)))
	ctx := WithCorrelation(context.Background(), "request-1", "00-11111111111111111111111111111111-2222222222222222-01")
	recorder.Record(ctx, Event{Kind: "http", Operation: "home", Status: 200, Duration: time.Millisecond})
	recorder.Record(ctx, Event{Kind: "upstream", Operation: "cart", Status: 503, Error: "business", Retries: 1, Reused: 1})
	recorder.Record(ctx, Event{Kind: "http", Operation: "/products/private?token=access-secret", Status: 502, Error: "password=hidden"})
	w := httptest.NewRecorder()
	recorder.ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
	for _, want := range []string{`"request_id_hash":`, `"trace_id_hash":`, `"operation":"other"`} {
		if !strings.Contains(logs.String(), want) {
			t.Fatalf("missing correlation/safe label %s in %s", want, &logs)
		}
	}
	for _, secret := range []string{"private", "access-secret", "hidden", "products/", "request-1", "11111111111111111111111111111111", "2222222222222222"} {
		if strings.Contains(logs.String()+w.Body.String(), secret) {
			t.Fatalf("sensitive or uncontrolled telemetry: %s", secret)
		}
	}
	var first, second map[string]any
	lines := strings.Split(strings.TrimSpace(logs.String()), "\n")
	if err := json.Unmarshal([]byte(lines[0]), &first); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(lines[1]), &second); err != nil {
		t.Fatal(err)
	}
	for _, field := range []string{"request_id_hash", "trace_id_hash"} {
		value, ok := first[field].(string)
		if !ok || len(value) != 32 || first[field] != second[field] {
			t.Fatalf("pseudonymous correlation not stable: %s", field)
		}
	}
	if !strings.Contains(w.Body.String(), `sdui_retries_total{kind="upstream",operation="cart"} 1`) || !strings.Contains(w.Body.String(), "sdui_duration_seconds_bucket") {
		t.Fatalf("missing counters/histogram: %s", w.Body)
	}
}

func TestRecorderConcurrentUpdatesAndUntrustedTraceRejection(t *testing.T) {
	recorder := New(nil)
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Go(func() {
			for j := 0; j < 100; j++ {
				recorder.Record(context.Background(), Event{Kind: "render", Operation: "home", Status: 200})
			}
		})
	}
	wg.Wait()
	w := httptest.NewRecorder()
	recorder.ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
	if !strings.Contains(w.Body.String(), `sdui_events_total{kind="render",operation="home"} 2000`) {
		t.Fatal("concurrent metrics lost updates")
	}
	for _, raw := range []string{"bad\r\ntrace", "00-00000000000000000000000000000000-2222222222222222-01", "00-11111111111111111111111111111111-0000000000000000-01"} {
		ctx := WithCorrelation(context.Background(), "request-1", raw)
		if TraceParent(ctx) != "" {
			t.Fatalf("accepted untrusted traceparent %q", raw)
		}
	}
}
