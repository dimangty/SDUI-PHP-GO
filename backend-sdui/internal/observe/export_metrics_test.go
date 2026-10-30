package observe

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestExporterHealthMetricsAreBoundedAndSafe(t *testing.T) {
	for _, enabled := range []bool{false, true} {
		t.Run(fmt.Sprint(enabled), func(t *testing.T) {
			collector := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(http.StatusBadRequest)
				fmt.Fprint(w, "PRIVATE-collector-error-token")
			}))
			defer collector.Close()
			c := TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = enabled, collector.URL+"/PRIVATE", 1
			tracing, err := NewTraceProvider(context.Background(), c)
			if err != nil {
				t.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			recorder := New(nil)
			recorder.SetTracing(tracing)
			check := func(want int) {
				t.Helper()
				w := httptest.NewRecorder()
				recorder.ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
				body := w.Body.String()
				if !strings.Contains(body, fmt.Sprintf("sdui_trace_export_failures_total %d\n", want)) {
					t.Fatalf("missing exporter counter: %s", body)
				}
				if strings.Contains(body, "PRIVATE") || strings.Contains(body, collector.URL) {
					t.Fatal("collector data leaked into metrics")
				}
			}
			check(0)
			for i := 1; i <= 2; i++ {
				_, span := tracing.Server(context.Background(), "GET", "home")
				span.End(200, "")
				if err := tracing.ForceFlush(context.Background()); err != nil {
					t.Fatal(err)
				}
				want := 0
				if enabled {
					want = i
				}
				check(want)
			}
		})
	}
}
