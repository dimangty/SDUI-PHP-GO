package observe

import (
	"context"
	"crypto/tls"
	"errors"
	"net"
	"net/http"
	"sync/atomic"
	"time"

	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
)

type traceOwner struct {
	provider *sdktrace.TracerProvider
	config   TraceConfig
	exporter *privateExporter
	client   *http.Client
	stop     context.CancelFunc
}

// privateExporter prevents collector-controlled response/error text and endpoint
// URLs from reaching the SDK's process-global stderr error handler. Failures are
// counted locally; exporting is always best effort and never a business error.
type privateExporter struct {
	sdktrace.SpanExporter
	lifetime context.Context
	timeout  time.Duration
	failures atomic.Uint64
}

func (e *privateExporter) ExportSpans(ctx context.Context, spans []sdktrace.ReadOnlySpan) error {
	ctx, cancel := context.WithTimeout(ctx, e.timeout)
	stop := context.AfterFunc(e.lifetime, cancel)
	defer stop()
	defer cancel()
	if e.lifetime.Err() != nil {
		cancel()
	}
	if err := e.SpanExporter.ExportSpans(ctx, spans); err != nil {
		e.failures.Add(1)
	}
	return nil
}

func (e *privateExporter) Shutdown(ctx context.Context) error {
	if err := e.SpanExporter.Shutdown(ctx); err != nil {
		e.failures.Add(1)
	}
	return nil
}

func NewTraceProvider(ctx context.Context, c TraceConfig) (*Tracing, error) {
	if err := c.Validate(); err != nil {
		return nil, err
	}
	if !c.Enabled {
		return nil, nil
	}
	// This client belongs only to the exporter, never the business adapters.
	// Proxy environment, redirects, cookies and implicit tracing are excluded.
	transport := &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: c.ExportTimeout}).DialContext,
		TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}, TLSHandshakeTimeout: c.ExportTimeout,
		ResponseHeaderTimeout: c.ExportTimeout, MaxResponseHeaderBytes: 8 << 10,
		MaxIdleConns: 1, MaxIdleConnsPerHost: 1, MaxConnsPerHost: 1, IdleConnTimeout: 30 * time.Second}
	client := &http.Client{Transport: transport, Timeout: c.ExportTimeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	startCtx, cancel := context.WithTimeout(ctx, c.ExportTimeout)
	defer cancel()
	exporter, err := otlptracehttp.New(startCtx, otlptracehttp.WithEndpointURL(c.Endpoint),
		otlptracehttp.WithHTTPClient(client), otlptracehttp.WithHeaders(map[string]string{}),
		otlptracehttp.WithCompression(otlptracehttp.NoCompression), otlptracehttp.WithEncoding(otlptracehttp.EncodingProtobuf),
		otlptracehttp.WithRetry(otlptracehttp.RetryConfig{Enabled: false}), otlptracehttp.WithTimeout(c.ExportTimeout),
		otlptracehttp.WithMaxRequestSize(1<<20), otlptracehttp.WithMaxResponseSize(4<<10))
	if err != nil {
		client.CloseIdleConnections()
		return nil, errors.New("SDUI trace exporter initialization failed")
	}
	lifetime, stop := context.WithCancel(context.Background())
	private := &privateExporter{SpanExporter: exporter, lifetime: lifetime, timeout: c.ExportTimeout}
	processor := sdktrace.NewBatchSpanProcessor(private, sdktrace.WithMaxQueueSize(c.QueueSize), sdktrace.WithMaxExportBatchSize(c.BatchSize), sdktrace.WithBatchTimeout(c.BatchTimeout), sdktrace.WithExportTimeout(c.ExportTimeout))
	provider := sdktrace.NewTracerProvider(sdktrace.WithSpanProcessor(processor),
		sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(c.SampleRatio))),
		sdktrace.WithResource(resource.NewSchemaless(attribute.String("service.name", "backend-sdui"))),
		sdktrace.WithRawSpanLimits(sdktrace.SpanLimits{AttributeCountLimit: 8, AttributeValueLengthLimit: 128}), sdktrace.WithoutPanicRecording())
	t := NewTracing(provider)
	t.owner = &traceOwner{provider: provider, config: c, exporter: private, client: client, stop: stop}
	return t, nil
}

func (t *Tracing) ExportFailures() uint64 {
	if t == nil || t.owner == nil {
		return 0
	}
	return t.owner.exporter.failures.Load()
}

func (t *Tracing) ForceFlush(ctx context.Context) error {
	if t == nil || t.owner == nil {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, t.owner.config.FlushTimeout)
	defer cancel()
	if err := t.owner.provider.ForceFlush(ctx); err != nil {
		return errors.New("SDUI trace flush deadline exceeded")
	}
	return nil
}

// Shutdown is called only after request drain, with the remaining runtime
// deadline. Cancel the exporter lifetime even on timeout: the SDK's background
// queue drain must not retain network work beyond that deadline.
func (t *Tracing) Shutdown(ctx context.Context) error {
	if t == nil || t.owner == nil {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, t.owner.config.ShutdownTimeout)
	defer cancel()
	defer t.owner.stop()
	defer t.owner.client.CloseIdleConnections()
	if err := t.owner.provider.Shutdown(ctx); err != nil {
		return errors.New("SDUI trace shutdown deadline exceeded")
	}
	return nil
}
