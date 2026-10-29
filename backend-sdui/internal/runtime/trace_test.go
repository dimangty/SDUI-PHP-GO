package runtime

import (
	"context"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
)

func TestTraceOperatorEnvironment(t *testing.T) {
	t.Setenv("YII_ORIGIN", "http://127.0.0.1:58088")
	defaults, err := Load()
	if err != nil || defaults.Tracing.Enabled || defaults.Tracing.Endpoint != "" || defaults.Tracing.SampleRatio != .1 {
		t.Fatalf("tracing must be opt-in: %+v %v", defaults.Tracing, err)
	}
	for key, value := range map[string]string{
		"SDUI_TRACE_ENABLED": "true", "SDUI_TRACE_ENDPOINT": "https://collector:4318/v1/traces",
		"SDUI_TRACE_SAMPLE_RATIO": "0", "SDUI_TRACE_QUEUE_SIZE": "32", "SDUI_TRACE_BATCH_SIZE": "8",
		"SDUI_TRACE_BATCH_TIMEOUT": "250ms", "SDUI_TRACE_EXPORT_TIMEOUT": "500ms",
		"SDUI_TRACE_FLUSH_TIMEOUT": "600ms", "SDUI_TRACE_SHUTDOWN_TIMEOUT": "700ms",
	} {
		t.Setenv(key, value)
	}
	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if !c.Tracing.Enabled || c.Tracing.Endpoint != "https://collector:4318/v1/traces" || c.Tracing.SampleRatio != 0 || c.Tracing.QueueSize != 32 || c.Tracing.BatchSize != 8 || c.Tracing.BatchTimeout != 250*time.Millisecond || c.Tracing.ExportTimeout != 500*time.Millisecond || c.Tracing.FlushTimeout != 600*time.Millisecond || c.Tracing.ShutdownTimeout != 700*time.Millisecond {
		t.Fatalf("operator config not applied: %+v", c.Tracing)
	}
	for _, tc := range []struct{ key, value string }{
		{"SDUI_TRACE_ENABLED", "secret"}, {"SDUI_TRACE_SAMPLE_RATIO", "NaN"}, {"SDUI_TRACE_SAMPLE_RATIO", "1.1"},
		{"SDUI_TRACE_QUEUE_SIZE", "999999999"}, {"SDUI_TRACE_BATCH_SIZE", "0"},
		{"SDUI_TRACE_EXPORT_TIMEOUT", "0s"}, {"SDUI_TRACE_FLUSH_TIMEOUT", "31s"},
		{"SDUI_TRACE_ENDPOINT", "http://secret:password@collector/?token=secret"},
	} {
		t.Run(tc.key, func(t *testing.T) {
			t.Setenv(tc.key, tc.value)
			if _, err := Load(); err == nil {
				t.Fatal("unsafe trace environment accepted")
			}
		})
	}
}

func TestTraceShutdownFollowsRequestDrainWithinSharedDeadline(t *testing.T) {
	exported := make(chan struct{}, 16)
	collector := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body)
		exported <- struct{}{}
	}))
	defer collector.Close()
	tc := observe.TraceDefaults()
	tc.Enabled, tc.Endpoint, tc.SampleRatio, tc.BatchTimeout = true, collector.URL+"/v1/traces", 1, 30*time.Second
	tracing, err := observe.NewTraceProvider(context.Background(), tc)
	if err != nil {
		t.Fatal(err)
	}
	defer tracing.Shutdown(context.Background())
	public, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer public.Close()
	admin, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer admin.Close()
	started, release := make(chan struct{}), make(chan struct{})
	h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, span := tracing.Server(r.Context(), "GET", "home")
		close(started)
		<-release
		span.End(200, "")
	})
	cfg := Defaults()
	cfg.ShutdownTimeout = time.Second
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- serve(ctx, cfg, public, admin, h, http.NotFoundHandler(), tracing) }()
	response := make(chan error, 1)
	go func() {
		res, err := http.Get("http://" + public.Addr().String())
		if err == nil {
			res.Body.Close()
		}
		response <- err
	}()
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("request not started")
	}
	cancel()
	select {
	case err := <-done:
		t.Fatalf("shutdown abandoned request: %v", err)
	case <-time.After(20 * time.Millisecond):
	}
	close(release)
	if err := <-response; err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("unbounded shutdown")
	}
	select {
	case <-exported:
	default:
		t.Fatal("tracer shut down before the drained request ended its span")
	}
}
