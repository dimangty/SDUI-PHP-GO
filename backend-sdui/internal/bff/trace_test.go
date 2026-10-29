package bff

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/sdk/trace/tracetest"
	"go.opentelemetry.io/otel/trace"

	"shopkmp/backend-sdui/internal/observe"
	"shopkmp/backend-sdui/internal/yii"
)

func traceFixture(t *testing.T, ratio float64, fn http.HandlerFunc) (http.Handler, *tracetest.InMemoryExporter) {
	t.Helper()
	exporter := tracetest.NewInMemoryExporter()
	tp := sdktrace.NewTracerProvider(sdktrace.WithSyncer(exporter), sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(ratio))), sdktrace.WithResource(resource.Empty()), sdktrace.WithoutPanicRecording())
	t.Cleanup(func() { _ = tp.Shutdown(context.Background()) })
	tracing := observe.NewTracing(tp)
	s := httptest.NewServer(fn)
	t.Cleanup(s.Close)
	up, err := yii.New(yii.Config{Origin: s.URL, Timeout: time.Second, Backoff: time.Millisecond, Tracing: tracing})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(up.CloseIdleConnections)
	return New(up, Config{Tracing: tracing}), exporter
}

// A public sampled parent must not become the outbound parent: the BFF owns
// its trace root and local sampling decision, and the client owns a child span.
func TestTracingCreatesLocalSpanInsteadOfForwardingPublicParent(t *testing.T) {
	const incoming = "00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01"
	parents := make(chan string, 1)
	h, exporter := traceFixture(t, 1, func(w http.ResponseWriter, r *http.Request) {
		parents <- r.Header.Get("Traceparent")
		fixtureUpstream(w, r)
	})
	r := httptest.NewRequest("GET", "/api/v1/screens/product/"+pid, nil)
	r.Header.Set("Traceparent", incoming)
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 200 {
		t.Fatalf("business response: %d %s", w.Code, w.Body)
	}
	parent := <-parents
	if parent == "" || strings.Contains(parent, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa") {
		t.Fatalf("missing locally-owned server/build/client spans: outbound traceparent=%q", parent)
	}
	spans := exporter.GetSpans()
	if len(spans) != 3 {
		t.Fatalf("want server/build/client chain; got %d spans: %+v", len(spans), spans)
	}
	byKind := map[trace.SpanKind]tracetest.SpanStub{}
	for _, s := range spans {
		byKind[s.SpanKind] = s
	}
	server, build, client := byKind[trace.SpanKindServer], byKind[trace.SpanKindInternal], byKind[trace.SpanKindClient]
	if server.Parent.IsValid() || len(server.Links) != 0 || build.Parent.SpanID() != server.SpanContext.SpanID() || client.Parent.SpanID() != build.SpanContext.SpanID() || server.SpanContext.TraceID() != client.SpanContext.TraceID() {
		t.Fatalf("broken parent chain: %+v", spans)
	}
	want := "00-" + client.SpanContext.TraceID().String() + "-" + client.SpanContext.SpanID().String() + "-01"
	if parent != want {
		t.Fatalf("outbound must propagate client span: %q != %q", parent, want)
	}
}

func TestTracingLocalSamplingZeroRejectsSampledPublicContext(t *testing.T) {
	parents := make(chan string, 1)
	h, exporter := traceFixture(t, 0, func(w http.ResponseWriter, r *http.Request) {
		parents <- r.Header.Get("Traceparent")
		fixtureUpstream(w, r)
	})
	remote := trace.NewSpanContext(trace.SpanContextConfig{TraceID: trace.TraceID{1}, SpanID: trace.SpanID{2}, TraceFlags: trace.FlagsSampled, Remote: true})
	r := httptest.NewRequest("GET", "/api/v1/screens/product/"+pid, nil)
	r = r.WithContext(trace.ContextWithRemoteSpanContext(r.Context(), remote))
	r.Header.Set("Traceparent", "00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	parent := <-parents
	if w.Code != 200 || len(exporter.GetSpans()) != 0 || !strings.HasSuffix(parent, "-00") || strings.Contains(parent, remote.TraceID().String()) || strings.Contains(parent, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa") {
		t.Fatalf("remote sampling influenced local decision: status=%d parent=%q spans=%d", w.Code, parent, len(exporter.GetSpans()))
	}
}

func TestTracingPrivacyAcrossRequestsErrorsActionsAndSpanData(t *testing.T) {
	const sensitive = "private-bearer-cookie-pin-password-phone-email-query-url-error"
	h, exporter := traceFixture(t, 1, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/products/"+pid {
			w.WriteHeader(400)
			_, _ = w.Write([]byte(`{"error":{"code":"PRIVATE","message":"` + sensitive + `","traceId":"` + sensitive + `"}}`))
			return
		}
		fixtureUpstream(w, r)
	})
	for _, tc := range []struct {
		method, path, body string
		status             int
	}{
		{"GET", "/api/v1/screens/product/" + pid, "", 400},
		{"GET", "/api/v1/screens/catalog?q=" + sensitive, "", 200},
		{"GET", "/api/v1/screens/profile", "", 200},
		{"POST", "/api/v1/sdui/actions", `{"command":"UpdateProfile","input":{"fullName":"` + sensitive + `","phone":"+79991234567"}}`, 200},
		{"POST", "/api/v1/sdui/actions", `{"command":"SetPin","input":{"pin":"` + sensitive + `","password":"` + sensitive + `"}}`, 400},
		{"GET", "/" + sensitive, "", 404},
	} {
		r := httptest.NewRequest(tc.method, tc.path, strings.NewReader(tc.body))
		r.Header.Set("Authorization", "Bearer "+sensitive)
		r.Header.Set("Cookie", sensitive)
		r.Header.Set("X-Request-Id", sensitive)
		r.Header.Set("Baggage", "secret="+sensitive)
		r.Header.Set("Tracestate", "secret="+sensitive)
		r.Header.Set("Content-Type", "application/json")
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != tc.status {
			t.Fatalf("privacy fixture did not reach expected flow: %s %s: %d %s", tc.method, tc.path, w.Code, w.Body)
		}
	}
	spans := exporter.GetSpans()
	if len(spans) < 10 {
		t.Fatalf("privacy test did not exercise tracing: %d spans", len(spans))
	}
	data, err := json.Marshal(spans)
	if err != nil {
		t.Fatal(err)
	}
	for _, secret := range []string{sensitive, pid, uid, "buyer@example.org", "+79991234567", "127.0.0.1", "PRIVATE"} {
		if strings.Contains(string(data), secret) {
			t.Fatalf("span data leaked %q: %s", secret, data)
		}
	}
	for _, s := range spans {
		if len(s.Events) != 0 || len(s.Links) != 0 || s.Status.Description != "" || s.SpanContext.TraceState().Len() != 0 {
			t.Fatalf("unbounded span data: %+v", s)
		}
	}
}

func TestTracingCancellationReachesUpstream(t *testing.T) {
	started, canceled := make(chan struct{}), make(chan struct{})
	h, exporter := traceFixture(t, 1, func(w http.ResponseWriter, r *http.Request) {
		close(started)
		<-r.Context().Done()
		close(canceled)
	})
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	r := httptest.NewRequest("GET", "/api/v1/screens/product/"+pid, nil).WithContext(ctx)
	done := make(chan struct{})
	go func() { h.ServeHTTP(httptest.NewRecorder(), r); close(done) }()
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("upstream not started")
	}
	cancel()
	for _, signal := range []chan struct{}{done, canceled} {
		select {
		case <-signal:
		case <-time.After(time.Second):
			t.Fatal("tracing delayed cancellation")
		}
	}
	spans := exporter.GetSpans()
	if len(spans) != 3 {
		t.Fatalf("cancellation lost spans: %d", len(spans))
	}
	for _, s := range spans {
		found := false
		for _, a := range s.Attributes {
			found = found || string(a.Key) == "error.type" && a.Value.AsString() == "canceled"
		}
		if !found {
			t.Fatalf("missing safe cancellation kind: %+v", s)
		}
	}
}

func TestTracingCollectorAvailabilityCannotFailOrBlockBusinessRequests(t *testing.T) {
	for _, mode := range []string{"disabled", "up", "unreachable", "blocked"} {
		t.Run(mode, func(t *testing.T) {
			collectorStarted := make(chan struct{}, 16)
			collector := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				_, _ = io.Copy(io.Discard, r.Body)
				select {
				case collectorStarted <- struct{}{}:
				default:
				}
				if mode == "blocked" {
					<-r.Context().Done()
				}
			}))
			defer collector.Close()
			c := observe.TraceDefaults()
			c.Enabled, c.Endpoint, c.SampleRatio = mode != "disabled", collector.URL+"/v1/traces", 1
			c.QueueSize, c.BatchSize = 2, 1
			c.ExportTimeout, c.ShutdownTimeout = 2*time.Second, 50*time.Millisecond
			if mode == "unreachable" {
				collector.Close()
			}
			tracing, err := observe.NewTraceProvider(context.Background(), c)
			if err != nil {
				t.Fatal(err)
			}
			defer tracing.Shutdown(context.Background())
			upstream := httptest.NewServer(http.HandlerFunc(fixtureUpstream))
			defer upstream.Close()
			up, err := yii.New(yii.Config{Origin: upstream.URL, Timeout: time.Second, Tracing: tracing})
			if err != nil {
				t.Fatal(err)
			}
			defer up.CloseIdleConnections()
			h := New(up, Config{Tracing: tracing})
			finished := make(chan struct{})
			go func() {
				defer close(finished)
				for range 10 {
					w := request(h, "GET", "/api/v1/screens/product/"+pid, "", "")
					if w.Code != 200 {
						t.Errorf("collector %s affected response: %d %s", mode, w.Code, w.Body)
						return
					}
				}
			}()
			select {
			case <-finished:
			case <-time.After(time.Second):
				t.Fatal("business requests waited for collector")
			}
			if mode == "up" || mode == "unreachable" {
				if err := tracing.ForceFlush(context.Background()); err != nil {
					t.Fatal(err)
				}
			}
			if mode == "up" || mode == "blocked" {
				select {
				case <-collectorStarted:
				case <-time.After(time.Second):
					t.Fatal("enabled tracing never exported")
				}
			}
			if mode == "disabled" && len(collectorStarted) != 0 {
				t.Fatal("disabled tracing contacted collector")
			}
			if mode == "unreachable" && tracing.ExportFailures() == 0 {
				t.Fatal("unreachable collector not exercised")
			}
		})
	}
}
