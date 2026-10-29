package bff

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
	"shopkmp/backend-sdui/internal/yii"
)

// All modes use exactly the same product fixture, business HTTP client and
// handler. Enabled modes use the production batch processor/OTLP HTTP exporter
// and a local collector, so results include async export work and queue drops.
func BenchmarkTracingProductHTTP(b *testing.B) {
	for _, mode := range []struct {
		name    string
		enabled bool
		ratio   float64
	}{
		{"disabled", false, 1}, {"enabled_sample0", true, 0}, {"enabled_sample1", true, 1},
	} {
		b.Run(mode.name, func(b *testing.B) {
			collector := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = io.Copy(io.Discard, r.Body) }))
			defer collector.Close()
			c := observe.TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = mode.enabled, collector.URL+"/v1/traces", mode.ratio
			tracing, err := observe.NewTraceProvider(context.Background(), c)
			if err != nil {
				b.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			s := httptest.NewServer(http.HandlerFunc(fixtureUpstream))
			defer s.Close()
			up, err := yii.New(yii.Config{Origin: s.URL, Timeout: time.Second, Tracing: tracing})
			if err != nil {
				b.Fatal(err)
			}
			defer up.CloseIdleConnections()
			h := New(up, Config{Tracing: tracing})
			b.ReportAllocs()
			b.ResetTimer()
			for range b.N {
				w := request(h, "GET", "/api/v1/screens/product/"+pid, "", "")
				if w.Code != 200 {
					b.Fatal(w.Code)
				}
			}
			b.StopTimer()
		})
	}
}
