package observe

import (
	"context"
	"crypto/x509"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	coltracepb "go.opentelemetry.io/proto/otlp/collector/trace/v1"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/proto"
)

func TestTraceConfigRejectsSecretURLsAndInvalidBounds(t *testing.T) {
	for _, endpoint := range []string{"", "/relative", "ftp://collector/v1/traces", "http://secret:password@collector/v1/traces", "http://collector/v1/traces?token=secret", "http://collector/?", "http://collector/#secret", "http://collector/#", "http:///v1/traces", "http://%zz", "http://collector:99999/v1/traces"} {
		t.Run(endpoint, func(t *testing.T) {
			c := TraceDefaults()
			c.Enabled, c.Endpoint = true, endpoint
			_, err := NewTraceProvider(context.Background(), c)
			if err == nil || strings.Contains(err.Error(), "secret") || strings.Contains(err.Error(), "password") {
				t.Fatalf("invalid endpoint must fail with fixed safe error: %v", err)
			}
		})
	}
	for _, change := range []func(*TraceConfig){
		func(c *TraceConfig) { c.SampleRatio = -1 }, func(c *TraceConfig) { c.SampleRatio = 2 },
		func(c *TraceConfig) { c.QueueSize = 0 }, func(c *TraceConfig) { c.QueueSize = 8193 },
		func(c *TraceConfig) { c.BatchSize = c.QueueSize + 1 }, func(c *TraceConfig) { c.ExportTimeout = 0 },
		func(c *TraceConfig) { c.FlushTimeout = -1 }, func(c *TraceConfig) { c.ShutdownTimeout = time.Hour },
	} {
		c := TraceDefaults()
		c.Enabled, c.Endpoint = true, "https://collector/v1/traces"
		change(&c)
		if c.Validate() == nil {
			t.Fatal("unsafe trace config accepted")
		}
	}
}

func TestTraceEndpointPreservesRequestURIAndRejectsEscapedPaths(t *testing.T) {
	var calls atomic.Int32
	uris := make(chan string, 8)
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		uris <- r.RequestURI
	}))
	defer s.Close()
	for _, path := range []string{"", "/", "/custom/v1/traces", "/custom/v1/traces/", "/trace-v1_2.3/~collector"} {
		t.Run("plain"+path, func(t *testing.T) {
			c := TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = true, s.URL+path, 1
			tracing, err := NewTraceProvider(context.Background(), c)
			if err != nil {
				t.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			_, span := tracing.Server(context.Background(), "GET", "home")
			span.End(200, "")
			if err := tracing.ForceFlush(context.Background()); err != nil {
				t.Fatal(err)
			}
			want := path
			if want == "" {
				want = "/"
			}
			select {
			case got := <-uris:
				if got != want {
					t.Errorf("collector RequestURI=%q want=%q", got, want)
				}
			case <-time.After(time.Second):
				t.Fatal("no actual collector request")
			}
		})
	}
	before := calls.Load()
	for _, path := range []string{"/SECRET%2Ftraces", "/SECRET%2ftraces", "/%76%31/traces", "/SECRET%20traces", "/SECRET%25traces", "/SECRET%3Ftraces", "/%E2%98%83/traces"} {
		c := TraceDefaults()
		c.Enabled, c.Endpoint = true, s.URL+path
		tracing, err := NewTraceProvider(context.Background(), c)
		if tracing != nil {
			_ = tracing.Shutdown(context.Background())
		}
		if err == nil || err.Error() != "SDUI_TRACE_ENDPOINT requires an unescaped path" {
			t.Errorf("escaped endpoint must fail with a fixed sanitized error: %v", err)
		}
	}
	if calls.Load() != before {
		t.Fatal("rejected endpoint triggered collector I/O")
	}
}

func TestTraceProviderDisabledIgnoresOTelEnvironmentAndExportsNothing(t *testing.T) {
	var calls atomic.Int32
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { calls.Add(1) }))
	defer s.Close()
	t.Setenv("OTEL_EXPORTER_OTLP_TRACES_ENDPOINT", "http://secret:password@other/%zz")
	c := TraceDefaults()
	c.Endpoint = s.URL + "/v1/traces"
	tracing, err := NewTraceProvider(context.Background(), c)
	if err != nil {
		t.Fatal(err)
	}
	ctx := WithCorrelation(context.Background(), "secret", "00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01")
	next, span := tracing.Server(ctx, "GET", "home")
	span.End(200, "")
	if next != ctx || tracing.TraceParent(ctx) != TraceParent(ctx) {
		t.Fatal("disabled tracing changed legacy context propagation")
	}
	if err := tracing.ForceFlush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if err := tracing.Shutdown(context.Background()); err != nil || calls.Load() != 0 {
		t.Fatalf("disabled exporter used network: calls=%d err=%v", calls.Load(), err)
	}
}

func TestTraceProviderRejectsAmbientOTelConfigBeforeSDKCanLogIt(t *testing.T) {
	for _, key := range []string{"OTEL_EXPORTER_OTLP_TRACES_ENDPOINT", "OTEL_EXPORTER_OTLP_HEADERS", "OTEL_EXPORTER_OTLP_CERTIFICATE", "OTEL_RESOURCE_ATTRIBUTES", "OTEL_TRACES_SAMPLER_ARG", "OTEL_BSP_MAX_QUEUE_SIZE", "OTEL_GO_X_OBSERVABILITY"} {
		t.Run(key, func(t *testing.T) {
			t.Setenv(key, "SECRET-invalid-%zz")
			c := TraceDefaults()
			c.Enabled, c.Endpoint = true, "http://collector/v1/traces"
			if _, err := NewTraceProvider(context.Background(), c); err == nil || strings.Contains(err.Error(), "SECRET") {
				t.Fatalf("ambient SDK settings must be rejected safely: %v", err)
			}
		})
	}
}

func TestOTLPWirePrivacyAndSafeCollectorErrors(t *testing.T) {
	for _, response := range []struct {
		name              string
		status            int
		contentType, body string
	}{
		{"success", 200, "application/x-protobuf", ""},
		{"error", 400, "text/plain", "COLLECTOR-SECRET-bearer-cookie-password"},
		{"partial", 200, "application/json", `{"partialSuccess":{"rejectedSpans":"1","errorMessage":"COLLECTOR-SECRET-bearer-cookie-password"}}`},
	} {
		t.Run(response.name, func(t *testing.T) {
			payloads := make(chan []byte, 4)
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path != "/v1/traces" || r.Header.Get("Authorization") != "" || r.Header.Get("Cookie") != "" || r.Header.Get("Baggage") != "" {
					t.Error("unexpected exporter destination or headers")
				}
				body, _ := io.ReadAll(r.Body)
				payloads <- body
				w.Header().Set("Content-Type", response.contentType)
				w.WriteHeader(response.status)
				fmt.Fprint(w, response.body)
			}))
			defer s.Close()
			c := TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = true, s.URL+"/v1/traces", 1
			tracing, err := NewTraceProvider(context.Background(), c)
			if err != nil {
				t.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			ctx := WithCorrelation(context.Background(), "REQUEST-SECRET", "00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01")
			ctx, server := tracing.Server(ctx, "METHOD-SECRET", "ROUTE-SECRET")
			ctx, build := tracing.Build(ctx, "SCREEN-SECRET")
			_, client := tracing.Client(ctx, "METHOD-SECRET", "https://host-SECRET/id-SECRET?q=query-SECRET")
			client.End(999, "ERROR-SECRET")
			build.End(502, "invalid_document")
			server.End(502, "http")
			if err := tracing.ForceFlush(context.Background()); err != nil {
				t.Fatal(err)
			}
			select {
			case raw := <-payloads:
				var msg coltracepb.ExportTraceServiceRequest
				if err := proto.Unmarshal(raw, &msg); err != nil {
					t.Fatal(err)
				}
				text := protojson.Format(&msg)
				if len(msg.ResourceSpans) == 0 || strings.Contains(text, "SECRET") || strings.Contains(text, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa") || strings.Contains(text, "127.0.0.1") {
					t.Fatalf("exported private data or no spans: %s", text)
				}
				if !strings.Contains(text, "other") || !strings.Contains(text, "service.name") {
					t.Fatalf("missing bounded span data: %s", text)
				}
			case <-time.After(time.Second):
				t.Fatal("no actual OTLP export")
			}
			wantFailures := uint64(0)
			if response.name != "success" {
				wantFailures = 1
			}
			if tracing.ExportFailures() != wantFailures {
				t.Fatalf("safe export diagnostic: %d", tracing.ExportFailures())
			}
		})
	}
}

func TestTraceExporterRefuses307And308Redirects(t *testing.T) {
	for _, status := range []int{http.StatusTemporaryRedirect, http.StatusPermanentRedirect} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			var collectorCalls, redirectedCalls atomic.Int32
			target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				redirectedCalls.Add(1)
			}))
			defer target.Close()
			collector := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				collectorCalls.Add(1)
				w.Header().Set("Location", target.URL+"/SECRET-redirect-target")
				w.WriteHeader(status)
				fmt.Fprint(w, "SECRET-collector-redirect")
			}))
			defer collector.Close()
			c := TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = true, collector.URL+"/v1/traces", 1
			tracing, err := NewTraceProvider(context.Background(), c)
			if err != nil {
				t.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			_, span := tracing.Server(context.Background(), "GET", "home")
			span.End(200, "")
			if err := tracing.ForceFlush(context.Background()); err != nil {
				t.Fatal(err)
			}
			if collectorCalls.Load() != 1 || redirectedCalls.Load() != 0 || tracing.ExportFailures() != 1 {
				t.Fatalf("redirect/retry escaped private exporter: collector=%d target=%d safe_failures=%d", collectorCalls.Load(), redirectedCalls.Load(), tracing.ExportFailures())
			}
		})
	}
}

func TestTraceExporterIgnoresProxyEnvironment(t *testing.T) {
	var proxyCalls atomic.Int32
	proxy := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		proxyCalls.Add(1)
	}))
	defer proxy.Close()
	for _, key := range []string{"HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY", "http_proxy", "https_proxy", "all_proxy"} {
		t.Setenv(key, proxy.URL)
	}
	for _, key := range []string{"NO_PROXY", "no_proxy"} {
		t.Setenv(key, "")
	}
	for _, tls := range []bool{false, true} {
		t.Run(fmt.Sprintf("tls=%v", tls), func(t *testing.T) {
			var collectorCalls atomic.Int32
			collector := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				collectorCalls.Add(1)
			}))
			if tls {
				collector.StartTLS()
			} else {
				collector.Start()
			}
			defer collector.Close()
			u, err := url.Parse(collector.URL)
			if err != nil {
				t.Fatal(err)
			}
			// Use a non-loopback hostname: ProxyFromEnvironment deliberately
			// bypasses 127.0.0.1. Only DNS/dialing is remapped to this fixture;
			// the production client's actual proxy selection remains exercised.
			u.Host = net.JoinHostPort("example.com", u.Port())
			c := TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = true, u.String()+"/v1/traces", 1
			tracing, err := NewTraceProvider(context.Background(), c)
			if err != nil {
				t.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			transport := tracing.owner.client.Transport.(*http.Transport)
			dial := transport.DialContext
			transport.DialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
				if address == u.Host {
					address = collector.Listener.Addr().String()
				}
				return dial(ctx, network, address)
			}
			if tls {
				// Trust only the controlled collector certificate, still checking
				// its example.com hostname. The next test retains default roots.
				roots := x509.NewCertPool()
				roots.AddCert(collector.Certificate())
				transport.TLSClientConfig.RootCAs = roots
			}
			_, span := tracing.Server(context.Background(), "GET", "home")
			span.End(200, "")
			if err := tracing.ForceFlush(context.Background()); err != nil {
				t.Fatal(err)
			}
			if collectorCalls.Load() != 1 || proxyCalls.Load() != 0 || tracing.ExportFailures() != 0 {
				t.Fatalf("proxy environment affected export: collector=%d proxy=%d safe_failures=%d", collectorCalls.Load(), proxyCalls.Load(), tracing.ExportFailures())
			}
		})
	}
}

func TestTraceExporterRejectsUntrustedTLS(t *testing.T) {
	var connections, requests atomic.Int32
	collector := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
	}))
	// Expected fixture-side TLS handshake errors are unrelated to SDK diagnostics.
	collector.Config.ErrorLog = log.New(io.Discard, "", 0)
	collector.Config.ConnState = func(_ net.Conn, state http.ConnState) {
		if state == http.StateNew {
			connections.Add(1)
		}
	}
	collector.StartTLS()
	defer collector.Close()
	c := TraceDefaults()
	c.Enabled, c.Endpoint, c.SampleRatio = true, collector.URL+"/SECRET-untrusted-collector", 1
	tracing, err := NewTraceProvider(context.Background(), c)
	if err != nil {
		t.Fatal(err)
	}
	defer tracing.Shutdown(context.Background())
	_, span := tracing.Server(context.Background(), "GET", "home")
	span.End(200, "")
	if err := tracing.ForceFlush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if connections.Load() != 1 || requests.Load() != 0 || tracing.ExportFailures() != 1 {
		t.Fatalf("untrusted TLS was accepted or retried: connections=%d requests=%d safe_failures=%d", connections.Load(), requests.Load(), tracing.ExportFailures())
	}
}

func TestTracingBoundedQueueDropsAndShutdownCancelsExporter(t *testing.T) {
	started, canceled := make(chan struct{}, 16), make(chan struct{}, 16)
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body)
		started <- struct{}{}
		<-r.Context().Done()
		canceled <- struct{}{}
	}))
	defer s.Close()
	c := TraceDefaults()
	c.Enabled, c.Endpoint, c.SampleRatio = true, s.URL+"/v1/traces", 1
	c.QueueSize, c.BatchSize, c.ExportTimeout = 2, 1, time.Second
	c.FlushTimeout, c.ShutdownTimeout = 30*time.Millisecond, 30*time.Millisecond
	tracing, err := NewTraceProvider(context.Background(), c)
	if err != nil {
		t.Fatal(err)
	}
	_, first := tracing.Server(context.Background(), "GET", "home")
	first.End(200, "")
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("export did not start")
	}
	ended := make(chan struct{})
	go func() {
		for range 10000 {
			_, span := tracing.Server(context.Background(), "GET", "home")
			span.End(200, "")
		}
		close(ended)
	}()
	select {
	case <-ended:
	case <-time.After(time.Second):
		t.Fatal("full queue blocked span completion")
	}
	start := time.Now()
	if err := tracing.ForceFlush(context.Background()); err == nil {
		t.Fatal("blocked flush needs a deadline")
	}
	if time.Since(start) > 500*time.Millisecond {
		t.Fatal("unbounded flush")
	}
	start = time.Now()
	if err := tracing.Shutdown(context.Background()); err == nil {
		t.Fatal("blocked shutdown needs a deadline")
	}
	if time.Since(start) > 500*time.Millisecond {
		t.Fatal("unbounded shutdown")
	}
	select {
	case <-canceled:
	case <-time.After(time.Second):
		t.Fatal("shutdown retained exporter HTTP request")
	}
	if len(started) > 3 {
		t.Fatal("queue did not drop excess spans")
	}
}

func TestTracingQueueRetainsAtMostConfiguredSpans(t *testing.T) {
	started, release := make(chan struct{}, 16), make(chan struct{})
	var exported atomic.Int32
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		var msg coltracepb.ExportTraceServiceRequest
		if err := proto.Unmarshal(raw, &msg); err != nil {
			t.Error(err)
			return
		}
		for _, resource := range msg.ResourceSpans {
			for _, scope := range resource.ScopeSpans {
				exported.Add(int32(len(scope.Spans)))
			}
		}
		started <- struct{}{}
		select {
		case <-release:
		case <-r.Context().Done():
		}
	}))
	defer s.Close()
	c := TraceDefaults()
	c.Enabled, c.Endpoint, c.SampleRatio, c.QueueSize, c.BatchSize = true, s.URL+"/v1/traces", 1, 2, 1
	tracing, err := NewTraceProvider(context.Background(), c)
	if err != nil {
		t.Fatal(err)
	}
	defer tracing.Shutdown(context.Background())
	_, first := tracing.Server(context.Background(), "GET", "home")
	first.End(200, "")
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("export did not start")
	}
	for range 10000 {
		_, span := tracing.Server(context.Background(), "GET", "home")
		span.End(200, "")
	}
	close(release)
	if err := tracing.ForceFlush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if n := exported.Load(); n != 3 {
		t.Fatalf("want one in-flight span and two queued, dropping the rest; exported %d", n)
	}
}

func TestTracingExportTimeoutCancelsCollectorRequest(t *testing.T) {
	started, canceled := make(chan struct{}), make(chan struct{})
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body)
		close(started)
		<-r.Context().Done()
		close(canceled)
	}))
	defer s.Close()
	c := TraceDefaults()
	c.Enabled, c.Endpoint, c.SampleRatio, c.BatchSize = true, s.URL+"/v1/traces", 1, 1
	c.ExportTimeout = 50 * time.Millisecond
	tracing, err := NewTraceProvider(context.Background(), c)
	if err != nil {
		t.Fatal(err)
	}
	defer tracing.Shutdown(context.Background())
	_, span := tracing.Server(context.Background(), "GET", "home")
	span.End(200, "")
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("export not started")
	}
	select {
	case <-canceled:
	case <-time.After(time.Second):
		t.Fatal("export timeout did not cancel HTTP request")
	}
	if err := tracing.ForceFlush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if tracing.ExportFailures() != 1 {
		t.Fatal("export deadline not counted safely")
	}
}

// Run the real SDK/exporter with its default error handler in a fresh process.
// Capturing the whole process catches stderr diagnostics from asynchronous
// export and from env parsing before explicit exporter options are applied.
func TestTracingNoRawCollectorOrEnvironmentErrorsOnStderr(t *testing.T) {
	executable, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, executable, "-test.run=^Test(OTLPWirePrivacyAndSafeCollectorErrors|TraceProviderRejectsAmbientOTelConfigBeforeSDKCanLogIt|TraceProviderDisabledIgnoresOTelEnvironmentAndExportsNothing|TraceEndpointPreservesRequestURIAndRejectsEscapedPaths|TraceExporterRefuses307And308Redirects|TraceExporterIgnoresProxyEnvironment|TraceExporterRejectsUntrustedTLS)$", "-test.count=1")
	output, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("privacy subprocess: %v %s", err, output)
	}
	if strings.Contains(string(output), "SECRET") || strings.Contains(string(output), "secret:password") || strings.Contains(string(output), "127.0.0.1") {
		t.Fatalf("raw diagnostic escaped exporter boundary: %s", output)
	}
}
